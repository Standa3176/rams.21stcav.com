---
status: implementing_commit_3
trigger: "Fix RAMS DOCX builder to match PDF output — 12 defects (D1-D12)"
created: 2026-05-14T22:00:00Z
updated: 2026-05-15T09:30:00Z
---

## Current Focus

hypothesis: All 12 defects map to specific surfaces in `app/Services/DocxBuilderService.php` (1433 lines) — the active RAMS DOCX renderer. The PDF blade (`resources/views/pdf/rams.blade.php`) gets pre-processed by `RamsDisplayPatchService` (fixes doc_author/personnel/contacts) AND `RamsComplianceUpgradeService` (adds permit/fixings/qa/cdm/material_handling data) AND uses `$rams->created_at->format('d/m/Y')` for the date — the DOCX renderer reads stale `$project` fields directly without those patches, has bugs in its own helpers (riskBadge off-by-one, step prefix doubled), uses a flat 4-col legend instead of the 5×5 grid, mis-orders the CDM section, and never renders 6.7–6.10 / Permits & Authorisations / COSHH / Environmental Management / Welfare Arrangements / Appendix A.
test: Read DocxBuilderService.php sections-by-section, cross-reference each defect to a specific line/method, and read RamsDisplayPatchService + RamsComplianceUpgradeService + rams.blade.php to confirm what the PDF does differently for each.
expecting: A complete defect→surface map ready for fix. Surface checkpoint to user before code lands.
next_action: CHECKPOINT — surface findings, confirm scope and split strategy.

## Symptoms

expected: DOCX field-for-field equivalent to PDF for the same RamsDocument — same data values, same sections in same order, same risk band labels, clean step headings.

actual: D1-D12 as detailed in symptoms block:
  Data: D1 Prepared By = client email "Marius@LIGHTFORMS.COM"; D2 Date = "May 2026"; D3 ROOMS blank; D4 §6.1 "Lead Engineer / 1 / CSCS Card, AV installation experience" template fallback; D5 Site Contact empty
  Structure: D6 Flat 4-col risk legend (not 5x5); D7 RA08/09/11 score=6 labelled LOW (should be MED); D8 "Step 1 — Step 1 —" duplication; D9 CDM section between H&S and Scope (should be after Method Statement)
  Missing: D10 §6.7-6.10 (Material Handling, Permit & Isolation, Fixings, Supervision/QA); D11 Permits & Authorisations, COSHH, Environmental Management, Welfare Arrangements; D12 Appendix A Toolbox Talk Record

errors: None — silent rendering bugs

reproduction: Package 124 (RamsDocument 81). Generated DOCX = rams_81_20260514_210348.docx vs PDF "21CQ30451-01-OPS - Light Forms Ltd.pdf". Extracted text dump confirmed all defects.

started: DOCX builder presumably not kept in sync with PDF blade as PDF was extended (Tier 1 compliance upgrade, room consolidation, Phase 23 work)

## Eliminated
<!-- APPEND only — no false hypotheses pursued, single investigation pass -->

## Evidence
<!-- APPEND only — facts discovered -->

- timestamp: 2026-05-14T22:05:00Z
  checked: Active DOCX renderer wiring (which class actually runs)
  found: `RamsController` still injects `WordDocumentService` (line 40) but never calls it. ALL render paths route through `RamsDocumentRendererService::render()` → `DocxBuilderService::build()`. `WordDocumentService` is dead code. Confirmed by grep: zero call sites for `$this->wordDoc`, `new WordDocumentService`, or `WordDocumentService::class`.
  implication: All 12 fixes go into `app/Services/DocxBuilderService.php`. `WordDocumentService.php` can be ignored (or deleted later as a separate cleanup).

