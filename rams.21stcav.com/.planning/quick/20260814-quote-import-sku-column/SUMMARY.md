---
quick_id: 260814-o8o
slug: quote-import-sku-column
date: 2026-08-14
status: complete
---

# Quick Task 260814-o8o -- Quote-import review SKU column always renders "--" -- Summary

## What was wrong

`resources/views/quote-import/review.blade.php:160` read `$item['sku'] ?? '' ?: '--'`. `sku` is
a key only the Claude-vision extraction path writes (`app/Services/QuoteExtractorService.php:324`).
The QuoteWerks and PDF-parser import paths write `part_number` / `part_no`
(`QuoteWerksImportService.php:150-151,235`) -- they never write `sku`. So every QuoteWerks-imported
line item rendered a dash in the SKU column even though the part number was stored correctly in
`extracted_data['line_items']`. The import pipeline itself was never broken -- this was a
display-only defect, isolated to the one Blade lookup that skipped the house fallback-chain
pattern used everywhere else (e.g. `ProjectPackageReviewController.php:107`).

## What changed

### Task 1 -- Fixed the SKU cell lookup

`resources/views/quote-import/review.blade.php:160` now reads:

```php
{{ $item['part_number'] ?? $item['part_no'] ?? $item['sku'] ?? '' ?: '--' }}
```

Ordered most-specific first (`part_number -> part_no -> sku -> dash`), matching the established
pattern. The existing `?: '--'` empty-string handling is preserved so a present-but-blank value
still renders the em-dash. No other column, header, or styling touched. No controller, service,
fetcher, or importer edits -- data layer is untouched, as required.

**Commit:** `dacf6ce` -- `fix(quote-import): fall back to part_number/part_no before sku in review SKU column`

### Task 2 -- Regression test

New file: `tests/Feature/QuoteImport/QuoteImportReviewSkuColumnTest.php`

Feature test hitting `GET /quote-import/{package}/review` with a `ProjectPackage` whose
`extracted_data['line_items']` contains three rows:
- QuoteWerks-shaped (`part_number` only, no `sku` key) -> asserts `part_number` value renders
- Vision-shaped (`sku` only, no `part_number`/`part_no`) -> asserts `sku` value still renders (no regression)
- Neither key present -> asserts the cell renders the dash

**Proof the test is real:** before restoring the fix, the Blade line was temporarily reverted to
the original `$item['sku'] ?? '' ?: '--'` lookup and the test was re-run -- it failed on the
`assertSeeText('QW-PART-001')` assertion as expected, confirming the test would have caught the
original defect. The fix was then restored (`git diff` against the prior commit is clean) and the
test re-run to confirm it passes again.

**Commit:** `ca42688` -- `fix(quote-import): add regression test for review SKU column fallback`

## Verification

- `php -l resources/views/quote-import/review.blade.php` -- no syntax errors
- `php -l tests/Feature/QuoteImport/QuoteImportReviewSkuColumnTest.php` -- no syntax errors
- `php artisan test --filter=QuoteImportReviewSkuColumn` -- 1 passed (5 assertions)
- Revert-and-reconfirm cycle (see above) proved the test is load-bearing, not a pass-either-way test
- Did not run a full-repo `php artisan test` (scoped `--filter` only, per constraints)
- No migration involved; `php artisan migrate` not run

## Deviations from Plan

None -- plan executed exactly as written. Blade-only change, no data-layer edits, matches the
`part_number ?? part_no ?? sku` fallback chain used elsewhere in the codebase.

## Files changed

- `resources/views/quote-import/review.blade.php` (1 line)
- `tests/Feature/QuoteImport/QuoteImportReviewSkuColumnTest.php` (new)

## Self-Check

- FOUND: `resources/views/quote-import/review.blade.php` (modified, contains the fallback chain)
- FOUND: `tests/Feature/QuoteImport/QuoteImportReviewSkuColumnTest.php`
- FOUND commit `dacf6ce`
- FOUND commit `ca42688`

## Self-Check: PASSED

## 🚨 Files to upload to live

Only one production file changed -- this is a **view-only fix**, no controller/service/model
changes, no migration:

- `resources/views/quote-import/review.blade.php`

**After upload, run:** `php artisan optimize:clear` (clears the compiled Blade view cache -- without
this the old compiled view keeps serving until it naturally expires/recompiles).

**No migration required.** `tests/Feature/QuoteImport/QuoteImportReviewSkuColumnTest.php` is
test-only and does not need to be uploaded to production.
