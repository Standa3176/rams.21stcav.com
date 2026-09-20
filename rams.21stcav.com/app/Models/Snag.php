<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 46 Plan 02 Task 1 — the MINIMAL snag record.
 *
 * 46-CONTEXT.md D-03, verbatim:
 *
 *   "raising a snag here creates a minimal snag record linked to the visit it
 *   came from. Parts, the three outcomes, and linked follow-up snags are
 *   Phase 47. Phase 46 must not build the snag lifecycle — it provides the
 *   entry point Phase 47 builds out. Design the record so Phase 47 extends it
 *   rather than replacing it."
 *
 * So this model captures what a PM SAW, and nothing about what happens next.
 * There is no outcome, no parts list, no follow-up chain, no resolver and no
 * assignee. The only writer in Phase 46 is the PM action built in Plan 46-05;
 * there is deliberately no route, controller, policy or service for snags in
 * this plan.
 *
 * ── PHASE 47 EXTENSION POINTS ───────────────────────────────────────────────
 *
 * Named here as future work so this is a foundation rather than a stub. Each
 * one is currently ABSENT and asserted absent by
 * `SnagTest::test_the_snags_table_carries_no_phase_47_column()`. Phase 47
 * removes the matching entry from that list deliberately, in a commit that
 * says why.
 *
 *   1. THE THREE OUTCOMES on `status` (Phase 47 criterion 2) — `STATUSES`
 *      grows to include `fixed`, `not_fixed` and `deferred`. The column is
 *      already `string(24)` with an index on `(project_id, status)`, so this
 *      is an append to the constant list, not a migration of shape.
 *   2. A PARTS RELATION (Phase 47 criterion 4) — parts are tracked PER SNAG,
 *      so they belong in their own `snag_parts` table hanging off `snag_id`.
 *      Nothing here needs to change for that; a `parts()` hasMany simply
 *      appears. This is why there is no `parts` column: a json blob would have
 *      to be unpicked.
 *   3. `parent_snag_id` FOR LINKED FOLLOW-UPS (Phase 47 criterion 3) — a
 *      not-fixed snag opens a NEW snag linked to the original. That is a
 *      nullable self-referencing column plus `parent()` / `children()`, added
 *      to this same table.
 *
 * Two Phase 47 shapes this record already supports without alteration:
 *   - A SNAG WITH NO VISIT (criteria 1 and 5): `visit_id` is nullable today.
 *   - ONE VISIT RESOLVING SEVERAL SNAGS (criterion 1): the link lives on the
 *     snag as a plain belongsTo, so N snags may point at one visit already.
 *     A snag is a distinct record from a snag visit here, not a field on one.
 *
 * ── Survival ────────────────────────────────────────────────────────────────
 *
 * A snag OUTLIVES the visit it came from, and outlives that visit's source
 * being superseded or force-deleted — the same D-04 property the visit itself
 * has. The trip to site happened and what was found is real. `visit_id` is
 * `nullOnDelete` for exactly this reason; see the migration docblock.
 *
 * @see database/migrations/2026_09_20_130000_create_snags_table.php
 * @see .planning/phases/46-visit-lifecycle/46-CONTEXT.md (D-02, D-03)
 *
 * @property int $id
 * @property int $project_id
 * @property ?int $visit_id
 * @property string $title
 * @property ?string $detail
 * @property ?string $room_name
 * @property ?int $raised_by_user_id
 * @property string $status
 */
class Snag extends Model
{
    use HasFactory;

    // ── Status ───────────────────────────────────────────────────────────────
    //
    // EXACTLY ONE entry. A Phase 46 snag has been raised and nothing has yet
    // happened to it; there is no other state it can honestly be in. The three
    // outcomes (fixed / not fixed / deferred) are Phase 47 criterion 2 and are
    // added HERE when that phase runs — extension point 1 above.

    public const STATUS_OPEN = 'open';

    public const STATUSES = [
        self::STATUS_OPEN,
    ];

    protected $fillable = [
        'project_id',
        'visit_id',
        'title',
        'detail',
        'room_name',
        'raised_by_user_id',
        'status',
    ];

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * The snag's mandatory parent — a snag has no meaning without its project.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The visit it came from. NULL is a legitimate value: the visit may have
     * been deleted (nullOnDelete), and from Phase 47 a snag may never have had
     * one at all.
     */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by_user_id');
    }
}
