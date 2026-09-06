---
phase: 28-ppe-ceiling-electrical-boundary-house-rules
plan: 02
subsystem: rams
tags: [php, laravel, phpunit, seeder, hazard-library]

# Dependency graph
requires:
  - phase: 26-08
    provides: "LegacyHazardNameFoldMap single choke-point class + drift-guard test convention, consumed by HazardLibraryService::fuzzyMatch()"
provides:
  - "Restricted-access hazard's canonical title renamed to 'Restricted access and ceiling void working' across seeder, fold map, and drift-guard test — matching house-rules.md and the already-correct RULE-06/ROADMAP text"
  - "New rename-supersession fold-map entry ('restricted access and ceiling voids' -> new title) so documents reviewed between Phase 26 and Phase 28 still fold forward on next regeneration"
affects: ["28-06 (GATE-06/07 throwing gate)", "28-03 (PPE closed-vocabulary fix, same wave, independent)"]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Rename via the seeder + single-choke-point fold map (LegacyHazardNameFoldMap), not a DB migration — HazardTemplateSeeder's upsert/orphan-cleanup mechanism (by-name WHERE is_global=true, whereNotIn delete) makes a seeder name change safe on next reseed with no separate migration"
    - "Rename-supersession fold-map entry: when a canonical title is itself renamed, add a new MAP entry keyed on the OLD canonical string (not just the pre-existing legacy aliases) so already-reviewed documents from the prior title's lifetime keep folding forward"

key-files:
  created: []
  modified:
    - database/seeders/HazardTemplateSeeder.php
    - app/Services/Rams/LegacyHazardNameFoldMap.php
    - tests/Unit/Services/Rams/LegacyHazardNameFoldMapTest.php
    - tests/Unit/Services/HazardLibraryServiceTest.php
    - tests/Feature/Rams/RiskTemplateResolverServiceTest.php
    - tests/Feature/Rams/ReviewedHazardTieringTest.php

key-decisions:
  - "D-05: RENAME the shipped title to match the skill (not amend the requirement) — REQUIREMENTS.md RULE-06 and ROADMAP.md criterion 2 already state 'Restricted access and ceiling void working' verbatim and needed zero edits, confirmed by grep before starting; renaming was the smaller diff and needed no divergence record."
  - "Added a NEW fold-map entry keyed on the Phase-26-shipped title itself ('restricted access and ceiling voids'), distinct from the pre-existing Group 2/3 legacy-name entries, closing a reachability gap the plan explicitly calls out: a document reviewed between Phase 26 and this rename carries the app's own prior canonical output, not a pre-Phase-26 legacy name."
  - "Per the plan's <scope_decision_no_title_backfill> block, did NOT add a migration to backfill already-stored reviewed_data/generated_data hazard names — the old title self-heals on next full regeneration via the new fold-map entry, matching the accepted Plan 27-08 tier-1 philosophy."
  - "Fixed three downstream tests (HazardLibraryServiceTest, RiskTemplateResolverServiceTest, ReviewedHazardTieringTest) that were not in the plan's files_modified list but broke because they exercise the REAL seeded hazard_templates row (not a mock) and asserted the old title verbatim — Rule 1 (bug directly caused by this plan's rename), confirmed pre-existing-vs-caused by temporarily reverting the seeder and re-running the specific failing test methods."
  - "Left RamsBuilderServiceTest, MethodStatementAssociatedRisksTest, and HazardIncludeWhenResolverTest untouched despite containing the old title string — confirmed each uses the title only as self-contained mock/fixture input (mock returns old title, test asserts the same mock's old title back), independent of the actual seeded value, so they pass unmodified and touching them would be out-of-scope churn."
  - "Left the one pre-existing RamsBuilderServiceTest failure (test_reviewedToRisk_case_only_match_renames_display_but_keeps_row_controls, unrelated 'Working at height' control-preservation assertion) untouched and undiagnosed further — confirmed via seeder revert that it fails identically before and after this plan's change, so it is out of scope per the Scope Boundary rule and logged to deferred-items.md rather than fixed."

requirements-completed: [RULE-06]

# Metrics
duration: ~35min
completed: 2026-09-06
---

# Phase 28 Plan 02: Restricted-Access Hazard Title Rename (D-05) Summary

