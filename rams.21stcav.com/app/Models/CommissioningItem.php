<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CommissioningItem — one AVIXA-category check per equipment instance.
 *
 * Generated from install_tasks per D-05/D-06/D-07 by CommissioningItemGenerator.
 * Statuses mutate via per-item AJAX endpoints (INST-05c) until
 * CommissioningSignoff lands — thereafter the item is immutable (INST-05i,
 * enforced by CommissioningSignoffException::itemsImmutable in the mutating
 * endpoints and the CommissioningSyncService re-sync guard).
 *
 * SoftDeletes is used so that D-04 re-sync can remove items which no longer
 * have a matching install_task without losing the audit trail. A later
 * re-sync may restore() the same row if the task reappears.
 *
 * @see \App\Services\CommissioningItemGenerator — row factory
 * @see \App\Services\CommissioningSyncService   — D-04 re-sync
 * @see \App\Observers\InstallTaskObserver       — D-03 trigger
 */
class CommissioningItem extends Model
{
    use HasFactory, SoftDeletes;

    // ── Status constants (INST-05a enum) ──────────────────────────────────

    public const STATUS_PENDING = 'pending';
    public const STATUS_PASS    = 'pass';
    public const STATUS_FAIL    = 'fail';
    public const STATUS_NA      = 'na';

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Pending',
        self::STATUS_PASS    => 'Pass',
        self::STATUS_FAIL    => 'Fail',
        self::STATUS_NA      => 'N/A',
    ];

    // ── Category constants (INST-05e / D-08 — exactly 7) ──────────────────

    public const CATEGORY_POWER   = 'power';
    public const CATEGORY_DISPLAY = 'display';
    public const CATEGORY_AUDIO   = 'audio';
    public const CATEGORY_VTC     = 'vtc';
    public const CATEGORY_CONTROL = 'control';
    public const CATEGORY_NETWORK = 'network';
    public const CATEGORY_CABLING = 'cabling';

    /**
     * Category → human label map resolved from config so planner-edited
     * wording propagates without a redeploy of this model.
     *
     * @return array<string, string>
     */
    public static function categoryLabels(): array
    {
        return config('commissioning.categories');
    }

    /**
     * Ordered list of category keys, matching the render order on the
     * sign-off sheet. Source of truth is config/commissioning.php — if a
     * new category is added there it appears here automatically.
     *
     * @return array<int, string>
     */
    public static function categoriesList(): array
    {
        return array_keys(config('commissioning.categories'));
    }

    // ── Eloquent config ──────────────────────────────────────────────────

    protected $fillable = [
        'install_programme_id',
        'install_task_id',
        'equipment_name',
        'room_name',
        'category',
        'status',
        'evidence_photo_path',
        'notes',
        'signed_off_by',
        'signed_off_at',
    ];

    protected $casts = [
        'signed_off_at' => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────────

    public function programme(): BelongsTo
    {
        return $this->belongsTo(InstallProgramme::class, 'install_programme_id');
    }

    public function installTask(): BelongsTo
    {
        return $this->belongsTo(InstallTask::class, 'install_task_id');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Pending is the only non-terminal state — pass/fail/na all count as
     * "engineer has made a decision on this item" (INST-05 acceptance gate).
     */
    public function isComplete(): bool
    {
        return in_array(
            $this->status,
            [self::STATUS_PASS, self::STATUS_FAIL, self::STATUS_NA],
            true,
        );
    }
}
