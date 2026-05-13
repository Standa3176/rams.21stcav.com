<?php

namespace Tests\Feature\Console;

use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 22.1 Plan 03 Task 1 — Feature tests for the
 * rams:backfill-room-overview-summary artisan command.
 *
 * Mirrors the Phase 22 BackfillCablePortFksCommandTest precedent:
 * - dry-run default (no writes without --apply)
 * - --apply persists changes inside a DB::transaction
 * - per-row 4-outcome category report (backfilled / already-set /
 *   both-set-no-action / neither-set) + apply-time rows-written counter
 * - idempotent: re-running --apply produces zero new writes
 * - T-22.1-05: SQL injection via rams_id arg neutralised by (int) cast
 *
 * Backfill semantics per CONTEXT.md D-07 + Plan 03 <behavior>:
 *   - works_summary empty + summary non-empty → copy summary → works_summary
 *     ('backfilled', written when --apply)
 *   - works_summary non-empty + summary empty → no-op ('already-set')
 *   - both non-empty → no overwrite, both keys preserved verbatim
 *     ('both-set-no-action', logged WARNING)
 *   - both empty → no-op ('neither-set')
 *
 * After a successful backfill the legacy `summary` field is left in place
 * (the schema-trim happens in a later plan AFTER the command has run on
 * production). Therefore re-running --apply on already-backfilled rows
 * lands them in `both-set-no-action`, NOT `already-set`. The test
 * `test_idempotency_second_apply_run_writes_zero_rows` locks this.
 *
 * @see app/Console/Commands/BackfillRoomOverviewSummaryCommand.php
 * @see app/Services/Rams/RoomOverviewSummaryBackfillService.php
 * @see tests/Feature/Console/BackfillCablePortFksCommandTest.php (template)
 */
class BackfillRoomOverviewSummaryCommandTest extends TestCase
{
    use RefreshDatabase;

    // ══════════════════════════════════════════════════════════════════════════
    // FIXTURE BUILDERS
    // ══════════════════════════════════════════════════════════════════════════

