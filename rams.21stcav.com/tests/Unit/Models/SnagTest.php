<?php

namespace Tests\Unit\Models;

use App\Models\Snag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 46 Plan 02 — locks the MINIMAL snag record D-03 asks for, and fences
 * it against Phase 47.
 *
 * 46-CONTEXT.md D-03: "raising a snag here creates a minimal snag record
 * linked to the visit it came from. Parts, the three outcomes, and linked
 * follow-up snags are Phase 47. Phase 46 must not build the snag lifecycle —
 * it provides the entry point Phase 47 builds out."
 *
 * @see app/Models/Snag.php
 * @see database/migrations/2026_09_20_130000_create_snags_table.php
 * @see .planning/phases/46-visit-lifecycle/46-CONTEXT.md (D-03)
 */
class SnagTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Phase 47 success criterion 2 owns the three outcomes (fixed / not fixed
     * / deferred). A Phase 46 snag has no other state it can honestly be in:
     * it has been raised, and nothing has yet happened to it.
     */
    public function test_a_snag_has_exactly_one_status_in_this_phase(): void
    {
        $this->assertSame(['open'], Snag::STATUSES);
        $this->assertSame('open', Snag::STATUS_OPEN);
    }

    /**
     * THE SCOPE FENCE, asserted as data (T-46-02-03).
     *
     * D-03 is a decision that would otherwise have to be remembered. This
     * makes it enforceable: the day Phase 47 legitimately adds `outcome`, it
     * removes that entry from this list DELIBERATELY, in a commit that says
     * why — the same anti-rot discipline as the cockpit read-only fence.
     *
     * Do not delete this test. Shorten the list, one entry at a time.
     */
    public function test_the_snags_table_carries_no_phase_47_column(): void
    {
        $phase47Columns = [
            'outcome',        // Phase 47 criterion 2 — fixed / not fixed / deferred
            'parts',          // Phase 47 criterion 4 — parts tracked per snag
            'parent_snag_id', // Phase 47 criterion 3 — a not-fixed snag opens a linked one
            'resolved_at',    // Phase 47 — resolution is a lifecycle event, not a raise
            'assigned_to',    // Phase 47 criterion 5 — sitting with a client or third party
            'cost',           // deferred for the whole v4.0 milestone (visit costs)
        ];

        foreach ($phase47Columns as $column) {
            $this->assertFalse(
                Schema::hasColumn('snags', $column),
                "snags.{$column} belongs to Phase 47 (46-CONTEXT.md D-03). Phase 46 raises a "
                . 'snag; it does not manage one. If Phase 47 is now legitimately adding this '
                . 'column, remove it from this list deliberately, in a commit that says why.'
            );
        }
    }

    /**
     * The nine columns D-03 permits, and not one more. A tenth column is a
     * scope decision, so it must fail here first.
     */
    public function test_the_snags_table_carries_exactly_the_nine_permitted_columns(): void
    {
        $expected = [
            'created_at',
            'detail',
            'id',
            'project_id',
            'raised_by_user_id',
            'room_name',
            'status',
            'title',
            'updated_at',
            'visit_id',
        ];

        $actual = Schema::getColumnListing('snags');
        sort($actual);

        $this->assertSame($expected, $actual);
    }

    /**
     * Nullable ON PURPOSE. Phase 47 criterion 1 needs a snag that may exist
     * with no visit attached; a NOT NULL column would have to be altered.
     */
    public function test_a_snag_may_exist_with_no_visit_attached(): void
    {
        $snag = Snag::factory()->create(['visit_id' => null]);

        $this->assertNull($snag->fresh()->visit_id);
        $this->assertNull($snag->fresh()->visit);
    }
}
