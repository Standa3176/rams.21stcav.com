---
phase: 27-manual-handling-display-lift-house-rules
plan: 03
subsystem: rams
tags: [rams, display-lift, manual-handling, validation-gate, mockery, phpunit]

# Dependency graph
requires:
  - phase: 27-manual-handling-display-lift-house-rules (Plan 27-01)
    provides: "DisplayLiftPolicy — the shared bands class (forSize(), violatesPolicy(), wallMountRemovalStatement())"
  - phase: 27-manual-handling-display-lift-house-rules (Plan 27-02)
    provides: "RamsComplianceUpgradeService::suggestHandlingMethod()/deriveMaterialHandling() delegating to DisplayLiftPolicy for RULE-02/RULE-03/RULE-12"
provides:
  - "GATE-09: RamsComplianceUpgradeService::enforceDisplayLiftGate(), an independent re-check of every display item's stated team size against DisplayLiftPolicy::violatesPolicy()"
  - "config/rams_tier1.php display_lift_gate_enabled kill-switch (env RAMS_DISPLAY_LIFT_GATE, default true)"
  - "Structural guard (DisplayLiftPolicySourceGuardTest) preventing a future divergent, hardcoded copy of the display-lift bands"
affects: [27-05-live-deploy, future-gates-06-07-11-12-13-14]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Config-gated validation gate throwing into an EXISTING catch/STATUS_FAILED surface (BuildRamsDocumentJob) rather than inventing a new error-handling framework — the reusable pattern for GATE-06/07/11/12/13/14"
    - "Isolated-process (#[RunInSeparateProcess] + #[PreserveGlobalState(false)]) Mockery alias mock of a final, all-static-method class, used ONLY when a genuine violation cannot be constructed via legitimate business data flowing through the real production code path"

key-files:
  created:
    - tests/Unit/Services/Rams/DisplayLiftGateTest.php
    - tests/Feature/Rams/DisplayLiftDualPathTest.php
    - tests/Feature/Rams/DisplayLiftPolicySourceGuardTest.php
  modified:
    - app/Services/Rams/RamsComplianceUpgradeService.php
    - config/rams_tier1.php

key-decisions:
  - "enforceDisplayLiftGate() re-implements NO band logic itself — it only calls DisplayLiftPolicy::violatesPolicy() on the (min_persons, inches) pairs deriveMaterialHandling() already stored, satisfying the plan's 'never trusts the same call path that produced the text' anti-pattern guard"
  - "Discovered during implementation: DisplayLiftPolicy::forSize() and ::violatesPolicy() are independent implementations that share only their numeric band constants (D-03) — by construction forSize()'s output can NEVER disagree with violatesPolicy() for the same inputs. This means NO legitimate quote/scope-item/room text, however constructed, can ever make deriveMaterialHandling() emit a genuinely gate-violating item. This is a positive correctness property, not a test gap — but it made the plan's 'go through the real entry points with a violating fixture' requirement unreachable via ordinary business data."
  - "Resolved the above by using an isolated-process (RunInSeparateProcess + PreserveGlobalState(false)) Mockery alias mock of DisplayLiftPolicy for the 2 violating-fixture tests only — the 2 conforming-fixture tests use the real, unmocked class. Confirmed empirically that HazardTemplateSeeder itself calls DisplayLiftPolicy::genericBandSummary()/wallMountRemovalStatement(), so the mock must be registered BEFORE seeding in each isolated process, or Mockery raises 'class already exists'."
  - "DocumentArtifactStorage (H-07's unified documents-disk migration) is the correct way to resolve a written RAMS DOCX path in new tests — the legacy storage_path('app/rams/...') convention some older tests still reference no longer receives new writes."

requirements-completed: [GATE-09]

# Metrics
duration: 95min
completed: 2026-08-26
---

# Phase 27 Plan 03: GATE-09 Display-Lift Gate Summary

**RamsComplianceUpgradeService::enforceDisplayLiftGate() independently re-validates every display item's stated manual-handling team size against DisplayLiftPolicy::violatesPolicy(), throwing RamsGenerationException into BuildRamsDocumentJob's existing catch/STATUS_FAILED surface, gated behind a RAMS_DISPLAY_LIFT_GATE env kill-switch.**

## Performance

- **Duration:** ~95 min
- **Started:** 2026-08-26T09:15:00Z (approx.)
- **Completed:** 2026-08-26T10:50:00Z (approx.)
- **Tasks:** 3 completed
- **Files modified:** 2 (production) + 3 (new test files)

