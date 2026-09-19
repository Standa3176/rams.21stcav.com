---
phase: 45-visit-model-read-only-cockpit
plan: 07
subsystem: cockpit
tags: [blade, presenter, read-only, accessibility, d-02, d-04, fence]
requires: [45-02, 45-04, 45-05, 45-06]
provides:
  - "app/Support/Cockpit/CockpitSectionPresenter — nine drawer states from existing accessors only"
  - "x-cockpit.drawer / visit-row / doc-row / lifecycle-row — the spine's row vocabulary"
  - "resources/views/projects/cockpit.blade.php — nine drawers, all closed at rest"
  - "CockpitSpineTest — at-rest disclosure of the reconstructed and superseded treatments"
  - "CockpitReadOnlyFenceTest — the fence, scoped through a both-ends-bracketed region helper"
affects: [45-08, 46, 47, 48, 51]
tech-stack:
  added: []
  patterns:
    - "nine sections == ProjectDeliverable::ALL_KEYS, the codebase's own canonical vocabulary"
    - "qualifier disclosure reaches the CLOSED summary count slot, not just the open drawer body"
    - "fence region helper brackets the subtree at BOTH ends so it cannot pass vacuously"
    - "criterion 5 asserted by row-count invariance, not by absence of buttons"
key-files:
  created:
    - app/Support/Cockpit/CockpitSectionPresenter.php
    - resources/views/components/cockpit/drawer.blade.php
    - resources/views/components/cockpit/visit-row.blade.php
    - resources/views/components/cockpit/doc-row.blade.php
    - resources/views/components/cockpit/lifecycle-row.blade.php
    - resources/views/projects/_cockpit-drawer.blade.php
    - tests/Feature/Cockpit/CockpitSpineTest.php
    - tests/Feature/Cockpit/CockpitReadOnlyFenceTest.php
  modified:
    - app/Http/Controllers/ProjectCockpitController.php
    - app/Models/Project.php
    - resources/views/projects/cockpit.blade.php
decisions:
  - "The nine deliverable sections are ProjectDeliverable::ALL_KEYS — the model's own docblock calls that list 'the single canonical nine-item deliverable vocabulary', so the cockpit and the deliverables screen now name the same nine things"
  - "Every one of the six Visit types maps to exactly one drawer, so no visit type can ever be rendered nowhere: commissioning visits sit in Programme and commissioning, programming visits in Programming"
  - "Project::visits() was added — no such relation existed, and without it the spine could not be eager-loaded"
  - "The fence's region helper carries BOTH brackets; proven by deliberately breaking it three ways and watching it go red"
metrics:
  duration: ~70 min
  completed: 2026-09-19
---

# Phase 45 Plan 07: The Drawer Spine Summary

Nine section drawers, every one closed at rest, with the visit rows inside them and the two
qualifier treatments that make the record honest — reconstructed (an inference, dashed) and
superseded (a recorded fact about the paperwork, solid) — plus a read-only fence that has been
watched failing.

## What Was Built

| Task | What | Commit |
|------|------|--------|
| 1 | `CockpitSectionPresenter`, controller wiring, `Project::visits()` | `8ef58ecf` |
| 2 | `drawer` / `visit-row` / `doc-row` / `lifecycle-row` + the filled spine | `bb77de0d` |
| 3 | `CockpitSpineTest` + `CockpitReadOnlyFenceTest` | `64b40ebf` |

### Task 1 — the presenter

`app/Support/Cockpit/CockpitSectionPresenter.php` is a `final` class with two public methods:
`sections(Project): Collection` and `isEmpty(Project): bool`. Its class docblock records, at the top,
that it **deliberately does not extend, subclass or modify `ProjectHealthService`** and why — that
service is the dashboard's derivation, and changing it changes what the dashboard renders with the
cockpit flag **off**, a criterion-4 violation. Every per-drawer value comes from an accessor that
already existed:

| Drawer | Derived from |
|--------|--------------|
| Site survey | `SiteSurvey::isCompleted()` / `isDraft()` |
| First fix and install | `Worksheet::isSigned()` → `isReadyForSignoff()` → `hasEngineerActivity()` |
| Programme and commissioning | `InstallProgramme::statusLabel()` / `isDraft()` / `isActive()` / `commissioningSignoff()->exists()` |
| Snagging | `Project::snaggingSignoffs()` |
| RAMS / Drawings / O&M manual / Cable schedule | the existing `HasMany` relations, plus `Project::deliverableState($key)` for the not-required state |
| Programming | **no derivation exists** — a box, not a light, and unticked only |

**Which nine.** The plan says "the nine deliverable sections" without enumerating them.
`ProjectDeliverable.php:14` settles it: `ALL_KEYS` is documented as *"the single canonical nine-item
deliverable vocabulary"*. Using that list rather than inventing one keeps the cockpit and the
deliverables selection screen talking about the same nine things. Group assignment: four in
**Visits**, four in **Documents**, and `programming` in **Reference**, which is honest — it is
neither a visit the system can evidence nor a document, and it is the one section carrying a
hand-ticked box.

