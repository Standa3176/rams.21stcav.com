---
quick_id: 260815-sup
slug: supply-only-category
date: 2026-08-15
status: complete
---

# Quick Task 260815-sup — "Hardware — supply only" category + bulk category change — Summary

## What shipped

A new equipment category, `hardware_supply_only` ("Hardware — supply only (no install)"), for client-owned kit 21CAV supplies but does not install (e.g. the "Digital Production Studio" 63-line room on package 161 — cameras, lenses, lighting the client already owns). It is included in O&M/asset-register output and excluded from RAMS, drawings, stencil coverage and site surveys — by construction, per the user's locked decision. A bulk category-change control was added to the equipment toolbar so a PM can re-tag a whole room in one action instead of 63 individual dropdowns.

## Tasks completed

**Task 1 — Canonical vocabulary** (`app/Services/Imports/EquipmentCategoryClassifier.php`)
- Added `hardware_supply_only` to `CATEGORIES` (now 9 values, was 8) — manual-selection only, no keyword rule added to the decision tree
- Added `OM_INCLUDED_CATEGORIES` const + `isOmIncludedCategory()` static helper, shared by Task 2's three call sites so a future category change is a one-place edit
- Fixed the stale "8 canonical" / "7 canonical" docblocks
- New unit tests: round-trip survival, canonical-list membership, a realistic camera/lens/lighting description still classifies as plain `hardware` (no keyword false-positive)

**Task 2 — O&M inclusion, and only O&M** (`app/Core/Modules/OMManual/OmManualGeneratorService.php`, `app/Services/MiniOmBuilderService.php`)
- Widened the three predicates (OmManualGeneratorService:845, MiniOmBuilderService:395/604 pre-edit line numbers) to delegate to `EquipmentCategoryClassifier::isOmIncludedCategory()`
- Left the 7 exclusion sites listed in PLAN.md untouched — `Project::hardwarePartNumbers()`, `Project::devicesWithStencils()`, `StencilsCoverageReportCommand`, `SiteSurveyController`, `PublicSurveyController`, and both `ProjectPackageReviewController` room-derivation sites all still use an exact `=== 'hardware'` match, so the new category is excluded from RAMS/drawings/surveys by construction — no change needed
- New feature test `ProjectPackageReviewSupplyOnlyCategoryTest` — the exclusion proof: a package with one `hardware` line and one `hardware_supply_only` line yields **both** in O&M (legacy-shape filter) and Mini O&M (`quotedAssetsForRoom`), but **only** the hardware line from `hardwarePartNumbers()`, `devicesWithStencils()`, `stencils:coverage-report`, and the site survey kit-by-area list

**Task 3 — Review-screen dropdown** (`resources/views/project-packages/review.blade.php`)
- Added the option to `$categoryOptions` (server-rendered dropdown) adjacent to `hardware`, and mirrored it in the JS `equipmentRowTemplate()` used when a PM adds a new row client-side
- Also added it to `$equipmentByCategory` so supply-only rows render in their own collapsible section instead of silently landing inside the Hardware section (an existing gap the `unknown` category already has — not fixed here, out of scope, but not repeated for the new value)
- New feature test proves the save round trip does not silently revert `hardware_supply_only` to `hardware` — the exact class of bug 260725-qw3 fixed for `service_contracts` / `customer_supplied`

**Task 4 — Bulk category change** (`resources/views/project-packages/review.blade.php`, `equipmentSection()`)
- Added a category `<select>` + Apply button to the existing bulk toolbar, beside Delete/Restore/Purge/Split
- `bulkSetCategory()` sets the chosen category on every selected row's dropdown and dispatches a native `change` event, which reuses the existing "move row when category changes" document-level listener to relocate each row into the correct tbody section for free — no separate DOM-move logic was needed
- Clears the selection afterwards, matching `bulkAction()`'s existing end-of-action behaviour
- `canBulk('category')` reads `this.selectedRowIds` first (inherited from the shared `canBulk()` early-return) before gating on `bulkCategoryValue !== ''`
- Uses `this.$root` throughout — never `this.$el` — per the 260815-ohw constraint; `EquipmentBulkSelectionTest`'s structural guards still pass unmodified

