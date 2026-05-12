<?php

namespace Tests\Feature\Cable;

use App\Models\CableSchedule;
use App\Models\CableScheduleItem;
use App\Models\Device;
use App\Models\DevicePort;
use App\Models\DeviceStencil;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 22 Plan 02 Task 1 — picker round-trip: PUT /cable-schedules/{id}
 * with all 4 port FKs + canonical text labels persists every key via the
 * Plan 22-01 $fillable whitelist on CableScheduleItem.
 *
 * DRAW-38 acceptance — engineer-picked port pair survives a save / reload
 * cycle. The picker writes hidden inputs + canonical labels; the controller
 * just trusts the validation rules + $fillable.
 *
 * @see app/Http/Controllers/CableScheduleController.php@update
 */
class CableScheduleUpdatePersistsPortFksTest extends TestCase
{
    use RefreshDatabase;

    public function test_picker_round_trip_persists_all_4_fks(): void
    {
        [$user, $project, $schedule, $srcDevice, $dstDevice, $srcPort, $dstPort] = $this->makeFixture();

        $response = $this->actingAs($user)
            ->put(route('cable-schedules.update', $schedule), [
                'items' => [
                    [
                        'cable_id'         => 'CAB-001',
                        'from_location'    => 'Crestron HD-MD-400 (HDMI 1)',
                        'to_location'      => 'Samsung QM65 (HDMI 1)',
                        'cable_type'       => 'HDMI',
                        'source_device_id' => $srcDevice->id,
                        'source_port_id'   => $srcPort->id,
                        'dest_device_id'   => $dstDevice->id,
                        'dest_port_id'     => $dstPort->id,
                    ],
                ],
            ]);

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();

        $persisted = $schedule->fresh()->items->first();
        $this->assertNotNull($persisted, 'item must be persisted after update');
        $this->assertSame($srcDevice->id, $persisted->source_device_id);
        $this->assertSame($srcPort->id,   $persisted->source_port_id);
        $this->assertSame($dstDevice->id, $persisted->dest_device_id);
        $this->assertSame($dstPort->id,   $persisted->dest_port_id);
    }

    public function test_canonical_labels_persist_via_from_location_to_location(): void
    {
        [$user, $project, $schedule, $srcDevice, $dstDevice, $srcPort, $dstPort] = $this->makeFixture();

        $this->actingAs($user)
            ->put(route('cable-schedules.update', $schedule), [
                'items' => [
                    [
                        'from_location'    => 'Crestron HD-MD-400 (HDMI 1)',
                        'to_location'      => 'Samsung QM65 (HDMI 1)',
                        'source_device_id' => $srcDevice->id,
                    ],
                ],
            ])
            ->assertStatus(302)
            ->assertSessionHasNoErrors();

        $persisted = $schedule->fresh()->items->first();
        $this->assertSame('Crestron HD-MD-400 (HDMI 1)', $persisted->from_location);
        $this->assertSame('Samsung QM65 (HDMI 1)', $persisted->to_location);
        $this->assertSame($srcDevice->id, $persisted->source_device_id);
    }

    public function test_legacy_null_fk_row_persists_unchanged(): void
    {
        [$user, $project, $schedule] = $this->makeFixture();

        $this->actingAs($user)
            ->put(route('cable-schedules.update', $schedule), [
                'items' => [
                    [
                        'cable_id'      => 'CAB-LEGACY',
                        'from_location' => 'Mains via 13A spur',
                        'to_location'   => 'AV rack',
                        'cable_type'    => 'IEC C13',
                    ],
                ],
            ])
            ->assertStatus(302)
            ->assertSessionHasNoErrors();

        $persisted = $schedule->fresh()->items->first();
        $this->assertNotNull($persisted);
        $this->assertNull($persisted->source_device_id);
        $this->assertNull($persisted->source_port_id);
        $this->assertNull($persisted->dest_device_id);
        $this->assertNull($persisted->dest_port_id);
        $this->assertSame('Mains via 13A spur', $persisted->from_location);
    }

    /**
     * @return array{0: User, 1: Project, 2: CableSchedule, 3: Device, 4: Device, 5: DevicePort, 6: DevicePort}
     */
    private function makeFixture(): array
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $schedule = CableSchedule::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => $project->name,
            'status'       => CableSchedule::STATUS_DRAFT,
        ]);

        // Two devices in the SAME project so cross-project guard never fires here.
        $srcDevice = Device::create([
            'project_id'   => $project->id,
            'description'  => 'Crestron HD-MD-400 HDMI Multiformat Receiver',
            'manufacturer' => 'Crestron',
            'model'        => 'HD-MD-400',
            'qty'          => 1,
        ]);
        $dstDevice = Device::create([
            'project_id'   => $project->id,
            'description'  => 'Samsung QM65 65" 4K Display',
            'manufacturer' => 'Samsung',
            'model'        => 'QM65',
            'qty'          => 1,
        ]);

        $stencil = DeviceStencil::create([
            'part_number'  => 'hd-md-400',
            'manufacturer' => 'Crestron',
            'model'        => 'HD-MD-400',
            'mxgraph_xml'  => '<shape/>',
            'source'       => DeviceStencil::SOURCE_AUTO_GENERATED,
        ]);

        $srcPort = DevicePort::create([
            'device_stencil_id' => $stencil->id,
            'label'             => 'HDMI 1',
            'side'              => DevicePort::SIDE_LEFT,
            'connector_type'    => 'hdmi',
            'signal_type'       => 'video',
            'direction'         => DevicePort::DIRECTION_IN,
            'sort_order'        => 0,
            'port_id'           => 'hdmi-1',
        ]);

        $dstPort = DevicePort::create([
            'device_stencil_id' => $stencil->id,
            'label'             => 'HDMI 1',
            'side'              => DevicePort::SIDE_RIGHT,
            'connector_type'    => 'hdmi',
            'signal_type'       => 'video',
            'direction'         => DevicePort::DIRECTION_OUT,
            'sort_order'        => 1,
            'port_id'           => 'hdmi-out-1',
        ]);

        return [$user, $project, $schedule, $srcDevice, $dstDevice, $srcPort, $dstPort];
    }
}
