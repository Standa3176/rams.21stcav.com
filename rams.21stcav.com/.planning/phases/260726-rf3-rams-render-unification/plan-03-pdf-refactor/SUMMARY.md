---
phase: 260726-rf3-rams-render-unification
plan: plan-03-pdf-refactor
status: complete
completed: 2026-07-26
branch: feat/worksheet-classifier-universal
commits:
  - e707e9b  # RAMS_UNIFIED_COMPOSER kill switch + renderer branch
  - 4c3fa81  # rams-v2.blade.php refactor to consume DTO + Theme
  - c2a1a99  # PDF snapshot test + rams:regenerate-snapshots command + Tilda fixture
migrations: 0
npm_build: false
new_tests: 3
new_assertions: 10
depends_on: plan-02
---

## What shipped

PDF renderer moved to the unified pipeline **behind a feature flag**.
`RAMS_UNIFIED_COMPOSER=false` (default) preserves the current path
byte-for-byte; `=true` dispatches to the new blade that consumes the
DTO + Theme scaffolded in Plans 01-02.

### Commit 1 — `e707e9b` — Kill switch + renderer branch

- `config/rams.php` — adds `'unified_composer' => env('RAMS_UNIFIED_COMPOSER', false)`.
- `app/Services/PdfService.php::buildRams` — branches on the flag.
  When on: composes `RamsDocumentDTO`, resolves `RamsTheme` singleton,
  dispatches to `pdf.rams-v2`. When off: dispatches to `pdf.rams`
  unchanged.
- `phpunit.xml` — excludes the `snapshot` group by default so byte-diff
  tests don't slow the fast suite.

### Commit 2 — `4c3fa81` — rams-v2.blade.php

- `resources/views/pdf/rams-v2.blade.php` — new blade that reads:
  cover, doc-control, company-info, and sign-off exclusively from the
  DTO + Theme. Palette hex tokens flow through `RamsTheme::paletteCss()`
  injected as a `<style>` block in the `<head>`.
- **Partial adoption:** compliance-upgrade fields (`site_logistics`,
  `ppe_matrix`, `access_equipment_detail`, `cdm_duty_holders`, etc.)
  still read from `$data` / `$rams` in this plan — full DTO adoption
  deferred to Plan 05 parity sweep. Logged in
  `phases/260726-rf3.../deferred-items.md`.
- `app/Support/Rams/RamsTheme.php` — new `paletteCss()` method emitting
  the `:root { --brand-blue: #...; ... }` block.

### Commit 3 — `c2a1a99` — Snapshot test + Tilda fixture

- `tests/Feature/Rams/Snapshot/PdfSnapshotTest.php` — HTML-level
  snapshot (not PDF binary) since Chromium/Browsershot output isn't
  deterministic. Load-or-capture pattern: first run writes golden and
  skips; subsequent runs hard-assert byte equality. Pinned Carbon
  clock `2026-07-25 10:30:00` so `docDate` doesn't drift. Belt-and-
  braces v1↔v2 delta guard (+500..+2000 bytes) catches large-scale
  content leaks.
- `app/Console/Commands/RamsRegenerateSnapshotsCommand.php` — Artisan
  `rams:regenerate-snapshots {fixture?}` re-captures goldens with a
  diff confirm prompt.
- `tests/Fixtures/rams/tilda-21cq29531/{record.json, expected-html-v1.html, expected-html-v2.html}` — Tilda fixture + captured goldens.
  Snapshot: v1 = 66 061 bytes, v2 = 66 840 bytes, delta = +779 bytes
  (the paletteCss `<style>` block).

## Test coverage

- **New:** snapshot group 3/3 (10 assertions).
- **Scoped regression sweep:** 735 tests, 11 pre-existing
  PublicSurvey 404 failures already flagged as unrelated.
- **`php -l` clean** on every new + modified file.

## Deploy

**Prod-safe with flag off.** `RAMS_UNIFIED_COMPOSER` defaults `false`
in `config/rams.php`; without an env override, both old and new
codepaths compile, but only the old path runs. Flip to `true` per-QA
via `.env` when smoke-testing v2. Plan 05 will flip globally after
parity sweep.

## Deviations from PLAN.md

1. **Tilda fixture hand-crafted, not pulled from real record.** Local
   dev DB was empty; couldn't tinker-dump project 88 record 92.
   Modelled on Plan 02's four composer fixtures + the SUMMARY hint
   that `client_contact_name === 'Wesley Jones'`. Real record capture
   deferred to Plan 05 (executor will have live DB access via VPS
   snapshot).
2. **Fixture omits `material_handling`** — `MethodStatementComposer`
   treats it as a string list, but prod stores it as an object
   `{ large_items: [...], handling_notes: "..." }`. Logged to
   `deferred-items.md`. Plan 05 to either extend the composer or add
   a dedicated `MaterialHandlingSectionDto`.
3. **Partial DTO adoption in rams-v2.blade.** Cover / doc-control /
   company-info / sign-off through DTO; compliance-upgrade fields
   still legacy. Explicitly reserved for Plan 05.
4. **Pre-existing bug surfaced** — `pdf.rams` blade emits PHP warning
   when `site_emergency` is partial (unguarded `?:` on undefined key
   under PHP 8.4). Not fixed in this plan (predates the phase; SCOPE
   BOUNDARY rule). Plan 05 fix suggestion in `deferred-items.md`:
   change guards to `?? ''`.
5. **HTML-level snapshot instead of PDF binary.** DomPDF output isn't
   deterministic enough for byte-diff without extensive metadata
   stripping. HTML capture is more reliable + catches template drift
   even when the PDF renders visually identical.

## Related

- **Plan 04** — DOCX gets the same treatment.
- **Plan 05** — parity sweep addresses the 5 deviations above.
- **`deferred-items.md`** — running log of pre-existing bugs +
  composer contract gaps surfaced during the phase.
