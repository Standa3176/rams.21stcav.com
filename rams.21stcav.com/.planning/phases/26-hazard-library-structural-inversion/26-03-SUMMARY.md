---
phase: 26-hazard-library-structural-inversion
plan: 03
subsystem: rams
tags: [laravel, hazard-library, rams, pdf, blade, config, kill-switch]

# Dependency graph
requires:
  - phase: 26-01
    provides: "18-hazard global library seeded with the include_when tier vocabulary"
  - phase: 26-02
    provides: "HazardIncludeWhenResolver — standalone tiered evaluator, not yet wired into the pipeline"
provides:
  - "Removal of all 4 render/build-time re-injection paths for the fixed 11-hazard baseline_hazards array (Tier1RamsDefaultsService, RiskAssessmentComposer, rams.blade.php LIVE template, rams-v2.blade.php)"
  - "config('rams_tier1.hazard_tiering_enabled') — a new, independent env-gated kill-switch (RAMS_HAZARD_LIBRARY_TIERING) ready for Plan 26-04 to consume, decoupled from the existing config('rams_tier1.enabled') so hazard tiering can be disabled without also disabling standards_references or coshh_products"
  - "Tier1BaselineHazardsRenderTest, Tier1RamsDefaultsServiceTest, and RamsDocumentComposerTest all rewritten to assert the new no-fallback contract"
affects: [26-04, 26-05, 26-06]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Narrower, independent env-gated kill-switch alongside an existing broader one (hazard_tiering_enabled vs. enabled) — precedent for splitting an all-or-nothing flag per-concern without touching the concerns that still need the broad flag"

key-files:
  created: []
  modified:
    - config/rams_tier1.php
    - app/Services/Rams/Tier1RamsDefaultsService.php
    - app/Support/Rams/SectionComposers/RiskAssessmentComposer.php
    - resources/views/pdf/rams.blade.php
    - resources/views/pdf/rams-v2.blade.php
    - app/Support/Rams/Sections/RiskAssessmentSectionDto.php
    - tests/Feature/Rams/Tier1BaselineHazardsRenderTest.php
    - tests/Unit/Services/Rams/Tier1RamsDefaultsServiceTest.php
    - tests/Feature/Rams/Composer/RamsDocumentComposerTest.php

key-decisions:
  - "hazard_tiering_enabled defaults to true (env('RAMS_HAZARD_LIBRARY_TIERING', true)) and is deliberately independent of the existing rams_tier1.enabled flag, which still gates standards_references and coshh_products/coshh_baseline — both out of Phase 26's scope"
  - "No fallback of any kind survives disabling either flag: setting the new flag false disables auto-population only (Plan 26-04's concern) and never resurrects the old fixed 11; this was verified for the render layer in this plan by proving rams_tier1.enabled=false makes no difference to hazard rendering any more"

patterns-established:
  - "When removing a fixed-value fallback, grep the whole app tree (not just files_modified) for the removed config key before declaring the plan done — this plan's own verification step caught a stale docblock reference in RiskAssessmentSectionDto.php that wasn't in the interfaces list"

requirements-completed: [HAZ-02, HAZ-03]

# Metrics
duration: ~40min
completed: 2026-08-24
---

# Phase 26 Plan 03: Hazard Library Structural Inversion — Kill the Fixed-Baseline Fallbacks Summary

**Deleted the entire 11-hazard `baseline_hazards` config array and its four render/build-time re-injection paths (including the LIVE PDF template `rams.blade.php`), replacing the coupled `rams_tier1.enabled` kill-switch with a new independent `hazard_tiering_enabled` flag so Plan 26-04's auto-population can be disabled with one `.env` edit without touching standards or COSHH.**

## Performance

