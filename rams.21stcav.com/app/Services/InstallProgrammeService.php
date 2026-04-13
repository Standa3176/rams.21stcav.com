<?php

namespace App\Services;

use App\Models\InstallProgramme;
use App\Models\Project;
use App\Models\User;
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

        $programme = InstallProgramme::create([
            'project_id'   => $project->id,
            'generated_by' => $user->id,
            'status'       => InstallProgramme::STATUS_DRAFT,
            'generated_at' => now(),
        ]);

        $programme->load('project');
        $this->generator->generate($programme);

        Log::info('InstallProgrammeService: programme created', [
            'programme_id' => $programme->id,
            'project_id'   => $project->id,
            'user_id'      => $user->id,
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
