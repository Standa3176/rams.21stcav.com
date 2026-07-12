<?php

namespace Tests\Unit\Services\Cable;

use App\Core\Modules\Projects\ProjectDataService;
use App\Services\CableScheduleGeneratorService;
use Database\Seeders\DeviceCableRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * T1-C: word-boundary matchesAny() kills known false positives.
 *
 * Tests go through the public buildRowsFromEquipmentLines API so we
 * exercise both the classification path AND the T1-A signal_type
 * extension in one hit. No RefreshDatabase — buildRowsFromEquipmentLines
 * is pure in-memory and doesn't touch the DB.
 *
 * Under the old str_contains implementation:
 *   - 'Microsoft Teams License' would match microphone branch ('mic' in 'Microsoft')
 *   - 'Ceiling Lamp Fixture' would match amplifier branch ('amp' in 'Lamp')
 *   - 'Cisco Room Kit' would bleed into the HDBaseT branch ('csc' in 'Cisco')
 *
 * After the word-boundary fix, all three false positives disappear while
 * genuine 'Shure MXW Microphone' + 'LEA Audio Amplifier' still classify
 * correctly.
 */
class CableScheduleGeneratorServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Quick task 260711-q7q — inferCableRun() now reads from
     * device_cable_rules. Every test method that touches
     * buildRowsFromEquipmentLines() (or any consumer of
     * inferCableRun) needs the canonical 15-rule seed pack loaded.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DeviceCableRulesSeeder::class);
    }

    private function make(): CableScheduleGeneratorService
    {
        // buildRowsFromEquipmentLines does not touch ProjectDataService — the
        // mock is only required to satisfy the constructor's readonly typed
        // dependency. Zero interactions expected.
        $projectData = Mockery::mock(ProjectDataService::class);
        $stencilResolver = Mockery::mock(\App\Services\Cable\StencilPortResolver::class);

        return new CableScheduleGeneratorService($projectData, $stencilResolver);
    }

    public function test_microsoft_teams_license_does_not_classify_as_microphone(): void
    {
        $rows = $this->make()->buildRowsFromEquipmentLines([
            ['name' => 'Microsoft Teams License'],
        ]);

        // Regression note: under the old str_contains impl, 'mic' inside
        // 'Microsoft' would route this to the microphone branch and produce
        // an XLR / Shure cable row. After T1-C: falls through to TBC.
        //
        // NB — Microsoft Teams License routes through the classifier's
        // 'services'/'option' bucket in real quotes (category='services');
        // this test intentionally passes NO category so it hits the pure
        // name-based path where the false positive lived.
        $this->assertCount(1, $rows, 'Expected one row from the fixture line.');
        $row = $rows[0];

        $this->assertNotSame('XLR', $row['cable_type']);
        $this->assertNotSame('Cat6 (Shure network)', $row['cable_type']);
        $this->assertSame('TBC', $row['cable_type']);
        $this->assertSame('unknown', $row['signal_type']);
    }

    public function test_shure_mxw_microphone_still_classifies_as_microphone(): void
    {
        $rows = $this->make()->buildRowsFromEquipmentLines([
            ['name' => 'Shure MXW Microphone'],
        ]);

        $this->assertCount(1, $rows);
        $row = $rows[0];

        // Shure/MXW branch inside the microphone block routes to the
        // Shure network Cat6.
        $this->assertSame('Cat6 (Shure network)', $row['cable_type']);
        $this->assertSame('audio', $row['signal_type']);
        // T1-A shape assertion — proves signal_type flowed through
        // buildRowsFromEquipmentLines end-to-end.
        $this->assertArrayHasKey('signal_type', $row);
    }

    public function test_ceiling_lamp_fixture_does_not_classify_as_amplifier(): void
    {
        $rows = $this->make()->buildRowsFromEquipmentLines([
            ['name' => 'Ceiling Lamp Fixture'],
        ]);

        // Under old code: 'amp' matches inside 'Lamp' → routes to amplifier.
        // After T1-C: falls through to TBC.
        $this->assertCount(1, $rows);
        $row = $rows[0];

        $this->assertNotSame('Audio Multicore', $row['cable_type']);
        $this->assertNotSame('Cat6 (Dante)', $row['cable_type']);
        $this->assertSame('TBC', $row['cable_type']);
        $this->assertSame('unknown', $row['signal_type']);
    }

    public function test_lea_audio_amplifier_still_classifies_as_amplifier(): void
    {
        $rows = $this->make()->buildRowsFromEquipmentLines([
            ['name' => 'LEA Audio Amplifier'],
        ]);

        $this->assertCount(1, $rows);
        $row = $rows[0];

        // LEA is Dante-native per the isDante check on 'lea', so the
        // amplifier branch routes to Cat6 (Dante).
        $this->assertSame('Cat6 (Dante)', $row['cable_type']);
        $this->assertSame('audio', $row['signal_type']);
        $this->assertArrayHasKey('signal_type', $row);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // T1-D — quoted cable products override cable_type by signal_type
    // ─────────────────────────────────────────────────────────────────────────

    public function test_quoted_hdmi_cable_overrides_video_rows(): void
    {
        // Kramer HDMI product classifies as cable_consumable (isCableProduct:
        // 'hdmi' + 'cable') and pins video-signal rows to the quoted product.
        $rows = $this->make()->buildRowsFromEquipmentLines([
            ['name' => 'Samsung QM85 Display'],
            ['name' => 'Kramer C-HM/HM-6 HDMI Cable', 'category' => 'cables'],
        ]);

        $displayRow = collect($rows)->firstWhere('signal_type', 'video');
        $this->assertNotNull($displayRow, 'Expected a video row for the Samsung display.');
        $this->assertSame('Kramer C-HM/HM-6 HDMI Cable', $displayRow['cable_type']);
        $this->assertStringStartsWith('Quoted: Kramer C-HM/HM-6 HDMI Cable | ', $displayRow['notes']);
    }

    public function test_quoted_belden_cable_overrides_audio_rows(): void
    {
        // Belden XLR classifies to audio; Kramer HDMI to video. Each
        // consumable pins its own signal_type only.
        $rows = $this->make()->buildRowsFromEquipmentLines([
            ['name' => 'Shure MXW Microphone'],
            ['name' => 'Samsung QM85 Display'],
            ['name' => 'Belden 8451 XLR Audio Cable', 'category' => 'cables'],
            ['name' => 'Kramer HDMI Cable', 'category' => 'cables'],
        ]);

        $micRow = collect($rows)->firstWhere('signal_type', 'audio');
        $this->assertNotNull($micRow, 'Expected an audio row for the mic.');
        $this->assertSame('Belden 8451 XLR Audio Cable', $micRow['cable_type']);
        $this->assertStringStartsWith('Quoted: Belden 8451 XLR Audio Cable | ', $micRow['notes']);

        $displayRow = collect($rows)->firstWhere('signal_type', 'video');
        $this->assertNotNull($displayRow);
        $this->assertSame('Kramer HDMI Cable', $displayRow['cable_type']);
        $this->assertStringStartsWith('Quoted: Kramer HDMI Cable | ', $displayRow['notes']);
    }

    public function test_shure_cat6_reclassifies_from_network_to_audio(): void
    {
        // 'Shure Cat6 Patch Cable' would classify as 'network' via cat6, but
        // the shure+network special case pins it to 'audio'. The Shure mic
        // audio row should then adopt this consumable.
        $rows = $this->make()->buildRowsFromEquipmentLines([
            ['name' => 'Shure MXW Microphone'],
            ['name' => 'Shure Cat6 Patch Cable', 'category' => 'cables'],
        ]);

        $micRow = collect($rows)->firstWhere('signal_type', 'audio');
        $this->assertNotNull($micRow, 'Expected an audio row for the Shure microphone.');
        $this->assertSame('Shure Cat6 Patch Cable', $micRow['cable_type']);
        $this->assertStringStartsWith('Quoted: Shure Cat6 Patch Cable | ', $micRow['notes']);
    }

    public function test_no_consumables_leaves_rows_unchanged(): void
    {
        // Regression guard: rows without any cable_consumable input retain
        // the pre-T1-D inferred cable_type + notes exactly.
        $rows = $this->make()->buildRowsFromEquipmentLines([
            ['name' => 'Samsung QM85 Display'],
        ]);

        $this->assertCount(1, $rows);
        $row = $rows[0];

        // Pre-T1-D shape — HDMI 2.0 + video, no "Quoted:" prefix.
        $this->assertSame('HDMI 2.0', $row['cable_type']);
        $this->assertSame('video', $row['signal_type']);
        $this->assertStringNotContainsString('Quoted:', $row['notes']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // T1-E — Survey narrative populates length + distance warnings
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Call a private/protected method via reflection so tests can exercise
     * the pure helpers directly without spinning up the full DB stack.
     */
    private function invoke(CableScheduleGeneratorService $svc, string $method, array $args = [])
    {
        $ref = new \ReflectionMethod($svc, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($svc, $args);
    }

    public function test_parses_last_meter_measurement_from_narrative(): void
    {
        $svc = $this->make();

        // Multiple hits — last wins per <behavior>.
        $this->assertSame(45.0, $this->invoke($svc, 'parseLengthFromNarrative',
            ['Route drops 3m at wall then extends 45m to rack']));

        // Formats: decimal, plain, metres/meters/metre (case-insensitive).
        $this->assertSame(12.5, $this->invoke($svc, 'parseLengthFromNarrative', ['12.5m to rack']));
        $this->assertSame(12.0, $this->invoke($svc, 'parseLengthFromNarrative', ['12 metres to comms room']));
        $this->assertSame(12.0, $this->invoke($svc, 'parseLengthFromNarrative', ['12 Meters']));
        $this->assertSame(12.0, $this->invoke($svc, 'parseLengthFromNarrative', ['12 metre']));

        // No match / empty.
        $this->assertNull($this->invoke($svc, 'parseLengthFromNarrative', ['no numeric measurement']));
        $this->assertNull($this->invoke($svc, 'parseLengthFromNarrative', ['']));
        $this->assertNull($this->invoke($svc, 'parseLengthFromNarrative', [null]));
    }

    public function test_priority_chain_cable_routes_then_json_then_legacy(): void
    {
        $svc = $this->make();

        // Priority 1: JSON cable_routes array wins over cable_route_desc.
        $room = new \App\Models\SiteSurveyRoom();
        $room->room_name        = 'Boardroom';
        $room->cable_routes     = [['category' => 'screen_cables', 'from' => 'A', 'to' => 'B', 'length_m' => 42, 'notes' => 'ceiling void']];
        $room->cable_route_desc = 'Legacy 5m note';

        $narrative = $this->invoke($svc, 'extractRoomNarrative', [$room]);
        $this->assertNotNull($narrative);
        // Regex on the synthetic narrative should find 42 (from length_m), not 5.
        $this->assertSame(42.0, $this->invoke($svc, 'parseLengthFromNarrative', [$narrative]));

        // Priority 3 fallback: no JSON cable_routes → cable_route_desc wins.
        $room2 = new \App\Models\SiteSurveyRoom();
        $room2->room_name        = 'Meeting Room';
        $room2->cable_routes     = null;
        $room2->cable_route_desc = 'Legacy path — 5m note';
        $narrative2 = $this->invoke($svc, 'extractRoomNarrative', [$room2]);
        $this->assertSame('Legacy path — 5m note', $narrative2);
        $this->assertSame(5.0, $this->invoke($svc, 'parseLengthFromNarrative', [$narrative2]));

        // All empty → null.
        $room3 = new \App\Models\SiteSurveyRoom();
        $room3->room_name        = 'Empty Room';
        $room3->cable_routes     = null;
        $room3->cable_route_desc = null;
        $this->assertNull($this->invoke($svc, 'extractRoomNarrative', [$room3]));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // computeDistanceWarnings tests retired 2026-07-12 (260712-euh).
    //
    // The 5 test cases below used to exercise the DISTANCE_WARNING_RULES const
    // + computeDistanceWarnings method (both deleted in Task 2). Equivalent
    // behaviour is now tested through the length-tier picker in
    // DeviceCableRuleInferenceTest (see 260712-euh T5 cases: HDMI short /
    // medium / long / over-max / null length; PTZ camera; generic mic).
    // ─────────────────────────────────────────────────────────────────────────

    // ─────────────────────────────────────────────────────────────────────────
    // T2-A — port-FK resolution on the flat generateFromDevices path
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build a Device with a pre-attached in-memory DeviceStencil + ports. No
     * DB — everything is set via ->setRelation so the resolvers can access
     * $device->stencil->ports without touching Eloquent's query builder.
     *
     * @param  array<int, array{signal_type: string, direction: ?string, side: string, sort_order?: int}>  $portSpecs
     */
    private function makeDeviceWithStencil(array $portSpecs): \App\Models\Device
    {
        $device  = new \App\Models\Device();
        $device->id            = 42;
        $device->project_id    = 1;
        $device->manufacturer  = 'Acme';
        $device->model         = 'X1';
        $device->part_no       = 'acme-x1';

        $stencil = new \App\Models\DeviceStencil();
        $stencil->id           = 7;
        $stencil->part_number  = 'acme-x1';

        $ports = collect();
        foreach ($portSpecs as $i => $spec) {
            $port = new \App\Models\DevicePort();
            $port->id            = 100 + $i;
            $port->signal_type   = $spec['signal_type'];
            $port->direction     = $spec['direction'];
            $port->side          = $spec['side'];
            $port->sort_order    = $spec['sort_order'] ?? $i;
            $port->port_id       = "p-{$port->id}";
            $ports->push($port);
        }
        $stencil->setRelation('ports', $ports);
        $device->setRelation('stencil', $stencil);

        return $device;
    }

    public function test_flat_path_populates_source_device_and_port_ids_when_stencil_has_matching_signal_type(): void
    {
        $svc = $this->make();

        $device = $this->makeDeviceWithStencil([
            ['signal_type' => 'video', 'direction' => 'out', 'side' => 'right', 'sort_order' => 0],
            ['signal_type' => 'video', 'direction' => 'out', 'side' => 'right', 'sort_order' => 1],
            ['signal_type' => 'audio', 'direction' => 'out', 'side' => 'right', 'sort_order' => 2],
        ]);

        $portId = $this->invoke($svc, 'resolveSourcePortId', [$device, 'video']);
        $this->assertSame(100, $portId, 'sort_order=0 wins ASC across two video-out ports.');

        // Fallback: direction null + side=right also counts as source.
        $device2 = $this->makeDeviceWithStencil([
            ['signal_type' => 'video', 'direction' => null, 'side' => 'right', 'sort_order' => 0],
        ]);
        $this->assertSame(100, $this->invoke($svc, 'resolveSourcePortId', [$device2, 'video']));
    }

    public function test_flat_path_leaves_ports_null_when_stencil_has_no_ports_auto_generated_placeholder(): void
    {
        $svc = $this->make();

        // Empty-ports stencil: auto-generated placeholder before Phase 24
        // curation adds ports.
        $device = new \App\Models\Device();
        $device->id = 42;
        $stencil = new \App\Models\DeviceStencil();
        $stencil->setRelation('ports', collect());
        $device->setRelation('stencil', $stencil);

        $this->assertNull($this->invoke($svc, 'resolveSourcePortId', [$device, 'video']));
        $this->assertNull($this->invoke($svc, 'resolveDestPortId',   [$device, 'video']));
    }

    public function test_flat_path_leaves_dest_ids_null(): void
    {
        // Contract statement: the flat generateFromDevices path has no dest
        // Device (rows emit "→ TBC" strings), so dest_device_id and
        // dest_port_id ALWAYS stay null. Verified structurally by scanning
        // the source for the pattern.
        $source = file_get_contents(base_path('app/Services/CableScheduleGeneratorService.php'));

        // The flat path CableScheduleItem::create must set dest_device_id
        // and dest_port_id explicitly to null.
        $this->assertMatchesRegularExpression(
            '/dest_device_id\s*\'\s*=>\s*null/',
            $source,
            'flat generateFromDevices must set dest_device_id => null explicitly.'
        );
        $this->assertMatchesRegularExpression(
            '/dest_port_id\s*\'\s*=>\s*null/',
            $source,
            'flat generateFromDevices must set dest_port_id => null explicitly.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // T2-B — signal-path DAG traversal (pure helpers)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * In-memory Device factory: sets attributes directly + calls setRelation
     * so the pure helpers can be exercised without touching the DB.
     */
    private function makeInMemoryDevice(array $attrs = []): \App\Models\Device
    {
        $d = new \App\Models\Device();
        foreach ($attrs as $k => $v) {
            $d->$k = $v;
        }
        return $d;
    }

    public function test_build_signal_graph_buckets_by_role_and_signal_type(): void
    {
        $svc = $this->make();

        $src  = $this->makeInMemoryDevice([
            'id' => 1, 'manufacturer' => 'Samsung', 'model' => 'QM85',
            'signal_role' => 'source',
        ]);
        $dsp  = $this->makeInMemoryDevice([
            'id' => 2, 'manufacturer' => 'Q-Sys', 'model' => 'Core 8 Flex',
            'signal_role' => 'processor',
        ]);
        $dst  = $this->makeInMemoryDevice([
            'id' => 3, 'manufacturer' => 'Samsung', 'model' => 'QM43',
            'signal_role' => 'destination',
        ]);

        // T2-B-ext: buildSignalGraph now takes ($localDevices, $centralDevices).
        // Passing an explicit empty collect() as the central set proves the
        // no-central-room contract stays byte-for-byte identical.
        $graph = $this->invoke($svc, 'buildSignalGraph', [collect([$src, $dsp, $dst]), collect()]);

        $this->assertArrayHasKey('video', $graph, 'display devices bucket to video signal_type.');
        $this->assertCount(1, $graph['video']['sources']);
        $this->assertCount(1, $graph['video']['destinations']);
    }

    public function test_processors_sorted_by_signal_path_order(): void
    {
        $svc = $this->make();

        $amp  = $this->makeInMemoryDevice(['id' => 20, 'manufacturer' => 'LEA', 'model' => 'Amplifier',   'signal_role' => 'processor']);
        $mtx  = $this->makeInMemoryDevice(['id' => 21, 'manufacturer' => 'Kramer', 'model' => 'Matrix',    'signal_role' => 'processor']);
        $dsp  = $this->makeInMemoryDevice(['id' => 22, 'manufacturer' => 'Q-Sys', 'model' => 'DSP',        'signal_role' => 'processor']);

        $sorted = $this->invoke($svc, 'sortProcessors', [[$amp, $mtx, $dsp]]);

        // Expected order: dsp (0), matrix (2), amplifier (5).
        $this->assertSame(22, $sorted[0]->id, 'DSP ranks first.');
        $this->assertSame(21, $sorted[1]->id, 'Matrix ranks between DSP and amplifier.');
        $this->assertSame(20, $sorted[2]->id, 'Amplifier ranks last of the three.');
    }

    public function test_all_unknown_signal_role_hits_flat_fallback_predicate(): void
    {
        // When EVERY device in a room has null signal_role, the room falls
        // through to generateFromDevicesFlat. Prove this contractually by
        // asserting hasUnknownSignalRole() returns true on unclassified
        // devices — which is the exact predicate generateFromDevices uses.
        $d1 = $this->makeInMemoryDevice(['id' => 1, 'signal_role' => null]);
        $d2 = $this->makeInMemoryDevice(['id' => 2, 'signal_role' => null]);
        $devices = collect([$d1, $d2]);

        $allUnknown = $devices->every(fn (\App\Models\Device $d) => $d->hasUnknownSignalRole());
        $this->assertTrue($allUnknown, 'every() must return true when every device is unclassified.');

        // Structural guard: the orchestrator MUST test hasUnknownSignalRole
        // before choosing the DAG path.
        $source = file_get_contents(base_path('app/Services/CableScheduleGeneratorService.php'));
        $this->assertStringContainsString('hasUnknownSignalRole', $source,
            'generateFromDevices must gate DAG vs flat on hasUnknownSignalRole().');
        $this->assertStringContainsString('generateFromDevicesFlat', $source,
            'flat fallback must be named generateFromDevicesFlat.');
    }

    public function test_signal_path_order_const_present_and_generateFromDevicesFlat_defined(): void
    {
        // Structural / contract guard — plan requires the const + method
        // signatures exist.
        $ref = new \ReflectionClass(\App\Services\CableScheduleGeneratorService::class);
        $this->assertTrue($ref->hasConstant('SIGNAL_PATH_ORDER'));
        $this->assertTrue($ref->hasMethod('buildSignalGraph'));
        $this->assertTrue($ref->hasMethod('emitDagEdges'));
        $this->assertTrue($ref->hasMethod('generateFromDevicesFlat'));
    }

    public function test_multiple_consumables_of_same_signal_type_join_with_slash(): void
    {
        // Two HDMI cable products both classify to 'video' — override display
        // joins them in array order with ' / '.
        $rows = $this->make()->buildRowsFromEquipmentLines([
            ['name' => 'Samsung QM85 Display'],
            ['name' => 'Kramer HDMI Cable', 'category' => 'cables'],
            ['name' => 'Cat6 Patch Lead', 'category' => 'cables'],
        ]);

        // The 'Kramer HDMI Cable' pins video; the 'Cat6 Patch Lead' pins
        // network. So video row gets Kramer HDMI.
        $displayRow = collect($rows)->firstWhere('signal_type', 'video');
        $this->assertNotNull($displayRow);
        $this->assertSame('Kramer HDMI Cable', $displayRow['cable_type']);

        // Two hdmi cables at once → joined with ' / '.
        $rows2 = $this->make()->buildRowsFromEquipmentLines([
            ['name' => 'Samsung QM85 Display'],
            ['name' => 'Kramer HDMI Cable', 'category' => 'cables'],
            ['name' => 'Premium Displayport Cable', 'category' => 'cables'],
        ]);
        $displayRow2 = collect($rows2)->firstWhere('signal_type', 'video');
        $this->assertNotNull($displayRow2);
        $this->assertSame(
            'Kramer HDMI Cable / Premium Displayport Cable',
            $displayRow2['cable_type'],
            'Same-signal_type consumables must join with " / " in array order.'
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
