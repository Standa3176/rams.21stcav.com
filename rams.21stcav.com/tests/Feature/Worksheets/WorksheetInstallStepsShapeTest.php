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
        // 2 phase headings + 3 items = 5 lines in the drawer.
        $response->assertSee('Install Steps (5)');
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
}
