---
quick_id: 260815-ohw
slug: equipment-multiselect-bulk
date: 2026-08-15
status: planned
---

# Quick Task 260815-ohw — Equipment multi-select toolbar is permanently disabled

## Symptom (user-reported, production `/project-packages/161/review`)

- Ticking the category "select all" checkbox shows **"63 selected"** in the toolbar
- **No** row checkbox appears ticked
- Delete / Restore / Purge / Split stay **greyed out**
- Clicking an individual row checkbox shows a focus ring but the row does not stay selected, and the buttons remain greyed

Net effect: the bulk toolbar shipped in 260723-eq1 is unusable.

## Root cause — REPRODUCED AND PROVEN, do not re-investigate

Reproduced locally against a seeded package. Live instrumentation of the Alpine component returned:

```
selectedRowIds after select-all : 5      ← array populated
DOM checkboxes checked          : 0      ← never reflected
canBulk('delete')               : false  ← buttons disabled
selectedRowIds AFTER canBulk    : 0      ← the array was DESTROYED by the read
```

Value types match on both sides (strings `"0"`,`"1"`,… vs checkbox `value` strings) — **it is not a type-coercion bug.**

The component has two competing sources of truth:

| UI element | Reads |
|---|---|
| `"N selected"` text + toolbar `x-show` | `selectedRowIds` (Alpine array) |
| Delete/Restore/Purge/Split `:disabled` | `_selectedRows()` — a live DOM query |

And three methods disagree about which side is authoritative:

| Method | Line | Behaviour |
|---|---|---|
| `clearSelection()` | ~2074 | Sets `cb.checked = false` on the DOM — **correct** |
| `toggleAllInTbody()` | ~2058 | Mutates `selectedRowIds` only, **never touches the DOM checkboxes** |
| `_selectedRows()` | ~2133 | Reads the DOM **and writes `this.selectedRowIds`** |

Failure sequence:

1. Select-all → `toggleAllInTbody` sets the array. Toolbar renders "N selected".
2. Alpine flushes `x-model` to the DOM on a microtask, so the checkboxes are not checked yet.
3. The reactive `:disabled="!canBulk(...)"` binding runs → `canBulk()` → `_selectedRows()` → DOM query finds **0** checked → its reconcile **overwrites `selectedRowIds = []`** → returns `false`.
4. Buttons stay disabled. On an individual click the same loop runs in reverse: Alpine re-renders the checkbox from the now-empty array and unchecks the box the user just ticked.

The reconcile-inside-a-reactive-read is the destructive part. The 260725-fx3 comment made the DOM authoritative to fix an earlier drift bug, but `toggleAllInTbody` was never updated to match, so select-all can never survive.

## Tasks

### Task 1 — `toggleAllInTbody()` must drive the DOM checkboxes

**File:** `resources/views/project-packages/review.blade.php` (~line 2058)

**Action:** When toggling, set `.checked` on each affected row checkbox directly — mirroring what `clearSelection()` already does — and then sync `selectedRowIds` to match. Keep the existing `showDeleted` / `data-deleted` row filtering and the merge/drop semantics for multiple category tables (ticking one category's master must not clear another's selection).

**Acceptance criteria:**
- After select-all on a category, `document.querySelectorAll('input.row-select:checked').length` equals the number of visible rows in that tbody
- `selectedRowIds.length` equals the same number
- `canBulk('delete')` returns `true` when at least one selected row has `data-deleted="0"`
- Unticking the master unchecks exactly that tbody's rows and leaves other categories' selections intact

### Task 2 — `_selectedRows()` must not write state during a reactive read

**File:** same (~line 2133)

**Action:** Remove the `selectedRowIds` reconcile from `_selectedRows()` so it becomes a pure read. If reconciliation is still wanted after row-level mutations (softDelete/restore/purge/split), do it explicitly in those handlers — `_deselectRow()` (260725-fx1) already covers that path.

Keep the DOM as the authority for *what is selected*; `selectedRowIds` becomes the reactive mirror that Task 1 keeps in step.

**Acceptance criteria:**
- `_selectedRows()` contains no assignment to `this.selectedRowIds`
- Calling `canBulk()` repeatedly does not change `selectedRowIds.length`
- The 260725-fx1 behaviour still holds: row-level × / restore / purge updates both the checkbox and the toolbar count

### Task 3 — Regression test

**File:** `tests/Feature/ProjectPackages/EquipmentBulkSelectionTest.php` (new, or extend the existing EquipmentGraveyard suite if that is the established home — check first)

**Action:** The failure is client-side, so a PHPUnit feature test cannot execute Alpine. Assert instead at the contract level that the Blade emits the pieces the fix depends on: `toggleAllInTbody` assigns `.checked` on row checkboxes, and `_selectedRows` contains no `this.selectedRowIds =` assignment. State plainly in a comment that this is a structural guard, not a behavioural test, and that the behavioural proof is the manual reproduction recorded in this plan.

**Acceptance criteria:**
- Test fails if either fix is reverted
- `php artisan test --filter=EquipmentBulkSelection` passes

## Constraints

- Blade/Alpine only — no controller, model, or service changes.
- Do NOT alter row-level actions (`softDelete` / `restore` / `purge` / `split`) or `_deselectRow` beyond what Task 2 requires; 260725-fx1 fixed real bugs there.
- No new packages, no migration.
- Lint: `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l <file>`
- Deployment is local-edit-then-upload (Phase 21 D-13). Blade change → needs `php artisan optimize:clear` after upload. **No migration.**

## Out of scope (planned separately)

- New "hardware supply-only" category — 14 `category === 'hardware'` branch sites need auditing first
- Bulk **category change** action (does not exist today; toolbar has only delete/restore/purge/split)
