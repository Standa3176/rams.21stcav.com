<?php

namespace Tests\Unit\Services\Rams;

use App\Exceptions\RamsGenerationException;
use App\Services\Rams\RamsComplianceUpgradeService;
use Tests\TestCase;

/**
 * Phase 30 Plan 03 (GATE-01/GATE-02) — throw/no-throw boundary proof for
 * RamsComplianceUpgradeService::enforceOrphanControlGate()/
 * enforceAreaCoverageGate(), plus the disarmed-by-default
 * RAMS_STRUCTURAL_GATES config-flag wiring through the real upgrade() entry
 * point.
 *
 * Reflection is used to exercise the private static methods directly,
 * mirroring CdmEmergencyGateTest's established pattern exactly.
 *
 * ── Non-vacuity proof (development-time only, not a committed assertion) ──
 *
 * Procedure followed during development of this test file:
 *
 *   1. Temporarily edited enforceOrphanControlGate() so its
 *      `if ($hasHazard && $hasClientReq) { continue; }` guard was changed to
 *      `if (true) { continue; }` (simulating the gate never finding a
 *      violation), and enforceAreaCoverageGate() so its `if (! $covered)`
 *      guard was changed to `if (false && ! $covered)` (simulating the area
 *      check always passing).
 *   2. Re-ran `php artisan test --filter=StructuralGatesTest`.
 *   3. Result: every throwing test
 *      (test_enforceOrphanControlGate_throws_on_canonical_asbestos_orphan,
 *      test_enforceOrphanControlGate_throws_when_hazard_present_but_client_responsibility_missing,
 *      test_enforceOrphanControlGate_throws_when_client_responsibility_present_but_hazard_missing,
 *      test_enforceAreaCoverageGate_throws_on_uncovered_area) FAILED — each
 *      expected RamsGenerationException but none was thrown; the
 *      non-throwing/clean-path/vacuous/config tests still passed
 *      unaffected — proving the throwing tests are not vacuously passing.
 *   4. Restored the real conditions from the pre-stub source (confirmed
 *      `git diff` empty afterward — no residual change).
 *   5. Re-ran the filter again: all tests passed, confirming the restore.
 *
 * @see app/Services/Rams/RamsComplianceUpgradeService.php
 * @see app/Services/Rams/StructuralGateVocabulary.php
 * @see .planning/phases/30-structural-validation-gates/30-03-PLAN.md
 * @see .planning/reference/21cav-rams-skill/PORTING-NOTES.md
 */
class StructuralGatesTest extends TestCase
{
    private function invokePrivateStatic(string $method, array $args = []): mixed
    {
        $m = new \ReflectionMethod(RamsComplianceUpgradeService::class, $method);
        $m->setAccessible(true);

        return $m->invoke(null, ...$args);
    }

    // ── GATE-01: enforceOrphanControlGate() ─────────────────────────────────

    public function test_enforceOrphanControlGate_throws_on_canonical_asbestos_orphan(): void
    {
        $this->expectException(RamsGenerationException::class);
        $this->expectExceptionMessageMatches('/asbestos register.*GATE-01/s');

        $this->invokePrivateStatic('enforceOrphanControlGate', [[
            'method_statement' => [
                'phases' => [
                    [
                        'title' => 'Site Survey',
                        'steps' => ['Review the asbestos register before any drilling or fixing works begin.'],
                    ],
                ],
            ],
            'hazards' => [],
            'client_responsibilities' => [],
        ]]);
    }

    public function test_enforceOrphanControlGate_throws_when_hazard_present_but_client_responsibility_missing(): void
    {
        $this->expectException(RamsGenerationException::class);
        $this->expectExceptionMessageMatches('/asbestos register.*no client-responsibility entry.*GATE-01/s');

        $this->invokePrivateStatic('enforceOrphanControlGate', [[
            'method_statement' => [
                'phases' => [
                    ['title' => 'Site Survey', 'steps' => ['Review the asbestos register.']],
                ],
            ],
            'hazards' => [
                ['hazard' => 'Asbestos-Containing Materials (ACM) disturbance', 'controls' => []],
            ],
            'client_responsibilities' => [],
        ]]);
    }

    public function test_enforceOrphanControlGate_throws_when_client_responsibility_present_but_hazard_missing(): void
    {
        $this->expectException(RamsGenerationException::class);
        $this->expectExceptionMessageMatches('/asbestos register.*no supporting hazard row.*GATE-01/s');

        $this->invokePrivateStatic('enforceOrphanControlGate', [[
            'method_statement' => [
                'phases' => [
                    ['title' => 'Site Survey', 'steps' => ['Review the asbestos register.']],
                ],
            ],
            'hazards' => [],
            'client_responsibilities' => ['Client to provide the asbestos register prior to works commencing.'],
        ]]);
    }

