<?php

namespace App\Services\Drawings;

use App\Core\Modules\Projects\ProjectDataService;
use App\Jobs\BuildSchematicJob;
use App\Models\Project;
use App\Models\ProjectDrawing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DrawingService — orchestration entry point for the v1.3 drawings module.
 *
 * Mirrors the InstallProgrammeService precedent (Phase 12) for "regenerate
 * archives prior". Layered with the project_drawings.superseded_by_id self-FK
 * so Plan 03's index page can `whereNull('superseded_by_id')` to show only
 * the current revision per kind+room.
 *
 * Method matrix:
 *   createForProject()  — create row #1 (status=draft). Does NOT dispatch the job.
 *   generateInitial()   — flip status=generating + dispatch job (no archive-prior;
 *                         used for the very first version after createForProject).
 *   regenerate()        — replicate + bump version + archive prior + dispatch job
 *                         (used for every revision AFTER the first).
 *   archivePrior()      — internal helper; transactional supersede flip.
 *
 * CRIT-02 (lock-on-edit + archive-prior) + DRAW-24 (revision tracking) +
 * DRAW-25 (status state machine).
 *
 * @see app/Services/InstallProgrammeService.php — regenerate-archives-prior precedent.
 * @see app/Jobs/BuildSchematicJob.php           — dispatched async work.
 */
class DrawingService
{
    public function __construct(
        private readonly ProjectDataService $projectDataService,
        private readonly DrawingDataResolverService $resolver,
        private readonly SheetNumberAllocator $sheetAllocator,
    ) {}

    /**
     * Create a new draft row for a project. Does NOT dispatch the build job —
     * call generateInitial() next from the controller create-flow.
     */
    public function createForProject(
        Project $project,
        string $kind,
        ?int $roomId,
        int $userId,
    ): ProjectDrawing {
        $drawing = ProjectDrawing::create([
            'project_id' => $project->id,
            'site_survey_room_id' => $roomId,
            'kind' => $kind,
            'version' => 1,
            'status' => ProjectDrawing::STATUS_DRAFT,
            'generated_by' => $userId,
            'source_data' => $this->projectDataService->resolve($project),
        ]);

        // Phase 20 Plan 01 (DRAW-23) — set-once auto-derived AVIXA sheet number.
        // Schematics get AV-201..299; racks get AV-301..399; floor plans skipped
        // (allocator throws on KIND_FLOOR_PLAN — guard here so v2.0 floor plans
        // can land without changing the controller path). The set-once guard
        // (`sheet_number === null`) is defensive — re-running createForProject
        // shouldn't ever happen on the same row, but if it did the allocator
        // would over-count itself.
        if (in_array($kind, [ProjectDrawing::KIND_SCHEMATIC, ProjectDrawing::KIND_RACK], true)
            && $drawing->sheet_number === null) {
            $drawing->update([
                'sheet_number' => $this->sheetAllocator->allocate($project->id, $kind),
            ]);
        }

        Log::info('DrawingService: drawing created', [
            'drawing_id' => $drawing->id,
            'project_id' => $project->id,
            'kind' => $kind,
            'room_id' => $roomId,
            'user_id' => $userId,
            'sheet_number' => $drawing->fresh()->sheet_number, // Phase 20 (DRAW-23)
        ]);

        return $drawing;
    }

    /**
     * Dispatch (or seed) the build for the very first version of a drawing —
     * no archive-prior semantics. Called immediately after createForProject()
     * in the controller create-flow.
     *
     * Per-kind dispatch:
     *   - kind=schematic — flips status=generating + dispatches BuildSchematicJob
     *     (Phase 17 async pipeline).
     *   - kind=rack      — Phase 18 SYNCHRONOUS flow: NO job dispatched. Status
     *     stays DRAFT. Seeds source_data.rack_meta (42U baseline + 230V) and
     *     an empty rack_items array so Plan 18-03's editor can render an
     *     empty rack and let the engineer drag equipment in. CONTEXT.md D-13
     *     locks "NO BuildRackElevationJob — there's no AI/D2/heavy work to
     *     defer."
     *   - kind=floor_plan — DEFERRED to v2.0 (Phase 19 dropped from v1.3 scope
     *     2026-05-02). Throws with an explicit pointer.
     */
    public function generateInitial(ProjectDrawing $drawing, int $userId): ProjectDrawing
    {
        return match ($drawing->kind) {
            ProjectDrawing::KIND_SCHEMATIC => $this->generateInitialSchematic($drawing, $userId),
            ProjectDrawing::KIND_RACK => $this->generateInitialRack($drawing, $userId),
            default => throw new \RuntimeException(
                "DrawingService::generateInitial: kind '{$drawing->kind}' lands in v2.0 (Phase 19 floor plans deferred)"
            ),
        };
    }

