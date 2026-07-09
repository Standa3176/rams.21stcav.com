<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Worksheet;

/**
 * Authorization policy for Worksheet.
 *
 * Registered in AppServiceProvider::boot() via:
 *   Gate::policy(Worksheet::class, WorksheetPolicy::class);
 *
 * Re-audit S-04 — was a bespoke `abort_unless(auth()->check(), 403)` on the
 * retry-generation handler in WorksheetController, while RamsController's
 * matching route went through `$this->authorize('update', $rams)`. If the
 * workspace ever tightens per-project ownership (the RAMS + SCC merge
 * memo hints at this), the retry route needs a single enforcement point.
 * This policy mirrors OmManualPolicy: today every method returns true
 * (shared team workspace), but the surface exists so per-user rules can
 * land in one place.
 */
class WorksheetPolicy
{
    /** Shared workspace — any authenticated user may view. */
    public function view(User $user, Worksheet $worksheet): bool
    {
        return true;
    }

    /** Shared workspace — any authenticated user may update. */
    public function update(User $user, Worksheet $worksheet): bool
    {
        return true;
    }

    /** Shared workspace — any authenticated user may delete. */
    public function delete(User $user, Worksheet $worksheet): bool
    {
        return true;
    }
}
