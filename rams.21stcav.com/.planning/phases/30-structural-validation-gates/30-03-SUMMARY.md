---
phase: 30-structural-validation-gates
plan: 03
subsystem: rams
tags: [laravel, php, validation-gates, rams-compliance]

# Dependency graph
requires:
  - phase: 30-structural-validation-gates
    plan: 01
    provides: StructuralGateVocabulary matching helper, structural_gate_triggers config vocabulary, structural_gates_enabled flag, compliance_warnings channel
  - phase: 30-structural-validation-gates
    plan: 02
    provides: client_responsibilities_expanded + areas_for_gate mirrors at all three real generation entry points
provides:
  - RamsComplianceUpgradeService::enforceOrphanControlGate() (GATE-01) — throws when a trigger phrase has no supporting hazard row OR no supporting client-responsibility entry (D-05)
  - RamsComplianceUpgradeService::enforceAreaCoverageGate() (GATE-02) — throws when a named area has no method step; passes vacuously on zero areas
  - The structural dispatch block in upgrade(), gated on rams_tier1.structural_gates_enabled (RAMS_STRUCTURAL_GATES, default false)
  - StructuralGatesTest (15 unit tests) and StructuralGatesSaveReviewGateTest (4 feature tests) proving throw/no-throw boundaries and real-HTTP armed-gate surfacing
affects: [30-06, 30-09, 31-*]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Gate method shape reused verbatim from enforceCdmGate()/enforceDisplayLiftGate(): private static function enforceXGate(array $data): array, defensive (array)($data['key'] ?? []) reads, returns $data unchanged (assertSame) on the clean path, throws RamsGenerationException naming the offender and its kill-switch on the first violation"
    - "A gate's haystack-building helper (orphanControlHaystack()) is a separate private method from the throwing loop, mirroring the established separation between judgement (StructuralGateVocabulary) and throw (the gate method itself)"

key-files:
  created:
    - tests/Unit/Services/Rams/StructuralGatesTest.php
    - tests/Feature/Rams/StructuralGatesSaveReviewGateTest.php
  modified:
    - app/Services/Rams/RamsComplianceUpgradeService.php

key-decisions:
  - "GATE-01 fires when EITHER the hazard row OR the client-responsibility entry is missing (D-05) — the canonical PORTING-NOTES.md:66-68 reading ('a matching hazard row *and* a matching clientReqs entry' — missing either conjunct fails the check). The thrown message names WHICH support is absent, not a blanket 'both missing'"
  - "GATE-01 scans BOTH method-statement step/title text AND hazard control lines for a trigger phrase (REQUIREMENTS.md:62), pooled into one case-folded haystack string built by a dedicated orphanControlHaystack() helper"
  - "GATE-02 passes vacuously on zero areas — a manual/form-only RAMS legitimately has no room list; erroring here would reject every manual RAMS (RESEARCH Finding 3 point 2)"
  - "GATE-02's docblock records that cleanTextArtifacts() runs LAST in upgrade(), so the gate matches PRE-typo-fix text while the issued document shows POST-fix text — a recorded property, not a defect, no code change follows"
  - "Both gates dispatch under the single existing rams_tier1.structural_gates_enabled flag (D-04, shipped in Plan 30-01) — no new flag introduced by this plan"
  - "No second matching vocabulary was created — both gates delegate every signal-match decision to StructuralGateVocabulary (D-07/D-08)"

patterns-established:
  - "Non-vacuity development procedure documented and actually run: temporarily stub each throw guard to always pass, confirm exactly the throwing tests fail, restore, confirm git diff empty, re-confirm green — recorded verbatim in the unit test file's class docblock, mirroring CdmEmergencyGateTest's house convention"

requirements-completed: [GATE-01, GATE-02]

duration: ~35min
completed: 2026-09-13
---

# Phase 30 Plan 03: GATE-01/GATE-02 Structural Gate Bodies Summary

