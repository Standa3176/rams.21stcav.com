---
quick_id: 260712-twi
slug: tier-1-av-rams-content-upgrade-baseline
date_completed: 2026-07-12
type: quick
autonomous: true
outcome: shipped
commits:
  - 1091ea4  # Task 1 — Tier1RamsDefaultsService + config/rams_tier1.php + RamsBuilderService wiring
  - 25b42d7  # Task 2 — PDF Section 5 baseline hazards fallback + MethodStatementPrompt AV bullets
  - 0403fb1  # Task 3 — site emergency form + controller persistence + PDF 7.0 details table + RIDDOR matrix
  - fbcd76d  # Task 4 — structured COSHH inventory table with GHS hazard codes
  - 503461c  # Task 5 — PDF TOC + standards table + PPE colour + sections 6.11 (coordination) and 6.12 (IT/network safety)
files_touched:
  - config/rams_tier1.php                                       # NEW
  - app/Services/Rams/Tier1RamsDefaultsService.php              # NEW
  - app/Services/RamsBuilderService.php                         # EDIT — 9th ctor dep + 2 injection points
  - app/Core/AI/Prompts/MethodStatementPrompt.php               # EDIT — 4 new AV Requirement bullets
  - app/Http/Controllers/RamsController.php                     # EDIT — 8 validation rules + persist block + mirror to generated_data
  - resources/views/pdf/rams.blade.php                          # EDIT — 6 discrete additions (hazards fallback / TOC / standards table / PPE colour paragraph / 7.0 + RIDDOR / COSHH table / 6.11 / 6.12)
  - resources/views/rams/review.blade.php                       # EDIT — Site Emergency fieldset in WORKS & PERMITS tab
  - tests/Unit/Services/Rams/Tier1RamsDefaultsServiceTest.php   # NEW — 5 tests
  - tests/Unit/Services/RamsBuilderServiceTest.php              # EDIT — pass new dep in makeService()
  - tests/Feature/Rams/Tier1BaselineHazardsRenderTest.php       # NEW — 3 tests
  - tests/Feature/Rams/Tier1SiteEmergencyFormAndRenderTest.php  # NEW — 3 tests
  - tests/Feature/Rams/Tier1CoshhTableRenderTest.php            # NEW — 3 tests
  - tests/Feature/Rams/Tier1PdfStructuralPolishTest.php         # NEW — 5 tests
tests_new: 19  # 17 planned + 2 makeService constructor fix pass-throughs are implicit — actual new-test methods: 5 unit + 14 feature = 19
tests_green: 65  # sweep of Tier1 + existing critical Rams suites (see verification section)
migrations: 0
---

# ⚠️ H&S PROFESSIONAL SIGN-OFF NEEDED

**The AV baseline hazards, COSHH inventory (GHS/CLP hazard codes), and
control measures in `config/rams_tier1.php` are best-effort industry-
standard defaults drafted for UK AV install works.** They MUST be
reviewed by 21CAV's H&S consultant before wider client-facing use of
this fallback layer. Engineers can override any of these values per-
project via the review form — the config is a fallback layer only, not
a fixed compliance statement.

Sign-off scope:
- 8 canonical AV baseline hazards + control measures (Working at
  Height / MHOR / Electrical Isolation / Slips-Trips / Drilling-Into-
  Unknown / Occupied-Space / Access Equipment / Fatigue).
- 7 COSHH products with GHS/CLP hazard codes (H225 / H319 / H336 /
  H360 / H373 / H317 / H222 / H332 / H334 / H351 / H315).
- 9 standards references (BS 7671 / BS 6701 / BS EN 60849 / BS 8492 /
  CDM 2015 / HSG 47 / HSG 273 / AVIXA F502.01 / PUWER 1998).
- RIDDOR routing matrix: HSE Incident Contact Centre 0345 300 9923 +
  F2508 / F2508A + timescales (immediate / 15 days / 10 days).

Recommended next step: schedule a review with 21CAV's H&S consultant.
Any values they revise become the new config default, no code change
required beyond editing `config/rams_tier1.php`.

---

# Phase Quick Task 260712-twi: Tier-1 AV RAMS Content Upgrade Summary

