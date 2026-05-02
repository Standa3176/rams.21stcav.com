<?php

namespace Tests\Feature\Drawings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 18 Plan 01 Task 1 — asserts the rack-metadata migration adds the four
 * nullable columns onto the devices table (CRIT-06: never silent 1U guess —
 * the columns must default NULL so unknown U-heights surface as warnings, not
 * fabricated 1U placeholders).
 *
 * @see database/migrations/2026_05_02_000001_add_rack_metadata_to_devices_table.php
 */
class DeviceRackMetadataMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_devices_table_has_u_height_column(): void
    {
        $this->assertTrue(Schema::hasColumn('devices', 'u_height'));
    }

    public function test_devices_table_has_is_rack_mounted_column(): void
    {
        $this->assertTrue(Schema::hasColumn('devices', 'is_rack_mounted'));
    }

    public function test_devices_table_has_requires_ventilation_gap_above_column(): void
    {
        $this->assertTrue(Schema::hasColumn('devices', 'requires_ventilation_gap_above'));
    }

    public function test_devices_table_has_requires_ventilation_gap_below_column(): void
    {
        $this->assertTrue(Schema::hasColumn('devices', 'requires_ventilation_gap_below'));
    }
}