**Implemented the orphan-control gate (GATE-01, throwing when a trigger phrase like "asbestos register" has no supporting hazard row OR no supporting client-responsibility entry) and the area-coverage gate (GATE-02, throwing when a named area has no method step, passing vacuously on zero areas), both dispatched behind the disarmed `RAMS_STRUCTURAL_GATES` flag, with unit coverage of every throw/no-throw boundary and a real-HTTP feature test proving an armed gate surfaces as a friendly redirect, not a 500.**

## Performance

- **Duration:** ~35 min
- **Completed:** 2026-09-13
- **Tasks:** 3
- **Files modified:** 3 (2 created, 1 modified)

## Accomplishments

- `RamsComplianceUpgradeService::enforceOrphanControlGate()` (GATE-01): for each row of `config('rams_tier1.structural_gate_triggers')`, scans a combined case-folded haystack of every method-statement phase title/step plus every hazard control line for the row's `phrase`. When present, requires BOTH `StructuralGateVocabulary::signalMatchesHazards()` and `::signalMatchesClientReqs()` to be true — throwing on the first trigger missing either support (D-05), with a message naming which support is absent so an engineer who added the hazard but not the client responsibility is told exactly that.
- A dedicated `orphanControlHaystack()` private helper builds the combined scan text once, case-folded, never throwing on missing/malformed `method_statement` or `hazards` keys.
- `RamsComplianceUpgradeService::enforceAreaCoverageGate()` (GATE-02): reads areas via `StructuralGateVocabulary::flattenAreas()`, matches each (case-folded, trimmed) against every phase title/step, throwing on the first uncovered area. Passes vacuously — and deliberately — on zero areas.
- The structural dispatch block added to `upgrade()` after the GATE-11/GATE-12 block and before `cleanTextArtifacts()`, calling `enforceOrphanControlGate()` then `enforceAreaCoverageGate()` under the existing `rams_tier1.structural_gates_enabled` flag (default `false`), with a comment noting GATE-04 (Plan 30-06) joins the same block.
- `tests/Unit/Services/Rams/StructuralGatesTest.php` (15 tests): the canonical asbestos-orphan failure, both half-supported D-05 cases, both-present/no-trigger/absent-keys clean paths, hazard-control-line scanning, GATE-02's throw/covered/vacuous-zero-areas/absent-method-statement/case-fold-and-trim cases, and dispatch-flag wiring through the real `upgrade()` entry point (disarmed-inert, armed-throws for both gates).
- `tests/Feature/Rams/StructuralGatesSaveReviewGateTest.php` (4 tests), modeled on `DisplayLiftSaveReviewGateTest`: drives the real `POST /rams/{rams}/update-and-download` route with `RAMS_STRUCTURAL_GATES` armed, proving a GATE-01 violation and a GATE-02 violation each redirect back to `rams.review` carrying the gate message (not a 500), that the identical payload succeeds when the flag is disarmed, and that nothing is persisted (`compliance_warnings` absent from the still-unmodified `generated_data`) when a gate throws.
- Full Rams test suite: 780 passed before this plan, 799 passed after (780 + 15 unit + 4 feature), 0 failed.

## Task Commits

Each task was committed atomically:

1. **Task 1: GATE-01 — enforceOrphanControlGate()** - `322705a` (feat)
2. **Task 2: GATE-02 — enforceAreaCoverageGate() + structural dispatch block** - `c589755` (feat)
3. **Task 3: StructuralGatesSaveReviewGateTest — armed-gate HTTP surfacing** - `71bbf63` (test)

_Note: all three tasks were `tdd="true"`; per this plan's autonomous execution mode, each task's RED state (temporarily removing the not-yet-implemented gate/dispatch/test code) was confirmed to fail its own filtered test run before the GREEN implementation was restored and verified, then committed as a single task commit — matching Plan 30-01/30-02's established convention of not committing the transient RED state separately. Tasks 1 and 2 share one file pair (`RamsComplianceUpgradeService.php` + `StructuralGatesTest.php`); the split was produced by temporarily removing Task 2's method/dispatch-line/tests, confirming Task 1's 9 tests green in isolation, committing, then restoring and re-confirming all 15 green before committing Task 2._