- timestamp: 2026-05-14T22:08:00Z
  checked: DOCX section build order vs PDF section order
  found: DocxBuilderService::build() lines 86-96:
    Cover → Document Control → Company Info → H&S Policy → **CdmSection** → ScopeOfWorks → EngineerFindingsByRoom → RiskAssessment → MethodStatement → Emergency → SignOff
  PDF order (rams.blade.php sec-heading hits): Cover → DocControl → CompanyInfo → H&S Policy → ScopeOfWorks → Risk Assessment → Method Statement (6.1-6.10) → **Decommissioning** → **Permits & Authorisations** → **CDM 2015 Duty Holders** → **COSHH** → **Environmental Management** → **Welfare Arrangements** → Emergency Procedures → **Commissioning Criteria** → Sign-Off → **Appendix A — Toolbox Talk Record**
  implication: D9 confirmed. CDM is currently emitted between H&S and Scope — needs to move to after Method Statement (where PDF places it). 6 sections completely missing from DOCX rendering.

- timestamp: 2026-05-14T22:12:00Z
  checked: D1 Prepared By — where DOCX reads from, where the email leak comes from
  found: DocxBuilderService line 179 reads `$project['doc_author']`. Same key the PDF reads. The PDF stays clean because `RamsController::downloadPdf()` calls `patchRamsForDisplay($rams)` → `RamsDisplayPatchService::patch()` which (lines 90-107) detects when `doc_author` is empty, an email address, OR matches the client_contact_name, and rewrites it to `project_manager` or owner name. The DOCX rendering paths (`BuildRamsDocumentJob`, `RamsController::updateAndDownload`, `RamsController::download` rebuild) NEVER apply this patch service.
  implication: D1 fix = either (a) call `RamsDisplayPatchService::patch($record)` from inside `DocxBuilderService::build()` before extracting `$data`, or (b) inline the same email-detection/owner-fallback logic in DocxBuilderService::buildCoverPage(). Option (a) is cleanest; option (b) limits blast radius. **Recommend (a)** because it also fixes D5 (site_contact resolution chain in patch service) and other personnel issues in one shot.

- timestamp: 2026-05-14T22:14:00Z
  checked: D2 Date — DOCX vs PDF date source
  found: DocxBuilderService line 160: `$project['date'] ?? now()->format('F Y')` — falls back to month-year format. PDF line 347-349: `$docDate = $rams->created_at ? $rams->created_at->format('d/m/Y') : now()->format('d/m/Y')`. RamsDataBuilderService line 209 sets `'date' => now()->format('F Y')` at build time (which then never gets corrected by patch service).
  implication: D2 fix: in DocxBuilderService cover + document-control rendering, replace `$project['date']` lookup with `$record->created_at?->format('d/m/Y') ?: now()->format('d/m/Y')`. Minimal surgical change (2 line edits — cover at 160, DocumentControl at 215).

- timestamp: 2026-05-14T22:16:00Z
  checked: D3 Rooms blank — DOCX cover ROOMS field source
  found: DocxBuilderService lines 122-127 builds `$roomsFiltered` from `$project['rooms']`. But `$project['rooms']` is NEVER populated by RamsDataBuilderService (only `data['rooms']` from ProjectContext and `data['project']['rooms_text']`). Falls back to `$project['rooms_text']` which is also empty when no form/package rooms_text was set. PDF blade (lines 322-345) builds `$roomsList` from THREE sources in priority order: `reviewed_data['rooms']` → `reviewed_data['room_overviews'][n]['room']` → `project['rooms_text']`. The first two sources are NEVER consulted by DocxBuilderService.
  implication: D3 fix: in `buildCoverPage()` AND `buildScopeOfWorks()`, replace the rooms resolution with the same priority chain as the PDF, reading `$record->reviewed_data['rooms']` then `$record->reviewed_data['room_overviews']` then `$project['rooms_text']`. Two surface lines (122-127, 317-321).

