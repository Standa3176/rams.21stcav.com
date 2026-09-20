<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class SiteSurvey extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'project_id',
        'project_name',
        'project_ref',
        'client_name',
        'site_address',
        'survey_date',
        'surveyor_name',
        'site_contact_name',
        'site_contact_phone',
        'visit_time',
        'pm_name',
        'pm_phone',
        'pm_email',
        'general_notes',
        'site_risks',
        'access_constraints',
        'h_and_s_notes',
        // Office review surface (quick task 260508-v7g)
        'office_review_notes',
        // Engineer-feedback site logistics (quick task 260503-rgg)
        'comms_room_access_status',
        'comms_room_access_notes',
        'parking_restraints',
        'distance_from_base_miles',
        'distance_from_base_notes',
        'site_access_notes',
        'delivery_routes',
        'superseded_at',
        'status',
        'filename',
        // Re-audit S-03 — `access_token` dropped from $fillable so no
        // `$survey->update([...])` payload can rotate the public engineer
        // link. The only writer is boot::creating() (line 72) which uses
        // direct property assignment and bypasses $fillable.
        'expires_at',
        'submitted_at',
        // Re-audit S-02 — `submitted_notification_sent_at` dropped from
        // $fillable so a client can't fake "office notified {N min ago}"
        // via a validated payload. The one legitimate writer
        // (SurveyService::submitPublic) uses ->forceFill() which bypasses
        // $fillable, so behaviour is unchanged.
        'survey_type',
        'survey_data',
    ];

    protected $casts = [
        'survey_date'                    => 'date',
        'expires_at'                     => 'datetime',
        'submitted_at'                   => 'datetime',
        'submitted_notification_sent_at' => 'datetime',
        'superseded_at'                  => 'datetime',
        'survey_data'                    => 'array',
    ];

    // ─── Boot: auto-generate access token on creation ────────────────────────

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (SiteSurvey $survey): void {
            if (empty($survey->access_token)) {
                $survey->access_token = (string) Str::uuid();
            }
        });
    }

    // ─── Relationships ────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(SiteSurveyRoom::class)->orderBy('sort_order');
    }

    /**
     * Office-side variations (quick task 260508-v7g — flat capture, no workflow).
     * Ordered by created_at so the table renders in chronological capture order.
     */
    public function variations(): HasMany
    {
        return $this->hasMany(SurveyVariation::class)->orderBy('created_at');
    }

    // ─── Status helpers ───────────────────────────────────────────────────────

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }

    /**
     * Is the engineer's form closed to further editing?
     *
     * ── D-02, QUOTED VERBATIM ───────────────────────────────────────────────
     *
     *   "Send back — rejects the return and reopens the engineer link for more
     *    information, rather than accepting something incomplete."
     *
     *   "Add an office note — the PM annotates the return WITHOUT CHANGING WHAT
     *    THE ENGINEER SAID. The engineer's record stays intact; the office view
     *    sits alongside it. Do NOT let an office note overwrite or edit
     *    engineer-captured data."
     *
     * Submitted, AND the office has not asked for more information since.
     *
     * THE REOPENING IS DERIVED, NEVER STORED, and `submitted_at` IS NEVER
     * CLEARED. The obvious implementation of "send back" — null out
     * `submitted_at` so the form reopens — is precisely the violation the
     * second quote forbids: `submitted_at` is the engineer's own record of
     * when they said "I am done", and an office action must not rewrite it.
     * Instead `VisitReworkState` compares `sent_back_at` against that very
     * timestamp, so resubmitting relocks the form by itself with no flag to
     * clear and no engineer record touched. See `VisitReworkState` for the
     * full argument.
     *
     * ── DO NOT RETARGET `isSubmitted()` AT THIS ─────────────────────────────
     *
     * `isSubmitted()` above is UNCHANGED and still means "was submitted". The
     * PDF generator, the submitted-notification path and the project-health
     * service all mean that by it, and none of them mean "closed to editing".
     * This method is the engineer-facing gate only.
     */
    public function isLockedForEngineer(): bool
    {
        if (! $this->isSubmitted()) {
            return false;
        }

        return ! \App\Support\Visits\VisitReworkState::isReopened($this);
    }

    // ─── Token helpers ────────────────────────────────────────────────────────

    /**
     * Returns true when the access token has passed its expiry time.
     * Surveys with a null expires_at never expire.
     */
    public function isTokenExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * The full public URL engineers use to access the survey form.
     */
    public function publicUrl(): string
    {
        return route('survey.show', ['token' => $this->access_token]);
    }
}
