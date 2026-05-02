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
    public function view(User $user, Project $project): bool
    {
        return $user->id === $project->user_id || $user->isAdmin();
    }

    public function update(User $user, Project $project): bool
    {
        return $user->id === $project->user_id || $user->isAdmin();
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->id === $project->user_id || $user->isAdmin();
    }
}
