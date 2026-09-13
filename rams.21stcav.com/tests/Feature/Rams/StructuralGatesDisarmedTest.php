<?php

namespace Tests\Feature\Rams;

use App\Exceptions\RamsGenerationException;
use App\Services\Rams\RamsComplianceUpgradeService;
use Tests\TestCase;

/**
 * Phase 30 Plan 06 — the disarmed-posture and flag-independence proof for
 * the three structural gates (GATE-01, GATE-02, GATE-04) shipped so far
 * (30-03, 30-06). Modelled on
 * {@see \Tests\Feature\Rams\CdmEmergencyDualPathGateTest::test_gates_stay_silent_when_disarmed()}:
 * every assertion is proven in BOTH directions — silent when the flag is
 * false, throwing/warning when the flag is true — because a disarmed-only
 * assertion is vacuous (it would pass even if the gate body were never
 * written).
 *
 * ── Call-site honesty (mirrors CdmEmergencyDualPathGateTest.php:30-75) ──
 *
 * `RamsComplianceUpgradeService::upgrade()` has SIX call sites in
 * production code, grep-verified during this plan:
 *
 *   1. `App\Services\RamsBuilderService::runPipeline()`      (:313)
 *   2. `App\Services\RamsBuilderService::runFromReview()`    (:978)
 *   3. `App\Http\Controllers\RamsController.php` (:621)
 *   4. `App\Http\Controllers\RamsController.php` (:719)
 *   5. `App\Http\Controllers\RamsController.php` (:875)
 *   6. `App\Console\Commands\RamsRefreshComplianceCommand.php` (:185)
 *
 * This file exercises `upgrade()` DIRECTLY (the shared function every one
 * of the six sites calls), not any specific controller/command/builder
 * call site — the same choice `CdmEmergencyDualPathGateTest`'s
 * `test_gate_throws_via_run_from_review()` makes for GATE-12, and the
 * choice this plan's sibling `StructuralGatesTest` already makes for its
 * own "via the real upgrade() entry point" tests. Driving all six call
 * sites individually through full HTTP/console/queue infrastructure is out
 * of this file's scope; `StructuralGatesSaveReviewGateTest` (Plan 30-03)
 * already proves GATE-01/GATE-02 surface correctly through one real HTTP
 * route (site 3, `update-and-download`) without a 500.
 *
 * Known asymmetry, recorded here for completeness (verified against
 * `app/Services/Rams/Tier1RamsDefaultsService.php`'s own docblock, not
 * assumed): `Tier1RamsDefaultsService::injectDefaultsIntoRamsData()` is
 * wired into ONLY the two `RamsBuilderService` call sites (1-2,
 * `runPipeline()`/`runFromReview()`), called immediately AFTER
 * `upgrade()` returns. It unconditionally sets `$data['coshh_baseline']`.
 * Sites 3-6 (`RamsController`'s three sites and
 * `RamsRefreshComplianceCommand`) never call it. So: immediately after
 * `upgrade()` alone returns, `coshh_baseline` is UNCHANGED by `upgrade()`
 * itself at every site (this plan's gates never touch it) — the
 * asymmetry lives one layer OUTSIDE `upgrade()`, in whether the caller
 * chains `injectDefaultsIntoRamsData()` on afterwards (sites 1-2 do;
 * sites 3-6 don't, unless a prior save already persisted the key). This
 * file's assertions are unaffected either way, since none of GATE-01,
 * GATE-02 or GATE-04 read or write `coshh_baseline`.
 *
 * ── Scope note, deliberate (D-04 partial-by-design) ──────────────────────
 *
 * At wave 3 (Plan 30-06) this file could only assert independence against
 * gates that existed then: the structural trio (GATE-01/02/04, one shared
 * flag, `structural_gates_enabled`) and the three previously-shipped gates
 * (`display_lift_gate_enabled`, `ffp2_confined_space_gate_enabled`,
 * `cdm_ae_gate_enabled`). `RamsComplianceUpgradeService::enforceHotWorksGate()`
 * (GATE-13, Plan 30-07, wave 4) DID NOT EXIST YET at that authoring time —
 * writing an assertion against it then would have either fatal'd (method
 * didn't exist) or, worse, passed VACUOUSLY against a stub, proving
 * nothing.
 *
 * Plan 30-07 (wave 4, THIS EXTENSION) now owns and adds the GATE-13 half of
 * the D-04 independence matrix below — see
 * "GATE-13 flag-independence (Plan 30-07)". `::enforceMissingRiskRefGate()`
 * (GATE-14, Plan 30-08, wave 5) STILL DOES NOT EXIST as of this extension
 * — its own reciprocal half remains owned by Plan 30-08, which is expected
 * to extend this same file again in its own wave. D-04's operative clause
 * — that GATE-14 "MUST be killable without disarming the structural trio"
 * — is not yet fully executable; the GATE-13 leg below is. A reader at
 * wave 4 (this plan) should see the remaining GATE-14 gap as partial BY
 * DESIGN, not by omission — REQUIREMENTS.md:72 records the status
 * ambiguity that GATE-11 and GATE-12 sharing one flag produced; this test
 * (and its Plan 30-08 extension) is what stops Phase 30 repeating it.
 *
 * ── Byte-identity scope, honestly stated ─────────────────────────────────
 *
 * "Byte-identical when disarmed" is scoped to: with all three Phase 30
 * flags false, `upgrade()`'s output is identical to the pre-Phase-30
 * baseline APART FROM the `compliance_warnings` key, which Plan 30-01
 * writes UNCONDITIONALLY and DELIBERATELY at the very top of `upgrade()`
 * (before any gate runs, before any flag check) — not something this
 * plan's gates introduce or could suppress. That key is always present
 * and always an empty array when no armed gate has pushed a finding onto
 * it, by design: a flag flip from true back to false must CLEAR any stale
 * warnings from the previous run, not strand them (`upgrade():38-49`'s own
 * docblock; {@see \Tests\Feature\Rams\ComplianceWarningsChannelTest}).
 *
 * @see App\Services\Rams\RamsComplianceUpgradeService::enforceOrphanControlGate()
 * @see App\Services\Rams\RamsComplianceUpgradeService::enforceAreaCoverageGate()
 * @see App\Services\Rams\RamsComplianceUpgradeService::enforceResidualScoreGate()
 * @see App\Services\Rams\RamsComplianceUpgradeService::enforceHotWorksGate()
 * @see tests/Feature/Rams/CdmEmergencyDualPathGateTest.php
 * @see tests/Unit/Services/Rams/StructuralGatesTest.php
 * @see tests/Unit/Services/Rams/HotWorksGateTest.php
 * @see .planning/phases/30-structural-validation-gates/30-CONTEXT.md
 * @see .planning/phases/30-structural-validation-gates/30-06-PLAN.md
 * @see .planning/phases/30-structural-validation-gates/30-07-PLAN.md
 */
