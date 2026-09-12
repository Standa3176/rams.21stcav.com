---
phase: 29-cdm-duty-holder-emergency-arrangements
plan: 12
subsystem: rams-docx-render
tags: [rams, gap-closure, docx, cdm, site-emergency, rule-07, rule-08, d-05]

# Dependency graph
requires:
  - phase: 29-cdm-duty-holder-emergency-arrangements
    provides: "Plan 29-10's RamsComplianceUpgradeService::DEFAULT_CONTRACTOR_NOTE constant"
  - phase: 29-cdm-duty-holder-emergency-arrangements
    provides: "Plan 29-11's PDF blade Section 7.0 block, mirrored here for the DOCX renderer"
provides:
  - "app/Services/DocxBuilderService.php buildEmergencyProcedures() — new Section 7.0 Site-Specific Emergency Details block (5 fields), reads SiteEmergencyResolver::resolve()"
  - "app/Services/DocxBuilderService.php buildWelfareArrangements() — First Aid bullet now points at Section 7.0 instead of the CDM section"
  - "app/Services/DocxBuilderService.php buildCdmSection() — renders the RULE-07 contractor_note paragraph"
  - "tests/Feature/Rams/DocxEmergencySectionRegressionTest.php — new presence-assertion regression suite for the DOCX render path"
affects:
  - "Plan 29-14 — still owns running the Plan 29-10 backfill migration and regenerating the tilda-21cq29531 DOCX/PDF snapshot fixtures (expected to need it now that Section 7.0 + contractor_note both add new content to the DOCX)"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "buildEmergencyProcedures() gained a 4th parameter (RamsDocument $record) so it can read reviewed_data['site_emergency'] the same way rams.blade.php does — DocxBuilderServiceV2 delegates through buildRestOfDocument() with no further changes needed, since $record was already in scope there."
    - "DOCX Section 7.0 uses buildCdmSection()'s existing 3400/6466 twip 2-column label/value split (not the PDF's 4-column table) — fire warden and first aider name+contact are combined into a single cell value ('Name (Contact)') since the DOCX table shape has no room for a separate Contact column."
    - "Hospital address rendered as a second addText() call inside the same value cell, 8pt MID_GREY, mirroring the PDF's <br><span> sub-line treatment."
    - "contractor_note paragraph mirrors the existing $notification paragraph exactly (same font(8, italic, MID_GREY) style), placed immediately after it in buildCdmSection()."

key-files:
  created:
    - tests/Feature/Rams/DocxEmergencySectionRegressionTest.php
  modified:
    - app/Services/DocxBuilderService.php

key-decisions:
  - "Both source hunks (Section 7.0 block + Welfare bullet reword, and the CDM contractor_note paragraph) ended up in the Task 1 commit rather than split across two commits as originally attempted: `git commit -m ... -- app/Services/DocxBuilderService.php` commits the full working-tree content of the given path regardless of what was staged via `git add -p`, not just the staged hunks. `git add -p` correctly split the diff into 4 hunks for Task 1 + 1 hunk for Task 2 in the index, but the pathspec-scoped commit re-read the working tree and included all 5. Documented here rather than attempting a git-history rewrite. Task 2's own commit carries the new test file plus a note explaining the contractor_note code landed a commit early."
  - "Did not add Electrical Isolation Switch or Fire Extinguisher Class rows to the DOCX Section 7.0 table — out of scope per the plan's explicit instruction (five fields only, matching the phase scope, not full PDF parity on those two fields)."

patterns-established: []

requirements-completed: [RULE-07, RULE-08]  # Render-site gap closure only — the underlying
  # constants/pipeline logic already existed from earlier Phase 29 plans.

# Metrics
metrics:
  duration: "~35 minutes"
  completed: "2026-09-12"
---

# Phase 29 Plan 12: CDM Duty Holder + Emergency Arrangements — DOCX render-site gap closure Summary

**The DOCX now carries a real Section 7.0 Site-Specific Emergency Details block (nearest A&E + address, fire assembly point, fire warden, first aider, defibrillator), reading the same `SiteEmergencyResolver`-resolved value the PDF uses; the Welfare First Aid bullet now points at it instead of the CDM section; and the CDM table now renders the RULE-07 verbatim contractor_note sentence — closing 29-UAT.md Gap 1 (the DOCX regression) and the DOCX half of Gap 3.**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-09-12
- **Completed:** 2026-09-12
- **Tasks:** 2 planned, both completed
- **Files modified:** 2 (1 service file, 1 new test file)

## Accomplishments

