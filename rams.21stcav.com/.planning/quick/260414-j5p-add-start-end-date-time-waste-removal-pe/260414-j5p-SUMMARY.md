---
phase: quick
plan: 260414-j5p
subsystem: rams
tags: [rams, pdf, review-form, cdm, coshh, permits, material-handling, welfare, compliance]
dependency_graph:
  requires: []
  provides: [tier1-compliance-fields, permit-tracking, cdm-duty-holders, material-handling, waste-removal-party]
  affects: [rams-review-form, rams-pdf-output, rams-controller]
tech_stack:
  added: []
  patterns: [json-column-sub-keys, blade-php-variable-extraction, form-array-inputs]
key_files:
  modified:
    - app/Http/Controllers/RamsController.php
    - resources/views/rams/review.blade.php
    - resources/views/pdf/rams.blade.php
decisions:
  - "Store all new fields in reviewed_data JSON sub-keys — no migration required"
  - "CDM table uses fixed 4 roles with Sub-contractor defaulting to company name/phone"
  - "New PDF sections use sub-labels (6.5, Permits, CDM, COSHH, Environmental, Welfare) rather than full section renumber to avoid disrupting existing section 7/8"
  - "Permits stored as full array of all types with required flag; PDF filters to required-only"
  - "reviewed_data saved in same update() call as generated_data before download attempt"
metrics:
  duration: 25min
  completed: "2026-04-14"
  tasks_completed: 3
  tasks_total: 3
  files_modified: 3
---

# Quick Task 260414-j5p: Add Start/End Times, Waste Removal, Permits, Material Handling & Tier 1 Compliance Sections

**One-liner:** Tier 1 RAMS compliance — start/end times, waste party, 8-permit checklist, material handling table, CDM duty holders, COSHH/Environmental/Welfare boilerplate, and Toolbox Talk appendix across controller, review form, and PDF.

## What Was Built

### Task 1 — RamsController wiring (commit d8f7fae)

**review() method:**
- Step 6 added: pulls `planned_start_time` and `planned_end_time` from `reviewed_data['programme']` into `generated_data['project']` transiently so the PDF cover table picks them up.

**updateAndDownload() method:**
- Extended validation rules for: `planned_start_date/time`, `planned_end_date/time`, `waste_removal_party` (enum: client/21cav/other), `waste_removal_notes`, `welfare_notes`, `permits_required[]`, `material_handling_*`, `cdm[]`.
- Merges planned date/time fields into `generated_data['project']` for PDF cover table.
- Builds and persists all new sub-keys into `reviewed_data` JSON: `programme` (times + waste + welfare), `permits_required`, `material_handling`, `cdm`.
- Single `update()` call saves both `generated_data` and `reviewed_data` before the download attempt.

### Task 2 — review.blade.php new field groups (commit cfd6b95)

Five new field groups inserted between the Operations Info section and the submit buttons:

- **Programme:** `planned_start_date`, `planned_start_time`, `planned_end_date`, `planned_end_time` in a 2-col grid.
- **Waste Removal:** radio (client/21CAV/other) + notes textarea.
- **Permits Required:** 8 permit types (Hot Works, WAH, PASMA, IPAF, Confined Space, Electrical Isolation, Asbestos Awareness, Other) — each with checkbox + hidden type field + notes input.
- **Material Handling:** toggle checkbox reveals large-items table with JS add-row; handling notes textarea below.
- **CDM 2015 Duty Holders:** fixed 4-role table (Client, Principal Designer, Principal Contractor, Sub-contractor) with organisation/name/contact inputs. Sub-contractor pre-fills 21st Century AV Ltd.
- **Welfare Arrangements:** site-specific notes textarea.

### Task 3 — rams.blade.php PDF sections (commit c428316)

New `@php` block variables extracted: `$plannedStartTime`, `$plannedEndTime`, `$wasteParty/Label/Notes`, `$permitsRd/$requiredPermits`, `$matHandling/$mhItems/$mhNotes`, `$cdmRows`, `$welfareNotes`.

PDF additions:
- **Cover Table 3:** START TIME / END TIME row (conditional on data).
- **Section 4 kv-block:** Waste Removal line appended.
- **Section 6.1:** `$reqMap` upgraded with CSCS/ECS/IPAF/PASMA in all role requirement strings; default fallback rows also updated.
- **Section 6.5 Material Handling:** table if large items present, boilerplate if none, handling notes paragraph.
- **Permits & Authorisations:** table of required permits with notes, or standard no-permits boilerplate.
- **CDM 2015 Duty Holders:** 4-role table, Sub-contractor defaults to company name/phone.
- **COSHH Assessment:** AV-specific hazardous substances boilerplate (adhesives, dust, flux, batteries).
- **Environmental Management:** Waste Disposal sub-section (with waste_removal data passthrough) + Noise/Dust/Vibration sub-section.
- **Welfare Arrangements:** standard welfare bullet list + site-specific `$welfareNotes` passthrough.
- **Appendix A — Toolbox Talk Record:** 5-row sign-off table with date/conducted-by/location fields on a page-break.

## Deviations from Plan

None — plan executed exactly as written.

The only minor adaptation: the `@foreach` over `$permitTypes` uses `$ptIdx` as the loop variable name instead of `$loop->index` to avoid a blade-scoping issue with the `@php` inline block inside `@foreach`. The plan used `$loop->index` which is valid Blade — both approaches work identically; `$ptIdx` is slightly more explicit.

## Known Stubs

None. All new fields flow from reviewed_data through the form and into the PDF. The COSHH and Environmental Management sections use fixed boilerplate (by design — no user input required for these regulatory sections).

## Threat Flags

None. No new network endpoints, auth paths, or schema changes introduced. All data flows through the existing authenticated `rams.update-and-download` POST route with existing policy authorization.

## Self-Check

### Files created/modified
- `app/Http/Controllers/RamsController.php` — modified
- `resources/views/rams/review.blade.php` — modified
- `resources/views/pdf/rams.blade.php` — modified

### Commits
- d8f7fae — feat(260414-j5p): wire review() and updateAndDownload() for new RAMS fields
- cfd6b95 — feat(260414-j5p): add new field groups to RAMS review form
- c428316 — feat(260414-j5p): add Tier 1 compliance sections to RAMS PDF template

## Self-Check: PASSED
