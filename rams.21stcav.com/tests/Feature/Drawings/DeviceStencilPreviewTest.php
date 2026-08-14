<?php

namespace Tests\Feature\Drawings;

use App\Models\DeviceStencil;
use App\Models\DevicePort;
use App\Models\User;
use App\Services\Drawings\AutoGenericStencilGenerator;
use App\Services\Drawings\StencilXmlToSvgRenderer;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 24 Plan 04 (DRAW-51, D-16) — server-rendered stencil preview.
 *
 * Task 1 locks StencilXmlToSvgRenderer's state-machine translation of
 * AutoGenericStencilGenerator's bounded mxGraph grammar into SVG — in
 * particular the `dashed`/`strokealpha` STATE-ELEMENT grammar (verified
 * against the vendored public/vendor/drawio/mxgraph/src/shape/mxStencil.js,
 * NOT the seed pack — the seed pack has zero precedent for either element).
 *
 * Task 2 locks `admin.device-stencils.preview`: returns rendered SVG (not
 * mxGraph XML), persists nothing, validates the posted `ports` array.
 *
 * @see app/Services/Drawings/StencilXmlToSvgRenderer.php
 * @see app/Http/Controllers/Admin/DeviceStencilController.php::preview()
 * @see .planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-04-PLAN.md
 */
class DeviceStencilPreviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private function renderer(): StencilXmlToSvgRenderer
    {
        return $this->app->make(StencilXmlToSvgRenderer::class);
    }

    private function generator(): AutoGenericStencilGenerator
    {
        return $this->app->make(AutoGenericStencilGenerator::class);
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

    private function makeStencil(array $overrides = []): DeviceStencil
    {
        return DeviceStencil::create(array_merge([
            'part_number'    => 'PN-'.fake()->unique()->numerify('#####'),
            'manufacturer'   => 'Netgear',
            'model'          => 'GS312TP',
            'display_name'   => null,
            'mxgraph_xml'    => '<shape name="21cav.test" h="140" w="220" aspect="variable" strokewidth="inherit"><background/><foreground/></shape>',
            'default_width'  => 220,
            'default_height' => 140,
            'source'         => DeviceStencil::SOURCE_AUTO_GENERATED,
            'needs_review'   => true,
        ], $overrides));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    // ── Task 1: StencilXmlToSvgRenderer ─────────────────────────────────────

    public function test_stencil_xml_to_svg_renderer_zero_port_placeholder_renders_header_rect_and_manufacturer_text(): void
    {
        $result = $this->generator()->build(['part_number' => 'X', 'manufacturer' => 'Y']);

        $svg = $this->renderer()->render($result['mxgraph_xml'], $result['default_width'], $result['default_height']);

        // Header bar rect (fillcolor #1B7A7A) is present.
        $this->assertMatchesRegularExpression('/<rect\b[^>]*fill="#1B7A7A"[^>]*\/>/', $svg);
        // Manufacturer text ("Y") is present.
        $this->assertStringContainsString('>Y<', $svg);
        // No rail/constraint markup for the zero-port case.
        $this->assertStringNotContainsString('stroke-dasharray', $svg);
        $this->assertStringNotContainsString('constraint', $svg);
    }

    public function test_stencil_xml_to_svg_renderer_dashed_and_muted_rail_lines_and_skips_connections(): void
    {
        $result = $this->generator()->build([
            'part_number' => 'GS312TP',
            'ports'       => $this->switchPorts(),
        ]);

        $svg = $this->renderer()->render($result['mxgraph_xml'], $result['default_width'], $result['default_height']);

        // Exactly 4 rail-tick lines, dashed + muted per the real
        // dashed/strokealpha state-element grammar.
        $this->assertSame(
            4,
            preg_match_all('/<line\b[^>]*stroke-dasharray="4 3"[^>]*stroke-opacity="0\.6"[^>]*\/>/', $svg)
        );

        // <connections>/<constraint> carry zero visual representation.
        $this->assertStringNotContainsString('constraint', $svg);
        $this->assertStringNotContainsString('<connections', $svg);
    }

    public function test_stencil_xml_to_svg_renderer_resets_dashed_and_alpha_state_after_reset_elements(): void
    {
        // Hand-authored minimal fixture: a dashed/muted line followed by a
        // reset, followed by a normal (non-provisional) line — proves the
        // parser tracks state that resets correctly, not a one-shot flag
        // applied to the whole document.
        $fixture = '<shape name="test" h="10" w="10" aspect="variable" strokewidth="inherit">'
            .'<foreground>'
            .'<strokecolor color="#94A3B8"/>'
            .'<dashed dashed="1"/>'
            .'<strokealpha alpha="0.6"/>'
            .'<line x1="0" y1="0" x2="8" y2="0"/>'
            .'<stroke/>'
            .'<dashed dashed="0"/>'
            .'<strokealpha alpha="1"/>'
            .'<line x1="0" y1="5" x2="8" y2="5"/>'
            .'<stroke/>'
            .'</foreground>'
            .'</shape>';

        $svg = $this->renderer()->render($fixture, 10, 10);

        preg_match_all('/<line\b[^>]*\/>/', $svg, $matches);
        $this->assertCount(2, $matches[0]);

        $this->assertStringContainsString('stroke-dasharray="4 3"', $matches[0][0]);
        $this->assertStringContainsString('stroke-opacity="0.6"', $matches[0][0]);

        $this->assertStringNotContainsString('stroke-dasharray', $matches[0][1]);
        $this->assertStringNotContainsString('stroke-opacity', $matches[0][1]);
    }

    public function test_stencil_xml_to_svg_renderer_applies_global_alpha_to_text_fill_opacity(): void
    {
        $result = $this->generator()->build([
            'part_number' => 'GS312TP',
            'ports'       => $this->switchPorts(),
        ]);

        $svg = $this->renderer()->render($result['mxgraph_xml'], $result['default_width'], $result['default_height']);

        // Port label text, drawn while alpha=0.6 is in effect, carries
        // fill-opacity — proves alpha is modelled as GLOBAL state
        // (mxStencil.js:948-951), not a stroke-only channel.
        $this->assertMatchesRegularExpression('/<text\b[^>]*fill-opacity="0\.6"[^>]*>RJ45 1</', $svg);

        // Header manufacturer text, drawn BEFORE the mute window, carries
        // no fill-opacity at all.
        $this->assertDoesNotMatchRegularExpression('/<text\b[^>]*fill-opacity[^>]*>Netgear</', $svg);
    }

    public function test_stencil_xml_to_svg_renderer_rejects_percentage_scale_alpha(): void
    {
        // Regression guard against reintroducing the 0-100 scale that
        // mxSvgCanvas2D.js:1137 would clamp to fully opaque — the renderer
        // must reject/ignore this rather than emitting stroke-opacity="60".
        $fixture = '<shape name="test" h="10" w="10" aspect="variable" strokewidth="inherit">'
            .'<foreground>'
            .'<strokecolor color="#94A3B8"/>'
            .'<strokealpha alpha="60"/>'
            .'<line x1="0" y1="0" x2="8" y2="0"/>'
            .'<stroke/>'
            .'</foreground>'
            .'</shape>';

        $svg = $this->renderer()->render($fixture, 10, 10);

        $this->assertStringNotContainsString('stroke-opacity="60"', $svg);
        $this->assertStringNotContainsString('stroke-opacity', $svg);
    }

    public function test_stencil_xml_to_svg_renderer_output_is_well_formed_svg(): void
    {
        $result = $this->generator()->build([
            'part_number' => 'GS312TP',
            'ports'       => $this->switchPorts(),
        ]);

        $svg = $this->renderer()->render($result['mxgraph_xml'], $result['default_width'], $result['default_height']);

        $this->assertStringStartsWith('<svg', $svg);

        $dom = new DOMDocument();
        $loaded = $dom->loadXML($svg);
        $this->assertTrue($loaded, 'Renderer output must be well-formed, parseable XML.');
    }

    public function test_stencil_xml_to_svg_renderer_does_not_double_escape_already_escaped_text(): void
    {
        $result = $this->generator()->build([
            'part_number' => 'XSS-TEST',
            'ports' => [
                ['label' => '<script>alert(1)</script>', 'side' => 'left', 'connector_type' => 'hdmi', 'signal_type' => 'video', 'direction' => 'in', 'sort_order' => 1, 'port_id' => 'hdmi-1'],
            ],
        ]);

        // Sanity: the generator's own XML carries the escaped form.
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $result['mxgraph_xml']);

        $svg = $this->renderer()->render($result['mxgraph_xml'], $result['default_width'], $result['default_height']);

        // Single round-trip: escaped once in the SVG output, never twice,
        // and never re-interpreted as live markup.
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $svg);
        $this->assertStringNotContainsString('&amp;lt;script&amp;gt;', $svg);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $svg);

        $dom = new DOMDocument();
        $dom->loadXML($svg);
        $this->assertStringContainsString('<script>alert(1)</script>', $dom->textContent);
    }

    public function test_stencil_xml_to_svg_renderer_round_trips_real_generator_output_for_zero_and_templated_ports(): void
    {
        $zeroPort = $this->generator()->build(['part_number' => 'ROUNDTRIP-0', 'manufacturer' => 'Acme']);
        $templated = $this->generator()->build(['part_number' => 'ROUNDTRIP-4', 'ports' => $this->switchPorts()]);

        $svgZero = $this->renderer()->render($zeroPort['mxgraph_xml'], $zeroPort['default_width'], $zeroPort['default_height']);
        $svgTemplated = $this->renderer()->render($templated['mxgraph_xml'], $templated['default_width'], $templated['default_height']);

        $this->assertStringStartsWith('<svg', $svgZero);
        $this->assertStringStartsWith('<svg', $svgTemplated);
    }

    public function test_stencil_xml_to_svg_renderer_never_reads_dashed_or_alpha_attribute_off_a_line_node(): void
    {
        $source = file_get_contents(app_path('Services/Drawings/StencilXmlToSvgRenderer.php'));
        $this->assertNotFalse($source);

        // getAttribute('dashed') / getAttribute('alpha') must each appear
        // exactly once as an EXECUTABLE call in the whole file — inside the
        // sibling <dashed>/<strokealpha> element handlers, never inside
        // emitLine(). (The class docblock also references the ground-truth
        // call once each, in prose — code lines only, here.)
        $codeLines = array_filter(
            explode("\n", $source),
            fn (string $line): bool => ! str_starts_with(trim($line), '*') && ! str_starts_with(trim($line), '//')
        );
        $code = implode("\n", $codeLines);

        $this->assertSame(1, substr_count($code, "getAttribute('dashed')"));
        $this->assertSame(1, substr_count($code, "getAttribute('alpha')"));

        preg_match('/private function emitLine\(.*?\n    \}\n/s', $source, $matches);
        $this->assertNotEmpty($matches, 'Could not isolate emitLine() body for grep-verification.');
        $emitLineBody = $matches[0];

        $this->assertStringNotContainsString("getAttribute('dashed')", $emitLineBody);
        $this->assertStringNotContainsString("getAttribute('alpha')", $emitLineBody);
        $this->assertStringNotContainsString("getAttribute('opacity')", $emitLineBody);
    }

    // ── Task 2: DeviceStencilController::preview() + route ─────────────────

    public function test_preview_endpoint_returns_svg_for_valid_ports_payload(): void
    {
        $stencil = $this->makeStencil();

        $response = $this->actingAs($this->admin)
            ->postJson(route('admin.device-stencils.preview', $stencil), [
                'ports' => $this->switchPorts(),
            ]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/svg+xml');
        $this->assertStringStartsWith('<svg', $response->getContent());
    }

    public function test_preview_endpoint_persists_nothing_across_repeated_calls(): void
    {
        $stencil = $this->makeStencil();
        $beforeStencilCount = DeviceStencil::count();
        $beforePortCount = DevicePort::count();
        $beforeAttributes = $stencil->fresh()->getAttributes();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->admin)
                ->postJson(route('admin.device-stencils.preview', $stencil), [
                    'ports' => $this->switchPorts(),
                ])
                ->assertOk();
        }

        $this->assertSame($beforeStencilCount, DeviceStencil::count());
        $this->assertSame($beforePortCount, DevicePort::count());
        $this->assertSame($beforeAttributes, $stencil->fresh()->getAttributes());
    }

    public function test_preview_endpoint_rejects_invalid_ports_payload_with_422_never_500(): void
    {
        $stencil = $this->makeStencil();

        // Duplicate port_id + missing direction on the second row.
        $response = $this->actingAs($this->admin)
            ->postJson(route('admin.device-stencils.preview', $stencil), [
                'ports' => [
                    ['label' => 'A', 'side' => 'left', 'connector_type' => 'hdmi', 'signal_type' => 'video', 'direction' => 'in', 'sort_order' => 1, 'port_id' => 'dupe-1'],
                    ['label' => 'B', 'side' => 'left', 'connector_type' => 'hdmi', 'signal_type' => 'video', 'sort_order' => 2, 'port_id' => 'dupe-1'],
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_preview_endpoint_svg_line_count_matches_posted_port_count(): void
    {
        $stencil = $this->makeStencil();

        $threePorts = [
            ['label' => 'A', 'side' => 'left', 'connector_type' => 'hdmi', 'signal_type' => 'video', 'direction' => 'in', 'sort_order' => 1, 'port_id' => 'a-1'],
            ['label' => 'B', 'side' => 'left', 'connector_type' => 'hdmi', 'signal_type' => 'video', 'direction' => 'in', 'sort_order' => 2, 'port_id' => 'b-1'],
            ['label' => 'C', 'side' => 'left', 'connector_type' => 'hdmi', 'signal_type' => 'video', 'direction' => 'in', 'sort_order' => 3, 'port_id' => 'c-1'],
        ];

        $response = $this->actingAs($this->admin)
            ->postJson(route('admin.device-stencils.preview', $stencil), [
                'ports' => $threePorts,
            ]);

        $response->assertOk();

        $svg = $response->getContent();
        $this->assertSame(3, preg_match_all('/<line\b[^>]*stroke-dasharray="4 3"[^>]*\/>/', $svg));

        // The intermediate mxgraph_xml layer must never contain these CSS
        // strings (Plan 24-01's grammar) — only the SVG output layer does.
        $this->assertStringNotContainsString('constraint', $svg);
    }
}
