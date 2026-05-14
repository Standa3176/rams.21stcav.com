<?php

namespace Tests\Feature\Drawings;

use App\Models\CableSchedule;
use App\Models\CableScheduleItem;
use App\Models\Device;
use App\Models\DevicePort;
use App\Models\DeviceStencil;
use App\Models\Project;
use App\Services\Drawings\SheetPaginator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 23 Plan 04 — DRAW-47 SheetPaginator
 *
 * Tests:
 *   - system_overview always emits (AV-201)
 *   - Sub-sheet emits ONLY when BOTH thresholds met (D-06):
 *       cables-of-signal >= min_cables_per_signal AND
 *       distinct-devices-touching-signal >= min_devices_touching_signal
 *   - Project.metadata.force_sheets tinker override forces sub-sheet
 *     regardless of threshold (D-06 deferred-UI escape hatch)
 *   - force_sheets validation: non-array ignored; unknown entries ignored
 *   - Sheet order deterministic: system_overview, audio, video, control, network
 *
 * @see app/Services/Drawings/SheetPaginator.php
 * @see .planning/phases/23-xten-av-style-renderer/23-CONTEXT.md D-06
 */
class SheetPaginatorTest extends TestCase
{
    use RefreshDatabase;

    private SheetPaginator $paginator;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-05-14 12:00:00');
        $this->paginator = app(SheetPaginator::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Build a project with $cableCount cables spread across $deviceCount devices,
     * all carrying ports with $signal signal_type.
     */
    private function makeProjectWithSignal(string $signal, int $cableCount, int $deviceCount): Project
    {
        $project = Project::factory()->create();

        $stencil = DeviceStencil::create([
            'part_number'  => "stencil-{$signal}",
            'manufacturer' => 'Test',
            'model'        => 'M',
            'mxgraph_xml'  => '<shape><background><rect x="0" y="0" w="220" h="140"/></background></shape>',
            'source'       => DeviceStencil::SOURCE_ENGINEER_CURATED,
        ]);

        $port = DevicePort::create([
            'device_stencil_id' => $stencil->id,
            'port_id'           => 'p1',
            'label'             => 'P1',
            'side'              => DevicePort::SIDE_LEFT,
            'connector_type'    => 'rj45',
            'signal_type'       => $signal,
            'direction'         => DevicePort::DIRECTION_IO,
            'sort_order'        => 1,
        ]);

        $devices = [];
        for ($i = 1; $i <= $deviceCount; $i++) {
            $devices[] = Device::create([
                'project_id'   => $project->id,
                'description'  => "Test Device {$i}",
                'manufacturer' => 'Test',
                'model'        => 'M',
                'part_no'      => "stencil-{$signal}-{$i}",
                'qty'          => 1,
            ]);
        }

        $schedule = CableSchedule::create([
            'user_id'         => $project->user_id,
            'project_id'      => $project->id,
            'project_ref'     => $project->quote_reference ?? 'Q-FIX',
            'project_name'    => $project->name,
            'client_name'     => $project->client_name,
            'source_filename' => 'fixture.xlsx',
            'status'          => CableSchedule::STATUS_DRAFT,
        ]);

        for ($i = 0; $i < $cableCount; $i++) {
            $src = $devices[$i % $deviceCount];
            $dst = $devices[($i + 1) % $deviceCount];
            CableScheduleItem::create([
                'cable_schedule_id' => $schedule->id,
                'source_device_id'  => $src->id,
                'source_port_id'    => $port->id,
                'dest_device_id'    => $dst->id,
                'dest_port_id'      => $port->id,
                'cable_id'          => "C-{$signal}-{$i}",
                'cable_type'        => 'CAT6',
                'from_location'     => 'src',
                'to_location'       => 'dst',
                'sort_order'        => $i,
            ]);
        }

        return $project->fresh();
    }

    public function test_empty_project_emits_one_diagram(): void
    {
        $project = Project::factory()->create();
        $sheets = $this->paginator->classify($project);

        $this->assertCount(1, $sheets);
        $this->assertSame('system_overview', $sheets[0]['key']);
        $this->assertSame('AV-201', $sheets[0]['sheet_number']);
        $this->assertNull($sheets[0]['signal_filter']);
    }

    public function test_below_cable_threshold_no_sub_sheet(): void
    {
        // 4 cables < 5 required — sub-sheet NOT emitted even though device count is met.
        $project = $this->makeProjectWithSignal('audio', cableCount: 4, deviceCount: 3);
        $sheets = $this->paginator->classify($project);

        $this->assertSame(['system_overview'], array_column($sheets, 'key'));
    }

    public function test_below_device_threshold_no_sub_sheet(): void
    {
        // 2 devices < 3 required — sub-sheet NOT emitted even though cable count is met.
        $project = $this->makeProjectWithSignal('audio', cableCount: 5, deviceCount: 2);
        $sheets = $this->paginator->classify($project);

        $this->assertSame(['system_overview'], array_column($sheets, 'key'));
    }

    public function test_above_threshold_emits_sub_sheet(): void
    {
        // BOTH thresholds met → sub-sheet emits.
        $project = $this->makeProjectWithSignal('audio', cableCount: 5, deviceCount: 3);
        $sheets = $this->paginator->classify($project);

        $this->assertSame(['system_overview', 'audio'], array_column($sheets, 'key'));

        $audio = collect($sheets)->firstWhere('key', 'audio');
        $this->assertSame('AV-202', $audio['sheet_number']);
        $this->assertSame('audio', $audio['signal_filter']);
    }

    public function test_force_sheets_metadata_override(): void
    {
        // Below threshold — but force_sheets says emit audio + control anyway.
        $project = Project::factory()->create([
            'metadata' => ['force_sheets' => ['audio', 'control']],
        ]);

        $sheets = $this->paginator->classify($project);
        $keys = array_column($sheets, 'key');

        $this->assertContains('system_overview', $keys);
        $this->assertContains('audio', $keys);
        $this->assertContains('control', $keys);
        $this->assertNotContains('video', $keys);
        $this->assertNotContains('network', $keys);
    }

    public function test_force_sheets_invalid_entry_is_ignored(): void
    {
        // Unknown signal type silently dropped — only 'audio' wins through.
        $project = Project::factory()->create([
            'metadata' => ['force_sheets' => ['audio', 'made-up-signal']],
        ]);

        $sheets = $this->paginator->classify($project);
        $keys = array_column($sheets, 'key');

        $this->assertContains('audio', $keys);
        $this->assertNotContains('made-up-signal', $keys);
    }

    public function test_force_sheets_non_array_metadata_is_ignored(): void
    {
        // String where array expected — logged + ignored, no crash.
        $project = Project::factory()->create([
            'metadata' => ['force_sheets' => 'audio'],
        ]);

        $sheets = $this->paginator->classify($project);

        $this->assertSame(['system_overview'], array_column($sheets, 'key'));
    }

    public function test_sheet_order_is_deterministic(): void
    {
        // Force ALL four sub-sheets via metadata in a shuffled order — output
        // MUST honour canonical sequence: system_overview, audio, video, control, network.
        $project = Project::factory()->create([
            'metadata' => ['force_sheets' => ['network', 'audio', 'control', 'video']],
        ]);

        $sheets = $this->paginator->classify($project);

        $this->assertSame(
            ['system_overview', 'audio', 'video', 'control', 'network'],
            array_column($sheets, 'key'),
        );
    }
}
