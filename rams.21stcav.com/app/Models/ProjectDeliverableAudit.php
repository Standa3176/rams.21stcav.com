<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProjectDeliverableAudit — append-only audit trail row for a
 * ProjectDeliverable state change (260822-CONTEXT.md D-03: every flag change
 * records who, when, and an optional why).
 *
 * Mirrors App\Models\DeviceStencilAudit's shape (Phase 24 D-03 precedent),
 * with one confirmed addition: a nullable `reason` free-text column, which
 * device_stencil_audits lacks. D-03 requires "why" explicitly (optional free
 * text), so it is not omitted here.
 *
 * Written exclusively by App\Services\ProjectDeliverablesService, inside the
 * same DB::transaction() as the ProjectDeliverable state change it records.
 *
 * @see app/Models/ProjectDeliverable.php
 * @see app/Services/ProjectDeliverablesService.php
 * @see .planning/phases/260822-esf-project-deliverables-selection/260822-CONTEXT.md (D-03)
 *
 * @property int $id
 * @property int $project_deliverable_id
 * @property int $user_id
 * @property string $action ACTION_* constant value
 * @property ?string $reason
 * @property ?array $before_snapshot
 * @property ?array $after_snapshot
 */
class ProjectDeliverableAudit extends Model
{
    // ── Action enum (D-03) ───────────────────────────────────────────────────

    public const ACTION_MANUAL_CHANGE = 'manual_change';

    public const ACTION_AUTO_FLIP = 'auto_flip';

    public const ACTION_IMPORT_DEFAULT = 'import_default';

    public const ACTION_BACKFILL = 'backfill';

    protected $fillable = [
        'project_deliverable_id',
        'user_id',
        'action',
        'reason',
        'before_snapshot',
        'after_snapshot',
    ];

    protected $casts = [
        'before_snapshot' => 'array',
        'after_snapshot'  => 'array',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function deliverable(): BelongsTo
    {
        return $this->belongsTo(ProjectDeliverable::class, 'project_deliverable_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }
}
