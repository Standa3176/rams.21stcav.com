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
 * ── WHY rows() IS THE REAL API (261011-f9l) ──────────────────────────────────
 *
 * 261011-d62 emitted the heading as a plain string into the same flat
 * `list<string>` as the actions, so NO consumer could tell one from the other.
 * That single root cause produced three live symptoms:
 *
 *   1. the engineer link's drawer counted headings as steps, so a 2-phase,
 *      7-action room advertised "Install Steps (9)";
 *   2. its `<ol>` gave every heading an ordinal of its own, so an engineer's
 *      "step 7" no longer meant the 7th action;
 *   3. the office view and the DOCX could not number actions at all without
 *      numbering the headings too.
 *
 * rows() returns `['kind' => 'heading'|'step', 'text' => string]` so each
 * surface can render and count by kind. lines() and text() are kept and are
 * defined ON TOP of rows(): both Blade files, WorksheetDocxService and
 * tests/Unit/Support/WorksheetInstallStepsTest call them, and their output is
 * byte-for-byte what it was before this task.
 *
 * Ordinals are NEVER stored in the text — each surface supplies its own, in
 * ONE continuous sequence across the whole room (not restarting per phase), so
 * "step 7" means the 7th action of that room on every surface.
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

    /** A phase title row. Carries no ordinal and is never counted as a step. */
    public const KIND_HEADING = 'heading';

    /** An action row. The thing an engineer does, and the thing that is numbered. */
    public const KIND_STEP = 'step';

    /**
     * Render-ready rows from ANY install_steps shape, each tagged with its kind.
     *
     * This is the method every surface should use. Counting steps is
     * `count(stepsOnly($rows))`, never `count($rows)`; numbering walks only
     * the `step` rows, in one continuous sequence across the whole room.
     *
     * Returns `[]` for null, `''`, whitespace, `[]`, and for a nested phase
     * list whose phases carry no items — so the callers' existing empty states
     * ("Install steps being generated…" on the office view, a hidden drawer on
     * the engineer link) are preserved. A normaliser that turned "nothing" into
     * one blank bullet would be a regression.
     *
     * Shapes (a) and (b) carry no phase structure, so every row they produce
     * is a `step` — which is why those surfaces still number 1..N exactly as
     * they did before 261011-f9l.
     *
     * @return list<array{kind: string, text: string}>
     */
    public static function rows(mixed $steps): array
    {
        return self::flatten($steps);
    }

    /**
     * Just the action rows — what "how many install steps are there" means.
     *
     * @param  list<array{kind: string, text: string}>  $rows
     * @return list<array{kind: string, text: string}>
     */
    public static function stepsOnly(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            fn ($row) => ($row['kind'] ?? null) === self::KIND_STEP
        ));
    }

    /**
     * Flat, clean step lines — headings and actions undifferentiated.
     *
     * Kept because it is the pre-261011-f9l public API and returns exactly
     * what it returned then. New callers want rows(); a caller that cannot
     * tell a heading from an action is the defect f9l fixed.
     *
     * @return list<string>
     */
    public static function lines(mixed $steps): array
    {
        return array_map(fn ($row) => $row['text'], self::rows($steps));
    }

    /**
     * The same lines joined with newlines. `''` when there are no steps.
     */
    public static function text(mixed $steps): string
    {
        return implode("\n", self::lines($steps));
    }

    /**
     * Wrap clean, non-empty strings as rows of one kind.
     *
     * @param  list<string>  $lines
     * @return list<array{kind: string, text: string}>
     */
    private static function rowsOf(array $lines, string $kind): array
    {
        return array_map(
            fn ($text) => ['kind' => $kind, 'text' => $text],
            self::clean($lines)
        );
    }

    /**
     * Shape dispatch. Everything collapses to a list of clean, kinded rows.
     *
     * @return list<array{kind: string, text: string}>
     */
    private static function flatten(mixed $steps): array
    {
        // (a) string — and also any array element that itself carries newlines.
        if (is_string($steps)) {
            return self::rowsOf(preg_split('/\r?\n/', $steps) ?: [], self::KIND_STEP);
        }

        if (is_int($steps) || is_float($steps)) {
            return self::rowsOf([(string) $steps], self::KIND_STEP);
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
     * The title row is tagged `heading`; the items stay `step`. That tag is
     * the whole point of 261011-f9l — before it, the label was an untagged
     * string and every consumer counted and numbered it as an action.
     *
     * @param  array<array-key, mixed>  $phase
     * @return list<array{kind: string, text: string}>
     */
    private static function flattenPhase(array $phase): array
    {
        $hasTitle = array_key_exists('title', $phase);
        $hasItems = array_key_exists('items', $phase);

        if (! $hasTitle && ! $hasItems) {
            return self::flatten(array_values($phase));
        }

        $items = self::flatten($phase['items'] ?? []);
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

        return array_merge(self::rowsOf([$label], self::KIND_HEADING), $items);
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
