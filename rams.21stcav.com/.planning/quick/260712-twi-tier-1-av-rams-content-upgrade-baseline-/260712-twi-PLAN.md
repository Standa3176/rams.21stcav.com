---
quick_id: 260712-twi
slug: tier-1-av-rams-content-upgrade-baseline
date: 2026-07-12
type: quick
autonomous: true
description: >
  Tier-1 AV RAMS content upgrade. Closes 20 identified gaps (G-1 to G-20) from
  the verification pass on Tilda RAMS #87. Ships a five-task bundle: baseline
  defaults service + config, AV hazard register, site emergency capture,
  structured COSHH table, and PDF frontmatter/structural polish. Non-breaking.
  No database migrations — everything rides on existing `rams_documents.form_data` +
  `reviewed_data` + `generated_data` JSON columns plus a new config layer that
  gets folded into `$data` at PDF render time.

files_modified:
  - config/rams_tier1.php                                       # NEW (Task 1)
  - app/Services/Rams/Tier1RamsDefaultsService.php              # NEW (Task 1)
  - app/Services/RamsBuilderService.php                         # WIRED (Task 1)
  - tests/Unit/Services/Rams/Tier1RamsDefaultsServiceTest.php   # NEW (Task 1)
  - resources/views/pdf/rams.blade.php                          # EXTENDED (Tasks 2,3,4,5)
  - app/Core/AI/Prompts/MethodStatementPrompt.php               # EXTENDED (Task 2)
  - tests/Feature/Rams/Tier1BaselineHazardsRenderTest.php       # NEW (Task 2)
  - resources/views/rams/review.blade.php                       # EXTENDED (Task 3)
  - app/Http/Controllers/RamsController.php                     # EXTENDED (Task 3)
  - tests/Feature/Rams/Tier1SiteEmergencyFormAndRenderTest.php  # NEW (Task 3)
  - tests/Feature/Rams/Tier1CoshhTableRenderTest.php            # NEW (Task 4)
  - tests/Feature/Rams/Tier1PdfStructuralPolishTest.php         # NEW (Task 5)

must_haves:
  truths:
    - "Task 1: Tier1RamsDefaultsService is a live singleton, `config/rams_tier1.php` ships with the safety-critical warning header, and the service is wired into BOTH `RamsBuilderService::runFromReview()` and `RamsBuilderService::runPipeline()` immediately after `RamsComplianceUpgradeService::upgrade($data)` so tier-1 defaults land in `generated_data`. 5 unit tests green (defaults injected when key missing, engineer values preserved when key present, empty arrays overridden with defaults, partial engineer data merged not clobbered, disabled config flag no-ops)."
    - "Task 2: PDF Section 5 hazard register renders the 8 canonical AV baseline hazards from `config('rams_tier1.baseline_hazards')` when `reviewed_data` supplies no hazards; when engineer has supplied any hazards those win verbatim. MethodStatementPrompt gains 3-4 new AV-specific requirement bullets (cable route pre-check, IT/network liaison, control-system configuration safety, live-services isolation) without inventing scope. 3 feature tests green covering empty-hazards fallback, engineer-supplied override, and mixed AI response validity."
    - "Task 3: `rams/review.blade.php` gains a Site Emergency fieldset (nearest A&E hospital + address, fire assembly point, fire warden name/contact, first aider name/contact, nearest defibrillator location) in the WORKS & PERMITS tab. Controller `updateAndDownload` persists it as `reviewed_data['site_emergency']`. PDF Section 7 renders a new 7.0 Site-Specific Emergency Details table when the sub-key is populated, or a red warning banner (`TBC AT SITE INDUCTION — MUST BE COMPLETED BEFORE WORKS COMMENCE`) when empty. Section 7.2 adds a 4-row RIDDOR routing table (Death or major injury / Over-7-day incapacity / Dangerous occurrence / Occupational disease → Responsible Person + HSE timescale + phone). 3 feature tests green covering form persistence, PDF populated render, PDF warning-banner render."
    - "Task 4: PDF COSHH Assessment section (line 1748 area) renders a 6-product baseline table with columns Product / Use / GHS Hazard Codes / Control Measures — populated from `config('rams_tier1.coshh_products')` (isopropyl alcohol, Sn/Pb solder, rosin flux, expanding foam, contact cleaner, cable lubricant, silicone thermal compound as the 7th if enabled). Engineer-added COSHH bullets from `reviewed_data['coshh']` render below the baseline table (existing behaviour preserved). Table has GHS/CLP hazard codes verbatim from spec (H225, H319, H336, H360, H373, H317, H222, H332, H334, H351, H315). 3 feature tests green covering baseline render, engineer-additions preserved, and disabled-flag skips baseline."
    - "Task 5: PDF gains a Table of Contents page (inserted after cover-table 3, before Section 1) enumerating all sections/subsections; a 'Standards & Guidance Applicable to This Works' table appended to Section 3 (BS 7671 / BS 6701 / BS EN 60849 / BS 8492 / CDM 2015 / HSG 47 / HSG 273 / AVIXA F502.01); a PPE colour-code paragraph inside 6.3 (or the equivalent dynamic `preInstallNum`-1 position when `ppe_matrix` is empty); a new 6.11 Coordination with Other Trades subsection; and a new 6.12 IT / Network Integration Safety subsection. Existing `preInstallNum` dynamic-numbering pattern (line 1479) is respected — 6.11 and 6.12 are safe additions with no renumbering downstream. 5 feature tests green (one per structural addition: TOC render, standards table render, PPE colour paragraph render, section 6.11 render, section 6.12 render)."
    - "Non-regression: all 129+ existing Rams / QuoteParser / Worksheet / CableSchedule feature tests stay green. No changes to `config/cables.php`, `CableSchedulePrompt.php`, port-picker views, or any cable-schedule code. No database migrations added or altered."

deferred_next:
  - "Laser class per specific projector model — requires product-catalog integration (e.g. Barco / Christie / NEC laser projector database with per-SKU class-1/3R/3B/4 lookup)."
  - "Site geolookup for nearest A&E hospital — needs Google Places API integration to auto-populate `site_emergency.nearest_hospital` from `site_address`."
  - "Sensitive-space hazard baselines — data centre / broadcast / medical / education overlays on top of the canonical AV baseline set."
  - "F10 CDM notification auto-trigger — dispatch F10 form to HSE when project duration > 30 days or > 500 person-days, keyed off `programme.planned_start_date` + `planned_end_date`."
---

<objective>
Ship a five-task Tier-1 AV RAMS content upgrade that closes 20 verification gaps (G-1 to G-20) surfaced on Tilda RAMS #87. The upgrade is a *fallback layer* — engineer-supplied values from the review form ALWAYS win. This is the safety-net that stops a blank/skeleton RAMS from ever going out the door.

