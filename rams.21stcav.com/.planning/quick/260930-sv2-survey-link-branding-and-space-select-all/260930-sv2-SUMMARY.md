---
phase: quick
plan: 260930-sv2
subsystem: site-survey-engineer-link
tags: [site-survey, branding, accessibility, contrast, no-javascript, select-all, engineer-link]
requires:
  - "Phase 46.5-06 — TYPE_SPACE_LIST and the visit_rooms field on the wizard's step 3"
  - "Phase 46.5-04 — the `intent` mechanism (next/back/create) this task extends"
  - ".planning/reference/21cav-rams-skill/scripts/brand.js — the brand source of truth"
provides:
  - "A no-JavaScript select-all / select-none on the 'Spaces being surveyed' step, via two new `intent` values"
  - "The site-survey engineer link in the real 21CAV palette and type"
  - "Three shipped AA contrast failures on that link, fixed"
  - "tests/Feature/Surveys/SurveyLinkBrandingTest — the branding asserted across all five link states"
  - "tests/Feature/Cockpit/CockpitSpaceSelectAllTest — all four tick states rendered"
affects:
  - resources/views/surveys/show.blade.php
  - resources/views/components/survey/*.blade.php
  - resources/views/components/cockpit/doc-form.blade.php
  - app/Http/Requests/CockpitDocumentRequest.php
  - app/Http/Controllers/ProjectCockpitDocumentController.php
key-files:
  created:
    - tests/Feature/Cockpit/CockpitSpaceSelectAllTest.php
    - tests/Feature/Surveys/SurveyLinkBrandingTest.php
    - .planning/quick/260930-sv2-survey-link-branding-and-space-select-all/PLAN.md
  modified:
    - resources/views/surveys/show.blade.php
    - resources/views/components/survey/photo-upload.blade.php
    - resources/views/components/survey/repeater-equipment.blade.php
    - resources/views/components/survey/select-field.blade.php
    - resources/views/components/survey/toggle-field.blade.php
    - resources/views/components/cockpit/doc-form.blade.php
    - app/Http/Requests/CockpitDocumentRequest.php
    - app/Http/Controllers/ProjectCockpitDocumentController.php
decisions:
  - "D-01: the select-all is TWO MORE `intent` SUBMIT VALUES (`spaces-all`, `spaces-none`), not a query-string link, not a companion checkbox, not CSS. It reuses the control NAME the form already carries for next/back, so no new control name appears — which is the exact objection that killed the companion-checkbox idea at 46.5-06 — and it needs no JavaScript"
  - "D-02: a `?spaces=none` LINK was REJECTED, not overlooked. Wizard steps are reached by POST + withInput(); a GET would arrive with no payload and silently wipe steps 1 and 2"
  - "D-03: `spaces-none` flashes an EMPTY ARRAY and `spaces-all` DROPS the key, so D-02's default-all stays a render decision and 'all' is never a second, driftable definition of the project's space list"
  - "D-04: the brand token NAMES are bound to the TEXT-SAFE shades (`brand.teal` = #016E82, not #01889F; `brand.gold` = #7A6011, not #D4AF37). This makes all 116 existing brand utility usages AA-compliant with zero class edits, and fixes two shipped failures in the same move"
  - "D-05: NO WEBFONT IS FETCHED. Poppins and Verdana Pro are named first in system stacks; nothing is requested over the network. The page is used in plant rooms on mobile data and already blocks on two third-party CDNs"
  - "D-06: the headline hues #01889F and #D4AF37 are DECORATIVE ONLY and live as literals in the <style> block, not as Tailwind tokens — only CSS gradients use them, and an unused token is a drift risk"
  - "D-07: the new CSS classes are prefixed `sv-`, NEVER `cav-`. `cav-` is the cockpit's namespace and FlagOffBehaviourUnchangedTest bans it from the public token pages"
  - "D-08: no decorative colour was added to any status-bearing surface. The one new mark (the section spine) is proven to be structure by asserting its count is identical across every state"
metrics:
  duration: ~2 h
  completed: 2026-09-30
---

# Quick Task 260930-sv2: the survey link joins the brand, and the spaces step gets a select-all — Summary

Two user-reported problems, both fixed and both measured.

The **"Spaces being surveyed"** step now has **`Tick all N`** and **`Tick none`**,
so surveying one room out of eighteen is one press instead of seventeen unticks.
It uses no JavaScript, adds no new control name and adds no route.

The **site-survey engineer link** now renders in the real 21st Century AV palette
and type. Along the way it turned out the old near-miss palette was shipping
**three WCAG AA failures**, all on a page used one-handed in bad light; all three
are fixed.

---

## 1. The select-all — what was chosen, and what was rejected

The cockpit region bans `<script`, nine handler attributes and `<select`, so a JS
toggle was never available. Four mechanisms were considered:

| option | verdict |
|---|---|
| A query-string link, `?spaces=none` | **REJECTED — it breaks the wizard.** Steps are reached by POST + `withInput()`. A GET arrives with no payload, so steps 1 and 2 would be silently wiped. This is the option that *looks* cheapest. |
| A companion "none" checkbox + CSS | **REJECTED — already ruled out.** The comment above `$spaceTicked` records that 46.5-06 rejected exactly this, because it puts a second control name on the form. CSS also cannot untick a box, so the form would still submit the spaces. |
| CSS `:has()` / label-driven | **REJECTED.** Same problem: it can only dim, not unset. The submitted payload would disagree with what the PM sees. |
| **Two more `intent` submit values** | **CHOSEN.** |

`spaces-all` and `spaces-none` join `next`, `back` and `create` on the `intent`
name the form **already carries**. Both land in the existing `advance()`, which
resolves them to the **same** step rather than to `±1`, and rewrite only what is
flashed:

- `spaces-none` flashes `visit_rooms = []`. The view reads `old()`, and an empty
  array means *answered, and the answer is none* → nothing ticked.
- `spaces-all` **drops** the key, so `old()` is absent and **D-02's existing
  default-all** takes over → everything ticked.

That last point is why `spaces-all` does not flash the eighteen names: doing so
would create a second definition of "all" that could drift from the project's
real space list.

**Why this clears every constraint:** no JavaScript, no new route, no `<select`,
and **no new control name** — which was the precise objection that killed the
companion-checkbox idea. The buttons render only when `count($spaces) > 1`, so a
one-space project gets no dead controls and a roomless one keeps its empty state.
Because both intents land in `advance()`, the existing
`test_a_step_advance_writes_no_row_in_any_of_the_six_tables()` guarantee extends
to them, and this task asserts it again directly.

**Copy checked against the fence before use.** `Tick all N`, `Tick none` and
`N of N ticked.` collide with none of the 21 `DEFERRED_AFFORDANCES` keys and
neither `FORBIDDEN_MARKUP` entry. Verified programmatically, not by eye.

---

## 2. The contrast ledger — every pairing shipped, with its measured ratio

Computed with the WCAG 2.x relative-luminance formula. The figures agree with the
independent ledger in `45-UI-SPEC-v1-superseded.md` (5.91 / 4.18 / 5.25 / 2.10),
which cross-validates both.

### Fixed — these were shipping and failing

| foreground | background | ratio | was |
|---|---|---|---|
| `#FFFFFF` | `#016E82` teal buttons | **5.91:1** PASS | white on `#178A95` = **4.11:1 FAIL** |
| `#FFFFFF` | `#7A6011` gold buttons | **5.99:1** PASS | white on `#C9922A` = **2.75:1 FAIL** |
| `#F5EDD6` sand eyebrow | `#014C5A` band | **8.23:1** PASS | `text-white/50` = **3.65:1 FAIL** |

### Text pairings shipped

| foreground | background | ratio | verdict |
|---|---|---|---|
| `#016E82` | `#FFFFFF` | 5.91:1 | PASS — all teal text |
| `#016E82` | `#F2F8F9` (teal/5) | 5.51:1 | PASS |
| `#016E82` | `#E6F0F2` (teal/10) | 5.10:1 | PASS |
| `#7A6011` | `#FFFFFF` | 5.99:1 | PASS — all gold text |
| `#7A6011` | `#F8F7F3` (gold/5) | 5.58:1 | PASS |
| `#7A6011` | `#EBE7DB` (gold/15) | 4.84:1 | PASS |
| `#FFFFFF` | `#014C5A` band | 9.62:1 | PASS |
| `#FFFFFF` | `#016E82` | 5.91:1 | PASS |
| `#FFFFFF` | `#7A6011` | 5.99:1 | PASS |
| `#FFFFFF` at 80% → `#CCDBDE` | `#014C5A` | 6.76:1 | PASS — every secondary band label |
| `#F5EDD6` | `#014C5A` | 8.23:1 | PASS — the eyebrow |
| `#1A1A1A` | `#F8F8F8` page | 16.39:1 | PASS |
| `#FFFFFF` | `#014C5A` teal hover | 9.62:1 | PASS |
| `#FFFFFF` | `#5F4A0E` gold hover | 8.49:1 | PASS |

### Non-text marks (3:1 floor)

| mark | background | ratio | verdict |
|---|---|---|---|
| `#01889F` section spine, header device | `#FFFFFF` | 4.18:1 | PASS as a mark |
| `#016E82` borders and focus rings | `#FFFFFF` | 5.91:1 | PASS |
| `#D4AF37` gold hairline / device | `#014C5A` | 4.58:1 | PASS |

### Measured and DELIBERATELY NOT SHIPPED

The point of measuring is that some answers come back *no*:

| pairing | ratio | why it was not used |
|---|---|---|
| `#01889F` on white **as text** | 4.18:1 | **FAILS** the 4.5:1 text floor. This is why `brand.teal` is bound to `#016E82`, not to the headline hue. |
| `#FFFFFF` on `#01889F` | 4.18:1 | **FAILS** for small text — so the band is `#014C5A`, not the headline teal. (The brief guessed "roughly 3.8:1"; the true figure is 4.18:1. The conclusion holds, the number did not.) |
| `#D4AF37` on white | 2.10:1 | **FAILS even the 3:1 non-text floor.** Gold is therefore never text and never sits behind text; it appears only over the dark band, where it measures 4.58:1. |
| `#767676` on `#F8F8F8` | 4.28:1 | **FAILS.** The brand's own "text light" on the brand's own surface does not pass — so it is not used for small text. |
| `#016E82` on `bg-brand-teal/20` | 4.39:1 | **FAILED** on one button's hover. Fixed by inverting to a solid fill on hover (5.91:1), which also reads as a firmer target for a gloved finger. |
| `#8A6D13` on `#F5EDD6` | 4.20:1 | FAILS — rejected in favour of `#7A6011` (5.12:1 on the same ground). |

**Where usability beat brand, and it is said out loud:** the brand sheet's
headline teal `#01889F` and warm gold `#D4AF37` are the most recognisable part of
the identity, and **neither is used for any text or any text background** on this
page, because both fail. They appear only as decorative marks. The text carries
the brand through the *darker* members of the same two hue families. On a phone
in a plant room, legible beats on-swatch.

---

## 3. The webfont decision: nothing is fetched

**Chosen: system stacks only. No `<link>`, no CSS import, no self-hosting.**

The page already blocks on two third-party CDNs (Tailwind and Alpine). A Google
Fonts link would make three, plus the font files, and a font request that never
resolves is a blank page on a bad connection — the normal case in a riser.
Self-hosting needs an asset pipeline, and this page is standalone: no Vite entry,
no build step.

Poppins and Verdana Pro are **named first**, so office machines that already have
them (from the RAMS Word pipeline) get the real faces at zero network cost.

**What the fallback actually looks like:** the heading stack is
`Verdana, 'Verdana Pro', 'DejaVu Sans', 'Trebuchet MS', 'Segoe UI', sans-serif`.
**Verdana ships on Windows, macOS and iOS**, so on almost every device an
engineer carries the heading face is *the real brand face*, fetched from disk.
Android falls back to DejaVu Sans / Roboto — a close humanist match, wide and
legible at small sizes, which is the property that matters here. The body stack
degrades to the platform UI font (`Segoe UI` / `-apple-system` / Roboto), all of
which are designed for small-screen legibility. The fallback is acceptable on its
own; nothing depends on a download.

`SurveyLinkBrandingTest` bans `fonts.googleapis.com`, `fonts.gstatic.com`,
`@font-face` and CSS imports from the rendered page, so the decision cannot be
casually reversed.

---

## 4. No decorative colour on status-bearing surfaces — confirmed, by diff

The user's standing complaint is premature colour: *"Tables need to not be
coloured until install info submited it complete (green) / not done (red) / part
completre (amber with reason why)."*

A census of every status-family utility (`amber`, `rose`, `red`, `emerald`,
`green`, `yellow`, `orange`) in the view, diffed against `HEAD`, has **exactly one
difference** — a **removal**:

```
- 2 bg-amber-600
```

Those were `hover:bg-amber-600` on the two gold buttons: Tailwind *orange*, not a
status and not the brand. Nothing was added to any status family. The branded
elements are all chrome — the header band, the gold hairline, the page surface,
section headings, buttons, focus rings.

The one new mark on a content element is the **teal section spine**, and it is
held to being *structure* by assertion: its count is **identical across every
state**, so it cannot be carrying progress. A mark that meant status could not
pass that test.

---

## 5. States rendered — five and four, not one

The seven defects found in the three weeks before this task were every one of
them an assertion that rendered a single state. So:

**The survey link — 5 states**, each a real rendered page, and every branding
assertion runs against all five:

| state | how it is built |
|---|---|
| not started | two rooms, none completed |
| partly done | one of two rooms completed |
| complete | both rooms completed |
| submitted / locked | `submitted_at` set → `isLockedForEngineer()` true, the read-only render path |
| roomless | no rooms at all — the state that 500'd in 260925-d51 |

**The spaces step — 4 tick states plus two shapes:**

| state | assertion |
|---|---|
| all ticked | untouched step 3 renders 18 of 18 |
| none ticked | after `spaces-none`: **18 boxes, 0 ticked** — not zero boxes |
| some ticked | one ticked survives a re-render, and the counter reads `1 of 18 ticked.` |
| no spaces | empty state shown, and **no bulk buttons** |
| one space | one box, **no bulk buttons** (nothing to bulk-change) |
| two spaces | bulk buttons present, labelled `Tick all 2` |

Tick counts are parsed **from the rendered markup**, not inferred from the
session — a test that only checked `assertSessionHasInput` would have passed for
a view that ignored `old()` entirely.

---

## 6. Deviations and things found on the way

**Three defects were introduced by this work and caught by its own tests.** All
three are the same family — *a comment is not inert* — and all three are now
recorded in the file so the next author does not repeat them:

1. **`@vite` written inside a CSS comment 500'd the page.** Blade compiles an
   at-directive **wherever it appears**, including inside a JS or CSS comment.
   Exactly the family of the known trap where `@php` inside a Blade `{{-- --}}`
   comment still compiles. Fixed by never writing a literal at-directive here.
2. **The palette rationale, written as a JS comment, shipped the superseded
   hexes to the browser** and failed the "old palette cannot return" assertion.
   Moved into a Blade comment, which is stripped at compile — which also took
   **~3.4 KB off the download** on a page used on mobile data.
3. **The new classes were first prefixed `cav-`, which is the *cockpit's*
   namespace.** `FlagOffBehaviourUnchangedTest::assertNoCockpitFootprint()` bans
   it from the public token pages and went red, correctly. Renamed to `sv-`.
   Then the *explanation* of that rename, written in a shipped CSS comment,
   quoted the banned marker verbatim and failed the same test again.

**Two observations, deliberately not acted on** (out of scope, logged not fixed):

- **`resources/views/public-survey/show.blade.php` is 127 KB of unreachable
  legacy.** Its only controller method, `PublicSurveyController@show`, has **no
  GET route**. The live engineer link is `routes/web.php:87` →
  `SurveyController@show` → `surveys/show.blade.php`, which is what this task
  branded. Worth deleting in its own task; a 127 KB near-duplicate of a page
  under active change is a standing trap for exactly the "I edited it and nothing
  changed" confusion that prompted this request.
- The old near-miss teal `#178A95` is still hardcoded in
  `components/dashboard/upload-card.blade.php` and
  `components/install-task/photo-upload.blade.php`. Those render on
  *authenticated* pages, not the survey link, so retoning them is a separate
  decision about the app's own palette and was left alone.

---

## Gates

| gate | result |
|---|---|
| `tests/Feature/Cockpit` | **402 passed** (7917 assertions), 151.52s — 392 baseline + 10 new |
| `tests/Unit/Cockpit` | **114 passed** (1203 assertions), 12.71s |
| cockpit total | **516 / 0** (baseline was 506) |
| `tests/Feature/Worksheets` | **263 passed** (2317 assertions), 39.64s |
| `-Filter Survey` | **248 passed** (1271 assertions), 54.30s |
| `tests/Feature/Documents` | **18 passed** (142 assertions), 5.29s |
| D-06 baseline | **159 passed, 0 failed**, 2 skipped (known ext-imagick) — gate is `>= 159 AND 0 failed` |
| three sha256 pins | **all three match `4abd2b24`** |
| `worksheets/public-show.blade.php` | **byte-identical to HEAD** |
| build required | **none** — the page is standalone, no Vite entry, no pinned file touched |

Every run was a foreground invocation of `gate-46.ps1` with real multi-second
durations, redirected to a file and never piped; none was a stalled run reporting
a false exit 0.

## Fence counts — unchanged

`FORBIDDEN_MARKUP` **2**, `DEFERRED_AFFORDANCES` **21**,
`BANNED_HANDLER_ATTRIBUTES` **9**, `WRITE_SURFACE_TABLES` **13**. The select-all
lifted nothing: it is a form POST on an existing control name.
