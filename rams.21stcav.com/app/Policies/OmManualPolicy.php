<?php

namespace App\Policies;

use App\Models\OmManual;
use App\Models\User;

/**
 * Authorization policy for OmManual.
 *
 * Registered in AppServiceProvider::boot() via:
 *   Gate::policy(OmManual::class, OmManualPolicy::class);
 */
class OmManualPolicy
{
    /**
     * Shared team workspace: any authenticated user may view an O&M record (auth middleware guarantees a logged-in user here).
     */
    public function view(User $user, OmManual $manual): bool
    {
        return true;
    }

    /**
     * Shared team workspace: any authenticated user may update an O&M record (auth middleware guarantees a logged-in user here).
     */
    public function update(User $user, OmManual $manual): bool
    {
        return true;
    }

    /**
     * Shared team workspace: any authenticated user may delete an O&M record (auth middleware guarantees a logged-in user here).
     */
    public function delete(User $user, OmManual $manual): bool
    {
        return true;
    }
}
