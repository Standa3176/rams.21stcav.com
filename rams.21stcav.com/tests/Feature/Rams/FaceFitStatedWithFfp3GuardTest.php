<?php

namespace Tests\Feature\Rams;

use Tests\TestCase;

/**
 * RULE-01 guard — wherever respiratory PPE is specified **in a sentence**, the
 * face-fit requirement is stated alongside it.
 *
 * `references/house-rules.md` §"Respiratory protection":
 *
 * > **FFP3, not [the lower grade]** (and never the typo "FFE3"). Drilling into
 * > masonry and concrete generates respirable crystalline silica.
 * > **Specify face-fit testing.**
 *
 * (The banned grade is spelled out in `house-rules.md`; this file avoids the
 * literal token so it does not have to be added to
 * `FfpTwoBannedFromSourceTest`'s exclusion list — that list should stay narrow
 * and hold only files that genuinely need the token as their mechanism.)
 *
 * Phase 28 fixed the respirator-grade half of RULE-01 and shipped
 * `FfpTwoBannedFromSourceTest` to hold it. It did not hold the face-fit half:
 * afterwards, face-fit was stated in exactly ONE place
 * (`HazardTemplateSeeder.php:294`) while six other prose sites specified FFP3
 * with no face-fit clause at all. On a job whose hazard set did not include the
 * seeder's drilling hazard, the generated document specified respiratory PPE and
 * never mentioned face-fit testing.
 *
 * ── What this guard does and does NOT cover ────────────────────────────────
 * It scans PROSE ONLY — a sentence that specifies FFP3. It deliberately ignores
 * PPE **pick-list labels** (`'Dust Mask (FFP3)'`, PPE arrays, fold-map values):
 * those are UI/vocabulary entries rendered as list items, not statements, and
 * appending a testing regime to a dropdown label would be wrong. It also ignores
 * GATE-06's own exception copy, which names FFP3 as the remediation target.
 *
 * "Prose" is decided by EXCLUSION, not by parsing: any line mentioning FFP3 that
 * does not match a {@see self::NON_PROSE_MARKERS} entry is treated as prose and
 * must state face-fit. That is deliberate — it fails toward flagging, so a new
 * FFP3 sentence in an unanticipated shape trips the guard rather than slipping
 * past it. The cost is that a genuinely new label form needs a marker added
 * here, which is a visible, reviewable edit.
 *
 * ── Deliberate exclusions ──────────────────────────────────────────────────
 * `config/rams_tier1.php` — the Expanding Foam COSHH entry's FFP3 line lives
 * inside the block **Phase 31 owns** for RULE-11 (fire-stopping). Phase 28's
 * D-09 explicitly moved that requirement out and recorded, in three places, that
 * the entry is not to be edited before Phase 31. Excluded here for the same
 * reason — not an oversight.
 */
class FaceFitStatedWithFfp3GuardTest extends TestCase
{
    /**
     * Files scanned for FFP3 prose. Each is a source of generated document
     * content; a new one should be added here when it starts specifying
     * respiratory PPE.
     *
     * @var list<string>
     */
    private const SCANNED_FILES = [
        'app/Services/Rams/RamsComplianceUpgradeService.php',
        'app/Services/RiskTemplateResolverService.php',
        'app/Services/RiskMatrixService.php',
        'app/Services/DocxBuilderService.php',
        'database/seeders/HazardTemplateSeeder.php',
        'resources/views/pdf/rams.blade.php',
        'resources/views/pdf/rams-v2.blade.php',
    ];

    /**
     * Substrings that mark an FFP3 line as something other than a document
     * prose statement. A line matching one of these is skipped.
     *
     * @var list<string>
     */
    private const NON_PROSE_MARKERS = [
        // Bare PPE pick-list / vocabulary labels — rendered as list items, not
        // sentences. Appending a testing regime to a dropdown label is wrong.
        "'Dust Mask (FFP3)'",
        "'Dust mask (FFP3)'",
        "dust masks (FFP3)",   // "PPE: safety footwear, dust masks (FFP3), safety glasses"
        "=> 'Dust Mask (FFP3)'",
        // GATE-06's own RamsGenerationException remediation text ("...replace it
        // with the FFP3 equivalent before regenerating, or set
        // RAMS_PPE_CEILING_ELECTRICAL_GATE=false..."). Operator-facing error
        // copy, never document content — face-fit has no place in it.
        'FFP3 equivalent',
    ];

    public function test_every_ffp3_prose_statement_also_states_face_fit(): void
    {
        $offenders = [];

        foreach (self::SCANNED_FILES as $relative) {
            $path = base_path($relative);
            $this->assertFileExists($path, "scanned file missing — update SCANNED_FILES: {$relative}");

            foreach (file($path, FILE_IGNORE_NEW_LINES) as $i => $line) {
                if (stripos($line, 'FFP3') === false) {
                    continue;
                }

                // Skip the guard's own doc references and PHP comments.
                $trimmed = ltrim($line);
                if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//')) {
                    continue;
                }

                // Skip labels and operator-facing error copy — not statements.
                $isNonProse = false;
                foreach (self::NON_PROSE_MARKERS as $marker) {
                    if (str_contains($line, $marker)) {
                        $isNonProse = true;
                        break;
                    }
                }
                if ($isNonProse) {
                    continue;
                }

                // Prose that specifies FFP3 must state face-fit on the same line.
                if (stripos($line, 'face-fit') === false) {
                    $offenders[] = $relative . ':' . ($i + 1) . ' — ' . trim($line);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "RULE-01: every FFP3 prose statement must also state face-fit testing "
            . "(house-rules.md §\"Respiratory protection\"). Offenders:\n  - "
            . implode("\n  - ", $offenders),
        );
    }

    public function test_the_seeder_control_that_already_stated_face_fit_is_unchanged(): void
    {
        // Non-vacuity anchor: the one site that was already correct before this
        // guard existed. If the scan silently stopped finding FFP3 lines (e.g.
        // a path typo in SCANNED_FILES), this assertion still fails loudly.
        $seeder = file_get_contents(base_path('database/seeders/HazardTemplateSeeder.php'));

        $this->assertStringContainsString(
            'FFP3 dust mask and safety glasses worn during all drilling and cutting. All operatives face-fit tested.',
            $seeder,
            'the seeder control is the canonical phrasing the other sites were aligned to',
        );
    }
}
