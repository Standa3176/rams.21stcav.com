<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Single source of truth for Phase 09 notification recipient resolution.
 *
 * All six Phase 09 trigger sites (RAMS ready, O&M ready, Worksheet ready,
 * Cable Schedule ready, Survey submitted, job-failed alerts) resolve their
 * recipient by calling this service. Centralising the rule fixes two latent
 * column/relation naming bugs that are already present in
 * app/Core/Modules/Survey/SurveyService.php (see the SurveyService refactor
 * in plan 09-04) — consult 09-RESEARCH.md "Pitfalls 1 + 2" for the full
 * analysis. The resolver below uses the canonical names:
 *
 *   - Admin lookup:  User::where('role', 'admin')   (role column, string enum)
 *   - Project owner: Project->owner  (belongsTo relation on user_id)
 *
 * Both names match the existing User::isAdmin() helper and the
 * Project::owner() relation declared in app/Models/Project.php. The legacy
 * column/relation names referenced in SurveyService DO NOT EXIST in the
 * schema or on the Project model — this resolver must never regress to them.
 *
 * Container usage: no constructor dependencies, so call sites resolve via
 *   app(NotificationRecipientResolver::class)
 * No service-provider binding is required.
 *
 * @see NOTF-05a, NOTF-05b, NOTF-05g
 * @see .planning/phases/09-email-notifications/09-RESEARCH.md — Pitfalls 1 + 2
 */
class NotificationRecipientResolver
{
    /**
     * Resolve the primary notification recipient for a given project.
     *
     * Fallback order:
     *   1. Project owner (Project.user_id → User.id) — only if the owner
     *      has a non-empty email address. Treats null AND '' as missing
     *      (users.email is NOT NULL at the schema level — "no email" in
     *      practice means an empty string).
     *   2. First admin (`User::where('role', 'admin')` with non-empty email,
     *      ordered by id ascending for deterministic selection).
     *   3. null — no recipient exists; the caller MUST log and skip the send.
     *
     * @param  Project|null  $project  Null selects the admin fallback directly
     *                                 (used by cross-project admin alerts).
     */
    public function resolveProjectRecipient(?Project $project): ?User
    {
        if ($project) {
            $project->loadMissing('owner');

            if ($project->owner instanceof User && $project->owner->email) {
                return $project->owner;
            }
        }

        return User::where('role', 'admin')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->orderBy('id')
            ->first();
    }

    /**
     * Return every admin user with a non-null email — the recipient list for
     * broadcast failure alerts (NOTF-05b: any admin can triage a failed job).
     *
     * Returns an empty Collection when no admins exist or none have an email.
     * Callers typically iterate with `->each()` and log the empty case.
     *
     * @return Collection<int, User>
     */
    public function resolveAdminRecipients(): Collection
    {
        return User::where('role', 'admin')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->get();
    }
}
