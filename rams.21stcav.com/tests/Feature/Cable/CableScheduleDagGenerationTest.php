<?php

namespace Tests\Feature\Cable;

use App\Core\Modules\Projects\ProjectDataService;
use App\Models\CableSchedule;
use App\Models\CableScheduleItem;
use App\Models\Device;
use App\Models\DevicePort;
use App\Models\DeviceStencil;
use App\Models\Project;
use App\Models\User;
use App\Services\Cable\StencilPortResolver;
use App\Services\CableScheduleGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * T2-B — end-to-end integration tests for signal-path DAG traversal.
 *
 * Uses a real Project + Device set + CableSchedule and calls the public
 * generate() entry point (which prefers the Device path when the project
 * has one). Every test asserts on the persisted CableScheduleItem rows,
 * not internals — so future refactors that keep the contract stable stay
 * green.
 */
class CableScheduleDagGenerationTest extends TestCase
{
    use RefreshDatabase;

    private function makeSvc(): CableScheduleGeneratorService
    {
        return new CableScheduleGeneratorService(
            app(ProjectDataService::class),
            app(StencilPortResolver::class),
        );
    }

    private function newProject(): Project
    {
        $user = User::factory()->create();
        return Project::factory()->create(['user_id' => $user->id]);
    }

    private function newSchedule(Project $project): CableSchedule
    {
        return CableSchedule::create([
            'user_id'      => $project->user_id,
            'project_id'   => $project->id,
            'project_name' => 'DAG Test',
            'status'       => CableSchedule::STATUS_DRAFT,
        ]);
    }

    public function test_dag_emits_source_processor_destination_two_edges(): void
    {
        $project = $this->newProject();

        // Source, processor, destination all resolve to signal_type=video via
        // inferCableRun (sony display, hdbaset extender, samsung display) so
        // the DAG buckets them together in the video signal_type bucket.
        Device::create([
            'project_id' => $project->id, 'room_name' => 'Boardroom',
            'description' => 'Blu-ray', 'manufacturer' => 'Sony', 'model' => 'UBP-X800 Blu-ray',
            'part_no' => 'x800', 'signal_role' => Device::ROLE_SOURCE, 'qty' => 1,
        ]);
        Device::create([
            'project_id' => $project->id, 'room_name' => 'Boardroom',
            'description' => 'HDBaseT Extender', 'manufacturer' => 'Atlona', 'model' => 'HDBaseT Extender',
            'part_no' => 'at-hdbt', 'signal_role' => Device::ROLE_PROCESSOR, 'qty' => 1,
        ]);
        Device::create([
            'project_id' => $project->id, 'room_name' => 'Boardroom',
            'description' => 'Display', 'manufacturer' => 'Samsung', 'model' => 'QM85 Display',
            'part_no' => 'qm85', 'signal_role' => Device::ROLE_DESTINATION, 'qty' => 1,
        ]);

        $schedule = $this->newSchedule($project);
        $count    = $this->makeSvc()->generate($schedule);

        // Expected chain: source → processor → destination → 2 edges.
        $this->assertSame(2, $count, 'DAG should emit 2 edges for source → processor → destination.');
        $items = CableScheduleItem::where('cable_schedule_id', $schedule->id)->orderBy('sort_order')->get();
        $this->assertCount(2, $items);
        $this->assertSame('video', $items[0]->signal_type);
        $this->assertSame('video', $items[1]->signal_type);
        foreach ($items as $item) {
            $this->assertNotNull($item->source_device_id);
            $this->assertNotNull($item->dest_device_id);
        }
    }

