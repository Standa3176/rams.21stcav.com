---
name: 260723-eq1-equipment-line-item-editing
status: complete
tasks: 3
completed: 2026-07-24
commits:
  - d1e0eac -- feat(review): equipment soft-delete storage — graveyard round-trip in parseReviewPayload (260723-eq1)
  - 813dc5a -- feat(review): equipment soft-delete/restore/purge + qty-split UI (260723-eq1)
  - 7afe5b1 -- feat(review): equipment multi-select bulk actions (260723-eq1)
---

# Equipment line-item editing — SUMMARY

## What shipped

Three atomic commits on `feat/worksheet-classifier-universal`, zero migrations, everything on top of the existing `ProjectPackage.reviewed_data` JSON.

### T1 — Storage round-trip (`d1e0eac`)

`app/Http/Controllers/ProjectPackageReviewController.php`
`tests/Unit/Http/Controllers/ProjectPackageReviewController_EquipmentGraveyardTest.php`
`tests/Feature/ProjectPackages/EquipmentGraveyardTest.php`

- `parseReviewPayload()` now splits incoming `equipment[]` on the `equipment[N][deleted]` marker into `$active` and `$deleted` buckets.
- Active rows persist to `reviewed_data.equipment[]` clean (no `deleted` key).
- Deleted rows persist to `reviewed_data.equipment_deleted[]` with `deleted:true` + `deleted_at` (iso8601) + `deleted_by` (user id) stamps.
- Round-trip: re-submitted graveyard rows carry back their original stamps via hidden inputs and are NOT re-stamped with `now()`.
- `_original_totals` snapshot naturally excludes deleted rows because it iterates `$payload['equipment']` which is now the active-only bucket.
- `update()` and `approve()` both go through `parseReviewPayload` — approvals cannot resurrect deleted rows.
- `show()` exposes `equipment_deleted[]` to the Blade view as its own top-level payload key so Task 2's rendering could iterate it.
- Downstream services (`OmManualGeneratorService`, `BuildRamsDocumentJob`, `MiniOmBuilderService`, `ProjectPackageRamsReviewService`) still read `extracted_data.equipment[]` which stays clean — zero downstream touches needed.
- 9 new tests pin the contract (split, stamps, round-trip preservation, `deleted="0"`/absent-key-as-active, `_original_totals` isolation, approve() parity).

### T2 — Row-level UI (`813dc5a`)

`resources/views/project-packages/review.blade.php`

- Wrap `#s-equipment` in Alpine `x-data="equipmentSection()"` — factory added at the bottom of the script block.
- Extend Blade rendering to include `reviewPayload.equipment_deleted[]` inline, indexed above the active list so hidden `equipment[N][...]` names never collide with active rows.
- New header strip "N deleted rows hidden" with a Show/Hide toggle. `x-cloak` prevents flash.
- Deleted rows carry `data-deleted="1"` and are hidden by default via CSS keyed off `#s-equipment.show-deleted` (Alpine `:class` toggles the body class).
- Per-row `col-actions` cell hosts BOTH active-mode (Split, Delete) and deleted-mode (Restore, Purge) buttons. CSS attribute selectors on `tr[data-deleted]` flip the pair — no JS-driven markup swap.
- `softDelete()` / `restore()` mutate `data-deleted` + the hidden `[deleted]` input value; `softDelete()` also stamps `deleted_at` (iso8601) + `deleted_by` (`auth()->id()`) hidden inputs so first POST carries them.
- `purge()` removes the row from the DOM — will not appear on next POST, so `parseReviewPayload` drops it entirely.
- `split()` clones a `qty>1` row (qty-1) times, sets each clone's quantity to 1, preserves part/name/area/category/zone. Handles BOTH plain `name="equipment[N][…]"` attrs AND Alpine `:name` bindings (zonePicker's baked-in literal is rewritten in the clone via `getAttribute(':name')` / `setAttribute`).
- JS `equipmentRowTemplate()` (used by `+ Add {category}`) kept in sync — carries `data-deleted="0"`, hidden `[deleted]` flag, and the same `col-actions` button group as the Blade row.
- Legacy `removeRow()` at line 1797 left untouched — still used by activities, hazards, PPE, room, cable and other tables outside `#s-equipment`.

### T3 — Multi-select bulk toolbar (`7afe5b1`)

`resources/views/project-packages/review.blade.php`

