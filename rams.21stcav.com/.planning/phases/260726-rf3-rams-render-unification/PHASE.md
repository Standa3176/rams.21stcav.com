---
phase: 260726-rf3-rams-render-unification
status: planning
started: 2026-07-26
branch: feat/worksheet-classifier-universal
scope: Structurally eliminate design-token + content drift between RAMS PDF (DomPDF + Blade) and DOCX (PhpWord + PHP) renderers by extracting shared theme + DTO + composer.
estimated: ~7 dev days + 1 week live soak
plans: 5
---

## Trigger

User inspection of the Tilda RAMS Rev 1.0 output revealed asymmetric
fix coverage across the two renderers:
- Design decisions (260725-rd1 blue palette + Poppins) went into
  DocxBuilderService but may not have fully mirrored into the PDF blade.
- Recent 260726-rf2 client-contact split went into the PDF blade only —
  DOCX cover uses `\n` (may render as space in Word), DOCX emergency
  table still uses " | " concatenation.
- Every content or design change today requires two implementations.

User picked **Route A** from the three unification options:
- (A) Shared design token + typed DTO, keep two renderers ✓ CHOSEN
- (B) HTML-first, DOCX via HTML→DOCX conversion — rejected (fidelity risk)
- (C) DOCX-first, PDF via LibreOffice — rejected (ops risk on VPS)

## Goal

After this phase ships, a design change (colour, font, spacing) or a
content addition (new section, new field) needs to land in exactly one
place, and the two output formats update in lockstep. Neither renderer
is allowed to read from `$rams->generated_data` / `reviewed_data` /
config directly at render time.

## Success criteria

1. Every hex colour, font name, and spacing constant used by either
   renderer lives in a single `RamsTheme` config file with typed
   accessors. `Grep` for hex colours in `DocxBuilderService.php` and
   `rams.blade.php` returns zero results.
2. A new field added to `RamsDocumentDTO` (e.g. `client_contact_mobile`)
   shows up in both PDF and DOCX with one PR — no per-renderer edit.
3. Tilda RAMS regenerated under the new path is byte-identical to the
   pre-refactor output (modulo normalised timestamps / run IDs).
4. Snapshot tests for PDF + DOCX prevent silent drift.
5. Kill switch `RAMS_UNIFIED_COMPOSER` allows one-line rollback for the
   soak week.

## Architecture

```
app/
├── Support/Rams/
│   ├── RamsTheme.php              (immutable design tokens)
│   ├── RamsDocumentDTO.php        (typed section tree — root)
│   ├── Sections/                  (per-section DTO leaves)
│   │   ├── CoverSectionDto.php
│   │   ├── DocControlSectionDto.php
│   │   ├── CompanyInfoSectionDto.php
│   │   ├── HealthSafetySectionDto.php
│   │   ├── StandardsTableSectionDto.php
│   │   ├── ScopeSectionDto.php
│   │   ├── RoomOverviewsSectionDto.php
│   │   ├── ExclusionsSectionDto.php
│   │   ├── RiskAssessmentSectionDto.php
│   │   ├── MethodStatementSectionDto.php
│   │   ├── EmergencySectionDto.php
│   │   ├── CoshhSectionDto.php
│   │   ├── EnvironmentalSectionDto.php
│   │   ├── WelfareSectionDto.php
│   │   ├── SignoffSectionDto.php
│   │   └── AppendixToolboxSectionDto.php
│   ├── RamsDocumentComposer.php   (RamsDocument → RamsDocumentDTO)
│   └── SectionComposers/          (per-section composer)
│       ├── CoverComposer.php
│       ├── ScopeComposer.php
│       ├── RiskAssessmentComposer.php
│       ├── MethodStatementComposer.php
│       ├── EmergencyComposer.php
│       └── ...
├── Services/
│   └── DocxBuilderService.php     (REFACTORED: reads DTO + Theme only)
resources/views/pdf/
└── rams.blade.php                 (REFACTORED: reads DTO + Theme only)
config/
└── rams_theme.php                 (source-of-truth for design tokens)
tests/
├── Unit/Support/Rams/             (theme + DTO tests)
├── Feature/Rams/Composer/         (composer fixture tests)
└── Snapshot/Rams/                 (byte-diff PDF/DOCX snapshots)
tests/fixtures/rams/
├── tilda-21cq29531/
│   ├── record.json                (serialised RamsDocument input)
│   ├── expected.pdf               (golden PDF)
│   └── expected.docx              (golden DOCX)
└── [4 more fixtures]
```

## Plan breakdown

| # | Plan | Deliverable | Est |
|---|------|-------------|-----|
| 1 | [Theme + DTO scaffolding](./plan-01-theme-dto-scaffolding/PLAN.md) | Config + typed DTO leaves. No renderer touched. | 1 day |
| 2 | [RamsDocumentComposer](./plan-02-composer/PLAN.md) | Composer + fixture tests. No renderer touched. | 1.5 days |
| 3 | [PDF renderer refactor](./plan-03-pdf-refactor/PLAN.md) | Blade reads from DTO + Theme + snapshot test + kill switch | 1.5 days |
| 4 | [DOCX renderer refactor](./plan-04-docx-refactor/PLAN.md) | DocxBuilder reads from DTO + Theme + snapshot test + kill switch | 2 days |
| 5 | [Parity sweep](./plan-05-parity-sweep/PLAN.md) | 5-fixture sweep + drift fixes. Kill switch stays until 1-week soak completes. | 1 day + soak |

## Non-goals

- No visual redesign — outputs must be byte-identical to today (modulo
  normalised timestamps / IDs).
- No new formats (email HTML, Confluence export).
- No AI pipeline changes.
- No `RamsDisplayPatchService` behaviour changes — it stays the
  composer's upstream. If drift traced to the patch service, that's a
  separate quick task.
- No kill-switch removal in this phase — deferred to a follow-up quick
  task after 1 week of clean live soak.

## Risks + mitigations

1. **Snapshot tests noisy from PhpWord run-ID randomness.** Mitigation:
   normalise run IDs + timestamps + rIds out of the XML before diff
   (helper `Tests\Support\DocxNormalizer`).
2. **DomPDF renders subtly differently between versions.** Mitigation:
   pin `dompdf/dompdf` version in composer; snapshot tests hash the
   HTML output too as a defence-in-depth layer.
3. **Composer becomes a god-object.** Mitigation: per-section
   sub-composers under `SectionComposers/`.
4. **Live regenerations mid-phase produce broken output.** Mitigation:
   kill switch defaults `false` on prod during the phase; only flip on
   for QA regens; flip globally only after Plan 5 parity sweep.
5. **Golden-file drift when we intentionally change output.** Mitigation:
   `php artisan rams:regenerate-snapshots` command re-captures all
   fixtures with a diff prompt for human review.

## Deploy strategy

Each plan lands as its own atomic commit set. Nothing enters the render
pipeline until Plan 3+4 land AND the kill switch is off. Plans 1+2 are
pure additive (new files only, zero risk to prod). Plans 3+4 refactor
the renderers but keep the old path behind `RAMS_UNIFIED_COMPOSER=false`.
Plan 5 flips the switch on globally + runs the parity sweep.

## Rollback

Set `RAMS_UNIFIED_COMPOSER=false` in `.env` + `php artisan config:cache`.
Every render immediately reverts to pre-phase code paths. Removal of
the fallback happens in a follow-up quick task only after 1 week of
clean live soak.
