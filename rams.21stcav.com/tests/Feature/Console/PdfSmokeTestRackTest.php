<?php

namespace Tests\Feature\Console;

use App\Models\ProjectDrawing;
use App\Services\PdfRenderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Phase 20 Plan 02 Task 1 — extends pdf:smoke-test --drawings to cover the
 * rack render path in addition to the existing schematic path.
 *
 * Phase 17 Plan 03 added --drawings (schematic only). This plan extends to:
 *   - Try a real ready rack first; otherwise fall back to in-memory rack
 *     fixture rendering pdf.drawings.rack.
 *   - Report BOTH outcomes (lines containing "schematic" AND "rack").
 *   - Exit FAILURE if either renders zero bytes; SUCCESS only if both succeed.
 *
 * @see app/Console/Commands/PdfSmokeTestCommand.php
 */
class PdfSmokeTestRackTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Bind a stub PdfRenderService that writes the supplied bytes to the
     * requested $writeToPath. $byteBlobs is keyed by Blade view name:
     *   ['pdf.drawings.schematic' => '%PDF-1.4 schematic stub',
     *    'pdf.drawings.rack'      => '%PDF-1.4 rack stub']
     * Pass an empty string ('') for a view to simulate a zero-byte failure
     * for that render path.
     */
    private function bindPdfRendererStub(array $byteBlobs): void
    {
        $this->app->bind(PdfRenderService::class, function () use ($byteBlobs) {
            return new class($byteBlobs) extends PdfRenderService {
                public function __construct(private array $byteBlobs) {}

                public function fromBlade(string $view, array $data, ?string $writeToPath = null, array $options = []): string
                {
                    $blob = $this->byteBlobs[$view] ?? '%PDF-1.4 default stub';
                    if ($writeToPath !== null) {
                        $dir = dirname($writeToPath);
                        if (! is_dir($dir)) {
                            mkdir($dir, 0755, true);
                        }
                        file_put_contents($writeToPath, $blob);

                        return $writeToPath;
                    }

                    return $blob;
                }
            };
        });
    }

    // ── 3. Smoke renders rack in addition to schematic (happy path) ────────

    public function test_drawings_smoke_renders_rack_in_addition_to_schematic(): void
    {
        $this->bindPdfRendererStub([
            'pdf.drawings.schematic' => '%PDF-1.4 schematic stub bytes',
            'pdf.drawings.rack'      => '%PDF-1.4 rack stub bytes here',
        ]);

        $exit = Artisan::call('pdf:smoke-test', [
            '--drawings' => true,
            '--out'      => storage_path('app/test-pdf-smoke-drawing.pdf'),
        ]);

        $output = Artisan::output();

        $this->assertSame(0, $exit, 'Smoke test should exit 0 when both renders succeed: '.$output);
        $this->assertMatchesRegularExpression(
            '/schematic/i',
            $output,
            'Output must mention schematic outcome'
        );
        $this->assertMatchesRegularExpression(
            '/rack/i',
            $output,
            'Output must mention rack outcome'
        );

        // Cleanup
        @unlink(storage_path('app/test-pdf-smoke-drawing.pdf'));
        @unlink(storage_path('app/test-pdf-smoke-drawing-rack.pdf'));
    }

    // ── 4. Smoke fails (exit 1) when rack render returns zero bytes ────────

    public function test_drawings_smoke_fails_when_rack_render_returns_zero_bytes(): void
    {
        $this->bindPdfRendererStub([
            'pdf.drawings.schematic' => '%PDF-1.4 schematic ok',
            'pdf.drawings.rack'      => '',   // zero-byte → must fail
        ]);

        $exit = Artisan::call('pdf:smoke-test', [
            '--drawings' => true,
            '--out'      => storage_path('app/test-pdf-smoke-drawing.pdf'),
        ]);

        $output = Artisan::output();

        $this->assertSame(1, $exit, 'Smoke test must exit 1 when rack render is zero bytes: '.$output);
        $this->assertMatchesRegularExpression(
            '/rack/i',
            $output,
            'Output must mention the rack failure'
        );

        // Cleanup
        @unlink(storage_path('app/test-pdf-smoke-drawing.pdf'));
        @unlink(storage_path('app/test-pdf-smoke-drawing-rack.pdf'));
    }
}
