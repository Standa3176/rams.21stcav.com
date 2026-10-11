<?php

namespace App\Support;

/**
 * Normalises `generated_data.rooms[n].install_steps` into a flat list of step
 * lines for rendering.
 *
 * ── WHY THIS EXISTS (261011-d62) ─────────────────────────────────────────────
 *
 * `install_steps` has THREE live producers and they do not agree on a shape.
 * Both render paths had only ever learned the first one, and PHP's
 * "Array to string conversion" warning is promoted to an exception by
 * Illuminate\Foundation\Bootstrap\HandleExceptions (error_reporting(-1) plus a
 * throwing handler), so the other two shapes hard-500'd BOTH surfaces:
 *
 *   (a) newline-delimited STRING
 *       app/Core/AI/Prompts/WorksheetPrompt.php:203 asks the model for
 *       `{"install_steps": "1. Step one\n2. Step two"}`.
 *
 *   (b) FLAT ARRAY of step strings
 *       app/Services/DocumentEdits/Adapters/WorksheetEditAdapter.php:256 and
 *       :285 (`append_install_step` / `replace_install_step`) write
 *       `array_values($steps)`. The adapter's own comment at :249 states
 *       "install_steps may be a string (legacy) or array (post-Pass B
 *       worksheets)" — so an array is a SUPPORTED shape, not a bug.
 *
 *   (c) NESTED phase list `[['step' => 1, 'title' => '…', 'items' => [...]], …]`
 *       app/Services/WorksheetGeneratorService.php:543 assigns
 *       `buildPhasedPlan()` (`: array`, :772) straight into `install_steps` in
 *       its "fallback steps if AI empty" branch — i.e. exactly when the
 *       install-step AI call returns nothing. The degraded path.
 *
 * `generated_data` is a persisted JSON column, so worksheets already saved on
 * live carry shapes (b) and (c). That is why the READ path is hardened here
 * rather than the write path being narrowed: narrowing the write would leave
 * every already-persisted worksheet broken forever.
 *
 * ⚠ Do NOT "simplify" this back to a cast or to
 * `preg_split('/\r?\n/', (string) $steps)`. That is the defect this class
 * exists to fix, and it 500s the engineer link — the surface an engineer opens
 * on site.
 *
 * Shape (c) is FLATTENED CARRYING ITS TITLE: an engineer needs to know
 * "Pre-Start & Access" from "Second Fix", so each phase contributes a heading
 * line (`PHASE 1 — Pre-Start & Access`) ahead of its items. The uppercase
 * `PHASE n —` token is what distinguishes a heading from an action inside a
 * plain, escaped list — no markup is emitted, because every echo on the
 * client-signed engineer link stays escaped.
 *
 * @see \App\Support\SurveyPdfHelpers  house precedent for a shared render helper
 * @see .planning/quick/261011-d62-worksheet-install-steps-array-render/PLAN.md
 */
class WorksheetInstallSteps
{
    /**
     * Leading list numbering / bullet glyphs the producers leave in the text.
     *
     * The `u` modifier is deliberate and is a fix, not a copy: the inline
     * version this replaced had no `u`, so the 3-byte `•` was matched one BYTE
     * at a time and a bulleted AI line rendered as mojibake on the engineer
     * link. `u` also makes preg_replace return null on invalid UTF-8, which
     * clean() falls back from rather than dropping the line.
     */
    private const LEADING_MARKER = '/^\s*(?:\d+[\.\)]|[-–—•*])\s*/u';

    /** ASCII-only fallback for text that is not valid UTF-8. */
    private const LEADING_MARKER_ASCII = '/^\s*(?:\d+[\.\)]|[-*])\s*/';

    /**
     * Flat, clean, render-ready step lines from ANY install_steps shape.
     *
     * Returns `[]` for null, `''`, whitespace, `[]`, and for a nested phase
     * list whose phases carry no items — so the callers' existing empty states
     * ("Install steps being generated…" on the office view, a hidden drawer on
     * the engineer link) are preserved. A normaliser that turned "nothing" into
     * one blank bullet would be a regression.
     *
     * @return list<string>
     */
    public static function lines(mixed $steps): array
    {
        return self::clean(self::flatten($steps));
    }

    /**
     * The same lines joined with newlines, for the office view's existing
     * `white-space:pre-wrap` block. `''` when there are no steps.
     */
    public static function text(mixed $steps): string
    {
        return implode("\n", self::lines($steps));
    }

    /**
     * Shape dispatch. Everything collapses to a list of raw candidate lines.
     *
     * @return list<string>
     */
    private static function flatten(mixed $steps): array
    {
        // (a) string — and also any array element that itself carries newlines.
        if (is_string($steps)) {
            return preg_split('/\r?\n/', $steps) ?: [];
        }

        if (is_int($steps) || is_float($steps)) {
            return [(string) $steps];
        }

        if (! is_array($steps)) {
            // null, bool, object, resource — nothing renderable.
            return [];
        }

        // (c) nested phase list: a title/items entry anywhere means phase shape.
        $out = [];
        foreach ($steps as $entry) {
            if (is_array($entry)) {
                $out = array_merge($out, self::flattenPhase($entry));

                continue;
            }

            // (b) flat array of step strings (or stray scalars).
            $out = array_merge($out, self::flatten($entry));
        }

        return $out;
    }

    /**
     * One `['step' => n, 'title' => '…', 'items' => [...]]` phase.
     *
     * A blank/missing title drops the heading line and keeps the items. A phase
     * with no items contributes NOTHING — a phase name is meaningless without
     * actions under it, and emitting a lone heading would read to an engineer
     * as an instruction with its content lost.
     *
     * An entry carrying neither `title` nor `items` is treated as a plain
     * nested list and flattened recursively, so a merely double-wrapped flat
     * array still renders.
     *
     * @param  array<array-key, mixed>  $phase
     * @return list<string>
     */
    private static function flattenPhase(array $phase): array
    {
        $hasTitle = array_key_exists('title', $phase);
        $hasItems = array_key_exists('items', $phase);

        if (! $hasTitle && ! $hasItems) {
            return self::flatten(array_values($phase));
        }

        $items = self::clean(self::flatten($phase['items'] ?? []));
        if ($items === []) {
            return [];
        }

        $title = is_string($phase['title'] ?? null) ? trim($phase['title']) : '';
        if ($title === '') {
            return $items;
        }

        $step  = $phase['step'] ?? null;
        $label = (is_int($step) || (is_string($step) && ctype_digit($step))) && (int) $step > 0
            ? 'PHASE ' . (int) $step . ' — ' . $title
            : 'PHASE — ' . $title;

        return array_merge([$label], $items);
    }

    /**
     * Trim, strip leading numbering/bullets, drop blanks, reindex.
     *
     * The engineer link stripped leading markers inline before this class
     * existed (public-show.blade.php, pre-261011-d62) because it renders the
     * steps in an `<ol>` that supplies its own numbering; doing it here keeps
     * both surfaces showing the same text.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private static function clean(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $line     = trim((string) $line);
            $stripped = preg_replace(self::LEADING_MARKER, '', $line);
            if ($stripped === null) {
                // Invalid UTF-8 — keep the line rather than lose a step.
                $stripped = preg_replace(self::LEADING_MARKER_ASCII, '', $line) ?? $line;
            }
            $stripped = trim($stripped);
            if ($stripped !== '') {
                $out[] = $stripped;
            }
        }

        return $out;
    }
}
