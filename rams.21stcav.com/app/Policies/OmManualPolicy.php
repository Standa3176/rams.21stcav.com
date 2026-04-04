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
    public function view(User $user, OmManual $manual): bool
    {
        return $user->id === $manual->user_id || $user->isAdmin();
    }

    public function update(User $user, OmManual $manual): bool
    {
        return $user->id === $manual->user_id || $user->isAdmin();
    }

    public function delete(User $user, OmManual $manual): bool
    {
        return $user->id === $manual->user_id || $user->isAdmin();
    }
}
