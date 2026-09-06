---
phase: 28-ppe-ceiling-electrical-boundary-house-rules
plan: 07
subsystem: database
tags: [laravel, migration, php, rams, ffp2, ffp3, confined-space, exclusions, backfill]

# Dependency graph
requires:
  - phase: 28-03
    provides: "PpeVocabularyFoldMap::canonical()/canonicalAll() — reused directly for the PPE surface, not re-implemented"
  - phase: 28-05
    provides: "RamsDisplayPatchService's RULE-09 exclusions bullet (verbatim string copied for the backfill)"
  - phase: 28-01
    provides: "ControlTextRuleViolations::detectAll() 'ffp2' classifier — reused for the hazard-controls surface"
  - phase: 28-02
    provides: "LegacyHazardNameFoldMap — reused read-only for the hazard-controls library lookup's fold step"
provides:
  - "One-time backfill migration over rams_documents.reviewed_data AND generated_data: PPE/ppe_matrix FFP2 fold, hazard-controls FFP2 replace via current library text, hazard-name 'Confined Spaces' -> canonical replace, RULE-09 exclusions bullet append"
  - "RULE-09 genuinely Complete — the already-reviewed-document gap Plan 28-05 explicitly left open is now closed"
affects: ["28-08 (deploy sequence: ship gate-off -> run this migration on production -> re-measure to zero -> arm the gate)"]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Data-only backfill migration copying Plan 27-08's exact shape (chunkById(100), idempotent, deliberate no-op down()), extended to operate over TWO JSON columns in one chunked pass instead of one"
    - "Library-controls lookup for a batch script deliberately narrowed to fold+exact match only (LegacyHazardNameFoldMap::canonicalName() then case-insensitive exact name match against global hazard_templates) — the fuzzy substring/shared-word tiers of HazardLibraryService::fuzzyMatch() are NOT reimplemented in a migration, fail-closed on a miss"

key-files:
  created:
    - database/migrations/2026_09_05_180000_backfill_ppe_ffp2_and_electrical_exclusion_bullet.php
    - tests/Feature/Rams/BackfillPpeFfp2AndElectricalExclusionBulletMigrationTest.php
  modified:
    - tests/Feature/Rams/FfpTwoBannedFromSourceTest.php

key-decisions:
  - "Task 1's production measurement checkpoint was already satisfied before this execution session (run 2026-09-06 by a prior agent/user as `stcav` on rams.21stcav.com, read-only) — recorded in 28-07-MEASUREMENT.md. This plan executed Task 2 only, against those real numbers, per the orchestrator's explicit instruction."
  - "Migration scope was expanded beyond the original plan text (ppe + exclusions only) to four surfaces, per the plan's own <scope_expanded_2026_09_06> block: ppe/ppe_matrix fold, hazard-controls FFP2 replace, hazard-NAME 'Confined Spaces' replace (new scope, supersedes 28-02-PLAN.md's no-title-backfill decision for this one string), and both reviewed_data AND generated_data (not reviewed_data alone)."
  - "Hazard-controls FFP2 fix is scoped to the 'ffp2' ControlTextRuleViolations key only — not 'any violation' (which would also catch kg_threshold/size_conditional_lift, an unmeasured, out-of-scope surface for this migration) and not 'confined_space' (measured production count for an affirmative claim in control text is 0 — no behaviour invented for an unmeasured case)."
  - "Library-controls lookup for the hazard-controls fix deliberately reimplements only the first two tiers of HazardLibraryService::fuzzyMatch() (fold, then exact match) — the substring/shared-word fuzzy tiers are NOT copied into a migration; a hazard whose name neither folds nor exact-matches a global hazard_templates row is left untouched, fail-closed."
  - "ppe_matrix is included in the migration despite RamsComplianceUpgradeService::addPpeMatrix() unconditionally rebuilding it (already FFP3) on every upgrade() call — because the production measurement found 54/54 documents currently affected (no document has been re-saved/regenerated since the 28-03/28-04 FFP3 fixes landed, all on the same day), and the migration's own scope table explicitly names ppe_matrix as an in-scope surface."
  - "tests/Feature/Rams/FfpTwoBannedFromSourceTest.php's EXCLUDED_FILES allow-list was extended with this plan's new test file (Rule 1 auto-fix — a pre-existing repo-wide guard flagged a legitimate new FFP2 fixture as a violation, the same category every prior Phase 28 FFP2-fixture test file already occupies on that same list). The guard test itself is not owned by a `critical_constraints` forbidden-file entry — only extending its allow-list, not its logic."

