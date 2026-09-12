---
phase: 29-cdm-duty-holder-emergency-arrangements
plan: 08
subsystem: rams-cdm-backfill-migration
tags: [rams, cdm, gap-closure, cr-02, latent-defect, tdd, migration]

# Dependency graph
requires:
  - phase: 29-cdm-duty-holder-emergency-arrangements
    plan: 05
    provides: "2026_09_11_180000_backfill_cdm_duty_holder_placeholder.php migration and its BackfillCdmDutyHolderMigrationTest coverage"
provides:
  - "reviewed_data['cdm']'s PD/PC guard now matches only enumerated exact-literal placeholder variants, never a genuine PM-authored sentence merely containing 'To be confirmed' — closes 29-VERIFICATION.md gap 2 / 29-REVIEW.md CR-02"
affects: []

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Replaced a single str_contains() substring guard with an enumerated NAME_EXACT_VARIANTS allowlist matched via in_array(trim($name), ..., true), mirroring the already-correct generated_data['cdm_duty_holders'] exact-literal guard in the same file"

key-files:
  created: []
  modified:
    - database/migrations/2026_09_11_180000_backfill_cdm_duty_holder_placeholder.php
    - tests/Feature/Rams/BackfillCdmDutyHolderMigrationTest.php

key-decisions:
  - "NAME_EXACT_VARIANTS enumerates both 'To be confirmed' (bare) and '[To be confirmed]' (bracketed) — the existing fixtureDocument() test fixture uses the bracketed RAW_PLACEHOLDER for reviewed_data['cdm'] rows, so the bracketed form had to be in the allowlist or test_backfill_patches_both_columns_and_shapes would have regressed into a false no-op."
  - "$name is trimmed before the exact-match comparison (matching the interface spec's 'trimmed for comparison' instruction); $role's existing trim+lowercase was left untouched."
  - "generated_data['cdm_duty_holders'] branch (already exact-literal against RAW_PLACEHOLDER) was not touched — out of this gap's scope per the plan."
  - "Class docblock's 'VALUE-EQUALITY / SUBSTRING GUARD (D-02)' section rewritten to describe the new exact-match behaviour instead of the removed substring behaviour, so the doc no longer contradicts the code."
  - "No data-repair, re-run, or rollback logic added; down() left as the documented no-op — production's 2026-09-11 run reported 0 reviewed_data.cdm replacements, so this is a latent-defect code fix only, per the plan's explicit scope boundary."

patterns-established: []

requirements-completed: [RULE-07, GATE-11]  # Closes 29-VERIFICATION.md gap 2 / CR-02 at the code
  # level so any future run of this migration (staging refresh, fresh seed) cannot discard a
  # genuine PM-authored CDM note; does not touch production data or config/rams_tier1.php.

# Metrics
duration: 15min
completed: 2026-09-12
---

# Phase 29 Plan 08: reviewed_data['cdm'] Exact-Literal Guard Fix Summary

**Tightened the CDM backfill migration's `reviewed_data['cdm']` PD/PC guard from a `str_contains()` substring check to an enumerated exact-literal allowlist, so a genuine PM-authored sentence like "PD to be confirmed once client appoints one" can never again be wholly discarded and replaced by boilerplate.**

## Performance

- **Duration:** ~15 min
- **Started:** 2026-09-12
- **Completed:** 2026-09-12
- **Tasks:** 1/1 complete
- **Files modified:** 2

## Accomplishments

