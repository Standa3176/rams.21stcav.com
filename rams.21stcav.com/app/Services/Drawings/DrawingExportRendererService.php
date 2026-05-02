<?php

namespace App\Services\Drawings;

use App\Models\ProjectDrawing;
use App\Services\DocumentArtifactStorage;
use App\Services\PdfRenderService;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Phase 17 Plan 03 — single entrypoint for drawing → PDF / SVG / PNG export.
 *
 * Wraps {@see PdfRenderService} for the rendered formats (PDF + PNG) and writes
 * SVG output directly from {@see ProjectDrawing::$generated_svg}. **Warning 8:
 * NEVER instantiates Spatie\Browsershot\Browsershot directly** — the PNG path
 * delegates to PdfRenderService::fromBladeAsPng() so the centralised Browsershot
 * construction (chrome path / no-sandbox / chromium-args) lands in one place.
 * Phase 20's CRIT-03 hardening (dedicated queue, memory probe) flows into
 * PdfRenderService and this service picks it up automatically.
 *
 * Filename convention (per ARCHITECTURE.md §6.1):
 *   drawings/{kind}-{drawingId}-v{version}-{ulid}.{format}
 *
 * Handover PNG cache (DRAW-26):
 *   drawings/handover-png/drawing-{id}-v{version}.png
 *
 * @see app/Services/PdfRenderService.php — central Browsershot wrapper.
 * @see app/Services/DocumentArtifactStorage.php — TYPE_DRAWING storage type.
 * @see resources/views/pdf/drawings/schematic.blade.php — Plan 02 Blade view.
 */
class DrawingExportRendererService
{
    public function __construct(
        private readonly PdfRenderService $pdfRenderService,
        private readonly DocumentArtifactStorage $artifacts,
    ) {}

    /**
     * Render the drawing to PDF using the kind-appropriate Blade view.
     * Returns absolute path to the written PDF.
     *
     * @throws RuntimeException When the drawing is not ready (status != ready).
     */
    public function renderPdf(ProjectDrawing $drawing): string
    {
        $this->assertReady($drawing);

        $bladeView = $this->bladeViewFor($drawing);
        $filename = $this->filenameFor($drawing, 'pdf');
        $path = $this->artifacts->writePath(DocumentArtifactStorage::TYPE_DRAWING, $filename);

        $this->pdfRenderService->fromBlade(
            $bladeView,
            ['drawing' => $drawing],
            $path,
        );

        return $path;
    }

    /**
     * Write the drawing's SVG (from generated_svg) to disk and return path.
     * For Phase 17 schematics: dumps generated_svg directly into a TYPE_DRAWING
     * file. Phase 19 (floor plans / Konva) may layer canvas_state→SVG conversion
     * on top by overriding this method's body.
     *
     * @throws RuntimeException When the drawing is not ready, or generated_svg is empty.
     */
    public function renderSvg(ProjectDrawing $drawing): string
    {
        $this->assertReady($drawing);

        $svg = (string) ($drawing->generated_svg ?? '');
        if ($svg === '') {
            throw new RuntimeException(
                "DrawingExportRendererService::renderSvg: drawing #{$drawing->id} has empty generated_svg"
            );
        }

        $filename = $this->filenameFor($drawing, 'svg');
        $path = $this->artifacts->writePath(DocumentArtifactStorage::TYPE_DRAWING, $filename);

        if (file_put_contents($path, $svg) === false) {
            throw new RuntimeException(
                "DrawingExportRendererService::renderSvg: failed to write SVG to {$path}"
            );
        }

        return $path;
    }

