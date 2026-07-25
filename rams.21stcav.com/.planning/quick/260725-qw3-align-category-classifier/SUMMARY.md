---
name: 260725-qw3-align-category-classifier
status: complete
completed: 2026-07-25
branch: feat/worksheet-classifier-universal
commits:
  - 3bfa578  # refactor(review) extract shared EquipmentCategoryClassifier + fix service_contracts/customer_supplied allowlist gap
  - c4e674e  # feat(quotewerks) use canonical EquipmentCategoryClassifier for import
  - ca1cdc2  # feat(quotewerks) re-route non-room section headers (Professional Services, Delivery, Summary) to correct category
  - df7c413  # feat(cli) packages:reclassify-equipment command to migrate pre-qw3 imports
  - cbca728  # docs(quick-260725-qw3) STATE row
  - 1486556  # docs(quick-260725-qw3) commit PLAN.md
tests: 77 tests / 240 assertions green (QuoteWerks|QuoteImport|ProjectPackageReview|EquipmentCategoryClassifier|PackagesReclassifyEquipment)
migrations: 0
deploy_steps:
  - git pull
  - php artisan optimize:clear
  - php artisan config:cache
  - php artisan packages:reclassify-equipment           # dry-run report
  - php artisan packages:reclassify-equipment --commit  # persist retro-fix
---

## What shipped — three related bugs, one coherent fix

### Bug 1 — vocabulary mismatch (fixed)

`QuoteWerksImportService::classifyDescription()` returned fabricated values (`display`, `audio`, `camera`, `cable`, `mounting`, `signal_distribution`, `control`, `furniture`, `service`, `other`) that were NOT in the review-UI's canonical 7-value set. On save, the controller's allowlist silently defaulted them all to `hardware` — every QW-imported package flattened to one Hardware tab.

**Fix:** `classifyDescription` deleted. QW importer now delegates to the shared `App\Services\Imports\EquipmentCategoryClassifier` — 7 canonical values, priority-ordered decision tree (`option` → `customer_supplied` → `service_contracts` → `consumables` → `cables` → `services` → `hardware` default). Specific matches beat broad ones — "Cat6 warranty extension" now correctly classifies as `service_contracts`, not `cables`.

### Bug 2 — controller allowlist missing 2 canonical values (fixed as side-effect)

`ProjectPackageReviewController::normaliseEquipmentCategory()` allowlist was `['hardware','cables','consumables','services','option']` — missing `service_contracts` + `customer_supplied`. Manual dropdown picks for those two silently reverted to `hardware` on save. Pre-existing bug independent of QW import.

**Fix:** `normaliseEquipmentCategory` delegates to the shared classifier; the explicit-category short-circuit returns any incoming canonical value verbatim before falling to keyword matching. All 7 are honoured.

### Bug 3 — non-room section headers leak into `area` (fixed)

QW quotes use section headers (LineType 32/256) for real rooms (Oregano, Cinnamon, Saffron) AND category groupings (Room Booking Panels, Professional Services, Summary, Delivery). The fetcher threaded ALL of them into `area` on subsequent products + appended all to `rooms[]`.

**Fix:** new `NON_ROOM_SECTION_PATTERNS` const in `QuoteWerksImportService` (7 regex patterns → forced category or null). `applySectionHeaderReroute()` runs post-classification in `buildExtractedData()`: wipes `area` on match, wipes `location` when defaulted from area, force-sets `category` when pattern implies one (Professional Services / Labour / Delivery → `services`; Consumables → `consumables`; Summary + Room Booking Panels → area cleared, classifier category stands). `isNonRoomSectionHeader()` also filters `rooms[]` + `room_overviews[]`. Post-processing lives in the RAMS-specific transformation layer — the SQL fetcher stays pure.

### Retroactive migration

New `php artisan packages:reclassify-equipment {package?} {--commit}` walks `ProjectPackage.extracted_data.equipment[]` (+ `equipment_list`, `line_items`, `equipment_deleted` graveyard). Dry-run default, idempotent, preserves manual dropdown picks (canonical categories short-circuit the classifier).

