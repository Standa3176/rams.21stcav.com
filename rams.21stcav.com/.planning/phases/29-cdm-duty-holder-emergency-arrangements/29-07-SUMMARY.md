---
phase: 29-cdm-duty-holder-emergency-arrangements
plan: 07
subsystem: rams-gate-12
tags: [rams, gate-12, gap-closure, cr-01, conservative-by-construction, tdd]

# Dependency graph
requires:
  - phase: 29-cdm-duty-holder-emergency-arrangements
    plan: 03
    provides: "SiteEmergencyResolver::classify() (GATE-12 plausibility check), disarmed by default via config('rams_tier1.cdm_ae_gate_enabled')"
provides:
  - "SiteEmergencyResolver::classify() now returns null for the exact HOLD_POINT literal in nearest_hospital, regardless of hospital_address content — closes 29-VERIFICATION.md gap 1 / 29-REVIEW.md CR-01"
affects: []

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Widened an existing early clean-return with an additional OR condition rather than restructuring classify()'s branch order — kept the conservative-by-construction (D-01/D-08) guarantee strictly additive"

key-files:
  created: []
  modified:
    - app/Services/Rams/SiteEmergencyResolver.php
    - tests/Unit/Services/Rams/SiteEmergencyResolverTest.php

key-decisions:
  - "Fix implemented as a single additional `|| $name === self::HOLD_POINT` on the existing blank-name early-return, using the already-trimmed $name (not $rawName) to match the rest of the method's convention. No other branch (banned_string, urgent_care_keyword, missing_address_or_postcode) touched."
  - "Two new regression tests added: the exact CR-01 repro (HOLD_POINT literal + blank hospital_address) and a second case with a non-empty hospital_address, proving the clean path does not depend on the address field at all — matching the plan's explicit requirement that a PM could paste the literal into either field independently."
  - "config/rams_tier1.php was not touched — cdm_ae_gate_enabled remains defaulted false, matching this plan's explicit constraint."

patterns-established: []

requirements-completed: [RULE-08, GATE-12]  # GATE-12's plausibility check no longer misfires on its
  # own sanctioned output; this closes gap 1 from 29-VERIFICATION.md / CR-01 from 29-REVIEW.md but
  # does not itself arm the gate (D-03's arming decision is unaffected by this plan).

# Metrics
duration: 20min
completed: 2026-09-12
---

# Phase 29 Plan 07: GATE-12 HOLD_POINT False-Positive Fix Summary

**Widened `SiteEmergencyResolver::classify()`'s early clean-return to also match the exact HOLD_POINT sentence, closing the CR-01 defect where GATE-12 would have flagged its own sanctioned output as a defect the moment `RAMS_CDM_AE_GATE` is armed.**

## Performance

- **Duration:** ~20 min
- **Started:** 2026-09-12
- **Completed:** 2026-09-12
- **Tasks:** 1/1 complete
- **Files modified:** 2

## Accomplishments

- Reproduced the CR-01 defect as a failing test first (TDD RED): `classify(['nearest_hospital' => HOLD_POINT, 'hospital_address' => ''])` returned `'missing_address_or_postcode'` instead of `null`.
- Fixed `classify()`'s early clean-return (`app/Services/Rams/SiteEmergencyResolver.php`) to also return `null` when the trimmed name equals `self::HOLD_POINT`, mirroring the existing blank-name check's placement and comment style, and extending the comment to document both cases the check now covers.
- Added two regression tests: the exact CR-01 repro (blank `hospital_address`) and a second case with a non-empty `hospital_address`, proving the fix does not depend on the address field.
- Verified all pre-existing `SiteEmergencyResolverTest` cases (blank-name clean, banned_string, all urgent-care-keyword variants, `utc` word-boundary, missing-address-for-a-genuine-hospital, verified-clean) remain unchanged and green.
- Ran the full RAMS Unit+Feature surface (`tests/Unit/Support/Rams tests/Feature/Rams`) — 325 passed, confirming no cross-file regression.

## Task Commits

1. **Task 1: Widen classify()'s clean-return to cover the HOLD_POINT literal**
   - `0050f19` (test) — RED: failing regression test for the CR-01 repro input
   - `a8427f4` (fix) — GREEN: additive widening of the early clean-return

## Files Created/Modified

- `app/Services/Rams/SiteEmergencyResolver.php` — `classify()`'s early clean-return now covers both the blank name and the exact HOLD_POINT literal
- `tests/Unit/Services/Rams/SiteEmergencyResolverTest.php` — two new tests: `test_classify_passes_hold_point_literal_with_blank_address`, `test_classify_passes_hold_point_literal_regardless_of_address`

## Deviations from Plan

None — plan executed exactly as written. The fix is a single additive `||` clause; no other branch was touched, `resolve()` was not modified, and `config/rams_tier1.php` was not touched.

## Issues Encountered

None.

## TDD Gate Compliance

RED gate commit: `0050f19` (`test(29-07): add failing regression for HOLD_POINT literal in classify()`).
GREEN gate commit: `a8427f4` (`fix(29-07): widen classify() clean-return to cover HOLD_POINT literal`).
No REFACTOR commit needed — the fix was minimal and required no cleanup pass.

## Verification

- `php artisan test tests/Unit/Services/Rams/SiteEmergencyResolverTest.php` — 17 passed (20 assertions), including the two new HOLD_POINT-literal cases and every pre-existing case unchanged.
- `php artisan test tests/Unit/Support/Rams tests/Feature/Rams` — 325 passed (full RAMS surface, no regression).
- `grep -n "self::HOLD_POINT" app/Services/Rams/SiteEmergencyResolver.php` shows the constant now referenced inside `classify()` (in addition to the docblock and `resolve()`).
- `config/rams_tier1.php` not modified in this plan's commits — `cdm_ae_gate_enabled` still defaults `false`.

## User Setup Required

None. This is a code-level bug fix; it does not arm GATE-12 and requires no deploy-specific action beyond the phase's existing (still-pending) deploy plan.

## Next Phase Readiness

- 29-VERIFICATION.md gap 1 / 29-REVIEW.md CR-01 is closed at the code level. GATE-12 will no longer misfire on its own sanctioned HOLD_POINT output once `RAMS_CDM_AE_GATE` is armed.
- Phase 29's overall closeout is still gated on the outstanding items already tracked in `29-06-SUMMARY.md` (visual document inspection on a live regenerated project, then arming the gate per D-03) — this plan does not change that status.
- No new blockers introduced.

---
*Phase: 29-cdm-duty-holder-emergency-arrangements*
*Completed: 2026-09-12*

## Self-Check: PASSED

- FOUND: app/Services/Rams/SiteEmergencyResolver.php
- FOUND: tests/Unit/Services/Rams/SiteEmergencyResolverTest.php
- FOUND: commit 0050f19 (test)
- FOUND: commit a8427f4 (fix)
