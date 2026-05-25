<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

/**
 * Authorization policy for Project (Phase 18 Plan 03).
 *
 * Owner-OR-admin gate at the Project level. Mirrors the inline ownership
 * checks scattered through ProjectController (e.g. `$project->user_id ===
 * auth()->id() || auth()->user()?->role === 'admin'`) — collecting them into
 * a single policy gives flipRackMountedFlag (and any future Project-scoped
 * mutation) a single authoritative authorisation seam.
 *
 * "Owner" = `Project::user_id` (the engineer who created/imported the
 * project).
 *
 * Registered in AppServiceProvider::boot() via:
 *   Gate::policy(Project::class, ProjectPolicy::class);
 *
 * Usage in controller:
 *   $this->authorize('view',   $project);
 *   $this->authorize('update', $project);
 *   $this->authorize('delete', $project);
 */
class ProjectPolicy
{
    /**
     * Shared team workspace: any authenticated user may view a Project (auth middleware guarantees a logged-in user here).
     */
    public function view(User $user, Project $project): bool
    {
        return true;
    }

    /**
     * Shared team workspace: any authenticated user may update a Project (auth middleware guarantees a logged-in user here).
     */
    public function update(User $user, Project $project): bool
    {
        return true;
    }

    /**
     * Shared team workspace: any authenticated user may delete a Project (auth middleware guarantees a logged-in user here).
     */
    public function delete(User $user, Project $project): bool
    {
        return true;
    }
}
