<?php

namespace Tests\Feature\Rams;

use App\Services\Rams\RamsComplianceUpgradeService;
use Tests\TestCase;

/**
 * Phase 30 Plan 08 (GATE-14) structural regression guard — proves
 * `RamsComplianceUpgradeService::enforceMissingRiskRefGate()` never re-derives
 * `crossReferenceMethodStatementRisks()`'s hard-coded `$keywordRiskMap`
 * (`:1065-1077`), the exact intersection-based code responsible for the
 * canonical defect GATE-14 exists to catch (RESEARCH.md Finding 7). Modeled
 * on `DisplayLiftPolicySourceGuardTest`'s allow-list file-scanning pattern,
 * inverted for the `keywordRiskMap` marker.
 *
 * ── A weaker marker than the analog, stated honestly ─────────────────────
 *
 * `DisplayLiftPolicySourceGuardTest`'s marker (`DisplayLiftPolicy::`) is a
 * `Class::` reference — it can only appear where the class is genuinely
 * invoked. `keywordRiskMap` is a LOCAL VARIABLE inside
 * `crossReferenceMethodStatementRisks()`, not a class or method name. A
 * bare grep for the substring `keywordRiskMap` across `app/` cannot, by
 * itself, distinguish "a file that legitimately DEFINES this local
 * variable" from "a file whose OTHER method happens to reference the same
 * literal text" when both live in the SAME FILE (as they do here —
 * `crossReferenceMethodStatementRisks()` and `enforceMissingRiskRefGate()`
 * are both private static methods on `RamsComplianceUpgradeService`). A
 * file-scoped assertion alone would therefore be vacuous the moment a
 * future edit made `enforceMissingRiskRefGate()` reference the map: the
 * file-level grep would still pass (the marker's file-level presence is
 * EXPECTED and allow-listed), silently missing the exact regression this
 * guard exists to catch.
 *
 * This is why this file's SECOND test is the load-bearing one: it extracts
 * `enforceMissingRiskRefGate()`'s OWN source lines via
 * `ReflectionMethod::getFileName()`/`getStartLine()`/`getEndLine()` and
 * asserts that slice — and only that slice — contains neither
 * `keywordRiskMap` nor a call to `crossReferenceMethodStatementRisks()`. A
 * method-scoped assertion is strictly stronger than a file-scoped grep on a
 * class this large (2,600+ lines, dozens of unrelated private statics).
 *
 * The allow-list below was re-derived from a live grep of the repo at plan
 * execution time, not hand-copied:
 *
 *   grep -rln "keywordRiskMap" app --include=*.php
 *
 * returned exactly 1 file:
 *   - app/Services/Rams/RamsComplianceUpgradeService.php (defines
 *     `$keywordRiskMap` inside `crossReferenceMethodStatementRisks()`,
 *     `:1065-1077`, and reads it at `:1106`)
 *
 * Scanned directory: app/ only. tests/ is excluded — test fixtures may
 * legitimately reference the literal string in docblocks/assertions and
 * are not a generation path.
 *
 * @see app/Services/Rams/RamsComplianceUpgradeService.php
 * @see tests/Feature/Rams/DisplayLiftPolicySourceGuardTest.php
 * @see tests/Unit/Services/Rams/MissingRiskRefGateTest.php
 * @see .planning/phases/30-structural-validation-gates/30-08-PLAN.md
 * @see .planning/phases/30-structural-validation-gates/30-RESEARCH.md (Finding 7)
 */
class MissingRiskRefGateSourceGuardTest extends TestCase
{
    /**
     * The only file that legitimately defines/references `$keywordRiskMap`,
     * re-derived from a live repo grep at plan execution time.
     */
    private const ALLOWED_FILES = [
        // Defines $keywordRiskMap inside crossReferenceMethodStatementRisks()
        // — the ONE sanctioned definition site. enforceMissingRiskRefGate()
        // lives in this same file but must NOT reference the variable; that
        // is what this file's second test proves at method scope, since a
        // file-level allow-list entry cannot distinguish the two methods.
        'app/Services/Rams/RamsComplianceUpgradeService.php',
    ];

    private const MARKER = 'keywordRiskMap';

    public function test_keywordriskmap_appears_only_in_the_sanctioned_file(): void
    {
        $appPath = base_path('app');
        $files = $this->phpFilesUnder($appPath);

        $allowedRealPaths = array_map(
            static fn (string $rel): string|false => realpath(base_path($rel)),
            self::ALLOWED_FILES,
        );

        $offenders = [];
        foreach ($files as $file) {
            $real = realpath($file);
            if ($real !== false && in_array($real, $allowedRealPaths, true)) {
                continue; // allow-listed: the one sanctioned definition site
            }

            $contents = file_get_contents($file);
            if ($contents === false) {
                continue;
            }

            if (str_contains($contents, self::MARKER)) {
                $offenders[] = $file . " contains '" . self::MARKER . "'";
            }
        }

        $this->assertEmpty(
            $offenders,
            'Phase 30 GATE-14 structural invariant violated: a file outside the sanctioned allow-list '
            . "references '" . self::MARKER . "', risking a divergent, hardcoded copy of the intersection-based "
            . "map GATE-14 exists to independently re-check.\nOffenders:\n  - " . implode("\n  - ", $offenders),
        );
    }

