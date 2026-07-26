---
plan: plan-04-docx-refactor
status: pending
started:
completed:
scope: Refactor DocxBuilderService to read exclusively from RamsDocumentDTO + RamsTheme. Add XML-diff snapshot test on Tilda DOCX. Same kill switch honoured.
estimated: 2 days
depends_on: plan-03
---

## Objective

Same treatment as PDF, applied to the PhpWord builder. Harder because
PhpWord is 100% programmatic (vs Blade's data-binding syntax) — every
section is a hand-coded sequence of `addTable() / addRow() / addCell() /
addText()` calls that need re-plumbing to read DTO fields.

## Tasks

### Task 1 — Kill switch branch in build entry point

`DocxBuilderService::build()`:
- If `config('rams.unified_composer')` → new path: compose DTO, dispatch
  to new render methods `buildFromDto(RamsDocumentDTO, RamsTheme)`.
- Else → old path unchanged.

### Task 2 — DocxBuilderServiceV2

New class `app/Services/DocxBuilderServiceV2.php` (kept separate for the
refactor; Plan 5 merges back and deletes the old class):
- Same public interface as `DocxBuilderService` (drop-in replacement).
- Constructor takes `RamsTheme` + `RamsDocumentComposer` via DI.
- Every colour + font reads from `$theme->palette(...)` / `$theme->font(...)`.
- Every table cell value reads from `$dto->{section}->{field}`.

Section-by-section porting:
- Same 16-section checklist as Plan 3.
- Each section is a `private function build{SectionName}(Section $section, $sectionDto, RamsTheme $theme): void` method.

### Task 3 — DOCX snapshot test + XML normaliser

`tests/Snapshot/Rams/DocxSnapshotTest.php`:
- Load Tilda fixture, compose DTO, render DOCX via new path, capture bytes.
- Unzip DOCX (`word/document.xml` is the target).
- Normalise via new `Tests\Support\DocxXmlNormalizer`:
  - Strip run IDs (`w:id="..."`) — PhpWord generates these randomly.
  - Strip relationship IDs (`rId...`) — position-dependent.
  - Strip `<w:sectPr>` / `<w:pgMar>` numeric noise beyond 4 decimal places.
  - Sort `xmlns:` attributes alphabetically.
- Compare normalised XML against `tests/fixtures/rams/tilda-21cq29531/expected.docx`
  (also normalised, cached at test start).
- `rams:regenerate-snapshots` command (from Plan 3) extended to cover
  `expected.docx` as well.

### Task 4 — Client-contact split fix (parity)

While refactoring the cover, correctly split client contact name +
email onto separate lines. Currently:
- PDF blade (post-260726-rf2): `<br>` separated ✓
- DOCX cover: uses `\n` which Word may render as space
- DOCX emergency: uses `" | "` concatenation

New path uses `$section->addText($name, $font)` + `$section->addTextBreak()`
+ `$section->addText($email, $font)` for guaranteed line-break rendering.

This is the FIRST parity win the refactor buys us — same DTO field
(`cover.client_contact_email`) produces the same visual result in both
formats.

## Constraints

- Old `DocxBuilderService.php` untouched in this plan (Plan 5 merges + deletes).
- With flag off: identical DOCX output (byte-identical modulo normaliser).
- With flag on: Tilda DOCX byte-identical to golden.
- All existing 806 tests + Plan 1-3 tests still green.

## Commits (target)

1. `feat(rams-docx): DocxBuilderServiceV2 skeleton + kill-switch branch (plan-04)`
2. `feat(rams-docx): port 16 sections to DTO-consuming build methods (plan-04)`
3. `test(rams): DOCX snapshot test + DocxXmlNormalizer (plan-04)`
4. `feat(rams-docx): explicit line-break for client_contact_email (plan-04)`

## Deliverable check

At plan close:
- With flag off: identical DOCX output.
- With flag on: Tilda DOCX byte-identical to golden.
- Snapshot test passes both ways.
- Client-contact split is IDENTICAL on both formats.
