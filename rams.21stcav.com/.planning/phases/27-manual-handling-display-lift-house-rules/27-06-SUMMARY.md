---
phase: 27-manual-handling-display-lift-house-rules
plan: 06
subsystem: rams
tags: [rams, display-lift, manual-handling, gate-09, phpunit]

# Dependency graph
requires:
  - phase: 27-manual-handling-display-lift-house-rules (Plan 27-01)
    provides: "DisplayLiftPolicy — the shared bands class (forSize(), violatesPolicy(), wallMountRemovalStatement())"
  - phase: 27-manual-handling-display-lift-house-rules (Plan 27-03)
    provides: "GATE-09: RamsComplianceUpgradeService::enforceDisplayLiftGate(), config-gated by RAMS_DISPLAY_LIFT_GATE, checking material_handling_derived.items only"
provides:
  - "enforceDisplayLiftGate() extended to also check engineer-typed material_handling.large_items[] rows (previously explicitly out of scope)"
  - "parseStatedTeamSize()/parseStatedInches() — conservative free-text parsers extracting operative counts and inch sizes from engineer handling_method strings, never guessing on ambiguity"
  - "RamsBuilderService::runFromReview()/runPipeline() now mirror reviewed_data/form_data['material_handling'] onto the generated_data array before upgrade() — closing a wiring gap that made the engineer-typed override invisible to the whole compliance-upgrade pipeline, not just GATE-09"
  - "Unmocked dual-path proof (DisplayLiftDualPathTest) that GATE-09 can fire on real production-shaped data via both real generation entry points"
affects: [27-05-live-deploy, future-gates-06-07-11-12-13-14]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Mask-then-count ambiguity detection for free-text parsing: mask out recognised size/inch phrases (including ranges like 'NN to MM inches'), then require exactly one distinct leftover number — more than one or zero means null, never a guess (T-27-06-01)"
    - "Deliberate, explicitly-recorded exception to an established convention (here: 'engineer values always win, never re-validated') rather than a silent violation of it — the docblock and this summary both carry the 2026-08-26 user-decision citation"

key-files:
  created:
    - tests/Unit/Services/Rams/DisplayLiftGateEngineerRowsTest.php
  modified:
    - app/Services/Rams/RamsComplianceUpgradeService.php
    - app/Services/RamsBuilderService.php
    - tests/Feature/Rams/DisplayLiftDualPathTest.php

key-decisions:
  - "DELIBERATE CONVENTION EXCEPTION (per <why_this_plan_exists>/user decision 2026-08-26): material_handling.large_items is engineer-typed free text that Plan 27-03 declared out of GATE-09's scope on the grounds of the codebase's established 'engineer values always win, never re-validated' pattern (HAZ-04's score_reviewed precedent). This plan reverses that for display-lift team size specifically — a stated lift team size is treated as a safety claim, not a preference, and is re-validated against DisplayLiftPolicy::violatesPolicy() like every other stated team size. This does NOT reopen HAZ-04 or any other engineer-wins field; it is scoped to this one gate."
  - "[Rule 2 - missing critical functionality] RamsBuilderService::runFromReview()/runPipeline() never mirrored reviewed_data/form_data['material_handling'] onto the array passed to RamsComplianceUpgradeService::upgrade() — discovered while building Task 3's dual-path proof. Without this mirror, Task 2's new engineer-row gate loop would see an empty array on every real generation, and — independent of GATE-09 entirely — the engineer's stated team size never reached generated_data (and therefore DocxBuilderService's DOCX render) via either entry point. Fixed by mirroring material_handling onto $data immediately before upgrade() in both methods, exactly matching the existing scope_items mirror pattern a few lines above in runFromReview()."
  - "Two residual gaps found but NOT fixed (out of this plan's scope, recorded in deferred-items.md): (1) RamsController::updateAndDownload() ('Save Review') builds $generatedData and calls upgrade() directly but never mirrors material_handling onto it the way it explicitly mirrors site_emergency — so GATE-09 still cannot fire on that specific action; (2) the PDF template (resources/views/pdf/rams.blade.php:418) reads material_handling straight from reviewed_data, bypassing generated_data/upgrade()/GATE-09 entirely by design — no mirror fix on the generation side can close this without a user decision on which source of truth the PDF should render from."
  - "parseStatedTeamSize()'s ambiguity design: mask out inch/size phrases (including 'NN to MM inches' ranges) first, then treat the leftover text's distinct bare numbers as the candidate team-size set — exactly one leftover number is required. This is what makes '2 persons normally, 3 for the 98 inch' correctly return null (two leftover numbers, 2 and 3) while every DisplayLiftPolicy::forSize() band sentence (which mentions its own inch range plus exactly one team-size number) round-trips cleanly."

