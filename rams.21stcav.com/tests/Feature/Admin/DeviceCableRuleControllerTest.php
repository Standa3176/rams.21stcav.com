<?php

namespace Tests\Feature\Admin;

use App\Models\DeviceCableRule;
use App\Models\User;
use Database\Seeders\DeviceCableRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Quick task 260711-q7q — admin CRUD for the cable inference rules.
 *
 * The seeder is loaded in setUp() so the index page + cache assertions
 * run against a realistic set of 15 baseline rules. Post-seed cache is
 * flushed by the seeder itself so `Cache::has()` starts false.
 */
class DeviceCableRuleControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->user  = User::factory()->create(['role' => 'user']);

        $this->seed(DeviceCableRulesSeeder::class);
    }

    // ── A. gate ──────────────────────────────────────────────────────────

    public function test_non_admin_cannot_access_rule_index(): void
    {
        $this->actingAs($this->user)
            ->get(route('admin.device-cable-rules.index'))
            ->assertForbidden();
    }

    // ── B. index ─────────────────────────────────────────────────────────

    public function test_admin_index_renders_paginated_rules_ordered_by_priority(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.device-cable-rules.index'))
            ->assertOk();

        // The first seeded rule (priority 10) is display / HDMI.
        $response->assertSee('HDMI 2.0');
        // Priority 130 is the wireless AP — 'mxwapx' is a unique keyword
        // in the keywords column so it proves the last row rendered too.
        $response->assertSee('mxwapx');
    }

    // ── C. create + store ────────────────────────────────────────────────

    public function test_admin_can_create_a_new_rule_via_store(): void
    {
        $baselineCount = DeviceCableRule::count();

        $response = $this->actingAs($this->admin)
            ->post(route('admin.device-cable-rules.store'), [
                'priority'     => 25,
                'keywords_raw' => "netgear m4300\nfibre uplink",
                'cable_type'   => 'Cat6 (fibre uplink)',
                'cores'        => '',
                'signal_type'  => 'network',
                'to_endpoint'  => 'Fibre distribution frame',
                'notes'        => 'Uplink to backbone',
                'is_active'    => '1',
            ]);

        $response->assertRedirect(route('admin.device-cable-rules.index'));

        $this->assertSame($baselineCount + 1, DeviceCableRule::count());

        $rule = DeviceCableRule::where('priority', 25)->first();
        $this->assertNotNull($rule);
        $this->assertSame('Cat6 (fibre uplink)', $rule->cable_type);
        $this->assertSame(['netgear m4300', 'fibre uplink'], (array) $rule->keywords);
        $this->assertTrue($rule->is_active);
    }

    // ── D. update ────────────────────────────────────────────────────────

    public function test_admin_can_update_priority_and_cable_type(): void
    {
        $rule = DeviceCableRule::where('priority', 10)->firstOrFail();

        $this->actingAs($this->admin)
            ->put(route('admin.device-cable-rules.update', $rule), [
                'priority'     => 15,
                'keywords_raw' => "display\nprojector",
                'cable_type'   => 'HDMI 2.1',
                'cores'        => '',
                'signal_type'  => 'video',
                'to_endpoint'  => 'AV Rack / Matrix Switcher',
                'notes'        => 'Updated by admin',
                'is_active'    => '1',
            ])
            ->assertRedirect(route('admin.device-cable-rules.index'));

        $rule->refresh();
        $this->assertSame(15, $rule->priority);
        $this->assertSame('HDMI 2.1', $rule->cable_type);
        $this->assertSame(['display', 'projector'], (array) $rule->keywords);
    }

    // ── E. delete ────────────────────────────────────────────────────────

    public function test_admin_can_delete_a_rule(): void
    {
        $rule = DeviceCableRule::where('priority', 130)->firstOrFail();
        $id = $rule->id;

        $this->actingAs($this->admin)
            ->delete(route('admin.device-cable-rules.destroy', $rule))
            ->assertRedirect(route('admin.device-cable-rules.index'));

        $this->assertNull(DeviceCableRule::find($id));
    }

    // ── F. cache flush on save/delete ────────────────────────────────────

    public function test_saving_or_deleting_a_rule_flushes_the_inference_cache(): void
    {
        // Warm the cache by calling forInference().
        DeviceCableRule::forInference();
        $this->assertTrue(Cache::has(DeviceCableRule::CACHE_KEY),
            'Cache should be warm after forInference().');

        // Any save flushes it.
        $rule = DeviceCableRule::first();
        $rule->update(['notes' => 'edited']);
        $this->assertFalse(Cache::has(DeviceCableRule::CACHE_KEY),
            'saved() event must forget the inference cache.');

        // Re-warm and delete another row.
        DeviceCableRule::forInference();
        $this->assertTrue(Cache::has(DeviceCableRule::CACHE_KEY));

        DeviceCableRule::where('priority', 130)->first()?->delete();
        $this->assertFalse(Cache::has(DeviceCableRule::CACHE_KEY),
            'deleted() event must forget the inference cache.');
    }
}
