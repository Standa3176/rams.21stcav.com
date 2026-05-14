<?php

namespace Tests\Feature\Drawings;

use App\Services\Drawings\XtenAvLayoutEngine;
use Tests\TestCase;

/**
 * Phase 23 Plan 02 Task 2 — DRAW-42 device-card emission + DRAW-46 zone group
 * containers. Asserts the XtenAvLayoutEngine emits a deterministic, ordered
 * list of mxCell descriptors with:
 *   - zone container BEFORE its child device cells
 *   - device cells parented at the zone container's id
 *   - base64 stencil embed pattern matching DrawIoBuilderService::emitMxGraph
 *   - XML-escape on user-supplied zone label + device name (T-23-02-A1 / A2)
 *   - stable ids across repeated calls (D-LOCK-5/6 determinism)
 */
class XtenAvLayoutEngineTest extends TestCase
{
    private XtenAvLayoutEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = app(XtenAvLayoutEngine::class);
    }

    /**
     * Build a fake DeviceStencil-like object — Plan 02 tests don't need DB.
     * Property surface mirrors the real DeviceStencil model (mxgraph_xml,
     * default_width/height, display_name, source) so the layout engine
     * exercises the same property access path it would in production.
     */
    private function fakeStencil(string $partNumber, string $mxgraphXml = '<shape h="140" w="220"/>'): object
    {
        return new class($partNumber, $mxgraphXml)
        {
            public string $part_number;
            public string $mxgraph_xml;
            public string $manufacturer = 'Acme';
            public string $model = 'XYZ';
            public ?string $display_name = null;
            public int $default_width = 220;
            public int $default_height = 140;
            public string $source = 'engineer-curated';
            public function __construct(string $pn, string $xml)
            {
                $this->part_number = $pn;
                $this->mxgraph_xml = $xml;
            }
            public function isCurated(): bool { return true; }
        };
    }

    public function test_emits_zone_container_before_device_cells(): void
    {
        $zoned = [
            'RACK' => [
                ['part_number' => 'A', 'name' => 'Switch', 'stencil' => $this->fakeStencil('A')],
            ],
        ];
        $cells = $this->engine->placeDevices($zoned);

        $kinds = array_column($cells, 'kind');
        $rackZonePos = array_search('zone', $kinds, true);
        $deviceAPos = array_search('device', $kinds, true);

        $this->assertNotFalse($rackZonePos);
        $this->assertNotFalse($deviceAPos);
        $this->assertLessThan($deviceAPos, $rackZonePos, 'Zone container must precede its child device cells');
    }

    public function test_zone_emits_dashed_group_with_children(): void
    {
        $zoned = [
            'RACK' => [
                ['part_number' => 'A', 'name' => 'Switch', 'stencil' => $this->fakeStencil('A')],
                ['part_number' => 'B', 'name' => 'Amp',    'stencil' => $this->fakeStencil('B')],
            ],
        ];
        $cells = $this->engine->placeDevices($zoned);

        $zoneCell = collect($cells)->firstWhere('kind', 'zone');
        $this->assertNotNull($zoneCell);
        $this->assertStringContainsString('dashed=1', $zoneCell['style']);
        $this->assertStringContainsString('fillColor=none', $zoneCell['style']);
        $this->assertSame('RACK', $zoneCell['value']);

        $deviceCells = array_values(array_filter($cells, fn ($c) => $c['kind'] === 'device'));
        $this->assertCount(2, $deviceCells);
        foreach ($deviceCells as $dc) {
            $this->assertSame($zoneCell['id'], $dc['parent'], 'each device must point at the zone parent id');
        }
    }

    public function test_device_cell_style_contains_base64_stencil(): void
    {
        $stencil = $this->fakeStencil('NEAT-BAR-PRO', '<shape h="160" w="240"/>');
        $zoned = ['RACK' => [['part_number' => 'NEAT-BAR-PRO', 'name' => 'Neat Bar Pro', 'stencil' => $stencil]]];
        $cells = $this->engine->placeDevices($zoned);

        $device = collect($cells)->firstWhere('kind', 'device');
        $this->assertStringContainsString('shape=stencil(', $device['style']);
        $this->assertStringContainsString(base64_encode('<shape h="160" w="240"/>'), $device['style']);
    }

    public function test_curated_and_tier1_stencils_both_render(): void
    {
        // Phase 21 D-04 carry-forward — both render side by side
        $curated = $this->fakeStencil('NEAT-BAR-PRO', '<shape ...><connections>...</connections></shape>');
        $tier1   = $this->fakeStencil('UNCATALOGUED-001', '<shape ... />'); // no <connections>

        $zoned = [
            'RACK' => [
                ['part_number' => 'NEAT-BAR-PRO',     'name' => 'Neat',  'stencil' => $curated],
                ['part_number' => 'UNCATALOGUED-001', 'name' => 'Other', 'stencil' => $tier1],
            ],
        ];
        $cells = $this->engine->placeDevices($zoned);
        $devices = array_values(array_filter($cells, fn ($c) => $c['kind'] === 'device'));
        $this->assertCount(2, $devices);
    }

    public function test_zone_label_xss_escaped(): void
    {
        $stencil = $this->fakeStencil('A');
        $zoned = [
            '<script>alert(1)</script>' => [
                ['part_number' => 'A', 'name' => 'Switch', 'stencil' => $stencil],
            ],
        ];
        $cells = $this->engine->placeDevices($zoned);
        $zoneCell = collect($cells)->firstWhere('kind', 'zone');
        $this->assertStringNotContainsString('<script>', $zoneCell['value']);
        $this->assertStringContainsString('&lt;script&gt;', $zoneCell['value']);
    }

    public function test_device_name_xss_escaped(): void
    {
        $stencil = $this->fakeStencil('A');
        $zoned = ['RACK' => [['part_number' => 'A', 'name' => '<img onerror=x>', 'stencil' => $stencil]]];
        $cells = $this->engine->placeDevices($zoned);
        $device = collect($cells)->firstWhere('kind', 'device');
        $this->assertStringNotContainsString('<img', $device['value']);
        $this->assertStringContainsString('&lt;img', $device['value']);
    }

    public function test_emits_stable_ids_across_calls(): void
    {
        $stencil = $this->fakeStencil('A');
        $zoned = ['RACK' => [['part_number' => 'A', 'name' => 'Switch', 'stencil' => $stencil]]];
        $a = $this->engine->placeDevices($zoned);
        $b = $this->engine->placeDevices($zoned);
        $this->assertSame(
            array_column($a, 'id'),
            array_column($b, 'id'),
            'IDs must be deterministic across calls (D-LOCK-5/6)',
        );
    }

    public function test_empty_zoned_input_returns_empty_array(): void
    {
        $this->assertSame([], $this->engine->placeDevices([]));
    }
}
