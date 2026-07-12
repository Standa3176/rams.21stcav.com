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

    // ── G. 260712-euh length_tiers CRUD ──────────────────────────────────

    public function test_admin_can_store_a_rule_with_length_tiers(): void
    {
        // Post two tiers in DESCENDING order — FormRequest must sort them.
        $tiersJson = json_encode([
            ['max_m' => 70, 'cable_type' => 'Cat6a HDBaseT', 'cores' => null, 'to_endpoint' => 'HDBaseT rx', 'notes' => 'medium'],
            ['max_m' => 15, 'cable_type' => 'HDMI 2.0',      'cores' => null, 'to_endpoint' => 'AV rack',   'notes' => 'short'],
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.device-cable-rules.store'), [
                'priority'     => 26,
                'keywords_raw' => "some display\nvideo",
                'cable_type'   => 'HDMI 2.0',
                'cores'        => '',
                'signal_type'  => 'video',
                'to_endpoint'  => 'AV rack',
                'notes'        => 'display note',
                'is_active'    => '1',
                'length_tiers' => $tiersJson,
            ])
            ->assertRedirect(route('admin.device-cable-rules.index'));

        $rule = DeviceCableRule::where('priority', 26)->firstOrFail();
        $this->assertIsArray($rule->length_tiers);
        $this->assertCount(2, $rule->length_tiers);
        // Sorted ascending on max_m: tier 0 must be the 15m one.
        $this->assertSame(15, (int) $rule->length_tiers[0]['max_m']);
        $this->assertSame(70, (int) $rule->length_tiers[1]['max_m']);
    }

    public function test_admin_can_update_length_tiers_on_existing_rule(): void
    {
        $rule = DeviceCableRule::where('priority', 10)->firstOrFail();

        // Post 4 tiers in mixed order to prove the sort.
        $tiersJson = json_encode([
            ['max_m' => 200, 'cable_type' => 'Fibre extender',     'notes' => 'long'],
            ['max_m' => 40,  'cable_type' => 'HDBaseT',            'notes' => 'medium'],
            ['max_m' => 10,  'cable_type' => 'HDMI 2.0',           'notes' => 'short'],
            ['max_m' => 100, 'cable_type' => 'HDBaseT-over-fibre', 'notes' => 'long-ish'],
        ]);

        $this->actingAs($this->admin)
            ->put(route('admin.device-cable-rules.update', $rule), [
                'priority'     => 10,
                'keywords_raw' => "display\nprojector",
                'cable_type'   => 'HDMI 2.0',
                'cores'        => '',
                'signal_type'  => 'video',
                'to_endpoint'  => 'AV Rack / Matrix Switcher',
                'notes'        => 'admin edited',
                'is_active'    => '1',
                'length_tiers' => $tiersJson,
            ])
            ->assertRedirect(route('admin.device-cable-rules.index'));

        $rule->refresh();
        $this->assertCount(4, $rule->length_tiers);
        $this->assertSame([10, 40, 100, 200], array_map(
            static fn ($t) => (int) $t['max_m'],
            $rule->length_tiers,
        ));
    }

    public function test_store_rejects_length_tier_with_zero_max_m(): void
    {
        $tiersJson = json_encode([
            ['max_m' => 0, 'cable_type' => 'Invalid tier'],
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('admin.device-cable-rules.store'), [
                'priority'     => 27,
                'keywords_raw' => "keyword",
                'cable_type'   => 'HDMI 2.0',
                'cores'        => '',
                'signal_type'  => 'video',
                'to_endpoint'  => 'AV rack',
                'notes'        => '',
                'is_active'    => '1',
                'length_tiers' => $tiersJson,
            ]);

        $response->assertSessionHasErrors('length_tiers.0.max_m');
        $this->assertNull(DeviceCableRule::where('priority', 27)->first());
    }

    public function test_admin_can_clear_length_tiers_by_posting_empty_array(): void
    {
        $rule = DeviceCableRule::where('priority', 10)->firstOrFail();
        // Sanity: seeder gave rule 10 three tiers.
        $this->assertNotEmpty($rule->length_tiers);

        $this->actingAs($this->admin)
            ->put(route('admin.device-cable-rules.update', $rule), [
                'priority'     => 10,
                'keywords_raw' => "display\nprojector",
                'cable_type'   => 'HDMI 2.0',
                'cores'        => '',
                'signal_type'  => 'video',
                'to_endpoint'  => 'AV Rack / Matrix Switcher',
                'notes'        => 'cleared',
                'is_active'    => '1',
                'length_tiers' => '[]',
            ])
            ->assertRedirect(route('admin.device-cable-rules.index'));

        $rule->refresh();
        // Empty array collapses to null via FormRequest normalisation.
        $this->assertNull($rule->length_tiers);
    }

    // ── H. 260712-ip3 negative_keywords CRUD ─────────────────────────────

    public function test_admin_can_store_rule_with_negative_keywords(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.device-cable-rules.store'), [
                'priority'              => 55,
                'keywords_raw'          => "widget",
                'negative_keywords_raw' => "usb 3\nusb-c webcam",
                'cable_type'            => 'Widget Cable',
                'cores'                 => '',
                'signal_type'           => 'video',
                'to_endpoint'           => 'Widget host',
                'notes'                 => 'test',
                'is_active'             => '1',
            ])
            ->assertRedirect(route('admin.device-cable-rules.index'));

        $rule = DeviceCableRule::where('priority', 55)->firstOrFail();
        $this->assertSame(['usb 3', 'usb-c webcam'], (array) $rule->negative_keywords);
    }

    public function test_admin_can_clear_negative_keywords_via_empty_textarea(): void
    {
        // Seed a rule with a non-empty exclusion list, then PUT with an
        // empty negative_keywords_raw — model must store null (not []).
        $rule = DeviceCableRule::create([
            'priority'          => 56,
            'keywords'          => ['widget'],
            'negative_keywords' => ['usb 3'],
            'cable_type'        => 'Widget Cable',
            'signal_type'       => 'video',
            'to_endpoint'       => 'Widget host',
            'notes'             => 'seeded',
            'is_active'         => true,
        ]);
        $this->assertSame(['usb 3'], (array) $rule->negative_keywords);

        $this->actingAs($this->admin)
            ->put(route('admin.device-cable-rules.update', $rule), [
                'priority'              => 56,
                'keywords_raw'          => "widget",
                'negative_keywords_raw' => '',
                'cable_type'            => 'Widget Cable',
                'cores'                 => '',
                'signal_type'           => 'video',
                'to_endpoint'           => 'Widget host',
                'notes'                 => 'cleared',
                'is_active'             => '1',
            ])
            ->assertRedirect(route('admin.device-cable-rules.index'));

        $rule->refresh();
        $this->assertNull($rule->negative_keywords,
            'An empty negative_keywords_raw payload must collapse to null on the model.');
    }

    public function test_seeded_codec_rule_has_expected_negative_keywords(): void
    {
        // The seeder in this test is the same one loaded on live — this
        // pins that priority 70's exclusion list is exactly what the
        // deploy checklist expects.
        $codec = DeviceCableRule::where('priority', 70)->firstOrFail();

        $this->assertSame(
            ['usb 3', 'usb 3.0', 'usb-c webcam', 'usb hub'],
            (array) $codec->negative_keywords,
        );
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
