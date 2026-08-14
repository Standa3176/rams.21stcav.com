---
quick_id: 260814-o8o
slug: quote-import-sku-column
date: 2026-08-14
status: planned
---

# Quick Task 260814-o8o — Quote-import review SKU column always renders "—"

## Problem

User reported that a QuoteWerks import "did not import part numbers from field ManufacturerPartNumber". Observed on production `/quote-import/159/review`: 47 line items, every row's **SKU** column showing `—`, while Description / Qty / Unit / Total all render correctly.

**The import is not broken.** The part numbers are stored correctly. This is a display-only defect.

## Diagnosis (traced end to end)

The data chain is intact:

| Stage | File:line | Key |
|---|---|---|
| QW SQL SELECT | `app/Services/Imports/Quote/QuoteWerksDbFetcher.php:357` | `ManufacturerPartNumber` |
| Fetcher mapping | `QuoteWerksDbFetcher.php:441,458` | → `part_number` |
| Service mapping | `app/Core/Modules/QuoteImport/QuoteWerksImportService.php:150-151` | → `part_number` + `part_no` |
| Stored as | `QuoteWerksImportService.php:235` | `extracted_data['line_items']` (same array as `equipment`) |

But the view reads a key nothing writes:

```php
resources/views/quote-import/review.blade.php:160
{{ $item['sku'] ?? '' ?: '—' }}
```

`sku` is the key used by the **Claude-vision** extraction path only (see the AI response schema at `app/Services/QuoteExtractorService.php:324`). The QuoteWerks and PDF-parser paths write `part_number` / `part_no`. Nothing in `app/Core/Modules/QuoteImport/` or `app/Services/Imports/` ever emits `sku` — verified by grep.

## Why this is an isolated outlier, not a systemic issue

Every other consumer already uses the house fallback-chain pattern:

- `app/Http/Controllers/ProjectPackageReviewController.php:107` — `part_number ?? part_no ?? sku ?? code ?? model`
- `app/Services/ProjectPackageRamsReviewService.php:110` — `part_number ?? part_no ?? sku`
- `app/Services/QuoteImport/QuoteImportStencilStubber.php:83` — `part_number ?? sku`
- `app/Console/Commands/StencilsCoverageReportCommand.php:75` — `part_number ?? sku`
- `app/Services/Worksheet/WorksheetClassifier.php:61` / `FriendlyNameResolver.php:39` — `part_no ?? sku ?? model`

`review.blade.php:160` is the **only** site reading `sku` with no fallback.

## Phase 24 note (no action needed)

`QuoteImportStencilStubber` keys on `part_number` and already has the `?? sku` fallback, so Phase 24's auto-stub is **unaffected** — stubs are created correctly for QuoteWerks imports despite the blank column. The blank SKU column is misleading but not load-bearing.

## Tasks

### Task 1 — Fix the column to tolerate every producer's shape

**File:** `resources/views/quote-import/review.blade.php` (line ~160)

**Action:** Replace the `sku`-only lookup with the established fallback chain, ordered most-specific first, matching `ProjectPackageReviewController.php:107`:

```
part_number → part_no → sku → '—'
```

Keep the existing `?: '—'` empty-string handling so a present-but-blank value still renders the em-dash rather than an empty cell. Do not change the column header, styling, or any other column.

**Acceptance criteria:**
- `review.blade.php` contains no lookup that reads `sku` without a `part_number` fallback
- A line item with only `part_number` set renders that value in the SKU cell
- A line item with only `sku` set (vision path) still renders that value — no regression
- A line item with neither renders `—`

### Task 2 — Regression test

**File:** `tests/Feature/QuoteImport/QuoteImportReviewSkuColumnTest.php` (new)

**Action:** Feature test hitting the review route with a `ProjectPackage` whose `extracted_data['line_items']` contains three rows: one QW-shaped (`part_number` only), one vision-shaped (`sku` only), one with neither. Assert the first two part numbers appear in the response and the third row's cell is the em-dash.

PHPUnit 11 conventions: `extends Tests\TestCase`, `use RefreshDatabase;`, `public function test_*(): void`.

This is the test that would have caught the original defect — the existing suite never asserted the SKU column's contents.

**Acceptance criteria:**
- `php artisan test --filter=QuoteImportReviewSkuColumn` passes
- The test fails if the Blade is reverted to the `sku`-only lookup

## Constraints

- Blade only — no controller, service, or importer changes. The data layer is correct.
- No new packages, no migration.
- Lint: `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l <file>`
- Deployment is local-edit-then-upload (Phase 21 D-13) — SUMMARY must end with a "🚨 Files to upload to live" section. This is a Blade change, so it needs `php artisan optimize:clear` (view cache) after upload, but **no migration**.