- `buildEmergencyProcedures()` gained a `RamsDocument $record` parameter (its one call site in `buildRestOfDocument()` updated) and now renders a "7.0 Site-Specific Emergency Details" heading + 2-column table before the existing 7.1 Emergency Contact Numbers block, with five rows: Nearest A&E Hospital (resolved text + address sub-line), Fire Assembly Point, Fire Warden (name+contact combined), First Aider (name+contact combined), Nearest Defibrillator. The A&E row always renders — even for a wholly empty `site_emergency` — showing the D-05 hold-point line, never the banned passive string, never blank.
- `buildWelfareArrangements()`'s First Aid bullet now reads "...Nearest A&E — see Section 7.0." (was: "...see CDM 2015 — Duty Holders section."), matching the PDF's exact wording. The obsolete code comment explaining the old cross-reference was replaced with one describing the new Section 7.0 block.
- `buildCdmSection()` now renders `$cdm['contractor_note']` (falling back to `RamsComplianceUpgradeService::DEFAULT_CONTRACTOR_NOTE`) as an italic MID_GREY paragraph immediately after the existing `$notification` paragraph, mirroring its exact style.
- New `tests/Feature/Rams/DocxEmergencySectionRegressionTest.php` — 7 presence-assertion tests built through the real `RamsDisplayPatchService::patch()` + `RamsComplianceUpgradeService::upgrade()` pipeline against a real built DOCX's extracted `word/document.xml`: wholly-empty hold-point, verified hospital name+address, Welfare bullet pointer, verbatim RULE-07 sentence, and the three forbidden CDM statements never appearing.
- Neither `DocxBuilderService::build()` (legacy) nor `DocxBuilderServiceV2::build()` (unified) throws — both delegate through the same modified methods (V2 delegates via `buildRestOfDocument()`, unchanged call chain).

## Task Commits

1. **Task 1: Section 7.0 A&E block + Welfare bullet fix** — `ee31074` (fix) — also carries Task 2's `buildCdmSection()` contractor_note hunk; see key-decisions for why.
2. **Task 2: DOCX presence regression test suite** — `31681ef` (test)

## Files Created/Modified

- `app/Services/DocxBuilderService.php` — `buildEmergencyProcedures()` signature + new Section 7.0 block; `buildWelfareArrangements()` bullet reworded; `buildCdmSection()` contractor_note paragraph
- `tests/Feature/Rams/DocxEmergencySectionRegressionTest.php` — new, 7 test methods

## Decisions Made

See `key-decisions` in frontmatter: (1) the contractor_note hunk rode along with the Task 1 commit due to `git commit -- <pathspec>` re-reading the full working-tree file rather than only staged hunks, (2) Electrical Isolation Switch / Fire Extinguisher Class rows intentionally omitted from the DOCX Section 7.0 table (out of this plan's five-field scope).

## Deviations from Plan

None — plan executed as written. The commit-split note above is a mechanical git-tooling artifact, not a scope or behavior deviation: both task's code changes are present, correctly attributed in commit messages, and independently test-verified.

## Issues Encountered

None.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- Both PDF (Plan 29-11) and DOCX (this plan) render sites now satisfy 29-UAT.md Gap 1 and Gap 3.
- `tests/Fixtures/rams/tilda-21cq29531/` DOCX/PDF snapshot fixtures were NOT touched or regenerated (per this plan's explicit constraint). The `DocxSnapshotTest` (tagged `snapshot`, excluded from the default `phpunit` group) was NOT force-run — it is expected to need regeneration given the new Section 7.0 block and contractor_note paragraph both add content to the DOCX output. This is Plan 29-14's diff-first regeneration scope, not this plan's.
- Plan 29-10's `2026_09_12_120000_backfill_cdm_contractor_note` migration remains not run against production — Plan 29-14's (or a human's) scope.

## Verification

- `grep -n '\$siteEmerg\s*=\|\$data\[.site_emergency.\]\s*=' app/Services/DocxBuilderService.php` around the new block — only the one read-only local-variable assignment from source data; no write-back into `$siteEmerg`, `$data`, or `$record`.
- `php artisan test tests/Feature/Rams/DocxEmergencySectionRegressionTest.php` — 7/7 pass, 33 assertions.
- `php artisan test tests/Feature/Rams/SiteEmergencyRenderSitesRegressionTest.php tests/Unit/Services/Rams/CdmDutyHolderWordingTest.php` — 23/23 pass, 89 assertions (no regressions on the PDF-side plans this one mirrors).
- `php artisan test tests/Feature/Rams/DocxBuilderNewSectionsTest.php tests/Feature/Rams/DocxBuilderPaletteFontTest.php tests/Feature/Rams/DocxBuilderPdfParityTest.php tests/Feature/Rams/ElectricalScopeBoundaryExclusionTest.php tests/Feature/Rams/EquipmentScheduleFallbackTest.php tests/Feature/Rams/WorkingAtHeightResidualScoreTest.php` — 43/43 pass, 257 assertions (no regressions on other DOCX builder sections).
- `php artisan test tests/Unit/Support/Rams tests/Feature/Rams` (full RAMS surface) — 348/348 pass, 1595 assertions, 77.12s. No failures, no tilda fixture regressions triggered (snapshot group excluded by default).
- No colour changes made to `app/Services/DocxBuilderService.php` — confirmed by diff review (left to Plan 29-13).

## Self-Check: PASSED

- FOUND: `app/Services/DocxBuilderService.php` (modified — Section 7.0 block, Welfare bullet reword, contractor_note paragraph)
- FOUND: `tests/Feature/Rams/DocxEmergencySectionRegressionTest.php` (created, 7 test methods)
- FOUND: commit `ee31074` (Task 1 + Task 2 source) in `git log`
- FOUND: commit `31681ef` (Task 2 test file) in `git log`

---
*Phase: 29-cdm-duty-holder-emergency-arrangements*
*Completed: 2026-09-12*
