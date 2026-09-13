---
phase: 30-structural-validation-gates
plan: 07
subsystem: rams
tags: [laravel, php, validation-gates, rams-compliance]

# Dependency graph
requires:
  - phase: 30-structural-validation-gates
    plan: 06
    provides: StructuralGatesDisarmedTest (D-04 disarmed-posture/independence proof for GATE-01/02/04), documented scope note handing off the reciprocal GATE-13/GATE-14 halves to Plans 30-07/30-08
  - phase: 30-structural-validation-gates
    plan: 01
    provides: hot_works_gate_enabled flag (config/rams_tier1.php:234, RAMS_HOT_WORKS_GATE, defaults false), compliance_warnings channel
provides:
  - ControlTextRuleViolations::hot_works_assertion detector — negation-first, two-list, narrow-by-construction absence-assertion classifier
  - RamsComplianceUpgradeService::enforceHotWorksGate() (GATE-13) — both halves (unconditional permit requirement, solder/flux in COSHH), plus its own RAMS_HOT_WORKS_GATE dispatch block in upgrade()
  - permitRuleIsUnconditionalHotWorksRequirement() and documentAssertsNoHotWorks() — the gate's two private helper methods
  - HotWorksGateTest.php — throw/no-throw boundary proof, including the T-30-16 regression test built by invoking addPermitAndIsolation() directly
  - StructuralGatesDisarmedTest extended with the GATE-13 half of the D-04 flag-independence matrix
affects: [30-08, 30-09, 31-*]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "A detector that classifies informational/assertion text (not a house-rule violation) still lives in the shared ControlTextRuleViolations::DETECTORS registry, but is deliberately narrow enough to never match the app's own standing boilerplate that also matches the concept in plain English — because that registry is ALSO consumed by RamsBuilderService::reviewedToRisk()'s Tier-1 house-rule-replacement path, and a match there forces a reviewed hazard's controls back to template text unconditionally"
    - "Two-tier gate short-circuit: enforceHotWorksGate() detects the absence assertion FIRST and returns $data unchanged immediately if none is found, so the permit/COSHH scans (the actual contradiction check) never run on the overwhelming majority of documents that never mention hot works at all"
    - "Conditional-wording discriminator (permitRuleIsUnconditionalHotWorksRequirement()): a substring match for 'permit' + a hot-works/solder/heat-shrink mention is not sufficient on its own — a short deny-list of conditional markers ('if ', 'where ', 'should ', 'when ', 'may be required') must ALSO be absent, because the app's own shipped permit line is conditionally worded but reads like a requirement at a glance"

key-files:
  created:
    - tests/Unit/Services/Rams/HotWorksGateTest.php
  modified:
    - app/Services/Rams/ControlTextRuleViolations.php
    - app/Services/Rams/RamsComplianceUpgradeService.php
    - tests/Unit/Services/Rams/ControlTextRuleViolationsTest.php
    - tests/Feature/Rams/StructuralGatesDisarmedTest.php

key-decisions:
  - "hot_works_assertion's AFFIRMATIVE phrase list is deliberately NARROW (pairs 'no hot works'/'no soldering' with an explicit verb: 'will be', 'are to be', 'not required', 'excluded') rather than matching the bare substring 'no hot works' — discovered during Task 1 that HazardTemplateSeeder.php:370 ships 'No hot works of any kind included in this scope.' as a standing control line on the always-included 'Fire and evacuation' hazard (present on every generated RAMS), which is NOT in RESEARCH.md's grep scope (app/, config/, resources/views/pdf/ — database/seeders/ was never scanned). A bare-substring match would have broken the existing test_no_seeded_library_control_is_ever_flagged self-check AND caused RamsBuilderService::reviewedToRisk()'s Tier-1 replacement path to silently overwrite any hazard's reviewed controls whenever this common boilerplate line is present, discarding engineer edits to the OTHER lines in that same control array — a genuine production regression, not just a test failure. Handled as a Rule-1 bug fix: the phrase list is narrow enough to leave this sentence, and every other seeded/generated hot-works sentence in the app, classified as clean, while still matching all of the plan's required example sentences."
  - "GATE-13's assertion detection is fully delegated to ControlTextRuleViolations::detect()'s hot_works_assertion key (Plan 30-07 Task 1) — no second, bespoke regex inside the gate, per the class's established single-choke-point discipline"
  - "The permit half's conditional-wording deny-list ('if ', 'where ', 'should ', 'when ', 'may be required') is a substring check on the WHOLE rule line, not scoped to the hot-works phrase itself — conservative by construction (a false negative here is acceptable; a false positive would fire on the app's own unconditional-sounding shipped line)"
  - "The COSHH half matches on the coshh_baseline entry's 'product' field alone (case-insensitive substring 'solder'/'flux'), not the GHS codes — every solder/flux entry in config/rams_tier1.php's coshh_products baseline names the substance directly in its product string, so no GHS-code parsing is needed"
  - "The all-five-violations fixture in StructuralGatesDisarmedTest uses the COSHH half (not the permit half) to trigger GATE-13, specifically so the fixture never needs to touch permit_and_isolation — avoiding any interaction with addPermitAndIsolation()'s own conditional line, since the fixture is hand-built and fed directly to upgrade(), not built by invoking that method first"

