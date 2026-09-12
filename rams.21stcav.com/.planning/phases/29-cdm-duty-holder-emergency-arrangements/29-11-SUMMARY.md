---
phase: 29-cdm-duty-holder-emergency-arrangements
plan: 11
subsystem: rams-pdf-render
tags: [rams, gap-closure, blade, cdm, site-emergency, rule-07, rule-08, d-05]

# Dependency graph
requires:
  - phase: 29-cdm-duty-holder-emergency-arrangements
    provides: "Plan 29-10's RamsComplianceUpgradeService::DEFAULT_CONTRACTOR_NOTE constant"
provides:
  - "resources/views/pdf/rams.blade.php Section 7.0 — A&E row renders unconditionally, always shows the D-05 hold-point line when unverified"
  - "resources/views/pdf/rams-v2.blade.php Section 7.0 — same fix, mirrored"
  - "Both blades' CDM 2015 Duty Holders section — renders the RULE-07 contractor_note paragraph"
  - "tests/Feature/Rams/CdmContractorNoteRenderRegressionTest.php — new regression test locking the verbatim RULE-07 sentence + forbidden-statement absence on both blades"
  - "tests/Feature/Rams/SiteEmergencyRenderSitesRegressionTest.php — new wholly-empty site_emergency presence-assertion tests for both blades"
affects:
  - "Plan 29-12 (DOCX render-site fixes) — same two gaps on the DOCX renderer, out of this plan's scope"
  - "Plan 29-14 (or a human) — still owns running the Plan 29-10 backfill migration and regenerating the tilda-21cq29531 snapshot fixtures"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Section 7.0 A&E row moved outside the $hasSiteEmerg gate on both blades — it is the one field with a defined empty-case value (the D-05 hold-point line via SiteEmergencyResolver::resolve()); the other six fields stay conditionally gated since they have no defined empty-case value."
    - "CDM contractor_note rendered as a body-para paragraph immediately after the CDM 2015 Duty Holders table, reading $data['cdm_duty_holders']['contractor_note'] with a RamsComplianceUpgradeService::DEFAULT_CONTRACTOR_NOTE fallback — mirrors the existing principal_designer/principal_contractor fallback pattern."
    - "Section-scoped test assertions: presence/absence checks against a substring extracted between the '7.0 Site-Specific Emergency Details' and '7.1 Emergency Contact Numbers' headings, not the whole rendered page — an unrelated 'TBC at site induction' client-contact fallback exists elsewhere in the document header (rams.blade.php:601) and would otherwise false-fail a naive whole-page assertStringNotContainsString('TBC', ...)."

key-files:
  created:
    - tests/Feature/Rams/CdmContractorNoteRenderRegressionTest.php
  modified:
    - resources/views/pdf/rams.blade.php
    - resources/views/pdf/rams-v2.blade.php
    - tests/Feature/Rams/SiteEmergencyRenderSitesRegressionTest.php
    - tests/Feature/Rams/RamsSection70HeadingTest.php
    - tests/Feature/Rams/Tier1SiteEmergencyFormAndRenderTest.php

key-decisions:
  - "Task 1 and Task 2 blade edits landed in separate commits despite touching the same two files — the A&E-row/banner change and the contractor_note paragraph are non-overlapping hunks, so the contractor_note line was staged out of the working tree for the Task 1 commit and re-applied for Task 2, keeping the two task commits atomic per the executor's per-task commit protocol."
  - "Discovered during Task 1 verification: two PRE-EXISTING test files (RamsSection70HeadingTest, Tier1SiteEmergencyFormAndRenderTest) asserted the exact defective behaviour this plan closes (literal 'TBC AT SITE INDUCTION' banner text, red #c00 border, banner-gated table). These are in-scope Rule 1 fixes, not deferred items — they test the same Section 7.0 code this plan's files_modified list targets, and asserting the pre-gap-closure behaviour would otherwise regress every future run. Updated both to assert the new hold-point-always-renders behaviour instead, and scoped their 'no TBC' assertions to the Section 7.0 HTML slice (matching the pattern already used in SiteEmergencyRenderSitesRegressionTest) after discovering the unrelated client-contact 'TBC at site induction' fallback at rams.blade.php:601 would otherwise false-fail a whole-page assertion. Committed as a third, separate commit (test-only, no blade changes) rather than folding into Task 1's commit, since the failure was only surfaced by running the full RAMS suite after Task 1 landed."
  - "Empty-state banner reworded from a red #c00 'MUST BE COMPLETED BEFORE WORKS COMMENCE' hard-stop to an amber #d9a441 informational note, per the plan's explicit instruction — the alarming framing is no longer accurate once the compliant A&E hold-point line always renders above it."

