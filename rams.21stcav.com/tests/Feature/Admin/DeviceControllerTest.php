<?php

namespace Tests\Feature\Admin;

use App\Models\Device;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quick task 260711-q7q — Tier 4 admin device editor.
 *
 * Six feature tests covering the admin gate, index filter/search,
 * edit form rendering, and update-writeback of signal_role +
 * is_critical + PoE metadata + room_name. Device rows are created
 * inline (no factory shipped for Device — the label-photo capture
 * flow + import pipelines are the runtime creation sites).
 */
class DeviceControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $user;

    private Project $projectA;

    private Project $projectB;

    private Device $deviceA1;

    private Device $deviceA2;

    private Device $deviceB1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->user  = User::factory()->create(['role' => 'user']);

        $this->projectA = Project::factory()->create(['name' => 'Alpha Project']);
        $this->projectB = Project::factory()->create(['name' => 'Bravo Project']);

        $this->deviceA1 = Device::create([
            'project_id'   => $this->projectA->id,
            'room_name'    => 'Boardroom',
            'manufacturer' => 'Samsung',
            'model'        => 'QM85',
            'part_no'      => 'SAM-QM85',
            'description'  => 'Samsung QM85 4K display',
            'qty'          => 1,
        ]);

        $this->deviceA2 = Device::create([
            'project_id'   => $this->projectA->id,
            'room_name'    => 'Comms Room',
            'manufacturer' => 'Cisco',
            'model'        => 'Room Kit Pro',
            'part_no'      => 'CIS-RKP',
            'description'  => 'Cisco Room Kit Pro codec',
            'qty'          => 1,
        ]);

        $this->deviceB1 = Device::create([
            'project_id'   => $this->projectB->id,
            'room_name'    => 'Main Hall',
            'manufacturer' => 'Q-Sys',
            'model'        => 'Core Nano',
            'part_no'      => 'QSC-NANO',
            'description'  => 'Q-Sys Core Nano DSP',
            'qty'          => 1,
        ]);
    }

    // ── 1. admin gate ─────────────────────────────────────────────────────

    public function test_non_admin_cannot_access_admin_devices_index(): void
    {
        $this->actingAs($this->user)
            ->get(route('admin.devices.index'))
            ->assertForbidden();
    }

    // ── 2. index renders + orders + shows signal role badge ───────────────

    public function test_admin_index_renders_ordered_paginated_list(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.devices.index'))
            ->assertOk();

        // Every seeded device manufacturer appears in the HTML.
        $response->assertSee('Samsung');
        $response->assertSee('Cisco');
        $response->assertSee('Q-Sys');

        // signal-role badge column renders — every seeded device is
        // unclassified so the muted "Unclassified" pill shows.
        $response->assertSee('dv-badge-role-unclassified', false);
        $response->assertSee('Unclassified');
    }

    // ── 3. project_id filter ─────────────────────────────────────────────

    public function test_project_id_filter_narrows_to_that_project_only(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.devices.index', ['project_id' => $this->projectA->id]))
            ->assertOk();

        // Project A devices present.
        $response->assertSee('Samsung');
        $response->assertSee('Cisco');
        // Project B device absent.
        $response->assertDontSee('Q-Sys');
        $response->assertDontSee('QSC-NANO');
    }

    // ── 4. free-text search ──────────────────────────────────────────────

    public function test_search_matches_manufacturer_or_model_or_part_no(): void
    {
        // Manufacturer match.
        $this->actingAs($this->admin)
            ->get(route('admin.devices.index', ['q' => 'samsung']))
            ->assertOk()
            ->assertSee('Samsung')
            ->assertDontSee('Cisco')
            ->assertDontSee('Q-Sys');

        // Part-no match (case-insensitive).
        $this->actingAs($this->admin)
            ->get(route('admin.devices.index', ['q' => 'qsc-nano']))
            ->assertOk()
            ->assertSee('QSC-NANO')
            ->assertDontSee('Samsung')
            ->assertDontSee('Cisco');

        // Model match.
        $this->actingAs($this->admin)
            ->get(route('admin.devices.index', ['q' => 'Room Kit']))
            ->assertOk()
            ->assertSee('Room Kit Pro')
            ->assertDontSee('Samsung');
    }

    // ── 5. edit form renders every field ─────────────────────────────────

    public function test_edit_form_renders_all_signal_role_pills_and_inputs(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.devices.edit', $this->deviceA1))
            ->assertOk();

        // All four signal-role radio pills present.
        $response->assertSee('name="signal_role"', false);
        $response->assertSee('value="source"', false);
        $response->assertSee('value="destination"', false);
        $response->assertSee('value="processor"', false);
        $response->assertSee('value="unclassified"', false);

        // is_critical checkbox.
        $response->assertSee('name="is_critical"', false);

        // PoE + room_name inputs.
        $response->assertSee('name="pse_budget_w"', false);
        $response->assertSee('name="pd_load_w"', false);
        $response->assertSee('name="room_name"', false);

        // Room name value is pre-populated.
        $response->assertSee('value="Boardroom"', false);
    }

    // ── 6. update persists every field; unclassified → null ──────────────

    public function test_update_persists_all_fields_and_maps_unclassified_to_null(): void
    {
        // Round 1 — classified update.
        $this->actingAs($this->admin)
            ->put(route('admin.devices.update', $this->deviceA1), [
                'room_name'    => 'Comms Room',
                'signal_role'  => 'destination',
                'is_critical'  => '1',
                'pse_budget_w' => '370.0',
                'pd_load_w'    => '',   // empty → null
            ])
            ->assertRedirect(route('admin.devices.index', ['project_id' => $this->projectA->id]));

        $this->deviceA1->refresh();
        $this->assertSame('Comms Room', $this->deviceA1->room_name);
        $this->assertSame('destination', $this->deviceA1->signal_role);
        $this->assertTrue($this->deviceA1->is_critical);
        $this->assertSame(370.0, (float) $this->deviceA1->pse_budget_w);
        $this->assertNull($this->deviceA1->pd_load_w);

        // Round 2 — unclassified submission writes null to signal_role.
        $this->actingAs($this->admin)
            ->put(route('admin.devices.update', $this->deviceA1), [
                'room_name'    => 'Boardroom',
                'signal_role'  => 'unclassified',
                // is_critical omitted → checkbox unchecked → false
                'pse_budget_w' => '',
                'pd_load_w'    => '',
            ])
            ->assertRedirect();

        $this->deviceA1->refresh();
        $this->assertNull($this->deviceA1->signal_role);
        $this->assertFalse($this->deviceA1->is_critical);
        $this->assertSame('Boardroom', $this->deviceA1->room_name);
    }
}
