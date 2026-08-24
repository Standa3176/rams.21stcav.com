<?php

namespace Tests\Feature\Rams;

use Tests\TestCase;

/**
 * Phase 26 Plan 04 (HAZ-02) structural guard, modeled on
 * DeadPathRemovalGuardTest — the fixed-vocabulary mandatory-baseline
 * machinery this plan deletes from HazardLibraryService, and the
 * baseline_hazards config key Plan 26-03 already removed, must NEVER be
 * re-introduced anywhere in app/ or tests/.
 *
 * Allowed exception: this test file itself contains the forbidden strings
 * (it's the guard). Excluded by realpath(__FILE__) whitelist.
 *
 * No runtime dependencies (no DB, no Browsershot) so the guard fires
 * everywhere CI runs.
 *
 * @see .planning/phases/26-hazard-library-structural-inversion/26-04-PLAN.md
 */
class HazardInjectionPathsRemovedGuardTest extends TestCase
{
    /**
     * Static substring scan: none of the forbidden strings may appear in
     * any *.php file under app/ or tests/ (except this guard file itself).
     */
    public function test_deleted_hazard_injection_paths_have_zero_references_in_app_and_tests(): void
    {
        $forbiddenStrings = [
            // Path #5 dead code removed from HazardLibraryService (Plan 26-04 Task 1):
            'MANDATORY_KEYWORDS',
            'mandatoryBaseline',
            'mergeWithMandatory',
            // The fixed 11-hazard config array removed by Plan 26-03:
            'rams_tier1.baseline_hazards',
        ];

        $thisTestPath = realpath(__FILE__);
        $appPath      = base_path('app');
        $testsPath    = base_path('tests');

        $files = array_merge(
            $this->phpFilesUnder($appPath),
            $this->phpFilesUnder($testsPath),
        );

        $offenders = [];
        foreach ($files as $file) {
            $real = realpath($file);
            if ($real !== false && $real === $thisTestPath) {
                continue; // whitelist: this guard file itself
            }
            $contents = file_get_contents($file);
            if ($contents === false) {
                continue;
            }
            foreach ($forbiddenStrings as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = "{$file} contains '{$needle}'";
                }
            }
        }

        $this->assertEmpty(
            $offenders,
            "Phase 26 HAZ-02 invariant violated: deleted hazard-injection machinery re-introduced.\n"
            . "Offenders:\n  - " . implode("\n  - ", $offenders)
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
