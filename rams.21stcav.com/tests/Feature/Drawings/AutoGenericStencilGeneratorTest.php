<?php

namespace Tests\Feature\Drawings;

use App\Services\Drawings\AutoGenericStencilGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 24 Plan 01 Task 3 — locks AutoGenericStencilGenerator's D-05
 * extension: provisional port rails + named mxGraph constraints, using the
 * REAL mxGraph stencil-XML grammar verified against the vendored engine
 * (public/vendor/drawio/mxgraph/src/shape/mxStencil.js), with zero
 * regression to the pre-Phase-24 zero-port placeholder path (criterion 6).
 *
 * @see app/Services/Drawings/AutoGenericStencilGenerator.php
 */
class AutoGenericStencilGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private function generator(): AutoGenericStencilGenerator
    {
        return $this->app->make(AutoGenericStencilGenerator::class);
    }

    /**
     * Captured byte-for-byte from the pre-Phase-24 committed generator
     * (git commit 06c9052, Phase 21 Plan 01) for the identical
     * ['part_number' => 'X', 'manufacturer' => 'Y'] input — the true
     * regression fixture, not a hand-transcribed approximation.
     */
    private const ZERO_PORT_REGRESSION_FIXTURE = '<shape name="auto-X" h="140" w="220" aspect="variable" strokewidth="inherit"><background><fillcolor color="#FAFAF6"/><strokecolor color="#1B7A7A"/><roundrect x="0" y="0" w="220" h="140" arcsize="6"/><fillstroke/></background><foreground><fillcolor color="#1B7A7A"/><rect x="0" y="0" w="220" h="30"/><fill/><fontstyle style="1"/><fontsize size="12"/><fontcolor color="#FFFFFF"/><text str="Y" x="110" y="20" align="center"/><fontstyle style="0"/><fontsize size="11"/><fontcolor color="#1B7A7A"/><text str="Y" x="110" y="65" align="center"/><fontstyle style="2"/><fontsize size="9"/><fontcolor color="#666666"/><text str="X" x="110" y="105" align="center"/><fontstyle style="0"/><fontsize size="7"/><fontcolor color="#666666"/><text str="Tier 1 placeholder" x="110" y="130" align="center"/></foreground></shape>';

    public function test_no_ports_hint_is_byte_identical_to_pre_phase_24_output(): void
    {
        $result = $this->generator()->build(['part_number' => 'X', 'manufacturer' => 'Y']);

        $this->assertSame(self::ZERO_PORT_REGRESSION_FIXTURE, $result['mxgraph_xml']);
    }

    private function switchPorts(): array
    {
        return [
            ['label' => 'RJ45 1', 'side' => 'left', 'connector_type' => 'rj45', 'signal_type' => 'network', 'direction' => 'io', 'sort_order' => 1, 'port_id' => 'rj45-1'],
            ['label' => 'RJ45 2', 'side' => 'left', 'connector_type' => 'rj45', 'signal_type' => 'network', 'direction' => 'io', 'sort_order' => 2, 'port_id' => 'rj45-2'],
            ['label' => 'RJ45 3', 'side' => 'left', 'connector_type' => 'rj45', 'signal_type' => 'network', 'direction' => 'io', 'sort_order' => 3, 'port_id' => 'rj45-3'],
            ['label' => 'RJ45 4', 'side' => 'left', 'connector_type' => 'rj45', 'signal_type' => 'network', 'direction' => 'io', 'sort_order' => 4, 'port_id' => 'rj45-4'],
        ];
    }

    public function test_ports_hint_emits_exactly_four_named_constraints(): void
    {
        $result = $this->generator()->build([
            'part_number' => 'GS312TP',
            'ports'       => $this->switchPorts(),
        ]);

        $xml = $result['mxgraph_xml'];

        // Exact <constraint name="{port_id}"> parity assertion per port_id
        // (RESEARCH.md Pitfall 2).
        foreach (['rj45-1', 'rj45-2', 'rj45-3', 'rj45-4'] as $portId) {
            $this->assertStringContainsString(sprintf('name="%s"', $portId), $xml);
            $this->assertSame(1, substr_count($xml, sprintf('name="%s"', $portId)));
        }

        $this->assertSame(4, preg_match_all('/<constraint\b[^>]*\/>/', $xml));
    }

    public function test_dashed_and_stroke_sequence_matches_batched_grammar(): void
    {
        $result = $this->generator()->build([
            'part_number' => 'GS312TP',
            'ports'       => $this->switchPorts(),
        ]);

        $xml = $result['mxgraph_xml'];

        // <dashed dashed="1"/> immediately precedes the rail-tick <line>
        // batch (after strokealpha + strokecolor state-setters), followed by
        // exactly 4 bare <line .../> elements, then a single <stroke/>.
        $this->assertMatchesRegularExpression(
            '/<dashed dashed="1"\/><strokealpha alpha="0\.6"\/><strokecolor color="#94A3B8"\/>(<line x1="[^"]*" y1="[^"]*" x2="[^"]*" y2="[^"]*"\/>){4}<stroke\/>/',
            $xml
        );
    }

    public function test_strokealpha_and_reset_bracket_every_port_related_primitive(): void
    {
        $result = $this->generator()->build([
            'part_number' => 'GS312TP',
            'ports'       => $this->switchPorts(),
        ]);

        $xml = $result['mxgraph_xml'];

        $strokealphaOnPos = strpos($xml, '<strokealpha alpha="0.6"/>');
        $strokePos = strpos($xml, '<stroke/>');
        $dashedResetPos = strpos($xml, '<dashed dashed="0"/>');
        $strokealphaResetPos = strpos($xml, '<strokealpha alpha="1"/>');
        $lastTextPos = strrpos($xml, '<text str="RJ45 4"');

        $this->assertNotFalse($strokealphaOnPos);
        $this->assertNotFalse($strokePos);
        $this->assertNotFalse($dashedResetPos);
        $this->assertNotFalse($strokealphaResetPos);
        $this->assertNotFalse($lastTextPos);

        // strokealpha 0.6 is set BEFORE the rail lines/stroke.
        $this->assertLessThan($strokePos, $strokealphaOnPos);

        // The reset pair comes AFTER every port-label <text> element.
        $this->assertGreaterThan($lastTextPos, $dashedResetPos);
        $this->assertGreaterThan($lastTextPos, $strokealphaResetPos);

        // Reset pair is ordered dashed="0" then strokealpha="1".
        $this->assertLessThan($strokealphaResetPos, $dashedResetPos);
    }

    public function test_port_labels_render_between_stroke_commit_and_reset(): void
    {
        $result = $this->generator()->build([
            'part_number' => 'GS312TP',
            'ports'       => $this->switchPorts(),
        ]);

        $xml = $result['mxgraph_xml'];

        $strokePos = strpos($xml, '<stroke/>');
        $dashedResetPos = strpos($xml, '<dashed dashed="0"/>');

        foreach (['RJ45 1', 'RJ45 2', 'RJ45 3', 'RJ45 4'] as $label) {
            $textPos = strpos($xml, sprintf('<text str="%s"', $label));
            $this->assertNotFalse($textPos, "Label {$label} must appear in the output.");
            $this->assertGreaterThan($strokePos, $textPos, "Label {$label} must render after the rail <stroke/> commit.");
            $this->assertLessThan($dashedResetPos, $textPos, "Label {$label} must render before the mandatory reset.");
        }
    }

    public function test_no_invented_svg_css_attributes_or_percentage_alpha_appear(): void
    {
        $result = $this->generator()->build([
            'part_number' => 'GS312TP',
            'ports'       => $this->switchPorts(),
        ]);

        $xml = $result['mxgraph_xml'];

        $this->assertStringNotContainsString('stroke-dasharray', $xml);
        $this->assertStringNotContainsString('opacity=', $xml);

        // Guard against the invented-attribute regression this task
        // corrected: the <dashed> element must never carry a bare `value=`
        // attribute (the wrong mxGraph grammar spelling).
        $this->assertDoesNotMatchRegularExpression('/<dashed[^>]*\bvalue="/', $xml);

        // Guard against a 0-100 percentage-scale alpha value ever being
        // emitted instead of the correct 0.0-1.0 fraction.
        $this->assertDoesNotMatchRegularExpression('/alpha="(?:[6-9]\d|100)"/', $xml);
    }

    public function test_no_line_element_carries_any_attribute_beyond_coordinates(): void
    {
        $result = $this->generator()->build([
            'part_number' => 'GS312TP',
            'ports'       => $this->switchPorts(),
        ]);

        $xml = $result['mxgraph_xml'];

        preg_match_all('/<line\b[^>]*\/>/', $xml, $matches);
        $this->assertNotEmpty($matches[0]);

        foreach ($matches[0] as $lineTag) {
            $this->assertMatchesRegularExpression(
                '/^<line x1="[^"]*" y1="[^"]*" x2="[^"]*" y2="[^"]*"\/>$/',
                $lineTag,
                "Unexpected attribute on line element: {$lineTag}"
            );
        }
    }

    public function test_port_label_is_escaped_via_existing_xml_helper(): void
    {
        $result = $this->generator()->build([
            'part_number' => 'XSS-TEST',
            'ports' => [
                ['label' => '<script>alert(1)</script>', 'side' => 'left', 'connector_type' => 'hdmi', 'signal_type' => 'video', 'direction' => 'in', 'sort_order' => 1, 'port_id' => 'hdmi-1'],
            ],
        ]);

        $xml = $result['mxgraph_xml'];

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $xml);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $xml);
    }

    public function test_constraint_coordinates_map_by_side(): void
    {
        $result = $this->generator()->build([
            'part_number' => 'MULTI-SIDE',
            'ports' => [
                ['label' => 'L', 'side' => 'left', 'connector_type' => 'hdmi', 'signal_type' => 'video', 'direction' => 'in', 'sort_order' => 1, 'port_id' => 'l-1'],
                ['label' => 'R', 'side' => 'right', 'connector_type' => 'hdmi', 'signal_type' => 'video', 'direction' => 'out', 'sort_order' => 2, 'port_id' => 'r-1'],
                ['label' => 'T', 'side' => 'top', 'connector_type' => 'rj45', 'signal_type' => 'network', 'direction' => 'io', 'sort_order' => 3, 'port_id' => 't-1'],
                ['label' => 'B', 'side' => 'bottom', 'connector_type' => 'rj45', 'signal_type' => 'network', 'direction' => 'io', 'sort_order' => 4, 'port_id' => 'b-1'],
            ],
        ]);

        $xml = $result['mxgraph_xml'];

        $this->assertMatchesRegularExpression('/<constraint x="0" y="[^"]+" perimeter="0" name="l-1"\/>/', $xml);
        $this->assertMatchesRegularExpression('/<constraint x="1" y="[^"]+" perimeter="0" name="r-1"\/>/', $xml);
        $this->assertMatchesRegularExpression('/<constraint x="[^"]+" y="0" perimeter="0" name="t-1"\/>/', $xml);
        $this->assertMatchesRegularExpression('/<constraint x="[^"]+" y="1" perimeter="0" name="b-1"\/>/', $xml);
    }
}
