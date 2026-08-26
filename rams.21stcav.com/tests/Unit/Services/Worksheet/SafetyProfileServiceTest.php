<?php

namespace Tests\Unit\Services\Worksheet;

use App\Services\Rams\DisplayLiftPolicy;
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

    public function test_large_display_via_keyword_fires_two_operative_band(): void
    {
        // 75" resolves to DisplayLiftPolicy's 55"-90" band (2 operatives
        // minimum) — the shared band's sentence, not a hardcoded
        // "Large display detected" string (RULE-02/D-04 parity).
        $out = $this->svc->profileRoom([], [
            ['name' => 'Samsung 75" QM75B Display'],
        ]);
        $this->assertNotEmpty($out);
        $this->assertSame(DisplayLiftPolicy::forSize(75.0)['sentence'], $out[0]);
    }

    public function test_genuine_small_display_now_fires_single_operative_band(): void
    {
        // D-04: a genuine 32" display is NOT a ≤14" control panel, so under
        // the corrected bands it now DOES produce a warning — the
        // 1-operative band, not the old "no warning at all" behaviour this
        // test previously pinned. A worksheet and a RAMS document must never
        // disagree about which displays need a stated team size.
        $out = $this->svc->profileRoom([], [
            ['name' => 'Samsung 32" LCD Monitor'],
        ]);
        $this->assertNotEmpty($out);
        $this->assertSame(DisplayLiftPolicy::forSize(32.0)['sentence'], $out[0]);
    }

    public function test_large_display_via_metadata_fires_regardless_of_name(): void
    {
        $out = $this->svc->profileRoom([], [
            ['name' => 'Widget 42', 'display_size_in' => 85],
        ]);
        $this->assertSame(DisplayLiftPolicy::forSize(85.0)['sentence'], $out[0]);
    }

    public function test_above_90_display_fires_three_operative_band(): void
    {
        $out = $this->svc->profileRoom([], [
            ['name' => 'Samsung 96" Display'],
        ]);
        $this->assertNotEmpty($out);
        $this->assertSame(DisplayLiftPolicy::forSize(96.0)['sentence'], $out[0]);
        $this->assertStringNotContainsString('minimum 2-person lift', $out[0]);
    }

    public function test_above_90_display_via_metadata_fires_three_operative_band(): void
    {
        // Metadata-first path (display_size_in => 95, no size keyword needed
        // in the name) resolves the same 3-operative band as the keyword
        // path — proving metadata-first still works after the D-04 change.
        $out = $this->svc->profileRoom([], [
            ['name' => 'Boardroom Video Wall', 'display_size_in' => 95],
        ]);
        $this->assertNotEmpty($out);
        $this->assertSame(DisplayLiftPolicy::forSize(95.0)['sentence'], $out[0]);
    }

    public function test_small_control_panel_still_produces_no_warning(): void
    {
        // The ≤14" scheduling/touch/control-panel exclusion is mirrored from
        // suggestHandlingMethod() (Open Question 1, resolved in favour of
        // consistency) — a genuine control panel still gets no row at all,
        // unlike a genuine small display which now does.
        $out = $this->svc->profileRoom([], [
            ['name' => '10.1in room scheduling touch panel'],
        ]);
        $this->assertSame([], $out);
        $this->assertNull(DisplayLiftPolicy::forSize(10.1, true));
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
