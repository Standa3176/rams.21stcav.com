---
phase: 27-manual-handling-display-lift-house-rules
plan: 04
subsystem: worksheet
tags: [php, laravel, worksheet, method-statement, display-lift, manual-handling]

# Dependency graph
requires:
  - phase: 27-01
    provides: "App\\Services\\Rams\\DisplayLiftPolicy — the single shared band table (forSize(), genericBandSummary(), wallMountRemovalStatement())"
  - phase: 27-02
    provides: "RamsComplianceUpgradeService::suggestHandlingMethod()'s general inch-parsing regex and small-control-panel keyword set, reused verbatim here"
provides:
  - "SafetyProfileService::resolveDisplayLiftWarning() — worksheet display-lift warnings now resolved through DisplayLiftPolicy, not a hardcoded 55-inch threshold + fixed size-list regex"
  - "MethodStatementService::fallbackPhases()'s Installation Works step reads DisplayLiftPolicy::genericBandSummary() instead of a hardcoded 'two-person lifts' claim"
affects: [27-03, gate-09-structural-guard]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Metadata-first, general-inch-regex-fallback display resolution: the same pattern already established in RamsComplianceUpgradeService::suggestHandlingMethod() is now mirrored (not reimplemented independently) in SafetyProfileService"

key-files:
  created: []
  modified:
    - app/Services/Worksheet/SafetyProfileService.php
    - app/Services/MethodStatementService.php
    - tests/Unit/Services/Worksheet/SafetyProfileServiceTest.php
    - tests/Unit/Services/MethodStatementServiceTest.php

key-decisions:
  - "A 43\"/32\" genuine display now produces a worksheet warning (1-operative band) where it previously produced none — D-04's explicit, locked requirement that the worksheet and the RAMS never disagree about which displays need a stated team size."
  - "The <=14\" scheduling/touch/booking/control-panel exclusion is mirrored onto the worksheet side using the same keyword set as suggestHandlingMethod() (Open Question 1, resolved in favour of consistency) — a genuine control panel still produces no row at all, a different outcome from a genuine small display."
  - "Display-shaped-ness on the worksheet side is intentionally broader than RamsComplianceUpgradeService's: any item with a display_size_in tag, a resolvable inch token in its name (regardless of surrounding words), or a display/tv/television/screen/monitor/lcd keyword is treated as a display. This preserves the pre-existing worksheet test expectation (a 'Samsung 32\" LCD Monitor' with no literal 'display' keyword still fires) while still excluding non-display items like racks/amps/speakers that carry no size token or display keyword."

requirements-completed: [RULE-02]

# Metrics
duration: ~25min
completed: 2026-08-26
---

# Phase 27 Plan 04: Worksheet + Method-Statement DisplayLiftPolicy Parity Summary

**`SafetyProfileService` and `MethodStatementService`'s fallback now resolve display-lift team sizes through the same shared `DisplayLiftPolicy` bands the RAMS §6.7 path uses, closing the gap where a 43" display fired no worksheet warning at all.**

## Performance

- **Duration:** ~25 min
- **Tasks:** 2
- **Files modified:** 4

## Accomplishments

- Removed `SafetyProfileService::LARGE_DISPLAY_INCHES = 55` and its fixed-size-list keyword regex (`55|65|70|75|85|86|98|100`); replaced `roomContainsLargeDisplay(): bool` with `resolveDisplayLiftWarning(): ?string`, which resolves the worst-case (highest `min_persons`) display band per room via `DisplayLiftPolicy::forSize()`, using the same general inch-parsing regex and small-control-panel keyword set `RamsComplianceUpgradeService::suggestHandlingMethod()` already uses.
- A genuine 32"/43"/96" display now produces the correct band-specific warning sentence on the worksheet; a ≤14" scheduling/touch/control panel still produces no row at all (mirrored exclusion, Open Question 1 resolved in favour of consistency).
- `MethodStatementService::fallbackPhases()`'s `'4. Installation Works'` step no longer states an unconditional "two-person lifts" claim — it now interpolates `DisplayLiftPolicy::genericBandSummary()`, which states all three bands (1/2/3 operatives) and the never-4 rule in prose.
- Updated both test files: the previously pinned "small display does not fire" assertion (whose premise was wrong under D-04) is replaced by a test proving the corrected 1-operative-band behaviour; the 75"/85" keyword and metadata tests now assert the real `DisplayLiftPolicy` sentence instead of a hardcoded "Large display" string; added 96"-keyword and 95"-metadata coverage for the 3-operative band, a dedicated ≤14" control-panel no-warning test, and a `MethodStatementServiceTest` assertion that `fallback()`'s Installation Works step contains no literal "two-person lifts" substring.