- Replaced the migration's `NAME_SUBSTRING = 'To be confirmed'` constant with `NAME_EXACT_VARIANTS = ['To be confirmed', '[To be confirmed]']`.
- Changed `fixCdmPlaceholder()`'s `reviewed_data['cdm']` PD/PC branch from `str_contains($name, self::NAME_SUBSTRING)` to `in_array(trim($name), self::NAME_EXACT_VARIANTS, true)`.
- Left the `generated_data['cdm_duty_holders']` branch untouched — it already used correct exact-literal equality against `RAW_PLACEHOLDER`.
- Rewrote the class docblock's "VALUE-EQUALITY / SUBSTRING GUARD (D-02)" prose so it accurately describes the new exact-match behaviour instead of the removed substring behaviour.
- Added a new regression test, `test_backfill_never_overwrites_a_pm_authored_sentence_containing_the_substring`, asserting `assertSame($before->reviewed_data, $after->reviewed_data, ...)` for two PM-authored-sentence fixtures (one PD, one PC) that each contain the exact substring `'To be confirmed'` but are not themselves the bare placeholder.
- Confirmed the existing bare-literal replace case (`test_backfill_patches_both_columns_and_shapes`) still passes — its `reviewed_data['cdm']` fixture rows use the bracketed `RAW_PLACEHOLDER`, which is why the bracketed form had to be included in `NAME_EXACT_VARIANTS`.
- Confirmed `test_backfill_never_overwrites_an_engineer_typed_real_name` and `test_backfill_is_idempotent` are unaffected.
- Ran the full RAMS Unit+Feature surface (`tests/Unit/Support/Rams tests/Feature/Rams`) — 326 passed, no cross-file regression.

## Task Commits

1. **Task 1: Tighten reviewed_data['cdm'] guard to exact-literal/enumerated match**
   - `8c1616a` (fix) — replaced `NAME_SUBSTRING`/`str_contains` with `NAME_EXACT_VARIANTS`/`in_array(trim(...), ..., true)`, corrected docblock, added regression test

## Files Created/Modified

- `database/migrations/2026_09_11_180000_backfill_cdm_duty_holder_placeholder.php` — `NAME_EXACT_VARIANTS` constant added replacing `NAME_SUBSTRING`; `reviewed_data['cdm']` branch now uses exact-match; docblock corrected
- `tests/Feature/Rams/BackfillCdmDutyHolderMigrationTest.php` — new test proving PM-authored sentences containing the substring survive the migration byte-identical

## Deviations from Plan

None — plan executed exactly as written. A single fix commit was used rather than separate TDD RED/GREEN commits, since the plan's acceptance criteria center on all tests (existing + new) passing together and the change is a small, atomic guard tightening; all required assertions (existing 4 tests green, new test green, no `str_contains($name` left in the branch, `NAME_EXACT_VARIANTS`/`in_array(trim($name)` present) were verified before committing.

## Issues Encountered

None.

## Verification

- `php artisan test tests/Feature/Rams/BackfillCdmDutyHolderMigrationTest.php tests/Feature/Rams/CdmEmergencyDualPathGateTest.php` — 8 passed (27 assertions).
- `php artisan test tests/Unit/Support/Rams tests/Feature/Rams` — 326 passed (1503 assertions), full RAMS surface, no regression.
- `grep -n "str_contains(\$name" database/migrations/2026_09_11_180000_backfill_cdm_duty_holder_placeholder.php` — 0 matches (substring check removed from the `reviewed_data['cdm']` branch).
- `grep -n "NAME_EXACT_VARIANTS\|in_array(trim(\$name)" database/migrations/2026_09_11_180000_backfill_cdm_duty_holder_placeholder.php` — new exact-match guard confirmed present.
- No production database was touched; `down()` remains the documented no-op; no data-repair or rollback logic was added, matching the plan's explicit scope boundary that production's 2026-09-11 run reported 0 `reviewed_data.cdm` replacements.

## User Setup Required

None. This is a code-level latent-defect fix; it does not touch `config/rams_tier1.php`, does not re-run the migration against production, and requires no deploy-specific action.

## Next Phase Readiness

- 29-VERIFICATION.md gap 2 / 29-REVIEW.md CR-02 is closed at the code level. If this migration is ever run again in a fresh environment (staging refresh, reseed), it can no longer discard a genuine PM-authored CDM note that happens to contain "To be confirmed".
- Phase 29's overall closeout remains gated on the items already tracked in `29-06-SUMMARY.md` (visual document inspection on a live regenerated project, then arming the gate per D-03) — this plan does not change that status.
- No new blockers introduced.

---
*Phase: 29-cdm-duty-holder-emergency-arrangements*
*Completed: 2026-09-12*

## Self-Check: PASSED

- FOUND: database/migrations/2026_09_11_180000_backfill_cdm_duty_holder_placeholder.php
- FOUND: tests/Feature/Rams/BackfillCdmDutyHolderMigrationTest.php
- FOUND: commit 8c1616a