    private function makeRamsWithRooms(array $rooms): RamsDocument
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        return RamsDocument::factory()->create([
            'user_id'       => $user->id,
            'project_id'    => $project->id,
            'reviewed_data' => [
                'room_overviews' => $rooms,
            ],
            'status' => RamsDocument::STATUS_AWAITING_REVIEW,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // CATEGORY: dry-run default
    // ══════════════════════════════════════════════════════════════════════════

    public function test_dry_run_default_no_writes_to_db_when_only_summary_populated(): void
    {
        $rams = $this->makeRamsWithRooms([
            ['room' => 'Boardroom', 'overview' => 'PM prose.', 'works_summary' => '', 'summary' => '- Legacy bullet'],
        ]);

        $this->artisan('rams:backfill-room-overview-summary')
            ->expectsOutputToContain('[DRY RUN]')
            ->expectsOutputToContain('backfilled: 1')
            ->assertExitCode(0);

        $fresh = $rams->fresh();
        $this->assertSame('', $fresh->reviewed_data['room_overviews'][0]['works_summary'],
            'Dry-run must NOT write works_summary to the DB.');
        $this->assertSame('- Legacy bullet', $fresh->reviewed_data['room_overviews'][0]['summary'],
            'Dry-run must NOT touch the legacy summary field.');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // CATEGORY: backfilled (the primary write branch)
    // ══════════════════════════════════════════════════════════════════════════

    public function test_apply_copies_summary_to_works_summary_when_works_summary_empty(): void
    {
        $rams = $this->makeRamsWithRooms([
            ['room' => 'Boardroom', 'overview' => 'PM prose.', 'works_summary' => '', 'summary' => "- Install 98\" display\n- Deploy Crestron Flex"],
        ]);

        $this->artisan('rams:backfill-room-overview-summary', ['--apply' => true])
            ->expectsOutputToContain('APPLYING writes')
            ->expectsOutputToContain('backfilled: 1')
            ->expectsOutputToContain('rows-written: 1')
            ->assertExitCode(0);

        $fresh = $rams->fresh();
        $this->assertSame("- Install 98\" display\n- Deploy Crestron Flex",
            $fresh->reviewed_data['room_overviews'][0]['works_summary'],
            'Backfilled works_summary must match the legacy summary verbatim.');
        $this->assertSame("- Install 98\" display\n- Deploy Crestron Flex",
            $fresh->reviewed_data['room_overviews'][0]['summary'],
            'Legacy summary field must be preserved verbatim (schema trim happens in Plan 06).');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // CATEGORY: already-set
    // ══════════════════════════════════════════════════════════════════════════

    public function test_already_set_category_when_only_works_summary_populated_no_legacy_summary(): void
    {
        $rams = $this->makeRamsWithRooms([
            ['room' => 'Cinnamon', 'overview' => 'Prose.', 'works_summary' => '- Already done', 'summary' => ''],
        ]);

        $this->artisan('rams:backfill-room-overview-summary', ['--apply' => true])
            ->expectsOutputToContain('already-set: 1')
            ->expectsOutputToContain('rows-written: 0')
            ->assertExitCode(0);

        $fresh = $rams->fresh();
        $this->assertSame('- Already done', $fresh->reviewed_data['room_overviews'][0]['works_summary'],
            'already-set must NOT overwrite the works_summary field.');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // CATEGORY: both-set-no-action (the no-data-loss guard)
    // ══════════════════════════════════════════════════════════════════════════

    public function test_both_set_category_preserves_both_keys_no_overwrite(): void
    {
        $rams = $this->makeRamsWithRooms([
            ['room' => 'Cafe', 'overview' => 'Prose.', 'works_summary' => '- New canonical bullet', 'summary' => '- Legacy ambiguous bullet'],
        ]);

        $this->artisan('rams:backfill-room-overview-summary', ['--apply' => true])
            ->expectsOutputToContain('both-set-no-action: 1')
            ->expectsOutputToContain('rows-written: 0')
            ->assertExitCode(0);

        $fresh = $rams->fresh();
        $this->assertSame('- New canonical bullet', $fresh->reviewed_data['room_overviews'][0]['works_summary'],
            'both-set-no-action: works_summary must NOT be overwritten by the legacy summary.');
        $this->assertSame('- Legacy ambiguous bullet', $fresh->reviewed_data['room_overviews'][0]['summary'],
            'both-set-no-action: legacy summary must be preserved verbatim — no data loss.');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // CATEGORY: neither-set
    // ══════════════════════════════════════════════════════════════════════════

    public function test_neither_set_category_no_action_no_log_warning(): void
    {
        $rams = $this->makeRamsWithRooms([
            ['room' => 'EmptyRoom', 'overview' => 'Only the overview is set.', 'works_summary' => '', 'summary' => ''],
        ]);

        $this->artisan('rams:backfill-room-overview-summary', ['--apply' => true])
            ->expectsOutputToContain('neither-set: 1')
            ->expectsOutputToContain('rows-written: 0')
            ->assertExitCode(0);

        $fresh = $rams->fresh();
        $this->assertSame('', $fresh->reviewed_data['room_overviews'][0]['works_summary']);
        $this->assertSame('', $fresh->reviewed_data['room_overviews'][0]['summary']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // CATEGORY: idempotency (second --apply == zero writes)
    // ══════════════════════════════════════════════════════════════════════════

    public function test_idempotency_second_apply_run_writes_zero_rows(): void
    {
        $rams = $this->makeRamsWithRooms([
            ['room' => 'Boardroom', 'overview' => '', 'works_summary' => '', 'summary' => '- Legacy bullet to backfill'],
        ]);

        // First run: backfills.
        $this->artisan('rams:backfill-room-overview-summary', ['--apply' => true])
            ->expectsOutputToContain('backfilled: 1')
            ->expectsOutputToContain('rows-written: 1')
            ->assertExitCode(0);

        // Second run: works_summary and summary are now BOTH populated — falls
        // into both-set-no-action. NO new writes.
        $this->artisan('rams:backfill-room-overview-summary', ['--apply' => true])
            ->expectsOutputToContain('backfilled: 0')
            ->expectsOutputToContain('both-set-no-action: 1')
            ->expectsOutputToContain('rows-written: 0')
            ->assertExitCode(0);

        $fresh = $rams->fresh();
        $this->assertSame('- Legacy bullet to backfill', $fresh->reviewed_data['room_overviews'][0]['works_summary']);
        $this->assertSame('- Legacy bullet to backfill', $fresh->reviewed_data['room_overviews'][0]['summary']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // CATEGORY: positional rams_id argument scoping
    // ══════════════════════════════════════════════════════════════════════════

    public function test_rams_id_argument_scopes_to_single_record(): void
    {
        $a = $this->makeRamsWithRooms([
            ['room' => 'A', 'overview' => '', 'works_summary' => '', 'summary' => '- A bullet'],
        ]);
        $b = $this->makeRamsWithRooms([
            ['room' => 'B', 'overview' => '', 'works_summary' => '', 'summary' => '- B bullet'],
        ]);

        $this->artisan('rams:backfill-room-overview-summary', [
            'rams'    => $a->id,
            '--apply' => true,
        ])
            ->expectsOutputToContain('backfilled: 1')
            ->expectsOutputToContain('rows-written: 1')
            ->assertExitCode(0);

        // Record A: backfilled.
        $this->assertSame('- A bullet', $a->fresh()->reviewed_data['room_overviews'][0]['works_summary']);

        // Record B: untouched.
        $this->assertSame('', $b->fresh()->reviewed_data['room_overviews'][0]['works_summary'],
            'Record B must not be modified when command scopes to record A.');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // T-22.1-05: SQL injection via rams_id arg neutralised by (int) cast
    // ══════════════════════════════════════════════════════════════════════════

    public function test_t22_1_05_sql_injection_via_rams_id_arg_neutralised_by_int_cast(): void
    {
        $this->makeRamsWithRooms([
            ['room' => 'X', 'overview' => '', 'works_summary' => '', 'summary' => '- bullet'],
        ]);

        // Junk after the first non-numeric char is silently dropped by PHP's int cast.
        // "5; DROP TABLE rams_documents;" → (int) === 5.
        $this->artisan('rams:backfill-room-overview-summary', [
            'rams'    => '5; DROP TABLE rams_documents;',
            '--apply' => true,
        ])->assertExitCode(0);

        $this->assertTrue(Schema::hasTable('rams_documents'),
            'T-22.1-05: rams_documents table must survive a SQL-injection arg payload.');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Summary line format lock
    // ══════════════════════════════════════════════════════════════════════════

    public function test_summary_line_contains_all_four_category_labels(): void
    {
        $this->makeRamsWithRooms([
            ['room' => 'A', 'overview' => '', 'works_summary' => '', 'summary' => '- backfilled'],
            ['room' => 'B', 'overview' => '', 'works_summary' => '- canonical', 'summary' => ''],
            ['room' => 'C', 'overview' => '', 'works_summary' => '- both1',     'summary' => '- both2'],
            ['room' => 'D', 'overview' => '', 'works_summary' => '',            'summary' => ''],
        ]);

        $this->artisan('rams:backfill-room-overview-summary')
            ->expectsOutputToContain('backfilled:')
            ->expectsOutputToContain('already-set:')
            ->expectsOutputToContain('both-set-no-action:')
            ->expectsOutputToContain('neither-set:')
            ->expectsOutputToContain('rows-written:')
            ->assertExitCode(0);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Empty-DB happy path
    // ══════════════════════════════════════════════════════════════════════════

    public function test_empty_database_returns_success_with_no_records_message(): void
    {
        $this->artisan('rams:backfill-room-overview-summary')
            ->expectsOutputToContain('No rams_documents found.')
            ->assertExitCode(0);
    }
}
