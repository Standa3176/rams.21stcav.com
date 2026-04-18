<?php

namespace Tests\Unit\Services\Worksheet;

use App\Services\Worksheet\WorksheetClassifier;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for WorksheetClassifier.
 *
 * Pure function, no Laravel container dependencies — constructed with an
 * explicit taxonomy override so the test suite can't drift as config
 * evolves. Every tier 1–5 path is exercised plus warranty / mount / existing
 * sentinel behaviours.
 */
class WorksheetClassifierTest extends TestCase
{
    private WorksheetClassifier $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new WorksheetClassifier($this->taxonomy());
    }

    // ─── Tier 1 ──────────────────────────────────────────────────────────────

    public function test_tier1_exact_sku_match_by_part_no(): void
    {
        $v = $this->svc->classify(['part_no' => 'CH-MTM1U', 'name' => 'Some mount'], []);
        $this->assertSame('display', $v['category']);
        $this->assertSame(1, $v['tier']);
        $this->assertFalse($v['fallback_used']);
    }

    public function test_tier1_sku_hit_substring_in_name(): void
    {
        $v = $this->svc->classify(['name' => 'Chief CH-MTM1U Flat Wall Mount'], []);
        $this->assertSame('display', $v['category']);
        $this->assertSame(1, $v['tier']);
    }

    // ─── Tier 2 ──────────────────────────────────────────────────────────────

    public function test_tier2_manufacturer_plus_keyword(): void
    {
        $v = $this->svc->classify(
            ['manufacturer' => 'Samsung', 'name' => 'Samsung QM75B 75" UHD Display'],
            [],
        );
        $this->assertSame('display', $v['category']);
        $this->assertSame(2, $v['tier']);
    }

    public function test_tier2_logitech_rally_bar_is_vc(): void
    {
        $v = $this->svc->classify(
            ['manufacturer' => 'Logitech', 'name' => 'Logitech Rally Bar'],
            [],
        );
        $this->assertSame('video_conferencing', $v['category']);
        $this->assertSame(2, $v['tier']);
    }

    public function test_tier2_netgear_managed_switch_is_network(): void
    {
        $v = $this->svc->classify(
            ['manufacturer' => 'Netgear', 'name' => 'Netgear Managed PoE Switch'],
            [],
        );
        $this->assertSame('network', $v['category']);
    }

    // ─── Tier 3 ──────────────────────────────────────────────────────────────

    public function test_tier3_keyword_fallback(): void
    {
        $v = $this->svc->classify(['name' => 'Unbranded loudspeaker'], []);
        $this->assertSame('audio', $v['category']);
        $this->assertSame(3, $v['tier']);
        $this->assertTrue($v['fallback_used']);
    }

    public function test_tier3_control_partition_sensor_even_without_manufacturer(): void
    {
        $v = $this->svc->classify(['name' => 'Partition sensor unit'], []);
        $this->assertSame('control', $v['category']);
        $this->assertSame(3, $v['tier']);
    }

    // ─── Tier 4 — mount inheritance ──────────────────────────────────────────

    public function test_tier4_mount_inherits_from_room_peer(): void
    {
        $context = [
            ['name' => 'Samsung 75" Display', 'manufacturer' => 'Samsung'],
            ['name' => 'Chief Flat Wall Mount', 'manufacturer' => 'Chief'],
        ];
        $v = $this->svc->classify($context[1], $context);
        $this->assertSame('display', $v['category']);
        $this->assertSame(4, $v['tier']);
    }

    public function test_tier4_mount_with_self_hint_uses_self(): void
    {
        // Mount whose own name contains a target-category hint.
        $v = $this->svc->classify(['name' => 'Chief Display Wall Mount', 'manufacturer' => 'Chief'], []);
        $this->assertSame('display', $v['category']);
    }

    public function test_tier4_mount_with_no_context_falls_to_mount_accessory(): void
    {
        $v = $this->svc->classify(['name' => 'Generic Wall Bracket', 'manufacturer' => 'Chief'], []);
        $this->assertSame('mount_accessory', $v['category']);
        $this->assertSame(4, $v['tier']);
    }

    // ─── Tier 4 — warranty ───────────────────────────────────────────────────

    public function test_tier4_warranty_with_no_parent_is_warranty_service(): void
    {
        $v = $this->svc->classify(['name' => '3 Year Extended Warranty'], []);
        $this->assertSame('warranty_service', $v['category']);
        $this->assertSame(4, $v['tier']);
    }

    public function test_tier4_warranty_inherits_from_preceding_context(): void
    {
        $context = [
            ['name' => 'Samsung QM75B Display', 'manufacturer' => 'Samsung'],
            ['name' => 'Extended Warranty'],
        ];
        $v = $this->svc->classify($context[1], $context);
        $this->assertSame('display', $v['category']);
        $this->assertSame(4, $v['tier']);
        $this->assertStringStartsWith('Warranty inherits', $v['reason']);
    }

    public function test_warranty_that_classifies_on_own_text_does_not_need_parent(): void
    {
        // "Cisco Smartnet for Catalyst Switch" → network on its own text
        $v = $this->svc->classify(
            ['name' => 'Cisco Smartnet for Catalyst Switch', 'manufacturer' => 'Cisco'],
            [],
        );
        $this->assertSame('network', $v['category']);
        $this->assertStringContainsString('warranty', $v['reason']);
    }

    // ─── Tier 4 — existing-unknown ───────────────────────────────────────────

    public function test_existing_with_type_hint_classifies_by_type(): void
    {
        $v = $this->svc->classify(['name' => 'Utilise existing Samsung display'], []);
        $this->assertSame('display', $v['category']);
        $this->assertStringContainsString('existing', $v['reason']);
    }

    public function test_existing_unknown_when_no_type_hint(): void
    {
        $v = $this->svc->classify(['name' => 'Utilise existing client equipment'], []);
        $this->assertSame('existing_unknown', $v['category']);
        $this->assertSame(4, $v['tier']);
    }

    // ─── Tier 5 — truly unknown ──────────────────────────────────────────────

    public function test_tier5_unclassified_when_nothing_matches(): void
    {
        $v = $this->svc->classify(['name' => 'Widget Thing 42'], []);
        $this->assertSame('unclassified', $v['category']);
        $this->assertSame(5, $v['tier']);
        $this->assertTrue($v['fallback_used']);
    }

    // ─── Shadow run aggregation ──────────────────────────────────────────────

    public function test_shadow_run_produces_histogram_and_tier_counts(): void
    {
        $rooms = [
            [
                'name' => 'Boardroom',
                'equipment' => [
                    ['name' => 'Samsung QM75B Display', 'manufacturer' => 'Samsung'],
                    ['name' => 'Logitech Rally Bar',   'manufacturer' => 'Logitech'],
                    ['name' => 'Widget Thing 42'],    // unclassified
                ],
            ],
        ];
        $t = $this->svc->runShadow($rooms);

        $this->assertSame(3, $t['total_items']);
        $this->assertSame(1, $t['histogram']['display']);
        $this->assertSame(1, $t['histogram']['video_conferencing']);
        $this->assertSame(1, $t['histogram']['unclassified']);
        $this->assertSame(1, $t['unclassified_count']);
        $this->assertSame(2, $t['tier_counts'][2]);
        $this->assertSame(1, $t['tier_counts'][5]);
    }

    // ─── Fixtures ────────────────────────────────────────────────────────────

    private function taxonomy(): array
    {
        return [
            'categories' => [
                'display'            => 'Display',
                'video_conferencing' => 'Video Conferencing',
                'audio'              => 'Audio',
                'control'            => 'Control & Automation',
                'rack'               => 'Rack & Infrastructure',
                'network'            => 'Network',
            ],
            'sentinels' => [
                'unclassified'     => 'Unclassified',
                'existing_unknown' => 'Existing',
                'warranty_service' => 'Warranty',
                'mount_accessory'  => 'Mount',
            ],
            'sku_map' => ['CH-MTM1U' => 'display'],
            'manufacturer_rules' => [
                ['manufacturer' => ['samsung'], 'keywords' => ['display', 'qm', 'uhd'], 'category' => 'display'],
                ['manufacturer' => ['logitech'], 'keywords' => ['rally', 'room kit'], 'category' => 'video_conferencing'],
                ['manufacturer' => ['cisco'],   'keywords' => ['catalyst', 'switch', 'smartnet'], 'category' => 'network'],
                ['manufacturer' => ['netgear'], 'keywords' => ['switch', 'poe'], 'category' => 'network'],
                ['manufacturer' => ['chief'],   'keywords' => ['mount', 'bracket'], 'category' => 'mount_inherit'],
            ],
            'keyword_rules' => [
                'audio'   => ['loudspeaker', 'speaker', 'microphone'],
                'control' => ['partition sensor', 'control processor'],
            ],
            'mount_inherit_keywords' => [
                'display'            => ['display', 'screen', 'qm'],
                'video_conferencing' => ['rally', 'codec'],
                'audio'              => ['speaker', 'microphone'],
            ],
            'warranty_keywords'  => ['warranty', 'smartnet', 'care plan'],
            'existing_keywords'  => ['utilise existing', 'utilize existing', 'retained'],
            'exclude_keywords'   => ['installation', 'commissioning', 'delivery', 'project management'],
        ];
    }
}
