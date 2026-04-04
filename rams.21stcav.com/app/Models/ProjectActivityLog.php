<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectActivityLog extends Model
{
    // Append-only: no updated_at
    const UPDATED_AT = null;

    // ── Action constants ──────────────────────────────────────────────────────

    const ACTION_CREATED          = 'project_created';
    const ACTION_STATUS_CHANGED   = 'status_changed';
    const ACTION_REOPENED         = 'project_reopened';
    const ACTION_DOCUMENT_ADDED   = 'document_added';
    const ACTION_DOCUMENT_UPDATED = 'document_updated';
    const ACTION_NOTE_ADDED       = 'note_added';
    const ACTION_PACKAGE_IMPORTED = 'package_imported';
    const ACTION_PACKAGE_REVIEWED = 'package_reviewed';

    protected $fillable = [
        'project_id',
        'user_id',
        'action',
        'from_status',
        'to_status',
        'description',
        'metadata',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'created_at' => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Accessor ──────────────────────────────────────────────────────────────

    public function getActorNameAttribute(): string
    {
        return $this->user?->name ?? 'System';
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeStatusChanges($query)
    {
        return $query->where('action', self::ACTION_STATUS_CHANGED);
    }

    public function scopeRecent($query, int $limit = 20)
    {
        return $query->orderByDesc('created_at')->limit($limit);
    }
}