requirements-completed: [GATE-09]

# Metrics
duration: 70min
completed: 2026-08-26
---

# Phase 27 Plan 06: GATE-09 Engineer-Row Coverage-Gap Closure Summary

**Extended enforceDisplayLiftGate() to re-validate engineer-typed material_handling.large_items[] rows via a conservative free-text team-size/inches parser, and fixed a previously-undiscovered wiring gap (RamsBuilderService never mirrored that field into generated_data at all) so the gate — and the rendered document — can actually see what the engineer typed.**

## Performance

- **Duration:** ~70 min
- **Started:** 2026-08-26T13:00:00Z (approx.)
- **Completed:** 2026-08-26T14:10:00Z (approx.)
- **Tasks:** 3 completed
- **Files modified:** 2 production files + 2 test files, 1 new test file

## Accomplishments
- `parseStatedTeamSize()`/`parseStatedInches()` conservatively extract a team size and an inch value from engineer-typed `handling_method`/`item` free text, recognising bare digits, number-words one-four, and "single"/"single-hand", while returning `null` (never a guess) on no recognisable count or on two-or-more conflicting counts — proven against every sentence `DisplayLiftPolicy::forSize()` itself emits for the 1/2/3 bands (the app can never reject its own correct output).
- `enforceDisplayLiftGate()` now runs a second pass over `material_handling.large_items[]`, routing every conformance decision through `DisplayLiftPolicy::violatesPolicy()` only — no reimplemented band numbers — and skips (never blocks) a row whose team size can't be confidently parsed.
- Discovered and fixed a real wiring gap: `RamsBuilderService::runFromReview()`/`runPipeline()` never carried `material_handling` from reviewed/form data into the array `upgrade()` operates on, so the engineer's override was invisible to the entire compliance pipeline — not just GATE-09 — on those two entry points. Fixed with a one-line mirror in each method, matching the existing `scope_items` mirror pattern.
- `DisplayLiftDualPathTest` gained two new, fully unmocked tests proving GATE-09 fires on real production-shaped data via both `runFromReview()` and `runPipeline()` — the proof Plan 27-03 could not construct for the derived-items branch, now closed for the engineer-typed branch.
- Rewrote `enforceDisplayLiftGate()`'s docblock, which previously argued `large_items` was explicitly out of scope, so the file no longer contradicts its own behaviour.

## Task Commits

Each task was committed atomically:

1. **Task 1: conservative team-size/inches parser** - `f640b67` (feat)
2. **Task 2: extend enforceDisplayLiftGate() to engineer-typed rows** - `bebd8fe` (feat)
3. **Task 3: unmocked dual-path proof + engineer-row test suite** - `5e28340` (test)

**Plan metadata:** (this commit, docs: complete plan)

