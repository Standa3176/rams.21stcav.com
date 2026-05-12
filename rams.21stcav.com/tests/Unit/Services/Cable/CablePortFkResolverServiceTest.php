<?php

namespace Tests\Unit\Services\Cable;

use App\Models\CableSchedule;
use App\Models\CableScheduleItem;
use App\Models\Device;
use App\Models\DevicePort;
use App\Models\DeviceStencil;
use App\Models\Project;
use App\Models\User;
use App\Services\Cable\CablePortFkResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 22 Plan 03 Task 1 — locks the pure deterministic backfill matcher
 * contract.
 *
 * The resolver is pure: given a CableScheduleItem + a collection of Devices
 * (pre-attached with their stencil + ports), it returns a per-row decision
 * with no DB writes. The command layer wraps the consumer-side "already-set"
 * skip + the optional --apply writes.
 *
 * Coverage map (per plan §<behavior>):
 *   1.  exact single-device single-port match            → matched
 *   2.  two matching devices same text                   → ambiguous (Pitfall 3)
 *   3.  zero matching devices                            → no-device-match
 *   4.  device has two HDMI ports                        → ambiguous
 *   5.  Tier 1.5 stencil with zero ports                 → no-device-match (Pitfall 4)
 *   6.  source matched / dest ambiguous (partial)        → ambiguous overall, source diagnostics still present
 *   7.  case-insensitive normalised match                → matched
 *   8.  cable_type='CAT6' → rj45 connector hint          → matched
 *   9.  empty connector_type port (Tier 1.5)             → excluded from matching (Pitfall 4)
 *   10. resolver is pure — zero DB writes                → row counts unchanged
 */
class CablePortFkResolverServiceTest extends TestCase
{
    use RefreshDatabase;

