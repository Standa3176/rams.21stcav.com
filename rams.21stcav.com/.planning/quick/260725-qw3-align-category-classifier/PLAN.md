---
name: 260725-qw3-align-category-classifier
description: Fix the equipment category vocabulary mismatch that flattens everything to `hardware` on QW import + save. Extract to shared classifier. Reroute non-room section headers (Professional Services, Delivery, Summary) so they don't leak into `area`. Add retroactive reclassify command.
status: in-progress
tasks: 4
---

# Align equipment category classifier + non-room section-header re-routing

## Why (three bugs, one fix)

### Bug 1 — Vocabulary mismatch between QW classifier and canonical set

`QuoteWerksImportService::classifyDescription()` returns fabricated values that are NOT in the canonical vocabulary the review UI expects:

| Where | Vocabulary | Values |
|---|---|---|
| QW classifier (`classifyDescription`) | Fabricated | `display`, `audio`, `camera`, `cable`, `mounting`, `signal_distribution`, `control`, `furniture`, `service`, `other` |
| Review page allowlist (`normaliseEquipmentCategory`) | 5 of 7 canonical | `hardware`, `cables`, `consumables`, `services`, `option` — **MISSING `service_contracts` + `customer_supplied`** |
| Review UI dropdown (Blade + JS) | 7 canonical | `hardware`, `cables`, `consumables`, `services`, `service_contracts`, `customer_supplied`, `option` |

Failure mode on QW-imported package save:
1. QW writes `category = 'display'` for a Sony display row
2. On save, `normaliseEquipmentCategory` sees `'display'` isn't in `['hardware','cables','consumables','services','option']`
3. Falls to keyword matching — "Sony 75\" 4K UHD commercial display" doesn't match cable/consumable/service patterns
4. Defaults to `hardware`

**Result: everything from QW import ends up bucketed as `hardware`** — the review UI shows a single fat "Hardware" tab, all other tabs empty.

### Bug 2 — Live-code allowlist rejects `service_contracts` + `customer_supplied`

Even a user manually picking `service_contracts` from the dropdown gets it silently reverted to `hardware` on save because those two values aren't in `normaliseEquipmentCategory`'s allowlist (line 1178). The dropdown offers them; the save handler rejects them. Pre-existing bug independent of QW; discovered while diagnosing Bug 1.

### Bug 3 — Non-room section headers leak into `area`

QuoteWerks quotes use section headers (LineType 32/256) for two purposes:
- **Rooms**: `OREGANO`, `CINNAMON`, `SAFFRON` (physical spaces)
- **Groupings**: `ROOM BOOKING PANELS`, `Professional Services`, `Summary` (not rooms — either category batches or non-equipment sections)

The fetcher's `mapToParsedShape()` blindly threads ALL section headers into `area` on subsequent products AND appends all of them to `rooms[]`. Result: user sees "Professional Services" and "Room Booking Panels" as fake rooms alongside the real ones. That's the "categories look like install location" symptom from the user's report.

## What ships

### Task 1 — Shared `EquipmentCategoryClassifier` service

**New file:** `app/Services/Imports/EquipmentCategoryClassifier.php`
- Single method: `classify(array $item): string`
- Signature accepts equipment-row array; reads `name` + `description` + `part_number` fields (any/all)
- Returns exactly one of the 7 canonical values
- Priority-ordered decision tree (specific → broad):
  1. `option` — matches `optional`, `option`
  2. `customer_supplied` — matches `existing`, `client supplied`, `customer supplied`, `**client supplied**`, `client-supplied`, `byo`, `byod`
  3. `service_contracts` — matches `warranty`, `care pack`, `carepack`, `coverplus`, `assurcare`, `prosupport`, `service plan`, `extended service`, `swap out`, `year warranty`
  4. `consumables` — matches `consumable`, `fixing`, `fastener`, `rawlplug`, `anchor`, `screw`, `bolt`, `tape`, `label`, `cleat`, `tie`, `strap`
  5. `cables` — matches `cable`, `cat5`, `cat6`, `cat6a`, `cat7`, `cat8`, `hdmi cable`, `sdi`, `utp`, `ftp`, `stp`, `patch lead`, `patch cable`, `fibre`, `fiber optic`, `rg6`, `rg59`, `trunking`, `conduit`
  6. `services` — matches `install`, `installation`, `commission`, `configuration`, `programming`, `labour`, `support`, `survey`, `management`, `training`, `professional service`, `onsite service`, `on-site service`, `handover`, `delivery`, `rack build`
  7. Default: `hardware`
