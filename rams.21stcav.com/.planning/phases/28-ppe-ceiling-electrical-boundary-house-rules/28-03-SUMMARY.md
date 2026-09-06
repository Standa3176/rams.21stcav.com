---
phase: 28-ppe-ceiling-electrical-boundary-house-rules
plan: 03
subsystem: rams
tags: [php, laravel, phpunit, ppe, ffp2, ffp3, closed-vocabulary]

# Dependency graph
requires:
  - phase: 26-08
    provides: "LegacyHazardNameFoldMap single choke-point/drift-guard test shape, copied for this plan's closed-vocabulary PPE surface"
provides:
  - "PpeVocabularyFoldMap — closed-vocabulary string-replace map folding 'Dust Mask (FFP2)' -> 'Dust Mask (FFP3)', wired into both persisted-data PPE call sites"
  - "RiskTemplateResolverService's two fresh-generation PPE_ACTIVITY_MAP/buildPpe() sites now emit FFP3 directly, closing 2 of the 14 live FFP2 sites research inventoried"
  - "End-to-end regression proof (real buildFromReview() entry point) that a stored reviewed_data['ppe'] FFP2 string renders as FFP3 on next regeneration"
affects: ["28-04 (remaining 12 FFP2 source sites)", "28-06 (GATE-06/07 throwing gate)"]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Closed-vocabulary fold map: an all-static class with a MAP const + resolver + all() test accessor, mirroring LegacyHazardNameFoldMap's shape, but with a miss returning the ORIGINAL input unchanged (never null) since it is applied to a whole array of mostly-unrelated items rather than a single fuzzy-resolved name"
    - "Fresh-generation code (constants/literals that build a NEW array on every call) gets a direct source-string fix; only ALREADY-PERSISTED stored data gets routed through the fold map — the same distinction research Q4 drew between RiskTemplateResolverService's PPE_ACTIVITY_MAP/buildPpe() and RamsBuilderService/RamsDataBuilderService's stored-ppe merge sites"

key-files:
  created:
    - app/Services/Rams/PpeVocabularyFoldMap.php
    - tests/Unit/Services/Rams/PpeVocabularyFoldMapTest.php
    - tests/Feature/Rams/PpeFfp2RenderRegressionTest.php
  modified:
    - app/Services/RamsBuilderService.php
    - app/Services/RamsDataBuilderService.php
    - app/Services/RiskTemplateResolverService.php

key-decisions:
  - "Closed-vocabulary replace map, not a ControlTextRuleViolations detector — reviewed_data['ppe'] is a fixed pick-list (checkboxes), never free engineer prose, so the free-text detector class this phase's other plans extend is the wrong tool for this surface (research Q4's explicit finding)."
  - "canonical() returns the input UNCHANGED (not null) on a miss — the one deliberate divergence from LegacyHazardNameFoldMap::canonicalName()'s shape, required because this map is applied to a whole PPE array where most items are not FFP2-related; returning null for an unmapped item would silently drop it from the engineer's picked list (see threat register T-28-03-01)."
  - "RiskTemplateResolverService.php:44/106 edited directly at the source string, NOT routed through PpeVocabularyFoldMap — they are code constants generating a fresh PPE array on every call, so there is no stored-data staleness to remediate; routing them through the map would be an unnecessary indirection on fresh-generation code."
  - "Verified (not merely trusted from the plan) that RamsDataBuilderService::mergeRiskData()'s separate ppe merge (:165-167, survey-derived risk) does not need its own fold-map wrap: its output feeds INTO mergePpe() as the basePpe argument before reaching the final generated_data array, so the two call sites the plan specifies are structurally sufficient — not an accidental gap."

requirements-completed: []  # RULE-01 deliberately left Pending — see Next Phase Readiness

# Metrics
duration: ~25min
completed: 2026-09-06
---

# Phase 28 Plan 03: PPE Closed-Vocabulary FFP2→FFP3 Fold Map Summary

