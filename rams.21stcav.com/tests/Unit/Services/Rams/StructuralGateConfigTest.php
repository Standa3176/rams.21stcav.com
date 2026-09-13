<?php

namespace Tests\Unit\Services\Rams;

use App\Services\Rams\HazardIncludeWhenResolver;
use Tests\TestCase;

/**
 * Phase 30 Plan 01 (D-03/D-04/D-06/D-07) — proves the three new Phase 30
 * kill-switch flags default false and read three distinct, new env vars,
 * and that the two Phase 30 config vocabulary tables
 * (`structural_gate_triggers` for GATE-01, `missing_risk_implications` for
 * GATE-14) are non-empty, uniform-key rows whose every `signal` value
 * resolves against {@see HazardIncludeWhenResolver}'s existing TIER2/TIER3
 * const maps — the single shared vocabulary D-07 requires, so no orphan
 * signal is ever introduced by this config.
 *
 * ── Non-vacuity proof (development-time only, not a committed assertion) ──
 *
 *   1. Before config/rams_tier1.php carried the three new blocks, every
 *      test in this file failed (config() returned null, not false, and
 *      the raw-source scan found zero occurrences of the three new env
 *      var names).
 *   2. After adding the three flag blocks and the two data tables, all
 *      tests in this file passed.
 *
 * @see config/rams_tier1.php
 * @see .planning/phases/30-structural-validation-gates/30-01-PLAN.md
 */
class StructuralGateConfigTest extends TestCase
{
    public function test_structural_gates_enabled_defaults_false(): void
    {
        $this->assertFalse(config('rams_tier1.structural_gates_enabled'));
    }

    public function test_missing_risk_ref_gate_enabled_defaults_false(): void
    {
        $this->assertFalse(config('rams_tier1.missing_risk_ref_gate_enabled'));
    }

    public function test_hot_works_gate_enabled_defaults_false(): void
    {
        $this->assertFalse(config('rams_tier1.hot_works_gate_enabled'));
    }

    public function test_three_flags_read_three_distinct_new_env_vars(): void
    {
        $source = file_get_contents(config_path('rams_tier1.php'));

        $newNames = [
            'RAMS_STRUCTURAL_GATES',
            'RAMS_MISSING_RISK_REF_GATE',
            'RAMS_HOT_WORKS_GATE',
        ];

        $bannedNames = [
            'RAMS_DISPLAY_LIFT_GATE',
            'RAMS_PPE_CEILING_ELECTRICAL_GATE',
            'RAMS_CDM_AE_GATE',
        ];

        foreach ($newNames as $name) {
            // Exactly one env() CALL SITE per name (the terminal flag line) —
            // the name may legitimately also appear in prose inside the
            // sibling flag blocks' "never reuses ..." independence comments,
            // so we scan for the call site specifically, not raw substring
            // count across the whole file.
            $this->assertSame(
                1,
                substr_count($source, "env('{$name}'"),
                "Expected exactly one env('{$name}', ...) call site in config/rams_tier1.php",
            );
        }

        // Each new name must be genuinely distinct from the other two and
        // from every previously-shipped gate flag.
        $this->assertSame($newNames, array_unique($newNames));
        foreach ($newNames as $name) {
            $this->assertNotContains($name, $bannedNames);
        }
    }

    public function test_structural_gate_triggers_is_non_empty_with_uniform_keys(): void
    {
        $triggers = config('rams_tier1.structural_gate_triggers');

        $this->assertIsArray($triggers);
        $this->assertNotEmpty($triggers);

        foreach ($triggers as $row) {
            $this->assertSame(['phrase', 'signal', 'label'], array_keys($row));
            $this->assertNotSame('', trim((string) $row['phrase']));
            $this->assertNotSame('', trim((string) $row['signal']));
            $this->assertNotSame('', trim((string) $row['label']));
        }
    }

    public function test_every_structural_gate_trigger_signal_resolves_against_shared_vocabulary(): void
    {
        $validSignals = $this->hazardIncludeWhenSignalKeys();

        foreach (config('rams_tier1.structural_gate_triggers') as $row) {
            $this->assertContains(
                $row['signal'],
                $validSignals,
                "structural_gate_triggers signal '{$row['signal']}' (phrase '{$row['phrase']}') is not a key "
                . 'in HazardIncludeWhenResolver\'s TIER2/TIER3 const maps (D-07 — one shared vocabulary, no '
                . 'orphan signals).',
            );
        }
    }

    public function test_missing_risk_implications_is_non_empty_with_uniform_keys(): void
    {
        $implications = config('rams_tier1.missing_risk_implications');

        $this->assertIsArray($implications);
        $this->assertNotEmpty($implications);

        foreach ($implications as $row) {
            $this->assertSame(['phrase', 'signal', 'label'], array_keys($row));
            $this->assertNotSame('', trim((string) $row['phrase']));
            $this->assertNotSame('', trim((string) $row['signal']));
            $this->assertNotSame('', trim((string) $row['label']));
        }
    }

    public function test_every_missing_risk_implication_signal_resolves_against_shared_vocabulary(): void
    {
        $validSignals = $this->hazardIncludeWhenSignalKeys();

        foreach (config('rams_tier1.missing_risk_implications') as $row) {
            $this->assertContains(
                $row['signal'],
                $validSignals,
                "missing_risk_implications signal '{$row['signal']}' (phrase '{$row['phrase']}') is not a key "
                . 'in HazardIncludeWhenResolver\'s TIER2/TIER3 const maps (D-07 — one shared vocabulary, no '
                . 'orphan signals).',
            );
        }
    }

    public function test_asbestos_signal_is_the_canonical_gate01_trigger(): void
    {
        // D-06 / PORTING-NOTES: the asbestos-orphan case is the canonical
        // GATE-01 example. Confirm at least one trigger row uses it.
        $signals = array_column(config('rams_tier1.structural_gate_triggers'), 'signal');

        $this->assertContains('asbestos', $signals);
    }

    /**
     * Read HazardIncludeWhenResolver's TIER2/TIER3 const maps via
     * reflection (works whether or not those consts are public — Task 2
     * of this plan may widen their visibility, but this test must not
     * depend on that having happened yet).
     */
    private function hazardIncludeWhenSignalKeys(): array
    {
        $ref = new \ReflectionClass(HazardIncludeWhenResolver::class);

        $mapConstants = [
            'TIER2_ACTIVITY_SIGNALS',
            'TIER2_KEYWORD_SIGNALS',
            'TIER3_KEYWORD_PRECHECK',
        ];

        $keys = [];

        foreach ($mapConstants as $constantName) {
            $constant = $ref->getReflectionConstant($constantName);
            $keys = array_merge($keys, array_keys($constant->getValue()));
        }

        return array_values(array_unique($keys));
    }
}
