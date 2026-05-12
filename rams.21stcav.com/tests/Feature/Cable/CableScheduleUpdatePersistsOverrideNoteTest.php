<?php

namespace Tests\Feature\Cable;

use App\Models\CableSchedule;
use App\Models\Device;
use App\Models\DevicePort;
use App\Models\DeviceStencil;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 22 Plan 02 Task 1 — DRAW-39 override-note persistence.
 *
 * The controller never enforces compatibility — DRAW-39 is explicit that
 * incompatible pairs are a WARNING (client-side modal banner) not a hard
 * block. The server-side validation rule for connector_override_note is
 * `nullable, string, max:500`, NOT conditionally required.
 *
 * If the engineer bypasses the picker entirely and submits an incompatible
 * pair without an override note, the row still saves. The picker UI is the
 * enforcer; the server is forgiving.
 *
 * @see app/Http/Controllers/CableScheduleController.php@update
 */
class CableScheduleUpdatePersistsOverrideNoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_override_note_persists_when_ports_incompatible(): void
    {
        [$user, $schedule, $srcDevice, $dstDevice, $hdmiPort, $rj45Port] = $this->makeIncompatibleFixture();

        $this->actingAs($user)
            ->put(route('cable-schedules.update', $schedule), [
                'items' => [
                    [
                        'cable_id'                => 'CAB-001',
                        'from_location'           => 'Crestron HD-MD-400 (HDMI 1)',
                        'to_location'             => 'Samsung QM65 (LAN 1)',
                        'cable_type'              => 'HDBaseT',
                        'source_device_id'        => $srcDevice->id,
                        'source_port_id'          => $hdmiPort->id,
                        'dest_device_id'          => $dstDevice->id,
                        'dest_port_id'            => $rj45Port->id,
                        'connector_override_note' => 'Active HDBaseT extender installed in cable run',
                    ],
                ],
            ])
            ->assertStatus(302)
            ->assertSessionHasNoErrors();

        $persisted = $schedule->fresh()->items->first();
        $this->assertSame('Active HDBaseT extender installed in cable run', $persisted->connector_override_note);
    }

    public function test_incompatible_pair_saves_even_without_override_note(): void
    {
        // DRAW-39 — server is not the gate; this proves the server accepts
        // the save even without a note (the modal is what enforces the note).
        [$user, $schedule, $srcDevice, $dstDevice, $hdmiPort, $rj45Port] = $this->makeIncompatibleFixture();

        $this->actingAs($user)
            ->put(route('cable-schedules.update', $schedule), [
                'items' => [
                    [
                        'source_device_id' => $srcDevice->id,
                        'source_port_id'   => $hdmiPort->id,
                        'dest_device_id'   => $dstDevice->id,
                        'dest_port_id'     => $rj45Port->id,
                        // No override note — server still accepts.
                    ],
                ],
            ])
            ->assertStatus(302)
            ->assertSessionHasNoErrors();

        $persisted = $schedule->fresh()->items->first();
        $this->assertSame($hdmiPort->id, $persisted->source_port_id);
        $this->assertNull($persisted->connector_override_note);
    }

    public function test_override_note_over_500_chars_returns_422(): void
    {
        [$user, $schedule, $srcDevice, $dstDevice, $hdmiPort, $rj45Port] = $this->makeIncompatibleFixture();

        $this->actingAs($user)
            ->put(route('cable-schedules.update', $schedule), [
                'items' => [
                    [
                        'source_device_id'        => $srcDevice->id,
                        'source_port_id'          => $hdmiPort->id,
                        'dest_device_id'          => $dstDevice->id,
                        'dest_port_id'            => $rj45Port->id,
                        'connector_override_note' => str_repeat('A', 501),
                    ],
                ],
            ])
            ->assertStatus(302)
            ->assertSessionHasErrors(['items.0.connector_override_note']);
    }

    /**
     * @return array{0: User, 1: CableSchedule, 2: Device, 3: Device, 4: DevicePort, 5: DevicePort}
     */
    private function makeIncompatibleFixture(): array
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $schedule = CableSchedule::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => $project->name,
            'status'       => CableSchedule::STATUS_DRAFT,
        ]);

        $srcDevice = Device::create([
            'project_id' => $project->id, 'description' => 'Crestron HD-MD-400',
            'manufacturer' => 'Crestron', 'model' => 'HD-MD-400', 'qty' => 1,
        ]);
        $dstDevice = Device::create([
            'project_id' => $project->id, 'description' => 'Samsung QM65',
            'manufacturer' => 'Samsung', 'model' => 'QM65', 'qty' => 1,
        ]);

        $stencil = DeviceStencil::create([
            'part_number'  => 'hd-md-400',
            'manufacturer' => 'Crestron',
            'model'        => 'HD-MD-400',
            'mxgraph_xml'  => '<shape/>',
            'source'       => DeviceStencil::SOURCE_AUTO_GENERATED,
        ]);

        $hdmiPort = DevicePort::create([
            'device_stencil_id' => $stencil->id,
            'label'             => 'HDMI 1',
            'side'              => DevicePort::SIDE_LEFT,
            'connector_type'    => 'hdmi',
            'signal_type'       => 'video',
            'direction'         => DevicePort::DIRECTION_IN,
            'sort_order'        => 0,
            'port_id'           => 'hdmi-1',
        ]);
        $rj45Port = DevicePort::create([
            'device_stencil_id' => $stencil->id,
            'label'             => 'LAN 1',
            'side'              => DevicePort::SIDE_RIGHT,
            'connector_type'    => 'rj45',
            'signal_type'       => 'network',
            'direction'         => DevicePort::DIRECTION_IO,
            'sort_order'        => 1,
            'port_id'           => 'lan-1',
        ]);

        return [$user, $schedule, $srcDevice, $dstDevice, $hdmiPort, $rj45Port];
    }
}
