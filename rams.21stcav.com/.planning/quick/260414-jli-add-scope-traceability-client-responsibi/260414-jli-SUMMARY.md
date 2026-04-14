---
phase: quick
plan: 260414-jli
subsystem: rams-review-pdf
tags: [rams, review-form, pdf, reviewed_data, scope-traceability, commissioning]
dependency_graph:
  requires: []
  provides: [scope_traceability, client_responsibilities_expanded, exclusions, decommissioning, commissioning_criteria in reviewed_data]
  affects: [RamsController, rams/review.blade.php, pdf/rams.blade.php]
tech_stack:
  added: []
  patterns: [reviewed_data JSON sub-key pattern, @php-before-@forelse pattern, transient pre-fill in review()]
key_files:
  created: []
  modified:
    - app/Http/Controllers/RamsController.php
    - resources/views/rams/review.blade.php
    - resources/views/pdf/rams.blade.php
decisions:
  - Exclusions always render in PDF with default fallback (never empty); Scope Traceability and Commissioning Criteria are conditional on data present
  - Decommissioning enabled by OR of reviewed_data flag and existing $hasDecomm (scope_items decommission array)
  - Client responsibilities expanded appended after existing 6.3 list rather than replacing it
metrics:
  duration_minutes: 18
  completed_date: "2026-04-14"
  tasks_completed: 3
  tasks_total: 3
  files_changed: 3
---

# Quick Task 260414-jli: Add Scope Traceability, Client Responsibilities, Exclusions, Decommissioning, Commissioning Criteria to RAMS

**One-liner:** Five new reviewed_data sub-keys — scope traceability pre-filled from quote line_items, default exclusions list, structured client responsibilities checkboxes, decommissioning toggle+steps, and commissioning criteria sign-off table — wired through controller validation, review form, and PDF output.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Controller — pre-fill review() and validate+persist updateAndDownload() | 3b03586 | app/Http/Controllers/RamsController.php |
| 2 | Review form — five new editable sections | d499b84 | resources/views/rams/review.blade.php |
| 3 | PDF template — five new rendered sections | f33b8b9 | resources/views/pdf/rams.blade.php |

## What Was Built

**Controller (Task 1):**
- `review()`: transient pre-fill of `scope_traceability` from `generated_data['quote']['line_items']` when key absent; default five-item `exclusions` list; empty-array defaults for `client_responsibilities_expanded`, `decommissioning`, `commissioning_criteria`
- `updateAndDownload()`: 38 new validation rules covering all five sub-keys; persistence block after CDM section storing all five into `$reviewedData` before `$rams->update()`

**Review Form (Task 2):**
- Scope Traceability: 4-column table (quote item, RAMS activity, room, notes) pre-filled from reviewed_data, JS add/remove rows
- Client Responsibilities Expanded: four standard checkboxes (network, licences, access, power) with notes fields + additional rows table
- Exclusions: editable list pre-filled with five project defaults, add/remove rows
- Decommissioning: toggle checkbox reveals labelling/storage/disposal/sign-off fields + ordered steps list
- Commissioning Criteria: 4-column table (system, criterion, verification method, pass condition), JS add/remove rows

**PDF Template (Task 3):**
- Variable reads added to `@php` block after `$welfareNotes` and `$roomOverviews`
- Scope Traceability table: after equipment schedule, before Section 5 (conditional on non-empty)
- Exclusions section: always renders with reviewed data or five default fallback items
- Client Responsibilities Expanded: appended after existing 6.3 bullet list (checked items + additional rows)
- Decommissioning Procedure: after 6.5 Material Handling, before Permits (conditional on `$decommEnabled`)
- Commissioning Criteria: page-break heading before Section 8, teal header row, Pass/Fail checkbox column

## Deviations from Plan

None — plan executed exactly as written.

## Known Stubs

None — all five sections are fully wired from reviewed_data to form to PDF.

## Threat Flags

No new network endpoints, auth paths, or file access patterns introduced. All new inputs validated by Laravel array rules with string max:500 per cell before persisting. Consistent with T-jli-01 mitigation in threat model.

## Self-Check: PASSED

- `app/Http/Controllers/RamsController.php` — modified, syntax clean
- `resources/views/rams/review.blade.php` — modified, syntax clean
- `resources/views/pdf/rams.blade.php` — modified, syntax clean
- Commits verified: 3b03586, d499b84, f33b8b9 all present on master
