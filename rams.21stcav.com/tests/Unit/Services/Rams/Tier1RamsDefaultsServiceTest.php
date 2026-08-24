<?php

namespace Tests\Unit\Services\Rams;

use App\Services\Rams\Tier1RamsDefaultsService;
use Tests\TestCase;

/**
 * Quick task 260712-twi Task 1 unit tests, updated by Phase 26 Plan 03
 * (Hazard Library Structural Inversion) to lock the current no-hazard-
 * fallback contract:
 *
 *   1. $data['hazards'] missing stays missing/unset — never injected.
 *   2. Preserves engineer-supplied hazards verbatim (engineer wins).
 *   3. Empty-array hazards stays empty — never replaced with a baseline.
 *   4. Preserves engineer-supplied standards_references verbatim.
 *   5. Disabled kill-switch returns $data unchanged.
 *
 * @see app/Services/Rams/Tier1RamsDefaultsService.php
 * @see config/rams_tier1.php
 * @see .planning/phases/26-hazard-library-structural-inversion/26-03-PLAN.md
 */
class Tier1RamsDefaultsServiceTest extends TestCase
{
    private function service(): Tier1RamsDefaultsService
    {
        return new Tier1RamsDefaultsService();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 1. Missing hazards key stays missing — no baseline injection (Phase 26)
    // ══════════════════════════════════════════════════════════════════════════

    public function test_does_not_inject_hazards_when_data_hazards_is_missing(): void
    {
        $data = ['project' => ['name' => 'Test']];

        $out = $this->service()->injectDefaultsIntoRamsData($data);

        // Phase 26 removed the hazards fallback entirely — the service no
        // longer sets $data['hazards'] under any circumstance. Hazard
        // population is now handled upstream by HazardIncludeWhenResolver.
        $this->assertArrayNotHasKey('hazards', $out);
        // Non-hazard tier-1 defaults are unaffected.
        $this->assertArrayHasKey('coshh_baseline', $out);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 2. Engineer-supplied hazards preserved verbatim
    // ══════════════════════════════════════════════════════════════════════════

    public function test_preserves_engineer_supplied_hazards_verbatim(): void
    {
        $engineerHazard = [
            'hazard'          => 'Custom engineer hazard XYZ',
            'persons_at_risk' => ['Engineers'],
            'pre_likelihood'  => 3,
            'pre_severity'    => 3,
            'controls'        => ['Custom control 1'],
            'post_likelihood' => 1,
            'post_severity'   => 2,
        ];

        $data = ['hazards' => [$engineerHazard]];

        $out = $this->service()->injectDefaultsIntoRamsData($data);

        $this->assertCount(
            1,
            $out['hazards'],
            'Engineer-supplied hazards must NOT be augmented with a baseline (there is none left to inject).',
        );
        $this->assertSame(
            'Custom engineer hazard XYZ',
            $out['hazards'][0]['hazard'],
            'Engineer hazard title must be preserved verbatim.',
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 3. Empty array hazards stays empty — no baseline injection (Phase 26)
    // ══════════════════════════════════════════════════════════════════════════

    public function test_empty_array_hazards_stays_empty(): void
    {
        $data = ['hazards' => []];

        $out = $this->service()->injectDefaultsIntoRamsData($data);

        $this->assertSame(
            [],
            $out['hazards'],
            'Empty hazards[] must stay empty — no fallback re-populates it.',
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 4. Standards references preserved when engineer set them
    // ══════════════════════════════════════════════════════════════════════════

    public function test_partial_engineer_data_not_clobbered_for_standards_references(): void
    {
        $engineerStd = [
            'ref'        => 'BS ENGINEER-01',
            'title'      => 'Engineer-supplied standard',
            'applies_to' => 'Custom scope',
        ];

        $data = ['standards_references' => [$engineerStd]];

        $out = $this->service()->injectDefaultsIntoRamsData($data);

        $this->assertCount(
            1,
            $out['standards_references'],
            'Engineer-supplied standards references must NOT be clobbered.',
        );
        $this->assertSame(
            'BS ENGINEER-01',
            $out['standards_references'][0]['ref'],
        );
        // But coshh_baseline is ALWAYS injected (non-clobbering key).
        $this->assertArrayHasKey('coshh_baseline', $out);
        $this->assertNotEmpty($out['coshh_baseline']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 5. Kill-switch: disabled config returns $data unchanged
    // ══════════════════════════════════════════════════════════════════════════

    public function test_disabled_flag_returns_data_unchanged(): void
    {
        config(['rams_tier1.enabled' => false]);

        $data = ['project' => ['name' => 'Test'], 'hazards' => []];

        $out = $this->service()->injectDefaultsIntoRamsData($data);

        $this->assertSame(
            $data,
            $out,
            'When rams_tier1.enabled is false, service must be a no-op.',
        );
        $this->assertArrayNotHasKey(
            'coshh_baseline',
            $out,
            'Kill-switch OFF must skip the coshh_baseline injection too.',
        );
        $this->assertArrayNotHasKey(
            'standards_references',
            $out,
        );
    }
}
