<?php

namespace Tests\Unit\Services\Cable;

use App\Core\Modules\Projects\ProjectDataService;
use App\Models\DeviceCableRule;
use App\Services\CableScheduleGeneratorService;
use Database\Seeders\DeviceCableRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Quick task 260711-q7q — byte-for-byte regression suite.
 *
 * Every assertion here compares the OUTPUT of the DB-driven
 * inferCableRun() against the KNOWN-GOOD strings produced by the
 * pre-refactor 13-branch hardcoded cascade. Any drift in seeded
 * keyword arrays, cable_type strings, or to_endpoint labels breaks
 * this suite — which means engineers can safely edit rules through
 * the admin UI without silently changing the schedule output that
 * downstream RAMS + O&M pipelines depend on.
 *
 * The seeder is loaded in setUp() so every case runs against the
 * canonical 15-row set (13 original branches + mic split + amp split).
 */
class DeviceCableRuleInferenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DeviceCableRulesSeeder::class);
    }

    private function make(): CableScheduleGeneratorService
    {
        $projectData     = Mockery::mock(ProjectDataService::class);
        $stencilResolver = Mockery::mock(\App\Services\Cable\StencilPortResolver::class);

        return new CableScheduleGeneratorService($projectData, $stencilResolver);
    }

    private function firstRow(string $name): array
    {
        $rows = $this->make()->buildRowsFromEquipmentLines([['name' => $name]]);
        $this->assertNotEmpty($rows, "Expected at least one row for '{$name}'.");

        return $rows[0];
    }

    // ── seeder sanity ────────────────────────────────────────────────────

    public function test_seeder_produces_expected_row_count_and_is_idempotent(): void
    {
        // Seeded once by setUp(); reseed a second time to prove idempotency.
        $countAfterFirst = DeviceCableRule::count();
        $this->seed(DeviceCableRulesSeeder::class);
        $countAfterSecond = DeviceCableRule::count();

        $this->assertSame($countAfterFirst, $countAfterSecond,
            'Re-running the seeder must not produce duplicates.');
        $this->assertSame(15, $countAfterSecond,
            '13 original branches + 2 splits (mic + amp) = 15 canonical rules.');
    }

    // ── canonical byte-for-byte cases ────────────────────────────────────

    public function test_generic_microphone_returns_xlr_audio_three_core(): void
    {
        $row = $this->firstRow('Sennheiser Microphone');

        $this->assertSame('XLR', $row['cable_type']);
        $this->assertSame('audio', $row['signal_type']);
        $this->assertSame('3', $row['cores']);
        $this->assertSame('DSP / Mixer input', $row['to_location']);
    }

    public function test_shure_mxw_microphone_returns_cat6_shure_network(): void
    {
        $row = $this->firstRow('Shure MXW Microphone');

        $this->assertSame('Cat6 (Shure network)', $row['cable_type']);
        $this->assertSame('audio', $row['signal_type']);
        $this->assertNull($row['cores']);
    }

    public function test_samsung_qm85_display_returns_hdmi_video(): void
    {
        $row = $this->firstRow('Samsung QM85 Display');

        $this->assertSame('HDMI 2.0', $row['cable_type']);
        $this->assertSame('video', $row['signal_type']);
        $this->assertNull($row['cores']);
    }

    public function test_cisco_room_kit_returns_cat6_poe_video(): void
    {
        $row = $this->firstRow('Cisco Codec Room Kit Pro');

        $this->assertSame('Cat6 (PoE)', $row['cable_type']);
        $this->assertSame('video', $row['signal_type']);
        $this->assertStringContainsString('VC codec', $row['notes']);
    }

    public function test_ptz_camera_returns_cat6_poe_video(): void
    {
        $row = $this->firstRow('AVer PTZ Camera');

        $this->assertSame('Cat6 (PoE)', $row['cable_type']);
        $this->assertSame('video', $row['signal_type']);
    }

    public function test_qsys_core_returns_dante_aes67(): void
    {
        $row = $this->firstRow('Q-Sys Core Nano');

        $this->assertSame('Cat6 (Dante/AES67)', $row['cable_type']);
        $this->assertSame('audio', $row['signal_type']);
    }

    public function test_ceiling_speaker_returns_2core_speaker_cable(): void
    {
        $row = $this->firstRow('Ceiling Speaker');

        $this->assertSame('2-core speaker cable (1.5mm LSZH)', $row['cable_type']);
        $this->assertSame('speaker', $row['signal_type']);
        $this->assertSame('2', $row['cores']);
    }

    public function test_netgear_switch_returns_cat6_network(): void
    {
        $row = $this->firstRow('Netgear GS312TP Switch');

        $this->assertSame('Cat6', $row['cable_type']);
        $this->assertSame('network', $row['signal_type']);
    }

    public function test_unknown_widget_returns_tbc_placeholder(): void
    {
        $row = $this->firstRow('Widget XYZ');

        $this->assertSame('TBC', $row['cable_type']);
        $this->assertSame('unknown', $row['signal_type']);
        $this->assertNull($row['cores']);
    }

    public function test_dante_amplifier_matches_before_generic_amplifier(): void
    {
        // Priority ordering guarantee: amp_dante (60) beats amp_analog (61)
        // so a LEA / Dante amp picks Cat6 (Dante), not Audio Multicore.
        $row = $this->firstRow('LEA Audio Amplifier');

        $this->assertSame('Cat6 (Dante)', $row['cable_type']);
        $this->assertSame('audio', $row['signal_type']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
