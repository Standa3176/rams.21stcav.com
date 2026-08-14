---
phase: 24-stencil-curation-ui-quote-import-auto-stub
plan: 10
subsystem: cli-tooling
tags: [laravel, artisan, gap-closure, device-stencils, drift-correction, uat]

# Dependency graph
requires:
  - phase: 24-01
    provides: DeviceStencil.needs_review column (migration backfill), DeviceStencil::audits() HasMany relation
  - phase: 24-08
    provides: stencils:reapply-templates command being amended by this plan
provides:
  - "stencils:reapply-templates eligibility corrected to needs_review=true AND whereDoesntHave('audits') — the command now actually reaches the 91 real zero-port stencil stubs"
affects: [24-11, 24-12]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Gap-closure defect fix: eligibility predicate swap (source=X -> needs_review=true) while leaving the whereDoesntHave(relation) safety clause byte-for-byte unmodified, with a dedicated regression test proving that clause alone still bounds a widened destructive-command surface"

key-files:
  created: []
  modified:
    - app/Console/Commands/StencilsReapplyTemplatesCommand.php
    - tests/Feature/Console/StencilsReapplyTemplatesCommandTest.php

key-decisions:
  - "Eligibility predicate swapped from source=auto-generated to needs_review=true; whereDoesntHave('audits') left completely untouched — it is now the SOLE protection against --commit overwriting engineer-touched work, since source no longer participates in the query at all."
  - "Added a Source column to the dry-run/commit report table so an operator can see which rows are engineer-curated vs auto-generated before running --commit, now that the command can legitimately touch both."
  - "Command still never mutates source or needs_review on any stencil it re-templates — it only fills mxgraph_xml/device_ports content. Promotion/review-clearing stays exclusively the admin UI's job (D-04/D-07), unchanged by this plan."

requirements-completed: []

# Metrics
duration: ~25min
completed: 2026-08-14
---

# Phase 24 Plan 10: Fix stencils:reapply-templates Eligibility (UAT Gap 1) Summary

**Swapped the reapply-templates command's eligibility predicate from the false `source=auto-generated` proxy to the real `needs_review=true` signal, unblocking all 91 real zero-port stencil stubs while keeping `whereDoesntHave('audits')` as the sole, unmodified safety boundary — proven live: dry-run went from "No eligible stencils. Nothing to do." to reporting all 91 eligible rows.**

## Performance

- **Duration:** ~25 min
- **Started:** 2026-08-14
- **Completed:** 2026-08-14
- **Tasks:** 2/2 completed
- **Files modified:** 2

## Accomplishments

