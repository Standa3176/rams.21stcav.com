<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 46 Plan 07 Task 1 — an OFFICE NOTE on a visit.
 *
 * 46-CONTEXT.md D-02, verbatim:
 *
 *   "Add an office note — the PM annotates the return without changing what
 *   the engineer said. The engineer's record stays intact; the office view
 *   sits alongside it. Do NOT let an office note overwrite or edit
 *   engineer-captured data."
 *
 * That sentence is this model's entire reason for existing as a separate
 * table. An office note SITS ALONGSIDE the engineer's record; it is never
 * inside it.
 *
 * ── DO NOT "CONSOLIDATE" THIS WITH `site_surveys.office_review_notes` ───────
 *
 * That column already exists and reusing it would have been one line. Three
 * reasons it was not, recorded here so the next agent does not undo the
 * decision by tidying:
 *
 *   1. It is SINGLE-VALUED and OVERWRITABLE — the second note destroys the
 *      first.
 *   2. It carries NO AUTHOR and NO TIMESTAMP — "who said that, and when" is
 *      unanswerable.
 *   3. It lives ON THE ENGINEER'S RECORD — office text inside engineer-
 *      captured data is precisely the shape D-02 forbids.
 *
 * And a worksheet has no equivalent column at all, so reusing the survey's
 * would leave first-fix and install visits unannotatable.
 *
 * ── APPEND-ONLY, STRUCTURALLY ───────────────────────────────────────────────
 *
 * `UPDATED_AT = null` and the table has no `updated_at` column, the same shape
 * `ProjectActivityLog` uses. THERE IS NO UPDATE PATH AND NO DELETE PATH: no
 * route, no controller method, no model method. A test greps `app/` and the
 * route table so the absence cannot quietly become a presence.
 *
 * @see database/migrations/2026_09_20_140000_create_visit_notes_table.php
 * @see .planning/phases/46-visit-lifecycle/46-CONTEXT.md (D-02)
 *
 * @property int $id
 * @property int $project_id
 * @property ?int $visit_id
 * @property ?int $user_id
 * @property string $body
 * @property ?\Illuminate\Support\Carbon $created_at
 */
class VisitNote extends Model
{
    use HasFactory;

    /** Append-only: no updated_at. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'project_id',
        'visit_id',
        'user_id',
        'body',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────────

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The visit it annotates. NULL is legitimate: `visits` has no softDeletes,
     * so a deleted visit nulls the pointer and the note survives.
     */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // ── Accessor ─────────────────────────────────────────────────────────

    /**
     * The same word the activity feed uses for a deleted login, so one person
     * leaving does not read two different ways on the same panel.
     *
     * @see ProjectActivityLog::getActorNameAttribute()
     */
    public function getActorNameAttribute(): string
    {
        return $this->author?->name ?? 'System';
    }
}
