<?php

namespace Tests\Feature\Admin;

use App\Models\LabourResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase 44 Plan 02 — admin CRUD for labour resources.
 *
 * Covers the admin gate on all six named routes, index rendering (including
 * inactive resources still showing for history), server-side roles
 * allow-list enforcement, update behaviour, toggleActive both directions,
 * and the structural D-02 tripwire: no destroy route exists.
 */
class LabourResourceControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->user  = User::factory()->create(['role' => 'user']);
    }

    // ── 1. admin gate — all six named routes ───────────────────────────────

    public function test_non_admin_cannot_access_any_labour_resource_admin_route(): void
    {
        $resource = LabourResource::factory()->create();

        $this->actingAs($this->user)
            ->get(route('admin.labour-resources.index'))
            ->assertForbidden();

        $this->actingAs($this->user)
            ->get(route('admin.labour-resources.create'))
            ->assertForbidden();

        $this->actingAs($this->user)
            ->post(route('admin.labour-resources.store'), [])
            ->assertForbidden();

        $this->actingAs($this->user)
            ->get(route('admin.labour-resources.edit', $resource))
            ->assertForbidden();

        $this->actingAs($this->user)
            ->put(route('admin.labour-resources.update', $resource), [])
            ->assertForbidden();

        $this->actingAs($this->user)
            ->post(route('admin.labour-resources.toggle-active', $resource))
            ->assertForbidden();
    }

    // ── 2. index renders active + inactive resources ───────────────────────

    public function test_admin_index_shows_active_and_inactive_resources(): void
    {
        LabourResource::factory()->create(['name' => 'Marcus Okafor', 'is_active' => true]);
        LabourResource::factory()->create(['name' => 'Priya Shah', 'is_active' => false]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.labour-resources.index'))
            ->assertOk();

        $response->assertSee('Marcus Okafor');
        $response->assertSee('Priya Shah');
        $response->assertSee('Inactive');
    }

    // ── 3. store creates an active resource regardless of is_active input ──

    public function test_store_creates_resource_always_active(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('admin.labour-resources.store'), [
                'name'      => 'Sean Pearce',
                'email'     => 'sean@example.com',
                'phone'     => '01234 567890',
                'roles'     => [LabourResource::ROLE_ENGINEER],
                'is_active' => false, // ignored — always created active
            ]);

        $response->assertRedirect(route('admin.labour-resources.index'));

        $this->assertDatabaseHas('labour_resources', [
            'name'      => 'Sean Pearce',
            'email'     => 'sean@example.com',
            'phone'     => '01234 567890',
            'is_active' => true,
        ]);
    }

    // ── 4. store rejects an out-of-allow-list role value ────────────────────

    public function test_store_rejects_role_outside_allow_list(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('admin.labour-resources.store'), [
                'name'  => 'Robert Allan',
                'roles' => ['director'],
            ]);

        $response->assertSessionHasErrors('roles.0');

        $this->assertDatabaseMissing('labour_resources', [
            'name' => 'Robert Allan',
        ]);
    }

    // ── 5. update persists a changed roles array without touching is_active ─

    public function test_update_persists_roles_change_without_touching_is_active(): void
    {
        $resource = LabourResource::factory()->create([
            'roles'     => [LabourResource::ROLE_ENGINEER],
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->put(route('admin.labour-resources.update', $resource), [
                'name'  => $resource->name,
                'email' => $resource->email,
                'phone' => $resource->phone,
                'roles' => [LabourResource::ROLE_ENGINEER, LabourResource::ROLE_PROGRAMMER],
            ]);

        $response->assertRedirect(route('admin.labour-resources.index'));

        $resource->refresh();
        $this->assertSame(
            [LabourResource::ROLE_ENGINEER, LabourResource::ROLE_PROGRAMMER],
            $resource->roles
        );
        $this->assertTrue($resource->is_active);
    }

    // ── 6. toggleActive flips both directions, row always survives ─────────

    public function test_toggle_active_flips_both_directions_and_row_survives(): void
    {
        $resource = LabourResource::factory()->create(['is_active' => true]);

        $this->actingAs($this->admin)
            ->post(route('admin.labour-resources.toggle-active', $resource))
            ->assertRedirect();

        $this->assertDatabaseHas('labour_resources', [
            'id'        => $resource->id,
            'is_active' => false,
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.labour-resources.toggle-active', $resource))
            ->assertRedirect();

        $this->assertDatabaseHas('labour_resources', [
            'id'        => $resource->id,
            'is_active' => true,
        ]);
    }

    // ── 7. structural tripwire — no destroy route exists (D-02) ────────────

    public function test_no_destroy_route_exists_for_labour_resources(): void
    {
        $this->assertFalse(Route::has('admin.labour-resources.destroy'));
    }
}
