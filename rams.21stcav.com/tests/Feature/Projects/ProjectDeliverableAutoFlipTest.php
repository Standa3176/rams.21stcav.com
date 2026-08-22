<?php

namespace Tests\Feature\Projects;

use App\Jobs\BuildOmManualJob;
use App\Models\CommissioningItem;
use App\Models\InstallProgramme;
use App\Models\Project;
use App\Models\ProjectDeliverable;
use App\Models\ProjectDeliverableAudit;
use App\Models\ProjectDrawing;
use App\Models\ProjectPackage;
use App\Models\RamsDocument;
use App\Models\User;
use App\Services\ProjectDeliverablesService;
use App\Services\PdfTextExtractorService;
use App\Services\QuoteLineExtractorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 260822-05 — D-02 auto-flip wiring proof: one test per wired creation entry
 * point (16 total), exercised via a REAL HTTP request against the actual
 * route (not a direct ProjectDeliverablesService call) so the wiring itself
 * is proven, not just the service logic Plan 01 already unit-tests.
 *
 * Sites (matching 260822-05-PLAN.md's re-derived 16-row table):
 *   1.  RamsController::generateFromProject()      — rams.from-project
 *   2.  RamsController::store()                    — rams.store (+ null-guard negative)
 *   3.  RamsController::regenerate()                — rams.regenerate
 *   4.  QuoteUploadController::store()              — rams.upload.store
 *   5.  WorksheetController::generateFromProject()  — worksheets.generate-from-project
 *   6.  OmManualController::generateFromProject()   — om-manuals.generate-from-project
 *   7.  OmManualController::store()                 — om-manuals.store (+ null-guard negative)
 *   8.  OmManualController::storeFromProject()      — om-manuals.from-project
 *   9.  CableScheduleController::generateFromProject() — cable-schedules.generate-from-project
 *   10. InstallProgrammeController::generate()      — install-programmes.generate
 *   11. SiteSurveyController::createFromProject()   — site-surveys.from-project
 *   12. SiteSurveyController::store()               — site-surveys.store (+ null-guard negative)
 *   13. ProjectDrawingController::createSchematic() — projects.drawings.create-schematic
 *   14. ProjectDrawingController::createRack()      — projects.drawings.create-rack
 *   15. ProjectDrawingController::regenerate()      — projects.drawings.regenerate
 *   16. CommissioningSignoffController::finalise()  — commissioning.signoff.finalise
 *
 * Plus one "already required" negative case proving a real flip never
 * writes a spurious second audit row when nothing needed fixing.
 *
 * Confirmed-excluded creation sites (NOT gaps — verified inert this session,
 * see 260822-05-PLAN.md's objective block):
 *   - CableScheduleController::store() — the create array has no project_id
 *     field at all (legacy standalone schedules; edit()'s own comment
 *     confirms "Project may be null on legacy standalone schedules").
 *   - RamsRegenerateSnapshotsCommand — runs against a temporary in-memory
 *     sqlite DB for golden-fixture regeneration; never touches the real DB.
 *   - DrawIoSpikeController::resolveOrCreateSpikeDrawing() /
 *     DrawingService::saveSpikeXml()'s ->replicate() — sandbox/spike rows
 *     deliberately unlinked from the user-facing drawings index (D-LOCK-7).
 */
class ProjectDeliverableAutoFlipTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_BASE64_PNG_TINY = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgAAIAAAUAAen63NgAAAAASUVORK5CYII=';

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function seedNotRequired(Project $project, string $key, User $user): void
    {
        app(ProjectDeliverablesService::class)->setState(
            $project,
            $key,
            ProjectDeliverable::STATE_NOT_REQUIRED,
            $user,
        );
    }

    private function assertAutoFlipped(Project $project, string $key): void
    {
        $row = ProjectDeliverable::where('project_id', $project->id)
            ->where('deliverable_key', $key)
            ->first();

        $this->assertNotNull($row, "no project_deliverables row for key '{$key}' after creation — the auto-flip site never fired");
        $this->assertSame(ProjectDeliverable::STATE_REQUIRED, $row->state, "deliverable '{$key}' was not flipped back to required");
        $this->assertSame(
            1,
            ProjectDeliverableAudit::where('project_deliverable_id', $row->id)
                ->where('action', ProjectDeliverableAudit::ACTION_AUTO_FLIP)
                ->count(),
            "expected exactly one auto_flip audit row for '{$key}'",
        );
    }

    private function assertNoAutoFlipOccurred(): void
    {
        $this->assertSame(
            0,
            ProjectDeliverableAudit::where('action', ProjectDeliverableAudit::ACTION_AUTO_FLIP)->count(),
            'no auto_flip audit row should exist — the null-guard must have suppressed the call',
        );
    }

    private function validRamsFormPayload(array $overrides = []): array
    {
        return array_merge([
            'project_ref'       => 'AUTOFLIP-RAMS-' . uniqid(),
            'project_name'      => 'Auto-Flip Test Project',
            'client_name'       => 'Acme Corp',
            'site_address'      => '1 Auto-Flip Lane, London, EC1A 1AA',
            'works_description' => 'Supply and installation of AV systems throughout the premises.',
            'hazards'           => ['Electrocution', 'Manual Handling'],
            'ppe'               => ['Safety Boots', 'Hi-Vis Vest'],
            'persons_at_risk'   => ['21CAV Staff', 'Client Staff'],
        ], $overrides);
    }

    /** Minimal reviewed ProjectPackage that passes RamsReviewValidatorService::validate(). */
    private function seedReviewedRamsPackage(Project $project, User $user): void
    {
        ProjectPackage::create([
            'project_id'        => $project->id,
            'user_id'           => $user->id,
            'quote_filename'    => 'test.pdf',
            'quote_path'        => 'quote-imports/test.pdf',
            'extracted_data'    => [
                'project'    => ['project_name' => $project->name],
                'equipment'  => [['quantity' => 1, 'name' => 'Test Item']],
                'activities' => [['key' => 'install', 'label' => 'Installation']],
                'ppe'        => ['Safety Boots'],
            ],
            'equipment_list'    => [],
            'cable_list'        => [],
            'works_description' => null,
            'revision'          => 1,
            'status'            => ProjectPackage::STATUS_REVIEWED,
        ]);
    }

    /** Minimal reviewed ProjectPackage with room data — feeds O&M's project-data paths. */
    private function seedReviewedOmPackage(Project $project, User $user): void
    {
        ProjectPackage::create([
            'project_id'        => $project->id,
            'user_id'           => $user->id,
            'quote_filename'    => 'test.pdf',
            'quote_path'        => 'quote-imports/test.pdf',
            'extracted_data'    => [
                'rooms'          => ['Boardroom'],
                'room_overviews' => [[
                    'room'             => 'Boardroom',
                    'overview'         => 'Sony 85" display + Yealink A30 bar.',
                    'works_summary'    => '',
                    'solution_type_id' => null,
                ]],
            ],
            'equipment_list'    => [['name' => 'Sony 85" Display', 'quantity' => 1, 'area' => 'Boardroom']],
            'cable_list'        => [],
            'works_description' => null,
            'revision'          => 1,
            'status'            => ProjectPackage::STATUS_REVIEWED,
        ]);
    }

    // ── Site 1: RamsController::generateFromProject() ──────────────────────────

    public function test_site1_rams_generate_from_project_auto_flips(): void
    {
        Bus::fake();
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $this->seedReviewedRamsPackage($project, $user);
        $this->seedNotRequired($project, ProjectDeliverable::KEY_RAMS, $user);

        $this->actingAs($user)->post(route('rams.from-project', $project));

        $this->assertAutoFlipped($project, ProjectDeliverable::KEY_RAMS);
    }

    // ── Site 2: RamsController::store() ─────────────────────────────────────────

    public function test_site2_rams_store_with_project_id_auto_flips(): void
    {
        Bus::fake();
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $this->seedNotRequired($project, ProjectDeliverable::KEY_RAMS, $user);

        $this->actingAs($user)->post(route('rams.store'), $this->validRamsFormPayload([
            'project_id' => $project->id,
        ]));

        $this->assertAutoFlipped($project, ProjectDeliverable::KEY_RAMS);
    }

    public function test_site2_rams_store_without_project_id_does_not_flip(): void
    {
        Bus::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('rams.store'), $this->validRamsFormPayload());

        $this->assertNoAutoFlipOccurred();
    }

    // ── Site 3: RamsController::regenerate() ────────────────────────────────────

    public function test_site3_rams_regenerate_auto_flips(): void
    {
        Bus::fake();
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $rams    = RamsDocument::factory()->create(['project_id' => $project->id, 'user_id' => $user->id]);
        $this->seedNotRequired($project, ProjectDeliverable::KEY_RAMS, $user);

        $this->actingAs($user)->post(route('rams.regenerate', $rams));

        $this->assertAutoFlipped($project, ProjectDeliverable::KEY_RAMS);
    }

    // ── Site 4: QuoteUploadController::store() ──────────────────────────────────

    public function test_site4_quote_upload_store_auto_flips(): void
    {
        Bus::fake();
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id, 'ref' => 'AUTOFLIP-UPLOAD-001']);
        $this->seedNotRequired($project, ProjectDeliverable::KEY_RAMS, $user);

        $this->actingAs($user)->post(route('rams.upload.store'), [
            'quote_pdf'   => UploadedFile::fake()->create('quote.pdf', 50, 'application/pdf'),
            'project_ref' => 'AUTOFLIP-UPLOAD-001',
        ]);

        $this->assertAutoFlipped($project, ProjectDeliverable::KEY_RAMS);
    }

    // ── Site 5: WorksheetController::generateFromProject() ─────────────────────

    public function test_site5_worksheet_generate_from_project_auto_flips(): void
    {
        Bus::fake();
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $this->seedNotRequired($project, ProjectDeliverable::KEY_WORKSHEET, $user);

        $this->actingAs($user)->post(route('worksheets.generate-from-project', $project));

        $this->assertAutoFlipped($project, ProjectDeliverable::KEY_WORKSHEET);
    }

    // ── Site 6: OmManualController::generateFromProject() ──────────────────────

    public function test_site6_om_generate_from_project_auto_flips(): void
    {
        Bus::fake([BuildOmManualJob::class]);
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $this->seedReviewedOmPackage($project, $user);
        $this->seedNotRequired($project, ProjectDeliverable::KEY_OM, $user);

        // ?draft=1 bypasses the strict NO-TBC validator (handover_date /
        // drawings aren't set on this bare fixture) — same pattern as
        // OmDraftModeTest, which exercises this exact route.
        $this->actingAs($user)->post(route('om-manuals.generate-from-project', $project) . '?draft=1');

        $this->assertAutoFlipped($project, ProjectDeliverable::KEY_OM);
    }

    // ── Site 7: OmManualController::store() ─────────────────────────────────────

    private function fakeOmExtractionPipeline(): void
    {
        Http::fake(['*' => Http::response([
            'content'     => [['type' => 'text', 'text' => json_encode([
                'equipment' => [['quantity' => 2, 'name' => 'Logitech Rally Bar']],
            ])]],
            'stop_reason' => 'end_turn',
        ], 200)]);

        $this->mock(PdfTextExtractorService::class, function ($mock): void {
            $mock->shouldReceive('extract')->andReturn('mock quote text');
        });
        $this->mock(QuoteLineExtractorService::class, function ($mock): void {
            $mock->shouldReceive('extractEquipmentLines')->andReturn(['2 Logitech Rally Bar']);
        });
    }

    public function test_site7_om_store_with_project_id_auto_flips(): void
    {
        $this->fakeOmExtractionPipeline();
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $this->seedNotRequired($project, ProjectDeliverable::KEY_OM, $user);

        $this->actingAs($user)->post(route('om-manuals.store'), [
            // createWithContent (not create($name, $kilobytes)) — the sparse
            // file produced by create()'s fseek+single-byte-write approach
            // was observed landing as a genuine 0-byte file after move() on
            // this Windows filesystem, tripping extractFromPdf()'s own
            // "Could not read the uploaded PDF file" guard. Real content
            // sidesteps that platform-specific sparse-file quirk entirely.
            'quote_pdf'  => UploadedFile::fake()->createWithContent('quote.pdf', str_repeat('%PDF-1.4 fake', 200)),
            'project_id' => $project->id,
        ]);

        $this->assertAutoFlipped($project, ProjectDeliverable::KEY_OM);
    }

    public function test_site7_om_store_without_project_id_does_not_flip(): void
    {
        $this->fakeOmExtractionPipeline();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('om-manuals.store'), [
            'quote_pdf' => UploadedFile::fake()->createWithContent('quote.pdf', str_repeat('%PDF-1.4 fake', 200)),
        ]);

        $this->assertNoAutoFlipOccurred();
    }

    // ── Site 8: OmManualController::storeFromProject() ─────────────────────────

    public function test_site8_om_store_from_project_auto_flips(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $this->seedReviewedOmPackage($project, $user);
        $this->seedNotRequired($project, ProjectDeliverable::KEY_OM, $user);

        $this->actingAs($user)->post(route('om-manuals.from-project', $project));

        $this->assertAutoFlipped($project, ProjectDeliverable::KEY_OM);
    }

    // ── Site 9: CableScheduleController::generateFromProject() ─────────────────

    public function test_site9_cable_schedule_generate_from_project_auto_flips(): void
    {
        Bus::fake();
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $this->seedNotRequired($project, ProjectDeliverable::KEY_CABLE_SCHEDULE, $user);

        $this->actingAs($user)->post(route('cable-schedules.generate-from-project', $project));

        $this->assertAutoFlipped($project, ProjectDeliverable::KEY_CABLE_SCHEDULE);
    }

    // ── Site 10: InstallProgrammeController::generate() ─────────────────────────

    public function test_site10_install_programme_generate_auto_flips(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $this->seedNotRequired($project, ProjectDeliverable::KEY_INSTALL_PROGRAMME, $user);

        $this->actingAs($user)->post(route('install-programmes.generate', $project));

        $this->assertAutoFlipped($project, ProjectDeliverable::KEY_INSTALL_PROGRAMME);
    }

    // ── Site 11: SiteSurveyController::createFromProject() ─────────────────────

    public function test_site11_survey_create_from_project_auto_flips(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $this->seedNotRequired($project, ProjectDeliverable::KEY_SITE_SURVEY, $user);

        $this->actingAs($user)->get(route('site-surveys.from-project', $project));

        $this->assertAutoFlipped($project, ProjectDeliverable::KEY_SITE_SURVEY);
    }

    // ── Site 12: SiteSurveyController::store() ──────────────────────────────────

    public function test_site12_survey_store_with_project_id_auto_flips(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $this->seedNotRequired($project, ProjectDeliverable::KEY_SITE_SURVEY, $user);

        $this->actingAs($user)->post(route('site-surveys.store'), [
            'project_id'   => $project->id,
            'project_name' => 'Auto-Flip Survey Test',
        ]);

        $this->assertAutoFlipped($project, ProjectDeliverable::KEY_SITE_SURVEY);
    }

    public function test_site12_survey_store_without_project_id_does_not_flip(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('site-surveys.store'), [
            'project_name' => 'No-Project Survey Test',
        ]);

        $this->assertNoAutoFlipOccurred();
    }

    // ── Site 13: ProjectDrawingController::createSchematic() ───────────────────

    public function test_site13_drawing_create_schematic_auto_flips(): void
    {
        Bus::fake();
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $this->seedNotRequired($project, ProjectDeliverable::KEY_DRAWINGS, $user);

        $this->actingAs($user)->post(route('projects.drawings.create-schematic', $project));

        $this->assertAutoFlipped($project, ProjectDeliverable::KEY_DRAWINGS);
    }

    // ── Site 14: ProjectDrawingController::createRack() ─────────────────────────

    public function test_site14_drawing_create_rack_auto_flips(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $this->seedNotRequired($project, ProjectDeliverable::KEY_DRAWINGS, $user);

        $this->actingAs($user)->post(route('projects.drawings.create-rack', $project));

        $this->assertAutoFlipped($project, ProjectDeliverable::KEY_DRAWINGS);
    }

    // ── Site 15: ProjectDrawingController::regenerate() ─────────────────────────

    public function test_site15_drawing_regenerate_auto_flips(): void
    {
        Bus::fake();
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $drawing = ProjectDrawing::create([
            'project_id'   => $project->id,
            'kind'         => ProjectDrawing::KIND_SCHEMATIC,
            'version'      => 1,
            'status'       => ProjectDrawing::STATUS_APPROVED,
            'source_data'  => [],
            'generated_by' => $user->id,
        ]);
        $this->seedNotRequired($project, ProjectDeliverable::KEY_DRAWINGS, $user);

        $this->actingAs($user)->post(route('projects.drawings.regenerate', [$project, $drawing]));

        $this->assertAutoFlipped($project, ProjectDeliverable::KEY_DRAWINGS);
    }

    // ── Site 16: CommissioningSignoffController::finalise() ─────────────────────

    public function test_site16_commissioning_finalise_auto_flips(): void
    {
        Storage::fake('documents');
        $user      = User::factory()->create();
        $project   = Project::factory()->create(['user_id' => $user->id, 'status' => Project::STATUS_INSTALLING]);
        $programme = InstallProgramme::factory()->create([
            'project_id' => $project->id,
            'status'     => InstallProgramme::STATUS_ACTIVE,
        ]);
        CommissioningItem::factory()->create([
            'install_programme_id' => $programme->id,
            'status'               => CommissioningItem::STATUS_PASS,
        ]);
        $this->seedNotRequired($project, ProjectDeliverable::KEY_SNAGGING, $user);

        $this->actingAs($user)->postJson(route('commissioning.signoff.finalise', $programme), [
            'client_name'          => 'Alice Client',
            'client_role'          => 'IT Manager',
            'client_company'       => 'Acme Ltd',
            'signature_png_base64' => 'data:image/png;base64,' . self::VALID_BASE64_PNG_TINY,
        ]);

        $this->assertAutoFlipped($project, ProjectDeliverable::KEY_SNAGGING);
    }

    // ── Negative: create against an already-required deliverable ───────────────

    public function test_create_against_already_required_deliverable_writes_no_new_audit_row(): void
    {
        Bus::fake();
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        app(ProjectDeliverablesService::class)->setState(
            $project,
            ProjectDeliverable::KEY_WORKSHEET,
            ProjectDeliverable::STATE_REQUIRED,
            $user,
        );

        $this->actingAs($user)->post(route('worksheets.generate-from-project', $project));

        $row = ProjectDeliverable::where('project_id', $project->id)
            ->where('deliverable_key', ProjectDeliverable::KEY_WORKSHEET)
            ->firstOrFail();

        $this->assertSame(ProjectDeliverable::STATE_REQUIRED, $row->state);
        $this->assertSame(
            1,
            ProjectDeliverableAudit::where('project_deliverable_id', $row->id)->count(),
            'only the original manual_change audit row from setState() should exist — creating a document against an already-required deliverable must not write a second row',
        );
        $this->assertDatabaseCount('project_deliverable_audits', 1);
    }
}
