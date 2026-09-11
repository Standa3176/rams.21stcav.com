<?php

namespace Tests\Feature\Rams;

use App\Models\RamsDocument;
use App\Services\Rams\RamsComplianceUpgradeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 29 Plan 05 Task 1 — proves the CDM placeholder backfill migration
 * patches both `generated_data['cdm_duty_holders']` (keyed shape,
 * exact-literal guard) and `reviewed_data['cdm']` (list-of-rows shape,
 * substring guard scoped to Principal Designer/Principal Contractor roles
 * only), never overwrites an engineer-typed real name, and is idempotent —
 * a second run is a byte-identical no-op.
 *
 * Mirrors BackfillPpeFfp2AndElectricalExclusionBulletMigrationTest.php's
 * shape: the migration file is included directly (`require`, not
 * `require_once`) and its `up()` invoked against fixture rows inserted
 * AFTER `RefreshDatabase` has already run every migration once (on an empty
 * table, so that initial run is a no-op) — mirroring how a real second
 * `php artisan migrate` invocation would behave against already-migrated
 * production data.
 *
 * @see database/migrations/2026_09_11_180000_backfill_cdm_duty_holder_placeholder.php
 * @see .planning/phases/29-cdm-duty-holder-emergency-arrangements/29-MEASUREMENT.md
 */
class BackfillCdmDutyHolderMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_09_11_180000_backfill_cdm_duty_holder_placeholder.php';

    private const RAW_PLACEHOLDER = '[To be confirmed]';

    private function runMigration(): void
    {
        $migration = require base_path(self::MIGRATION_PATH);
        $migration->up();
    }

    /** A fixture document carrying the placeholder in both columns/shapes. */
    private function fixtureDocument(): RamsDocument
    {
        return RamsDocument::factory()->create([
            'reviewed_data' => [
                'cdm' => [
                    ['role' => 'Client', 'organisation' => 'Acme Ltd', 'name' => '', 'contact' => ''],
                    ['role' => 'Principal Designer', 'organisation' => '', 'name' => self::RAW_PLACEHOLDER, 'contact' => ''],
                    ['role' => 'Principal Contractor', 'organisation' => '', 'name' => self::RAW_PLACEHOLDER, 'contact' => ''],
                ],
            ],
            'generated_data' => [
                'cdm_duty_holders' => [
                    'client'               => 'Acme Ltd',
                    'principal_designer'   => self::RAW_PLACEHOLDER,
                    'principal_contractor' => self::RAW_PLACEHOLDER,
                    'contractor'           => '21st Century AV Ltd',
                    'subcontractor'        => '21st Century AV Ltd',
                    'project_manager'      => self::RAW_PLACEHOLDER,
                    'site_supervisor'      => self::RAW_PLACEHOLDER,
                ],
            ],
        ]);
    }

    public function test_backfill_patches_both_columns_and_shapes(): void
    {
        $doc = $this->fixtureDocument();

        $this->runMigration();

        $doc->refresh();

        $rd = $doc->reviewed_data;
        $gd = $doc->generated_data;

        $this->assertSame(
            RamsComplianceUpgradeService::DEFAULT_PRINCIPAL_DESIGNER_NOTE,
            $gd['cdm_duty_holders']['principal_designer'],
        );
        $this->assertSame(
            RamsComplianceUpgradeService::DEFAULT_PRINCIPAL_CONTRACTOR_NOTE,
            $gd['cdm_duty_holders']['principal_contractor'],
        );

        // project_manager/site_supervisor are data-dependent placeholders,
        // NOT RULE-07's settled-position fields — must be left untouched.
        $this->assertSame(self::RAW_PLACEHOLDER, $gd['cdm_duty_holders']['project_manager']);
        $this->assertSame(self::RAW_PLACEHOLDER, $gd['cdm_duty_holders']['site_supervisor']);

        $rows = collect($rd['cdm'])->keyBy('role');
        $this->assertSame(
            RamsComplianceUpgradeService::DEFAULT_PRINCIPAL_DESIGNER_NOTE,
            $rows['Principal Designer']['name'],
        );
        $this->assertSame(
            RamsComplianceUpgradeService::DEFAULT_PRINCIPAL_CONTRACTOR_NOTE,
            $rows['Principal Contractor']['name'],
        );

        // Client row untouched.
        $this->assertSame('', $rows['Client']['name']);
        $this->assertSame('Acme Ltd', $rows['Client']['organisation']);
    }

    public function test_backfill_never_overwrites_an_engineer_typed_real_name(): void
    {
        $doc = RamsDocument::factory()->create([
            'reviewed_data' => [
                'cdm' => [
                    ['role' => 'Principal Contractor', 'organisation' => 'ABC Construction Ltd', 'name' => 'Jane Smith, ABC Construction Ltd', 'contact' => '07700 900000'],
                ],
            ],
            'generated_data' => [
                'cdm_duty_holders' => [
                    'principal_contractor' => 'Jane Smith, ABC Construction Ltd',
                ],
            ],
        ]);

        $before = DB::table('rams_documents')->where('id', $doc->id)->first();

        $this->runMigration();

        $after = DB::table('rams_documents')->where('id', $doc->id)->first();

        $this->assertSame(
            $before->reviewed_data,
            $after->reviewed_data,
            'reviewed_data must be byte-identical when a real name is already present',
        );
        $this->assertSame(
            $before->generated_data,
            $after->generated_data,
            'generated_data must be byte-identical when a real name is already present',
        );
    }

    public function test_backfill_is_idempotent(): void
    {
        $doc = $this->fixtureDocument();

        $this->runMigration();
        $afterFirstRun = DB::table('rams_documents')->where('id', $doc->id)->first();

        // A second run must be a byte-identical no-op — re-including the
        // migration file mirrors a real second `php artisan migrate` call.
        $this->runMigration();
        $afterSecondRun = DB::table('rams_documents')->where('id', $doc->id)->first();

        $this->assertSame(
            $afterFirstRun->reviewed_data,
            $afterSecondRun->reviewed_data,
            'reviewed_data must be byte-identical after a second migration run',
        );
        $this->assertSame(
            $afterFirstRun->generated_data,
            $afterSecondRun->generated_data,
            'generated_data must be byte-identical after a second migration run',
        );
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
