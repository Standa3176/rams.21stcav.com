<?php

namespace App\Services\Drawings;

use App\Models\Project;
use App\Models\ProjectDrawing;
use App\Services\DocumentArtifactStorage;
use App\Services\PdfRenderService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use setasign\Fpdi\Fpdi;

/**
 * Phase 20 Plan 01 — bound multi-page project PDF builder (DRAW-21).
 *
 * Concatenates a cover sheet (cover + drawing register table) plus every
 * non-superseded schematic + rack drawing into a single bound PDF. Per-drawing
 * render failures are isolated — a single drawing throwing does NOT abort the
 * whole bound PDF; instead the failed drawing is logged + skipped from the
 * concat, and its register row is prefixed with "[render failed] " so the
 * cover sheet visually surfaces it.
 *
 * Order is kind-grouped, NOT chronological:
 *   1. All schematics by created_at ASC
 *   2. All racks by created_at ASC
 *
 * (Floor plans excluded — Phase 19 deferred to v2.0.)
 *
 * Filename convention (per ARCHITECTURE.md §6.1 + CONTEXT.md decision):
 *   drawings/bound-{projectId}-v{version}-{ulid}.pdf
 *
 * Version numbering scans the on-disk drawings/ directory rather than a DB
 * column — keeps the migration footprint zero and is the source-of-truth
 * approach since bound PDFs are always regeneratable from canonical data.
 *
 * MOD-10 (regen-needed badge): callers can compare the file mtime against
 * `max(drawings.updated_at)` to surface a "regenerate recommended" badge in
 * the UI when the bound PDF has gone stale.
 *
 * @see BuildBoundPdfJob — async dispatch wrapper
 * @see BoundPdfReadyMail — completion notification
 * @see resources/views/pdf/drawings/bound-cover.blade.php — cover view
 */
class BoundPdfBuilderService
{
    public function __construct(
        private readonly DrawingExportRendererService $renderer,
        private readonly PdfRenderService $pdf,
        private readonly DocumentArtifactStorage $artifacts,
    ) {}

    /**
     * Build the bound PDF for $project. Returns metadata for the caller
     * (controller / job) to log + email.
     *
     * @return array{
     *     path: string,
     *     register: array<int, array<string, string>>,
     *     failed_drawings: array<int, array{drawing_id: int, error: string}>,
     *     generated_at: Carbon,
     *     version: int,
     * }
     */
    public function build(Project $project): array
    {
        $now = Carbon::now();

        // Kind-grouped order (schematics first, racks second) then chronological.
        // CASE ordering is portable across MySQL (production) and SQLite (tests);
        // MySQL's FIELD() is not available in SQLite. Bound parameters defended
        // against by hardcoding the kind constants — no user input flows in.
        $drawings = $project->drawings()
            ->whereNull('superseded_by_id')
            ->whereIn('kind', [ProjectDrawing::KIND_SCHEMATIC, ProjectDrawing::KIND_RACK])
            ->orderByRaw("CASE kind WHEN '".ProjectDrawing::KIND_SCHEMATIC."' THEN 1 WHEN '".ProjectDrawing::KIND_RACK."' THEN 2 ELSE 99 END")
            ->orderBy('created_at')
            ->get();

        // ── Per-drawing PDF render with failure isolation ────────────────────
        $perDrawingPdfs = [];   // [drawing_id => absolute path]
        $failedDrawings = [];   // [['drawing_id' => int, 'error' => string], ...]
        $register       = [];

        foreach ($drawings as $drawing) {
            $titlePrefix = '';
            try {
                if (! $drawing->isReady()) {
                    throw new \RuntimeException("drawing not ready (status={$drawing->status})");
                }
                $perDrawingPdfs[$drawing->id] = $this->renderer->renderPdf($drawing);
            } catch (\Throwable $e) {
                Log::warning('BoundPdfBuilderService: per-drawing render failed (skipping in concat, marking in register)', [
                    'project_id' => $project->id,
                    'drawing_id' => $drawing->id,
                    'kind'       => $drawing->kind,
                    'error'      => $e->getMessage(),
                ]);
                $failedDrawings[] = ['drawing_id' => $drawing->id, 'error' => $e->getMessage()];
                $titlePrefix = '[render failed] ';
            }

            $register[] = [
                'sheet_number' => $drawing->sheet_number ?? '—',
                'title'        => $titlePrefix
                    .$drawing->kindLabel()
                    .' — '
                    .($drawing->room?->name ?? $drawing->rack_label ?? 'Whole project'),
                'kind'         => $drawing->kind,
                'revision'     => $drawing->revisionLabel(),
                'status'       => $drawing->status,
                'date'         => optional($drawing->updated_at)->toDateString() ?? '—',
            ];
        }

        // ── Cover sheet → temp PDF ───────────────────────────────────────────
        $coverTmp = tempnam(sys_get_temp_dir(), 'cover-').'.pdf';
        $this->pdf->fromBlade(
            'pdf.drawings.bound-cover',
            [
                'project'         => $project,
                'register'        => $register,
                'failed_drawings' => $failedDrawings,
                'generated_at'    => $now,
            ],
            $coverTmp,
        );

        // ── Concat with FPDI: cover + each successful per-drawing PDF ────────
        $version  = $this->nextVersion((int) $project->id);
        $filename = sprintf(
            'bound-%d-v%d-%s.pdf',
            $project->id,
            $version,
            strtolower((string) Str::ulid()),
        );
        $outPath  = $this->artifacts->writePath(DocumentArtifactStorage::TYPE_DRAWING, $filename);

        $this->concat($outPath, array_merge([$coverTmp], array_values($perDrawingPdfs)));

        @unlink($coverTmp);

        Log::info('BoundPdfBuilderService: bound PDF written', [
            'project_id'          => $project->id,
            'version'             => $version,
            'register_count'      => count($register),
            'failed_count'        => count($failedDrawings),
            'pdf_pages_attempted' => count($perDrawingPdfs) + 1, // +1 for cover
            'path'                => $outPath,
        ]);

        return [
            'path'            => $outPath,
            'register'        => $register,
            'failed_drawings' => $failedDrawings,
            'generated_at'    => $now,
            'version'         => $version,
        ];
    }

