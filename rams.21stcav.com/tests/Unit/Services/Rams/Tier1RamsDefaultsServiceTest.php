<?php

namespace Tests\Unit\Services\Rams;

use App\Services\Rams\Tier1RamsDefaultsService;
use Tests\TestCase;

/**
 * Quick task 260712-twi Task 1 unit tests.
 *
 * Locks the tier-1 defaults fallback layer:
 *
 *   1. Injects baseline hazards when $data['hazards'] is missing.
 *   2. Preserves engineer-supplied hazards verbatim (engineer wins).
 *   3. Treats empty-array hazards as "missing" and replaces with baseline.
 *   4. Preserves engineer-supplied standards_references verbatim.
 *   5. Disabled kill-switch returns $data unchanged.
 *
 * @see app/Services/Rams/Tier1RamsDefaultsService.php
 * @see config/rams_tier1.php
 * @see .planning/quick/260712-twi-tier-1-av-rams-content-upgrade-baseline-/260712-twi-PLAN.md
 */
class Tier1RamsDefaultsServiceTest extends TestCase
{
    private function service(): Tier1RamsDefaultsService
    {
        return new Tier1RamsDefaultsService();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 1. Injects baseline hazards when reviewed data has none
    // ══════════════════════════════════════════════════════════════════════════

    public function test_injects_baseline_hazards_when_data_hazards_is_missing(): void
    {
        $data = ['project' => ['name' => 'Test']];

        $out = $this->service()->injectDefaultsIntoRamsData($data);

        $this->assertArrayHasKey('hazards', $out);
        $this->assertIsArray($out['hazards']);
        $this->assertGreaterThanOrEqual(
            8,
            count($out['hazards']),
            'Expected >= 8 canonical AV baseline hazards to be injected.',
        );
        // Sanity check the shape of the first hazard.
        $this->assertArrayHasKey('hazard', $out['hazards'][0]);
        $this->assertArrayHasKey('controls', $out['hazards'][0]);
        $this->assertGreaterThanOrEqual(3, count($out['hazards'][0]['controls']));
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
            'Engineer-supplied hazards must NOT be augmented with baseline.',
        );
        $this->assertSame(
            'Custom engineer hazard XYZ',
            $out['hazards'][0]['hazard'],
            'Engineer hazard title must be preserved verbatim.',
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 3. Empty array hazards treated as missing → baseline injected
    // ══════════════════════════════════════════════════════════════════════════

    public function test_treats_empty_array_hazards_as_missing(): void
    {
        $data = ['hazards' => []];

        $out = $this->service()->injectDefaultsIntoRamsData($data);

        $this->assertNotEmpty(
            $out['hazards'],
            'Empty hazards[] must be replaced with baseline set.',
        );
        $this->assertGreaterThanOrEqual(8, count($out['hazards']));
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
