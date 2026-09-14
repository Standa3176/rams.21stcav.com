<?php

namespace Tests\Feature\Rams;

use App\Exceptions\RamsGenerationException;
use App\Services\Rams\RamsComplianceUpgradeService;
use Tests\TestCase;

/**
 * Phase 30 Plan 09 — ROADMAP criterion 4, proven by fixture rather than a
 * live-only UAT step. 21CQ30960 (VW Blakelands) is the canonical target:
 * its professional review (RAMS 97, 2026-08-25) is what produced GATE-13
 * and GATE-14 in the first place. The apparent conflict between ROADMAP
 * criterion 4 ("regenerates clean") and 30-CONTEXT.md's Specifics ("the
 * document containing the defects") is not a conflict — these are the SAME
 * project at two points in time (see both fixtures' own `_notes`).
 *
 * Two fixtures:
 *   - tests/Fixtures/rams/21cq30960/record.json           (CLEAN, post-fix)
 *   - tests/Fixtures/rams/21cq30960-defects/record.json   (pre-fix, RAMS 97 shape)
 *
 * Both are fed directly to {@see RamsComplianceUpgradeService::upgrade()}
 * — this file does not build a `RamsDocument`/HTTP round trip; that is
 * `StructuralGatesSaveReviewGateTest`'s (Plan 30-03) job for GATE-01/02 and
 * out of scope for this file, which is about the REAL document shape, not
 * the HTTP surfacing.
 *
 * ── GATE-13 correction, recorded here not silently assumed (Rule 1) ──────
 *
 * `RamsComplianceUpgradeService::addPermitAndIsolation()` UNCONDITIONALLY
 * overwrites `$data['permit_and_isolation']` with its own fixed,
 * conditionally-worded rule set on every `upgrade()` call (":999-1013" —
 * "Hot works permit required IF soldering..."), regardless of what a
 * fixture supplies. This is the exact, already-documented D-02 /
 * `config/rams_tier1.php:216-231` limitation ("the permit half is equally
 * unready") — {@see \Tests\Unit\Services\Rams\HotWorksGateTest}'s own class
 * docblock independently confirms the permit half is "live on ALL SIX
 * sites" for this exact reason. Consequently the permit half of GATE-13 can
 * NEVER be demonstrated through the real `upgrade()` pipeline — only the
 * COSHH half can. The defects fixture's `_notes` records this in full; the
 * tests below assert what is actually true (COSHH-half firing), not what
 * an earlier draft of this plan's prose assumed.
 *
 * ── GATE-14 known gap, recorded not fixed (per 30-09 plan instruction) ────
 *
 * The canonical Step-4 defect omits BOTH "Working at Height" (RA01) and
 * "Manual Handling" (RA02). GATE-14 fires on RA01 (`mounting_above_reach`
 * signal) because the defects fixture's Step-4 phase text contains
 * "stepladder". It CANNOT fire on RA02: `config('rams_tier1.missing_risk_implications')`
 * (Plan 30-01) defines only `mounting_above_reach` and `ceiling_void_access`
 * — no `manual_handling` signal exists anywhere in
 * `StructuralGateVocabulary::SUPPORTED_SIGNALS` or
 * `HazardIncludeWhenResolver`'s const maps. This is Plan 30-08's own
 * recorded corpus-fidelity gap, carried forward for Phase 31/measurement —
 * `test_defects_fixture_gate_14_fires_on_ra01_but_not_ra02_known_gap()`
 * below proves both halves of that statement explicitly, so the gap stays
 * visible rather than silently passing.
 */
class StructuralGatesRealDocumentTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__ . '/../../Fixtures/rams';

    private function loadGeneratedData(string $fixture): array
    {
        $path = self::FIXTURE_DIR . '/' . $fixture . '/record.json';
        $this->assertFileExists($path, "Fixture record.json missing for '{$fixture}'.");

        $fx = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($fx, "Fixture '{$fixture}' record.json is not valid JSON.");

        $generatedData = $fx['rams']['generated_data'] ?? null;
        $this->assertIsArray($generatedData, "Fixture '{$fixture}' has no rams.generated_data.");

        return $generatedData;
    }

    private function armAllFivePhase30Flags(): void
    {
        config([
            'rams_tier1.structural_gates_enabled' => true,
            'rams_tier1.missing_risk_ref_gate_enabled' => true,
            'rams_tier1.hot_works_gate_enabled' => true,
        ]);
    }

    private function disarmAllPhase30Flags(): void
    {
        config([
            'rams_tier1.structural_gates_enabled' => false,
            'rams_tier1.missing_risk_ref_gate_enabled' => false,
            'rams_tier1.hot_works_gate_enabled' => false,
        ]);
    }

    // ── ROADMAP criterion 4 — the clean fixture passes all five armed gates ──

    public function test_clean_fixture_passes_all_five_armed_gates_with_zero_warnings(): void
    {
        $this->armAllFivePhase30Flags();

        $data = $this->loadGeneratedData('21cq30960');

        $result = RamsComplianceUpgradeService::upgrade($data);

        $this->assertSame(
            [],
            $result['compliance_warnings'],
            'CLEAN 21CQ30960 fixture produced compliance_warnings with every Phase 30 gate armed — '
                . 'ROADMAP criterion 4 requires a genuinely empty array, not merely an unpopulated one.',
        );
    }

    public function test_clean_fixture_non_vacuity_areas_and_client_responsibilities_are_populated(): void
    {
        // Guards against a future edit silently emptying the fixture's
        // non-vacuity inputs and the test above passing for the wrong
        // reason (an empty array trivially produces no gate findings).
        $data = $this->loadGeneratedData('21cq30960');

        $this->assertNotEmpty($data['areas_for_gate'] ?? [], 'areas_for_gate must be non-empty (GATE-02 non-vacuity).');
        $this->assertNotEmpty($data['hazards'] ?? [], 'hazards must be non-empty (GATE-01/GATE-04 non-vacuity).');
        $this->assertNotEmpty(
            $data['client_responsibilities_expanded'] ?? [],
            'client_responsibilities_expanded must be populated (GATE-01 D-08 non-vacuity).',
        );

        foreach ($data['hazards'] as $hazard) {
            $this->assertArrayHasKey('pre_likelihood', $hazard);
            $this->assertArrayHasKey('pre_severity', $hazard);
        }
    }

    public function test_clean_fixture_disarmed_still_passes_unchanged(): void
    {
        $this->disarmAllPhase30Flags();

        $data = $this->loadGeneratedData('21cq30960');

        $result = RamsComplianceUpgradeService::upgrade($data);

        $this->assertSame([], $result['compliance_warnings']);
    }

    // ── GATE-13 fires on the real document shape (COSHH half) ────────────

    public function test_defects_fixture_gate_13_fires_via_coshh_half_when_hot_works_gate_armed(): void
    {
        config(['rams_tier1.hot_works_gate_enabled' => true]);

        $data = $this->loadGeneratedData('21cq30960-defects');

        try {
            RamsComplianceUpgradeService::upgrade($data);
            $this->fail('Expected RamsGenerationException (GATE-13) was not thrown.');
        } catch (RamsGenerationException $e) {
            $this->assertMatchesRegularExpression('/GATE-13/', $e->getMessage());
            $this->assertMatchesRegularExpression('/Solder/i', $e->getMessage());
        }
    }

    public function test_defects_fixture_permit_half_cannot_fire_through_the_real_pipeline(): void
    {
        // Documents the Rule-1 correction in this file's class docblock:
        // addPermitAndIsolation() always overwrites permit_and_isolation
        // with its own conditionally-worded line before GATE-13 ever runs,
        // regardless of fixture input. Proven here by removing the COSHH
        // half so the ONLY remaining path to a throw would be the permit
        // half — and confirming upgrade() does NOT throw.
        config(['rams_tier1.hot_works_gate_enabled' => true]);

        $data = $this->loadGeneratedData('21cq30960-defects');
        unset($data['coshh_baseline']);

        $result = RamsComplianceUpgradeService::upgrade($data);

        $this->assertIsArray($result, 'Expected no throw once the COSHH half is removed — the permit '
            . 'half can never fire through the real upgrade() pipeline (addPermitAndIsolation() always '
            . 'overwrites permit_and_isolation with its own conditional wording).');
    }

    // ── GATE-14 fires on RA01, cannot fire on RA02 (known, recorded gap) ──

    public function test_defects_fixture_gate_14_fires_on_ra01_but_not_ra02_known_gap(): void
    {
        config(['rams_tier1.missing_risk_ref_gate_enabled' => true]);

        $data = $this->loadGeneratedData('21cq30960-defects');

        $result = RamsComplianceUpgradeService::upgrade($data);

        $warnings = $result['compliance_warnings'];
        $gate14 = array_values(array_filter($warnings, static fn ($w) => ($w['gate'] ?? null) === 'GATE-14'));

        $this->assertNotEmpty($gate14, 'Expected at least one GATE-14 warning for the Step 4 phase.');
        $this->assertSame(0, $gate14[0]['hazard_index'], 'GATE-14 should warn on hazard index 0 (RA01, Working at Height).');
        $this->assertStringContainsString('Working at height', $gate14[0]['hazard']);

        // The known gap: RA02 (Manual handling) is present in the register,
        // uncited by the same phase, but NO manual_handling signal exists in
        // rams_tier1.missing_risk_implications — so GATE-14 cannot and does
        // not warn on it. Exactly one GATE-14 warning is expected, not two.
        $this->assertCount(
            1,
            $gate14,
            'GATE-14 fired on more than one hazard — the known manual_handling coverage gap '
                . '(Plan 30-08 SUMMARY) may have been closed; if so, update this test and the '
                . 'SUMMARY together rather than leaving this assertion stale.',
        );
    }

    public function test_defects_fixture_disarmed_produces_no_warnings_and_does_not_throw(): void
    {
        $this->disarmAllPhase30Flags();

        $data = $this->loadGeneratedData('21cq30960-defects');

        $result = RamsComplianceUpgradeService::upgrade($data);

        $this->assertSame([], $result['compliance_warnings']);
    }

    // ── "all five flags false, BOTH fixtures pass silently" ───────────────

    public function test_both_fixtures_pass_silently_with_all_phase_30_flags_false(): void
    {
        $this->disarmAllPhase30Flags();

        $clean = RamsComplianceUpgradeService::upgrade($this->loadGeneratedData('21cq30960'));
        $defects = RamsComplianceUpgradeService::upgrade($this->loadGeneratedData('21cq30960-defects'));

        $this->assertSame([], $clean['compliance_warnings']);
        $this->assertSame([], $defects['compliance_warnings']);
    }
}
