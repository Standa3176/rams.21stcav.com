---
status: diagnosed
phase: 29-cdm-duty-holder-emergency-arrangements
source: [29-04-SUMMARY.md, 29-06-SUMMARY.md, 29-09-SUMMARY.md]
started: 2026-09-12T16:32:00Z
updated: 2026-09-12T17:00:00Z
---

## Current Test

[testing complete]

## Tests

Live document verification of RAMS 103 (project `21CQ30949-01-OPS`, Gardner Leader LLP),
regenerated on production `rams.21stcav.com` after the Phase 29 deploy + backfill. Both renderers
were downloaded and inspected: `21CQ30949-01-OPS - Gardner Leader LLP.pdf` (654 KB) and
`rams_103_20260912_162944_493291.docx` (47 KB). This is ROADMAP Phase 29 criterion 4's
"verified against production data, not just a fixture" check.

**Note on this project's data state:** no `site_emergency` values were captured in the review form
for RAMS 103, so `$hasSiteEmerg` is false. That is the common case and is precisely why the defects
below are visible.

### 1. Banned string absent from generated output
expected: The string "to be identified at site induction" appears in neither the PDF nor the DOCX
result: pass

### 2. `[To be confirmed]` absent from the CDM duty-holder table
expected: Neither document shows "[To be confirmed]" for Principal Designer or Principal Contractor
result: pass
<!-- Confirms the Plan 29-05 backfill reached live documents: 46/54 rows patched -->

### 3. Word document states the nearest A&E
expected: The DOCX conveys nearest-A&E information, or defers to a section that actually contains it
result: issue
reported: "Word version is blue and not to brand ... Please check both are on brand but also tier 1 and present the same data/info"
severity: blocker
<!-- Found during that inspection: the DOCX Welfare First Aid bullet reads
     "Nearest A&E — see CDM 2015 — Duty Holders section." The DOCX has no Section 7.0 at all
     (7. EMERGENCY PROCEDURES goes straight to 7.1 Emergency Contact Numbers), and the CDM
     duty-holders section contains no A&E information. Net: the Word RAMS now carries NO
     nearest-A&E information anywhere, and misdirects the reader. Pre-Phase-29 it at least
     stated "Nearest hospital A&E to be identified at site induction". This is a regression. -->

### 4. Section 7.0 renders the D-05 hold-point line when no A&E is captured
expected: Section 7.0's Nearest A&E row shows "Nearest A&E — to be confirmed at induction (must be a 24/7 Emergency Department)"
result: issue
severity: blocker
<!-- The hold-point wording appears in NEITHER document. rams.blade.php:1978 gates the whole 7.0
     table behind $hasSiteEmerg; with no captured data the pre-existing banner
     "TBC AT SITE INDUCTION — MUST BE COMPLETED BEFORE WORKS COMMENCE." renders instead, and
     "TBC" still appears once in the PDF. ROADMAP criterion 2 is therefore not met for an
     uncaptured project. -->

### 5. CDM duty-holder wording states the RULE-07 anticipated-sole-contractor position
expected: Output contains "21CAV is currently anticipated to be the sole contractor for the AV installation scope..."
result: issue
severity: major
<!-- That sentence appears in neither document. Shipped instead:
     PD = "Not formally appointed at this stage — the client will confirm Principal Designer
     arrangements before works commence if the wider project requires one (CDM 2015 Regulation 5)."
     PC = "If the client appoints a Principal Contractor, 21CAV works to their Construction Phase
     Plan and site arrangements. If 21CAV is confirmed as sole contractor, 21CAV prepares and
     implements the Construction Phase Plan under CDM 2015 Regulation 15."
     Not wrong — avoids the forbidden unequivocal assertion, correctly cites Reg 15, and the PC note
     matches the house rule's conditional Contractor-row wording — but RULE-07's required sentence
     is absent. -->

### 6. Both renderers use 21CAV brand colours
expected: DOCX uses the same brand palette as the PDF
result: issue
reported: "word version is blue and not to brand . PDF is to brand colours so fine"
severity: major
<!-- PDF: #1B7A7A teal (x17), #F4FBFB pale teal, #1A1A2E navy.
     DOCX: 2E74B5 (x102), DEEBF7 (x217), 333333 — these are Microsoft Word's stock
     "Blue, Accent 1" defaults, not a brand choice. DocxBuilderService never had brand colours
     applied. -->

