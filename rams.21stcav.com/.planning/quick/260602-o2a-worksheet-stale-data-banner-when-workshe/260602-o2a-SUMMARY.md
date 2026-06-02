---
quick_id: 260602-o2a
type: execute-summary
date: 2026-06-02
duration: ~35min
status: complete
commits:
  - 0df6ebf  # test(260602-o2a): RED — failing isStale tests
  - ffd59ae  # feat(260602-o2a): GREEN — Worksheet::isStale + staleSince accessors
  - 72f1de0  # feat(260602-o2a): banner partial + 3 admin mount points
  - dbde92f  # feat(260602-o2a): public engineer link banner
  - 88586a9  # test(260602-o2a): feature tests for banner + regen disappearance + canary
files_created:
  - app/Models/Worksheet.php  # +83 lines (Carbon import + isStale + staleSince + docblock)
  - resources/views/worksheets/_stale-banner.blade.php  # NEW, 65 lines
  - tests/Unit/Models/WorksheetIsStaleTest.php  # NEW, 199 lines
  - tests/Feature/Worksheets/WorksheetStaleBannerTest.php  # NEW, 155 lines
files_modified:
  - resources/views/worksheets/show.blade.php  # +4 lines (1 @include after page-header)
  - resources/views/worksheets/index.blade.php  # +4 lines (1 @include inside Project <td>)
  - resources/views/projects/show.blade.php  # +2 lines (1 @include in worksheet row)
  - resources/views/worksheets/public-show.blade.php  # +7 lines (1 @include inside @else)
tests:
  unit:
    file: tests/Unit/Models/WorksheetIsStaleTest.php
    count: 9
    assertions: 11
    status: GREEN
  feature:
    file: tests/Feature/Worksheets/WorksheetStaleBannerTest.php
    count: 5
    assertions: 13
    status: GREEN
  total_new: 14
  total_new_assertions: 24
  broader_suite:
    filter: "WorksheetStaleBanner|WorksheetShowViewParity|WorksheetCategorySummary|WorksheetCompletionNotification|WorksheetIsStale|PublicWorksheetHeaderContact"
    result: 29/29 GREEN (66 assertions)
canaries:
  - test: RamsRenderRegression D-12 byte-equivalence
    status: GREEN 3/3 (9 assertions)
deviations:
  - rule: 2
    type: defensive
    description: "Used `$updatedAt instanceof Carbon ? ... : ($updatedAt ? Carbon::parse($updatedAt) : null)` in staleSince() because the eager-loaded `updated_at` is always a Carbon (datetime cast on parent::$timestamps) but defensive parse keeps the return-type contract `?Carbon` strictly honest even if a future code path supplies a string."
---

# Quick Task 260602-o2a — Worksheet Stale-Data Banner

## What Shipped

A model accessor (`Worksheet::isStale()`) + reusable Blade partial (`_stale-banner.blade.php`) that surfaces when a worksheet's snapshot has drifted out of date relative to its source (the project's latest package). Banner renders in 4 places — 3 admin views (full banner with Regenerate button on show, compact pill on index + project rows) and 1 public engineer link (informational only, no button).

## Truth Table — `Worksheet::isStale()`

| Scenario                                                              | Returns |
| --------------------------------------------------------------------- | ------- |
| `status=draft|final` AND `package.updated_at > generated_data.generated_at` | **true** |
| `status=draft|final` AND package not edited since snapshot            | false   |
| `status=failed`                                                       | false   |
| `status=pending`                                                      | false   |
| `status=generating`                                                   | false   |
| `project_id` is NULL                                                  | false   |
| Project has no `latestPackage`                                        | false   |
| `generated_data` is NULL                                              | false   |
| `generated_data` array missing `'generated_at'` key                   | false   |

Comparison: `Carbon::parse($worksheet->generated_data['generated_at'])->lt($worksheet->project->latestPackage->updated_at)`.

Defensive `loadMissing('project.latestPackage')` at the top so call sites don't need to eager-load.

## `staleSince()`

Returns Carbon of source package `updated_at` when stale, else null. Used by banner copy: `Project data was updated {{ $staleAgo }}`.

## Mount Points

| File                                                  | Variant   | Location                                                |
| ----------------------------------------------------- | --------- | ------------------------------------------------------- |
| `resources/views/worksheets/show.blade.php`           | admin     | After page-header `</div>`, above content sections      |
| `resources/views/worksheets/index.blade.php`          | pill      | Inside Project `<td>`, below project ref subtitle      |
| `resources/views/projects/show.blade.php`             | pill      | Inside worksheet row's project-name `<td>`             |
| `resources/views/worksheets/public-show.blade.php`    | public    | Inside `@else` branch, above engineer reference drawer |

`grep -rc "_stale-banner" resources/views/` confirms exactly 4 mount sites + 1 partial definition.

## Variant Differences

