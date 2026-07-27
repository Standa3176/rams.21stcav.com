---
phase: 260727-wt1-worksheet-catalogue-and-learning
status: planning
started: 2026-07-27
branch: feat/worksheet-classifier-universal (new phase branch may be cut at execute-time)
scope: Move worksheet product taxonomy from static config file to database-backed catalogue that (a) is admin-editable, (b) learns from PM classifications on the QW package review page, and (c) never drops unclassified kit from the worksheet output.
estimated: ~2 dev days + 1 week live soak
plans: 5
---

## Trigger

Tilda worksheet (project 88, worksheet 14) rendered fragmented — 19
items skipped from the kit list because `config/worksheet_taxonomy.php`
had no Crestron rule matching room-scheduling touch screens,
Automate VX, AirMedia, SurgeX, or IV-CAMERA-4K-PTZ SKUs. Plus 8 more
items only survived via Tier 3 keyword fallback (drift flag).

Root cause diagnosis (2026-07-27): the taxonomy will drift on every
new vendor / product family / SKU series. Static config file is the
wrong shape for a growing supplier base — every new device requires a
code deploy. Options considered:

- **Bigger config file** — kicks the can; same problem next vendor.
- **AI-assisted classifier** — blocked by CLAUDE.md constraint: AI can
  format method statements but must never invent scope/equipment.
  Classifying a Crestron NVX as `control` vs `video_conferencing` IS a
  scope decision.
- **Static DB catalogue alone** — better than config but still needs
  admin data entry for every new SKU.
- **DB catalogue + learn from QW review page + never drop kit** ✓
  CHOSEN. No deploy needed. Self-improving. AI-free. Kit stays visible.

## Goal

After this phase ships:
1. `WorksheetClassifier` reads from a DB-backed catalogue, not the
   config file.
2. When a PM sets a category on `/project-packages/{id}/review` for a
   SKU the catalogue doesn't know, a `learned` row lands automatically.
3. Next project that imports the same SKU auto-classifies.
4. Unclassified items appear in the worksheet under an explicit
   "Unclassified — Bucket at Review" section — never dropped.
5. Admin has a review queue to promote / correct learned mappings so
   a bad PM guess doesn't pollute future projects silently.

## Success criteria

1. `Grep` for `worksheet_taxonomy` in classifier code returns zero
   config-file reads at runtime — the file becomes a seed-only source.
2. PM classifies a novel SKU once → next import of same SKU classifies
   automatically without any config edit.