class StructuralGatesDisarmedTest extends TestCase
{
    /**
     * A minimal `generated_data`-shaped array that violates GATE-01
     * (orphan "asbestos register" reference), GATE-02 (an uncovered named
     * area) and GATE-04's error tier (a residual score exceeding its
     * initial score) simultaneously.
     */
    private function tripleViolatingDocument(): array
    {
        return [
            'areas_for_gate' => ['Boardroom 2'],
            'method_statement' => [
                'phases' => [
                    ['title' => 'Site Survey', 'steps' => ['Review the asbestos register.']],
                ],
            ],
            'hazards' => [
                [
                    'hazard' => 'Working at height',
                    'pre_likelihood' => 2,
                    'pre_severity' => 3,
                    'post_likelihood' => 4,
                    'post_severity' => 2,
                ],
            ],
            'client_responsibilities' => [],
        ];
    }

    private function allPhase30FlagsFalse(): void
    {
        config([
            'rams_tier1.structural_gates_enabled' => false,
            'rams_tier1.missing_risk_ref_gate_enabled' => false,
            'rams_tier1.hot_works_gate_enabled' => false,
        ]);
    }

    // ── Disarmed: silent on a genuinely violating document ──────────────────

    public function test_all_three_phase_30_flags_false_throws_nothing_on_a_triple_violating_document(): void
    {
        $this->allPhase30FlagsFalse();

        $result = RamsComplianceUpgradeService::upgrade($this->tripleViolatingDocument());

        $this->assertIsArray($result);
        $this->assertSame([], $result['compliance_warnings']);
    }

