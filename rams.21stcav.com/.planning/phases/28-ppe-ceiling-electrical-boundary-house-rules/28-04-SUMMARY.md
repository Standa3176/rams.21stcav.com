---
phase: 28-ppe-ceiling-electrical-boundary-house-rules
plan: 04
subsystem: rams
tags: [php, laravel, phpunit, ppe, ffp2, ffp3, static-analysis]

# Dependency graph
requires:
  - phase: 28-03
    provides: "RiskTemplateResolverService's two fresh-generation FFP3 sites — this plan explicitly excludes them from its 'remaining 12', per its own critical_constraints"
provides:
  - "The remaining 12 of the 14 live FFP2 source sites RULE-01/GATE-06 requires, all now FFP3"
  - "FfpTwoBannedFromSourceTest — repo-wide static ban on the literal token FFP2, verified non-vacuous"
affects: ["28-06 (GATE-06/07 throwing gate — RULE-01's genuine completion still depends on it)"]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Repo-wide static source-ban test, copying HazardInjectionPathsRemovedGuardTest's recursive phpFilesUnder() glob + self-exclusion shape, extended with a file-path EXCLUDED_FILES allowlist rather than a string-content allowlist"

key-files:
  created:
    - tests/Feature/Rams/FfpTwoBannedFromSourceTest.php
  modified:
    - app/Http/Controllers/ProjectPackageReviewController.php
    - app/Http/Controllers/RamsReviewController.php
    - app/Services/DocxBuilderService.php
    - app/Services/Rams/RamsComplianceUpgradeService.php
    - app/Services/RiskMatrixService.php
    - resources/views/pdf/rams-v2.blade.php
    - resources/views/pdf/rams.blade.php

key-decisions:
  - "RiskMatrixService.php:133's 'FFP2 or FFP3' hedge was rewritten to drop the hedge entirely ('Wear FFP3 dust masks...'), not just token-replaced — per the plan's explicit instruction that the hedge itself (offering FFP2 as an acceptable alternative) is the defect, not merely the bare token."
  - "Extended the static ban test's EXCLUDED_FILES list beyond the plan's originally-specified 5 backup files to also exclude ControlTextRuleViolations.php, PpeVocabularyFoldMap.php, and their 3 direct test files (Rule 3 fix — see Deviations)."

requirements-completed: []  # RULE-01 deliberately left [ ] Pending — genuinely complete only once Plan 28-06 ships the throwing GATE-06 gate, per this plan's own success_criteria

# Metrics
duration: ~35min
completed: 2026-09-06
---

# Phase 28 Plan 04: Remaining 12 Live FFP2 Sites + Repo-Wide Static Ban Summary

**Fixed the last 12 of RULE-01's 14 live FFP2 source sites (both PPE picklists, the live DOCX COSHH boilerplate, 4 hardcoded-controls array entries, the RiskMatrixService dead-code hedge, and both PDF blade templates), and added `FfpTwoBannedFromSourceTest` — a repo-wide static scan that fails the build the moment the literal token FFP2 reappears in any non-backup, non-ban-mechanism source file, verified non-vacuous by a deliberate reintroduce-and-revert.**

## Performance

- **Duration:** ~35 min
- **Completed:** 2026-09-06
- **Tasks:** 2 planned (both `type="auto"`)
- **Files modified:** 8 (7 modified, 1 created) — matches the plan's `files_modified` list exactly (`RamsComplianceUpgradeService.php` absorbed all 4 of its sites in one file-touch)

## Accomplishments

