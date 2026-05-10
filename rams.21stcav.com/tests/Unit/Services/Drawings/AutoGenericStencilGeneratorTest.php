<?php

namespace Tests\Unit\Services\Drawings;

use App\Services\Drawings\AutoGenericStencilGenerator;
use Tests\TestCase;

/**
 * Phase 21 Plan 01 Task 2 — locks AutoGenericStencilGenerator output per
 * CONTEXT.md D-04. Asserts:
 *   - build() returns the expected payload shape
 *   - mxgraph_xml is a valid <shape> document
 *   - Visible text contains manufacturer + model + part_number
 *   - XSS hint values are XML-escaped (T-21.01-01 mitigation; mirrors
 *     DrawIoSpikeBuilderService::xml() T-17.02-01 protection)
 *   - NO port rails (D-04: Tier 1 placeholder, Phase 24 adds rails)
 *   - Deterministic — same hints produce byte-identical output across calls
 *   - Defaults to "Unknown Device" when no name hints supplied
 *
 * @see app/Services/Drawings/AutoGenericStencilGenerator.php
 * @see .planning/phases/21-device-port-catalog-stencil-cache/21-CONTEXT.md (D-04 visual spec)
 */
class AutoGenericStencilGeneratorTest extends TestCase
{
    private function generator(): AutoGenericStencilGenerator
    {
        return new AutoGenericStencilGenerator;
    }

    public function test_build_returns_payload_shape(): void
    {
        $payload = $this->generator()->build([
            'manufacturer' => 'NEAT',
            'model'        => 'Bar Pro',
            'name'         => 'Neat Bar Pro Videobar',
            'part_number'  => 'NEAT-BAR-PRO',
        ]);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('mxgraph_xml', $payload);
        $this->assertArrayHasKey('default_width', $payload);
        $this->assertArrayHasKey('default_height', $payload);
        $this->assertArrayHasKey('display_name', $payload);
        $this->assertSame(220, $payload['default_width']);
        $this->assertSame(140, $payload['default_height']);
    }

    public function test_mxgraph_xml_contains_manufacturer_model_and_part_number(): void
    {
        $xml = $this->generator()->build([
            'manufacturer' => 'NEAT',
            'model'        => 'Bar Pro',
            'name'         => 'Neat Bar Pro Videobar',
            'part_number'  => 'NEAT-BAR-PRO',
        ])['mxgraph_xml'];

        $this->assertStringContainsString('<shape', $xml);
        $this->assertStringContainsString('</shape>', $xml);
        $this->assertStringContainsString('NEAT', $xml);
        $this->assertStringContainsString('Bar Pro', $xml);
        $this->assertStringContainsString('NEAT-BAR-PRO', $xml);
    }

    public function test_xml_escapes_user_supplied_xss_in_manufacturer_field(): void
    {
        $xml = $this->generator()->build([
            'manufacturer' => '<script>alert(1)</script>',
            'model'        => 'Innocent Model',
            'name'         => 'Test',
            'part_number'  => 'TEST-001',
        ])['mxgraph_xml'];

        // Raw <script> tag MUST NOT appear in output (would be interpolated as XML).
        $this->assertStringNotContainsString('<script>alert(1)</script>', $xml);
        // The escaped form MUST appear (T-21.01-01 mitigation).
        $this->assertStringContainsString('&lt;script&gt;', $xml);
    }

    public function test_no_port_rails_in_auto_generic_stencil(): void
    {
        // D-04 explicit constraint: Tier 1 placeholders have NO port rails.
        // Phase 24's curation UI is what adds them. Renderer surfaces the
        // un-curated state as a "needs promoting" hint via the body label.
        $xml = $this->generator()->build([
            'manufacturer' => 'NEAT',
            'model'        => 'Bar Pro',
            'name'         => 'Neat Bar Pro',
            'part_number'  => 'NEAT-BAR-PRO',
        ])['mxgraph_xml'];

        $this->assertStringNotContainsString('<constraint', $xml,
            'Auto-generic stencils MUST NOT carry port constraints (D-04)');
        $this->assertStringNotContainsString('<connections', $xml,
            'Auto-generic stencils MUST NOT carry connections (D-04)');
    }

    public function test_build_is_deterministic_for_same_hints(): void
    {
        $hints = [
            'manufacturer' => 'Acme',
            'model'        => 'Widget',
            'name'         => 'Acme Widget',
            'part_number'  => 'ACME-W-1',
        ];
        $first  = $this->generator()->build($hints);
        $second = $this->generator()->build($hints);

        $this->assertSame($first['mxgraph_xml'], $second['mxgraph_xml'],
            'AutoGenericStencilGenerator MUST be deterministic — same hints → same XML');
        $this->assertSame($first['display_name'], $second['display_name']);
    }

    public function test_display_name_falls_back_to_unknown_device_when_no_hints(): void
    {
        $payload = $this->generator()->build([]);

        $this->assertSame('Unknown Device', $payload['display_name']);
        $this->assertStringContainsString('Unknown Device', $payload['mxgraph_xml']);
    }

    public function test_display_name_prefers_name_then_manufacturer_model_then_part_number(): void
    {
        $withName = $this->generator()->build([
            'name'         => 'Friendly Name',
            'manufacturer' => 'Acme',
            'model'        => 'Widget',
            'part_number'  => 'ACME-W-1',
        ]);
        $this->assertSame('Friendly Name', $withName['display_name']);

        $withMfgModel = $this->generator()->build([
            'manufacturer' => 'Acme',
            'model'        => 'Widget',
            'part_number'  => 'ACME-W-1',
        ]);
        $this->assertSame('Acme Widget', $withMfgModel['display_name']);

        $withPart = $this->generator()->build([
            'part_number' => 'ACME-W-1',
        ]);
        $this->assertSame('ACME-W-1', $withPart['display_name']);
    }
}
