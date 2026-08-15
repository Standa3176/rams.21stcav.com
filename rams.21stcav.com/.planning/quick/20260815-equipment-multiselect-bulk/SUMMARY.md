---
quick_id: 260815-ohw
slug: equipment-multiselect-bulk
date: 2026-08-15
status: complete
---

# Quick Task 260815-ohw — Equipment multi-select bulk toolbar repair — Summary

**One-liner:** `toggleAllInTbody()` now drives `.checked` on the DOM checkboxes directly instead of leaving `x-model` to catch up, and `_selectedRows()` no longer reconciles (overwrites) `selectedRowIds` mid-read — the two competing sources of truth that made select-all destroy its own selection before `canBulk()` ever saw it.

## What was wrong

Reported symptom: ticking a category's "select all" checkbox showed "63 selected" in the toolbar, but no row checkbox appeared ticked, and Delete/Restore/Purge/Split stayed permanently greyed out. Individual row clicks showed the same self-undo behaviour.

The root cause was already reproduced and proven before this task started (see PLAN.md's live-instrumentation trace: `selectedRowIds` populated to 5 on select-all, DOM checkboxes stayed at 0 checked, `canBulk('delete')` returned `false`, and the very act of calling `canBulk()` collapsed `selectedRowIds` back to 0). This task did not re-investigate — it implemented the fix already specified.

Two methods in the `equipmentSection()` Alpine factory (`resources/views/project-packages/review.blade.php`) disagreed about which side — the Alpine array or the DOM — was authoritative:

- `toggleAllInTbody()` mutated only `selectedRowIds`, relying on Alpine's `x-model` to flush `.checked` onto the DOM on a later microtask.
- `_selectedRows()` (called by every `canBulk()` check, which drives the toolbar's `:disabled` bindings) read the DOM directly **and wrote back** to `selectedRowIds` if it disagreed with what it just read.

Because the toolbar's `:disabled="!canBulk(...)"` binding is reactive, it fired immediately after `toggleAllInTbody()`'s write — before the `x-model` flush had landed on the DOM — saw zero checked boxes, and `_selectedRows()`'s reconcile stomped `selectedRowIds` back to `[]`.

## What changed

### Task 1 — `toggleAllInTbody()` drives the DOM directly

Now sets `cb.checked = checked` on every affected row's checkbox (mirroring what `clearSelection()` already did), in addition to updating `selectedRowIds`. The existing `showDeleted` / `data-deleted` visibility filter and the merge/drop set logic for multiple independent category tables are unchanged — ticking one category's master still leaves other categories' selections untouched.

### Task 2 — `_selectedRows()` becomes a pure read

Removed the reconcile block (`if (domIds.length !== this.selectedRowIds.length || ...) { this.selectedRowIds = domIds; }`). `_selectedRows()` now only queries the DOM and returns matching `<tr>` elements — no more writing to `this.selectedRowIds` from inside a call that `canBulk()` (a reactive `:disabled` expression) triggers on every render.

Row-level actions (`softDelete` / `restore` / `purge` / `_deselectRow`, from 260725-fx1) were not touched — they already set `.checked = false` and filter `selectedRowIds` explicitly, independent of the removed reconcile, so that behaviour is preserved.

### Task 3 — Regression test

New `tests/Feature/ProjectPackages/EquipmentBulkSelectionTest.php` (the existing `EquipmentGraveyardTest.php` suite covers `parseReviewPayload()` server-side splitting, not client-side Alpine structure — it was checked and is not the right home for this). Explicitly documented in the file as a **structural guard, not a behavioural test**: PHPUnit cannot execute Alpine or a browser DOM, so it asserts the Blade source contains the specific code shapes the fix depends on:

- `toggleAllInTbody()`'s method body contains `cb.checked = checked`
- `toggleAllInTbody()`'s method body still contains the `showDeleted` / `data-deleted` visibility filter
- `_selectedRows()`'s method body does **not** contain `this.selectedRowIds =`
- `_selectedRows()`'s method body still returns the DOM query
- `_deselectRow()` still unchecks the checkbox and filters `selectedRowIds` (260725-fx1 regression guard)

## Proof the guard is real

Per the verification gate, each fix was manually reverted in place, the test suite re-run to confirm the corresponding assertion failed, then restored:

- Reverting Task 1 (removing the `.checked =` write from `toggleAllInTbody`) → `test_toggle_all_in_tbody_drives_dom_checkboxes` failed as expected (`assertStringContainsString('cb.checked = checked', ...)`).
- Reverting Task 2 (restoring the reconcile block in `_selectedRows`) → `test_selected_rows_is_a_pure_read_with_no_state_write` failed as expected (`assertStringNotContainsString('this.selectedRowIds =', ...)`).
- Both reverts were restored immediately after confirming the failure; `git diff` against the committed state showed zero lines changed afterward.

## Verification performed

- **Lint:** `php84/php.exe -l resources/views/project-packages/review.blade.php` — no syntax errors.
- **Tests:** `php artisan test --filter="EquipmentBulkSelection|EquipmentGraveyard"` — 12 passed, 64 assertions (3 new + 9 existing, zero regressions).
- **Known pre-existing failures** (`DrawIoSpikeController` constructor-arity, 2 tests, tracked in `deferred-items.md`) were not run and are unrelated to this change.
- **No migration** — Blade/Alpine only, per plan constraints.
- **Manual browser verification (select-all ticks boxes + enables Delete on `/project-packages/1/review`): NOT performed.** No browser/Playwright tooling was available in this execution session. The behavioural proof for this fix is (a) the pre-task live-instrumentation repro recorded in PLAN.md and (b) the structural test's revert-and-confirm-failure proof above — not a fresh manual click-through. A human should confirm in-browser before/alongside deploy.

## Deviations from Plan

None — plan executed exactly as written (Tasks 1, 2, 3 as specified). The only addition beyond the plan's literal acceptance criteria was documenting the revert-and-restore proof inline in code comments (`EquipmentBulkSelectionTest.php` docblocks) so a future reader doesn't need to re-derive why each assertion exists.

## Files changed

- `resources/views/project-packages/review.blade.php` — `toggleAllInTbody()` and `_selectedRows()` (commit `57cdb15`)
- `tests/Feature/ProjectPackages/EquipmentBulkSelectionTest.php` — new file (commit `03b9397`)

## Commits

- `57cdb15` — fix(project-packages): make equipment select-all sync DOM checkboxes and stop canBulk from wiping selection
- `03b9397` — fix(project-packages): add structural regression guard for equipment bulk-select fix

## Self-Check

- FOUND: `resources/views/project-packages/review.blade.php` (modified)
- FOUND: `tests/Feature/ProjectPackages/EquipmentBulkSelectionTest.php`
- FOUND commit `57cdb15` in `git log`
- FOUND commit `03b9397` in `git log`

## Self-Check: PASSED

## 🚨 Files to upload to live

- `resources/views/project-packages/review.blade.php`

**No migration.** After uploading, run `php artisan optimize:clear` on live — Blade views are cached and the fix will not take effect until the compiled view cache is cleared. `tests/Feature/ProjectPackages/EquipmentBulkSelectionTest.php` is test-only and does not need to be uploaded (but should be committed/pushed so CI/future sessions have it).
