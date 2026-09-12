<?php

namespace Tests\Feature\Rams;

use App\Models\RamsDocument;
use App\Services\Rams\RamsComplianceUpgradeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 29 Plan 10 Task 2 — proves the `contractor_note` backfill migration
 * adds the RULE-07 sentence only to rows whose
 * `generated_data['cdm_duty_holders']` array is missing the key entirely,
 * never overwrites an already-present value (including a deliberately
 * cleared empty string), is idempotent, and skips rows with no
 * `cdm_duty_holders` key at all.
 *
 * Mirrors BackfillCdmDutyHolderMigrationTest.php's shape: the migration
 * file is included directly (`require`, not `require_once`) and its
 * `up()` invoked against fixture rows inserted AFTER `RefreshDatabase` has
 * already run every migration once (on an empty table, so that initial run
 * is a no-op) — mirroring how a real second `php artisan migrate`
 * invocation would behave against already-migrated production data.
 *
 * @see database/migrations/2026_09_12_120000_backfill_cdm_contractor_note.php
 */
class BackfillCdmContractorNoteMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_09_12_120000_backfill_cdm_contractor_note.php';

    private function runMigration(): void
    {
        $migration = require base_path(self::MIGRATION_PATH);
        $migration->up();
    }

    public function test_row_with_cdm_duty_holders_but_no_contractor_note_key_gets_the_constant_added(): void
    {
        $doc = RamsDocument::factory()->create([
            'generated_data' => [
                'cdm_duty_holders' => [
                    'client'               => 'Acme Ltd',
                    'principal_designer'   => RamsComplianceUpgradeService::DEFAULT_PRINCIPAL_DESIGNER_NOTE,
                    'principal_contractor' => RamsComplianceUpgradeService::DEFAULT_PRINCIPAL_CONTRACTOR_NOTE,
                ],
            ],
        ]);

        $this->runMigration();

        $doc->refresh();

        $this->assertSame(
            RamsComplianceUpgradeService::DEFAULT_CONTRACTOR_NOTE,
            $doc->generated_data['cdm_duty_holders']['contractor_note'],
        );
    }

    public function test_row_with_a_hand_edited_contractor_note_is_left_untouched_byte_for_byte(): void
    {
        $doc = RamsDocument::factory()->create([
            'generated_data' => [
                'cdm_duty_holders' => [
                    'client'          => 'Acme Ltd',
                    'contractor_note' => 'Confirmed sole contractor by signed letter dated 2026-09-01.',
                ],
            ],
        ]);

        $before = DB::table('rams_documents')->where('id', $doc->id)->first();

        $this->runMigration();

        $after = DB::table('rams_documents')->where('id', $doc->id)->first();

        $this->assertSame(
            $before->generated_data,
            $after->generated_data,
            'a hand-edited contractor_note value must survive byte-identical',
        );
    }

    public function test_row_with_contractor_note_already_empty_string_is_left_untouched_not_treated_as_missing(): void
    {
        $doc = RamsDocument::factory()->create([
            'generated_data' => [
                'cdm_duty_holders' => [
                    'client'          => 'Acme Ltd',
                    'contractor_note' => '',
                ],
            ],
        ]);

        $before = DB::table('rams_documents')->where('id', $doc->id)->first();

        $this->runMigration();

        $after = DB::table('rams_documents')->where('id', $doc->id)->first();

        $this->assertSame(
            $before->generated_data,
            $after->generated_data,
            'a deliberately cleared empty-string contractor_note must not be treated as missing',
        );
    }

    public function test_running_up_twice_produces_zero_additional_changes(): void
    {
        $doc = RamsDocument::factory()->create([
            'generated_data' => [
                'cdm_duty_holders' => [
                    'client' => 'Acme Ltd',
                ],
            ],
        ]);

        $this->runMigration();
        $afterFirstRun = DB::table('rams_documents')->where('id', $doc->id)->first();

        $this->runMigration();
        $afterSecondRun = DB::table('rams_documents')->where('id', $doc->id)->first();

        $this->assertSame(
            $afterFirstRun->generated_data,
            $afterSecondRun->generated_data,
            'generated_data must be byte-identical after a second migration run',
        );
    }

    public function test_row_with_no_cdm_duty_holders_key_at_all_is_skipped_without_error(): void
    {
        $doc = RamsDocument::factory()->create([
            'generated_data' => [
                'method_statement' => ['phases' => []],
            ],
        ]);

        $before = DB::table('rams_documents')->where('id', $doc->id)->first();

        $this->runMigration();

        $after = DB::table('rams_documents')->where('id', $doc->id)->first();

        $this->assertSame($before->generated_data, $after->generated_data);
    }

    public function test_down_is_a_documented_no_op(): void
    {
        $migration = require base_path(self::MIGRATION_PATH);
        $migration->down();

        $source = file_get_contents(base_path(self::MIGRATION_PATH));
        $downBody = substr($source, (int) strpos($source, 'public function down()'));

        $this->assertStringNotContainsString('->update(', $downBody);
    }
}
