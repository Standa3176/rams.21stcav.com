<?php

namespace Tests\Feature\Rams;

use Tests\TestCase;

/**
 * Phase 22.1 D-10 + D-11 static guard: the 3 dead-path classes deleted by
 * Plan 22.1-04 must NEVER be re-introduced. Any future commit that adds back
 * a reference to RamsGeneratorService, RamsPrompt, or WorksBulletsPrompt
 * inside app/ or tests/ fails this guard.
 *
 * This is the canary version of the verification-ratchet greps that Plan
 * 22.1-04 itself runs at delete time; running it on every CI build prevents
 * silent re-introduction via a misguided revert or copy-paste.
 *
 * Allowed exception: this test file itself contains the basenames as strings
 * (it's the guard). Excluded by path filter.
 *
 * No runtime dependencies (no Browsershot, no D2 binary, no DB) so the guard
 * fires everywhere CI runs.
 *
 * @see .planning/phases/22.1-rams-scope-room-data-consolidation/22.1-04-PLAN.md
 */
class DeadPathRemovalGuardTest extends TestCase
{
    /**
     * Static substring scan: none of the 3 deleted class basenames may appear
     * in any *.php file under app/ or tests/ (except this guard file itself).
     */
    public function test_deleted_classes_have_zero_references_in_app_and_tests(): void
    {
        $forbiddenClasses = [
            // The three dead-path class basenames deleted by Plan 22.1-04.
            // This file is the one allowed location where the basenames may
            // appear as string literals (the path-filter below whitelists
            // realpath(__FILE__) so the guard does not flag itself):
            //   - RamsGeneratorService  (the legacy app/Services/ + app/Core/Modules/RAMS/ classes)
            //   - RamsPrompt            (whole-document AI prompt; would VIOLATE CLAUDE.md if invoked)
            //   - WorksBulletsPrompt    (the install-action bullet generator behind the deleted textarea)
            'RamsGeneratorService',
            'RamsPrompt',
            'WorksBulletsPrompt',
        ];

        $thisTestPath = realpath(__FILE__);
        $appPath      = base_path('app');
        $testsPath    = base_path('tests');

        // Phase 22.1-06 extension: the phase-level invariant guard
        // (Phase22_1InvariantGuardTest) intentionally cites the same deleted
        // class names as deletion targets for SC #3. It is the legitimate
        // downstream complement to this file and must be whitelisted too.
        $whitelistedGuards = array_filter([
            $thisTestPath,
            realpath(base_path('tests/Feature/Rams/Phase22_1InvariantGuardTest.php')),
        ]);

        $files = array_merge(
            $this->phpFilesUnder($appPath),
            $this->phpFilesUnder($testsPath),
        );

        $offenders = [];
        foreach ($files as $file) {
            $real = realpath($file);
            if ($real !== false && in_array($real, $whitelistedGuards, true)) {
                continue; // whitelist: this guard file + Phase 22.1-06 invariant guard
            }
            $contents = file_get_contents($file);
            if ($contents === false) {
                continue;
            }
            foreach ($forbiddenClasses as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = "{$file} contains '{$needle}'";
                }
            }
        }

        $this->assertEmpty(
            $offenders,
            "Phase 22.1 D-10/D-11 invariant violated: deleted class re-introduced.\n"
            . "Offenders:\n  - " . implode("\n  - ", $offenders)
        );
    }

    /**
     * Filesystem-level guard: the 3 deleted PHP files must not be re-created
     * at their original paths.
     */
    public function test_deleted_class_files_do_not_exist_on_filesystem(): void
    {
        $deletedFiles = [
            base_path('app/Services/RamsGeneratorService.php'),
            base_path('app/Core/AI/Prompts/RamsPrompt.php'),
            base_path('app/Core/AI/Prompts/WorksBulletsPrompt.php'),
            // Plan 22.1-04 Rule-3 deviation: a SECOND RamsGeneratorService
            // existed at Core/Modules/RAMS/ (the actual RamsPrompt consumer)
            // and was deleted in the same wave. Guard against re-creation.
            base_path('app/Core/Modules/RAMS/RamsGeneratorService.php'),
        ];

        foreach ($deletedFiles as $path) {
            $this->assertFileDoesNotExist(
                $path,
                "Phase 22.1 D-10/D-11 invariant violated: deleted file re-created at {$path}."
            );
        }
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
