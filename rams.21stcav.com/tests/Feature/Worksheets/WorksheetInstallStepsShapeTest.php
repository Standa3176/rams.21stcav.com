<?php

namespace Tests\Feature\Worksheets;

use App\Models\Project;
use App\Models\User;
use App\Models\Worksheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 261011-d62 — `install_steps` renders on BOTH surfaces in ALL THREE shapes.
 *
 * ── THE DEFECT THIS FILE IS THE GUARD FOR ────────────────────────────────────
 *
 * `generated_data.rooms[n].install_steps` has three live producers:
 *
 *   (a) newline-delimited STRING  — app/Core/AI/Prompts/WorksheetPrompt.php:203
 *   (b) FLAT ARRAY of strings     — WorksheetEditAdapter.php:256 / :285
 *   (c) NESTED {step,title,items} — WorksheetGeneratorService.php:543, the
 *                                   "fallback steps if AI empty" branch
 *
 * Both render paths had only ever learned (a), so (b) and (c) returned 500:
 *
 *   - office view (worksheets/show.blade.php) — `{{ $room['install_steps'] }}`
 *     put an array through e()/htmlspecialchars() -> TypeError. The
 *     `@if(! empty(...))` guard does not help: a non-empty array passes empty().
 *   - engineer link (worksheets/public-show.blade.php) — `(string) $array`
 *     raises "Array to string conversion", which HandleExceptions promotes to
 *     an ErrorException because error_reporting(-1) is set. NOT a quiet
 *     "Array" string — a 500.
 *
 * And (c) is written exactly when the install-step AI call returns nothing, so
 * the defect fired on the degraded path that is meant to keep working.
 *
 * ── WHY THE ASSERTIONS ARE SHAPED LIKE THIS ──────────────────────────────────
 *
 * assertOk() alone would not prove an engineer can READ the steps, which is
 * the whole point. Every case asserts the step TEXT in the rendered output,
 * and shape (c) asserts the phase TITLES too — an engineer needs to tell
 * "Pre-Start & Access" from "Second Fix". The two empty-state cases assert
 * BOTH sides: the empty chip/absent drawer is present AND no stray step text
 * leaks.
 *
 * @see \App\Support\WorksheetInstallSteps
 * @see .planning/quick/261011-d62-worksheet-install-steps-array-render/PLAN.md
 */
class WorksheetInstallStepsShapeTest extends TestCase
{
    use RefreshDatabase;

    /** The office view's "steps not generated yet" chip (show.blade.php). */
    private const OFFICE_EMPTY_CHIP = 'Install steps being generated';

