---
name: 260723-eq1-equipment-line-item-editing
description: Add soft-delete/restore/purge, multi-select, and qty-split to the equipment table on /project-packages/{id}/review. Small footprint — no DB migration, graveyard pattern keeps downstream consumers untouched.
status: in-progress
tasks: 3
---

# Equipment line-item editing — soft-delete + split + multi-select

## Why

Current `#s-equipment` table on `resources/views/project-packages/review.blade.php` only supports "add row" and "remove row" (`removeRow(this)` — DOM-only delete with a confirm). No history, no undo, no way to bulk-act, no way to split a `qty:3` line into three `qty:1` lines for per-unit routing / labelling / warranty.

Three feature gaps to close:
1. **Soft delete → restore → purge** — deleted rows survive save into a graveyard until explicitly purged. PM can undo a mis-click after saving.
2. **Multi-select** — sticky toolbar for bulk delete / restore / purge / split.
3. **Split qty>1 into individual lines** — one `qty:3` row → three `qty:1` rows keeping all other fields identical.

## Storage design — no DB migration

New JSON field on `ProjectPackage.reviewed_data`:
- **`equipment_deleted[]`** — graveyard array of items the PM soft-deleted. Same schema as `equipment[]` items but with three extra keys: `deleted: true`, `deleted_at: iso8601`, `deleted_by: user_id`.

On save, `parseReviewPayload()` splits incoming rows into `active[]` and `deleted[]`:
- Active rows land in `reviewed_data.equipment[]` — clean, no `deleted` flag
- Soft-deleted rows land in `reviewed_data.equipment_deleted[]` — with stamps
- Purged rows (removed from DOM entirely) don't submit at all → not persisted anywhere → gone

On page load, Blade iterates BOTH arrays and renders each row with a `deleted` state. Downstream services (`OmManualGeneratorService`, `BuildRamsDocumentJob`, `MiniOmBuilderService`, `ProjectPackageRamsReviewService`, `ProjectPackageReviewController` itself) read `reviewed_data.equipment[]` — which stays clean — so no changes needed there.

## Task 1 — Storage + server-side round-trip

**Files:**
- `app/Http/Controllers/ProjectPackageReviewController.php` — `parseReviewPayload()` accepts `equipment[N][deleted]` (default "0"), splits into active + deleted arrays; `update()` writes both back into `merged['equipment']` + `merged['equipment_deleted']`; approve() mirrors the same split (approvals should not resurrect deleted lines).

**Split logic in the controller:**
```php
$active = []; $deleted = [];
foreach ($rawEquipment as $item) {
    $entry = $this->normaliseEquipmentEntry($item);  // existing quantity + part_number + name + area + category + zone
    if (!empty($item['deleted'])) {
        // Preserve original deletion stamps if resubmitted with them
        $entry['deleted']    = true;
        $entry['deleted_at'] = $item['deleted_at'] ?? now()->toIso8601String();
        $entry['deleted_by'] = (int) ($item['deleted_by'] ?? auth()->id());
        $deleted[] = $entry;
    } else {
        $active[] = $entry;
    }
}
$raw['equipment']         = $active;
$raw['equipment_deleted'] = $deleted;
```

Note: `_original_totals` snapshot is calculated only from `$active` — it already only fires on first save, so historical baselines are unaffected. Deleting a row after that snapshot is a legit qty deviation the mismatch check surfaces (existing behaviour, keep it).

**Gates:** existing controller tests must still pass. Add a targeted unit test that constructs a request payload with mixed deleted/active rows and asserts round-trip separation.

**Commit:** `feat(review): equipment soft-delete storage — graveyard round-trip in parseReviewPayload (260723-eq1)`

## Task 2 — Client-side UI: row-level actions + graveyard rendering

**Files:**
- `resources/views/project-packages/review.blade.php` — equipment table markup + JS

**Render both arrays on load:**
Loop `$reviewed_data['equipment']` first (deleted=false), then `$reviewed_data['equipment_deleted']` (deleted=true), rendering the same row template. Each row carries a hidden `equipment[N][deleted]` input reactive to Alpine state.

**Row visual states:**
- **Active** — unchanged existing styling
- **Soft-deleted** — greyed background (`opacity: .5`), strikethrough on `name` + `part_number`, `× Remove` replaced with `↺ Restore` and `🗑 Purge`
- Add a header toggle: `Show N deleted rows` (default: hidden). When hidden, deleted rows have `x-show="showDeleted"` on their `<tr>`.

**Alpine.js state (add to the wrapping `<div>` around the equipment section):**
```js
x-data="{
  showDeleted: false,
  softDelete(row) { row.dataset.deleted = '1'; row.querySelector('input[name*=\"[deleted]\"]').value = '1'; this.$refs.status.textContent = 'Row moved to deleted.'; },
  restore(row)    { row.dataset.deleted = '0'; row.querySelector('input[name*=\"[deleted]\"]').value = '0'; },
  purge(row)      { row.remove(); ensureEquipmentEmptyState(...); },
}"
```

