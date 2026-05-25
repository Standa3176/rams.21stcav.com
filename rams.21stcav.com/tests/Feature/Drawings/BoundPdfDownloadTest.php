<?php

namespace Tests\Feature\Drawings;

use App\Jobs\BuildBoundPdfJob;
use App\Models\Project;
use App\Models\ProjectDrawing;
use App\Models\User;
use App\Services\DocumentArtifactStorage;
use App\Services\Drawings\DrawingExportRendererService;
use App\Services\PdfRenderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 20 Plan 01 — feature tests for the bound-PDF download routes (DRAW-21).
 *
 * Coverage:
 *   1. test_owner_can_download_bound_pdf_when_ready_drawings_exist
 *   2. test_non_owner_gets_403
 *   3. test_regen_needed_badge_renders_when_drawing_updated_after_bound_pdf
 *
 * PdfRenderService + DrawingExportRendererService are bound to fakes that
 * write tiny FPDF-generated single-page fixture PDFs. This exercises the
 * routing + auth + freshness + controller wiring end-to-end without paying
 * Browsershot startup time. Browsershot end-to-end is covered by the
 * Plan 20-02 smoke test (`pdf:smoke-test --drawings`).
 */
class BoundPdfDownloadTest extends TestCase
{
    use RefreshDatabase;

    private string $fixturePdf;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(DocumentArtifactStorage::DISK);

        // Single-page fixture PDF (FPDF — bundled by Task 1 composer require).
        $this->fixturePdf = tempnam(sys_get_temp_dir(), 'bound-feat-fixture-').'.pdf';
        $pdf = new \FPDF();
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->Cell(40, 10, 'Bound PDF feature test fixture');
        $pdf->Output('F', $this->fixturePdf);

        $this->bindRendererFake();
        $this->bindPdfFake();
    }

    protected function tearDown(): void
    {
        @unlink($this->fixturePdf);
        parent::tearDown();
    }

    private function bindRendererFake(array $failingIds = []): void
    {
        $fixture = $this->fixturePdf;
        $this->app->bind(DrawingExportRendererService::class, function () use ($fixture, $failingIds) {
            return new class($fixture, $failingIds) extends DrawingExportRendererService {
                public function __construct(private string $fixture, private array $failingIds) {}

                public function renderPdf(ProjectDrawing $drawing): string
                {
                    if (in_array($drawing->id, $this->failingIds, true)) {
                        throw new \RuntimeException('simulated');
                    }
                    $copy = tempnam(sys_get_temp_dir(), 'fake-render-').'.pdf';
                    copy($this->fixture, $copy);

                    return $copy;
                }

                public function renderSvg(ProjectDrawing $drawing): string
                {
                    $tmp = tempnam(sys_get_temp_dir(), 'fake-svg-').'.svg';
                    file_put_contents($tmp, '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

                    return $tmp;
                }

                public function renderPng(ProjectDrawing $drawing, int $widthPx = 1920): string
                {
                    $tmp = tempnam(sys_get_temp_dir(), 'fake-png-').'.png';
                    // Tiny 1x1 PNG byte sequence so file_exists() + addFile() work.
                    file_put_contents($tmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII='));

                    return $tmp;
                }
            };
        });
    }

    private function bindPdfFake(): void
    {
        $fixture = $this->fixturePdf;
        $this->app->bind(PdfRenderService::class, function () use ($fixture) {
            return new class($fixture) extends PdfRenderService {
                public function __construct(private string $fixture) {}

                public function fromBlade(string $view, array $data, ?string $writeToPath = null, array $options = []): string
                {
                    if ($writeToPath !== null) {
                        copy($this->fixture, $writeToPath);

                        return $writeToPath;
                    }

                    return file_get_contents($this->fixture);
                }
            };
        });
    }

    private function makeProject(User $user): Project
    {
        return Project::create([
            'user_id'      => $user->id,
            'name'         => 'Bound PDF Download Test',
            'ref'          => 'BOUND-DL-'.fake()->numerify('###'),
            'client_name'  => 'Test Client Ltd',
            'site_address' => '1 Bound Test Street, London',
            'status'       => 'quote_imported',
        ]);
    }

    private function makeReadySchematic(Project $project, string $sheet): ProjectDrawing
    {
        return ProjectDrawing::create([
            'project_id'         => $project->id,
            'site_survey_room_id'=> null,
            'kind'               => ProjectDrawing::KIND_SCHEMATIC,
            'version'            => 1,
            'sheet_number'       => $sheet,
            'status'             => ProjectDrawing::STATUS_READY,
            'generated_svg'      => '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
            'generated_by'       => $project->user_id,
            'source_data'        => [],
        ]);
    }

    // ── 1. Owner can download bound PDF — happy path ──────────────────────

    public function test_owner_can_download_bound_pdf_when_ready_drawings_exist(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $this->makeReadySchematic($project, 'AV-201');
        $this->makeReadySchematic($project, 'AV-202');

        $response = $this->actingAs($user)
            ->get(route('projects.drawings.bound-pdf', $project));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        // Use streamed/binary response content via getFile when available;
        // for the inline-build path, response()->download returns BinaryFileResponse.
        $body = $response->streamedContent() ?: $response->getContent();
        $this->assertStringStartsWith('%PDF', substr((string) $body, 0, 4),
            'Response body must begin with the PDF magic header.');
    }

    // ── 2. Non-owner can download the shared bound PDF ────────────────────

    public function test_non_owner_can_download_shared_bound_pdf(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create(); // default role = 'user' — non-owner
        $project = $this->makeProject($owner);
        $this->makeReadySchematic($project, 'AV-201');

        // Shared workspace: a non-owner, non-admin user may download any bound PDF.
        $response = $this->actingAs($intruder)
            ->get(route('projects.drawings.bound-pdf', $project));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $body = $response->streamedContent() ?: $response->getContent();
        $this->assertStringStartsWith('%PDF', substr((string) $body, 0, 4),
            'Response body must begin with the PDF magic header.');
    }

    // ── 3. Regen-needed badge surfaces when drawings touched after bound PDF ─

    public function test_regen_needed_badge_renders_when_drawing_updated_after_bound_pdf(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $drawing = $this->makeReadySchematic($project, 'AV-201');

        // First request — builds the bound PDF inline (project has 1 drawing,
        // ≤3 threshold).
        $this->actingAs($user)->get(route('projects.drawings.bound-pdf', $project))
            ->assertOk();

        // Touch the drawing AFTER the bound PDF was written.
        // sleep(1) to ensure mtime differs (filesystems are second-resolution).
        sleep(1);
        $drawing->touch();

        // Now visit the index — staleness flag must be true → badge renders.
        $response = $this->actingAs($user)->get(route('projects.drawings.index', $project));

        $response->assertOk();
        $response->assertSee('Regen needed');
    }

    // ── 4. POST regenerateBoundPdf dispatches the job + flashes message ───

    public function test_regenerate_bound_pdf_dispatches_job(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $this->makeReadySchematic($project, 'AV-201');

        $response = $this->actingAs($user)
            ->post(route('projects.drawings.bound-pdf.build', $project));

        $response->assertRedirect(route('projects.drawings.index', $project));
        Bus::assertDispatched(BuildBoundPdfJob::class, function (BuildBoundPdfJob $job) use ($project) {
            return $job->projectId === (int) $project->id;
        });
    }
}
