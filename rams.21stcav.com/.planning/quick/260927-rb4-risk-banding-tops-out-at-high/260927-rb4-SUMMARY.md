---
phase: quick
plan: 260927-rb4
subsystem: rams-render
tags: [rams, risk-matrix, banding, safety-content, pdf, docx, single-source-of-truth]
requires:
  - "260726-rf3 Plan 03/04 — the RAMS_UNIFIED_COMPOSER kill switch and the two PDF blades"
provides:
  - "A 5x5 = 25 now renders VERY HIGH, in its own colour, with redesign wording"
  - "App\\Support\\Rams\\RamsRiskBand — the only place a RAMS risk band is decided"
affects:
  - app/Support/Rams/RamsRiskBand.php
  - app/Support/Rams/RamsTheme.php
  - app/Services/DocxBuilderService.php
  - app/Services/RiskMatrixService.php
  - resources/views/pdf/rams.blade.php
  - resources/views/pdf/rams-v2.blade.php
  - tests/Feature/Rams/RiskBandingFourBandTest.php
  - tests/Feature/Rams/DocxBuilderPdfParityTest.php
  - tests/Fixtures/rams/21cq30960/*
  - tests/Fixtures/rams/tilda-21cq29531/*
key-files:
  created:
    - app/Support/Rams/RamsRiskBand.php
    - tests/Feature/Rams/RiskBandingFourBandTest.php
  modified:
    - app/Support/Rams/RamsTheme.php
    - app/Services/DocxBuilderService.php
    - app/Services/RiskMatrixService.php
    - resources/views/pdf/rams.blade.php
    - resources/views/pdf/rams-v2.blade.php
    - tests/Feature/Rams/DocxBuilderPdfParityTest.php
    - tests/Fixtures/rams/21cq30960/expected-html-v1.html
    - tests/Fixtures/rams/21cq30960/expected-html-v2.html
    - tests/Fixtures/rams/21cq30960/expected-docx-v1.xml.norm
    - tests/Fixtures/rams/21cq30960/expected-docx-v2.xml.norm
    - tests/Fixtures/rams/tilda-21cq29531/expected-html-v1.html
    - tests/Fixtures/rams/tilda-21cq29531/expected-html-v2.html
    - tests/Fixtures/rams/tilda-21cq29531/expected-docx-v1.xml.norm
    - tests/Fixtures/rams/tilda-21cq29531/expected-docx-v2.xml.norm
decisions:
  - "VERY HIGH fill is SCC's F5B7B1, not this repo's risk_orange FFD0A0 — orange above pink inverts the severity gradient; 10.0:1 contrast on the body navy"
  - "LOW/MED/HIGH keep this repo's existing fills — porting SCC's whole palette would have been a restyle, not a banding fix"
  - "The DOCX HIGH fill moves FFDEDE -> F8D7DA as a consequence of there being one palette, which was the point"
  - "Bands are NOT config/rams_theme.php tokens — a band is a threshold plus a fill plus a label plus reviewed prose; RamsTheme::riskBand() delegates"
  - "All four legend rows use SCC wording verbatim, not just the two new ones — it is one reviewed table"
  - "The legend becomes one row per band; the range column closes (10 - 16, 17 - 25) instead of the open-ended 10+ that hid the gap"
  - "RiskMatrixService's fifth, differently-cut copy (Low <=3 / Critical >=13) delegates too; its 'Critical' label is now 'Very High'"
---

# Quick Task 260927-rb4: the worst score on the matrix no longer prints as "HIGH" — Summary

A RAMS 5×5 matrix runs to 25. Every renderer banded at `>= 10 => HIGH` and
stopped. So an almost-certain fatality — 5 × 5 = 25, the worst score the matrix
can express — came out in the same pink as a 10, carried the same word, and the
legend gave the reader the same instruction. There was no "Very High" band and
nothing in the document said the activity must not proceed and must be
redesigned. That is now fixed in **every** renderer, from **one** helper.

## Which renderer is live, and how that was determined

`config/rams.php:44` — `'unified_composer' => env('RAMS_UNIFIED_COMPOSER', false)`.
The default is **`false`**, and `RAMS_UNIFIED_COMPOSER` appears in **no**
`.env`, `.env.example` or `.env.testing` in this tree. `PdfService::buildRams()`
branches on it at line 66: flag on → `pdf.rams-v2`; flag off → **`pdf.rams`**.
`DocxBuilderService::build()` branches the same way at line 106: flag off →
`buildLegacy()`.

**So the live renderers are `resources/views/pdf/rams.blade.php` and
`DocxBuilderService::buildLegacy()`.** The production VPS `.env` cannot be read
from here, and it is the one place the flag could be `true` — which is why
**both** blades were fixed and both are covered by the new test. The fix is
live-visible whichever way that flag is set.

### Stale copies found and deliberately NOT edited

- `resources/views/pdf/rams.blade - keep boarder.php`
- `resources/views/pdf/rams.blade-keep-borders.php`
- `resources/views.backup-260430/pdf/rams.blade.php` (a whole backup view tree,
  `$riskBg` at `:443`)

None of these is a registered view name; none is reachable from `PdfService`.
Left untouched.

## The defect, exactly

`pdf/rams.blade.php:472-482` (and byte-identically `rams-v2.blade.php:514-524`):

```php
// Risk helpers — LOW ≤4, MED 5–9, HIGH ≥10 (matching reference)
$riskBg = function(int $score): string {
    if ($score >= 10) return '#F8D7DA';  // HIGH red
```

and a legend hard-coded to three `<td class="rk-band">` cells in one `<tr>`,
top row `10+ / HIGH / "Stop work. Implement immediate controls."`

## Every band-logic copy in the tree

| Copy | Bands before | Action |
|---|---|---|
| `resources/views/pdf/rams.blade.php` — `$riskBg` / `$riskLabel` + legend | `≤4 / 5-9 / 10+` | **Fixed** — delegates to `RamsRiskBand`; legend `@foreach`es `::legend()` |
| `resources/views/pdf/rams-v2.blade.php` — same block + legend | `≤4 / 5-9 / 10+` | **Fixed** — identical change |
| `app/Services/DocxBuilderService.php` — `riskColour()`, `riskBadge()`, footer legend, private `RISK_*` hexes | `≤4 / 5-9 / 10+`, HIGH = `FFDEDE` | **Fixed** — both methods delegate; legend is one row per band; the four `RISK_*` constants **deleted** |
| `app/Services/RiskMatrixService.php` — `riskColour()` / `riskLabel()` | `≤3 / 4-6 / 7-12 / ≥13 "Critical"` — a fifth, differently-cut copy agreeing with nothing | **Fixed** — both delegate. Had **no callers** anywhere, so zero blast radius; its `public const RISK_*` are retained as public API but are no longer consulted |
| `app/scripts/generate_rams_docx.js:91` — `riskColor()` | 3-band | **NOT changed** — a standalone Node script with **no PHP caller** (`grep -rn generate_rams_docx app/ config/ resources/ tests/ --include=*.php` → nothing). Dead in this application; changing it is not verifiable from the PHP suite. **Reported, not touched.** |
| `app/Console/Commands/CreateDocxTemplates.php:143-147` | a 5-row severity/likelihood *reference* table with its own `1 / 2-6 / 7-9 / 10-14 / ≥15` priority wording | **NOT changed** — this generates blank `.docx` *templates*, a different artefact with different semantics from the rendered RAMS. Out of scope; **reported**. |
| `app/Services/ProjectPackageRamsReviewService.php:285` — `riskLabelFromScore()` | 3-band, feeds a `<select>` on `project-packages/review.blade.php` with fixed `Low / Medium / High` options | **NOT changed** — adding a fourth option means changing an editable review form and its option list, a different surface with its own tests. Out of scope; **reported as a follow-up**. |
| `.rm-low` / `.rm-med` / `.rm-high` CSS in both blades | 3 classes | **NOT changed** — declared but never applied (`class=` never references them). Dead CSS; **reported**. |
| `.planning/reference/21cav-rams-skill/` | vendored | **NOT touched.** Guarded by `MANIFEST.sha256` + `VendoredSkillDriftGuardTest`, which passed. This was a port into application code, not a re-vendor. |
| SCC `resources/rams-skill/` (sibling repo) | the canonical 4-band table | **READ ONLY.** `git status --short -- resources/rams-skill` in that repo is **empty**. |

## The fix

`app/Support/Rams/RamsRiskBand.php` — a `final readonly` value object. One
static `for(int $score)` resolver returns the threshold's fill, short code,
legend heading, closed range **and** legend action together; `legend()` returns
the four bands ascending. Everything else calls it:

```php
$riskBand  = fn(int $score) => \App\Support\Rams\RamsRiskBand::for($score);
$riskBg    = fn(int $score): string => $riskBand($score)->cssFill();
$riskLabel = fn(int $score): string => $riskBand($score)->code;
```

```php
private function riskColour(int $score): string { return RamsRiskBand::for($score)->fill; }
private function riskBadge(int $score): string  { return RamsRiskBand::for($score)->code; }
```

and both legends now iterate `RamsRiskBand::legend()`, so a row cannot go
missing or disagree with the cell above it. `RamsTheme::riskBand()` was added as
a pass-through seam for `pdf.rams-v2`, which already receives `$theme` — a
delegation, deliberately not a second implementation.

`DocxBuilderService`'s `RISK_GREEN / RISK_AMBER / RISK_ORANGE / RISK_RED`
constants are gone, replaced by a comment saying why they must not come back.
They are how the DOCX came to paint HIGH `FFDEDE` while the PDF used `F8D7DA`.

### The table

| Band | Range | Fill | Provenance |
|---|---|---|---|
| LOW | 1 – 4 | `D4EDDA` | this repo, unchanged |
| MEDIUM | 5 – 9 | `FFF3CD` | this repo, unchanged |
| HIGH | 10 – 16 | `F8D7DA` | this repo's PDF value; the DOCX now matches it |
| VERY HIGH | 17 – 25 | `F5B7B1` | **new** — SCC |

## Legend wording — SCC verbatim, all four rows

From the sibling SCC repository, `resources/rams-skill/scripts/build_rams.js:269-272`,
character for character:

- **LOW, 1 – 4** — "Acceptable. Monitor and maintain controls."
- **MEDIUM, 5 – 9** — "Further reduction required where reasonably practicable.
  Work may proceed once the listed controls are implemented and the residual
  risk is accepted by the Lead Engineer."
- **HIGH, 10 – 16** — "Work must not proceed until the listed controls are
  implemented and verified by the Lead Engineer."
- **VERY HIGH, 17 – 25** — "Unacceptable. Work must not proceed. The activity
  must be redesigned or further controls introduced to bring the risk down
  before any work begins."

The old three-band `"Stop work. Implement immediate controls."` is gone, and the
test asserts it is absent from every renderer. LOW's string was already
identical in both applications; MEDIUM's replaces the thinner "Action required
to reduce risk." from the same reviewed table.

## The VERY HIGH colour, and why

`F5B7B1`, SCC's value — **not** this repo's existing `risk_orange` (`FFD0A0`,
described in `config/rams_theme.php:56` as a "high-mid interim"). `FFD0A0` is a
lighter, *warmer* orange: sitting it above the HIGH pink would make the worst
band read as less severe than the one below it and invert the gradient.
`F5B7B1` is a deeper, more saturated salmon-red — a visible escalation from
`F8D7DA` — and it is the reviewed SCC value, so both applications now agree.

Contrast against the body navy (`#1A1A2E`, `rams_theme.dark_text`, the colour
these cells' text actually uses): relative luminance 0.5646 vs 0.0114 →
**10.0:1**, comfortably past WCAG AA 4.5:1 and past AAA 7:1.

No `risk_vhigh` token was added to `config/rams_theme.php`. A second home for
the colour is how this defect started.

## RED, then GREEN

`tests/Feature/Rams/RiskBandingFourBandTest.php` written first, against the
unfixed renderers:

```
FAILED  RiskBandingFourBandTest > legacy pdf blade renders very high and a four row legend
FAILED  RiskBandingFourBandTest > unified pdf blade renders very high and a four row legend
FAILED  RiskBandingFourBandTest > docx risk colour and badge have four bands
FAILED  RiskBandingFourBandTest > docx legend lists four bands with scc wording
FAILED  RiskBandingFourBandTest > docx matrix grid paints the top right cell very high
Tests:    5 failed, 4 passed (41 assertions)
Duration: 7.52s
```

All five renderer paths red. After the fix:

```
✓ worst possible score is very high not high
✓ sixteen and seventeen fall on opposite sides
✓ all four band boundaries
✓ legend has four rows ascending with scc wording
✓ legacy pdf blade renders very high and a four row legend
✓ unified pdf blade renders very high and a four row legend
✓ docx risk colour and badge have four bands
✓ docx legend lists four bands with scc wording
✓ docx matrix grid paints the top right cell very high
Tests:    9 passed (89 assertions)
Duration: 17.12s
```

The blade tests render a fixture hazard scored **5×5 = 25** before controls and
**4×4 = 16** after, so a single render exercises both sides of the boundary:
25 must carry `#F5B7B1` and the words `VERY HIGH`, 16 must keep `#F8D7DA`, and
`substr_count($html, 'class="rk-band"')` must be exactly **4**. The DOCX is
covered separately through `riskColour()`, `riskBadge()`, the rendered
`word/document.xml` legend and the 5×5 grid fills.

## Pre-existing tests that encoded the defect

Two assertions in `DocxBuilderPdfParityTest` had to change; neither was deleted
to make a red test pass, both were replaced by stronger properties.

| Retired | Why it can no longer hold | Successor |
|---|---|---|
| `test_risk_colour_uses_three_band_palette` — hex literals, `red` for both 10 and 25 | It asserted the cap that *was* the defect | `test_risk_colour_uses_the_shared_four_band_palette` — asserts against `RamsRiskBand::FILL_*`, so parity is now checked at the source of truth rather than by re-typing hexes that can drift, plus `16 != 17` |
| `test_risk_legend_emits_five_by_five_grid_and_three_band_footer` | The footer has four bands now | `..._and_four_band_footer` — same grid assertions, plus `VERY HIGH` |
| `test_risk_badge_returns_med_for_score_5_and_6` | Still holds — the D7 `>= 5 => MED` threshold is preserved by the helper | Extended in place with 16 / 17 / 25 |

## Golden snapshots regenerated

The legend change is intentional output drift, so the two committed golden sets
were regenerated the documented way —
`php artisan rams:regenerate-snapshots --force` — giving 8 modified fixture
files. `--group snapshot` went from **8 failures to 12 passed**.

Two notes worth keeping:

- **`--group snapshot` is excluded from the default suite by `phpunit.xml`, so
  `--filter Rams` never reaches it.** `artisan test tests/Feature/Rams/Snapshot`
  reports `No tests found` — the group exclusion still applies. It must be run
  as `artisan test --group=snapshot`, or golden drift is invisible.
- The regenerate command writes goldens for **all eight** fixtures, but only two
  fixtures have goldens committed. The six extra files it created were deleted
  individually (never `git clean`) so the diff stays on the banding change.
  `database/database.sqlite` was byte-compared before and after and is
  **unchanged** — the command's always-rolled-back transaction held.

## Gates

| Gate | Result |
|---|---|
| `tests/Feature/Cockpit` + `tests/Unit/Cockpit` | `Tests: 489 passed (8881 assertions)` — 489 / 0 entering, held |
| `tests/Feature/Worksheets` | `Tests: 183 passed (1687 assertions)` |
| `tests/Feature/Documents` | `Tests: 18 passed (142 assertions)` |
| `--filter Rams` | `Tests: 2 deprecated, 906 passed (3689 assertions)` — 897 entering, +9 new |
| `--group snapshot` | `Tests: 12 passed (71 assertions)` |
| D-06 baseline | `Tests: 2 skipped, 159 passed (396 assertions)` — `>= 159 passed AND 0 failed` |
| Three sha256 pins | all three unchanged, before and after |

`VendoredSkillDriftGuardTest` — `manifest exists and is parseable`, `no vendored
file has been edited in place` — both PASS.

## Follow-ups reported, not done

1. **`ProjectPackageRamsReviewService::riskLabelFromScore()`** and the
   `Low / Medium / High` `<select>` on `project-packages/review.blade.php` are
   still three-band. A reviewer can therefore file a 25 as "High" by hand.
   Needs the option list and its tests changed together.
2. **`app/scripts/generate_rams_docx.js`** — dead 3-band Node copy, no PHP
   caller. Delete it or wire it to nothing.
3. **`CreateDocxTemplates.php:143-147`** — the blank-template reference matrix
   uses its own unrelated `1 / 2-6 / 7-9 / 10-14 / ≥15` priority scheme. Worth
   deciding whether it should mirror the document bands.
4. **`resources/views.backup-260430/`** and the two `keep-bord*` blades are
   uncalled duplicates of a live safety template. They should be deleted rather
   than left to be found and edited by mistake.
5. **`.planning/reference/21cav-rams-skill/`** was not re-vendored. If SCC's
   `f7ba07df` band change belongs in the vendored copy, that is a separate,
   deliberate re-vendor commit that regenerates `MANIFEST.sha256`.

Nothing pushed, nothing deployed.
