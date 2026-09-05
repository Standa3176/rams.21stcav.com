<?php

namespace App\Services\Rams;

/**
 * Phase 27 Plan 08 (RULE-02, RULE-13) — Blocker 1 closure. Registry of
 * detectable house-rule violations in free-text hazard control lines.
 *
 * Consumed by `RamsBuilderService::reviewedToRisk()`'s tier-1 precedence
 * branch (the two-tier union policy, 27-08-PLAN.md `<objective>`): any
 * control line a reviewed hazard carries that violates a detectable house
 * rule is replaced with the current library text — REGARDLESS of the
 * `controls_reviewed` marker, because a house rule is a settled safety
 * position, not an engineer preference. Tier 2 (the `controls_reviewed`
 * marker itself) governs everything this class does NOT flag.
 *
 * ── Extensibility ──────────────────────────────────────────────────────────
 * Detectors are declared in one ordered registry ({@see self::DETECTORS})
 * mapping a violation key to a private static detector method name. Phase 28
 * adds `ffp2` / `confined_space`, Phase 31 adds `coshh_wrong_category` — each
 * is a new `DETECTORS` entry plus its own `detectXxx()` method.
 * `reviewedToRisk()` calls only {@see self::detectAll()}; it never changes
 * when a detector is added here.
 *
 * ── Conservative by construction (T-27-08-01) ───────────────────────────────
 * A control line this class cannot confidently classify as a violation is
 * CLEAN — {@see self::detect()} returns null. A false positive here silently
 * overwrites an engineer's deliberate wording on a live safety document,
 * possibly without them noticing; a false negative merely leaves today's
 * (already-shipped) behaviour unchanged. Every detector below is written to
 * prefer the false negative. Two of this plan's acceptance criteria prove
 * this class never flags the app's own corrected library text (every control
 * line `HazardTemplateSeeder` emits) or `DisplayLiftPolicy`'s own sentences —
 * treat both as load-bearing, not box-ticking.
 *
 * Mirrors {@see LegacyHazardNameFoldMap}'s all-static, single-choke-point
 * shape: this is the ONE place a caller resolves a control line's rule
 * conformance through. Do not duplicate a detector's logic anywhere else.
 */
final class ControlTextRuleViolations
{
    /**
     * Plan 27-06's inch-extraction pattern (originally
     * `RamsComplianceUpgradeService::INCH_REGEX`) — moved here as the single
     * shared copy so {@see self::parseStatedInches()} and
     * `RamsComplianceUpgradeService::suggestHandlingMethod()` reuse the exact
     * same pattern rather than maintaining two independent copies that could
     * silently diverge. Matches "98″", "98\"", "98 inch", "98-inch", "10.1″",
     * etc. Capture group 1 is the numeric value.
     */
    public const INCH_REGEX = '/(\d+(?:\.\d+)?)\s*(?:″|"|\\\\"|\xE2\x80\xB3|inch|in\b|-inch)/u';

    /**
     * Phrases that indicate a control states a size threshold with the team
     * requirement applying AT OR ABOVE it — e.g. "over 40 inches", "40 inches
     * or larger".
     *
     * @var list<string>
     */
    private const ABOVE_PHRASES = [
        'over', 'above', 'exceeding', 'more than', 'greater than',
        'or more', 'or larger', 'or above', 'and above', 'and over',
    ];

    /**
     * Phrases that indicate a control states a size threshold with the team
     * requirement applying BELOW it — e.g. "under 55 inches", "less than 55".
     *
     * @var list<string>
     */
    private const BELOW_PHRASES = [
        'under', 'below', 'less than', 'smaller than', 'or less', 'or smaller',
    ];

    /**
     * Ordered detector registry — violation key => private static detector
     * method name. {@see self::detect()} tries each entry in order and
     * returns the key of the FIRST match. Extend by appending a new entry
     * plus its own `detectXxx(string $control): bool` method; never touch
     * `RamsBuilderService::reviewedToRisk()` to add a detector.
     *
     * @var array<string, string>
     */
    private const DETECTORS = [
        'kg_threshold'          => 'detectKgThreshold',
        'size_conditional_lift' => 'detectSizeConditionalLift',
    ];

