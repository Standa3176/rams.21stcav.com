<?php

namespace App\Services\Worksheet;

/**
 * Render-time text sanitiser. Never mutates source data; only the strings
 * that flow into generated_data / DOCX.
 *
 * Rules:
 *   - "exisiting"  → "existing"       (case-preserving)
 *   - whitespace collapsed (trim + single spaces)
 *   - unmatched "(" suffixed with ")" (common quote-parser bug in room names)
 *   - curly apostrophes → straight
 *
 * Additional rules can be added to `normalize()` without touching callers.
 */
class WorksheetTextNormalizer
{
    /**
     * Apply all normalisation rules to a string. Returns $s unchanged when
     * no rule fires.
     */
    public function normalize(?string $s): string
    {
        if ($s === null || $s === '') {
            return '';
        }

        // 1. Canonical "existing" spelling (case-preserving single-char variant).
        $s = preg_replace_callback(
            '/\bexisiting\b/i',
            fn ($m) => (ctype_upper($m[0][0] ?? '') ? 'Existing' : 'existing'),
            $s,
        ) ?? $s;

        // 2. Straighten curly apostrophes so PhpWord output + snapshot tests
        //    don't diverge across platforms (Windows sometimes emits U+2019).
        $s = strtr($s, [
            "\u{2019}" => "'",
            "\u{2018}" => "'",
            "\u{201C}" => '"',
            "\u{201D}" => '"',
        ]);

        // 3. Collapse runs of whitespace.
        $s = preg_replace('/\s+/u', ' ', (string) $s) ?? $s;
        $s = trim((string) $s);

        // 4. Close unmatched opening paren. Common in room labels like
        //    "Meeting Room (Ground Floor" — the closing paren is lost in
        //    the quote parser. Only applied if there's a mismatch.
        $opens  = substr_count($s, '(');
        $closes = substr_count($s, ')');
        if ($opens > $closes) {
            $s .= str_repeat(')', $opens - $closes);
        }

        return $s;
    }

    /**
     * Recursively normalise all string values in a nested array. Used to
     * sanitise an entire generated_data subtree (rooms / blockers / etc).
     */
    public function normalizeTree(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->normalize($value);
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->normalizeTree($v);
            }
        }
        return $value;
    }
}
