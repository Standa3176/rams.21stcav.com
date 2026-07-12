<?php

namespace Tests\Feature\Rams;

use Carbon\Carbon;
use Tests\TestCase;

/**
 * Quick task 260712-twi Task 2 feature tests — PDF Section 5 baseline
 * hazards fallback + engineer-hazards-win override.
 *
 * Locks the render-time defensive fallback added at line ~309 of
 * resources/views/pdf/rams.blade.php:
 *
 *   1. When $data['hazards'] is empty AND config('rams_tier1.enabled') is
 *      true, the 8 canonical baseline AV hazards render in the register.
 *   2. When $data['hazards'] contains engineer-supplied hazards, those
 *      render VERBATIM and the baseline is NOT injected.
 *   3. When the kill-switch is off, the empty-register "No hazards
 *      identified" note is preserved (existing else-branch behaviour).
 *
 * View rendered directly (no Browsershot, no PDF binary) so tests run fast
 * and Content assertions are simple string checks.
 */
class Tier1BaselineHazardsRenderTest extends TestCase
{
    /** Minimal $rams object stub for the view. */
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
    // 1. Empty hazards → baseline fallback fires
    // ══════════════════════════════════════════════════════════════════════════

    public function test_baseline_hazards_render_when_reviewed_data_hazards_is_empty(): void
    {
        config(['rams_tier1.enabled' => true]);

        $html = $this->renderWith($this->baseData(['hazards' => []]));

        // At least 3 of the 8 canonical baseline hazard titles must appear.
        $this->assertStringContainsString('Working at Height', $html);
        $this->assertStringContainsString('Manual Handling of AV Equipment', $html);
        $this->assertStringContainsString('Electrical Isolation', $html);
        // "No hazards identified" else-branch text must NOT appear because
        // the fallback populated the register.
        $this->assertStringNotContainsString('No hazards identified.', $html);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 2. Engineer-supplied hazards render verbatim, baseline NOT injected
    // ══════════════════════════════════════════════════════════════════════════

    public function test_engineer_supplied_hazards_render_verbatim_and_baseline_is_not_injected(): void
    {
        config(['rams_tier1.enabled' => true]);

        $engineerHazards = [
            [
                'hazard'          => 'Custom test hazard XYZ',
                'persons_at_risk' => ['Engineers'],
                'controls'        => ['Custom control 1', 'Custom control 2'],
                'pre_likelihood'  => 2,
                'pre_severity'    => 3,
                'post_likelihood' => 1,
                'post_severity'   => 3,
            ],
        ];

        $html = $this->renderWith($this->baseData(['hazards' => $engineerHazards]));

        // Engineer values ALWAYS win.
        $this->assertStringContainsString('Custom test hazard XYZ', $html);
        // Canonical baseline title must NOT appear because engineer supplied
        // their own set.
        $this->assertStringNotContainsString('Manual Handling of AV Equipment', $html);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 3. Kill-switch off → legacy "No hazards identified" restored
    // ══════════════════════════════════════════════════════════════════════════

    public function test_disabled_flag_leaves_hazards_empty_no_baseline_injected(): void
    {
        config(['rams_tier1.enabled' => false]);

        $html = $this->renderWith($this->baseData(['hazards' => []]));

        // Legacy else-branch behaviour restored.
        $this->assertStringContainsString('No hazards identified.', $html);
        // Baseline titles must NOT appear.
        $this->assertStringNotContainsString('Working at Height', $html);
    }
}
