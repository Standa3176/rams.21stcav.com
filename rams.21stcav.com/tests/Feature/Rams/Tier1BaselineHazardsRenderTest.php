<?php

namespace Tests\Feature\Rams;

use Carbon\Carbon;
use Tests\TestCase;

/**
 * Phase 26 (Hazard Library Structural Inversion) feature tests — PDF
 * Section 5 hazard rendering, post-removal of the 260712-twi render-time
 * baseline fallback.
 *
 * These tests originally locked the OLD behaviour (quick task 260712-twi
 * Task 2): an empty hazards array silently padded itself with the fixed
 * 11-hazard baseline_hazards config array at render time. Phase 26 Plan 03
 * removed that fallback entirely (resources/views/pdf/rams.blade.php no
 * longer contains it), so this file now locks the OPPOSITE, current
 * contract:
 *
 *   1. $data['hazards'] renders EXACTLY what it is given — an empty array
 *      stays empty and produces the "No hazards identified." note,
 *      regardless of config('rams_tier1.enabled').
 *   2. Engineer-supplied hazards render verbatim (unchanged — there is no
 *      baseline left to not-inject either way, so this is now a simpler
 *      but still-meaningful regression guard against any future
 *      reintroduction of a fallback).
 *   3. config('rams_tier1.enabled') being false or true makes no
 *      difference to hazard rendering any more, because nothing in the
 *      render path reads that flag for hazards — proving the render layer
 *      is stable regardless of the flag's value.
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
    // 1. Empty hazards → NO baseline injected, "No hazards identified." renders
    // ══════════════════════════════════════════════════════════════════════════

    public function test_no_baseline_injected_when_hazards_empty(): void
    {
        config(['rams_tier1.enabled' => true]);

        $html = $this->renderWith($this->baseData(['hazards' => []]));

        // Empty stays empty — the render-time fallback no longer exists.
        $this->assertStringContainsString('No hazards identified.', $html);

        // None of the old fixed-11 baseline hazard titles must appear.
        // "Working at Height" (old Title-Case baseline string) is a
        // deliberate case-sensitive canary distinguishing the removed
        // baseline vocabulary from the new sentence-case skill vocabulary
        // ("Working at height") — a case-insensitive match here would be
        // too weak to prove the old fallback is gone.
        $this->assertStringNotContainsString('Working at Height', $html);
        $this->assertStringNotContainsString('Manual Handling of AV Equipment', $html);
        $this->assertStringNotContainsString('Electrical Isolation', $html);
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
    // 3. rams_tier1.enabled=false → render layer unaffected, still no baseline
    // ══════════════════════════════════════════════════════════════════════════

    public function test_disabled_flag_leaves_hazards_empty_no_baseline_injected(): void
    {
        config(['rams_tier1.enabled' => false]);

        $html = $this->renderWith($this->baseData(['hazards' => []]));

        // Empty stays empty regardless of the flag's value — nothing in the
        // render path reads rams_tier1.enabled for hazards any more.
        $this->assertStringContainsString('No hazards identified.', $html);
        // Baseline titles must NOT appear.
        $this->assertStringNotContainsString('Working at Height', $html);
    }
}
