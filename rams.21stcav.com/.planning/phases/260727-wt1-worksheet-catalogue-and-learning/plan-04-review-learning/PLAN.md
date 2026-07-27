---
plan: plan-04-review-learning
status: pending
depends_on: plan-03
scope: When a PM sets a category on /project-packages/{id}/review for a SKU the taxonomy doesn't know, LearnedTaxonomyWriter writes a source='learned' row. Next project auto-classifies.
estimated: 0.5 day
---

## Objective

Turn the QW package review page into a self-improving taxonomy source.
Every unknown SKU a PM classifies teaches the system. Zero AI, zero
config edit, zero deploy.

## Tasks

### Task 1 — LearnedTaxonomyWriter service

`App\Services\Worksheet\LearnedTaxonomyWriter`:
- Public method `writeFromReview(ProjectPackage $package, array $equipment, User $actor): int` — returns count of new learned rows written.
- For each equipment row where `category` is set:
  - Skip if `category === 'unknown'` (per 260726-fx5 — Unknown is a
    deferral, not a decision).
  - Lookup `ProductTaxonomyRepository::findByExactSku($row['part_number'])`.
  - If found → skip (already known).
  - If not found → build a learned row:
    - `sku_pattern` = `$row['part_number']` (exact — the PM's decision
      is a per-SKU truth, not a family truth).
    - `manufacturer` = derived from `$row['name']` (first word if
      capitalised — Crestron / Sony / etc); nullable.
    - `description_pattern` = first 80 chars of `$row['name']`.
    - `worksheet_category` = map from equipment category to worksheet
      category (see Task 2).
    - `source` = 'learned'.
    - `learned_from_package_id` = `$package->id`.
    - `created_by` = `$actor->id`.
  - Call `ProductTaxonomyRepository::learn($data)`.
- Idempotent — repeated saves of same row don't duplicate (repository
  uses `firstOrCreate` on `sku_pattern`).

### Task 2 — Category mapping

Equipment categories from the QW review page dropdown (8 values per
260726-fx5) map to worksheet categories:

| Equipment category | Worksheet category |
|---|---|
| hardware | (defer — PM must pick specific worksheet category first) |
| cables | control (or new `cables` sub-category — deferred) |
| consumables | (skip — not on worksheet) |
| services | (skip — worksheet excludes labour) |
| service_contracts | (skip — worksheet excludes warranty) |
| customer_supplied | (skip — worksheet excludes existing kit) |
| option | (skip — worksheet excludes optional items) |
| unknown | (skip — deferral, not decision) |

**Only `hardware` produces a learned row — and only when the PM also
sets a `worksheet_category` on the row.** This requires a NEW field on
the review page: "Worksheet Category" dropdown alongside the existing
equipment category, populated only when equipment category is
`hardware`. Six values: display / video_conferencing / audio / control
/ rack / network. Auto-suggests from the classifier's best guess but
PM can override.

### Task 3 — Review page dropdown addition

`resources/views/project-packages/review.blade.php`: add a "Worksheet
Category" column to the equipment table, visible only when equipment
category is `hardware`. Alpine.js `x-show` reactive on the existing
`data-equip-category` select.

Value posts as `equipment[N][worksheet_category]`. Round-trips via
`parseReviewPayload` alongside the existing equipment shape.

### Task 4 — Wire into save + approve

`ProjectPackageReviewController::update()` + `::approve()` — after
payload is validated + saved, dispatch
`LearnedTaxonomyWriter::writeFromReview($package, $equipment, auth()->user())`.

Async? Sync is fine — this is a lightweight DB insert per row (max
~100 rows per save; typical <20). Queue-based dispatch is over-
engineering.

### Task 5 — Feature tests

`tests/Feature/Worksheet/ReviewLearningTest.php`:
- PM classifies a novel SKU with worksheet_category set → row written.
- PM saves again → no duplicate row (idempotent).
- PM classifies a SKU that already exists in seed catalogue → no
  learned row (skip when already known).
- PM classifies but leaves worksheet_category blank → no learned row.
- PM sets equipment category to `unknown` → no learned row.
- Learned row's `learned_from_package_id` + `created_by` populated.

## Constraints

- No changes to renderers.
- No changes to WorksheetClassifier — repository handles the read; this
  plan only writes.
- Existing 8-value equipment category dropdown unchanged. New
  `worksheet_category` dropdown is additive.
- Idempotent — safe to run multiple times on same package.

## Commits (target)

1. `feat(worksheet): LearnedTaxonomyWriter service + tests (plan-04)`
2. `feat(review): worksheet_category dropdown on equipment table (plan-04)`
3. `feat(review): dispatch LearnedTaxonomyWriter on save + approve (plan-04)`
4. `test(worksheet): review-page learning end-to-end (plan-04)`

## Deliverable check

At plan close:
- PM regenerates Tilda worksheet → still shows Unclassified section.
- PM opens review page, sets worksheet_category for a Crestron TSS-1070
  row → clicks Save.
- PM regenerates Tilda worksheet → the TSS-1070 row now appears under
  the chosen category, not Unclassified.
- Fresh project on a different account importing a TSS-1070 →
  auto-classified via the learned row.