    /** The engineer link's install-steps drawer summary (public-show.blade.php). */
    private const ENGINEER_DRAWER = 'Install Steps (';

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /**
     * Worksheet::create (not ->update) so boot::creating mints access_token by
     * direct assignment — access_token is deliberately absent from $fillable
     * and must never be mass-assigned.
     *
     * @param  mixed  $installSteps  the shape under test
     */
    private function worksheet(mixed $installSteps): Worksheet
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        return Worksheet::create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'project_name'   => 'Install Steps Shape Fixture',
            'project_ref'    => '21CQ00000-01-OPS',
            'client_name'    => 'Fixture Client',
            'site_address'   => '1 Fixture Way, Reading RG1 1AA',
            'status'         => Worksheet::STATUS_FINAL,
            'generated_data' => [
                'rooms' => [
                    [
                        'name'                   => 'Boardroom',
                        'is_surveyed'            => true,
                        'equipment'              => [],
                        'works_summary_bullets'  => [],
                        'room_works_description' => '',
                        'install_steps'          => $installSteps,
                    ],
                ],
            ],
        ]);
    }

    private function office(Worksheet $worksheet): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($worksheet->user)->get(route('worksheets.show', $worksheet));
    }

    private function engineer(Worksheet $worksheet): \Illuminate\Testing\TestResponse
    {
        return $this->get(route('public-worksheet.show', ['token' => $worksheet->access_token]));
    }

    // ── Shape (a): newline-delimited string, the AI path ─────────────────────

    private const SHAPE_A = "1. Mount the Sony display centred at 1200mm AFFL\n2. Terminate the HDMI run at the table plate";

    public function test_shape_a_string_renders_on_the_office_view(): void
    {
        $response = $this->office($this->worksheet(self::SHAPE_A));

        $response->assertOk();
        $response->assertSee('Install Steps');
        $response->assertSee('Mount the Sony display centred at 1200mm AFFL');
        $response->assertSee('Terminate the HDMI run at the table plate');
        $response->assertDontSee(self::OFFICE_EMPTY_CHIP);
    }

    public function test_shape_a_string_renders_on_the_engineer_link(): void
    {
        $response = $this->engineer($this->worksheet(self::SHAPE_A));

        $response->assertOk();
        $response->assertSee(self::ENGINEER_DRAWER);
        $response->assertSee('Mount the Sony display centred at 1200mm AFFL');
        $response->assertSee('Terminate the HDMI run at the table plate');
    }

    // ── Shape (b): flat array, written by the edit adapter ───────────────────

    /** @var list<string> */
    private const SHAPE_B = [
        'Install the display mounting bracket',
        'Hang and level the display',
        'Commission the room and hand over',
    ];

    public function test_shape_b_flat_array_renders_every_step_on_the_office_view(): void
    {
        $response = $this->office($this->worksheet(self::SHAPE_B));

        $response->assertOk();
        foreach (self::SHAPE_B as $step) {
            $response->assertSee($step);
        }
        $response->assertDontSee(self::OFFICE_EMPTY_CHIP);
    }

    public function test_shape_b_flat_array_renders_every_step_on_the_engineer_link(): void
    {
        $response = $this->engineer($this->worksheet(self::SHAPE_B));

        $response->assertOk();
        $response->assertSee('Install Steps (' . count(self::SHAPE_B) . ')');
        foreach (self::SHAPE_B as $step) {
            $response->assertSee($step);
        }
    }

    // ── Shape (c): nested phase list, the generator's AI-empty fallback ──────

    /**
     * 2 phases, 3 actions — the fixture the whole of 261011-f9l turns on.
     * The drawer must say 3, the ordinals must run 1..3, and the two headings
     * must carry no ordinal at all.
     */
    private const SHAPE_C_ACTION_COUNT = 3;

    /** @var list<array{step: int, title: string, items: list<string>}> */
    private const SHAPE_C = [
        ['step' => 1, 'title' => 'Pre-Start & Access', 'items' => [
            'Confirm site access and working area availability for Boardroom',
            'Review RAMS and toolbox talk with all engineers',
        ]],
        ['step' => 2, 'title' => 'Second Fix', 'items' => [
            'Mount and secure 2x Sony FW-85BZ40H',
        ]],
    ];

    public function test_shape_c_nested_renders_items_and_titles_on_the_office_view(): void
    {
        $response = $this->office($this->worksheet(self::SHAPE_C));

        $response->assertOk();
        // The ITEMS — what the engineer actually does.
        $response->assertSee('Confirm site access and working area availability for Boardroom');
        $response->assertSee('Review RAMS and toolbox talk with all engineers');
        $response->assertSee('Mount and secure 2x Sony FW-85BZ40H');
        // The TITLES — dropping these would lose "Pre-Start" vs "Second Fix".
        $response->assertSee('Pre-Start & Access');
        $response->assertSee('Second Fix');
        $response->assertDontSee(self::OFFICE_EMPTY_CHIP);
    }

    public function test_shape_c_nested_renders_items_and_titles_on_the_engineer_link(): void
    {
        $response = $this->engineer($this->worksheet(self::SHAPE_C));

        $response->assertOk();
        // 261011-f9l — this asserted `(5)` (2 headings + 3 actions), which was
        // the DEFECT written down as if it were the spec: the drawer counts
        // install steps, and a heading is not one. STRENGTHENED to the action
        // count; the full arithmetic lives in the dedicated test below.
        $response->assertSee('Install Steps (' . self::SHAPE_C_ACTION_COUNT . ')');
        $response->assertSee('Confirm site access and working area availability for Boardroom');
        $response->assertSee('Review RAMS and toolbox talk with all engineers');
        $response->assertSee('Mount and secure 2x Sony FW-85BZ40H');
        $response->assertSee('Pre-Start & Access');
        $response->assertSee('Second Fix');
    }

    public function test_shape_c_titles_are_distinguishable_from_actions_on_the_engineer_link(): void
    {
        // D-04: a title line mixed undistinguished into the action list would
        // read as just another action. The heading carries an explicit, escaped
        // PHASE marker — no new unescaped echo was added to do it.
        $content = $this->engineer($this->worksheet(self::SHAPE_C))->assertOk()->getContent();

        $this->assertStringContainsString('PHASE 1 — Pre-Start &amp; Access', $content);
        $this->assertStringContainsString('PHASE 2 — Second Fix', $content);
    }

    // ── Empty state, both sides asserted (D-06) ──────────────────────────────

    public function test_empty_install_steps_keeps_the_office_view_generating_chip(): void
    {
        foreach (['', null, [], '   '] as $empty) {
            $response = $this->office($this->worksheet($empty));

            $response->assertOk();
            $response->assertSee('Install Steps');
            $response->assertSee(self::OFFICE_EMPTY_CHIP);
        }
    }

    public function test_empty_install_steps_keeps_the_engineer_link_drawer_hidden(): void
    {
        foreach (['', null, [], '   '] as $empty) {
            $response = $this->engineer($this->worksheet($empty));

            $response->assertOk();
            $response->assertDontSee(self::ENGINEER_DRAWER);
            $response->assertDontSee('PHASE');
        }
    }

    /**
     * A nested phase list whose phases carry no items is "nothing", not one
     * blank bullet — the regression D-06 names explicitly.
     */
    public function test_itemless_nested_phases_are_treated_as_empty_on_both_surfaces(): void
    {
        $steps = [['step' => 1, 'title' => 'First Fix', 'items' => []]];

        $this->office($this->worksheet($steps))
            ->assertOk()
            ->assertSee(self::OFFICE_EMPTY_CHIP);

        $this->engineer($this->worksheet($steps))
            ->assertOk()
            ->assertDontSee(self::ENGINEER_DRAWER);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  261011-f9l — a heading is not a step: counting and numbering
    //
    //  261011-d62 left the phase title as an untagged line in the same list
    //  as the actions. Asserting only that the heading TEXT is present passed
    //  happily while the drawer said 5 for 3 steps and the <ol> numbered the
    //  headings — which is why every assertion below is against MARKUP: the
    //  per-row class and the per-row ordinal, not the words.
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * The one `<ol class="steps">` the fixture's single room renders.
     *
     * Asserting exactly one match is part of the guard: two lists would mean
     * the ordinals restart somewhere, and "step 7" would stop being unique
     * within the room.
     */
    private function engineerStepsList(string $content): string
    {
        $found = preg_match_all('#<ol class="steps">(.*?)</ol>#s', $content, $m);

        $this->assertSame(1, $found, 'the room must render exactly one ordered step list');

        return $m[1][0];
    }

    /**
     * Every row of that list as `[class, ordinal-or-null]`, in document order.
     *
     * @return list<array{0: string, 1: string|null}>
     */
    private function engineerStepRows(string $content): array
    {
        $list = $this->engineerStepsList($content);

        $this->assertSame(
            substr_count($list, '<li'),
            preg_match_all('#<li class="(phase|step)"(?: value="(\d+)")?>#', $list, $m, PREG_SET_ORDER),
            'every <li> in the step list must declare its kind as class="phase" or class="step"'
        );

        return array_map(
            fn ($row) => [$row[1], ($row[2] ?? '') === '' ? null : $row[2]],
            $m
        );
    }

    /**
     * SYMPTOM 1. The drawer advertises how many install steps there are. A
     * phase heading is not an install step, so a 2-phase / 3-action room says
     * 3 — not 5.
     *
     * ⚠ This assertion FAILED before the f9l fix: the drawer rendered
     * `Install Steps (5)` from `count($stepLines)`.
     */
    public function test_the_engineer_drawer_count_is_the_action_count_not_the_row_count(): void
    {
        $response = $this->engineer($this->worksheet(self::SHAPE_C))->assertOk();

        $response->assertSee('Install Steps (' . self::SHAPE_C_ACTION_COUNT . ')');
        $response->assertDontSee('Install Steps (5)');
    }

    /**
     * SYMPTOM 2. Ordinals run 1..N across the whole room over ACTIONS ONLY,
     * and a heading carries none — so an engineer's "step 7" is the 7th thing
     * they do. Asserted against the rendered markup, row by row.
     */
    public function test_engineer_ordinals_number_actions_only_and_run_continuously(): void
    {
        $rows = $this->engineerStepRows(
            $this->engineer($this->worksheet(self::SHAPE_C))->assertOk()->getContent()
        );

        $this->assertSame(
            [
                ['phase', null],   // PHASE 1 — Pre-Start & Access
                ['step', '1'],
                ['step', '2'],
                ['phase', null],   // PHASE 2 — Second Fix
                ['step', '3'],     // continuous across the phase boundary, not restarted
            ],
            $rows,
            'headings must carry no ordinal and the action ordinals must run 1..N across the whole room'
        );
    }

    /**
     * The displayed ordinal is the markup ordinal — one source of truth.
     *
     * `ol.steps` drops its native marker and prints `attr(value)` through
     * `li.step::before`, so the numbers asserted above are literally the
     * numbers an engineer reads. If this CSS went missing the list would show
     * no numbers at all while the row assertions stayed green, so it is
     * pinned here. Scoped to `ol.steps` — `ul.actions` must stay bulleted.
     */
    public function test_the_engineer_numbering_css_is_present_and_targets_action_rows_only(): void
    {
        $content = $this->engineer($this->worksheet(self::SHAPE_C))->assertOk()->getContent();

        $this->assertStringContainsString('.room-drawer-body ol.steps { list-style: none; }', $content);
        $this->assertStringContainsString('.room-drawer-body ol.steps li.step::before', $content);
        $this->assertStringContainsString('content: attr(value) ".";', $content);

        // The ordinal rule must not reach the heading rows or the bullets.
        $this->assertStringNotContainsString('ol.steps li.phase::before', $content);
        $this->assertStringNotContainsString('ul.actions li.step::before', $content);
        $this->assertStringNotContainsString('ul.actions { list-style: none; }', $content);
    }

    /** The heading text survives, and stays escaped on this client-signed page. */
    public function test_the_engineer_heading_rows_keep_their_escaped_phase_label(): void
    {
        $list = $this->engineerStepsList(
            $this->engineer($this->worksheet(self::SHAPE_C))->assertOk()->getContent()
        );

        $this->assertStringContainsString('<li class="phase">PHASE 1 — Pre-Start &amp; Access</li>', $list);
        $this->assertStringContainsString('<li class="phase">PHASE 2 — Second Fix</li>', $list);
    }

    /**
     * Shapes (a) and (b) carry no headings, so they must number 1..N exactly
     * as they did before f9l — the regression this task must not cause.
     */
    public function test_shape_a_still_numbers_one_to_n_on_the_engineer_link(): void
    {
        $rows = $this->engineerStepRows(
            $this->engineer($this->worksheet(self::SHAPE_A))->assertOk()->getContent()
        );

        $this->assertSame([['step', '1'], ['step', '2']], $rows);
    }

    public function test_shape_b_still_numbers_one_to_n_on_the_engineer_link(): void
    {
        $response = $this->engineer($this->worksheet(self::SHAPE_B))->assertOk();

        $this->assertSame(
            [['step', '1'], ['step', '2'], ['step', '3']],
            $this->engineerStepRows($response->getContent())
        );
        $response->assertSee('Install Steps (' . count(self::SHAPE_B) . ')');
    }

    // ── SYMPTOM 3 — the office view numbers its actions again ───────────────

    /**
     * Every office-view action row as `[kind, ordinal-or-null]`.
     *
     * @return list<array{0: string, 1: string|null}>
     */
    private function officeStepRows(string $content): array
    {
        preg_match_all(
            '#<div data-install-(phase|step)(?:="(\d+)")?#',
            $content,
            $m,
            PREG_SET_ORDER
        );

        return array_map(
            fn ($row) => [$row[1], ($row[2] ?? '') === '' ? null : $row[2]],
            $m
        );
    }

    /**
     * 261011-d62 stripped shape (a)'s own `1.` / `2.` text and left the office
     * view with no numbering whatsoever. D-05: the surface supplies the
     * ordinals, for all three shapes, headings excepted.
     */
    public function test_the_office_view_numbers_actions_only_for_the_nested_shape(): void
    {
        $content = $this->office($this->worksheet(self::SHAPE_C))->assertOk()->getContent();

        $this->assertSame(
            [
                ['phase', null],
                ['step', '1'],
                ['step', '2'],
                ['phase', null],
                ['step', '3'],
            ],
            $this->officeStepRows($content),
        );

        // The ordinal an engineer reads sits in the row it belongs to.
        $this->assertMatchesRegularExpression(
            '#data-install-step="3"[^>]*>\s*<span[^>]*>3\.</span>\s*<span[^>]*>Mount and secure 2x Sony FW-85BZ40H</span>#s',
            $content,
            'office action row 3 must render the ordinal "3." beside its own text'
        );

        // And the heading is a heading: no ordinal span inside it.
        $this->assertMatchesRegularExpression(
            '#data-install-phase[^>]*>PHASE 1 — Pre-Start &amp; Access</div>#',
            $content,
            'the office heading row must carry its escaped label and no ordinal'
        );
    }

    public function test_the_office_view_numbers_the_flat_shapes_one_to_n(): void
    {
        $this->assertSame(
            [['step', '1'], ['step', '2']],
            $this->officeStepRows($this->office($this->worksheet(self::SHAPE_A))->assertOk()->getContent()),
        );

        $this->assertSame(
            [['step', '1'], ['step', '2'], ['step', '3']],
            $this->officeStepRows($this->office($this->worksheet(self::SHAPE_B))->assertOk()->getContent()),
        );
    }

    /** No empty numbered row when there is nothing to number (D-08). */
    public function test_an_empty_shape_renders_no_numbered_row_on_either_surface(): void
    {
        foreach (['', null, [], '   ', [['step' => 1, 'title' => 'First Fix', 'items' => []]]] as $empty) {
            $office = $this->office($this->worksheet($empty))->assertOk();
            $office->assertSee(self::OFFICE_EMPTY_CHIP);
            $this->assertSame([], $this->officeStepRows($office->getContent()));

            $engineer = $this->engineer($this->worksheet($empty))->assertOk();
            $engineer->assertDontSee(self::ENGINEER_DRAWER);
            $this->assertStringNotContainsString('<li class="step"', $engineer->getContent());
            $this->assertStringNotContainsString('<li class="phase"', $engineer->getContent());
        }
    }
}
