<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 24 Plan 01 Task 1 — curation audit trail row (CONTEXT.md D-03).
 *
 * The record of truth for "who curated what, when". A `device_stencils` row
 * is deliberately project-less (no project_id — that's what makes 21 D-03's
 * cross-project cache propagation work), so a project-scoped
 * `ProjectActivityLog` entry can't answer that question, and `metadata` only
 * ever holds the LAST edit (Phase 21 D-02 role — notes / last-edited-by
 * convenience). This table is the append-style history behind it.
 *
 * `stencils:reapply-templates` (D-08, Plan 24-08) reads DeviceStencil::audits()
 * to scope its `whereDoesntHave('audits')` re-templating eligibility — a
 * stencil with ANY audit row (promoted or hand-edited) is never silently
 * re-templated.
 *
 * Naming (D-09 / 21 D-09): generic — no rams_ / project_ prefix — so the
 * table ports to SCC after the planned RAMS+SCC merge.
 *
 * @see app/Models/DeviceStencil.php
 * @see database/migrations/2026_08_13_140000_add_needs_review_and_logo_path_to_device_stencils_and_create_device_stencil_audits.php
 * @see .planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-CONTEXT.md (D-03)
 *
 * @property int $id
 * @property int $device_stencil_id
 * @property int $user_id
 * @property string $action ACTION_* constant value
 * @property ?array $before_snapshot
 * @property ?array $after_snapshot
 */
class DeviceStencilAudit extends Model
{
    // ── Action enum (D-03) ───────────────────────────────────────────────────
    // Mirrors the DeviceStencil::SOURCE_* const-per-enum-value style.

    public const ACTION_PROMOTE = 'promote';

    public const ACTION_EDIT = 'edit';

    public const ACTION_DISCARD_REGENERATE = 'discard-regenerate';

    protected $fillable = [
        'device_stencil_id',
        'user_id',
        'action',
        'before_snapshot',
        'after_snapshot',
    ];

    protected $casts = [
        'before_snapshot' => 'array',
        'after_snapshot'  => 'array',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function stencil(): BelongsTo
    {
        return $this->belongsTo(DeviceStencil::class, 'device_stencil_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }
}
