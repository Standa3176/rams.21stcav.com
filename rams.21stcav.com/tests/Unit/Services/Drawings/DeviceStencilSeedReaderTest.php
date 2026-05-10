<?php

namespace Tests\Unit\Services\Drawings;

use App\Services\Drawings\DeviceStencilSeedReader;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 21 Plan 02 Task 1 — locks DeviceStencilSeedReader behaviour per
 * CONTEXT.md D-05 (seed pack manifest schema). Asserts:
 *   - all() walks resources/data/device-stencils-seed/*.json
 *   - The 5 promoted spike stencils are returned
 *   - _INDEX.md (non-JSON) is skipped
 *   - Bulk manifests with shape {stencils: [...]} are flat-mapped (per-file
 *     manifests + bulk manifests interleave seamlessly)
 *   - validate() throws RuntimeException with the file path + missing field
 *     name on schema violation
 *   - Each stencil has the required fields per the manifest schema
 *   - The 5 mxgraph_xml strings each contain their manufacturer name
 *
 * @see app/Services/Drawings/DeviceStencilSeedReader.php
 * @see resources/data/device-stencils-seed/_INDEX.md
 * @see .planning/phases/21-device-port-catalog-stencil-cache/21-CONTEXT.md (D-05)
 */
class DeviceStencilSeedReaderTest extends TestCase
{
    private function reader(): DeviceStencilSeedReader
    {
        return new DeviceStencilSeedReader;
    }

    public function test_all_returns_at_least_the_five_per_file_spike_stencils(): void
    {
        $stencils = $this->reader()->all();

        // Look up by slug (canonical filename identifier) — part_number reflects
        // real QuoteWerks part_no values (e.g. BAR-PRO for ClickShare,
        // GS312TP for Netgear) which don't all match the slug pattern.
        $slugs = array_map(
            fn (array $s) => strtolower($s['slug']),
            $stencils
        );

        // The 5 spike-promoted manifests MUST be present (per Task 1 ship list).
        $this->assertContains('neat-bar-pro', $slugs,
            'neat-bar-pro must be in seed pack');
        $this->assertContains('samsung-qm65c-t', $slugs,
            'samsung-qm65c-t must be in seed pack');
        $this->assertContains('clickshare-bar-pro', $slugs,
            'clickshare-bar-pro must be in seed pack');
        $this->assertContains('sennheiser-tcc2', $slugs,
            'sennheiser-tcc2 must be in seed pack');
        $this->assertContains('netgear-gs312tp', $slugs,
            'netgear-gs312tp must be in seed pack');
    }

    public function test_each_stencil_has_required_manifest_fields(): void
    {
        foreach ($this->reader()->all() as $stencil) {
            $this->assertArrayHasKey('part_number', $stencil);
            $this->assertArrayHasKey('slug', $stencil);
            $this->assertArrayHasKey('manufacturer', $stencil);
            $this->assertArrayHasKey('model', $stencil);
            $this->assertArrayHasKey('mxgraph_xml', $stencil);
            $this->assertArrayHasKey('source', $stencil);
            $this->assertArrayHasKey('ports', $stencil);
            $this->assertIsArray($stencil['ports']);
            $this->assertNotEmpty($stencil['part_number']);
            $this->assertStringContainsString('<shape', $stencil['mxgraph_xml']);
        }
    }

    public function test_index_md_is_skipped(): void
    {
        // _INDEX.md is non-JSON; reader MUST skip it without throwing.
        $stencils = $this->reader()->all();

        // Just asserting we got here without an exception means _INDEX.md was
        // not parsed as JSON. The all() result also must NOT contain a "_INDEX"
        // entry (no shape with that part_number / slug).
        foreach ($stencils as $stencil) {
            $this->assertNotSame('_INDEX', $stencil['part_number']);
            $this->assertNotSame('_index', strtolower($stencil['slug'] ?? ''));
        }
    }

    public function test_validate_throws_when_required_field_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('mxgraph_xml');