**Row-level buttons:**
- **Active rows** — `× Delete` (soft-delete with confirm — "Move to deleted? You can restore before approving.")
- **Deleted rows** — `↺ Restore` (no confirm) + `🗑 Purge` (confirm — "Permanently delete? This cannot be undone.")

**Split button:**
- New `⎘ Split` button on every row (only enabled when `quantity > 1`)
- Client-side: clones the row `qty - 1` times, sets `quantity = 1` on each, re-indexes all `equipment[N]` names starting from N+1 for the new rows so they don't collide with existing ones.
- Preserves all other fields (part_number, name, area, category, zone).
- Split rows land immediately after the source row for visual continuity.

**Style adds** (scoped to `#s-equipment`):
```css
#s-equipment tr[data-deleted="1"] { opacity: .5; background: #FEF3F2; }
#s-equipment tr[data-deleted="1"] .equip-input,
#s-equipment tr[data-deleted="1"] input[name*="[name]"] { text-decoration: line-through; }
```

**Gates:**
- `npm run build` succeeds
- Manual click-through: add row → save → reload; delete row → save → row appears in "deleted" fold; restore → save → row back in active; purge → save → gone; split qty=3 → save → 3 rows persist.

**Commit:** `feat(review): equipment soft-delete/restore/purge + qty-split UI (260723-eq1)`

## Task 3 — Multi-select bulk toolbar

**Files:**
- `resources/views/project-packages/review.blade.php` — checkbox column + sticky toolbar

**Add checkbox column** at the very start of each equipment row (`<td class="col-select"><input type="checkbox" x-model="selected" :value="rowIndex"></td>`).

**Header:** master checkbox in `<thead>` → select all visible (respects the `showDeleted` filter).

**Sticky bulk toolbar** appears at the top of the equipment section when `selected.length > 0`:
```
[3 selected] Delete · Restore · Purge · Split · Clear
```

Buttons only enable when the action is applicable to the current selection:
- **Delete** — enabled if any selected are active
- **Restore** — enabled if any selected are deleted
- **Purge** — enabled if any selected are deleted (extra-danger confirm — "Permanently delete 3 rows? This cannot be undone.")
- **Split** — enabled if any selected have `qty > 1` (splits each matching row individually)

**Alpine state:**
```js
x-data="{
  selected: [],
  toggleAll(e) { this.selected = e.target.checked ? Array.from(rows.map(r => r.id)) : []; },
  bulkAction(kind) { this.selected.forEach(id => rowAction(kind, findRow(id))); this.selected = []; },
}"
```

**Gates:** manual click-through — select 3 rows → bulk delete → all 3 in deleted fold on next reload.

**Commit:** `feat(review): equipment multi-select bulk actions (260723-eq1)`

## Task 4 — STATE + SUMMARY + push

Standard closeout:
- STATE.md row (paste under 260723-rr1)
- SUMMARY.md
- Push to `live` + `origin`
- Deploy notes: `git pull && php artisan optimize:clear && php artisan config:cache && npm run build`

**Commit:** `docs(quick-260723-eq1): PLAN + SUMMARY + STATE row for equipment editing`

## Global constraints

- **No DB migration** — everything lives in existing `ProjectPackage.reviewed_data` JSON.
- **Downstream services untouched** — graveyard split in `parseReviewPayload` keeps `equipment[]` clean.
- **`php -l`** after every PHP edit.
- **`npm run build`** before each commit that touches Blade/JS.
- **Existing tests** (`--filter ProjectPackageReview|EquipmentClassifier`) must stay green.
- Alpine.js only — no new JS framework, no new npm deps.
- Commit prefix: `feat(review):` for functional commits, `docs(quick-...)` for the closeout.

## Explicit non-goals

- **Row-level audit log** — who deleted / restored / when, per row, in a persistent event log. `deleted_at` + `deleted_by` on the graveyard entry is enough for now.
- **Cross-package undo** — deleted rows stay on their package; no global recycle bin.
- **Server-side split endpoint** — split is pure client-side (clone the DOM row). No AJAX call.
- **Regen impact preview** — no "This will remove 3 rows from cable schedule / RAMS section 3" preview. Consumers just skip the removed rows next generation. Add a preview later if PM feedback asks for it.

## Deploy

- **No migrations.**
- `git pull && php artisan optimize:clear && php artisan config:cache && npm run build`
- Sanity check on live: open a completed package review, delete a row, save, reload, verify the row shows in the deleted fold (click "Show N deleted") with restore + purge buttons.
