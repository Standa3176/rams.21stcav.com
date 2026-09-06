# Plan 28-08 — Local verification (Task 1 of 2)

**Run:** 2026-09-06, local, `php artisan test` (full suite, unfiltered).
**Status:** ✅ Local half PASSED. ⏸ Task 2 (live production deploy) is OUTSTANDING — human action.

---

## Full suite result

```
Tests: 2442 passed, 2 failed, 6 skipped, 10 warnings, 2 deprecated (9638 assertions)
Duration: 492.65s
```

**Both failures are pre-existing and unrelated to Phase 28. Neither was assumed — both verified.**

### 1. `RamsBuilderServiceTest::test_reviewedToRisk_case_only_match_renames_display_but_keeps_row_controls` (`:561`)

Verified by reverting Phase 28's two candidate source files to the pre-phase commit and re-running:

```bash
git checkout c27bfec -- app/Services/Rams/ControlTextRuleViolations.php app/Services/RamsBuilderService.php
php artisan test --filter='test_reviewedToRisk_case_only_match_renames_display_but_keeps_row_controls'
# -> still fails
git checkout HEAD -- <same files>   # restored; tree confirmed clean
```

Plans 28-02/03/04 each cited this as pre-existing, but 28-02's evidence was a *seeder* revert,
which would not have isolated Plan 28-01's new detectors. The check above does. Logged in
`deferred-items.md`.

### 2. `QueueRecoverCommandTest` (`:163`)

`git diff c27bfec..HEAD --name-only | grep -ci queue` → **0**. Phase 28 touched no queue code at
all. The test's own inline comment records it as a deliberately deferred item from a prior quick
task ("is a production change and out of scope for this quick task — see SUMMARY.md").

---

## What Phase 28 changed (13 source files + 1 migration + 14 test files)

`ProjectPackageReviewController` · `RamsController` · `RamsReviewController` · `DocxBuilderService` ·
`ControlTextRuleViolations` · `LegacyHazardNameFoldMap` · `PpeVocabularyFoldMap` (new) ·
`RamsComplianceUpgradeService` · `RamsDisplayPatchService` · `RamsBuilderService` ·
`RamsDataBuilderService` · `RiskMatrixService` · `RiskTemplateResolverService` ·
`config/rams_tier1.php` · `HazardTemplateSeeder` · both pdf blades ·
`2026_09_05_180000_backfill_ppe_ffp2_and_electrical_exclusion_bullet.php`

---

## ⏸ Task 2 — live production deploy, NOT yet done

The migration shows `Pending` in `migrate:status`. Nothing has been deployed.

Follow `28-08-PLAN.md`'s `<mandatory_deploy_sequence>` exactly. The order is not free choice:
`config/rams_tier1.php:98` defaults the gate to `true`, and Save Review calls `upgrade()` on
`generated_data` directly — bypassing tier-1 correction and the fold map — so arming before
migrating would block Save Review on 54/54 documents (FFP2) and 52/54 (hazard name).

Ship flag-off → migrate → re-measure to zero → arm → regenerate 21CQ30960 through the DOCX path.

**Phase 28 cannot be closed until that runs and `28-08-SUMMARY.md` records the result.**
