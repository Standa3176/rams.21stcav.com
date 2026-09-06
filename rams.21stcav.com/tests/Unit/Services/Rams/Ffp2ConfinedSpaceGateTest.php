<?php

namespace Tests\Unit\Services\Rams;

use App\Exceptions\RamsGenerationException;
use App\Services\Rams\RamsComplianceUpgradeService;
use Tests\TestCase;

/**
 * Phase 28 Plan 06 (GATE-06/GATE-07) — throw/no-throw boundary proof for
 * RamsComplianceUpgradeService::enforceFfp2AndConfinedSpaceGate().
 *
 * Reflection is used to exercise the private static method directly with
 * hand-built `hazards`/`ppe`/`ppe_matrix` fixtures — mirrors the established
 * DisplayLiftGateTest pattern exactly. Unlike GATE-09's `forSize()`, which
 * is conformant-by-construction and requires an isolated-process alias mock
 * to force a violation through the real `upgrade()` entry point,
 * `ControlTextRuleViolations::detect()` is a plain deterministic classifier
 * with no such property — the wiring tests at the bottom of this file call
 * the real, unmocked `upgrade()` directly.
 *
 * ── Non-vacuity proof (development-time only, not a committed assertion) ──
 *
 * Procedure followed during development of this test file, mirroring
 * DisplayLiftGateTest's documented "break-the-fix-and-watch-the-test-fail"
 * procedure:
 *
 *   1. Temporarily edited enforceFfp2AndConfinedSpaceGate() in
 *      app/Services/Rams/RamsComplianceUpgradeService.php so all four
 *      guarding conditions were neutralised at once: the hazard-name `if
 *      ($nameViolation !== null)` and both PPE/PPE-matrix `if (stripos(...)
 *      !== false)` guards were changed to `if (false && ...)`, and
 *      `$controlViolations = ControlTextRuleViolations::detectAll($controls);`
 *      was replaced with `$controlViolations = [];` (simulating every check
 *      being silently disabled/broken simultaneously).
 *   2. Re-ran `php artisan test --filter=Ffp2ConfinedSpaceGateTest`.
 *   3. Result: exactly the 8 throwing tests that exercise a stubbed
 *      condition (test_hazard_control_ffp2_throws,
 *      test_hazard_control_confined_space_throws,
 *      test_hazard_name_confined_space_throws,
 *      test_hazard_name_confined_spaces_plural_throws,
 *      test_hazard_name_confined_space_entry_throws,
 *      test_ppe_array_ffp2_throws, test_ppe_matrix_ffp2_throws,
 *      test_upgrade_throws_via_public_entry_point_when_flag_enabled) FAILED
 *      — each expected RamsGenerationException but none was thrown; the
 *      other 5 non-throwing/config tests still passed unaffected — proving
 *      the 8 throwing tests are not vacuously passing.
 *   4. Restored the real conditions from a pre-stub file backup (confirmed
 *      `git diff` empty afterward — no residual change).
 *   5. Re-ran the filter again: all 13 tests passed, confirming the restore.
 *
 * @see app/Services/Rams/RamsComplianceUpgradeService.php
 * @see app/Services/Rams/ControlTextRuleViolations.php
 * @see .planning/phases/28-ppe-ceiling-electrical-boundary-house-rules/28-06-PLAN.md
 */
class Ffp2ConfinedSpaceGateTest extends TestCase
{
    private function invokePrivateStatic(string $method, array $args = []): mixed
    {
        $m = new \ReflectionMethod(RamsComplianceUpgradeService::class, $method);
        $m->setAccessible(true);

        return $m->invoke(null, ...$args);
    }

    private function dataWithHazard(string $hazardName, array $controls): array
    {
        return [
            'hazards' => [
                ['hazard' => $hazardName, 'controls' => $controls],
            ],
        ];
    }

    // ── Hazard control-line violations ───────────────────────────────────────

    public function test_hazard_control_ffp2_throws(): void
    {
        $this->expectException(RamsGenerationException::class);

        $this->invokePrivateStatic('enforceFfp2AndConfinedSpaceGate', [
            $this->dataWithHazard('X', ['Dust mask (FFP2) worn.']),
        ]);
    }

    public function test_hazard_control_confined_space_throws(): void
    {
        $this->expectException(RamsGenerationException::class);

        $this->invokePrivateStatic('enforceFfp2AndConfinedSpaceGate', [
            $this->dataWithHazard('X', ['This is a confined space.']),
        ]);
    }

    /** Load-bearing non-vacuity case: the seeder's own corrected sentence must never be flagged. */
    public function test_seeder_negating_sentence_does_not_throw(): void
    {
        $result = $this->invokePrivateStatic('enforceFfp2AndConfinedSpaceGate', [
            $this->dataWithHazard('X', [
                'Confirm ventilation and safe access before entering ceiling voids, comms rooms or '
                . 'enclosures. These are not classified as confined spaces, but access is restricted '
                . 'and is treated as a controlled activity.',
            ]),
        ]);

        $this->assertSame('X', $result['hazards'][0]['hazard']);
    }

