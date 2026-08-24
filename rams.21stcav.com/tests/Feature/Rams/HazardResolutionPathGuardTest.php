<?php

namespace Tests\Feature\Rams;

use Tests\TestCase;

/**
 * Phase 26 Plan 07 (HAZ-02 gap closure) structural regression guard —
 * catches a hypothetical future service that queries the hazard library or
 * resolver directly (HazardTemplate::, ->resolveFromSeeds(), or
 * HazardIncludeWhenResolver), bypassing RiskTemplateResolverService's
 * sanctioned merge/tiering logic. This is the exact class of gap this plan
 * closes: RamsBuilderService::runFromReview() existed for the whole of
 * Plans 26-01..26-06 calling a hazard-resolution path that never reached
 * the tiered resolver, and no test caught it.
 *
 * Modeled on HazardInjectionPathsRemovedGuardTest's file-scanning helper
 * (phpFilesUnder()), but INVERTED: instead of a forbidden-string scan, this
 * is an allow-list scan — only files that legitimately call the
 * hazard-resolution machinery may contain the three marker strings.
 *
 * The allow-list below was re-derived from a live grep of the repo at plan
 * time, not hand-copied — the union of:
 *   grep -rl "HazardTemplate::"        app --include=*.php
 *   grep -rl "resolveFromSeeds("       app --include=*.php
 *   grep -rl "HazardIncludeWhenResolver" app --include=*.php
 * returned 8 files. The 8th, app/Services/Rams/Tier1RamsDefaultsService.php,
 * held the string HazardIncludeWhenResolver only inside a docblock COMMENT
 * (not a call site) — reworded by this plan's Task 3 rather than added to
 * the allow-list, so the allow-list stays limited to files that genuinely
 * call the hazard-resolution machinery, which is its stated purpose.
 *
 * Scanned directory: app/ only. tests/ is excluded — test fixtures
 * legitimately reference these symbols constantly and are not a
 * generation path.
 */
class HazardResolutionPathGuardTest extends TestCase
{
    /**
     * The 7 files that genuinely call the hazard-resolution machinery
     * (HazardTemplate::, ->resolveFromSeeds(), or HazardIncludeWhenResolver),
     * re-derived from a live repo grep at plan time — not hand-copied.
     */
    private const ALLOWED_FILES = [
        'app/Core/Modules/KnowledgeLibrary/HazardLibraryService.php',
        'app/Http/Controllers/HazardTemplateController.php',
        'app/Http/Controllers/RamsController.php',
        'app/Services/RiskTemplateResolverService.php',
        'app/Services/RamsBuilderService.php',
        'app/Services/Rams/HazardIncludeWhenResolver.php',
        'app/Services/RamsExtractionDraftBuilderService.php',
    ];

    private const MARKERS = [
        'HazardTemplate::',
        '->resolveFromSeeds(',
        'HazardIncludeWhenResolver',
    ];

    public function test_only_the_sanctioned_files_reference_hazard_resolution_machinery(): void
    {
        $appPath = base_path('app');
        $files   = $this->phpFilesUnder($appPath);

        $allowedRealPaths = array_map(
            static fn (string $rel): string|false => realpath(base_path($rel)),
            self::ALLOWED_FILES,
        );

        $offenders = [];
        foreach ($files as $file) {
            $real = realpath($file);
            if ($real !== false && in_array($real, $allowedRealPaths, true)) {
                continue; // allow-listed: a sanctioned caller of the hazard-resolution machinery
            }

            $contents = file_get_contents($file);
            if ($contents === false) {
                continue;
            }

            foreach (self::MARKERS as $marker) {
                if (str_contains($contents, $marker)) {
                    $offenders[] = "{$file} contains '{$marker}'";
                }
            }
        }

        $this->assertEmpty(
            $offenders,
            "Phase 26 HAZ-02 structural invariant violated: a file outside the sanctioned "
            . "allow-list references hazard-resolution machinery directly, bypassing "
            . "RiskTemplateResolverService's merge/tiering logic.\nOffenders:\n  - "
            . implode("\n  - ", $offenders)
        );
    }

    /**
     * Sanity check that the allow-list itself is exactly 7 entries and
     * every entry resolves to a real file — a typo in the constant would
     * otherwise silently widen what the guard permits.
     */
    public function test_allow_list_has_seven_entries_and_all_resolve(): void
    {
        $this->assertCount(7, self::ALLOWED_FILES);

        foreach (self::ALLOWED_FILES as $rel) {
            $this->assertFileExists(base_path($rel), "allow-listed path does not exist: {$rel}");
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
