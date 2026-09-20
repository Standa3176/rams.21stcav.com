<?php

namespace Tests\Unit\Models;

use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 46 Plan 01 — the visit lifecycle, as executable fact.
 *
 * Task 1 covers the schema: seven columns, and the two that are ABSENT on
 * purpose. The state machine follows in Task 2.
 */
class VisitLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /** The seven columns, exactly — one per act a human performed. */
    private const LIFECYCLE_COLUMNS = [
        'sent_at',
        'accepted_at',
        'sent_back_at',
        'send_back_reason',
        'accepted_by_user_id',
        'created_by_user_id',
        'rooms_in_scope',
    ];

    // -- Task 1: the schema --------------------------------------------------

    public function test_the_seven_lifecycle_columns_exist(): void
    {
        foreach (self::LIFECYCLE_COLUMNS as $column) {
            $this->assertTrue(
                Schema::hasColumn('visits', $column),
                "visits.{$column} is required by the Phase 46 lifecycle."
            );
        }
    }

    public function test_there_is_no_returned_at_and_no_scope_locked_at_column(): void
    {
        // DERIVED OVER STORED. A return is recorded by the engineer on their
        // own record (SiteSurvey.submitted_at / the latest WorksheetSignoff);
        // a second copy here would go stale on a re-signoff and then
        // contradict its own source. The lock is a CONSEQUENCE of a return,
        // not an independent fact. See the migration docblock before adding
        // either of these.
        $this->assertFalse(
            Schema::hasColumn('visits', 'returned_at'),
            'A stored returned_at would be a second source of truth for "it came back".'
        );

        $this->assertFalse(
            Schema::hasColumn('visits', 'scope_locked_at'),
            'The lock is derived from the return — storing it lets it contradict its own cause.'
        );
    }

    public function test_every_new_column_is_nullable_so_a_backfilled_visit_asserts_nothing(): void
    {
        $visit = Visit::factory()->backfilledFromWorksheet()->create();

        $visit->refresh();

        foreach (self::LIFECYCLE_COLUMNS as $column) {
            $this->assertNull(
                $visit->getAttribute($column),
                "visits.{$column} must default to NULL — the 24 backfilled visits on live ".
                'performed none of these acts and the schema must not claim otherwise.'
            );
        }

        $this->assertSame(Visit::STATUS_COMPLETED, $visit->status);
        $this->assertTrue($visit->isBackfilled());
    }

    public function test_the_existing_source_unique_index_still_guards_the_backfill(): void
    {
        $first = Visit::factory()->create([
            'source_type' => Visit::SOURCE_WORKSHEET,
            'source_id'   => 4242,
        ]);

        $this->assertNotNull($first->id);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Visit::factory()->create([
            'source_type' => Visit::SOURCE_WORKSHEET,
            'source_id'   => 4242,
        ]);
    }
}