patterns-established: []

requirements-completed: [RULE-07, RULE-08]  # Already marked complete in REQUIREMENTS.md from
  # earlier Phase 29 work (the constants/logic existed); this plan closes the render-site gap
  # that kept the verbatim wording from ever reaching PDF output for the common empty-data case.

# Metrics
metrics:
  duration: "~55 minutes"
  completed: "2026-09-12"
---

# Phase 29 Plan 11: CDM Duty Holder + Emergency Arrangements — PDF render-site gap closure Summary

**Section 7.0's Nearest A&E row now renders unconditionally on both PDF blades (showing the D-05 hold-point line even with a wholly empty `site_emergency`), and the CDM 2015 Duty Holders section now carries the RULE-07 verbatim contractor_note sentence — closing 29-UAT.md Gap 2 and the PDF half of Gap 3.**

## Performance

- **Duration:** ~55 min
- **Started:** 2026-09-12
- **Completed:** 2026-09-12
- **Tasks:** 2 planned (+ 1 follow-up test-fix commit surfaced by full-suite verification)
- **Files modified:** 6 (2 blades, 3 test files updated, 1 test file created)

## Accomplishments

- `rams.blade.php` and `rams-v2.blade.php` Section 7.0: the "Nearest A&E Hospital" table row now opens the `<table>` unconditionally and always renders `SiteEmergencyResolver::resolve()`'s two-branch value — a wholly empty `site_emergency` now shows "Nearest A&E — to be confirmed at induction (must be a 24/7 Emergency Department)" instead of a blank table hidden behind the old `@if($hasSiteEmerg)` gate.
- The six fields with no defined empty-case value (fire assembly point, fire warden, first aider, defibrillator, isolation switch, extinguisher class) stay conditionally gated inside the same table.
- The old red "TBC AT SITE INDUCTION — MUST BE COMPLETED BEFORE WORKS COMMENCE." banner (which contained the literal banned substring "TBC" and contradicted the now-always-present hold-point line) was replaced with an amber informational note naming only the six genuinely-unconfirmed fields.
- Both blades' CDM 2015 Duty Holders section now renders `$data['cdm_duty_holders']['contractor_note']` (falling back to `RamsComplianceUpgradeService::DEFAULT_CONTRACTOR_NOTE` for pre-Plan-29-10 documents) as a `body-para` paragraph immediately after the CDM table.
- New `CdmContractorNoteRenderRegressionTest` proves the verbatim RULE-07 sentence renders through the real `upgrade()` pipeline on both blades, that none of the forbidden CDM statements appear, and that the render-side fallback works for documents predating the contractor_note key.
- New wholly-empty-`site_emergency` test cases added to `SiteEmergencyRenderSitesRegressionTest` for both blades — a genuine presence assertion (not the absence-only pattern that let this gap through originally).

## Task Commits

Each task was committed atomically:

1. **Task 1: Section 7.0 A&E row renders unconditionally; reword empty-state banner** - `d50b4bc` (fix)
2. **Task 2: CDM section renders RULE-07 contractor_note; new regression tests** - `63d0f66` (feat)
3. **Follow-up: update pre-existing tests asserting the old defective banner behaviour** - `a5a8359` (test)

## Files Created/Modified

- `resources/views/pdf/rams.blade.php` - Section 7.0 A&E row unconditional; reworded banner; CDM contractor_note paragraph
- `resources/views/pdf/rams-v2.blade.php` - Same two fixes, mirrored (DTO field reads)
- `tests/Feature/Rams/CdmContractorNoteRenderRegressionTest.php` - New: verbatim RULE-07 sentence + forbidden-statement absence, both blades, real pipeline
- `tests/Feature/Rams/SiteEmergencyRenderSitesRegressionTest.php` - New wholly-empty site_emergency presence tests, both blades
- `tests/Feature/Rams/RamsSection70HeadingTest.php` - Updated 2 tests that asserted the old TBC banner
- `tests/Feature/Rams/Tier1SiteEmergencyFormAndRenderTest.php` - Updated 1 test that asserted the old TBC banner

## Decisions Made