**Renamed the restricted-access hazard's canonical title from "Restricted access and ceiling voids" to "Restricted access and ceiling void working" across the seeder, the fold map (repointing two existing targets and adding a new rename-supersession entry), and the drift-guard test — plus fixed three downstream tests that read the real seeded value and broke as a direct result.**

## Performance

- **Duration:** ~35 min
- **Completed:** 2026-09-06
- **Tasks:** 2 planned (both `type="auto"`)
- **Files modified:** 6 (3 planned + 3 Rule-1 downstream test fixes)

## Accomplishments
- `HazardTemplateSeeder.php` hazard #7's `name` field and its inline comment now read "Restricted access and ceiling void working" — description, controls, `include_when`, and likelihood/severity fields left untouched (title-only rename), verified idempotent via the existing seeder test suite (5 tests, including "reseeding is idempotent" and "seeding twice keeps eighteen global rows each time")
- `LegacyHazardNameFoldMap.php`: Group 2's `'cable installation in ceiling voids'` and Group 3's `'confined spaces'` entries now both resolve to the new title; added a new dated docblock note explaining the rename (what/why/D-05) and a new MAP entry `'restricted access and ceiling voids' => 'Restricted access and ceiling void working'` closing the reachability gap for documents reviewed in the Phase-26-to-28 window
- `LegacyHazardNameFoldMapTest.php`: renamed test 1 and updated its 3 assertions to the new title; added `test_phase_26_shipped_title_also_resolves_to_the_renamed_canonical_title()` proving the new supersession entry — all 5 tests pass (23 assertions)
- Confirmed via `--filter=ControlTextRuleViolationsTest` that 28-01's `test_fold_map_target_is_never_flagged_as_confined_space()` iterates `LegacyHazardNameFoldMap::all()`'s values dynamically (not a hardcoded string) — it passed unmodified with one extra assertion (17 map entries now, up from 16), confirming 28-01's forward-compatibility design worked exactly as its summary predicted
- Discovered and fixed 3 downstream tests broken by the rename (not in this plan's `files_modified`, but directly caused by it): `HazardLibraryServiceTest::test_confined_spaces_resolves_to_a_real_template_via_resolve_from_seeds`, and 3 assertions across `RiskTemplateResolverServiceTest` (tier-2 ceiling-signal match, explicit-picks fold path, `tieredRowsNotAlreadyPresent()` match), and `ReviewedHazardTieringTest::test_real_legacy_vocabulary_folds_dedupes_and_restores_library_scores` — all read the live seeded `hazard_templates` row through `app(...)`, not a mock, so they asserted the old title verbatim and failed until updated

## Task Commits

Each task was committed atomically:

1. **Task 1: Rename the seeder hazard and repoint the fold map** - `0cb6d89` (feat)
2. **Task 2: Update the fold-map drift-guard test** (+ 3 downstream Rule-1 fixes) - `a6c2fea` (test)

**Plan metadata:** pending (this commit)

## Files Created/Modified
- `database/seeders/HazardTemplateSeeder.php` - Hazard #7 `name` and inline comment renamed; no other field changed
- `app/Services/Rams/LegacyHazardNameFoldMap.php` - Two MAP values repointed, one new MAP entry added, new dated docblock note recording the rename
- `tests/Unit/Services/Rams/LegacyHazardNameFoldMapTest.php` - Test 1 renamed + updated, new test 1b added
- `tests/Unit/Services/HazardLibraryServiceTest.php` - Updated one assertion's expected title string (Rule 1)
- `tests/Feature/Rams/RiskTemplateResolverServiceTest.php` - Updated 3 assertions' expected title string plus a docblock comment (Rule 1)
- `tests/Feature/Rams/ReviewedHazardTieringTest.php` - Updated a docblock comment plus 4 assertions' expected title string (Rule 1)

## Decisions Made
See `key-decisions` in frontmatter above — summarized: rename (not requirement amendment) per D-05's own stated rationale; new supersession fold-map entry is not optional (closes a silent-unreachability gap); no title backfill migration per the plan's explicit scope decision; downstream test breakage traced to Rule 1 (bug directly caused by this plan) versus one confirmed pre-existing, unrelated failure left alone.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Fixed 3 downstream tests broken by the rename**
- **Found during:** Task 2 verification (running the plan's required `--filter=ControlTextRuleViolationsTest` and then broadening to check for other live-seeded-data test breakage before considering the plan done)
- **Issue:** `HazardLibraryServiceTest`, `RiskTemplateResolverServiceTest`, and `ReviewedHazardTieringTest` all resolve hazards through the real `HazardTemplateSeeder`-seeded `hazard_templates` table (via `app(RiskTemplateResolverService::class)` / `app(HazardLibraryService::class)` / RAMS regeneration), not a mock, and hardcoded the pre-rename title in their assertions — Task 1's seeder rename broke them immediately.
- **Fix:** Updated each assertion's (and two docblock comments') expected string to the new title. Confirmed via `git show`-based targeted revert-and-retest that `RamsBuilderServiceTest`'s one failure is unrelated (fully mocked `resolveFromSeeds()`, fails identically with the old seeder title too) and left it alone.
- **Files modified:** `tests/Unit/Services/HazardLibraryServiceTest.php`, `tests/Feature/Rams/RiskTemplateResolverServiceTest.php`, `tests/Feature/Rams/ReviewedHazardTieringTest.php`
- **Verification:** `--filter=HazardLibraryServiceTest` (4 passed, 25 assertions), `--filter=RiskTemplateResolverServiceTest` (10 passed, 49 assertions), `--filter=ReviewedHazardTieringTest` (7 passed, 84 assertions) — all exit 0
- **Committed in:** `a6c2fea` (Task 2 commit)

---

**Total deviations:** 1 auto-fixed (Rule 1, 3 files)
**Impact on plan:** Necessary for correctness — these tests exercise the exact live behavior this plan changed. No scope creep; `RamsBuilderServiceTest`, `MethodStatementAssociatedRisksTest`, and `HazardIncludeWhenResolverTest` were verified to need no change (self-contained mock/fixture data) and were left untouched.

## Issues Encountered

One pre-existing, unrelated test failure was found and left alone per the Scope Boundary rule: `tests/Unit/Services/RamsBuilderServiceTest.php::test_reviewedToRisk_case_only_match_renames_display_but_keeps_row_controls` fails on an assertion about control-list preservation for a "Working at height" case-only-rename scenario (fully mocked, unrelated to the restricted-access hazard). Confirmed pre-existing by temporarily reverting `HazardTemplateSeeder.php` to its pre-Task-1 content and re-running the same test method — it failed identically. Logged to `deferred-items.md` rather than fixed, since it is out of this plan's scope.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- RULE-06 is now genuinely satisfied: seeder, fold map (all 3 targets), drift-guard test, and REQUIREMENTS.md/ROADMAP.md text all agree on "Restricted access and ceiling void working" — verified via `grep -rn "Restricted access and ceiling voids"` across `app/`, `database/seeders/`, `resources/`, and `tests/`, with the only remaining matches being intentional historical/provenance docblock comments (2 in `LegacyHazardNameFoldMap.php`'s own docblock, 1 in `RamsComplianceUpgradeService.php`'s explicitly "do not touch" rollback-path comment — pre-existing, out of scope) and self-contained mock/fixture strings in 3 unrelated test files.
- Plan 28-03 (PPE closed-vocabulary fix) can proceed independently — untouched by this plan.
- Plan 28-06 (GATE-06/07 throwing gate) is unaffected — this plan did not touch `ControlTextRuleViolations.php` or `RamsBuilderService.php`.
- No blockers.

## Self-Check: PASSED

- FOUND: database/seeders/HazardTemplateSeeder.php
- FOUND: app/Services/Rams/LegacyHazardNameFoldMap.php
- FOUND: tests/Unit/Services/Rams/LegacyHazardNameFoldMapTest.php
- FOUND: tests/Unit/Services/HazardLibraryServiceTest.php
- FOUND: tests/Feature/Rams/RiskTemplateResolverServiceTest.php
- FOUND: tests/Feature/Rams/ReviewedHazardTieringTest.php
- FOUND commit 0cb6d89
- FOUND commit a6c2fea
- Verification run: `php artisan test --filter=LegacyHazardNameFoldMap` -> 5 passed (23 assertions), exit 0
- Verification run: `php artisan test --filter=ControlTextRuleViolationsTest` -> 20 passed (65 assertions), exit 0

---
*Phase: 28-ppe-ceiling-electrical-boundary-house-rules*
*Completed: 2026-09-06*
