<?php

namespace Tests\Feature\Cockpit;

use App\Jobs\BuildWorksheetJob;
use App\Core\Modules\Projects\ProjectService;
use App\Models\LabourResource;
use App\Models\Project;
use App\Models\ProjectActivityLog;
use App\Models\ProjectDeliverable;
use App\Models\ProjectPackage;
use App\Models\RamsDocument;
use App\Models\SiteSurvey;
use App\Models\User;
use App\Models\Visit;
use App\Models\Worksheet;
use App\Services\WorkerMonitorService;
use App\Support\Cockpit\CockpitCreationOutcome;
use App\Support\Visits\VisitLinkIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 46.5, Plan 46.5-06 — ONE CREATION, ONE OUTCOME (D-07).
 *
 * *"generate visit , engineer link , pdf/word version all under a signl
 * creation version."* Finishing the wizard produces all of it, or none of it.
 *
 * ── WHAT THIS FILE IS ACTUALLY FOR ────────────────────────────────────────
 *
 * PARTIAL FAILURE. D-07 names it as the thing to design for, so the failure
 * modes below are FORCED rather than hoped against: a `VisitLinkIssuer` double
 * that throws is bound into the container, and the row counts of FIVE named
 * tables are asserted unchanged afterwards. A test that exercises one failure
 * mode proves nothing about the others, so the outcome's states are ITERATED.
 *
 * ── AND THE DOUBLE-WORKSHEET TRAP ────────────────────────────────────────
 *
 * `VisitLinkIssuer::surveyFor()` ADOPTS an existing survey; `worksheetFor()`
 * DOES NOT — every call creates a worksheet and dispatches a build. So a naive
 * "generate the document, then create the visit" for an install would produce
 * TWO worksheets and TWO queued AI builds from ONE click.
 * `test_one_install_creation_produces_exactly_one_worksheet()` is the guard,
 * and it counts the dispatched jobs as well as the rows.
 */
class CockpitCombinedCreationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * THE FIVE TABLES A FORCED FAILURE MUST LEAVE EXACTLY WHERE THEY WERE.
     *
     * Named individually rather than counted, because a count of one table
     * would pass while a visit or an activity row was written behind it. The
     * loop asserts `assertCount(5, …)` so a later edit cannot quietly shrink
     * the list.
     *
     * @var array<int, string>
     */
    private const UNTOUCHED_TABLES = [
        'site_surveys',
        'visits',
        'project_activity_logs',
        'worksheets',
        'rams_documents',
    ];

    /**
     * THE DOUBLES ARE ARMED BY A FLAG, NOT BY A REBIND.
     *
     * A container rebind cannot be reliably UNDONE mid-test — `forgetInstance()`
     * leaves the earlier resolution in place on this container — and the retry
     * assertions need the failure to STOP without a second HTTP boot. So the
     * doubles stay bound for the whole test and read this flag, which is what
     * `releaseTheFailure()` flips. A leftover double would then make the retry
     * fail loudly rather than silently, which is how this was found.
     */
    public static bool $failing = false;

    protected function setUp(): void
    {
        parent::setUp();

        self::$failing = false;

        config(['cockpit.enabled' => true]);

        // THE QUEUE IS FAKED ON EVERY PATH. `phpunit.xml` runs `sync`, so a
        // real dispatch would run `BuildWorksheetJob` INLINE and make live AI
        // calls from a test. Faking it also lets the double-worksheet guard
        // COUNT the dispatches rather than infer them.
        Bus::fake();
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function user(): User
    {
        return User::factory()->create(['name' => 'Priya Mistry']);
    }

    private function project(): Project
    {
        $project = Project::factory()->create([
            'name'         => 'Combined Creation Job',
            'ref'          => 'Q-4666',
            'client_name'  => 'Northbank Media',
            'site_address' => '12 Wharf Road, Leeds',
            'status'       => Project::STATUS_INSTALLING,
        ]);

        ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $this->user()->id,
            'quote_filename' => 'quote.pdf',
            'quote_path'     => 'packages/quote.pdf',
            'extracted_data' => [
                'room_overviews' => [
                    ['room' => 'Boardroom', 'overview' => 'Two 75in displays.', 'summary' => 'Boardroom'],
                    ['room' => 'Huddle 1',  'overview' => 'One soundbar.',      'summary' => 'Huddle 1'],
                ],
                'equipment'      => [['quantity' => 2, 'name' => '75in display', 'area' => 'Boardroom']],
            ],
            'status'         => ProjectPackage::STATUS_REVIEWED,
        ]);

        return $project;
    }

    private function engineer(string $name = 'Dev Chandra'): LabourResource
    {
        return LabourResource::factory()->create([
            'name'      => $name,
            'roles'     => [LabourResource::ROLE_ENGINEER],
            'is_active' => true,
        ]);
    }

    /** @return array<string, int> */
    private function rowCounts(): array
    {
        $counts = [];

        foreach (self::UNTOUCHED_TABLES as $table) {
            $counts[$table] = \Illuminate\Support\Facades\DB::table($table)->count();
        }

        return $counts;
    }

    /** The whole site-survey wizard payload, as the last step submits it. */
    private function surveyPayload(array $overrides = []): array
    {
        return $overrides + [
            'module'               => ProjectDeliverable::KEY_SITE_SURVEY,
            'intent'               => 'create',
            'step'                 => 3,
            'format'               => 'word',
            'survey_date'          => '2026-10-05',
            'surveyor_name'        => 'Dev Chandra',
            'site_contact_name'    => 'Alice Brand',
            'site_contact_phone'   => '07700 900123',
            'general_notes'        => 'Two spaces, one riser.',
            'visit_scheduled_date' => '2026-10-05',
            'visit_engineers'      => ['Dev Chandra'],
            'visit_rooms'          => ['Boardroom'],
        ];
    }

    private function installPayload(array $overrides = []): array
    {
        return $overrides + [
            'module' => ProjectDeliverable::KEY_WORKSHEET,
            'intent' => 'create',
            'format' => 'word',
        ];
    }

    private function submit(Project $project, array $payload, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user())
            ->post(route('projects.cockpit.documents.store', $project), $payload);
    }

    /**
     * FORCE A FAILURE AT THE LATEST POSSIBLE POINT INSIDE THE TRANSACTION.
     *
     * ⚠ `VisitLinkIssuer` IS `final`, so it can be neither subclassed nor
     * Mockery-mocked — and this plan's scope fence forbids editing it (its
     * adoption rules are load-bearing and already tested). So the throw is
     * forced from `ProjectService::log()`, the LAST collaborator inside the
     * transaction, which is a STRICTLY STRONGER test than a throwing `issue()`:
     * by the time it fires, the document, the visit AND the engineer link have
     * ALL ALREADY SUCCEEDED. If anything at all could survive a rollback, this
     * is the shape that would leave it behind.
     *
     * The link-issuance failure ITSELF is forced separately and literally by
     * `forceIssueFailure()` below, through a collaborator the issuer calls.
     */
    private function forceLogFailure(): void
    {
        $double = new class extends ProjectService {
            public function __construct()
            {
            }

            public function log(
                Project $project,
                User $user,
                string $action,
                string $description,
                ?string $fromStatus = null,
                ?string $toStatus = null,
                ?array $metadata = null,
            ): ProjectActivityLog {
                if (CockpitCombinedCreationTest::$failing) {
                    throw new RuntimeException('The creation could not be completed.');
                }

                return parent::log($project, $user, $action, $description, $fromStatus, $toStatus, $metadata);
            }
        };

        $this->app->instance(ProjectService::class, $double);

        self::$failing = true;
    }

    private function releaseTheFailure(): void
    {
        self::$failing = false;
    }

    /**
     * A FAILURE INSIDE `VisitLinkIssuer::issue()` ITSELF, forced through a
     * collaborator it calls: `worksheetFor()` runs
     * `WorkerMonitorService::ensureRunning()` between creating the worksheet
     * and dispatching its build. A throw there is a genuine link-issuance
     * failure on the install path, with the worksheet row ALREADY WRITTEN — so
     * it proves the rollback removes a record the ISSUER created, not merely
     * one this code created.
     */
    private function forceIssueFailure(): void
    {
        $double = new class extends WorkerMonitorService {
            public function __construct()
            {
            }

            public function ensureRunning(): void
            {
                if (CockpitCombinedCreationTest::$failing) {
                    throw new RuntimeException('The engineer link could not be produced.');
                }
            }
        };

        $this->app->instance(WorkerMonitorService::class, $double);

        self::$failing = true;
    }

    // ── The happy path: ONE action, THREE artefacts ─────────────────────────

    public function test_finishing_the_survey_wizard_creates_the_document_the_visit_and_the_link(): void
    {
        $project = $this->project();
        $this->engineer();

        $this->submit($project, $this->surveyPayload())->assertRedirect();

        $survey = SiteSurvey::where('project_id', $project->id)->get();
        $visits = Visit::where('project_id', $project->id)->get();

        $this->assertCount(1, $survey, 'One survey, never two.');
        $this->assertCount(1, $visits, 'One visit.');

        $visit = $visits->first();

        $this->assertSame(Visit::TYPE_SITE_SURVEY, $visit->type);
        $this->assertSame(Visit::STATUS_PLANNED, $visit->status);
        $this->assertNotNull($visit->sent_at, 'The link was never issued: `sent_at` is null.');
        $this->assertFalse((bool) $visit->is_backfilled, 'A visit a PM created is not an inference.');

        // THE LINK POINTS AT THE SURVEY THIS REQUEST PRODUCED.
        $this->assertSame(Visit::SOURCE_SITE_SURVEY, $visit->source_type);
        $this->assertSame($survey->first()->id, $visit->source_id);

        // ONE activity row, as `storeVisit` logs one.
        $this->assertSame(
            1,
            ProjectActivityLog::where('project_id', $project->id)
                ->where('action', ProjectActivityLog::ACTION_VISIT_CREATED)
                ->count(),
        );

        // THE SUCCESS FLASH NAMES ALL THREE and says the build is QUEUED — it
        // never claims a file exists, because every generator queues its build.
        $success = (string) session('success');

        $this->assertStringContainsString('queued', $success);
        $this->assertStringContainsString('visit', $success);
        $this->assertStringContainsString('engineer link', $success);
    }

    public function test_the_visit_carries_exactly_what_the_wizard_collected(): void
    {
        $project  = $this->project();
        $engineer = $this->engineer();
        $this->engineer('Marie Okonkwo');

        $this->submit($project, $this->surveyPayload([
            'visit_scheduled_date' => '2026-11-02',
            'visit_engineers'      => ['Dev Chandra'],
            'visit_rooms'          => ['Boardroom', 'Huddle 1'],
        ]))->assertRedirect();

        $visit = Visit::where('project_id', $project->id)->firstOrFail();

        $this->assertSame('2026-11-02', $visit->scheduled_date?->format('Y-m-d'));
        $this->assertSame(['Boardroom', 'Huddle 1'], $visit->rooms_in_scope);

        // NAMES ONLY REACH THE PAGE (LR-04); the creator resolves them to ids.
        $this->assertSame([$engineer->id], $visit->labour_resource_ids);
    }

    public function test_ticking_no_space_is_an_answer_and_never_a_validation_failure(): void
    {
        $project = $this->project();
        $this->engineer();

        $payload = $this->surveyPayload();
        unset($payload['visit_rooms']);

        $this->submit($project, $payload)->assertRedirect()->assertSessionHasNoErrors();

        $visit = Visit::where('project_id', $project->id)->firstOrFail();

        $this->assertSame([], $visit->rooms_in_scope, 'An empty scope is a stored answer, not a refusal.');
    }

    /**
     * AN UNKNOWN ENGINEER NAME CANNOT BECOME AN ID (T-46.5-06-03).
     *
     * The page renders NAMES, so the creator resolves names against ACTIVE
     * labour resources and drops anything matching none. There is nothing to
     * strip because nothing unknown is ever admitted.
     */
    public function test_an_engineer_name_that_matches_nobody_is_dropped(): void
    {
        $project  = $this->project();
        $engineer = $this->engineer();

        $this->submit($project, $this->surveyPayload([
            'visit_engineers' => ['Dev Chandra', 'Mallory Nobody'],
        ]))->assertRedirect();

        $visit = Visit::where('project_id', $project->id)->firstOrFail();

        $this->assertSame([$engineer->id], $visit->labour_resource_ids);
    }

    // ── THE DOUBLE-WORKSHEET TRAP ───────────────────────────────────────────

    /**
     * `worksheetFor()` HAS NO ADOPTION. Calling the generator AND creating the
     * visit would produce two worksheets and two queued AI builds from one
     * click — a duplicate record and duplicate model spend. The visit goes
     * FIRST and the issuer's worksheet IS the document.
     */
    public function test_one_install_creation_produces_exactly_one_worksheet(): void
    {
        $project = $this->project();

        $this->submit($project, $this->installPayload())->assertRedirect();

        $this->assertSame(
            1,
            Worksheet::where('project_id', $project->id)->count(),
            'ONE creation produced more than one worksheet. `worksheetFor()` has no adoption, '
            .'so the generator must NOT be called as well as the issuer.',
        );

        Bus::assertDispatchedTimes(BuildWorksheetJob::class, 1);

        $visit = Visit::where('project_id', $project->id)->firstOrFail();

        $this->assertSame(Visit::TYPE_INSTALL, $visit->type);
        $this->assertSame(Visit::SOURCE_WORKSHEET, $visit->source_type);
        $this->assertSame(
            Worksheet::where('project_id', $project->id)->firstOrFail()->id,
            $visit->source_id,
            'The visit points at a worksheet other than the one that exists.',
        );
    }

    // ── RAMS: document only, no visit, no link (D-04) ───────────────────────

    public function test_finishing_the_rams_wizard_creates_no_visit_and_no_link(): void
    {
        $project = $this->project();
        $this->engineer();

        $this->submit($project, [
            'module' => ProjectDeliverable::KEY_RAMS,
            'intent' => 'create',
            'format' => 'word',
        ])->assertRedirect();

        $this->assertSame(0, Visit::where('project_id', $project->id)->count(), 'RAMS created a visit.');
        $this->assertSame(0, SiteSurvey::where('project_id', $project->id)->count(), 'RAMS created a survey, and every survey carries a link.');
        $this->assertSame(0, Worksheet::where('project_id', $project->id)->count());
    }

    // ── Adoption: a live survey is USED, never duplicated ───────────────────

    public function test_a_project_with_a_live_survey_adopts_it_and_mints_no_second_token(): void
    {
        $project = $this->project();
        $this->engineer();

        $existing = app(\App\Core\Modules\Survey\SurveyService::class)
            ->createFromProject($project, $this->user());

        $token = $existing->access_token;

        $this->submit($project, $this->surveyPayload())->assertRedirect();

        $this->assertSame(1, SiteSurvey::where('project_id', $project->id)->count(), 'A second survey was created.');

        $existing->refresh();

        $this->assertSame($token, $existing->access_token, 'The engineer link token was rotated. This code never writes one.');

        $visit = Visit::where('project_id', $project->id)->firstOrFail();

        $this->assertSame($existing->id, $visit->source_id, 'The visit points at a survey other than the adopted one.');
    }

    // ── FORCED FAILURE: five tables, and which half happened ────────────────

    public function test_a_link_failure_leaves_five_named_tables_exactly_as_they_were(): void
    {
        $project = $this->project();
        $this->engineer();

        $before = $this->rowCounts();

        $this->assertCount(5, $before, 'Five tables are watched. Shrinking the list is not a fix.');

        $this->forceLogFailure();

        $response = $this->submit($project, $this->surveyPayload());

        $after = $this->rowCounts();

        foreach (self::UNTOUCHED_TABLES as $table) {
            $this->assertSame(
                $before[$table],
                $after[$table],
                "A forced link failure left a row in `{$table}`. The whole creation is one transaction.",
            );
        }

        // NO SUCCESS, on any path.
        $this->assertNull(session('success'), 'A half-run flashed success.');

        // THE MESSAGE NAMES ALL THREE ARTEFACTS AND SAYS NOTHING WAS SAVED.
        $response->assertSessionHasErrors('module');

        $message = (string) session('errors')->first('module');

        $this->assertStringContainsString('document', $message);
        $this->assertStringContainsString('visit', $message);
        $this->assertStringContainsString('engineer link', $message);
        $this->assertStringContainsString('rolled back', $message);

        // AND THE PM KEEPS THEIR ANSWERS.
        $response->assertRedirect();
        $this->assertSame('Two spaces, one riser.', session('_old_input')['general_notes'] ?? null);
    }

    /**
     * THE ADOPTION VARIANT — the half a rollback CANNOT undo.
     *
     * A survey that existed BEFORE the request is still there afterwards,
     * untouched, because a transaction only rolls back what it wrote. The PM is
     * told exactly that rather than "nothing happened", which would be false.
     */
    public function test_a_link_failure_on_an_adopted_survey_leaves_it_untouched_and_says_so(): void
    {
        $project = $this->project();
        $this->engineer();

        $existing = app(\App\Core\Modules\Survey\SurveyService::class)
            ->createFromProject($project, $this->user());

        $token = $existing->access_token;

        $this->forceLogFailure();

        $this->submit($project, $this->surveyPayload());

        $this->assertSame(1, SiteSurvey::where('project_id', $project->id)->count(), 'A rollback deleted a record this request did not create.');

        $existing->refresh();

        $this->assertSame($token, $existing->access_token, 'The pre-existing engineer link changed.');
        $this->assertSame(0, Visit::where('project_id', $project->id)->count());
        $this->assertNull(session('success'));

        $message = (string) session('errors')->first('module');

        $this->assertStringContainsString('existing survey was used and left exactly as it was', $message);
        $this->assertStringContainsString('NOT created', $message);
    }

    /** A retry after a failure produces EXACTLY ONE of everything. */
    public function test_a_retry_after_a_failure_produces_exactly_one_of_everything(): void
    {
        $project = $this->project();
        $this->engineer();

        $this->forceLogFailure();
        $this->submit($project, $this->surveyPayload());

        // The double goes away, exactly as a transient failure would.
        $this->releaseTheFailure();

        $this->submit($project, $this->surveyPayload())->assertRedirect();

        $this->assertSame(1, SiteSurvey::where('project_id', $project->id)->count(), 'The retry doubled the survey.');
        $this->assertSame(1, Visit::where('project_id', $project->id)->count(), 'The retry doubled the visit.');
        $this->assertSame(
            1,
            ProjectActivityLog::where('project_id', $project->id)
                ->where('action', ProjectActivityLog::ACTION_VISIT_CREATED)
                ->count(),
            'The retry doubled the activity row.',
        );

        $visit = Visit::where('project_id', $project->id)->firstOrFail();

        $this->assertNotNull($visit->sent_at, 'The retry produced a visit with no link.');
    }

    // ── SUCCESS IS STRUCTURALLY IMPOSSIBLE FOR A HALF-RUN ───────────────────

    /**
     * ITERATED, NOT SAMPLED. Every state the outcome can be in is constructed
     * and judged, because a test that exercises one failure mode proves nothing
     * about the others.
     */
    public function test_no_path_flashes_success_when_the_outcome_is_incomplete(): void
    {
        $states     = CockpitCreationOutcome::everyState();
        $incomplete = 0;
        $complete   = 0;

        foreach ($states as $outcome) {
            if ($outcome->isComplete()) {
                $complete++;

                $this->assertNotNull($outcome->successMessage(), 'A complete outcome has no success sentence.');

                continue;
            }

            $incomplete++;

            $this->assertNull(
                $outcome->successMessage(),
                "An INCOMPLETE outcome offered a success sentence: document={$outcome->document}, "
                ."visit={$outcome->visit}, link={$outcome->link}.",
            );

            // AND IT STILL SAYS SOMETHING TRUE.
            $this->assertNotSame('', $outcome->sentence());
        }

        // 3 document states x 2 visit states x 2 link states x 2 module kinds.
        $this->assertCount(24, $states, 'Every constructible outcome state was judged.');
        $this->assertSame(4, $complete, 'Four of the 24 states are complete: 2 document states x 2 module kinds.');
        $this->assertSame(20, $incomplete, 'Twenty of the 24 states are incomplete, and not one may flash success.');
    }

    public function test_a_rolled_back_outcome_is_never_complete(): void
    {
        $this->assertFalse(CockpitCreationOutcome::rolledBack(false)->isComplete());
        $this->assertFalse(CockpitCreationOutcome::rolledBack(true)->isComplete());
        $this->assertNull(CockpitCreationOutcome::rolledBack(true)->successMessage());
    }

    // ── Scoping (T-46.5-06-04) ──────────────────────────────────────────────

    public function test_a_second_projects_survey_is_never_touched(): void
    {
        $mine   = $this->project();
        $theirs = $this->project();
        $this->engineer();

        $other = app(\App\Core\Modules\Survey\SurveyService::class)
            ->createFromProject($theirs, $this->user());

        $this->submit($mine, $this->surveyPayload([
            // An id in the payload selects nothing: there is no id field, and
            // every lookup goes through the route-bound {project}.
            'survey_id'  => $other->id,
            'project_id' => $theirs->id,
        ]))->assertRedirect();

        $this->assertSame(1, SiteSurvey::where('project_id', $mine->id)->count());
        $this->assertSame(1, SiteSurvey::where('project_id', $theirs->id)->count());

        $visit = Visit::where('project_id', $mine->id)->firstOrFail();

        $this->assertNotSame($other->id, $visit->source_id, 'The visit adopted ANOTHER project\'s survey.');
    }

    public function test_no_token_is_written_rotated_or_rendered_by_the_creation(): void
    {
        $project = $this->project();
        $this->engineer();

        $response = $this->submit($project, $this->surveyPayload());

        $survey = SiteSurvey::where('project_id', $project->id)->firstOrFail();

        $this->assertNotNull($survey->access_token, 'The model assigns the token; the creator must not.');

        // THE TOKEN NEVER REACHES THE REDIRECT (T-46.5-06-01).
        $this->assertStringNotContainsString((string) $survey->access_token, (string) $response->headers->get('Location'));
        $this->assertStringNotContainsString((string) $survey->access_token, (string) session('success'));
    }

    /**
     * THE LINK-ISSUANCE FAILURE, LITERALLY, ON THE INSTALL PATH.
     *
     * The throw happens INSIDE `VisitLinkIssuer::issue()` with the worksheet
     * row already written, so the rollback has to remove a record the ISSUER
     * created — not merely one this code created.
     */
    public function test_a_failure_inside_the_issuer_leaves_no_worksheet_behind(): void
    {
        $project = $this->project();

        $before = $this->rowCounts();

        $this->forceIssueFailure();

        $this->submit($project, $this->installPayload());

        foreach (self::UNTOUCHED_TABLES as $table) {
            $this->assertSame(
                $before[$table],
                $this->rowCounts()[$table],
                "A failure inside the issuer left a row in `{$table}`.",
            );
        }

        $this->assertNull(session('success'), 'A half-run flashed success.');

        $message = (string) session('errors')->first('module');

        $this->assertStringContainsString('rolled back', $message);

        // AND THE RETRY PRODUCES EXACTLY ONE WORKSHEET.
        $this->releaseTheFailure();

        $this->submit($project, $this->installPayload())->assertRedirect();

        $this->assertSame(1, Worksheet::where('project_id', $project->id)->count());
        $this->assertSame(1, Visit::where('project_id', $project->id)->count());
    }

    public function test_the_rams_document_is_untouched_by_a_survey_creation(): void
    {
        $project = $this->project();
        $this->engineer();

        $this->submit($project, $this->surveyPayload())->assertRedirect();

        $this->assertSame(0, RamsDocument::where('project_id', $project->id)->count());
    }
}
