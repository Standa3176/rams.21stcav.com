---
plan: plan-03-pdf-refactor
status: pending
started:
completed:
scope: Refactor rams.blade.php to read exclusively from RamsDocumentDTO + RamsTheme. Add byte-level PDF snapshot test on Tilda fixture. Kill switch RAMS_UNIFIED_COMPOSER short-circuits back to current path.
estimated: 1.5 days
depends_on: plan-02
---

## Objective

Move the PDF renderer over to the unified pipeline behind a feature
flag. When the flag is off, current behaviour is preserved exactly.
When on, the blade reads from DTO + Theme only — zero direct
`$rams->generated_data` / `reviewed_data` / config reads.

## Tasks

### Task 1 — Kill switch

`config/rams.php` gains:
```php
'unified_composer' => env('RAMS_UNIFIED_COMPOSER', false),
```

`app/Services/RamsDocumentRendererService.php` (or wherever PDF render
is dispatched):
- If `config('rams.unified_composer')` → new path: compose DTO, pass
  to a NEW blade `resources/views/pdf/rams-v2.blade.php`.
- Else → old path: existing `resources/views/pdf/rams.blade.php`
  unchanged.

### Task 2 — rams-v2.blade.php

New blade file that IS the refactored PDF template. Structure mirrors
today's `rams.blade.php` visually but every value read is via the DTO
+ Theme:

- `{{ $dto->cover->client }}` instead of `{{ $project['client'] ?? '' }}`
- Colour classes like `class="brand-blue"` resolve via `<style>` block
  injected from `{{ $theme->paletteCss() }}` (new method on RamsTheme
  that emits a `<style>:root { --brand-blue: #{{ hex }}; }</style>`
  block).
- Section conditionals via `@if(!$dto->exclusions->isEmpty())`.

Section-by-section porting checklist (16 sections × 1 hour each):
- [ ] Cover (both tables)
- [ ] Doc Control
- [ ] Company Info
- [ ] H&S Policy + Standards
- [ ] Scope of Works (with 3-bucket equipment table)
- [ ] Room Overviews
- [ ] Exclusions
- [ ] Risk Assessment (matrix + hazards)
- [ ] Method Statement (12 sub-sections)
- [ ] Emergency Procedures
- [ ] COSHH
- [ ] Environmental
- [ ] Welfare
- [ ] Sign-off
- [ ] Appendix Toolbox

### Task 3 — PDF snapshot test

`tests/Snapshot/Rams/PdfSnapshotTest.php`:
- Load the Tilda fixture (`tests/fixtures/rams/tilda-21cq29531/record.json`)
- Compose DTO, render PDF via new path, capture bytes.
- Normalise: strip `/CreationDate` / `/ModDate` from PDF metadata,
  strip DomPDF version string.
- Compare against `tests/fixtures/rams/tilda-21cq29531/expected.pdf`
  (also normalised).
- On first run, capture the CURRENT (pre-refactor) PDF as the golden
  file via a `--capture` flag.

New Artisan command `php artisan rams:regenerate-snapshots {fixture?}`:
- Re-runs the render for a fixture (or all) and overwrites the
  `expected.pdf` / `expected.docx` golden file.
- Prints a diff prompt: "This will overwrite expected.pdf for tilda-21cq29531. Confirm? [y/N]"

### Task 4 — HTML-level snapshot as belt-and-braces

Same fixture, but also snapshot the rendered HTML (before DomPDF).
Catches template drift even when DomPDF renders both to visually
identical PDFs.

## Constraints

- `RAMS_UNIFIED_COMPOSER=false` (default) must produce byte-identical
  output to pre-refactor. Snapshot the pre-refactor path as a
  sanity check.
- No changes to `DocxBuilderService.php`.
- All existing tests still green.
- Snapshot tests only run in `Snapshot` group — skipped by default in
  CI unless `PHPUNIT_GROUP=snapshot` set (byte-diff tests are noisy
  and slow the fast suite down).

## Commits (target)

1. `feat(rams): RAMS_UNIFIED_COMPOSER kill switch + renderer branch (plan-03)`
2. `feat(rams-pdf): refactor rams-v2.blade.php to consume DTO + Theme (plan-03)`
3. `test(rams): PDF snapshot test + rams:regenerate-snapshots command (plan-03)`

## Deliverable check

At plan close:
- With `RAMS_UNIFIED_COMPOSER=false`: identical PDF output.
- With `RAMS_UNIFIED_COMPOSER=true`: Tilda PDF byte-identical to golden.
- Snapshot test passes both ways.
- DOCX render still unchanged (Plan 4's problem).
