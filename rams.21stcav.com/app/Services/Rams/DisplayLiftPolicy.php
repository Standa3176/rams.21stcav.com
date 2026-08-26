<?php

namespace App\Services\Rams;

/**
 * Phase 27 Plan 01 (RULE-02, RULE-03) — the single, shared, machine-readable
 * form of the 21CAV display-lift team-size policy and the RULE-03 wall-mount
 * removal statement.
 *
 * D-03: "The bands and the sentences they produce live in one PHP class...
 * Every stating point reads it — suggestHandlingMethod(), the
 * MethodStatementService:461 string, the hazard-library seeder's Manual
 * handling control text, SafetyProfileService, and GATE-09 itself. The gate
 * and the generator must resolve the team size through the same call, so a
 * future edit cannot make them disagree." This class IS that single choke
 * point. It is not yet wired into any of those callers — that wiring is
 * Plans 27-02/03/04. This plan only creates the class and proves it in
 * isolation.
 *
 * ── Provenance of the settled bands ──────────────────────────────────────
 *
 * `.planning/reference/21cav-rams-skill/references/house-rules.md:8-11`
 * (the SKILL, unamended) states the original 21CAV position: every display
 * is a two-operative team lift regardless of panel size, never four
 * operatives, never conditional on screen size.
 *
 * `.planning/REQUIREMENTS.md:74` RULE-02 originally mirrored that skill text
 * verbatim. During Phase 27 discussion the user was shown that a size-ladder
 * is the EXACT defect the 21CQ30960 professional review raised against the
 * app's own `RamsComplianceUpgradeService::suggestHandlingMethod()` ladder
 * (4 persons at 85"+, 3 persons at 65"+) — and, having been shown that
 * conflict explicitly, deliberately overrode the skill on the app side only.
 * See `.planning/phases/27-manual-handling-display-lift-house-rules/27-CONTEXT.md`
 * D-01.
 *
 * D-01's FIRST amendment (superseded, recorded here only so a future reader
 * does not reintroduce it as "the fix"): a two-operative FLOOR for every
 * display up to and including 90", with a 3-operative ladder above 90".
 * Never 1 operative at any size; never 4 operatives at any size.
 *
 * D-01's CORRECTION (2026-08-25, same day, after research — this is the
 * FINAL, governing position; it supersedes the floor-only amendment's
 * "never 1 operative" clause, and every other clause of the floor-only
 * amendment stands): research surfaced that
 * `App\Services\Worksheet\SafetyProfileService` has no equivalent of
 * `suggestHandlingMethod()`'s existing ≤14" small-panel exclusion, so
 * removing the pre-existing 55" threshold entirely (a separate, later D-04
 * decision) would have put a "minimum 2-person lift" instruction on a
 * 10.1" room-booking touch panel. Asked to resolve that, the user reinstated
 * a single-operative band below 55" — returning to the position first typed
 * at the start of the discussion ("55 can be one man, 65-85 2 man and >90
 * 3 man") which the intermediate floor-only answer had displaced. The
 * reversal was flagged explicitly and confirmed twice, and the exact 55"/90"
 * boundaries were pinned in a follow-up rather than assumed. See
 * `27-CONTEXT.md` D-01's correction block, and `.planning/REQUIREMENTS.md:74`
 * RULE-02's amendment note, which records this same history.
 *
 * ── The final, settled bands ─────────────────────────────────────────────
 *
 *   | Item                                          | Team size            |
 *   |------------------------------------------------|----------------------|
 *   | Scheduling / touch / control panel ≤14"        | No manual-handling row at all (existing exclusion, unchanged) |
 *   | Display under 55"                              | 1 operative           |
 *   | Display 55" to 90" inclusive                   | 2 operatives minimum  |
 *   | Display above 90"                              | 3 operatives minimum  |
 *   | Any display, any size                          | Never 4 or more       |
 *   | Display whose size cannot be resolved (D-05)   | 2 operatives, silently — no flag, no error |
 *
 * The ≤14" exclusion (no-row) and the D-05 unresolvable-size fallback
 * (2-operative row) are DELIBERATELY DIFFERENT outcomes for the same "no
 * numeric size available at this call site" starting point — see
 * `forSize()`'s `$isSmallControlPanel` parameter versus its `$inches === null`
 * handling. Do not collapse them.
 *
 * Mechanical aids (trolley, panel lifter) are additional and never discharge
 * a required operative — this is unchanged from the original skill text and
 * from D-01's floor amendment (D-02). The >90" sentence in particular must
 * never read as an aid-satisfiable alternative to the third operative; the
 * pre-existing `RamsComplianceUpgradeService.php:1261-1262`
 * ">=65in -> 'Two persons may lift if using a panel-lift trolley'" wording is
 * exactly the construction this class's >90" sentence must not repeat.
 *
 * RULE-03's wall-mount removal sequence (`wallMountRemovalStatement()`) is
 * UNAMENDED — it is not part of D-01's team-size divergence. It is fixed by
 * `house-rules.md:13-16`: controlled to the lowest practicable height, one
 * operative each side, before release from the mount, stated explicitly as
 * the highest-risk lift on a strip-out.
 */
