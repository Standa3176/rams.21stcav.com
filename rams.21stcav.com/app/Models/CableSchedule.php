<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CableSchedule extends Model
{
    use HasFactory, SoftDeletes;

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
        'error_message',
        'completion_email_sent_at',
        'failed_email_sent_at',
    ];

    // ── Casts ─────────────────────────────────────────────────────────────────

    protected function casts(): array
    {
        return [
            'completion_email_sent_at' => 'datetime',
            'failed_email_sent_at'     => 'datetime',
        ];
    }

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

    // ── Stale-data signal (quick task 260726-fx4) ────────────────────────────
    //
    // Mirrors the pattern on Worksheet / OmManual / RamsDocument — a schedule
    // is stale when its underlying project package OR its associated survey
    // has moved on AFTER the schedule was generated. Unlike the other doc
    // types, CableSchedule doesn't persist a `generated_data['generated_at']`
    // snapshot; we use `completion_email_sent_at` (stamped once at the end
    // of BuildCableScheduleJob::handle) as the primary reference, falling
    // back to `updated_at` for legacy rows generated before that column
    // landed. Only draft / final are candidates — pending / generating /
    // failed have their own UX surface (progress spinner or retry banner).

    /**
     * True iff the project's latestPackage OR latestSurvey moved on after
     * this cable schedule was generated. Defensive against missing project
     * link, missing package/survey rows, or unset generation timestamps.
     */
    public function isStale(): bool
    {
        if (! in_array($this->status, [self::STATUS_DRAFT, self::STATUS_FINAL], true)) {
            return false;
        }

        $this->loadMissing(['project.latestPackage', 'project.latestSurvey']);

        $project = $this->project;
        if ($project === null) {
            return false;
        }

        // Prefer completion_email_sent_at (stamped once on successful build),
        // fall back to updated_at for legacy rows. Cast defensively — the
        // completion timestamp is casted to datetime above, updated_at is
        // always a Carbon on Eloquent models.
        $generatedAt = $this->completion_email_sent_at ?? $this->updated_at;
        if ($generatedAt === null) {
            return false;
        }
        $generatedAtC = $generatedAt instanceof \Illuminate\Support\Carbon
            ? $generatedAt
            : \Illuminate\Support\Carbon::parse($generatedAt);

        $package = $project->latestPackage;
        if ($package !== null && $generatedAtC->lt($package->updated_at)) {
            return true;
        }

        $survey = $project->latestSurvey;
        if ($survey !== null
            && $survey->submitted_at !== null
            && $generatedAtC->lt($survey->submitted_at)) {
            return true;
        }

        return false;
    }

    /**
     * When isStale() is true, returns the Carbon timestamp of the most-recent
     * source change (package updated_at OR survey submitted_at, whichever is
     * later). Null when fresh or any defensive branch fired.
     */
    public function staleSince(): ?\Illuminate\Support\Carbon
    {
        if (! $this->isStale()) {
            return null;
        }

        $packageAt = $this->project?->latestPackage?->updated_at;
        $surveyAt  = $this->project?->latestSurvey?->submitted_at;

        $candidates = array_filter([
            $packageAt instanceof \Illuminate\Support\Carbon
                ? $packageAt
                : ($packageAt ? \Illuminate\Support\Carbon::parse($packageAt) : null),
            $surveyAt instanceof \Illuminate\Support\Carbon
                ? $surveyAt
                : ($surveyAt  ? \Illuminate\Support\Carbon::parse($surveyAt)  : null),
        ]);

        if (empty($candidates)) {
            return null;
        }

        return collect($candidates)->max();
    }
}