3. Tilda worksheet regenerated: all 19 previously-skipped items appear
   on the kit list (in a room-tagged Unclassified section if the DB
   catalogue doesn't cover them yet).
4. Admin review queue lists all `learned` rows with promote/correct/
   delete actions.
5. Snapshot tests prevent silent worksheet-content regressions on the
   new pipeline.

## Architecture

```
database/migrations/
└── 2026_07_27_000001_create_product_taxonomy_table.php

app/
├── Models/
│   └── ProductTaxonomy.php
├── Repositories/
│   └── ProductTaxonomyRepository.php     (all catalogue lookups)
├── Services/Worksheet/
│   ├── WorksheetClassifier.php           (REFACTORED: reads via repo)
│   └── LearnedTaxonomyWriter.php         (called from review-page save)
├── Http/Controllers/
│   └── ProjectPackageReviewController.php (write-through wired in)
└── Filament/ (or admin blade)
    └── ProductTaxonomyResource.php       (promote/correct/delete UI)

config/
└── worksheet_taxonomy.php                (DEMOTED to seed-only source)

database/seeders/
└── ProductTaxonomySeeder.php             (ports config values to DB)

tests/
├── Feature/Worksheet/
│   ├── ClassifierDbReadTest.php
│   ├── ReviewLearningTest.php
│   ├── UnclassifiedRenderingTest.php
│   └── AdminPromotionTest.php
└── Fixtures/worksheet/                   (Tilda + 3 more fixtures for snapshot)
```

Table shape (indicative):
```
product_taxonomy
├── id
├── sku_pattern           (VARCHAR nullable; exact match preferred, wildcards allowed)
├── manufacturer          (VARCHAR nullable)
├── description_pattern   (VARCHAR nullable; substring match)
├── product_family        (VARCHAR nullable; human label — "Crestron TSW-1070 series")
├── worksheet_category    (ENUM 'display','video_conferencing','audio','control','rack','network','unclassified')
├── install_step_hint     (TEXT nullable; text fragment inserted into install steps)
├── source                (ENUM 'seed','learned','admin')
├── learned_from_package  (nullable FK → project_packages.id)
├── created_by            (nullable FK → users.id)
├── promoted_by           (nullable FK → users.id)
├── promoted_at           (nullable timestamp)
├── created_at
├── updated_at
└── index on (sku_pattern), (manufacturer, description_pattern)
```

## Plan breakdown

| # | Plan | Deliverable | Est |
|---|------|-------------|-----|
| 1 | [Schema + seed](./plan-01-schema-seed/PLAN.md) | Migration + model + repository + seeder from config. Config file untouched. | 0.5 day |
| 2 | [Classifier DB read](./plan-02-classifier-db/PLAN.md) | `WorksheetClassifier` reads via repository. Kill switch `WORKSHEET_TAXONOMY_DB` falls back to config. Snapshot parity test. | 0.5 day |
| 3 | [Never-drop-kit rendering](./plan-03-never-drop-kit/PLAN.md) | Unclassified items appear under explicit section on worksheet DOCX + PDF. Warning banner stays. | 0.5 day |
| 4 | [Review-page learning](./plan-04-review-learning/PLAN.md) | `LearnedTaxonomyWriter` wired to `/project-packages/{id}/review` save/approve. Idempotent. | 0.5 day |
| 5 | [Admin promotion + docs](./plan-05-admin-promotion/PLAN.md) | Filament (or blade) admin resource for learned-row review + audit trail + STATE + SUMMARY. | 0.5 day + soak |

## Ordering rationale

- Plan 03 lands BEFORE Plan 04 so PMs first gain VISIBILITY of
  unclassified items in the rendered worksheet, then gain the
  MECHANISM to fix them via the review page.
- Plan 02 gates the DB read behind `WORKSHEET_TAXONOMY_DB=false`
  (default) so Plans 3-5 can develop against the DB path while prod
  keeps reading from the config file. Plan 05 flips the switch on
  after the parity test in Plan 02 passes.

## Non-goals

- No changes to the 6-category worksheet taxonomy itself
  (display / video_conferencing / audio / control / rack / network).
  Adding a 7th category (e.g. `wireless_presentation` for AirMedia)
  is a separate product decision — this phase treats them as `control`
  by default; category-refinement is a deferred task.
- No AI in the classifier — CLAUDE.md constraint stands.
- No changes to worksheet DOCX / PDF chrome, palette, or fonts. Plan
  03 only adds a new section header + row group under Unclassified.
- Kill switch removal is a deferred quick task after 1-week live soak.

## Related

- **260726-fx4 Task 4** — established the "no silent lies" principle
  the current classifier honours by refusing to guess. This phase
  extends the principle: no silent lies AND no dropped kit.
- **260726-fx5** — added the 8th `unknown` category to equipment
  classification on the QW review page. Plan 04 leverages that same
  dropdown to write learned taxonomy rows.
- **260727-fx6** — throwaway config-file additions (Crestron / SurgeX /
  AirMedia) to unblock Tilda in the morning while this phase is being
  built. Rules get ported into the DB seed and the config additions
  become dead code when Plan 02 flips the switch.
- **rf3 phase** (`260726-rf3-rams-render-unification`) — paused
  mid-Plan-04. This phase is independent and can execute in parallel
  in a fresh session, but ideally we finish rf3 first to reduce
  context-switching.

## Rollback

`WORKSHEET_TAXONOMY_DB=false` in `.env` + `php artisan config:cache`
reverts to config-file reads. Every worksheet render immediately
falls back to pre-phase behaviour. Removal of the fallback happens in
a follow-up quick task only after 1 week of clean live soak.
