<?php

namespace App\Services\Drawings;

use App\Models\ProjectDrawing;
use App\Services\DocumentArtifactStorage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Phase 17 — End-to-end orchestrator for schematic generation.
 *
 * Pipeline:
 *   adjacency → D2 source (text) → D2 CLI binary → SVG (string) → ProjectDrawing.generated_svg
 *
 * Zero AI calls (DRAW-30 invention guard / MIN-08). Pure data → text → SVG
 * via a deterministic, server-side D2 binary. Reruns are idempotent: the
 * same project state always produces the same source, and the D2 layout
 * engine seeds itself from the source content.
 *
 * @see app/Services/Drawings/DrawingDataResolverService.php — adjacency input.
 * @see app/Services/Drawings/SchematicD2SourceBuilder.php   — D2 source emission.
 * @see app/Jobs/BuildSchematicJob.php                       — async caller.
 */
class SchematicGeneratorService
{
    public function __construct(
        private readonly DrawingDataResolverService $resolver,
        private readonly SchematicD2SourceBuilder $builder,
        private readonly DocumentArtifactStorage $artifacts,
    ) {}

    /**
     * Generate the SVG for the given drawing and persist it onto the row.
     *
     * Side effects:
     *   - $drawing->generated_svg, filename, status set to STATUS_READY on success.
     *   - Log::warning on ambiguous cables (CRIT-05 surfaced).
     *
     * @throws RuntimeException when D2 CLI invocation fails or the drawing kind/status guard trips.
     */
    public function generate(ProjectDrawing $drawing): void
    {
        // ── Pre-flight guards ────────────────────────────────────────────
        if ($drawing->kind !== ProjectDrawing::KIND_SCHEMATIC) {
            throw new RuntimeException(
                "SchematicGeneratorService::generate: kind '{$drawing->kind}' is not 'schematic'"
            );
        }

        if ($drawing->status !== ProjectDrawing::STATUS_GENERATING) {
            throw new RuntimeException(
                "SchematicGeneratorService::generate: drawing must be in 'generating' state, got '{$drawing->status}'"
            );
        }

        // ── Resolve adjacency from canonical project data ────────────────
        $adjacency = $this->resolver->adjacencyForProject($drawing->project);

        // Pick the room: by FK, or first available, or empty room when nothing matches.
        $room = $this->pickRoomForDrawing($drawing, $adjacency);

        // ── Emit D2 source ───────────────────────────────────────────────
        $result = $this->builder->build($room);
        $d2Source = $result['source'];
        $warnings = $result['warnings'] ?? [];
        $ambiguousCount = $result['ambiguous_cables'] ?? 0;

        if (! empty($warnings)) {
            Log::warning('SchematicGeneratorService: ambiguous cables', [
                'drawing_id' => $drawing->id,
                'count' => $ambiguousCount,
                'first_warning' => $warnings[0] ?? null,
            ]);
        }

        // ── Write D2 source to a temp file ───────────────────────────────
        $tmpDir = storage_path('app/tmp/d2');
        if (! is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }
        $tmpD2Path = $tmpDir.DIRECTORY_SEPARATOR.sprintf('schematic-%d-v%d.d2', $drawing->id, $drawing->version);

        if (file_put_contents($tmpD2Path, $d2Source) === false) {
            throw new RuntimeException("SchematicGeneratorService: failed to write temp D2 source to {$tmpD2Path}");
        }

        // ── Determine final SVG output path via DocumentArtifactStorage ──
        $filename = sprintf(
            'schematic-%d-v%d-%s.svg',
            $drawing->id,
            $drawing->version,
            strtolower((string) Str::ulid()),
        );
        $outputSvgPath = $this->artifacts->writePath(DocumentArtifactStorage::TYPE_DRAWING, $filename);

        try {
            // ── Invoke D2 CLI ────────────────────────────────────────────
            $binary = (string) config('drawings.d2_binary_path', '/usr/local/bin/d2');
            $layout = (string) config('drawings.d2_layout', 'elk');
            $timeout = (int) config('drawings.d2_timeout', 60);

            $process = new Process([
                $binary,
                '--layout='.$layout,
                $tmpD2Path,
                $outputSvgPath,
            ]);
            $process->setTimeout($timeout);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException(
                    'D2 CLI failed: '.substr((string) $process->getErrorOutput(), 0, 400)
                );
            }

            // ── Read SVG bytes and persist to the drawing row ────────────
            if (! is_file($outputSvgPath)) {
                throw new RuntimeException("D2 CLI succeeded but output file missing: {$outputSvgPath}");
            }
            $svgBytes = file_get_contents($outputSvgPath);
            if ($svgBytes === false || $svgBytes === '') {
                throw new RuntimeException("D2 CLI produced an empty SVG at {$outputSvgPath}");
            }

            $drawing->update([
                'generated_svg' => $svgBytes,
                'filename' => basename($outputSvgPath),
                'status' => ProjectDrawing::STATUS_READY,
                'error_message' => null,
            ]);

            Log::info('SchematicGeneratorService: generated', [
                'drawing_id' => $drawing->id,
                'filename' => basename($outputSvgPath),
                'svg_bytes' => strlen($svgBytes),
                'ambiguous_cables' => $ambiguousCount,
            ]);
        } finally {
            // Clean up tmp source — leave the SVG output in place.
            if (is_file($tmpD2Path)) {
                @unlink($tmpD2Path);
            }
        }
    }

    /**
     * Pick the room from adjacency that matches the drawing's
     * site_survey_room_id. Falls back to the first room when the FK is
     * null (whole-project schematic deferred per CONTEXT.md). Returns an
     * empty-shaped room when adjacency is empty so the builder still
     * emits a valid (empty) D2 source.
     *
     * @param  array<int, array<string, mixed>>  $adjacency
     * @return array{room_id:int|null,room_name:string,devices:array,cables:array}
     */
    private function pickRoomForDrawing(ProjectDrawing $drawing, array $adjacency): array
    {
        $emptyRoom = [
            'room_id' => $drawing->site_survey_room_id,
            'room_name' => $drawing->room?->room_name ?? 'No room data',
            'devices' => [],
            'cables' => [],
        ];

        if (empty($adjacency)) {
            return $emptyRoom;
        }

        if ($drawing->site_survey_room_id !== null) {
            foreach ($adjacency as $room) {
                if (($room['room_id'] ?? null) === $drawing->site_survey_room_id) {
                    return $room;
                }
            }
        }

        // Fallback: first room (the synthetic "Project schematic" path
        // when no rooms exist on the project, or first-of-many otherwise).
        return $adjacency[0];
    }
}
