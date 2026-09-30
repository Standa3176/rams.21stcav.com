<?php

namespace Tests\Feature\Cockpit;

use App\Http\Requests\CockpitDocumentRequest;
use App\Models\Project;
use App\Models\ProjectDeliverable;
use App\Models\ProjectPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The no-JavaScript select-all on "Spaces being surveyed" (quick task 260930-sv2).
 *
 * WHY THIS FILE EXISTS RATHER THAN THREE MORE CASES IN CockpitWizardTest: the
 * defect being fixed is a RENDER state, and the seven defects found in the three
 * weeks before this task were every one of them an assertion that rendered a
 * SINGLE state. So each of the four tick states gets its own rendered page and
 * its own count — all ticked, none ticked, some ticked, and a project with no
 * spaces at all — plus the one-space case where the control must NOT appear.
 *
 * THE MECHANISM UNDER TEST, STATED, because asserting it without saying it is
 * how the next reader breaks it: the blade reads `old('visit_rooms')`. ABSENT
 * means "not answered yet, default all" (D-02). An EMPTY ARRAY means "answered,
 * and the answer is none". `spaces-none` therefore flashes `[]` and `spaces-all`
 * DROPS the key. Both redirect to the SAME step, which is what makes them a
 * tick rewrite rather than navigation.
 */
class CockpitSpaceSelectAllTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The same six tables CockpitWizardTest fences, for the same reason. The two
     * new intents land in `advance()`, so the "a half-finished wizard persists
     * nothing" guarantee has to hold for them too — and is asserted, not assumed.
     *
     * @var array<int, string>
     */
    private const UNTOUCHED_TABLES = [
        'site_surveys',
        'visits',
        'worksheets',
        'rams_documents',
        'project_activity_logs',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['cockpit.enabled' => true]);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function project(): Project
    {
        return Project::factory()->create([
            'name'         => 'Select All Job',
            'ref'          => 'Q-4699',
            'client_name'  => 'Northbank Media',
            'site_address' => '12 Wharf Road, Leeds',
            'status'       => Project::STATUS_INSTALLING,
        ]);
    }

    private function user(): User
    {
        return User::factory()->create(['name' => 'Priya Mistry']);
    }

    /**
     * Spaces on file, via the only source the presenter reads — a REVIEWED
     * package's `room_overviews` (`ProjectContextResolver::resolve()['rooms']`).
     *
     * @param  array<int, string>  $names
     */
    private function spaces(Project $project, array $names): void
    {
        ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $this->user()->id,
            'quote_filename' => 'quote.pdf',
            'quote_path'     => 'packages/quote.pdf',
            'extracted_data' => [
                'room_overviews' => array_map(
                    fn (string $name) => ['room' => $name, 'overview' => 'Two displays.', 'summary' => $name],
                    $names
                ),
            ],
            'status'         => ProjectPackage::STATUS_REVIEWED,
        ]);
    }

    /** The eighteen-space project from the user's own complaint. */
    private function eighteenSpaces(Project $project): void
    {
        $this->spaces($project, array_map(fn (int $i) => "Room {$i}", range(1, 18)));
    }

    private function submit(Project $project, array $payload)
    {
        return $this->actingAs($this->user())
            ->post(route('projects.cockpit.documents.store', $project), $payload);
    }

    private function stepUrl(Project $project, int $step = 3): string
    {
        return route('projects.cockpit', [
            'project' => $project,
            'module'  => ProjectDeliverable::KEY_SITE_SURVEY,
            'tab'     => 'overview',
            'action'  => 'generate',
            'step'    => $step,
        ]);
    }

    /** The step-3 payload, with the tick state the caller wants. */
    private function stepThree(string $intent, ?array $rooms = null): array
    {
        $payload = [
            'module' => ProjectDeliverable::KEY_SITE_SURVEY,
            'intent' => $intent,
            'step'   => 3,
            'tab'    => 'overview',
        ];

        if ($rooms !== null) {
            $payload['visit_rooms'] = $rooms;
        }

        return $payload;
    }

    /**
     * How many `visit_rooms` checkboxes are rendered, and how many carry
     * `checked`.
     *
     * COUNTED FROM THE MARKUP, not inferred from the session, because the
     * session is the mechanism and the markup is the thing the PM sees. A test
     * that asserted only `assertSessionHasInput` would have passed for a view
     * that ignored `old()` entirely.
     *
     * @return array{total: int, checked: int}
     */
    private function ticks(string $html): array
    {
        preg_match_all('/<input\b[^>]*name="visit_rooms\[\]"[^>]*>/', $html, $m);

        $total   = count($m[0]);
        $checked = count(array_filter($m[0], fn (string $tag) => str_contains($tag, 'checked')));

        return ['total' => $total, 'checked' => $checked];
    }

    /** @return array<string, int> */
    private function rowCounts(): array
    {
        $counts = [];

        foreach (self::UNTOUCHED_TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    // ── 1. THE FOUR TICK STATES, EACH RENDERED ──────────────────────────────

    /** STATE 1 of 4 — untouched. D-02's default-all, which this task preserves. */
    public function test_state_all_ticked_is_what_an_untouched_step_three_renders(): void
    {
        $project = $this->project();
        $this->eighteenSpaces($project);

        $html = $this->actingAs($this->user())->get($this->stepUrl($project))
            ->assertOk()
            ->getContent();

        $this->assertSame(
            ['total' => 18, 'checked' => 18],
            $this->ticks($html),
            'D-02: an unanswered space list renders every space ticked.'
        );
    }

    /** STATE 2 of 4 — none. The press that replaces seventeen manual unticks. */
    public function test_state_none_ticked_after_the_none_intent(): void
    {
        $project = $this->project();
        $this->eighteenSpaces($project);

        $this->submit($project, $this->stepThree('spaces-none'))
            ->assertRedirect($this->stepUrl($project))
            ->assertSessionHasNoErrors();

        $html = $this->actingAs($this->user())->get($this->stepUrl($project))
            ->assertOk()
            ->getContent();

        $this->assertSame(
            ['total' => 18, 'checked' => 0],
            $this->ticks($html),
            'spaces-none must render eighteen boxes with none ticked — not zero boxes.'
        );
    }

    /**
     * STATE 3 of 4 — some. The state the PM is actually AFTER: none, then one.
     *
     * This is the whole point of the feature and it is asserted end-to-end:
     * press "Tick none", tick one box, and the one survives a re-render.
     */
    public function test_state_some_ticked_survives_a_re_render(): void
    {
        $project = $this->project();
        $this->eighteenSpaces($project);

        // The PM has cleared, then ticked exactly one. A `back` from the last
        // step is the only deliberate way through here again (the view's own
        // comment says so), so that is the round trip asserted.
        $this->submit($project, $this->stepThree('back', ['Room 7']))
            ->assertRedirect($this->stepUrl($project, 2));

        $html = $this->actingAs($this->user())
            ->get($this->stepUrl($project))
            ->assertOk()
            ->getContent();

        $ticks = $this->ticks($html);

        $this->assertSame(18, $ticks['total']);
        $this->assertSame(1, $ticks['checked'], 'One ticked space must stay one, never re-default to all.');
        $this->assertStringContainsString('1 of 18 ticked.', $html);
    }

    /** STATE 4 of 4 — a project with no spaces on file at all. */
    public function test_state_no_spaces_renders_the_empty_state_and_no_bulk_control(): void
    {
        $project = $this->project();

        $html = $this->actingAs($this->user())->get($this->stepUrl($project))
            ->assertOk()
            ->getContent();

        $this->assertSame(['total' => 0, 'checked' => 0], $this->ticks($html));
        $this->assertStringContainsString('No spaces are on file for this project yet.', $html);

        // NO DEAD CONTROLS. Buttons that bulk-change nothing are worse than no
        // buttons, so the guard is `count($spaces) > 1` and this proves it.
        $this->assertStringNotContainsString('value="spaces-all"', $html);
        $this->assertStringNotContainsString('value="spaces-none"', $html);
    }

    // ── 2. THE ALL INTENT RETURNS TO THE DEFAULT ────────────────────────────

    public function test_the_all_intent_re_ticks_everything_after_a_none(): void
    {
        $project = $this->project();
        $this->eighteenSpaces($project);

        $this->submit($project, $this->stepThree('spaces-none'))
            ->assertRedirect($this->stepUrl($project));

        // `spaces-all` DROPS the key rather than flashing all eighteen names, so
        // the view falls back onto D-02's default. Asserted as an ABSENCE,
        // because flashing the names would be a second definition of "all" that
        // could drift from the project's real space list.
        $response = $this->submit($project, $this->stepThree('spaces-all', ['Room 1']))
            ->assertRedirect($this->stepUrl($project))
            ->assertSessionHasNoErrors();

        $response->assertSessionMissing('_old_input.visit_rooms');

        $html = $this->actingAs($this->user())->get($this->stepUrl($project))
            ->assertOk()
            ->getContent();

        $this->assertSame(['total' => 18, 'checked' => 18], $this->ticks($html));
    }

    // ── 3. THE CONTROL APPEARS ONLY WHEN IT CAN DO SOMETHING ────────────────

    public function test_one_space_renders_no_bulk_control_and_two_spaces_do(): void
    {
        $one = $this->project();
        $this->spaces($one, ['Boardroom']);

        $html = $this->actingAs($this->user())->get($this->stepUrl($one))->assertOk()->getContent();

        $this->assertSame(['total' => 1, 'checked' => 1], $this->ticks($html));
        $this->assertStringNotContainsString('value="spaces-none"', $html);

        $two = Project::factory()->create(['name' => 'Two Space Job', 'ref' => 'Q-4700']);
        $this->spaces($two, ['Boardroom', 'Huddle 1']);

        $html = $this->actingAs($this->user())->get($this->stepUrl($two))->assertOk()->getContent();

        $this->assertSame(['total' => 2, 'checked' => 2], $this->ticks($html));
        $this->assertStringContainsString('value="spaces-none"', $html);
        $this->assertStringContainsString('Tick all 2', $html);
    }

    // ── 4. WHAT MUST NOT CHANGE ─────────────────────────────────────────────

    /**
     * THE REASON THIS IS A POST INTENT AND NOT THE `?spaces=none` LINK IT LOOKS
     * LIKE IT COULD BE. A GET would arrive with no payload and wipe steps 1 and 2.
     */
    public function test_the_earlier_steps_answers_survive_a_tick_rewrite(): void
    {
        $project = $this->project();
        $this->eighteenSpaces($project);

        $response = $this->submit($project, $this->stepThree('spaces-none') + [
            'visit_scheduled_date' => '2026-10-14',
            'surveyor_name'        => 'Dev Chandra',
            'site_contact_name'    => 'Ruth Okafor',
            'general_notes'        => 'Scaffold booked for the atrium.',
        ]);

        $response->assertRedirect($this->stepUrl($project))->assertSessionHasNoErrors();

        $response->assertSessionHasInput('surveyor_name', 'Dev Chandra');
        $response->assertSessionHasInput('site_contact_name', 'Ruth Okafor');
        $response->assertSessionHasInput('general_notes', 'Scaffold booked for the atrium.');
        $response->assertSessionHasInput('visit_scheduled_date', '2026-10-14');
    }

    public function test_neither_new_intent_writes_a_row_in_any_of_the_five_tables(): void
    {
        $project = $this->project();
        $this->eighteenSpaces($project);

        $before = $this->rowCounts();

        $this->submit($project, $this->stepThree('spaces-none'))->assertSessionHasNoErrors();
        $this->submit($project, $this->stepThree('spaces-all'))->assertSessionHasNoErrors();

        $this->assertSame($before, $this->rowCounts(), 'A tick rewrite is not a creation.');
    }

    /**
     * The closed set stays closed. Adding two intents must not open the door to
     * a third by accident.
     *
     * MOVED, BY NAME, 5 -> 6 BY QUICK TASK 260930-qcy: `regenerate-document`
     * joined the set for the site survey's document-only regenerate action
     * (Task 3), which is a legitimate sixth member rather than a widened
     * floor — `rubbish` below still proves the set stays closed.
     */
    public function test_the_intent_set_is_exactly_six_and_rubbish_is_still_refused(): void
    {
        $this->assertSame(
            ['next', 'back', 'create', 'spaces-all', 'spaces-none', 'regenerate-document'],
            CockpitDocumentRequest::INTENTS
        );
        $this->assertSame(['spaces-all', 'spaces-none'], CockpitDocumentRequest::SPACE_INTENTS);

        $project = $this->project();
        $this->eighteenSpaces($project);

        $before = $this->rowCounts();

        $this->submit($project, $this->stepThree('spaces-everything'))
            ->assertSessionHasErrors('intent');

        $this->assertSame($before, $this->rowCounts());
    }

    /**
     * THE NEW COPY AGAINST THE FENCE, checked here rather than trusted. The two
     * labels must collide with none of the `DEFERRED_AFFORDANCES` keys and must
     * introduce neither `FORBIDDEN_MARKUP` entry into the rendered page.
     */
    public function test_the_new_copy_adds_no_script_no_select_and_no_handler_attribute(): void
    {
        $project = $this->project();
        $this->eighteenSpaces($project);

        $html = $this->actingAs($this->user())->get($this->stepUrl($project))->assertOk()->getContent();

        $start = strpos($html, 'Tick all 18');
        $this->assertNotFalse($start, 'The select-all must actually render before this asserts anything.');

        $region = substr($html, max(0, $start - 2000), 4000);

        foreach (['<select', '<script', 'onclick', 'wire:', 'x-on:', '@click', 'x-data', 'x-show', 'x-init', 'x-if', 'x-text'] as $banned) {
            $this->assertStringNotContainsString($banned, $region);
        }
    }
}
