<?php

namespace App\Console\Commands;

use App\Models\ProjectDrawing;
use App\Services\Drawings\DrawingExportRendererService;
use App\Services\PdfRenderService;
use Illuminate\Console\Command;

/**
 * Quickest possible "is the PDF pipeline alive?" check. Renders a tiny
 * Blade view to a PDF in storage/app/pdf-smoke.pdf and reports the file
 * size. Runs in CI and on production for post-deploy verification.
 *
 * Phase 17 Plan 03 added the --drawings flag (CONTEXT.md operational
 * precedent) — renders a fixture schematic via PdfRenderService against the
 * Phase 17 schematic Blade view. Sets the stage for Phase 20's CRIT-04
 * chrome-version-drift extension.
 *
 * Exit codes: 0 = OK, 1 = render failed or zero-byte output.
 */
class PdfSmokeTestCommand extends Command
{
    protected $signature = 'pdf:smoke-test
                            {--out= : Override output path (defaults to storage/app/pdf-smoke.pdf)}
                            {--drawings : Render a schematic fixture instead of the RAMS smoke baseline}';

    protected $description = 'Render a hello-world Blade view to PDF via Browsershot to verify the pipeline.';

    public function handle(PdfRenderService $renderer): int
    {
        if ($this->option('drawings')) {
            return $this->renderDrawingSmoke($renderer);
        }

        $out = (string) ($this->option('out') ?: storage_path('app/pdf-smoke.pdf'));

        try {
            $renderer->fromBlade('pdf._smoke', [], $out);
        } catch (\Throwable $e) {
            $this->error('PDF smoke test FAILED: '.$e->getMessage());

            return self::FAILURE;
        }

        $size = is_file($out) ? filesize($out) : 0;
        if ($size <= 0) {
            $this->error('PDF smoke test FAILED: zero-byte output at '.$out);

            return self::FAILURE;
        }

        $this->info(sprintf('PDF smoke test OK — wrote %d bytes to %s', $size, $out));

        return self::SUCCESS;
    }

    /**
     * --drawings flag: render a schematic Blade view to PDF.
     *
     * Strategy: prefer a real ready schematic if one exists (truer e2e check);
     * otherwise fall back to an in-memory ProjectDrawing fixture with a hard-coded
     * placeholder generated_svg so the smoke test can run on a fresh dev machine
     * without seed data.
     *
     * Asserts non-zero bytes only (MIN-09 anti-pattern: don't pin HTML internals).
     */
    private function renderDrawingSmoke(PdfRenderService $renderer): int
    {
        $out = (string) ($this->option('out') ?: storage_path('app/pdf-smoke-drawing.pdf'));

        // Prefer a real ready schematic if available (e2e path).
        $real = ProjectDrawing::query()
            ->where('kind', ProjectDrawing::KIND_SCHEMATIC)
            ->where('status', ProjectDrawing::STATUS_READY)
            ->whereNull('superseded_by_id')
            ->latest('id')
            ->first();

        try {
            if ($real !== null) {
                $exporter = app(DrawingExportRendererService::class);
                $generated = $exporter->renderPdf($real);
                if ($generated !== $out && is_file($generated)) {
                    @copy($generated, $out);
                }
                $this->info(sprintf(
                    'Drawings smoke (real fixture): drawing #%d v%d → %s',
                    $real->id,
                    $real->version,
                    $generated,
                ));
            } else {
                // Fallback: in-memory fixture so the smoke test can run on a
                // fresh dev machine. ProjectDrawing is unsaved — Blade just
                // reads attributes; no DB write happens.
                $fixture = new ProjectDrawing([
                    'kind' => ProjectDrawing::KIND_SCHEMATIC,
                    'status' => ProjectDrawing::STATUS_READY,
                    'version' => 1,
                    'generated_svg' => '<svg xmlns="http://www.w3.org/2000/svg" '
                        .'width="400" height="200" viewBox="0 0 400 200">'
                        .'<text x="20" y="100" font-family="sans-serif" font-size="14">'
                        .'Smoke test schematic — '.now()->toDateTimeString()
                        .'</text></svg>',
                ]);
                // Provide id/created_at so Blade helpers don't blow up.
                $fixture->id = 0;
                $fixture->created_at = now();
                $fixture->updated_at = now();

                $renderer->fromBlade(
                    'pdf.drawings.schematic',
                    ['drawing' => $fixture],
                    $out,
                );
                $this->info('Drawings smoke (placeholder fixture): wrote '.$out);
            }
        } catch (\Throwable $e) {
            $this->error('Drawings PDF smoke test FAILED: '.$e->getMessage());

            return self::FAILURE;
        }

        $size = is_file($out) ? filesize($out) : 0;
        if ($size <= 0) {
            $this->error('Drawings PDF smoke test FAILED: zero-byte output at '.$out);

            return self::FAILURE;
        }

        $this->info(sprintf('Drawing PDF rendered: %s (%d bytes)', $out, $size));

        return self::SUCCESS;
    }
}
