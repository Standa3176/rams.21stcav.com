<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 45 Plan 02 Task 1 — one typed record per trip to site.
 *
 * A visit WRAPS the paperwork that evidences it (a `SiteSurvey`, or a
 * `Worksheet` that carries a `WorksheetSignoff` — 45-CONTEXT.md D-01) without
 * owning it. Neither wrapped model changes shape, and neither is touched by
 * this phase.
 *
 * D-04 is the load-bearing property: a visit OUTLIVES its source. The source
 * is resolved through `withTrashed()` so a soft-deleted survey still renders,
 * and `isSuperseded()` is DERIVED at read time, never stored — a stored copy
 * would go stale the moment someone supersedes a survey.
 *
 * Read-only phase: there is deliberately no create/update helper, no observer,
 * no event and no route here. The only writer in Phase 45 is the 45-05
 * backfill command.
 *
 * @see database/migrations/2026_09_19_140000_create_visits_table.php
 * @see .planning/phases/45-visit-model-read-only-cockpit/45-CONTEXT.md (D-01..D-04, D-06)
 *
 * @property int $id
 * @property int $project_id
 * @property ?int $install_record_id
 * @property string $type
 * @property string $status
 * @property ?\Illuminate\Support\Carbon $scheduled_date
 * @property array $labour_resource_ids
 * @property ?string $source_type
 * @property ?int $source_id
 * @property bool $is_backfilled
 * @property ?string $title
 * @property ?string $summary
 */
class Visit extends Model
{
    use HasFactory;

    // ── Visit types (ROADMAP v4.0 criterion 1) ───────────────────────────────
    //
    // D-02 forbids a `legacy` type: an enum value meaning "we do not know"
    // would have to be handled by every filter and report forever. A
    // reconstructed worksheet visit is typed `install` and flagged with
    // `is_backfilled` instead.

    public const TYPE_SITE_SURVEY = 'site_survey';

    public const TYPE_FIRST_FIX = 'first_fix';

    public const TYPE_INSTALL = 'install';

    public const TYPE_PROGRAMMING = 'programming';

    public const TYPE_SNAG = 'snag';

    public const TYPE_COMMISSIONING = 'commissioning';

    public const TYPES = [
        self::TYPE_SITE_SURVEY,
        self::TYPE_FIRST_FIX,
        self::TYPE_INSTALL,
        self::TYPE_PROGRAMMING,
        self::TYPE_SNAG,
        self::TYPE_COMMISSIONING,
    ];

    // ── Statuses ─────────────────────────────────────────────────────────────
    //
    // Only the two states a read-only phase can evidence. The full
    // prepare / send / return / accept lifecycle belongs to Phase 46 and must
    // NOT be pre-built here.

    public const STATUS_PLANNED = 'planned';

    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_COMPLETED,
    ];

    // ── Source vocabulary (the wrapped record) ───────────────────────────────
    //
    // T-45-02-01: `source()` branches on exactly these two hardcoded constants
    // with a `default => null`. A class name is NEVER resolved out of the
    // column, so a poisoned `source_type` cannot instantiate an arbitrary
    // model.

    public const SOURCE_SITE_SURVEY = 'site_survey';

    public const SOURCE_WORKSHEET = 'worksheet';

    public const SOURCE_TYPES = [
        self::SOURCE_SITE_SURVEY,
        self::SOURCE_WORKSHEET,
    ];

    protected $fillable = [
        'project_id',
        'install_record_id',
        'type',
        'status',
        'scheduled_date',
        'labour_resource_ids',
        'source_type',
        'source_id',
        'is_backfilled',
        'title',
        'summary',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date'      => 'date',
            'labour_resource_ids' => 'array',
            'is_backfilled'       => 'boolean',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * The project is a visit's mandatory parent (D-06 refinement) — NOT the
     * install record, which a backfilled survey visit often predates.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    // ── The wrapped record (D-04) ────────────────────────────────────────────

    /**
     * Resolve the wrapped record.
     *
     * Deliberately a plain method, not an Eloquent relation: there is no FK to
     * relate through (see the migration docblock), and the lookup must always
     * be `withTrashed()` so a soft-deleted survey or worksheet still resolves.
     *
     * Returns null when the source has been FORCE-deleted, or when
     * `source_type` is unrecognised. Callers must be able to render a visit
     * from its own denormalised `title` / `scheduled_date` in that case.
     */
    public function source(): ?Model
    {
        if ($this->source_id === null) {
            return null;
        }

        return match ($this->source_type) {
            self::SOURCE_SITE_SURVEY => SiteSurvey::withTrashed()->find($this->source_id),
            self::SOURCE_WORKSHEET   => Worksheet::withTrashed()->find($this->source_id),
            default                  => null,
        };
    }

    /**
     * D-02's explicit, queryable marker: this visit was reconstructed by the
     * backfill, so its type is an inference rather than something a human
     * asserted.
     */
    public function isBackfilled(): bool
    {
        return (bool) $this->is_backfilled;
    }

    /**
     * DERIVED, never stored. True when the wrapped record has been superseded
     * (`superseded_at`, `SiteSurvey` only) or soft-deleted (`deleted_at`).
     *
     * The cockpit MARKS a superseded visit; it must never filter it out
     * (45-RESEARCH.md Pitfall 5).
     */
    public function isSuperseded(): bool
    {
        $source = $this->source();

        if ($source === null) {
            return false;
        }

        return $source->getAttribute('superseded_at') !== null
            || $source->getAttribute('deleted_at') !== null;
    }

    // ── Assigned labour (Phase 44) ───────────────────────────────────────────

    /**
     * The labour resources assigned to this visit.
     *
     * `labour_resource_ids` is a json array of ints, not a pivot table — a
     * visit's assignment list is descriptive display data and gates nothing
     * (T-45-02-02).
     *
     * @return Collection<int, LabourResource>
     */
    public function labourResources(): Collection
    {
        $ids = $this->labour_resource_ids ?? [];

        if ($ids === []) {
            return new Collection();
        }

        return LabourResource::whereIn('id', $ids)->get();
    }
}
