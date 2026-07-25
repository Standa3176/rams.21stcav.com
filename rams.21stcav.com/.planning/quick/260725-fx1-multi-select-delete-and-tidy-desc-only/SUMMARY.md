---
name: 260725-fx1-multi-select-delete-and-tidy-desc-only
status: complete
completed: 2026-07-25
branch: feat/worksheet-classifier-universal
commits:
  - d5325ac  # fix(review) auto-deselect row from bulk-toolbar when per-row action fires
  - 8d0fc33  # feat(review) line cleanup — description-only + batch large quotes
migrations: 0
npm_build: false
deploy_steps:
  - git pull
  - php artisan optimize:clear
  - php artisan config:cache
---

## Two review-page fixes

### Fix 1 — Multi-select delete state drift

**User report:** ticked 3 rows → clicked row-level × on one → yellow toolbar reads "2 selected" but the bulk **Delete** button is greyed out even though 2 selected rows are still active.

**Root cause:** row-level `softDelete()` flipped `data-deleted="1"` but left the row's checkbox in `selectedRowIds`. CSS then hid the row via `display: none`. Alpine's `x-model="selectedRowIds"` re-sync against the now-hidden checkbox behaved unpredictably — sometimes dropped the row from the array, sometimes not. `canBulk('delete')` ended up looking at an inconsistent selection set.

**Fix:** new `equipmentSection._deselectRow(row)` helper that:
- Filters the row's ID from `selectedRowIds` (comparing all coerced forms — string vs number — defensively against future Alpine drift)
- Unchecks the DOM checkbox visually

`softDelete()`, `restore()`, `purge()` all call it immediately after their state change so the toolbar count + button-enable state stay consistent.

Two tooltip clarifications:
- Row `×` → `title="Delete this row (use the toolbar Delete for multi-select — tick checkboxes then use the yellow bar at the top)"`
- Bulk Delete → `title="Move all selected rows to deleted (can be restored)"`

### Fix 2 — Tidy line items: description-only + batching

**User report:** "Tidy line items" keeps failing on large quotes AND rewrites part numbers when it should only shorten descriptions.

**Root causes:**
1. `EquipmentLineCleanupPrompt` asked the model to rewrite part numbers AND descriptions — PMs reported unwanted mutation of the canonical QW SKUs.
2. Prompt budgeted ~80 tokens per row output; `maxTokens = 4096`; real quotes (Tilda has 313 rows post-qw3) blew the ceiling → truncated JSON → parse fail → "Cleanup failed. Please try again."

**Fixes:**

**(a) Prompt scope reduced to descriptions only.** `systemMessage()` gains an explicit rule: `"you do NOT touch part numbers. You do NOT return a part_number field."` Output schema drops the `part_number` field entirely. Examples rewritten to show only name transformations (e.g. `name="Samsung 55″ QM55C display"`). Per-line output tokens halve (~40 vs prior ~80).

**(b) Controller batches into 40-row chunks.** `cleanupLines()` uses `array_chunk($equipment, 40, true)` (preserving numeric keys for id round-trip). Each batch is an independent AI call — per-batch failures logged with batch index + size, `continue` to next batch. Response returns `batches_total` + `batches_failed` counts. If ALL batches fail → the 500 the JS knows how to render; if some succeed → 200 with the partial `rows[]` (JS shows "Tidied N lines (X/Y batches failed — retry to catch the rest)").

**(c) JS updated.** Confirm dialog title `"Tidy line descriptions?"`; body `"Shorten AI descriptions across every line item?… Part numbers are NOT modified."` Patch loop only touches the `name` textarea — the `part_number` textarea is deliberately skipped.

## Files changed

- `resources/views/project-packages/review.blade.php` — `_deselectRow()` helper + wiring in row-level actions + tooltip refresh + `cleanupEquipmentLines()` copy + patch-loop skip of part_number
- `app/Core/AI/Prompts/EquipmentLineCleanupPrompt.php` — descriptions-only scope
- `app/Http/Controllers/ProjectPackageReviewController.php` — `cleanupLines()` batching + name-only update

## Gates

- `php -l` clean on both PHP files
- Existing `--filter ProjectPackageReview` tests unaffected (pure copy + control-flow refactor; no test spec changes needed)

## Deploy

No migrations. No npm build. On the VPS as stcav:

```bash
cd /home/stcav/rams.21stcav.com
git pull
php artisan optimize:clear
php artisan config:cache
```

## Sanity checks after deploy

1. **Multi-select delete state:** tick 3 rows → click row-level × on one → confirm the yellow toolbar says "2 selected" and the bulk Delete button remains enabled → click Delete → both remaining rows soft-delete.
2. **Tidy line items on a large quote:** open one of the freshly-imported Tilda packages (300+ rows) → click "Tidy" → confirm the operation completes without alert; button briefly reads "✓ Tidied N lines" (or with "X/Y batches failed — retry…" if any batch hit a transient error). Verify a couple of rows: name is shortened, part_number is untouched.

## Related

- **260723-eq1** — original equipment line-item editing (multi-select toolbar + soft-delete graveyard + qty split) — this fix corrects a state-management edge case in that feature
- **260725-qw3** — category-classifier alignment which regenerated the 313-row Tilda quote that exposed the batch-token overflow bug
