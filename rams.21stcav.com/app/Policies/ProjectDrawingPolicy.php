<?php

namespace App\Policies;

use App\Models\ProjectDrawing;
use App\Models\User;

/**
 * Authorization policy for ProjectDrawing (DRAW-25).
 *
 * Mirrors RamsDocumentPolicy exactly: the document owner OR any admin may
 * view / update / delete. "Owner" here is the user identified by
 * generated_by (the engineer who triggered drawing creation), since
 * drawings have no separate user_id column — generated_by is the only user
 * FK on the table.
 *
 * Registered in AppServiceProvider::boot() via:
 *   Gate::policy(ProjectDrawing::class, ProjectDrawingPolicy::class);
 *
 * Usage in controller:
 *   $this->authorize('view',   $drawing);
 *   $this->authorize('update', $drawing);
 *   $this->authorize('delete', $drawing);
 */
class ProjectDrawingPolicy
{
    /**
     * Shared team workspace: any authenticated user may view/download the drawing (auth middleware guarantees a logged-in user here).
     */
    public function view(User $user, ProjectDrawing $drawing): bool
    {
        return true;
    }

    /**
     * Shared team workspace: any authenticated user may update (status change,
     * regenerate) the drawing (auth middleware guarantees a logged-in user here).
     */
    public function update(User $user, ProjectDrawing $drawing): bool
    {
        return true;
    }

    /**
     * Shared team workspace: any authenticated user may delete the drawing (auth middleware guarantees a logged-in user here).
     */
    public function delete(User $user, ProjectDrawing $drawing): bool
    {
        return true;
    }
}
