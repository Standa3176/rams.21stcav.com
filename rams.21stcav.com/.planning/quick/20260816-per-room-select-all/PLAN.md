---
quick_id: 260816-prs
slug: per-room-select-all
date: 2026-08-16
status: planned
---

# Quick Task 260816-prs — Replace category-wide select-all with per-room select-all

## Why

The equipment table's master checkbox lives in `<thead>` and calls `toggleAllInTbody($event.target, '{{ $catKey }}')` — it selects **every visible row in the whole category**. On a real quote that is 63 hardware rows spanning multiple spaces.

The user's actual workflow is per-space: "this room is supply-only, that room is an install". Selecting all hardware everywhere is never what they want, and it makes the new bulk category-change action (260815-sup) dangerous — one click can recategorise the entire project.

**Replace it with a select-all per room.**

## Current structure (verified)

`resources/views/project-packages/review.blade.php`:

- `<thead>` (~line 1133) holds the single per-category master checkbox.
- `<tbody id="equipment-tbody-{catKey}">` groups rows by room via the `$rowsByRoom` map (built ~line 1193, `ksort`ed).
- Each room emits a header row `<tr data-room-row="1">` with `<td colspan="8">` containing `.eq-area-label` and — for `hardware` only — the Total Rooms / Generate Rooms controls.
- Equipment rows are `<tr data-equip-row="1" data-deleted="0|1" data-row-id="{$i}">`.

**Gap:** equipment rows carry no room identifier, so a selection cannot currently be scoped to a room without fragile "walk siblings until the next `data-room-row`" traversal.

## Tasks

### Task 1 — Tag rows with their room

**File:** `resources/views/project-packages/review.blade.php`

**Action:** Add `data-room="{{ $roomName }}"` to both the room header `<tr data-room-row="1">` and every equipment `<tr data-equip-row="1">` inside that room group. `$roomName` is already in scope in the `@forelse ($rowsByRoom as $roomName => $roomRows)` loop and already defaults to `'General'` when `area` is blank.

Attribute-based scoping, not DOM traversal — traversal breaks the moment a row is moved between categories (the existing per-row category-change listener does exactly that) or hidden by the graveyard filter.

**Acceptance criteria:**
- Every `tr[data-equip-row]` has a non-empty `data-room`
- Rows whose `area` is blank carry `data-room="General"`, matching the visible group label

### Task 2 — Remove the category-wide master checkbox

**File:** same, `<thead>` (~line 1133)

**Action:** Remove the `<input type="checkbox" @change="toggleAllInTbody(...)">` from the header row. Keep the `<th class="col-select">` cell itself so column alignment is preserved.

**Acceptance criteria:**
- No checkbox renders in any category `<thead>`
- Column widths/alignment unchanged (the `col-select` `<th>` still present)

### Task 3 — Add a per-room select-all

**File:** same, room header row (~line 1203)

**Action:** Split the room header's `<td colspan="8">` into `<td class="col-select">` holding the new checkbox plus `<td colspan="7">` carrying the existing content (room label + hardware-only Generate Rooms block). This keeps the checkbox aligned under the row-select column.

Wire it to a new `toggleAllInRoom($event.target, catKey, roomName)`. Render it for **every** category, not just hardware — rooms are grouped in all categories.

Give it a title of the form `Select all visible rows in {roomName}`.

**Acceptance criteria:**
- Each room group shows exactly one select-all checkbox, aligned in the select column
- Ticking it selects only that room's visible rows; rows in other rooms and other categories are untouched
- Unticking clears only that room's rows
- A room whose rows are all soft-deleted while `showDeleted` is off selects nothing

### Task 4 — `toggleAllInRoom()` on the Alpine component

**File:** same, `equipmentSection()` (~line 2058, replacing `toggleAllInTbody`)

**Action:** Implement room-scoped selection. Query within the category tbody for `tr[data-equip-row][data-room="..."]`, apply the same `showDeleted` / `data-deleted` visibility filter the current method uses, then set `.checked` on each row's `input.row-select` **and** merge/drop the ids in `selectedRowIds`.

Remove `toggleAllInTbody()` once nothing references it.

**These constraints are load-bearing — they were three separate bugs in 260815-ohw. Do not regress them:**

1. **Use `this.$root`, NEVER `this.$el`.** Alpine binds `$el` to the element being evaluated; from a toolbar/button binding `$el` is that element, not the component root. `EquipmentBulkSelectionTest` asserts `this.$el` appears nowhere in the file.
2. **Set the DOM checkboxes directly** as well as updating `selectedRowIds` — do not rely on `x-model` reflecting into the DOM before `canBulk()`'s DOM query runs.
3. **Do not write `selectedRowIds` inside `_selectedRows()`** or any other function called from a reactive binding.
4. Keep the merge/drop set semantics so selecting room A then room B yields both, and unticking A leaves B selected.

**Acceptance criteria:**
- Selecting room A then room B gives a selection equal to A ∪ B
- Unticking room A leaves exactly room B's rows selected, in both the array and the DOM
- `canBulk('delete')` is true immediately after a room select-all
- `toggleAllInTbody` no longer exists anywhere in the file

### Task 5 — Update the structural guards

**File:** `tests/Feature/ProjectPackages/EquipmentBulkSelectionTest.php`

**Action:** The existing guards reference `toggleAllInTbody`. Repoint them at `toggleAllInRoom`, keep the `this.$el` prohibition and the "no state write in `_selectedRows`" assertion, and add one asserting the `<thead>` no longer emits a select-all checkbox.

**Acceptance criteria:**
- `php artisan test --filter="EquipmentBulkSelection|EquipmentGraveyard"` passes
- Guards fail if `this.$el` returns, or if a thead checkbox is reintroduced

### Task 6 — Browser verification

**Action:** Verify against the local repro. Note it currently has a single room ("Digital Production Studio"), so **add a second room's rows first** to prove cross-room isolation — that is the entire point of this change and cannot be shown with one room.

Local: `http://rams.21stcav.com.test/project-packages/1/review`, admin `uat-local@example.test` / `uat-local-password`. Run `php artisan view:clear` first — compiled Blade caching has masked fixes twice in this codebase.

If browser tooling is unavailable, **say so plainly** rather than claiming verification. A structural test cannot prove Alpine behaviour; that mistake is exactly what 260815-ohw shipped.

**Acceptance criteria:** a recorded two-room check (select room A → only A's rows selected → bulk category applies to A only), or an explicit statement that it was not performed.

## Constraints

- Blade/Alpine only. No controller, model, or service changes. No migration.
- Do not alter row-level actions (`softDelete`/`restore`/`purge`/`split`), `_deselectRow` (260725-fx1), or `bulkSetCategory` (260815-sup) beyond repointing what Task 4 requires.
- PHPUnit 11, NOT Pest.
- Lint: `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l <file>`
- Local-edit-then-upload (Phase 21 D-13) → `php artisan optimize:clear` after upload. No migration.
- Known pre-existing failures: 2 `DrawIoSpikeController` constructor-arity tests in `deferred-items.md` — not regressions.
