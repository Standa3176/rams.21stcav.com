---
phase: 26-hazard-library-structural-inversion
plan: 07
subsystem: rams-hazard-generation
tags: [php, laravel, hazard-library, risk-assessment, tdd, regression-guard]

# Dependency graph
requires:
  - phase: 26-hazard-library-structural-inversion (Plan 04)
    provides: HazardIncludeWhenResolver wired into RiskTemplateResolverService and runPipeline()
  - phase: 26-hazard-library-structural-inversion (Plan 05)
    provides: HAZ-04 editable score defaults + score_reviewed/needs_confirmation markers on reviewed rows
provides:
  - "RiskTemplateResolverService::tieredRowsNotAlreadyPresent() — public, reusable tier-1/3 fetch-and-dedup entry point"
  - "RamsBuilderService::reviewedToRisk() merges tier-1/3 hazards onto reviewed picks with a real derived drilling signal"
  - "EquipmentClassifierService::textIndicatesDrilling() — reusable keyword-based drilling/fixing text detector"
  - "RamsComplianceUpgradeService::addProjectSpecificRisks() gated behind rams_tier1.hazard_tiering_enabled (sixth injection path closed)"
  - "HazardResolutionPathGuardTest — structural regression guard against a future untiered hazard-resolution path"
  - "RamsDataBuilderService hazard normaliser preserves score_reviewed/needs_confirmation into generated_data"
affects: [rams-generation, rams-review-screen, hazard-library-phase-27-28]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Single shared tier-evaluation call site (fetchTieredCandidates()) reused by both resolveHazards() and tieredRowsNotAlreadyPresent() — prevents tier logic from diverging across callers"
    - "Structural allow-list guard test (inverted from a forbidden-string scan) re-derived from a live repo grep at plan time, not hand-copied"

key-files:
  created:
    - tests/Feature/Rams/ReviewedHazardTieringTest.php
    - tests/Unit/Services/Rams/ProjectSpecificRisksGatedTest.php
    - tests/Feature/Rams/HazardResolutionPathGuardTest.php
  modified:
    - app/Services/RiskTemplateResolverService.php
    - app/Services/RamsBuilderService.php
    - app/Services/EquipmentClassifierService.php
    - app/Services/Rams/RamsComplianceUpgradeService.php
    - app/Services/Rams/Tier1RamsDefaultsService.php
    - app/Services/RamsDataBuilderService.php
    - tests/Unit/Services/RamsBuilderServiceTest.php
    - tests/Feature/Rams/RiskTemplateResolverServiceTest.php
    - .planning/REQUIREMENTS.md

key-decisions:
  - "Test 5 (drilling-gated positive direction) uses an empty reviewed-hazards fixture rather than reusing Test 2's 5-row live-vocab fixture — that fixture's 'Noise and Vibration' row case-insensitively dedups against the library's own 'Noise and vibration' tier-2 hazard, masking the exact signal under test."
  - "RamsDataBuilderService::assemble()'s hazard normaliser was silently dropping score_reviewed/needs_confirmation on EVERY generation path (not just runFromReview()) — fixed as a Rule 1/2 auto-fix since the plan's own success criteria require needs_confirmation to survive into generated_data, and the two-key addition is symmetric across both paths with no schema change."
  - "addProjectSpecificRisks()'s gate mirrors the SAME config flag as reviewedToRisk()'s tier merge (rams_tier1.hazard_tiering_enabled) rather than a separate flag — when disabled, BOTH paths independently revert to their own pre-Plan-07 behaviour, which is why the flag-off test asserts the reviewed picks AND the 3 unconditional legacy candidates are both present, not just the reviewed picks alone."

requirements-completed: [HAZ-02]

# Metrics
duration: ~45min
completed: 2026-08-24
---

# Phase 26 Plan 07: Gap Closure — Hazard tiering on the runFromReview() path Summary

**Wires `RiskTemplateResolverService`'s tiered `HazardIncludeWhenResolver` merge into `RamsBuilderService::reviewedToRisk()` (the path every already-reviewed project takes) and gates a previously-undocumented sixth hazard-injection path (`RamsComplianceUpgradeService::addProjectSpecificRisks()`) that was the proven cause of the unexplained 7→11 hazard-count delta on live.**

## Performance

- **Duration:** ~45 min
- **Completed:** 2026-08-24
- **Tasks:** 3 completed
- **Files modified:** 9 (6 app files, 3 new test files, plus 2 existing test files extended)

## Accomplishments