- **Duration:** ~40 min
- **Started:** 2026-08-24T10:50:00Z (approx, first Read of plan files)
- **Completed:** 2026-08-24T11:20:33Z
- **Tasks:** 3/3 completed
- **Files modified:** 9 (6 in the plan's `files_modified` list + 3 pre-existing tests fixed as a direct consequence)

## Accomplishments
- `config/rams_tier1.php`'s 180-line `baseline_hazards` array (11 fixed hazards) is gone entirely; `coshh_products`, `standards_references`, and `av_prompt_bullets` are untouched.
- New `hazard_tiering_enabled` config key (`env('RAMS_HAZARD_LIBRARY_TIERING', true)`), decoupled from `enabled`, documented as the Plan 26-04 wiring point.
- `Tier1RamsDefaultsService::injectDefaultsIntoRamsData()` no longer touches `$data['hazards']` at all — `standards_references` and `coshh_baseline` branches unchanged.
- `RiskAssessmentComposer::compose()`'s config-fallback chain replaced with a plain `reviewed_data ?? generated_data ?? []` read.
- `resources/views/pdf/rams.blade.php` — **the live PDF template** — no longer re-injects the fixed 11 when `$hazards` is empty; the existing "No hazards identified." branch handles the true-empty case correctly, unchanged.
- `resources/views/pdf/rams-v2.blade.php` — identical block removed (not live, but can no longer silently resurrect the 11 if `RAMS_UNIFIED_COMPOSER` is ever flipped on).
- `Tier1BaselineHazardsRenderTest` rewritten: test 1 now proves the OPPOSITE of what it proved before this phase (empty stays empty, "No hazards identified." renders, old baseline titles absent — including a deliberate case-sensitive "Working at Height" canary vs. the new sentence-case skill vocabulary).
- Global verification (`grep -rn "baseline_hazards" config/ app/ resources/`) returns 0 matches.

## Task Commits

1. **Task 1: New narrower kill-switch + config/Tier1RamsDefaultsService cleanup** - `3e3a3fd` (feat)
2. **Task 2: Remove the 3 render-time fallbacks (paths #2, #3, #4)** - `4914b54` (feat)
3. **Task 3: Rewrite Tier1BaselineHazardsRenderTest** - `4998220` (test)

**Deviation fix (Rule 1):** `0af8cfa` (test) — fixed 2 pre-existing tests broken by Tasks 1-2's changes.

## Files Created/Modified
- `config/rams_tier1.php` - `baseline_hazards` array deleted; `hazard_tiering_enabled` key added immediately after `enabled`, with a docblock explaining the decoupling rationale.
- `app/Services/Rams/Tier1RamsDefaultsService.php` - Hazards fallback branch deleted; class + method docblocks updated to point at `HazardIncludeWhenResolver` as the new hazard-population owner.
- `app/Support/Rams/SectionComposers/RiskAssessmentComposer.php` - `$raw` assignment simplified to `(array) ($rd['hazards'] ?? $gd['hazards'] ?? [])`; class docblock updated.
- `resources/views/pdf/rams.blade.php` - **LIVE PDF template.** Deleted the `260712-twi Task 2` comment + `if (empty($hazards) && config('rams_tier1.enabled', true))` block. The unrelated `rams_tier1.enabled` COSHH-gating line (`:1849` post-edit) is untouched — out of scope.
- `resources/views/pdf/rams-v2.blade.php` - Identical block deleted at its corresponding location.
- `app/Support/Rams/Sections/RiskAssessmentSectionDto.php` - Stale docblock reference to `config('rams_tier1.baseline_hazards')` updated (not in `files_modified`, but caught by the plan's own global verification grep — see Deviations).
- `tests/Feature/Rams/Tier1BaselineHazardsRenderTest.php` - Test 1 renamed/rewritten to assert absence of the old baseline; tests 2 and 3 kept with updated comments/docblock describing the new contract.
- `tests/Unit/Services/Rams/Tier1RamsDefaultsServiceTest.php` - Tests 1 and 3 renamed/rewritten to assert `hazards` stays missing/empty; test 2's comment updated; tests 4 and 5 (standards/kill-switch) untouched.
- `tests/Feature/Rams/Composer/RamsDocumentComposerTest.php` - Fresh-build fixture assertion changed from `assertNotEmpty($dto->riskAssessment->hazards)` / `RA01` ref check to `assertEmpty(...)`, matching the composer's new no-fallback behaviour.

## Decisions Made
- `hazard_tiering_enabled` was added as a sibling key to `enabled` rather than replacing it, per the plan's explicit interface contract and CONTEXT.md's D-06/Discretion notes — `enabled` still legitimately gates `standards_references` and `coshh_products`, which this plan does not touch.
- Left the `RiskAssessmentComposer` constructor's `Repository $config` dependency in place even though it is now unused within `compose()` — the plan's action text only specified replacing the `$raw` assignment and updating the docblock; removing the constructor parameter was out of scope and risked touching DI wiring/tests not listed in `files_modified`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - stale doc, required by plan's own global verification] `RiskAssessmentSectionDto` docblock referenced the removed config path**
- **Found during:** Task 3 (running the plan's mandated `grep -rn "baseline_hazards" config/ app/ resources/` verification)
- **Issue:** `app/Support/Rams/Sections/RiskAssessmentSectionDto.php:19` still read `reviewed hazards / config('rams_tier1.baseline_hazards')` — not in the plan's `files_modified` list, but the plan's own `<verification>` block requires this grep to return 0 matches across the whole tree, not just the 5 named files.
- **Fix:** Reworded the docblock to describe reviewed/generated-only hazard population with no config-baseline fallback.
- **Files modified:** `app/Support/Rams/Sections/RiskAssessmentSectionDto.php`
- **Verification:** `grep -rn "baseline_hazards" config/ app/ resources/` now returns 0 matches (exit code 1).
- **Committed in:** `4998220` (part of Task 3 commit)

**2. [Rule 1 - bug, 3 pre-existing tests broken by Tasks 1-2] `Tier1RamsDefaultsServiceTest` and `RamsDocumentComposerTest` directly asserted the removed injection behaviour**
- **Found during:** Post-Task-3 regression run (`php artisan test --filter=Rams`, run beyond the plan's mandated scoped test as a broader safety check)
- **Issue:** Neither test file was in this plan's `files_modified` list, but both directly asserted the OLD baseline-injection contract this plan's Tasks 1-2 intentionally removed — `test_injects_baseline_hazards_when_data_hazards_is_missing`, `test_treats_empty_array_hazards_as_missing` (both asserting `>= 8` injected hazards), and `RamsDocumentComposerTest::test_fresh_build_produces_valid_dto_with_populated_cover` (asserting `RA01`/non-empty hazards on a fixture supplying none). All 3 failed immediately once Tasks 1-2 landed — a direct, in-scope consequence of this plan's own changes, not a pre-existing unrelated failure.
- **Fix:** Renamed/rewrote the two `Tier1RamsDefaultsServiceTest` methods to assert `hazards` stays missing/empty (no injection); updated `RamsDocumentComposerTest`'s fresh-build assertion to `assertEmpty($dto->riskAssessment->hazards)`.
- **Files modified:** `tests/Unit/Services/Rams/Tier1RamsDefaultsServiceTest.php`, `tests/Feature/Rams/Composer/RamsDocumentComposerTest.php`
- **Verification:** `php artisan test --filter=Rams` — 458 passed / 3 failed → 461 passed / 0 failed.
- **Committed in:** `0af8cfa`

---

**Total deviations:** 2 auto-fixed (both Rule 1 — stale reference / broken pre-existing test, both direct consequences of this plan's mandated removals, not scope creep).
**Impact on plan:** Necessary to satisfy the plan's own `<verification>` block (global `baseline_hazards` grep = 0) and to leave the `Rams` test namespace green, as required by the phase's live-validation posture (a red suite is not a safe state to hand to Plan 26-04).

## Issues Encountered
None beyond the deviations documented above.

## User Setup Required
None - no external service configuration required. Config-only + code removal + test updates; no migration, no deploy (Plan 26-06 owns live deploy).

## Next Phase Readiness
- All four render/build-time re-injection paths for the fixed 11-hazard baseline are gone; `config('rams_tier1.baseline_hazards')` no longer exists anywhere in the codebase (verified by tree-wide grep).
- `config('rams_tier1.hazard_tiering_enabled')` exists and defaults to `true`, ready for Plan 26-04 to read when wiring `HazardIncludeWhenResolver` into the RAMS build pipeline.
- `--filter=Rams` full namespace: 461 passed, 0 failed (1873 assertions).
- No blockers for Plan 26-04. Note for that plan: an empty hazards register is now the correct, expected state everywhere in the render/compose layers when the resolver isn't yet wired in — Plan 26-04 is what makes the 4 `always` tier-1 hazards (and any signal/confirm matches) actually appear.

## Self-Check: PASSED

- `config/rams_tier1.php` — FOUND, `baseline_hazards` absent, `hazard_tiering_enabled` present
- `app/Services/Rams/Tier1RamsDefaultsService.php` — FOUND, hazards branch absent
- `app/Support/Rams/SectionComposers/RiskAssessmentComposer.php` — FOUND, config fallback absent
- `resources/views/pdf/rams.blade.php` — FOUND, fallback block absent, "No hazards identified." branch intact
- `resources/views/pdf/rams-v2.blade.php` — FOUND, fallback block absent
- `tests/Feature/Rams/Tier1BaselineHazardsRenderTest.php` — FOUND, 3 test methods, passing
- Commit `3e3a3fd` — FOUND in `git log`
- Commit `4914b54` — FOUND in `git log`
- Commit `4998220` — FOUND in `git log`
- Commit `0af8cfa` — FOUND in `git log`
- `php artisan test --filter=Tier1BaselineHazardsRenderTest` — 3 passed, 8 assertions, 0 failures
- `php artisan test --filter=Rams` — 461 passed, 0 failed, 1873 assertions
- `grep -rn "baseline_hazards" config/ app/ resources/` — 0 matches

---
*Phase: 26-hazard-library-structural-inversion*
*Completed: 2026-08-24*