## Files Created/Modified
- `app/Services/Rams/RamsComplianceUpgradeService.php` - Added `INCH_REGEX` shared constant (extracted from `suggestHandlingMethod()`'s inline pattern), `parseStatedTeamSize()`, `parseStatedInches()`; extended `enforceDisplayLiftGate()` with a second loop over `material_handling.large_items[]`; rewrote the method's docblock to record the 2026-08-26 scope reversal instead of arguing against it.
- `app/Services/RamsBuilderService.php` - Mirrors `material_handling` from `reviewedData`/`formData` onto the pipeline array immediately before each of the two `RamsComplianceUpgradeService::upgrade()` call sites (`runFromReview()`, `runPipeline()`) — a Rule 2 auto-fix, not originally in this plan's `files_modified` list.
- `tests/Unit/Services/Rams/DisplayLiftGateEngineerRowsTest.php` - New: parser unit tests (recognised phrasings, ambiguity-returns-null cases, round-trip against `DisplayLiftPolicy::forSize()`'s own sentences), engineer-row enforce/skip reflection tests, kill-switch test, and a grep-based test asserting `enforceDisplayLiftGate()`'s only conformance call is `DisplayLiftPolicy::violatesPolicy(`.
- `tests/Feature/Rams/DisplayLiftDualPathTest.php` - Added `test_gate_fires_on_engineer_row_via_run_from_review`/`_run_pipeline` (real `DisplayLiftPolicy`, no mock); annotated the two retained alias-mocked violating-fixture tests to record they cover the derived-items branch only.
- `.planning/phases/27-manual-handling-display-lift-house-rules/deferred-items.md` - New: records the two residual gaps found but not fixed (see Decisions).

## Decisions Made
See `key-decisions` in frontmatter above. Most consequential: this plan makes a **deliberate, explicitly-recorded exception** to the codebase's "engineer values always win, never re-validated" convention for display-lift team size specifically, per the 2026-08-26 user decision cited in `27-06-PLAN.md`'s `<why_this_plan_exists>`. This is scoped narrowly — no other engineer-entered field's re-validation posture changes.

The second most consequential finding: GATE-09's engineer-row extension, as specified by the plan, would have been correct code that still could never fire on live data, for a *different* reason than Plan 27-03's original gap — `material_handling` was never threaded from `reviewed_data`/`form_data` into the array `RamsComplianceUpgradeService::upgrade()` sees, on either real generation entry point. This was fixed (Rule 2) as part of Task 3's proof requirement. Two adjacent instances of the same underlying gap remain and are NOT fixed here (recorded in `deferred-items.md`): `RamsController::updateAndDownload()`'s direct `upgrade()` call site, and the PDF template's direct `reviewed_data` read that bypasses `generated_data`/GATE-09 entirely by design.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical] `RamsBuilderService::runFromReview()`/`runPipeline()` never mirrored `material_handling` into the array `upgrade()` operates on**
- **Found during:** Task 3 (building the unmocked dual-path proof — the seeded engineer row was invisible to the gate until this fix)
- **Issue:** `RamsDataBuilderService::assemble()` never includes a `material_handling` key, and neither `runFromReview()` nor `runPipeline()` copied `reviewedData['material_handling']`/`formData['material_handling']` onto `$data` before calling `RamsComplianceUpgradeService::upgrade($data)`. Task 2's new engineer-row loop was correctly implemented but structurally unreachable through the real entry points — and, independent of GATE-09, the engineer's override never reached `generated_data`/the rendered DOCX via these two paths either.
- **Fix:** Added `$data['material_handling'] = (array) ($reviewedData['material_handling'] ?? $data['material_handling'] ?? []);` (and the `formData` equivalent in `runPipeline()`) immediately before each `upgrade()` call, mirroring the existing `scope_items` mirror pattern already present in `runFromReview()`.
- **Files modified:** app/Services/RamsBuilderService.php
- **Verification:** `test_gate_fires_on_engineer_row_via_run_from_review`/`_run_pipeline` pass; non-vacuity check below confirms they were genuinely failing before this fix (reverting the gate's own loop, not this mirror, was the formal non-vacuity procedure — see below — but the mirror was independently verified necessary by first running the dual-path tests without it and observing the same "exception not thrown" failure).
- **Committed in:** 5e28340 (Task 3 commit)

---

**Total deviations:** 1 auto-fixed (Rule 2, missing critical functionality — a wiring gap discovered while proving Task 3's explicit acceptance criteria, not speculative scope creep). `RamsBuilderService.php` was not in `27-06-PLAN.md`'s `files_modified` list; the fix was the minimal change needed to satisfy Task 3's already-scoped dual-path proof requirement.
**Impact on plan:** Necessary for Task 3's acceptance criteria to be satisfiable at all with a real, unmocked `DisplayLiftPolicy`. No scope creep beyond the two named entry points (`runFromReview()`/`runPipeline()`) Task 3 required. Two adjacent instances of the same class of gap (`RamsController::updateAndDownload()`, the PDF template) were found but deliberately NOT fixed — see `deferred-items.md` — because they are outside this plan's task list and one of them is an architectural question needing a user decision.

## Issues Encountered

**Ambiguity-detection design required more than adjacency checking.** The acceptance criterion `parseStatedTeamSize('2 persons normally, 3 for the 98 inch')` must return `null` even though "3" has no `person`/`operative` keyword directly adjacent to it in that sentence — it only reads as a team-size mention by natural-language inference ("3 [persons] for the 98 inch [display]"). A purely keyword-anchored parser would have returned `2` (matching only "2 persons") instead of correctly recognising the conflict. Resolved by masking out all inch/size phrases (including "NN to MM inches" ranges, needed so `DisplayLiftPolicy::forSize()`'s own 55-to-90 band sentence round-trips correctly) and then requiring exactly one distinct bare number to remain in the leftover text — verified against all 9 Task 1 acceptance criteria plus the three-band round-trip test.

## User Setup Required

None - no external service configuration required. Reuses the existing `RAMS_DISPLAY_LIFT_GATE` kill-switch (default `true`); no new flag introduced.

## Non-Vacuity Proof (per-plan requirement)

Task 2 (`enforceDisplayLiftGate()`'s engineer-row loop):
1. Temporarily reduced `$largeItems` to a hardcoded `[]` (simulating the loop being silently disabled).
2. Re-ran `php artisan test --filter=DisplayLiftGateEngineerRowsTest`.
3. **Result:** 3 tests failed as expected (`test_engineer_row_four_persons_throws`, `test_engineer_row_single_operative_at_75_inches_throws`, `test_engineer_row_unresolvable_inches_still_checked_and_throws`), each failing with "Failed asserting that exception of type RamsGenerationException is thrown."
4. Restored the real assignment; re-ran: all 20 tests in the file passed again.

Task 3 (dual-path + full engineer-row suite together):
1. Repeated the same `$largeItems = []` reduction.
2. Re-ran `php artisan test --filter="DisplayLiftDualPathTest|DisplayLiftGateEngineerRowsTest"`.
3. **Result:** 5 tests failed as expected — the same 3 unit tests above, plus `test_gate_fires_on_engineer_row_via_run_from_review` and `test_gate_fires_on_engineer_row_via_run_pipeline` (both failing with "Expected RamsGenerationException was not thrown"), confirming the two new dual-path tests are not vacuously passing.
4. Restored; re-ran the combined filter (`DisplayLiftGateEngineerRowsTest|DisplayLiftDualPathTest|DisplayLiftGateTest|DisplayLiftPolicySourceGuardTest`): all 40 tests passed.

## Verification

- `php artisan test --filter="DisplayLiftGateEngineerRowsTest|DisplayLiftDualPathTest|DisplayLiftGateTest|DisplayLiftPolicySourceGuardTest"` — 40 tests, 74 assertions, all pass. Confirms:
  - Every Task 1/2 acceptance criterion (parser recognition, ambiguity-returns-null, round-trip against `DisplayLiftPolicy::forSize()`, enforce/skip boundaries, kill-switch, no-literal-band-numbers grep).
  - Both new dual-path tests fire on real, unmocked `DisplayLiftPolicy` via both generation entry points.
  - Plan 27-03's existing `DisplayLiftGateTest` (11 tests) and `DisplayLiftPolicySourceGuardTest` (3 tests) still pass unmodified — no regression on the derived-items branch or the structural allow-list guard.
- Full suite: `php artisan test` — 2369 passed, 1 failed, 6 skipped (9397 assertions), duration 557.25s (~9.3 min). The 1 failure is `Tests\Feature\Queue\QueueRecoverCommandTest::test_unhealthy_queue_runs_restart_and_drain_plan` — the exact documented pre-existing memory-limit flake from this plan's `<known_context>`. `BoundPdfDownloadTest` (the other documented pre-existing, order-dependent failure) did NOT fail in this run — consistent with its own description as order-dependent pollution from an unrelated Drawings test, not a regression either way. **No new failures** introduced by this plan's changes, including the `RamsBuilderService.php` deviation fix.

## Next Phase Readiness
- GATE-09 now covers both the policy-derived path (Plan 27-03) and the engineer-typed path (this plan) — the coverage gap ROADMAP criterion 3 and the 21CQ30960 review both motivated is closed for the two real RAMS-generation entry points.
- Two adjacent gaps remain and are recorded in `deferred-items.md` for a follow-up plan/quick-task: `RamsController::updateAndDownload()`'s missing `material_handling` mirror, and the PDF template's direct `reviewed_data` read that bypasses GATE-09 by design. Neither blocks this plan's own acceptance criteria, but both mean "the live PDF is gated" is not yet fully true — only the DOCX-generating entry points this plan proved are.

---
*Phase: 27-manual-handling-display-lift-house-rules*
*Completed: 2026-08-26*

## Self-Check: PASSED

All created/modified files confirmed on disk; all 3 task commit hashes (f640b67, bebd8fe, 5e28340) confirmed in `git log --oneline --all`.
