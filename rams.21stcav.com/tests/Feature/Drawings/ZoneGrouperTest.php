<?php

namespace Tests\Feature\Drawings;

use App\Services\Drawings\ZoneGrouper;
use Tests\TestCase;

/**
 * Phase 23 Plan 02 Task 1 — DRAW-46 zone derivation contract per
 * D-01 / D-02 / D-04 + OQ-1 Path B name-keyword fallback.
 *
 * Precedence per .planning/phases/23-xten-av-style-renderer/23-CONTEXT.md
 * + .planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-1-CATEGORIES.md:
 *   1. $line['zone']                — D-02 / D-04 engineer override
 *   2. config category map          — D-01 (returns non-null/non-OTHER)
 *   3. name-keyword secondary scan  — OQ-1 Path B (hardware fall-through)
 *   4. 'OTHER'                      — fallback
 */
class ZoneGrouperTest extends TestCase
{
    private ZoneGrouper $grouper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->grouper = app(ZoneGrouper::class);
        // Lock the config the test relies on; tests stay deterministic even
        // if OQ-1 disposition changes the seed config later.
        config()->set('drawings.zone_vocab', [
            'RACK', 'CEILING', 'WALL', 'TABLE', 'RECEPTION', 'FLOOR',
            'PAGING_STATION', 'EXTERNAL', 'OTHER',
        ]);
        config()->set('drawings.category_to_zone', [
            // OQ-1 Path B shape — hardware falls through to name-keyword scan;
            // all other categories resolve to OTHER (and are filtered upstream
            // by Project::devicesWithStencils() — they never reach the grouper
            // in production but tests cover the contract anyway).
            'hardware'          => null,
            'cables'            => 'OTHER',
            'consumables'       => 'OTHER',
            'services'          => 'OTHER',
            'service_contracts' => 'OTHER',
            'customer_supplied' => 'OTHER',
            'option'            => 'OTHER',
        ]);
    }

    public function test_per_device_zone_override_wins(): void
    {
        // category=hardware would scan name (which says "rack") → RACK; the
        // override should send it to CEILING regardless.
        $lines = [
            ['part_number' => 'X', 'category' => 'hardware', 'name' => 'Netgear Rack Switch', 'zone' => 'CEILING', 'stencil' => 'present'],
        ];
        $grouped = $this->grouper->assign($lines);
        $this->assertArrayHasKey('CEILING', $grouped);
        $this->assertArrayNotHasKey('RACK', $grouped);
    }

    public function test_free_text_zone_creates_separate_group(): void
    {
        $lines = [
            ['part_number' => 'A', 'category' => 'hardware', 'name' => 'Rack Switch', 'zone' => 'Equipment Rack', 'stencil' => 'present'],
            ['part_number' => 'B', 'category' => 'hardware', 'name' => 'Rack Switch', 'zone' => 'RACK', 'stencil' => 'present'],
        ];
        $grouped = $this->grouper->assign($lines);
        $this->assertArrayHasKey('Equipment Rack', $grouped);
        $this->assertArrayHasKey('RACK', $grouped);
        $this->assertCount(1, $grouped['Equipment Rack']);
        $this->assertCount(1, $grouped['RACK']);
    }

    public function test_hardware_category_falls_through_to_name_keyword_ceiling(): void
    {
        // Per OQ-1 Path B — `hardware` config value is null, must trigger
        // the name-keyword scan. "Ceiling" keyword matches → CEILING.
        $lines = [
            ['part_number' => 'A', 'category' => 'hardware', 'name' => 'Sennheiser TeamConnect Ceiling 2', 'stencil' => 'present'],
        ];
        $grouped = $this->grouper->assign($lines);
        $this->assertArrayHasKey('CEILING', $grouped);
    }

    public function test_hardware_category_falls_through_to_name_keyword_rack(): void
    {
        $lines = [
            ['part_number' => 'A', 'category' => 'hardware', 'name' => 'Netgear GS312TP Rack Switch', 'stencil' => 'present'],
            ['part_number' => 'B', 'category' => 'hardware', 'name' => 'Yamaha DSP Processor', 'stencil' => 'present'],
            ['part_number' => 'C', 'category' => 'hardware', 'name' => 'Crestron Amplifier', 'stencil' => 'present'],
        ];
        $grouped = $this->grouper->assign($lines);
        $this->assertArrayHasKey('RACK', $grouped);
        $this->assertCount(3, $grouped['RACK']);
    }

    public function test_hardware_category_falls_through_to_name_keyword_wall(): void
    {
        $lines = [
            ['part_number' => 'A', 'category' => 'hardware', 'name' => 'Samsung QM65C-T 65" Display', 'stencil' => 'present'],
            ['part_number' => 'B', 'category' => 'hardware', 'name' => 'Epson Projector', 'stencil' => 'present'],
        ];
        $grouped = $this->grouper->assign($lines);
        $this->assertArrayHasKey('WALL', $grouped);
        $this->assertCount(2, $grouped['WALL']);
    }

    public function test_hardware_category_falls_through_to_name_keyword_table(): void
    {
        $lines = [
            ['part_number' => 'A', 'category' => 'hardware', 'name' => 'Crestron TS-1070 Touchpanel', 'stencil' => 'present'],
            ['part_number' => 'B', 'category' => 'hardware', 'name' => 'Logitech Tabletop Codec', 'stencil' => 'present'],
        ];
        $grouped = $this->grouper->assign($lines);
        $this->assertArrayHasKey('TABLE', $grouped);
    }

    public function test_keyword_ordering_ceiling_before_rack(): void
    {
        // Edge case from OQ-1 disposition: "Ceiling Camera Bracket" must
        // resolve to CEILING (not match generic keywords later in the list).
        // Ordering rule: `ceiling` keyword evaluated before generic ones.
        $lines = [
            ['part_number' => 'A', 'category' => 'hardware', 'name' => 'Ceiling Camera Bracket', 'stencil' => 'present'],
        ];
        $grouped = $this->grouper->assign($lines);
        $this->assertArrayHasKey('CEILING', $grouped);
        $this->assertArrayNotHasKey('RACK', $grouped);
    }

    public function test_name_keyword_matching_is_case_insensitive(): void
    {
        $lines = [
            ['part_number' => 'A', 'category' => 'hardware', 'name' => 'NETGEAR RACK SWITCH', 'stencil' => 'present'],
        ];
        $grouped = $this->grouper->assign($lines);
        $this->assertArrayHasKey('RACK', $grouped);
    }

    public function test_name_keyword_falls_back_to_model_when_name_empty(): void
    {
        // OQ-1 disposition: "...evaluates name (and falls back to model if
        // name is empty)..."
        $lines = [
            ['part_number' => 'A', 'category' => 'hardware', 'name' => '', 'model' => 'QM65C-T Display', 'stencil' => 'present'],
        ];
        $grouped = $this->grouper->assign($lines);
        $this->assertArrayHasKey('WALL', $grouped);
    }

    public function test_unknown_category_falls_to_other(): void
    {
        $lines = [
            ['part_number' => 'Z', 'category' => 'unicorn-device', 'name' => 'Mystery Box', 'stencil' => 'present'],
        ];
        $grouped = $this->grouper->assign($lines);
        $this->assertArrayHasKey('OTHER', $grouped);
    }

    public function test_missing_category_falls_to_other(): void
    {
        // No category, no zone, no keyword-bearing name → OTHER.
        $lines = [
            ['part_number' => 'Z', 'name' => 'Mystery Box', 'stencil' => 'present'],
        ];
        $grouped = $this->grouper->assign($lines);
        $this->assertArrayHasKey('OTHER', $grouped);
    }

    public function test_lines_without_stencil_are_excluded(): void
    {
        $lines = [
            ['part_number' => '', 'category' => 'hardware', 'name' => 'Rack Switch', 'stencil' => null],
            ['part_number' => 'X', 'category' => 'hardware', 'name' => 'Ceiling Mic', 'stencil' => 'present'],
        ];
        $grouped = $this->grouper->assign($lines);
        $this->assertSame(['CEILING'], array_keys($grouped));
    }

    public function test_zone_order_follows_vocab_then_free_text_alphabetical(): void
    {
        $lines = [
            ['part_number' => 'B', 'category' => 'hardware', 'name' => 'Samsung Display',     'stencil' => 'present'], // WALL
            ['part_number' => 'A', 'category' => 'hardware', 'name' => 'Netgear Rack Switch', 'stencil' => 'present'], // RACK
            ['part_number' => 'C', 'category' => 'hardware', 'name' => 'Switch', 'zone' => 'Zebra Cage',   'stencil' => 'present'],
            ['part_number' => 'D', 'category' => 'hardware', 'name' => 'Switch', 'zone' => 'Aardvark Bay', 'stencil' => 'present'],
        ];
        $grouped = $this->grouper->assign($lines);
        $this->assertSame(
            ['RACK', 'WALL', 'Aardvark Bay', 'Zebra Cage'],
            array_keys($grouped),
            'RACK/WALL come first in vocab order; free-text alphabetical after',
        );
    }

    public function test_within_zone_order_preserves_input_order(): void
    {
        $lines = [
            ['part_number' => 'A', 'category' => 'hardware', 'name' => 'Rack Switch A', 'stencil' => 'present'],
            ['part_number' => 'B', 'category' => 'hardware', 'name' => 'Rack Switch B', 'stencil' => 'present'],
            ['part_number' => 'C', 'category' => 'hardware', 'name' => 'Rack Switch C', 'stencil' => 'present'],
        ];
        $grouped = $this->grouper->assign($lines);
        $this->assertSame(['A', 'B', 'C'], array_column($grouped['RACK'], 'part_number'));
    }

    public function test_empty_input_returns_empty_array(): void
    {
        $this->assertSame([], $this->grouper->assign([]));
    }
}