patterns-established:
  - "A plan's own Finding/grep-scope claim ('the assertion side has no app-side source') is re-verified against the FULL codebase (including database/seeders/, which the cited grep command excluded) before being encoded as a design assumption — direct grep across app/, config/, database/seeders/, resources/views/pdf/ surfaced HazardTemplateSeeder.php:370 as a genuine app-side absence-assertion source the plan's research had missed"

requirements-completed: [GATE-13]

duration: ~50min
completed: 2026-09-13
---

# Phase 30 Plan 07: GATE-13 Hot-Works Contradiction Gate Summary

**Built GATE-13 whole — a `hot_works_assertion` detector plus `enforceHotWorksGate()` cross-referencing a "no hot works" absence assertion against an unconditional hot-works permit requirement or solder/flux in COSHH — ships disarmed behind its own `RAMS_HOT_WORKS_GATE` flag (default false) per D-02, with a discovered-and-fixed collision against the app's own standing "No hot works of any kind included in this scope." library boilerplate that RESEARCH.md's grep scope had missed.**

## Performance

- **Duration:** ~50 min
- **Completed:** 2026-09-13
- **Tasks:** 2
- **Files modified:** 5 (1 created, 4 modified)

## Accomplishments

- `ControlTextRuleViolations::hot_works_assertion` detector added to the shared `DETECTORS` registry (appended last — orthogonal domain, no phrase overlap with the four existing entries). Negation-first, two-list shape mirroring `detectConfinedSpace()`: `HOT_WORKS_NEGATIONS` (conditional/procedural wording — "permit required if", "if soldering", "under permit", etc.) checked first and short-circuits to clean; `HOT_WORKS_ABSENCE_ASSERTIONS` (narrow, verb-paired absence phrases — "no hot works will be", "hot works are not required", "no soldering will be undertaken", etc.) checked second.
- `RamsComplianceUpgradeService::enforceHotWorksGate()` (GATE-13): detects the absence assertion first via `documentAssertsNoHotWorks()` (scanning `exclusions`, every hazard name/control, every method-statement step through the shared detector), returning `$data` unchanged immediately if none is found. Only then scans `permit_and_isolation.rules` for an UNCONDITIONAL hot-works permit requirement via `permitRuleIsUnconditionalHotWorksRequirement()` (a conditional-marker deny-list keeps the app's own shipped "...permit required IF soldering..." line non-contradictory), then scans `coshh_baseline` entries' `product` field for "solder"/"flux".
- GATE-13's own dispatch block added to `upgrade()`, gated on `rams_tier1.hot_works_gate_enabled` (default `false`), placed after the structural trio block and before `cleanTextArtifacts()`.
- `HotWorksGateTest.php` (new, 8 tests): three throw cases (unconditional permit, solder in COSHH, flux in COSHH), the T-30-16 regression proof built by invoking `addPermitAndIsolation([])` directly and asserting identity, two clean-path no-throw cases (no assertion at all; assertion present with no contradicting evidence), and two dispatch-flag tests through the real `upgrade()` entry point.
- `StructuralGatesDisarmedTest.php` extended (owned jointly with Plan 30-08 per its scope note) with the GATE-13 half of the D-04 flag-independence matrix: arming `RAMS_HOT_WORKS_GATE` alone throws only the GATE-13 message on a document also violating GATE-01/02/04 (structural trio stays dormant); arming the structural trio alone throws only a structural message and leaves the hot-works gate dormant. The GATE-14 leg remains explicitly unasserted (method doesn't exist until Plan 30-08), documented in the file's own scope note.
- `ControlTextRuleViolationsTest.php` extended with 6 new tests covering the detector's throw/no-throw boundaries, including a dedicated test proving `HazardTemplateSeeder.php:370`'s seeded scope-exclusion line is never flagged.
- Full Rams test suite: 850 passed, 0 failed (was 834 at the end of Plan 30-06; 834 + 16 new tests across the three extended/new test files = 850, confirmed exact match).

## Task Commits

Each task was committed atomically:

1. **Task 1: hot_works_assertion detector in ControlTextRuleViolations** - `a820f0c` (test)
2. **Task 2: GATE-13 — enforceHotWorksGate() and its disarmed dispatch block** - `172e4a1` (feat)

_Note: both tasks were `tdd="true"`; per this plan's autonomous execution mode, RED state was confirmed for Task 2 via the documented "break-the-fix-and-watch-the-test-fail" procedure recorded in `HotWorksGateTest`'s own class docblock (stubbing `documentAssertsNoHotWorks()`'s guard to always pass, confirming exactly the three throwing tests fail, restoring and re-confirming `git diff` empty) before the GREEN implementation was committed as one commit, matching Plans 30-01/30-03/30-06's established convention._

## Files Created/Modified

- `app/Services/Rams/ControlTextRuleViolations.php` - added `hot_works_assertion` to `DETECTORS`, `HOT_WORKS_NEGATIONS`/`HOT_WORKS_ABSENCE_ASSERTIONS` consts, `detectHotWorksAssertion()`
- `app/Services/Rams/RamsComplianceUpgradeService.php` - added `enforceHotWorksGate()`, `documentAssertsNoHotWorks()`, `permitRuleIsUnconditionalHotWorksRequirement()`, and the `hot_works_gate_enabled` dispatch block in `upgrade()`
- `tests/Unit/Services/Rams/ControlTextRuleViolationsTest.php` - 6 new tests for `hot_works_assertion`'s throw/no-throw boundaries and the seeded-library collision proof
- `tests/Unit/Services/Rams/HotWorksGateTest.php` - new, 8 tests covering the gate's throw/no-throw boundaries, the T-30-16 regression proof, and dispatch-flag wiring
- `tests/Feature/Rams/StructuralGatesDisarmedTest.php` - extended with the GATE-13 half of the D-04 flag-independence matrix (2 new tests) and an updated scope note

## Decisions Made

- `hot_works_assertion`'s phrase list is narrow (verb-paired), not a bare "no hot works" substring match — a Rule-1 bug fix discovered mid-Task-1 against `HazardTemplateSeeder.php:370`'s standing library boilerplate, which RESEARCH.md's stated grep scope (`app/`, `config/`, `resources/views/pdf/`) never covered (`database/seeders/` was excluded from that grep)
- The gate's assertion detection is fully delegated to the shared detector registry — no bespoke regex inside `enforceHotWorksGate()`
- The COSHH half matches on the `product` field's substring only, not GHS codes — sufficient given every solder/flux entry names the substance directly
- The all-five-violations fixture (`StructuralGatesDisarmedTest`) triggers GATE-13 via the COSHH half specifically to avoid touching `permit_and_isolation`
- Both halves ship whole and correct but the flag stays `false` (D-02/D-04) — nothing in this plan arms `RAMS_HOT_WORKS_GATE`

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] RESEARCH.md's grep scope missed a genuine app-side "no hot works" assertion source, which would have broken production behaviour if the detector had used a bare substring match**
- **Found during:** Task 1, before writing `detectHotWorksAssertion()`
- **Issue:** RESEARCH.md Finding 5 states "the assertion side has no app-side source" based on `grep -rni "hot work" app/ config/ resources/views/pdf/`. That grep scope excludes `database/seeders/`. Direct broader grep found `HazardTemplateSeeder.php:370`: `'No hot works of any kind included in this scope.'` — a standing control line on the always-included ("include_when: always") "Fire and evacuation" hazard, present on every generated RAMS. A naive bare-substring "no hot works" detector would have (a) broken the existing `test_no_seeded_library_control_is_ever_flagged` self-check, since this plan's own `<behavior>` bullet explicitly requires that test to still pass untouched, and (b) caused `RamsBuilderService::reviewedToRisk()`'s Tier-1 house-rule-replacement path (which shares the same `ControlTextRuleViolations::DETECTORS` registry) to silently force ANY reviewed hazard's controls back to template text whenever this common boilerplate line is present — discarding an engineer's edits to the other four lines in that same control array on every RAMS regeneration touching this hazard. This is a real production regression risk, not merely a test failure.
- **Fix:** Designed `HOT_WORKS_ABSENCE_ASSERTIONS` as a narrow, verb-paired phrase list (e.g. "no hot works will be", "hot works are not required") rather than a bare "no hot works" substring match. Verified none of the chosen phrases match the seeded sentence, added a dedicated regression test (`test_seeded_fire_and_evacuation_no_hot_works_scope_line_is_never_flagged`) documenting the reasoning, and confirmed `test_no_seeded_library_control_is_ever_flagged` still passes. The detector still satisfies every explicit example sentence in the plan's `<behavior>` block.
- **Files modified:** `app/Services/Rams/ControlTextRuleViolations.php` (design choice, documented in the const's own docblock), `tests/Unit/Services/Rams/ControlTextRuleViolationsTest.php` (regression test added)
- **Verification:** `php artisan test --filter=ControlTextRuleViolationsTest` — 26 passed, 0 failed
- **Committed in:** `a820f0c` (Task 1 commit)

---

**Total deviations:** 1 auto-fixed (1 Rule-1 bug, caught and corrected before any GREEN implementation was written, no production behaviour change shipped — the narrow design was chosen from the start of the GREEN phase, not retrofitted after a regression)
**Impact on plan:** Strengthens D-02/RESEARCH.md Finding 5's own conclusion — the evidence base for shipping GATE-13 disarmed is even wider than RESEARCH stated (a third app-side absence-assertion source exists, in `database/seeders/`, outside the cited grep scope), while leaving GATE-13's implementation and the shared detector registry both correct and non-regressive.

## Issues Encountered

None beyond the grep-scope gap documented above.

## User Setup Required

None — no external service configuration, no new env var, no migration. `RAMS_HOT_WORKS_GATE` remains unset/false in `.env` (shipped disarmed by Plan 30-01; this plan builds the gate body behind it, arms nothing).

## Next Phase Readiness

- Plan 30-08 (GATE-14) can extend `StructuralGatesDisarmedTest.php` again with its own reciprocal flag-independence legs against GATE-13 (now shipped) and the structural trio, completing the D-04 matrix
- Plan 30-09's snapshot fixtures and phase gate can reference GATE-13 as shipped (disarmed) and tested
- Phase 31's arming task should read this plan's `enforceHotWorksGate()` docblock for the recorded Blade-side bypass (`pdf/rams.blade.php:407-409`, `pdf/rams-v2.blade.php:463-465` derive a 'Hot Works Permit' row invisible to `upgrade()`) before flipping `RAMS_HOT_WORKS_GATE=true`
- No blockers. Full Rams suite: 850 passed, 0 failed.

---
*Phase: 30-structural-validation-gates*
*Completed: 2026-09-13*

## Self-Check: PASSED

Verified on disk: `app/Services/Rams/ControlTextRuleViolations.php` contains `hot_works_assertion`/`detectHotWorksAssertion` (confirmed via grep); `app/Services/Rams/RamsComplianceUpgradeService.php` contains `enforceHotWorksGate` (confirmed via grep); `tests/Unit/Services/Rams/HotWorksGateTest.php` exists with 8 tests, all passing; `tests/Feature/Rams/StructuralGatesDisarmedTest.php` extended to 14 tests, all passing; `tests/Unit/Services/Rams/ControlTextRuleViolationsTest.php` extended to 26 tests, all passing. Both commit hashes (`a820f0c`, `172e4a1`) confirmed present in `git log --oneline -5`. Full Rams suite run: 850 passed, 0 failed. `config('rams_tier1.hot_works_gate_enabled')` confirmed defaulting `false`; no `.env` entry added.