Purpose: Every RAMS the platform produces must, at minimum, meet UK CDM 2015 / HSG 65 / AVIXA F502.01 tier-1 competence expectations for AV install works. The gap-analysis on RAMS #87 showed that when the engineer skipped the review form (or filled in only the mandatory fields), the resulting PDF was missing an emergency section, had no COSHH detail, showed no risk register for AV-baseline hazards, and lacked a standards/TOC frontmatter. This bundle bakes that content in as a project-wide default that renders whenever the engineer has left the corresponding section empty.

Output:
- 1 new config file (safety-critical warning header)
- 1 new service (fallback layer)
- 1 new fieldset on review form
- 1 controller-side persistence path
- 5 structural additions to the PDF template
- 3 new hazard-content elements in the AI prompt (formatting-only, not scope-invention)
- 4 new feature-test files + 1 unit-test file (17 new test cases total)
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@./CLAUDE.md
@.planning/STATE.md

@resources/views/pdf/rams.blade.php
@app/Services/RamsBuilderService.php
@app/Services/Rams/RamsComplianceUpgradeService.php
@app/Http/Controllers/RamsController.php
@app/Core/AI/Prompts/MethodStatementPrompt.php
@resources/views/rams/review.blade.php
@config/rams.php
</context>

<interfaces>
<!-- Key contracts + injection points the executor uses directly. -->
<!-- No codebase exploration needed for these — verified during planning. -->

Injection point (Task 1) — `App\Services\RamsBuilderService`:
  - Method `runFromReview()`: after `$data = RamsComplianceUpgradeService::upgrade($data);` (currently line ~254), BEFORE `$record->update(['generated_data' => $data, ...])` (~line 262). Inject: `$data = app(Tier1RamsDefaultsService::class)->injectDefaultsIntoRamsData($data);`
  - Method `runPipeline()`: after `$data = RamsComplianceUpgradeService::upgrade($data);` (currently line ~698), BEFORE `$record->update(['generated_data' => $data, ...])` (~line 705). Inject the same call.
  - Both entry points funnel into `generated_data`, so the defaults land once and persist for both PDF + DOCX pipelines. Constructor-inject via `readonly private Tier1RamsDefaultsService $tier1Defaults` alongside the existing 8 dependencies.

Persistence surface (Task 3) — `App\Http\Controllers\RamsController::updateAndDownload()`:
  - Add to the `$validated = $request->validate([...])` array (~line 326) the new nullable fields: `site_emergency_nearest_hospital`, `site_emergency_hospital_address`, `site_emergency_fire_assembly_point`, `site_emergency_fire_warden_name`, `site_emergency_fire_warden_contact`, `site_emergency_first_aider_name`, `site_emergency_first_aider_contact`, `site_emergency_defibrillator_location`.
  - Save block: after the `commissioning_criteria` block (~line 537), add:
    ```
    $reviewedData['site_emergency'] = [
      'nearest_hospital'           => $validated['site_emergency_nearest_hospital'] ?? '',
      'hospital_address'           => $validated['site_emergency_hospital_address'] ?? '',
      'fire_assembly_point'        => $validated['site_emergency_fire_assembly_point'] ?? '',
      'fire_warden_name'           => $validated['site_emergency_fire_warden_name'] ?? '',
      'fire_warden_contact'        => $validated['site_emergency_fire_warden_contact'] ?? '',
      'first_aider_name'           => $validated['site_emergency_first_aider_name'] ?? '',
      'first_aider_contact'        => $validated['site_emergency_first_aider_contact'] ?? '',
      'defibrillator_location'     => $validated['site_emergency_defibrillator_location'] ?? '',
    ];
    ```
  - No mirror-to-form_data needed (site emergency is review-only; no form-based create flow captures it).

