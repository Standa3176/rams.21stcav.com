---
name: 260725-fx1-multi-select-delete-and-tidy-desc-only
description: Two user-reported fixes on /project-packages/{id}/review. (1) Multi-select delete state got out of sync when a per-row × was clicked mid-selection — auto-deselect the row from the toolbar when any row-level action fires. (2) "Tidy line items" keeps failing on large quotes AND rewrites part numbers when it should only shorten descriptions — prompt+controller+JS all changed to name-only, plus batching so it doesn't blow the response token budget.
status: in-progress
tasks: 2
---

# Multi-select delete UX + Tidy-lines simplification

## Why

### Fix 1 — Multi-select delete state drift

User reports:
- Selects 3 items via checkboxes → yellow toolbar shows "3 selected"
- Clicks the row-level × on one → that row soft-deletes (correct)
- Toolbar now shows "2 selected" but bulk **Delete** is greyed out

The row-level `softDelete` handler flips `data-deleted="1"` but leaves the row's checkbox in `selectedRowIds`. CSS then hides the row (`display: none`), which triggers Alpine to re-sync the `x-model="selectedRowIds"` binding — but the sync happens against a hidden element and the value gets dropped from the array unreliably. Race between Alpine re-render and DOM query in `_selectedRows()` explains the "greyed Delete" without a live repro.

Fix: **explicitly deselect the row from `selectedRowIds` when any row-level action fires**. State stays consistent, no reliance on Alpine's implicit sync on hidden elements.

### Fix 2 — Tidy-lines fails on large quotes + does too much

Current `EquipmentLineCleanupPrompt` asks the model to:
- Normalise part numbers (upper-case, hyphens, strip whitespace)
- Rewrite descriptions to engineer-shorthand

Two problems:
1. **User's intent is descriptions only** — "it should only shorten/tidy desc ie from sales version to simpler engineer ones". Part-number rewrites are unwanted mutation (they're already the canonical QW code).
2. **Token overflow on large quotes** — `maxTokens = 4096`, prompt budgets ~80 tokens per line × 60 lines ≈ 4800. Real quotes (Tilda has 313 rows post-qw3 re-classify) blow the ceiling → truncated JSON → parse failure → "Cleanup failed. Please try again."

Both fixed together: strip part_number from the prompt (halves per-row tokens), batch the equipment array (40 rows per call), aggregate. Per-batch failure is contained — the rest of the batches still land.

## Task 1 — Multi-select delete state fix

**File:** `resources/views/project-packages/review.blade.php`

Inside `equipmentSection()`:

1. Add internal helper:
   ```js
   _deselectRow(row) {
       if (!row) return;
       const id = row.dataset.rowId;
       if (id == null) return;
       // Alpine's x-model can push string, number, or coerced values into
       // the array depending on Alpine version — filter all three forms
       // to be safe against future Alpine drift.
       this.selectedRowIds = this.selectedRowIds.filter(rid =>
           String(rid) !== String(id)
       );
       // Uncheck the DOM checkbox too so the visual state matches even
       // when the row is hidden (display: none — CSS still renders the
       // checkbox as "checked" in inspector).
       const cb = row.querySelector('input.row-select');
       if (cb) cb.checked = false;
   },
   ```

2. Update `softDelete(row)`, `restore(row)`, `purge(row)` to call `this._deselectRow(row)` immediately AFTER the state change (so canBulk() sees the correct count on next reactive tick).

3. Update the row `×` tooltip: `title="Delete this row (use the toolbar Delete for multi-select)"`

4. Update the bulk-toolbar Delete button tooltip: `title="Move all selected rows to deleted"` (was `"Move selected active rows to deleted"` — cleaner).

**Gate:** click through in browser: tick 3 → row-x on 1 → toolbar count drops to 2 → Delete stays enabled → click Delete → both remaining rows soft-delete.

**Commit:** `fix(review): auto-deselect row from bulk-toolbar when per-row action fires (260725-fx1)`

## Task 2 — Tidy-lines: descriptions only + batching

**File 1:** `app/Core/AI/Prompts/EquipmentLineCleanupPrompt.php`

Strip everything to descriptions:
- Delete the "PART NUMBER rules" block from `systemMessage()`
- Update the "EXAMPLES:" block to show only name transformations
- Change output schema to `{ "items": [ { "id": 0, "name": "..." } ] }` (no `part_number` key)
- Update `build()` prompt copy to match

Lower per-line output budget (was ~80 tokens including part number, now ~40 for name only) — keep `maxTokens` at 4096 for now (batching handles overflow).

**File 2:** `app/Http/Controllers/ProjectPackageReviewController.php` — `cleanupLines()`

- Chunk `$equipment` into batches of 40 rows via `array_chunk` (preserving original numeric keys via `array_chunk($equipment, 40, true)`)
- For each batch: build input, run AIManager, aggregate into a single `$byId` map
- On per-batch failure: log the batch index + error, `continue` to next batch (don't fail the whole request)
- Only update `$equipment[$i]['name']` — leave `part_number` untouched
- Return only the name field in the `rows[]` response
- If ALL batches failed, return the current 500 error; if SOME succeeded, return 200 with partial `rows[]` (JS patches what it can)

**File 3:** `resources/views/project-packages/review.blade.php`

- Update `cleanupEquipmentLines(btn)` confirm dialog copy:
  ```
  Shorten AI descriptions across every line item? This rewrites verbose
  sales-quote text into short engineer-friendly descriptions (e.g. "Samsung
  55″ QM55C display"). Part numbers are NOT modified. Unsaved edits will
  be overwritten by the saved data, so save first if you have uncommitted changes.
  ```
- Remove the `partEl` patch loop — only patch `nameEl`

**Gates:**
- `php -l` clean on both PHP files
- Manual smoke on live: click "Tidy" on a large quote (Tilda re-import) → alerts about batching in log, updates name-only, no part_number churn.
- Optional micro-test: unit test on the prompt asserting the output schema doesn't mention part_number.

**Commit:** `feat(review): line cleanup — description-only + batch large quotes (260725-fx1)`

## Task 3 — Docs + push

STATE.md row + SUMMARY.md + push both remotes.

**Commit:** `docs(quick-260725-fx1): PLAN + SUMMARY + STATE for multi-select + tidy-desc fixes`

## Global constraints

- No DB migration.
- No npm build (Blade + PHP only).
- `php -l` after every PHP edit.
- Existing `--filter ProjectPackageReview|EquipmentLineCleanup` must stay green (or updated).

## Explicit non-goals

- **Wholesale rewrite of `EquipmentLineCleanupPrompt`** — keep the shape (still returns JSON with `items[]`); just drop the part_number field.
- **Async / queued cleanup** — the sync fetch still works after batching; queueing large-quote cleanup is a follow-up if PMs report waiting >30s.
- **Part-number cleanup as an opt-in flag** — deleted from scope entirely. If PMs want it back, they can ask for a separate button.
- **Category / area normalisation as part of cleanup** — separate feature.

## Deploy

- No migrations. No npm build.
- Server as stcav: `git pull && php artisan optimize:clear && php artisan config:cache`.
- Sanity: (1) tick 3 rows → click row × on one → confirm the toolbar Delete stays enabled and count drops to 2. (2) Click "Tidy" on a Tilda-sized quote → confirm no failure, only names change.
