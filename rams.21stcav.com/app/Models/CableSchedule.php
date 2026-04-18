<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CableSchedule extends Model
{
    use SoftDeletes;

    // ── Status constants ──────────────────────────────────────────────────────
    public const STATUS_PENDING    = 'pending';
    public const STATUS_GENERATING = 'generating';
    public const STATUS_DRAFT      = 'draft';
    public const STATUS_FINAL      = 'final';
    public const STATUS_FAILED     = 'failed';

    protected $fillable = [
        'user_id',
        'project_id',
        'project_name',
        'project_ref',
        'client_name',
        'source_filename',
        'status',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CableScheduleItem::class)->orderBy('sort_order');
    }
}
