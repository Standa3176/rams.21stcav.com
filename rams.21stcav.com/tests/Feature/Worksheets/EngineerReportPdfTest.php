<?php

namespace Tests\Feature\Worksheets;

use App\Models\DeviceLabelPhoto;
use App\Models\Project;
use App\Models\User;
use App\Models\Worksheet;
use App\Models\WorksheetPhoto;
use App\Models\WorksheetSignoff;
use App\Services\PdfRenderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Engineer Report PDF + enhanced View page coverage (quick task 260602-rcd).
 *
 * Coverage:
 *   1. PDF endpoint returns non-empty application/pdf when activity exists
 *      (Browsershot replaced by an in-process fake — same pattern as
 *      BoundPdfDownloadTest::bindPdfFake to avoid the chromium dep)
 *   2. PDF endpoint 404s when worksheet has no engineer activity
 *   3. View page renders Completed-Work Photos section when worksheet has photos
 *   4. View page renders Outstanding Items aggregate when signoffs have items
 *   5. Unauthenticated request to PDF endpoint redirects to login
 *   6. projects.show: Engineer Report button is disabled when no activity,
 *      enabled when activity exists.
 */
class EngineerReportPdfTest extends TestCase
{
    use RefreshDatabase;

    private string $fixturePdf;

    protected function setUp(): void
    {
        parent::setUp();

        // 8-byte fake PDF body — tests assert MIME + non-empty bytes, not PDF
        // validity. Real Browsershot end-to-end belongs in pdf:smoke-test.
        $this->fixturePdf = '%PDF-1.4 fake-engineer-report-fixture';

        $this->bindPdfFake();
    }

    private function bindPdfFake(): void
    {
        $fixture = $this->fixturePdf;
        $this->app->bind(PdfRenderService::class, function () use ($fixture) {
            return new class($fixture) extends PdfRenderService {
                public function __construct(private string $fixture) {}

                public function fromBlade(string $view, array $data, ?string $writeToPath = null, array $options = []): string
                {
                    // Also render the actual Blade view so any syntax errors
                    // surface in the test rather than getting silently swallowed.
                    view($view, $data)->render();

                    if ($writeToPath !== null) {
                        file_put_contents($writeToPath, $this->fixture);
                        return $writeToPath;
                    }
                    return $this->fixture;
                }
            };
        });
    }

    // ── Fixture helpers ──────────────────────────────────────────────────────

