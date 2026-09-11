---
phase: 29-cdm-duty-holder-emergency-arrangements
plan: 05
subsystem: database
tags: [rams, cdm, rule-07, migration, backfill, carry-forward]

# Dependency graph
requires:
  - phase: 29-cdm-duty-holder-emergency-arrangements
    plan: 01
    provides: "Production measurement checkpoint — 46/54 (85%) RamsDocument rows carrying the CDM placeholder, deploy-order gate for this backfill"
  - phase: 29-cdm-duty-holder-emergency-arrangements
    plan: 03
    provides: "RamsComplianceUpgradeService::DEFAULT_PRINCIPAL_DESIGNER_NOTE / DEFAULT_PRINCIPAL_CONTRACTOR_NOTE — the restated RULE-07 wording this backfill writes"
provides:
  - "database/migrations/2026_09_11_180000_backfill_cdm_duty_holder_placeholder.php — idempotent, chunked, dual-column/dual-shape backfill of the CDM placeholder"
  - "RamsDisplayPatchService carry-forward guard — skips placeholder cdm/site_emergency values (D-04), closing the re-propagation vector"
affects: [29-06-PLAN]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Idempotent backfill migration shape (chunked scan, value-equality/substring guard, per-surface counters, documented no-op down()) reused verbatim from the Phase 28-07 precedent"
    - "Carry-forward eligibility filter — a private static helper filters a prior document's list-shaped field before assignment, rather than mutating the whole block or skipping it unconditionally"

key-files:
  created:
    - database/migrations/2026_09_11_180000_backfill_cdm_duty_holder_placeholder.php
    - tests/Feature/Rams/BackfillCdmDutyHolderMigrationTest.php
  modified:
    - app/Services/Rams/RamsDisplayPatchService.php
    - tests/Feature/Rams/PatchRamsForDisplayTest.php

key-decisions:
  - "reviewed_data['cdm'] rows are matched on the 'name' field (not 'value') — confirmed against 29-RESEARCH.md Finding 5 and RamsController.php:504-509's actual write shape (a LIST of {role, name} rows), which is more precise than the PLAN.md interfaces block's 'value/name' hedge."
  - "generated_data['cdm_duty_holders'] uses an EXACT-literal guard (=== '[To be confirmed]') while reviewed_data['cdm'] uses a SUBSTRING guard (str_contains(...,'To be confirmed')) scoped to PD/PC roles only — the keyed shape only ever holds the bare literal (RamsComplianceUpgradeService::addCdmDutyHolders()'s old behaviour), but the list shape is free text an engineer could type a longer sentence into, so substring matching is the only guard that reaches a seeded-default variant without risking a false negative or, given the PD/PC role scope, a false positive against real content."
  - "SiteEmergencyResolver::HOLD_POINT is private; RamsDisplayPatchService's new isPlaceholderNearestHospital() copies the literal string verbatim (documented explicitly) rather than widening the resolver's public surface for one caller — mirrors this codebase's existing convention of SiteEmergencyResolverTest.php duplicating the same constant rather than exposing it."
  - "Carry-forward tests were added to the existing PatchRamsForDisplayTest.php (which already exercises RamsDisplayPatchService::patch() via RamsController::patchRamsForDisplay() reflection) rather than creating a new test file, per the plan's instruction to extend an existing test class covering patch()'s behaviour."

patterns-established: []

requirements-completed: [RULE-07]

# Metrics
duration: 45min
completed: 2026-09-11
---

# Phase 29 Plan 05: CDM Duty-Holder Backfill + Carry-Forward Guard Summary

**Idempotent migration backfills the RULE-07 CDM placeholder on both `reviewed_data['cdm']` (list-of-rows, substring guard) and `generated_data['cdm_duty_holders']` (keyed shape, exact-literal guard) across all already-persisted production `RamsDocument` rows, and closes the per-project carry-forward's placeholder re-propagation vector for both CDM and site-emergency values.**

## Performance

- **Duration:** 45 min
- **Started:** 2026-09-11
- **Completed:** 2026-09-11
- **Tasks:** 2/2 complete
- **Files modified:** 4 (2 created, 2 modified)

## Accomplishments

- New migration `2026_09_11_180000_backfill_cdm_duty_holder_placeholder.php` patches the bare `'[To be confirmed]'` literal on `generated_data['cdm_duty_holders']['principal_designer'|'principal_contractor']` (exact-equality guard) and any `reviewed_data['cdm']` row whose role is Principal Designer/Principal Contractor and whose `name` field contains the substring `'To be confirmed'` (role-scoped substring guard) — reusing `RamsComplianceUpgradeService::DEFAULT_PRINCIPAL_DESIGNER_NOTE`/`DEFAULT_PRINCIPAL_CONTRACTOR_NOTE` rather than re-typing the wording. Mirrors the Phase 28-07 precedent's chunked/dual-column/counted/no-op-`down()` shape verbatim.
- `project_manager`/`site_supervisor` are deliberately left untouched (they are data-dependent placeholders, not RULE-07's settled-position fields — matches GATE-11's own scope).
- `RamsDisplayPatchService`'s per-project carry-forward now filters `cdm` rows through `carryForwardEligibleCdmRows()` (drops any placeholder-valued PD/PC row) and skips the entire `site_emergency` carry-forward when the prior document's `nearest_hospital` is blank or equals the D-05 hold-point line via the new `isPlaceholderNearestHospital()` helper — genuinely useful prior values (a real PD/PC name, a verified A&E with its fire-warden/defibrillator sub-fields) still carry forward exactly as before.
- 16 new tests across two files, all green: 4 in `BackfillCdmDutyHolderMigrationTest` (patch-both-shapes, never-overwrite-real-name, idempotent, `down()`-is-no-op), 5 new carry-forward cases added to `PatchRamsForDisplayTest` (placeholder-dropped, real-value-propagated, blank-A&E-skipped, hold-point-A&E-skipped, verified-A&E-propagated-in-full) plus the 7 pre-existing cases in that file still passing.
- Full `tests/Unit/Support/Rams tests/Feature/Rams` suite: 322 passed (1489 assertions), zero regressions.