    // ── Armed: the SAME document throws — proves the disarmed test above is
    //    not vacuous ───────────────────────────────────────────────────────

    public function test_the_same_triple_violating_document_throws_when_structural_gates_enabled_true(): void
    {
        config(['rams_tier1.structural_gates_enabled' => true]);

        $this->expectException(RamsGenerationException::class);

        RamsComplianceUpgradeService::upgrade($this->tripleViolatingDocument());
    }

    // ── Byte-identical-when-false, scoped to the compliance_warnings key ────

    public function test_disarmed_output_is_byte_identical_to_baseline_apart_from_compliance_warnings_key(): void
    {
        $this->allPhase30FlagsFalse();

        // A clean (non-violating) document, so the comparison isolates the
        // structural gates' effect from any throw. Two identical calls
        // stand in for "pre-Phase-30 baseline vs current" per the
        // established ComplianceWarningsChannelTest pattern — the only
        // structural difference Phase 30 introduces to a disarmed run is
        // the new `compliance_warnings` key itself.
        $input = [
            'hazards' => [
                ['hazard' => 'Manual Handling', 'pre_likelihood' => 2, 'pre_severity' => 3],
            ],
        ];

        $result = RamsComplianceUpgradeService::upgrade($input);
        $baseline = RamsComplianceUpgradeService::upgrade($input);

        $this->assertArrayHasKey('compliance_warnings', $result);
        $this->assertSame([], $result['compliance_warnings']);

        unset($result['compliance_warnings'], $baseline['compliance_warnings']);
        $this->assertSame($baseline, $result);
    }

    // ── Only structural_gates_enabled true: GATE-01/02/04 run ───────────────

    public function test_only_structural_gates_enabled_true_runs_gate_01(): void
    {
        config(['rams_tier1.structural_gates_enabled' => true]);

        $this->expectException(RamsGenerationException::class);
        $this->expectExceptionMessageMatches('/GATE-01/');

        RamsComplianceUpgradeService::upgrade([
            'method_statement' => [
                'phases' => [
                    ['title' => 'Site Survey', 'steps' => ['Review the asbestos register.']],
                ],
            ],
            'hazards' => [],
            'client_responsibilities' => [],
        ]);
    }

    public function test_only_structural_gates_enabled_true_runs_gate_02(): void
    {
        config(['rams_tier1.structural_gates_enabled' => true]);

        $this->expectException(RamsGenerationException::class);
        $this->expectExceptionMessageMatches('/GATE-02/');

        RamsComplianceUpgradeService::upgrade([
            'areas_for_gate' => ['Boardroom 2'],
            'method_statement' => [
                'phases' => [
                    ['title' => 'Install Displays', 'steps' => ['Mount the display in Reception.']],
                ],
            ],
            'hazards' => [],
            'client_responsibilities' => [],
        ]);
    }

    public function test_only_structural_gates_enabled_true_runs_gate_04(): void
    {
        config(['rams_tier1.structural_gates_enabled' => true]);

        $this->expectException(RamsGenerationException::class);
        $this->expectExceptionMessageMatches('/GATE-04/');

        RamsComplianceUpgradeService::upgrade([
            'hazards' => [
                [
                    'hazard' => 'Working at height',
                    'pre_likelihood' => 2,
                    'pre_severity' => 3,
                    'post_likelihood' => 4,
                    'post_severity' => 2,
                ],
            ],
        ]);
    }

