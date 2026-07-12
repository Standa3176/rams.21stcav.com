<?php

namespace Tests\Feature\Rams;

use Carbon\Carbon;
use Tests\TestCase;

/**
 * Quick task 260712-twi Task 4 feature tests.
 *
 * Locks the structured COSHH inventory table with GHS/CLP hazard codes
 * that replaces the legacy 4-item bullet list. Superset change — the
 * legacy list is preserved in an @else branch as a safety net for when
 * rams_tier1.enabled is false.
 *
 * Tests cover:
 *   1. Baseline COSHH table renders with the expected GHS codes and
 *      product names when rams_tier1.enabled is true.
 *   2. Engineer-added COSHH bullets from $data['coshh'] render BELOW
 *      the baseline table (existing behaviour preserved).
 *   3. Kill-switch off → legacy 4-item bullet list renders instead.
 */
class Tier1CoshhTableRenderTest extends TestCase
{
    private function ramsStub(): object
    {
        $stub                = new \stdClass();
        $stub->project_name  = 'Test Project';
        $stub->project_ref   = 'TEST-001';
        $stub->form_data     = [];
        $stub->client_name   = 'Test Client';
        $stub->site_address  = 'Test Site Address';
        $stub->created_at    = Carbon::create(2026, 7, 12);
        $stub->reviewed_data = [];

        return $stub;
    }

    private function baseData(array $overrides = []): array
    {
        return array_merge([
            'scope_of_works'  => 'Test scope',
            'project'         => [
                'name'         => 'Test Project',
                'ref'          => 'TEST-001',
                'client'       => 'Test Client',
                'site_address' => 'Test Site Address',
            ],
            'hazards'          => [],
            'ppe'              => [],
            'persons_at_risk'  => [],
            'team'             => [],
            'method_statement' => ['phases' => []],
            'quote'            => [],
            'site_logistics'   => [],
        ], $overrides);
    }

    private function renderWith(array $data): string
    {
        return view('pdf.rams', [
            'data' => $data,
            'rams' => $this->ramsStub(),
        ])->render();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 1. Baseline COSHH table with GHS codes renders
    // ══════════════════════════════════════════════════════════════════════════

    public function test_pdf_renders_coshh_baseline_table_with_ghs_codes(): void
    {
        config(['rams_tier1.enabled' => true]);

        $html = $this->renderWith($this->baseData());

        $this->assertStringContainsString('Standard AV Install COSHH Inventory', $html);
        $this->assertStringContainsString('GHS Hazard Codes', $html);
        // A representative product from the config (case-insensitive check).
        $this->assertMatchesRegularExpression('/isopropyl/i', $html);
        // GHS code for isopropyl alcohol.
        $this->assertStringContainsString('H225', $html);
        // Lead/tin solder product.
        $this->assertMatchesRegularExpression('/(Sn\/Pb|Tin\/Lead)/i', $html);
        // Reference to SDS binder in the trailing paragraph.
        $this->assertStringContainsString('Safety Data Sheet', $html);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 2. Engineer-added coshh bullets render BELOW the baseline table
    // ══════════════════════════════════════════════════════════════════════════

    public function test_engineer_added_coshh_bullets_render_below_baseline_table(): void
    {
        config(['rams_tier1.enabled' => true]);

        $customCoshh = 'Custom site-specific COSHH: contact adhesive brand X used in comms room';

        $html = $this->renderWith($this->baseData([
            'coshh' => [$customCoshh],
        ]));

        // Both must be present.
        $this->assertStringContainsString('Standard AV Install COSHH Inventory', $html);
        $this->assertStringContainsString($customCoshh, $html);
        $this->assertStringContainsString('Site-specific COSHH entries', $html);

        // Position check: engineer additions must appear AFTER the baseline
        // table heading in the HTML source.
        $baselinePos = strpos($html, 'Standard AV Install COSHH Inventory');
        $customPos   = strpos($html, $customCoshh);
        $this->assertNotFalse($baselinePos);
        $this->assertNotFalse($customPos);
        $this->assertGreaterThan(
            $baselinePos,
            $customPos,
            'Engineer-added COSHH entries must render BELOW the baseline table.',
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 3. Kill-switch off → legacy 4-item bullet list restored
    // ══════════════════════════════════════════════════════════════════════════

    public function test_disabled_flag_falls_back_to_legacy_bullet_list(): void
    {
        config(['rams_tier1.enabled' => false]);

        $html = $this->renderWith($this->baseData());

        // Legacy bullet list restored.
        $this->assertStringContainsString('Cable conduit adhesives / sealants', $html);
        // Baseline table markers must NOT appear.
        $this->assertStringNotContainsString('Standard AV Install COSHH Inventory', $html);
    }
}
