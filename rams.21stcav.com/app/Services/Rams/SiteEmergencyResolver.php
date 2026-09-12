<?php

namespace App\Services\Rams;

/**
 * Phase 29 Plan 02 (RULE-08, GATE-12) — the single shared resolver deciding
 * the verified-name-vs-hold-point A&E branch. No render site (blade, DOCX
 * builder, or composer) re-derives this decision independently — every
 * caller goes through {@see self::resolve()} for the branch text and
 * {@see self::classify()} for the plausibility check. Mirrors
 * {@see ControlTextRuleViolations}'s static-utility-class shape: no
 * constructor, all methods `public static function`.
 *
 * ── The two branches (D-05/D-06) ────────────────────────────────────────────
 * Where a 24/7 Emergency Department is verified for the site (both
 * `nearest_hospital` and `hospital_address` non-empty after trim), it is
 * named with its address, and route/travel time are confirmed at induction.
 * Where it is not verified, the document states the exact house-rule
 * hold-point line — {@see self::HOLD_POINT}, verbatim from
 * `.planning/reference/21cav-rams-skill/references/house-rules.md:270`. The
 * resolver never guesses a hospital name.
 *
 * ── Conservative by construction (D-08, inherited Phase 28 D-01) ───────────
 * {@see self::classify()} returns `null` (clean) for anything it cannot
 * confidently classify as a defect — including the hold-point line itself,
 * which is legitimate output, not a violation. A false positive here would
 * silently overwrite an engineer's deliberate wording on a live safety
 * document; a false negative merely leaves today's (already-shipped)
 * behaviour unchanged. GATE-12 introduces NO UK A&E dataset (D-08) — this is
 * a keyword/format plausibility check only, never a lookup against a real
 * hospital registry.
 */
final class SiteEmergencyResolver
{
    /**
     * Verbatim from house-rules.md §"Emergency arrangements" (`:270`) —
     * never paraphrased. Also passes GATE-12's plausibility check clean
     * (D-08): it is the sanctioned hold-point output, not a defect.
     */
    private const HOLD_POINT = 'Nearest A&E — to be confirmed at induction (must be a 24/7 Emergency Department)';

    /**
     * D-05's banned passive string — must never appear in either branch.
     * Checked as a defence-in-depth backstop (D-01) even though no render
     * site should ever write this value into `nearest_hospital` after this
     * phase.
     */
    private const BANNED_STRING = 'to be identified at site induction';

    /**
     * House-rule categories that are explicitly NOT an A&E (D-08 rule 2):
     * urgent-care centres, minor-injury units, walk-in centres. `utc` is
     * handled separately below via a word-boundary regex, because a bare
     * `stripos` for `utc` would false-positive on any hospital name that
     * merely contains the substring "utc" inside an unrelated word.
     *
     * @var list<string>
     */
    private const URGENT_CARE_KEYWORDS = [
        'urgent care',
        'urgent treatment centre',
        'minor injury unit',
        'minor injuries unit',
        'walk-in centre',
        'walk in centre',
    ];

    /**
     * Resolves `site_emergency` (the canonical `nearest_hospital` /
     * `hospital_address` keys — see `EmergencyComposer`) into the D-05
     * two-branch value.
     *
     * @return array{verified: bool, text: string}
     */
    public static function resolve(array $siteEmergency): array
    {
        $name = trim((string) ($siteEmergency['nearest_hospital'] ?? ''));
        $address = trim((string) ($siteEmergency['hospital_address'] ?? ''));

        if ($name === '' || $address === '') {
            return ['verified' => false, 'text' => self::HOLD_POINT];
        }

        return [
            'verified' => true,
            'text' => sprintf('%s, %s. Route and travel time confirmed at induction.', $name, $address),
        ];
    }

    /**
     * GATE-12's plausibility classifier. Returns `null` when the value is
     * clean (including the hold-point branch, which always passes clean per
     * D-08), or a short machine-readable defect code otherwise:
     *
     *   - `banned_string`               — the D-05 forbidden passive phrase
     *   - `urgent_care_keyword`         — named A&E matches an excluded category
     *   - `missing_address_or_postcode` — named A&E with no address given
     */
    public static function classify(array $siteEmergency): ?string
    {
        $rawName = (string) ($siteEmergency['nearest_hospital'] ?? '');
        $name = trim($rawName);

        // No named A&E at all -> the hold-point branch. Also covers a PM
        // copy-pasting the HOLD_POINT sentence itself back into
        // nearest_hospital (CR-01, 29-VERIFICATION.md gap 1) — that literal
        // is the app's own sanctioned output, never a defect, regardless of
        // hospital_address. Both cases are always legitimate output, never
        // flagged (D-08 conservative-by-construction) — this is checked
        // BEFORE the keyword/address checks below, which only apply once a
        // genuinely different name has actually been given.
        if ($name === '' || $name === self::HOLD_POINT) {
            return null;
        }

        if (stripos($rawName, self::BANNED_STRING) !== false) {
            return 'banned_string';
        }

        foreach (self::URGENT_CARE_KEYWORDS as $keyword) {
            if (stripos($rawName, $keyword) !== false) {
                return 'urgent_care_keyword';
            }
        }

        // `utc` needs a word-boundary check, not bare `stripos` — otherwise
        // any hospital name that merely contains the substring "utc" inside
        // an unrelated word would false-positive. `\b` matches on the
        // word-character/non-word-character transition either side of the
        // literal token.
        if (preg_match('/\butc\b/i', $rawName) === 1) {
            return 'urgent_care_keyword';
        }

        $address = trim((string) ($siteEmergency['hospital_address'] ?? ''));
        if ($address === '') {
            return 'missing_address_or_postcode';
        }

        return null;
    }
}
