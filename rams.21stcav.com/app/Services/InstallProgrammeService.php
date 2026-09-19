<?php

namespace App\Services;

use App\Models\InstallProgramme;
use App\Models\InstallRecord;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * InstallProgrammeService — high-level orchestration for install programme lifecycle.
 *
 * Generation flow:
 *   createForProject() → archiveExisting() → InstallProgramme::create() →
 *   InstallTaskGeneratorService::generate() → returns programme (status=draft)
 *
 * Activation flow:
 *   activate() → validates status=draft → sets status=active + activated_at
 *
 * Re-generation:
 *   createForProject() calls archiveExisting() first, so any draft/active programmes
 *   are archived before a new one is created.
 *
 * @see InstallTaskGeneratorService — populates tasks on generation
 * @see InstallProgrammeController  — HTTP layer calling these methods
 */
class InstallProgrammeService
{
    public function __construct(
        private readonly InstallTaskGeneratorService $generator,
    ) {}

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Create a new draft InstallProgramme for the project and generate its tasks.
     *
     * Archives any existing draft or active programmes before creating the new one,
     * ensuring only one programme generation is current at any time.
     *
     * @param  Project $project  The project to create a programme for
     * @param  User    $user     The authenticated user triggering generation
     * @return InstallProgramme  The newly created draft programme (tasks populated)
     */
    public function createForProject(Project $project, User $user): InstallProgramme
    {
        $this->archiveExisting($project);

        // Phase 45 / D-06 — resolve-or-create the project's ONE durable
        // install record. This row is deliberately NEVER archived:
        // archiveExisting() above operates on programmes only, so anything
        // filed against the record (a Visit) survives every regenerate.
        //
        // The try/catch is NOT defensive decoration. createForProject() is not
        // transaction-wrapped (there is no DB::transaction() anywhere in this
        // method), and archiveExisting() has ALREADY run by this point. On the
        // losing side of a concurrent double-generate, firstOrCreate's INSERT
        // hits the unique index on install_records.project_id and throws — and
        // an uncaught throw here would return to the caller with the previous
        // programme archived and no new one created, leaving the project with
        // no draft AND no active programme. The unique index guarantees no
        // DUPLICATE row; the catch guarantees no FAILED regenerate. They are
        // two different guarantees, and the index only provides the first.
        try {
            $record = InstallRecord::firstOrCreate(['project_id' => $project->id]);
        } catch (QueryException $e) {
            $record = InstallRecord::where('project_id', $project->id)->firstOrFail();
        }

        $programme = InstallProgramme::create([
            'project_id'        => $project->id,
            'install_record_id' => $record->id,
            'generated_by'      => $user->id,
            'status'            => InstallProgramme::STATUS_DRAFT,
            'generated_at'      => now(),
        ]);

        $programme->load('project');
        $this->generator->generate($programme);

        Log::info('InstallProgrammeService: programme created', [
            'programme_id'      => $programme->id,
            'install_record_id' => $record->id,
            'project_id'        => $project->id,
            'user_id'           => $user->id,
        ]);

        return $programme;
    }

    /**
     * Activate a draft programme, making it the live delivery programme.
     *
     * Sets status to active and records activated_at timestamp.
     *
     * @param  InstallProgramme $programme  Must be in draft status
     * @return void
     *
     * @throws \LogicException  If programme is not in draft status
     */
    public function activate(InstallProgramme $programme): void
    {
        if (! $programme->isDraft()) {
            throw new \LogicException(
                "InstallProgrammeService: cannot activate programme {$programme->id} — status is '{$programme->status}', expected 'draft'."
            );
        }

        $programme->status       = InstallProgramme::STATUS_ACTIVE;
        $programme->activated_at = now();
        $programme->save();

        Log::info('InstallProgrammeService: programme activated', [
            'programme_id' => $programme->id,
            'project_id'   => $programme->project_id,
            'activated_at' => $programme->activated_at->toISOString(),
        ]);
    }

    /**
     * Archive all draft and active programmes for the given project.
     *
     * Called automatically by createForProject() before creating a new generation.
     * Archived programmes are retained (soft-deletes not applied here — status change only).
     *
     * @param  Project $project  The project whose programmes should be archived
     * @return void
     */
    public function archiveExisting(Project $project): void
    {
        $programmes = InstallProgramme::where('project_id', $project->id)
            ->whereIn('status', [
                InstallProgramme::STATUS_DRAFT,
                InstallProgramme::STATUS_ACTIVE,
            ])
            ->get();

        foreach ($programmes as $programme) {
            $programme->status = InstallProgramme::STATUS_ARCHIVED;
            $programme->save();

            Log::info('InstallProgrammeService: programme archived', [
                'programme_id' => $programme->id,
                'project_id'   => $project->id,
            ]);
        }
    }
}