- `HAZ-02` now holds on BOTH `RamsBuilderService` generation entry points — `runPipeline()` (Plan 26-04) and `runFromReview()` (this plan). Live evidence (21CQ30960 / RAMS 96 producing 11 old-vocabulary hazards with zero always/confirm-tier hazards present) is now reproduced and closed by an automated regression test (`ReviewedHazardTieringTest`).
- Traced and closed the unexplained 7→11 delta from `26-VERIFICATION.md`: `RamsComplianceUpgradeService::addProjectSpecificRisks()` was a sixth, previously-undocumented injection path that unconditionally appended 3 old-vocabulary hazard rows (`Cable Pulling & Termination`, `Low Voltage AV Connections`, `Fixings into Walls & Ceilings`) on every generation, regardless of the tiering flag. Every one of its 7 candidates now has a direct or D-02-mapped equivalent in the 18-hazard library, so gating it behind the tiering flag removes zero real safety coverage — only a duplicate legacy mechanism.
- The three drilling-gated tier-2 hazards (`Dust from drilling and cutting`, `Fixings into walls, ceilings and pillars`, `Noise and vibration`) now auto-populate on `runFromReview()` from a REAL derived signal (`EquipmentClassifierService::textIndicatesDrilling()` against the reviewed scope narrative), proven in both directions — never a documented limitation.
- `RAMS_HAZARD_LIBRARY_TIERING=false` verified as a genuine rollback on BOTH the `reviewedToRisk()` merge point (reviewed picks only, zero tier-1/3 additions) and the sixth path (byte-identical pre-Plan-07 legacy behaviour) — never a blank register, never the deleted 11-hazard baseline.
- A structural regression guard (`HazardResolutionPathGuardTest`) now fails automatically if a future service queries the hazard library or resolver directly, bypassing `RiskTemplateResolverService`'s sanctioned merge logic — the exact class of gap this plan closes.
- Fixed a symmetric normalisation bug (`RamsDataBuilderService::assemble()`) that silently dropped `score_reviewed`/`needs_confirmation` from every hazard row on BOTH generation paths, before this plan's own confirm-tier assertions could even be proven true.

## Task Commits

Each task was committed atomically:

1. **Task 1: RiskTemplateResolverService — reusable tier-1/3 fetch-and-dedup entry point** - `98149a2` (feat)
2. **Task 2: Wire tiered resolution into runFromReview() — close the coverage hole** - `ebb3356` (feat)
3. **Task 3: Gate the sixth injection path + structural regression guard** - `b89748d` (fix)

_Note: each task followed RED → GREEN TDD — failing tests were written and confirmed failing before the corresponding implementation commit; only the implementation-complete state was committed per task, per this plan's `tdd="true"` task type._

## Files Created/Modified

- `app/Services/RiskTemplateResolverService.php` - Extracted `fetchTieredCandidates()` from `resolveHazards()`; added public `tieredRowsNotAlreadyPresent()` for callers holding an already-built register
- `app/Services/RamsBuilderService.php` - `runFromReview()` derives a scope narrative + real drilling signal and forwards them into `reviewedToRisk()`; `reviewedToRisk()` merges tier-1/3 candidates on top of reviewed picks and re-sequences ids 1..N in a single pass
- `app/Services/EquipmentClassifierService.php` - Added `textIndicatesDrilling(string $text): bool`, reusing the existing `MOUNT_KEYWORDS` constant `classify()` already matches per-item
- `app/Services/Rams/RamsComplianceUpgradeService.php` - `addProjectSpecificRisks()` gated behind `config('rams_tier1.hazard_tiering_enabled')` — no-op when tiering governs the register, byte-identical legacy behaviour otherwise
- `app/Services/Rams/Tier1RamsDefaultsService.php` - Reworded a docblock comment so its comment-only mention of `HazardIncludeWhenResolver` drops out of the structural guard's marker scan
- `app/Services/RamsDataBuilderService.php` - Hazard normaliser in `assemble()` preserves `score_reviewed`/`needs_confirmation` through to `generated_data` (Rule 1/2 auto-fix, both generation paths)
- `tests/Feature/Rams/ReviewedHazardTieringTest.php` - New. The `runFromReview()`-with-populated-reviewed-hazards coverage the phase's 2265 pre-existing tests never exercised — 5 tests, real seeded DB, real resolver
- `tests/Unit/Services/Rams/ProjectSpecificRisksGatedTest.php` - New. Locks the no-op-when-enabled / byte-identical-when-disabled gate on `addProjectSpecificRisks()`
- `tests/Feature/Rams/HazardResolutionPathGuardTest.php` - New. Structural allow-list guard against a future untiered hazard-resolution path
- `tests/Unit/Services/RamsBuilderServiceTest.php` - Extended `makeService()`/`makeServiceWithHazardLibrary()` mock factories with `tieredRowsNotAlreadyPresent()` stubs; added a unit test asserting `reviewedToRisk()` forwards the real derived signal and re-sequences ids
- `tests/Feature/Rams/RiskTemplateResolverServiceTest.php` - Added 5 tests covering `tieredRowsNotAlreadyPresent()`'s dedup, flag-off, and tier-2 signal matching behaviour
- `.planning/REQUIREMENTS.md` - HAZ-02 marked complete with closure narrative; traceability table row updated