- **admin** — Tailwind `bg-amber-50 border border-amber-300 text-amber-900` banner with right-aligned Regenerate `<form>` posting to `worksheets.retry-generation` using the existing `data-confirm` modal pattern (260504-m2k).
- **public** — Inline RGBA styles (palette lifted from `.room-drawer.amber` lines 315/337/345; public view does not load Tailwind). Informational copy: "Project data has been updated since this worksheet was generated. Ask the office to refresh it before signing off." **No form, no button.**
- **pill** — Compact `bg-amber-100 text-amber-800` span (mirrors Plan 20-01 boundPdfStaleBadge precedent exactly).

## Authorization

Unchanged. Per 260525-pyu/s8b shared-workspace decision, any authenticated user can hit the existing `worksheets.retry-generation` route. The banner's form inherits this — no new policy, no new gate.

## Surveys NOT Touched

Per plan: `SiteSurvey` + `PublicSurveyController` have ZERO references to `snapshot|generated_data|extracted_data` — surveys are live-read from the project/survey model. A stale banner is structurally not applicable. Did not touch `resources/views/surveys/show.blade.php`.

## Test Results

### New tests (this task)

- **WorksheetIsStaleTest** — 9 unit tests / 11 assertions GREEN (one per row of truth table)
- **WorksheetStaleBannerTest** — 5 feature tests / 13 assertions GREEN
  1. Admin show — stale → banner + Regenerate button visible
  2. Admin show — fresh → banner not visible
  3. Public engineer link — stale → informational banner, no retry-generation form
  4. Post-regen disappearance — bump `generated_at`, re-GET, banner gone
  5. Regression canary — fresh worksheet show still renders 200 + page title

### Broader regression suite

Filter `WorksheetStaleBanner|WorksheetShowViewParity|WorksheetCategorySummary|WorksheetCompletionNotification|WorksheetIsStale|PublicWorksheetHeaderContact`: **29/29 GREEN (66 assertions)**.

### Pre-existing failures (NOT caused by this task)

`PublicWorksheetSignoffTest` has 2 pre-existing failures (`sign persists worksheet signoff with correct fields…` and `resubmit appends a second signoff and does not overwrite…`). Verified by checking out the pre-260602-o2a commit (`0dd6071`) — same 2 tests fail with the same errors on the unmodified codebase. **These are unrelated to the stale-banner work** (sign-off persistence, not stale-data detection or view rendering). Out of scope per the deviation rules (Pre-existing failures in unrelated files are not auto-fixed; logged here for visibility).

### RamsRenderRegression D-12 byte-equivalence canary

**GREEN 3/3 (9 assertions)** — `pdf_byte_identical_across_two_renders_{manual_form,quote_import,survey_derived}_fixture` all pass. This task touched only one model accessor + 4 Blade view mount points; zero touch to any RAMS/O&M/Schematic/Drawing generator surface, so byte-equivalence is preserved by inspection AND by canary.

## Commits

```
0df6ebf  test(260602-o2a): add failing tests for Worksheet::isStale() truth table  (RED gate)
ffd59ae  feat(260602-o2a): add Worksheet::isStale() + staleSince() accessors  (GREEN gate)
72f1de0  feat(260602-o2a): add stale-data banner partial + mount on 3 admin views
dbde92f  feat(260602-o2a): add stale-data banner to public engineer worksheet link
88586a9  test(260602-o2a): add feature tests for stale banner + regen disappearance + regression canary
```

## Deviations

**Rule 2 (defensive) — staleSince() return-type belt-and-braces**

`staleSince()` already calls `isStale()` which calls `loadMissing('project.latestPackage')` — so by the time `staleSince()` returns true and we dereference `$this->project?->latestPackage?->updated_at`, the value WILL be a Carbon instance (datetime cast inherited from `Model::$timestamps`). Added an `instanceof Carbon` check + a defensive `Carbon::parse()` branch anyway, so the declared return type `?Carbon` is strictly honest even if a future code path supplies a string-shaped `updated_at`. Zero behavioural impact on the planned truth table. Documented in the model docblock.

**No other deviations.** Plan executed exactly as written — zero schema changes, zero new routes, zero new auth gates, zero touch to surveys, zero touch to worksheet generator.

## Self-Check: PASSED

- All 5 commits exist on branch `feat/worksheet-classifier-universal`
- All 4 modified files exist and contain the planned changes
- All 4 new files exist (`_stale-banner.blade.php`, `WorksheetIsStaleTest.php`, `WorksheetStaleBannerTest.php`, this SUMMARY.md)
- All new unit + feature tests pass (14/14 / 24 assertions)
- RamsRenderRegression D-12 canary still GREEN 3/3
- `grep -rc "_stale-banner" resources/views/` returns 4 files (1 partial + 3 admin includes + 1 public include = 5 total `@include`/partial-definition occurrences across 4 files)