    public function test_dag_fan_out_multiple_destinations_shares_processor_chain(): void
    {
        $project = $this->newProject();

        Device::create([
            'project_id' => $project->id, 'room_name' => 'Auditorium',
            'description' => 'Blu-ray', 'manufacturer' => 'Sony', 'model' => 'UBP-X800',
            'part_no' => 'x800', 'signal_role' => Device::ROLE_SOURCE, 'qty' => 1,
        ]);
        Device::create([
            'project_id' => $project->id, 'room_name' => 'Auditorium',
            'description' => 'HDBaseT', 'manufacturer' => 'Atlona', 'model' => 'HDBaseT Extender',
            'part_no' => 'at-hdbt', 'signal_role' => Device::ROLE_PROCESSOR, 'qty' => 1,
        ]);
        Device::create([
            'project_id' => $project->id, 'room_name' => 'Auditorium',
            'description' => 'Left screen', 'manufacturer' => 'Samsung', 'model' => 'QM85 Left',
            'part_no' => 'qm85-l', 'signal_role' => Device::ROLE_DESTINATION, 'qty' => 1,
        ]);
        Device::create([
            'project_id' => $project->id, 'room_name' => 'Auditorium',
            'description' => 'Right screen', 'manufacturer' => 'Samsung', 'model' => 'QM85 Right',
            'part_no' => 'qm85-r', 'signal_role' => Device::ROLE_DESTINATION, 'qty' => 1,
        ]);

        $schedule = $this->newSchedule($project);
        $count    = $this->makeSvc()->generate($schedule);

        // Chain per (src × dst): source → processor → destination = 2 edges.
        // Two destinations → 4 total edges.
        $this->assertSame(4, $count);
    }