## Task Commits

Each task was committed atomically:

1. **Task 1: Idempotent backfill migration for the CDM placeholder** - `6f4b308` (feat)
2. **Task 2: Carry-forward guard — skip placeholder cdm/site_emergency values (D-04)** - `f97cb10` (fix)

## Files Created/Modified

- `database/migrations/2026_09_11_180000_backfill_cdm_duty_holder_placeholder.php` - the idempotent, chunked, dual-column/dual-shape CDM placeholder backfill
- `tests/Feature/Rams/BackfillCdmDutyHolderMigrationTest.php` - proves the migration's shape, value-equality/substring guards, idempotency, and no-op `down()`
- `app/Services/Rams/RamsDisplayPatchService.php` - `carryForwardEligibleCdmRows()` and `isPlaceholderNearestHospital()` filter the carry-forward block per D-04
- `tests/Feature/Rams/PatchRamsForDisplayTest.php` - 5 new carry-forward test cases covering both filters

## Decisions Made

- Resolved the plan interfaces block's `{role, value}` / `{role, name}` hedge in favour of `{role, name}` — confirmed by direct read of `RamsController::updateAndDownload()` (`:504-509`) and 29-RESEARCH.md Finding 5, both of which pin the field to `name`. No `value` key exists anywhere in this write path.
- `RamsComplianceUpgradeService::DEFAULT_PRINCIPAL_DESIGNER_NOTE`/`DEFAULT_PRINCIPAL_CONTRACTOR_NOTE` are `public const` (added in Plan 29-03 specifically so this plan could reference them) — used directly in both the migration and the carry-forward guard rather than being re-typed.
- `SiteEmergencyResolver::HOLD_POINT` stayed `private`; the guard copies its literal value with an explicit docblock citing the existing `SiteEmergencyResolverTest.php` precedent for duplicating rather than exposing it, since widening a class's public surface for a single new caller was out of this plan's scope.

## Deviations from Plan

None - plan executed exactly as written. The one clarification made (matching `reviewed_data['cdm']` rows on `'name'` rather than a `'value'`/`'name'` either-or) is a resolution of an intentional hedge in the plan's own `<interfaces>` block, not a deviation from behaviour the plan specified — 29-RESEARCH.md (which the plan's `<context>` references) already pins the field to `'name'` with a worked example, so this is applying the plan's own most-authoritative source rather than departing from it.

## Issues Encountered

None.

## Verification

- `php artisan test --filter=BackfillCdmDutyHolderMigrationTest` — 4 passed (13 assertions)
- `php artisan test --filter=PatchRamsForDisplayTest` — 12 passed (43 assertions), including the 5 new carry-forward cases
- `php artisan test tests/Unit/Support/Rams tests/Feature/Rams` (full RAMS suite) — 322 passed (1489 assertions), zero regressions
- Manual review: `down()` method body contains no `->update(` call (grep-verified and asserted by `test_down_is_a_documented_no_op`)
- Manual review: `principal_designer`/`principal_contractor` are the ONLY `cdm_duty_holders` keys patched by the migration — `project_manager`/`site_supervisor` proven untouched in `test_backfill_patches_both_columns_and_shapes`
- No production database was touched — all verification ran against the local SQLite/MySQL test database via `RefreshDatabase`

## User Setup Required

None — this migration has not been run against production. Per D-02/D-03, running it on `rams.21stcav.com` and verifying a live regeneration is a separate, later manual step; GATE-11/GATE-12 remain disarmed (`RAMS_CDM_AE_GATE` unset, defaults `false`) and this plan does not arm them.

## Next Phase Readiness

- The migration is written, tested, and ready to run against production as a separate deploy step (out of this plan's scope per the working-directory constraints — do not run it here).
- RULE-07 is now implemented end-to-end for every already-persisted document: Plan 29-03's code fix covers every future `upgrade()` pass, and this plan's backfill covers every document that will never regenerate again. Marked complete in REQUIREMENTS.md.
- The carry-forward guard means no NEW document created after this plan's deploy can have a placeholder CDM or A&E value re-seeded onto it from an older sibling document on the same project, regardless of whether the backfill has run yet on that specific prior document.
- Plan 29-06 (test-fixture regeneration for the `tilda-21cq29531` snapshots) is unaffected by this plan — those fixtures cover Plan 29-04's A&E content change, not the CDM backfill.
- No blockers.

---
*Phase: 29-cdm-duty-holder-emergency-arrangements*
*Completed: 2026-09-11*

## Self-Check: PASSED