See `key-decisions` in frontmatter: (1) split blade edits across two atomic commits despite shared files, (2) fixed two pre-existing tests that locked in the defect this plan closes (Rule 1, in-scope — not deferred), (3) reworded banner from red hard-stop to amber informational note per plan instruction.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Pre-existing tests asserted the exact defect this plan closes**
- **Found during:** Post-Task-1 full-suite verification (`php artisan test tests/Unit/Support/Rams tests/Feature/Rams`)
- **Issue:** `RamsSection70HeadingTest::test_heading_renders_when_emergency_data_empty()`, `::test_heading_renders_when_emergency_all_keys_present_but_blank()`, and `Tier1SiteEmergencyFormAndRenderTest::test_pdf_renders_warning_banner_when_site_emergency_empty()` all asserted the literal string `'TBC AT SITE INDUCTION'` and/or the red `border: 2pt solid #c00` styling — the exact banner text and framing this plan's Task 1 explicitly removes.
- **Fix:** Updated all three tests to assert the new behaviour: the D-05 hold-point line present, "TBC" absent, the amber `border: 1pt solid #d9a441` styling. Scoped the "no TBC" assertions to the Section 7.0 slice of the rendered HTML (between the "7.0 Site-Specific Emergency Details" and "7.1 Emergency Contact Numbers" headings) after an initial whole-page assertion false-failed against an unrelated pre-existing "TBC at site induction" client-contact fallback at `rams.blade.php:601` (Section 5, document header table) — out of this plan's scope.
- **Files modified:** `tests/Feature/Rams/RamsSection70HeadingTest.php`, `tests/Feature/Rams/Tier1SiteEmergencyFormAndRenderTest.php`
- **Verification:** `php artisan test tests/Feature/Rams/RamsSection70HeadingTest.php tests/Feature/Rams/Tier1SiteEmergencyFormAndRenderTest.php` — 6/6 pass.
- **Committed in:** `a5a8359`

---

**Total deviations:** 1 auto-fixed (Rule 1 — pre-existing tests locking in the defect being closed)
**Impact on plan:** Necessary to keep the full RAMS suite green after the intentional, plan-mandated behaviour change. No scope creep — both fixed test files were already targeting the exact Section 7.0 code this plan's `files_modified` list covers.

## Issues Encountered

None beyond the test-fix deviation above.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Both PDF render sites (`rams.blade.php`, `rams-v2.blade.php`) now satisfy 29-UAT.md Gaps 2 and the PDF half of Gap 3.
- Plan 29-12 (per the original plan's `affects` note) still needs to apply the equivalent DOCX-renderer fix for Gap 3's DOCX half (`DocxBuilderService.php`'s Welfare bullet cross-references a Section 7.0 that the DOCX renderer does not have — 29-UAT.md test 3).
- Plan 29-10's `2026_09_12_120000_backfill_cdm_contractor_note` migration is still code-complete but NOT run against production — remains Plan 29-14's (or a human's) scope, unblocked now that the render fix has shipped.
- `tests/Fixtures/rams/tilda-21cq29531/` snapshot fixtures were NOT touched or regenerated (per this plan's explicit constraint) — the full RAMS suite (`tests/Unit/Support/Rams tests/Feature/Rams`, 341 tests) ran clean with no tilda fixture failures, so no diff-first regeneration is currently required by this plan's changes.

## Verification

- `grep -n "site_emergency\['nearest_hospital'\] =" resources/views/pdf/rams.blade.php resources/views/pdf/rams-v2.blade.php` — no matches (no write-back into `site_emergency`, confirming the read-only render constraint).
- `php artisan test tests/Feature/Rams/SiteEmergencyRenderSitesRegressionTest.php tests/Feature/Rams/CdmContractorNoteRenderRegressionTest.php` — 15/15 pass, 80 assertions.
- `php artisan test tests/Unit/Support/Rams tests/Feature/Rams` (full RAMS surface) — 341/341 pass, 1562 assertions, 79.09s. No failures, no tilda fixture regressions.

## Self-Check: PASSED

- FOUND: `resources/views/pdf/rams.blade.php` (modified — A&E row unconditional, banner reworded, contractor_note paragraph added)
- FOUND: `resources/views/pdf/rams-v2.blade.php` (modified, same two fixes)
- FOUND: `tests/Feature/Rams/CdmContractorNoteRenderRegressionTest.php` (created, 5 test methods)
- FOUND: `tests/Feature/Rams/SiteEmergencyRenderSitesRegressionTest.php` (modified, 2 new test methods)
- FOUND: `tests/Feature/Rams/RamsSection70HeadingTest.php` (modified, 2 assertions updated)
- FOUND: `tests/Feature/Rams/Tier1SiteEmergencyFormAndRenderTest.php` (modified, 1 assertion updated)
- FOUND: commit `d50b4bc` (Task 1) in `git log`
- FOUND: commit `63d0f66` (Task 2) in `git log`
- FOUND: commit `a5a8359` (follow-up test fix) in `git log`

---
*Phase: 29-cdm-duty-holder-emergency-arrangements*
*Completed: 2026-09-12*
