<?php

namespace Tests\Unit\Services\Rams;

use App\Exceptions\RamsGenerationException;
use App\Services\Rams\RamsComplianceUpgradeService;
use Tests\TestCase;

/**
 * Phase 30 Plan 07 (GATE-13) — throw/no-throw boundary proof for
 * `RamsComplianceUpgradeService::enforceHotWorksGate()`, plus the
 * corpus-wide-false-positive regression proof T-30-16 exists specifically
 * to prevent.
 *
 * Reflection is used to exercise the private static methods directly,
 * mirroring `CdmEmergencyGateTest`'s established pattern exactly.
 *
 * ── Which of the six `upgrade()` call sites each half is live on
 *    (Pitfall 6, `CdmEmergencyDualPathGateTest.php:30-75`'s house
 *    convention) ──────────────────────────────────────────────────────────
 *
 * `RamsComplianceUpgradeService::upgrade()` has SIX call sites in
 * production code (grep-verified: `RamsBuilderService::runPipeline()` :313,
 * `RamsBuilderService::runFromReview()` :978, three `RamsController.php`
 * sites :621/:719/:875, and `RamsRefreshComplianceCommand.php` :185).
 *
 *   - The PERMIT half is live on ALL SIX sites: `addPermitAndIsolation()`
 *     runs unconditionally INSIDE `upgrade()` itself, before GATE-13's
 *     dispatch block. Every document reaching this gate already carries
 *     `permit_and_isolation.rules`.
 *   - The COSHH half is live on sites 3-6 only, DORMANT on sites 1-2:
 *     `Tier1RamsDefaultsService::injectDefaultsIntoRamsData()` (which sets
 *     `coshh_baseline` unconditionally) is wired into ONLY the two
 *     `RamsBuilderService` call sites, and runs AFTER `upgrade()` returns
 *     (RESEARCH.md Finding 1). So immediately after `upgrade()` alone
 *     returns, `coshh_baseline` is whatever the CALLER already put in
 *     `$data` before invoking `upgrade()` — absent on a fresh build,
 *     present on a re-run of already-upgraded `generated_data` (sites 3-6
 *     typically re-upgrade a document that already carries it from a prior
 *     pass). This file's unit tests below construct `coshh_baseline`
 *     directly in the input array to exercise that half in isolation,
 *     regardless of which site would populate it in production.
 *
 * ── Non-vacuity proof (development-time only, not a committed assertion) ──
 *
 * Procedure followed during development of this test file, mirroring
 * `CdmEmergencyGateTest`'s documented "break-the-fix-and-watch-the-test-
 * fail" procedure:
 *
 *   1. Temporarily changed `enforceHotWorksGate()`'s
 *      `if (! self::documentAssertsNoHotWorks($data))` guard to
 *      `if (true)` (simulating the assertion detector never firing).
 *   2. Re-ran `php artisan test --filter=HotWorksGateTest`.
 *   3. Result: exactly the throwing tests
 *      (test_throws_when_no_hot_works_assertion_is_paired_with_an_unconditional_permit_rule,
 *      test_throws_when_no_hot_works_assertion_is_paired_with_solder_in_coshh,
 *      test_throws_when_no_hot_works_assertion_is_paired_with_flux_in_coshh)
 *      FAILED — each expected `RamsGenerationException` but none was
 *      thrown; the non-throwing tests still passed unaffected — proving
 *      the throwing tests are not vacuously passing.
 *   4. Restored the real guard from the pre-stub source (confirmed
 *      `git diff` empty afterward — no residual change).
 *   5. Re-ran the filter again: all tests passed, confirming the restore.
 *
 * @see app/Services/Rams/RamsComplianceUpgradeService.php
 * @see app/Services/Rams/ControlTextRuleViolations.php
 * @see .planning/phases/30-structural-validation-gates/30-07-PLAN.md
 * @see .planning/phases/30-structural-validation-gates/30-RESEARCH.md (Finding 5, Pitfall 4)
 */
class HotWorksGateTest extends TestCase
{
    private function invokePrivateStatic(string $method, array $args = []): mixed
    {
        $m = new \ReflectionMethod(RamsComplianceUpgradeService::class, $method);
        $m->setAccessible(true);

        return $m->invoke(null, ...$args);
    }

    // ── Throws — the real three-way contradiction, each half in isolation ──

    public function test_throws_when_no_hot_works_assertion_is_paired_with_an_unconditional_permit_rule(): void
    {
        $this->expectException(RamsGenerationException::class);
        $this->expectExceptionMessageMatches('/GATE-13/');

        $this->invokePrivateStatic('enforceHotWorksGate', [[
            'hazards' => [
                ['hazard' => 'No hot works will be undertaken on this site.'],
            ],
            'permit_and_isolation' => [
                'rules' => [
                    'Hot works permit required for all soldering and heat-shrink operations on site.',
                ],
            ],
        ]]);
    }