## Files created

- `app/Services/Imports/EquipmentCategoryClassifier.php`
- `app/Console/Commands/PackagesReclassifyEquipmentCommand.php`
- `tests/Unit/Services/Imports/EquipmentCategoryClassifierTest.php` — 19 tests / 42 assertions
- `tests/Unit/Console/PackagesReclassifyEquipmentCommandTest.php` — 7 tests / 27 assertions

## Files modified

- `app/Core/Modules/QuoteImport/QuoteWerksImportService.php` — DI classifier, deleted `classifyDescription`, added `NON_ROOM_SECTION_PATTERNS` + `applySectionHeaderReroute` + `isNonRoomSectionHeader` helpers, filter `rooms[]` and `room_overviews[]`
- `app/Http/Controllers/ProjectPackageReviewController.php` — DI classifier, `normaliseEquipmentCategory` now a 3-line delegate
- `tests/Unit/QuoteWerksImportServiceTest.php` — `makeService()` helper, updated stale category assertions, added 10 new tests (2 classification + 8 re-routing)
- `.planning/STATE.md` — new activity row above 260725-qw2

## Deviations from PLAN.md

1. **PLAN referenced `reviewed_data.equipment[]` for ProjectPackage — corrected to `extracted_data.equipment[]`.** Verified via `Schema::getColumnListing('project_packages')`: `reviewed_data` is a `RamsDocument` column, not a `ProjectPackage` column. The reclassify command walks `extracted_data.equipment[]` + `equipment_list[]` + `line_items[]` + `equipment_deleted[]` (graveyard also lives in `extracted_data`).
2. **Task 5 landed as 2 commits** (`cbca728` STATE.md + `1486556` PLAN.md) instead of 1 — PLAN.md was untracked when the STATE.md commit was made, and git-safety forbade amending. Both carry `docs(quick-260725-qw3):` prefix so history is unambiguous.
3. **SUMMARY.md** could not be written by the executor subagent (sandbox rule blocking `.md` report files); persisted by the parent orchestrator (this file).

## Gates green

- `php -l` on every edited PHP file — all "No syntax errors"
- `--filter EquipmentCategoryClassifier` → 19/19
- `--filter "QuoteWerks|QuoteImport|ProjectPackageReview"` → 43/43
- `--filter QuoteWerksImportServiceTest` → 26/26
- `--filter PackagesReclassifyEquipment` → 7/7
- Full-scope gate → **77 tests / 240 assertions green**

## Deploy

No migrations. No npm build.

```bash
sudo -u stcav bash
cd /home/stcav/rams.21stcav.com
git pull
php artisan optimize:clear
php artisan config:cache

# Migrate pre-qw3 imports (all packages by default; append a package ID to scope to one)
php artisan packages:reclassify-equipment            # dry-run report
php artisan packages:reclassify-equipment --commit   # persist retro-fix
```

## Sanity check on live

Re-open `21CQ29531-05-OPS`:
- **Before:** 5 fake rooms including "Room Booking Panels" + "Professional Services"; every row bucketed as `hardware`.
- **After:** 3 real rooms only (Oregano, Cinnamon, Saffron). Rows split across `hardware` / `cables` / `consumables` / `services` / `service_contracts`. Warranty upgrades (`PSP.FW75BZ35L.PO2`) → `service_contracts`. `TRUNKING` → `cables`. `INSTALL2` / `HANDOVER` / `PROGRAMMING1` / `DELIVERY` / `PROJECTMANAGEMENT` → `services`. `**CLIENT SUPPLIED**` items (existing displays, PCs, cameras) → `customer_supplied`.

## Related

- **260725-qw2** — room descriptions from CustomMemo01 (the immediate predecessor)
- **260723-qw1** — QuoteWerks direct-import replacing broken sqlsrv/wrong-columns baseline
- **260723-eq1** — equipment line-item editing (multi-select, split, soft-delete) — the tools users need to hand-fix any `area` cases the auto re-route can't handle
