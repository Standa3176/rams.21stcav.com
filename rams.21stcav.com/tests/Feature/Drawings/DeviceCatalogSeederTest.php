<?php

namespace Tests\Feature\Drawings;

use App\Models\Device;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DeviceCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 18 Plan 01 — DeviceCatalogSeeder behaviour:
 *
 *   1. Devices whose part_no matches a JSON pack entry get u_height +
 *      is_rack_mounted populated.
 *   2. Devices NOT in the pack stay at NULL u_height (CRIT-06: never silent
 *      1U guess).
 *   3. Re-running the seeder is idempotent (same end state).
 *   4. Lookup is case-insensitive trimmed (mirrors the part_no normalisation
 *      in DrawingDataResolverService).
 *
 * @see database/seeders/DeviceCatalogSeeder.php
 * @see app/Services/Drawings/DeviceCatalogService.php
 */
class DeviceCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    private function makeProject(): Project
    {
        $user = User::factory()->create();

        return Project::create([
            'user_id' => $user->id,
            'name' => 'Catalog Seeder Test',
            'ref' => 'CAT-TEST-001',
            'client_name' => 'Test Client Ltd',
            'site_address' => '1 Catalog Street, London',
            'status' => 'quote_imported',
        ]);
    }

    public function test_seeder_populates_rack_metadata_for_known_part_no(): void
    {
        $project = $this->makeProject();
        $device = Device::create([
            'project_id' => $project->id,
            'description' => 'Crestron AirMedia 3200',
            'part_no' => 'AM-3200-GV',
        ]);

        $this->assertNull($device->fresh()->u_height);
        $this->assertNull($device->fresh()->is_rack_mounted);

        $this->seed(DeviceCatalogSeeder::class);

        $device->refresh();
        $this->assertSame('1.00', (string) $device->u_height);
        $this->assertTrue((bool) $device->is_rack_mounted);
    }

    public function test_seeder_leaves_unknown_part_no_with_null_u_height(): void
    {
        $project = $this->makeProject();
        $device = Device::create([
            'project_id' => $project->id,
            'description' => 'Unknown SKU device',
            'part_no' => 'UNKNOWN-XYZ-999',
        ]);

        $this->seed(DeviceCatalogSeeder::class);

        $device->refresh();
        $this->assertNull($device->u_height);
        $this->assertNull($device->is_rack_mounted);
    }

    public function test_seeder_is_idempotent(): void
    {
        $project = $this->makeProject();
        $device = Device::create([
            'project_id' => $project->id,
            'description' => 'Crestron AirMedia 3200',
            'part_no' => 'AM-3200-GV',
        ]);

        $this->seed(DeviceCatalogSeeder::class);
        $afterFirst = $device->fresh();

        $this->seed(DeviceCatalogSeeder::class);
        $afterSecond = $device->fresh();

        $this->assertSame((string) $afterFirst->u_height, (string) $afterSecond->u_height);
        $this->assertSame($afterFirst->is_rack_mounted, $afterSecond->is_rack_mounted);
        $this->assertSame(1, Device::where('part_no', 'AM-3200-GV')->count(),
            'idempotent: no duplicate rows after re-running seeder');
    }

    public function test_seeder_match_is_case_insensitive_and_trimmed(): void
    {
        $project = $this->makeProject();
        $device = Device::create([
            'project_id' => $project->id,
            'description' => 'Crestron AirMedia 3200 (lowercase part_no)',
            'part_no' => '  am-3200-gv  ',
        ]);

        $this->seed(DeviceCatalogSeeder::class);

        $device->refresh();
        $this->assertSame('1.00', (string) $device->u_height,
            'lookup must be case-insensitive trimmed (mirrors DrawingDataResolverService normalisation)');
        $this->assertTrue((bool) $device->is_rack_mounted);
    }

    public function test_catalog_service_lookup_returns_null_for_unknown_part(): void
    {
        $svc = app(\App\Services\Drawings\DeviceCatalogService::class);
        $this->assertNull($svc->lookupByPartNo('UNKNOWN-XYZ-999'));
        $this->assertNull($svc->lookupByPartNo(null));
        $this->assertNull($svc->lookupByPartNo(''));
    }

    public function test_catalog_service_lookup_returns_array_for_known_part(): void
    {
        $svc = app(\App\Services\Drawings\DeviceCatalogService::class);
        $row = $svc->lookupByPartNo('AM-3200-GV');

        $this->assertIsArray($row);
        $this->assertArrayHasKey('u_height', $row);
        $this->assertArrayHasKey('is_rack_mounted', $row);
        $this->assertArrayHasKey('current_draw_a', $row);
        $this->assertArrayHasKey('weight_kg', $row);
        $this->assertArrayHasKey('btu_per_hour', $row);
        $this->assertSame(1.0, $row['u_height']);
        $this->assertTrue($row['is_rack_mounted']);
    }
}