final class DisplayLiftPolicy
{
    /**
     * The inclusive upper bound (inches) of the ≤14" scheduling/touch/
     * control-panel exclusion. At or below this size, combined with the
     * small-control-panel flag, there is no manual-handling row at all.
     */
    private const SMALL_PANEL_MAX_INCHES = 14;

    /**
     * The exclusive upper bound (inches) of the 1-operative band. At exactly
     * this size the 2-operative band applies (the band is inclusive on its
     * lower edge, not this one).
     */
    private const SINGLE_OPERATIVE_MAX_INCHES = 55;

    /**
     * The inclusive upper bound (inches) of the 2-operative band. Above this
     * size the 3-operative band applies.
     */
    private const TEAM_LIFT_MAX_INCHES = 90;

    /** Minimum operatives required under {@see SINGLE_OPERATIVE_MAX_INCHES}. */
    private const MIN_OPERATIVES_UNDER_55 = 1;

    /** Minimum operatives required from 55" to 90" inclusive. */
    private const MIN_OPERATIVES_55_TO_90 = 2;

    /** Minimum operatives required above 90". */
    private const MIN_OPERATIVES_ABOVE_90 = 3;

    /** Never 4 or more operatives, at any size. */
    private const MAX_OPERATIVES_NEVER_EXCEED = 3;

    /**
     * Resolve the display-lift team size for a resolved inch value.
     *
     * `$isSmallControlPanel` combined with `$inches <= 14` is the ONLY case
     * that returns null (no manual-handling row at all) — this is the
     * pre-existing scheduling/touch/booking/control-panel exclusion this
     * class now also exposes, not a new rule.
     *
     * `$inches === null` (D-05, an unresolvable size) is NOT the same as the
     * no-row case above — it silently resolves to the same shape and
     * sentence as the 55"-90" band, with no flag and no distinguishing
     * marker in the return value. This is the deliberate asymmetry D-05
     * records: an unknown size defaults conservatively (2), which sits
     * above the <55" band's 1.
     *
     * Every other input returns `['min_persons' => int, 'sentence' => string]`.
     *
     * @return array{min_persons: int, sentence: string}|null
     */
    public static function forSize(?float $inches, bool $isSmallControlPanel = false): ?array
    {
        if ($isSmallControlPanel && $inches !== null && $inches <= self::SMALL_PANEL_MAX_INCHES) {
            return null;
        }

        if ($inches === null) {
            // D-05: unresolvable size takes the 2-operative band silently.
            return self::band55To90();
        }

        if ($inches < self::SINGLE_OPERATIVE_MAX_INCHES) {
            return self::bandUnder55();
        }

        if ($inches <= self::TEAM_LIFT_MAX_INCHES) {
            return self::band55To90();
        }

        return self::bandAbove90();
    }

    /**
     * An INDEPENDENT re-check for GATE-09. Deliberately does not call
     * {@see forSize()} and shares no code path with it beyond referencing
     * the same three numeric constants — D-03 requires the gate and the
     * generator to each resolve the team size through the shared class, but
     * a violation check that merely re-derived forSize()'s output and
     * compared it would not be a true independent check; this method
     * re-implements the band logic from the constants directly.
     *
     * Returns true when:
     *   - `$statedPersons >= 4` (never 4+, any size); OR
     *   - `$inches !== null && $inches > 90 && $statedPersons < 3` (below-floor
     *     above 90"); OR
     *   - `$inches !== null && $inches >= 55 && $statedPersons === 1` (1
     *     operative at 55" or larger).
     *
     * Returns false for every other combination, including `$inches === null`
     * regardless of `$statedPersons` (D-05 — an unresolvable size is never a
     * gate error) and including `$statedPersons === 1 && $inches < 55` (the
     * corrected single-operative band is correct output, not a defect).
     */
    public static function violatesPolicy(int $statedPersons, ?float $inches): bool
    {
        if ($statedPersons >= self::MAX_OPERATIVES_NEVER_EXCEED + 1) {
            return true;
        }

        if ($inches === null) {
            // D-05: an unresolvable size is never a gate error.
            return false;
        }

        if ($inches > self::TEAM_LIFT_MAX_INCHES && $statedPersons < self::MIN_OPERATIVES_ABOVE_90) {
            return true;
        }

        if ($inches >= self::SINGLE_OPERATIVE_MAX_INCHES && $statedPersons === self::MIN_OPERATIVES_UNDER_55) {
            return true;
        }

        return false;
    }