## Files Created/Modified

- `app/Services/Rams/RamsComplianceUpgradeService.php` - added `enforceOrphanControlGate()`, `orphanControlHaystack()`, `enforceAreaCoverageGate()`, and the structural dispatch block in `upgrade()`; removed the now-superseded `HazardIncludeWhenResolver`-literal docblock phrasing that would have tripped `HazardResolutionPathGuardTest`'s marker scan (see Deviations)
- `tests/Unit/Services/Rams/StructuralGatesTest.php` - new, 15 tests covering both gates' throw/no-throw boundaries and dispatch-flag wiring
- `tests/Feature/Rams/StructuralGatesSaveReviewGateTest.php` - new, 4 tests proving armed-gate HTTP surfacing on the real Save Review route

## Decisions Made

- GATE-01 fires on EITHER support missing (D-05), correcting ROADMAP criterion 1's backwards phrasing — Plan 30-05 will correct the doc itself
- GATE-02 passes vacuously on zero areas, recorded as deliberate in the docblock
- Both gates share the single `rams_tier1.structural_gates_enabled` flag already shipped in Plan 30-01 — no new config surface added
- No second matching vocabulary created; all signal matching goes through `StructuralGateVocabulary`

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] New docblock prose tripped the HazardResolutionPathGuardTest marker scan**
- **Found during:** Task 2 full-suite verification (`php artisan test --filter=Rams`)
- **Issue:** `enforceOrphanControlGate()`'s docblock explained why it does not call the Phase 26 hazard-library resolver's dynamic method, using the literal substring `HazardIncludeWhenResolver` in prose (not a call site). `HazardResolutionPathGuardTest` scans all of `app/` for that marker string and only permits it in a fixed allow-list of genuine call sites — this was the same class of incidental-prose collision Plan 30-01 hit and fixed for `StructuralGateVocabulary.php`.
- **Fix:** Reworded the docblock to describe the resolver by role ("the Phase 26 hazard-library resolver's dynamic resolve-from-database method") instead of naming the class, with no functional change. `RamsComplianceUpgradeService.php` was not added to the guard's allow-list because it has no genuine call site to add — the prose reference was removable without losing meaning.
- **Files modified:** `app/Services/Rams/RamsComplianceUpgradeService.php`
- **Verification:** `php artisan test --filter=Rams` — 795 passed, 0 failed (was 1 failed before the fix)
- **Committed in:** `322705a` (Task 1 commit)

---

**Total deviations:** 1 auto-fixed (1 Rule-1 bug, no allow-list or production-behavior change)
**Impact on plan:** None — a documentation-wording fix only, caught by an existing structural regression guard exactly as intended.

## Issues Encountered

None beyond the guard-test docblock collision documented above.

## User Setup Required

None — no external service configuration, no new env vars, no migration. `RAMS_STRUCTURAL_GATES` remains unset/false in `.env` (already shipped disarmed by Plan 30-01).

## Next Phase Readiness

- Plan 30-06 (GATE-04) can join the same structural dispatch block added here without modifying its shape
- Plan 30-09's arming runbook and Plan 30-05's ROADMAP correction can now reference this plan's shipped, tested GATE-01/GATE-02 bodies
- No blockers. Full Rams suite: 799 passed, 0 failed.

---
*Phase: 30-structural-validation-gates*
*Completed: 2026-09-13*

## Self-Check: PASSED

Verified on disk: `app/Services/Rams/RamsComplianceUpgradeService.php` contains `enforceOrphanControlGate` and `enforceAreaCoverageGate` (confirmed via grep); `tests/Unit/Services/Rams/StructuralGatesTest.php` exists with 15 tests, all passing; `tests/Feature/Rams/StructuralGatesSaveReviewGateTest.php` exists with 4 tests, all passing. All three commit hashes (`322705a`, `c589755`, `71bbf63`) confirmed present in `git log --oneline -5`.
