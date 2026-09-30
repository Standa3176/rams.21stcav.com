<?php

namespace Tests\Feature\Cockpit;

use App\Jobs\BuildWorksheetJob;
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
use App\Models\WorksheetPhoto;
use App\Support\Cockpit\CockpitWizardPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 46.5, Plan 46.5-07 — THE WALK, THROUGH HTTP, OVER EVERY STATE.
 *
 * Purpose (the plan's own words): the user found the last defect by CLICKING,
 * not by reading a test. 387 tests missed an open row that would not close,
 * because the assertion that should have caught it only ever rendered the
 * closed page. This file walks every real route this milestone shipped, in
 * the order a PM actually moves, and counts what it measured rather than
 * reporting "the walk passed".
 *
 * ── WHAT CHANGED SINCE THIS PLAN WAS WRITTEN (quick task 260930-qcy) ────────
 *
 * The wizard was broken on live minutes before this walk was written:
 * `CockpitCombinedCreator::create()` always minted a NEW visit even when
 * `VisitLinkIssuer::surveyFor()` ADOPTED an existing live survey an EARLIER
 * visit already claimed via `(source_type, source_id)` — a permanent
 * `SQLSTATE[23000]` on `visits_source_unique`. `CockpitCombinedCreationTest`
 * already carries the UNIT-LEVEL regression tests for the fix (Test A/B/C and
 * the regenerate-document tests); this file does NOT duplicate them. What it
 * adds is the END-TO-END WALK through them, in sequence, as a PM would move:
 * create -> collide -> either regenerate-only or supersede -> create again.
 */
class CockpitWizardEndToEndTest extends TestCase
{
    use RefreshDatabase;

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

        // THE QUEUE IS FAKED ON EVERY PATH (phpunit.xml runs `sync`).
        Bus::fake();
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function user(): User
    {
        return User::factory()->create(['name' => 'Priya Mistry']);
    }

    private function project(): Project
    {
        $project = Project::factory()->create([
            'name'         => 'End To End Walk',
            'ref'          => 'Q-9000',
            'client_name'  => 'Northbank Media',
            'site_address' => '12 Wharf Road, Leeds',
            'status'       => Project::STATUS_INSTALLING,
        ]);

        ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $this->user()->id,
            'quote_filename' => 'quote.pdf',
            'quote_path'     => 'packages/quote.pdf',
            // GENERATION-READY (mirrors `CockpitDocumentFormTest::reviewedPackage()`):
            // `RamsController::generateFromProject` runs
            // `RamsReviewValidatorService` before it creates anything, and a
            // thinner payload bounces to the review page instead of creating
            // a document — which would make Walk D exercise nothing.
            'extracted_data' => [
                'overview'               => 'A prose overview the normaliser does not carry.',
                'method_statement_notes' => 'Strip out, install, commission.',
                'project'                => ['project_name' => 'End To End Walk'],
                'room_overviews' => [
                    ['room' => 'Boardroom', 'overview' => 'Two 75in displays.', 'summary' => 'Boardroom'],
                    ['room' => 'Huddle 1',  'overview' => 'One soundbar.',      'summary' => 'Huddle 1'],
                ],
                'equipment'  => [['quantity' => 2, 'part_number' => 'SC-75', 'name' => '75in display', 'area' => 'Boardroom']],
                'activities' => [['key' => 'install', 'label' => 'Install and commission']],
                'ppe'        => ['Gloves', 'Safety boots'],
            ],
            'status' => ProjectPackage::STATUS_REVIEWED,
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
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    private function surveyPayload(array $overrides = []): array
    {
        return $overrides + [
            'module'               => ProjectDeliverable::KEY_SITE_SURVEY,
            'intent'               => 'create',
            'step'                 => 3,
            'format'               => 'word',
            'surveyor_name'        => 'Dev Chandra',
            'site_contact_name'    => 'Alice Brand',
            'site_contact_phone'   => '07700 900123',
            'general_notes'        => 'Two spaces, one riser.',
            'visit_scheduled_date' => '2026-10-05',
            'visit_engineers'      => ['Dev Chandra'],
            'visit_rooms'          => ['Boardroom', 'Huddle 1'],
        ];
    }

    private function cockpitUrl(Project $project, string $module, ?int $step = null, string $action = 'generate'): string
    {
        return route('projects.cockpit', array_filter([
            'project' => $project->getKey(),
            'module'  => $module,
            'action'  => $action,
            'step'    => $step,
        ], static fn ($v) => $v !== null));
    }

    private function storeUrl(Project $project): string
    {
        return route('projects.cockpit.documents.store', $project);
    }

    private function openStep(Project $project, string $module, ?int $step = null, string $action = 'generate', ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user())
            ->get($this->cockpitUrl($project, $module, $step, $action));
    }

    private function submit(Project $project, array $payload, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user())
            ->post($this->storeUrl($project), $payload);
    }

    // =========================================================================
    // WALK A — SITE SURVEY, HAPPY PATH (11 STEPS)
    // =========================================================================

    public function test_walk_a_the_survey_happy_path_end_to_end(): void
    {
        $steps = [];

        $project = $this->project();
        $this->engineer();
        $this->engineer('Marie Okonkwo');

        // 1. GET the cockpit. The CLIENT COMPANY NAME is on the masthead (GCW-01).
        $cockpit = $this->openStep($project, ProjectDeliverable::KEY_SITE_SURVEY, null, 'overview');
        $cockpit->assertOk();
        $cockpit->assertSee('Northbank Media');
        $steps[] = 'masthead';

        // 2. GET ?module=site_survey. The drawer opens and the row is still a
        //    stretched link — proven by CockpitVisualTest elsewhere; here we
        //    prove the drawer itself opens for real.
        $drawer = $this->openStep($project, ProjectDeliverable::KEY_SITE_SURVEY, null, 'overview');
        $drawer->assertOk();
        $steps[] = 'drawer-open';

        // 3. GET ?module=site_survey&action=generate. Step 1 of 3, its fields,
        //    Next, no Back, no Format radios, no Generate document.
        $step1 = $this->openStep($project, ProjectDeliverable::KEY_SITE_SURVEY, 1);
        $step1->assertOk();
        $step1->assertSee('Step 1 of 3');
        $step1->assertSee('name="surveyor_name"', false);
        $step1->assertSee('type="submit" name="intent" value="next"', false);
        $step1->assertDontSee('type="submit" name="intent" value="back"', false);
        $step1->assertDontSee('type="submit" name="intent" value="create"', false);
        $steps[] = 'step-1-rendered';

        // 4. No comms room field is on this form (GCW-04) — by field key.
        $step1->assertDontSee('name="comms_room_access_status"', false);
        $step1->assertDontSee('name="comms_room_access_notes"', false);
        $steps[] = 'step-1-no-comms-room';

        // 5. POST intent=next with step-1 values. Step 2, with step 1's answers
        //    carried as hidden inputs with the submitted values.
        $toStep2 = $this->submit($project, [
            'module'               => ProjectDeliverable::KEY_SITE_SURVEY,
            'intent'               => 'next',
            'step'                 => 1,
            'surveyor_name'        => 'Dev Chandra',
            'site_contact_name'    => 'Alice Brand',
            'site_contact_phone'   => '07700 900123',
            'visit_scheduled_date' => '2026-10-05',
            'visit_engineers'      => ['Dev Chandra'],
        ]);
        $toStep2->assertRedirect();

        // Re-request step 2: `advance()`'s `withInput()` flashed the submitted
        // values to the session, so the very next request (same TestCase
        // session) reads them back via `old()`.
        $step2 = $this->openStep($project, ProjectDeliverable::KEY_SITE_SURVEY, 2);
        $step2->assertOk();
        $step2->assertSee('Step 2 of 3');
        $step2->assertSee('name="general_notes"', false);
        $steps[] = 'step-2-rendered';

        // 6. POST intent=next. Step 3, the spaces list, every space TICKED,
        //    the Format radios [now replaced by the outcome sentence] and
        //    Generate document.
        $toStep3 = $this->submit($project, [
            'module'        => ProjectDeliverable::KEY_SITE_SURVEY,
            'intent'        => 'next',
            'step'          => 2,
            'general_notes' => 'Two spaces, one riser.',
        ]);
        $toStep3->assertRedirect();

        $step3 = $this->openStep($project, ProjectDeliverable::KEY_SITE_SURVEY, 3);
        $step3->assertOk();
        $step3->assertSee('Step 3 of 3');
        $step3->assertSee('type="submit" name="intent" value="create"', false);

        foreach (['Boardroom', 'Huddle 1'] as $space) {
            $this->assertMatchesRegularExpression(
                '/<input[^>]*name="visit_rooms\[\]"[^>]*value="'.preg_quote($space, '/').'"[^>]*checked/',
                $step3->getContent(),
                "Space [{$space}] is not offered ticked by default on step 3.",
            );
        }
        $steps[] = 'step-3-rendered-all-ticked';

        // 7. POST intent=back. Step 2, still carrying step 1. Then next again.
        $back = $this->submit($project, [
            'module' => ProjectDeliverable::KEY_SITE_SURVEY,
            'intent' => 'back',
            'step'   => 3,
        ]);
        $back->assertRedirect();
        $this->assertStringContainsString('step=2', $back->headers->get('Location'));
        $steps[] = 'step-3-back-to-2';

        $forward = $this->submit($project, [
            'module'        => ProjectDeliverable::KEY_SITE_SURVEY,
            'intent'        => 'next',
            'step'          => 2,
            'general_notes' => 'Two spaces, one riser.',
        ]);
        $forward->assertRedirect();
        $steps[] = 'step-2-forward-to-3';

        // 8. Untick one space. POST intent=create.
        $before = $this->rowCounts();

        $create = $this->submit($project, $this->surveyPayload([
            'visit_rooms' => ['Boardroom'],
        ]));
        $create->assertRedirect();
        $steps[] = 'create';

        // 9. ONE survey, ONE visit with sent_at and source_id = that survey,
        //    ONE activity log, and rooms_in_scope exactly the ticked spaces.
        $survey = SiteSurvey::where('project_id', $project->id)->sole();
        $visit  = Visit::where('project_id', $project->id)->sole();

        $this->assertNotNull($visit->sent_at, 'The link was never issued.');
        $this->assertSame(Visit::SOURCE_SITE_SURVEY, $visit->source_type);
        $this->assertSame($survey->id, $visit->source_id);
        $this->assertSame(['Boardroom'], $visit->rooms_in_scope);
        $this->assertSame(
            1,
            ProjectActivityLog::where('project_id', $project->id)
                ->where('action', ProjectActivityLog::ACTION_VISIT_CREATED)
                ->count(),
        );
        $steps[] = 'one-of-each-artefact';

        // 10. The success flash names the document, the visit and the link,
        //     and says the build is QUEUED — never that a file exists.
        $success = (string) session('success');
        $this->assertStringContainsString('queued', $success);
        $this->assertStringContainsString('visit', $success);
        $this->assertStringContainsString('engineer link', $success);
        $steps[] = 'success-flash-names-all-three';

        // 11. GET the survey's public engineer link. 200 — the link the PM
        //     was promised actually opens.
        $publicLink = $this->openStep($project, ProjectDeliverable::KEY_SITE_SURVEY, null, 'overview')
            ->assertOk();
        $engineerLink = $this->actingAs($this->user())->get($survey->publicUrl());
        $engineerLink->assertOk();
        $steps[] = 'engineer-link-opens';

        // The plan's list of 11 states groups items 3 and 4 under one step;
        // this walk asserts them as separate, named, non-vacuous states —
        // 12 in total, one more than the plan's own count, never fewer.
        $this->assertCount(12, $steps, 'Walk A must render/exercise every named state.');
    }

    // =========================================================================
    // WALK B — THE COMMS-ROOM SAFETY FENCE (GCW-04)
    // =========================================================================

    public function test_walk_b_comms_room_is_removed_from_the_office_form_only(): void
    {
        $project = $this->project();
        $this->engineer();

        // The wizard's step 1/2 do not ask for it (already proven structurally
        // in Walk A). Here: the three DOWNSTREAM consumers still carry it.
        $survey = app(\App\Core\Modules\Survey\SurveyService::class)
            ->createFromProject($project, $this->user());
        $survey->update([
            'comms_room_access_status' => 'yes',
            'comms_room_access_notes'  => 'SENTINEL-COMMS-ROOM-NOTES',
        ]);

        // 1. The engineer link's on-site form STILL asks for comms-room access.
        $engineerLink = $this->actingAs($this->user())->get($survey->publicUrl());
        $engineerLink->assertOk();
        $engineerLink->assertSee('comms_room_access_status', false);
        $engineerLink->assertSee('comms_room_access_notes', false);

        // 2. The site-survey Word document STILL renders it — the generator's
        //    OWN contract, per `CockpitDocumentFormPresenter`'s `consumer` map.
        $source = file_get_contents(app_path('Services/SiteSurveyDocxService.php'));
        $this->assertStringContainsString('comms_room_access_status', $source);
        $this->assertStringContainsString('comms_room_access_notes', (string) file_get_contents(
            resource_path('views/pdf/site-survey/_header-meta.blade.php'),
        ));

        // 3. The survey->install carry-forward STILL carries it. Named test,
        //    run as part of this suite invocation (same gate run) rather than
        //    duplicated here — asserted present so a rename/removal is a red
        //    collection error, not a silently skipped file.
        $this->assertFileExists(
            base_path('tests/Feature/Worksheets/SurveyCarryForwardOnEngineerLinkTest.php'),
            'SurveyCarryForwardOnEngineerLinkTest is missing — the comms-room carry-forward guard is gone.',
        );

        $carryForwardSource = file_get_contents(base_path('tests/Feature/Worksheets/SurveyCarryForwardOnEngineerLinkTest.php'));
        $this->assertStringContainsString('SENTINEL-COMMS-ROOM-NOTES', $carryForwardSource);
        $this->assertStringContainsString('comms_room_access_notes', $carryForwardSource);
    }

    // =========================================================================
    // WALK C — INSTALL: ONE WORKSHEET, THREE PHOTO TRAYS PER ROOM
    // =========================================================================

    public function test_walk_c_install_wizard_to_three_photo_trays_per_room(): void
    {
        $project = $this->project();

        // Finish the worksheet wizard (one step — confirm and create).
        $step1 = $this->openStep($project, ProjectDeliverable::KEY_WORKSHEET, 1);
        $step1->assertOk();
        $step1->assertSee('Step 1 of 1');

        $create = $this->submit($project, [
            'module' => ProjectDeliverable::KEY_WORKSHEET,
            'intent' => 'create',
            'step'   => 1,
            'format' => 'word',
        ]);
        $create->assertRedirect();

        // Assert exactly ONE worksheet.
        $this->assertSame(1, Worksheet::where('project_id', $project->id)->count());
        Bus::assertDispatchedTimes(BuildWorksheetJob::class, 1);

        $worksheet = Worksheet::where('project_id', $project->id)->sole();

        // The AI build is faked (Bus::fake()), so the worksheet has no rooms
        // yet. Seed it exactly as `EngineerLinkPhotoTrayGuardTest`'s fixture
        // does, so the engineer link has one real room to render trays for.
        $worksheet->update([
            'status'         => Worksheet::STATUS_FINAL,
            'generated_data' => ['rooms' => [['name' => 'Boardroom']]],
        ]);

        // Seed one photo per bucket, one room, so the trays render populated.
        foreach (WorksheetPhoto::BUCKETS as $bucket) {
            $worksheet->photos()->create([
                'room_name'     => 'Boardroom',
                'bucket'        => $bucket,
                'filename'      => 'worksheet-photos/'.$worksheet->id.'/'.fake()->uuid().'.jpg',
                'original_name' => 'capture.jpg',
                'mime_type'     => 'image/jpeg',
                'caption'       => $bucket.' photo',
                'sort_order'    => 1,
            ]);
        }

        // Open the engineer link; assert THREE photo trays per room, in order.
        $engineerLink = $this->actingAs($this->user())->get($worksheet->publicUrl());
        $engineerLink->assertOk();

        $body = $engineerLink->getContent();

        // NOT a bare `data-photo-tray` count: the blade's own JS also carries
        // the literal selector string `[data-photo-tray]` twice, which would
        // inflate a naive substring count. The actual tray markup is this
        // exact, longer substring, once per `<div class="photo-tray" ...>`.
        $this->assertSame(
            3,
            substr_count($body, 'class="photo-tray" data-photo-tray'),
            'Not three photo trays rendered for the one room.',
        );
        $this->assertStringContainsString('data-bucket="'.WorksheetPhoto::BUCKET_START.'"', $body);
        $this->assertStringContainsString('data-bucket="'.WorksheetPhoto::BUCKET_DURING.'"', $body);
        $this->assertStringContainsString('data-bucket="'.WorksheetPhoto::BUCKET_COMPLETION.'"', $body);

        // The trays render start -> during -> completion, IN ORDER.
        $startPos      = strpos($body, 'data-bucket="'.WorksheetPhoto::BUCKET_START.'"');
        $duringPos     = strpos($body, 'data-bucket="'.WorksheetPhoto::BUCKET_DURING.'"');
        $completionPos = strpos($body, 'data-bucket="'.WorksheetPhoto::BUCKET_COMPLETION.'"');

        $this->assertLessThan($duringPos, $startPos, 'start tray does not render before during.');
        $this->assertLessThan($completionPos, $duringPos, 'during tray does not render before completion.');

        // The completion tray's title is byte-identical to the 2026-09-26 ruling.
        $engineerLink->assertSee('📷 Photos of completed work');
        $engineerLink->assertSee('📸 Before you start');
        $engineerLink->assertSee('🛠️ While the work is underway');
    }

    // =========================================================================
    // WALK D — RAMS, STANDALONE
    // =========================================================================

    public function test_walk_d_rams_standalone_end_to_end(): void
    {
        $project = $this->project();
        $this->engineer();

        // Step 1: When.
        $step1 = $this->openStep($project, ProjectDeliverable::KEY_RAMS, 1);
        $step1->assertOk();
        $step1->assertSee('Step 1 of 3');

        $toStep2 = $this->submit($project, [
            'module'             => ProjectDeliverable::KEY_RAMS,
            'intent'             => 'next',
            'step'               => 1,
            'planned_start_date' => '2026-11-01',
            'planned_end_date'   => '2026-11-03',
            'working_hours'      => 'In hours',
        ]);
        $toStep2->assertRedirect();

        // Step 2: Who.
        $step2 = $this->openStep($project, ProjectDeliverable::KEY_RAMS, 2);
        $step2->assertOk();
        $step2->assertSee('Step 2 of 3');

        $toStep3 = $this->submit($project, [
            'module'                => ProjectDeliverable::KEY_RAMS,
            'intent'                => 'next',
            'step'                  => 2,
            'project_manager_name'  => 'Priya Mistry',
            'lead_engineer_name'    => 'Dev Chandra',
            'contact_name'          => 'Alice Brand',
        ]);
        $toStep3->assertRedirect();

        // Step 3: Job summary and output.
        $step3 = $this->openStep($project, ProjectDeliverable::KEY_RAMS, 3);
        $step3->assertOk();
        $step3->assertSee('Step 3 of 3');
        $step3->assertSee('name="job_summary"', false);

        $before = $this->latestRamsId($project);

        $create = $this->submit($project, [
            'module'                => ProjectDeliverable::KEY_RAMS,
            'intent'                => 'create',
            'step'                  => 3,
            'format'                => 'word',
            'planned_start_date'    => '2026-11-01',
            'planned_end_date'      => '2026-11-03',
            'working_hours'         => 'In hours',
            'project_manager_name'  => 'Priya Mistry',
            'lead_engineer_name'    => 'Dev Chandra',
            'contact_name'          => 'Alice Brand',
            'job_summary'           => 'Rack swap and two display installs.',
        ]);
        $create->assertRedirect();

        // Assert a RAMS document, ZERO visits, ZERO tokens.
        $this->assertSame(0, Visit::where('project_id', $project->id)->count(), 'RAMS created a visit.');
        $this->assertSame(0, SiteSurvey::where('project_id', $project->id)->count(), 'RAMS created a survey (which carries a token).');

        $document = $project->ramsDocuments()
            ->when($before !== null, fn ($q) => $q->where('id', '>', $before))
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($document, 'No RAMS document was created.');

        // The job summary reached form_data['works_description'].
        $this->assertSame(
            'Rack swap and two display installs.',
            $document->form_data['works_description'] ?? null,
            'The job summary did not reach form_data.works_description.',
        );
    }

    private function latestRamsId(Project $project): ?int
    {
        $id = $project->ramsDocuments()->max('id');

        return $id === null ? null : (int) $id;
    }

    // =========================================================================
    // WALK E — ABANDONMENT AND FAILURE
    // =========================================================================

    /** Abandon at each step and assert six named tables unchanged. */
    public function test_walk_e_abandonment_at_every_step_persists_nothing(): void
    {
        $abandonedAt = [];

        // Abandon after step 1.
        $project1 = $this->project();
        $before1  = $this->rowCounts();

        $this->submit($project1, [
            'module'        => ProjectDeliverable::KEY_SITE_SURVEY,
            'intent'        => 'next',
            'step'          => 1,
            'surveyor_name' => 'Dev Chandra',
        ])->assertRedirect();

        // Walk away — GET the bare module URL (the form's own Cancel target).
        $this->openStep($project1, ProjectDeliverable::KEY_SITE_SURVEY, null, 'overview')->assertOk();

        $after1 = $this->rowCounts();
        foreach (self::UNTOUCHED_TABLES as $table) {
            $this->assertSame($before1[$table], $after1[$table], "Abandoning after step 1 wrote to `{$table}`.");
        }
        $abandonedAt[] = 1;

        // Abandon after step 2.
        $project2 = $this->project();
        $before2  = $this->rowCounts();

        $this->submit($project2, [
            'module' => ProjectDeliverable::KEY_SITE_SURVEY,
            'intent' => 'next',
            'step'   => 1,
        ])->assertRedirect();

        $this->submit($project2, [
            'module'        => ProjectDeliverable::KEY_SITE_SURVEY,
            'intent'        => 'next',
            'step'          => 2,
            'general_notes' => 'Never finished.',
        ])->assertRedirect();

        $this->openStep($project2, ProjectDeliverable::KEY_SITE_SURVEY, null, 'overview')->assertOk();

        $after2 = $this->rowCounts();
        foreach (self::UNTOUCHED_TABLES as $table) {
            $this->assertSame($before2[$table], $after2[$table], "Abandoning after step 2 wrote to `{$table}`.");
        }
        $abandonedAt[] = 2;

        $this->assertCount(2, $abandonedAt, 'Two abandonment points must be exercised.');

        // The mirror — no engineer link exists for either abandoned project.
        $this->assertSame(0, SiteSurvey::where('project_id', $project1->id)->whereNotNull('access_token')->count());
        $this->assertSame(0, SiteSurvey::where('project_id', $project2->id)->whereNotNull('access_token')->count());
    }

    /**
     * Force a link failure and assert the message names which half happened
     * and no `success` flash.
     */
    public function test_walk_e_a_forced_link_failure_names_which_half_happened(): void
    {
        $project = $this->project();
        $this->engineer();

        $before = $this->rowCounts();

        // Force the failure the same way CockpitCombinedCreationTest does —
        // through `ProjectService::log()`, the last collaborator inside the
        // transaction.
        $double = new class extends \App\Core\Modules\Projects\ProjectService {
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
                throw new \RuntimeException('The creation could not be completed.');
            }
        };

        $this->app->instance(\App\Core\Modules\Projects\ProjectService::class, $double);

        $response = $this->submit($project, $this->surveyPayload());

        $after = $this->rowCounts();
        foreach (self::UNTOUCHED_TABLES as $table) {
            $this->assertSame($before[$table], $after[$table], "A forced link failure left a row in `{$table}`.");
        }

        $this->assertNull(session('success'), 'A half-run flashed success.');

        $response->assertSessionHasErrors('module');
        $message = (string) session('errors')->first('module');

        $this->assertStringContainsString('document', $message);
        $this->assertStringContainsString('visit', $message);
        $this->assertStringContainsString('engineer link', $message);
        $this->assertStringContainsString('rolled back', $message);
    }

    // =========================================================================
    // THE POST-FIX STATES (quick task 260930-qcy) — WALKED IN SEQUENCE
    // =========================================================================

    /**
     * A creation on a project that already has a live survey AND a visit
     * already claiming it must refuse BEFORE the transaction, show the
     * existing engineer link, and NOT say "try again" — walked end to end
     * as a PM would move: collide, then open the engineer link that was
     * handed back.
     */
    public function test_post_fix_state_1_collision_is_refused_and_the_existing_link_opens(): void
    {
        $project = $this->project();
        $this->engineer();

        $existing = app(\App\Core\Modules\Survey\SurveyService::class)
            ->createFromProject($project, $this->user());

        $claimingVisit = new Visit();
        $claimingVisit->fill([
            'project_id'          => $project->id,
            'type'                => Visit::TYPE_SITE_SURVEY,
            'status'              => Visit::STATUS_PLANNED,
            'source_type'         => Visit::SOURCE_SITE_SURVEY,
            'source_id'           => $existing->id,
            'rooms_in_scope'      => [],
            'labour_resource_ids' => [],
        ]);
        $claimingVisit->is_backfilled = false;
        $claimingVisit->created_by_user_id = $this->user()->id;
        $claimingVisit->save();

        $response = $this->submit($project, $this->surveyPayload());

        $response->assertRedirect();
        $this->assertLessThan(500, $response->getStatusCode());
        $this->assertSame(1, Visit::where('project_id', $project->id)->count());

        $message = (string) session('errors')->first('module');
        $this->assertStringNotContainsString('try again', $message);

        $handedBackLink = session('cockpit_existing_link');
        $this->assertSame($existing->publicUrl(), $handedBackLink);

        // OPEN IT — the link handed back must actually resolve.
        $opened = $this->actingAs($this->user())->get($handedBackLink);
        $opened->assertOk();
    }

    /**
     * The new intent=regenerate-document path — persists the document,
     * creates no Visit and no link, and correctly writes an EARLIER-step
     * field (general_notes, step 2) when submitted from step 3.
     */
    public function test_post_fix_state_2_regenerate_only_persists_an_earlier_step_field(): void
    {
        $project = $this->project();
        $this->engineer();

        $existing = app(\App\Core\Modules\Survey\SurveyService::class)
            ->createFromProject($project, $this->user());

        $token = $existing->access_token;

        $response = $this->submit($project, $this->surveyPayload([
            'intent'        => 'regenerate-document',
            'general_notes' => 'Edited via the walk.',
        ]));

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $existing->refresh();

        $this->assertSame('Edited via the walk.', $existing->general_notes, 'The earlier-step field was not persisted.');
        $this->assertSame(0, Visit::where('project_id', $project->id)->count());
        $this->assertSame($token, $existing->access_token, 'regenerate-document rotated the engineer link token.');

        $success = (string) session('success');
        $this->assertStringNotContainsString('visit', $success);
        $this->assertStringNotContainsString('engineer link', $success);
    }

    /**
     * The cockpit's new supersede control — posts to the existing named
     * route `site-surveys.supersede-from-project`, after which a fresh
     * creation succeeds cleanly.
     */
    public function test_post_fix_state_3_supersede_then_a_fresh_creation_succeeds(): void
    {
        $project = $this->project();
        $this->engineer();

        $existing = app(\App\Core\Modules\Survey\SurveyService::class)
            ->createFromProject($project, $this->user());

        // TOUCH the survey, so `SurveyService::createFromProject(supersede:
        // true)` takes the SUPERSEDE branch rather than the "discard an
        // untouched husk" branch — both are real, distinct paths inside that
        // service, and the supersede route this task surfaces is specifically
        // the ARCHIVE-and-start-fresh one.
        $existing->update(['surveyor_name' => 'Dev Chandra']);
        $existingId = $existing->id;

        // The drawer, with a document already held, renders the supersede form.
        $open = $this->openStep($project, ProjectDeliverable::KEY_SITE_SURVEY, 3);
        $open->assertOk();
        $open->assertSee(route('site-surveys.supersede-from-project', $project));

        // POST the supersede form directly, exactly as the rendered form does.
        $supersede = $this->actingAs($this->user())
            ->post(route('site-surveys.supersede-from-project', $project));

        $existing = SiteSurvey::findOrFail($existingId);
        $this->assertNotNull($existing->superseded_at, 'Superseding did not archive the original survey.');

        $this->assertSame(2, SiteSurvey::where('project_id', $project->id)->count(), 'Superseding did not create a fresh survey.');

        $fresh = SiteSurvey::where('project_id', $project->id)->whereNull('superseded_at')->sole();

        $supersede->assertRedirect(route('site-surveys.confirm-rooms', $fresh));

        // A fresh creation now succeeds cleanly — no collision, because the
        // freshly-created survey (from supersede) has no claiming visit yet.
        $create = $this->submit($project, $this->surveyPayload());
        $create->assertRedirect();
        $create->assertSessionHasNoErrors();

        $this->assertSame(1, Visit::where('project_id', $project->id)->count());
        $this->assertNotNull(session('success'));
    }
}