    public function test_throws_when_no_hot_works_assertion_is_paired_with_solder_in_coshh(): void
    {
        $this->expectException(RamsGenerationException::class);
        $this->expectExceptionMessageMatches('/GATE-13/');
        $this->expectExceptionMessageMatches('/Solder/i');

        $this->invokePrivateStatic('enforceHotWorksGate', [[
            'hazards' => [
                ['hazard' => 'No hot works will be undertaken on this site.'],
            ],
            'coshh_baseline' => [
                ['product' => 'Tin/Lead (Sn/Pb) Solder — 60/40 or 63/37'],
            ],
        ]]);
    }

    public function test_throws_when_no_hot_works_assertion_is_paired_with_flux_in_coshh(): void
    {
        $this->expectException(RamsGenerationException::class);
        $this->expectExceptionMessageMatches('/GATE-13/');
        $this->expectExceptionMessageMatches('/Flux/i');

        $this->invokePrivateStatic('enforceHotWorksGate', [[
            'hazards' => [
                ['hazard' => 'No soldering will be undertaken on site.'],
            ],
            'coshh_baseline' => [
                ['product' => 'Rosin (Colophony) Flux — solder flux'],
            ],
        ]]);
    }

    // ── T-30-16 regression: the app's own conditional permit line never
    //    trips the gate, even when a genuine absence assertion is present ─

    public function test_does_not_throw_on_addPermitAndIsolations_own_conditional_permit_line(): void
    {
        // Built by invoking addPermitAndIsolation() directly (the real
        // production method), NOT a hand-authored fixture that merely
        // resembles it — this is the load-bearing proof Pitfall 4 names:
        // "the gate's own test fixture had to delete permit_and_isolation
        // to get a clean pass" would be the warning sign that the gate is
        // wrong.
        $data = $this->invokePrivateStatic('addPermitAndIsolation', [[]]);
        $data['hazards'] = [
            ['hazard' => 'No hot works will be undertaken on this site.'],
        ];

        $result = $this->invokePrivateStatic('enforceHotWorksGate', [$data]);

        $this->assertSame($data, $result);
    }

    // ── Does not throw — no absence assertion anywhere in the document ─────

    public function test_does_not_throw_when_no_absence_assertion_exists(): void
    {
        $data = [
            'hazards' => [
                ['hazard' => 'Manual handling'],
            ],
            'permit_and_isolation' => [
                'rules' => [
                    'Hot works permit required for all soldering and heat-shrink operations on site.',
                ],
            ],
            'coshh_baseline' => [
                ['product' => 'Tin/Lead (Sn/Pb) Solder — 60/40 or 63/37'],
            ],
        ];

        $result = $this->invokePrivateStatic('enforceHotWorksGate', [$data]);

        $this->assertSame($data, $result);
    }

    // ── Does not throw — absence assertion present, but no permit or COSHH
    //    evidence exists to contradict it ──────────────────────────────────

    public function test_does_not_throw_when_assertion_present_with_no_permit_or_coshh_evidence(): void
    {
        $data = [
            'hazards' => [
                ['hazard' => 'No hot works will be undertaken on this site.'],
            ],
        ];

        $result = $this->invokePrivateStatic('enforceHotWorksGate', [$data]);

        $this->assertSame($data, $result);
    }

    // ── Flag wiring, via the real upgrade() entry point ─────────────────────

    public function test_flag_disabled_gate_is_never_called_even_on_a_full_three_way_contradiction(): void
    {
        config(['rams_tier1.hot_works_gate_enabled' => false]);

        $result = RamsComplianceUpgradeService::upgrade([
            'hazards' => [
                ['hazard' => 'No hot works will be undertaken on this site.'],
            ],
            'coshh_baseline' => [
                ['product' => 'Tin/Lead (Sn/Pb) Solder — 60/40 or 63/37'],
            ],
        ]);

        $this->assertIsArray($result);
    }

    public function test_flag_enabled_throws_via_the_real_upgrade_entry_point(): void
    {
        config(['rams_tier1.hot_works_gate_enabled' => true]);

        $this->expectException(RamsGenerationException::class);
        $this->expectExceptionMessageMatches('/GATE-13/');

        RamsComplianceUpgradeService::upgrade([
            'hazards' => [
                ['hazard' => 'No hot works will be undertaken on this site.'],
            ],
            'coshh_baseline' => [
                ['product' => 'Tin/Lead (Sn/Pb) Solder — 60/40 or 63/37'],
            ],
        ]);
    }
}