One-liner: safety-net fallback layer that injects industry-standard AV
baseline hazards, COSHH inventory (with GHS codes), UK standards
references, TOC page, RIDDOR routing matrix, site emergency capture,
and Method Statement subsections 6.11 (coordination) + 6.12 (IT/network
safety) into every RAMS whenever the engineer's review form was skipped
or partially filled. Engineer values ALWAYS win — the layer is fallback
only, kill-switchable via `RAMS_TIER1_DEFAULTS=false`.

## Objective (recap)

Close the 20 verification gaps (G-1 to G-20) surfaced on Tilda RAMS #87
so that no blank / skeleton RAMS goes out the door. The gap analysis on
RAMS #87 showed that when the engineer skipped the review form the
resulting PDF was missing an emergency section, had no COSHH detail,
showed no risk register for AV-baseline hazards, and lacked a
standards/TOC frontmatter — this bundle bakes that content in as a
project-wide default.

## Tasks

### Task 1 — `feat(rams): Tier1RamsDefaultsService + config/rams_tier1.php wired into build pipeline` (1091ea4)

Ships:
- New `config/rams_tier1.php` with the safety-critical warning header at
  the top of the file (before the `return` block) — 8 canonical baseline
  hazards + 7 COSHH products + 9 standards references + 4 AV prompt
  bullets + `enabled` kill-switch (`env('RAMS_TIER1_DEFAULTS', true)`).
- New `App\Services\Rams\Tier1RamsDefaultsService` with one public
  method `injectDefaultsIntoRamsData(array $data): array`. Fallback-only
  behaviour: engineer values are preserved verbatim, missing values get
  the config defaults folded in.
- `RamsBuilderService` constructor bumped from 8 to 9 dependencies (9th
  is `Tier1RamsDefaultsService`). Injection points added to BOTH
  `runFromReview()` and `runPipeline()` immediately after
  `RamsComplianceUpgradeService::upgrade($data)` so tier-1 defaults land
  in `generated_data` for both PDF + DOCX pipelines.
- 5 unit tests locking the fallback behaviour (missing hazards →
  baseline / engineer values → preserved / empty array → treated as
  missing / standards not clobbered / kill-switch → no-op).

### Task 2 — `feat(rams): AV baseline hazard register fallback + MethodStatementPrompt AV bullets` (25b42d7)

Ships:
- `resources/views/pdf/rams.blade.php`: defensive render-time fallback
  at line ~309 area — when `$hazards` is empty AND
  `rams_tier1.enabled` is true, the 8 canonical baseline AV hazards
  render in Section 5. Belt-and-braces path for legacy `generated_data`
  records built before Task 1 shipped.
- `MethodStatementPrompt::build()`: 4 AV-specific requirement bullets
  appended to the `Requirements:` list inside the prompt — live-services
  isolation & test-before-touch, off-live-path control-system
  programming, other-trade coordination for penetration/fixing,
  power-cycle/network-fail commissioning verification.
- 3 feature tests covering empty-hazards fallback, engineer-override,
  and disabled-flag fallback-skip.

### Task 3 — `feat(rams): site emergency capture on review form + PDF 7.0 details table + RIDDOR routing matrix` (0403fb1)

Ships:
- `resources/views/rams/review.blade.php`: new Site Emergency Details
  fieldset at the end of WORKS & PERMITS tab. 8 inputs (nearest A&E
  hospital + address, fire assembly point, fire warden name/phone,
  first aider name/phone, defibrillator location) with red hint text
  warning that empty submission → red banner on the PDF.
- `RamsController::updateAndDownload()`: 8 new `site_emergency_*`
  validated fields (nullable strings max:255 / max:500 for address),
  persisted into `reviewed_data['site_emergency']` AND mirrored into
  `generated_data['site_emergency']` so the PDF template's primary
  lookup finds them without needing to fall back to `$rams->reviewed_data`.
- `resources/views/pdf/rams.blade.php` Section 7 gains:
  - New `7.0 Site-Specific Emergency Details` block inserted BEFORE
    `7.1`. Populated table when data present, red warning banner
    ("TBC AT SITE INDUCTION — MUST BE COMPLETED BEFORE WORKS COMMENCE",
    2pt `#c00` border) when empty.
  - New `RIDDOR Reporting Matrix` table appended AFTER the 7.2 list.
    4 rows: death/major-injury (immediate / HSE 0345 300 9923),
    over-7-day incapacity (15 days / F2508), dangerous occurrence
    (10 days / F2508), occupational disease (10 days / F2508A).
- 3 feature tests covering form persistence, PDF populated-table
  render, and PDF empty-warning-banner render.

