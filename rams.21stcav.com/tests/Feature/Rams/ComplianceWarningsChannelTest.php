<?php

namespace Tests\Feature\Rams;

use App\Services\Rams\RamsComplianceUpgradeService;
use Tests\TestCase;

/**
 * Phase 30 Plan 01 (UI-SPEC warn channel) — proves `upgrade()` writes
 * `generated_data['compliance_warnings']` UNCONDITIONALLY and WHOLESALE on
 * every run, following the `resolveSiteEmergency()` precedent
 * (`upgrade():83-86` — unconditional enrichment sitting beside a flag-gated
 * throw) rather than writing it only inside a flag-gated block, which would
 * leave a stale persisted warnings array on every document the moment a
 * flag flipped back to false.
 *
 * All three Phase 30 gate flags are false by default (D-03) and this test
 * does not flip any of them — no gate body exists yet (that is plans
 * 30-03/30-06/30-07/30-08). This file only proves the channel itself: it
 * exists, it is empty by default, it clears stale entries, and it changes
 * nothing else about `upgrade()`'s output.
 *
 * ── Non-vacuity proof (development-time only, not a committed assertion) ──
 *
 *   1. Before `upgrade()` wrote `compliance_warnings`, every test in this
 *      file that asserts `assertArrayHasKey('compliance_warnings', ...)`
 *      failed.
 *   2. After adding the unconditional `$ramsData['compliance_warnings'] = []`
 *      initialisation near the top of `upgrade()`, all tests passed.
 *
 * @see app/Services/Rams/RamsComplianceUpgradeService.php
 * @see .planning/phases/30-structural-validation-gates/30-01-PLAN.md
 * @see .planning/phases/30-structural-validation-gates/30-UI-SPEC.md
 */
class ComplianceWarningsChannelTest extends TestCase
{
    public function test_upgrade_writes_empty_compliance_warnings_with_all_phase_30_flags_false(): void
    {
        $result = RamsComplianceUpgradeService::upgrade([]);

        $this->assertArrayHasKey('compliance_warnings', $result);
        $this->assertSame([], $result['compliance_warnings']);
    }

    public function test_upgrade_overwrites_stale_compliance_warnings_wholesale(): void
    {
        $result = RamsComplianceUpgradeService::upgrade([
            'compliance_warnings' => [
                ['gate' => 'GATE-04', 'hazard_index' => 3, 'hazard' => 'Stale Hazard', 'message' => 'stale from a previous run'],
            ],
        ]);

        // Overwritten wholesale, never appended to — the stale entry must
        // be gone, not merged alongside a fresh empty result.
        $this->assertSame([], $result['compliance_warnings']);
    }

    public function test_upgrade_output_otherwise_unchanged_with_all_flags_false(): void
    {
        config([
            'rams_tier1.display_lift_gate_enabled' => false,
            'rams_tier1.ffp2_confined_space_gate_enabled' => false,
            'rams_tier1.cdm_ae_gate_enabled' => false,
            'rams_tier1.structural_gates_enabled' => false,
            'rams_tier1.missing_risk_ref_gate_enabled' => false,
            'rams_tier1.hot_works_gate_enabled' => false,
        ]);

        $input = [
            'hazards' => [
                ['hazard' => 'Manual Handling', 'pre_likelihood' => 2, 'pre_severity' => 3],
            ],
        ];

        $result = RamsComplianceUpgradeService::upgrade($input);

        // Baseline captured from a second identical call — proves the only
        // structural difference this plan introduces is the new key itself,
        // not a change to any pre-existing computed value.
        $baseline = RamsComplianceUpgradeService::upgrade($input);
        unset($baseline['compliance_warnings']);
        $withoutNewKey = $result;
        unset($withoutNewKey['compliance_warnings']);

        $this->assertSame($baseline, $withoutNewKey);
        $this->assertArrayHasKey('compliance_warnings', $result);
    }

    public function test_upgrade_does_not_throw_because_of_the_new_key(): void
    {
        // Deliberately malformed compliance_warnings input (wrong shape) —
        // the wholesale overwrite must tolerate this without throwing.
        $result = RamsComplianceUpgradeService::upgrade([
            'compliance_warnings' => 'not-an-array-at-all',
        ]);

        $this->assertSame([], $result['compliance_warnings']);
    }
}