## Task Commits

1. **Task 1: Worksheet + method-statement parity with DisplayLiftPolicy** - `6af9227` (feat)
2. **Task 2: Revisit pinned test + extend both test files** - `3d55225` (test)

**Plan metadata:** (this commit, docs: complete plan)

## Files Created/Modified

- `app/Services/Worksheet/SafetyProfileService.php` - `LARGE_DISPLAY_INCHES` constant removed; `roomContainsLargeDisplay()` replaced by `resolveDisplayLiftWarning()`, which resolves per-item inch values (metadata-first, general-regex fallback), determines the small-control-panel flag via the same keyword set as `suggestHandlingMethod()`, and calls `DisplayLiftPolicy::forSize()` — returning the worst-case band's sentence for the room, or `null` when no item resolves to a display band at all.
- `app/Services/MethodStatementService.php` - `fallbackPhases()`'s Installation Works step string now interpolates `DisplayLiftPolicy::genericBandSummary()` instead of hardcoding "two-person lifts"; imports `App\Services\Rams\DisplayLiftPolicy`.
- `tests/Unit/Services/Worksheet/SafetyProfileServiceTest.php` - pinned pre-D-04 assertion replaced with `test_genuine_small_display_now_fires_single_operative_band`; keyword/metadata tests updated to assert the real `DisplayLiftPolicy` sentence; added 3-operative-band (keyword + metadata) coverage and a dedicated small-control-panel no-warning test.
- `tests/Unit/Services/MethodStatementServiceTest.php` - added `test_fallback_installation_works_step_has_no_hardcoded_two_person_lift_claim`.

## Decisions Made

- Display-shaped-ness for the worksheet resolver is intentionally broader than `suggestHandlingMethod()`'s own display-keyword gate (`display`/`tv`/`television`/`screen`): it also treats any item with a `display_size_in` tag or a bare resolvable inch token in its name (e.g. "Samsung 32\" LCD Monitor", no literal "display" keyword) as a display. This was necessary to preserve the file's own pre-existing keyword-fallback philosophy (the old regex fired off ANY size+quote/inch token in the name, with no keyword gate at all) while still excluding non-display items (racks, amps, speakers) that carry neither a size token nor a display keyword. Read the actual `DisplayLiftPolicy::forSize()` return values rather than guessing wording, per the plan's explicit instruction.
- The small-control-panel keyword set (`scheduling`, `touch panel`, `booking panel`, `control panel`) and the general inch-parsing regex are copied verbatim from `RamsComplianceUpgradeService::suggestHandlingMethod()`, not reimplemented — per D-03/T-27-05, both paths must read the same source of truth so a future edit to one cannot silently diverge from the other.

## Deviations from Plan

None - plan executed exactly as written. The one area requiring judgment (exactly which keywords make an item "display-shaped" on the worksheet side, since the plan's acceptance criteria required a "Samsung 32\" LCD Monitor" — no literal "display" keyword — to fire a warning) is documented above under Decisions Made, not a deviation from any stated behavior in the plan.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- `SafetyProfileService.php` and `MethodStatementService.php` are both now candidates for Plan 27-03's `DisplayLiftPolicySourceGuardTest` allow-list scan (per this plan's threat register entry T-27-05) — confirm that guard test's file list includes both when 27-03 lands.
- Full test suite run after this plan: 2329 passed, 1 pre-existing failure (`Tests\Feature\Queue\QueueRecoverCommandTest::unhealthy_queue_runs_restart_and_drain_plan`, a documented environment/memory-limit flake unrelated to this plan's changes — see that test's own in-file comment), 6 skipped, no new failures introduced by this plan.

## Self-Check: PASSED

All 4 modified files and the SUMMARY.md exist on disk; both task commit hashes (`6af9227`, `3d55225`) are present in `git log`.