## Accomplishments
- GATE-09 shipped: `enforceDisplayLiftGate()` fires on 4+ operatives at any size, 2 operatives above 90", or 1 operative at 55"+; passes clean on 1-operative-under-55" and D-05's unresolvable-size fallback.
- Rollback switch (`RAMS_DISPLAY_LIFT_GATE`, default `true`) proven at both the unit level (config-gated `upgrade()` call) and via the non-vacuity revert/restore procedure.
- Proved the gate fires/passes identically via both real generation entry points (`runFromReview()` and `runPipeline()`/`buildFromForm()`), asserting on persisted `RamsDocument.status`/`error_message`, through the live DOCX renderer.
- Structural guard (`DisplayLiftPolicySourceGuardTest`) locks the allow-list of files permitted to call `DisplayLiftPolicy::*` to the 3 files that currently do, re-derived from a live grep at execution time.

## Task Commits

Each task was committed atomically:

1. **Task 1: enforceDisplayLiftGate() + config flag** - `8e4a2db` (feat)
2. **Task 2: DisplayLiftGateTest — throw/no-throw boundary proof** - `afa1407` (test)
3. **Task 3: Dual-path proof + structural guard against divergent bands** - `e79db3a` (test)

**Plan metadata:** (this commit, docs: complete plan)

## Files Created/Modified
- `app/Services/Rams/RamsComplianceUpgradeService.php` - Added `enforceDisplayLiftGate()` (new private static method) and wired it into `upgrade()`'s pipeline immediately after `deriveMaterialHandling()`, config-gated
- `config/rams_tier1.php` - Added `display_lift_gate_enabled` key (`env('RAMS_DISPLAY_LIFT_GATE', true)`), documented and placed alongside `hazard_tiering_enabled`
- `tests/Unit/Services/Rams/DisplayLiftGateTest.php` - Reflection-based throw/no-throw boundary tests for `enforceDisplayLiftGate()`, plus config-gated `upgrade()` wiring tests (isolated-process alias mock)
- `tests/Feature/Rams/DisplayLiftDualPathTest.php` - Dual-entry-point proof (conforming via real `DisplayLiftPolicy`; violating via isolated-process alias mock), asserting `RamsDocument.status`
- `tests/Feature/Rams/DisplayLiftPolicySourceGuardTest.php` - Allow-list file scan for `DisplayLiftPolicy::` references, re-derived from a live grep

## Decisions Made
See `key-decisions` in frontmatter above — the most consequential finding: `DisplayLiftPolicy::forSize()`/`::violatesPolicy()`'s deliberate D-03 single-source-of-truth design makes a genuine gate violation structurally unreachable through legitimate business data. This is a *positive* property (it's exactly why GATE-09 will rarely if ever fire on real live data — see Plan 27-05's pre-emptive regeneration check), but it meant the plan's literal "regenerate through the real entry points with a violating fixture" instruction required an isolated-process Mockery alias mock of `DisplayLiftPolicy` rather than a naturally-parsed description string. This was verified empirically (multiple failed attempts at natural-text violations, all confirmed compliant-by-construction) before adopting the mock, and the two conforming-fixture tests in the same file deliberately use the real, unmocked class so the mock's scope is minimized to exactly the 2 tests that need it.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] `DisplayLiftDualPathTest`'s file-existence assertions targeted the wrong storage path**
- **Found during:** Task 3 (initial test run)
- **Issue:** The plan's cited precedent (`ReviewedHazardTieringTest`) checks `storage_path('app/rams/' . $filename)`, a pre-H-07 legacy path. `DocxBuilderService::build()` (unchanged by this plan) now writes new artifacts via `DocumentArtifactStorage` to `storage/app/documents/rams/`, so the legacy path assertion failed even on successful generation.
- **Fix:** Resolved the written path via `app(DocumentArtifactStorage::class)->readPath(DocumentArtifactStorage::TYPE_RAMS, $filename)` — the same single source of truth every reader in the codebase uses post-H-07.
- **Files modified:** tests/Feature/Rams/DisplayLiftDualPathTest.php
- **Verification:** `test_conforming_display_item_generates_successfully_via_run_from_review`/`..._via_run_pipeline` pass, asserting a real, non-null DOCX path.
- **Committed in:** e79db3a (Task 3 commit)

**2. [Rule 3 - Blocking] Manual-form fixture in `DisplayLiftDualPathTest` initially omitted `user_id`**
- **Found during:** Task 3 (initial test run)
- **Issue:** `rams_documents.user_id` has a NOT NULL constraint; the manual-form fixture helper didn't set it, causing a `QueryException` on `RamsDocument::create()`.
- **Fix:** Added `'user_id' => User::factory()->create()->id` to `makeManualFormRams()`.
- **Files modified:** tests/Feature/Rams/DisplayLiftDualPathTest.php
- **Verification:** Both `run_pipeline` tests pass.
- **Committed in:** e79db3a (Task 3 commit)

---