    private function makeWorksheet(array $rooms = [['name' => 'Boardroom', 'is_surveyed' => true]]): Worksheet
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        return Worksheet::factory()->create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'status'         => Worksheet::STATUS_DRAFT,
            'generated_data' => [
                'rooms'        => $rooms,
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    private function seedWorksheetPhoto(Worksheet $worksheet, string $roomName = 'Boardroom'): WorksheetPhoto
    {
        return WorksheetPhoto::create([
            'worksheet_id'  => $worksheet->id,
            'room_name'     => $roomName,
            'filename'      => 'worksheet-photos/' . uniqid() . '.jpg',
            'original_name' => 'install.jpg',
            'mime_type'     => 'image/jpeg',
            'caption'       => 'Display installed',
            'sort_order'    => 0,
        ]);
    }

    private function seedSignoffWithSnags(Worksheet $worksheet, string $snags): WorksheetSignoff
    {
        return WorksheetSignoff::create([
            'worksheet_id'         => $worksheet->id,
            'client_name'          => 'Snag Client',
            'signature_png_base64' => 'iVBORw0KGgo=',
            'signed_with_comments' => true,
            'comments'             => $snags,
            'signed_at'            => now(),
        ]);
    }

    // ── 1. PDF returns application/pdf when activity exists ──────────────────

    public function test_pdf_endpoint_returns_application_pdf_when_activity_exists(): void
    {
        $worksheet = $this->makeWorksheet();
        $this->seedWorksheetPhoto($worksheet);
        $this->seedSignoffWithSnags($worksheet, 'Missing cable management');

        $response = $this->actingAs($worksheet->user)
            ->get(route('worksheets.engineer-report-pdf', $worksheet));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertNotEmpty($response->streamedContent());
    }

    // ── 2. PDF 404s when no activity ─────────────────────────────────────────

    public function test_pdf_endpoint_returns_404_when_no_engineer_activity(): void
    {
        $worksheet = $this->makeWorksheet(); // no photos, no signoffs, no label photos

        $response = $this->actingAs($worksheet->user)
            ->get(route('worksheets.engineer-report-pdf', $worksheet));

        $response->assertStatus(404);
    }

    // ── 3. View page renders Completed-Work Photos section ───────────────────

    public function test_view_page_renders_completed_work_photos_section_when_photos_exist(): void
    {
        $worksheet = $this->makeWorksheet();
        $this->seedWorksheetPhoto($worksheet, 'Boardroom');

        $response = $this->actingAs($worksheet->user)
            ->get(route('worksheets.show', $worksheet));

        $response->assertOk();
        $response->assertSee('Completed-Work Photos', false);
    }

    public function test_view_page_does_not_render_completed_work_photos_section_when_no_photos(): void
    {
        $worksheet = $this->makeWorksheet();

        $response = $this->actingAs($worksheet->user)
            ->get(route('worksheets.show', $worksheet));

        $response->assertOk();
        $response->assertDontSee('Completed-Work Photos', false);
    }

    // ── 4. View page renders Outstanding Items aggregate ─────────────────────

    public function test_view_page_renders_outstanding_items_when_signoffs_have_snags(): void
    {
        $worksheet = $this->makeWorksheet();
        $this->seedSignoffWithSnags($worksheet, "Display tilt loose\nReplace dead mic");

        $response = $this->actingAs($worksheet->user)
            ->get(route('worksheets.show', $worksheet));

        $response->assertOk();
        $response->assertSee('Outstanding Items', false);
        $response->assertSee('Display tilt loose', false);
        $response->assertSee('Replace dead mic', false);
    }

    public function test_view_page_omits_outstanding_items_when_none(): void
    {
        $worksheet = $this->makeWorksheet();
        // Add a clean signoff — should NOT trigger the section.
        WorksheetSignoff::create([
            'worksheet_id'         => $worksheet->id,
            'client_name'          => 'Clean Sign',
            'signature_png_base64' => 'iVBORw0KGgo=',
            'signed_with_comments' => false,
            'comments'             => null,
            'signed_at'            => now(),
        ]);

        $response = $this->actingAs($worksheet->user)
            ->get(route('worksheets.show', $worksheet));

        $response->assertOk();
        $response->assertDontSee('Outstanding Items', false);
    }

    // ── 5. Auth — unauthenticated request 302s to login ──────────────────────

    public function test_pdf_endpoint_redirects_unauthenticated_user(): void
    {
        $worksheet = $this->makeWorksheet();
        $this->seedWorksheetPhoto($worksheet);

        $response = $this->get(route('worksheets.engineer-report-pdf', $worksheet));

        // Default Breeze redirect to login. Guest middleware (web) sends 302.
        $response->assertRedirect(route('login'));
    }

    // ── 6. projects.show: Engineer Report button states ──────────────────────

    public function test_projects_show_renders_disabled_engineer_report_button_when_no_activity(): void
    {
        $worksheet = $this->makeWorksheet();
        $project   = $worksheet->project;

        $response = $this->actingAs($worksheet->user)
            ->get(route('projects.show', $project));

        $response->assertOk();
        // Disabled <button> branch — title attribute is the stable assertion target.
        $response->assertSee('No engineer activity yet', false);
        $response->assertSee('📄 Engineer Report', false);
        // Crucially: the disabled branch must NOT emit the engineer-report-pdf URL.
        $response->assertDontSee(route('worksheets.engineer-report-pdf', $worksheet), false);
    }

    public function test_projects_show_renders_enabled_engineer_report_link_when_activity_exists(): void
    {
        $worksheet = $this->makeWorksheet();
        $this->seedWorksheetPhoto($worksheet);
        $project = $worksheet->project;

        $response = $this->actingAs($worksheet->user)
            ->get(route('projects.show', $project));

        $response->assertOk();
        $response->assertSee('📄 Engineer Report', false);
        $response->assertSee(route('worksheets.engineer-report-pdf', $worksheet), false);
        $response->assertDontSee('No engineer activity yet', false);
    }
}