        $this->reader()->validate(
            [
                'part_number'  => 'TEST-PART',
                'slug'         => 'test-part',
                'manufacturer' => 'TestCo',
                'model'        => 'TestModel',
                'source'       => 'engineer-curated',
                'ports'        => [],
                // mxgraph_xml MISSING — must trigger validation error
            ],
            '/fake/path/test-part.json'
        );
    }

    public function test_validate_includes_file_path_in_error(): void
    {
        $path = '/fake/path/broken.json';

        try {
            $this->reader()->validate(
                ['part_number' => 'TEST'],
                $path
            );
            $this->fail('validate() must throw on incomplete manifest');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('broken.json', $e->getMessage(),
                'Error message must include the file path for engineer triage');
        }
    }

    public function test_validate_throws_on_invalid_port_side(): void
    {
        $this->expectException(RuntimeException::class);

        $this->reader()->validate(
            [
                'part_number'  => 'TEST-PART',
                'slug'         => 'test-part',
                'manufacturer' => 'TestCo',
                'model'        => 'TestModel',
                'mxgraph_xml'  => '<shape><foreground/></shape>',
                'source'       => 'engineer-curated',
                'ports'        => [
                    [
                        'port_id'        => 'p1',
                        'label'          => 'P1',
                        'side'           => 'middle', // INVALID — must be left/right/top/bottom
                        'connector_type' => 'hdmi',
                        'signal_type'    => 'video',
                        'direction'      => 'in',
                    ],
                ],
            ],
            '/fake/path/bad-side.json'
        );
    }

    public function test_validate_throws_on_invalid_source(): void
    {
        $this->expectException(RuntimeException::class);

        $this->reader()->validate(
            [
                'part_number'  => 'TEST-PART',
                'slug'         => 'test-part',
                'manufacturer' => 'TestCo',
                'model'        => 'TestModel',
                'mxgraph_xml'  => '<shape><foreground/></shape>',
                'source'       => 'something-invalid',
                'ports'        => [],
            ],
            '/fake/path/bad-source.json'
        );
    }

    public function test_neat_bar_pro_has_six_ports(): void
    {
        $stencils = $this->reader()->all();
        $neat = $this->findStencil($stencils, 'neat-bar-pro');

        $this->assertNotNull($neat, 'neat-bar-pro must be in seed pack');
        $this->assertCount(6, $neat['ports'],
            'Neat Bar Pro spike has 6 ports — promoted manifest must preserve');
    }

    public function test_mxgraph_xml_contains_manufacturer_name(): void
    {
        $stencils = $this->reader()->all();

        $expectations = [
            'neat-bar-pro'       => 'NEAT',
            'samsung-qm65c-t'    => 'SAMSUNG',
            'clickshare-bar-pro' => 'CLICKSHARE',
            'sennheiser-tcc2'    => 'SENNHEISER',
            'netgear-gs312tp'    => 'NETGEAR',
        ];

        foreach ($expectations as $slug => $needle) {
            $stencil = $this->findStencil($stencils, $slug);
            $this->assertNotNull($stencil, "{$slug} must be in seed pack");
            $this->assertStringContainsStringIgnoringCase(
                $needle,
                $stencil['mxgraph_xml'],
                "{$slug} mxgraph_xml must contain manufacturer name {$needle}"
            );
        }
    }

    public function test_clickshare_keeps_clickshare_logo_per_d14(): void
    {
        // D-14: ClickShare slug stays distinct from Barco. Manifest carries
        // manufacturer = "Barco" (true brand) but logo_svg_path remains
        // /img/manufacturers/clickshare.svg.
        $stencil = $this->findStencil($this->reader()->all(), 'clickshare-bar-pro');

        $this->assertNotNull($stencil);
        $this->assertSame('Barco', $stencil['manufacturer'],
            'ClickShare Bar Pro is a Barco product — manufacturer = Barco');
        $this->assertSame('/img/manufacturers/clickshare.svg', $stencil['logo_svg_path'] ?? null,
            'D-14: clickshare.svg path is preserved (NOT renamed to barco.svg)');
    }

    /**
     * Look up by slug (canonical filename identifier). part_number reflects
     * the actual QuoteWerks part_no value (e.g. BAR-PRO for ClickShare,
     * GS312TP for Netgear) which doesn't always follow the slug pattern.
     *
     * @param  array<int, array<string, mixed>>  $stencils
     */
    private function findStencil(array $stencils, string $slug): ?array
    {
        foreach ($stencils as $stencil) {
            if (strtolower((string) ($stencil['slug'] ?? '')) === strtolower($slug)) {
                return $stencil;
            }
        }

        return null;
    }
}
