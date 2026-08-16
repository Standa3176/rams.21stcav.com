---
quick_id: 260816-prs
slug: per-room-select-all
date: 2026-08-16
status: complete
---

# Quick Task 260816-prs — Replace category-wide select-all with per-room select-all — Summary

**One-liner:** The equipment table's master checkbox no longer selects every row in a whole category (up to 63 hardware rows across every room on a real quote) — select-all now lives per room, scoped via a new `data-room` attribute and `toggleAllInRoom()`, so a single click can no longer misfire the bulk category-change action across an entire project.

## What was wrong

`toggleAllInTbody()` sat in the category `<thead>` and selected every visible row in the whole category tbody, regardless of which room those rows belonged to. The user's actual workflow is per-space ("this room is supply-only, that room is an install"), so selecting all hardware everywhere was never what they wanted — and it made the bulk category-change action shipped the day before (260815-sup) dangerous, since one click could recategorise an entire project's worth of equipment.

## What changed

All in `resources/views/project-packages/review.blade.php`, `equipmentSection()` (repaired 2026-08-15 in 260815-ohw — see load-bearing constraints below).

### Task 1 — Tag rows with their room

Added `data-room="{{ $roomName }}"` to both the room header `<tr data-room-row="1">` and every equipment `<tr data-equip-row="1">` inside that room group, in the `@forelse ($rowsByRoom as $roomName => $roomRows)` loop. `$roomName` already defaults to `'General'` for blank `area` values, so every row carries a non-empty room. Attribute-based scoping was used deliberately instead of DOM traversal, since traversal breaks the moment a row moves category (the existing per-row category-change listener does exactly that) or is hidden by the graveyard filter.

### Task 2 — Removed the category-wide master checkbox

Removed the `<input type="checkbox" @change="toggleAllInTbody(...)">` from the `<thead>`. The `<th class="col-select">` cell itself was kept (now empty) so column alignment holds.

### Task 3 — Per-room select-all

Split the room header's single `<td colspan="8">` into `<td class="col-select">` (new checkbox) + `<td colspan="7">` (existing room label + hardware-only Generate Rooms controls, unchanged). Wired to `toggleAllInRoom($event.target, catKey, roomName)`, rendered for every category (not just hardware), with `title="Select all visible rows in {roomName}"`.

### Task 4 — `toggleAllInRoom()` on the Alpine component