    // ── Hazard NAME violations (Revision 1, checker Blocker 1) ───────────────
    //
    // The name check is unconditional per hazard — none of these fold via
    // LegacyHazardNameFoldMap (which only maps the exact plural 'confined
    // spaces') and the control line in each case is deliberately clean, so
    // a throw here can only be explained by the NAME check firing.

    public function test_hazard_name_confined_space_throws(): void
    {
        $this->expectException(RamsGenerationException::class);

        $this->invokePrivateStatic('enforceFfp2AndConfinedSpaceGate', [
            $this->dataWithHazard('Confined Space', ['Ventilation confirmed before entry.']),
        ]);
    }

    public function test_hazard_name_confined_spaces_plural_throws(): void
    {
        $this->expectException(RamsGenerationException::class);

        $this->invokePrivateStatic('enforceFfp2AndConfinedSpaceGate', [
            $this->dataWithHazard('Confined Spaces', ['Ventilation confirmed before entry.']),
        ]);
    }

    public function test_hazard_name_confined_space_entry_throws(): void
    {
        $this->expectException(RamsGenerationException::class);

        $this->invokePrivateStatic('enforceFfp2AndConfinedSpaceGate', [
            $this->dataWithHazard('Confined Space Entry', ['Ventilation confirmed before entry.']),
        ]);
    }

    /** Load-bearing non-vacuity case for the name check: the phase's own canonical hazard, clean on both surfaces. */
    public function test_canonical_hazard_name_and_clean_controls_does_not_throw(): void
    {
        $result = $this->invokePrivateStatic('enforceFfp2AndConfinedSpaceGate', [
            $this->dataWithHazard('Restricted access and ceiling void working', [
                'Confirm ventilation and safe access before entering ceiling voids, comms rooms or '
                . 'enclosures. These are not classified as confined spaces, but access is restricted '
                . 'and is treated as a controlled activity.',
            ]),
        ]);

        $this->assertSame(
            'Restricted access and ceiling void working',
            $result['hazards'][0]['hazard'],
        );
    }

    // ── PPE / PPE-matrix surfaces ─────────────────────────────────────────────

    public function test_ppe_array_ffp2_throws(): void
    {
        $this->expectException(RamsGenerationException::class);

        $this->invokePrivateStatic('enforceFfp2AndConfinedSpaceGate', [
            ['ppe' => ['Dust Mask (FFP2)']],
        ]);
    }

    public function test_ppe_matrix_ffp2_throws(): void
    {
        $this->expectException(RamsGenerationException::class);

        $this->invokePrivateStatic('enforceFfp2AndConfinedSpaceGate', [
            ['ppe_matrix' => [['task' => 'X', 'ppe' => ['Dust mask (FFP2)']]]],
        ]);
    }

    // ── Clean input ────────────────────────────────────────────────────────────

    public function test_clean_input_returns_unchanged_no_throw(): void
    {
        $data = ['hazards' => [], 'ppe' => ['Hi-Vis Vest'], 'ppe_matrix' => []];

        $result = $this->invokePrivateStatic('enforceFfp2AndConfinedSpaceGate', [$data]);

        $this->assertSame($data, $result);
    }

    // ── Config default ────────────────────────────────────────────────────────

    public function test_ffp2_confined_space_gate_enabled_defaults_true_when_env_unset(): void
    {
        $this->assertTrue(config('rams_tier1.ffp2_confined_space_gate_enabled'));
    }

    // ── upgrade() wiring + config gate (public entry point) ──────────────────
    //
    // Unlike GATE-09's forSize()/violatesPolicy() pair, ControlTextRuleViolations
    // ::detect() is a plain deterministic classifier with no "conformant by
    // construction" property — a violating hazards/ppe/ppe_matrix array passed
    // straight into the real, unmocked upgrade() survives every step that
    // runs BEFORE this gate's call site (fillMissingHazardControls() only
    // fills EMPTY controls arrays; addProjectSpecificRisks() is a no-op while
    // rams_tier1.hazard_tiering_enabled defaults true), so no Mockery alias
    // mock is needed here.

    public function test_upgrade_throws_via_public_entry_point_when_flag_enabled(): void
    {
        config(['rams_tier1.ffp2_confined_space_gate_enabled' => true]);

        $this->expectException(RamsGenerationException::class);

        RamsComplianceUpgradeService::upgrade(
            $this->dataWithHazard('X', ['Dust mask (FFP2) worn.']),
        );
    }

    public function test_upgrade_never_calls_the_gate_when_flag_disabled(): void
    {
        config(['rams_tier1.ffp2_confined_space_gate_enabled' => false]);

        $result = RamsComplianceUpgradeService::upgrade(
            $this->dataWithHazard('X', ['Dust mask (FFP2) worn.']),
        );

        $this->assertIsArray($result);
        // The violating control line survives untouched — proves the gate
        // never ran, not merely that it happened not to fire.
        $hazards = $result['hazards'] ?? [];
        $this->assertSame('Dust mask (FFP2) worn.', $hazards[0]['controls'][0] ?? null);
    }
}
