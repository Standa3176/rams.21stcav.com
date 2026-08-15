---
quick_id: 260815-sup
slug: supply-only-category
date: 2026-08-15
status: planned
---

# Quick Task 260815-sup — "Hardware — supply only" category + bulk category change

## Why

User has quotes containing rooms of client-owned kit that 21CAV supplies but does **not** install (e.g. "Digital Production Studio" on package 161 — 63 lines of cameras, lenses, lighting). Today the only options are `hardware` (which drags it into RAMS method statements, drawings and surveys as if it were being installed) or deleting it (which also removes it from the O&M handover, where it legitimately belongs).

## Locked decision — where supply-only kit appears

Confirmed by the user 2026-08-15:

| Surface | Include? |
|---|---|
| **O&M manual / asset register** | ✅ **YES** — the client owns the kit; it belongs in handover documentation |
| RAMS method statement / work activities | ❌ no |
| Drawings / stencil catalogue | ❌ no |
| Site surveys / project install | ❌ no |

## Audit — what actually has to change

**Exclusions are FREE.** Every "keep it out" site already uses an exclusive filter, so a new category value is excluded by construction. Verified:

| Site | Filter | Result |
|---|---|---|
| `app/Models/Project.php:255` (`devicesWithStencils`) | `if ($cat !== 'hardware') continue;` | auto-excluded — drawings ✓ |
| `app/Models/Project.php:328` (`hardwarePartNumbers`) | `if ($cat !== 'hardware') continue;` | auto-excluded — RAMS ✓ |
| `app/Console/Commands/StencilsCoverageReportCommand.php:94` | `if ($category !== 'hardware') continue;` | auto-excluded ✓ |
| `app/Http/Controllers/SiteSurveyController.php:269` | `if ($category !== 'hardware') continue;` | auto-excluded ✓ |
| `app/Http/Controllers/PublicSurveyController.php:67` | `if ($category !== 'hardware') continue;` | auto-excluded ✓ |
| `app/Http/Controllers/ProjectPackageReviewController.php:170` | `if ($cat !== 'hardware') continue;` | auto-excluded (room derivation) ✓ |
| `app/Http/Controllers/ProjectPackageReviewController.php:901` | `$itemCat === 'hardware'` | auto-excluded (Generate Rooms split) ✓ |

**DO NOT** add the new value to any of the above. Their current behaviour is already correct.

**Inclusions must be added — O&M only, 3 sites:**

| Site | Current | Required |
|---|---|---|
| `app/Core/Modules/OMManual/OmManualGeneratorService.php:845` | `return $category === '' \|\| $category === 'hardware';` | also allow the supply-only value |
| `app/Services/MiniOmBuilderService.php:395` | `if ($category !== 'hardware') continue;` | also allow the supply-only value |
| `app/Services/MiniOmBuilderService.php:604` | `if ($category !== 'hardware') continue;` | also allow the supply-only value |

## Tasks

### Task 1 — Add the category to the canonical vocabulary

**Files:** `app/Services/Imports/EquipmentCategoryClassifier.php`

**Action:** Add `hardware_supply_only` to `EquipmentCategoryClassifier::CATEGORIES`. Display label is **"Hardware — supply only (no install)"**.

Do **NOT** add keyword rules to the classifier decision tree — this category is **manual-selection only**. Nothing in a quote description reliably indicates "we supply but don't install"; that is a commercial decision the PM makes. The classifier's existing explicit-category short-circuit (which returns a canonical incoming `category` verbatim) means a manually-set value already survives save round-trips — verify that holds for the new value.

Fix the stale docblock while there: the class comment says "The 8 canonical category values" and the `classify()` docblock says "one of the 7 canonical categories". Both are already wrong; make them agree with reality.

**Acceptance criteria:**
- `EquipmentCategoryClassifier::CATEGORIES` contains `hardware_supply_only`
- `classify(['category' => 'hardware_supply_only', ...])` returns it verbatim (manual selection survives)
- No description/part-number keyword maps to it — a unit test asserts a realistic camera/lens/lighting line still classifies as `hardware`, not supply-only