- `col-select` checkbox column added at the start of every equipment row (both Blade + JS template).
- Master checkbox in each category `<thead>` selects all visible rows in that tbody, respecting the `showDeleted` filter.
- Sticky `.bulk-toolbar` appears whenever `selectedRowIds.length > 0` — Delete / Restore / Purge / Split / Clear buttons.
- Buttons auto-disable when the action doesn't apply to the current selection (e.g. Split disables if no selected row has qty>1; Restore disables if nothing selected is deleted).
- Bulk actions confirm ONCE for the whole selection ("Move 3 row(s) to deleted?"), not per row.
- Refactored existing row-level buttons to share `_doDelete` / `_doRestore` / `_doPurge` / `_doSplit` internals with the toolbar — single code path.
- Colspans bumped 7→8 (Blade room-header / spacer / empty-state) and 6→8 (JS empty-state / room-header templates) to cover the new checkbox column.
- `_reindexRow()` now also updates the per-row-select checkbox `value=` so split clones toggle their own new id in the shared `selectedRowIds` array, not the source row's.
- Clones do not carry the source row's checked state.

## Verification

- `php -l app/Http/Controllers/ProjectPackageReviewController.php` — no syntax errors
- `php artisan view:clear` + Blade compile-string check — 159925 chars, compiles cleanly
- `npm run build` — Vite bundle succeeds (no size regression, no new deps)
- `phpunit --filter EquipmentGraveyard` — **9/9 green, 46 assertions**
- `phpunit tests/Feature/ProjectPackages` — **19/19 green, 62 assertions**

## Deviations from PLAN.md

- **PLAN.md said "Row-level audit log — deferred"**: kept as deferred. Stamps on the graveyard entry are enough for the current PM workflow.
- **PLAN.md said "no server-side split endpoint"**: split is pure client-side DOM clone as designed. Zero backend touches.
- **`$totalDeletedRows` counter** — added a Blade-side `$totalDeletedRows` variable so the "N deleted rows hidden" strip renders correctly on the server without waiting for Alpine to hydrate. Alpine's `x-text="deletedCount()"` then keeps it in sync as bulk actions restore/purge rows on the client.
- **Colspan bump on the JS-generated room-header template (line 2297)** — the existing code used `colspan="6"` while the Blade template used `colspan="7"`. Both bumped to 8 in T3 for consistency with the new checkbox column. This corrects a pre-existing minor inconsistency but is not a semantic change.
- **`bulk-toolbar` sticky positioning** — uses `position: sticky; top: 0` within the section body so it floats at the top of the scroll viewport when the user scrolls through 100+ rows.

## Deploy

- **NO migrations.**
- Steps:
  - `git pull`
  - `php artisan optimize:clear`
  - `php artisan config:cache`
  - `npm run build`
- Sanity on live: open a completed package's review page →
  1. Delete a row via the button in `col-actions` → save → reload → click "Show N deleted" → row appears with Restore + Purge buttons.
  2. Click Restore → save → row is back in the active list without stamps.
  3. Select 3 rows via the leading checkboxes → sticky toolbar appears → Delete → all 3 in deleted fold on next reload.
  4. Click Split on a qty=3 hardware row → 3 rows appear as qty=1 each with identical part/name/area/zone → save → reload → all 3 persist.

## Explicit non-goals (unchanged from PLAN.md)

- Row-level audit log (persistent event log per row).
- Cross-package undo / global recycle bin — graveyard is per-package.
- Server-side split endpoint — split is pure client-side.
- Regen impact preview ("This will remove 3 rows from cable schedule / RAMS section 3") — consumers just skip removed rows on next generation. Add if PM feedback asks for it.

## Self-Check

Files exist:
- `.planning/quick/260723-eq1-equipment-line-item-editing/PLAN.md`
- `.planning/quick/260723-eq1-equipment-line-item-editing/SUMMARY.md` (this file)
- `app/Http/Controllers/ProjectPackageReviewController.php`
- `resources/views/project-packages/review.blade.php`
- `tests/Unit/Http/Controllers/ProjectPackageReviewController_EquipmentGraveyardTest.php`
- `tests/Feature/ProjectPackages/EquipmentGraveyardTest.php`

Commits on `feat/worksheet-classifier-universal`:
- `d1e0eac` — T1 storage
- `813dc5a` — T2 UI
- `7afe5b1` — T3 bulk toolbar

## Self-Check: PASSED