PDF template landmarks (`resources/views/pdf/rams.blade.php`, 1976 lines):
  - Line 290: `$hazards = $data['hazards'] ?? [];`  (Task 2 fallback lives here — replace with `$data['hazards'] ?? (array) config('rams_tier1.baseline_hazards', [])` gated by `config('rams_tier1.enabled')`).
  - Line 611: end of cover-page tables (Task 5 TOC inserts AFTER this, BEFORE line 613's Section 1 heading).
  - Line 677-697: Section 3 H&S Policy paragraphs (Task 5 appends the Standards & Guidance table AFTER line 697, BEFORE the `SECTION 4` comment at line 699).
  - Line 1230-1288: hazard-register table + `@if(! empty($hazards))` gate (Task 2 defaults change makes the else-branch unreachable in the fallback path).
  - Line 1430-1455: `6.3 PPE Matrix` block gated by `! empty($data['ppe_matrix'])` (Task 5 inserts the PPE colour-code paragraph INSIDE this block, between the intro and the table — colour code is only meaningful when hi-vis matters, which is what the PPE matrix gate already implies).
  - Line 1479: `@php $preInstallNum = ! empty($data['ppe_matrix']) ? '6.5' : '6.3'; @endphp` — the dynamic-numbering pattern. Task 5 uses the same pattern for 6.11 / 6.12 (they always render, no dynamic offset needed).
  - Line 1631-1640: `6.10 Supervision & Quality Assurance` block. Task 5 inserts new subsections 6.11 (Coordination with Other Trades) and 6.12 (IT / Network Integration Safety) AFTER line 1640, BEFORE the `DECOMMISSIONING PROCEDURE` comment at line 1642.
  - Line 1748-1772: COSHH Assessment block. Task 4 replaces the current 4-item `<ul>` (lines 1754-1759) with a 6-product `<table class="std-table">`. The engineer-added COSHH block gated by `@if(! empty($data['coshh']))` (lines 1765-1772) is preserved verbatim below the table.
  - Line 1820-1852: SECTION 7 Emergency Procedures. Task 3 inserts a NEW `7.0 Site-Specific Emergency Details` block AFTER line 1822 (the section heading) and BEFORE the existing 7.1 block at line 1824. Task 3 also inserts the RIDDOR routing table inside the existing 7.2 block AFTER line 1852.

MethodStatementPrompt requirement bullets (Task 2) — `App\Core\AI\Prompts\MethodStatementPrompt::build()`:
  - Add 3-4 new bullets to the `Requirements:` list (lines 154-165) INSIDE the same prompt string. These are AV-specific formatting hints, not scope invention — the AI is told to INCLUDE these considerations if the scope calls for them, not to invent them. Suggested wording:
    - `- If cable routing crosses live-services zones (containment, tray, existing conduit), the Installation step must call out isolation and 'test-before-touch' verification of any existing power/data circuit encountered.`
    - `- Any control-system programming or DSP configuration step must specify that engineers work OFF the live signal path (staging PC or bench-programmed) before hot-cutover, and that the client's IT contact is informed before any network device joins the LAN.`
    - `- Where new displays, speakers or cabling attach to plant that another trade owns (ceiling grid, partitions, structural steel), the relevant step must reference coordination with that trade before penetration or fixing.`
    - `- The Commissioning step must reference power-cycle and network-fail recovery verification for every codec, DSP or control processor deployed.`
</interfaces>

<tasks>

<task type="auto">
  <name>Task 1: Ship Tier1RamsDefaultsService + config/rams_tier1.php + wire into RamsBuilderService</name>
  <files>
    config/rams_tier1.php (NEW)
    app/Services/Rams/Tier1RamsDefaultsService.php (NEW)
    app/Services/RamsBuilderService.php (EDIT — inject at 2 points)
    tests/Unit/Services/Rams/Tier1RamsDefaultsServiceTest.php (NEW)
  </files>
  <action>
    Create `config/rams_tier1.php` as the safety-critical defaults layer. First 8 lines MUST be the exact warning header from the known-facts block:
    `/** WARNING — safety-critical defaults. All content in this file has been drafted from industry-standard AV install practice but MUST be reviewed by 21CAV's H&S consultant before real-world use in litigation-adjacent RAMS documents. Engineers may override any of these defaults per-project via the review form. This file is a fallback layer only. */`

    Return an array with these top-level keys:
    - `enabled` (bool, default `true` via `env('RAMS_TIER1_DEFAULTS', true)`) — master kill-switch.
    - `baseline_hazards` (array of 8 hazards, each shaped `['hazard' => string, 'persons_at_risk' => string[], 'pre_likelihood' => int(1-5), 'pre_severity' => int(1-5), 'controls' => string[3+], 'post_likelihood' => int(1-5), 'post_severity' => int(1-5)]`). The 8 canonical hazards per spec: Working at Height, Manual Handling of AV Equipment, Electrical Isolation & Live Working, Slips/Trips from Cable Runs, Drilling into Ceilings/Walls (unknown services), Working in Occupied Client Space, Use of Access Equipment (ladders/MEWP/tower), Fatigue from Long Working Days. Each MUST carry ≥3 realistic industry-standard control measures. Use the Working-at-Height sample from the known-facts block verbatim as reference for control-measure specificity ("Class 1 stepladder EN131 rated with 3 points of contact", "IPAF/PASMA cert", "Buddy system above 2m", etc.).
    - `coshh_products` (array of 6-7 products, each shaped `['product' => string, 'typical_use' => string, 'ghs_codes' => string[], 'controls' => string[2+]]`). Use the GHS/CLP hazard codes verbatim from the known-facts block: isopropyl alcohol (H225+H319+H336), Sn/Pb solder (H360+H373), rosin flux (H317), expanding foam (H319+H332+H334+H351), contact cleaner (H222+H336), cable lubricant (H319), silicone thermal compound (H319+H315).
    - `standards_references` (array of 8+ standards for Task 5's Section-3 table), each shaped `['ref' => string, 'title' => string, 'applies_to' => string]`. Include at minimum: BS 7671:2018+A2:2022 (electrical), BS 6701:2016+A1:2020 (comms cabling), BS EN 60849 (voice alarm), BS 8492 (public address), CDM 2015, HSG 47 (buried services), HSG 273 (fire safety in comms rooms), AVIXA F502.01 (AV installation practice).
    - `av_prompt_bullets` (array of 4 strings from the interfaces block above — used by Task 2 to enrich MethodStatementPrompt).

    Create `app/Services/Rams/Tier1RamsDefaultsService.php` as a singleton with one public method: `injectDefaultsIntoRamsData(array $data): array`. Behaviour (fallback-only, engineer wins):
    - If `config('rams_tier1.enabled')` is false → return `$data` unchanged.
    - If `$data['hazards']` is empty or unset → set `$data['hazards'] = config('rams_tier1.baseline_hazards')`; else leave verbatim.
    - If `$data['standards_references']` is empty or unset → set from config; else leave verbatim.
    - Always set `$data['coshh_baseline']` = `config('rams_tier1.coshh_products')` (new key, non-clobbering — the existing `$data['coshh']` engineer-additions key stays independent).
    - Return the mutated `$data`.
    - Class-level PHPDoc explains the fallback pattern per project conventions.

    Wire into `app/Services/RamsBuilderService.php` at TWO spots (per D-01):
    1. Constructor: add `private readonly Tier1RamsDefaultsService $tier1Defaults` alongside the existing 8 dependencies (bump to 9). Add `use App\Services\Rams\Tier1RamsDefaultsService;` at the top.
    2. `runFromReview()`: on the line immediately AFTER `$data = RamsComplianceUpgradeService::upgrade($data);` (currently ~line 254), insert `$data = $this->tier1Defaults->injectDefaultsIntoRamsData($data);`.
    3. `runPipeline()`: same injection on the line immediately AFTER the equivalent compliance-upgrade call (currently ~line 698).

    Create `tests/Unit/Services/Rams/Tier1RamsDefaultsServiceTest.php` (extends `Tests\TestCase`, uses `RefreshDatabase` NOT needed since service is pure array work). 5 test methods:
    - `test_injects_baseline_hazards_when_data_hazards_is_missing()` — asserts count of `$data['hazards']` >= 8 after inject.
    - `test_preserves_engineer_supplied_hazards_verbatim()` — pre-populate `$data['hazards'] = [['hazard' => 'Custom hazard', ...]]`, assert count === 1 and title unchanged after inject.
    - `test_treats_empty_array_hazards_as_missing()` — `$data['hazards'] = []` should be replaced with baseline.
    - `test_partial_engineer_data_not_clobbered_for_standards_references()` — pre-populate one engineer standard, assert kept.
    - `test_disabled_flag_returns_data_unchanged()` — `config(['rams_tier1.enabled' => false])` then inject; assert `$data` identity-equal to input.

    `php -l` every edited/created .php file (config, service, RamsBuilderService, test) before commit.

    Commit message: `feat(rams): Tier1RamsDefaultsService + config/rams_tier1.php wired into build pipeline`
  </action>
  <verify>
    <automated>vendor/bin/phpunit tests/Unit/Services/Rams/Tier1RamsDefaultsServiceTest.php --stop-on-failure</automated>
    Plus manual: `php -l config/rams_tier1.php`, `php -l app/Services/Rams/Tier1RamsDefaultsService.php`, `php -l app/Services/RamsBuilderService.php`. Also `php artisan config:clear && php artisan tinker --execute="dd(config('rams_tier1.enabled'), count(config('rams_tier1.baseline_hazards')));"` shows `true` + `>= 8`.
  </verify>
  <done>
    Config file exists with warning header; service injects defaults into `$data` when engineer values missing and preserves engineer values when present; RamsBuilderService calls the service at both build entry points; 5 unit tests green; `php -l` clean on all 4 changed files. One atomic commit.
  </done>
</task>

<task type="auto">
  <name>Task 2: PDF Section 5 baseline hazards fallback + MethodStatementPrompt AV bullets</name>
  <files>
    resources/views/pdf/rams.blade.php (EDIT — line ~290 hazards fallback + line ~1230-1288 register)
    app/Core/AI/Prompts/MethodStatementPrompt.php (EDIT — insert 3-4 new Requirements bullets in build())
    tests/Feature/Rams/Tier1BaselineHazardsRenderTest.php (NEW — 3 test methods)
  </files>
  <action>
    In `resources/views/pdf/rams.blade.php` around line 290, change `$hazards = $data['hazards'] ?? [];` to still read from `$data['hazards']` only (Task 1 already populated the fallback into `generated_data['hazards']` before this render), BUT add a defensive belt-and-braces fallback right after the type guards at line 301: if `empty($hazards) && config('rams_tier1.enabled')` then `$hazards = (array) config('rams_tier1.baseline_hazards', []);`. This gives us two paths to the fallback — one at build time (Task 1) for `generated_data` persistence, one at render time here for any legacy `generated_data` records that were built before Task 1 shipped. Non-breaking: engineer data still wins.

    Do NOT touch the existing `@if(! empty($hazards))` gate at line 1230 — the fallback makes the `@else` branch (line 1286 "No hazards identified") effectively unreachable, which is the desired behaviour. Leave it as a defence-in-depth safety net.

    In `app/Core/AI/Prompts/MethodStatementPrompt.php::build()`, insert the 4 AV-specific requirement bullets from the interfaces block AFTER the existing bullet on line 162 ("The final step MUST be Completion & Sign-Off...") and BEFORE line 163 ("Each step must have 4 to 8 bullet points."). This keeps them alongside the other content-shape requirements and clear of the mechanical formatting constraints. Do NOT change the JSON schema, systemMessage, maxTokens, or temperature. Do NOT change the sentinel-wrap behaviour on user-controlled context fields.

    Rationale under project CLAUDE.md constraint "AI only for formatting": these bullets do not tell the AI to invent scope or equipment. They constrain WHICH considerations must be REFLECTED in whatever scope the engineer/quote supplies — same guardrail pattern as the existing "the penultimate step MUST cover Integration, Testing & Commissioning" bullet. Cross-check against existing bullets before writing.

    Create `tests/Feature/Rams/Tier1BaselineHazardsRenderTest.php` (extends `Tests\TestCase`, uses `RefreshDatabase`). 3 test methods:
    - `test_baseline_hazards_render_when_reviewed_data_hazards_is_empty()` — factory-create a RamsDocument with `generated_data['hazards'] = []`, render via `PdfService::buildRams()` OR the `pdf.rams` view directly with a stubbed `$data`, assert PDF/HTML contains at least 3 of the canonical hazard titles ("Working at Height", "Manual Handling", "Electrical Isolation"). Use `view('pdf.rams', ['rams' => $rams, 'data' => $data])->render()` to avoid Browsershot in tests.
    - `test_engineer_supplied_hazards_render_verbatim_and_baseline_is_not_injected()` — pre-populate `generated_data['hazards'] = [['hazard' => 'Custom test hazard XYZ', 'persons_at_risk' => ['Engineers'], 'controls' => ['Custom control'], 'pre_likelihood' => 2, 'pre_severity' => 3, 'post_likelihood' => 1, 'post_severity' => 3]]`; assert HTML contains "Custom test hazard XYZ" and does NOT contain "Manual Handling of AV Equipment" (canonical baseline title).
    - `test_disabled_flag_leaves_hazards_empty_no_baseline_injected()` — `config(['rams_tier1.enabled' => false])`, `generated_data['hazards'] = []`, render, assert HTML contains "No hazards identified" (the else-branch text at line 1287).

    `php -l` every edited .php file before commit.

    Commit message: `feat(rams): AV baseline hazard register fallback + MethodStatementPrompt AV bullets`
  </action>
  <verify>
    <automated>vendor/bin/phpunit tests/Feature/Rams/Tier1BaselineHazardsRenderTest.php --stop-on-failure && vendor/bin/phpunit tests/Unit/Services/Rams/Tier1RamsDefaultsServiceTest.php --stop-on-failure</automated>
    Plus manual: `php -l app/Core/AI/Prompts/MethodStatementPrompt.php`, `php -l resources/views/pdf/rams.blade.php` (Blade lint — Laravel's `view:cache` compile step catches syntax errors: `php artisan view:clear && php artisan view:cache`).
  </verify>
  <done>
    3 new feature tests green; Task 1 unit tests still green; MethodStatementPrompt has 4 new AV-specific requirement bullets INSIDE the existing Requirements list (no schema change); PDF renders baseline hazards when reviewed_data.hazards is empty and engineer overrides win when present. One atomic commit.
  </done>
</task>

<task type="auto">
  <name>Task 3: Site emergency form fieldset + controller persistence + PDF Section 7 rewrite (7.0 details table + RIDDOR routing)</name>
  <files>
    resources/views/rams/review.blade.php (EDIT — new fieldset in WORKS & PERMITS tab near welfare_notes at line ~789)
    app/Http/Controllers/RamsController.php (EDIT — `updateAndDownload` validation + persist block after line ~537)
    resources/views/pdf/rams.blade.php (EDIT — insert 7.0 block after line 1822, RIDDOR table after line 1852)
    tests/Feature/Rams/Tier1SiteEmergencyFormAndRenderTest.php (NEW — 3 test methods)
  </files>
  <action>
    In `resources/views/rams/review.blade.php`, insert a new fieldset in the WORKS & PERMITS tab immediately after the `welfare_notes` textarea block (around line 793, right before the `</div>{{-- /TAB: WORKS & PERMITS --}}` closing marker). Use the same styling primitives as the existing fieldsets (`<h3 class="section-heading">`, `<div class="form-group">`, `.form-label`, `.form-control`). Add 8 inputs bound to `$rd['site_emergency']` with `old()` fallbacks (defensive against validation redirects):
    - `site_emergency_nearest_hospital` (text, label "Nearest A&E Hospital — Name")
    - `site_emergency_hospital_address` (textarea rows=2, label "Nearest A&E — Full Address & Postcode")
    - `site_emergency_fire_assembly_point` (text, label "Fire Assembly Point Location")
    - `site_emergency_fire_warden_name` (text, label "Site Fire Warden — Name")
    - `site_emergency_fire_warden_contact` (text, label "Site Fire Warden — Phone")
    - `site_emergency_first_aider_name` (text, label "Site First Aider — Name")
    - `site_emergency_first_aider_contact` (text, label "Site First Aider — Phone")
    - `site_emergency_defibrillator_location` (text, label "Nearest Defibrillator — Location")

    All 8 inputs MUST carry `data-optional` so validation stays soft (the render-side warning banner is the enforcement).

    Add a small callout above the fieldset: `<p class="hint" style="font-size:.85rem; color:#a00;">Leaving these blank produces a RED warning banner on the RAMS PDF. Complete at site induction if not known now.</p>`

    In `app/Http/Controllers/RamsController.php::updateAndDownload()`, add the 8 new validated fields to the `$validated = $request->validate([...])` array (~line 326) as `['nullable','string','max:255']` (address gets `max:500`). After the `commissioning_criteria` block at line 537, insert the persist block from the interfaces section (`$reviewedData['site_emergency'] = [...]` mapping the 8 validated keys). No mirror-to-form_data (site emergency is review-only, not part of the manual-create flow).

    In `resources/views/pdf/rams.blade.php`, edit SECTION 7 (line 1820+):
    1. AFTER line 1822 (the `<div class="sec-heading page-break">7. &nbsp;Emergency Procedures</div>` line) and BEFORE line 1824 (the `{{-- 7.1 Emergency Contact Numbers --}}` comment), insert a new 7.0 block:
       ```
       @php $siteEmerg = (array) ($data['site_emergency'] ?? ($rams->reviewed_data['site_emergency'] ?? [])); @endphp
       @php $hasSiteEmerg = ! empty(array_filter($siteEmerg)); @endphp
       <div class="sec-subheading">7.0 Site-Specific Emergency Details</div>
       @if($hasSiteEmerg)
         <table class="emerg-table" style="margin-bottom: 10pt;">
           <tr><td class="e-lbl">Nearest A&amp;E Hospital</td>
               <td class="e-val" colspan="3">{{ $siteEmerg['nearest_hospital'] ?: 'TBC' }}
                 @if(! empty($siteEmerg['hospital_address']))<br><span style="font-size:8pt; color:#555;">{{ $siteEmerg['hospital_address'] }}</span>@endif
               </td></tr>
           <tr><td class="e-lbl">Fire Assembly Point</td>
               <td class="e-val" colspan="3">{{ $siteEmerg['fire_assembly_point'] ?: 'TBC' }}</td></tr>
           <tr><td class="e-lbl">Fire Warden</td>
               <td class="e-val">{{ $siteEmerg['fire_warden_name'] ?: 'TBC' }}</td>
               <td class="e-lbl">Contact</td>
               <td class="e-val">{{ $siteEmerg['fire_warden_contact'] ?: '—' }}</td></tr>
           <tr><td class="e-lbl">First Aider</td>
               <td class="e-val">{{ $siteEmerg['first_aider_name'] ?: 'TBC' }}</td>
               <td class="e-lbl">Contact</td>
               <td class="e-val">{{ $siteEmerg['first_aider_contact'] ?: '—' }}</td></tr>
           <tr><td class="e-lbl">Nearest Defibrillator</td>
               <td class="e-val" colspan="3">{{ $siteEmerg['defibrillator_location'] ?: 'TBC — confirm at site induction' }}</td></tr>
         </table>
       @else
         <div style="border: 2pt solid #c00; background: #ffecec; padding: 8pt; margin: 6pt 0 12pt 0; color: #900; font-weight: 700; text-align: center;">
           TBC AT SITE INDUCTION — MUST BE COMPLETED BEFORE WORKS COMMENCE.<br>
           <span style="font-weight: normal; font-size: 8.5pt;">Nearest hospital, fire assembly point, fire warden, first aider, and defibrillator location have not been captured in the review form.</span>
         </div>
       @endif
       ```
    2. AFTER line 1852 (the last `<li>` of the existing 7.2 list, "RIDDOR reportable incidents must be reported..."), insert a RIDDOR routing table BEFORE the `</ul>` on that line's closer, or (safer) AFTER the `</ul>` that closes the 7.2 list:
       ```
       <div class="sec-subheading" style="margin-top: 4pt;">RIDDOR Reporting Matrix</div>
       <table class="std-table" style="margin-bottom: 10pt;">
         <thead><tr>
           <th style="width: 30%;">Incident Type</th>
           <th style="width: 30%;">Responsible Person</th>
           <th style="width: 22%;">Timescale</th>
           <th>Reporting Route</th>
         </tr></thead>
         <tbody>
           <tr><td>Death or specified major injury</td><td>{{ $company }} Ops Manager</td><td><strong>Immediate</strong></td><td>HSE Incident Contact Centre — 0345 300 9923</td></tr>
           <tr><td>Over-7-day incapacity (injury)</td><td>{{ $company }} Ops Manager</td><td>Within 15 days</td><td>HSE online RIDDOR form (F2508)</td></tr>
           <tr><td>Dangerous occurrence (near miss)</td><td>{{ $company }} Ops Manager</td><td>Within 10 days</td><td>HSE online RIDDOR form (F2508)</td></tr>
           <tr><td>Occupational disease</td><td>{{ $company }} Ops Manager</td><td>Within 10 days of diagnosis</td><td>HSE online RIDDOR form (F2508A)</td></tr>
         </tbody>
       </table>
       ```

    Create `tests/Feature/Rams/Tier1SiteEmergencyFormAndRenderTest.php`. 3 test methods:
    - `test_review_form_persists_site_emergency_into_reviewed_data()` — factory-create a RAMS, POST to the review update route with the 8 site_emergency fields populated, refresh the model, assert `$rams->reviewed_data['site_emergency']['nearest_hospital']` matches submitted value.
    - `test_pdf_renders_populated_site_emergency_table()` — factory-create with populated `reviewed_data['site_emergency']`, render `pdf.rams` view, assert HTML contains "7.0 Site-Specific Emergency Details" AND "Nearest A&E Hospital" AND the submitted hospital name AND the RIDDOR routing table strings ("0345 300 9923", "F2508").
    - `test_pdf_renders_warning_banner_when_site_emergency_empty()` — factory-create with no site_emergency, render, assert HTML contains "TBC AT SITE INDUCTION" AND `border: 2pt solid #c00`.

    `php -l` every edited/created .php file. Also `php artisan view:clear && php artisan view:cache` to catch Blade syntax errors.

    Commit message: `feat(rams): site emergency capture on review form + PDF 7.0 details table + RIDDOR routing matrix`
  </action>
  <verify>
    <automated>vendor/bin/phpunit tests/Feature/Rams/Tier1SiteEmergencyFormAndRenderTest.php --stop-on-failure</automated>
    Manual: navigate to `/rams/{id}/review`, verify new fieldset renders in WORKS & PERMITS tab with the red hint text; submit form; verify DB row shows `reviewed_data.site_emergency.*` populated; regenerate PDF and confirm 7.0 block + RIDDOR table appear.
  </verify>
  <done>
    Review form has new Site Emergency fieldset with 8 inputs + red warning-hint text; controller validates + persists them into `reviewed_data['site_emergency']`; PDF Section 7 renders 7.0 populated table when data present, red warning banner when empty; RIDDOR routing table renders inside 7.2; 3 feature tests green; all prior tests still green. One atomic commit.
  </done>
</task>

<task type="auto">
  <name>Task 4: PDF COSHH Assessment section — 6-product baseline table with GHS hazard codes</name>
  <files>
    resources/views/pdf/rams.blade.php (EDIT — replace bullet-list at line 1754-1759 with 6-product table)
    tests/Feature/Rams/Tier1CoshhTableRenderTest.php (NEW — 3 test methods)
  </files>
  <action>
    In `resources/views/pdf/rams.blade.php`, edit the COSHH ASSESSMENT block (lines 1745-1772).

    Keep the `<div class="sec-heading">COSHH Assessment</div>` at line 1748 and the introductory `<p class="body-para">` at lines 1749-1753 verbatim. REPLACE the current 4-item bullet list at lines 1754-1759 with a structured 6-product table. Keep the "Engineers must report any unexpected COSHH hazard..." paragraph at lines 1760-1764 verbatim. Keep the existing engineer-additions block at lines 1765-1772 verbatim (this is the extension point that lets site-specific COSHH items ride ABOVE the baseline).

    The replacement table:
    ```
    @php $coshhBaseline = (array) ($data['coshh_baseline'] ?? config('rams_tier1.coshh_products', [])); @endphp
    @if(! empty($coshhBaseline) && config('rams_tier1.enabled'))
    <p class="body-para"><strong>Standard AV Install COSHH Inventory:</strong></p>
    <table class="std-table" style="margin-bottom: 8pt;">
      <thead>
        <tr style="background-color:#1B7A7A; color:#ffffff;">
          <th style="width:22%; color:#fff;">Product / Substance</th>
          <th style="width:22%; color:#fff;">Typical Use</th>
          <th style="width:18%; color:#fff;">GHS Hazard Codes</th>
          <th style="color:#fff;">Control Measures</th>
        </tr>
      </thead>
      <tbody>
        @foreach($coshhBaseline as $ci)
        @php $ci = is_array($ci) ? $ci : []; @endphp
        <tr>
          <td><strong>{{ $ci['product'] ?? '' }}</strong></td>
          <td>{{ $ci['typical_use'] ?? '' }}</td>
          <td style="font-size:8pt; font-family: Consolas, monospace;">{{ implode(', ', (array)($ci['ghs_codes'] ?? [])) }}</td>
          <td>
            <ul class="blist" style="margin:0; padding-left:14pt;">
              @foreach((array)($ci['controls'] ?? []) as $ctrl)
                <li>{{ $ctrl }}</li>
              @endforeach
            </ul>
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
    <p class="body-para" style="font-size:8pt; color:#555;">
      GHS/CLP hazard codes: H2xx = physical, H3xx = health, H4xx = environmental. Refer to the manufacturer Safety Data Sheet (SDS) for each product on site. SDS binder kept in Vehicle 1 tool cabinet and available at request from Site Supervisor.
    </p>
    @else
    <ul class="blist">
      <li><strong>Cable conduit adhesives / sealants</strong> — used in limited quantities. Ensure adequate ventilation. Wear nitrile gloves and safety glasses. Avoid skin contact. Store according to manufacturer data sheet.</li>
      <li><strong>Dust generated by drilling / cutting</strong> — use FFP2 dust masks when drilling into plasterboard, masonry or MDF. Use dust extraction where practicable.</li>
      <li><strong>Electrical flux (soldering)</strong> — only where cable terminations require soldering. Ensure ventilation. Avoid inhalation of fumes.</li>
      <li><strong>Battery acid (UPS batteries if applicable)</strong> — handle sealed VRLA batteries per manufacturer instructions. Wear chemical-resistant gloves and eye protection.</li>
    </ul>
    @endif
    ```

    The `@else` branch is the legacy 4-item bullet list, kept verbatim as a safety net for when `config('rams_tier1.enabled')` is false. This makes the change a strict superset — nothing is deleted.

    Create `tests/Feature/Rams/Tier1CoshhTableRenderTest.php`. 3 test methods:
    - `test_pdf_renders_coshh_baseline_table_with_ghs_codes()` — factory-create RAMS, render `pdf.rams` view, assert HTML contains "Standard AV Install COSHH Inventory" AND "GHS Hazard Codes" AND "H225" AND "Isopropyl" (case-insensitive) AND "Sn/Pb solder" (or "Lead-tin solder").
    - `test_engineer_added_coshh_bullets_render_below_baseline_table()` — factory-create RAMS with `generated_data['coshh'] = ['Custom site-specific COSHH: contact adhesive brand X used in comms room']`, render, assert HTML contains "Site-specific COSHH entries" AND "Custom site-specific COSHH: contact adhesive brand X" AND the position of that string is AFTER the position of "Standard AV Install COSHH Inventory" in the HTML.
    - `test_disabled_flag_falls_back_to_legacy_bullet_list()` — `config(['rams_tier1.enabled' => false])`, render, assert HTML contains "Cable conduit adhesives / sealants" (legacy bullet) AND does NOT contain "Standard AV Install COSHH Inventory".

    `php -l resources/views/pdf/rams.blade.php` (Blade compile via `php artisan view:clear && php artisan view:cache`).

    Commit message: `feat(rams): structured COSHH inventory table with GHS hazard codes`
  </action>
  <verify>
    <automated>vendor/bin/phpunit tests/Feature/Rams/Tier1CoshhTableRenderTest.php --stop-on-failure</automated>
    Manual: render a Tilda test RAMS, verify the 6-product table appears with all GHS codes visible and monospace-styled, and that the manufacturer-SDS note paragraph renders below it.
  </verify>
  <done>
    COSHH section renders a 6-product structured table with GHS hazard codes when `rams_tier1.enabled` is true; engineer-added COSHH bullets from `$data['coshh']` still render below the baseline table (existing behaviour preserved verbatim); legacy 4-bullet fallback rides in the `@else` branch for the kill-switch case; 3 feature tests green; all prior tests still green. One atomic commit.
  </done>
</task>

<task type="auto">
  <name>Task 5: PDF frontmatter + structural polish — TOC page, Standards table, PPE colour paragraph, Section 6.11 + 6.12</name>
  <files>
    resources/views/pdf/rams.blade.php (EDIT — 5 discrete insertions across the file)
    tests/Feature/Rams/Tier1PdfStructuralPolishTest.php (NEW — 5 test methods, one per structural addition)
  </files>
  <action>
    Five surgical additions to `resources/views/pdf/rams.blade.php`. Each must be atomic and reversible — don't renumber existing sections, don't touch existing content.

    (1) Table of Contents page. Insert AFTER line 611 (the closing `</table>` of Cover Table 3) and BEFORE line 613 (the opening comment of Section 1). The TOC lives on its own page via `page-break-before`:
    ```
    {{-- ════════════════════════════════════════════════════════════════════════
         TABLE OF CONTENTS
         ════════════════════════════════════════════════════════════════════════ --}}
    <div class="sec-heading page-break">Table of Contents</div>
    <table class="std-table" style="width:100%; margin-bottom:12pt;">
      <tbody>
        <tr><td style="width:82%;">1. Document Control</td><td style="text-align:right;">Section 1</td></tr>
        <tr><td>2. Company Information</td><td style="text-align:right;">Section 2</td></tr>
        <tr><td>3. Health &amp; Safety Policy Statement &amp; Standards Applicable</td><td style="text-align:right;">Section 3</td></tr>
        <tr><td>4. Scope of Works, Site Logistics &amp; Room Overviews</td><td style="text-align:right;">Section 4</td></tr>
        <tr><td>5. Risk Assessment (Baseline AV Hazards + Site-Specific)</td><td style="text-align:right;">Section 5</td></tr>
        <tr><td>6. Method Statement (Team, Tools, PPE, Access, Steps, Coordination, IT)</td><td style="text-align:right;">Section 6</td></tr>
        <tr><td>7. Emergency Procedures (Site Details, Accident, Fire, RIDDOR)</td><td style="text-align:right;">Section 7</td></tr>
        <tr><td>8. Document Sign-Off</td><td style="text-align:right;">Section 8</td></tr>
        <tr><td>COSHH Assessment, Environmental Management, Welfare Arrangements</td><td style="text-align:right;">Supplementary</td></tr>
        <tr><td>Appendix A — Toolbox Talk Record</td><td style="text-align:right;">Appendix</td></tr>
      </tbody>
    </table>
    <p class="body-para" style="font-size:8pt; color:#555;">Page numbers omitted — the PDF renderer's dynamic pagination is authoritative. Use the running header ("RAMS | REF | CLIENT") + footer page count to navigate.</p>
    ```

    (2) Standards & Guidance Applicable to This Works table. Insert AFTER line 697 (the closing `</p>` of the third H&S Policy paragraph) and BEFORE line 699 (the SECTION 4 comment):
    ```
    <div class="sec-subheading" style="margin-top:8pt;">Standards &amp; Guidance Applicable to This Works</div>
    @php $stdRefs = (array) ($data['standards_references'] ?? config('rams_tier1.standards_references', [])); @endphp
    @if(! empty($stdRefs))
    <table class="std-table" style="margin-bottom:8pt;">
      <thead><tr style="background-color:#1B7A7A; color:#ffffff;">
        <th style="width:22%; color:#fff;">Reference</th>
        <th style="width:40%; color:#fff;">Title</th>
        <th style="color:#fff;">Applies To (on this project)</th>
      </tr></thead>
      <tbody>
        @foreach($stdRefs as $std)
        @php $std = is_array($std) ? $std : []; @endphp
        <tr>
          <td><strong>{{ $std['ref'] ?? '' }}</strong></td>
          <td>{{ $std['title'] ?? '' }}</td>
          <td>{{ $std['applies_to'] ?? '' }}</td>
        </tr>
        @endforeach
      </tbody>
    </table>
    @endif
    ```

    (3) PPE colour-code paragraph. Insert INSIDE the existing 6.3 PPE Matrix block (line 1430-1455), between the `<div class="sec-subheading">6.3 Personal Protective Equipment (PPE)</div>` at line 1432 and the `<table class="std-table">` at line 1433. New paragraph:
    ```
    <p class="body-para" style="font-size:9pt;">
      <strong>Hi-vis colour convention on this site:</strong>
      {{ $company }} engineers wear <strong>orange</strong> hi-vis vests (EN ISO 20471 class 2 minimum). If the client site enforces a different colour code (e.g. yellow for visitors, blue for contractors), the site induction MUST clarify and any conflict is resolved by the site rules — engineers to swap out to the site-mandated colour before entering active areas.
    </p>
    ```

    (4) Section 6.11 — Coordination with Other Trades. Insert AFTER line 1640 (the closing `</ul>`/`@endif` of the 6.10 QA block) and BEFORE line 1642 (the DECOMMISSIONING PROCEDURE comment):
    ```
    <div class="sec-subheading">6.11 Coordination with Other Trades</div>
    <ul class="blist">
      <li>Where AV works interface with plant owned by other trades (ceiling grid installer, partition contractor, electrical fit-out, IT/network cabling), the Lead Engineer must confirm the interface point with the trade's on-site supervisor before penetration, fixing or cable pull.</li>
      <li>Any drilling into ceiling voids requires prior confirmation from the ceiling installer that the grid is fully supported and no live services are on the drill path.</li>
      <li>Any cable pull through partitions requires prior confirmation from the partition contractor that the wall is not carrying fire-stop that would be breached.</li>
      <li>Any tie-in to client-owned IT infrastructure requires prior confirmation from the client IT contact (see 6.12) that the port is provisioned and the VLAN is correctly assigned.</li>
      <li>Interface disputes are escalated to the Project Manager for resolution before works proceed; engineers do not commence contested works.</li>
    </ul>
    ```

    (5) Section 6.12 — IT / Network Integration Safety. Insert immediately AFTER the 6.11 block:
    ```
    <div class="sec-subheading">6.12 IT / Network Integration Safety</div>
    <ul class="blist">
      <li>The client's IT contact must be informed and available before any codec, DSP, control processor, room-scheduler tablet or network switch joins the client LAN.</li>
      <li>Control system programming (Crestron, Q-SYS, Extron, Vaddio) and DSP configuration are performed OFF the live signal path (bench or staging PC) and only cutover after the client IT contact confirms the target VLAN is live and the switch port is enabled.</li>
      <li>Firmware updates on client-owned hardware are only performed with written authorisation from the client IT contact and only during agreed maintenance windows.</li>
      <li>Any credentials (SIP registrar, MQTT broker, control-system admin passwords) are captured in the O&amp;M Manual and handed over to the client IT contact — engineers do not retain client credentials post-handover.</li>
      <li>Power-cycle and network-fail recovery is verified during commissioning for every codec, DSP and control processor — each device must autonomously return to the last-good configuration after unexpected loss of power or network.</li>
    </ul>
    ```

    Create `tests/Feature/Rams/Tier1PdfStructuralPolishTest.php` — 5 test methods (one per structural addition):
    - `test_toc_page_renders_between_cover_and_section_1()` — render, assert HTML contains "Table of Contents" AND all 8 section labels + "Appendix A — Toolbox Talk Record".
    - `test_standards_applicable_table_renders_in_section_3()` — render, assert HTML contains "Standards & Guidance Applicable to This Works" AND at least 6 of the 8 standards refs ("BS 7671", "BS 6701", "CDM 2015", "HSG 47", "HSG 273", "AVIXA F502.01").
    - `test_ppe_colour_code_paragraph_renders_inside_6_3()` — set `generated_data['ppe_matrix']` to a non-empty array so the 6.3 block gates open, render, assert HTML contains "Hi-vis colour convention on this site" AND "orange" AND "EN ISO 20471".
    - `test_section_6_11_coordination_with_other_trades_renders()` — render, assert HTML contains "6.11 Coordination with Other Trades" AND "ceiling grid installer" AND "Interface disputes are escalated".
    - `test_section_6_12_it_network_integration_safety_renders()` — render, assert HTML contains "6.12 IT / Network Integration Safety" AND "Crestron, Q-SYS, Extron" AND "Power-cycle and network-fail recovery".

    `php -l resources/views/pdf/rams.blade.php`. Blade compile check: `php artisan view:clear && php artisan view:cache` (must exit 0).

    Commit message: `feat(rams-pdf): TOC + standards table + PPE colour + sections 6.11 (coordination) and 6.12 (IT/network safety)`
  </action>
  <verify>
    <automated>vendor/bin/phpunit tests/Feature/Rams/Tier1PdfStructuralPolishTest.php --stop-on-failure</automated>
    Manual: render Tilda test RAMS, page-by-page visual check: TOC appears immediately after cover; Section 3 shows the Standards table under the H&S paragraphs; 6.3 PPE section shows the hi-vis paragraph above the matrix; 6.11 and 6.12 appear after 6.10 and before the Decommissioning Procedure heading. No section renumbering downstream of 6.10.
  </verify>
  <done>
    PDF gains 5 structural elements: TOC page, Standards table in Section 3, PPE colour paragraph in Section 6.3, Section 6.11 Coordination, Section 6.12 IT/Network Safety; 5 feature tests green; existing `preInstallNum` / `methodNum` / `mhNum` / `permitNum` / `fixingsNum` / `qaNum` dynamic-numbering pattern preserved verbatim (no downstream renumbering); Blade compile clean; all prior tests still green. One atomic commit.
  </done>
</task>

</tasks>

<verification>
Overall bundle verification:

1. `php artisan view:clear && php artisan view:cache` exits 0 (Blade compile check on the 1976-line template after 4 rounds of surgical edits).

2. Run the full Rams test suite:
   ```
   vendor/bin/phpunit tests/Feature/Rams tests/Unit/Services/Rams tests/Feature/DocumentEdits --testdox
   ```
   Expected: all 5 new test files pass + all pre-existing tests (129+ across ManualRamsCreationTest, PatchRamsForDisplayTest, ReviewWorkflowTest, DeadPathRemovalGuardTest, DocxBuilderPdfParityTest, Phase22_1InvariantGuardTest, QuoteUploadRamsCreationTest, RamsRenderRegressionTest, RamsUpdateAndDownloadTransactionTest, ReviewedDataStructuralDiffTest, ScopeConsolidationGuardTest) still green.

3. Cross-suite non-regression check — run the wider suite to confirm no CableSchedule / QuoteParser / Worksheet coupling was broken:
   ```
   vendor/bin/phpunit tests/Feature/Cable tests/Feature/ProjectPackages tests/Unit --testdox
   ```
   Expected: 598+ tests green (per STATE.md 2026-07-12 baseline).

4. Config sanity:
   ```
   php artisan tinker --execute="dd(config('rams_tier1.enabled'), count(config('rams_tier1.baseline_hazards')), count(config('rams_tier1.coshh_products')), count(config('rams_tier1.standards_references')));"
   ```
   Expected: `true`, `>= 8`, `>= 6`, `>= 8`.

5. End-to-end render check on Tilda RAMS #87 (the source of the 20 gaps):
   - Regenerate the PDF.
   - Manually confirm: TOC page (Task 5), 8 baseline hazards render in Section 5 if reviewed_data.hazards was empty (Task 2), 6-product COSHH table with GHS codes in COSHH Assessment (Task 4), Site Emergency red warning banner (Task 3, expected because Tilda review form was never filled), Standards table in Section 3 (Task 5), Section 6.11 + 6.12 (Task 5).
   - All 20 gaps G-1 to G-20 from the source verification should now be closed.
</verification>

<success_criteria>
- 5 atomic commits in order Task 1 → Task 2 → Task 3 → Task 4 → Task 5.
- `config/rams_tier1.php` ships with the safety-critical warning comment header at the top.
- `Tier1RamsDefaultsService` is registered as a singleton (or resolved via `app()`) and called from both `RamsBuilderService::runFromReview()` and `RamsBuilderService::runPipeline()` immediately after `RamsComplianceUpgradeService::upgrade($data)`.
- Zero database migrations added.
- All 5 new test files created + all 17 new test methods green.
- All 129+ pre-existing tests still green.
- `php -l` clean on every edited/created .php file.
- Blade compile clean via `php artisan view:cache`.
- No changes to `config/cables.php`, `CableSchedulePrompt.php`, port-picker views, or any cable-schedule code.
- Fallback layer behaviour verified: engineer values ALWAYS win, defaults ONLY inject when engineer values are empty/missing, `config('rams_tier1.enabled') === false` disables the whole layer.
</success_criteria>

<output>
On task completion, write `.planning/quick/260712-twi-tier-1-av-rams-content-upgrade-baseline-/260712-twi-SUMMARY.md` covering: 5 atomic commits with their SHAs, files changed per task, test coverage (17 new tests, all pre-existing green), key implementation decisions (defaults-fallback pattern, non-clobbering of engineer values, kill-switch via `rams_tier1.enabled`), and how the 20 verification gaps (G-1 to G-20) from RAMS #87 are now closed.
</output>
