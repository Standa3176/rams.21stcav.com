<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Time entry for a user working on a project.
 *
 * Phase 14 delivered the minimum columns needed to make clock in / out end-to-end
 * work on the mobile field page (INST-04g). Phase 15 extends with category
 * (CATEGORIES enum), notes, closure_reason, and an audits() history relation
 * for retro-edit review (D-04, D-06, D-12).
 *
 * Invariants:
 *   - At most one open entry per (project_id, user_id) at any time — enforced
 *     by TimeEntryService::start() with DB::transaction + lockForUpdate.
 *   - category is nullable at the DB level (Phase 14 rows backfill to null),
 *     but Plan 15-02 TimeEntryService::start() enforces membership in CATEGORIES
 *     for all NEW entries per D-03.
 *   - All timestamps stored as UTC (Laravel default). Display in Europe/London
 *     handled at the view layer via Carbon::setTimezone() (D-19).
 *
 * @see TimeEntryService — business logic + guard
 * @see TimeEntryController — HTTP endpoints (start / stop / heartbeat / update)
 * @see TimeEntryAudit — append-only retro-edit history
 */
class TimeEntry extends Model
{
    use HasFactory;

    // ─────────────────────────────────────────────────────────────────────────
    // Category enum (D-01) — DB values stored lowercase; UI title-cases
    // ─────────────────────────────────────────────────────────────────────────

    public const CATEGORY_INSTALLATION  = 'installation';
    public const CATEGORY_COMMISSIONING = 'commissioning';
    public const CATEGORY_TESTING       = 'testing';
    public const CATEGORY_OTHER         = 'other';

    public const CATEGORIES = [
        self::CATEGORY_INSTALLATION,
        self::CATEGORY_COMMISSIONING,
        self::CATEGORY_TESTING,
        self::CATEGORY_OTHER,
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // Closure reason (D-12) — null = manual clock-out; sentinel below = scheduler
    // ─────────────────────────────────────────────────────────────────────────

    public const CLOSURE_REASON_STALE_AUTO_CLOSE = 'stale_auto_close';

    protected $fillable = [
        'project_id',
        'user_id',
        'category',
        'clocked_in_at',
        'clocked_out_at',
        'last_heartbeat_at',
        'notes',
        'closure_reason',
    ];

    protected function casts(): array
    {
        return [
            'clocked_in_at'     => 'datetime',
            'clocked_out_at'    => 'datetime',
            'last_heartbeat_at' => 'datetime',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────────────────

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Retro-edit history for this entry (D-04, D-07).
     * Append-only: Plan 15-02 service creates rows; nothing updates/deletes them.
     */
    public function audits(): HasMany
    {
        return $this->hasMany(TimeEntryAudit::class);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // State helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * True when the entry has not yet been clocked out.
     */
    public function isOpen(): bool
    {
        return $this->clocked_out_at === null;
    }
}
