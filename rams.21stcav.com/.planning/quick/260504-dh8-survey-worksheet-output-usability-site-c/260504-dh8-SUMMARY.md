---
quick_id: 260504-dh8
mode: quick
type: summary
status: complete
completed_at: 2026-05-04T09:01:38Z
duration_minutes: 11
commits:
  - 472a016: feat(quick-260504-dh8) restructure populated site-survey PDF with 3-group h3 layout
  - 3bec680: feat(quick-260504-dh8) add Site Conditions h3 to blank room body + Installation Reference DOCX section
  - 360d763: feat(quick-260504-dh8) add Survey Reference (teal) drawer to public worksheet engineer view
files_modified:
  - resources/views/pdf/site-survey/summary.blade.php
  - resources/views/pdf/site-survey/_blank-room-body.blade.php
  - app/Services/WorksheetDocxService.php
  - resources/views/worksheets/public-show.blade.php
file_count: 4
line_delta: +647 / -6
deviations: []
---

# Quick Task 260504-dh8 — Survey + Worksheet Output Usability Fixes

Three usability fixes to survey + worksheet output, surfaced during Phase 20 UAT. Pure additive — no schema, no controllers, no routes touched.

---

## What changed

### 1. `resources/views/pdf/site-survey/summary.blade.php` (+206 / -4)

Restructured the per-room foreach (line 29-70) from one flat `<table>` of all fields into THREE labeled h3 groups:

- **Group 1 — Site Conditions:** dimensions, ceiling/wall/floor, power/network, cabling, hazard/other notes (Room Ref row dropped — already in h2 title; Existing AV Equipment moved into AV Requirements group).
- **Group 2 — AV Requirements:** Planned AV Works rendered via `H::narrativeAsTickList()` (replaces nl2br wall-of-text); Existing AV Equipment second row. Both rows guarded so empty rooms render no heading. `H::stripLeadingDuplicate()` applied to `av_requirements` first (matches field-form.blade.php pattern at line 39).
- **Group 3 — Engineer Findings:** copied verbatim from `rams.blade.php` lines 903-1032, with `$ef['x']` swapped to `$room->x` direct attribute access (Eloquent casts auto-decode arrays). Each of the 7 sub-sections (mounting heights, WAH methods, cable routes, wall construction & prep, brackets, table info, floor box info) keeps its own `@if` guard. The h3 itself only renders when at least one EF attribute is populated. Photos block at end is unchanged.

### 2. `resources/views/pdf/site-survey/_blank-room-body.blade.php` (+1 / -0)

Inserted exactly one line `<h3>Site Conditions</h3>` between the `@php($line = …)` line and the first `<table>` tag. Visually consistent with the existing 7 engineer-feedback h3s already in the file. No other changes.

### 3. `app/Services/WorksheetDocxService.php` (+219 / -2)

- Added `use App\Models\SiteSurvey;` import.
- `build()` now calls a new `loadEngineerFeedbackByRoom($worksheet)` helper at the top to load engineer-feedback once, keyed by lowercase room_name. Defensive: missing `project_id` / missing survey returns `[]`, downstream becomes a no-op.
- `build()`'s room foreach computes `$ef` via lowercase room-name lookup and passes it into `buildRoom()`.
- `buildRoom()` signature gains a new optional 5th parameter `array $ef = []` (backwards-compat).
- `buildRoom()` invokes `$this->renderInstallationReference($section, $ef)` between the existing CABLE ROUTE NOTES block and the existing POWER & NETWORK CHECK block.
- New private `renderInstallationReference($section, array $ef)` renders the 7 EF sub-sections via PhpWord (using existing TEAL/DARK/MID/GREY/WHITE constants and existing `heading()` / `t()` helpers). Pure no-op when `$ef` is empty or every sub-key is empty — legacy worksheets pre-260503-rgg produce identical output.

### 4. `resources/views/worksheets/public-show.blade.php` (+221 / -0)

- Page-level `@php` block at line 442 extended with one-off `SiteSurvey` lookup keyed by lowercase room name. Defensive `class_exists(\App\Models\SiteSurvey::class)` guard mirrors the `DeviceLabelPhoto` precedent at line 505. Missing class / missing project / missing survey → `$efByRoom = []`, drawer never renders. Label maps (`methodLabels`, `wallConstructionLabels`, `cableCategoryLabels`) declared once for the page.
- Per-room `@php` block at line 458 extended with `$efKey` / `$ef` / `$hasEF` / `$efItemCount` lookup.
- New `<details class="room-drawer teal">` block inserted between the room `<summary>` and the existing AV Works (teal), Kit List (gold), and Install Steps (amber) drawers — only rendered when `$hasEF` is true.
- Drawer body renders 7 read-only sub-section cards (mounting heights, WAH methods, cable routes, wall construction & prep, brackets, table info, floor box info) with inline styles only. Reuses existing `.room-drawer` / `.room-drawer-body` / `.actions` classes — **NO new CSS classes added**.
- Drawer summary shows `📋 Survey Reference (N captured)` where N = count of populated sub-sections.

---

## File footprint audit

```
$ git diff --stat HEAD~3 HEAD -- {target paths}
 app/Services/WorksheetDocxService.php          | 221 ++++++++-
 .../pdf/site-survey/_blank-room-body.blade.php |   1 +
 .../views/pdf/site-survey/summary.blade.php    | 210 +++++++-
 .../views/worksheets/public-show.blade.php     | 221 ++++++++++
 4 files changed, 647 insertions(+), 6 deletions(-)
```

