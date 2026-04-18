<?php

namespace Tests\Unit\Services\Worksheet;

use App\Services\Worksheet\SafetyProfileService;
use PHPUnit\Framework\TestCase;

class SafetyProfileServiceTest extends TestCase
{
    private SafetyProfileService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new SafetyProfileService();
    }

    public function test_no_warnings_for_empty_room(): void
    {
        $this->assertSame([], $this->svc->profileRoom([], []));
    }

    public function test_large_display_via_keyword_fires_two_person_lift(): void
    {
        $out = $this->svc->profileRoom([], [
            ['name' => 'Samsung 75" QM75B Display'],
        ]);
        $this->assertNotEmpty($out);
        $this->assertStringStartsWith('Large display detected', $out[0]);
    }

    public function test_small_display_does_not_fire_two_person_lift(): void
    {
        $out = $this->svc->profileRoom([], [
            ['name' => 'Samsung 32" LCD Monitor'],
        ]);
        $this->assertEmpty(array_filter($out, fn ($w) => str_starts_with($w, 'Large display')));
    }

    public function test_large_display_via_metadata_fires_regardless_of_name(): void
    {
        $out = $this->svc->profileRoom([], [
            ['name' => 'Widget 42', 'display_size_in' => 85],
        ]);
        $this->assertStringStartsWith('Large display', $out[0]);
    }

    public function test_rack_chassis_fires_team_lift_only_in_the_room_with_the_rack(): void
    {
        // Room with the rack:
        $withRack = $this->svc->profileRoom([], [
            ['name' => '24U Equipment Rack'],
        ]);
        $this->assertNotEmpty(array_filter($withRack, fn ($w) => str_contains($w, 'Rack chassis')));

        // Room without a rack (same project in real life):
        $withoutRack = $this->svc->profileRoom([], [
            ['name' => 'Samsung 55" Display'],
        ]);
        $this->assertEmpty(array_filter($withoutRack, fn ($w) => str_contains($w, 'Rack chassis')));
    }

    public function test_ceiling_work_fires_for_ceiling_items(): void
    {
        $out = $this->svc->profileRoom([], [
            ['name' => 'Shure MXA920 Ceiling Array'],
        ]);
        $this->assertNotEmpty(array_filter($out, fn ($w) => str_starts_with($w, 'Ceiling or high-level')));
    }

    public function test_partition_sensor_fires_live_services(): void
    {
        $out = $this->svc->profileRoom([], [
            ['name' => 'Extron Partition Sensor'],
        ]);
        $this->assertNotEmpty(array_filter($out, fn ($w) => str_contains($w, 'live services')));
    }

    public function test_heavy_metadata_fires_heavy_warning(): void
    {
        $out = $this->svc->profileRoom([], [
            ['name' => 'Generic Gear', 'weight_kg' => 30],
        ]);
        $this->assertNotEmpty(array_filter($out, fn ($w) => str_contains($w, '≥ 25 kg')));
    }

    public function test_warnings_are_per_room_not_project_wide(): void
    {
        // Regression: a rack in Room A must not produce a warning in Room B.
        $roomA_items = [['name' => '42U Server Rack']];
        $roomB_items = [['name' => 'Samsung 55" Display']];

        $a = $this->svc->profileRoom(['name' => 'Room A'], $roomA_items);
        $b = $this->svc->profileRoom(['name' => 'Room B'], $roomB_items);

        $this->assertNotEmpty(array_filter($a, fn ($w) => str_contains($w, 'Rack')));
        $this->assertEmpty(   array_filter($b, fn ($w) => str_contains($w, 'Rack')));
    }
}