**Total deviations:** 2 auto-fixed (both Rule 3 - blocking test-infrastructure issues discovered while validating the new tests against the current codebase state). No production-code deviations from the plan.
**Impact on plan:** Both fixes are test-file-only corrections needed to make the plan's own prescribed verification actually pass against the current, post-H-07 codebase state. No scope creep; no change to GATE-09's actual behavior or the files_modified list.

## Issues Encountered

**Structural discovery (not a bug, documented as a key decision above):** `DisplayLiftPolicy::forSize()` and `::violatesPolicy()` share their numeric band constants by construction, so no naturally-parsed description text can ever produce a `(min_persons, inches)` pair that fails `violatesPolicy()` — every branch of `forSize()` returns a pair `violatesPolicy()` necessarily accepts. This was proven exhaustively (all three GATE-09 error conditions checked against every `forSize()` branch) before concluding an isolated-process alias mock was the only way to satisfy the plan's "regenerate through the real entry points with a violating fixture" requirement. The mock is scoped to exactly 2 of `DisplayLiftDualPathTest`'s 4 tests (and 2 of `DisplayLiftGateTest`'s 11 tests); every other assertion in both files exercises the real, unmocked `DisplayLiftPolicy`.

**Mockery alias-mock ordering gotcha (resolved):** `HazardTemplateSeeder::standardHazards()` itself calls `DisplayLiftPolicy::genericBandSummary()`/`::wallMountRemovalStatement()`. Seeding before registering the alias mock caused Mockery to fail with "class already exists" (the seeder had already triggered autoload of the real class). Fixed by registering the mock as the first statement in each affected test, before any seeding.

## Non-Vacuity Proof (ROADMAP success criterion 3)

Performed live during Task 2 development, documented in `DisplayLiftGateTest.php`'s class docblock and reproduced here for the record:

1. Temporarily edited `RamsComplianceUpgradeService::enforceDisplayLiftGate()`'s `if (DisplayLiftPolicy::violatesPolicy(...))` to `if (false && DisplayLiftPolicy::violatesPolicy(...))` (simulating the check being silently disabled).
2. Re-ran `php artisan test --filter=DisplayLiftGateTest`.
3. **Result:** 5 tests failed as expected — the 4 boundary throw-tests (`test_four_or_more_operatives_always_throws`, `test_two_operatives_above_90_inches_throws`, `test_one_operative_at_55_inches_throws`, `test_one_operative_above_55_inches_throws`) plus `test_upgrade_calls_the_gate_and_throws_when_flag_enabled`, each failing with "Failed asserting that exception of type RamsGenerationException is thrown" — proving these tests are not vacuously passing.
4. Reverted the edit (`sed` restore, verified via `git diff` showing no residual change).
5. Re-ran the filter: all 11 tests passed again.

## User Setup Required

None - no external service configuration required. `RAMS_DISPLAY_LIFT_GATE` defaults to `true` and needs no `.env` entry unless an operator wants to disable it.

## Verification

- `php artisan test --filter="DisplayLiftGateTest|DisplayLiftDualPathTest|DisplayLiftPolicySourceGuardTest|RamsComplianceUpgradeServiceDisplayLiftTest|ReviewedHazardTieringTest|ManualRamsCreationTest|DisplayLiftPolicyTest|ProjectSpecificRisksGatedTest"` — 63 tests, 220 assertions, all pass (no regression in the Phase 26/27 tests this plan's pipeline step sits alongside).
- Full suite: `php artisan test` — 2366 tests, 9358 assertions, 2 failures, 6 skipped. Both failures are pre-existing and unrelated to this plan's files:
  - `QueueRecoverCommandTest::test_unhealthy_queue_runs_restart_and_drain_plan` — the documented memory-limit flake (per this plan's `<known_context>`).
  - `Tests\Feature\Drawings\BoundPdfDownloadTest` (2 assertions) — passes in isolation (`4/4` green when filtered alone); fails only as part of the full ordered run, indicating pre-existing test-order pollution from an unrelated Drawings-domain test, not from any file this plan touches.

## Next Phase Readiness
- GATE-09 is live and config-gated, ready for Plan 27-05's live regeneration proof (21CQ30960) to confirm it does not fire on real production data before the rollback flag is ever needed.
- The gate/config/exception pattern established here (`enforceDisplayLiftGate()` → `RamsGenerationException` → `BuildRamsDocumentJob`'s existing catch → `STATUS_FAILED`) is the reusable template for GATE-06/07/11/12/13/14.

---
*Phase: 27-manual-handling-display-lift-house-rules*
*Completed: 2026-08-26*

## Self-Check: PASSED

All created files confirmed on disk; all 3 task commit hashes (8e4a2db, afa1407, e79db3a) confirmed in `git log --oneline --all`.
