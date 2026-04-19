<?php

namespace App\Services;

use App\DTO\ProjectHealth;
use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\SiteSurvey;
use Carbon\Carbon;

/**
 * Derives a per-project health status (green/amber/red) from already-loaded
 * Eloquent relations. MUST NOT call $project->relation()->get() or issue any
 * additional DB queries — caller is responsible for eager-loading.
 *
 * Health priority: RED → AMBER → GREEN (first-match-wins).
 *
 * Phase 08, plan 08-01 (DASH-01c / DASH-01d / DASH-01e).
 *
 * @see \App\DTO\ProjectHealth       Returned value object.
 * @see \App\Http\Controllers\DashboardController  Primary caller.
 */
class ProjectHealthService
{
    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Assess the health of a project based on already-loaded relations.
     *
     * Expected eager-loaded relations: ramsDocuments, siteSurveys.
     */
    public function assess(Project $project): ProjectHealth
    {
        // Filter soft-deleted RAMS documents from the in-memory collection.
        // The ramsDocuments() relation can include deleted records when the
        // model uses SoftDeletes — we must not count them for health.
        $rams    = $project->ramsDocuments->filter(fn ($r) => $r->deleted_at === null);
        $surveys = $project->siteSurveys;

        $stageStart = $this->stageStartTimestamp($project);
        $overdue    = $stageStart !== null
            && Carbon::now()->diffInDays($stageStart, false) < -14;

        // ── RED: first-match-wins ─────────────────────────────────────────────

        if ($rams->contains('status', RamsDocument::STATUS_FAILED)) {
            return new ProjectHealth('red', 'RAMS document failed', $overdue);
        }

        if ($project->status === Project::STATUS_ENGINEERING
            && $rams->whereIn('status', $this->approvedOrBeyond())->isEmpty()) {
            return new ProjectHealth('red', 'No approved RAMS in engineering', $overdue);
        }

        if ($project->status === Project::STATUS_SURVEY_PENDING
            && $surveys->filter(fn (SiteSurvey $s) => $s->isSubmitted())->isEmpty()
            && $stageStart !== null
            && Carbon::now()->diffInDays($stageStart, false) < -14) {
            return new ProjectHealth('red', 'Survey overdue — no submission', true);
        }

        // ── AMBER ─────────────────────────────────────────────────────────────

        if ($stageStart !== null && Carbon::now()->diffInDays($stageStart, false) < -7) {
            return new ProjectHealth('amber', 'Stage duration > 7 days', $overdue);
        }

        if ($rams->contains('status', RamsDocument::STATUS_AWAITING_REVIEW)) {
            return new ProjectHealth('amber', 'RAMS awaiting review', $overdue);
        }

        if ($project->status === Project::STATUS_ENGINEERING
            && $rams->whereIn('status', [
                RamsDocument::STATUS_UPLOADED,
                RamsDocument::STATUS_AWAITING_REVIEW,
            ])->isNotEmpty()) {
            return new ProjectHealth('amber', 'RAMS blocked in pipeline', $overdue);
        }

        return new ProjectHealth('green', 'On track', $overdue);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Returns the Carbon datetime marking the start of the project's current
     * stage, or null if the status has no corresponding milestone column
     * (e.g. quote_imported, archived).
     */
    private function stageStartTimestamp(Project $project): ?Carbon
    {
        $column = match ($project->status) {
            Project::STATUS_SURVEY_PENDING  => 'survey_started_at',
            Project::STATUS_ENGINEERING     => 'engineering_started_at',
            Project::STATUS_INSTALLING      => 'installation_started_at',
            Project::STATUS_COMMISSIONING   => 'commissioning_started_at',
            Project::STATUS_HANDOVER        => 'handover_started_at',
            Project::STATUS_COMPLETED       => 'completed_at',
            default                         => null,
        };

        if ($column === null) {
            return null;
        }

        // Casts turn these into Carbon instances, but guard against unset values.
        $value = $project->{$column};

        return $value instanceof Carbon ? $value : null;
    }

    /** @return string[] Statuses considered "approved or beyond" for DASH-01d. */
    private function approvedOrBeyond(): array
    {
        return [
            RamsDocument::STATUS_APPROVED,
            RamsDocument::STATUS_APPROVED_FOR_GENERATION,
            RamsDocument::STATUS_GENERATING,
            RamsDocument::STATUS_COMPLETED,
        ];
    }
}
