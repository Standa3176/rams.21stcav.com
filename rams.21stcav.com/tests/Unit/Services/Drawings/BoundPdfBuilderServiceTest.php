<?php

namespace Tests\Unit\Services\Drawings;

use App\Models\Project;
use App\Models\ProjectDrawing;
use App\Models\User;
use App\Services\DocumentArtifactStorage;
use App\Services\Drawings\BoundPdfBuilderService;
use App\Services\Drawings\DrawingExportRendererService;
use App\Services\PdfRenderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;
use Tests\TestCase;

/**
 * Phase 20 Plan 01 — locks BoundPdfBuilderService behaviour (DRAW-21):
 *   1. test_two_schematic_project_produces_bound_pdf_with_three_pages
 *   2. test_failed_drawing_is_skipped_but_register_still_lists_it
 *   3. test_floor_plan_drawings_excluded
 *   4. test_register_orders_schematics_before_racks
 *   5. test_bound_pdf_filename_matches_pattern
 *
 * Browsershot is NOT exercised — DrawingExportRendererService::renderPdf and
 * PdfRenderService::fromBlade are bound to fakes that write tiny single-page
 * fixture PDFs via FPDF. The FPDI concat path IS exercised end-to-end against
 * those fixtures, so the page-count assertions are real.
 */
class BoundPdfBuilderServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $fixturePdf;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(DocumentArtifactStorage::DISK);

        // Build a tiny single-page PDF fixture using FPDI's underlying FPDF.
        // Persist to a temp path the renderer fakes can copy from.
        $this->fixturePdf = tempnam(sys_get_temp_dir(), 'bound-fixture-').'.pdf';
        $pdf = new \FPDF();
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->Cell(40, 10, 'Single-page fixture');
        $pdf->Output('F', $this->fixturePdf);
    }

    protected function tearDown(): void
    {
        @unlink($this->fixturePdf);
        parent::tearDown();
    }

    private function makeProject(): Project
    {
        $user = User::factory()->create();

        return Project::create([
            'user_id'      => $user->id,
            'name'         => 'Bound PDF Test',
            'ref'          => 'BOUND-'.fake()->numerify('###'),
            'client_name'  => 'Test Client Ltd',
            'site_address' => '1 Bound Street, London',
            'status'       => 'quote_imported',
        ]);
    }

    private function makeDrawing(Project $project, string $kind, int $minutesOffset = 0, ?string $sheet = null): ProjectDrawing
    {
        $row = ProjectDrawing::create([
            'project_id'         => $project->id,
            'site_survey_room_id'=> null,
            'kind'               => $kind,
            'rack_label'         => $kind === ProjectDrawing::KIND_RACK ? 'Rack 1' : null,
            'version'            => 1,
            'sheet_number'       => $sheet,
            'status'             => ProjectDrawing::STATUS_READY,
            'generated_svg'      => '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
            'generated_by'       => $project->user_id,
            'source_data'        => [],
        ]);
        // Force created_at to a deterministic order independent of insert order.
        if ($minutesOffset !== 0) {
            $row->created_at = now()->subMinutes($minutesOffset);
            $row->save(['timestamps' => false]);
        }

        return $row;
    }

    /**
     * Bind a fake DrawingExportRendererService that returns the fixture PDF
     * for every drawing (or throws for IDs in $failingIds).
     */
    private function bindRendererFake(array $failingIds = []): void
    {
        $fixture = $this->fixturePdf;
        $this->app->bind(DrawingExportRendererService::class, function () use ($fixture, $failingIds) {
            return new class($fixture, $failingIds) extends DrawingExportRendererService {
                public function __construct(private string $fixture, private array $failingIds)
                {
                    // Skip parent constructor — we override every method we use.
                }

                public function renderPdf(ProjectDrawing $drawing): string
                {
                    if (in_array($drawing->id, $this->failingIds, true)) {
                        throw new \RuntimeException('simulated render failure');
                    }
                    // Copy fixture so the caller can safely operate on its own
                    // file path (FPDI reads the file synchronously — this just
                    // mirrors what real renderPdf does).
                    $copy = tempnam(sys_get_temp_dir(), 'fake-render-').'.pdf';
                    copy($this->fixture, $copy);

                    return $copy;
                }
            };
        });
    }

    /**
     * Bind a fake PdfRenderService that writes a single-page fixture PDF to
     * the requested path (instead of invoking Browsershot).
     */
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

    private function buildAndCount(string $path): int
    {
        $pdf = new Fpdi();

        return $pdf->setSourceFile($path);
    }

    // ── Test 1 ───────────────────────────────────────────────────────────────

    public function test_two_schematic_project_produces_bound_pdf_with_three_pages(): void
    {
        $project = $this->makeProject();
        $this->makeDrawing($project, ProjectDrawing::KIND_SCHEMATIC, minutesOffset: 20, sheet: 'AV-201');
        $this->makeDrawing($project, ProjectDrawing::KIND_SCHEMATIC, minutesOffset: 10, sheet: 'AV-202');

        $this->bindRendererFake();
        $this->bindPdfFake();

        $result = app(BoundPdfBuilderService::class)->build($project->fresh());

        $this->assertFileExists($result['path']);
        $this->assertSame(3, $this->buildAndCount($result['path']),
            'Cover (1) + 2 schematics = 3 pages.');
        $this->assertCount(2, $result['register']);
        $this->assertEmpty($result['failed_drawings']);
    }

    // ── Test 2 ───────────────────────────────────────────────────────────────

    public function test_failed_drawing_is_skipped_but_register_still_lists_it(): void
    {
        $project = $this->makeProject();
        $okDrawing  = $this->makeDrawing($project, ProjectDrawing::KIND_SCHEMATIC, minutesOffset: 20, sheet: 'AV-201');
        $badDrawing = $this->makeDrawing($project, ProjectDrawing::KIND_SCHEMATIC, minutesOffset: 10, sheet: 'AV-202');

        $this->bindRendererFake(failingIds: [$badDrawing->id]);
        $this->bindPdfFake();

        $result = app(BoundPdfBuilderService::class)->build($project->fresh());

        // 2 register entries (failed drawing still listed) but PDF only has
        // cover + 1 successful page = 2 pages.
        $this->assertCount(2, $result['register']);
        $this->assertCount(1, $result['failed_drawings']);
        $this->assertSame($badDrawing->id, $result['failed_drawings'][0]['drawing_id']);
        $this->assertStringContainsString('[render failed]', $result['register'][1]['title']);
        $this->assertSame(2, $this->buildAndCount($result['path']),
            'Cover (1) + 1 successful schematic = 2 pages (failed drawing skipped from concat).');
    }

    // ── Test 3 ───────────────────────────────────────────────────────────────

    public function test_floor_plan_drawings_excluded(): void
    {
        $project = $this->makeProject();
        $this->makeDrawing($project, ProjectDrawing::KIND_SCHEMATIC, minutesOffset: 20, sheet: 'AV-201');
        $this->makeDrawing($project, ProjectDrawing::KIND_FLOOR_PLAN, minutesOffset: 10);

        $this->bindRendererFake();
        $this->bindPdfFake();

        $result = app(BoundPdfBuilderService::class)->build($project->fresh());

        // Register has only the schematic — floor plan filtered out.
        $this->assertCount(1, $result['register']);
        $this->assertSame(ProjectDrawing::KIND_SCHEMATIC, $result['register'][0]['kind']);
    }

    // ── Test 4 ───────────────────────────────────────────────────────────────

    public function test_register_orders_schematics_before_racks(): void
    {
        $project = $this->makeProject();
        // Rack created BEFORE schematic in time, but kind-grouping must put
        // the schematic first.
        $this->makeDrawing($project, ProjectDrawing::KIND_RACK,      minutesOffset: 20, sheet: 'AV-301');
        $this->makeDrawing($project, ProjectDrawing::KIND_SCHEMATIC, minutesOffset: 10, sheet: 'AV-201');

        $this->bindRendererFake();
        $this->bindPdfFake();

        $result = app(BoundPdfBuilderService::class)->build($project->fresh());

        $this->assertCount(2, $result['register']);
        $this->assertSame(ProjectDrawing::KIND_SCHEMATIC, $result['register'][0]['kind'],
            'Schematics ALWAYS come before racks regardless of created_at.');
        $this->assertSame(ProjectDrawing::KIND_RACK, $result['register'][1]['kind']);
    }

    // ── Test 5 ───────────────────────────────────────────────────────────────

    public function test_bound_pdf_filename_matches_pattern(): void
    {
        $project = $this->makeProject();
        $this->makeDrawing($project, ProjectDrawing::KIND_SCHEMATIC, sheet: 'AV-201');

        $this->bindRendererFake();
        $this->bindPdfFake();

        $result = app(BoundPdfBuilderService::class)->build($project->fresh());

        $this->assertMatchesRegularExpression(
            '~drawings[\\\\/]bound-\d+-v\d+-[0-9a-z]{26}\.pdf$~',
            $result['path'],
        );
        $this->assertSame(1, $result['version'], 'First bound PDF for a project starts at v1.');
    }
}
