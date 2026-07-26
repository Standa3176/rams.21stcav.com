---
phase: 260726-rf3-rams-render-unification
status: paused
paused_at: 2026-07-26
paused_reason: Executor hit account monthly spend limit mid-Plan-04
last_commit: 9648cf1
branch: feat/worksheet-classifier-universal
---

## Where we are

**Shipped** (Plans 01 + 02 + 03 + Plan 04 Commit 1 of 4):

| Plan | Status | Commits | Prod risk |
|------|--------|---------|-----------|
| 01 — Theme + DTO scaffolding | ✅ complete | 1c366a9 + 5a0daa6 + 13f6555 + b0a2df1 | none — pure additive |
| 02 — RamsDocumentComposer | ✅ complete | 6c888df + a550a65 + eecea4a + 903e5c5 | none — patch service marker only |
| 03 — PDF refactor + kill switch | ✅ complete | e707e9b + 4c3fa81 + c2a1a99 + 68a1a8a | none with flag off (default) |
| 04 — DOCX refactor | ⚠️ Commit 1 of 4 only | 9648cf1 | none with flag off (default) |
| 05 — Parity sweep | ⛔ not started | — | — |

**Result on prod:** Zero behaviour change. `RAMS_UNIFIED_COMPOSER=false`
(default) keeps both PDF + DOCX on their pre-refactor paths byte-for-byte.
The unified pipeline exists but is gated. Safe to leave in this state
indefinitely — no rollback needed.

## Pending — Plan 04 (3 commits remaining)

Per `plan-04-docx-refactor/PLAN.md`:

**Commit 2** — Port 16 sections in `DocxBuilderServiceV2::build()` to
DTO-consuming methods:
- Replace the current `$legacy->buildLegacy()` delegation with real
  per-section `private function build{SectionName}(Section $section, {Section}Dto $dto, RamsTheme $theme): void` methods.
- Mirror Plan 03's partial adoption: cover / doc-control / company-info
  / sign-off through DTO; compliance-upgrade fields (site_logistics,
  ppe_matrix, access_equipment_detail, cdm_duty_holders, etc.) still
  read from `$data` / `$rams` — full DTO for those is Plan 05.

**Commit 3** — DOCX snapshot test + XML normaliser:
- New `tests/Snapshot/Rams/DocxSnapshotTest.php` in the `snapshot`
  group (already excluded from fast suite per Plan 03).
- New `Tests\Support\DocxXmlNormalizer` — strips run IDs (`w:id="..."`),
  relationship IDs (`rId...`), numeric noise beyond 4 decimals in
  `<w:sectPr>` / `<w:pgMar>`, sorts `xmlns:` attributes.
- Extend `rams:regenerate-snapshots` (from Plan 03) to cover
  `expected.docx`.
- Golden: capture Tilda DOCX from the OLD path first, verify NEW path
  matches when flag is on.

**Commit 4** — Client-contact line-break fix:
- V2 cover renders `client_contact_email` via `$section->addText($name) + $section->addTextBreak() + $section->addText($email)` instead of
  concatenating with `\n` (which Word may render as space) or `" | "`.
- The parity win — same DTO field produces same visual result in both
  formats.

## Pending — Plan 05 (entire plan)

Per `plan-05-parity-sweep/PLAN.md` + accumulated deferred items:

1. Full-fixture sweep across all 5 fixtures + Tilda.
2. Fix drift found (~5-15 atomic drift commits).
3. **Deferred items to address** (from `deferred-items.md`):
   - `pdf.rams` blade PHP warning on partial `site_emergency`
     (unguarded `?:` on undefined key under PHP 8.4). Fix in both
     blades: change guards to `?? ''`. Better: enforce full 9-key
     shape in `EmergencySectionDto::fromArray` default.
   - `MethodStatementComposer` treats `material_handling` as string
     list but prod stores it as object
     `{ large_items: [...], handling_notes: "..." }`. Fix: extend
     `MethodStatementSectionDto` with structured `materialHandlingItems`
     field, OR move to dedicated `MaterialHandlingSectionDto`.
4. Full DTO adoption for compliance-upgrade fields in both renderers
   (currently legacy raw reads in Plan 03 + Plan 04 Commit 1).
5. Real Tilda fixture — replace the hand-crafted `record.json` with
   `\App\Models\RamsDocument::find(92)?->toJson()` from live VPS
   snapshot (Plan 03 executor couldn't access it locally).
6. Global flag flip: `RAMS_UNIFIED_COMPOSER=true` in `.env.example`;
   live `.env` gets the flag flipped in deploy step.
7. `HOW-TO-CHANGE-RAMS.md` documentation.
8. STATE row + phase-level SUMMARY.

## How to resume

New session, ideally with account spend headroom restored. First actions:

1. `git log --oneline -5` — confirm you're at `9648cf1` or later.
2. Read this file + `PHASE.md` + `plan-04-docx-refactor/PLAN.md` +
   `deferred-items.md`.
3. Verify current state:
   ```
   & ".\vendor\bin\phpunit" --filter "Docx" --exclude-group snapshot
   ```
   Expect 39/39 green.
4. Spawn `gsd-executor` for Plan 04 Commits 2-4 (they're one logical
   unit — the V2 port + its snapshot test + the line-break fix).
5. Then spawn `gsd-executor` for Plan 05.
6. Between plans: parent orchestrator writes SUMMARY.md (hooks block
   the executor from writing docs).

## Rollback (if ever needed)

Nothing to roll back. Everything shipped is gated behind
`RAMS_UNIFIED_COMPOSER=false` (default). To be extra safe on prod, add:
```
RAMS_UNIFIED_COMPOSER=false
```
to `/home/stcav/rams.21stcav.com/.env` and `php artisan config:cache`.
This is already the default so no-op — but explicit `.env` presence
prevents an accidental global flip from taking effect.