### 7. Tier-1 content parity between PDF and DOCX
expected: Both documents present the same Tier-1 data
result: issue
severity: minor
<!-- Largely aligned: hazard RA refs 16/16, standards refs 7/7, exclusions 3/3, COSHH 6/5,
     PPE-FFP3 5/4. The structural gap is Section 7.0 (present in PDF, absent in DOCX) — covered by
     gap 1 below. PDF also surfaces subsections 6.1-6.6 as headings that the DOCX does not, though
     the underlying content appears present in another form. Recorded for visibility; the 7.0 gap is
     the actionable part. -->

### 8. CDM Client row carries the known client's name
expected: The CDM table's Client row shows the client name; it is not left blank nor the client shown as Principal Designer
result: pending
reason: Could not determine from text extraction — `pdftotext -layout` scrambles the wrapped multi-line CDM cells. The extract renders as an empty Client row with "Gardner Leader LLP" against Principal Designer, which may be an extraction artifact. Needs a human eyeball on the rendered PDF table.

## Summary

total: 8
passed: 2
issues: 5
pending: 1
skipped: 0
blocked: 0

## Gaps

- truth: "The Word (DOCX) RAMS conveys nearest-A&E information, or defers to a section that contains it"
  status: failed
  reason: "DocxBuilderService.php:2155's Welfare First Aid bullet reads 'Nearest A&E — see CDM 2015 — Duty Holders section.' The DOCX renderer has no Section 7.0 (confirmed: 7. EMERGENCY PROCEDURES is followed directly by 7.1 Emergency Contact Numbers; DocxBuilderServiceV2.php:54 documents that it does NOT render a site_emergency block), and the CDM duty-holders section contains no A&E data. The Word RAMS therefore carries no nearest-A&E information at all and misdirects the reader to an unrelated section. This is a REGRESSION introduced by Plan 29-04: pre-Phase-29 the bullet at least stated the (banned but informative) 'Nearest hospital A&E to be identified at site induction'. D-07's 'Welfare defers to Section 7.0' assumed a document structure the DOCX does not have. It escaped 29-04's regression test because that test makes absence assertions (banned string gone, TBC gone) and cannot detect information disappearing entirely."
  severity: blocker
  test: 3
  root_cause: "D-07 was implemented as a cross-reference without verifying the target section exists in the DOCX renderer"
  artifacts:
    - path: "app/Services/DocxBuilderService.php"
      issue: "Line 2155 cross-references a section that does not exist in this renderer and contains no A&E data"
    - path: "app/Services/DocxBuilderServiceV2.php"
      issue: "Line 54 docblock confirms no site_emergency block is rendered"
  missing:
    - "Render a Site-Specific Emergency Details block in the DOCX (mirroring PDF Section 7.0) reading the same site_emergency_resolved value, so the cross-reference has a real target"
    - "OR state the resolved A&E value inline in the DOCX Welfare bullet if adding a 7.0 block is out of scope — but never cross-reference a section that lacks the data"
    - "A regression test asserting the DOCX contains the resolved A&E text (a PRESENCE assertion, not only absence)"

- truth: "Section 7.0 renders the D-05 hold-point line when no verified A&E has been captured"
  status: failed
  reason: "rams.blade.php:1978 gates the entire 7.0 table behind $hasSiteEmerg (computed at :1975 from array_filter over site_emergency). RAMS 103 has no captured site_emergency, so the table never renders and the pre-existing banner 'TBC AT SITE INDUCTION — MUST BE COMPLETED BEFORE WORKS COMMENCE.' (rams.blade.php:2021) renders instead. The D-05 hold-point wording 'must be a 24/7 Emergency Department' appears in NEITHER document, and 'TBC' still appears once in the PDF. Plan 29-04 removed the 'TBC' fallback INSIDE the table but the table is unreachable in the empty-data case, which is the common case. ROADMAP criterion 2 is not met for an uncaptured project."
  severity: blocker
  test: 4
  root_cause: "The $hasSiteEmerg guard short-circuits before the resolver-backed row is reached; the empty case was never the tested path"
  artifacts:
    - path: "resources/views/pdf/rams.blade.php"
      issue: "Line 1978 @if($hasSiteEmerg) makes the resolver-backed A&E row at :1983 unreachable when site_emergency is empty; the :2021 fallback banner still says TBC"
  missing:
    - "Render the A&E row (with the hold-point line) even when site_emergency is otherwise empty — the A&E branch is defined for the empty case by design (D-05), unlike fire warden / defibrillator which legitimately have no default"
    - "Remove or reword the remaining 'TBC AT SITE INDUCTION' banner so it cannot contradict the hold-point line"
    - "A regression test rendering a RAMS with a wholly empty site_emergency and asserting the hold-point text is present and 'TBC' is absent"

