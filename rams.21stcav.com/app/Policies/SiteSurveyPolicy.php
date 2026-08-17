<?php

namespace App\Policies;

use App\Models\SiteSurvey;
use App\Models\User;

/**
 * Authorization policy for SiteSurvey.
 *
 * Registered in AppServiceProvider::boot() via:
 *   Gate::policy(SiteSurvey::class, SiteSurveyPolicy::class);
 *
 * Quick task 260817-w4k — SiteSurvey was the ONE document type reachable
 * through DocumentEditController with no policy behind it. Laravel denies
 * an ability that has no policy or gate, so routing the AI-edit endpoints
 * through can('update', ...) would have 403'd every survey edit until this
 * file existed. Verified before writing it: Gate::allows('update', $survey)
 * returned false while the other four types returned true.
 *
 * Mirrors CableSchedulePolicy. Today every method returns true (shared
 * workspace) — this file adds NO restriction; it exists so the enforcement
 * point is present and any future per-user rule lands in one place.
 */
class SiteSurveyPolicy
{
    /** Shared workspace — any authenticated user may view. */
    public function view(User $user, SiteSurvey $survey): bool
    {
        return true;
    }

    /** Shared workspace — any authenticated user may update. */
    public function update(User $user, SiteSurvey $survey): bool
    {
        return true;
    }

    /** Shared workspace — any authenticated user may delete. */
    public function delete(User $user, SiteSurvey $survey): bool
    {
        return true;
    }
}
