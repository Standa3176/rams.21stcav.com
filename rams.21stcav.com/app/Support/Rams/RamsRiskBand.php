<?php

namespace App\Support\Rams;

/**
 * The SINGLE source of truth for RAMS 5×5 risk banding — fill colour, short
 * badge code, legend heading, score range and legend action wording.
 *
 * WHY THIS CLASS EXISTS (quick-260927-rb4)
 * ----------------------------------------
 * The banding was duplicated in four places with only THREE bands each:
 *
 *   - resources/views/pdf/rams.blade.php     ($riskBg / $riskLabel + legend)
 *   - resources/views/pdf/rams-v2.blade.php  (byte-identical copy)
 *   - App\Services\DocxBuilderService         (riskColour / riskBadge + legend)
 *   - App\Services\RiskMatrixService          (a FIFTH, differently-banded copy)
 *
 * Every copy topped out at `>= 10 => HIGH`. A 5×5 matrix runs to 25, so the
 * worst score on the grid — 5 likelihood × 5 severity, a fatality that is
 * almost certain — printed in the same pink as a 10 and carried the same word,
 * "HIGH", with the same legend instruction. There was no "Very High" band and
 * nothing anywhere told the reader the activity must not proceed and must be
 * redesigned.
 *
 * The sibling SCC application fixed exactly this on 2026-09-26 (commit
 * f7ba07df, "consistent 4-band risk banding in RAMS builder"). Its canonical
 * table lives at resources/rams-skill/scripts/brand.js:274-289 and its legend
 * wording at scripts/build_rams.js:268-272. Both are reviewed safety copy and
 * are reproduced here VERBATIM:
 *
 *   1–4 Low · 5–9 Medium · 10–16 High · 17–25 Very High
 *
 * Read the SCC skill; never write to it. It is also the vendored source of
 * truth for this application and live safety content for a second one.
 *
 * INVARIANT
 * ---------
 * Nothing outside this class may decide a band. The fill, the badge, the
 * legend heading and the legend wording all come from one `for()` call, so
 * they can never disagree again — which is the entire point of the fix.
 */
final readonly class RamsRiskBand
{
    /**
     * Band fills — bare 6-digit uppercase hex, no leading '#'.
     *
     * LOW / MEDIUM / HIGH keep this repository's existing PDF values
     * (rams.blade.php pre-fix) so the fix is a banding change and not a
     * restyle. Only the fourth band is new.
     *
     * VERY HIGH adopts SCC's F5B7B1 rather than this repo's existing
     * `risk_orange` (FFD0A0) token: FFD0A0 is a lighter, *warmer* orange that
     * reads as LESS severe than the HIGH pink it would have to sit above,
     * inverting the severity gradient. F5B7B1 is a deeper, more saturated
     * salmon-red — a visible escalation from F8D7DA — and it is what the
     * reviewed SCC table already uses, so the two applications agree.
     * Contrast against the body navy (#1A1A2E, rams_theme dark_text) is
     * 10.0:1, comfortably past WCAG AA's 4.5:1.
     */
    public const FILL_LOW   = 'D4EDDA';
    public const FILL_MED   = 'FFF3CD';
    public const FILL_HIGH  = 'F8D7DA';
    public const FILL_VHIGH = 'F5B7B1';

    private function __construct(
        /** Short badge shown in the score cell — LOW | MED | HIGH | VERY HIGH. */
        public string $code,
        /** Title-case band name — Low | Medium | High | Very High. */
        public string $name,
        /** Legend heading — LOW | MEDIUM | HIGH | VERY HIGH (MED spells out). */
        public string $legendCode,
        /** Inclusive score range, SCC formatting: '1 – 4' … '17 – 25'. */
        public string $range,
        /** Bare 6-digit uppercase hex, no '#'. */
        public string $fill,
        /** Legend action wording — SCC build_rams.js:268-272, verbatim. */
        public string $action,
    ) {}

    /**
     * Resolve the band for a raw risk score (likelihood × severity).
     *
     * Thresholds are SCC's exactly: 17+ Very High, 10-16 High, 5-9 Medium,
     * everything below Low. Out-of-range input (0, negative, >25) clamps into
     * the nearest band rather than throwing — a renderer must never blow up
     * mid-document over a malformed AI score.
     */
    public static function for(int $score): self
    {
        if ($score >= 17) {
            return new self(
                code:       'VERY HIGH',
                name:       'Very High',
                legendCode: 'VERY HIGH',
                range:      '17 – 25',
                fill:       self::FILL_VHIGH,
                action:     'Unacceptable. Work must not proceed. The activity must be redesigned '
                           .'or further controls introduced to bring the risk down before any work begins.',
            );
        }

        if ($score >= 10) {
            return new self(
                code:       'HIGH',
                name:       'High',
                legendCode: 'HIGH',
                range:      '10 – 16',
                fill:       self::FILL_HIGH,
                action:     'Work must not proceed until the listed controls are implemented '
                           .'and verified by the Lead Engineer.',
            );
        }

        if ($score >= 5) {
            return new self(
                code:       'MED',
                name:       'Medium',
                legendCode: 'MEDIUM',
                range:      '5 – 9',
                fill:       self::FILL_MED,
                action:     'Further reduction required where reasonably practicable. Work may proceed '
                           .'once the listed controls are implemented and the residual risk is accepted '
                           .'by the Lead Engineer.',
            );
        }

        return new self(
            code:       'LOW',
            name:       'Low',
            legendCode: 'LOW',
            range:      '1 – 4',
            fill:       self::FILL_LOW,
            action:     'Acceptable. Monitor and maintain controls.',
        );
    }

    /** CSS-ready fill, '#'-prefixed — for the PDF blades. */
    public function cssFill(): string
    {
        return '#'.$this->fill;
    }

    /**
     * The four bands in ascending severity order — the legend, in one place.
     *
     * Every renderer's legend iterates this, so a legend row can never go
     * missing or disagree with the cell colour beside it.
     *
     * @return array<int, self>
     */
    public static function legend(): array
    {
        return [
            self::for(1),
            self::for(5),
            self::for(10),
            self::for(17),
        ];
    }
}
