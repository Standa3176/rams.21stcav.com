---
phase: 27-manual-handling-display-lift-house-rules
plan: 01
subsystem: rams
tags: [php, laravel, phpunit, policy-class, manual-handling, display-lift]

requires:
  - phase: 26-hazard-library-structural-inversion
    provides: "LegacyHazardNameFoldMap.php as the established static-class, single-choke-point, provenance-docblock precedent"
provides:
  - "App\\Services\\Rams\\DisplayLiftPolicy — the single PHP class resolving display-lift team size (RULE-02's corrected 1/2/3-operative bands), an independent violatesPolicy() gate check for GATE-09, and RULE-03's wall-mount-removal statement, plus genericBandSummary() and bands() introspection"
affects: [27-02, 27-03, 27-04]

tech-stack:
  added: []
  patterns:
    - "All-static, final, no-constructor policy class mirroring LegacyHazardNameFoldMap's shape (const thresholds, extensive provenance docblock, nullable-or-array resolver, public introspection accessor)"
    - "Independent gate re-check (violatesPolicy) that does not call the generator's resolver (forSize) internally, so a future edit to one cannot silently desync from the other"

key-files:
  created:
    - app/Services/Rams/DisplayLiftPolicy.php
    - tests/Unit/Services/Rams/DisplayLiftPolicyTest.php
  modified: []

key-decisions:
  - "forSize()'s null-return case is reserved exclusively for the pre-existing ≤14\" small-control-panel exclusion; D-05's unresolvable-size case returns the same non-null 2-operative shape as a 60\" input, never null — these are two different outcomes for two different inputs, not one collapsed case."
  - "violatesPolicy() re-implements the band thresholds directly from the shared constants rather than calling forSize() and comparing, satisfying D-03's requirement that the gate and generator resolve team size through the same shared class without becoming the same code path (so a bug in one is not automatically inherited by both, while both still cite the identical numeric constants)."
  - "The >90\" sentence states the third operative as a required floor and contains no 'may lift if using a trolley'-style aid-discharges-a-person construction (D-02), unlike the pre-existing RamsComplianceUpgradeService >=65in wording it will eventually replace in Plan 27-02."

patterns-established:
  - "Policy-class provenance docblock: records the full decision history (original skill position, first amendment, and the same-day correction) with explicit citations to CONTEXT.md decision IDs and REQUIREMENTS.md amendment notes, so a future reader cannot mistake the settled final position for drift from an earlier draft."

requirements-completed: [RULE-02, RULE-03]

duration: 8min
completed: 2026-08-26
---

# Phase 27 Plan 01: DisplayLiftPolicy Summary

**Created `App\Services\Rams\DisplayLiftPolicy` — the single all-static PHP class encoding the corrected 1/2/3-operative display-lift bands, an independent GATE-09 violation check, and the RULE-03 wall-mount-removal statement, proven by 19 boundary-by-boundary unit tests; nothing calls it yet.**

## Performance

- **Duration:** ~8 min (RED commit to GREEN commit)
- **Started:** 2026-08-26T08:06:39+01:00
- **Completed:** 2026-08-26T09:07:52+01:00
- **Tasks:** 2 completed (RED + GREEN, TDD plan)
- **Files modified:** 2 (both new)

## Accomplishments
- `DisplayLiftPolicy::forSize()` resolves any inch value (or `null`) to the correct band, including the ≤14" no-row exclusion (distinct from D-05's null-inches 2-operative silent fallback) and the >90" sentence that never lets a mechanical aid discharge the required third operative.
- `DisplayLiftPolicy::violatesPolicy()` gives GATE-09 (Plan 27-03) an independent re-check — flags 4+ operatives at any size, 2 operatives above 90", and 1 operative at 55" or larger, while never flagging a compliant 1-operative sub-55" lift or an unresolvable size.
- `wallMountRemovalStatement()` and `genericBandSummary()` give the seeder and method-statement fallback (Plans 27-02/27-04) a single reusable source for RULE-03's sentence and a size-agnostic band summary, so no caller can restate the numbers independently and drift.
- Extensive provenance docblock records the full D-01 history (unamended skill → floor-only amendment → same-day single-operative-band correction) with explicit citations, per the project's established defence against a future reader mistaking the settled bands for drift (26-VERIFICATION.md precedent).

## Task Commits

Each task was committed atomically (TDD RED/GREEN):

1. **Task 1 (test half) / Task 2: DisplayLiftPolicyTest — boundary-by-boundary proof** - `92b845a` (test) — RED: 19 tests written against the not-yet-existing class, confirmed all fail with `Class "App\Services\Rams\DisplayLiftPolicy" not found`.
2. **Task 1: DisplayLiftPolicy — bands, gate check, and statements** - `6c66c2d` (feat) — GREEN: class implemented, all 19 tests pass (54 assertions).

_Note: the plan's two tasks (class + its test) were executed as one TDD RED/GREEN cycle since the test file fully specifies the class's contract — writing the test first (RED) then the implementation (GREEN) satisfies both tasks' `<verify>` in the correct order._

## Files Created/Modified
- `app/Services/Rams/DisplayLiftPolicy.php` - final, all-static class; `forSize()`, `violatesPolicy()`, `wallMountRemovalStatement()`, `genericBandSummary()`, `bands()`, plus private per-band sentence builders and the six named threshold constants.
- `tests/Unit/Services/Rams/DisplayLiftPolicyTest.php` - 19 test methods, no DB, mirrors `LegacyHazardNameFoldMapTest`'s structure (plain `Tests\TestCase`, one assertion-focused case per test, plus a `bands()` introspection test and a reflection-based structural test).

## Deviations from Plan

None - plan executed exactly as written. The two-task split (write the class / write its test) was executed as a single TDD RED→GREEN sequence rather than two separate non-TDD commits, which is the plan's own `tdd="true"` instruction for both tasks, not a deviation from it.

## Verification

- `php artisan test --filter=DisplayLiftPolicyTest` — 19 passed (54 assertions).
- `php artisan test --filter=DisplayLift` — same 19 passed, confirming the plan's `<verification>` command.
- Every acceptance criterion in Task 1's `<acceptance_criteria>` list has a corresponding, named assertion in the test file (not incidental coverage): no-row exclusion, 43"/55"/90"/90.1" band values, the >90" sentence's absence of aid-discharge wording, the null-vs-60" shape equality, all six `violatesPolicy()` truth/false cases, and both string-content assertions on `wallMountRemovalStatement()`/`genericBandSummary()`.
- No test in the file touches the database or requires `RefreshDatabase` — confirmed by reading the file: it extends `Tests\TestCase` with no traits, no factories.
- Reflection test independently confirms the class is `final`, has no constructor, and every method (public and private) is static — the stronger claim than the plan's "zero non-static methods" phrasing, verified directly rather than assumed.

## Known Stubs

None. This class has no I/O, no UI, no data source to stub — it is a pure, stateless, deterministic value class per the plan's own threat model ("no I/O, no database access, no external input surface").

## Threat Flags

None. No new network endpoint, auth path, file access pattern, or schema change was introduced — this plan creates one pure value class with zero callers, matching the plan's threat model exactly (`T-27-01`, accepted, no new surface).

## Self-Check: PASSED

- `app/Services/Rams/DisplayLiftPolicy.php` — FOUND
- `tests/Unit/Services/Rams/DisplayLiftPolicyTest.php` — FOUND
- Commit `92b845a` — FOUND in `git log --oneline --all`
- Commit `6c66c2d` — FOUND in `git log --oneline --all`