- Also accepts explicit `$item['category']` — if it's already one of the 7 canonical values, short-circuit and return it (respects manual dropdown selections)

**Refactor:** `ProjectPackageReviewController::normaliseEquipmentCategory()`
- Delegate to `EquipmentCategoryClassifier` (inject via constructor OR call via `app()` — pick DI to keep testable)
- Fixes Bug 2 as a side effect (allowlist expands to all 7 through the shared classifier)
- Remove the now-redundant keyword blocks in the controller

**Refactor:** `QuoteWerksImportService::buildExtractedData()`
- Delete inline `classifyDescription()` method entirely
- Call `EquipmentCategoryClassifier::classify()` (inject via constructor)
- Fixes Bug 1 — QW-imported categories now use the canonical vocab from day one

**Unit tests:** `tests/Unit/Services/Imports/EquipmentCategoryClassifierTest.php`
- Each of the 7 categories: at least 2 fixture rows per bucket verifying classification
- Explicit category short-circuit: `['category' => 'service_contracts', 'name' => 'Sony display']` returns `service_contracts` (not `hardware`)
- Priority ordering: `"Warranty extension - Cat6 cable"` returns `service_contracts` (not `cables`)
- Empty item: returns `hardware`
- Case insensitivity: `"WARRANTY"` and `"Warranty"` both classify as `service_contracts`

**Commit:** `refactor(review): extract shared EquipmentCategoryClassifier + fix service_contracts/customer_supplied allowlist gap (260725-qw3)`

### Task 2 — Wire QW importer through the shared classifier

Covered in Task 1 via the refactor. Keeping it as a separate commit for atomic diff clarity:

**Commit:** `feat(quotewerks): use canonical EquipmentCategoryClassifier for import (260725-qw3)`

### Task 3 — Non-room section-header re-routing in QW mapper

**File:** `app/Core/Modules/QuoteImport/QuoteWerksImportService.php`

Add a post-processing step in `buildExtractedData()` that runs AFTER the fetcher's shape lands but BEFORE the equipment array is written:

```php
private const NON_ROOM_SECTION_PATTERNS = [
    // pattern → forced_category (null = don't force)
    '/professional\s+services?/i'    => 'services',
    '/^\s*services?\s*$/i'           => 'services',
    '/^\s*labour\s*$/i'              => 'services',
    '/^\s*delivery\s*$/i'            => 'services',
    '/^\s*consumables?\s*$/i'        => 'consumables',
    '/^\s*summary\s*$/i'             => null,          // skip entirely
    '/room\s+booking\s+panels?/i'    => null,          // hardware, but not a room
];
```

For each incoming equipment row:
- If `area` matches a `NON_ROOM_SECTION_PATTERN`:
  - Set `area` = `''` (empty — Blade renders as "General" bucket for user reassignment)
  - If forced category set, override `category` with it
  - Otherwise let the shared classifier's output stand

For `rooms[]`:
- Filter out any names matching the patterns before returning

Regex approach with escape via `preg_match` — no need for a full patterns table extraction; keeping it inline. `Summary` typically has zero products after it in real QW quotes (per the tinker probe we ran), so the "skip entirely" branch is defensive.

