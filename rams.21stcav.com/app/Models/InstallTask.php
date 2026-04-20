<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Represents a single unit of work within an install programme.
 *
 * Each task covers one piece of equipment in one room, with a task type
 * describing the nature of the work (install, configure, cable, test, commission).
 *
 * room_name is denormalised (NOT a FK to site_survey_rooms). ProjectDataService
 * resolves rooms from reviewed_data; room IDs may not match survey room names
 * exactly. This is an intentional design decision (see T-12-01 threat register).
 *
 * Status pipeline: pending → in_progress → complete (or blocked / skipped).
 *
 * @see InstallProgramme         — parent programme owning this task
 * @see InstallTaskGeneratorService — generates tasks from ProjectDataService data
 */
class InstallTask extends Model
{
    use HasFactory, SoftDeletes;

    // ── Status constants ──────────────────────────────────────────────────────

    public const STATUS_PENDING     = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETE    = 'complete';
    public const STATUS_BLOCKED     = 'blocked';
    public const STATUS_SKIPPED     = 'skipped';

    // ── Task type constants ───────────────────────────────────────────────────

    public const TYPE_INSTALL    = 'install';
    public const TYPE_CONFIGURE  = 'configure';
    public const TYPE_CABLE      = 'cable';
    public const TYPE_TEST       = 'test';
    public const TYPE_COMMISSION = 'commission';

    // ── Mass-assignable fields ────────────────────────────────────────────────

    protected $fillable = [
        'install_programme_id',
        'room_name',
        'room_ref',
        'equipment_name',
        'quantity',
        'equipment_category',
        'task_type',
        'title',
        'description',
        'status',
        'blocked_reason',
        'sort_order',
        'notes',
        'assigned_to',
        'assigned_at',
        'started_at',
        'completed_at',
        'sign_off_required',
        'planned_start_date',
        'planned_end_date',
        'status_changed_at',
        'status_changed_by',
    ];

    // ── Casts ─────────────────────────────────────────────────────────────────

    protected function casts(): array
    {
        return [
            'assigned_at'        => 'datetime',
            'started_at'         => 'datetime',
            'completed_at'       => 'datetime',
            'quantity'           => 'integer',
            'sort_order'         => 'integer',
            'sign_off_required'  => 'boolean',
            'planned_start_date' => 'date',
            'planned_end_date'   => 'date',
            'status_changed_at'  => 'datetime',
        ];
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function programme(): BelongsTo
    {
        return $this->belongsTo(InstallProgramme::class, 'install_programme_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(InstallTaskPhoto::class)->orderBy('sort_order');
    }

    public function statusChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Human-readable status label for display.
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING     => 'Pending',
            self::STATUS_IN_PROGRESS => 'In Progress',
            self::STATUS_COMPLETE    => 'Complete',
            self::STATUS_BLOCKED     => 'Blocked',
            self::STATUS_SKIPPED     => 'Skipped',
            default                  => ucfirst(str_replace('_', ' ', $this->status)),
        };
    }

    /**
     * Returns true when this task is awaiting work.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Returns true when this task has been signed off as complete.
     */
    public function isComplete(): bool
    {
        return $this->status === self::STATUS_COMPLETE;
    }
}
