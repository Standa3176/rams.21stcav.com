<?php

namespace App\Policies;

use App\Models\CableSchedule;
use App\Models\User;

/**
 * Authorization policy for CableSchedule.
 *
 * Registered in AppServiceProvider::boot() via:
 *   Gate::policy(CableSchedule::class, CableSchedulePolicy::class);
 *
 * Re-audit S-04 — mirrors WorksheetPolicy. Today every method returns
 * true (shared workspace), but the enforcement point exists so any
 * future per-user rule lands in one file.
 */
class CableSchedulePolicy
{
    /** Shared workspace — any authenticated user may view. */
    public function view(User $user, CableSchedule $schedule): bool
    {
        return true;
    }

    /** Shared workspace — any authenticated user may update. */
    public function update(User $user, CableSchedule $schedule): bool
    {
        return true;
    }

    /** Shared workspace — any authenticated user may delete. */
    public function delete(User $user, CableSchedule $schedule): bool
    {
        return true;
    }
}
