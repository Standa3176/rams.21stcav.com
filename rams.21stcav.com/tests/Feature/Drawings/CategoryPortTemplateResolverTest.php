<?php

namespace Tests\Feature\Drawings;

use App\Services\Drawings\CategoryPortTemplateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 24 Plan 01 Task 2 — locks CategoryPortTemplateResolver's deterministic
 * device-type -> port-template resolution (CONTEXT.md D-06/D-07).
 *
 * @see app/Services/Drawings/CategoryPortTemplateResolver.php
 * @see config/drawings.php `port_templates` / `port_template_precedence`
 */
class CategoryPortTemplateResolverTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): CategoryPortTemplateResolver
    {
        return $this->app->make(CategoryPortTemplateResolver::class);
    }

    /**
     * UI-SPEC line 241's canonical multi-keyword ambiguity fixture (D-07):
     * matches both `display` and `bracket` with opposite templates (2 HDMI
     * vs zero ports) — the explicit precedence rule must win.
     */
    public function test_display_bracket_resolves_to_bracket_not_display(): void
    {
        $result = $this->resolver()->resolve('Samsung 65" Display Bracket', 'SAM-65-BRK');

        $this->assertSame([], $result, 'Must resolve to the empty bracket template, not the display template.');
    }

    public function test_unrecognised_device_type_returns_null(): void
    {
        $result = $this->resolver()->resolve('Neat Bar Pro', 'NEAT-BAR-PRO');

        $this->assertNull($result);
    }

    public function test_switch_keyword_resolves_to_four_deterministically_ided_ports(): void
    {
        $result = $this->resolver()->resolve('Netgear GS312TP PoE Switch', 'GS312TP');

        $this->assertNotNull($result);
        $this->assertCount(4, $result);

        foreach ($result as $port) {
            $this->assertMatchesRegularExpression('/^rj45-[1-4]$/', $port['port_id']);
            $this->assertSame('rj45', $port['connector_type']);
            $this->assertNull($port['x_pct']);
            $this->assertNull($port['y_pct']);
        }

        $portIds = array_column($result, 'port_id');
        $this->assertSame(['rj45-1', 'rj45-2', 'rj45-3', 'rj45-4'], $portIds);
    }

    public function test_cable_short_circuit_beats_everything(): void
    {
        // Contains 'display' AND 'cable' — cable must win regardless of
        // precedence-list ordering (D-07: "cable beats everything").
        $result = $this->resolver()->resolve('HDMI Display Cable 3m', 'CAB-HDMI-3M');

        $this->assertSame([], $result);
    }

    public function test_display_keyword_alone_resolves_to_single_hdmi_port(): void
    {
        $result = $this->resolver()->resolve('Samsung QM65C Display', 'QM65C');

        $this->assertNotNull($result);
        $this->assertCount(1, $result);
        $this->assertSame('hdmi-1', $result[0]['port_id']);
        $this->assertSame('video', $result[0]['signal_type']);
    }

    public function test_resolve_is_deterministic_across_repeated_calls(): void
    {
        $resolver = $this->resolver();

        $first = $resolver->resolve('Netgear GS312TP PoE Switch', 'GS312TP');
        $second = $resolver->resolve('Netgear GS312TP PoE Switch', 'GS312TP');

        $this->assertEquals($first, $second);
    }
}
