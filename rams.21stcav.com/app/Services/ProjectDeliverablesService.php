<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectDeliverable;
use App\Models\ProjectDeliverableAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ProjectDeliverablesService — the sole write path for project_deliverables
 * (260822-CONTEXT.md D-01/D-02/D-03).
 *
 * All writes to project_deliverables MUST go through this service — mirrors
 * the house rule stated in ProjectService's docblock for project status
 * transitions. Direct `ProjectDeliverable::create()` / `->update()` calls
 * bypass the D-03 audit trail and MUST NOT be used.
 *
 * Every state change writes exactly one ProjectDeliverableAudit row inside
 * the same DB::transaction() as the model write (mirrors the before/after
 * snapshot pattern used by all three write sites in
 * Admin\DeviceStencilController — capture $before, ->update(), capture
 * $after, write the audit row, all inside one transaction).
 *
 * D-02's soft gate (autoFlipIfNotRequired()) never blocks a create — it is
 * called AFTER a document is created against a deliverable currently marked
 * Not required, to repair the flag, never to prevent the create.
 *
 * @see app/Models/ProjectDeliverable.php
 * @see app/Models/ProjectDeliverableAudit.php
 * @see .planning/phases/260822-esf-project-deliverables-selection/260822-CONTEXT.md (D-01, D-02, D-03)
 */
class ProjectDeliverablesService
{
    /**
     * Set a deliverable's state, creating the row on first write. Audits
     * every call — even a no-op re-set to the same state (D-03: "who, when"
     * must be provable even for a confirm-no-change action).
     *
     * @throws \Throwable  Never validates $newState — see this phase's
     *                     threat model T-260822-01: callers (controllers)
     *                     MUST validate the state via FormRequest/
     *                     $request->validate() with `in:required,not_required,
     *                     not_yet_decided` BEFORE calling this method.
     */
    public function setState(
        Project $project,
        string $key,
        string $newState,
        User $user,
        ?string $reason = null,
        string $action = ProjectDeliverableAudit::ACTION_MANUAL_CHANGE,
    ): ProjectDeliverable {
        return DB::transaction(function () use ($project, $key, $newState, $user, $reason, $action) {
            $row = ProjectDeliverable::firstOrCreate(
                ['project_id' => $project->id, 'deliverable_key' => $key],
                ['state' => ProjectDeliverable::STATE_NOT_YET_DECIDED],
            );

            $before = ['state' => $row->state];
            $row->update(['state' => $newState]);
            $after = ['state' => $newState];

            ProjectDeliverableAudit::create([
                'project_deliverable_id' => $row->id,
                'user_id' => $user->id,
                'action' => $action,
                'reason' => $reason,
                'before_snapshot' => $before,
                'after_snapshot' => $after,
            ]);

            return $row->fresh();
        });
    }

    /**
     * D-02's soft gate: repair a deliverable that was Not required but just
     * had a document created against it. Never blocks the create — this is
     * called AFTER it, to fix the flag. A no-op (no state change, NO audit
     * row) when the deliverable is currently Required or Not yet decided —
     * only actual flips are audited.
     */
    public function autoFlipIfNotRequired(Project $project, string $key, User $user): void
    {
        $row = ProjectDeliverable::where('project_id', $project->id)
            ->where('deliverable_key', $key)
            ->first();

        if ($row === null || $row->state !== ProjectDeliverable::STATE_NOT_REQUIRED) {
            return;
        }

        $this->setState(
            $project,
            $key,
            ProjectDeliverable::STATE_REQUIRED,
            $user,
            "Auto-flipped: a {$key} document was created while marked Not required.",
            ProjectDeliverableAudit::ACTION_AUTO_FLIP,
        );
    }

    /**
     * Seed initial deliverable states (e.g. D-15's import defaults, D-17's
     * back-catalogue retrofit) — one row + one audit row per key, all inside
     * a single DB::transaction() (all-or-nothing).
     *
     * @param  array<string, string>  $states  deliverable_key => state
     */
    public function setInitialStates(
        Project $project,
        array $states,
        User $user,
        string $action = ProjectDeliverableAudit::ACTION_IMPORT_DEFAULT,
    ): void {
        DB::transaction(function () use ($project, $states, $user, $action) {
            foreach ($states as $key => $state) {
                $this->setState($project, $key, $state, $user, null, $action);
            }
        });
    }
}
