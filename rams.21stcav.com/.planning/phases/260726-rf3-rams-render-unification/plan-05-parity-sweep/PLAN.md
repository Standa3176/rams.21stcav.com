---
plan: plan-05-parity-sweep
status: pending
started:
completed:
scope: Flip RAMS_UNIFIED_COMPOSER on globally. Regenerate all 5 fixtures under new path. Diff PDF vs DOCX. Fix any drift found. Kill switch stays until 1-week soak passes.
estimated: 1 day + 1 week soak
depends_on: plan-04
---

## Objective

Flip the flag on, sweep all 5 fixtures for drift, fix what drifts.
The kill switch stays in place for one live soak week; a follow-up
quick task deletes the fallback code paths afterwards.

## Tasks

### Task 1 — Full-fixture sweep

For each of 5 fixtures (Tilda, fresh-build, prior-rams-carry,
decommission-heavy, missing-survey, empty-scope):
- Regenerate golden PDF + DOCX via new path
  (`rams:regenerate-snapshots {fixture}`)
- Visual diff: open both in a viewer, walk section by section
- Log any drift in `plan-05-parity-sweep/DRIFT.md`

Expected drift categories:
1. **Design drift** — colour, font, spacing mismatch between formats
   because the two renderers read different tokens pre-refactor. Fix by
   correcting the RamsTheme value + regenerating.
2. **Content drift** — one format shows a field the other doesn't
   (typically DOCX has extras the PDF didn't render historically). Fix
   by adding the missing field to the appropriate blade section.
3. **Ordering drift** — one format sequences sections differently. Fix
   by aligning both to `$theme->sectionOrder()`.

### Task 2 — Drift fixes

Each drift item lands as its own atomic commit:
- `fix(rams): {section} {format} — {short drift description} (plan-05)`

Expected count: 5-15 drift commits based on the audit findings we
already know about (client-contact split done in Plan 4; DOCX
emergency section pipe format done in Plan 4; PDF may have extras like
the running header pipe pattern that DOCX doesn't have).

### Task 3 — Global flag flip + deploy note

- Set `RAMS_UNIFIED_COMPOSER=true` in `.env.example` (default new
  installs).
- Live `.env` gets the flag flipped in the deploy step.
- SUMMARY.md flags the 1-week soak deadline and links a follow-up quick
  task placeholder (`260802-rf3-remove-old-render-paths`) for kill
  switch + old code removal.

### Task 4 — Update STATE.md + user-visible docs

- Add STATE row for the phase completion.
- Update `.planning/PROJECT.md` if needed to note the unification.
- Note in the phase SUMMARY: any design changes now land in ONE place.

### Task 5 — Documentation

New file `.planning/phases/260726-rf3-rams-render-unification/HOW-TO-CHANGE-RAMS.md`:
- "Want to change a colour?" → edit `config/rams_theme.php`
- "Want to add a new section field?" → edit the SectionDto + composer + both templates (with checklist)
- "Want to add a whole new section?" → 6-step checklist
- Links to the DTO/Theme/Composer classes

## Constraints

- All 5 fixture snapshots pass in both formats.
- No visual regression on Tilda (user's canonical reference document).
- Kill switch remains functional throughout the soak week.
- Existing suite + Plan 1-4 tests all green.

## Commits (target)

1-N. `fix(rams): {section} {format} — {drift description} (plan-05)`  ×N
- `feat(rams): flip RAMS_UNIFIED_COMPOSER to true by default (plan-05)`
- `docs(rams): HOW-TO-CHANGE-RAMS + phase SUMMARY + STATE row (plan-05)`

## Post-phase (deferred)

- **After 1-week live soak**: quick task `260802-rf3-remove-old-render-paths`
  - Delete `DocxBuilderService` (old class).
  - Delete `resources/views/pdf/rams.blade.php` (old blade).
  - Rename V2 files back to canonical names.
  - Remove kill switch config + branch.
  - Drop the fallback tests.

## Deliverable check

At plan close:
- Kill switch flipped to `true` on prod.
- Fixture parity verified across all 5.
- Documentation exists explaining the new pipeline.
- No known drift.
