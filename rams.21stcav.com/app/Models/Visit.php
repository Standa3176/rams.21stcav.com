<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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
 * -- Phase 46: the lifecycle --------------------------------------------------
 *
 * The paragraph above is SUPERSEDED for writes only -- and only in where they
 * live. Phase 46 gives a visit a lifecycle (sent, returned, sent back,
 * accepted), but the writes are owned by the Phase 46 controller, not by this
 * model: there is still NO observer, NO model event and NO route here.
 *
 * THE RULE THAT PICKED THE COLUMNS, in one sentence so it survives: A COLUMN
 * FOR EVERY ACT A HUMAN PERFORMED, AND A DERIVATION FOR EVERYTHING THE
 * ENGINEER'S OWN RECORD ALREADY KNOWS.
 *
 * So `sent_at`, `accepted_at` and `sent_back_at` are stored -- a PM performed
 * them here and nothing else records them -- while `returnedAt()` and
 * `isLocked()` are DERIVED, for exactly the reason `isSuperseded()` is: a
 * stored copy of "it came back" would go stale the moment a worksheet is
 * re-signed after remedials, and then this model would contradict the record
 * it wraps. There is deliberately no `returned_at` and no `scope_locked_at`
 * column; see the migration docblock before adding either.
 *
 * The stored `status` vocabulary is UNCHANGED (`planned` / `completed`) so no
 * Phase 45 backfilled row is rewritten. The new `STATE_*` vocabulary is a
 * DERIVED layer on top of it, not a replacement.
 *
 * D-06: there is no "Edit visit" control, so no column exists here that only
 * an edit form would use. A visit locks on return; a PM who needs a change
 * sends it back.
 *
 * @see database/migrations/2026_09_19_140000_create_visits_table.php
 * @see database/migrations/2026_09_20_120000_add_lifecycle_columns_to_visits_table.php
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
 * @property ?\Illuminate\Support\Carbon $sent_at
 * @property ?\Illuminate\Support\Carbon $accepted_at
 * @property ?\Illuminate\Support\Carbon $sent_back_at
 * @property ?string $send_back_reason
 * @property ?int $accepted_by_user_id
 * @property ?int $created_by_user_id
 * @property ?array $rooms_in_scope
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
    // Only the two states a read-only phase could evidence -- and Phase 46
    // does NOT grow the list. A visit whose link has been issued is stored as
    // `planned` with `sent_at` set: `sent` is a DERIVED state (see STATES
    // below), not a stored one. Growing this vocabulary would mean rewriting
    // the 24 reconstructed rows on live, which is exactly what
    // `VisitLifecycleTest::test_the_stored_status_vocabulary_did_not_grow()`
    // exists to stop.

    public const STATUS_PLANNED = 'planned';

    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_COMPLETED,
    ];

    // ── Derived lifecycle states (Phase 46) ─────────────────────────
    //
    // NOT a column. `state()` resolves these at read time from the stored acts
    // plus the engineer's own record. Nothing writes a STATE_* value anywhere.

    public const STATE_PLANNED = 'planned';

    public const STATE_SENT = 'sent';

    public const STATE_RETURNED = 'returned';

    public const STATE_SENT_BACK = 'sent_back';

    public const STATE_ACCEPTED = 'accepted';

    /** Every Phase 45 backfilled visit: complete before the lifecycle existed. */
    public const STATE_CLOSED = 'closed';

    public const STATES = [
        self::STATE_PLANNED,
        self::STATE_SENT,
        self::STATE_RETURNED,
        self::STATE_SENT_BACK,
        self::STATE_ACCEPTED,
        self::STATE_CLOSED,
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
        // Phase 46 lifecycle. Deliberately nothing token-like: there is no
        // token on this model and none is to be introduced here. SiteSurvey
        // and Worksheet omit their access tokens from $fillable by security
        // re-audit, and an engineer link must follow that same pattern.
        'sent_at',
        'accepted_at',
        'sent_back_at',
        'send_back_reason',
        'accepted_by_user_id',
        'created_by_user_id',
        'rooms_in_scope',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date'      => 'date',
            'labour_resource_ids' => 'array',
            'is_backfilled'       => 'boolean',
            'sent_at'             => 'datetime',
            'accepted_at'         => 'datetime',
            'sent_back_at'        => 'datetime',
            'rooms_in_scope'      => 'array',
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
     * Who accepted this visit. Nullable, and `nullOnDelete` at the database --
     * a deleted staff login reads NULL rather than silently becoming somebody
     * else (T-46-01-02).
     */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    /**
     * The snags raised against this visit (Phase 46, Plan 46-07).
     *
     * READ-ONLY IN THIS PHASE: the row renders a plain COUNT of them and
     * nothing else. There is no snag register in Phase 46 — `Open register` is
     * still a banned affordance — so a count with no destination is the honest
     * rendering of a record Phase 47 will give a home. `snags.visit_id` is
     * nullOnDelete, so a hard-deleted visit loses the link and keeps the snag.
     */
    public function snags(): HasMany
    {
        return $this->hasMany(Snag::class);
    }

    /**
     * The office notes written against this visit (Phase 46, Plan 46-07).
     *
     * APPEND-ONLY and deliberately SEPARATE from anything the engineer
     * captured — D-02: "the engineer's record stays intact; the office view
     * sits alongside it."
     */
    public function notes(): HasMany
    {
        return $this->hasMany(VisitNote::class);
    }

    /**
     * Who created this visit. NULL on every Phase 45 backfilled row -- nobody
     * created those, they were reconstructed.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

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

    // ── The lifecycle: stored acts, derived truth (Phase 46) ─────────────

    /**
     * When the engineer's work came back -- DERIVED, never stored.
     *
     * Read through the existing `source()`: a survey's `submitted_at`, or the
     * most recent `WorksheetSignoff`. Sign-off is APPEND-ONLY, so a re-signoff
     * after remedials produces a newer row and this method follows it; a
     * `returned_at` column would have kept pointing at the first one and then
     * disagreed with the worksheet it wraps. Same doctrine as
     * `isSuperseded()`.
     *
     * NULL when there is no source, when the source was force-deleted, or when
     * nothing has come back yet. Never reads a column on `visits`.
     */
    public function returnedAt(): ?Carbon
    {
        $source = $this->source();

        if ($source === null) {
            return null;
        }

        if ($source instanceof SiteSurvey) {
            return $source->submitted_at;
        }

        if ($source instanceof Worksheet) {
            $signoff = $source->latestSignoff();

            if ($signoff === null) {
                return null;
            }

            // `signed_at` is what `signoffs()` orders by, so it is what
            // "latest" MEANS here. `created_at` is only the fallback for a row
            // somehow written without one.
            return $signoff->signed_at ?? $signoff->created_at;
        }

        return null;
    }

    /**
     * The visit's lifecycle state.
     *
     * THE ORDER BELOW IS THE DECISION, not an implementation detail:
     *
     *   accepted_at set         -> ACCEPTED   (a PM reviewed it; final)
     *   wasSentBack()           -> SENT_BACK  (rework outstanding)
     *   returnedAt() not null   -> RETURNED   (awaiting review)
     *   sent_at set             -> SENT       (out with the engineer)
     *   stored status completed -> CLOSED     (every Phase 45 backfilled visit)
     *   otherwise               -> PLANNED
     */
    public function state(): string
    {
        if ($this->accepted_at !== null) {
            return self::STATE_ACCEPTED;
        }

        if ($this->wasSentBack()) {
            return self::STATE_SENT_BACK;
        }

        if ($this->returnedAt() !== null) {
            return self::STATE_RETURNED;
        }

        if ($this->sent_at !== null) {
            return self::STATE_SENT;
        }

        if ($this->status === self::STATUS_COMPLETED) {
            return self::STATE_CLOSED;
        }

        return self::STATE_PLANNED;
    }

    /**
     * Finished, for the purpose of the cockpit's progress ring.
     *
     * THE ONLY CLOSED TEST IN THE CODEBASE. A visit a PM accepted is finished,
     * and so is a backfilled one that was already complete when Phase 45
     * reconstructed it -- so accepting a visit can never make the ring go
     * backwards.
     */
    public function isClosed(): bool
    {
        return $this->accepted_at !== null
            || $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Out with the engineer, with nothing back yet.
     */
    public function isAwaitingReturn(): bool
    {
        return $this->sent_at !== null && $this->returnedAt() === null;
    }

    /**
     * Scope locks ON RETURN, not on acceptance -- ROADMAP v4.0 criterion 3: a
     * visit "stays editable after sending; scope locks once a return arrives".
     * DERIVED, because the lock is a CONSEQUENCE of the return and a stored
     * `scope_locked_at` could contradict its own cause.
     *
     * D-06: a PM who needs a change on a locked visit SENDS IT BACK. There is
     * no edit control, so there is nothing to unlock.
     */
    public function isLocked(): bool
    {
        return $this->returnedAt() !== null;
    }

    /**
     * Sent back, and not yet answered.
     *
     * COMPARATIVE, not a bare flag: a visit sent back and then returned again
     * is no longer awaiting rework, and a boolean would have had to be un-set
     * by hand to say so. A send-back recorded against a visit with no return
     * at all still counts -- nothing has come back since either way.
     */
    public function wasSentBack(): bool
    {
        if ($this->sent_back_at === null) {
            return false;
        }

        $returnedAt = $this->returnedAt();

        return $returnedAt === null || $this->sent_back_at->greaterThan($returnedAt);
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
