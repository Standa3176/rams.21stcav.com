<?php

namespace Tests\Feature\Rams;

use Tests\TestCase;

/**
 * Phase 28 Plan 04 (D-04) — the repo-wide static ban on the literal token
 * FFP2. RULE-01/GATE-06's runtime gate only catches code paths a live
 * regeneration proof job actually exercises; this milestone has been
 * reopened by reintroduction before, so D-04 wants breadth at BUILD time on
 * top of the runtime gate's caution. Modeled on
 * HazardInjectionPathsRemovedGuardTest's shape.
 *
 * There is no legitimate sentence in generated document content containing
 * the bare token FFP2 (the house rule is FFP3, singular, with face-fit
 * testing) — so unlike GATE-07's negation-aware confined-space detector,
 * this is a trivial substring ban, not a negation/affirmative classifier.
 *
 * Excluded, and why (kept deliberately narrow, per D-04's "price of leaving
 * backups untouched" framing):
 *   - resources/views.backup-260430/** and the two "keep border(s)" frozen
 *     copies — 5 backup/legacy files the user chose to leave untouched
 *     rather than edit or delete (D-04). They never regenerate live
 *     document output.
 *   - ControlTextRuleViolations.php / PpeVocabularyFoldMap.php and their
 *     three test files — these ARE the FFP2-ban/fold mechanism itself. A
 *     detector regex, a fold-map lookup key and their own test fixtures
 *     must reference the literal token FFP2 in order to detect/replace it
 *     wherever ELSE it appears. Excluding them is not a loophole: every
 *     other file under app/, resources/views/, config/, database/seeders/
 *     and tests/ is still fully scanned, so a 15th live document-content
 *     site (or a hand-copied second detector) is still caught. Confirmed
 *     this session that every lowercase `ffp2` occurrence in the tree also
 *     falls inside these same 5 files (method/test names, docblocks, the
 *     fold map's lowercase lookup key) — so a case-sensitive scan is
 *     sufficient once they are excluded; no separate case-insensitive
 *     forbidden-string entry is needed.
 *
 * @see .planning/phases/28-ppe-ceiling-electrical-boundary-house-rules/28-04-PLAN.md
 */
class FfpTwoBannedFromSourceTest extends TestCase
{
    /**
     * Files that legitimately reference the literal token FFP2 as the
     * ban/fold mechanism's own source or its direct test fixtures — never
     * as generated document content. Paths are relative to the repo root.
     */
    private const EXCLUDED_FILES = [
        // Frozen backup/legacy copies (D-04) — deliberately untouched.
        'resources/views/pdf/rams.blade - keep boarder.php',
        'resources/views/pdf/rams.blade-keep-borders.php',
        // The ban/fold mechanism's own source and its direct test fixtures.
        'app/Services/Rams/ControlTextRuleViolations.php',
        'app/Services/Rams/PpeVocabularyFoldMap.php',
        'tests/Unit/Services/Rams/ControlTextRuleViolationsTest.php',
        'tests/Unit/Services/Rams/PpeVocabularyFoldMapTest.php',
        'tests/Feature/Rams/PpeFfp2RenderRegressionTest.php',
    ];

    public function test_ffp2_does_not_appear_in_any_non_backup_source_file(): void
    {
        $forbidden = 'FFP2';

        $thisTestPath = realpath(__FILE__);
        $basePath     = base_path();

        $scanRoots = [
            base_path('app'),
            base_path('resources/views'),
            base_path('config'),
            base_path('database/seeders'),
            base_path('tests'),
        ];

        $files = [];
        foreach ($scanRoots as $root) {
            $files = array_merge($files, $this->phpFilesUnder($root));
        }

        $excluded = array_map(
            static fn (string $relative): string => rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative),
            self::EXCLUDED_FILES,
        );

        $offenders = [];
        foreach ($files as $file) {
            $real = realpath($file);
            if ($real !== false && $real === $thisTestPath) {
                continue; // whitelist: this guard file itself
            }

            foreach ($excluded as $excludedPath) {
                if ($real !== false && realpath($excludedPath) !== false && $real === realpath($excludedPath)) {
                    continue 2; // whitelist: known ban-mechanism / backup file
                }
            }

            $contents = file_get_contents($file);
            if ($contents === false) {
                continue;
            }

            if (str_contains($contents, $forbidden)) {
                $offenders[] = "{$file} contains '{$forbidden}'";
            }
        }

        $this->assertEmpty(
            $offenders,
            "RULE-01/GATE-06 static ban violated: the literal token FFP2 was found outside the "
            . "known backup/ban-mechanism exclusion list.\nOffenders:\n  - " . implode("\n  - ", $offenders)
        );
    }

    /**
     * Recursive glob for *.php files under a directory. `.blade.php` files
     * resolve to extension `php` under SplFileInfo::getExtension() (splits
     * on the LAST dot), so pointing this at resources/views needs no
     * modification to the helper itself.
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
