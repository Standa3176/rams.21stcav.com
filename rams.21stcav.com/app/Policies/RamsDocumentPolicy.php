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
     * Shared team workspace: any authenticated user may view/download a RAMS record (auth middleware guarantees a logged-in user here).
     */
    public function view(User $user, RamsDocument $rams): bool
    {
        return true;
    }

    /**
     * Shared team workspace: any authenticated user may update (status change, regenerate) a RAMS record (auth middleware guarantees a logged-in user here).
     */
    public function update(User $user, RamsDocument $rams): bool
    {
        return true;
    }

    /**
     * Shared team workspace: any authenticated user may delete a RAMS record (auth middleware guarantees a logged-in user here).
     */
    public function delete(User $user, RamsDocument $rams): bool
    {
        return true;
    }
}
