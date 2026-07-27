---
phase: 260726-rf3-rams-render-unification
status: paused
paused_at: 2026-07-27
paused_reason: 4/5 plans complete; Plan 05 remainder (parity sweep + flag flip) needs live-VPS Tilda data + fresh session budget
last_commit: a743af5
branch: feat/worksheet-classifier-universal
---

## Where we are

**Shipped** (Plans 01 + 02 + 03 + full Plan 04 + Plan 05a):

| Plan | Status | Commits | Prod risk |
|------|--------|---------|-----------|
| 01 — Theme + DTO scaffolding | ✅ complete | 1c366a9 + 5a0daa6 + 13f6555 + b0a2df1 | none — pure additive |
| 02 — RamsDocumentComposer | ✅ complete | 6c888df + a550a65 + eecea4a + 903e5c5 | none — patch service marker only |
| 03 — PDF refactor + kill switch | ✅ complete | e707e9b + 4c3fa81 + c2a1a99 + 68a1a8a | none with flag off (default) |
| 04 — DOCX refactor | ✅ complete | 9648cf1 + 7c6288f + 5629cb2 | none with flag off (default) |
| 05a — Deferred bug fixes | ✅ complete | 0f7bdad + a743af5 | none — defensive null-safety + shape backwards-compat |
| 05b — Full parity sweep + flag flip | ⛔ pending | — | — |

**Result on prod:** Zero behaviour change. `RAMS_UNIFIED_COMPOSER=false`
(default) keeps both PDF + DOCX on their pre-refactor paths byte-for-byte.
When flipped to `true`, the cover renders from DTO with the
client-contact line-break fix; rest delegates to legacy = byte-identical.
Snapshot delta v1→v2 on Tilda: PDF HTML +779 bytes, DOCX XML +225 bytes
(both = the client-contact `<br>` / `<w:br/>` only).

## Plan 05b — what remains

Per `plan-05-parity-sweep/PLAN.md`:

1. **Full DTO adoption for compliance-upgrade fields.** rams-v2.blade
   + DocxBuilderServiceV2 currently read `site_logistics`, `ppe_matrix`,
   `access_equipment_detail`, `cdm_duty_holders`, `programme` etc. from
   `$data` / `$rams` legacy. Move each to DTO. ~1 day of section-by-section
   porting.
2. **Real Tilda fixture from live VPS snapshot.** Current
   `tests/Fixtures/rams/tilda-21cq29531/record.json` is hand-crafted
   because Plan 03 executor had no live-DB access. Pull the real record
   92 via `\App\Models\RamsDocument::find(92)?->toJson()` on VPS +
   commit. Regenerate all snapshot goldens.
3. **Full-fixture sweep** across all 5 fixtures (fresh-build,
   prior-rams-carry, decommission-heavy, missing-survey, empty-scope) +
   Tilda. Visual diff both formats, log drift, fix atomically.
4. **Global flag flip.** `RAMS_UNIFIED_COMPOSER=true` in
   `.env.example`; live `.env` gets flag flipped in deploy step. Sanity
   checks: regenerate Tilda (parity), regenerate a project with a novel
   fixture (renders correctly), verify snapshot tests still pass.
5. **`HOW-TO-CHANGE-RAMS.md`** documentation.
6. **STATE + phase SUMMARY.**

## Post-phase (deferred quick task)

- **`260803-rf3-remove-old-render-paths`** — after 1-week live soak.
  Delete `DocxBuilderService`, `resources/views/pdf/rams.blade.php`
  (the OLD blade), remove kill switch, drop fallback tests. Rename V2
  files to canonical names.

## How to resume

New session, verify at `a743af5` or later:
```
git log --oneline -5
```

1. Read this file + `PHASE.md` + `plan-05-parity-sweep/PLAN.md` +
   `deferred-items.md`.
2. Spawn executor for Plan 05b Task 1 (full DTO adoption in both
   renderers). Bounded chunk.
3. After Task 1 lands, spawn executor for Plan 05b Tasks 2-4 (fixture
   + sweep + flip). Needs live-VPS access for the real Tilda record.
4. Docs + close.

## Rollback

`RAMS_UNIFIED_COMPOSER=false` in `.env` + `php artisan config:cache`.
Everything reverts to pre-phase code paths.
