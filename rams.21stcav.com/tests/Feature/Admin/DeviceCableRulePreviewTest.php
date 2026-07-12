<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\DeviceCableRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quick task 260712-ip3 — admin rule preview endpoint.
 *
 * `GET /admin/device-cable-rules/preview?equipment=...&length_m=...`
 * returns a JSON body describing which rule wins for the given
 * equipment name + optional length, PLUS a full walker trace so admins
 * can eyeball rule behaviour without SSH-ing to the box. Read-only;
 * persists nothing. Route is registered BEFORE the resource route so
 * the string `preview` isn't caught by the `{deviceCableRule}` param.
 *
 * The seeder is loaded in setUp() so the trace runs against the
 * canonical 20-row set — including the 260712-ip3 negative_keywords
 * exclusion lists on rules 61 / 70 / 80.
 */
class DeviceCableRulePreviewTest extends TestCase
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

    public function test_preview_endpoint_requires_admin_auth(): void
    {
        // Unauthenticated → redirected to login.
        $this->get(route('admin.device-cable-rules.preview', ['equipment' => 'Cisco Room Kit']))
            ->assertRedirect(route('login'));

        // Non-admin authenticated → 403.
        $this->actingAs($this->user)
            ->get(route('admin.device-cable-rules.preview', ['equipment' => 'Cisco Room Kit']))
            ->assertForbidden();
    }

    public function test_preview_endpoint_validates_equipment_field(): void
    {
        $this->actingAs($this->admin)
            ->getJson(route('admin.device-cable-rules.preview'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['equipment']);
    }

    public function test_preview_endpoint_returns_matched_rule_and_trace_for_codec(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson(route('admin.device-cable-rules.preview', [
                'equipment' => 'Cisco Codec Room Kit Pro',
            ]))
            ->assertOk();

        $body = $response->json();

        $this->assertSame(70, $body['matched_priority'],
            'Cisco Codec Room Kit Pro must match the priority 70 VC codec rule.');
        $this->assertSame('video', $body['signal_type']);
        $this->assertNotEmpty($body['trace']);
        $this->assertContains('matched', array_column($body['trace'], 'verdict'),
            'Trace must contain exactly one matched verdict when a rule wins.');
    }

    public function test_preview_endpoint_returns_tbc_shape_when_no_rule_matches(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson(route('admin.device-cable-rules.preview', [
                'equipment' => 'Nonsense Random Gadget XYZ',
            ]))
            ->assertOk();

        $body = $response->json();

        $this->assertNull($body['matched_rule_id']);
        $this->assertNull($body['matched_priority']);
        $this->assertSame('TBC', $body['cable_type']);
        $this->assertSame('unknown', $body['signal_type']);

        // Every trace row should be a skipped_keywords verdict — the walker
        // inspected every active rule and none had a positive keyword hit.
        foreach ($body['trace'] as $row) {
            $this->assertSame('skipped_keywords', $row['verdict'],
                'When no rule matches, every trace row must be skipped_keywords.');
        }
    }

    public function test_preview_endpoint_records_negative_skip_reason(): void
    {
        // Real-world 260712-ip3 regression: Logitech USB 3.0 Webcam used to
        // hijack the priority 70 codec rule on the `logitech` keyword. With
        // the exclusion list in place, the walker should skip 70 with a
        // skipped_negative verdict AND fall through to the priority 141
        // USB 3 rule.
        $response = $this->actingAs($this->admin)
            ->getJson(route('admin.device-cable-rules.preview', [
                'equipment' => 'Logitech USB 3.0 Webcam',
            ]))
            ->assertOk();

        $body = $response->json();

        $this->assertSame(141, $body['matched_priority'],
            'Logitech USB 3.0 Webcam must route to the priority 141 USB 3 rule after 260712-ip3.');
        $this->assertSame('usb', $body['signal_type']);

        // Find the codec rule trace row and confirm it was skipped_negative.
        $codecRow = null;
        foreach ($body['trace'] as $row) {
            if ($row['priority'] === 70) {
                $codecRow = $row;
                break;
            }
        }
        $this->assertNotNull($codecRow, 'Codec rule must appear in the trace.');
        $this->assertSame('skipped_negative', $codecRow['verdict']);
        $this->assertStringContainsString('negative_keywords', $codecRow['reason'],
            'Reason field must name the negative_keywords list.');
    }

    public function test_preview_endpoint_returns_tier_used_when_length_supplied(): void
    {
        // Priority 70 codec has 2 tiers: max_m 90 (Cat6 PoE) and
        // max_m 300 (Fibre + PoE media converter). At 200m the second
        // tier wins (200 > 90 but 200 ≤ 300).
        $response = $this->actingAs($this->admin)
            ->getJson(route('admin.device-cable-rules.preview', [
                'equipment' => 'Cisco Codec Room Kit',
                'length_m'  => 200,
            ]))
            ->assertOk();

        $body = $response->json();

        $this->assertSame(70, $body['matched_priority']);
        $this->assertNotNull($body['tier_used']);
        $this->assertSame(300, (int) $body['tier_used']['max_m']);
        // Cable_type should reflect the tier override.
        $this->assertStringContainsStringIgnoringCase('fibre', $body['cable_type']);
    }
}