requirements-completed: [RULE-01, RULE-09]

# Metrics
duration: ~40min
completed: 2026-09-06
---

# Phase 28 Plan 07: Backfill FFP2/Hazard-Name/RULE-09 Exclusion Migration Summary

**New chunked, idempotent migration patches all 54 production `rams_documents` rows across both `reviewed_data` and `generated_data` — folding stale FFP2 PPE strings to FFP3, replacing FFP2-carrying hazard controls with current library text, renaming the legacy "Confined Spaces" hazard label, and appending RULE-09's electrical-boundary exclusion bullet where the document's exclusions array was already set — closing the already-reviewed-document gap Plan 28-05 explicitly left open.**

## Performance

- **Duration:** ~40 min
- **Completed:** 2026-09-06
- **Tasks:** 1 of 2 (Task 1's checkpoint was pre-satisfied — see below; only Task 2 executed this session)
- **Files modified:** 3 (2 created, 1 modified)

## Task 1 status (not re-run this session)

Task 1 (`checkpoint:human-action`, production measurement) was already run and satisfied on
2026-09-06 against `rams.21stcav.com`, logged in as `stcav`, read-only, before this execution
session began. Full results live in `28-07-MEASUREMENT.md`. Recorded here per the plan's
acceptance criterion:

| Surface | Docs affected | Self-heals on regeneration? |
|---|---:|---|
| `FFP2` in `ppe` / `ppe_matrix` | **54 / 54** | ❌ never |
| `FFP2` in hazard `controls[]` | **36** | ⚠️ only on full regeneration (tier-1), not Save Review |
| Hazard **NAME** = `Confined Spaces` | **52** (99 occurrences, 1 distinct string) | ⚠️ only on full regeneration (fold map), not Save Review |
| `exclusions` already set (`isset`) | **32** | ❌ never |
| Affirmative confined-space claim in control **text** | **0** | — (nothing to fix) |

Every one of the 54 production `RamsDocument` rows carries at least the PPE defect (100%), which
is why this migration was written as unconditional rather than gated behind a further
re-measurement.

## Accomplishments

- `database/migrations/2026_09_05_180000_backfill_ppe_ffp2_and_electrical_exclusion_bullet.php`: chunked (`chunkById(100)`), idempotent migration fixing four independently-counted surfaces per row, over BOTH `reviewed_data` and `generated_data`:
  - (a) `ppe` and every `ppe_matrix[*]['ppe']` element folded through `PpeVocabularyFoldMap::canonicalAll()` (Plan 28-03's map — reused directly, not re-implemented).
  - (b) Hazard `controls`/`control_measures` (key name differs by column — `generated_data` uses `controls`, `reviewed_data` uses `control_measures`, both checked) replaced with the current global `hazard_templates` library text, ONLY when `ControlTextRuleViolations::detectAll()` flags the `ffp2` key specifically — classification reused from the existing single choke point, never duplicated.
  - (c) Hazard name exactly `"Confined Spaces"` deterministically replaced with `"Restricted access and ceiling void working"` — the same target `LegacyHazardNameFoldMap` already resolves to, so this migration and a full regeneration produce identical output.
  - (d) RULE-09's exclusions bullet (copied verbatim from `RamsDisplayPatchService.php`) appended to every already-`isset` `exclusions` array lacking it, via an `in_array(..., true)` exact-string guard — never touches a document whose `exclusions` key is unset (that document gets the bullet for free from the existing `!isset` seed on its next render).
  - `down()` is a deliberate no-op, documented in the class docblock, for the same reason as the Plan 27-08 precedent it copies: none of the four checks can distinguish a row this migration changed from one an engineer had already corrected by hand.
- `tests/Feature/Rams/BackfillPpeFfp2AndElectricalExclusionBulletMigrationTest.php`: 5 new feature tests, all against a real `rams_documents` row (via `RamsDocument::factory()`) and a real global `HazardTemplate` fixture:
  1. `test_backfill_fixes_all_four_surfaces_in_both_columns` — proves all four surfaces fire correctly in BOTH `reviewed_data` and `generated_data` on the same document.
  2. `test_backfill_is_idempotent` — runs the migration twice, asserts `reviewed_data`/`generated_data` are byte-identical (`assertSame`) after the second run.
  3. `test_backfill_never_touches_a_document_with_no_exclusions_key` — proves the `!isset` document is left alone (per plan's explicit exclusion).
  4. `test_backfill_never_removes_an_engineer_authored_exclusion` — proves append-only, non-destructive behaviour.
  5. `test_backfill_never_renames_a_hazard_with_no_library_match` — proves the fail-closed behaviour for a hazard name that neither folds nor exact-matches any global library entry (deliberate narrowing, documented in the migration's own docblock).
- The migration's own fixture legitimately contains the literal string `"FFP2"` (as stale data to be corrected), which tripped the pre-existing `FfpTwoBannedFromSourceTest` repo-wide static ban. Extended that test's `EXCLUDED_FILES` allow-list with the one new test file path, following the exact convention every prior Phase 28 FFP2-fixture test (`PpeFfp2RenderRegressionTest`, `Ffp2ConfinedSpaceSaveReviewGateTest`, `Ffp2ConfinedSpaceDualPathGateTest`, etc.) already uses.

## Task Commits

1. **Task 2: Backfill migration + test + static-ban allow-list extension** - `458c8f2` (feat)

**Plan metadata:** pending (this commit)

## Files Created/Modified

- `database/migrations/2026_09_05_180000_backfill_ppe_ffp2_and_electrical_exclusion_bullet.php` - New idempotent, chunked backfill migration (see Accomplishments)
- `tests/Feature/Rams/BackfillPpeFfp2AndElectricalExclusionBulletMigrationTest.php` - New feature test suite, 5 tests, 36 assertions
- `tests/Feature/Rams/FfpTwoBannedFromSourceTest.php` - `EXCLUDED_FILES` const extended by one entry (Rule 1 auto-fix)

## Decisions Made

See `key-decisions` in frontmatter above. Summarized: Task 1 was pre-satisfied and not re-run;
migration scope followed the plan's `<scope_expanded_2026_09_06>` block (four surfaces, both
columns) rather than the original plan text's narrower two-surface/one-column scope; the
hazard-controls fix is deliberately scoped to the `ffp2` violation key only (not `confined_space`,
measured at 0 occurrences, and not other `ControlTextRuleViolations` keys which are out of this
migration's measured scope); the library-controls lookup deliberately reimplements only
`HazardLibraryService::fuzzyMatch()`'s fold+exact tiers, never its fuzzy tiers, fail-closed on a
miss; `ppe_matrix` is included despite technically self-healing via `upgrade()`, because the
production measurement found it 100% affected today.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Static FFP2 source-ban false-positive on this plan's own legitimate test fixture**
- **Found during:** Task 2 verification (`artisan test --filter=Rams`)
- **Issue:** `tests/Feature/Rams/FfpTwoBannedFromSourceTest.php` (Plan 28-04's repo-wide static ban on the literal token `FFP2`) flagged the new migration test file, because its fixture data deliberately contains the string `"Dust Mask (FFP2)"` and an FFP2 hazard-control line — the exact stale data the migration exists to correct.
- **Fix:** Added the new test file's path to `FfpTwoBannedFromSourceTest::EXCLUDED_FILES`, with a comment explaining why (same category as five other pre-existing FFP2-fixture test files already on that list).
- **Files modified:** `tests/Feature/Rams/FfpTwoBannedFromSourceTest.php` (+6 lines)
- **Verification:** `artisan test --filter='FfpTwoBannedFromSourceTest|BackfillPpeFfp2AndElectricalExclusionBulletMigrationTest'` → 6 passed, 36 assertions, exit 0.
- **Committed in:** `458c8f2` (same commit as Task 2 — not a separate commit, since it was discovered and fixed before Task 2's single commit was made)

---

**Total deviations:** 1 auto-fixed (Rule 1, test-only, no application code touched)
**Impact on plan:** No scope creep — extending a pre-existing allow-list by one entry is exactly the mechanism that guard test was designed for; every other Phase 28 FFP2-fixture test file already required the same one-line addition when it was created.

## Issues Encountered

None beyond the deviation above.

## User Setup Required

None - no external service configuration required. The migration is not yet run against
production; Plan 28-08 owns the deploy sequence (deploy with the gate disabled, run this
migration, re-measure to confirm zero, then arm the gate).

## Verification

- `php artisan migrate:status | grep backfill_ppe_ffp2_and_electrical_exclusion_bullet` → listed, `Pending` locally (expected — local dev DB is empty of `RamsDocument` rows; this migration has not yet been run against production, which is Plan 28-08's job).
- `artisan test --filter=BackfillPpeFfp2AndElectricalExclusionBulletMigrationTest` → **5 passed, 36 assertions**, exit 0.
- `artisan test --filter='FfpTwoBannedFromSourceTest|BackfillPpeFfp2AndElectricalExclusionBulletMigrationTest'` → **6 passed, 36 assertions**, exit 0 (confirms the deviation fix).
- `artisan test --filter=Rams` (full Rams suite) → **649 passed, 1 failed, 2537 assertions**, 97.06s.
  - **Known pre-existing failure, confirmed unrelated:** `RamsBuilderServiceTest::test_reviewedToRisk_case_only_match_renames_display_but_keeps_row_controls` (`:561`) — already verified genuinely pre-existing by Plan 28-06 (reverted 28-01/28-03 source to `c27bfec`, re-ran, still failed there; files restored). Left alone per this plan's explicit verification instruction.
  - **Count reconciliation:** baseline was 644 passed / 1 failed before this plan (per plan's `<verification>` block); this session added exactly 5 new tests (the new migration test file), giving 649 passed / 1 failed — the arithmetic matches exactly, confirming no other test regressed and no test was silently skipped.

## Next Phase Readiness

- **RULE-09 can now be judged genuinely Complete.** Plan 28-05 shipped the forward path (the
  `!isset` seed reaching every never-reviewed document's next render) but explicitly, by design,
  left the already-reviewed-document gap open — any document that had been through even one prior
  Save Review had `reviewed_data['exclusions']` permanently `isset`, so the seed could never fire
  for it again. This plan's surface (d) is exactly that remaining gap: it appends the identical
  verbatim bullet to every already-`isset` exclusions array (32 production documents) that doesn't
  already carry it, without disturbing any engineer-authored exclusion. Once Plan 28-08 runs this
  migration against production, every `RamsDocument` — regardless of review history — will state
  the electrical scope boundary. `REQUIREMENTS.md`'s RULE-09 checkbox should be flipped from
  Pending/PARTIAL to Complete once 28-08 confirms the production run.
- **RULE-01/RULE-06 (hazard-name mislabel)** are strengthened further: RULE-01 was already marked
  Complete by Plan 28-06's three-layer guarantee (static ban, tier-1 auto-correct, throwing gate),
  and this migration additionally clears the historical PPE/controls/name debt those layers
  guarantee will never reaccumulate — closing the gap between "future writes are safe" and
  "already-persisted data is clean."
- **Gate is NOT armed by this plan** — `config/rams_tier1.php`'s `ffp2_confined_space_gate_enabled`
  and `.env`'s `RAMS_PPE_CEILING_ELECTRICAL_GATE` were not touched. Plan 28-08 owns the required
  deploy sequence: (1) deploy with the gate disabled, (2) run this migration against production,
  (3) re-run the Pass-1/Pass-2 production scans and require `ppe_ffp2 = 0` and
  `hazard_NAME_confined = 0`, (4) only then arm the gate.
- No blockers.

## Self-Check: PASSED

- FOUND: database/migrations/2026_09_05_180000_backfill_ppe_ffp2_and_electrical_exclusion_bullet.php
- FOUND: tests/Feature/Rams/BackfillPpeFfp2AndElectricalExclusionBulletMigrationTest.php
- FOUND: tests/Feature/Rams/FfpTwoBannedFromSourceTest.php (modified, +6 lines confirmed in `git show --stat`)
- FOUND commit `458c8f2`
- Verification run: `artisan test --filter=BackfillPpeFfp2AndElectricalExclusionBulletMigrationTest` → 5 passed (36 assertions), exit 0
- Verification run: `artisan test --filter='FfpTwoBannedFromSourceTest|BackfillPpeFfp2AndElectricalExclusionBulletMigrationTest'` → 6 passed (36 assertions), exit 0
- Verification run: `artisan test --filter=Rams` → 649 passed, 1 failed (2537 assertions), 97.06s — the 1 failure matches the documented pre-existing failure exactly

---
*Phase: 28-ppe-ceiling-electrical-boundary-house-rules*
*Completed: 2026-09-06*
