<?php

namespace Tests\Feature\Drawings;

use App\Models\Project;
use App\Models\ProjectDrawing;
use App\Models\User;
use App\Services\DocumentArtifactStorage;
use App\Services\Drawings\DrawingExportRendererService;
use App\Services\PdfRenderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Phase 20 Plan 01 — feature tests for the ZIP bundle download route (DRAW-28).
 *
 * Coverage:
 *   1. test_owner_can_download_zip_with_per_drawing_artifacts
 *   2. test_zip_entry_names_have_no_path_traversal
 *
 * As with BoundPdfDownloadTest, PdfRenderService + DrawingExportRendererService
 * are bound to fakes so this is a true integration test of routing + auth +
 * ZIP composition + entry-name sanitisation, without paying Browsershot
 * startup cost.
 */
class ZipBundleDownloadTest extends TestCase
{
    use RefreshDatabase;

    private string $fixturePdf;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(DocumentArtifactStorage::DISK);

        $this->fixturePdf = tempnam(sys_get_temp_dir(), 'zip-feat-fixture-').'.pdf';
        $pdf = new \FPDF();
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->Cell(40, 10, 'ZIP feature test fixture');
        $pdf->Output('F', $this->fixturePdf);

        $this->bindRendererFake();
        $this->bindPdfFake();
    }

    protected function tearDown(): void
    {
        @unlink($this->fixturePdf);
        parent::tearDown();
    }

    private function bindRendererFake(): void
    {
        $fixture = $this->fixturePdf;
        $this->app->bind(DrawingExportRendererService::class, function () use ($fixture) {
            return new class($fixture) extends DrawingExportRendererService {
                public function __construct(private string $fixture) {}

                /** Produce realistic filenames matching the real renderer's pattern. */
                private function fakeFilename(ProjectDrawing $drawing, string $ext): string
                {
                    return sprintf(
                        '%s-%d-v%d-%s.%s',
                        $drawing->kind,
                        $drawing->id,
                        (int) $drawing->version,
                        strtolower((string) \Illuminate\Support\Str::ulid()),
                        $ext,
                    );
                }

                public function renderPdf(ProjectDrawing $drawing): string
                {
                    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.$this->fakeFilename($drawing, 'pdf');
                    copy($this->fixture, $path);

                    return $path;
                }

                public function renderSvg(ProjectDrawing $drawing): string
                {
                    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.$this->fakeFilename($drawing, 'svg');
                    file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

                    return $path;
                }

                public function renderPng(ProjectDrawing $drawing, int $widthPx = 1920): string
                {
                    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.$this->fakeFilename($drawing, 'png');
                    file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII='));

                    return $path;
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
            'name'         => 'ZIP Bundle Test',
            'ref'          => 'ZIP-'.fake()->numerify('###'),
            'client_name'  => 'Test Client Ltd',
            'site_address' => '1 Zip Street, London',
            'status'       => 'quote_imported',
        ]);
    }

    private function makeReadyDrawing(Project $project, string $kind, string $sheet): ProjectDrawing
    {
        return ProjectDrawing::create([
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
    }

    private function captureZipResponse($response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    private function listZipEntries(string $zipBytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'received-zip-').'.zip';
        file_put_contents($tmp, $zipBytes);

        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            @unlink($tmp);
            $this->fail('Could not open downloaded ZIP for inspection');
        }

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = $zip->getNameIndex($i);
        }
        $zip->close();
        @unlink($tmp);

        return $entries;
    }

    // ── 1. Owner can download ZIP with per-drawing artifacts ──────────────

    public function test_owner_can_download_zip_with_per_drawing_artifacts(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $this->makeReadyDrawing($project, ProjectDrawing::KIND_SCHEMATIC, 'AV-201');
        $this->makeReadyDrawing($project, ProjectDrawing::KIND_RACK, 'AV-301');

        $response = $this->actingAs($user)
            ->get(route('projects.drawings.bundle', $project));

        $response->assertOk();
        $this->assertStringContainsString('.zip', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('application/zip', $response->headers->get('Content-Type'));

        $body = $this->captureZipResponse($response);
        $entries = $this->listZipEntries($body);

        // Bound PDF — built inline because project has ≤3 drawings.
        $this->assertNotEmpty(
            array_filter($entries, fn ($e) => preg_match('/^bound-\d+-v\d+-[0-9a-z]{26}\.pdf$/', $e)),
            'ZIP must contain a bound-{id}-v{N}-{ulid}.pdf entry.'
        );
        // Per-drawing PDF / SVG / PNG entries (filenames include kind).
        $this->assertNotEmpty(
            array_filter($entries, fn ($e) => preg_match('/^schematic-\d+-v\d+-[0-9a-z]+\.pdf$/', $e)),
            'ZIP must contain a per-schematic PDF entry.'
        );
        $this->assertNotEmpty(
            array_filter($entries, fn ($e) => preg_match('/^schematic-\d+-v\d+-[0-9a-z]+\.svg$/', $e)),
            'ZIP must contain a per-schematic SVG entry.'
        );
        $this->assertNotEmpty(
            array_filter($entries, fn ($e) => preg_match('/^schematic-\d+-v\d+-[0-9a-z]+\.png$/', $e)),
            'ZIP must contain a per-schematic PNG entry.'
        );
        $this->assertNotEmpty(
            array_filter($entries, fn ($e) => preg_match('/^rack-\d+-v\d+-[0-9a-z]+\.pdf$/', $e)),
            'ZIP must contain a per-rack PDF entry.'
        );
        // Drawing register CSV.
        $this->assertContains('drawing-register.csv', $entries);
    }

    // ── 2. ZIP entry names sanitised (no path traversal) ──────────────────

    public function test_zip_entry_names_have_no_path_traversal(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $this->makeReadyDrawing($project, ProjectDrawing::KIND_SCHEMATIC, 'AV-201');

        $response = $this->actingAs($user)
            ->get(route('projects.drawings.bundle', $project));

        $response->assertOk();

        $body = $this->captureZipResponse($response);
        $entries = $this->listZipEntries($body);

        foreach ($entries as $entry) {
            $this->assertStringNotContainsString('..', $entry,
                "ZIP entry '{$entry}' must not contain '..' (path traversal mitigation T-20-02).");
            $this->assertFalse(
                str_starts_with($entry, '/'),
                "ZIP entry '{$entry}' must not start with '/' (absolute-path mitigation T-20-02).",
            );
            $this->assertFalse(
                str_starts_with($entry, '\\'),
                "ZIP entry '{$entry}' must not start with '\\\\' (Windows absolute-path mitigation T-20-02).",
            );
        }
    }

    // ── 3. Non-owner can download the shared bundle ───────────────────────

    public function test_non_owner_can_download_shared_bundle(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create(); // default role = 'user' — non-owner
        $project = $this->makeProject($owner);
        $this->makeReadyDrawing($project, ProjectDrawing::KIND_SCHEMATIC, 'AV-201');

        // Shared workspace: a non-owner, non-admin user may download any bundle.
        $response = $this->actingAs($intruder)
            ->get(route('projects.drawings.bundle', $project));

        $response->assertOk();
        $this->assertSame('application/zip', $response->headers->get('Content-Type'));

        $body = $this->captureZipResponse($response);
        $entries = $this->listZipEntries($body);
        $this->assertContains('drawing-register.csv', $entries);
    }
}
