<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use HasFactory, SoftDeletes;
    // ── Lifecycle states ──────────────────────────────────────────────────────

    const STATUS_QUOTE_IMPORTED   = 'quote_imported';
    const STATUS_SURVEY_PENDING   = 'survey_pending';
    const STATUS_ENGINEERING      = 'engineering';
    const STATUS_INSTALLING       = 'installing';
    const STATUS_COMMISSIONING    = 'commissioning';
    const STATUS_HANDOVER         = 'handover';
    const STATUS_COMPLETED        = 'completed';
    const STATUS_ARCHIVED         = 'archived';

    /** Ordered lifecycle progression. */
    const LIFECYCLE = [
        self::STATUS_QUOTE_IMPORTED,
        self::STATUS_SURVEY_PENDING,
        self::STATUS_ENGINEERING,
        self::STATUS_INSTALLING,
        self::STATUS_COMMISSIONING,
        self::STATUS_HANDOVER,
        self::STATUS_COMPLETED,
        self::STATUS_ARCHIVED,
    ];

    /**
     * Valid forward transitions from each status.
     * Archiving is always allowed regardless of current status.
     */
    const TRANSITIONS = [
        self::STATUS_QUOTE_IMPORTED => [self::STATUS_SURVEY_PENDING],
        self::STATUS_SURVEY_PENDING => [self::STATUS_ENGINEERING],
        self::STATUS_ENGINEERING    => [self::STATUS_INSTALLING],
        self::STATUS_INSTALLING     => [self::STATUS_COMMISSIONING],
        self::STATUS_COMMISSIONING  => [self::STATUS_HANDOVER],
        self::STATUS_HANDOVER       => [self::STATUS_COMPLETED],
        self::STATUS_COMPLETED      => [self::STATUS_ARCHIVED],
        self::STATUS_ARCHIVED       => [], // reopen handled separately
    ];

    /**
     * Valid backward transitions from each status (per D-20).
     * Any authenticated user may revert a project to a previous lifecycle stage.
     * Archiving is handled separately via archive()/reopen() methods.
     */
    const TRANSITIONS_BACKWARD = [
        self::STATUS_SURVEY_PENDING => [self::STATUS_QUOTE_IMPORTED],
        self::STATUS_ENGINEERING    => [self::STATUS_SURVEY_PENDING],
        self::STATUS_INSTALLING     => [self::STATUS_ENGINEERING],
        self::STATUS_COMMISSIONING  => [self::STATUS_INSTALLING],
        self::STATUS_HANDOVER       => [self::STATUS_COMMISSIONING],
        self::STATUS_COMPLETED      => [self::STATUS_HANDOVER],
    ];

    // ── Display labels & colours ──────────────────────────────────────────────

    const STATUS_LABELS = [
        self::STATUS_QUOTE_IMPORTED => 'Quote Imported',
        self::STATUS_SURVEY_PENDING => 'Survey Pending',
        self::STATUS_ENGINEERING    => 'Engineering',
        self::STATUS_INSTALLING     => 'Installing',
        self::STATUS_COMMISSIONING  => 'Commissioning',
        self::STATUS_HANDOVER       => 'Handover',
        self::STATUS_COMPLETED      => 'Completed',
        self::STATUS_ARCHIVED       => 'Archived',
    ];

    const STATUS_COLOURS = [
        self::STATUS_QUOTE_IMPORTED => '#6c757d',  // grey
        self::STATUS_SURVEY_PENDING => '#fd7e14',  // orange
        self::STATUS_ENGINEERING    => '#0d6efd',  // blue
        self::STATUS_INSTALLING     => '#6f42c1',  // purple
        self::STATUS_COMMISSIONING  => '#20c997',  // teal-green
        self::STATUS_HANDOVER       => '#007B8A',  // brand teal
        self::STATUS_COMPLETED      => '#28a745',  // green
        self::STATUS_ARCHIVED       => '#adb5bd',  // light grey
    ];

    // ── Eloquent config ───────────────────────────────────────────────────────

    protected $fillable = [
        'user_id',
        'name',
        'ref',
        'quote_reference',
        'client_name',
        'site_address',
        'works_description',
        'status',
        'previous_status',
        'reopened_at',
        'reopened_by',
        'reopen_reason',
        'survey_started_at',
        'engineering_started_at',
        'installation_started_at',
        'commissioning_started_at',
        'handover_started_at',
        'completed_at',
        'archived_at',
        'notes',
    ];

    protected $casts = [
        'reopened_at'              => 'datetime',
        'survey_started_at'        => 'datetime',
        'engineering_started_at'   => 'datetime',
        'installation_started_at'  => 'datetime',
        'commissioning_started_at' => 'datetime',
        'handover_started_at'      => 'datetime',
        'completed_at'             => 'datetime',
        'archived_at'              => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function packages(): HasMany
    {
        return $this->hasMany(ProjectPackage::class);
    }

    public function latestPackage()
    {
        return $this->hasOne(ProjectPackage::class)->latestOfMany();
    }

    public function activityLog(): HasMany
    {
        return $this->hasMany(ProjectActivityLog::class)->orderByDesc('created_at');
    }

    public function ramsDocuments(): HasMany
    {
        return $this->hasMany(RamsDocument::class);
    }

    public function omManuals(): HasMany
    {
        return $this->hasMany(OmManual::class);
    }

    public function siteSurveys(): HasMany
    {
        return $this->hasMany(SiteSurvey::class);
    }

    public function cableSchedules(): HasMany
    {
        return $this->hasMany(CableSchedule::class);
    }

    public function worksheets(): HasMany
    {
        return $this->hasMany(Worksheet::class);
    }

    public function installProgrammes(): HasMany
    {
        return $this->hasMany(InstallProgramme::class)->orderBy('created_at', 'desc');
    }

    public function activeInstallProgramme(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(InstallProgramme::class)
                    ->where('status', InstallProgramme::STATUS_ACTIVE)
                    ->latestOfMany();
    }

    /**
     * All uploaded quote versions for this project, oldest first.
     */
    public function projectQuotes(): HasMany
    {
        return $this->hasMany(ProjectQuote::class)->orderBy('version_number');
    }

    /**
     * The most recently uploaded quote version for this project.
     */
    public function latestProjectQuote()
    {
        return $this->hasOne(ProjectQuote::class)->latestOfMany('version_number');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [
            self::STATUS_COMPLETED,
            self::STATUS_ARCHIVED,
        ]);
    }

    /**
     * Exclude archived projects from the query (default scope for index views).
     * Archived projects are only visible via explicit ?status=archived filter.
     */
    public function scopeNotArchived($query)
    {
        return $query->where('status', '!=', self::STATUS_ARCHIVED);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    // ── Lifecycle helpers ─────────────────────────────────────────────────────

    public function canTransitionTo(string $status): bool
    {
        // Archiving is always available from any non-archived status.
        if ($status === self::STATUS_ARCHIVED) {
            return $this->status !== self::STATUS_ARCHIVED;
        }

        // Archived projects cannot transition anywhere (use reopen() instead).
        if ($this->status === self::STATUS_ARCHIVED) {
            return false;
        }

        // Check forward transitions.
        if (in_array($status, self::TRANSITIONS[$this->status] ?? [])) {
            return true;
        }

        // Check backward transitions (per D-20 — any user may revert lifecycle stage).
        return in_array($status, self::TRANSITIONS_BACKWARD[$this->status] ?? []);
    }

    public function nextStatus(): ?string
    {
        $transitions = self::TRANSITIONS[$this->status] ?? [];

        return $transitions[0] ?? null;
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    public function isCompleted(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_ARCHIVED]);
    }

    public function canReopen(): bool
    {
        return $this->status === self::STATUS_ARCHIVED
            && $this->previous_status !== null;
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? ucfirst($this->status);
    }

    public function getStatusColourAttribute(): string
    {
        return self::STATUS_COLOURS[$this->status] ?? '#6c757d';
    }
}
