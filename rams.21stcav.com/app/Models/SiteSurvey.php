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
        'superseded_at',
        'status',
        'filename',
        'access_token',
        'expires_at',
        'submitted_at',
        'survey_type',
        'survey_data',
    ];

    protected $casts = [
        'survey_date'   => 'date',
        'expires_at'    => 'datetime',
        'submitted_at'  => 'datetime',
        'superseded_at' => 'datetime',
        'survey_data'   => 'array',
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