- Re-grepped the live-site inventory before starting (per critical_constraints) and confirmed exactly 12 non-backup FFP2 sites remained after Plan 28-03's `RiskTemplateResolverService` fix — matching the plan's list with no discrepancy.
- Fixed both `PPE_OPTIONS` picklist consts (`ProjectPackageReviewController.php:33`, `RamsReviewController.php:42`): `'Dust Mask (FFP2)'` → `'Dust Mask (FFP3)'`.
- Fixed `DocxBuilderService.php:2038`'s live `$coshhBoilerplate` COSHH entry (the live DOCX renderer, not just the PDF blade).
- Fixed all 4 `RamsComplianceUpgradeService.php` sites: `addPpeMatrix()`'s "Drilling / cutting / fixing" and "Working in ceiling voids" task-row PPE (`:349`, `:361`), and `addProjectSpecificRisks()`'s two hardcoded controls (`:747`, `:796`).
- Fixed `RiskMatrixService.php:133`'s dead-code hedge — dropped "or FFP3" entirely rather than leaving a hedge that a diff-skim could misread as compliant, per the plan's explicit trap warning. Confirmed zero callers (`grep -c RiskMatrixService app/Services/RiskMatrixService.php` → 1, own declaration only); fixed for source hygiene, no live-path proof attempted.
- Fixed both occurrences in each of `resources/views/pdf/rams-v2.blade.php` (`:540`, `:1964`) and `resources/views/pdf/rams.blade.php` (`:498`, `:1903`) — identical wording in both, not diverged.
- Left `config/rams_tier1.php` completely untouched (`git diff --stat` shows no change) — `:128` was already FFP3 from an earlier phase, `:286`'s fire-stop claim is Phase 31's per D-09 and was never approached.
- Created `tests/Feature/Rams/FfpTwoBannedFromSourceTest.php`, copying `HazardInjectionPathsRemovedGuardTest`'s recursive `phpFilesUnder()` glob and self-exclusion shape, scanning `app/`, `resources/views/`, `config/`, `database/seeders/`, `tests/` for the bare token `FFP2`.
- Verified the test is non-vacuous: temporarily reintroduced `'Dust Mask (FFP2)'` into `RamsReviewController.php`, confirmed the test failed with the exact offender line named in the assertion message, then reverted (confirmed `git diff` empty afterward — no residual change).
- Ran the full `--filter=Rams` suite (622 passed, 1 pre-existing failure, 2450 assertions) plus a standalone `--filter=FfpTwoBannedFromSourceTest` pass — no new failures introduced.

## Task Commits

1. **Task 1: Fix the 12 remaining live FFP2 source sites** - `0ad26b4` (fix)
2. **Task 2: Repo-wide static FFP2 ban test** - `ac805f4` (test)

**Plan metadata:** pending (this commit)

## Files Created/Modified
- `app/Http/Controllers/ProjectPackageReviewController.php` - `PPE_OPTIONS` const, FFP2→FFP3
- `app/Http/Controllers/RamsReviewController.php` - `PPE_OPTIONS` const, FFP2→FFP3
- `app/Services/DocxBuilderService.php` - `$coshhBoilerplate` drilling-dust entry, FFP2→FFP3
- `app/Services/Rams/RamsComplianceUpgradeService.php` - 4 sites across `addPpeMatrix()`/`addProjectSpecificRisks()`, FFP2→FFP3
- `app/Services/RiskMatrixService.php` - dropped the "FFP2 or FFP3" hedge, now FFP3-only (dead code, zero callers)
- `resources/views/pdf/rams-v2.blade.php` - 2 sites, FFP2→FFP3
- `resources/views/pdf/rams.blade.php` - 2 sites, FFP2→FFP3
- `tests/Feature/Rams/FfpTwoBannedFromSourceTest.php` - new repo-wide static ban test

## Decisions Made
See `key-decisions` in frontmatter. Summarized: the `RiskMatrixService` hedge was rewritten (not token-swapped) to remove the "FFP2 as acceptable alternative" defect itself; the static ban test's exclusion list was widened beyond the plan's original 5-file list to also cover the ban/fold mechanism's own source and its 3 direct test fixtures — see Deviations below for the full reasoning.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking issue] Widened `FfpTwoBannedFromSourceTest`'s exclusion list beyond the plan's 5 backup files**

