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

    /** Render multi-line narrative as tick-list bullets for on-site verification. */
    public static function narrativeAsTickList(string $narrative): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split("/\r\n|\n|\r/", $narrative) ?: [])));

        if (empty($lines)) {
            return '';
        }

        // Single line → plain paragraph (bullets would add visual noise).
        if (count($lines) === 1) {
            return '<p style="margin:0;">' . e($lines[0]) . '</p>';
        }

        $html = '<ul class="tick-list">';
        foreach ($lines as $line) {
            $html .= '<li><span class="checkbox">&#9744;</span> ' . e($line) . '</li>';
        }
        $html .= '</ul>';

        return $html;
    }
}