**New `PpeVocabularyFoldMap` closed-vocabulary replace class wired into both persisted `reviewed_data['ppe']` call sites (`RamsBuilderService::reviewedToRisk()`, `RamsDataBuilderService::mergePpe()`), plus direct source fixes to `RiskTemplateResolverService`'s two fresh-generation PPE sites — closing research Q4's previously-unscoped gap where a stored `Dust Mask (FFP2)` string had zero rule-scanning anywhere in the pipeline.**

## Performance

- **Duration:** ~25 min
- **Completed:** 2026-09-06
- **Tasks:** 3 planned (all `type="auto"`, Task 1 `tdd="true"`)
- **Files modified:** 6 (3 created, 3 modified) — exactly the plan's `files_modified` list, no additions

## Accomplishments
- `app/Services/Rams/PpeVocabularyFoldMap.php`: new all-static class mirroring `LegacyHazardNameFoldMap`'s shape (`MAP` const, resolver, `all()` test accessor) — `canonical()` lowercase+trims for lookup but returns the caller's original untrimmed string unchanged on a miss (not null, per the plan's explicit divergence from the hazard fold map); `canonicalAll()` maps an array preserving order. 6/6 unit tests pass (6 assertions), including the drift-guard proving no map value ever contains `FFP2` in any casing.
- Wired the map into both stored-data PPE surfaces research found with zero rule-scanning: `RamsBuilderService::reviewedToRisk()`'s ppe assembly line now runs `PpeVocabularyFoldMap::canonicalAll((array) ($rd['ppe'] ?? []))` before the existing `array_map('strval', ...)`/`array_filter(...)`; `RamsDataBuilderService::mergePpe()` folds the merged base+form array the same way before `array_unique()`.
- Fixed `RiskTemplateResolverService`'s two remaining hardcoded FFP2 literals directly at the source string (not routed through the map, since both are fresh-generation code constants, not stored data): `PPE_ACTIVITY_MAP['ceiling_works']` and `buildPpe()`'s drilling-fallback append both now emit `'Dust Mask (FFP3)'`. `grep -c "Dust Mask (FFP2)" app/Services/RiskTemplateResolverService.php` returns 0, confirmed.
- New `tests/Feature/Rams/PpeFfp2RenderRegressionTest.php` proves the persisted-data case end-to-end through the REAL `RamsBuilderService::buildFromReview()` entry point (the same method `BuildRamsDocumentJob::handle()` calls) — not just at the unit level: a `RamsDocument` fixture whose `reviewed_data['ppe']` contains `'Dust Mask (FFP2)'` renders `generated_data['ppe']` containing `'Dust Mask (FFP3)'` and never `'Dust Mask (FFP2)'`. The AI method-statement call is faked via `Http::fake()` (copied from `DisplayLiftDualPathTest::fakeClaudeResponse()`'s exact shape), keeping the test deterministic with no real network call.
- Verified before wiring that `RiskTemplateResolverServiceTest` and `ControlTextRuleViolationsTest` carry zero PPE-string assertions that would break from these edits (`grep -in "dust\|ppe" tests/Feature/Rams/RiskTemplateResolverServiceTest.php` returns nothing) — confirmed no Rule-1 downstream fixups were needed, unlike Plan 28-02's rename.

## Task Commits

Each task was committed atomically:

1. **Task 1: PpeVocabularyFoldMap + drift-guard test** - `b1e998f` (feat)
2. **Task 2: Wire the map into the two persisted-data PPE call sites + fix RiskTemplateResolverService's source** - `f698b48` (fix)
3. **Task 3: End-to-end feature test** - `d81be23` (test)

**Plan metadata:** pending (this commit)

## Files Created/Modified
- `app/Services/Rams/PpeVocabularyFoldMap.php` - New closed-vocabulary fold map (`canonical()`/`canonicalAll()`/`all()`)
- `tests/Unit/Services/Rams/PpeVocabularyFoldMapTest.php` - 6 unit tests covering every behavior case + drift-guard
- `app/Services/RamsBuilderService.php` - `reviewedToRisk()` ppe line wrapped with `PpeVocabularyFoldMap::canonicalAll()`; new `use` import
- `app/Services/RamsDataBuilderService.php` - `mergePpe()` wrapped with `PpeVocabularyFoldMap::canonicalAll()`; new `use` import
- `app/Services/RiskTemplateResolverService.php` - Two literal `'Dust Mask (FFP2)'` strings changed to `'Dust Mask (FFP3)'` (`PPE_ACTIVITY_MAP['ceiling_works']`, `buildPpe()`'s drilling-fallback append)
- `tests/Feature/Rams/PpeFfp2RenderRegressionTest.php` - New end-to-end regression test through the real `buildFromReview()` pipeline

## Decisions Made
See `key-decisions` in frontmatter above — summarized: closed-vocabulary replace map (not a free-text detector) per research Q4; unmapped-item-passes-through-unchanged is deliberate (never silently drop a PPE item); `RiskTemplateResolverService`'s two sites get a direct source fix, not a map indirection, because they generate fresh output on every call; verified (not assumed) that `mergeRiskData()`'s separate ppe merge doesn't need its own wrap since its output flows into `mergePpe()` before reaching `generated_data`.

## Deviations from Plan

None - plan executed exactly as written. No Rule 1/2/3 fixes were needed: no existing test hardcoded a "Dust Mask" or PPE-array literal that broke from these edits, and the one pre-existing test failure encountered during verification (`RamsBuilderServiceTest::test_reviewedToRisk_case_only_match_renames_display_but_keeps_row_controls`) was already logged in `deferred-items.md` by Plan 28-02, confirmed identical, and left untouched per the plan's own "Known pre-existing failure" verification note.

## Issues Encountered

One pre-existing, already-logged test failure was encountered during required verification and left alone: `RamsBuilderServiceTest::test_reviewedToRisk_case_only_match_renames_display_but_keeps_row_controls` (fully mocked, unrelated "Working at height" control-preservation bug). Matches `deferred-items.md`'s Plan-28-02 entry exactly — not caused by this plan, not chased.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- `PpeVocabularyFoldMap` is now the single choke point any future PPE-vocabulary correction should extend — do not hand-copy a second replace anywhere.
- 2 of the ~14 live FFP2 sites research inventoried are now closed (`RiskTemplateResolverService.php:44,106`). **RULE-01 is deliberately left `[ ]` Pending in `REQUIREMENTS.md`** (per this plan's success criteria) — it is genuinely satisfied only once Plan 28-04 fixes the remaining live sites (`RamsComplianceUpgradeService.php`, `resources/views/pdf/rams.blade.php`, `RiskMatrixService.php`, the two `PPE_OPTIONS` controller consts, `DocxBuilderService.php`, `config/rams_tier1.php`'s `:286` line, etc.) and Plan 28-06 ships the throwing GATE-06 gate.
- Plan 28-04 can proceed independently — untouched by this plan; the two `RiskTemplateResolverService` sites this plan closed were explicitly excluded from 28-04's "remaining 12" per this plan's `<critical_constraints>`.
- No blockers.

## Self-Check: PASSED

- FOUND: app/Services/Rams/PpeVocabularyFoldMap.php
- FOUND: tests/Unit/Services/Rams/PpeVocabularyFoldMapTest.php
- FOUND: tests/Feature/Rams/PpeFfp2RenderRegressionTest.php
- FOUND: app/Services/RamsBuilderService.php
- FOUND: app/Services/RamsDataBuilderService.php
- FOUND: app/Services/RiskTemplateResolverService.php
- FOUND commit b1e998f
- FOUND commit f698b48
- FOUND commit d81be23
- Verification run: `php artisan test --filter=PpeVocabularyFoldMapTest` -> 6 passed (6 assertions), exit 0
- Verification run: `php artisan test --filter=PpeFfp2RenderRegressionTest` -> 1 passed (2 assertions), exit 0
- Verification run: `php artisan test --filter=RiskTemplateResolverService` -> 10 passed (49 assertions), exit 0
- Verification run: `grep -c "Dust Mask (FFP2)" app/Services/RiskTemplateResolverService.php` -> 0

---
*Phase: 28-ppe-ceiling-electrical-boundary-house-rules*
*Completed: 2026-09-06*