## Decisions Made

- **Test 5's fixture diverges from Test 2's** (empty reviewed-hazards list instead of the 5-row live-vocab fixture) to isolate the drilling signal under test — the live-vocab fixture's `Noise and Vibration` name would otherwise case-insensitively collide with the library's own `Noise and vibration` tier-2 hazard and mask a true positive as a false negative.
- **`RamsDataBuilderService::assemble()` normaliser fix** treated as in-scope Rule 1/2 auto-fix rather than deferred: the plan's own Task 2 Test 2 explicitly requires `needs_confirmation=true` to survive into `generated_data`, and the existing normaliser silently stripped it on both generation paths — a pre-existing gap this plan's own test surfaced, not introduced by it.
- **Sixth-path gate reuses the same flag** (`rams_tier1.hazard_tiering_enabled`) as the primary tier merge rather than a second flag, per the plan's explicit design and `26-CONTEXT.md`'s Claude's Discretion precedent (`RAMS_TIER1_DEFAULTS`-style single-flag-gates-multiple-paths pattern).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1/2 - Bug + Missing Critical] `RamsDataBuilderService::assemble()` silently dropped `score_reviewed`/`needs_confirmation` from every hazard row**
- **Found during:** Task 2 (writing `ReviewedHazardTieringTest` Test 2 — confirm-tier hazards asserted `needs_confirmation === true` in `generated_data` and failed)
- **Issue:** The hazard-normalisation pass in `RamsDataBuilderService::assemble()` rebuilt each hazard row from an explicit key whitelist (`id`, `hazard`, `persons_at_risk`, `pre_likelihood`, `pre_severity`, `controls`, `post_likelihood`, `post_severity`) that predates Plan 26-05's `score_reviewed`/`needs_confirmation` additions — both keys were silently dropped on EVERY generation path (`runPipeline()` and `runFromReview()` alike), not just the path this plan touches
- **Fix:** Added both keys to the normalised row shape, defaulting `false` to match `RiskTemplateResolverService`'s own defaults
- **Files modified:** `app/Services/RamsDataBuilderService.php`
- **Verification:** `ReviewedHazardTieringTest` Test 2 (confirm-tier `needs_confirmation` assertions) passes; full suite shows zero regressions
- **Committed in:** `b89748d` (Task 3 commit — bundled with the sixth-path gating fix since both surfaced from the same test run)

---

**Total deviations:** 1 auto-fixed (Rule 1/2 — bug + missing critical functionality)
**Impact on plan:** Necessary for the plan's own explicit success criterion (confirm-tier `needs_confirmation` visible in `generated_data`) to be provably true. Fix is symmetric across both generation paths with no schema change — no scope creep.

## Issues Encountered

- Two intermediate test-fixture bugs were found and fixed during Task 2/3 TDD cycles (not production code deviations): a copy-paste assertion typo (`post_severity` expected `4`, fixture value was `3`) and an initial `test_tiering_disabled_degrades_to_reviewed_picks_only` assertion that incorrectly expected `addProjectSpecificRisks()`'s own byte-identical legacy rollback to produce zero hazards — corrected to assert the two gated paths' behaviour independently once Task 3's design became concrete.
- The session was interrupted by a connection error partway through Task 1's RED phase; resumed cleanly from the last-known state (uncommitted RED test intact, no production code touched) per the coordinator's verified state.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- HAZ-02 holds on both `RamsBuilderService` generation entry points; the phase's four success criteria (HAZ-01 through HAZ-04) are now all complete.
- `26-VERIFICATION.md` Outstanding item 7 (manual/live re-verification of RAMS 96 / 21CQ30960 after deploy) remains open — this is a post-deploy human step, not part of this plan's automated scope. The same file's Outstanding item 3 (review-screen UI visual confirmation) and item 5 (superseding the RAMS 96 test artifact) also remain open from the original verification pass and are unaffected by this plan.
- No blockers for subsequent phases. Phase 27/28 (house-rule text edits) can proceed against a hazard register that is now genuinely tiered on every generation path.

---
*Phase: 26-hazard-library-structural-inversion*
*Completed: 2026-08-24*

## Self-Check: PASSED

All 9 created/modified files confirmed present on disk; all 3 task commits (`98149a2`, `ebb3356`, `b89748d`) confirmed present in git history.
