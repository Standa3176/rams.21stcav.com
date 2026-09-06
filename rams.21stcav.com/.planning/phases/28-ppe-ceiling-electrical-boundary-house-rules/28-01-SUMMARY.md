---
phase: 28-ppe-ceiling-electrical-boundary-house-rules
plan: 01
subsystem: rams
tags: [php, laravel, phpunit, rule-engine, ppe, confined-space]

# Dependency graph
requires:
  - phase: 27-08
    provides: "ControlTextRuleViolations class + DETECTORS registry choke point, consumed by RamsBuilderService::reviewedToRisk()"
provides:
  - "ffp2 detector: flags the bare FFP2 token (including the 'FFP2 or FFP3' hedge) in any hazard control line"
  - "confined_space detector: negation-aware, hyphen/whitespace-normalised classifier for affirmative confined-space mislabels, safe against the seeder's own negating sentence, the fold-map's canonical outputs, and short hazard-name labels"
  - "20-case proof corpus (12 new tests) proving both detectors against trivial, hedge, negation, hyphenated, bare-label, fold-map, AI-prompt-derived, and documented-out-of-scope fixtures"
affects: ["28-06 (GATE-06/07 throwing gate — consumes detect() for both control-line and hazard-name surfaces)"]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Normalise-before-match: hyphens/whitespace collapsed to a single space in one variable, then ALL checks (negation, affirmative, bare fallback) read that SAME variable — prevents a fallback that quietly reads the un-normalised string from reopening a closed gap"

key-files:
  created: []
  modified:
    - app/Services/Rams/ControlTextRuleViolations.php
    - tests/Unit/Services/Rams/ControlTextRuleViolationsTest.php

key-decisions:
  - "detectFfp2() has no negation logic (unlike detectConfinedSpace()) — there is no legitimate sentence containing the literal token FFP2, so a trivial /\\bFFP2\\b/i regex is correct and deliberately catches the 'FFP2 or FFP3' hedge as a violation, not a pass."
  - "detectConfinedSpace() normalises hyphens/whitespace into a distinctly-named $normalised variable and uses it for negation, affirmative, AND bare-substring checks — per the plan's revision-2 finding, a fallback reading the merely-lowercased string would reopen the exact gap revision 1 closed for a bare hyphenated name like 'Confined-Space'."
  - "DocxBuilderService.php:1903's §6.11 boilerplate is left unedited and DOES classify as confined_space — documented as intentionally out of scope (scope_decisions block) because it names a Principal-Contractor permit category, not a 21CAV space claim, and never enters $data['hazards'] so no gate ever scans it."
  - "RamsBuilderService.php was not touched, per plan constraint — the DETECTORS registry addition is picked up automatically by the existing detectAll() call site."

requirements-completed: [RULE-01, GATE-07]

duration: ~20min
completed: 2026-09-06
---

# Phase 28 Plan 01: FFP2 and Confined-Space Control-Text Detectors Summary

**Added `ffp2` and `confined_space` entries to `ControlTextRuleViolations::DETECTORS` — a trivial bare-token regex and a negation-aware, hyphen-normalised affirmative-phrase classifier — with a 12-test proof corpus covering the seeder's negating sentence, the FFP2-or-FFP3 hedge, hyphenated forms, bare hazard-name labels, and the fold-map's canonical outputs.**

## Performance

- **Duration:** ~20 min
- **Completed:** 2026-09-06
- **Tasks:** 2 (both `type="auto" tdd="true"` / `type="auto"`)
- **Files modified:** 2

## Accomplishments
- `detectFfp2()` — case-insensitive word-boundary match on the literal token `FFP2`, no negation list, deliberately flags the "FFP2 or FFP3" hedge as a violation
- `detectConfinedSpace()` — normalises hyphens/whitespace into one variable used by all three checks (negation list first, affirmative phrase list second, bare `str_contains` fallback last), so hyphenated and bare-label inputs cannot slip past the fallback the way the plan-checker's revision-1 and revision-2 findings identified
- Both entries registered in `DETECTORS`; `RamsBuilderService::reviewedToRisk()` untouched — confirmed via `grep -n "detectAll" app/Services/RamsBuilderService.php` showing the single, unmodified call site
- 12 new test methods added across `// ── ffp2 (RULE-01) ──` and `// ── confined_space (GATE-07) ──` sections, plus the two pre-existing self-check tests (`test_no_seeded_library_control_is_ever_flagged`, `test_no_display_lift_policy_sentence_is_ever_flagged`) pass unmodified with both new detectors active

## Task Commits

Each task was committed atomically:

1. **Task 1: Add detectFfp2() and detectConfinedSpace() to the DETECTORS registry** - `74b5612` (feat)
2. **Task 2: Extend the proof corpus for D-01's load-bearing acceptance criterion** - `571b910` (test)

**Plan metadata:** pending (this commit)

## Files Created/Modified
- `app/Services/Rams/ControlTextRuleViolations.php` - Added `CONFINED_SPACE_NEGATIONS` / `CONFINED_SPACE_AFFIRMATIVE` const phrase lists, `detectFfp2()`, `detectConfinedSpace()`, and their two `DETECTORS` registry entries
- `tests/Unit/Services/Rams/ControlTextRuleViolationsTest.php` - Added `ffp2 (RULE-01)` and `confined_space (GATE-07)` test sections (12 new test methods) plus the `LegacyHazardNameFoldMap` import

## Decisions Made
- No negation logic for `detectFfp2()` — the bare token is always a defect, matching the plan's `<action>` spec exactly (see key-decisions above for rationale).
- Both new detector phrase-check variables normalise BEFORE any comparison and share one variable across all three checks, closing the exact gap the plan-checker found twice (hyphen escape in revision 1, fallback-reads-wrong-variable escape in revision 2).
- Followed the plan's explicit exclusion of `DocxBuilderService.php:1903` and `resources/views/rams/review.blade.php:264` — neither file was touched; the boilerplate sentence's classification is documented via a new test rather than suppressed.

## Deviations from Plan

None — plan executed exactly as written. Both detector methods, both registry entries, and all listed test methods (including the three revision-1/2 fixtures) were implemented verbatim per the plan's `<action>` and `<action>`/test specs.

## Issues Encountered

None. The project's PHP binary is not on `PATH` in this shell (`php: command not found`); resolved by invoking `/c/Users/sonny.tanda/.config/herd/bin/php84.bat artisan test` directly — no code or environment change required.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Plan 28-06 (the GATE-06/07 throwing gate) can now call `ControlTextRuleViolations::detect()` / `detectAll()` directly against both hazard control lines and hazard-name labels — this plan's Task 1 behavior cases explicitly prove the classifier handles the short-label input shape 28-06 needs.
- Plan 28-02 (fold-map rename) can proceed independently — `test_fold_map_target_is_never_flagged_as_confined_space` iterates `LegacyHazardNameFoldMap::all()`'s values rather than hardcoding the current target string, so it remains valid after that plan renames it.
- No blockers.

## Self-Check: PASSED

- FOUND: app/Services/Rams/ControlTextRuleViolations.php
- FOUND: tests/Unit/Services/Rams/ControlTextRuleViolationsTest.php
- FOUND commit 74b5612
- FOUND commit 571b910
- Verification run: `php artisan test --filter=ControlTextRuleViolationsTest` → 20 passed (64 assertions), exit 0

---
*Phase: 28-ppe-ceiling-electrical-boundary-house-rules*
*Completed: 2026-09-06*
