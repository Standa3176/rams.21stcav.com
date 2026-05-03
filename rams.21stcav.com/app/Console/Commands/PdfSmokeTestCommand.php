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
     * --drawings flag: render BOTH schematic + rack Blade views to PDF
     * (Phase 20 Plan 02 Task 1 — extends Phase 17 P03 schematic-only path).
     *
     * Strategy: schematic + rack each get their own out path. Both helpers
     * try a real ready drawing first; if none, fall back to in-memory fixture.
     * Final exit code is FAILURE if EITHER renders zero bytes — both paths
     * must be alive to ship.
     *
     * Asserts non-zero bytes only (MIN-09 anti-pattern: don't pin HTML internals).
     */
    private function renderDrawingSmoke(PdfRenderService $renderer): int
    {
        $schemOut = (string) ($this->option('out') ?: storage_path('app/pdf-smoke-drawing.pdf'));
        // Rack out is derived from the schematic out (sibling file with -rack suffix);
        // preserves --out semantics while giving the rack render its own destination.
        $rackOut = $this->deriveRackOutPath($schemOut);

        $schemOk = $this->renderSchematicSmoke($renderer, $schemOut);
        $rackOk = $this->renderRackSmoke($renderer, $rackOut);

        $this->info(sprintf(
            'Drawings smoke summary: schematic=%s rack=%s',
            $schemOk ? 'ok' : 'FAIL',
            $rackOk ? 'ok' : 'FAIL',
        ));

        return ($schemOk && $rackOk) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Derive a sibling out path for the rack render based on the schematic's
     * --out value. Replaces a `.pdf` suffix with `-rack.pdf`; falls back to
     * appending `-rack.pdf` if no suffix matches.
     */
    private function deriveRackOutPath(string $schemOut): string
    {
        if (str_ends_with($schemOut, '.pdf')) {
            return substr($schemOut, 0, -4).'-rack.pdf';
        }

        return $schemOut.'-rack.pdf';
    }

    /**
     * Render the schematic smoke fixture (real-or-placeholder) to $out.
     * Returns true on success (file > 0 bytes), false on failure.
     */
    private function renderSchematicSmoke(PdfRenderService $renderer, string $out): bool
    {
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
                    'Schematic smoke (real fixture): drawing #%d v%d → %s',
                    $real->id,
                    $real->version,
                    $generated,
                ));
            } else {
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
                $fixture->id = 0;
                $fixture->created_at = now();
                $fixture->updated_at = now();

                $renderer->fromBlade(
                    'pdf.drawings.schematic',
                    ['drawing' => $fixture],
                    $out,
                );
                $this->info('Schematic smoke (placeholder fixture): wrote '.$out);
            }
        } catch (\Throwable $e) {
            $this->error('Schematic smoke FAILED: '.$e->getMessage());

            return false;
        }

        $size = is_file($out) ? filesize($out) : 0;
        if ($size <= 0) {
            $this->error('Schematic smoke FAILED: zero-byte output at '.$out);

            return false;
        }

        $this->info(sprintf('Schematic smoke: OK (%d bytes at %s)', $size, $out));

        return true;
    }

    /**
     * Render the rack smoke fixture (real-or-placeholder) to $out. Mirrors
     * renderSchematicSmoke shape — Phase 20 P02 Task 1 added this so the
     * pipeline's rack arm gets the same liveness check as the schematic arm.
     */
    private function renderRackSmoke(PdfRenderService $renderer, string $out): bool
    {
        // Prefer a real ready rack if available (e2e path).
        $real = ProjectDrawing::query()
            ->where('kind', ProjectDrawing::KIND_RACK)
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
                    'Rack smoke (real fixture): drawing #%d v%d → %s',
                    $real->id,
                    $real->version,
                    $generated,
                ));
            } else {
                // Placeholder rack SVG — tall narrow rect with U-rail tick marks.
                // Just enough structure to exercise the rack Blade view + Browsershot
                // font fallback chain (CRIT-04). NOT a real rack drawing.
                $rackSvg = '<svg xmlns="http://www.w3.org/2000/svg" '
                    .'width="200" height="600" viewBox="0 0 200 600">'
                    .'<rect x="40" y="20" width="120" height="560" '
                    .'fill="none" stroke="#111" stroke-width="2"/>'
                    .'<line x1="40" y1="50"  x2="160" y2="50"  stroke="#888"/>'
                    .'<line x1="40" y1="90"  x2="160" y2="90"  stroke="#888"/>'
                    .'<line x1="40" y1="130" x2="160" y2="130" stroke="#888"/>'
                    .'<line x1="40" y1="170" x2="160" y2="170" stroke="#888"/>'
                    .'<text x="100" y="600" font-family="sans-serif" font-size="10" '
                    .'text-anchor="middle">Smoke test rack — '
                    .now()->toDateTimeString().'</text>'
                    .'</svg>';

                $fixture = new ProjectDrawing([
                    'kind' => ProjectDrawing::KIND_RACK,
                    'status' => ProjectDrawing::STATUS_READY,
                    'version' => 1,
                    'generated_svg' => $rackSvg,
                ]);
                $fixture->id = 0;
                $fixture->created_at = now();
                $fixture->updated_at = now();

                $renderer->fromBlade(
                    'pdf.drawings.rack',
                    ['drawing' => $fixture],
                    $out,
                );
                $this->info('Rack smoke (placeholder fixture): wrote '.$out);
            }
        } catch (\Throwable $e) {
            $this->error('Rack smoke FAILED: '.$e->getMessage());

            return false;
        }

        $size = is_file($out) ? filesize($out) : 0;
        if ($size <= 0) {
            $this->error('Rack smoke FAILED: zero-byte output at '.$out);

            return false;
        }

        $this->info(sprintf('Rack smoke: OK (%d bytes at %s)', $size, $out));

        return true;
    }
}
