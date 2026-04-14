---
quick_task: 260414-cnf
title: Rewrite rams.blade.php PDF template to 9-section format
completed: 2026-04-14
duration_mins: ~12
files_modified:
  - resources/views/pdf/rams.blade.php
commits:
  - f9790a1
---

# Quick Task 260414-cnf — Summary

## One-liner

Complete rewrite of `rams.blade.php` Blade/dompdf template to match the 9-section structure produced by `DocxBuilderService.php`, ensuring PDF and DOCX outputs are structurally identical.

## What Changed

`resources/views/pdf/rams.blade.php` was fully replaced. The old template had 8 loosely-structured sections that did not match the DOCX renderer introduced in task 260413-rm9. The new template mirrors `DocxBuilderService::build()` section-for-section.

### Section mapping (new PDF vs DOCX)

| Section | PDF heading | DOCX method |
|---------|-------------|-------------|
| Cover | Two teal-label tables | `buildCoverPage()` |
| 1 | Document Control | `buildDocumentControl()` |
| 2 | Company Information | `buildCompanyInformation()` |
| 3 | Health & Safety Policy Statement | `buildHealthSafetyPolicy()` |
| 4 | Scope of Works | `buildScopeOfWorks()` |
| 5 | Risk Assessment | `buildRiskAssessment()` |
| 6 | Method Statement | `buildMethodStatement()` |
| 7 | Emergency Procedures | `buildEmergencyProcedures()` |
| 8 | Document Sign-Off | `buildDocumentSignOff()` |

## Key implementation details

- **Cover page** — Two separate `cover-table` blocks. Table 1: CLIENT, SITE ADDRESS, PROJECT REFERENCE, ROOMS, DATE. Table 2: PREPARED BY, TELEPHONE, CLIENT CONTACT, REVISION, STATUS. Teal left-column label cells, white/alternating value cells.
- **Section 1** — Revision history table (Rev | Date | Author | Description | Status) pre-filled from `$data['project']` plus three blank rows for future revisions.
- **Section 2** — Two-column grid: Company/Address/Website/Email (left) mapped to Project Reference/Telephone/Email/Prepared by (right).
- **Section 3** — Verbatim three-paragraph H&S policy boilerplate, justified body text.
- **Section 4** — Header info block (Client/Site/Rooms/Working Hours) then equipment schedule table grouped by DECOMMISSION & HANDBACK, EXISTING — RETAINED, NEW INSTALLATION. Falls back to `$data['quote']['line_items']` if all three `scope_items` buckets are empty.
- **Section 5** — Four-row risk key (LOW 1-4 green / MEDIUM 5-9 amber / HIGH 10-14 orange / CRITICAL 15-25 red) then hazard register table. Each row: Ref (RA01...), Hazard, Persons at Risk, Initial Risk (L×S=R + badge colour), Control Measures, Residual Risk (L×S=R + badge colour).
- **Section 6** — 6.1 Team table aggregated by role with competency text; 6.2 Tools bulleted (falls back to default list if empty); 6.3 Client Responsibilities bullets; 6.4 Method of Works as "Step N — Title" headings with sub-bullets.
- **Section 7** — Emergency contact table (999 / 101 / Site Contact / 21CAV Ops), verbatim accident bullets, verbatim fire evacuation bullets.
- **Section 8** — Two-column sign-off table (21CAV | Client Acceptance) with Name/Position/Date/Signature rows.

## Data fields used

All fields trace to `$data['project']`, `$data['hazards']`, `$data['scope_items']`, `$data['scope_of_works']`, `$data['method_statement']['phases']`, `$data['team']`, `$data['tools_and_equipment']`, `$data['client_responsibilities']`, `$data['quote']`, `$rams->form_data`, and `config('rams.*')`. No invented values.

## Deviations from plan

None — plan executed exactly as specified. The H&S policy was rendered as two merged paragraphs in DocxBuilderService (paragraphs 2 and 3 are combined); the PDF renders them as three distinct paragraphs matching the task spec, which is a faithful representation of the same content.

## Self-Check

- [x] File exists: `resources/views/pdf/rams.blade.php` — confirmed (Write tool succeeded)
- [x] Commit exists: `f9790a1` — confirmed by git output
- [x] No invented data — all values trace to `$data[]`, `$rams->form_data`, or `config('rams.*')`
- [x] No stubs that block plan goal — template is fully wired
