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

    // Phase 46, Plan 46-04 — the cockpit's first write. T-46-04-06: a create
    // with no actor and no entry in the feed would be repudiable, so every
    // visit created from a module drawer writes exactly one of these beside
    // the visit's own `created_by_user_id`.
    const ACTION_VISIT_CREATED    = 'visit_created';

    // Phase 46, Plan 46-06 — the PM's two review acts. Added DELIBERATELY
    // rather than left to the panel feed's humanised fallback (45-12): an
    // unknown action string would still render a readable line, which is
    // exactly why a missing constant here would never be noticed.
    // T-46-06-03: acceptance is accountable — there is no un-accept path, so
    // this row and `visits.accepted_by_user_id` are the permanent record of
    // who said yes.
    const ACTION_VISIT_ACCEPTED   = 'visit_accepted';

    const ACTION_VISIT_SENT_BACK  = 'visit_sent_back';

    // Phase 46, Plan 46-07 — raising a snag. A NEW constant, unlike the office
    // note which reuses ACTION_NOTE_ADDED: a note IS a note and a second
    // constant would split the feed's history for no gain, whereas raising a
    // snag is a distinct act that Phase 47 will read back. D-03: this records
    // that a snag was RAISED. Nothing here says what happens to it next.
    const ACTION_SNAG_RAISED      = 'snag_raised';

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
