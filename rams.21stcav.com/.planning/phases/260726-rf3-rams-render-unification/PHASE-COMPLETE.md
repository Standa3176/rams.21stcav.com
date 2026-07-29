---
phase: 260726-rf3-rams-render-unification
status: complete_opt_in
completed_at: 2026-07-29
last_commit: 737bf2a
branch: feat/worksheet-classifier-universal
flag_default: RAMS_UNIFIED_COMPOSER=false  # opt-in per project until global sweep
---

## Status: complete (opt-in)

All 5 plans landed. Unified pipeline is available behind
`RAMS_UNIFIED_COMPOSER` env flag. Flag defaults **off** in
`.env.example` so existing installs get zero behaviour change.

Global default-on flip is a follow-up quick task
(`260805-rf3-global-flag-flip`) — parked until we have:
1. Real Tilda fixture pulled from live VPS (currently hand-crafted).
2. Snapshot goldens regenerated across all 5 fixture scenarios.
3. Resolution of the 3 DOCX deferrals (Standards, Emergency, Welfare)
   documented in `deferred-items.md`.

Kill switch removal (delete legacy V1 paths + rename V2 → canonical
names) is a further follow-up quick task
(`260812-rf3-remove-old-render-paths`) after 1-week soak with the
flag on globally.

## Shipped commits

| Plan | Commits | Prod risk |
|------|---------|-----------|
| 01 — Theme + DTO scaffolding | 1c366a9 + 5a0daa6 + 13f6555 + b0a2df1 | none — pure additive |
| 02 — RamsDocumentComposer | 6c888df + a550a65 + eecea4a + 903e5c5 | none — patch service marker only |
| 03 — PDF refactor + kill switch | e707e9b + 4c3fa81 + c2a1a99 + 68a1a8a | none with flag off |
| 04 — DOCX refactor | 9648cf1 + 7c6288f + 5629cb2 | none with flag off |
| 05a — Deferred bug fixes | 0f7bdad + a743af5 | none — defensive |
| 05b Part 1 — DTO adoption extend | c207236 + 737bf2a | none with flag off; +663 bytes PDF delta / +100 bytes DOCX delta with flag on |

**Total: 21 code commits + phase-level docs.** Zero prod behaviour
change with flag off (default). Snapshot test v1 vs v2 delta on Tilda
proves parity — only the client-contact line-break + a small whitespace
tidy differ.

## DTO adoption coverage (as of 737bf2a)

**Both formats via DTO:**
- Cover (client + site + ref + rooms + date + personnel + client contact
  with the line-break fix)
- Document Control (revision history)
- Company Information
- Exclusions
- Sign-off

**PDF via DTO, DOCX still legacy delegation:**
- Standards & Guidance table
- Emergency Procedures

**Both formats still legacy:**
- Health & Safety Policy
- Scope of Works
- Room Overviews
- Risk Assessment (matrix + hazards)
- Method Statement (all 12 sub-sections)
- COSHH
- Environmental Management
- Welfare Arrangements
- Appendix Toolbox

The gap between "both formats DTO" and "one or both legacy" doesn't
compromise parity — legacy delegations produce byte-identical output
to pre-phase. Migration continues incrementally as future work touches
those sections.

## How to enable the unified pipeline

**Per-project QA (safe):** Set `RAMS_UNIFIED_COMPOSER=true` in
`.env` on a QA server → `php artisan config:cache && php artisan
queue:restart` → regenerate a RAMS. Snapshot tests guarantee identical
output on the Tilda fixture; real projects may surface edge cases.

**Global on live:** Wait for the follow-up quick task above. Same
flag flip + cache-clear + restart, but with more fixture coverage
first.

**Instant rollback:** `RAMS_UNIFIED_COMPOSER=false` + cache-clear +
restart. Every render immediately reverts to legacy paths.

## Related

- **`HOW-TO-CHANGE-RAMS.md`** (same folder) — one-page reference for
  "I want to change the RAMS output — where do I edit it?"
- **`deferred-items.md`** — 3 pre-existing / 3 Plan 05b deferrals +
  1 fixture-realism gap, each with a fix path.
- **`PHASE.md`** — original architecture (5-plan roadmap).

## Follow-up quick tasks (deferred)

| Task | Trigger |
|------|---------|
| `260802-rf3-tilda-fixture-from-live` | On any machine with VPS DB access |
| `260805-rf3-global-flag-flip` | After Tilda fixture from live + 5-fixture snapshot regen |
| `260812-rf3-remove-old-render-paths` | 1 week after global flag flip lands cleanly on live |
| `260812-rf3-resolve-docx-deferrals` | Standards + Emergency + Welfare shape/content decisions |