    public function test_enforceOrphanControlGate_does_not_throw_when_both_supports_present(): void
    {
        $data = [
            'method_statement' => [
                'phases' => [
                    ['title' => 'Site Survey', 'steps' => ['Review the asbestos register.']],
                ],
            ],
            'hazards' => [
                ['hazard' => 'Asbestos-Containing Materials (ACM) disturbance', 'controls' => []],
            ],
            'client_responsibilities' => ['Client to provide the asbestos register prior to works commencing.'],
        ];

        $result = $this->invokePrivateStatic('enforceOrphanControlGate', [$data]);

        $this->assertSame($data, $result);
    }

    public function test_enforceOrphanControlGate_does_not_throw_when_no_trigger_phrase_present(): void
    {
        $data = [
            'method_statement' => [
                'phases' => [
                    ['title' => 'Install Displays', 'steps' => ['Mount the display on the wall bracket.']],
                ],
            ],
            'hazards' => [],
            'client_responsibilities' => [],
        ];

        $result = $this->invokePrivateStatic('enforceOrphanControlGate', [$data]);

        $this->assertSame($data, $result);
    }

    public function test_enforceOrphanControlGate_scans_hazard_control_lines_too(): void
    {
        $this->expectException(RamsGenerationException::class);
        $this->expectExceptionMessageMatches('/asbestos survey.*GATE-01/s');

        $this->invokePrivateStatic('enforceOrphanControlGate', [[
            'method_statement' => ['phases' => []],
            'hazards' => [
                ['hazard' => 'Structural disturbance', 'controls' => ['Obtain an asbestos survey before drilling.']],
            ],
            'client_responsibilities' => [],
        ]]);
    }

    public function test_enforceOrphanControlGate_does_not_crash_on_absent_client_responsibility_keys(): void
    {
        $data = [
            'method_statement' => ['phases' => []],
            'hazards' => [],
        ];

        $result = $this->invokePrivateStatic('enforceOrphanControlGate', [$data]);

        $this->assertSame($data, $result);
    }

    // ── GATE-02: enforceAreaCoverageGate() ───────────────────────────────────

    public function test_enforceAreaCoverageGate_throws_on_uncovered_area(): void
    {
        $this->expectException(RamsGenerationException::class);
        $this->expectExceptionMessageMatches('/Boardroom 2.*GATE-02/s');

        $this->invokePrivateStatic('enforceAreaCoverageGate', [[
            'areas_for_gate' => ['Boardroom 2'],
            'method_statement' => [
                'phases' => [
                    ['title' => 'Install Displays', 'steps' => ['Mount the display in Reception.']],
                ],
            ],
        ]]);
    }

    public function test_enforceAreaCoverageGate_does_not_throw_when_every_area_covered(): void
    {
        $data = [
            'areas_for_gate' => ['Boardroom 2', 'Reception'],
            'method_statement' => [
                'phases' => [
                    ['title' => 'Install Displays', 'steps' => ['Mount the display in Boardroom 2 and Reception.']],
                ],
            ],
        ];

        $result = $this->invokePrivateStatic('enforceAreaCoverageGate', [$data]);

        $this->assertSame($data, $result);
    }

    public function test_enforceAreaCoverageGate_passes_vacuously_on_zero_areas(): void
    {
        $data = [
            'areas_for_gate' => [],
            'method_statement' => ['phases' => []],
        ];

        $result = $this->invokePrivateStatic('enforceAreaCoverageGate', [$data]);

        $this->assertSame($data, $result);
    }

    public function test_enforceAreaCoverageGate_passes_when_method_statement_absent_and_areas_empty(): void
    {
        $data = [];

        $result = $this->invokePrivateStatic('enforceAreaCoverageGate', [$data]);

        $this->assertSame($data, $result);
    }

    public function test_enforceAreaCoverageGate_matching_is_case_folded_and_trimmed(): void
    {
        $data = [
            'areas_for_gate' => ['  Boardroom 2  '],
            'method_statement' => [
                'phases' => [
                    ['title' => 'Install Displays', 'steps' => ['Mount the display in BOARDROOM 2.']],
                ],
            ],
        ];

        $result = $this->invokePrivateStatic('enforceAreaCoverageGate', [$data]);

        $this->assertSame($data, $result);
    }

    // ── Dispatch / flag wiring via the real upgrade() entry point ───────────

    public function test_upgrade_gate_inert_when_flag_disarmed(): void
    {
        config(['rams_tier1.structural_gates_enabled' => false]);

        // Feeds upgrade() a document that would violate BOTH GATE-01 and
        // GATE-02 if the flag were armed.
        $result = RamsComplianceUpgradeService::upgrade([
            'areas_for_gate' => ['Boardroom 2'],
            'method_statement' => [
                'phases' => [
                    ['title' => 'Site Survey', 'steps' => ['Review the asbestos register.']],
                ],
            ],
            'hazards' => [],
            'client_responsibilities' => [],
        ]);

        $this->assertIsArray($result);
    }

    public function test_upgrade_throws_via_public_entry_point_when_flag_enabled_and_orphan_control_present(): void
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

    public function test_upgrade_throws_via_public_entry_point_when_flag_enabled_and_area_uncovered(): void
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
}
