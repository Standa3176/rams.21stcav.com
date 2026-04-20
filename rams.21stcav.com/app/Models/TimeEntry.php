<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Time entry for a user working on a project (Phase 14 partial, INST-04g guard path).
 *
 * Phase 14 delivers the minimum columns needed to make clock in / out end-to-end
 * work on the mobile field page. Phase 15 will extend with category, notes,
 * heartbeat loop, and the close-stale-sessions scheduled job (INST-04a–e).
 *
 * Invariants:
 *   - At most one open entry per (project_id, user_id) at any time — enforced
 *     by TimeEntryService::start() with DB::transaction + lockForUpdate.
 *   - All timestamps stored as UTC (Laravel default). Display in Europe/London
 *     handled at the view layer via Carbon::setTimezone().
 *
 * @see TimeEntryService — business logic + guard
 * @see TimeEntryController — HTTP endpoints (start / stop)
 */
class TimeEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'user_id',
        'clocked_in_at',
        'clocked_out_at',
        'last_heartbeat_at',
    ];

    protected function casts(): array
    {
        return [
            'clocked_in_at'     => 'datetime',
            'clocked_out_at'    => 'datetime',
            'last_heartbeat_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * True when the entry has not yet been clocked out.
     */
    public function isOpen(): bool
    {
        return $this->clocked_out_at === null;
    }
}
