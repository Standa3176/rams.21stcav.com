<?php

namespace Tests\Unit\Services;

use App\Core\Modules\OMManual\OmManualGeneratorService;
use Tests\TestCase;

/**
 * Unit tests for the Tier-1 O&M loop close (2026-07): manufacturer support
 * and service-escalation overrides on `$manual->extracted_data` must reach
 * the PDF via the generator's deterministic layer.
 *
 * The wiring under test is the *precedence* of override cells over resolver
 * defaults, not the resolver itself. Tests hit the helpers via reflection so
 * we don't need to spin up an AI call to exercise them.
 */
class OmManualOverrideMergeTest extends TestCase
{
    private function invokePrivate(string $method, ...$args): mixed
    {
        $ref = new \ReflectionClass(OmManualGeneratorService::class);
        $m   = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke(null, ...$args);
    }

    // ── normaliseManufacturerOverrides ─────────────────────────────────────

    public function test_normalise_mfg_overrides_drops_rows_with_blank_brand(): void
    {
        $raw = [
            ['brand' => 'Crestron', 'phone' => '+44 1', 'email' => '', 'portal' => '', 'warranty' => ''],
            ['brand' => '',         'phone' => 'xxx',   'email' => 'x@x', 'portal' => '', 'warranty' => ''],
            ['brand' => '   ',      'phone' => '',      'email' => '',    'portal' => '', 'warranty' => ''],
            ['brand' => 'Sony',     'phone' => '',      'email' => 'x@sony', 'portal' => '', 'warranty' => ''],
        ];

        $out = $this->invokePrivate('normaliseManufacturerOverrides', $raw);

        $this->assertCount(2, $out, 'Blank-brand rows must be dropped.');
        $this->assertArrayHasKey('crestron', $out);
        $this->assertArrayHasKey('sony',     $out);
        $this->assertSame('Crestron', $out['crestron']['brand']);
        $this->assertSame('+44 1',    $out['crestron']['phone']);
    }

    public function test_normalise_mfg_overrides_key_is_case_and_whitespace_insensitive(): void
    {
        // "Crestron " should match a detected brand like "crestron" or
        // "CRESTRON" — the lookup key is the mb_strtolower(trim(...)) result.
        $raw = [
            ['brand' => '  CRESTRON  ', 'phone' => '+44 999', 'email' => '', 'portal' => '', 'warranty' => ''],
        ];
        $out = $this->invokePrivate('normaliseManufacturerOverrides', $raw);

        $this->assertArrayHasKey('crestron', $out);
        $this->assertSame('CRESTRON', $out['crestron']['brand'], 'Display brand preserved as typed.');
        $this->assertSame('+44 999',  $out['crestron']['phone']);
    }

    public function test_normalise_mfg_overrides_returns_empty_array_when_input_is_not_array(): void
    {
        $this->assertSame([], $this->invokePrivate('normaliseManufacturerOverrides', null));
        $this->assertSame([], $this->invokePrivate('normaliseManufacturerOverrides', 'garbage'));
        $this->assertSame([], $this->invokePrivate('normaliseManufacturerOverrides', 42));
    }

    // ── normaliseEscalationOverride ────────────────────────────────────────

    public function test_normalise_escalation_returns_fixed_shape_even_when_input_is_null(): void
    {
        $out = $this->invokePrivate('normaliseEscalationOverride', null);

        $this->assertSame([
            'contact_name' => '',
            'phone'        => '',
            'email'        => '',
            'hours'        => '',
            'matrix'       => '',
        ], $out);
    }

    public function test_normalise_escalation_trims_each_field(): void
    {
        $raw = [
            'contact_name' => '  21st Century AV Service Desk  ',
            'phone'        => "\t+44 1189 977770\n",
            'email'        => 'support@21stcenturyav.com',
            'hours'        => 'Mon–Fri 09:00–17:30',
            'matrix'       => "L1 helpdesk\nL2 lead engineer\nL3 PM",
        ];
        $out = $this->invokePrivate('normaliseEscalationOverride', $raw);

        $this->assertSame('21st Century AV Service Desk', $out['contact_name']);
        $this->assertSame('+44 1189 977770',              $out['phone']);
        $this->assertStringContainsString('L1 helpdesk',   $out['matrix']);
    }

    // ── preferOverride ──────────────────────────────────────────────────────

    public function test_prefer_override_uses_non_empty_override(): void
    {
        $this->assertSame(
            '+44 1234',
            $this->invokePrivate('preferOverride', '+44 1234', 'default-phone'),
        );
    }

    public function test_prefer_override_falls_back_when_override_is_null_empty_or_whitespace(): void
    {
        $this->assertSame('default-phone', $this->invokePrivate('preferOverride', null,   'default-phone'));
        $this->assertSame('default-phone', $this->invokePrivate('preferOverride', '',     'default-phone'));
        $this->assertSame('default-phone', $this->invokePrivate('preferOverride', '   ',  'default-phone'));
        $this->assertSame('default-phone', $this->invokePrivate('preferOverride', "\t\n", 'default-phone'));
    }

    // ── brandKey ────────────────────────────────────────────────────────────

    public function test_brand_key_is_lowercase_trimmed(): void
    {
        $this->assertSame('crestron', $this->invokePrivate('brandKey', 'Crestron'));
        $this->assertSame('crestron', $this->invokePrivate('brandKey', '  CRESTRON  '));
        $this->assertSame('crestron', $this->invokePrivate('brandKey', 'crestron'));
    }
}