    public function test_dag_source_with_no_destination_emits_tbc_placeholder_and_logs_warning(): void
    {
        Log::spy();

        $project = $this->newProject();
        Device::create([
            'project_id' => $project->id, 'room_name' => 'Boardroom',
            'description' => 'Source', 'manufacturer' => 'Sony', 'model' => 'UBP-X800',
            'part_no' => 'x800', 'signal_role' => Device::ROLE_SOURCE, 'qty' => 1,
        ]);

        $schedule = $this->newSchedule($project);
        $count    = $this->makeSvc()->generate($schedule);

        // Source alone, no processor, no destination → single TBC edge.
        $this->assertSame(1, $count);
        $item = CableScheduleItem::where('cable_schedule_id', $schedule->id)->first();
        $this->assertNotNull($item);
        $this->assertNull($item->dest_device_id);
        $this->assertStringContainsString('TBC', $item->to_location);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($msg, $ctx) => str_contains($msg, 'signal without destination')
                && isset($ctx['room_name']) && $ctx['room_name'] === 'Boardroom'
                && isset($ctx['signal_type']))
            ->atLeast()->once();
    }

    public function test_all_unknown_signal_role_falls_through_to_flat(): void
    {
        $project = $this->newProject();

        // Two devices, both unclassified — should emit ONE row per device
        // via the flat path (not the DAG).
        Device::create([
            'project_id' => $project->id, 'room_name' => 'Meeting Room',
            'description' => 'Display', 'manufacturer' => 'Samsung', 'model' => 'QM43',
            'part_no' => 'qm43', 'signal_role' => null, 'qty' => 1,
        ]);
        Device::create([
            'project_id' => $project->id, 'room_name' => 'Meeting Room',
            'description' => 'Camera', 'manufacturer' => 'Logitech', 'model' => 'Rally',
            'part_no' => 'rally', 'signal_role' => null, 'qty' => 1,
        ]);

        $schedule = $this->newSchedule($project);
        $count    = $this->makeSvc()->generate($schedule);

        // Flat path emits one row per device → 2 rows.
        $this->assertSame(2, $count);
        // Every row has null dest_device_id (flat path invariant).
        $items = CableScheduleItem::where('cable_schedule_id', $schedule->id)->get();
        foreach ($items as $item) {
            $this->assertNull($item->dest_device_id, 'flat path never populates dest_device_id.');
            $this->assertNotNull($item->source_device_id, 'flat path always populates source_device_id.');
        }
    }

    public function test_sort_order_monotonic_across_multiple_rooms(): void
    {
        $project = $this->newProject();

        // Boardroom: unclassified device → flat path → 1 row.
        Device::create([
            'project_id' => $project->id, 'room_name' => 'Boardroom',
            'description' => 'Display', 'manufacturer' => 'Samsung', 'model' => 'QM85',
            'part_no' => 'qm85', 'signal_role' => null, 'qty' => 1,
        ]);
        // Reception: unclassified → flat → 1 row.
        Device::create([
            'project_id' => $project->id, 'room_name' => 'Reception',
            'description' => 'Display', 'manufacturer' => 'Samsung', 'model' => 'QM55',
            'part_no' => 'qm55', 'signal_role' => null, 'qty' => 1,
        ]);

        $schedule = $this->newSchedule($project);
        $this->makeSvc()->generate($schedule);

        $items = CableScheduleItem::where('cable_schedule_id', $schedule->id)
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(2, $items);
        $this->assertSame(1, (int) $items[0]->sort_order);
        $this->assertSame(2, (int) $items[1]->sort_order);
        $this->assertSame('C-001', $items[0]->cable_id);
        $this->assertSame('C-002', $items[1]->cable_id);
    }

    public function test_dag_edges_receive_t2a_port_fks_when_stencils_have_matching_signal_type(): void
    {
        $project = $this->newProject();

        // Seed a stencil with a video out-port for the source device.
        $srcStencil = DeviceStencil::create([
            'part_number' => 'src-pn', 'manufacturer' => 'Sony', 'model' => 'UBP',
            'mxgraph_xml' => '<shape/>', 'source' => DeviceStencil::SOURCE_AUTO_GENERATED,
        ]);
        $srcPort = DevicePort::create([
            'device_stencil_id' => $srcStencil->id, 'label' => 'HDMI OUT',
            'side' => DevicePort::SIDE_RIGHT, 'connector_type' => 'hdmi',
            'signal_type' => 'video', 'direction' => DevicePort::DIRECTION_OUT,
            'sort_order' => 0, 'port_id' => 'hdmi-out',
        ]);
        // Destination stencil with video in-port.
        $dstStencil = DeviceStencil::create([
            'part_number' => 'dst-pn', 'manufacturer' => 'Samsung', 'model' => 'QM85',
            'mxgraph_xml' => '<shape/>', 'source' => DeviceStencil::SOURCE_AUTO_GENERATED,
        ]);
        $dstPort = DevicePort::create([
            'device_stencil_id' => $dstStencil->id, 'label' => 'HDMI IN',
            'side' => DevicePort::SIDE_LEFT, 'connector_type' => 'hdmi',
            'signal_type' => 'video', 'direction' => DevicePort::DIRECTION_IN,
            'sort_order' => 0, 'port_id' => 'hdmi-in',
        ]);

        Device::create([
            'project_id' => $project->id, 'room_name' => 'Boardroom',
            'description' => 'Blu-ray', 'manufacturer' => 'Sony', 'model' => 'UBP-X800',
            'part_no' => 'src-pn', 'signal_role' => Device::ROLE_SOURCE, 'qty' => 1,
        ]);
        Device::create([
            'project_id' => $project->id, 'room_name' => 'Boardroom',
            'description' => 'Display', 'manufacturer' => 'Samsung', 'model' => 'QM85',
            'part_no' => 'dst-pn', 'signal_role' => Device::ROLE_DESTINATION, 'qty' => 1,
        ]);

        $schedule = $this->newSchedule($project);
        $count    = $this->makeSvc()->generate($schedule);

        // Direct source → destination = 1 edge (no processor).
        $this->assertSame(1, $count);
        $item = CableScheduleItem::where('cable_schedule_id', $schedule->id)->first();
        $this->assertNotNull($item);
        $this->assertSame($srcPort->id, $item->source_port_id, 'source port FK must be populated.');
        $this->assertSame($dstPort->id, $item->dest_port_id, 'dest port FK must be populated.');
    }
}