- timestamp: 2026-05-14T22:18:00Z
  checked: D4 §6.1 Team Requirements generic placeholder
  found: DocxBuilderService line 904: `if (! empty($team))` — when team is empty, falls back to hardcoded "Lead Engineer / 1 / CSCS Card, AV installation experience" (line 939-941). The user's saved generated_data['team'] is empty for this record. Even when populated, DocxBuilderService line 905-908 has a tiny req map (`'lead engineer' => 'CSCS Card, IPAF (if applicable)…'`, `'project manager' => 'SMSTS or equivalent'`). PDF blade lines 1338-1344 has full PDF req map ('project manager' → 'SMSTS or equivalent. CSCS Card. First Aid at Work certificate. Responsible for site management and client liaison.'), AND lines 1304-1324 has fallback logic to build team from `$project['project_manager']`/`lead_engineer`/`additional_engineers`/`programmer` strings when the team array is empty.
  implication: D4 fix: (a) port the PDF $reqMap verbatim into DocxBuilderService::buildMethodStatement(); (b) port the empty-team fallback that reads `$project['project_manager']` etc. so even when `data['team']` is empty, we synthesise a sensible team from the project personnel fields.

- timestamp: 2026-05-14T22:20:00Z
  checked: D5 Site Contact placeholder
  found: DocxBuilderService line 1124: `$siteContact = $project['site_contact'] ?? $formData['site_contact'] ?? ''` — bare empty string fallback. PDF line 1835: `{{ $clientContact ?: ($siteContact ?: 'TBC at site induction') }}` — literal placeholder.
  implication: D5 fix: extend the resolution chain in `buildEmergencyProcedures()` to fall back through `client_contact_name` (or full `$clientContact` concat) then literal 'TBC at site induction'. One line.

