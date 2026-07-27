---
plan: plan-01-schema-seed
status: pending
scope: DB migration + Eloquent model + ProductTaxonomyRepository + seeder that ports config/worksheet_taxonomy.php values into rows. Zero classifier or renderer changes.
estimated: 0.5 day
---

## Objective

Land the DB primitive that Plans 2-5 depend on. Pure additive — the
classifier still reads from the config file until Plan 02 flips the
kill switch.

## Tasks

### Task 1 — Migration + model

New migration `2026_07_27_000001_create_product_taxonomy_table.php`
per the shape in `PHASE.md`. Columns:
- `sku_pattern` VARCHAR(120) nullable + indexed (exact match; `*`
  wildcard tolerated in future — Plan 02 handles the matcher).
- `manufacturer` VARCHAR(60) nullable.
- `description_pattern` VARCHAR(255) nullable (substring match).
- `product_family` VARCHAR(120) nullable (human label).
- `worksheet_category` ENUM — 6 canonical values from
  `config/worksheet_taxonomy.php` categories map + `'unclassified'`
  as an explicit sentinel.
- `install_step_hint` TEXT nullable.
- `source` ENUM `'seed','learned','admin'` — default `seed`.
- `learned_from_package_id` FK nullable.
- `created_by` FK nullable, `promoted_by` FK nullable, `promoted_at`
  timestamp nullable, standard timestamps.
- Composite index `(manufacturer, description_pattern)`.

Model `App\Models\ProductTaxonomy` — `$fillable` for all writable
fields, `$casts` for timestamps + enums. Relationships:
`->creator()`, `->promoter()`, `->learnedFromPackage()`.

### Task 2 — Repository

`App\Repositories\ProductTaxonomyRepository`:
- `findByExactSku(string $sku): ?ProductTaxonomy`
- `findByManufacturerAndKeyword(string $manufacturer, string $description): ?ProductTaxonomy`
- `findByKeywordOnly(string $description): ?ProductTaxonomy`
- `learn(array $data): ProductTaxonomy` — used by Plan 04's writer.
- Singleton-bound in service provider. All lookups query the DB (Plan
  02 wires the classifier to consume this; Plan 04 wires the writer).

### Task 3 — Seeder from config

`database/seeders/ProductTaxonomySeeder.php` ports every entry in
`config/worksheet_taxonomy.php`:
- `sku_map` entries → rows with `sku_pattern` set + `source='seed'`.
- `manufacturer_rules` → rows with `manufacturer` + representative
  `description_pattern` (one row per keyword in each rule — expanded
  from the compact config shape). Same `worksheet_category` as the
  rule.
- `keyword_rules` → rows with only `description_pattern` set (no
  manufacturer). Tier 3 fallback preserved.
- `mount_inherit_keywords`, `warranty_keywords`, `existing_keywords`,
  `exclude_keywords` — these are behavioural, not taxonomy — stay in
  config, NOT ported. Plan 02 keeps reading them from config.

Seeder is idempotent — `updateOrCreate` on `(sku_pattern,
manufacturer, description_pattern, worksheet_category)`. Safe to
re-run.

### Task 4 — Unit tests

- `ProductTaxonomyModelTest` — construction, casts, relationships.
- `ProductTaxonomyRepositoryTest` — each of the three finders returns
  the seeded row for known inputs, null for unknowns.
- `ProductTaxonomySeederTest` — count of rows after seeding matches
  count of config entries within tolerance (each manufacturer_rule
  expands to N rows where N = keyword count). Re-running seeder does
  not duplicate.

## Constraints

- No changes to `WorksheetClassifier` or any renderer.
- No changes to `config/worksheet_taxonomy.php` — config stays as
  authoritative seed source.
- Migration is reversible — `down()` drops the table cleanly.
- `php -l` clean, all new tests green, existing suite green.

## Commits (target)

1. `feat(worksheet): product_taxonomy migration + model (plan-01)`
2. `feat(worksheet): ProductTaxonomyRepository + finders (plan-01)`
3. `feat(worksheet): seed catalogue from config/worksheet_taxonomy (plan-01)`
4. `test(worksheet): coverage for taxonomy model + repo + seeder (plan-01)`

## Deliverable check

At plan close:
- Migration runs cleanly forward + back.
- Seeder produces N rows where N ≈ config entry count.
- Repository finds seeded rows.
- Prod worksheet output identical (classifier still on config).