- **Task 1** — `StencilsReapplyTemplatesCommand::handle()`'s eligibility query now reads `where('needs_review', true)->whereDoesntHave('audits')`, replacing the disproven `where('source', DeviceStencil::SOURCE_AUTO_GENERATED)` clause. The "no eligible stencils" message, the class docblock's D-11 reference and SAFETY paragraph, and the dry-run report table (new `Source` column) were all updated to describe and expose the corrected predicate.
- **Task 2** — Added `makeRealisticEngineerCuratedStub()` (the literal empirical shape UAT found: `source=engineer-curated`, `needs_review=true`, `metadata.needs_phase_24_curation=true`, zero ports) and two new tests: one proving `--commit` now templates that shape without mutating its `source`/`needs_review`, and one proving `whereDoesntHave('audits')` alone still excludes it once an audit row exists. All 6 pre-existing tests were re-verified unchanged (their fixtures were already `needs_review`-shaped correctly, coincidentally, per the plan's task-2 analysis) — 8/8 pass.
- **Real-world proof** (see below): the local SQLite catalogue, seeded with the actual 96-stencil / 91-zero-port-stub shape, went from reporting zero eligible stencils to reporting all 91 as eligible under dry-run, with no writes performed.

## Task Commits

Each task was committed atomically:

1. **Task 1: Correct the eligibility predicate + report column** - `f98e0fa` (fix)
2. **Task 2: Realistic-catalogue regression tests** - `af2bd14` (fix)

## Files Created/Modified

- `app/Console/Commands/StencilsReapplyTemplatesCommand.php` - eligibility clause `where('needs_review', true)` replaces `where('source', DeviceStencil::SOURCE_AUTO_GENERATED)`; `whereDoesntHave('audits')` unchanged; new `Source` report column; corrected "no eligible" message and docblock (D-11 correction + widened-surface safety note).
- `tests/Feature/Console/StencilsReapplyTemplatesCommandTest.php` - added `makeRealisticEngineerCuratedStub()` helper and two new tests (`test_realistic_engineer_curated_stub_with_needs_review_gets_templated_in_commit_pass`, `test_engineer_curated_stub_with_needs_review_and_audit_row_is_never_touched`); updated class docblock and one existing test's inline comment to reflect the corrected predicate. 8 tests total, all green.

## Real-World Proof (dry-run before/after)

**Before (this plan's baseline, run against the pre-fix command):**
```
── DRY-RUN MODE (default) — no writes ──

No eligible stencils (source=auto-generated with zero device_stencil_audits rows). Nothing to do.
```

**After (post-fix, same local SQLite catalogue — 96 stencils, 91 zero-port engineer-curated stubs):**
```
── DRY-RUN MODE (default) — no writes ──

Scanning 91 eligible stencil(s)...

+---------+-------------------+------------------+-----------+-----------+
| Stencil | Part Number       | Source           | Old Ports | New Ports |
+---------+-------------------+------------------+-----------+-----------+
| 40      | am-3200-gv        | engineer-curated | 0         | 0         |
...
| 51      | gs108t            | engineer-curated | 0         | 4         |
...
| 92      | in1608            | engineer-curated | 0         | 4         |
+---------+-------------------+------------------+-----------+-----------+

── Totals: 52 stencil(s) affected

DRY-RUN — no stencils were changed.
Re-run with --commit to persist. Command is idempotent — running twice with --commit produces no additional diffs.
```

All 91 stubs are now correctly seen as eligible (up from 0). 52 of the 91 show a real content diff (new template resolves ports for that part number); the remaining 39 resolve to zero ports under the current `port_templates` config (ambiguous/unrecognised part numbers) and so produce no report row (no drift to show) — this is expected `stencils:coverage-report`/curation territory, not a bug in this command. No writes occurred (`git status --short` confirmed clean after the run; dry-run is still the default).

## Deviations from Plan

**1. [Informational, not a fix] Acceptance-criteria grep-count mismatch for `whereDoesntHave('audits')`.** Task 1's acceptance criteria stated `grep -c "whereDoesntHave('audits')"` should return `1` ("unchanged, still present exactly once"). The pre-existing file already contained this literal string twice before this plan touched it (once in the class docblock's SAFETY paragraph, once in the actual query at what was then line 76) — so the premise "present exactly once" was already false pre-fix. This plan's docblock rewrite (describing the corrected predicate and the widened-surface safety argument, as Task 1's `<action>` explicitly requires) added one further docblock mention, bringing the total to 3. The functionally load-bearing invariant — the clause's presence, position, and behavior inside the actual `handle()` query — is unchanged and present exactly once there, which is what the plan's safety requirement is actually about. No code behavior is affected; documented here per Rule 1 threshold ("does this affect correctness" — no) rather than silently diverging from the plan's literal acceptance text.

No other deviations — plan executed as written. `whereDoesntHave('audits')` clause itself was not touched, reordered, or weakened at any point.

## Issues Encountered

None. Did not run the broader `tests/Feature` suite — scoped `--filter=StencilsReapplyTemplates` only, per this plan's verification gates. The 2 pre-existing `DrawIoSpikeController` constructor-arity failures logged in `deferred-items.md` (Plan 24-01) are unrelated to any file this plan touches and were not re-checked or counted as regressions.

## User Setup Required

None. No external service configuration required; this is a pure defect fix to an existing CLI command.

## Next Phase Readiness

- `stencils:reapply-templates` is now genuinely usable against the real catalogue. An operator can run dry-run, review the `Source` column in the report, then `--commit` to fill the 91 real zero-port stubs — this unblocks Criterion 5 (bounded top-10 Tier 1 fill) and the general D-08 workflow.
- Plans 24-11 and 24-12 (the other two gap-closure plans from `24-UAT.md`) are untouched by this plan and remain to be executed separately.
- `--commit` was deliberately NOT run against the local seeded data during this execution, per the verification-gates instruction — that action is left to a real operator decision after reviewing the dry-run diff.

---

## 🚨 Files to upload to live

Per Phase 21 D-13 (local-edit-then-upload convention), the following file from this plan must be uploaded to the Hostinger VPS on next deploy:

- `app/Console/Commands/StencilsReapplyTemplatesCommand.php`

**No migration in this plan.** This fix depends entirely on Plan 24-01's `needs_review` column already being live on the target environment (already deployed per Plan 24-08's SUMMARY). Once uploaded, `php artisan stencils:reapply-templates` (dry-run) should be run first against live data to confirm the 91-stub count matches, reviewed, and only then re-run with `--commit` by an operator — never as an automated deploy step.

Test file (`tests/Feature/Console/StencilsReapplyTemplatesCommandTest.php`) is not required on live — it exists for the local/CI test suite only.

## Self-Check: PASSED

Both modified files verified present on disk. Both task commit hashes (`f98e0fa`, `af2bd14`) verified present in `git log`.
