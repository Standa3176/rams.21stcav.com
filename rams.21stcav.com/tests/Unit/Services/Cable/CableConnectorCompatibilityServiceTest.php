<?php

namespace Tests\Unit\Services\Cable;

use App\Services\Cable\CableConnectorCompatibilityService;
use Tests\TestCase;

/**
 * Phase 22 Plan 01 Task 3 — locks the connector-compatibility contract.
 *
 * Coverage:
 *   - DRAW-39 exact-match compatible
 *   - DRAW-39 case-insensitive comparison (HDMI / hdmi / Hdmi all equivalent)
 *   - DRAW-39 named-allowlist pairs compatible bidirectionally (A4)
 *   - DRAW-39 explicit mismatch returns {compatible:false, reason:string}
 *   - Pitfall 4 — empty / Tier 1.5 stencil tolerance: empty connector_type
 *     treated as "unknown — assume compatible" (91 of 96 seeded stencils
 *     have empty ports until Phase 24 curates them)
 *   - config/cables.php seeded with allowlist + Phase 23 signal-type colour map
 *
 * No RefreshDatabase — the service is pure-function. Tests\TestCase boots the
 * Laravel app container so config('cables.*') resolves to the file under test.
 */
class CableConnectorCompatibilityServiceTest extends TestCase
{
    private CableConnectorCompatibilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CableConnectorCompatibilityService;
    }

    public function test_exact_match_is_compatible(): void
    {
        $result = $this->service->check('hdmi', 'hdmi');
        $this->assertTrue($result['compatible']);
        $this->assertNull($result['reason']);
    }

    public function test_case_insensitive_match(): void
    {
        $result = $this->service->check('HDMI', 'hdmi');
        $this->assertTrue($result['compatible']);

        $result = $this->service->check(' Hdmi ', 'HDMI');
        $this->assertTrue($result['compatible']);
    }

    public function test_exact_mismatch_returns_incompatible_with_reason(): void
    {
        $result = $this->service->check('hdmi', 'rj45');
        $this->assertFalse($result['compatible']);
        $this->assertSame('Connector mismatch: hdmi → rj45', $result['reason']);
    }

    public function test_allowlist_forward_direction_compatible(): void
    {
        $result = $this->service->check('hdmi', 'dp');
        $this->assertTrue($result['compatible']);
        $this->assertSame('HDMI ↔ DisplayPort via active adapter', $result['reason']);
    }

    public function test_allowlist_reverse_direction_compatible_bidirectional(): void
    {
        // A4 (RESEARCH): allowlist entries match in both directions.
        $result = $this->service->check('dp', 'hdmi');
        $this->assertTrue($result['compatible']);
        $this->assertSame('HDMI ↔ DisplayPort via active adapter', $result['reason']);
    }

    public function test_usbc_thunderbolt_alias(): void
    {
        $result = $this->service->check('usb-c', 'thunderbolt');
        $this->assertTrue($result['compatible']);
        $this->assertSame('USB-C ↔ Thunderbolt 3/4 backwards-compatible', $result['reason']);
    }

    public function test_empty_source_connector_treated_as_compatible(): void
    {
        // Pitfall 4: Tier 1.5 stencils (91 of 96) have empty ports until
        // Phase 24 curates them. Empty connector_type → "unknown, assume
        // compatible" — not a mismatch.
        $result = $this->service->check('', 'hdmi');
        $this->assertTrue($result['compatible']);
        $this->assertSame('connector type not catalogued — assume compatible', $result['reason']);
    }

    public function test_empty_dest_connector_treated_as_compatible(): void
    {
        $result = $this->service->check('hdmi', '');
        $this->assertTrue($result['compatible']);
        $this->assertSame('connector type not catalogued — assume compatible', $result['reason']);
    }

    public function test_both_empty_treated_as_compatible(): void
    {
        $result = $this->service->check('', '');
        $this->assertTrue($result['compatible']);
    }

    public function test_whitespace_only_treated_as_empty(): void
    {
        $result = $this->service->check('   ', 'hdmi');
        $this->assertTrue($result['compatible']);
        $this->assertSame('connector type not catalogued — assume compatible', $result['reason']);
    }

    public function test_compatibility_aliases_config_seeded(): void
    {
        $aliases = (array) config('cables.compatibility_aliases');
        $this->assertGreaterThanOrEqual(3, count($aliases));
    }

    public function test_signal_type_colours_config_seeded_for_phase_23(): void
    {
        $colours = (array) config('cables.signal_type_colours');
        foreach (['audio', 'video', 'control', 'network', 'usb', 'speaker'] as $key) {
            $this->assertArrayHasKey($key, $colours);
        }
    }
}