    /**
     * Locate the latest bound PDF on disk for $projectId, or null if none.
     * Used by ProjectDrawingController::downloadBoundPdf to skip a regen when
     * a fresh bound PDF already exists.
     */
    public function latestBoundPdfPath(int $projectId): ?string
    {
        $glob = $this->boundGlob($projectId);
        if (empty($glob)) {
            return null;
        }
        // Pick the highest-version file (NOT highest mtime — handles clock skew).
        usort($glob, function (string $a, string $b): int {
            return $this->extractVersion($b) <=> $this->extractVersion($a);
        });

        return $glob[0];
    }

    // ════════════════════════════════════════════════════════════════════════
    // Internals
    // ════════════════════════════════════════════════════════════════════════

    /**
     * FPDI page-by-page concatenation. Importing keeps each source page's
     * orientation + size, so cover (A4 portrait) + landscape drawings render
     * correctly in a single PDF without forcing a uniform orientation.
     */
    private function concat(string $outPath, array $sourcePdfPaths): void
    {
        $pdf = new Fpdi();
        foreach ($sourcePdfPaths as $src) {
            if (! is_file($src)) {
                continue;
            }
            $pageCount = $pdf->setSourceFile($src);
            for ($p = 1; $p <= $pageCount; $p++) {
                $tplId  = $pdf->importPage($p);
                $size   = $pdf->getTemplateSize($tplId);
                $orient = ($size['width'] > $size['height']) ? 'L' : 'P';
                $pdf->AddPage($orient, [$size['width'], $size['height']]);
                $pdf->useTemplate($tplId);
            }
        }
        $pdf->Output($outPath, 'F');
    }

    /**
     * Scan disk for existing bound-{projectId}-v*-*.pdf and return next
     * version number. First time = 1.
     */
    private function nextVersion(int $projectId): int
    {
        $glob = $this->boundGlob($projectId);
        $maxV = 0;
        foreach ($glob as $f) {
            $maxV = max($maxV, $this->extractVersion($f));
        }

        return $maxV + 1;
    }

    /** Glob all bound PDFs on disk for $projectId. */
    private function boundGlob(int $projectId): array
    {
        // writePath('drawings', '') returns the absolute drawings/ dir path
        // (with trailing path separator after Laravel/Storage normalises it).
        $dir = $this->artifacts->writePath(DocumentArtifactStorage::TYPE_DRAWING, '');

        return glob(rtrim($dir, '/\\').'/bound-'.$projectId.'-v*-*.pdf') ?: [];
    }

    /** Extract integer version from a `bound-{id}-v{N}-{ulid}.pdf` filename. */
    private function extractVersion(string $absPath): int
    {
        if (preg_match('/-v(\d+)-/', basename($absPath), $m)) {
            return (int) $m[1];
        }

        return 0;
    }
}
