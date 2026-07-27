---
plan: plan-02-classifier-db
status: pending
depends_on: plan-01
scope: Refactor WorksheetClassifier to consume ProductTaxonomyRepository behind WORKSHEET_TAXONOMY_DB kill switch. Snapshot parity test proves DB path matches config path.
estimated: 0.5 day
---

## Objective

Move classifier lookups from config to DB. Kill switch lets us
develop + verify the DB path against the current config path
byte-for-byte before flipping globally.

## Tasks

### Task 1 — Kill switch

`config/worksheet.php` (create if absent) gains:
```php
'taxonomy_source' => env('WORKSHEET_TAXONOMY_DB', false) ? 'db' : 'config',
```

### Task 2 — Refactor WorksheetClassifier

Introduce a `TaxonomySource` interface with two implementations:
- `ConfigTaxonomySource` — reads `config('worksheet_taxonomy')`
  (current behaviour, extracted verbatim).
- `DbTaxonomySource` — reads via `ProductTaxonomyRepository`.

`WorksheetClassifier` constructor takes `TaxonomySource` via DI. Which
implementation binds depends on `config('worksheet.taxonomy_source')`
resolved once in `AppServiceProvider::register()`.

Tier logic stays identical — T1 SKU, T2 manufacturer+keyword, T3
keyword-only, T4 context (mount inherit, warranty, existing). Only
the SOURCE of the tiered rules changes.

### Task 3 — Behavioural config stays

`mount_inherit_keywords`, `warranty_keywords`, `existing_keywords`,
`exclude_keywords` are behavioural rules, not taxonomy — they still
read from config in both source implementations. Only category-mapping
data (T1/T2/T3) moves to DB.

### Task 4 — Snapshot parity test

`tests/Feature/Worksheet/ClassifierParityTest.php`:
- Load a fixture equipment list covering every category
  (Sony display, Cisco codec, Shure mic, Q-SYS DSP, Netgear switch,
  Middle Atlantic rack, Chief mount, Crestron control, plus edge cases
  from Tilda's 19 unclassifieds after they've been added to the DB
  seed via 260727-fx6-quick or a Plan 01 seed extension).
- Run classifier with `WORKSHEET_TAXONOMY_DB=false` — capture output.
- Run classifier with `WORKSHEET_TAXONOMY_DB=true` — assert same output.
- Fails loudly on ANY per-item category difference.

### Task 5 — Fixture seed extension

Extend the Plan 01 seeder to include the Tilda edge cases so the
parity test has real data. If 260727-fx6 quick task already added
Crestron/SurgeX/AirMedia to config, port those into DB seed rows too.

## Constraints

- With flag off: identical worksheet output (verified by re-running
  Tilda worksheet 14 fixture through both paths).
- With flag on: same. Any per-item drift fails the snapshot test.
- No changes to renderers or write paths.
- `php -l` clean, all new tests green.

## Commits (target)

1. `feat(worksheet): WORKSHEET_TAXONOMY_DB kill switch + TaxonomySource interface (plan-02)`
2. `feat(worksheet): DbTaxonomySource reading via repository (plan-02)`
3. `test(worksheet): classifier parity test — config path ↔ DB path (plan-02)`

## Deliverable check

At plan close:
- With flag off: unchanged behaviour.
- With flag on: identical classification output on every fixture item.
- Parity test green in both directions.