    /**
     * Classify a single control-measure line.
     *
     * @return string|null a machine-readable violation key, or null when the
     *   line is clean (including "cannot confidently classify" — see the
     *   class docblock's conservative-by-construction note).
     */
    public static function detect(string $control): ?string
    {
        foreach (self::DETECTORS as $key => $method) {
            if (call_user_func([self::class, $method], $control)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Classify every control line in an array.
     *
     * @param  array<int, string>  $controls
     * @return array<int, string> index => violation key, for offending lines
     *   only. A clean line is simply absent from the result (never present
     *   with a null value).
     */
    public static function detectAll(array $controls): array
    {
        $violations = [];

        foreach (array_values($controls) as $i => $control) {
            $key = self::detect((string) $control);
            if ($key !== null) {
                $violations[$i] = $key;
            }
        }

        return $violations;
    }

    // =========================================================================
    // DETECTORS
    // =========================================================================

    /**
     * RULE-13 — a fixed kg lifting threshold, e.g. "over 20 kg", "under
     * 20kg", "above 25 kg". There is no fixed "safe" lifting weight in UK
     * law; stating one is the exact defect 21CQ30960 raised.
     *
     * Requires a threshold word directly adjacent to the number+kg. A line
     * that merely mentions a weight as an INDICATIVE figure — "panel weighs
     * approximately 32 kg — confirm at survey" — never matches, by design
     * (T-27-08-01): "approximately" is not a threshold word, so there is
     * nothing here for this detector to misclassify as a rule.
     */
    private static function detectKgThreshold(string $control): bool
    {
        return (bool) preg_match(
            '/\b(?:over|above|exceeding|more than|under|below|less than)\s+\d+(?:\.\d+)?\s*kg\b/iu',
            $control,
        );
    }

    /**
     * RULE-02 — a control makes the lift team conditional on a screen size
     * in a way that disagrees with {@see DisplayLiftPolicy}'s settled bands
     * (the 55"/90" boundaries). Never re-encodes the bands: the correct team
     * size for the stated threshold is resolved through
     * {@see DisplayLiftPolicy::forSize()} alone.
     *
     * The stated threshold is nudged a hair to the side its own direction
     * word actually means before calling `forSize()` — "under 55" is checked
     * against the <55 band (1 operative), not the exact-55 band (2
     * operatives) `forSize(55.0)` would otherwise resolve to, since
     * `forSize()`'s <55 boundary is exclusive. This is what lets
     * `DisplayLiftPolicy`'s own "under 55 inches" sentence round-trip to
     * clean instead of being flagged against itself.
     *
     * Requires ALL of:
     *   - a recognised threshold direction word/phrase is present;
     *   - {@see self::parseStatedTeamSize()} resolves to exactly one team
     *     size (ambiguous or absent input is not this detector's concern —
     *     T-27-08-01, a parsing miss must never become a false positive);
     *   - {@see self::parseStatedInches()} resolves to exactly one size.
     *
     * This combination is what keeps `DisplayLiftPolicy::genericBandSummary()`
     * — a single string mixing the 1/2/3-operative bands together — clean:
     * `parseStatedTeamSize()` finds 2+ distinct counts across that whole
     * string and returns null (its own built-in ambiguity guard), so this
     * gate never fires on it.
     */
    private static function detectSizeConditionalLift(string $control): bool
    {
        $lower = strtolower($control);

        $direction = null;
        foreach (self::ABOVE_PHRASES as $phrase) {
            if (str_contains($lower, $phrase)) {
                $direction = 'above';
                break;
            }
        }
        if ($direction === null) {
            foreach (self::BELOW_PHRASES as $phrase) {
                if (str_contains($lower, $phrase)) {
                    $direction = 'below';
                    break;
                }
            }
        }
        if ($direction === null) {
            return false;
        }

        $statedPersons = self::parseStatedTeamSize($control);
        $statedInches  = self::parseStatedInches($control);
        if ($statedPersons === null || $statedInches === null) {
            return false;
        }

        $epsilon     = 0.01;
        $checkInches = $direction === 'above' ? $statedInches + $epsilon : $statedInches - $epsilon;

        $expected = DisplayLiftPolicy::forSize($checkInches);
        if ($expected === null) {
            // Small-panel exclusion zone — not this detector's concern.
            return false;
        }

        return $statedPersons !== $expected['min_persons'];
    }

    // =========================================================================
    // SHARED FREE-TEXT PARSERS
    // =========================================================================
    //
    // Moved here from RamsComplianceUpgradeService (Plan 27-06 Task 1) so
    // there is exactly one implementation, per this plan's constraint that
    // the free-text parsers never gain a second copy.
    // RamsComplianceUpgradeService::enforceDisplayLiftGate() now calls these
    // here instead of the private methods it used to own; both call sites'
    // behaviour is unchanged, only the location moved.

    /**
     * Conservative free-text team-size parser for engineer-typed control
     * lines and `material_handling.large_items[].handling_method` strings.
     * Extracts an operative count; NEVER decides conformance (that is
     * {@see DisplayLiftPolicy::violatesPolicy()}'s job alone) and NEVER calls
     * `DisplayLiftPolicy` itself.
     *
     * Recognises, case-insensitively: bare digits and the number-words
     * one-four directly adjacent to "person(s)"/"operative(s)" (e.g.
     * "2 persons", "two persons", "minimum 3 persons", "3-person lift",
     * "team lift (2 persons minimum)", "minimum 4 operatives",
     * "two-operative team lift"), plus "single"/"single-hand" mapped to 1
     * ("single person lift", "single-hand lift").
     *
     * T-27-06-01 (HIGH): a parsing miss must never block a real job or
     * become a false positive. Ambiguity ALWAYS returns null, never a guess:
     *   - no recognisable count anywhere in the text -> null.
     *   - two or more DIFFERENT counts found (e.g. "2 persons normally, 3
     *     for the 98 inch") -> null, even though one of them looks like a
     *     confident match — a genuinely conflicting statement is exactly
     *     the case a conservative parser must decline to resolve.
     *
     * Implementation: normalises the recognised phrasings to bare digits,
     * masks out inch/size phrases (including "NN to MM inches" ranges, so a
     * display's diagonal is never mistaken for a team-size count — this is
     * what keeps every sentence `DisplayLiftPolicy::forSize()` emits,
     * including "...55 to 90 inches..." and "...above 90 inches...",
     * round-tripping to exactly one number), then requires EXACTLY one
     * distinct number to remain in what is left.
     */
    public static function parseStatedTeamSize(string $text): ?int
    {
        $normalised = strtolower($text);

        // "single person"/"single-person"/"single hand"/"single-hand" -> 1.
        $normalised = preg_replace('/\bsingle[\s-]+(?:person|hand)\b/u', '1 person', $normalised)
            ?? $normalised;

        // Number-words one-four, ONLY when directly adjacent to a
        // person/operative keyword — an unrelated "two" elsewhere in the
        // text is never treated as a team-size mention.
        $wordMap = ['one' => '1', 'two' => '2', 'three' => '3', 'four' => '4'];
        $normalised = preg_replace_callback(
            '/\b(one|two|three|four)\b(?=[\s-]*(?:persons?|operatives?)\b)/u',
            static fn (array $m): string => $wordMap[$m[1]],
            $normalised,
        ) ?? $normalised;

        // Mask out inch/size phrases — including "NN to MM inches" ranges —
        // so a display's stated diagonal is never mistaken for a team-size
        // count. Deliberately broader than self::INCH_REGEX (adds the
        // plural "inches" and an optional leading "NN to "/"NN-" range
        // prefix); this masking pattern is an internal detail of THIS
        // parser only — parseStatedInches() below reuses self::INCH_REGEX
        // verbatim, unrelated to this mask.
        $masked = preg_replace(
            '/(?:\d+(?:\.\d+)?\s*(?:to|-)\s*)?\d+(?:\.\d+)?\s*(?:″|"|\\\\"|\xE2\x80\xB3|inch(?:es)?|in\b|-inch)/u',
            ' ',
            $normalised,
        ) ?? $normalised;

        if (! preg_match_all('/\d+(?:\.\d+)?/u', $masked, $matches) || empty($matches[0])) {
            return null; // no recognisable count present
        }

        $distinct = array_unique(array_map(
            static fn (string $n): int => (int) round((float) $n),
            $matches[0],
        ));

        if (count($distinct) !== 1) {
            return null; // ambiguous — two or more different counts stated
        }

        return (int) reset($distinct);
    }

    /**
     * Reuses {@see self::INCH_REGEX} verbatim, applied to `$text` only. No
     * match returns null (D-05's silent-fallback precedent, extended here —
     * an unresolvable size is never a violation on its own, per
     * {@see DisplayLiftPolicy::violatesPolicy()}).
     */
    public static function parseStatedInches(string $text): ?float
    {
        if (preg_match(self::INCH_REGEX, strtolower($text), $m)) {
            return (float) $m[1];
        }

        return null;
    }
}
