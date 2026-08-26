<?php

namespace Tests\Feature\Rams;

use Tests\TestCase;

/**
 * Phase 27 Plan 03 (GATE-09) structural regression guard — catches a future
 * edit that adds a hardcoded team-size string (or a divergent copy of
 * DisplayLiftPolicy's bands) in a new file instead of calling through the
 * single shared class D-03 requires. Modeled on
 * HazardResolutionPathGuardTest's allow-list file-scanning pattern (Phase 26
 * Plan 07), inverted for the `DisplayLiftPolicy::` marker string instead of
 * the hazard-resolution machinery markers.
 *
 * The allow-list below was re-derived from a live grep of the repo at plan
 * execution time, not hand-copied:
 *
 *   grep -rl "DisplayLiftPolicy::" app --include=*.php
 *
 * returned 3 files:
 *   - app/Services/Rams/RamsComplianceUpgradeService.php (Plan 27-02's
 *     suggestHandlingMethod()/deriveMaterialHandling() delegation, plus this
 *     plan's enforceDisplayLiftGate() independent re-check)
 *   - app/Services/MethodStatementService.php (Plan 27-04)
 *   - app/Services/Worksheet/SafetyProfileService.php (Plan 27-04)
 *
 * Scanned directory: app/ only. tests/ is excluded — test fixtures
 * legitimately reference DisplayLiftPolicy constantly and are not a
 * generation path. app/Services/Rams/DisplayLiftPolicy.php itself is also
 * excluded — the class's own internal calls use `self::`, never
 * `DisplayLiftPolicy::`, so this exclusion is inert in practice but kept
 * explicit for clarity (a class does not "call itself" for this guard's
 * purposes).
 *
 * @see app/Services/Rams/DisplayLiftPolicy.php
 * @see tests/Feature/Rams/HazardResolutionPathGuardTest.php
 * @see .planning/phases/27-manual-handling-display-lift-house-rules/27-03-PLAN.md
 */
class DisplayLiftPolicySourceGuardTest extends TestCase
{
    /**
     * The 3 files that genuinely call DisplayLiftPolicy's static methods,
     * re-derived from a live repo grep at plan execution time.
     */
    private const ALLOWED_FILES = [
        'app/Services/Rams/RamsComplianceUpgradeService.php',
        'app/Services/MethodStatementService.php',
        'app/Services/Worksheet/SafetyProfileService.php',
    ];

    private const MARKER = 'DisplayLiftPolicy::';

    public function test_only_the_sanctioned_files_reference_display_lift_policy(): void
    {
        $appPath = base_path('app');
        $files   = $this->phpFilesUnder($appPath);

        $allowedRealPaths = array_map(
            static fn (string $rel): string|false => realpath(base_path($rel)),
            self::ALLOWED_FILES,
        );

        // The class's own definition file never calls itself via
        // `DisplayLiftPolicy::` (it uses `self::`) — excluded explicitly for
        // clarity, not because it would otherwise trip the scan.
        $definitionFile = realpath(base_path('app/Services/Rams/DisplayLiftPolicy.php'));

        $offenders = [];
        foreach ($files as $file) {
            $real = realpath($file);
            if ($real !== false && ($real === $definitionFile || in_array($real, $allowedRealPaths, true))) {
                continue; // allow-listed: a sanctioned caller, or the class's own definition
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
            'Phase 27 GATE-09 structural invariant violated: a file outside the sanctioned allow-list '
            . "references DisplayLiftPolicy directly, risking a divergent, hardcoded copy of its bands.\n"
            . "Offenders:\n  - " . implode("\n  - ", $offenders),
        );
    }

    /**
     * Sanity check that the allow-list itself is exactly 3 entries and every
     * entry resolves to a real file — a typo in the constant would otherwise
     * silently widen what the guard permits.
     */
    public function test_allow_list_has_three_entries_and_all_resolve(): void
    {
        $this->assertCount(3, self::ALLOWED_FILES);

        foreach (self::ALLOWED_FILES as $rel) {
            $this->assertFileExists(base_path($rel), "allow-listed path does not exist: {$rel}");
        }
    }

    /**
     * The allow-list must match a FRESH grep of the repo, not a stale
     * hand-copy — this is the acceptance criterion's explicit requirement.
     */
    public function test_allow_list_matches_a_fresh_grep_of_the_repo(): void
    {
        $appPath = base_path('app');
        $files   = $this->phpFilesUnder($appPath);

        $definitionFile = realpath(base_path('app/Services/Rams/DisplayLiftPolicy.php'));

        $matching = [];
        foreach ($files as $file) {
            $real = realpath($file);
            if ($real === $definitionFile) {
                continue;
            }

            $contents = file_get_contents($file);
            if ($contents !== false && str_contains($contents, self::MARKER)) {
                $matching[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file);
            }
        }

        $normalise = static fn (string $path): string => str_replace('\\', '/', $path);

        $matching = array_map($normalise, $matching);
        $allowed  = array_map($normalise, self::ALLOWED_FILES);

        sort($matching);
        sort($allowed);

        $this->assertSame(
            $allowed,
            $matching,
            'The allow-list must exactly match a fresh grep -rl "DisplayLiftPolicy::" app --include=*.php run — '
            . 'update ALLOWED_FILES if a new sanctioned caller was added, or investigate if an unexpected file appears.',
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