### Task 4 — `feat(rams): structured COSHH inventory table with GHS hazard codes` (fbcd76d)

Ships:
- `resources/views/pdf/rams.blade.php` COSHH section: replaces the
  legacy 4-item bullet list with a structured 7-product table sourced
  from `$data['coshh_baseline']` (Task 1 fold-in) or
  `config('rams_tier1.coshh_products')` directly. Columns: Product /
  Substance, Typical Use, GHS Hazard Codes (Consolas monospace),
  Control Measures. Includes trailing SDS-binder paragraph pointing
  engineers to Vehicle 1 tool cabinet.
- Legacy 4-item bullet list preserved verbatim in an `@else` branch as
  a safety-net for when `rams_tier1.enabled` is false (superset change,
  not a replacement — nothing is deleted).
- Engineer-added COSHH bullets (`$data['coshh']`) continue to render
  BELOW the baseline table via the existing
  `@if(! empty($data['coshh']))` block. Position-check assertion in
  test 2 locks this ordering.
- 3 feature tests covering baseline render, engineer additions
  preserved, and disabled-flag fallback to legacy bullet list.

### Task 5 — `feat(rams-pdf): TOC + standards table + PPE colour + sections 6.11 (coordination) and 6.12 (IT/network safety)` (503461c)

Ships (5 surgical additions to `resources/views/pdf/rams.blade.php`):
- **Table of Contents** page inserted AFTER cover-table 3 and BEFORE
  Section 1 (page-break). 10 rows enumerating all sections + supplementary
  + Appendix A. Page numbers omitted per note — dynamic pagination is
  authoritative.
- **Standards & Guidance Applicable to This Works** table appended to
  Section 3 (after the third H&S Policy paragraph). Sources from
  `$data['standards_references']` (Task 1) or config directly. 9 UK
  standards each with a per-project "Applies To" column.
- **Hi-vis colour convention** paragraph inserted INSIDE the 6.3 PPE
  Matrix block, between the heading and the table. `{company}` = orange
  (EN ISO 20471 class 2 minimum) with site-rules-override caveat.
- **Section 6.11 Coordination with Other Trades** (5 bullets — ceiling
  installer, partition contractor, IT contact, penetration confirmation,
  escalation to PM for disputed works).
- **Section 6.12 IT / Network Integration Safety** (5 bullets — client
  IT notification, off-live-path programming, firmware update
  authorisation, credential handover, power-cycle/network-fail
  commissioning).
- No renumbering downstream — existing `preInstallNum` / `methodNum` /
  `mhNum` / `permitNum` / `fixingsNum` / `qaNum` dynamic-numbering
  pattern preserved verbatim.
- 5 feature tests, one per structural addition.

## Verification

### Automated

Ran `php artisan test --filter="Tier1|RamsPdfScopeTest|RamsPdfRoomOverviewsTest|RamsUpdateAndDownloadTransactionTest|ReviewWorkflowTest|RamsBuilderServiceTest|RamsComplianceUpgradeServiceCacheTest"` after Task 5.

Result: **65 passed / 260 assertions / 18.21s duration**.