Result: **EXACTLY 4 files** — matches the constraint.

## Forbidden-paths audit

```
$ git diff --stat HEAD~3 HEAD -- app/Models/ app/Core/ routes/ database/ \
    app/Http/Controllers/ resources/views/pdf/rams.blade.php \
    resources/views/pdf/drawings/ resources/views/site-survey/ \
    resources/views/pdf/site-survey/_styles.blade.php \
    resources/views/pdf/site-survey/field-form.blade.php config/
```

Result: **EMPTY** — no controllers, no models, no routes, no migrations, no styles, no field-form.blade.php, and rams.blade.php is unchanged (reference only).

---

## Render smoke tests

All 4 smoke tests passed — full results captured during execution:

### Test 1: `summary.blade.php` (no EF data — regression baseline)

```
BYTES: 19,877  (> 5KB ✓)
Site Conditions       → YES
AV Requirements       → YES
Engineer Findings     → NO   (regression-safe: no h3 when no EF data)
```

### Test 2: `summary.blade.php` (synthetic EF data on first room)

```
BYTES: 9,045
Site Conditions       → YES
AV Requirements       → YES
Engineer Findings     → YES
Installation heights  → YES
Working at height     → YES
Cable routes planned  → YES
Wall construction     → YES
Brackets to source    → YES
Table:                → YES
Floor box:            → YES
```

All 7 EF sub-sections render verbatim from the rams.blade.php-cloned block.

### Test 3: `field-form.blade.php` (blank room body — Site Conditions h3 added)

```
BYTES: 74,572  (> 50KB ✓)
Site Conditions       → YES   (NEW — added by this task)
Mounting Heights      → YES   (existing)
Working at Height     → YES   (existing)
Cable Routes          → YES   (existing)
Wall Construction     → YES   (existing)
Brackets Required     → YES   (existing)
```

The new h3 sits visually consistent with the 7 existing EF h3s.

### Test 4: `public-show.blade.php` (no EF — regression baseline)

```
BYTES: 102,011
Survey Reference      → NO   (regression-safe: no drawer when no EF data)
```

### Test 5: `public-show.blade.php` (synthetic EF — drawer renders)

```
BYTES: 107,532
Survey Reference      → YES
📋 Survey Reference   → YES
room-drawer teal      → YES
Mounting heights      → YES
Cable routes planned  → YES
Wall construction     → YES
Brackets required     → YES
Table info            → YES
Floor box info        → YES
Working at height     → YES
```

All 7 sub-section cards render with the teal drawer chrome.

### Test 6: `WorksheetDocxService` DOCX regeneration with EF data

```
DOCX size: 18,453 bytes
INSTALLATION REFERENCE        → YES
SURVEYOR                      → YES   (in heading "INSTALLATION REFERENCE — SURVEYOR'S ENGINEER FINDINGS")
Mounting heights              → YES
Working at height             → YES
Cable routes planned          → YES
Wall construction & prep      → YES
Brackets required             → YES
Table info                    → YES
Floor box info                → YES
Chief XTM1U                   → YES   (synthetic data round-trips into DOCX)
```

DOCX validation (XML well-formedness check via `validateDocx()`) passes — file opens cleanly.

---

## Regression smoke tests (defensive paths)

| Scenario | Expected | Observed |
|----------|----------|----------|
| Survey with NO engineer-feedback data → summary.blade.php | No "Engineer Findings" h3 | ✓ NO |
| Worksheet with NO matching SiteSurvey → public-show.blade.php | No "Survey Reference" drawer | ✓ NO |
| Worksheet whose project has no SiteSurvey → DOCX rebuild | No "INSTALLATION REFERENCE" heading | ✓ NO (default empty `$ef` triggers `renderInstallationReference()` no-op) |

---

## Manual verification

- `php -l` clean on all 4 modified files.
- `php artisan view:cache` succeeds (Blade compiles clean).
- `git diff --stat HEAD~3 HEAD -- {forbidden paths}` returns empty.
- File footprint exactly 4 files.
- No new CSS classes added in public-show.blade.php (verified via inline-style approach for sub-section eyebrow text).

---

## Files to upload to live

Per local-edit-then-upload deployment workflow:

- `resources/views/pdf/site-survey/summary.blade.php`
- `resources/views/pdf/site-survey/_blank-room-body.blade.php`
- `app/Services/WorksheetDocxService.php`
- `resources/views/worksheets/public-show.blade.php`

## Commands to run on live

```
php artisan view:clear
```

No migrations, no composer, no npm. Pure presentation/service-layer change.

---

## Deviations from plan

**None** — plan executed exactly as written.

All 3 tasks landed atomically with the file footprint, forbidden-paths, and behavioural acceptance criteria from the plan. Engineer-feedback rendering on summary.blade.php was copied verbatim from rams.blade.php lines 903-1032 with the documented `$ef['x']` → `$room->x` substitution. No bugs encountered, no architectural decisions surfaced, no fix-attempt limit reached.

## Self-Check: PASSED

- Files exist: 4 modified + 1 SUMMARY.md (all FOUND)
- Commits exist: 472a016, 3bec680, 360d763 (all FOUND on `feat/worksheet-classifier-universal`)