    /**
     * RULE-03's wall-mount removal statement, verbatim matching
     * `house-rules.md:13-16`'s required sequence. Unamended — not part of
     * D-01's team-size divergence. The single reusable string so the
     * seeder and any decommission-scan logic never diverge on wording.
     */
    public static function wallMountRemovalStatement(): string
    {
        return 'Removal of a display from an existing wall mount is the highest-risk lift on any strip-out. '
            . 'The load is controlled to the lowest practicable height with one operative each side before release from the mount.';
    }

    /**
     * A single sentence usable in static contexts (the DB seeder, the
     * method-statement fallback) that cannot reference one specific inch
     * value — states all three numeric thresholds (14, 55, 90) and the
     * never-4 rule in prose.
     */
    public static function genericBandSummary(): string
    {
        return 'Displays up to 14 inches used as scheduling, touch or control panels require no manual-handling row. '
            . 'Displays under 55 inches are a 1-operative lift, displays from 55 to 90 inches inclusive require a minimum of 2 operatives, '
            . 'and displays above 90 inches require a minimum of 3 operatives. Never 4 or more operatives are required at any size. '
            . 'Mechanical aids are additional and never discharge a required operative.';
    }

    /**
     * The ordered band table for test introspection — mirrors
     * {@see LegacyHazardNameFoldMap::all()}'s role. Covers the 1/2/3
     * operative bands only (the ≤14" no-row exclusion and the D-05
     * unresolvable-size fallback are not bands in this table — they are
     * behaviours of {@see forSize()} layered on top of it).
     *
     * @return list<array{min_inches: ?float, max_inches: ?float, min_persons: int}>
     */
    public static function bands(): array
    {
        return [
            [
                'min_inches' => null,
                'max_inches' => (float) self::SINGLE_OPERATIVE_MAX_INCHES,
                'min_persons' => self::MIN_OPERATIVES_UNDER_55,
            ],
            [
                'min_inches' => (float) self::SINGLE_OPERATIVE_MAX_INCHES,
                'max_inches' => (float) self::TEAM_LIFT_MAX_INCHES,
                'min_persons' => self::MIN_OPERATIVES_55_TO_90,
            ],
            [
                'min_inches' => (float) self::TEAM_LIFT_MAX_INCHES,
                'max_inches' => null,
                'min_persons' => self::MIN_OPERATIVES_ABOVE_90,
            ],
        ];
    }

    /** @return array{min_persons: int, sentence: string} */
    private static function bandUnder55(): array
    {
        return [
            'min_persons' => self::MIN_OPERATIVES_UNDER_55,
            'sentence' => 'Single-person lift for displays under 55 inches. '
                . 'Use correct lifting technique and screen protection during transit. Do not lay face-down.',
        ];
    }

    /** @return array{min_persons: int, sentence: string} */
    private static function band55To90(): array
    {
        return [
            'min_persons' => self::MIN_OPERATIVES_55_TO_90,
            'sentence' => 'Team lift — minimum 2 operatives for displays 55 to 90 inches. '
                . 'Mechanical aids (trolley, panel lifter) are used in addition where available, not instead of the second operative. '
                . 'Use screen protection during transit. Do not lay face-down.',
        ];
    }

    /** @return array{min_persons: int, sentence: string} */
    private static function bandAbove90(): array
    {
        return [
            'min_persons' => self::MIN_OPERATIVES_ABOVE_90,
            'sentence' => 'Team lift — minimum 3 operatives required for displays above 90 inches. '
                . 'This third operative is a required floor, not an allowance — mechanical aids (trolley, panel lifter) are used in addition where available '
                . 'and do not reduce the required team size. Use screen protection during transit. Do not lay face-down.',
        ];
    }
}
