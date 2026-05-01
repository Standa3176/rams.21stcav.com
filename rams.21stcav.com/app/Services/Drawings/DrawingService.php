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

        Log::info('DrawingService: drawing created', [
            'drawing_id' => $drawing->id,
            'project_id' => $project->id,
            'kind' => $kind,
            'room_id' => $roomId,
            'user_id' => $userId,
        ]);

        return $drawing;
    }

    /**
     * Dispatch the build job for the very first version (no archive-prior).
     * Use this immediately after createForProject() in the controller
     * create-flow.
     *
     * Phase 17 only handles kind=schematic — rack and floor_plan throw with
     * an explicit phase pointer so Plan 02/Phase 18/19 implementers see
     * exactly where to plug in.
     */
    public function generateInitial(ProjectDrawing $drawing, int $userId): ProjectDrawing
    {
        if ($drawing->kind !== ProjectDrawing::KIND_SCHEMATIC) {
            throw new \RuntimeException(
                "DrawingService::generateInitial: kind '{$drawing->kind}' lands in Phase 18/19"
            );
        }

        $drawing->update([
            'status' => ProjectDrawing::STATUS_GENERATING,
            'generated_by' => $userId,
        ]);

        BuildSchematicJob::dispatch($drawing->id);

        Log::info('DrawingService: generateInitial dispatched', [
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
}