    /**
     * Phase 17 schematic flow — flip status to GENERATING and dispatch the
     * async build job. Logic preserved verbatim from the pre-Phase-18 shape.
     */
    private function generateInitialSchematic(ProjectDrawing $drawing, int $userId): ProjectDrawing
    {
        $drawing->update([
            'status' => ProjectDrawing::STATUS_GENERATING,
            'generated_by' => $userId,
        ]);

        BuildSchematicJob::dispatch($drawing->id);

        Log::info('DrawingService: generateInitial dispatched (schematic)', [
            'drawing_id' => $drawing->id,
            'kind' => $drawing->kind,
        ]);

        return $drawing;
    }

    /**
     * Phase 18 rack flow — synchronous (no job dispatched). Status stays
     * DRAFT until the engineer saves rack canvas state in Plan 18-03's
     * editor (which then renders synchronously to STATUS_READY).
     *
     * Seeds an empty rack scaffold under source_data.rack_meta + rack_items
     * so Plan 18-03 can read the shape without conditional logic. 42U + 230V
     * defaults per CONTEXT.md "Claude's Discretion" (UK mains, engineer
     * overrides per rack on edit).
     */
    private function generateInitialRack(ProjectDrawing $drawing, int $userId): ProjectDrawing
    {
        $existing = (array) ($drawing->source_data ?? []);

        $drawing->update([
            'status' => ProjectDrawing::STATUS_DRAFT,
            'generated_by' => $userId,
            'source_data' => array_merge($existing, [
                'rack_meta' => [
                    'rack_label' => $drawing->rack_label ?? 'Rack 1',
                    'rack_height_u' => 42,
                    'nominal_voltage_v' => 230,
                    'floor' => null,
                ],
                'rack_items' => [],
            ]),
        ]);

        Log::info('DrawingService: generateInitial empty rack created (sync flow)', [
            'drawing_id' => $drawing->id,
            'kind' => $drawing->kind,
        ]);

        return $drawing;
    }

    /**
     * Regenerate an existing drawing — replicate row, bump version, archive
     * prior, dispatch BuildSchematicJob.
     *
     * Wrapped in DB::transaction so a failure rolls back BOTH the new row
     * AND the supersede flip. Job dispatch happens AFTER commit so a queue
     * worker never sees a phantom row mid-transaction.
     *
     * @see archivePrior() — supersede flip helper called inside the txn.
     */
    public function regenerate(ProjectDrawing $existing, int $userId): ProjectDrawing
    {
        if ($existing->kind !== ProjectDrawing::KIND_SCHEMATIC) {
            throw new \RuntimeException(
                "DrawingService::regenerate: kind '{$existing->kind}' lands in Phase 18/19"
            );
        }

        $newRow = DB::transaction(function () use ($existing, $userId): ProjectDrawing {
            // Replicate but DROP per-version artifacts so the new row starts clean.
            $newRow = $existing->replicate([
                'canvas_state',
                'generated_svg',
                'thumbnail_png_path',
                'filename',
                'completion_email_sent_at',
                'failed_email_sent_at',
                'superseded_by_id',
                'access_token',
            ]);

            $newRow->version = ((int) $existing->version) + 1;
            $newRow->status = ProjectDrawing::STATUS_GENERATING;
            $newRow->generated_by = $userId;
            $newRow->source_data = $this->projectDataService->resolve($existing->project);
            $newRow->error_message = null;
            $newRow->save();

            $this->archivePrior($existing, $newRow);

            return $newRow;
        });

        BuildSchematicJob::dispatch($newRow->id);

        Log::info('DrawingService: regenerate dispatched', [
            'old_drawing_id' => $existing->id,
            'new_drawing_id' => $newRow->id,
            'kind' => $newRow->kind,
            'version' => $newRow->version,
        ]);

        return $newRow;
    }

