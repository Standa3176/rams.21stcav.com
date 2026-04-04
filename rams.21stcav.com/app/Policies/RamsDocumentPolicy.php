<?php

namespace App\Policies;

use App\Models\RamsDocument;
use App\Models\User;

/**
 * Authorization policy for RamsDocument.
 *
 * Registered in AppServiceProvider::boot() via:
 *   Gate::policy(RamsDocument::class, RamsDocumentPolicy::class);
 *
 * Usage in controller:
 *   $this->authorize('view',   $rams);
 *   $this->authorize('update', $rams);
 *   $this->authorize('delete', $rams);
 */
class RamsDocumentPolicy
{
    /**
     * Allow the document owner OR any admin to view/download a RAMS record.
     */
    public function view(User $user, RamsDocument $rams): bool
    {
        return $user->id === $rams->user_id || $user->isAdmin();
    }

    /**
     * Allow the document owner OR any admin to update (status change, regenerate) a RAMS record.
     */
    public function update(User $user, RamsDocument $rams): bool
    {
        return $user->id === $rams->user_id || $user->isAdmin();
    }

    /**
     * Allow the document owner OR any admin to delete a RAMS record.
     */
    public function delete(User $user, RamsDocument $rams): bool
    {
        return $user->id === $rams->user_id || $user->isAdmin();
    }
}