**Every Visit type reaches a drawer.** `site_survey` → Site survey; `first_fix` and `install` →
First fix and install; `commissioning` → Programme and commissioning; `snag` → Snagging;
`programming` → Programming. No type can be rendered nowhere, which matters because D-04's whole
point is that a visit is never hidden.

**Count-slot disclosure.** `visitDisclosure()` builds `3 visits · 2 reconstructed`,
`2 visits · 1 superseded`, and the singular form that drops the numeral, `1 visit · reconstructed`.
Empty is `none yet`. Neither qualifier feeds the pip or the status text: gold means *someone must
act*, and neither can be actioned on a read-only page.

### Task 2 — the row vocabulary and the filled spine

`drawer.blade.php` is a native `<details>`/`<summary>` with **zero JavaScript**. It never ships
`open` — a regex guard in the spine test checks every rendered `<details>` tag. `aria-expanded` is
not hand-written (the element announces itself), there is no `tabindex` and no `pointer-events`
trick, and the arrow is `aria-hidden`.

`visit-row.blade.php` carries both treatments:

- **Reconstructed (D-02), all four, none optional** — the `Reconstructed` chip first in the strip
  (`cav-chip--reconstructed`, 1px dashed); the 2px dashed left edge (`cav-visit--reconstructed`);
  the verbatim sub-line, *different* for worksheet-derived ("Type inferred from a signed worksheet
  — the work actually done was not recorded.") and survey-derived ("Built from the existing survey
  record, not captured as a visit."); and, for **worksheet-derived only**, the dotted-underlined
  type word carrying `aria-describedby` pointing at that sub-line. A survey-derived visit renders
  no `cav-inferred-type` at all — its type is not in doubt, only its provenance, and the spine test
  asserts that absence.
- **Superseded (D-04)** — the row stays in date order in its own drawer, never hidden and never
  behind a toggle; a `Superseded` chip with a solid border; `line-through` scoped to the
  deliverable name via `cav-superseded-name` and nothing else; the verbatim sub-line
  "Superseded {d M Y} — the visit still happened."; and **no opacity anywhere** — the spine test
  asserts the string `opacity` does not appear in the subtree at all.

A row may carry both chips, Reconstructed first (asserted by string position). A visit whose source
has been force-deleted still renders, with the `Record unavailable` chip.

`doc-row.blade.php` renders the sketch's O&M full-versus-mini `<select>` as **static text** of the
current selection. `lifecycle-row.blade.php` reuses the Done and Waiting disc shapes.

Dates render `02 Sep` in rows and `d M Y` in the superseded sub-line. British spelling, no
exclamation marks, and the presenter's `label()` never emits the word "Error" — a failed document
status reads "Could not be produced".

### Task 3 — the two test files

**`CockpitSpineTest`** — 15 tests. Nine sections render (exactly nine `<details>`); a not-required
section still renders, with the chip and "Marked not required at import."; no drawer ships `open`;
the reconstructed and superseded disclosures appear **inside the `<summary>` markup**, extracted by
a `summariesOnly()` helper, so they are proven visible with every drawer shut; a superseded row is
present and **not last**; neither qualifier produces `cav-pip--attn` or `cav-status--attn`;
programming renders the unticked box and "Not marked" with the ticked markup absent; an empty
project renders nine drawers with eight waiting pips and the page empty-state copy; a force-deleted
source still renders its visit.

**`CockpitReadOnlyFenceTest`** — 7 tests, all routed through `cockpitRegion()`.

## The fence — and the proof that it can fail

`cockpitRegion(string $html): string` extracts the `cav-cockpit` subtree with `DOMXPath` (the
technique 45-06 established) and, **before returning**, asserts four things: the node was found,
the region is non-empty, it contains the masthead project name (**top bracket**), and it contains
"Open the full project page" (**bottom bracket** — per 45-06 the last element in the shell). Both
brackets live inside the helper. The helper's docblock records the scope decision verbatim: the
shared layout's logout form, command-palette input, nav buttons and `@vite`/Alpine scripts are out
of scope by plan-time decision and are covered instead by 45-08's sha256 assertion.

**A guard nobody has watched fail is a guard nobody should trust.** So it was broken three ways:

| # | Break | Result |
|---|-------|--------|
| 1 | XPath changed to match a class that does not exist (`cav-cockpit-does-not-exist`) | **6 failed, 1 passed** — the vacuity guard `assertNotNull` fired on every scoped test. Without it all six would have passed green while examining nothing |
| 2 | Region truncated at `cav-page`, keeping the masthead | **6 failed, 1 passed** — the **bottom bracket** fired: *"The extracted region stops before the end of the page shell"*. This is the exact failure the top bracket alone would have missed, because the truncated region still contained the project name |
| 3 | `<button type="button">Book a visit</button>` inserted into every drawer body | **2 failed, 5 passed** — `no form control and no script` caught the `<button`, and `none of the fifteen deferred affordances appears` caught the copy |

All three breaks were reverted; `git status` reports `drawer.blade.php` and the test file clean,
and the suite is green again.

The one test that stayed green throughout is `test_the_fence_enumerates_the_whole_deferred_set`,
which is pure data (15 affordances, 6 markup forms, 5 tables) and correctly does not depend on
rendering.

**Row-count invariance (A1 / VIS-06 / criterion 5).** `visits`, `install_records`,
`install_programmes`, `site_surveys` and `worksheets` are enumerated as a data array; both a single
render and three consecutive renders leave every count identical. Absence of form controls proves
the page offers no way to write; this proves that *rendering it* writes nothing.

## Verification

```
Tests:    44 passed (347 assertions)
```
(`artisan test tests/Feature/Cockpit` — 22 from 45-06, 15 spine, 7 fence.)

D-06 behaviour gate, re-run character-identical from `45-BASELINE.md`:

```
Tests:    2 skipped, 159 passed (396 assertions)
Duration: 34.51s
```
Meets the gate `>= 159 passed AND 0 failed`. The two skips are the pre-existing `ext-imagick`
environmental skips named in the baseline.

- `php -l` over every file in `storage/framework/views` after `view:clear` → all parse, no output.
- `git diff --name-only 4abd2b24 --` over `ProjectHealthService.php`, `ProjectHealth.php`,
  `layouts/app.blade.php`, `resources/css/app.css`, `tailwind.config.js` and
  `LabourResourceClientSurfacePrivacyTest.php` → **empty**. All six protected files unchanged since
  the phase-start SHA.
- No `<details` in `drawer.blade.php` carries `open`.
- No Tailwind colour utility and no raw hex in any cockpit Blade file (45-06's
  `test_cockpit_components_carry_no_raw_hex_colour` still passes over the four new components).
- Every PHP command ran through the explicit Herd binary
  (`$USERPROFILE/.config/herd/bin/php84/php.exe`), never bare `php`, which is absent from the Bash
  PATH here and would have exited 0 while running nothing.

## Deviations from Plan

**1. [Rule 3 — Blocking] `Project::visits()` did not exist**
- **Found during:** Task 1
- **Issue:** `Visit` declares `belongsTo(Project)` and `InstallRecord` has a `visits()` HasMany, but
  `Project` had none. Without it the spine could not be eager-loaded and every drawer would have
  fired a lazy query.
- **Fix:** Added `Project::visits(): HasMany`, ordered by `scheduled_date` so date order — and
  therefore a superseded visit's position (D-04) — is the default, not something each caller
  re-derives.
- **Files modified:** `app/Models/Project.php`
- **Commit:** `8ef58ecf`

**2. [Rule 3 — Blocking] one partial not in the plan's file list**
- `resources/views/projects/_cockpit-drawer.blade.php` — the drawer-plus-body block, `_`-prefixed
  per `CLAUDE.md:169`. Repeating it three times in `cockpit.blade.php` would have been the
  alternative. It derives nothing; it renders the array the presenter built.
- **Commit:** `bb77de0d`

**3. Presentational: lifecycle "Drawn" reads "Not drawn" when empty**
- The first draft reused the generic `none yet`, which pushed the page's `none yet` count to ten and
  read oddly beside a disc. Changed to `Not drawn` / `Not sent`. Caught by a test, not by taste.

No architectural change was needed, so no Rule 4 checkpoint arose.

## Known Stubs

None. Every drawer renders from a real accessor or from a genuine absence of data. The programming
section's ticked branch remains deliberately unreachable — that is 45-06's recorded contract, not a
stub: nothing in this phase can store who ticked it or when, and rendering the tick would claim a
certainty that does not exist.

## Threat Flags

None. No new network endpoint, auth path, file access pattern or schema change. The only route is
45-06's existing GET, no new column or table was added, and every value reaches the page through
`{{ }}`, escaped by Blade. `{!! !!}` appears in no cockpit view.

## Notes for the Next Agent

- **The fence is scoped on purpose, and the scope decision is written into the test file.** If an
  assertion in `CockpitReadOnlyFenceTest` goes red, the answer is almost never to widen or narrow
  the region — it is that something in the cockpit changed. Read the docblock above
  `cockpitRegion()` before touching it.
- **Do not remove either bracket.** Break 2 above is the reason the bottom one exists: a truncated
  extraction passes the top bracket while examining nothing.
- Phase 46 adds the first write affordance. When it does, it must **delete** the matching entry from
  `DEFERRED_AFFORDANCES` deliberately and adjust `test_the_fence_enumerates_the_whole_deferred_set`
  — that is the anti-rot mechanism working, not an obstacle.
- A unique index on `visits(source_type, source_id)` means one source backs at most one visit. A
  test that reconstructs two visits from the same worksheet dies with a
  `UniqueConstraintViolationException`; create two sources.

## Self-Check: PASSED

All nine created files exist on disk and all three task commits resolve in `git log`.