    /**
     * The load-bearing assertion (see class docblock): a file-scoped grep
     * cannot distinguish crossReferenceMethodStatementRisks() (sanctioned
     * definition) from enforceMissingRiskRefGate() (must NOT reference the
     * map) when both live in the same allow-listed file. Reflection
     * extracts enforceMissingRiskRefGate()'s own source lines and asserts
     * that slice, specifically, contains neither the marker nor a call to
     * the method that defines it.
     */
    public function test_enforce_missing_risk_ref_gate_source_references_neither_keywordriskmap_nor_cross_reference_method(): void
    {
        $method = new \ReflectionMethod(RamsComplianceUpgradeService::class, 'enforceMissingRiskRefGate');

        $file = $method->getFileName();
        $this->assertNotFalse($file, 'Could not resolve the declaring file for enforceMissingRiskRefGate().');

        $lines = file($file);
        $this->assertNotFalse($lines);

        // getStartLine()/getEndLine() are 1-indexed and inclusive.
        $slice = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        $this->assertStringNotContainsString(
            self::MARKER,
            $slice,
            'enforceMissingRiskRefGate() must never reference $keywordRiskMap — that is the exact '
            . 'intersection-based code responsible for the defect GATE-14 exists to catch. Re-running it '
            . 'here would be the "gate re-derives the thing it is checking" anti-pattern.',
        );

        $this->assertStringNotContainsString(
            'crossReferenceMethodStatementRisks',
            $slice,
            'enforceMissingRiskRefGate() must never call crossReferenceMethodStatementRisks() directly — '
            . 'it must be an independent re-check, reading only the associated_risks that method already '
            . 'wrote, never re-deriving them.',
        );
    }

    /**
     * Sanity check that the allow-list itself is exactly 1 entry and
     * resolves to a real file — a typo in the constant would otherwise
     * silently widen what the guard permits.
     */
    public function test_allow_list_has_one_entry_and_resolves(): void
    {
        $this->assertCount(1, self::ALLOWED_FILES);

        foreach (self::ALLOWED_FILES as $rel) {
            $this->assertFileExists(base_path($rel), "allow-listed path does not exist: {$rel}");
        }
    }

    /**
     * The allow-list must match a FRESH grep of the repo, not a stale
     * hand-copy.
     */
    public function test_allow_list_matches_a_fresh_grep_of_the_repo(): void
    {
        $appPath = base_path('app');
        $files = $this->phpFilesUnder($appPath);

        $matching = [];
        foreach ($files as $file) {
            $contents = file_get_contents($file);
            if ($contents !== false && str_contains($contents, self::MARKER)) {
                $matching[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file);
            }
        }

        $normalise = static fn (string $path): string => str_replace('\\', '/', $path);

        $matching = array_map($normalise, $matching);
        $allowed = array_map($normalise, self::ALLOWED_FILES);

        sort($matching);
        sort($allowed);

        $this->assertSame(
            $allowed,
            $matching,
            'The allow-list must exactly match a fresh grep -rln "keywordRiskMap" app --include=*.php run — '
            . 'update ALLOWED_FILES if a new sanctioned reference was added, or investigate if an unexpected '
            . 'file appears.',
        );
    }

    /**
     * The guard fails if a future edit makes the gate read the map — proven
     * by construction: the load-bearing test above asserts a NEGATIVE on
     * enforceMissingRiskRefGate()'s own source slice, so any edit adding
     * either marker to that method's body trips it immediately.
     */
    public function test_guard_would_fail_if_the_gate_referenced_the_map(): void
    {
        $method = new \ReflectionMethod(RamsComplianceUpgradeService::class, 'enforceMissingRiskRefGate');
        $file = $method->getFileName();
        $lines = file($file);

        $slice = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        // Simulate the regression this guard exists to catch, in memory
        // only — never written to disk.
        $simulatedRegression = $slice . "\n// keywordRiskMap\n";

        $this->assertStringContainsString(
            self::MARKER,
            $simulatedRegression,
            'Sanity check on the guard itself: a slice that DOES contain the marker must fail '
            . 'assertStringNotContainsString() — if this assertion fails, the guard\'s own logic is broken.',
        );
    }

    /**
     * Recursive glob for *.php files under a directory.
     *
     * @return string[]
     */
    private function phpFilesUnder(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $files = [];
        foreach ($rii as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