**Unit tests:** in `QuoteWerksImportServiceTest`
- QW parsedShape with `rooms = ['Oregano', 'Professional Services', 'Summary']` → RAMS `rooms` = `['Oregano']` only
- Equipment row with `area = 'Professional Services'` → output has `area = ''`, `category = 'services'`
- Equipment row with `area = 'Room Booking Panels'` → output has `area = ''`, `category` unchanged (classifier's output preserved)
- Case-insensitive: `'PROFESSIONAL SERVICES'` and `'professional services'` both re-routed
- Case with only real rooms in mix: behavior unchanged

**Commit:** `feat(quotewerks): re-route non-room section headers (Professional Services, Delivery, Summary) to correct category (260725-qw3)`

### Task 4 — Retroactive reclassify artisan command

**New file:** `app/Console/Commands/PackagesReclassifyEquipmentCommand.php`

Signature:
```
packages:reclassify-equipment {package? : Package ID (optional; without = all packages)}
                              {--commit : Actually apply changes (default is dry-run)}
```

Behaviour:
- Walk `ProjectPackage::query()->get()` (or single package if `package` arg passed)
- For each: read `reviewed_data.equipment[]`, re-run classifier + non-room re-routing on each row
- Report per-package: `N rows unchanged, M rows recategorised, K areas cleared` — grouped table by category
- Also runs on `reviewed_data.equipment_deleted[]` (graveyard) for consistency
- Dry-run by default (mirrors the `cables:reimport-shorttag-quotes` pattern from 260604)
- `--commit` writes back to `reviewed_data`, bumps updated_at, logs an audit line
- **Idempotent** — running twice in a row with `--commit` produces zero diffs on the second run

**Unit tests:** `tests/Unit/Console/PackagesReclassifyEquipmentCommandTest.php`
- Dry-run makes no DB writes even when diffs exist
- `--commit` persists new categories
- Idempotence (run twice → zero diffs on run 2)
- Package with pre-existing correct categories → zero recategorisation reported

**Commit:** `feat(cli): packages:reclassify-equipment command to migrate pre-qw3 imports (260725-qw3)`

### Task 5 — Docs + push

Standard closeout: STATE.md row above 260725-qw2, SUMMARY.md, push both remotes.

**Commit:** `docs(quick-260725-qw3): PLAN + SUMMARY + STATE row for category classifier alignment`

## Global constraints

- **No DB migration** — everything lives in existing `ProjectPackage.reviewed_data` JSON.
- **No npm build** — pure PHP changes.
- `php -l` after every PHP edit.
- Existing `--filter QuoteWerks|QuoteImport|EquipmentClassifier|ProjectPackageReview` tests must stay green (or updated).
- Commit prefixes: `refactor(review)`, `feat(quotewerks)`, `feat(cli)`, `docs(quick-…)`.

## Explicit non-goals

- **`EquipmentClassifierService`** — the OTHER classifier at `app/Services/EquipmentClassifierService.php` produces activity keys (`display_installation`, `ceiling_works`, etc.) for RAMS activity generation. That's a DIFFERENT purpose and vocabulary — used for method statement + risk assessment building, NOT for the review UI's category buckets. Leave it alone.
- **Parenthetical room extraction** — the Tilda quote's `TSS-1070-B-S-LB-Kit (Vanilla)`, `(Poppy)` etc. could be parsed to route each touch panel to its actual room. That's a separate enhancement — the current fix just clears the fake "Room Booking Panels" area and lets the user re-assign via the multi-select bulk tools we shipped last week (260723-eq1).
- **PDF-import path re-classification** — the PDF parser (`QuoteParserService`) also writes `category` values that could benefit from the shared classifier. Focus is on QW direct-import for this task; PDF import consumption of the shared service can be a follow-up if PMs report PDF-imported classifications being off.
- **UI copy for `service_contracts` / `customer_supplied`** — no dropdown label changes; those already exist in the Blade dropdown correctly (line 2215-2216 of `review.blade.php`).

## Deploy

- No migrations.
- Server as stcav: `git pull && php artisan optimize:clear && php artisan config:cache`.
- Post-deploy: for each already-imported QW package, run `php artisan packages:reclassify-equipment` (dry-run) → review report → `php artisan packages:reclassify-equipment --commit`.
- Fresh QW imports from this point are correctly classified at import time.

## Sanity test after deploy

Re-import `21CQ29531-05-OPS` from QW → open the review page:
- **Before (current state):** 5 fake rooms including "Room Booking Panels" + "Professional Services"; every row bucketed as `hardware`.
- **After:** 3 real rooms only (Oregano, Cinnamon, Saffron); rows split correctly across `hardware`, `cables`, `consumables`, `services`, `service_contracts`. Warranty upgrades (`PSP.FW75BZ35L.PO2`) → `service_contracts`. `TRUNKING` → `cables`. `INSTALL2` / `HANDOVER` / `PROGRAMMING1` / `DELIVERY` / `PROJECTMANAGEMENT` → `services`. `**CLIENT SUPPLIED**` items (existing displays, PCs, cameras) → `customer_supplied`.