- **Found during:** Task 2, before writing the test (re-grepped per the plan's own "re-verify, don't trust a stale list" instruction, which the critical_constraints applied explicitly to the 14-site inventory but turned out to apply equally to the test's exclusion list).
- **Issue:** `28-RESEARCH.md` and `28-CONTEXT.md`'s D-04 both asserted "zero occurrences of FFP2 exist anywhere under tests/" and specified only the 5 known backup files as exclusions. That was true at research time (2026-09-05), but Plans 28-01 and 28-03 landed the same day and shipped the FFP2-detection/fold infrastructure this milestone actually needs: `ControlTextRuleViolations::detectFfp2()` (a regex literally containing the token `FFP2`, plus docblock prose naming it), `PpeVocabularyFoldMap` (a lowercase `'dust mask (ffp2)'` lookup key, plus docblock prose), and three test files (`ControlTextRuleViolationsTest.php`, `PpeVocabularyFoldMapTest.php`, `PpeFfp2RenderRegressionTest.php`) whose fixtures and test-method names legitimately reference the literal token `FFP2` to prove the detector/fold-map correctly catches and replaces it. Writing the test exactly as specified (5-file exclusion list only) would have failed immediately against the current tree — not because of a reintroduced defect, but because the very mechanism this milestone built needs to say the word it's banning.
- **Fix:** Extended `EXCLUDED_FILES` with these 5 additional paths, kept narrow and commented with the same rationale as the backup-file exclusion (`app/Services/Rams/ControlTextRuleViolations.php`, `app/Services/Rams/PpeVocabularyFoldMap.php`, `tests/Unit/Services/Rams/ControlTextRuleViolationsTest.php`, `tests/Unit/Services/Rams/PpeVocabularyFoldMapTest.php`, `tests/Feature/Rams/PpeFfp2RenderRegressionTest.php`). Confirmed every lowercase `ffp2` occurrence in the tree also falls inside these same 5 files, so no separate case-insensitive forbidden-string entry was needed (the plan's own contingency instruction for that case). Every other file under the 5 scanned roots — including all document-content sites and any future 15th site — is still fully scanned and caught.
- **Files modified:** `tests/Feature/Rams/FfpTwoBannedFromSourceTest.php` (new file — the exclusion list was written correctly the first time, not retrofitted).
- **Commit:** `ac805f4`

## Issues Encountered

None beyond the deviation above. The one pre-existing test failure encountered during the full `--filter=Rams` verification run (`RamsBuilderServiceTest::test_reviewedToRisk_case_only_match_renames_display_but_keeps_row_controls`) matches the plan's documented "Known pre-existing failure" exactly (logged in `deferred-items.md` by Plan 28-02) — confirmed identical, left untouched.

## User Setup Required

None - no external service configuration required.

## Verification Evidence

### Task 1 acceptance criterion (as specified in the plan)
```
$ grep -rl "FFP2" app resources/views config database/seeders tests --include=*.php 2>/dev/null | grep -v "resources/views.backup-260430" | grep -v "keep boarder" | grep -v "keep-borders"
app/Services/Rams/ControlTextRuleViolations.php
app/Services/Rams/PpeVocabularyFoldMap.php
tests/Feature/Rams/PpeFfp2RenderRegressionTest.php
tests/Unit/Services/Rams/ControlTextRuleViolationsTest.php
tests/Unit/Services/Rams/PpeVocabularyFoldMapTest.php
```
These 5 hits are exactly the ban/fold-mechanism files identified in the Deviations section above (Plan 28-01/28-03 infrastructure) — zero document-content sites remain outside the backup exclusion.

### Required verification grep (`app/ config/ database/ resources/views/` only, per the plan's `<verification>` block)
```
$ grep -rn "FFP2" app/ config/ database/ resources/views/ --include=*.php
app/Services/Rams/ControlTextRuleViolations.php:260:     * RULE-01 — the bare token FFP2 (any casing, any surrounding context).
app/Services/Rams/ControlTextRuleViolations.php:261:     * There is no legitimate sentence containing the literal token FFP2 —
app/Services/Rams/ControlTextRuleViolations.php:265:     * "FFP2 or FFP3" hedge (RiskMatrixService.php:133): stating FFP2 as an
app/Services/Rams/ControlTextRuleViolations.php:270:        return (bool) preg_match('/\bFFP2\b/i', $control);
app/Services/Rams/PpeVocabularyFoldMap.php:20: * a stored `Dust Mask (FFP2)` string would survive forever on every future
app/Services/Rams/PpeVocabularyFoldMap.php:31: * whole array of items, most of which are not FFP2-related (see
app/Services/Rams/PpeVocabularyFoldMap.php:78:     * map's OUTPUT side can never re-introduce the banned FFP2 token,
resources/views/pdf/rams.blade - keep boarder.php:226:        'Dust Mask (FFP2)'             => ['Dust-generating activities',         'EN 149'],
resources/views/pdf/rams.blade-keep-borders.php:226:        'Dust Mask (FFP2)'             => ['Dust-generating activities',         'EN 149'],
```
**Note on this output vs. the plan's literal expectation:** the plan's `<verification>` section states this command should show "the ONLY remaining hits are the 5 excluded backup-file paths." That expectation predates Plans 28-01/28-03 (which shipped the FFP2-detection/fold infrastructure into `app/Services/Rams/` the same day the plan was written). The actual output above shows the 2 backup-file hits the plan expected, PLUS 2 additional files (`ControlTextRuleViolations.php`, `PpeVocabularyFoldMap.php`) that are the ban/fold mechanism's own source, not reintroduced document content — the same category of exclusion, just not yet reflected in the plan text when it was written. Zero hits appear in any file that renders or persists document content.

### Static ban test
```
$ php artisan test --filter=FfpTwoBannedFromSourceTest
PASS  Tests\Feature\Rams\FfpTwoBannedFromSourceTest
✓ ffp2 does not appear in any non backup source file

Tests: 1 passed (1 assertions)
```
Non-vacuity confirmed: reintroducing `'Dust Mask (FFP2)'` into `RamsReviewController.php` made this test fail with `RamsReviewController.php contains 'FFP2'` as the named offender; reverting restored the pass with an empty `git diff`.

### Full Rams suite (no new failures)
```
$ php artisan test --filter=Rams
Tests: 2 deprecated, 1 failed, 622 passed (2450 assertions)
```
The 1 failure is `RamsBuilderServiceTest::test_reviewedToRisk_case_only_match_renames_display_but_keeps_row_controls` — the plan's documented pre-existing failure (deferred-items.md, Plan 28-02), unrelated to this plan's changes, confirmed identical and left alone.

## Next Phase Readiness

- 12 of the 14 live FFP2 sites this plan owned are now FFP3; combined with Plan 28-03's 2 sites, all 14 live sites RULE-01/D-04 inventoried are fixed.
- `FfpTwoBannedFromSourceTest` is now live and will fail the build if FFP2 reappears anywhere in `app/`, `resources/views/`, `config/`, `database/seeders/`, or `tests/` outside the 7-file exclusion list (5 backup/frozen files + 2 ban-mechanism source files, with their 3 direct test fixtures also excluded).
- **RULE-01 is deliberately left `[ ]` Pending in `REQUIREMENTS.md`**, per this plan's own success criteria — it is genuinely complete only once Plan 28-06 ships GATE-06's throwing re-check (the runtime gate this static test complements, not replaces).
- No blockers for Plan 28-06.

## Self-Check: PASSED

- FOUND: tests/Feature/Rams/FfpTwoBannedFromSourceTest.php
- FOUND: app/Http/Controllers/ProjectPackageReviewController.php (Dust Mask (FFP3))
- FOUND: app/Http/Controllers/RamsReviewController.php (Dust Mask (FFP3))
- FOUND: app/Services/DocxBuilderService.php (FFP3 dust masks)
- FOUND: app/Services/Rams/RamsComplianceUpgradeService.php (4x FFP3)
- FOUND: app/Services/RiskMatrixService.php (Wear FFP3 dust masks)
- FOUND: resources/views/pdf/rams-v2.blade.php (2x FFP3)
- FOUND: resources/views/pdf/rams.blade.php (2x FFP3)
- FOUND commit 0ad26b4
- FOUND commit ac805f4
- Verification run: `php artisan test --filter=FfpTwoBannedFromSourceTest` -> 1 passed (1 assertion), exit 0
- Verification run: `php artisan test --filter=Rams` -> 622 passed / 1 pre-existing failure (2450 assertions)
- Verification run: `grep -rn "FFP2" app/ config/ database/ resources/views/ --include=*.php` -> 9 lines, all inside the 7-file exclusion category (pasted above)

---
*Phase: 28-ppe-ceiling-electrical-boundary-house-rules*
*Completed: 2026-09-06*
