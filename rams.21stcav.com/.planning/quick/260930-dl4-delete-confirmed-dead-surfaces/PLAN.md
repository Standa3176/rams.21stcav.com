---
phase: quick
plan: 260930-dl4
type: chore
severity: dead-code
subsystem: consolidation
autonomous: true
---

# Quick Task 260930-dl4: delete the confirmed-dead surfaces (SCOPE.md D-04)

## Objective

Execute D-04 of `.planning/consolidation/SCOPE.md` — the user's verbatim
instruction on the inventory was **"2 . delete."** — by removing the surfaces
that a per-item, repo-wide re-verification confirms have no caller.

Recall point: tag `pre-consolidation-260930` + `~/stcav_rams-pre-consolidation-260930.sql.gz`.

## Method — verify, THEN delete, per item

For every candidate, grep `app/ resources/ routes/ tests/ config/ database/ public/`
for **three** things: its route name, its view name, and its file path. Any hit in
live code (including a test) is a REFUSAL, not a deletion. A stale inventory is
how a live file gets deleted.

Explicit hazard: some tests assert route ABSENCE and some assert PRESENCE
(`routes/web.php:172-179`, `:337-343`). A deletion that makes an absence
assertion pass vacuously is as bad as one that breaks a live page.

## Tasks

1. Re-verify group 1 (non-resolvable filenames) → delete with `git rm` by name.
2. Re-verify group 2 (old survey engineer link + its two endpoints).
3. Re-verify group 3 (orphan routes: supersede-from-project, project-data, draw-io spike).
4. Repair any test enumeration list that names a deleted path.
5. Run the full gate set AFTER the deletions.

## Prohibitions

`git clean` is forbidden. Named paths only. No push, no deploy. The three pinned
files stay byte-identical to `4abd2b24`.