    /**
     * Capture a PNG of the drawing via PdfRenderService::fromBladeAsPng (uses
     * the same Blade view as renderPdf). Returns absolute path.
     *
     * Warning 8: NEVER instantiates Browsershot directly — Phase 20's CRIT-03
     * hardening lives in PdfRenderService and this method picks it up
     * automatically.
     *
     * @throws RuntimeException When the drawing is not ready.
     */
    public function renderPng(ProjectDrawing $drawing, int $widthPx = 1920): string
    {
        $this->assertReady($drawing);

        $bladeView = $this->bladeViewFor($drawing);
        $filename = $this->filenameFor($drawing, 'png');
        $path = $this->artifacts->writePath(DocumentArtifactStorage::TYPE_DRAWING, $filename);

        $this->pdfRenderService->fromBladeAsPng(
            $bladeView,
            ['drawing' => $drawing],
            $path,
            [
                'widthPx' => $widthPx,
                'heightPx' => intval($widthPx * 0.707),
            ],
        );

        return $path;
    }

    /**
     * Idempotent PNG render for O&M Manual handover (DRAW-26). Cached per-
     * drawing-version under drawings/handover-png/drawing-{id}-v{version}.png.
     * Returns the cached path when present; otherwise generates a new one at a
     * smaller width (1280px) to keep DOCX size manageable.
     *
     * Returns null when the drawing is not ready (handover Word doc gracefully
     * skips it — Plan 03 Task 3 honours this).
     */
    public function ensurePngForHandover(ProjectDrawing $drawing): ?string
    {
        if (! $drawing->isReady()) {
            return null;
        }

        $bladeView = $this->bladeViewFor($drawing);
        $filename = sprintf(
            'handover-png/drawing-%d-v%d.png',
            $drawing->id,
            $drawing->version,
        );

        $existing = $this->artifacts->readPath(DocumentArtifactStorage::TYPE_DRAWING, $filename);
        if ($existing !== null) {
            return $existing;
        }

        $path = $this->artifacts->writePath(DocumentArtifactStorage::TYPE_DRAWING, $filename);

        $this->pdfRenderService->fromBladeAsPng(
            $bladeView,
            ['drawing' => $drawing],
            $path,
            [
                'widthPx' => 1280,
                'heightPx' => intval(1280 * 0.707),
            ],
        );

        return $path;
    }

    /**
     * Resolve the Blade view for a given drawing kind. Phase 18 Plan 03 lit
     * up the rack arm. Floor plans deferred to v2.0 (CONTEXT.md 2026-05-02
     * scope reduction) — the throw stays as a clear pointer for the future
     * implementer.
     */
    private function bladeViewFor(ProjectDrawing $drawing): string
    {
        return match ($drawing->kind) {
            ProjectDrawing::KIND_SCHEMATIC => 'pdf.drawings.schematic',
            ProjectDrawing::KIND_RACK => 'pdf.drawings.rack',
            ProjectDrawing::KIND_FLOOR_PLAN => throw new RuntimeException(
                'DrawingExportRendererService: floor plans land in v2.0 (pdf.drawings.floor-plan)'
            ),
            default => throw new RuntimeException(
                "DrawingExportRendererService: unknown drawing kind '{$drawing->kind}'"
            ),
        };
    }

    /**
     * Build a deterministic-yet-unique filename per
     * ARCHITECTURE.md §6.1: drawings/{kind}-{drawingId}-v{version}-{ulid}.{format}.
     */
    private function filenameFor(ProjectDrawing $drawing, string $format): string
    {
        return sprintf(
            '%s-%d-v%d-%s.%s',
            $drawing->kind,
            $drawing->id,
            (int) $drawing->version,
            strtolower((string) Str::ulid()),
            $format,
        );
    }

    /**
     * Defensive guard: PDF/SVG/PNG renders require the drawing to be in
     * STATUS_READY. Generation in progress / failed / draft drawings cannot
     * be rendered (their generated_svg is either empty or stale).
     *
     * @throws RuntimeException When status is not ready.
     */
    private function assertReady(ProjectDrawing $drawing): void
    {
        if ($drawing->status !== ProjectDrawing::STATUS_READY) {
            throw new RuntimeException(
                "DrawingExportRendererService: drawing #{$drawing->id} is not ready (status={$drawing->status})"
            );
        }
    }
}
