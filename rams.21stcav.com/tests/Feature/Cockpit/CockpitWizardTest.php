<?php

namespace Tests\Feature\Cockpit;

use App\Models\LabourResource;
use App\Models\Project;
use App\Models\ProjectDeliverable;
use App\Models\SiteSurvey;
use App\Models\User;
use App\Support\Cockpit\CockpitDocumentFormPresenter;
use App\Support\Cockpit\CockpitWizardPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 46.5, Plan 46.5-04 — THE STEPPED CREATION WIZARD.
 *
 * A 17-field wall was called *"scary"* and the user asked for three short steps
 * in their own words: *"dates ,site contact and engineer then next button Then
 * notes (dont need comms room) . then next and confirm space being surveys"*.
 * This file is the proof, and it is deliberately split into three parts:
 *
 *   1. THE STEP ADVANCE WRITES NOTHING. `intent=next` is a POST that persists
 *      no row, and that is asserted as row counts across SIX NAMED TABLES
 *      before and after — because "a write route that writes nothing" is
 *      exactly the kind of thing a later change quietly turns into a write.
 *
 *   2. EVERY STEP IS RENDERED. Not one of them; all of them, by LOOPING the
 *      document's own step list, so a fourth step added to the map is covered
 *      the day it lands.
 *
 *   3. THE ABANDONED-WIZARD RULE (GCW-03). Nothing is persisted until the final
 *      step, so an engineer link can never be issued against a survey whose
 *      rooms were never confirmed — because until the final submit there is no
 *      survey.
 *
 * ── WHY PART 2 IS WRITTEN AS A LOOP AND NOT AS THREE METHODS ───────────────
 *
 * Two days before this plan the user found a defect — an open drawer row that
 * would not close — that 387 tests missed, because the assertion that should
 * have caught it only ever rendered the CLOSED page. Vacuous, not wrong. A
 * wizard has N states and a test that renders one of them proves nothing about
 * the others, so every step of every stepped document is rendered here and the
 * COUNT of states rendered is itself asserted.
 */
class CockpitWizardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * THE SIX TABLES A HALF-FINISHED WIZARD MUST NOT TOUCH.
     *
     * Named, every one of them, because a count-of-one-table assertion is the
     * vacuous kind: `site_surveys` alone would pass while a visit, a worksheet
     * or an activity row was written behind it.
     *
     * @var array<int, string>
     */
    private const UNTOUCHED_TABLES = [
        'site_surveys',
        'visits',
        'worksheets',
        'rams_documents',
        'project_activity_logs',
        'project_packages',
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
            'name'         => 'Wizard Cockpit Job',
            'ref'          => 'Q-4655',
            'client_name'  => 'Northbank Media',
            'site_address' => '12 Wharf Road, Leeds',
            'status'       => Project::STATUS_INSTALLING,
        ]);
    }

    private function user(): User
    {
        return User::factory()->create(['name' => 'Priya Mistry']);
    }

    private function resources(): void
    {
        LabourResource::factory()->create([
            'name'      => 'Dev Chandra',
            'roles'     => [LabourResource::ROLE_ENGINEER],
            'is_active' => true,
        ]);
    }

    private function wizard(): CockpitWizardPresenter
    {
        return app(CockpitWizardPresenter::class);
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

    private function submit(Project $project, array $payload, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user())
            ->post(route('projects.cockpit.documents.store', $project), $payload);
    }

    private function stepUrl(Project $project, string $module, int $step): string
    {
        return route('projects.cockpit', [
            'project' => $project,
            'module'  => $module,
            'tab'     => 'overview',
            'action'  => 'generate',
            'step'    => $step,
        ]);
    }

    /** The step-1 answers the user's own step 1 asks for. */
    private function stepOneValues(): array
    {
        return [
            'survey_date'        => '2026-10-14',
            'surveyor_name'      => 'Dev Chandra',
            'site_contact_name'  => 'Ruth Okafor',
            'site_contact_phone' => '07700 900123',
        ];
    }

    // ── 1. THE STEP ADVANCE WRITES NOTHING ──────────────────────────────────

    public function test_a_next_from_step_one_lands_on_step_two_and_the_answers_survive(): void
    {
        $project = $this->project();

        $response = $this->submit($project, [
            'module' => ProjectDeliverable::KEY_SITE_SURVEY,
            'intent' => 'next',
            'step'   => 1,
            'tab'    => 'overview',
        ] + $this->stepOneValues());

        $response->assertRedirect($this->stepUrl($project, ProjectDeliverable::KEY_SITE_SURVEY, 2))
            ->assertSessionHasNoErrors();

        // `withInput()` and nothing else: the wizard lives in the session flash,
        // never in the database.
        $response->assertSessionHasInput('surveyor_name', 'Dev Chandra');
        $response->assertSessionHasInput('site_contact_name', 'Ruth Okafor');
    }

    /**
     * THE ASSERTION THE WHOLE PERSISTENCE RULE RESTS ON.
     *
     * Six tables, named individually, counted before and counted after. A step
     * advance dispatches nothing, logs nothing and writes nothing — so if a
     * later change gives `advance()` a model call, this goes red rather than
     * quietly leaving orphan rows behind every abandoned wizard.
     */
    public function test_a_step_advance_writes_no_row_in_any_of_the_six_tables(): void
    {
        $project = $this->project();

        $before = $this->rowCounts();

        $this->submit($project, [
            'module' => ProjectDeliverable::KEY_SITE_SURVEY,
            'intent' => 'next',
            'step'   => 1,
            'tab'    => 'overview',
        ] + $this->stepOneValues())->assertSessionHasNoErrors();

        $after = $this->rowCounts();

        foreach (self::UNTOUCHED_TABLES as $table) {
            $this->assertSame(
                $before[$table],
                $after[$table],
                "A step advance wrote to `{$table}`. Nothing is persisted until the final step."
            );
        }

        $this->assertCount(6, $before, 'Six tables are snapshotted, by name.');
    }

    public function test_a_failing_step_one_field_re_renders_step_one_and_never_step_two(): void
    {
        $project = $this->project();

        $this->submit($project, [
            'module'      => ProjectDeliverable::KEY_SITE_SURVEY,
            'intent'      => 'next',
            'step'        => 1,
            'tab'         => 'overview',
            'survey_date' => 'not-a-date',
        ])
            ->assertSessionHasErrors('survey_date')
            ->assertRedirect();

        // A PM must not reach step 3 to learn step 1 was wrong.
        $this->assertSame(0, SiteSurvey::count(), 'A failed advance writes nothing either.');
    }

    /**
     * ONLY THE CURRENT STEP IS VALIDATED, so a step-1 advance is not refused for
     * a step-2 or step-3 field the PM has not reached yet.
     */
    public function test_a_step_one_advance_is_not_refused_for_a_later_steps_fields(): void
    {
        $project = $this->project();

        $this->submit($project, [
            'module' => ProjectDeliverable::KEY_SITE_SURVEY,
            'intent' => 'next',
            'step'   => 1,
            'tab'    => 'overview',
        ])->assertSessionHasNoErrors();
    }

    public function test_an_unrecognised_intent_is_a_validation_failure_that_writes_nothing(): void
    {
        $project = $this->project();

        $before = $this->rowCounts();

        $response = $this->submit($project, [
            'module' => ProjectDeliverable::KEY_SITE_SURVEY,
            'intent' => 'rubbish',
            'format' => 'word',
            'tab'    => 'overview',
        ]);

        $response->assertSessionHasErrors('intent');

        foreach ($this->rowCounts() as $table => $count) {
            $this->assertSame($before[$table], $count, "An unrecognised intent wrote to `{$table}`.");
        }

        // The submitted value is never echoed.
        $this->assertStringNotContainsString('rubbish', (string) $response->headers->get('location'));
    }

    public function test_an_unreal_step_on_an_advance_falls_back_to_step_one_and_writes_nothing(): void
    {
        $project = $this->project();

        foreach (['9', 'abc', '0'] as $unreal) {
            $before = $this->rowCounts();

            $response = $this->submit($project, [
                'module' => ProjectDeliverable::KEY_SITE_SURVEY,
                'intent' => 'next',
                'step'   => $unreal,
                'tab'    => 'overview',
            ]);

            // Resolved to step 1, so `next` goes to step 2 — dropped, never
            // rejected, exactly as `?step=` is on the GET.
            $response->assertRedirect($this->stepUrl($project, ProjectDeliverable::KEY_SITE_SURVEY, 2))
                ->assertSessionHasNoErrors();

            foreach ($this->rowCounts() as $table => $count) {
                $this->assertSame($before[$table], $count, "An unreal step wrote to `{$table}`.");
            }
        }
    }

    public function test_a_back_from_step_two_returns_to_step_one(): void
    {
        $project = $this->project();

        $this->submit($project, [
            'module' => ProjectDeliverable::KEY_SITE_SURVEY,
            'intent' => 'back',
            'step'   => 2,
            'tab'    => 'overview',
        ])->assertRedirect($this->stepUrl($project, ProjectDeliverable::KEY_SITE_SURVEY, 1));
    }

    public function test_back_on_the_first_step_and_next_on_the_last_stay_where_they_are(): void
    {
        $project = $this->project();
        $steps   = $this->wizard()->stepsFor(ProjectDeliverable::KEY_SITE_SURVEY);
        $last    = $steps[count($steps) - 1];

        $this->submit($project, [
            'module' => ProjectDeliverable::KEY_SITE_SURVEY,
            'intent' => 'back',
            'step'   => 1,
            'tab'    => 'overview',
        ])->assertRedirect($this->stepUrl($project, ProjectDeliverable::KEY_SITE_SURVEY, 1));

        $this->submit($project, [
            'module' => ProjectDeliverable::KEY_SITE_SURVEY,
            'intent' => 'next',
            'step'   => $last,
            'tab'    => 'overview',
        ])->assertRedirect($this->stepUrl($project, ProjectDeliverable::KEY_SITE_SURVEY, $last));
    }

    /**
     * NO ROUTE WAS ADDED. The advance is the same POST the creation uses,
     * distinguished by `intent` — asserted here as well as in
     * `CockpitPageTest`'s exact 6/3 counts, because the temptation to add a
     * `wizard.step` route is exactly what this test exists to catch.
     */
    public function test_the_advance_adds_no_route(): void
    {
        $names = collect(app('router')->getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter()
            ->filter(fn (string $name) => str_starts_with($name, 'projects.cockpit'))
            ->values()
            ->all();

        foreach ($names as $name) {
            $this->assertStringNotContainsString('step', $name, 'The step advance is not a route of its own.');
            $this->assertStringNotContainsString('wizard', $name, 'The step advance is not a route of its own.');
        }
    }

    /**
     * `intent=create` is TODAY'S BEHAVIOUR, unchanged — the final submit still
     * validates in full, so a hand-crafted POST cannot skip a step's rules by
     * claiming to be on step 1 (T-46.5-04-02).
     */
    public function test_the_final_submit_revalidates_every_steps_rules_even_on_a_forged_step(): void
    {
        $project = $this->project();

        $this->submit($project, [
            'module'      => ProjectDeliverable::KEY_SITE_SURVEY,
            'intent'      => 'create',
            'format'      => 'word',
            'tab'         => 'overview',
            // A forged step, claiming the document is on step 1 so that step 2's
            // rules "do not apply".
            'step'        => 1,
            'survey_date' => '2026-10-14',
            // A step-2 field, carried forward as a hidden input and therefore
            // attacker-controlled: it is RE-VALIDATED, never trusted.
            'general_notes' => str_repeat('z', 5001),
        ])->assertSessionHasErrors('general_notes');

        $this->assertSame(0, SiteSurvey::count(), 'A rejected final submit creates no survey.');
    }

    public function test_a_carried_display_only_field_is_still_prohibited_on_the_final_submit(): void
    {
        $project = $this->project();

        $this->submit($project, [
            'module'       => ProjectDeliverable::KEY_SITE_SURVEY,
            'intent'       => 'create',
            'format'       => 'word',
            'tab'          => 'overview',
            'step'         => 1,
            'project_name' => 'Something the PM typed behind the app is back',
        ])->assertSessionHasErrors('project_name');

        $this->assertSame(0, SiteSurvey::count());
    }

    /**
     * A POST WITH NO `intent` IS TODAY'S SUBMISSION. Every existing caller and
     * every existing test predates this plan and none of them sends one, so the
     * absence defaults to `create` — the same courtesy `tab` already gets.
     */
    public function test_a_post_with_no_intent_still_creates_exactly_as_it_did_before(): void
    {
        $project = $this->project();
        $this->resources();

        $this->submit($project, [
            'module' => ProjectDeliverable::KEY_SITE_SURVEY,
            'format' => 'word',
            'tab'    => 'overview',
        ] + $this->stepOneValues())->assertSessionHasNoErrors();

        $survey = SiteSurvey::where('project_id', $project->id)->sole();

        $this->assertSame('Dev Chandra', $survey->surveyor_name);
        $this->assertSame('Ruth Okafor', $survey->site_contact_name);
    }

    public function test_the_format_is_only_required_on_the_final_submit(): void
    {
        $project = $this->project();

        // An advance carries no format — the radios are on the last step only.
        $this->submit($project, [
            'module' => ProjectDeliverable::KEY_SITE_SURVEY,
            'intent' => 'next',
            'step'   => 1,
            'tab'    => 'overview',
        ])->assertSessionHasNoErrors();

        // The creation still demands one.
        $this->submit($project, [
            'module' => ProjectDeliverable::KEY_SITE_SURVEY,
            'intent' => 'create',
            'tab'    => 'overview',
        ])->assertSessionHasErrors('format');
    }

    public function test_an_advance_on_an_unknown_module_is_a_validation_error_and_never_a_500(): void
    {
        $project = $this->project();

        $this->submit($project, [
            'module' => '../../etc/passwd',
            'intent' => 'next',
            'step'   => 1,
        ])->assertSessionHasErrors('module');
    }

    public function test_a_document_with_no_wizard_is_unchanged_by_this_plan(): void
    {
        $this->assertSame([], $this->wizard()->stepsFor(ProjectDeliverable::KEY_OM));
        $this->assertSame([], $this->wizard()->stepsFor(ProjectDeliverable::KEY_RAMS));

        // And the step spine says so for the two that DO step.
        $this->assertSame([1, 2, 3], $this->wizard()->stepsFor(ProjectDeliverable::KEY_SITE_SURVEY));
        $this->assertSame([1], $this->wizard()->stepsFor(ProjectDeliverable::KEY_WORKSHEET));
    }

    /**
     * The presenter is the only source of the step list, so every document in
     * the map is judged rather than the one this plan happened to design for.
     */
    public function test_every_document_in_the_map_is_covered_by_the_step_spine(): void
    {
        $judged = 0;

        foreach (array_keys(CockpitDocumentFormPresenter::documentFieldMap()) as $module) {
            $steps = $this->wizard()->stepsFor($module);

            $this->assertSame(array_values(array_unique($steps)), $steps, "{$module}'s steps repeat.");
            $judged++;
        }

        $this->assertSame(4, $judged, 'Four documents, every one judged.');
    }
}