### Task 2 — Include supply-only in O&M, and ONLY O&M

**Files:** `app/Core/Modules/OMManual/OmManualGeneratorService.php` (~845), `app/Services/MiniOmBuilderService.php` (~395, ~604)

**Action:** Widen those three predicates to admit `hardware_supply_only` alongside `hardware`. Prefer a single shared helper over three copies of a literal-pair check, so the next category change has one place to edit — but do not refactor the surrounding code beyond that.

**Acceptance criteria:**
- A package containing one `hardware` line and one `hardware_supply_only` line produces an O&M manual listing **both**
- The same package's `Project::hardwarePartNumbers()` and `Project::devicesWithStencils()` return **only** the `hardware` line
- `php artisan stencils:coverage-report` does not count the supply-only line
- Site survey equipment list excludes the supply-only line

The second and third criteria are the important ones — they prove the exclusions still hold and nothing leaked into RAMS or drawings.

### Task 3 — Review-screen dropdown option

**File:** `resources/views/project-packages/review.blade.php`

**Action:** Add the option to the per-row category dropdown with the label above. Keep it adjacent to `hardware` in the list so the related choices sit together.

**Acceptance criteria:**
- The option renders in every row's category select
- Selecting it and saving persists `hardware_supply_only` (not silently reverted to `hardware` — this is the exact bug 260725-qw3 fixed for two other values, so assert it explicitly)

### Task 4 — Bulk category change on the equipment toolbar

**File:** `resources/views/project-packages/review.blade.php` (`equipmentSection()`)

**Action:** Add a category `<select>` + Apply control to the existing bulk toolbar, beside Delete/Restore/Purge/Split. Applying sets the category on every selected row's dropdown, then clears the selection (matching `bulkAction()`'s existing end-of-action behaviour).

This is what makes the category usable — otherwise changing a 63-line room means 63 individual dropdowns.

**Constraints carried from 260815-ohw (just fixed — do not regress):**
- Use `this.$root`, never `this.$el`, for any DOM query. Alpine binds `$el` to the element being evaluated, so from a toolbar binding `$el` is the button, not the component root.
- Any new gate function used in a `:disabled` binding must read `this.selectedRowIds` first to establish a reactive dependency.
- Keep DOM checkboxes and `selectedRowIds` in sync, as `toggleAllInTbody()` now does.

**Acceptance criteria:**
- Selecting rows, choosing a category and clicking Apply sets that category on exactly the selected rows
- Unselected rows are untouched
- Selection clears afterwards
- The control is disabled when nothing is selected
- Structural guards in `EquipmentBulkSelectionTest` still pass (no `this.$el`, reactive dependency intact)

### Task 5 — Browser verification

**Action:** The 260815-ohw post-mortem is the reason this task exists: a structural test passed while the UI stayed broken, because PHPUnit cannot execute Alpine. Verify Task 4 in a real browser against the local repro at `/project-packages/1/review` (local admin `uat-local@example.test` / `uat-local-password`; run `php artisan view:clear` first — compiled Blade caching masked a fix during that task).

If browser tooling is unavailable in your session, **say so plainly** rather than claiming verification.

**Acceptance criteria:** Either a recorded browser check of select → apply → persisted category, or an explicit statement that it was not performed.

## Constraints

- No migration. `hardware_supply_only` is a new value in existing json data; historical rows are unaffected and keep their current categories.
- PHPUnit 11, NOT Pest. `extends Tests\TestCase`, `use RefreshDatabase;`.
- Lint: `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l <file>`
- Local-edit-then-upload (Phase 21 D-13). Blade + PHP changes → `php artisan optimize:clear` after upload. No migration.
- Known pre-existing failures: 2 `DrawIoSpikeController` constructor-arity tests in `deferred-items.md` — not regressions.