    /**
     * Internal: flip the prior row to STATUS_SUPERSEDED and link it to the
     * new row via superseded_by_id. Called only from inside regenerate()'s
     * DB::transaction block.
     */
    public function archivePrior(ProjectDrawing $existing, ProjectDrawing $newRow): void
    {
        $existing->status = ProjectDrawing::STATUS_SUPERSEDED;
        $existing->superseded_by_id = $newRow->id;
        $existing->save();

        Log::info('DrawingService: prior drawing superseded', [
            'archived_drawing_id' => $existing->id,
            'superseded_by_id' => $newRow->id,
        ]);
    }

    /**
     * Quick task 260509-ibx — persist a draw.io spike's mxGraph XML edit.
     *
     * Lock-on-edit policy (D-LOCK-2, mirrors v1.3 Phase 18 P03 archive-prior):
     *   - First save (canvas_state empty): write XML directly to the row's
     *     canvas_state column. The act of writing flips the lock —
     *     ProjectDrawing::hasUserEdits() now returns true.
     *   - Subsequent save (canvas_state populated): replicate row, bump version,
     *     write the new XML to the new row, archive the prior row via
     *     archivePrior() (sets STATUS_SUPERSEDED + superseded_by_id link).
     *
     * Wrapped in DB::transaction so a failure rolls back BOTH the new row and
     * the supersede flip. Uses canvas_state (mediumText, 16 MB) per D-LOCK-8 —
     * existing column added in Phase 17 P01 with PITFALLS.md MOD-05 explicitly
     * earmarking it for "Konva scene graph for user edits", a near-identical
     * shape to mxGraph XML. NO migration.
     *
     * Returns the row that now holds the saved XML — same row on first save,
     * a new versioned row on subsequent saves.
     *
     * @see DrawingService::archivePrior() — supersede helper called inside the txn.
     * @see ProjectDrawing::hasUserEdits()  — lock-state predicate.
     */
    public function saveSpikeXml(ProjectDrawing $drawing, string $xml, int $userId): ProjectDrawing
    {
        if (! $drawing->hasUserEdits()) {
            // ── First save — direct write, no archive-prior. ────────────────
            $drawing->update([
                'canvas_state' => $xml,
                'generated_by' => $userId,
            ]);

            Log::info('DrawingService: spike XML first-save (lock flip)', [
                'drawing_id' => $drawing->id,
                'project_id' => $drawing->project_id,
                'xml_bytes' => strlen($xml),
            ]);

            return $drawing;
        }

        // ── Subsequent save — replicate + bump + archive prior. ────────────
        $newRow = DB::transaction(function () use ($drawing, $xml, $userId): ProjectDrawing {
            $newRow = $drawing->replicate([
                'canvas_state',
                'generated_svg',
                'thumbnail_png_path',
                'filename',
                'completion_email_sent_at',
                'failed_email_sent_at',
                'superseded_by_id',
                'access_token',
            ]);

            $newRow->version = ((int) $drawing->version) + 1;
            $newRow->status = ProjectDrawing::STATUS_DRAFT;
            $newRow->generated_by = $userId;
            $newRow->canvas_state = $xml;
            $newRow->error_message = null;
            $newRow->save();

            $this->archivePrior($drawing, $newRow);

            return $newRow;
        });

        Log::info('DrawingService: spike XML versioned save (archive-prior)', [
            'old_drawing_id' => $drawing->id,
            'new_drawing_id' => $newRow->id,
            'version' => $newRow->version,
            'xml_bytes' => strlen($xml),
        ]);

        return $newRow;
    }

    /**
     * Quick task 260509-ibx — write the SVG export from the embed to disk
     * via DocumentArtifactStorage::TYPE_DRAWING.
     *
     * Preview-only — D-LOCK-8 makes mxGraph XML the source of truth, SVG
     * is for thumbnail/embed-in-PDF use only. Writes to:
     *   storage/app/documents/drawings/spike-{drawing_id}.svg
     *
     * Returns the absolute path written (caller may basename() for client
     * display).
     */
    public function saveSpikeSvg(ProjectDrawing $drawing, string $svg): string
    {
        $artifacts = app(\App\Services\DocumentArtifactStorage::class);
        $filename = sprintf('spike-%d.svg', $drawing->id);
        $path = $artifacts->writePath(\App\Services\DocumentArtifactStorage::TYPE_DRAWING, $filename);

        if (file_put_contents($path, $svg) === false) {
            throw new \RuntimeException("DrawingService::saveSpikeSvg: failed to write {$path}");
        }

        Log::info('DrawingService: spike SVG written', [
            'drawing_id' => $drawing->id,
            'path' => $path,
            'svg_bytes' => strlen($svg),
        ]);

        return $path;
    }
}
