<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use HasFactory, SoftDeletes;
    // ── Lifecycle states ──────────────────────────────────────────────────────

    const STATUS_QUOTE_IMPORTED = 'quote_imported';

    const STATUS_SURVEY_PENDING = 'survey_pending';

    const STATUS_ENGINEERING = 'engineering';

    const STATUS_INSTALLING = 'installing';

    const STATUS_COMMISSIONING = 'commissioning';

    const STATUS_HANDOVER = 'handover';

    const STATUS_COMPLETED = 'completed';

    const STATUS_ARCHIVED = 'archived';

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
        self::STATUS_ENGINEERING => [self::STATUS_INSTALLING],
        self::STATUS_INSTALLING => [self::STATUS_COMMISSIONING],
        self::STATUS_COMMISSIONING => [self::STATUS_HANDOVER],
        self::STATUS_HANDOVER => [self::STATUS_COMPLETED],
        self::STATUS_COMPLETED => [self::STATUS_ARCHIVED],
        self::STATUS_ARCHIVED => [], // reopen handled separately
    ];

    /**
     * Valid backward transitions from each status (per D-20).
     * Any authenticated user may revert a project to a previous lifecycle stage.
     * Archiving is handled separately via archive()/reopen() methods.
     */
    const TRANSITIONS_BACKWARD = [
        self::STATUS_SURVEY_PENDING => [self::STATUS_QUOTE_IMPORTED],
        self::STATUS_ENGINEERING => [self::STATUS_SURVEY_PENDING],
        self::STATUS_INSTALLING => [self::STATUS_ENGINEERING],
        self::STATUS_COMMISSIONING => [self::STATUS_INSTALLING],
        self::STATUS_HANDOVER => [self::STATUS_COMMISSIONING],
        self::STATUS_COMPLETED => [self::STATUS_HANDOVER],
    ];

    // ── Display labels & colours ──────────────────────────────────────────────

    const STATUS_LABELS = [
        self::STATUS_QUOTE_IMPORTED => 'Quote Imported',
        self::STATUS_SURVEY_PENDING => 'Survey Pending',
        self::STATUS_ENGINEERING => 'Engineering',
        self::STATUS_INSTALLING => 'Installing',
        self::STATUS_COMMISSIONING => 'Commissioning',
        self::STATUS_HANDOVER => 'Handover',
        self::STATUS_COMPLETED => 'Completed',
        self::STATUS_ARCHIVED => 'Archived',
    ];

    const STATUS_COLOURS = [
        self::STATUS_QUOTE_IMPORTED => '#6c757d',  // grey
        self::STATUS_SURVEY_PENDING => '#fd7e14',  // orange
        self::STATUS_ENGINEERING => '#0d6efd',  // blue
        self::STATUS_INSTALLING => '#6f42c1',  // purple
        self::STATUS_COMMISSIONING => '#20c997',  // teal-green
        self::STATUS_HANDOVER => '#007B8A',  // brand teal
        self::STATUS_COMPLETED => '#28a745',  // green
        self::STATUS_ARCHIVED => '#adb5bd',  // light grey
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
        // Phase 4 — Tier 1 OM lifecycle dates
        'handover_date',
        'defects_liability_end',
    ];

    protected $casts = [
        'reopened_at' => 'datetime',
        'survey_started_at' => 'datetime',
        'engineering_started_at' => 'datetime',
        'installation_started_at' => 'datetime',
        'commissioning_started_at' => 'datetime',
        'handover_started_at' => 'datetime',
        'completed_at' => 'datetime',
        'archived_at' => 'datetime',
        // Phase 4 — Tier 1 OM lifecycle dates (date-only, no time component)
        'handover_date' => 'date',
        'defects_liability_end' => 'date',
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

    // ── Phase 4 — Tier 1 OM data model relationships ─────────────────────────

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /**
     * Lowercased, trimmed, deduped set of part numbers from the latest
     * package's equipment_list filtered to category=hardware. Used by
     * the Mini O&M and the Asset Register tab to hide test photos and
     * non-physical line items (services, consumables) so the asset views
     * only show items that are actually quoted hardware.
     *
     * Returns [] when no package or no hardware lines — callers should
     * treat empty as "no filter applied" (show everything) so a fresh
     * project before package import doesn't render an empty register.
     *
     * @return array<int, string>
     */
    public function hardwarePartNumbers(): array
    {
        $eq = $this->latestPackage?->equipment_list ?? [];
        if (! is_array($eq)) {
            return [];
        }

        $parts = [];
        foreach ($eq as $line) {
            if (! is_array($line)) {
                continue;
            }
            $cat = strtolower(trim((string) ($line['category'] ?? 'hardware')));
            if ($cat !== 'hardware') {
                continue;
            }
            $part = strtolower(trim((string) ($line['part_number'] ?? '')));
            if ($part !== '') {
                $parts[$part] = true;
            }
        }

        return array_keys($parts);
    }

    /**
     * Phase 21 Plan 01 — Project's hardware equipment lines paired with their
     * DeviceStencil row (per CONTEXT.md D-07).
     *
     * Reads `latestPackage->extracted_data['equipment']` (fallback to
     * `equipment_list` for legacy projects), filters to category=hardware
     * (mirrors hardwarePartNumbers()), and joins each line to a DeviceStencil
     * via the cross-project firstOrCreate cache (DeviceStencilCacheService).
     *
     * Output shape (one entry per hardware line):
     *   [
     *     'part_number'  => string,
     *     'manufacturer' => ?string,
     *     'model'        => ?string,
     *     'name'         => string,
     *     'quantity'     => int,
     *     'area'         => ?string,
     *     'stencil'      => ?DeviceStencil,
     *   ]
     *
     * Lines with empty part_number are kept in the output with stencil = null
     * so the renderer can surface a "no part_number" warning rather than
     * silently dropping them.
     *
     * SIDE EFFECT (D-07): on first encounter of an uncatalogued part_number,
     * the cache service writes a Tier 1 placeholder DeviceStencil row to the
     * database. This is intentional — every project gets stencils on day 1,
     * even uncatalogued items render *something*. Subsequent reads (this
     * project OR any other project that references the same part_number)
     * are pure SELECTs.
     *
     * RACE-SAFETY (D-03): NOT wrapped in DB::transaction. The unique index on
     * device_stencils.part_number makes the underlying firstOrCreate atomic;
     * concurrent first-calls converge to the same row. Wrapping in a
     * transaction would block on the unique index without benefit and would
     * actually HURT throughput (T-21.01-03 mitigation rationale).
     *
     * Phase 24's curation UI promotes auto-generated stencils to engineer-
     * curated ones in-place; the cache key (part_number) means every
     * project automatically picks up the upgrade on next render — no
     * per-project re-association needed.
     *
     * @return array<int, array{part_number: string, manufacturer: ?string, model: ?string, name: string, quantity: int, area: ?string, stencil: ?\App\Models\DeviceStencil}>
     */
    public function devicesWithStencils(): array
    {
        $extracted = $this->latestPackage?->extracted_data ?? null;
        $eq = (is_array($extracted) ? ($extracted['equipment'] ?? null) : null)
            ?? $this->latestPackage?->equipment_list
            ?? [];

        if (! is_array($eq)) {
            return [];
        }

        $lines = [];
        foreach ($eq as $line) {
            if (! is_array($line)) {
                continue;
            }
            $cat = strtolower(trim((string) ($line['category'] ?? 'hardware')));
            if ($cat !== 'hardware') {
                continue;
            }
            $lines[] = [
                'part_number'  => (string) ($line['part_number'] ?? ''),
                'manufacturer' => $line['manufacturer'] ?? null,
                'model'        => $line['model'] ?? null,
                'name'         => (string) ($line['name'] ?? ''),
                'quantity'     => (int) ($line['quantity'] ?? 1),
                'area'         => $line['area'] ?? null,
            ];
        }

        if ($lines === []) {
            return [];
        }

        return app(\App\Services\Drawings\DeviceStencilCacheService::class)->resolveMany($lines);
    }

    public function appendices(): HasMany
    {
        return $this->hasMany(Appendix::class);
    }

    public function commissioningTests(): HasMany
    {
        return $this->hasMany(CommissioningTest::class);
    }

    public function trainingRecords(): HasMany
    {
        return $this->hasMany(TrainingRecord::class);
    }

    public function installProgrammes(): HasMany
    {
        return $this->hasMany(InstallProgramme::class)->orderBy('created_at', 'desc');
    }

    public function activeInstallProgramme(): HasOne
    {
        return $this->hasOne(InstallProgramme::class)
            ->where('status', InstallProgramme::STATUS_ACTIVE)
            ->latestOfMany();
    }

    /**
     * v1.3 / Phase 17 — every drawing kind (schematic / rack / floor_plan).
     * Filter by kind at the call site, e.g.
     *   $project->drawings()->where('kind', ProjectDrawing::KIND_SCHEMATIC)
     *
     * Plan 03's index page filters out superseded revisions via
     *   ->whereNull('superseded_by_id') so only current versions show.
     */
    public function drawings(): HasMany
    {
        return $this->hasMany(ProjectDrawing::class);
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
