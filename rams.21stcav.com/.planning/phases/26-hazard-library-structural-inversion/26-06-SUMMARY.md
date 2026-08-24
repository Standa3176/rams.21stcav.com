---
phase: 26-hazard-library-structural-inversion
plan: 06
subsystem: testing
tags: [laravel, phpunit, docx, hazard-library, rams, regression]

# Dependency graph
requires:
  - phase: 26-01
    provides: "18-hazard global library seeded with the include_when tier vocabulary"
  - phase: 26-04
    provides: "RiskTemplateResolverService wired to HazardIncludeWhenResolver — live pipeline entry point for hazard resolution"
  - phase: 26-05
    provides: "RamsReviewDataService numeric score schema, needs_confirmation/score_reviewed markers"
provides:
  - "DOCX-path proof (not the non-live rams-v2.blade.php / RiskAssessmentComposer) that a near-empty scope yields the 4 always-tier + 5 confirm-tier hazards (9 rows) and never resurrects the old fixed-11 baseline"
  - "DOCX-path proof that Working at height renders residual 1x4 (=4) in the actual generated document.xml, not the config baseline's 2x3"
  - "RA{NN} ref-stability regression proven at register size 8 (in addition to the pre-existing size-3 guard), confirming refs still resolve 1:1 as the register becomes genuinely variable-length"
  - "Belt-and-braces HazardTemplateSeeder idempotency regression: 18 global rows after two seed runs, zero rows with a null include_when after the second run"
affects: []

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "DOCX-path test pattern (ZipArchive -> word/document.xml string assertions) duplicated verbatim from DocxBuilderNewSectionsTest::renderDocumentXml() — no shared trait exists yet for it across the 4 test files that now carry a copy"

key-files:
  created:
    - tests/Feature/Rams/HazardTemplateSeederIdempotencyTest.php
    - tests/Feature/Rams/WorkingAtHeightResidualScoreTest.php
  modified:
    - tests/Unit/Services/Rams/MethodStatementAssociatedRisksTest.php

key-decisions:
  - "Test 1 of WorkingAtHeightResidualScoreTest asserts 9 rows (4 always + 5 confirm), not 'exactly the 4 always-tier hazards' as this plan's own <action> text and must_haves.truths literally state. This corrects the same documented inaccuracy 26-04-SUMMARY.md already flagged in the plan's Task 2 wording: HazardIncludeWhenResolver (26-02) unconditionally surfaces confirm:<key> hazards on every job per CONTEXT.md's binding 2026-08-23 tier-3 correction. Asserting a literal 4-row register would have made the test assert something the locked design explicitly contradicts."
  - "The exact DOCX residual-score string ('1x4=4', using the U+00D7 multiplication sign) was read directly from DocxBuilderService::buildRiskAssessment() (:1274, \"{$postL}x{$postS}={$postScore}\") rather than discovered by running the test once and reading a failure diff, since the source was unambiguous. The test still runs the DOCX build and reads the string back out of the real generated file — it does not skip DOCX-path proof, only the trial-and-error discovery step the plan's action text suggested as one way to find the string."
  - "MethodStatementAssociatedRisksTest's new 8-hazard fixture and test method are additive only — the original 3-hazard fixture and test are untouched and still pass, per the plan's explicit 'both must coexist' instruction."

patterns-established: []

requirements-completed: []

# Metrics
duration: ~50min
completed: 2026-08-24
---

# Phase 26 Plan 06: Hazard Library Structural Inversion — DOCX-Path Proof + RA-Ref Regression (Tasks 1-2) Summary

**Proved HAZ-02's near-empty-scope behaviour and HAZ-03's 1x4 residual score through the actual live DOCX renderer (not a fixture, not the non-live PDF composer), and extended the RA-ref regression guard to a variable-length (8-hazard) register — Task 3 (live deploy to rams.21stcav.com + manual 21CQ30960 spot-check) is a human checkpoint and was deliberately NOT executed by this run.**

