---
phase: quick
plan: 260927-rb4
type: defect
severity: live-safety-documentation
subsystem: rams-render
autonomous: true
---

# Quick Task 260927-rb4: the risk banding has only three bands, so a 25 prints as "HIGH"

## Objective

The RAMS 5×5 risk matrix runs to **25**. Every renderer banded at
`>= 10 => HIGH` and stopped there. So `5 likelihood × 5 severity` — an almost
certain fatality, the worst score the matrix can express — came out in the same
pink as a 10, carrying the same word, **HIGH**, with the same legend
instruction. There was no "Very High" band, and nothing anywhere in the
document told the reader that the activity must not proceed and must be
redesigned.

This is live safety documentation. Fix the banding, and make it impossible for
the colour, the label and the legend to disagree again.

## Root cause — four copies, none of them complete

`resources/views/pdf/rams.blade.php:472-482`:

```php
// Risk helpers — LOW ≤4, MED 5–9, HIGH ≥10 (matching reference)
$riskBg = function(int $score): string {
    if ($score >= 10) return '#F8D7DA';  // HIGH red
```

and its legend at `:1299-1307` hard-coded three `<td class="rk-band">` cells in
a single `<tr>`, the top one reading `10+ / HIGH / "Stop work. Implement
immediate controls."`

The same block is duplicated byte-for-byte in `pdf/rams-v2.blade.php:514-524`
(legend `:1360-1370`). `DocxBuilderService` carries its own third copy
(`riskColour()`, `riskBadge()`, and a hard-coded 6-cell legend row) — and its
own private `RISK_*` hexes, which is how the DOCX came to paint HIGH as
`FFDEDE` while the PDF used `F8D7DA`. `RiskMatrixService` holds a **fifth**,
differently-cut copy (`Low ≤3 · Medium 4-6 · High 7-12 · Critical ≥13`) that
agreed with nothing.

`App\Support\Rams\RamsTheme` — the class whose whole purpose is "both renderers
read from the same source of truth" — had no band helper at all.

## Approach — one PHP value object, every renderer reads it

New `App\Support\Rams\RamsRiskBand`: a readonly value object with a single
static `for(int $score)` resolver and a `legend()` accessor. It owns the
threshold, the fill, the short badge code, the legend heading, the closed score
range and the legend action wording **together**, because splitting them is
exactly how the renderers drifted.

The canonical table is ported from the sibling SCC application, which fixed
this same defect on 2026-09-26 (commit `f7ba07df`, "consistent 4-band risk
banding in RAMS builder") — `resources/rams-skill/scripts/brand.js:274-289`:

```
1–4 Low · 5–9 Medium · 10–16 High · 17–25 Very High
```

Its legend wording (`scripts/build_rams.js:268-272`) is reviewed safety copy
and is reproduced **verbatim**, all four rows.

The SCC repository and `.planning/reference/21cav-rams-skill/` are both
**read-only** here. Nothing is written to either; this is a port into
application code, not a re-vendor.

## Tasks

1. **[test]** `tests/Feature/Rams/RiskBandingFourBandTest.php` — fails first.
   Asserts 25 takes the Very High fill / "VERY HIGH" code / redesign wording,
   that **16 and 17 fall on opposite sides**, that the legend has **four** rows
   in ascending order with SCC wording, and asserts it **for every renderer**:
   `pdf.rams`, `pdf.rams-v2`, and the DOCX builder (fills, badge, legend rows
   and the 5×5 grid).
2. **[feat]** `app/Support/Rams/RamsRiskBand.php` — the single source of truth.
3. **[fix]** `pdf/rams.blade.php` — `$riskBg` / `$riskLabel` delegate; the
   legend `@foreach`es `RamsRiskBand::legend()`.
4. **[fix]** `pdf/rams-v2.blade.php` — identical change.
5. **[fix]** `DocxBuilderService` — `riskColour()` / `riskBadge()` delegate;
   the footer legend becomes one row per band; the private `RISK_*` hexes are
   deleted.
6. **[fix]** `RiskMatrixService` — the fifth copy delegates too.
7. **[feat]** `RamsTheme::riskBand()` — a pass-through seam for renderers that
   already hold `$theme`.
8. **[test]** Update the pre-existing three-band assertions in
   `DocxBuilderPdfParityTest` (D6/D7) that encoded the defect.
9. **[chore]** Regenerate the two committed golden snapshot sets via
   `php artisan rams:regenerate-snapshots --force`.

## Decisions

- **VERY HIGH fill = `F5B7B1`, SCC's value — not this repo's `risk_orange`
  (`FFD0A0`).** `FFD0A0` is a lighter, warmer orange; placed above the HIGH
  pink it would read as *less* severe and invert the severity gradient.
  `F5B7B1` is a deeper, more saturated salmon-red — a visible escalation from
  `F8D7DA` — and it is what the reviewed SCC table already uses, so the two
  applications now agree. Contrast against the body navy (`#1A1A2E`,
  `rams_theme.dark_text`) is **10.0:1**, well past WCAG AA's 4.5:1.
- **LOW / MEDIUM / HIGH keep this repository's existing fills**
  (`D4EDDA` / `FFF3CD` / `F8D7DA`). Adopting SCC's full palette would have
  recoloured three bands that were never wrong — a restyle, not a banding fix.
  Only the fourth band is new.
- **The DOCX now paints HIGH `F8D7DA`, not `FFDEDE`.** That is not a restyle
  decision; it falls out of there being one palette. A per-renderer palette is
  the thing being removed.
- **The bands do NOT live in `config/rams_theme.php`.** A theme token can carry
  a fill and nothing else; a band is a threshold *plus* a fill *plus* a label
  *plus* reviewed legend prose. `RamsTheme::riskBand()` delegates rather than
  duplicating, and no `risk_vhigh` token was added — a second home for the
  colour is how this started.
- **Legend wording is SCC verbatim for all four rows, not just the two new
  ones.** It is one reviewed table; MEDIUM's "Further reduction required where
  reasonably practicable…" is materially better safety prose than the old
  "Action required to reduce risk." and comes from the same review.
- **The legend becomes one row per band, not a wider single row.** Four bands
  plus four descriptions will not fit across the page in either renderer, and
  the range column now closes (`10 – 16`, `17 – 25`) instead of the open-ended
  `10+` that concealed the missing band.
- **`.rk-band` / `.risk-key-row` CSS is unchanged** — the legend keeps the same
  classes, so no stylesheet is touched and no `npm run build` is needed.

## Explicitly out of scope

The `for_review` status bug, the missing `rams.show` route, the AI pipeline,
and any `DocxBuilderService` layout beyond the band logic it already contained.

## Gates

- `tests/Feature/Cockpit` + `tests/Unit/Cockpit` — **489 / 0** entering, must hold
- `tests/Feature/Worksheets` (183)
- `tests/Feature/Documents` (18)
- `--filter Rams` — 897 entering, expected to rise
- `--group snapshot` (excluded from the default suite by `phpunit.xml`, so it
  is **not** reached by `--filter Rams` — must be run explicitly)
- D-06 baseline `>= 159 passed AND 0 failed` — never equality against 161
- Three sha256 pins unchanged