- truth: "CDM duty-holder output states RULE-07's anticipated-sole-contractor position"
  status: failed
  reason: "RULE-07 requires the verbatim sentence '21CAV is currently anticipated to be the sole contractor for the AV installation scope. The client shall confirm whether the overall project involves, or is likely to involve, more than one contractor before works commence.' (standards-and-legislation.md:23-28). It appears in neither rendered document. The shipped constants (RamsComplianceUpgradeService.php:1121 and :1131) say something different — defensible (they avoid the forbidden unequivocal assertion, correctly cite Regulation 15, and the PC note matches the house rule's conditional Contractor-row wording at :32-34) but not what RULE-07 asks for."
  severity: major
  test: 5
  root_cause: "The constants were authored as a paraphrase rather than the prescribed sentence; CONTEXT.md left exact wording to planner discretion and no test asserted the verbatim string"
  artifacts:
    - path: "app/Services/Rams/RamsComplianceUpgradeService.php"
      issue: "DEFAULT_PRINCIPAL_DESIGNER_NOTE (:1121) and DEFAULT_PRINCIPAL_CONTRACTOR_NOTE (:1131) omit the required anticipated-sole-contractor sentence"
  missing:
    - "Decide with the user whether RULE-07 means the verbatim sentence must appear, or whether the shipped wording satisfies its intent — then either add the sentence or restate RULE-07 (with the same RESTATED note discipline used for RULE-08)"
    - "A test asserting whichever wording is settled on, so it cannot drift again"

- truth: "The DOCX renderer uses 21CAV brand colours, matching the PDF"
  status: failed
  reason: "User-reported and measured. PDF palette: #1B7A7A teal (17 uses), #F4FBFB pale teal tint, #1A1A2E navy. DOCX palette: 2E74B5 (102 uses), DEEBF7 (217 uses), 333333 — these are Microsoft Word's stock 'Blue, Accent 1' theme defaults. DocxBuilderService never had brand colours applied; it is not a drift but an original omission predating Phase 29."
  severity: major
  test: 6
  root_cause: "DocxBuilderService was built with Office default accent colours and no brand palette was ever introduced"
  artifacts:
    - path: "app/Services/DocxBuilderService.php"
      issue: "Hardcoded '2E74B5' and 'DEEBF7' (Word default accent-1 shades) where brand teal belongs"
  missing:
    - "Substitute the brand palette: 2E74B5 -> 1B7A7A, DEEBF7 -> F4FBFB, dark headers -> 1A1A2E"
    - "Define the palette as named constants shared with (or mirroring) the PDF's values so the two renderers cannot diverge again"
    - "A test asserting no Office-default accent hex remains in the DOCX builder"

human_verification:
  - test: "CDM duty-holder table — Client row"
    expected: "The Client row shows the client's name (Gardner Leader LLP); the client is NOT shown as Principal Designer and the Client row is not blank"
    why_human: "pdftotext -layout scrambles the wrapped multi-line CDM cells, so the extract cannot distinguish a genuine row-assignment bug from an extraction artifact. Needs a human to open the PDF and read the table. house-rules.md is explicit that the Client row uses the known client's name."
---

_UAT performed: 2026-09-12 against production RAMS 103 (21CQ30949-01-OPS, Gardner Leader LLP)_
_Documents: `21CQ30949-01-OPS - Gardner Leader LLP.pdf`, `rams_103_20260912_162944_493291.docx`_
