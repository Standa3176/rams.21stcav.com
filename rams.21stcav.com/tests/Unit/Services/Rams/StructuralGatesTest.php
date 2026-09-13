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

    // ── GATE-04: enforceResidualScoreGate() ─────────────────────────────────

    public function test_enforceResidualScoreGate_throws_when_residual_score_exceeds_initial(): void
    {
        $this->expectException(RamsGenerationException::class);
        $this->expectExceptionMessageMatches('/Working at height.*RA01.*residual score of 8 against an initial score of 6.*GATE-04/s');

        $this->invokePrivateStatic('enforceResidualScoreGate', [[
            'hazards' => [
                [
                    'hazard' => 'Working at height',
                    'pre_likelihood' => 2,
                    'pre_severity' => 3,
                    'post_likelihood' => 4,
                    'post_severity' => 2,
                ],
            ],
        ]]);
    }

    public function test_enforceResidualScoreGate_error_names_row_by_index_not_by_id(): void
    {
        // Two rows: the first is clean, the second violates. The RA## label
        // must be "RA02" (row index 1, +1, zero-padded) — NOT derived from
        // any 'id' key on the hazard row (RamsComplianceUpgradeService.php
        // :1000-1006's documented 260817-r5e correction: row position, not
        // $h['id']).
        $this->expectException(RamsGenerationException::class);
        $this->expectExceptionMessageMatches('/RA02/');

        $this->invokePrivateStatic('enforceResidualScoreGate', [[
            'hazards' => [
                [
                    'id' => 'zzz-not-the-label-source',
                    'hazard' => 'Manual handling',
                    'pre_likelihood' => 3,
                    'pre_severity' => 3,
                    'post_likelihood' => 1,
                    'post_severity' => 1,
                ],
                [
                    'id' => 'aaa-also-not-the-label-source',
                    'hazard' => 'Electrical connection to mains supply',
                    'pre_likelihood' => 2,
                    'pre_severity' => 3,
                    'post_likelihood' => 4,
                    'post_severity' => 2,
                ],
            ],
        ]]);
    }

    public function test_enforceResidualScoreGate_warns_and_does_not_throw_when_residual_severity_below_initial(): void
    {
        $result = $this->invokePrivateStatic('enforceResidualScoreGate', [[
            'hazards' => [
                [
                    'hazard' => 'Working at height for display installation (up to 3m)',
                    'pre_likelihood' => 3,
                    'pre_severity' => 4,
                    'post_likelihood' => 1,
                    'post_severity' => 3,
                ],
            ],
        ]]);

        $this->assertCount(1, $result['compliance_warnings']);
        $this->assertSame('GATE-04', $result['compliance_warnings'][0]['gate']);
        $this->assertSame(0, $result['compliance_warnings'][0]['hazard_index']);
        $this->assertSame(
            'Working at height for display installation (up to 3m)',
            $result['compliance_warnings'][0]['hazard'],
        );
        $this->assertMatchesRegularExpression(
            '/residual severity 3 is lower than initial severity 4/',
            $result['compliance_warnings'][0]['message'],
        );
    }

    public function test_enforceResidualScoreGate_runs_the_committed_tilda_fixture_hazard_set(): void
    {
        // Non-vacuity fixture per RESEARCH Finding 4 — the committed golden
        // record's hazard set is REAL, intended, previously-issued output
        // (WorkingAtHeightResidualScoreTest asserts hazard 0's 1x3 residual
        // through the live DOCX path). Direct inspection of the fixture
        // (all three rows) shows EVERY row's post_severity is below its
        // pre_severity — 3<4, 2<3, 3<5 — so the gate correctly warns on all
        // three, not "exactly one" as an earlier reading of the fixture
        // assumed before this test was written against the real data. Zero
        // rows error: no row's residual score (post_l*post_s) exceeds its
        // initial score (pre_l*pre_s) — 3<=12, 4<=12, 3<=15.
        $fixture = json_decode(
            file_get_contents(base_path('tests/Fixtures/rams/tilda-21cq29531/record.json')),
            true,
        );
        $hazards = $fixture['rams']['generated_data']['hazards'];

        $result = $this->invokePrivateStatic('enforceResidualScoreGate', [[
            'hazards' => $hazards,
        ]]);

        $this->assertCount(3, $result['compliance_warnings']);
        $this->assertSame([0, 1, 2], array_column($result['compliance_warnings'], 'hazard_index'));
        $this->assertSame(
            'Working at height for display installation (up to 3m)',
            $result['compliance_warnings'][0]['hazard'],
        );
    }

    public function test_enforceResidualScoreGate_skips_row_missing_pre_scores_instead_of_defaulting(): void
    {
        // T-30-14: a row with NO pre_likelihood/pre_severity keys at all
        // must be skipped — never scored against the `?? 1` default, which
        // would manufacture a false "residual exceeds initial" violation
        // (post 2x2=4 > a phantom 1x1=1 default).
        $result = $this->invokePrivateStatic('enforceResidualScoreGate', [[
            'hazards' => [
                [
                    'hazard' => 'Incomplete row, no pre-scores at all',
                    'post_likelihood' => 2,
                    'post_severity' => 2,
                ],
            ],
        ]]);

        $this->assertSame([], $result['compliance_warnings']);
    }

    public function test_enforceResidualScoreGate_skips_row_missing_only_pre_severity(): void
    {
        $result = $this->invokePrivateStatic('enforceResidualScoreGate', [[
            'hazards' => [
                [
                    'hazard' => 'Incomplete row, pre_likelihood only',
                    'pre_likelihood' => 3,
                    'post_likelihood' => 5,
                    'post_severity' => 5,
                ],
            ],
        ]]);

        $this->assertSame([], $result['compliance_warnings']);
    }

    public function test_enforceResidualScoreGate_clean_hazard_set_returns_data_with_empty_warnings(): void
    {
        // Genuinely clean: post_score (3) < pre_score (9) AND
        // post_severity (3) is NOT below pre_severity (3) — neither tier
        // fires.
        $data = [
            'hazards' => [
                [
                    'hazard' => 'Manual handling',
                    'pre_likelihood' => 3,
                    'pre_severity' => 3,
                    'post_likelihood' => 1,
                    'post_severity' => 3,
                ],
            ],
        ];

        $result = $this->invokePrivateStatic('enforceResidualScoreGate', [$data]);

        $this->assertSame([], $result['compliance_warnings']);
    }

    public function test_enforceResidualScoreGate_no_hazards_key_returns_data_unchanged(): void
    {
        $data = ['compliance_warnings' => []];

        $result = $this->invokePrivateStatic('enforceResidualScoreGate', [$data]);

        $this->assertSame($data, $result);
    }

    public function test_enforceResidualScoreGate_a_preceding_warn_row_does_not_suppress_a_later_error(): void
    {
        // A warn-tier row (row 0) followed by an error-tier row (row 1):
        // the warn tier must not short-circuit or otherwise interfere with
        // the error tier's own scan of the remaining rows. Note:
        // RamsGenerationException (app/Exceptions/RamsGenerationException.php)
        // carries no payload, so the warnings collected during THIS
        // throwing call are necessarily discarded along with the rest of
        // the function's local state when it throws (a throw never
        // returns $data) — the plan's "collect every row's warnings before
        // throwing" instruction is an internal-ordering/intent decision
        // (do a full scan, do not stop early at the first error), not an
        // externally observable persistence guarantee. That guarantee is
        // proven instead by test_enforceResidualScoreGate_
        // warns_and_does_not_throw_when_residual_severity_below_initial()
        // and the Tilda fixture test above, both of which exercise the
        // warn tier on a CLEAN (non-throwing) hazard set where $data really
        // is returned.
        $this->expectException(RamsGenerationException::class);
        $this->expectExceptionMessageMatches('/Error-tier row.*RA02.*GATE-04/s');

        $this->invokePrivateStatic('enforceResidualScoreGate', [[
            'hazards' => [
                [
                    'hazard' => 'Warn-tier row',
                    'pre_likelihood' => 3,
                    'pre_severity' => 4,
                    'post_likelihood' => 1,
                    'post_severity' => 3,
                ],
                [
                    'hazard' => 'Error-tier row',
                    'pre_likelihood' => 2,
                    'pre_severity' => 3,
                    'post_likelihood' => 4,
                    'post_severity' => 2,
                ],
            ],
        ]]);
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

    public function test_upgrade_throws_via_public_entry_point_when_flag_enabled_and_residual_score_exceeds_initial(): void
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

    public function test_upgrade_gate_04_warns_but_does_not_throw_via_public_entry_point_when_flag_enabled(): void
    {
        config(['rams_tier1.structural_gates_enabled' => true]);

        $result = RamsComplianceUpgradeService::upgrade([
            'hazards' => [
                [
                    'hazard' => 'Working at height for display installation (up to 3m)',
                    'pre_likelihood' => 3,
                    'pre_severity' => 4,
                    'post_likelihood' => 1,
                    'post_severity' => 3,
                ],
            ],
        ]);

        $this->assertCount(1, $result['compliance_warnings']);
        $this->assertSame('GATE-04', $result['compliance_warnings'][0]['gate']);
    }
}