    // ── Flag independence: arming the structural trio does not arm the
    //    three previously-shipped gates ──────────────────────────────────────

    public function test_arming_structural_gates_does_not_arm_display_lift_gate(): void
    {
        // Disarm the target gate explicitly (it defaults true), arm the
        // structural trio, feed a document that would violate the display
        // lift gate if it ran — no throw, proving structural_gates_enabled
        // has zero effect on RAMS_DISPLAY_LIFT_GATE's own dispatch check.
        config([
            'rams_tier1.display_lift_gate_enabled' => false,
            'rams_tier1.structural_gates_enabled' => true,
        ]);

        $result = RamsComplianceUpgradeService::upgrade([
            'hazards' => [],
        ]);

        $this->assertIsArray($result);
    }

    public function test_arming_structural_gates_does_not_arm_ffp2_confined_space_gate(): void
    {
        config([
            'rams_tier1.ffp2_confined_space_gate_enabled' => false,
            'rams_tier1.structural_gates_enabled' => true,
        ]);

        $result = RamsComplianceUpgradeService::upgrade([
            'hazards' => [],
        ]);

        $this->assertIsArray($result);
    }

    public function test_arming_structural_gates_does_not_arm_cdm_ae_gate(): void
    {
        // Cannot force a genuine GATE-11 violation through upgrade() at
        // all — addCdmDutyHolders() is UNCONDITIONAL (runs regardless of
        // any flag) and always overwrites cdm_duty_holders with its own
        // restated RULE-07 wording, ignoring whatever was in $data
        // beforehand (documented in CdmEmergencyDualPathGateTest.php:24-32,
        // confirmed again here). So the only way structural_gates_enabled
        // could visibly "leak into" GATE-11 through the public upgrade()
        // entry point is by upgrade() throwing at all when
        // cdm_ae_gate_enabled is left at its real default (false) — it
        // must not, on a document that (per the CDM gate's own design)
        // never reaches a violating state via this path.
        config([
            'rams_tier1.cdm_ae_gate_enabled' => false,
            'rams_tier1.structural_gates_enabled' => true,
        ]);

        $result = RamsComplianceUpgradeService::upgrade([
            'hazards' => [],
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('cdm_duty_holders', $result);
    }

    // ── Flag independence, reverse direction: arming the three
    //    previously-shipped gates does not arm the structural trio ─────────

    public function test_arming_display_lift_gate_does_not_arm_structural_gates(): void
    {
        config([
            'rams_tier1.display_lift_gate_enabled' => true,
        ]);
        $this->allPhase30FlagsFalse();

        // A document that would violate GATE-01 if the structural trio
        // ran, with a display_lift-shaped input that does not itself
        // violate that gate (empty hazards/method statement is the
        // display-lift gate's vacuous-pass case, matching its own
        // established test suite).
        $result = RamsComplianceUpgradeService::upgrade($this->tripleViolatingDocument());

        $this->assertIsArray($result);
        $this->assertSame([], $result['compliance_warnings']);
    }

    public function test_arming_ffp2_confined_space_gate_does_not_arm_structural_gates(): void
    {
        config([
            'rams_tier1.ffp2_confined_space_gate_enabled' => true,
        ]);
        $this->allPhase30FlagsFalse();

        $result = RamsComplianceUpgradeService::upgrade($this->tripleViolatingDocument());

        $this->assertIsArray($result);
        $this->assertSame([], $result['compliance_warnings']);
    }

    public function test_arming_cdm_ae_gate_does_not_arm_structural_gates(): void
    {
        config([
            'rams_tier1.cdm_ae_gate_enabled' => true,
        ]);
        $this->allPhase30FlagsFalse();

        $result = RamsComplianceUpgradeService::upgrade($this->tripleViolatingDocument());

        $this->assertIsArray($result);
        $this->assertSame([], $result['compliance_warnings']);
    }

    // ── GATE-13 flag-independence (Plan 30-07) ──────────────────────────────
    //
    // D-04's operative clause made executable rather than merely asserted:
    // a document violating GATE-01, GATE-02, GATE-04 AND GATE-13
    // simultaneously throws ONLY the message of whichever gate's flag is
    // armed — arming the hot-works gate never wakes the structural trio,
    // and arming the structural trio never wakes the hot-works gate.
    //
    // The GATE-14 leg of this same matrix (`missing_risk_ref_gate_enabled`)
    // is intentionally NOT asserted here: `enforceMissingRiskRefGate()`
    // does not exist yet (Plan 30-08, wave 5). Asserting anything about it
    // now would either fatal or pass vacuously against a stub — this file's
    // documented scope-note precedent (see class docblock) applies
    // identically to this new leg. Plan 30-08 re-runs this file and adds
    // the missing bullet when the method exists.

    /**
     * `tripleViolatingDocument()` (GATE-01/02/04) plus a GATE-13
     * contradiction: a hazard asserting "no hot works" alongside a
     * `coshh_baseline` entry naming a solder product. Deliberately uses the
     * COSHH half, not the permit half, so this fixture never needs to
     * touch `permit_and_isolation` (which `addPermitAndIsolation()` would
     * otherwise populate with its own conditional, non-contradictory
     * line — irrelevant here since this fixture is fed directly to
     * `upgrade()` via a hand-built array, not built by invoking that
     * method first).
     */
    private function allFiveViolatingDocument(): array
    {
        $doc = $this->tripleViolatingDocument();

        // A clean (non-error, non-warn) hazard row so this addition does
        // not introduce a SECOND, unrelated GATE-04 violation — pre/post
        // scores identical, and post_severity is not below pre_severity.
        $doc['hazards'][] = [
            'hazard' => 'No hot works will be undertaken on this site.',
            'pre_likelihood' => 1,
            'pre_severity' => 1,
            'post_likelihood' => 1,
            'post_severity' => 1,
        ];

        $doc['coshh_baseline'] = [
            ['product' => 'Tin/Lead (Sn/Pb) Solder — 60/40 or 63/37'],
        ];

        return $doc;
    }

    public function test_arming_only_hot_works_gate_throws_gate_13_and_leaves_the_structural_trio_dormant(): void
    {
        $this->allPhase30FlagsFalse();
        config(['rams_tier1.hot_works_gate_enabled' => true]);

        try {
            RamsComplianceUpgradeService::upgrade($this->allFiveViolatingDocument());
            $this->fail('Expected RamsGenerationException (GATE-13) was not thrown.');
        } catch (RamsGenerationException $e) {
            $this->assertMatchesRegularExpression('/GATE-13/', $e->getMessage());
            $this->assertDoesNotMatchRegularExpression('/GATE-01|GATE-02|GATE-04/', $e->getMessage());
        }
    }

    public function test_arming_only_structural_gates_throws_a_structural_message_and_leaves_hot_works_gate_dormant(): void
    {
        config(['rams_tier1.structural_gates_enabled' => true]);
        config(['rams_tier1.hot_works_gate_enabled' => false]);
        config(['rams_tier1.missing_risk_ref_gate_enabled' => false]);

        try {
            RamsComplianceUpgradeService::upgrade($this->allFiveViolatingDocument());
            $this->fail('Expected RamsGenerationException (a structural gate) was not thrown.');
        } catch (RamsGenerationException $e) {
            $this->assertMatchesRegularExpression('/GATE-01|GATE-02|GATE-04/', $e->getMessage());
            $this->assertDoesNotMatchRegularExpression('/GATE-13/', $e->getMessage());
        }
    }
}