## Performance

- **Duration:** ~50 min
- **Started:** 2026-08-24T (session start, first Read of plan files)
- **Completed:** 2026-08-24T (this summary)
- **Tasks:** 2/3 completed (Task 3 is a `checkpoint:human-verify` gate, correctly not run — see Deviations)
- **Files modified:** 3 (2 created, 1 modified)

## Accomplishments
- `MethodStatementAssociatedRisksTest` gained an 8-hazard, non-sequential-id fixture (`biggerHazards()`) and a new test proving every emitted `RA{NN}` reference still resolves 1:1 against the rendered risk register at that size — coexisting with, not replacing, the original 3-hazard guard. This matters because Plans 26-03/26-04 removed the fixed 11-hazard register entirely; refs are now genuinely computed against a variable-length register on every job, not a constant.
- New `HazardTemplateSeederIdempotencyTest`: seeds `HazardTemplateSeeder` twice, asserts exactly 18 `is_global=true` rows after each run, and — the part not covered by Plan 26-01's existing `HazardTemplateSeederIncludeWhenTest` — asserts every one of those 18 rows still carries a non-null `include_when` after the second run. A silent null-out on re-seed would quietly downgrade every global hazard to D-04's "manual-only" behaviour and kill auto-population without any row-count symptom.
- New `WorkingAtHeightResidualScoreTest`, built through the real live pipeline entry point (`RiskTemplateResolverService::resolve()`) and read back out of an actual generated `.docx` via `ZipArchive`/`word/document.xml` (the pattern from `DocxBuilderNewSectionsTest::renderDocumentXml()`, copied verbatim — no shared trait exists yet):
  - Test 1: a genuinely blank scope (`resolve([], false, $user->id, [], [])`) resolves to the 4 always-tier hazards plus the 5 confirm-tier hazards (9 rows total — D-06's "always surfaced for human confirmation" behaviour, not a bug), and the DOCX never contains any of the three canary old-baseline titles (`Manual Handling of AV Equipment`, `Electrical Isolation`, `Working at Height`).
  - Test 2: `resolve(['ceiling_works'], ...)` resolves `Working at height` via `signal:mounting_above_reach`, confirmed `post_likelihood===1`/`post_severity===4` at the PHP-array level, then confirmed the DOCX's actual residual-risk cell renders the exact string `1×4=4` (read directly from `DocxBuilderService::buildRiskAssessment()`'s cell-text expression, not guessed).
