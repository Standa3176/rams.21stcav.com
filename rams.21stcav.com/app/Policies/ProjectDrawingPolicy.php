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
     * Allow the drawing's generator OR any admin to view/download the drawing.
     */
    public function view(User $user, ProjectDrawing $drawing): bool
    {
        return $user->id === $drawing->generated_by || $user->isAdmin();
    }

    /**
     * Allow the drawing's generator OR any admin to update (status change,
     * regenerate) the drawing.
     */
    public function update(User $user, ProjectDrawing $drawing): bool
    {
        return $user->id === $drawing->generated_by || $user->isAdmin();
    }

    /**
     * Allow the drawing's generator OR any admin to delete the drawing.
     */
    public function delete(User $user, ProjectDrawing $drawing): bool
    {
        return $user->id === $drawing->generated_by || $user->isAdmin();
    }
}