    private CablePortFkResolverService $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new CablePortFkResolverService;
    }

    public function test_exact_single_device_single_port_match(): void
    {
        [$project, $schedule, $item] = $this->makeScheduleItem([
            'from_location' => 'Crestron HD-MD-400',
            'to_location'   => 'Crestron HD-MD-400',
            'cable_type'    => 'HDMI',
        ]);

        [$device, $port] = $this->makeDeviceWithSinglePort($project, [
            'manufacturer'   => 'Crestron',
            'model'          => 'HD-MD-400',
            'part_no'        => 'HD-MD-400',
        ], [
            'connector_type' => 'hdmi',
            'signal_type'    => 'video',
            'label'          => 'HDMI 1',
            'port_id'        => 'hdmi-1',
        ]);

        $decision = $this->resolver->resolve($item, collect([$device]));

        $this->assertSame('matched', $decision['match']);
        $this->assertSame($device->id, $decision['source_device_id']);
        $this->assertSame($port->id, $decision['source_port_id']);
        $this->assertSame($device->id, $decision['dest_device_id']);
        $this->assertSame($port->id, $decision['dest_port_id']);
    }

    public function test_two_matching_devices_returns_ambiguous(): void
    {
        // Pitfall 3 — strict-match prevents substring collision but two
        // devices with identical manufacturer+model still match the same text
        // and that's correctly flagged as ambiguous.
        [$project, , $item] = $this->makeScheduleItem([
            'from_location' => 'Crestron HD-MD-400',
            'to_location'   => 'Samsung QM65',
            'cable_type'    => 'HDMI',
        ]);

        // Two physical units of the same model share a stencil (part_number
        // is uniquely indexed across the catalog — the same model maps to
        // exactly one DeviceStencil row).
        [$device1, $port1] = $this->makeDeviceWithSinglePort($project, [
            'manufacturer' => 'Crestron',
            'model'        => 'HD-MD-400',
            'part_no'      => 'HD-MD-400',
        ], ['connector_type' => 'hdmi', 'signal_type' => 'video', 'label' => 'HDMI 1', 'port_id' => 'hdmi-1']);

        // Second unit: same stencil, second device row.
        $device2 = Device::create([
            'project_id'   => $project->id,
            'description'  => 'Crestron HD-MD-400 (second unit)',
            'manufacturer' => 'Crestron',
            'model'        => 'HD-MD-400',
            'part_no'      => 'HD-MD-400',
            'qty'          => 1,
        ]);
        $existingStencil = DeviceStencil::where('part_number', DeviceStencil::normalisePartNumber('HD-MD-400'))->first();
        $this->attachStencilToDevice($device2, $existingStencil);

        $decision = $this->resolver->resolve($item, collect([$device1, $device2]));

        $this->assertSame('ambiguous', $decision['match']);
        $this->assertNull($decision['source_device_id']);
        $this->assertNull($decision['source_port_id']);
    }

    public function test_zero_matching_devices_returns_no_device_match(): void
    {
        [$project, , $item] = $this->makeScheduleItem([
            'from_location' => 'AMX Modero NX-1200',  // not present in project
            'to_location'   => 'AMX Modero NX-1200',
            'cable_type'    => 'HDMI',
        ]);

        [$otherDevice] = $this->makeDeviceWithSinglePort($project, [
            'manufacturer' => 'Crestron',
            'model'        => 'HD-MD-400',
            'part_no'      => 'HD-MD-400',
        ], ['connector_type' => 'hdmi', 'signal_type' => 'video', 'label' => 'HDMI 1', 'port_id' => 'hdmi-1']);

        $decision = $this->resolver->resolve($item, collect([$otherDevice]));

        $this->assertSame('no-device-match', $decision['match']);
        $this->assertNull($decision['source_device_id']);
        $this->assertNull($decision['dest_device_id']);
    }

    public function test_device_with_two_hdmi_ports_returns_ambiguous(): void
    {
        [$project, , $item] = $this->makeScheduleItem([
            'from_location' => 'Crestron DM-NVX-360',
            'to_location'   => 'Samsung QM65',
            'cable_type'    => 'HDMI',
        ]);

        [$device, $stencil] = $this->makeDeviceAndStencil($project, [
            'manufacturer' => 'Crestron',
            'model'        => 'DM-NVX-360',
            'part_no'      => 'DM-NVX-360',
        ]);
        DevicePort::create([
            'device_stencil_id' => $stencil->id,
            'label'             => 'HDMI 1', 'side' => DevicePort::SIDE_LEFT,
            'connector_type'    => 'hdmi', 'signal_type' => 'video',
            'direction'         => DevicePort::DIRECTION_IN, 'sort_order' => 0, 'port_id' => 'hdmi-1',
        ]);
        DevicePort::create([
            'device_stencil_id' => $stencil->id,
            'label'             => 'HDMI 2', 'side' => DevicePort::SIDE_LEFT,
            'connector_type'    => 'hdmi', 'signal_type' => 'video',
            'direction'         => DevicePort::DIRECTION_IN, 'sort_order' => 1, 'port_id' => 'hdmi-2',
        ]);
        $this->attachStencilToDevice($device, $stencil);

        $decision = $this->resolver->resolve($item, collect([$device]));

        $this->assertSame('ambiguous', $decision['match']);
        $this->assertNull($decision['source_device_id']);
        $this->assertNull($decision['source_port_id']);
    }

    public function test_tier_1_5_stencil_with_zero_ports_returns_no_device_match(): void
    {
        // Pitfall 4 — Tier 1.5 stencils have empty ports list. Backfill cannot
        // deterministically match, so the resolver returns no-device-match
        // (the matched device has no catalogued port). Phase 24 curation
        // closes this gap.
        [$project, , $item] = $this->makeScheduleItem([
            'from_location' => 'Cisco Webex Room Kit Plus',
            'to_location'   => 'Cisco Webex Room Kit Plus',
            'cable_type'    => 'HDMI',
        ]);

        [$device, $stencil] = $this->makeDeviceAndStencil($project, [
            'manufacturer' => 'Cisco',
            'model'        => 'Webex Room Kit Plus',
            'part_no'      => 'CS-KIT-PLUS-K9',
        ]);
        // No ports created — Tier 1.5 stencil.
        $this->attachStencilToDevice($device, $stencil);

        $decision = $this->resolver->resolve($item, collect([$device]));

        $this->assertSame('no-device-match', $decision['match']);
        $this->assertNull($decision['source_device_id']);
        $this->assertNull($decision['source_port_id']);
    }

    public function test_source_matched_but_dest_ambiguous_returns_ambiguous_with_partial_diagnostics(): void
    {
        // RED test 10 (resolver layer): the resolver returns informative
        // partial data — source_device_id IS populated even though overall
        // 'match' is 'ambiguous'. The COMMAND tests (Task 2) prove that the
        // command DOES NOT persist this partial data.
        [$project, , $item] = $this->makeScheduleItem([
            'from_location' => 'Crestron HD-MD-400',     // matches one device with one HDMI port → source matched
            'to_location'   => 'Samsung QM65',           // matches one device with TWO HDMI ports → dest ambiguous
            'cable_type'    => 'HDMI',
        ]);

        [$srcDevice, $srcPort] = $this->makeDeviceWithSinglePort($project, [
            'manufacturer' => 'Crestron', 'model' => 'HD-MD-400', 'part_no' => 'HD-MD-400',
        ], ['connector_type' => 'hdmi', 'signal_type' => 'video', 'label' => 'HDMI 1', 'port_id' => 'hdmi-1']);

        [$dstDevice, $dstStencil] = $this->makeDeviceAndStencil($project, [
            'manufacturer' => 'Samsung', 'model' => 'QM65', 'part_no' => 'QM65',
        ]);
        DevicePort::create([
            'device_stencil_id' => $dstStencil->id, 'label' => 'HDMI 1', 'side' => DevicePort::SIDE_RIGHT,
            'connector_type' => 'hdmi', 'signal_type' => 'video', 'direction' => DevicePort::DIRECTION_IN,
            'sort_order' => 0, 'port_id' => 'hdmi-1',
        ]);
        DevicePort::create([
            'device_stencil_id' => $dstStencil->id, 'label' => 'HDMI 2', 'side' => DevicePort::SIDE_RIGHT,
            'connector_type' => 'hdmi', 'signal_type' => 'video', 'direction' => DevicePort::DIRECTION_IN,
            'sort_order' => 1, 'port_id' => 'hdmi-2',
        ]);
        $this->attachStencilToDevice($dstDevice, $dstStencil);

        $decision = $this->resolver->resolve($item, collect([$srcDevice, $dstDevice]));

        $this->assertSame('ambiguous', $decision['match'], 'overall outcome must be ambiguous');
        // Partial diagnostics: source half is populated; dest half is null.
        $this->assertSame($srcDevice->id, $decision['source_device_id']);
        $this->assertSame($srcPort->id, $decision['source_port_id']);
        $this->assertNull($decision['dest_device_id']);
        $this->assertNull($decision['dest_port_id']);
    }

    public function test_case_insensitive_normalised_match(): void
    {
        [$project, , $item] = $this->makeScheduleItem([
            'from_location' => 'crestron HD-md-400',
            'to_location'   => 'crestron HD-md-400',
            'cable_type'    => 'HDMI',
        ]);

        [$device, $port] = $this->makeDeviceWithSinglePort($project, [
            'manufacturer' => 'Crestron',
            'model'        => 'HD-MD-400',
            'part_no'      => 'HD-MD-400',
        ], ['connector_type' => 'hdmi', 'signal_type' => 'video', 'label' => 'HDMI 1', 'port_id' => 'hdmi-1']);

        $decision = $this->resolver->resolve($item, collect([$device]));

        $this->assertSame('matched', $decision['match']);
        $this->assertSame($device->id, $decision['source_device_id']);
        $this->assertSame($port->id, $decision['source_port_id']);
    }

    public function test_cable_type_cat6_maps_to_rj45_connector(): void
    {
        [$project, , $item] = $this->makeScheduleItem([
            'from_location' => 'Netgear GS312TP',
            'to_location'   => 'Netgear GS312TP',
            'cable_type'    => 'CAT6',
        ]);

        [$device, $stencil] = $this->makeDeviceAndStencil($project, [
            'manufacturer' => 'Netgear',
            'model'        => 'GS312TP',
            'part_no'      => 'GS312TP',
        ]);
        // One RJ45 port AND one HDMI port — only the RJ45 should match given CAT6.
        $rj45 = DevicePort::create([
            'device_stencil_id' => $stencil->id, 'label' => 'LAN 1', 'side' => DevicePort::SIDE_LEFT,
            'connector_type' => 'rj45', 'signal_type' => 'network', 'direction' => DevicePort::DIRECTION_IO,
            'sort_order' => 0, 'port_id' => 'rj45-1',
        ]);
        DevicePort::create([
            'device_stencil_id' => $stencil->id, 'label' => 'HDMI 1', 'side' => DevicePort::SIDE_LEFT,
            'connector_type' => 'hdmi', 'signal_type' => 'video', 'direction' => DevicePort::DIRECTION_IN,
            'sort_order' => 1, 'port_id' => 'hdmi-1',
        ]);
        $this->attachStencilToDevice($device, $stencil);

        $decision = $this->resolver->resolve($item, collect([$device]));

        $this->assertSame('matched', $decision['match']);
        $this->assertSame($rj45->id, $decision['source_port_id'], 'CAT6 must map to rj45 not hdmi');
        $this->assertSame($rj45->id, $decision['dest_port_id']);
    }

    public function test_empty_connector_type_ports_are_excluded_from_matching(): void
    {
        // Pitfall 4 — Tier 1.5 stencils may have ports with empty connector_type.
        // Those count as "unknown" and must NOT contribute to "exactly one
        // matching port" success when cable_type is set. Even when cable_type
        // is empty (no hint), empty-connector ports are excluded.
        [$project, , $item] = $this->makeScheduleItem([
            'from_location' => 'Vendor X Box',
            'to_location'   => 'Vendor X Box',
            'cable_type'    => '',
        ]);

        [$device, $stencil] = $this->makeDeviceAndStencil($project, [
            'manufacturer' => 'Vendor X',
            'model'        => 'Box',
            'part_no'      => 'X-BOX-1',
        ]);
        // Only port has empty connector_type → should be excluded from matching.
        DevicePort::create([
            'device_stencil_id' => $stencil->id, 'label' => 'Port 1', 'side' => DevicePort::SIDE_LEFT,
            'connector_type' => '', 'signal_type' => '',
            'direction' => DevicePort::DIRECTION_IO, 'sort_order' => 0, 'port_id' => 'p1',
        ]);
        $this->attachStencilToDevice($device, $stencil);

        $decision = $this->resolver->resolve($item, collect([$device]));

        $this->assertSame('no-device-match', $decision['match'], 'Empty connector_type ports must not deterministic-match');
        $this->assertNull($decision['source_port_id']);
    }

    public function test_resolver_is_pure_no_db_writes(): void
    {
        [$project, , $item] = $this->makeScheduleItem([
            'from_location' => 'Crestron HD-MD-400',
            'to_location'   => 'Crestron HD-MD-400',
            'cable_type'    => 'HDMI',
        ]);
        [$device, $port] = $this->makeDeviceWithSinglePort($project, [
            'manufacturer' => 'Crestron', 'model' => 'HD-MD-400', 'part_no' => 'HD-MD-400',
        ], ['connector_type' => 'hdmi', 'signal_type' => 'video', 'label' => 'HDMI 1', 'port_id' => 'hdmi-1']);

        $beforeDevices  = Device::count();
        $beforePorts    = DevicePort::count();
        $beforeStencils = DeviceStencil::count();
        $beforeItems    = CableScheduleItem::count();

        $this->resolver->resolve($item, collect([$device]));
        $this->resolver->resolve($item, collect([$device]));
        $this->resolver->resolve($item, collect([$device]));

        $this->assertSame($beforeDevices, Device::count());
        $this->assertSame($beforePorts, DevicePort::count());
        $this->assertSame($beforeStencils, DeviceStencil::count());
        $this->assertSame($beforeItems, CableScheduleItem::count());

        // Item itself must be untouched.
        $reloaded = $item->fresh();
        $this->assertNull($reloaded->source_device_id);
        $this->assertNull($reloaded->source_port_id);
    }

    // ── Fixture helpers ─────────────────────────────────────────────────────

    /**
     * @return array{0: Project, 1: CableSchedule, 2: CableScheduleItem}
     */
    private function makeScheduleItem(array $itemAttrs): array
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $schedule = CableSchedule::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => $project->name,
            'status'       => CableSchedule::STATUS_DRAFT,
        ]);
        $item = CableScheduleItem::create(array_merge([
            'cable_schedule_id' => $schedule->id,
            'sort_order'        => 0,
        ], $itemAttrs));

        return [$project, $schedule, $item];
    }

    /**
     * @return array{0: Device, 1: DeviceStencil}
     */
    private function makeDeviceAndStencil(Project $project, array $deviceAttrs): array
    {
        $device = Device::create(array_merge([
            'project_id'  => $project->id,
            'description' => trim(($deviceAttrs['manufacturer'] ?? '') . ' ' . ($deviceAttrs['model'] ?? '')),
            'qty'         => 1,
        ], $deviceAttrs));

        $stencil = DeviceStencil::create([
            'part_number'  => DeviceStencil::normalisePartNumber($deviceAttrs['part_no'] ?? ''),
            'manufacturer' => $deviceAttrs['manufacturer'] ?? null,
            'model'        => $deviceAttrs['model'] ?? null,
            'mxgraph_xml'  => '<shape/>',
            'source'       => DeviceStencil::SOURCE_AUTO_GENERATED,
        ]);

        return [$device, $stencil];
    }

    /**
     * @return array{0: Device, 1: DevicePort}
     */
    private function makeDeviceWithSinglePort(Project $project, array $deviceAttrs, array $portAttrs): array
    {
        [$device, $stencil] = $this->makeDeviceAndStencil($project, $deviceAttrs);

        $port = DevicePort::create(array_merge([
            'device_stencil_id' => $stencil->id,
            'side'              => DevicePort::SIDE_LEFT,
            'direction'         => DevicePort::DIRECTION_IN,
            'sort_order'        => 0,
        ], $portAttrs));

        $this->attachStencilToDevice($device, $stencil);

        return [$device, $port];
    }

    /**
     * The Device model has no native belongsTo(DeviceStencil) — stencils are
     * resolved by normalised part_number, not FK. The command layer attaches
     * the resolved stencil via setRelation() so callers can access
     * `$device->stencil->ports` naturally. The unit tests mirror that
     * attachment so the resolver receives the same input shape.
     */
    private function attachStencilToDevice(Device $device, DeviceStencil $stencil): void
    {
        // Re-load ports relation so resolver sees fresh port set.
        $stencil->load('ports');
        $device->setRelation('stencil', $stencil);
    }
}