Replaced `toggleAllInTbody()` with `toggleAllInRoom(masterCb, catKey, roomName)`, which queries `tr[data-equip-row][data-room="..."]` (via `CSS.escape(roomName)`) scoped to the category's tbody instead of every row in it, applies the same `showDeleted` / `data-deleted` visibility filter, and sets `.checked` on each row's `input.row-select` directly (not relying on `x-model`'s async flush) while merging/dropping ids in `selectedRowIds` with the same set semantics as before — selecting room A then room B yields A union B, unticking A leaves B selected. `toggleAllInTbody` was removed from the file entirely, including its mentions in surrounding comments.

**Load-bearing constraints preserved (260815-ohw, three stacked prior bugs):**
- `this.$root` is used throughout, never `this.$el` — verified absent from the whole file.
- DOM checkboxes are set directly, not left to `x-model`'s flush.
- `_selectedRows()` remains a pure read — no write to `this.selectedRowIds` was added inside it or any other reactively-called helper.

**Deviation (Rule 2 — missing critical functionality, auto-fixed):** two dynamic JS paths mutate room groupings at runtime and were not covered by the plan's six tasks, but would have silently broken the new per-room scoping the moment they ran in the same session:
- `moveEquipRowToArea()` (fires when the engineer edits a row's Title/Section area field) now sets `row.dataset.room = newArea` on move, and any room-header row it creates on the fly gets `data-room` plus a real per-room checkbox (built with the DOM API and wired via `window.Alpine.$data(root).toggleAllInRoom(...)` on a native `change` listener, rather than an inline `@change` attribute string — this avoids double-escaping a user-typed room name that could contain a quote).
- The "move row when category changes" listener's ad-hoc "General" fallback header (built only when a destination category tbody has no room headers yet) got the same `data-room="General"` + checkbox treatment, so it isn't the one group in the table select-all can't reach.

Both fixes keep the row's own `data-room` correct after an in-session edit; without them, a row moved to a new room or a new category would fall out of the reach of every room's select-all until the page reloaded, defeating the point of this task.

### Task 5 — Updated the structural guards

`tests/Feature/ProjectPackages/EquipmentBulkSelectionTest.php`:
- Renamed and repointed `test_toggle_all_in_tbody_drives_dom_checkboxes` to `test_toggle_all_in_room_drives_dom_checkboxes`, extracting `toggleAllInRoom(masterCb, catKey, roomName) {` instead, and added an assertion that the method body scopes its query by `tr[data-equip-row][data-room=`.
- Added `test_thead_no_longer_renders_a_select_all_checkbox`, which asserts `toggleAllInTbody` is fully absent from the file and that the `<thead>...</thead>` block contains no `<input type="checkbox"`.
- Left the `this.$el` prohibition and the `_selectedRows()` no-state-write assertion untouched (still passing, still guarding the 260815-ohw fix).

### Task 6 — Browser verification

**Not performed. No browser/Playwright tooling was available in this execution session.** Per the plan's own instruction ("say so plainly rather than claiming verification"), this is stated explicitly rather than inferred from the passing structural tests, which cannot execute Alpine or a DOM. `php artisan view:clear` was run so a subsequent manual check isn't masked by stale compiled Blade output. A human should perform the recorded two-room check before/alongside deploy: add a second room's rows to the local repro package (`extracted_data['equipment']`, some lines with a different `area`, e.g. via `php artisan tinker`), load `http://rams.21stcav.com.test/project-packages/1/review`, tick one room's select-all, confirm only that room's rows are ticked, and confirm `bulkSetCategory()`'s Apply only recategorises that room.

## Verification performed

- **Lint:** `php84/php.exe -l resources/views/project-packages/review.blade.php` and `-l tests/Feature/ProjectPackages/EquipmentBulkSelectionTest.php` — no syntax errors.
- **Tests:** `php artisan test --filter="EquipmentBulkSelection|EquipmentGraveyard|ProjectPackageReview"` — 20 passed, 87 assertions, zero regressions (`EquipmentBulkSelectionTest`, `EquipmentGraveyardTest`, `ProjectPackageReviewController_EquipmentGraveyardTest`, `ProjectPackageReviewSupplyOnlyCategoryTest`).
- **Known pre-existing failures** (`DrawIoSpikeController` constructor-arity, 2 tests, tracked in `deferred-items.md`) were not run and are unrelated to this change.
- **No migration** — Blade/Alpine only, per plan constraints.
- **Full-repo test run:** intentionally not run, per verification gate — scoped `--filter` only.
- **Manual browser verification: NOT performed** — see Task 6 above.

## Deviations from Plan

### Auto-fixed issues

**1. [Rule 2 — missing critical functionality] `data-room` not kept in sync by the two runtime room-relocation code paths**
- **Found during:** Task 4, while confirming the new `data-room` scoping was airtight.
- **Issue:** `moveEquipRowToArea()` (area field edits) and the category-change listener's "General" fallback header both create/move rows and room-header rows without any awareness of the new `data-room` attribute — a row moved to a new room mid-session, or dropped into a freshly-created fallback header, would have no `data-room` (or a stale one), making it invisible to every room's select-all until reload.
- **Fix:** `moveEquipRowToArea()` now sets `row.dataset.room` on move and builds any new header row with `data-room` plus a working checkbox (native `change` listener plus `Alpine.$data()` lookup, not a templated `@change` string, to avoid escaping a user-typed room name). The category-change listener's fallback "General" header gets the identical treatment.
- **Files modified:** `resources/views/project-packages/review.blade.php`
- **Commit:** `26cf365`

None of the other five tasks required deviation — the plan's structure, line-number pointers, and load-bearing constraints all matched the file as found.

## Files changed

- `resources/views/project-packages/review.blade.php` — Tasks 1-4 plus the Rule 2 fix above (commit `26cf365`)
- `tests/Feature/ProjectPackages/EquipmentBulkSelectionTest.php` — Task 5 (commit `596c2c5`)

## Commits

- `26cf365` — feat(project-packages): replace category-wide select-all with per-room select-all
- `596c2c5` — feat(project-packages): repoint structural guards at toggleAllInRoom

## Self-Check

- FOUND: `resources/views/project-packages/review.blade.php` (modified)
- FOUND: `tests/Feature/ProjectPackages/EquipmentBulkSelectionTest.php` (modified)
- FOUND commit `26cf365` in `git log`
- FOUND commit `596c2c5` in `git log`

## Self-Check: PASSED

## 🚨 Files to upload to live

- `resources/views/project-packages/review.blade.php`

**No migration.** After uploading, run `php artisan optimize:clear` on live — Blade views are cached and this fix will not take effect until the compiled view cache is cleared. `tests/Feature/ProjectPackages/EquipmentBulkSelectionTest.php` is test-only and does not need to be uploaded (but should be committed/pushed so CI/future sessions have it).