- timestamp: 2026-05-14T22:22:00Z
  checked: D6 Risk legend — DOCX flat table vs PDF 5×5 grid
  found: DocxBuilderService lines 758-775 emit a flat 4-column table (Risk Level / Score Range / Description / Action) populated from `$data['risk_colour_key']`. PDF lines 1197-1227 emit:
    (1) A 5×5 grid: rows=Likelihood 1-5 (Unlikely → Almost Certain), cols=Severity 1-5 (Minor → Fatal), each cell shows L×S coloured by band
    (2) A 3-band footer legend: 1-4 LOW (green), 5-9 MEDIUM (amber), 10+ HIGH (red)
  implication: D6 fix: replace DocxBuilderService::buildRiskAssessment lines 755-778 with: (a) 5×5 PhpWord table — 6 rows × 6 cols, with axis headers and L×S product per cell coloured via `riskColour()` helper; (b) a 3-band footer row table mirroring the PDF "rk-band" format. Risk colour palette also needs alignment — PDF uses 3 bands (#D4EDDA, #FFF3CD, #F8D7DA); DOCX has 4 (RISK_GREEN/AMBER/ORANGE/RED). For visual parity, drop the ORANGE band entirely (or keep palette internally but classify 10+ as a single RED band). Trace edits: lines 758-778 (replace legend), 1357-1365 (`riskColour()` band thresholds).

- timestamp: 2026-05-14T22:24:00Z
  checked: D7 Risk badge — RA08/09/11 score=6 labelled LOW
  found: DocxBuilderService lines 1368-1375:
    ```
    riskBadge(): $score >= 10 => 'HIGH', $score >= 7 => 'MED', default => 'LOW'
    ```
    So scores 5 and 6 fall through to LOW. The PDF legend itself (DOCX line 766 + colour_key data) says LOW=1-4, MEDIUM=5-9, HIGH=10+. Direct contradiction.
  implication: D7 fix: one-line — change `$score >= 7` to `$score >= 5`. Also fix `riskColour()` (lines 1357-1365) to use 3 bands matching the PDF: <=4 green, <=9 amber, default red. Drop the orange band threshold.

- timestamp: 2026-05-14T22:26:00Z
  checked: D8 Step header duplication
  found: DocxBuilderService line 1063 strips `/^\d+[\.\-–—\s]+/` from raw title — handles "1. Foo" or "1 — Foo" but NOT "Step 1 — Foo". Then line 1064 builds `'Step ' . ($i + 1) . ' — ' . $cleanTitle`. If the AI's title is already "Step 1 — Arrival & Site Induction", the strip leaves it intact and we prepend "Step 1 — " again → "Step 1 — Step 1 — Arrival & Site Induction". Confirmed in /tmp/rams81_text.txt: every step row shows duplicated "Step N — Step N —" pattern.
  implication: D8 fix: extend the strip regex to also remove a leading "Step N — " prefix: `preg_replace('/^(?:Step\s+\d+\s*[\.\-–—:]\s*|\d+[\.\-–—\s]+)/i', '', $rawTitle)`. One line.

- timestamp: 2026-05-14T22:27:00Z
  checked: D9 CDM section placement
  found: DocxBuilderService::build() lines 89-96 emit `buildCdmSection` immediately after H&S Policy and before ScopeOfWorks. PDF emits CDM in the post-Method-Statement compliance block (between Permits & Authorisations and COSHH Assessment).
  implication: D9 fix: move `buildCdmSection()` from line 90 to a position after `buildMethodStatement()` (after line 94) but before `buildEmergencyProcedures()`. This is part of the larger restructuring needed for D10/D11.

- timestamp: 2026-05-14T22:29:00Z
  checked: D10/D11/D12 missing sections — where the data lives and how the PDF renders each
  found: All required data is already in `$data` post-`RamsComplianceUpgradeService::upgrade()`:
    - `$data['material_handling_derived']` → §6.7 Material Handling (PDF blade 1560-1607)
    - `$data['permit_and_isolation']['rules']` → §6.8 Permit & Isolation (PDF 1612-1618)
    - `$data['fixings_control']['rules']` → §6.9 Fixings (PDF 1623-1629)
    - `$data['supervision_and_qa']['responsibilities']` → §6.10 Supervision/QA (PDF 1634-1640)
    - Static prose → Permits & Authorisations block (PDF 1674-1695)
    - Static prose → COSHH Assessment (PDF 1748-1772)
    - Static prose → Environmental Management (Waste + Noise/Dust) (PDF 1777-1802)
    - Static prose → Welfare Arrangements (PDF 1807-1817)
    - Static prose + signature table → Appendix A Toolbox Talk (PDF 1945-1972)
  implication: D10-D12 fix: add new private methods in DocxBuilderService for each missing block:
    - `buildPermitAndIsolation()` (§6.8 inside Method Statement section)
    - `buildFixingsControl()` (§6.9)
    - `buildSupervisionAndQA()` (§6.10)
    - `buildMaterialHandling()` (§6.7 — place between PPE/Access and Pre-Install)
    - `buildPermitsAndAuthorisations()` (compliance block)
    - `buildCoshhAssessment()` (compliance block)
    - `buildEnvironmentalManagement()` (compliance block)
    - `buildWelfareArrangements()` (compliance block)
    - `buildAppendixA()` (after Sign-Off)
  Section numbering already handles `6.7`/`6.5` etc. logic — DOCX uses static "6.X" naming since PPE matrix is always present from the upgrade service. Section render order in build() will need updating to interleave these correctly.

- timestamp: 2026-05-14T22:30:00Z
  checked: WordDocumentService.php — risk of breaking the legacy path
  found: WordDocumentService.php (790 lines) is fully decoupled from RAMS render path. Zero call sites. Even RamsController constructor injects it but never references `$this->wordDoc` anywhere. The class can be left untouched without affecting fixes.
  implication: Don't touch WordDocumentService.php. Don't refactor the unused constructor injection — separate cleanup task.

## Resolution

root_cause:
  - Single class — `app/Services/DocxBuilderService.php` (1433 lines, the active RAMS DOCX renderer) — has diverged from the PDF blade template (`resources/views/pdf/rams.blade.php`, 1976 lines) across 12 axes.
  - The PDF benefits from TWO services that the DOCX path silently bypasses:
    1. `RamsDisplayPatchService::patch()` — fixes stale/leaked `doc_author`, resolves personnel chain, infers client contact, normalises rooms_text and scope_items. Applied by `RamsController::downloadPdf()`. NOT applied by `BuildRamsDocumentJob`, `RamsController::updateAndDownload()`, or `RamsController::download()`.
    2. `RamsComplianceUpgradeService::upgrade()` — adds `permit_and_isolation`, `fixings_control`, `supervision_and_qa`, `material_handling_derived`, `cdm_duty_holders`. THIS IS CALLED by both DOCX and PDF paths (via `RamsBuilderService::buildFromReview` line 254, `updateAndDownload` line 546, `download` line 641). So the data IS present in `$data` — DocxBuilderService just never renders it.
  - DocxBuilderService also has 3 self-contained rendering bugs unrelated to the upstream patch service: D7 (risk band off-by-one in `riskBadge()`), D8 (double "Step N —" prefix in `buildMethodStatement()`), D9 (CdmSection emitted in wrong position in `build()`).
  - DocxBuilderService is structurally missing 9 sections that the PDF renders: 6.7 Material Handling, 6.8 Permit & Isolation, 6.9 Fixings, 6.10 Supervision/QA, Permits & Authorisations, COSHH, Environmental Management, Welfare Arrangements, Appendix A. All input data is present in `$data` post-upgrade.

fix: TBD — surfacing to user at checkpoint. Proposed scope split:
  Commit 1 — Data parity (D1, D2, D3, D4, D5): apply `RamsDisplayPatchService::patch()` at the top of `DocxBuilderService::build()`, fix the date source to `$record->created_at`, port the PDF rooms resolution chain into `buildCoverPage()`/`buildScopeOfWorks()`, port the PDF $reqMap + empty-team fallback into `buildMethodStatement()`, add the "TBC at site induction" placeholder in `buildEmergencyProcedures()`.
  Commit 2 — Structural fixes (D6, D7, D8, D9): rewrite `buildRiskAssessment()` legend block to emit a 5×5 grid + 3-band footer, fix `riskBadge()` to use `>= 5` threshold, fix `riskColour()` to use 3-band palette, fix `buildMethodStatement()` step regex to strip leading "Step N — ", move `buildCdmSection()` invocation from line 90 to after `buildMethodStatement()`.
  Commit 3 — Missing sections (D10, D11, D12): add 9 new private methods, update section order in `build()` to interleave them correctly, add tests asserting each new section renders.

verification: Generate DOCX for RamsDocument 81 after each commit, extract document.xml text, assert each defect-specific fragment is present/absent. Add feature test `tests/Feature/Rams/DocxBuilderPdfParityTest.php` with per-defect assertions.

files_changed:
  - app/Services/DocxBuilderService.php
  - tests/Feature/Rams/DocxBuilderPdfParityTest.php

## Implementation Log

- 2026-05-15T09:20Z — Commit 1 landed: 145c7a3 fix(rams-docx): cover + method-statement data parity with PDF (D1-D5). Surface: app/Services/DocxBuilderService.php (241 ins / 31 del). Tests: tests/Feature/Rams/DocxBuilderPdfParityTest.php (NEW, 8 cases, 32 assertions).
- 2026-05-15T09:23Z — Commit 2 landed: da83d61 fix(rams-docx): risk matrix grid + medium-risk threshold + step header + CDM placement (D6-D9). Surface: app/Services/DocxBuilderService.php (94 ins / 32 del). Tests: +5 cases (riskBadge thresholds, riskColour palette, 5x5 grid, step de-duplication, CDM positional ordering).
- 2026-05-15T09:30Z — Commit 3 staging: D10-D12 missing sections. 9 new private methods in DocxBuilderService.php (buildMaterialHandling, buildPermitAndIsolation, buildFixingsControl, buildSupervisionAndQA, buildPermitsAndAuthorisations, buildCoshhAssessment, buildEnvironmentalManagement, buildWelfareArrangements, buildAppendixA). Interleaved in build() in the same order as PDF. 9 new tests in DocxBuilderPdfParityTest.php (one per section).
- 2026-05-15T09:32Z — Pre-commit verification: php -l clean on both files. DocxBuilderPdfParityTest 22/22 green (134 assertions). Phase23InvariantGuardTest + V13SurfacesUntouchedTest + RamsRenderRegressionTest 20/20 green (174 assertions). No regressions.