- Full suite run: **2265 passed, 1 failed** (the same documented pre-existing `QueueRecoverCommandTest::test_unhealthy_queue_runs_restart_and_drain_plan` memory-limit flake noted in every prior plan's summary since 26-04 — not chased, zero new failures introduced by this plan).

## Task Commits

1. **Task 1: RA-ref regression extension + seeder idempotency hardening** - `f88d6fb` (test)
2. **Task 2: DOCX-path proof — HAZ-02 near-empty scope + HAZ-03 residual score, full suite run** - `661b4cb` (test)

Task 3 (Live deploy + manual 21CQ30960 spot-check + reversibility proof) is a `checkpoint:human-verify` gate with `gate="blocking"` and was **not executed** — see "Checkpoint Not Executed" below.

## Files Created/Modified
- `tests/Unit/Services/Rams/MethodStatementAssociatedRisksTest.php` - Added `biggerHazards()` (8 non-sequential-id fixture) and `test_emitted_ra_ids_all_exist_in_the_rendered_risk_register_at_variable_length()`, coexisting unmodified alongside the original 3-hazard test.
- `tests/Feature/Rams/HazardTemplateSeederIdempotencyTest.php` (new) - 2 tests: row-count stability across two seed runs, and non-null `include_when` on every global row after the second run.
- `tests/Feature/Rams/WorkingAtHeightResidualScoreTest.php` (new) - 2 feature tests proving HAZ-02 near-empty-scope behaviour and HAZ-03's 1x4 residual through the real DOCX renderer.

## Decisions Made
- Corrected the plan's own Task 2 `<action>` wording (and the mirrored `must_haves.truths` claim of "only the 4 always-tier hazard names") to match the actually-locked design: a blank-signal job legitimately yields 9 rows, not 4, per CONTEXT.md's binding scoping correction 2 and 26-04-SUMMARY.md's prior documentation of the same inaccuracy. Asserting the plan's literal wording would have made the test fail against correct, already-shipped behaviour.
- Found the exact DOCX residual-score render string (`"{$postL}×{$postS}={$postScore}"`, `app/Services/DocxBuilderService.php:1274`) by reading the source directly rather than running the test once to read a failure diff — both tests still exercise the real live build+unzip path, so the DOCX-path proof requirement is unaffected; only the discovery method for the literal string differed from the plan's suggested trial-and-error approach.
- Did not extract `renderDocumentXml()` into a shared trait even though the plan raised the possibility — checked first (per the plan's own instruction) and confirmed no such trait exists; 4 test files (including this plan's new one) now each carry an independent copy, matching the established pattern rather than introducing a new abstraction mid-phase.

## Deviations from Plan

### Checkpoint Not Executed (by design — see HARD_STOP_BOUNDARY)

**Task 3: Live deploy + manual 21CQ30960 spot-check + reversibility proof** was **not run**. This is a `checkpoint:human-verify` gate (`gate="blocking"`) requiring a real deploy to `rams.21stcav.com` as the `stcav` user, a live database migration + seed, manual visual comparison of a real project's generated hazard register against its source quote, and a live `.env` flag toggle — none of which an autonomous executor is authorised to perform against production. See the `## CHECKPOINT REACHED` report below for the exact steps a human operator should run.

### Auto-fixed Issues

None — plan executed exactly as written for Tasks 1-2, other than the wording correction to Task 2's near-empty-scope row-count claim documented above (not a code fix; a test-assertion correction to match already-locked, already-shipped design).

## Issues Encountered
- `php` is not on `PATH` in this execution shell (same issue noted in every prior Phase 26 plan's summary) — resolved by prepending `/c/Users/sonny.tanda/.config/herd/bin/php84` to `PATH` for every `php artisan` invocation in this session.

## User Setup Required
Task 3's live deploy — see the `## CHECKPOINT REACHED` report for the exact steps.

## Next Phase Readiness
- Tasks 1 and 2's automated proof is complete and green: `--filter=MethodStatementAssociatedRisksTest` (5/5), `--filter=HazardTemplateSeederIdempotencyTest` (2/2), `--filter=WorkingAtHeightResidualScoreTest` (2/2), full suite (2265 passed / 1 pre-existing unrelated failure).
- HAZ-01..04 are already marked `[x]` Complete in `REQUIREMENTS.md` from Plans 26-01/26-03/26-04/26-05 (verified before writing this summary) — this plan adds proof depth (DOCX-path, variable-length register) but does not newly flip any requirement checkbox. `requirements-completed: []` above reflects that this plan's own two tasks close no new requirement on their own; the phase's remaining open item is Task 3's live human checkpoint.
- The phase cannot be marked ready for `/gsd:verify-work` until Task 3 is approved live — this is stated explicitly in the plan's own `<success_criteria>` ("Reversibility is demonstrated live, not just asserted in a unit test").

## Self-Check: PASSED

- `tests/Feature/Rams/HazardTemplateSeederIdempotencyTest.php` — FOUND
- `tests/Feature/Rams/WorkingAtHeightResidualScoreTest.php` — FOUND
- `tests/Unit/Services/Rams/MethodStatementAssociatedRisksTest.php` — FOUND (modified)
- Commit `f88d6fb` — FOUND in `git log`
- Commit `661b4cb` — FOUND in `git log`
