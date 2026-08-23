<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ProjectDeliverable — the three-state (D-01) record of whether a given
 * deliverable is expected on a project. One row per (project_id,
 * deliverable_key), unique-constrained at the DB level.
 *
 * ALL_KEYS (D-04) is the single canonical nine-item deliverable vocabulary
 * for the whole phase, reconciling the three lists that previously disagreed
 * (project tabs, DocumentArtifactStorage::TYPE_*, DocumentEditAdapterRegistry
 * — see 260822-CONTEXT.md D-07). It is deliberately exhaustive:
 *
 *   - Quotes, Asset Register and Project Data are EXCLUDED (D-06) — they are
 *     inputs to a project, not deliverables produced by it, and MUST NEVER
 *     be added to this list.
 *   - Programming (KEY_PROGRAMMING) IS included (D-04) but is a tracked flag
 *     only (D-05) — there is no Programming model, no generator, no project
 *     tab, and no DocumentArtifactStorage storage type for it. Its presence
 *     here is intentional and complete; do not build any of those things for
 *     it as part of this phase.
 *
 * All writes MUST go through App\Services\ProjectDeliverablesService — see
 * that class's docblock. Direct `ProjectDeliverable::create()` /
 * `->update()` calls bypass the D-03 audit trail.
 *
 * @see app/Models/ProjectDeliverableAudit.php
 * @see app/Services/ProjectDeliverablesService.php
 * @see app/Services/ProjectHealthService.php (D-13 amber rule, reads undecided_since)
 * @see .planning/phases/260822-esf-project-deliverables-selection/260822-CONTEXT.md (D-01, D-04, D-05, D-06)
 * @see .planning/quick/20260823-amber-backcatalogue/PLAN.md (undecided_since)
 *
 * @property int $id
 * @property int $project_id
 * @property string $deliverable_key
 * @property string $state
 * @property \Illuminate\Support\Carbon|null $undecided_since Anchors the D-13
 *   amber-grace-period clock. Non-null = this row became `not_yet_decided` at
 *   that moment via a real decision path (ProjectDeliverablesService::setState()).
 *   Null = never explicitly left undecided by a human (e.g. the D-17
 *   back-catalogue retrofit) — grandfathered, never goes amber under D-13.
 */
class ProjectDeliverable extends Model
{
    // ── Canonical deliverable keys (D-04) ───────────────────────────────────

    public const KEY_SITE_SURVEY = 'site_survey';

    public const KEY_RAMS = 'rams';

    public const KEY_WORKSHEET = 'worksheet';

    public const KEY_OM = 'om';

    public const KEY_CABLE_SCHEDULE = 'cable_schedule';

    public const KEY_INSTALL_PROGRAMME = 'install_programme';

    public const KEY_DRAWINGS = 'drawings';

    public const KEY_SNAGGING = 'snagging';

    public const KEY_PROGRAMMING = 'programming';

    /**
     * The single canonical nine-item list, in D-04's stated order. Nothing
     * else is selectable (D-06 — Quotes/Asset Register/Project Data are
     * inputs, not deliverables, and must never be added here).
     */
    public const ALL_KEYS = [
        self::KEY_SITE_SURVEY,
        self::KEY_RAMS,
        self::KEY_WORKSHEET,
        self::KEY_OM,
        self::KEY_CABLE_SCHEDULE,
        self::KEY_INSTALL_PROGRAMME,
        self::KEY_DRAWINGS,
        self::KEY_SNAGGING,
        self::KEY_PROGRAMMING,
    ];

    // ── Three-state enum (D-01) ──────────────────────────────────────────────

    public const STATE_REQUIRED = 'required';

    public const STATE_NOT_REQUIRED = 'not_required';

    public const STATE_NOT_YET_DECIDED = 'not_yet_decided';

    protected $fillable = [
        'project_id',
        'deliverable_key',
        'state',
        'undecided_since',
    ];

    protected $casts = [
        'undecided_since' => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function audits(): HasMany
    {
        return $this->hasMany(ProjectDeliverableAudit::class);
    }
}