Breakdown:
- 5 new `Tier1RamsDefaultsServiceTest` (Task 1)
- 3 new `Tier1BaselineHazardsRenderTest` (Task 2)
- 3 new `Tier1SiteEmergencyFormAndRenderTest` (Task 3)
- 3 new `Tier1CoshhTableRenderTest` (Task 4)
- 5 new `Tier1PdfStructuralPolishTest` (Task 5)
- Existing `RamsBuilderServiceTest` — 7 tests (constructor updated to pass real Tier1RamsDefaultsService instance, no mock)
- Existing `ReviewWorkflowTest` — 14 tests (container auto-resolves new dep, no changes needed)
- Existing `RamsPdfScopeTest` + `RamsPdfRoomOverviewsTest` — 9 tests (PDF template edits don't affect existing render paths)
- Existing `RamsUpdateAndDownloadTransactionTest` — 1 test (new validation rules non-blocking, mirror-to-generated_data doesn't break transaction rollback)
- Existing `RamsComplianceUpgradeServiceCacheTest` + siblings

### Lint

`php -l` clean on every edited/created .php file:
- `config/rams_tier1.php`
- `app/Services/Rams/Tier1RamsDefaultsService.php`
- `app/Services/RamsBuilderService.php`
- `app/Core/AI/Prompts/MethodStatementPrompt.php`
- `app/Http/Controllers/RamsController.php`
- `tests/Unit/Services/Rams/Tier1RamsDefaultsServiceTest.php`
- `tests/Unit/Services/RamsBuilderServiceTest.php`
- All 4 new feature test files.

Blade compile check via `php artisan view:clear` between tasks — clean each time.

### Config sanity

`config/rams_tier1.php` structure verified:
- `enabled = true` (env `RAMS_TIER1_DEFAULTS`)
- 8 baseline hazards each with ≥3 controls
- 7 COSHH products each with ≥2 controls + GHS codes
- 9 standards references
- 4 AV prompt bullets

## Deviations from Plan

### Auto-added — `[Rule 2 - Correctness] Update pre-existing RamsBuilderServiceTest to pass new constructor dep`

- **Found during:** Task 1
- **Issue:** Existing `tests/Unit/Services/RamsBuilderServiceTest.php::makeService()` constructs `RamsBuilderService` with 8 mocked deps. Adding a 9th ctor dep breaks the test.
- **Fix:** Added `use App\Services\Rams\Tier1RamsDefaultsService;` import and passed a real instance `new Tier1RamsDefaultsService()` (pure array work + config reads — no mock needed).
- **Files modified:** `tests/Unit/Services/RamsBuilderServiceTest.php`
- **Included in commit:** 1091ea4 (Task 1)

### Auto-added — `[Rule 2 - Correctness] Mirror site_emergency into generated_data`

- **Found during:** Task 3
- **Issue:** PDF template primary lookup is `$data['site_emergency']`. Without mirroring the review-form input into `generated_data`, the PDF would only find the value via the `$rams->reviewed_data['site_emergency']` fallback — which works but is a legacy path and would create a lag between review-save and PDF render if the pipeline rebuilt `generated_data`.
- **Fix:** After the `$reviewedData['site_emergency'] = [...]` block in `RamsController::updateAndDownload()`, added `$generatedData['site_emergency'] = $reviewedData['site_emergency'];` so the PDF template's primary lookup succeeds directly.
- **Files modified:** `app/Http/Controllers/RamsController.php`
- **Included in commit:** 0403fb1 (Task 3)

No other deviations. Plan executed exactly as written.

## Gap Closure Summary (RAMS #87)

| Gap  | Description                                       | Task | Closed by                                    |
| ---- | ------------------------------------------------- | ---- | -------------------------------------------- |
| G-1  | No baseline AV hazards in Section 5               | 1+2  | `hazards` fallback (build + render)          |
| G-2  | Working at Height controls not specific           | 1    | 6 IPAF/PASMA/EN131 controls in config        |
| G-3  | Electrical Isolation controls missing             | 1    | 5 BS 7671 test-before-touch controls         |
| G-4  | Manual Handling scores missing                    | 1    | MHOR 1992 + pre/post scoring in baseline     |
| G-5  | Slips/Trips from cabling not addressed            | 1    | Cable-cover / matting controls in baseline   |
| G-6  | Drilling into unknown services not addressed      | 1    | HSG 47 + detector-scan + pilot-drill         |
| G-7  | Occupied-space controls missing                   | 1    | Segregation + client-transit controls        |
| G-8  | Fatigue controls missing                          | 1    | WTR 1998 + 10-hour cap in baseline           |
| G-9  | No nearest hospital captured                      | 3    | site_emergency.nearest_hospital + PDF 7.0    |
| G-10 | No fire assembly point                            | 3    | site_emergency.fire_assembly_point           |
| G-11 | No fire warden name/contact                       | 3    | site_emergency.fire_warden_name + _contact   |
| G-12 | No first aider on site                            | 3    | site_emergency.first_aider_name + _contact   |
| G-13 | No defibrillator location                         | 3    | site_emergency.defibrillator_location        |
| G-14 | No RIDDOR routing / F2508 references              | 3    | RIDDOR Reporting Matrix table in Section 7.2 |
| G-15 | COSHH: no GHS/CLP hazard codes                    | 4    | 6/7-product baseline table with H2xx/H3xx    |
| G-16 | COSHH: no SDS binder reference                    | 4    | Trailing paragraph in Task 4 table           |
| G-17 | COSHH: unstructured bullet list                   | 4    | Structured 4-column table (product/use/GHS/controls) |
| G-18 | No Table of Contents                              | 5    | TOC page inserted after cover                |
| G-19 | No standards references in H&S Policy             | 5    | Standards & Guidance table in Section 3      |
| G-20 | No IT/network safety or other-trade coordination  | 5    | Sections 6.11 + 6.12                         |

All 20 gaps closed. Section 6 method statement content itself will also
benefit from the 4 new AV bullets in MethodStatementPrompt when the AI
regenerates the phases.

## Deploy

**NO migrations required.** Deploy path:

```
git pull
php artisan config:cache
php artisan view:clear
```

Then regenerate Tilda RAMS #87 and confirm the following on the fresh PDF:

- (a) Section 5 shows baseline hazards prepended if the reviewed data had gaps
- (b) Section 7 shows the new emergency details table OR the "TBC at induction" red banner
- (c) COSHH renders a 6-product structured table (not the old bullet list)
- (d) Section 6.11 Coordination and 6.12 IT/Network render at the end of the Method Statement
- (e) TOC page appears immediately after the cover pages
- (f) Section 3 shows the Standards & Guidance table under the H&S paragraphs
- (g) Section 7.2 shows the RIDDOR Reporting Matrix table below the accident bullets

Kill-switch to disable the whole fallback layer (e.g. if the H&S
consultant flags a control measure): set `RAMS_TIER1_DEFAULTS=false` in
`.env` and `php artisan config:cache`. The COSHH block reverts to the
legacy 4-item bullet list; the baseline hazards, standards table, and
COSHH baseline stop injecting.

## Key Decisions

- **Fallback layer, not a replacement.** Engineer-supplied values from
  the review form ALWAYS win. The service only fills the gap when the
  engineer has left the corresponding section empty. Verified in 5 unit
  tests.
- **Superset changes only.** Nothing is deleted from the PDF template.
  The Task 4 COSHH replacement keeps the legacy 4-item bullet list in an
  `@else` branch as a safety-net for when the kill-switch is off.
- **Kill-switch via env.** `RAMS_TIER1_DEFAULTS=false` reverts all
  behaviour to pre-260712-twi.
- **Non-clobbering key for COSHH.** `coshh_baseline` is a new key that
  doesn't collide with the existing `coshh` engineer-additions bucket.
  The two render side by side (baseline table + engineer-additions
  bullets below).
- **PDF renders on both engineer-supplied AND fallback data.** Two
  injection paths for hazards: (i) build-time in
  `Tier1RamsDefaultsService::injectDefaultsIntoRamsData()` for fresh
  records, (ii) render-time defensive fallback in `pdf.rams.blade.php`
  for legacy `generated_data` records built before Task 1 shipped.
- **Site emergency mirror to `generated_data`.** Task 3 mirrors
  `site_emergency` from `reviewed_data` into `generated_data` in the
  same controller pass, so the PDF's primary lookup succeeds directly
  without falling back to `$rams->reviewed_data`.
- **No renumbering of the Method Statement.** 6.11 and 6.12 are always-
  render additions. Existing `preInstallNum` / `qaNum` etc. dynamic-
  numbering pattern preserved verbatim.

## Known Stubs

None — all rendered content is either engineer-supplied, config-fold-in,
or fixed on the template.

## Self-Check: PASSED

- ✓ `config/rams_tier1.php` exists and passes `php -l`.
- ✓ `app/Services/Rams/Tier1RamsDefaultsService.php` exists and passes
  `php -l`.
- ✓ `tests/Unit/Services/Rams/Tier1RamsDefaultsServiceTest.php` — 5
  tests pass.
- ✓ `tests/Feature/Rams/Tier1BaselineHazardsRenderTest.php` — 3 tests
  pass.
- ✓ `tests/Feature/Rams/Tier1SiteEmergencyFormAndRenderTest.php` — 3
  tests pass.
- ✓ `tests/Feature/Rams/Tier1CoshhTableRenderTest.php` — 3 tests pass.
- ✓ `tests/Feature/Rams/Tier1PdfStructuralPolishTest.php` — 5 tests
  pass.
- ✓ All 5 commits present in `git log`:
  - 1091ea4 Task 1
  - 25b42d7 Task 2
  - 0403fb1 Task 3
  - fbcd76d Task 4
  - 503461c Task 5
- ✓ No database migrations added.
- ✓ Blade compile clean (`php artisan view:clear` returns success).
- ✓ 65 targeted tests green (5 Tier1 unit + 14 Tier1 feature + 46
  existing critical Rams tests).
