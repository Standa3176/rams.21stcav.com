---
plan: plan-03-never-drop-kit
status: pending
depends_on: plan-02
scope: Worksheet DOCX + PDF templates render unclassified items under explicit "Unclassified — Bucket at Review" section instead of dropping them. Warning banner stays as the fix-at-review nudge.
estimated: 0.5 day
---

## Objective

Install engineers must see every piece of kit on site — even if the
category is TBD. Current behaviour hides unclassified items and only
mentions them in the QA warnings section. New behaviour: render them
under an explicit section AND keep the warning.

## Tasks

### Task 1 — Classifier emits unclassified items as a rendered category

Extend `WorksheetClassifier` (or its downstream aggregator) to:
- Continue emitting the QA-warning entries (unchanged).
- ALSO emit unclassified items in a new bucket `'unclassified'` on the
  per-room kit list so the DOCX renderer can display them.
- Preserve room grouping — unclassified items stay attached to their
  room; the "General" bucket (Tilda's 8 orphan touch screens) shows
  under a top-level "Unclassified" heading, not scattered per room.

### Task 2 — DOCX renderer

`App\Services\Worksheet\WorksheetDocxRenderer` (or wherever
`{room} → categories → items` is walked): add an "Unclassified —
Bucket at Review" row group after the six canonical categories in each
room block. Style: same table cell shape as other categories, but
label prefixed with amber icon + "REVIEW" tag (mirror the existing QA
warning styling for consistency).

### Task 3 — PDF template

Same treatment in the PDF Blade template. Reuse the same visual
convention.

### Task 4 — Warning banner stays

The top-of-worksheet WORKSHEET QA WARNINGS section keeps listing
unclassified items — the banner is the nudge that says "fix this at
review". The rendered Unclassified section is the SAFETY net that
prevents kit going missing on the install.

### Task 5 — Snapshot test

`tests/Feature/Worksheet/UnclassifiedRenderingTest.php`:
- Fixture with 3 known-classified items + 2 known-unclassified items.
- Render both DOCX + PDF.
- Assert 5 items total in the kit list.
- Assert 2 items appear in the Unclassified section.
- Assert warning banner lists the same 2 items.

## Constraints

- No new items dropped. Every equipment row from the source data
  either lands in a canonical category or in Unclassified.
- Warning banner unchanged (still counts + lists).
- Existing 6 canonical category sections unchanged.
- Snapshot test proves parity.

## Commits (target)

1. `feat(worksheet): classifier emits 'unclassified' bucket alongside warnings (plan-03)`
2. `feat(worksheet-docx): render Unclassified section on kit list (plan-03)`
3. `feat(worksheet-pdf): render Unclassified section on kit list (plan-03)`
4. `test(worksheet): never-drop-kit snapshot coverage (plan-03)`

## Deliverable check

At plan close:
- Regenerate Tilda worksheet: all 19 previously-skipped items appear
  under "Unclassified — Bucket at Review".
- Warning banner still lists them.
- Kit total row count = original quote row count minus excluded
  labour/service lines.
