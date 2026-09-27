<?php

namespace Tests\Feature\Admin;

use App\Models\LabourResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Labour resources CRUD — Phase 44 Plan 02, reopened to the whole workspace
 * by quick task 260927-lr7.
 *
 * THIS FILE USED TO ASSERT THE OPPOSITE. Phase 44 asserted a non-admin got
 * 403 on all six routes. That was inverted deliberately: the admin gate is
 * why nothing ever linked to the page, why live carried zero labour
 * resources, and why the survey wizard's engineer step showed nobody but
 * "Unassigned". The routes now live in the plain `auth` group.
 *
 * This IS an authorization widening: any signed-in user can now add, edit and
 * deactivate a labour resource, including its `email` and `phone`. Three
 * states are therefore asserted, not one — non-admin (in, and persists),
 * guest (bounced to login, nothing written), admin (unregressed). A test that
 * only proved the non-admin could get in would pass against a page with no
 * gate whatsoever.
 *
 * LR-04 is untouched by this and still binds: a CLIENT is never shown an
 * engineer's phone or email. This is a staff surface behind authentication;
 * the client-facing rule lives in
 * tests/Feature/Security/LabourResourceClientSurfacePrivacyTest.php and this
 * page is deliberately NOT on that test's CLIENT_FACING_PATHS list.
 *
 * Also covers the nav item itself (the thing whose absence made the whole
 * feature dead), index rendering including inactive resources, the
 * server-side roles allow-list, update behaviour, toggleActive in both
 * directions, and the D-02 tripwire: no destroy route, old or new name.
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

    // ── 1. authorization — three states, all asserted ──────────────────────
    //
    // 260927-lr7 WIDENED this surface. Phase 44 asserted "non-admin gets 403"
    // here; that assertion is now inverted on purpose. All three states are
    // rendered, because a test that only proves the non-admin can get in would
    // pass just as happily against a page with no gate at all.
    //
    //   non-admin  -> 200 / persists      (the widening actually works)
    //   guest      -> redirect to login   (the `auth` gate is still there)
    //   admin      -> 200                 (nothing regressed for admins)

    public function test_non_admin_can_read_every_labour_resource_screen(): void
    {
        $resource = LabourResource::factory()->create();

        $this->actingAs($this->user)
            ->get(route('labour-resources.index'))
            ->assertOk();

        $this->actingAs($this->user)
            ->get(route('labour-resources.create'))
            ->assertOk();

        $this->actingAs($this->user)
            ->get(route('labour-resources.edit', $resource))
            ->assertOk();
    }

    public function test_non_admin_can_store_a_labour_resource(): void
    {
        $response = $this->actingAs($this->user)
            ->post(route('labour-resources.store'), [
                'name'  => 'Alison Field',
                'email' => 'alison@example.com',
                'phone' => '01234 111222',
                'roles' => [LabourResource::ROLE_ENGINEER],
            ]);

        $response->assertRedirect(route('labour-resources.index'));

        // The row actually landed — a 302 alone would also be what a rejected
        // request looks like, so assert the persistence, not the redirect.
        $this->assertDatabaseHas('labour_resources', [
            'name'      => 'Alison Field',
            'email'     => 'alison@example.com',
            'is_active' => true,
        ]);
    }

    public function test_non_admin_can_update_and_deactivate_a_labour_resource(): void
    {
        $resource = LabourResource::factory()->create([
            'name'      => 'Zack Original',
            'roles'     => [LabourResource::ROLE_ENGINEER],
            'is_active' => true,
        ]);

        $this->actingAs($this->user)
            ->put(route('labour-resources.update', $resource), [
                'name'  => 'Zack Renamed',
                'roles' => [LabourResource::ROLE_ENGINEER],
            ])
            ->assertRedirect(route('labour-resources.index'));

        $this->assertDatabaseHas('labour_resources', [
            'id'   => $resource->id,
            'name' => 'Zack Renamed',
        ]);

        $this->actingAs($this->user)
            ->post(route('labour-resources.toggle-active', $resource))
            ->assertRedirect();

        // D-02: deactivated, still on file — never deleted.
        $this->assertDatabaseHas('labour_resources', [
            'id'        => $resource->id,
            'is_active' => false,
        ]);
    }

    public function test_guest_cannot_reach_or_write_any_labour_resource_route(): void
    {
        $resource = LabourResource::factory()->create();
        $login    = route('login');

        $this->get(route('labour-resources.index'))->assertRedirect($login);
        $this->get(route('labour-resources.create'))->assertRedirect($login);
        $this->get(route('labour-resources.edit', $resource))->assertRedirect($login);
        $this->post(route('labour-resources.store'), [
            'name'  => 'Guest Intruder',
            'roles' => [LabourResource::ROLE_ENGINEER],
        ])->assertRedirect($login);
        $this->put(route('labour-resources.update', $resource), [
            'name'  => 'Guest Renamed',
            'roles' => [LabourResource::ROLE_ENGINEER],
        ])->assertRedirect($login);
        $this->post(route('labour-resources.toggle-active', $resource))->assertRedirect($login);

        // Nothing the guest sent got through.
        $this->assertDatabaseMissing('labour_resources', ['name' => 'Guest Intruder']);
        $this->assertDatabaseHas('labour_resources', [
            'id'        => $resource->id,
            'name'      => $resource->name,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_still_reach_the_index(): void
    {
        $this->actingAs($this->admin)
            ->get(route('labour-resources.index'))
            ->assertOk();
    }

    // ── 1b. the nav item — the reason the page was unreachable at all ───────
    //
    // The routes existed since Phase 44; nothing linked to them. Assert the
    // top-level link renders for BOTH an admin and a non-admin, on a page a
    // non-admin can actually load (projects.index — every other primary nav
    // link in navigation.blade.php is wrapped in an isAdmin conditional).

    public function test_top_level_labour_nav_link_renders_for_non_admin(): void
    {
        $this->actingAs($this->user)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee('href="' . route('labour-resources.index') . '"', false)
            ->assertSee('Labour');
    }

    public function test_top_level_labour_nav_link_renders_for_admin(): void
    {
        $this->actingAs($this->admin)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee('href="' . route('labour-resources.index') . '"', false);
    }

    // ── 2. index renders active + inactive resources ───────────────────────

    public function test_admin_index_shows_active_and_inactive_resources(): void
    {
        LabourResource::factory()->create(['name' => 'Marcus Okafor', 'is_active' => true]);
        LabourResource::factory()->create(['name' => 'Priya Shah', 'is_active' => false]);

        $response = $this->actingAs($this->admin)
            ->get(route('labour-resources.index'))
            ->assertOk();

        $response->assertSee('Marcus Okafor');
        $response->assertSee('Priya Shah');
        $response->assertSee('Inactive');
    }

    // ── 3. store creates an active resource regardless of is_active input ──

    public function test_store_creates_resource_always_active(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('labour-resources.store'), [
                'name'      => 'Sean Pearce',
                'email'     => 'sean@example.com',
                'phone'     => '01234 567890',
                'roles'     => [LabourResource::ROLE_ENGINEER],
                'is_active' => false, // ignored — always created active
            ]);

        $response->assertRedirect(route('labour-resources.index'));

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
            ->post(route('labour-resources.store'), [
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
            ->put(route('labour-resources.update', $resource), [
                'name'  => $resource->name,
                'email' => $resource->email,
                'phone' => $resource->phone,
                'roles' => [LabourResource::ROLE_ENGINEER, LabourResource::ROLE_PROGRAMMER],
            ]);

        $response->assertRedirect(route('labour-resources.index'));

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
            ->post(route('labour-resources.toggle-active', $resource))
            ->assertRedirect();

        $this->assertDatabaseHas('labour_resources', [
            'id'        => $resource->id,
            'is_active' => false,
        ]);

        $this->actingAs($this->admin)
            ->post(route('labour-resources.toggle-active', $resource))
            ->assertRedirect();

        $this->assertDatabaseHas('labour_resources', [
            'id'        => $resource->id,
            'is_active' => true,
        ]);
    }

    // ── 7. structural tripwire — no destroy route exists (D-02) ────────────

    public function test_no_destroy_route_exists_for_labour_resources(): void
    {
        $this->assertFalse(Route::has('labour-resources.destroy'));

        // 260927-lr7 renamed the route names. Assert the OLD names are gone
        // too, so a resurrected copy of the Phase 44 block cannot smuggle a
        // destroy route — or the old admin-gated paths — back in under a
        // prefix this file no longer looks at.
        $this->assertFalse(Route::has('admin.labour-resources.destroy'));
        $this->assertFalse(Route::has('admin.labour-resources.index'));

        // And the surface really is create/read/update/deactivate only.
        foreach (['index', 'create', 'store', 'edit', 'update', 'toggle-active'] as $action) {
            $this->assertTrue(
                Route::has("labour-resources.{$action}"),
                "labour-resources.{$action} must exist — the nav item links to it."
            );
        }
    }
}
