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

    // ─────────────────────────────────────────────────────────────────────────
    // T2-B-ext — cross-room signal-graph chains
    // ─────────────────────────────────────────────────────────────────────────

    public function test_cross_room_dsp_serves_local_amp_speaker_chain(): void
    {
        $project = $this->newProject();

        // Boardroom: local audio processor (LEA amp, hits 'amplifier' → audio)
        // + local audio destination (Biamp Tesira DSP, hits 'biamp' → audio).
        Device::create([
            'project_id' => $project->id, 'room_name' => 'Boardroom',
            'description' => 'Amp', 'manufacturer' => 'LEA', 'model' => 'LEA Audio Amplifier',
            'part_no' => 'lea-amp', 'signal_role' => Device::ROLE_PROCESSOR, 'qty' => 1,
        ]);
        Device::create([
            'project_id' => $project->id, 'room_name' => 'Boardroom',
            'description' => 'Zone Mixer', 'manufacturer' => 'Biamp', 'model' => 'Tesira Zone Mixer DSP',
            'part_no' => 'biamp-mix', 'signal_role' => Device::ROLE_DESTINATION, 'qty' => 1,
        ]);
        // Comms Room: audio source (Q-Sys Core DSP, hits 'dsp'/'q-sys' → audio).
        Device::create([
            'project_id' => $project->id, 'room_name' => 'Comms Room',
            'description' => 'DSP', 'manufacturer' => 'Q-Sys', 'model' => 'Core 8 Flex DSP',
            'part_no' => 'qsys-core', 'signal_role' => Device::ROLE_SOURCE, 'qty' => 1,
        ]);

        $schedule = $this->newSchedule($project);
        $this->makeSvc()->generate($schedule);

        $items = CableScheduleItem::where('cable_schedule_id', $schedule->id)->get();

        // Boardroom's audio bucket completes via the central Q-Sys DSP: chain
        // Q-Sys DSP → LEA amp → Biamp DSP = 2 edges. Edge 1 is cross-room.
        $crossRoomRow = $items->first(fn ($i) =>
            str_contains((string) ($i->notes ?? ''), 'Cross-room: Comms Room → Boardroom')
        );
        $this->assertNotNull($crossRoomRow,
            'Boardroom must emit a cross-room row for the Comms Room DSP (central fallback).');

        // Local amp → biamp-dsp edge exists WITHOUT cross-room prefix.
        $boardroomLocalRows = $items->filter(fn ($i) =>
            str_contains((string) ($i->from_location ?? ''), 'Boardroom')
            && ! str_contains((string) ($i->notes ?? ''), 'Cross-room:')
        );
        $this->assertGreaterThan(0, $boardroomLocalRows->count(),
            'Same-room edges within Boardroom must not carry a Cross-room prefix.');
    }

    public function test_project_with_no_central_room_uses_pure_local_dag(): void
    {
        $project = $this->newProject();

        // Boardroom + Reception — neither name substring-matches
        // CENTRAL_ROOM_KEYWORDS ('comms', 'rack', 'central', 'server',
        // 'plant', 'equipment room'). Both are self-contained rooms.
        Device::create([
            'project_id' => $project->id, 'room_name' => 'Boardroom',
            'description' => 'Blu-ray', 'manufacturer' => 'Sony', 'model' => 'UBP-X800',
            'part_no' => 'x800', 'signal_role' => Device::ROLE_SOURCE, 'qty' => 1,
        ]);
        Device::create([
            'project_id' => $project->id, 'room_name' => 'Boardroom',
            'description' => 'Display', 'manufacturer' => 'Samsung', 'model' => 'QM85 Display',
            'part_no' => 'qm85', 'signal_role' => Device::ROLE_DESTINATION, 'qty' => 1,
        ]);
        Device::create([
            'project_id' => $project->id, 'room_name' => 'Reception',
            'description' => 'Blu-ray', 'manufacturer' => 'Sony', 'model' => 'UBP-X700',
            'part_no' => 'x700', 'signal_role' => Device::ROLE_SOURCE, 'qty' => 1,
        ]);
        Device::create([
            'project_id' => $project->id, 'room_name' => 'Reception',
            'description' => 'Display', 'manufacturer' => 'Samsung', 'model' => 'QM55 Display',
            'part_no' => 'qm55', 'signal_role' => Device::ROLE_DESTINATION, 'qty' => 1,
        ]);

        $schedule = $this->newSchedule($project);
        $count    = $this->makeSvc()->generate($schedule);

        // Two rooms, each source → destination = 1 edge → 2 edges total.
        // Zero central-room matches → identical to pre-ext output.
        $this->assertSame(2, $count,
            'No central room → per-room graphs must produce pre-ext row count.');

        $items = CableScheduleItem::where('cable_schedule_id', $schedule->id)->get();
        foreach ($items as $item) {
            $this->assertStringNotContainsString('Cross-room:', (string) ($item->notes ?? ''),
                'No central room → no row should carry the Cross-room prefix.');
        }
    }

    public function test_local_source_wins_over_central_source(): void
    {
        $project = $this->newProject();

        // Boardroom: local video source (Sony UBP) AND local video destination.
        Device::create([
            'project_id' => $project->id, 'room_name' => 'Boardroom',
            'description' => 'Blu-ray', 'manufacturer' => 'Sony', 'model' => 'UBP-X800',
            'part_no' => 'x800', 'signal_role' => Device::ROLE_SOURCE, 'qty' => 1,
        ]);
        Device::create([
            'project_id' => $project->id, 'room_name' => 'Boardroom',
            'description' => 'Display', 'manufacturer' => 'Samsung', 'model' => 'QM85 Display',
            'part_no' => 'qm85', 'signal_role' => Device::ROLE_DESTINATION, 'qty' => 1,
        ]);
        // Comms Room ALSO has a video source (would collide on signal_type=video).
        Device::create([
            'project_id' => $project->id, 'room_name' => 'Comms Room',
            'description' => 'Media Server', 'manufacturer' => 'Sony', 'model' => 'BRAVIA Media Server',
            'part_no' => 'bmsvr', 'signal_role' => Device::ROLE_SOURCE, 'qty' => 1,
        ]);

        $schedule = $this->newSchedule($project);
        $this->makeSvc()->generate($schedule);

        $items = CableScheduleItem::where('cable_schedule_id', $schedule->id)->get();

        // Boardroom rows must use the LOCAL Sony UBP as source — never the
        // central BRAVIA Media Server. Central-fallback is skipped when the
        // local room already has a source for that signal_type.
        $boardroomRows = $items->filter(fn ($i) =>
            str_contains((string) ($i->from_location ?? ''), 'Boardroom')
        );
        $this->assertGreaterThan(0, $boardroomRows->count());
        foreach ($boardroomRows as $row) {
            $this->assertStringNotContainsString('BRAVIA Media Server', (string) ($row->from_location ?? ''),
                'Local source must win — central Sony BRAVIA must not appear as Boardroom source.');
            $this->assertStringNotContainsString('Cross-room: Comms Room → Boardroom', (string) ($row->notes ?? ''),
                'When local source exists, central source must not be pulled → no cross-room prefix.');
        }
    }

    public function test_cross_room_note_prefix_appears_only_on_across_room_rows(): void
    {
        $project = $this->newProject();

        // Boardroom: local processor + local destination (both audio).
        Device::create([
            'project_id' => $project->id, 'room_name' => 'Boardroom',
            'description' => 'Amp', 'manufacturer' => 'LEA', 'model' => 'LEA Audio Amplifier',
            'part_no' => 'lea-amp', 'signal_role' => Device::ROLE_PROCESSOR, 'qty' => 1,
        ]);
        Device::create([
            'project_id' => $project->id, 'room_name' => 'Boardroom',
            'description' => 'Zone Mixer', 'manufacturer' => 'Biamp', 'model' => 'Tesira Zone Mixer DSP',
            'part_no' => 'biamp-mix', 'signal_role' => Device::ROLE_DESTINATION, 'qty' => 1,
        ]);
        // Comms Room: audio source (only cross-room contributor).
        Device::create([
            'project_id' => $project->id, 'room_name' => 'Comms Room',
            'description' => 'DSP', 'manufacturer' => 'Q-Sys', 'model' => 'Core 8 Flex DSP',
            'part_no' => 'qsys-core', 'signal_role' => Device::ROLE_SOURCE, 'qty' => 1,
        ]);

        $schedule = $this->newSchedule($project);
        $this->makeSvc()->generate($schedule);

        $items = CableScheduleItem::where('cable_schedule_id', $schedule->id)
            ->orderBy('sort_order')->get();

        $crossRoomRows = $items->filter(fn ($i) =>
            str_contains((string) ($i->notes ?? ''), 'Cross-room:')
        );
        $localRows = $items->filter(fn ($i) =>
            ! str_contains((string) ($i->notes ?? ''), 'Cross-room:')
        );

        // Chain in Boardroom's audio bucket: DSP(Comms) → amp(Board) → biamp(Board).
        // First edge is cross-room; second is same-room. Comms Room also emits
        // its own DSP → TBC (source without destination) row — same-room, no prefix.
        $this->assertGreaterThanOrEqual(1, $crossRoomRows->count(),
            'At least one cross-room edge (Comms DSP → Boardroom amp) must exist.');
        $this->assertGreaterThanOrEqual(1, $localRows->count(),
            'At least one same-room edge must exist without the cross-room prefix.');
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

    // ─────────────────────────────────────────────────────────────────────────
    // T3-B — redundant-row emission for is_critical processors
    // ─────────────────────────────────────────────────────────────────────────

    public function test_critical_processor_emits_redundant_row_pair(): void
    {
        $project = $this->newProject();

        // Chain: Sony source → Atlona HDBaseT critical processor → Samsung display.
        // Both edges have the critical processor at one endpoint → 2 primary +
        // 2 redundant rows.
        Device::create([
            'project_id' => $project->id, 'room_name' => 'Boardroom',
            'description' => 'Blu-ray', 'manufacturer' => 'Sony', 'model' => 'UBP-X800',
            'part_no' => 'x800', 'signal_role' => Device::ROLE_SOURCE, 'qty' => 1,
        ]);
        Device::create([
            'project_id' => $project->id, 'room_name' => 'Boardroom',
            'description' => 'HDBaseT Extender', 'manufacturer' => 'Atlona', 'model' => 'HDBaseT Extender',
            'part_no' => 'at-hdbt', 'signal_role' => Device::ROLE_PROCESSOR,
            'is_critical' => true, 'qty' => 1,
        ]);
        Device::create([
            'project_id' => $project->id, 'room_name' => 'Boardroom',
            'description' => 'Display', 'manufacturer' => 'Samsung', 'model' => 'QM85 Display',
            'part_no' => 'qm85', 'signal_role' => Device::ROLE_DESTINATION, 'qty' => 1,
        ]);

        $schedule = $this->newSchedule($project);
        $count    = $this->makeSvc()->generate($schedule);

        // 2 primary + 2 redundant = 4 rows total.
        $this->assertSame(4, $count);

        $items = CableScheduleItem::where('cable_schedule_id', $schedule->id)
            ->orderBy('sort_order')->get();
        $this->assertCount(4, $items);

        // Interleave: primary(1), redundant(2), primary(3), redundant(4).
        $this->assertFalse((bool) $items[0]->is_redundant, 'row 1 is the primary source→proc edge.');
        $this->assertTrue((bool)  $items[1]->is_redundant, 'row 2 is the redundant twin of row 1.');
        $this->assertFalse((bool) $items[2]->is_redundant, 'row 3 is the primary proc→dst edge.');
        $this->assertTrue((bool)  $items[3]->is_redundant, 'row 4 is the redundant twin of row 3.');

        // Redundant cable_ids end in '-R', anchored to primary padded number.
        $this->assertStringEndsWith('-R', (string) $items[1]->cable_id);
        $this->assertStringEndsWith('-R', (string) $items[3]->cable_id);
        $this->assertSame($items[0]->cable_id . '-R', $items[1]->cable_id);
        $this->assertSame($items[2]->cable_id . '-R', $items[3]->cable_id);

        // Redundant rows carry the required notes prefix.
        $this->assertStringStartsWith('[REDUNDANT]', (string) ($items[1]->notes ?? ''));
        $this->assertStringStartsWith('[REDUNDANT]', (string) ($items[3]->notes ?? ''));

        // sort_order strictly monotonic 1,2,3,4 (no gaps, no fractions).
        $this->assertSame(1, (int) $items[0]->sort_order);
        $this->assertSame(2, (int) $items[1]->sort_order);
        $this->assertSame(3, (int) $items[2]->sort_order);
        $this->assertSame(4, (int) $items[3]->sort_order);
    }

    public function test_non_critical_processor_emits_single_edge(): void
    {
        $project = $this->newProject();

        // Same chain as the critical test but is_critical=false. Zero
        // redundant rows should emerge.
        Device::create([
            'project_id' => $project->id, 'room_name' => 'Boardroom',
            'description' => 'Blu-ray', 'manufacturer' => 'Sony', 'model' => 'UBP-X800',
            'part_no' => 'x800', 'signal_role' => Device::ROLE_SOURCE, 'qty' => 1,
        ]);
        Device::create([
            'project_id' => $project->id, 'room_name' => 'Boardroom',
            'description' => 'HDBaseT Extender', 'manufacturer' => 'Atlona', 'model' => 'HDBaseT Extender',
            'part_no' => 'at-hdbt', 'signal_role' => Device::ROLE_PROCESSOR,
            'is_critical' => false, 'qty' => 1,
        ]);
        Device::create([
            'project_id' => $project->id, 'room_name' => 'Boardroom',
            'description' => 'Display', 'manufacturer' => 'Samsung', 'model' => 'QM85 Display',
            'part_no' => 'qm85', 'signal_role' => Device::ROLE_DESTINATION, 'qty' => 1,
        ]);

        $schedule = $this->newSchedule($project);
        $count    = $this->makeSvc()->generate($schedule);

        $this->assertSame(2, $count, 'Non-critical chain emits only primary edges.');

        $items = CableScheduleItem::where('cable_schedule_id', $schedule->id)->get();
        foreach ($items as $item) {
            $this->assertNotTrue((bool) $item->is_redundant,
                'No row should be redundant when is_critical=false.');
        }
    }

    public function test_multiple_critical_processors_emit_paired_rows_per_edge(): void
    {
        $project = $this->newProject();

        // Chain: Sony source → Atlona1 (critical proc) → Atlona2 (critical proc) → Samsung display.
        // Both processors unmatched by SIGNAL_PATH_ORDER → sort by id ASC.
        // 3 primary edges → 3 primary + 3 redundant = 6 rows.
        Device::create([
            'project_id' => $project->id, 'room_name' => 'Boardroom',
            'description' => 'Blu-ray', 'manufacturer' => 'Sony', 'model' => 'UBP-X800',
            'part_no' => 'x800', 'signal_role' => Device::ROLE_SOURCE, 'qty' => 1,
        ]);
        Device::create([
            'project_id' => $project->id, 'room_name' => 'Boardroom',
            'description' => 'HDBaseT One', 'manufacturer' => 'Atlona', 'model' => 'HDBaseT Extender A',
            'part_no' => 'at-hdbt-a', 'signal_role' => Device::ROLE_PROCESSOR,
            'is_critical' => true, 'qty' => 1,
        ]);
        Device::create([
            'project_id' => $project->id, 'room_name' => 'Boardroom',
            'description' => 'HDBaseT Two', 'manufacturer' => 'Atlona', 'model' => 'HDBaseT Extender B',
            'part_no' => 'at-hdbt-b', 'signal_role' => Device::ROLE_PROCESSOR,
            'is_critical' => true, 'qty' => 1,
        ]);
        Device::create([
            'project_id' => $project->id, 'room_name' => 'Boardroom',
            'description' => 'Display', 'manufacturer' => 'Samsung', 'model' => 'QM85 Display',
            'part_no' => 'qm85', 'signal_role' => Device::ROLE_DESTINATION, 'qty' => 1,
        ]);

        $schedule = $this->newSchedule($project);
        $count    = $this->makeSvc()->generate($schedule);

        $this->assertSame(6, $count, '3 primary + 3 redundant edges expected.');

        $items = CableScheduleItem::where('cable_schedule_id', $schedule->id)
            ->orderBy('sort_order')->get();
        $this->assertCount(6, $items);

        $primaryIds   = [];
        $redundantIds = [];
        foreach ($items as $item) {
            if (str_ends_with((string) $item->cable_id, '-R')) {
                $redundantIds[] = (string) $item->cable_id;
            } else {
                $primaryIds[] = (string) $item->cable_id;
            }
        }
        $this->assertCount(3, $primaryIds);
        $this->assertCount(3, $redundantIds);

        // Every redundant cable_id maps to a distinct primary — no reuse.
        $strippedRedundantIds = array_map(fn ($r) => substr($r, 0, -2), $redundantIds);
        sort($primaryIds);
        sort($strippedRedundantIds);
        $this->assertSame($primaryIds, $strippedRedundantIds,
            'Each redundant row must anchor to a distinct primary cable_id.');

        // sort_order strictly monotonic 1..6, no gaps or fractional values.
        foreach ($items as $i => $item) {
            $this->assertSame($i + 1, (int) $item->sort_order);
        }
    }

    public function test_project_without_is_critical_data_uses_default_no_redundancy(): void
    {
        $project = $this->newProject();

        // Every device leaves is_critical unset (null via nullable migration).
        // Zero redundant rows must emerge — soft opt-in preserved.
        Device::create([
            'project_id' => $project->id, 'room_name' => 'Boardroom',
            'description' => 'Blu-ray', 'manufacturer' => 'Sony', 'model' => 'UBP-X800',
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

        $this->assertSame(2, $count, 'Null is_critical must behave identically to false.');

        $items = CableScheduleItem::where('cable_schedule_id', $schedule->id)
            ->orderBy('sort_order')->get();
        foreach ($items as $item) {
            $this->assertNotTrue((bool) $item->is_redundant,
                'Null is_critical must not produce redundant rows.');
        }

        // sort_order stays monotonic 1,2 (baseline sanity).
        $this->assertSame(1, (int) $items[0]->sort_order);
        $this->assertSame(2, (int) $items[1]->sort_order);
    }
}
