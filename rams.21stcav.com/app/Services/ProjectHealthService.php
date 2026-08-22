<?php

namespace App\Services;

use App\DTO\ProjectHealth;
use App\Models\Project;
use App\Models\ProjectDeliverable;
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
 * Phase 260822-esf, plan 03 (D-12 / D-13) — see below.
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
     * Expected eager-loaded relations: ramsDocuments, siteSurveys, deliverables.
     * `deliverables` is optional — if the caller has not eager-loaded it, the
     * D-12 not-required guards and the D-13 amber rule below both degrade to
     * a no-op rather than issuing a query (see isExplicitlyNotRequired() and
     * the D-13 loop, both gated on Project::deliverableState()/relationLoaded()).
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
            && $rams->whereIn('status', $this->approvedOrBeyond())->isEmpty()
            && ! $this->isExplicitlyNotRequired($project, ProjectDeliverable::KEY_RAMS)) {
            return new ProjectHealth('red', 'No approved RAMS in engineering', $overdue);
        }

        if ($project->status === Project::STATUS_SURVEY_PENDING
            && $surveys->filter(fn (SiteSurvey $s) => $s->isSubmitted())->isEmpty()
            && $stageStart !== null
            && Carbon::now()->diffInDays($stageStart, false) < -14
            && ! $this->isExplicitlyNotRequired($project, ProjectDeliverable::KEY_SITE_SURVEY)) {
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

        // D-13: a deliverable stuck on "Not yet decided" for longer than the
        // grace period becomes amber. Deliberately last in priority order —
        // it must never mask a higher-priority red/amber rule above.
        // Gated on relationLoaded('deliverables'): if the caller hasn't
        // eager-loaded it, this rule is skipped entirely rather than issuing
        // a query (same "MUST NOT query" contract as the rest of this class).
        if ($project->relationLoaded('deliverables')) {
            $undecided = [];

            foreach (ProjectDeliverable::ALL_KEYS as $key) {
                $row = $project->deliverables->firstWhere('deliverable_key', $key);

                if ($row !== null
                    && $row->state === ProjectDeliverable::STATE_NOT_YET_DECIDED
                    && $row->created_at instanceof Carbon
                    && Carbon::now()->diffInDays($row->created_at, false) < -self::DELIVERABLE_DECISION_GRACE_DAYS) {
                    $undecided[] = $row;
                }
            }

            if ($undecided !== []) {
                return new ProjectHealth(
                    'amber',
                    'Deliverables selection needs review — '.count($undecided).' item(s) still Not yet decided',
                    $overdue
                );
            }
        }

        return new ProjectHealth('green', 'On track', $overdue);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * D-13 grace period: how many days a deliverable may sit in
     * "not_yet_decided" before it is flagged amber. Anchored to this file's
     * own existing "Stage duration > 7 days" amber threshold (see the AMBER
     * block above) — the only precedent in this codebase for "how long
     * before an unattended thing becomes worth flagging". No other value has
     * any basis in the source material (260822-CONTEXT.md D-13).
     */
    private const DELIVERABLE_DECISION_GRACE_DAYS = 7;

    /**
     * D-12: true when the project has an explicit "not_required" state for
     * the given deliverable key. Safe to call with no eager-loaded
     * relation — Project::deliverableState() returns null in that case,
     * which never equals STATE_NOT_REQUIRED, so the guard simply no-ops
     * rather than issuing a query.
     */
    private function isExplicitlyNotRequired(Project $project, string $key): bool
    {
        return $project->deliverableState($key) === ProjectDeliverable::STATE_NOT_REQUIRED;
    }

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
