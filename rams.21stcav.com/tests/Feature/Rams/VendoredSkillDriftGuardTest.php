<?php

namespace Tests\Feature\Rams;

use Tests\TestCase;

/**
 * Guards the vendored 21cav-rams skill against silent drift.
 *
 * Milestone v3.0 declares `.planning/reference/21cav-rams-skill/` the source of
 * truth: where the application and those documents disagree about safety
 * content, structure or scoring, the documents win. That only holds if the
 * vendored copy is actually the skill.
 *
 * It was not. Between the 2026-08-23 vendoring and 2026-08-26, the vendored
 * `house-rules.md` was 121 lines against the live skill's 298 — 13 sections
 * against 21 — and `references/standards-and-legislation.md` plus
 * `data/standards-library.json` were absent entirely. Phases 26 and 27 were
 * planned and shipped against that subset, which is how an unconditional
 * strip-out control reached production on installation-only jobs (the live
 * `hazard-library.md` marks it "Removal jobs only"). Nothing detected the gap
 * for three days; it was found by chance while comparing the two repos.
 *
 * This test does not check the vendored copy against the upstream skill — that
 * lives in a different repository and is not reachable from CI. It checks the
 * weaker but useful property that the vendored files have not been edited in
 * place since they were recorded. An intentional re-vendor regenerates
 * MANIFEST.sha256 in the same commit, which makes the re-vendor visible in
 * review rather than silent.
 *
 * To re-vendor:
 *   1. Copy the skill over `.planning/reference/21cav-rams-skill/`, keeping
 *      `README-VENDORED.md` and this manifest.
 *   2. Regenerate the manifest (from the repo root):
 *      find .planning/reference/21cav-rams-skill -type f \
 *        ! -name 'README-VENDORED.md' ! -name 'MANIFEST.sha256' \
 *        | sed 's|.planning/reference/21cav-rams-skill/||' | sort \
 *        | while read f; do \
 *            echo "$(sha256sum ".planning/reference/21cav-rams-skill/$f" | cut -d' ' -f1)  $f"; \
 *          done > .planning/reference/21cav-rams-skill/MANIFEST.sha256
 *   3. Re-read the diff against REQUIREMENTS.md before planning anything on top
 *      of it. The 2026-08-26 re-vendor changed shipped safety positions.
 */
class VendoredSkillDriftGuardTest extends TestCase
{
    private const SKILL_DIR = '.planning/reference/21cav-rams-skill';
    private const MANIFEST  = self::SKILL_DIR . '/MANIFEST.sha256';

    /** Files that are ours, not the skill's, and are excluded from the manifest. */
    private const NOT_PART_OF_SKILL = ['README-VENDORED.md', 'MANIFEST.sha256'];

    public function test_manifest_exists_and_is_parseable(): void
    {
        $path = base_path(self::MANIFEST);

        $this->assertFileExists($path, 'MANIFEST.sha256 is missing — the drift guard cannot run.');

        $entries = $this->manifestEntries();

        $this->assertNotEmpty($entries, 'MANIFEST.sha256 parsed to zero entries.');

        foreach ($entries as $relative => $hash) {
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{64}$/',
                $hash,
                "Malformed sha256 for {$relative} in MANIFEST.sha256.",
            );
        }
    }

    public function test_no_vendored_file_has_been_edited_in_place(): void
    {
        $drifted = [];

        foreach ($this->manifestEntries() as $relative => $expected) {
            $path = base_path(self::SKILL_DIR . '/' . $relative);

            if (! is_file($path)) {
                $drifted[] = "{$relative} — listed in the manifest but missing from disk";
                continue;
            }

            $actual = hash_file('sha256', $path);

            if ($actual !== $expected) {
                $drifted[] = "{$relative} — content changed (expected {$expected}, got {$actual})";
            }
        }

        $this->assertSame([], $drifted, $this->explain($drifted));
    }

    public function test_no_untracked_file_has_appeared_in_the_skill_directory(): void
    {
        $manifest = $this->manifestEntries();
        $onDisk   = [];

        $dir = base_path(self::SKILL_DIR);
        $it  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));

            if (in_array($relative, self::NOT_PART_OF_SKILL, true)) {
                continue;
            }

            $onDisk[] = $relative;
        }

        sort($onDisk);
        $expected = array_keys($manifest);
        sort($expected);

        $this->assertSame(
            $expected,
            $onDisk,
            "The vendored skill's file list no longer matches MANIFEST.sha256. "
            . 'A file was added or removed without regenerating the manifest. '
            . 'If this was an intentional re-vendor, regenerate it (see this test\'s docblock) '
            . 'and re-read the diff against REQUIREMENTS.md before planning on top of it.',
        );
    }

    /**
     * The two files whose absence caused the 2026-08-26 finding. Named
     * explicitly so a future partial vendoring fails loudly rather than
     * quietly shrinking the source of truth.
     */
    public function test_the_previously_missing_files_are_present(): void
    {
        foreach ([
            'references/standards-and-legislation.md',
            'data/standards-library.json',
        ] as $relative) {
            $this->assertFileExists(
                base_path(self::SKILL_DIR . '/' . $relative),
                "{$relative} is absent from the vendored skill. It was missing from the "
                . '2026-08-23 vendoring, which left Phase 31 (standards/COSHH scoping) '
                . 'with no source to port from. Re-vendor completely.',
            );
        }
    }

    /** @return array<string, string> relative path => expected sha256 */
    private function manifestEntries(): array
    {
        $lines = file(
            base_path(self::MANIFEST),
            FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES,
        );

        $entries = [];

        foreach ($lines ?: [] as $line) {
            // "<sha256>  <relative path>" — two spaces, sha256sum's own format.
            if (! preg_match('/^([0-9a-f]{64})\s\s(.+)$/', trim($line), $m)) {
                continue;
            }

            $entries[$m[2]] = $m[1];
        }

        return $entries;
    }

    /** @param list<string> $drifted */
    private function explain(array $drifted): string
    {
        if ($drifted === []) {
            return '';
        }

        return "The vendored 21cav-rams skill has been edited in place:\n  - "
            . implode("\n  - ", $drifted)
            . "\n\nThis directory is the milestone's declared source of truth and is "
            . "vendored verbatim — it is not ours to edit. If you intended to re-vendor "
            . "a newer skill, regenerate MANIFEST.sha256 in the same commit (see this "
            . "test's docblock) so the change is visible in review, and re-read the diff "
            . 'against REQUIREMENTS.md before planning on top of it.';
    }
}
