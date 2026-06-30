<?php

namespace App\Support;

/**
 * Static helpers used by the site-survey PDF Blade views (summary, blank,
 * field-form). Extracted from the legacy SurveyPdfService when the HTML
 * concatenation moved into Blade — keeping the data-cleansing logic in PHP
 * keeps the Blade templates focused on layout.
 */
class SurveyPdfHelpers
{
    /** Inline "write-on" slot used by the blank/field forms (visual underline). */
    public const BLANK_LINE = '<span style="color:#bbb;">____________________________</span>';

    /** Yes/No badge used in the summary PDF. */
    public static function yn(bool $val): string
    {
        return $val
            ? '<span class="badge-yes">Yes</span>'
            : '<span class="badge-no">No</span>';
    }

    /** Inline "write-on" slot for the blank form. Echoes the value if present. */
    public static function blank(?string $value = null): string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? e($value) : self::BLANK_LINE;
    }

    /**
     * Room headings come from imported data and sometimes have unbalanced
     * parens (e.g. "Conference room (23) - Secondary (Right"). Auto-close
     * trailing unbalanced brackets so the PDF reads cleanly.
     */
    public static function balanceParens(string $title): string
    {
        $open  = substr_count($title, '(');
        $close = substr_count($title, ')');

        if ($open > $close) {
            $title .= str_repeat(')', $open - $close);
        }

        return $title;
    }

    /**
     * Strip the "Standard checks for this solution type:" tail that
     * SurveyService::resolveAvRequirementsText() appends to the operator's
     * overview narrative. Those checklist items are a hint for the surveyor
     * to investigate on-site (and remain visible on the field-form clipboard
     * PDF), but they have NO place on the post-survey summary PDF — once
     * the survey is done, the items are either answered via the structured
     * room fields (dimensions, wall material, etc.) or have been resolved
     * verbally. Leaving them rendered as bullets in the client-facing
     * summary reads like a list of unanswered questions.
     *
     * The marker matches the concatenation pattern in SurveyService.php
     * (verbatim: "\n\nStandard checks for this solution type:\n"). We
     * accept some whitespace flex (one-or-more newlines on either side
     * of the marker line) so a manual operator paste of the same heading
     * also strips cleanly.
     */
    public static function stripStandardChecksTail(string $narrative): string
    {
        $stripped = preg_replace(
            '/\s*\R+\s*Standard checks for this solution type:.*$/su',
            '',
            $narrative
        );

        return trim((string) ($stripped ?? $narrative));
    }

    /**
     * Dedupe a narrative's first line when it repeats the room name — a data
     * quality issue in some imports that causes the PDF to print the heading
     * twice.
     */
    public static function stripLeadingDuplicate(string $narrative, string $roomName): string
    {
        if ($narrative === '' || $roomName === '') {
            return $narrative;
        }

        $lines  = preg_split("/\r\n|\n|\r/", $narrative) ?: [];
        $target = strtolower(trim($roomName));

        while (! empty($lines) && strtolower(trim($lines[0])) === $target) {
            array_shift($lines);
        }

        return implode("\n", $lines);
    }

    /**
     * Render multi-line narrative as either bullets or paragraphs depending
     * on the input shape. Three shapes are recognised:
     *
     *   1. **Explicit list** — half or more lines start with `-`, `*`, `•`.
     *      Author chose discrete items; render as bullets, strip the
     *      original markers so we don't get `• - text` double-marker.
     *
     *   2. **Soft-wrapped prose** — at least one line starts lowercase.
     *      Signals the line is a column-wrap continuation from the previous
     *      one (typical AI extraction from a PDF preserves PDF visual
     *      line breaks mid-sentence). Bullets here produce visually broken
     *      cuts like `• Cinnamon and Saffron are now using the Crestron
     *      Flex integrator kit, which also offers`. Collapse to paragraphs:
     *      double-newlines = paragraph break, single newlines = soft wrap
     *      joined with space.
     *
     *   3. **Capital-start discrete items** — every line starts with a
     *      capital, no list markers. Likely a checklist where the author
     *      didn't bother typing `-`. Render as bullets.
     *
     * Internal mode (default true) prefixes each `<li>` with a ☐ ballot box
     * so the engineer can tick on-site. Client mode omits the ☐.
     *
     * Triggered by user-reported visual bug on Tilda 21CQ29531-05-OPS
     * survey 22 client PDF — every line of the Cinnamon/Saffron AV
     * requirements bulleted mid-sentence because the quote-extractor
     * preserved the source PDF's visual line breaks.
     */
    public static function narrativeAsTickList(string $narrative, bool $internal = true): string
    {
        $narrative = trim($narrative);
        if ($narrative === '') {
            return '';
        }

        $rawLines = array_values(array_filter(array_map('trim', preg_split("/\r\n|\n|\r/", $narrative) ?: [])));

        if (empty($rawLines)) {
            return '';
        }

        // Single line → plain paragraph (bullets would add visual noise).
        if (count($rawLines) === 1) {
            return '<p style="margin:0;">' . e($rawLines[0]) . '</p>';
        }

        // ── Shape detection ──────────────────────────────────────────────
        $markerCount           = 0;
        $hasLowercaseLineStart = false;
        foreach ($rawLines as $line) {
            if (preg_match('/^[\-\*•]\s/u', $line)) {
                $markerCount++;
            }
            if (preg_match('/^[a-z]/u', $line)) {
                $hasLowercaseLineStart = true;
            }
        }

        $isExplicitList = $markerCount >= (count($rawLines) / 2);

        // ── Shape 1: explicit list ───────────────────────────────────────
        if ($isExplicitList) {
            $html = '<ul class="tick-list">';
            foreach ($rawLines as $line) {
                $clean = preg_replace('/^[\-\*•]\s*/u', '', $line);
                if ($internal) {
                    $html .= '<li><span class="checkbox">&#9744;</span> ' . e($clean) . '</li>';
                } else {
                    $html .= '<li>' . e($clean) . '</li>';
                }
            }
            $html .= '</ul>';

            return $html;
        }

        // ── Shape 2: soft-wrapped prose → join + sentence-split → bullets ──
        // Earlier this branch rendered as one paragraph, which produced
        // visually inconsistent output (CINNAMON / SAFFRON read as a wall
        // of text while OREGANO from Shape 3 had clean bullets). User
        // wanted bullets EVERYWHERE for cross-room consistency. We now:
        //   1. Collapse all whitespace runs (incl. PDF-column soft wraps)
        //      into single spaces — joins fragments into a coherent string.
        //   2. Split on sentence boundaries: `[.!?]` followed by whitespace
        //      and an upper-case letter. Conservative enough that
        //      abbreviations like "Mr. Smith" or "i.e. that" don't split
        //      (they don't have a uppercase-letter following the period).
        //   3. Render each sentence as one bullet, with the same internal-
        //      vs-client checkbox rule the other shapes use.
        if ($hasLowercaseLineStart) {
            $joined    = trim((string) preg_replace('/\s+/u', ' ', $narrative));
            $sentences = preg_split('/(?<=[.!?])\s+(?=[A-Z])/u', $joined) ?: [];
            $sentences = array_values(array_filter(array_map('trim', $sentences)));

            if (empty($sentences)) {
                return '';
            }
            if (count($sentences) === 1) {
                return '<p style="margin:0;">' . e($sentences[0]) . '</p>';
            }

            $html = '<ul class="tick-list">';
            foreach ($sentences as $sentence) {
                if ($internal) {
                    $html .= '<li><span class="checkbox">&#9744;</span> ' . e($sentence) . '</li>';
                } else {
                    $html .= '<li>' . e($sentence) . '</li>';
                }
            }
            $html .= '</ul>';

            return $html;
        }

        // ── Shape 3: capital-start discrete items ────────────────────────
        $html = '<ul class="tick-list">';
        foreach ($rawLines as $line) {
            if ($internal) {
                $html .= '<li><span class="checkbox">&#9744;</span> ' . e($line) . '</li>';
            } else {
                $html .= '<li>' . e($line) . '</li>';
            }
        }
        $html .= '</ul>';

        return $html;
    }
}