**Task 5 — Browser verification: NOT PERFORMED.** No browser/Playwright MCP tool was available in this session (only Read/Write/Edit/Bash/Grep/Glob). The select → Apply → persisted-category flow has not been visually confirmed in a real browser. A human should verify at `http://rams.21stcav.com.test/project-packages/1/review` (run `php artisan view:clear` first per the 260815-ohw post-mortem, since compiled Blade caching previously masked a fix) before/alongside deploying this change, the same way 260815-ohw's browser check is still pending.

## Deviations from Plan

None beyond a scope-preserving addition: also adding `hardware_supply_only` to `$equipmentByCategory` (Task 3) so the new category renders in its own section rather than silently bucketing into Hardware — the plan only mentioned the dropdown option, but leaving this out would have made the outer per-category `@foreach` render an always-empty "Hardware — supply only" section while real supply-only rows kept appearing inside Hardware, undermining the whole point of the new category. Classified as Rule 2 (missing essential functionality for the feature to actually work as intended).

## Verification

- Lint: `php -l` clean on all touched PHP/Blade files
- Tests: `php artisan test --filter="EquipmentBulkSelection|EquipmentGraveyard|EquipmentCategoryClassifier|ProjectPackageReview|OmManual|MiniOm"` — run in 5 batches (single combined run hit the tool timeout on this large filter set):
  - `EquipmentBulkSelection`: 3 passed
  - `EquipmentGraveyard|EquipmentCategoryClassifier`: 36 passed (103 assertions)
  - `ProjectPackageReview`: 10 passed (39 assertions)
  - `OmManual`: 63 passed (258 assertions)
  - `MiniOm`: 3 passed
  - Total: 115 passed, 0 failed
- No migration run (none required)
- No full-repo `php artisan test` run (scoped `--filter` only, per constraints)
- Known pre-existing failures (2 `DrawIoSpikeController` constructor-arity tests) not touched, not re-verified — out of scope

## Known Stubs

None.

## Threat Flags

None. This task only widens an existing inclusion predicate for O&M output (read-only classification), adds a manual-selection-only dropdown value, and adds a bulk client-side DOM operation scoped to already-authorized equipment rows on an already-authorized review page. No new endpoints, no new auth paths, no new file access, no schema change.

## Self-Check: PASSED

Files verified to exist:
- `app/Services/Imports/EquipmentCategoryClassifier.php` — FOUND
- `app/Core/Modules/OMManual/OmManualGeneratorService.php` — FOUND
- `app/Services/MiniOmBuilderService.php` — FOUND
- `resources/views/project-packages/review.blade.php` — FOUND
- `tests/Unit/Services/Imports/EquipmentCategoryClassifierTest.php` — FOUND
- `tests/Feature/ProjectPackages/ProjectPackageReviewSupplyOnlyCategoryTest.php` — FOUND

Commits verified in `git log --oneline`:
- `50703b4` feat(project-packages): add hardware_supply_only category to classifier — FOUND
- `fccb36c` feat(project-packages): include hardware_supply_only in O&M output only — FOUND
- `09331e9` feat(project-packages): add supply-only category dropdown + bulk category apply — FOUND

## 🚨 Files to upload to live

- `app/Services/Imports/EquipmentCategoryClassifier.php`
- `app/Core/Modules/OMManual/OmManualGeneratorService.php`
- `app/Services/MiniOmBuilderService.php`
- `resources/views/project-packages/review.blade.php`

**No migration.** After upload, run `php artisan optimize:clear` (Blade view cache must be cleared — compiled views previously masked a fix in the adjacent 260815-ohw task; the same risk applies here to the toolbar and dropdown markup changes).

Test files (`tests/Unit/Services/Imports/EquipmentCategoryClassifierTest.php`, `tests/Feature/ProjectPackages/ProjectPackageReviewSupplyOnlyCategoryTest.php`) do not need to ship to the live server — dev/CI only.

**Before/alongside deploy:** perform the Task 5 browser verification that could not be done in this session — confirm select → Apply → persisted category works end-to-end at `/project-packages/{id}/review`.
