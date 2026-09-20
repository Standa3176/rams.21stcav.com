---
milestone: v3.0
milestone_name: RAMS Skill Parity
last_updated: "2026-08-23"
---

# Roadmap

## Project Reference

See: `.planning/PROJECT.md` (updated 2026-05-09)

**Current milestone:** v3.0 RAMS Skill Parity. Source of truth: the `21cav-rams` Claude skill vendored at `.planning/reference/21cav-rams-skill/`. Where the app and the skill disagree on safety content, structure or scoring, the skill wins. v2.0 Engineering-Grade AV Drawings is **paused** mid-milestone (Phase 24 Plan 09 + Phase 25 remain open) while this milestone runs.

## Roadmap Overview

| Milestone | Theme | Phases | Status |
|-----------|-------|--------|--------|
| v1.0 | RAMS MVP | 01–07 | ✅ Shipped — [archive](milestones/v1.0-ROADMAP.md) |
| v1.1 | Operations Dashboard & Notifications | 08–09 (10/11 deferred) | ✅ Shipped 2026-04-25 — [archive](milestones/v1.1-ROADMAP.md) |
| v1.2 | Installation Programme & Field Management | 12–16 | ✅ Shipped 2026-04-25 — [archive](milestones/v1.2-ROADMAP.md) |
| v1.3 | Technical Drawings & Schematics | 17–20 (19 → v2.0) | ✅ Shipped 2026-05-09 — [archive](milestones/v1.3-ROADMAP.md) |
| v2.0 | Engineering-Grade AV Drawings | 21–25 | ⏸ Paused — 24-09 + Phase 25 open |
| **v3.0** | **RAMS Skill Parity** | **26–31** | **📋 Planned** |
| v1.4 | Client Portal & Project Visibility | 32–35 | 📋 Planned (renumbered after v3.0) |
| v1.5 | Financial & Proposal Engine | 36–39 | 📋 Planned (renumbered after v3.0) |
| v1.6 | Service & Inventory | 40–43 | 📋 Planned (renumbered after v3.0) |

---

## 📋 v3.0 RAMS Skill Parity (Planned)

**Milestone Goal:** Close the gap between the `21cav-rams` Claude skill's settled methodology and what the app actually generates. A professional review of a real generated RAMS (21CQ30960, VW Blakelands) found defects the skill's own documents had already predicted by name — the double "Associated risks" line and the podium-steps contradiction are both written down in `house-rules.md` as known failure modes (both independently fixed by quick task `260817-r5e` before this milestone opened). This milestone ships the rest: 10 deterministic validation gates, 10 house rules enforced in code, and a hazard-library reconciliation that inverts the register from "full, user prunes" to "empty, user/job adds to."

**The structural inversion is the spine.** `PORTING-NOTES.md`:

> *"The default should be an empty register that the user adds to, never a full register the user prunes."*

Two separate mechanisms currently violate this, not one:

1. `config/rams_tier1.php:52` — 11 fixed `baseline_hazards`, injected via `Tier1RamsDefaultsService` whenever reviewed data supplies no hazards (fallback-only).
2. `App\Core\Modules\KnowledgeLibrary\HazardLibraryService::MANDATORY_KEYWORDS` (`:36-44`) — 7 hazard keywords **always** merged into every resolved hazard set via `mergeWithMandatory()`, regardless of what the engineer selected or the AI extracted. This one is stronger than the config fallback and was not called out by name in REQUIREMENTS.md's framing — Phase 26 must fix both or the inversion is incomplete.

Phase 26 (HAZ-01..04) fixes both, first, because several later gates and rules are only meaningful once hazard inclusion is conditional — GATE-05 (uniform-scoring detection) and GATE-10 (COSHH/standards padding) are near-meaningless against a fixed register, and RULE-02/03/01/06 edit hazard content that Phase 26 replaces wholesale.

**Real-data risk found during roadmapping (see "Standing hazards" in the roadmapping brief — "a rule shipped last week would have turned the entire dashboard amber 7 days after deploy"):** several GATE requirements would fire as false-positive errors on *every single RAMS generated today* if shipped before their paired RULE fix, because the current defaults already violate the house rule the gate is meant to enforce:

- **GATE-11** (CDM duty-holder ≠ "[To be confirmed]") — `RamsComplianceUpgradeService.php:1035-1036` hardcodes `'[To be confirmed]'` for `principal_designer`/`principal_contractor` on every job today. Shipping GATE-11 before RULE-07 would error on 100% of occupied-premises RAMS.
- **GATE-12** (named A&E must be real) — `resources/views/pdf/rams.blade.php:1953` hardcodes *"Nearest hospital A&E to be identified at site induction."* as the literal default, with a `'TBC'` fallback at `:1976`. Shipping GATE-12 before RULE-08 would error on effectively every RAMS.
- **GATE-07** ("confined space" mislabel) — `HazardLibraryService`'s current mandatory-keyword fallback title is literally `Str::title('confined spaces')`. Shipping GATE-07 before RULE-06/HAZ-01 retitle it would error on the current baseline itself.
- **GATE-06** (FFP2 → error) — `config/rams_tier1.php:129` still reads FFP2 (contradicting `:286`'s FFP3). Shipping GATE-06 before RULE-01 would error on the current baseline itself.
- **GATE-10** (standards/COSHH padding) — `config/rams_tier1.php`'s `coshh_products` is injected **unconditionally** (not fallback-only like hazards/standards — see `Tier1RamsDefaultsService::injectDefaultsIntoRamsData():82`), and the 9-entry standards table always renders in full. Shipping GATE-10 before RULE-04/05 would error on every RAMS with a non-empty standards or COSHH table.

Every phase below pairs a GATE with the RULE fix (or the Phase 26 hazard-shape change) it depends on for correctness, in the same phase, so no gate ships ahead of the default it's meant to police.

**Phases:** 26–31 (6 phases). Continues numbering from v2.0 (21–25); v1.4/v1.5/v1.6 renumbered to 32–43 below.

**Sequencing / file-contention note:** `config/rams_tier1.php` is the single most-touched file across this milestone (Group B and Group C both write to it). Phase 26 restructures it most heavily (hazard list → include-when library); Phases 27, 28 and 31 make narrower edits to content Phase 26 creates. All four depend on Phase 26 landing first for exactly this reason — editing the current fixed arrays before Phase 26 replaces them would be immediately overwritten. Phase 29 (CDM/A&E) and Phase 30 (structural gates) touch different files (`RamsComplianceUpgradeService.php`, PDF templates) and carry less contention risk.

**Documentation discrepancy found during roadmapping:** `REQUIREMENTS.md`'s v3.0 header states "Total requirements: 24," but the itemised list is GATE-01..12 (12) + RULE-01..10 (10) + HAZ-01..04 (4) = **26**, of which GATE-03 and GATE-08 are already shipped (24 remain open). The roadmap below covers all 26 IDs — the 24 that need new work, plus GATE-03/GATE-08 marked already-shipped for traceability, per the source document's own "listed for traceability, not rework" framing. Flagging rather than silently dropping 2 IDs to force the stated total.

### Phases

- [x] **Phase 26: Hazard Library Structural Inversion** — Port all 18 `hazard-library.md` hazards with include-when conditions, replacing `config/rams_tier1.php` baseline_hazards AND `HazardLibraryService::MANDATORY_KEYWORDS`; align scores to the skill (incl. Working at Height residual 1×4); typical scores are editable defaults, never silently applied. Foundation for Phases 27–31.
- [x] **Phase 27: Manual-Handling & Display-Lift House Rules** — Display lifts take banded team sizes (no row ≤14″, 1 operative <55″, 2 minimum 55–90″, 3 minimum >90″, never 4+), resolved from one shared source; wall-mount removal stated as the highest-risk lift; mount/bracket rows stop inheriting display handling text (RULE-12); GATE-09 errors on any non-conforming lift. *(RULE-02 amended 2026-08-25 — deliberate 21CAV override of the skill; see REQUIREMENTS.md RULE-02.)*
- [x] **Phase 28: PPE, Ceiling & Electrical Boundary House Rules** — FFP3 (not FFP2) everywhere; "confined space" never applied to ceiling void/comms room/riser; electrical scope boundary + ceiling load statements land in output; GATE-06 + GATE-07 ship alongside. *(**COMPLETE + DEPLOYED LIVE 2026-09-07**, 4/4 criteria — see `28-08-SUMMARY.md`. 8 plans, 29 commits. Two production backfill migrations cleared the whole corpus: 54 documents' PPE arrays, 48 residual control lines across 34 documents, 52 documents' legacy `Confined Spaces` hazard name, and 32 documents' exclusions. Armed gate verified passing on the real 21CQ30960 payload (project 92, RAMS 102). RULE-11 moved to Phase 31 during discussion — see D-09.)*
- [ ] **Phase 29: CDM Duty-Holder & Emergency Arrangements** — Settled sole-Contractor CDM position replaces "[To be confirmed]"; named A&E with address replaces "to be identified at site induction"; GATE-11 + GATE-12 ship alongside.
- [ ] **Phase 30: Structural Validation Gates** — Orphan-controls check, every-area-has-a-method-step check, residual-≤-initial-score check (GATE-01, GATE-02, GATE-04).
- [ ] **Phase 31: Standards/COSHH Scoping & Padding Gates** — Standards table and COSHH list become job-conditional (extends Phase 26's include-when pattern); uniform-scoring detection + COSHH/standards padding cross-check (GATE-05, GATE-10); **plus RULE-11 — one consistent fire-stopping position across exclusions, hazard register and QA (moved here from Phase 28 on 2026-09-05)**.

### Out of scope for v3.0 (deferred to v3.1+)

- Hold points as first-class objects (owner / state / blocking) — `PORTING-NOTES.md` calls this the single biggest upgrade over the skill; new capability, not parity
- Site-level inheritance (asbestos register, access, welfare, A&E per site) — **note:** GATE-12 (Phase 29) wants a maintained A&E dataset; without site-level storage, Phase 29 planning must choose a scoping approach (curated static list / plausibility check / explicit defer) rather than assume a live per-site lookup
- Revision letters, supersede handling and diffing between revisions
- Persisting the source JSON as an audit trail
- Dynamic section cross-reference resolution (`§6.4` breaking when optional sections are omitted)
- Toolbox-talk capture surface with signatures
- Making `itIntegration` and similar Teams-Rooms-shaped sections conditional on activity

### Canonical refs

- Source of truth: `.planning/reference/21cav-rams-skill/PORTING-NOTES.md` (12 validation gates, the two-layer split)
- `.planning/reference/21cav-rams-skill/references/house-rules.md` (settled positions — RULE-01..10)
- `.planning/reference/21cav-rams-skill/references/hazard-library.md` (18 hazards, typical scores, include-when — HAZ-01..04)
- Already-shipped: `.planning/quick/20260817-rams-generator-defects/SUMMARY.md` (quick task 260817-r5e — GATE-03, GATE-08)
- Real review defect trigger: 21CQ30960 (VW Blakelands) professional review
- Config most touched: `config/rams_tier1.php`; second injection mechanism: `app/Core/Modules/KnowledgeLibrary/HazardLibraryService.php`
- Live PDF template: `resources/views/pdf/rams.blade.php` (`rams-v2.blade.php` exists but `RAMS_UNIFIED_COMPOSER` is unset in production — not live, per 260817-r5e's finding)

### Phase 26: Hazard Library Structural Inversion

**Goal**: Replace both unconditional hazard-injection mechanisms — `config/rams_tier1.php`'s 11 `baseline_hazards` (fallback-only-when-empty, via `Tier1RamsDefaultsService`) and `HazardLibraryService::MANDATORY_KEYWORDS` (7 keywords, ALWAYS merged into every resolved hazard set regardless of engineer selection) — with the skill's full 18-hazard library, each carrying an include-when condition. A new RAMS starts from an empty register; hazards populate only when the job's captured scope/activities match a hazard's include-when trigger. Typical L×S scores from `hazard-library.md` are ported as editable defaults, never silently committed, aligning residual severity where the skill holds it at initial severity (Working at Height 1×4, not the current baseline's 2×3).
**Depends on**: Nothing (first phase of v3.0; foundation for Phases 27–31, all of which edit hazard-shaped content this phase restructures)
**Requirements**: HAZ-01, HAZ-02, HAZ-03, HAZ-04
**Success Criteria** (what must be TRUE):

  1. All 18 `hazard-library.md` hazards exist in the app's hazard source — the 10 the app already carries (reconciled to skill wording/scores) plus the 8 newly ported (Noise and vibration, Restricted access and ceiling voids, Low voltage AV connections, Asbestos-containing materials, Vehicle and plant movement, Lone and small-team working, Fire and evacuation, Decommissioning and WEEE) — each carrying an include-when condition
  2. Creating a new RAMS (review form, quote-import auto-seed, or AI extraction) starts with zero pre-populated hazards; only hazards whose include-when condition matches the job's captured activities/scope appear — replacing BOTH `config/rams_tier1.php:52` (`baseline_hazards`, currently injected whenever reviewed hazards are empty) AND `HazardLibraryService::MANDATORY_KEYWORDS` (`:36-44`, currently always merged in via `mergeWithMandatory()` regardless of what was selected)
  3. Typical initial/residual scores are visibly pre-filled but editable — never committed to `generated_data` without a human or model touch-point; Working at Height residual renders 1×4 (not the current baseline's 2×3 at `config/rams_tier1.php:67-68`)
  4. Regenerating a real project (21CQ30960) shows only hazards its actual scope supports, manually spot-checked against the source quote — not validated against the old fixed 11/7-item lists, which cannot contain the answer

**Plans**: 6 plans across 4 waves
Plans:
**Wave 1**

- [x] 26-01-PLAN.md — Migration + HazardTemplateSeeder rewrite (18-hazard library, include_when tiers, orphan-row cleanup) + BLOCKING migrate/seed
- [x] 26-02-PLAN.md — HazardIncludeWhenResolver (tier 1/2/3 evaluation service) + unit tests
- [x] 26-03-PLAN.md — Remove injection paths #1/#2/#3/#4 (Tier1RamsDefaultsService, rams.blade.php, rams-v2.blade.php, RiskAssessmentComposer) + new RAMS_HAZARD_LIBRARY_TIERING kill-switch

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 26-04-PLAN.md — Remove path #5 (HazardLibraryService mandatory-baseline); wire tiered resolver into RiskTemplateResolverService + call sites

**Wave 3** *(blocked on Wave 2 completion)*

- [x] 26-05-PLAN.md — HAZ-04 editable score defaults + reviewed marker + needs-confirmation badge (RamsReviewDataService, reviewedToRisk(), quote-review.blade.php)

**Wave 4** *(blocked on Wave 3 completion)*

- [x] 26-06-PLAN.md — DOCX-path verification, RA-ref regression (Tasks 1-2 done); live deploy + 21CQ30960 spot-check (human checkpoint, Task 3, still open)

**Wave 5** *(gap closure, spawned by live verification after 26-06 Tasks 1-2)*

- [x] 26-07-PLAN.md — Wire tiered resolution into `runFromReview()` (the third, previously-unwired generation path); gate the newly-traced sixth injection path (`RamsComplianceUpgradeService::addProjectSpecificRisks()`); structural regression guard

**UI hint**: yes (empty-register UX, include-when-driven hazard population, editable score inputs on the RAMS review screen)

### Phase 27: Manual-Handling & Display-Lift House Rules

**Goal**: Every generated RAMS states a display-lift team size conforming to RULE-02's banded position — no manual-handling row for ≤14″ control panels, 1 operative under 55″, minimum 2 from 55″ to 90″ inclusive, minimum 3 above 90″, never 4 or more — resolved from one shared source; mount and bracket rows stop inheriting display handling text; wall-mount removal is called out as the highest-risk lift on a strip-out; and the generator errors rather than silently accepting a non-conforming lift. *(Goal restated 2026-08-25 to match the amended RULE-02 and success criteria 1 and 3 below; the original read "the two-operative display-lift position without exception", which predates the correction.)*
**Depends on**: Phase 26 (RULE-02/03 edit the Manual Handling hazard's control text, which Phase 26 replaces wholesale — editing the pre-Phase-26 content would be immediately overwritten)
**Requirements**: RULE-02, RULE-03, RULE-12, GATE-09
**Success Criteria** (what must be TRUE):

  1. Every place that states a display lift team size resolves it from **one shared source** and produces RULE-02's amended bands — no row for ≤14″ control panels, 1 operative under 55″, minimum 2 from 55″ to 90″ inclusive, minimum 3 above 90″, never 4 or more. The `≥85″ → 4 persons` and `≥65″ → 3 persons` ladder in `RamsComplianceUpgradeService::suggestHandlingMethod()` (`:1254-1266`) is gone, as is the aid-as-substitute wording ("Two persons may lift if using a panel-lift trolley") above 90″; mechanical aids are stated as additional, never a substitute. *(**Amended 2026-08-25**, corrected same day — see RULE-02's amendment note. Also corrects the original criterion's file reference: `config/rams_tier1.php:78` no longer holds a lift threshold — Phase 26 removed `baseline_hazards` from that file entirely.)*
  2. Where `scope_items.decommission` contains a wall-mounted display, the generated method statement or hazard control explicitly states the removal-from-mount sequence: controlled to lowest practicable height, one operative each side, before release from the mount — and that sequence does **not** appear on an installation-only job. *(**Restated 2026-08-26 (--force edit).** Originally read "Where a job's scope includes decommission/strip-out…", which made this criterion depend on quote-package classification rather than on anything Phase 27 builds. Live verification (RAMS 100/102) found `scope_items.decommission` empty on 21CQ30960 — all 24 items classified `new_install` — so the criterion failed against correct, deployed code that simply had no input. The emission behaviour IS built and provable: `deriveMaterialHandling()` emits the sequence when the decommission bucket is populated, and the seeder deliberately does not emit it unconditionally (see 27-08 and `deferred-items.md`). The classification gap is real but upstream — raised separately as **DATA-01**, not as a Phase 27 failure.)*
  3. GATE-09 errors on a display lift that does not conform to RULE-02's bands — 4 or more operatives at any size, 2 operatives above 90″, or 1 operative at 55″ or larger — proven by reverting the fix on a fixture and observing the gate fire, then restoring. A 1-operative lift below 55″ and an unresolvable display size both pass clean. *(Amended 2026-08-25 alongside RULE-02.)*
  4. Regenerating 21CQ30960 (or another live strip-out job) does not trip GATE-09 — confirms the fix and the gate agree against real data, not just a fixture

**Plans**: 8 plans, 5 waves (27-06, 27-07 added mid-execution in Wave 3; 27-08 added in Wave 5 after live verification)
Plans:

- [x] 27-01-PLAN.md — DisplayLiftPolicy (D-03 single shared source: bands, independent violatesPolicy() re-check, RULE-03 statement, seeder-facing summary) + unit tests. Wave 1.
- [x] 27-02-PLAN.md — RULE-02 ladder replacement + RULE-12 branch-order fix in suggestHandlingMethod(); RULE-03 decommission-scope scan in deriveMaterialHandling(); HazardTemplateSeeder re-sourced from DisplayLiftPolicy. Wave 2 — depends on 27-01. **LANDED 2026-08-26** (RULE-12's weight-derivation clause deliberately deferred — see 27-02-SUMMARY.md).
- [x] 27-03-PLAN.md — GATE-09: enforceDisplayLiftGate() wired into upgrade(), RAMS_DISPLAY_LIFT_GATE env flag, dual-path proof (runFromReview/runPipeline), structural guard against divergent bands. Wave 3 — depends on 27-02.
- [x] 27-04-PLAN.md — D-04 worksheet parity: SafetyProfileService + MethodStatementService fallback string read DisplayLiftPolicy. Wave 2 (parallel with 27-02) — depends on 27-01. **LANDED 2026-08-26** (see 27-04-SUMMARY.md).
- [x] 27-06-PLAN.md — **Added mid-execution** (Wave 3, depends on 27-01/27-02/27-03/27-04) after a coverage gap was found in 27-03's shipped GATE-09: extends enforceDisplayLiftGate() to also validate engineer-typed material_handling.large_items rows, which the original gate could never check (policy-derived items are conformant by construction). **LANDED 2026-08-26** (see 27-06-SUMMARY.md).
- [x] 27-07-PLAN.md — **Added mid-execution** (Wave 3, depends on 27-01/27-02/27-03/27-04/27-06) to close the last two GATE-09 bypass paths 27-06 found but did not fix: mirrors material_handling in RamsController::updateAndDownload() before upgrade(), and re-points the live PDF template at generated_data['material_handling'] (gated) with a reviewed_data fallback for pre-phase documents. **LANDED 2026-08-26** (see 27-07-SUMMARY.md).
- [x] 27-05-PLAN.md — Live deploy + reseed + 21CQ30960 regeneration verification (ROADMAP success criterion 4) + rollback-flag smoke test. Wave 4 — depends on 27-02, 27-03, 27-04, 27-06, 27-07. **EXECUTED MANUALLY 2026-08-26** (human checkpoints, no SUMMARY by design): deployed as `stcav`, kill-switch rollback proven, 21CQ30960 regenerated (RAMS 100/102) — see 27-VERIFICATION.md.
- [x] 27-08-PLAN.md — **Added Wave 5** after live verification found the hazard library never reached a RAMS regenerated from existing reviewed data (27-VERIFICATION.md Blocker 1). Three-tier control precedence in reviewedToRisk() gated on a new `controls_reviewed` marker mirroring `score_reviewed`, plus `ControlTextRuleViolations` and a backfill migration. **LANDED + VERIFIED LIVE 2026-08-26** — 60 documents / 438 hazard rows backfilled, `over 20 kg` now clean on RAMS 102 (see 27-08-SUMMARY.md).

**UI hint**: yes (gate errors surface on the RAMS review screen)

### Phase 28: PPE, Ceiling & Electrical Boundary House Rules

**Goal**: Fix the FFP2/FFP3 contradiction and the "confined space" mislabel at every occurrence (config, `HazardLibraryService` fallback, and Phase 26's ported library), and ensure the ceiling-load and electrical-scope-boundary statements land in generated output; ship GATE-06 and GATE-07 in the same phase so neither fires against a still-broken default.
**Depends on**: Phase 26 (RULE-01/RULE-06 edit hazard content Phase 26 restructures; GATE-07's "confined space" check needs the retitled "Restricted access and ceiling void working" hazard from HAZ-01 to exist first, or it fires against the app's own pre-fix baseline)
**Requirements**: RULE-01, RULE-06, RULE-09, RULE-10, GATE-06, GATE-07
> **RULE-11 moved out to Phase 31 on 2026-09-05.** It was listed here but appeared in none of the four success criteria below, so it would have shipped silently unbuilt or been built with nothing to verify against. Its defect lives in the COSHH table — the Expanding Foam entry already named in Phase 31 criterion 2 — and RULE-05/GATE-10 own that table. Decision and rationale: `28-CONTEXT.md` D-09.
**Success Criteria** (what must be TRUE):

  1. No respiratory-PPE mention anywhere in generated output reads FFP2 — `config/rams_tier1.php:129` (Dust from drilling) and `:286` (Expanding Foam COSHH entry, already FFP3) agree; face-fit testing is stated
  2. No hazard title, fallback string, or generated document text labels a ceiling void, comms room or riser "confined space[s]" — including `HazardLibraryService`'s prior "confined spaces" mandatory-keyword fallback (`:36-44`, `:210-215`) — all read "Restricted access and ceiling void working"
  3. A generated RAMS for a job with ceiling-mounted AV equipment states the ceiling-load position (supported from structural soffit or purpose-designed mount kit — never suspended grid, pipework or sprinkler pipe) and, where the job touches mains power, the electrical scope boundary (terminates at existing socket/data outlet, no alteration to fixed installation, no live working)
  4. GATE-06 errors on any FFP2 occurrence and GATE-07 errors on any ceiling-void/comms-room/riser hazard mislabelled "confined space" — both verified by reintroducing the defect on a fixture and observing the error, then restoring, and both pass clean against a freshly regenerated real project

**Plans**: 8 plans, 3 waves (planned 2026-09-05)

  - [x] 28-01-PLAN.md — `ControlTextRuleViolations` ffp2/confined_space detectors + negation-aware proof corpus (D-01). Wave 1. Requirements: RULE-01, GATE-07.
  - [x] 28-02-PLAN.md — RULE-06 hazard title rename to "Restricted access and ceiling void working" across seeder, fold map (+ new supersession entry) and drift-guard test (D-05). Wave 1. Requirements: RULE-06.
  - [x] 28-03-PLAN.md — new `PpeVocabularyFoldMap` closed-vocabulary fix for the `reviewed_data['ppe']` array gap research found (Q4), wired into `reviewedToRisk()`/`mergePpe()`, plus an end-to-end render regression test. Wave 1. Requirements: RULE-01.
  - [x] 28-04-PLAN.md — remaining 12 live FFP2 source-site fixes + repo-wide static FFP2 ban test (D-04). Wave 1. Requirements: RULE-01, GATE-06.
  - [x] 28-05-PLAN.md — RULE-09 electrical-boundary exclusions bullet (unconditional default, D-06/D-07 minimum-sentence scope) + RULE-10 end-to-end regression lock for the already-firing ceiling-load signal (Q1). Wave 1. Requirements: RULE-09, RULE-10.
  - [x] 28-06-PLAN.md — GATE-06/GATE-07 throwing re-check in `RamsComplianceUpgradeService::upgrade()`, new `RAMS_PPE_CEILING_ELECTRICAL_GATE` flag, closes the `RamsController::downloadPdf()` catch gap (D-03/D-08). Wave 2 — depends on 28-01, 28-04. Requirements: GATE-06, GATE-07.
  - [x] 28-07-PLAN.md — measure-first production count (checkpoint) + idempotent backfill migration for already-persisted `reviewed_data['ppe']`/`['exclusions']` (D-08, orchestrator-mandated). Wave 2 — depends on 28-03, 28-05. Requirements: RULE-01, RULE-09.
  - [x] 28-08-PLAN.md — full test suite + live production regeneration checkpoint against 21CQ30960 (ROADMAP criterion 4). Wave 3 — depends on all prior plans. Requirements: RULE-01, RULE-06, RULE-09, RULE-10, GATE-06, GATE-07.

**UI hint**: yes (gate errors surface on the RAMS review screen)

### Phase 29: CDM Duty-Holder & Emergency Arrangements

**Goal**: Replace the unconditional CDM duty-holder placeholder and the hardcoded "to be identified at site induction" A&E line with the settled positions, and ship GATE-11/GATE-12 so a RAMS can no longer go out the door with either placeholder.
**Depends on**: Nothing structurally — touches `RamsComplianceUpgradeService.php` and the PDF templates, not the hazard register Phase 26 restructures; may run before or after Phases 27–28
**Requirements**: RULE-07, RULE-08, GATE-11, GATE-12
**Success Criteria** (what must be TRUE):

  1. `RamsComplianceUpgradeService::upgrade()`'s CDM duty-holder defaults (`:1035-1036`, currently hardcoded `'[To be confirmed]'` for `principal_designer`/`principal_contractor` on every job) state the settled sole-Contractor position on an occupied-premises job instead
  2. The Emergency Procedures section's nearest-A&E line (`resources/views/pdf/rams.blade.php:1960`, currently the literal string "Nearest hospital A&E to be identified at site induction.", plus its `'TBC'` fallbacks) is replaced by a resolver that names a verified 24/7 A&E with full address and postcode where one is recorded for the site, or states the house-rule hold-point line ("Nearest A&E — to be confirmed at induction (must be a 24/7 Emergency Department)") where it is not — never a guessed hospital name
  3. GATE-11 errors when the CDM duty-holder table is left as "[To be confirmed]" on an occupied-premises job; GATE-12 errors when the named A&E does not resolve to a real, currently-open A&E — **scoping flag**: no UK A&E open/closed dataset exists in this codebase today, and site-level A&E storage is explicitly out of scope for v3.1 (see Out of Scope above), so phase planning must pick an approach (curated static list, plausibility check, or explicit narrower scope) rather than assume a live lookup exists
  4. Regenerating a live occupied-premises project shows a stated CDM position and a real named A&E with address, not either placeholder — verified against production data, not just a fixture

**Plans**: 6 plans across 4 waves

Plans:
**Wave 1**

- [x] 29-01-PLAN.md — Measurement checkpoint (production CDM count, live composer state, suite runtime) + RULE-08/criterion-2 restatement (D-06)
- [x] 29-02-PLAN.md — SiteEmergencyResolver (RULE-08 branch + GATE-12 classifier) + disarmed gate config flag + EmergencyComposer/DTO wiring

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 29-03-PLAN.md — RULE-07 CDM wording restatement + DOCX CDM fallback fix + GATE-11/GATE-12 wired into upgrade()

**Wave 3** *(blocked on Wave 2 completion)*

- [x] 29-04-PLAN.md — All 5 A&E/Welfare render sites fixed (both blades + DocxBuilderService) + regression test
- [x] 29-05-PLAN.md — Idempotent CDM placeholder backfill migration + carry-forward guard (D-04)

**Wave 4** *(blocked on Wave 3 completion)*

- [x] 29-06-PLAN.md — Dual-path gate proof + fixture regeneration + full-suite verification + live production checkpoint. **Tasks 1-2 LANDED 2026-09-11** (`4a5edf1`, `d3dc28a`) — dual-path GATE-11/GATE-12 reachability proof, `tilda-21cq29531` fixtures regenerated, full suite green (2515 passed, 1 pre-existing unrelated failure). **Task 3 PARTIALLY COMPLETE (2026-09-11):** deploy + Plan 29-05 backfill migration verified live on production (46/54 rows backfilled, matches 29-01 measurement exactly — see `29-MEASUREMENT.md`). **Visual PDF/DOCX document inspection is still OUTSTANDING** — success criterion 4 below is NOT yet met; see `29-06-SUMMARY.md`.

**Gap closure** *(spawned by 29-UAT.md live document verification — not part of the original 6-plan/4-wave plan above)*

- [x] 29-09-PLAN.md — Gap-closure (WR-01): `rams.blade.php` Section 7.0 A&E cell falls back to `SiteEmergencyResolver::resolve()` when `site_emergency_resolved` is absent (the `RamsRegenerateSnapshotsCommand` upgrade()-bypass path), instead of rendering blank. Requirements: RULE-08.
- [x] 29-10-PLAN.md — Gap-closure (Gap 3, data half): `RamsComplianceUpgradeService::DEFAULT_CONTRACTOR_NOTE` constant extracted from `addCdmDutyHolders()`'s inline RULE-07 sentence, for Plan 29-11/29-12 to reference; idempotent (not yet run) `array_key_exists()`-guarded backfill migration written for the 46 rows whose `generated_data.cdm_duty_holders` predates the `contractor_note` key. Requirements: RULE-07.
- [x] 29-11-PLAN.md — Gap-closure (Gap 2 + PDF half of Gap 3): Section 7.0's Nearest A&E row renders unconditionally on both PDF blades (D-05 hold-point line shows even for a wholly empty `site_emergency`); CDM Duty Holders section carries the RULE-07 verbatim `contractor_note` sentence. Requirements: RULE-07, RULE-08.
- [x] 29-12-PLAN.md — Gap-closure (Gap 1 + DOCX half of Gap 3): DOCX regression fixed — new "7.0 Site-Specific Emergency Details" block in `DocxBuilderService` mirrors the PDF's resolver-backed A&E row instead of leaving the Word RAMS with no nearest-A&E information; DOCX CDM section carries the RULE-07 `contractor_note` paragraph. Requirements: RULE-07, RULE-08.
- [x] 29-13-PLAN.md — Gap-closure (Gap 4/6): DOCX brand-colour correction — `DocxBuilderService` and `config/rams_theme.php` corrected from Microsoft Word's stock "Blue, Accent 1" defaults (2E74B5/DEEBF7/333333) to 21CAV's actual brand palette (1B7A7A teal/F4FBFB pale-teal tint/1A1A2E navy), matching the PDF. Requirements: RULE-07, RULE-08.
- [x] 29-14-PLAN.md — Gap-closure cycle closeout: diff-first regeneration of all 4 `tilda-21cq29531` goldens (every changed line mapped to Plans 29-10 through 29-13), `--group snapshot` green, full RAMS suite (349/349, 1604 assertions) + whole-repo suite (2541 passed, 1 pre-existing unrelated failure) both green, GATE-11/GATE-12 confirmed still disarmed by default and their own coverage unaffected. Requirements: RULE-07, RULE-08, GATE-11, GATE-12. Human/production actions still outstanding: Plan 29-10's backfill migration not yet run against production; the 29-UAT.md human_verification CDM Client-row item remains open.

**UI hint**: yes (gate errors surface on the RAMS review screen; CDM/A&E fields may need review-form inputs)

### Phase 30: Structural Validation Gates

**Goal**: Ship the five gates that check document-structural consistency — orphan controls, area/method-step coverage, residual-vs-initial scoring, hot-works contradiction, and missing risk references — against the finished Phase 26 hazard shape. GATE-13 ships built-whole but disarmed in this phase and is flipped on in Phase 31 (D-02) — it is not descoped.
**Depends on**: Phase 26 (GATE-01's "matching hazard row" check and GATE-04's residual-score check need the final hazard set and HAZ-03's aligned scores to be meaningful, not the old fixed 11-hazard baseline)
**Requirements**: GATE-01, GATE-02, GATE-04, GATE-13, GATE-14
**Success Criteria** (what must be TRUE):

  1. GATE-01 errors when a method step or hazard control references a document, permit or hold point (e.g. "review the asbestos register") and the RAMS lacks EITHER a matching hazard row OR a matching client-responsibility entry — both supports are required — proven against a fixture reproducing the canonical asbestos-orphan failure named in `PORTING-NOTES.md`. (`clientReqs` is the skill's key; its app equivalent is the union of `client_responsibilities` and `client_responsibilities_expanded` — D-08.)
  2. GATE-02 errors when any area/room in the RAMS has zero method steps
  3. GATE-04 flags (does not silently accept) any hazard where residual severity is lower than initial severity, and errors when residual score exceeds initial score on any hazard
  4. Running all five gates against a real regenerated project (21CQ30960) with the Phase 26 hazard set passes clean — no false positives against legitimate, correctly-scoped output — proven by authored fixtures (a defect-bearing 21CQ30960 reproducing the real-world shape, and a clean one proving no false positives), not by a live-only UAT step. RAMS 97 of 2026-08-25 carried the defects; this criterion requires a post-fix regeneration to pass clean.
  5. GATE-13 errors when a RAMS asserts no hot works while also requiring a hot-works permit or listing solder/flux in COSHH — proven against a fixture reproducing the 21CQ30960 three-way contradiction (RA18 says no hot works, §6.8 requires a hot-works permit for soldering, COSHH carries Tin/Lead solder and rosin flux) — and does NOT fire on `addPermitAndIsolation()`'s own conditional permit line. Ships disarmed per D-02; flipped in Phase 31.
  6. GATE-14 flags (warn tier, does not block) any method step failing to cite a hazard its own text plainly implies where that hazard IS present in the register — proven against a fixture reproducing the Step 4 "Display & Mount Installation" defect that cites RA11/12/13/21 but omits RA01 Working at Height and RA02 Manual Handling. GATE-14 is warn tier, not error tier, because `associated_risks` is app-generated by `crossReferenceMethodStatementRisks()` (`:991-1092`) — a violation is a defect in the app's own derivation, not fixable from the review screen, so a blocking error there would be unactionable.

**Plans**: 9 plans
Plans:
- [x] 30-01-PLAN.md - Foundation: three disarmed kill-switch flags (D-04), the config-resident GATE-01 trigger vocabulary and GATE-14 implication map (D-06), the shared StructuralGateVocabulary matching helper (D-07/D-08), and the compliance_warnings advisory channel.
- [x] 30-02-PLAN.md - Data reachability: mirror client_responsibilities_expanded and a gate-private area list into the pipeline array at all three upgrade() entry points, with a dual-path non-vacuity proof. Without this GATE-01/02 report clean on every document.
- [x] 30-03-PLAN.md - GATE-01 (orphan controls; fires when EITHER support is missing, per D-05) and GATE-02 (area/method-step coverage; zero areas passes vacuously), behind RAMS_STRUCTURAL_GATES.
- [x] 30-04-PLAN.md - Warn surface per the approved 30-UI-SPEC: review-screen summary panel plus hazard-row marker, and the assertion that warning text never reaches a PDF or DOCX.
- [x] 30-05-PLAN.md - Documentation corrections (goal line says five gates per D-01; criterion 1 corrected per D-05; new criteria for GATE-13 and GATE-14) and 30-MEASUREMENT.md, the read-only corpus measurement and arming runbook.
- [x] 30-06-PLAN.md - GATE-04, the phase's only two-tier gate: error on residual score exceeding initial, warn on residual severity below initial; plus the byte-identical-when-disarmed and flag-independence proofs.
- [x] 30-07-PLAN.md - GATE-13 hot-works contradiction, built whole and shipped disarmed per D-02, including the regression proving it does not fire on addPermitAndIsolation()'s own conditional permit line.
- [x] 30-08-PLAN.md - GATE-14 missing risk references, WARN tier (the risks line is app-generated), with a static source guard proving it does not re-derive $keywordRiskMap.
- [x] 30-09-PLAN.md - The two 21CQ30960 fixtures (defect-bearing plus clean), snapshot wiring in both snapshot test files, ROADMAP criterion 4 proven automatically, and the phase gate.

**UI hint**: yes (gate errors/warnings surface on the RAMS review screen)

### Phase 31: Standards/COSHH Scoping & Padding Gates

**Goal**: Extend Phase 26's include-when pattern to the standards-references and COSHH-substances tables so they cite only what the job involves, then ship the two gates the source notes call out as "far more reliable... once inclusion is conditional" — uniform-scoring detection and COSHH/standards padding cross-check.
**Depends on**: Phase 26 (extends the same include-when mechanism to a second config table; GATE-05's uniform-scoring detection is only meaningful once hazard inclusion is conditional)
**Requirements**: RULE-04, RULE-05, RULE-11, GATE-05, GATE-10
> **RULE-11 (fire-stopping) moved here from Phase 28 on 2026-09-05** — see `28-CONTEXT.md` D-09. It lands here because its concrete defect is a COSHH-table entry (Expanding Foam described as a cable-penetration fire-stop), which criterion 2 already names, and because RULE-05/GATE-10 own that table. Note it is **wider than a COSHH scoping fix**: it also requires the exclusions list and the hazard register to state the same position, which is new surface for this phase. Its former blocker is closed — there is no 21CAV approved fire-stopping product to name; fire-stopping is excluded outright (`house-rules.md` §"Fire-stopping — one consistent position").
**Success Criteria** (what must be TRUE):

  1. The generated standards table cites only standards the job's captured activities actually involve — `config/rams_tier1.php:352-397`'s always-rendered 9-entry table (including BS EN 60849 voice-alarm, BS 8492 PA systems, HSG 47 underground services and BS EN 60825-1 laser safety, none of which are job-conditional today) becomes include-when scoped
  2. The generated COSHH table lists only substances the job actually carries — `Tier1RamsDefaultsService::injectDefaultsIntoRamsData()`'s unconditional `coshh_baseline` assignment (`:82`, currently ALWAYS set regardless of scope, unlike hazards/standards which are fallback-only) becomes include-when scoped, so a Teams Rooms install no longer shows solder flux or expanding-foam entries
  3. GATE-05 warns when most hazards on a RAMS share the same initial score (assembled-from-library signal) — verified against a fixture that deliberately reintroduces uniform scoring
  4. GATE-10 errors when a cited standard or COSHH substance has no supporting activity in the job's scope — verified against a fixture reproducing the named offenders (BS EN 60849, BS 8492, HSG 47, laser safety with no laser, soldering flux with no soldering) and passing clean against a freshly regenerated real project
  5. **(RULE-11, added 2026-09-05)** A generated RAMS takes ONE fire-stopping position across all three places it appears — the exclusions list, the hazard register and QA. Fire-stopping is excluded: any penetration of a fire-rated element is sealed by others or referred to the client with a specified detail before proceeding. No generated document both excludes fire-stopping and claims in a hazard row or QA to fire-stop penetrations "to the original rating", and no COSHH entry describes expanding foam as a cable-penetration fire-stop — verified by regenerating 21CQ30960, whose RAMS 97 pack carried exactly that contradiction (RA13 and RA18 correct, COSHH table contradicting both)

**Plans**: TBD
**UI hint**: yes (gate errors/warnings surface on the RAMS review screen)

---

## 📋 v4.0 Project Cockpit (Planned)

*"One page per project. Every trip to site is a visit. Nothing is said twice."*

Replaces the eleven-tab project page with a single cockpit of section drawers, closed at rest.
Each section is also its deliverable. Every attendance on site becomes a **typed visit** — site
survey, first fix, install, programming, snag, commissioning — and the type drives both what the
engineer's link contains and what must come back. RAMS and worksheets stop being project-level
documents and become artifacts of the visit they were written for.

**Design source of truth:** `.planning/sketches/002-install-cockpit/` (cockpit + panels) and
`.planning/sketches/003-quote-import/` (import review). Decisions were taken interactively on
2026-09-19; the sketch READMEs record what was chosen and why.

**Phases:** 44–51. Continues from the v1.6 outline (40–43); 32–43 remain reserved for
v1.4/v1.5/v1.6.

### Deliberately out of scope for v4.0

Each of these is **recorded in the admin Hidden Functions register**, not forgotten:

- **Visit costs** — no cost/rate capture. Deferred by decision 2026-09-19.
- **Install programme task planner** — the 52-task scheduler, week view, Gantt, field view and
  task assignment are hidden for launch so the system ships simple. ⚠️ `CommissioningItemGenerator`
  currently derives items from programme *tasks*; Phase 51 must re-source them from the asset
  register before this is safe.
- **In-app drawing generation** — schematics, rack builder, rack canvas editor, bound PDF.
  Drawings are produced in StarDrawer and uploaded (Phase 48) while the in-app builder continues
  separately.
- **Project-level RAMS view** — RAMS becomes per-visit; a "every RAMS on this project" surface is
  not rebuilt.

### Phases

- [x] **Phase 44: Labour Resources** — one table with a role field (engineer / programmer / other), admin add + remove, PM-facing assignment dropdowns. Client-facing output may expose a name only, never email or phone.
- [ ] **Phase 45: Visit Model + Read-only Cockpit** — a `visits` table wrapping existing `SiteSurvey` and `Worksheet` rows (backfilled, nothing deleted); the cockpit page behind a flag, read-only, alongside the existing project page.
- [ ] **Phase 46: Visit Lifecycle** — prepare, send, return, accept. Per-visit RAMS and worksheet scoped to the visit's type and rooms. A visit stays editable after sending; scope locks once a return arrives.
- [ ] **Phase 47: Snagging** — snag items separate from snag visits; three outcomes (fixed, not fixed, deferred); a not-fixed item requires the engineer to state actions and parts needed, closes the visit, and opens a new snag linked to the original. Parts tracked per snag. Snags may sit with the client or others and never have a visit.
- [ ] **Phase 48: Documents** — generate client-facing documents; the PM sends them and confirms sent in-app, recording who, when and which revision. O&M offered as full or mini. Drawings uploaded from StarDrawer.
- [ ] **Phase 49: Import Review + Self-populating Deliverables** — one import screen showing what the quote contains, with deliverables ticked from its lines and labelled *from quote* / *assumed* / *not found*. All nine always shown. The equipment-line review opens in a side panel.
- [ ] **Phase 50: Project Data Versioning** — re-import a later QuoteWerks revision: product, quantity and labour replace the old; superseded data is archived and viewable; images on removed rooms are retained against the archived version.
- [ ] **Phase 51: Commissioning as a Visit Type** — items derived per device from the asset register rather than from programme tasks, unblocking the hidden task planner.

### Phase 44: Labour Resources

**Goal**: A single labour-resource record with a role, maintained in admin, that the PM selects
from anywhere work is assigned — and which can never leak an engineer's contact details into
anything a client sees.

**Depends on**: Nothing. Deliberately first because it is standalone and useful on its own.

**Requirements**: Derived from the 2026-09-19 design decisions (LR-01..LR-05, minted at
planning time — no formal requirement IDs existed yet for this milestone; see
`.planning/sketches/002-install-cockpit/README.md`) mapping 1:1 to the five success criteria below.

**Success Criteria** (what must be TRUE):

  1. One table holds every labour resource with a role field (engineer / programmer / other) — a
     person may hold more than one role rather than appearing twice
  2. An admin can add, edit and deactivate a resource; deactivating preserves history on past
     visits rather than deleting the person
  3. A PM can select one or more resources anywhere work is assigned, by name
  4. **Contact details never reach a client-facing surface.** Email and phone are visible to the
     PM and admin only. Proven by a test asserting that no client-facing document or page renders
     a resource's email or phone, only the name
  5. Existing engineer names already recorded as free text (`captured_by`, worksheet sign-offs)
     still display correctly and are not broken by the new table

**Plans**: 4 plans, 3 waves

- [x] 44-01-PLAN.md — `labour_resources` schema + `LabourResource` model (roles json column, is_active, nullable user_id). Wave 1. Requirements: LR-01, LR-02.
- [x] 44-02-PLAN.md — Admin CRUD (`/admin/labour-resources`): add/edit/deactivate, no hard-delete. Wave 2 (parallel with 44-03). Requirements: LR-02.
- [x] 44-03-PLAN.md — PM-facing `<x-labour-resource-select>` multi-select component (active-only, name-only). Wave 2 (parallel with 44-02). Requirements: LR-03.
- [x] 44-04-PLAN.md — Privacy boundary proof: source-guard + real HTTP-render test that no client-facing surface renders email/phone. Wave 3 — depends on 44-01/44-02/44-03. Requirements: LR-04, LR-05.

**UI hint**: yes (admin CRUD plus a PM-facing selector)

---

### Phase 45: Visit Model + Read-only Cockpit

**Goal**: One typed `Visit` record that wraps every existing trip to site, and a cockpit page that
reads it — both shipped alongside the current project page, changing nothing a user sees until a
flag is turned on.

**Depends on**: Phase 44 (labour resources are assigned to visits).

**Requirements**: VIS-01..VIS-10, minted at planning time (2026-09-19) into
`.planning/REQUIREMENTS.md` § Milestone v4.0 › Group VIS. Derived from the 2026-09-19 design
decisions in `.planning/sketches/002-install-cockpit/README.md`, narrowed by 45-CONTEXT.md D-01.

**Success Criteria** (what must be TRUE):

  1. A `visits` table exists carrying a **type** (site survey / first fix / install / programming /
     snag / commissioning), its project, its scheduled date, its assigned labour resources, and its
     status
  2. Every existing `SiteSurvey` and `Worksheet` row is **wrapped** by a backfilled visit. Nothing
     is deleted, no existing row is rewritten, and every live `/survey/{token}` and
     `/worksheet/{token}` link keeps working exactly as it does today
  3. The cockpit page renders **read-only** behind a feature flag, on its own route, showing the
     programme spine and its section drawers closed at rest
     *(2026-09-20, D-09: the form changed, the substance did not. The spine is now a list of
     module rows and the drawer is a URL-driven right-hand side panel rather than an inline
     `<details>` accordion. The property this criterion protects — read-only, flag-gated, its
     own route, and nothing open until the PM opens it — is intact and is asserted by
     `CockpitSpineTest::test_no_panel_element_exists_in_the_dom_at_rest`.)*
  4. The existing eleven-tab project page is **untouched and remains the default**. With the flag
     off, the application behaves exactly as it does today — proven by a test, not by inspection
  5. Traffic lights on the cockpit are derived from data that already exists; this phase adds no
     new engineer-facing capture and no new writes

**Open questions carried from the sketch — ALL THREE NOW RESOLVED** at discuss-phase 2026-09-19.
Kept for decision history; see `45-CONTEXT.md` for the answers. Do not treat these as open:

  - Does the typed-visit model hold for every type, or do survey and install diverge too far to
    share one record?
  - `InstallProgramme` cannot be the master as it stands — `archiveExisting()` + `createForProject()`
    replace the whole record on every regenerate, which would orphan visits filed under it. Does
    Phase 45 split the durable record from the regenerable task list, or does Phase 46?
  - Brand: the cockpit sketch is 21CAV teal/Verdana; the live app is `#1E5FE0`/Inter. Either the
    cockpit starts a brand-aligned refresh or it is retokened to match. A half-branded app is worse
    than either.

**Plans**: 14 plans, 6 waves + a 6-wave replacement set. **45-01 is the sole wave-1 plan, deliberately serialised**: its Task 2
measures the D-06 behaviour-preservation baseline, which is only valid on a clean tree with no Phase
45 code applied. 45-02 (the `visits` migration) and 45-03 (`npm install` + `npm run build`) would
otherwise run concurrently with the measurement and corrupt it — every test in the subset uses
`RefreshDatabase` — and that corrupted number is re-asserted as the gate by 45-04 and 45-08.

- [x] 45-01-PLAN.md — Mint VIS-01..VIS-10 into REQUIREMENTS.md; **MEASURE** the InstallProgramme behaviour-preservation baseline (the research doc's 161 was a static count, never executed). **Sole wave-1 plan — serialised so the baseline is measured on a clean tree.** Wave 1. Requirements: VIS-01..VIS-10.
- [x] 45-02-PLAN.md — `visits` table + `Visit` model + factory; `(source_type, source_id)` unique index, no FK to the wrapped record so a visit outlives it. Wave 2 (depends on 45-01). Requirements: VIS-01, VIS-08.
- [x] 45-03-PLAN.md — `config/cockpit.php` (defaults FALSE) + `@fontsource/poppins` + `cav-tokens.css` + `cockpit.css` + Vite input. `@fontsource/poppins` is **[VERIFIED]** by the completed Package Legitimacy Audit in 45-RESEARCH.md, so this plan is autonomous — no human gate. Wave 2 (depends on 45-01). Requirements: VIS-10, VIS-05.
- [x] 45-04-PLAN.md — The D-06 split: `install_records` durable parent + **nullable** FK on `install_programmes`; `install_tasks` do NOT move; baseline re-asserted; `QueryException` catch round the `firstOrCreate` because `createForProject()` is not transaction-wrapped. Wave 3. Requirements: VIS-09.
- [x] 45-05-PLAN.md — `visits:backfill`, idempotent and dry-run by default; one visit per survey and per SIGNED worksheet; public token routes proven unaffected. Wave 3. Requirements: VIS-02, VIS-03, VIS-07, VIS-08.
- [x] 45-06-PLAN.md — Cockpit route + flag-gated read-only controller + page shell + state primitives (pip, tick-box, chip, tag, hint). Wave 4. Requirements: VIS-04, VIS-06, VIS-10.
- [x] 45-07-PLAN.md — The spine: nine drawers closed at rest, visit rows, reconstructed and superseded treatments, read-only fence test **scoped to the `cav-brand cav-cockpit` subtree** (the shared app layout's logout form, search input, buttons and `@vite`/Alpine scripts are out of scope by recorded decision), plus a five-table row-count invariance proof for criterion 5. Wave 5. Requirements: VIS-04, VIS-06, VIS-08.
- [x] 45-08-PLAN.md — Flag-off behaviour proof (404, zero markup, layout byte-identical) + whole-phase gate re-run + greyscale/320px human check. **The phase's only human checkpoint.** Wave 6. Requirements: VIS-05, VIS-03, VIS-09.

**THE PAGE 45-06 AND 45-07 BUILT WAS REPLACED.** On **2026-09-20**, after the original eight plans
had executed and shipped, the user supplied a new design (screenshot) and it was accepted as the
v4.0 design contract: `.planning/sketches/004-delivery-cockpit/README.md`. It **supersedes sketch
002** and **reverses D-07** — the cockpit uses the application's own blue and Inter inside the
existing left nav, not a 21CAV teal/Verdana/Poppins refresh (**D-08**). The drawer became a
URL-driven right-hand side panel with Overview / Files / Notes tabs (**D-09**); the traffic-light
pip became a status chip plus a count (**D-10**); a ninth **Snagging** module row was added so that
`Visit::TYPE_SNAG` reaches a screen (**D-16**). Plans **45-09..45-14** replace the page, not the
model: the `visits` table, the backfill, the `install_records` split and the flag-off proof from
45-01..45-08 are untouched. The original eight entries below are left exactly as they were — they
are executed history, and the roadmap records what happened, not a tidy fiction about what was
always intended. `45-UI-SPEC.md` (sketch-002 era) was deleted by 45-14; the labelled copy
`45-UI-SPEC-v1-superseded.md` is retained for its still-correct constraint analysis.
45-08's entry still calls itself "the phase's only human checkpoint" — that was true when it
was written. Its visual check was left OPEN (`45-FLAG-OFF-PROOF.md` § 8) and is now answered
against the replacement page, by 45-14. There is still exactly one human gate in Phase 45; it
moved.

- [x] 45-09-PLAN.md — Retarget the `.cav-*` token layer from teal/Verdana/Poppins to the app's blue and Inter (D-08, reversing D-07). CSS only — no Blade, no PHP — so the reversal is one auditable commit. Wave 1 (of the replacement set). Requirements: VIS-10.
- [x] 45-10-PLAN.md — `CockpitModulePresenter` (nine module rows per D-10/D-11/D-16, status chip + count phrase) and `CockpitHeaderPresenter` (masthead facts, the three KPI cards of D-12, one stage chip). Pure PHP, unit-tested, no markup — so a fabricated denominator cannot reach a PM's screen. Wave 2. Requirements: VIS-04, VIS-06.
- [x] 45-11-PLAN.md — The replacement page itself: masthead, KPI cards, stage chip, module list, and the side-panel shell with its **Overview** tab. Panel open/closed and tab state live entirely in the URL — zero JavaScript. The accordion, the pip and the hand-tick box are deleted. Wave 3. Requirements: VIS-04, VIS-10.
- [x] 45-12-PLAN.md — The panel's **Files** tab as the project's document library (D-13 — the user's own example of what the panel is for), the **Notes** tab, and the **Recent activity** feed from the existing `ProjectActivityLog` (D-14). Read-only listing and viewing only; upload is Phase 48. Wave 4. Requirements: VIS-04, VIS-08.
- [x] 45-13-PLAN.md — Reconcile the four cockpit test files with the rebuild: the read-only fence extended to 18 deferred affordances and 9 banned handler attributes across every open panel, 13 spine assertions retargeted and 2 retired with their subjects rehomed. **Found and fixed a real regression** — the module row had stopped disclosing `reconstructed` / `superseded` at rest (D-02/D-04). Wave 5. Requirements: VIS-04, VIS-06, VIS-08.
- [x] 45-14-PLAN.md — Whole-phase gate re-run (D-06 baseline, full suite, the three sha256 pins, `npm run build`), documentation close-out, and **the phase's only human checkpoint**: the design comparison, greyscale and 320px. **Blocking — not self-approvable.** Wave 6. Requirements: VIS-04, VIS-05, VIS-10.

**UI hint**: yes (new read-only page, flag-gated)

---

### Phase 46: Visit Lifecycle

**Goal**: A visit can be prepared, sent to whoever is attending, returned by them, and accepted by
the PM — with its RAMS and worksheet scoped to that visit's type and rooms rather than to the whole
project.

**Depends on**: Phase 45 (the `Visit` record and the cockpit that displays it), Phase 44 (labour
resources are who a visit is sent to).

**Requirements**: VL-01..VL-11 (minted 2026-09-20). **VL-12 is a recorded GAP against criterion 2
— see below and `.planning/REQUIREMENTS.md` § Group VL.**

**Success Criteria** (what must be TRUE):

  1. A PM can prepare a visit — type, date, rooms in scope, assigned labour resources — and send it,
     producing one engineer link whose content is driven by the visit's type
  2. A RAMS and a worksheet generated for a visit cover **only that visit's type and rooms**, not the
     whole project
  3. A visit stays editable after sending; **scope locks once a return arrives**, and the lock is
     visible rather than silent
  4. The PM can accept a returned visit, and acceptance is recorded with who and when
  5. Existing `/survey/{token}` and `/worksheet/{token}` links continue to work unchanged throughout

**Open questions** — ALL RESOLVED at planning time, 2026-09-20:

  - *Who may accept a visit?* **Any authenticated staff user.** The app's documented shared-workspace
    convention (`abort_unless(auth()->check(), 403)`, no role model beyond `EnsureUserIsAdmin`);
    inventing a PM role here would be a role model nothing else in the app has. (Plan 46-04.)
  - *Does accepting auto-close the deliverable?* **No — they stay separate**, the conservative reading
    46-CONTEXT.md names. Acceptance closes a visit; closing a deliverable stays its own act.
  - *What happens to a rejected return?* **The same visit reopens.** Send back sets `sent_back_at` +
    a required reason, and the engineer link becomes writable again by DERIVATION — nothing the
    engineer captured is cleared to achieve it. A fresh visit would orphan the first return.
  - *One link, or reissued?* **One.** The visit's link is the token link of the record its type
    selects — `/survey/{token}` or `/worksheet/{token}` — issued by the generator that already
    exists. A survey visit on a project that already has a live survey ADOPTS it rather than minting
    a second link. Reissue/revoke stays where it already lives (`worksheets.revoke-token`).

**Criterion 2 is NOT fully delivered — recorded as VL-12.** A visit captures `rooms_in_scope` and
the engineer link shows it, but the RAMS and worksheet GENERATORS are not scoped to a visit's rooms:
`RamsController::generateFromProject()` and `WorksheetController::generateFromProject()` take a
`Project` and nothing else, and no decision exists about how a room-scoped RAMS should be authored
from an AI pipeline driven by the whole quote. Raised at planning time rather than silently dropped.
Needs a user decision before it can be planned.

**Plans**: 8 plans

Plans:
- [ ] 46-01-PLAN.md — Visit lifecycle: seven stored acts, the rest derived (wave 1)
- [x] 46-02-PLAN.md — The minimal snag record, fenced against Phase 47 (wave 1)
- [x] 46-03-PLAN.md — **The survey → install carry-forward, read live (wave 1, D-01)**
- [ ] 46-04-PLAN.md — Quick actions: create a visit, generate a document; the fence retired per entry (wave 2)
- [ ] 46-05-PLAN.md — What the engineer sees: the link reopens, and the office says why (wave 2)
- [ ] 46-06-PLAN.md — Accept and send back, with a visible scope lock (wave 3)
- [ ] 46-07-PLAN.md — Office note and raise a snag; the visit row reaches its four-control cap (wave 4)
- [ ] 46-08-PLAN.md — End-to-end proof, fence re-proof, and the human check (wave 5)

**UI hint**: yes (prepare panel, send flow, return review — the cockpit's write half)

---

### Phase 47: Snagging

**Goal**: Snags are tracked items with a life of their own, resolved through visits but not dependent
on them — so an unresolved snag can never be lost in free text.

**Depends on**: Phase 46 (a snag is resolved by a visit, and a not-fixed snag opens a new one).

**Requirements**: Not yet minted. Mint SN-xx into `.planning/REQUIREMENTS.md` § v4.0 at planning time.

**Success Criteria** (what must be TRUE):

  1. A **snag item** is a distinct record from a **snag visit** — a snag may exist with no visit
     attached, and one visit may resolve several snags
  2. Each snag carries one of three outcomes: **fixed**, **not fixed**, **deferred**
  3. A **not fixed** outcome requires the engineer to state the actions and parts needed. That closes
     the visit and **opens a new snag linked to the original**, for the PM to action
  4. Parts required are tracked per snag
  5. A snag may sit with the client or a third party and never have a visit at all — that state is
     representable and visible

**Open questions** (resolve at discuss-phase):

  - How is a snag raised — PM free text only, or can an engineer raise one from a visit return?
    (User said "free text but give add another snag/issue" for PM entry on 2026-09-19.)
  - Is the chain of linked snags shown as a thread, or does each new snag only point back one step?
  - Does a deferred snag need a date or a reason, or is it simply parked?
  - Does a project with open snags block deliverable closure anywhere?

**Plans**: Not yet planned

**UI hint**: yes (snag list, per-snag panel, engineer-side fix/not-fixed capture)

---

### Phase 48: Documents

**Goal**: The PM generates client-facing documents from the cockpit, sends them, and confirms in-app
that they went — so "has the client got the RAMS" is a question the system can answer.

**Depends on**: Phase 46 (documents are per-visit artifacts), Phase 45 (the drawers they live in).

**Requirements**: Not yet minted. Mint DOC-xx into `.planning/REQUIREMENTS.md` § v4.0 at planning time.

**Success Criteria** (what must be TRUE):

  1. A PM can generate a client-facing document from within its section drawer
  2. The PM **confirms sent in-app**, and the system records **who sent it, when, and which revision**
  3. A section cannot read as complete on the strength of a document that was generated but never
     confirmed sent
  4. O&M is offered as **full or mini**
  5. Drawings are **uploaded** (produced in StarDrawer), not generated in-app, and carry the same
     sent-confirmation treatment

**Open questions** (resolve at discuss-phase):

  - Does the app send the document itself (email from the system) or does the PM send it outside and
    tick a box? The 2026-09-19 decision was "PM need to confirm sent in app", which implies the latter
    but does not rule out the former.
  - If a document is regenerated after being confirmed sent, does the confirmation clear?
  - Who counts as "the client" — a stored contact on the project, or typed per send?

**Plans**: Not yet planned

**UI hint**: yes (generate/send panel per drawer, revision + sent-state display)

---

### Phase 49: Import Review + Self-populating Deliverables

**Goal**: One screen at project creation that shows exactly what the QuoteWerks quote contains and
which deliverables it implies — so nothing is dropped without the PM seeing it.

**Depends on**: Nothing in v4.0 structurally, but should follow Phase 45 so it can adopt the
`.cav-brand` tokens rather than establishing a second visual language.

**Requirements**: Not yet minted. Mint IMP-xx into `.planning/REQUIREMENTS.md` § v4.0 at planning time.

**Success Criteria** (what must be TRUE):

  1. One import screen shows what the quote contains — rooms, hardware, client-supplied, cables,
     labour — before the project is created
  2. Deliverables are **ticked from the quote's lines** and each is labelled **from quote**,
     **assumed**, or **not found**
  3. **All nine deliverables are always shown**, ticked or not, so nothing is omitted silently
  4. The equipment-line review opens in a **side panel**, not a separate page
  5. An unusually high client-supplied proportion is surfaced before creation, because install tasks
     and the asset register are built from that split

**Open questions** (resolve at discuss-phase):

  - Programming: the 2026-09-19 decision is that it appears only if on the quote or the PM selects it
    at upload. Does the same conditional rule apply to any other deliverable?
  - Can the PM correct an equipment line's classification (hardware vs client-supplied) on this screen,
    or only after creation? Note the stale-key bug fixed in `ProjectDataService` — edits must reach the
    generators.
  - What happens if the quote cannot be parsed — block creation, or create with a warning?

**Plans**: Not yet planned

**UI hint**: yes (sketch 003 `.planning/sketches/003-quote-import/import-review.html` is the accepted design)

---

### Phase 50: Project Data Versioning

**Goal**: Re-importing a later QuoteWerks revision replaces the current project data while keeping
every superseded version viewable — so a PM can always see what the job used to be.

**Depends on**: Phase 49 (the import path this re-runs), Phase 45 (D-04's superseded treatment is the
precedent for how superseded data is shown).

**Requirements**: Not yet minted. Mint VER-xx into `.planning/REQUIREMENTS.md` § v4.0 at planning time.

**Success Criteria** (what must be TRUE):

  1. Re-importing a later revision replaces **product, quantity and labour** data with the new
  2. Superseded data is **archived and viewable**, never deleted
  3. Room data removed by the new revision is archived; **images on removed rooms are retained against
     the archived version**, not orphaned and not destroyed
  4. There is a way to view the old data from the project
  5. Data that is not sourced from the quote survives a re-import untouched

**Open questions** (resolve at discuss-phase):

  - Are visits, snags and sent-document records unaffected by a re-import? (The 2026-09-19 decision was
     "old project data is archived and superceded. Other data remains" — needs a precise boundary.)
  - Does a re-import invalidate documents already confirmed sent against the old revision?
  - Is there a diff view (what changed between revisions) or only a view of each version?

**Plans**: Not yet planned

**UI hint**: yes (version history and archived-data view)

---

### Phase 51: Commissioning as a Visit Type

**Goal**: Commissioning items derive per device from the asset register instead of from install
programme tasks — which is what currently keeps the task planner hidden.

**Depends on**: Phase 46 (commissioning becomes a visit type), Phase 49/50 (the asset register is
built from quote data).

**Requirements**: Not yet minted. Mint COM-xx into `.planning/REQUIREMENTS.md` § v4.0 at planning time.

**Success Criteria** (what must be TRUE):

  1. Commissioning items are derived **per device from the asset register**, not from `install_tasks`
  2. `CommissioningItemGenerator` no longer reads programme tasks — confirmed in Phase 45 research to
     do exactly that at `app/Services/CommissioningItemGenerator.php:93` and `:118`, with an
     `install_task_id` FK
  3. Existing commissioning records survive the change — the live `commissioning_items.install_task_id`
     FK and the UNIQUE constraint on `commissioning_signoffs.install_programme_id` are migrated, not
     broken
  4. Commissioning runs as a visit type like any other
  5. With commissioning re-sourced, the install programme task planner can be unhidden safely

**Open questions** (resolve at discuss-phase):

  - Does unhiding the task planner happen in this phase or a later one? Criterion 5 says it *can*, not
    that it does.
  - What happens to commissioning items already tied to a task that no longer drives them?
  - Does every device in the asset register generate an item, or only some classes of device?

**Plans**: Not yet planned

**UI hint**: yes (commissioning drawer, per-device item list)

---

## 🚧 v2.0 Engineering-Grade AV Drawings (Paused)

**Status note (2026-08-23):** Paused mid-milestone in favour of v3.0. Phases 21, 22, 22.1 and 23 are complete on disk. Phase 24 has one open plan (24-09, a bounded human-checkpoint curation task, out of autonomous-executor scope by design). Phase 25 remains unplanned. Resume either after v3.0 ships or opportunistically between v3.0 phases.

**Milestone Goal:** Auto-generate AV technical drawings at the engineering-grade fidelity of XTEN-AV / D-Tools / Lucidchart. Custom device cards (manufacturer logo + name + model + port rails), port-to-port cable routing, signal-type colour coding, sub-room zones, multi-page paginator with title block, sheet border. Output renders in the draw.io / mxGraph embed validated by spike `260509-ibx`. Visual contract = the XTEN-AV PAGING SYSTEM reference user shared 2026-05-09.

**Reference image:** XTEN-AV PAGING SYSTEM (saved in conversation 2026-05-09). Every PR is evaluated against "does it move us closer to this output?"

**Platform decision:** draw.io / mxGraph self-hosted (Apache 2.0). Spike validated 2026-05-09. Native build was the alternative — saves ~5–7 weeks vs full Konva canvas + custom SVG renderer.

**Phases:** 21–25 (5 phases, ~25-30 plans estimated, ~10–15 weeks)

**Strategy summary:** Tier 1 (auto-generic stencil per part_number) + Tier 2 (engineer-curated catalog growth via UI) combined. AI port extraction (Tier 3) lands as polish in Phase 25. v1.3 D2-based renderer stays usable as fallback for projects without sufficient catalog coverage.

### Phases

- [x] **Phase 21: Device Port Catalog + Stencil Cache** — `device_ports` + `device_stencils` tables; hand-curated top-50 device seed pack; auto-generic placeholder for uncatalogued parts; cross-project caching via `firstOrCreate` on part_number; manufacturer logo glyphs for top 20 brands. Foundation for all other phases. ✅ COMPLETE 2026-05-10 (3/3 plans, ~43 min total exec time).
- [x] **Phase 22: Cable Schedule with Port-Level FKs** ✅ COMPLETE 2026-05-12 (3/3 plans) — `source_port_id` + `dest_port_id` columns on `cable_schedule_items`; cascading dropdown UI (room → device → port); connector-compatibility validation; auto-derive from quote `cable_list` "X to Y" naming where unambiguous; one-shot backfill command. Depends on Phase 21. Estimate: 2–3 weeks.
- [x] **Phase 22.1: RAMS Scope/Room-Data Consolidation** ✅ COMPLETE 2026-05-13 (7/7 plans) — inserted phase; eliminates field-duplication across the 3-stage RAMS pipeline (`form_data` → `reviewed_data` → `generated_data`). Backward-compatible `generated_data` shape; backfill migration; dead-path removal. Survey↔RAMS sync + `Project.works_description` propagation deferred to Phase 22.2. See detail section below.
- [x] **Phase 23: XTEN-AV-Style Renderer** ✅ COMPLETE 2026-05-15 (7/7 plans, 4 waves) — custom device-card stencils with port rails; port-to-port cable routing; signal-type colour coding (audio/video/control/network/USB); cable ID labels; sub-room zones (RACK / CEILING / etc) auto-derived + engineer-overridable; multi-page paginator (system + audio + video + control sub-sheets); standardised title block; sheet border. Depends on Phase 21+22. Estimate: 2–4 weeks (faster via draw.io vs ~4–5 weeks native). **7 plans, 4 waves** (planned 2026-05-13).
- [ ] **Phase 24: Stencil Curation UI + Quote-Import Auto-Stub** — quote-import auto-stub flow seeds `device_stencils` + category-default `device_ports` for every new part_number seen in quote line items; admin route at `/admin/device-stencils` + edit screen (port table, not drag — D-01) for upgrading auto-generic stencils to engineer-curated ones; manufacturer-logo upload; "promote" action flips `device_stencils.source` from auto-generated → engineer-curated; cross-project propagation automatic via cache lookup. Closes the Phase 21 Tier 1 gap (audit 2026-05-15: 5/96 = 5% coverage). Depends on Phase 21 + Phase 22. Planned 2026-08-13 (9 plans, 7 waves). Plans 01-08, 10, 11, 12 complete; 24-09 (bounded human-checkpoint curation) remains open.
- [ ] **Phase 25: AI Assist + Replacement Wiring** — Claude vision over manufacturer datasheet PDFs → port JSON → engineer review/approve flow (covers long-tail devices); chat-edit operations on rendered drawings (`move_device_to_zone`, `add_cable_between_ports`, etc.) bounded by canonical-data validity; bound PDF (v1.3 Phase 20) + O&M Manual auto-embed (v1.3 Phase 17) swap from D2 output to engineering-grade output for projects with sufficient catalog coverage. Depends on Phase 21+22+23. Estimate: 2–3 weeks.

### Out of scope for v2.0 (deferred to v2.1+)

- DWG export — LibreDWG GPLv3 license blocker; Teigha is paid
- Real-time multi-user collaborative drawing
- Apple Pencil pressure / tilt
- Mobile-first drawing creation (drawings stay desktop/tablet)
- Custom symbol library editor in-app (symbols stay in `device_stencils` table)
- **Floor plans** (DRAW-14..20 from v1.3 backlog) — held for v2.1 with the same renderer + room-shape stencils

### Canonical refs

- Visual contract: XTEN-AV PAGING SYSTEM reference image (conversation 2026-05-09)
- Platform validation: `.planning/quick/260509-ibx-draw-io-embed-spike-sandbox-one-stencil-/260509-ibx-SUMMARY.md`
- Native-build alternative (rejected — kept for diff): memory note `v2_engineering_grade_drawings_plan.md`
- Spike seed data: `resources/data/draw-io-stencils/21cav-mtr-spike.json` (5 hand-coded MTR stencils — promoted to seed for Phase 21)

### Phase 21: Device Port Catalog + Stencil Cache

<sub>✅ COMPLETE 2026-05-10 (3/3 plans) · planned 2026-05-10</sub>

**Goal:** Lay the device_ports + device_stencils tables, the firstOrCreate cross-project cache, the auto-generic Tier 1 placeholder generator, the hand-curated top-50 seed pack, the top-20 manufacturer logos, and the generalised draw.io builder reading from the new tables. Foundation for Phases 22-25.

**Plans:** 3 plans, 2 waves

- [x] 21-01-schema-models-cache-service-PLAN.md — Migration creating device_stencils + device_ports; DeviceStencil + DevicePort models; DeviceStencilCacheService (firstOrCreate-on-part_number); AutoGenericStencilGenerator (Tier 1 placeholder); Project::devicesWithStencils() accessor. Wave 1. Requirements: DRAW-31, DRAW-32, DRAW-34, DRAW-36.
- [x] 21-02-seed-pack-promote-and-curate-PLAN.md — Promote 5 spike stencils + selected v1.3 catalog entries into per-file curation manifests; hand-curate gap to top-50 from quote volume; idempotent DeviceStencilSeeder using whereRaw LOWER TRIM matching pattern. Wave 2 (parallel with 21-03). Requirements: DRAW-33.
- [x] 21-03-manufacturer-logos-builder-integration-PLAN.md — Top-15 new manufacturer logo SVGs (Crestron, Cisco, QSC, Bogen, Polycom, Logitech, Shure, Sony, Extron, Biamp, Yamaha, Atlona, Lightware, Q-SYS, Barco) bringing top-20 with the 5 spike logos; ManufacturerLogoResolver; rename DrawIoSpikeBuilderService → DrawIoBuilderService reading from device_stencils table; spike admin route preserved with shim. Wave 2 (parallel with 21-02). Requirements: DRAW-35.

### Phase 22: Cable Schedule with Port-Level FKs

**Goal**: Cable schedule items become typed via four FK columns (`source_device_id`, `source_port_id`, `dest_device_id`, `dest_port_id`) referencing Phase 21's `devices` + `device_ports` tables. Cascading dropdown UI on the cable schedule edit screen lets engineers pick exact source-port → dest-port pairs filtered by signal_type compatibility. Connector-compatibility validation warns at save (engineer override allowed with note, not a hard block). A one-shot backfill command populates port FKs from quote `cable_list` "X to Y" naming where the device-side ports are unambiguous (single matching connector on each side); leaves nullable for ambiguous rows so engineers can resolve manually. Legacy cable_schedule_items without port FKs continue to render via existing v1.3 surfaces — strictly additive. This is the data layer Phase 23's port-to-port renderer reads from.
**Depends on**: Phase 21 (device_ports table, DevicePort model with SIDE_*/DIRECTION_* constants, DeviceStencilCacheService cross-project caching)
**Requirements**: DRAW-37, DRAW-38, DRAW-39, DRAW-40, DRAW-41
**Success Criteria** (what must be TRUE):

  1. Engineer can edit a cable_schedule_items row and pick source device → source port (filtered to ports on that device, ordered by side then position) → dest device → dest port (filtered by signal_type compatibility with the chosen source port) via cascading dropdowns
  2. Form save warns the engineer (with override-with-note option) when chosen source and dest ports have incompatible connector types (e.g. HDMI → RJ45) — never a hard block
  3. Running `php artisan cables:backfill-port-fks` on existing cable_schedule_items populates port FKs deterministically where the quote `cable_list` "X to Y" naming has exactly one matching connector on each side; leaves nullable where ambiguous; reports per-row decisions to stdout
  4. Phase 23's renderer can consume `cable_schedule_items.source_port_id` + `dest_port_id` to draw port-to-port cable routing without further data layer work
  5. v1.3 cable schedule XLSX export, schematic SVG generator, and bound-PDF cable-list section continue to render without regression for legacy rows where the new FK columns are NULL

**Plans**: 3 plans, 2 waves

  - [x] 22-01-PLAN.md — Schema migration (4 FK columns + override-note + port-pair index) + CableScheduleItem fillable + belongsTo relations + config/cables.php + CableConnectorCompatibilityService. Wave 1 — foundation. Requirements: DRAW-37, DRAW-39.
  - [x] 22-02-PLAN.md — Alpine.js port-picker modal (D-02 side-by-side) + chain-link icon column + extended CableScheduleController@update with cross-project FK injection guard (T-22-A4) + D-10 regression tests (XLSX byte-identity + SchematicGenerator NULL-FK case). Wave 2 — depends on 22-01. Requirements: DRAW-38, DRAW-39.
  - [x] 22-03-PLAN.md — CablePortFkResolverService (pure deterministic matcher) + cables:backfill-port-fks artisan command (dry-run-default with --apply flag, per-row 4-category report, idempotent, T-22-A5/A6 mitigated). Wave 2 — depends on 22-01. Requirements: DRAW-40, DRAW-41.

**UI hint**: yes (cascading dropdown UI on cable schedule edit; backend command + form changes)
**Canonical refs**:

  - `.planning/phases/21-device-port-catalog-stencil-cache/21-CONTEXT.md` (Phase 21 decisions D-01..D-15 — port catalog contract)
  - `.planning/phases/21-device-port-catalog-stencil-cache/21-01-schema-models-cache-service-SUMMARY.md` (DevicePort model API surface, side/direction enum constants, FK semantics)
  - `.planning/REQUIREMENTS.md` §"Phase 22 — Cable Schedule with Port-Level FKs" (DRAW-37..41 acceptance criteria)
  - Visual contract: XTEN-AV PAGING SYSTEM reference image (conversation 2026-05-09) — port-to-port routing pattern Phase 23 will render from this data

### Phase 22.1: RAMS Scope/Room-Data Consolidation

**Goal**: Eliminate field-duplication across the 3-stage RAMS pipeline (`form_data` -> `reviewed_data` -> `generated_data`). Audit (2026-05-13) found 5 overlapping "scope/works/space narrative" fields at 3 granularities stored in 5 different JSON locations with inconsistent fallback chains, so a single scope edit can duplicate across all 5 - risking divergence between what engineers see in the review UI and what renders in the final PDF. This phase keeps `generated_data` shape backward-compatible (already-rendered RAMS docs unaffected) but consolidates the canonical source of truth, deprecates redundant fields with a backfill migration, removes dead-path code, and surfaces previously-invisible AI prose for engineer review. Survey<->RAMS sync rules + cross-document `Project.works_description` propagation rules DEFER to Phase 22.2 (touches in-flight workflows; needs a feature flag).
**Depends on**: None (audit complete; safe to ship in parallel with v2.0 schematic work)
**Requirements**: DATA-01, DATA-02, DATA-03, DATA-04, DATA-05
**Success Criteria** (what must be TRUE):

  1. A single project-wide scope edit propagates to ONE canonical JSON location only - the other 4 storage paths are deprecated, with a backfill migration mapping legacy values
  2. Per-room narrative carries exactly TWO fields (`overview` + `works_summary`); `summary` and `description` are either deprecated or surfaced in the review UI (decision locked during discuss-phase)
  3. Five dead-path files/paths removed per the audit: `RamsGeneratorService`, `RamsPrompt`, dead AI bullet-list capture path, `reviewed_data.project.overview` round-trip, related test scaffolding
  4. Backfill migration `summary` -> `works_summary` succeeds on all existing `reviewed_data` records (idempotent, dry-run-default with `--apply` flag)
  5. Regression test asserts byte-equivalence: existing `reviewed_data` records render byte-identical PDFs before and after the cleanup (golden-file in `tests/Feature/RamsRenderRegressionTest.php`)
  6. AI prompt audit confirms no prompt invents scope/equipment/design content (per CLAUDE.md constraint - AI is ONLY for formatting and method statement structuring)

**Plans**: 7 plans, 6 waves (Plan 07 = gap closure for write-side leaks identified in 22.1-VERIFICATION.md)

  - [x] 22.1-01-PLAN.md — Write DATA-01..05 acceptance criteria into REQUIREMENTS.md (closes the roadmap/requirements gap). Wave 1. Requirements: DATA-01, DATA-02, DATA-03, DATA-04, DATA-05.
  - [x] 22.1-02-PLAN.md — Byte-equivalence golden-file regression test scaffolding (D-12 canary; runs BEFORE production code changes so subsequent waves can detect render drift). Wave 1 — parallel with 22.1-01. Requirements: DATA-05.
  - [x] 22.1-03-PLAN.md — Per-room narrative consolidation: rams:backfill-room-overview-summary artisan + RamsReviewDataService schema trim to 4 keys + MethodStatementService overview-input swap + RoomOverviewSummaryPrompt description-output drop (D-01, D-07, D-08, D-09 per-room). Wave 2 — depends on 22.1-01 + 22.1-02. Requirements: DATA-02, DATA-04.
  - [x] 22.1-04-PLAN.md — Dead-path removal: delete RamsGeneratorService + RamsPrompt + WorksBulletsPrompt + works_bullets textarea + survey controller rewire + DeadPathRemovalGuardTest static guard (D-10, D-11, D-04). Wave 3 — depends on 22.1-03. Requirements: DATA-03.
  - [x] 22.1-05-PLAN.md — Project-wide narrative consolidation: stop auto-seeding Project.works_description + drop method_statement_notes mapping + remove PM-INSTRUCTIONS separator + approve-time scope_of_works_bullets persistence + pdf/rams.blade.php fallback chain simplified + reviewed_data.project.overview dropped (D-02, D-03, D-06, D-08, D-09 project-level). Wave 4 — depends on 22.1-03 + 22.1-04. Requirements: DATA-01.
  - [x] 22.1-06-PLAN.md — Final verification: ReviewedDataStructuralDiffTest (D-13) + Phase22_1InvariantGuardTest mapping each ROADMAP SC #1-6 to a CI-verifiable assertion + full grep-ratchet sweep. Wave 5 — depends on all prior. Requirements: DATA-01, DATA-02, DATA-03, DATA-04, DATA-05.
  - [x] 22.1-07-PLAN.md — Gap closure for DATA-01 + DATA-02 (PARTIAL → SATISFIED): strip dead summary/description form fields + 4-key shape at all 4 canonical writers (editPayload, parseReviewPayload, generateSurveyRooms, ExtractQuoteJob scaffold) + RoomOverviewSummaryService canonical works_summary key rename + write-side guard test method added to ReviewedDataStructuralDiffTest. Wave 6 — depends on 22.1-06. Requirements: DATA-01, DATA-02.

**UI hint**: yes (review-form field consolidation; possibly new edit UI for AI prose if D-03 lands on "surface it")
**Canonical refs**:

  - `.planning/audits/rams-room-fields-audit-2026-05-13.md` - full audit with file:line citations
  - `.planning/REQUIREMENTS.md` §"Phase 22.1 - RAMS Scope/Room-Data Consolidation" (DATA-01..05)
  - `app/Services/RamsBuilderService.php`, `app/Services/RamsReviewDataService.php`, `app/Services/RamsDataBuilderService.php` (3-stage pipeline core)
  - `app/Models/RamsDocument.php` (form_data / extracted_data / reviewed_data / generated_data shape contracts)
  - `app/Core/AI/Prompts/MethodStatementPrompt.php` (the only AI prompt in scope)
  - CLAUDE.md (project constraints: AI is ONLY allowed for formatting and method statement structuring - never for inventing scope)

### Phase 23: XTEN-AV-Style Renderer

<sub>✅ COMPLETE 2026-05-15 (7/7 plans, 4 waves)</sub>

**Goal**: Custom device-card stencils with port rails; port-to-port cable routing reading Phase 22's `source_port_id`/`dest_port_id` FKs; signal-type colour coding (audio/video/control/network/USB); cable ID labels; sub-room zones (RACK / CEILING / etc) auto-derived + engineer-overridable; multi-page paginator (system + audio + video + control sub-sheets); standardised title block; sheet border. Built via draw.io/mxGraph rather than native SVG.

**Depends on**: Phase 21 (device_ports + DeviceStencilCacheService) + Phase 22 (cable_schedule_items port FKs)

**Plans:** 7 plans, 4 waves (planned 2026-05-13, shipped 2026-05-15)

- [x] 23-01 through 23-07 — device-card stencils + port rails, zone derivation + engineer override, signal-type colour system, port-to-port router, multi-page paginator, review zone dropdown UX, final verification + D-01..D-10 / DRAW-42..49 closure. See `.planning/phases/23-xten-av-style-renderer/` SUMMARY files.

**Requirements**: DRAW-42..49

**Verification**: `23-VERIFICATION.md` — D-01..D-10 + DRAW-42..49 disposition/closure log (2026-05-15). D-10 colour UAT scaffolded.

### Phase 24: Stencil Curation UI + Quote-Import Auto-Stub

**Goal**: Close the Phase 21 Tier 1 coverage gap (audit 2026-05-15 found only 5 of 96 seeded stencils carry full port data — the 91 stubs in `_v1.3-promoted.json` + `_top-50-gap.json` have manufacturer/model/mxgraph but zero `device_ports` rows, so AI port-pair proposals can't run on real projects). This phase ships two complementary mechanisms: (1) a quote-import auto-stub flow (`QuoteImportStencilStubber`, hooked into all 3 import paths per D-09) that calls `DeviceStencilCacheService::firstOrCreate` for every new hardware part_number seen during import, seeding category-derived port templates via a deterministic `CategoryPortTemplateResolver` (D-06/D-07) and flagging `source = auto-generated` + `needs_review = true`; and (2) an admin stencil curation UI at `/admin/device-stencils` (D-14) with a list view filterable by source + needs_review + manufacturer, a per-stencil edit screen with an editable port TABLE (D-01 — explicitly not drag-on-canvas) beside a server-rendered live preview (D-02/D-16), manufacturer-logo upload (D-12/D-15), and a "Promote to Engineer-Curated" action that hard-gates on port completeness (D-04) and writes an audit row (D-03). Cross-project propagation happens automatically via Phase 21's `firstOrCreate` cache lookup — once a stencil is promoted, every project using that part_number sees the new ports on next render. Tier 1 fill itself is engineer labour (per-stencil datasheet review) — this phase ships the tools that make the labour tractable; the AI-assisted port extraction layer remains Phase 25 scope (DRAW-54). Strictly additive: legacy projects using uncatalogued part_numbers continue to render via the auto-generic Tier 1 placeholder from Phase 21.
**Depends on**: Phase 21 (DeviceStencilCacheService::firstOrCreate, DevicePort model, AutoGenericStencilGenerator); Phase 22 (cable_schedule_items port FK columns — auto-stub ports must be valid FK targets for the cascading-dropdown picker)
**Requirements**: DRAW-50, DRAW-51, DRAW-52, DRAW-53 (DRAW-54 is Phase 25 scope, corrected per 24-CONTEXT.md D-13 — not planned here)
**Success Criteria** (what must be TRUE):

  1. Importing a quote whose equipment lines contain a part_number NOT in `device_stencils` creates a new stub row in `device_stencils` + N rows in `device_ports` derived from a category template, idempotent across re-imports (verified by feature test against a fresh DB + the Light Forms 21CQ30451-01-OPS synthetic fixture)
  2. The category template chooser uses ONLY deterministic signals (part_number prefix, description keywords from a fixed allowlist) — never AI invention — so the same import always produces the same stub shape; ambiguous categories (e.g. "Display Bracket") produce a zero-port stub rather than a wrong guess
  3. Admin can browse `/admin/device-stencils?source=auto-generated&needs_review=1`, see a list of every stub awaiting promotion, click into one, edit its ports in a table (add/delete rows, edit label/signal_type/connector_type/direction/port_id inline — D-01, not drag), see a live server-rendered preview, upload a manufacturer logo (PNG/SVG, sanitised), and click "Promote to Engineer-Curated" — `device_stencils.source` flips, `needs_review` clears, audit row written, server-side hard-gated on port completeness regardless of client state (D-04)
  4. Promoting a stencil propagates to all existing projects using that part_number on next drawing render (no per-project migration needed — Phase 21's cache lookup handles it); verified by an integration test rendering project A with a stub, promoting the stencil, re-rendering project A and asserting the new ports surface
  5. The 10 highest quote-volume part_numbers from existing imports (computed via `php artisan stencils:coverage-report`) have Tier 1 (full port) coverage at phase close — engineer-driven fill via the curation UI, bounded delivery target rather than a 91-device sprint (manual-only verification, Plan 24-09)
  6. No regression on Phase 23's renderer: projects whose devices remain Tier 2 (auto-generic, zero-port) continue to render with the bare placeholder; D-07 NULL-FK cable fallback unchanged; templated stubs additionally render provisional (dashed/muted) port rails with named mxGraph constraints (D-05)

**Plans**: 9 plans, 7 waves (planned 2026-08-13)

  - [x] 24-01-PLAN.md — Foundation: migration (needs_review indexed column, logo_path, device_stencil_audits table, D-10/D-15/D-03) + DeviceStencilAudit model + config `port_templates` vocabulary + `CategoryPortTemplateResolver` (D-06/D-07) + `AutoGenericStencilGenerator` extension emitting provisional rails + named mxGraph constraints (D-05). Wave 1 — foundation, unblocks everything else. Requirements: DRAW-51 (partial — mxgraph_xml/constraint regeneration contract; UI ships in 24-05).
  - [x] 24-02-PLAN.md — `QuoteImportStencilStubber` service + all 3 import-path hooks (`ExtractQuoteJob`, `QuoteWerksImportService::buildExtractedData`, `ReimportQuoteJob` — D-09). Wave 2 — depends on 24-01. Requirements: none (fulfils unnumbered Success Criteria 1 + 2, not a DRAW-5x UI requirement).
  - [x] 24-03-PLAN.md — Admin list view: `/admin/device-stencils` route + nav entry + filterable/searchable index. Wave 2 — depends on 24-01. Requirements: DRAW-50.
  - [x] 24-04-PLAN.md — Server-rendered preview pipeline: `StencilXmlToSvgRenderer` (bounded-grammar mxGraph-stencil-XML to SVG translator, settling RESEARCH.md Open Questions 1 + 3) + preview endpoint. Wave 3 — depends on 24-01 + 24-03. Requirements: DRAW-51 (preview half).
  - [x] 24-05-PLAN.md — Edit screen UI: port table (Alpine reactive repeater, D-01) + 600ms debounced live preview (D-02/D-16) + batched save regenerating mxgraph_xml with proven port_id/constraint parity. Wave 4 — depends on 24-04. Requirements: DRAW-51.
  - [x] 24-06-PLAN.md — Manufacturer logo upload (PNG/SVG, mandatory `SvgSanitizerService` sanitisation — D-12/D-15). Wave 5 — depends on 24-05. Requirements: DRAW-52.
  - [x] 24-07-PLAN.md — `StencilPromotionValidator` (D-04 hard-block/soft-warn gate) + Promote/Discard actions + `device_stencil_audits` write (D-03) + end-to-end curation-flow test (criterion 3). Wave 6 — depends on 24-06. Requirements: DRAW-53.
  - [x] 24-08-PLAN.md — `stencils:reapply-templates` (D-08, dry-run/--commit, never touches curated/audited stencils) + `stencils:coverage-report` (independent live-DB top-N ranking feeding Plan 24-09). Wave 2 — depends on 24-01 only (parallel with 24-02/24-03). Requirements: none (tooling; fulfils D-08/D-11 and feeds Criterion 5).
  - [ ] 24-09-PLAN.md — Tier 1 fill bounded delivery (checkpoint:human-action): engineer-driven curation of the top-10 highest-volume part_numbers identified by `stencils:coverage-report`, using the full curation UI; per-stencil verification that promoted port shape renders correctly in a real Phase 23 drawing. Wave 7 — depends on 24-07 + 24-08. Requirements: none (bounded delivery task — see 24-CONTEXT.md D-13; DRAW-54 is Phase 25 scope).
  - [x] 24-10-PLAN.md — Gap-closure (UAT Gap 1): `stencils:reapply-templates` eligibility corrected from `source=auto-generated` (unreachable — the real 91 zero-port stubs are `engineer-curated`) to `needs_review=true`, keeping `whereDoesntHave('audits')` unmodified as the sole safety boundary. Not part of the original 7-wave plan — spawned by UAT on Plan 24-08's output. Requirements: none.
  - [x] 24-11-PLAN.md — Gap-closure (UAT Gap 2): `DeviceStencilController::update()`'s D-17 confirm_regenerate guard corrected to fire only when `source===engineer-curated AND ports()->exists()` (not on `source` alone) — as shipped it flashed the warning on 91 of 96 real saves. Spawned by the same UAT pass as 24-10. Requirements: DRAW-51.
  - [x] 24-12-PLAN.md — Gap-closure (documentation): appends correction blocks to `24-CONTEXT.md` D-11 and D-17 recording the real eligibility/guard predicates fixed by 24-10/24-11, in the same in-place amendment style D-17 already used. Depends on 24-10 + 24-11. Requirements: none.

**UI hint**: yes (admin route + edit screen with reactive port table + live preview; quote-import flow is backend but surfaces a "stubs created" toast to the importer)
**Canonical refs**:

  - `.planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-CONTEXT.md` (D-01..D-16 locked decisions)
  - `.planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-RESEARCH.md` (mxGraph constraint syntax, debounce pattern, 5 pitfalls, 3 open questions settled during planning)
  - `.planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-UI-SPEC.md` (design contract, 6/6 dimensions passed)
  - `.planning/phases/21-device-port-catalog-stencil-cache/21-02-seed-pack-promote-and-curate-SUMMARY.md` (seed pack structure, source enum, idempotency contract)
  - `resources/data/device-stencils-seed/_INDEX.md` (manifest schema + curation manifests as source of truth)
  - `resources/data/device-stencils-seed/_v1.3-promoted.json` + `_top-50-gap.json` (the 91 stubs awaiting Tier 1 fill)
  - `app/Services/Drawings/DeviceStencilCacheService.php` (firstOrCreate cache lookup — auto-stub flow extends this)
  - `app/Jobs/ExtractQuoteJob.php` (one of 3 auto-stub hook insertion points)
  - 2026-05-15 audit: 5/96 (5%) Tier 1 coverage; Light Forms 21CQ30451 hardware (FW-85BZ40L, BT9910/B, PA20) — zero matches in current catalogue

---

## ✅ v1.2 Installation Programme & Field Management — SHIPPED 2026-04-25

5 phases, 21 plans — full installation delivery loop from auto-generated task list → mobile field view → time tracking → commissioning sign-off with snagging PDF. See [milestones/v1.2-ROADMAP.md](milestones/v1.2-ROADMAP.md) for full details.

---

## ✅ v1.3 Technical Drawings & Schematics — SHIPPED 2026-05-09

3 phases, 7 plans — schematics (D2 CLI) + rack elevations (custom Blade SVG) + bound PDF / ZIP / O&M auto-embed. Phase 19 (Floor Plans / Konva) deferred mid-milestone to v2.0 backlog 999.1. Companion draw.io spike (260509-ibx) validated the v2.0 engineering-grade rendering platform. See [milestones/v1.3-ROADMAP.md](milestones/v1.3-ROADMAP.md) for full details.

---

<details>
<summary>v1.3 collapsed details (click to expand)</summary>

**Milestone Goal:** Generate AV technical drawings — schematics + rack elevations — from the same canonical project data that powers RAMS, O&M, and worksheets. Internal engineers view drawings on tablets and print during install; clients receive them as part of the O&M Manual handover. Drawings derive from canonical project data only — AI may assist with layout but never invents equipment, cables, or rooms.

**Phases:** 17, 18, 20 (3 phases, ~7 plans estimated)

> **Scope reduction (2026-05-02):** Phase 19 (Floor Plans / Konva) deferred to v2.0 backlog 999.1. Reason: the Konva canvas editor is the most likely throwaway when v2.0's build-vs-buy decision lands on the engineering-grade renderer (Lucidchart/draw.io integration OR native port-aware SVG). v2.0 needs to build floor plans properly with port catalog + zones anyway. DXF export (DRAW-29) moves with floor plans. v1.3 ships ~3-4 weeks sooner. See `.planning/phases/999.1-v2-engineering-grade-av-drawings/` and memory note `v2_engineering_grade_drawings_plan.md`.

### Phases

- [x] **Phase 17: System Schematics + Shared Foundations** — Auto-generate per-room signal-flow SVG schematics via D2 CLI; lays the `project_drawings` table, model, policy, storage type, job pattern, and `waitForJs` PDF extension that Phases 18 + 20 depend on (completed 2026-05-01)
- [x] **Phase 18: Rack Elevations** — 1U-precise rack drawings from equipment list with U-height + ventilation data; drag-reorder editor + per-rack totals footer; engineer always builds manually (no auto-place) (completed 2026-05-02)
- [x] **Phase 20: Drawing Export Pipeline + O&M Integration** — Bound multi-page project PDF, drawing register, sheet numbering, revision tracking, status state machine; embeds drawings (schematic + rack only — floor plans deferred) in O&M handover via PNG flatten
 (completed 2026-05-03)

- ⤳ ~~Phase 19: Floor Plans (Konva)~~ — **deferred to v2.0 backlog 999.1**

## Phase Details

### Phase 17: System Schematics + Shared Foundations

**Goal**: Engineers can auto-generate per-room signal-flow schematics from canonical project data and download them as PDF or SVG. This phase also lays the shared drawings foundation (table, model, policy, storage type, job pattern, edit-adapter, mailable, `waitForJs` PDF flag) that Phases 18–20 build on as pure additions.
**Depends on**: Nothing (first phase of v1.3; foundations land here)
**Requirements**: DRAW-01, DRAW-02, DRAW-03, DRAW-04, DRAW-05, DRAW-06, DRAW-22, DRAW-24, DRAW-25, DRAW-26, DRAW-27, DRAW-30
**Success Criteria** (what must be TRUE):

  1. User can click "Generate Schematic" on a project and see a per-room SVG signal-flow diagram with cable IDs and port labels matching the cable schedule character-for-character
  2. User can read each schematic at a glance because lines use signal-type colour coding (audio / video / control / network / USB) and AVIXA-style symbols (display, speaker, mic, camera, switcher, DSP, amp, control processor)
  3. User can download an individual schematic as PDF or SVG with a standard title block (project ref, client, drawn-by, revision R0, date)
  4. User can edit an auto-generated schematic and on regenerate the prior version is archived (never silently overwritten); regenerate prompts the user when canvas edits exist
  5. User can change a schematic's status (draft / for review / approved / superseded) and see drawings filed in the O&M Manual handover via PNG embed

**Plans**: 3 plans

- [x] 17-01-foundations-PLAN.md — project_drawings table + ProjectDrawing model + policy + TYPE_DRAWING storage + PdfRenderService::waitForJs extension + DrawingService/DrawingDataResolverService + DrawingEditAdapter scaffolding (DRAW-30) + BuildSchematicJob skeleton + DrawingReadyMail + Device::isSource/isDestination/isProcessor (CRIT-05) + routes + Project::drawings relation. Wave 1 — foundation. Requirements: DRAW-24, DRAW-25, DRAW-30 (scaffolding only).
- [x] 17-02-schematic-generator-PLAN.md — SchematicGeneratorService (D2 CLI invocation) + SchematicD2SourceBuilder + ~25 AV symbol pack (resources/svg/av-symbols/) + DrawingDataResolverService::adjacencyForProject body + schematic Blade view + reusable title-block partial + config/drawings.php (D2 binary path, layout engine, signal-type colour map) + feature test. Wave 2 — depends on 17-01. Requirements: DRAW-01, DRAW-02, DRAW-03, DRAW-04, DRAW-22.
- [x] 17-03-render-ui-handover-PLAN.md — DrawingExportRendererService (PDF/SVG/PNG via PdfRenderService + Browsershot) + drawings index + show + status pill + regenerate-confirm modal (lock-on-edit UX scaffolding for DRAW-05) + per-format download routes + status update via DrawingEditAdapter + OmManualDocxService Drawings section (PNG embed for DRAW-26) + pdf:smoke-test --drawings flag + Project::show page link. Wave 2 — depends on 17-01. Requirements: DRAW-05 (scaffolding only — full editor in Phase 19), DRAW-06, DRAW-26, DRAW-27.

**UI hint**: yes
**Canonical refs**:

  - `.planning/research/SUMMARY.md`
  - `.planning/research/STACK.md` §1 Schematic Engine + §5 AV Symbol Pack
  - `.planning/research/ARCHITECTURE.md` §2 Data Model + §3 Service Layer + §4.3 PdfRenderService waitForJs extension + §8 Build Order
  - `.planning/research/PITFALLS.md` CRIT-01 (Browsershot/React canvas), CRIT-02 (drift vs canonical), CRIT-05 (reversed signal flow)

### Phase 18: Rack Elevations

**Goal**: Engineers can manually build 1U-precise rack elevations from rack-mounted equipment (with U-height + ventilation metadata) via a drag-into-U-slots editor, lock per-item U-positions, and download per-rack PDF/SVG with totals footer (weight, current, BTU, U-utilisation). A unified "+ Create Drawing" picker replaces the per-kind buttons on the drawings index. CRIT-06 enforced — devices outside the manufacturer JSON pack surface as "U-height unknown" warnings, never silent 1U guesses.
**Depends on**: Phase 17 (foundations: `project_drawings` table, model, policy, `TYPE_DRAWING` storage, `BuildSchematicJob` pattern, `DrawingReadyMail`, edit-adapter pattern)
**Requirements**: DRAW-07, DRAW-08, DRAW-09, DRAW-10, DRAW-11, DRAW-12, DRAW-13
**Success Criteria** (what must be TRUE):

  1. User clicks "+ Create Drawing" on a project's drawings page, picks Rack Elevation, and lands in an editor with a 42U rack scaffold + U-numbered side rail (1 at bottom, 42 at top — AVIXA convention)
  2. User can drag equipment from a palette (rack-mounted equipment grouped first, all other equipment greyed but draggable second) into U-slots; each item respects its U-height; user can lock per-item U-position so subsequent reorders skip locked items (DRAW-10)
  3. User can manage multiple racks per project (no single-rack limit) — each rack is its own ProjectDrawing row with its own status, revision, and download endpoints (DRAW-11)
  4. User reads per-rack totals (weight, current draw, BTU, U-utilisation) in the footer of every rack drawing — partial data shows asterisks + ratio (e.g. "Weight: 28 kg* (4/7 known)") with tooltip listing unclassified devices (DRAW-12)
  5. User can download each rack as PDF (landscape A4 with title block) or SVG (direct write of generated_svg); items with no U-height in the manufacturer JSON pack render with a 1U placeholder AND a "U-height unknown" warning region (CRIT-06 — never a silent 1U guess) (DRAW-13)

**Plans**: 2 plans

- [x] 18-01-picker-and-schema-PLAN.md — Device schema migration (u_height decimal, is_rack_mounted, ventilation gaps; all nullable) + hand-curated 53-entry manufacturer JSON pack at resources/data/device-port-catalog.json + DeviceCatalogService reader + idempotent DeviceCatalogSeeder + unified "+ Create Drawing" Alpine picker modal (Schematic with Yes/No auto-gen toggle, Rack with single Create button, Floor Plan disabled with "Coming in v2.0" tooltip) + ProjectDrawingController picker/createRack actions + DrawingService::generateInitial extended for kind=rack (synchronous, no job dispatched) + DrawingDataResolverService::rackStackForProject body. Wave 1 — foundation. Requirements: DRAW-08, DRAW-09 (palette ordering — partial), DRAW-11, DRAW-12. (LANDED 2026-05-02; commits 5ce6799 / 782e902 / 74b8fb4; 24 new test cases / 72 assertions)
- [x] 18-03-rack-editor-PLAN.md — RackElevationRenderService (synchronous custom Blade SVG, ~340 LOC measured 0.06s for 42U/30-items, U-numbered rail + equipment rectangles + totals footer with asterisks/ratios + CRIT-06 unknown-U-height warnings + htmlspecialchars XSS protection) + pdf/drawings/rack.blade.php (landscape A4 with title block) + DrawingExportRendererService::bladeViewFor extended for kind=rack + ProjectDrawingController::editRack + saveRackCanvas (AJAX, throttled, validated) + flipRackMountedFlag endpoints (project-scoped against new App\Policies\ProjectPolicy — Blocker 2 fix) + Sortable.js drag-into-U-slots editor with cursor-walk lock-aware reorder algorithm + per-item U-position lock + new resources/js/rack-editor.js Vite entry + sortablejs ^1.15.6 added to package.json + show.blade.php Edit Rack button (existing line-66 kind-agnostic SVG render branch UNCHANGED — Warning 9 fix). Wave 2 — depends on 18-01. Requirements: DRAW-07, DRAW-08, DRAW-09 (partial), DRAW-10, DRAW-11, DRAW-12, DRAW-13. (LANDED 2026-05-02; commits dade6d8 / f3ad476 / ce981d9; 20 new test cases / 99 assertions)

**UI hint**: yes
**Canonical refs**:

  - `.planning/research/SUMMARY.md`
  - `.planning/research/STACK.md` §1.2 Rack Elevations (custom Blade SVG)
  - `.planning/research/FEATURES.md` Phase 18 — Rack Elevations
  - `.planning/research/ARCHITECTURE.md` §4.2 Phase 18 render pipeline
  - `.planning/research/PITFALLS.md` CRIT-06 (U-height accuracy)

### ⤳ Phase 19: Floor Plans — DEFERRED to v2.0

Floor plan drawing tool moved out of v1.3 scope on 2026-05-02 to avoid building Konva canvas + Browsershot+Konva PDF round-trip work that v2.0's engineering-grade renderer will replace. v2.0 needs to build floor plans properly with port catalog + sub-room zones anyway. DXF export (DRAW-29) moves with floor plans. See backlog 999.1 for the full v2.0 plan.

**Requirements moved to v2.0:** DRAW-14, DRAW-15, DRAW-16, DRAW-17, DRAW-18, DRAW-19, DRAW-20, DRAW-29.

### Phase 20: Drawing Export Pipeline + O&M Integration

**Goal**: Engineers can produce a single bound multi-page PDF per project (cover sheet + drawing register + paginated drawings) with configurable sheet numbering and standard title blocks; download all drawings as a ZIP bundle; and ship drawings inside the O&M Manual handover via PNG embed. Production hardening (dedicated drawings queue, smoke test, font loading, license audit) lands here. *(DXF export deferred to v2.0 with floor plans.)*
**Depends on**: Phase 17 (foundations) + Phase 18 (rack elevations as a second drawing kind to render)
**Requirements**: DRAW-21, DRAW-23, DRAW-28
**Success Criteria** (what must be TRUE):

  1. User can download a single bound multi-page PDF per project that opens with a cover sheet, a drawing register table (sheet number / title / revision / date), and the paginated per-section drawings (schematics → rack elevations)
  2. User can configure sheet numbering per project (default `AV-201` schematics, `AV-301` racks) and see the chosen numbers on every drawing's title block
  3. User can download a ZIP bundle of all of a project's drawings (PDF + SVG + PNG) in one action
  4. User who opens an O&M Manual sees a "Drawings" section with each ready drawing embedded as a high-resolution PNG, one drawing per page, matching the bound PDF
  5. Production hardening: dedicated drawings queue (concurrency=1) + `pdf:smoke-test --drawings` + chrome-headless-shell version pin + `@font-face` + license audit

**Plans**: 2 plans

- [x] 20-01-bound-pdf-sheet-numbering-zip-PLAN.md — sheet_number column + SheetNumberAllocator + setasign/fpdi MIT install + BoundPdfBuilderService (cover+register Blade + per-drawing concat with isolated failures) + BuildBoundPdfJob + BoundPdfReadyMail + 3 routes (bound-pdf download, bound-pdf build, ZIP bundle) + drawings index UI (bound-PDF button + ZIP button + sheet column + 'regen needed' badge). Wave 1. Requirements: DRAW-21, DRAW-23, DRAW-28.
- [x] 20-02-production-hardening-om-rack-embed-PLAN.md — O&M rack-embed regression test + pdf:smoke-test --drawings rack extension + drawings:audit-licenses command + drawings queue connection in config/queue.php + .env.example chrome-headless-shell version pin + @font-face declarations in 3 drawing Blade views + public/fonts/.gitkeep + drawings-queue-runbook.md. Wave 2 — depends on 20-01. Requirements: (none — pure hardening).

**UI hint**: yes
**Canonical refs**:

  - `.planning/research/SUMMARY.md`
  - `.planning/research/PITFALLS.md` CRIT-03 (queue OOM), CRIT-04 (Chrome version drift), MOD-01 (DXF/DWG GPL trap), MOD-10 (O&M references), MOD-12 (notification timing)
  - `.planning/quick/260427-qvr-migrate-pdf-rendering-to-browsershot/260427-qvr-SUMMARY.md` — Browsershot deployment runbook precedent

</details>

---

## Future Milestones (Outline)

### v1.4 Client Portal & Project Visibility (Phases 32–35)

*"Clients see what they need, when they need it"*

> Renumbered from 26–29 to 32–35 (2026-08-23) to make room for v3.0 (Phases 26–31). Phases 21/22 below were completed under an earlier numbering pass before this renumbering and are listed as-is; treat the numbers in this outline block as provisional until re-planned.

- [x] Phase 21: Client Portal — Branded project status page per client/site with secure access (completed 2026-05-10)
- [x] Phase 22: Document Access — Clients download RAMS, O&M, drawings and certificates from portal (completed 2026-05-12)
- [ ] Phase 34: Survey & Installation Progress — Live completion percentages per room visible to client
- [ ] Phase 35: Notification & Communication — Client receives updates on project milestones and document availability

### v1.5 Financial & Proposal Engine (Phases 36–39)

*"From pricing rules to signed proposal"*

- [ ] Phase 36: Pricing Engine — Multiplier-based config (HW value x multiplier with min/max), admin+sales accessible
- [ ] Phase 37: Proposal Generator — New client + renewal flows, PDF/DOCX branded output
- [ ] Phase 38: Budget Tracking — Project cost monitoring, margin alerts, forecast vs actual
- [ ] Phase 39: Renewal Workflow — Auto-populate from existing contract hardware, year-on-year escalation

### v1.6 Service & Inventory (Phases 40–43)

*"Post-install lifecycle"*

- [ ] Phase 40: Asset Registry — Track installed equipment as live assets with QR codes per item
- [ ] Phase 41: Service Tickets — Contract search, room/asset select, auto-fill site/contact, callback scheduling
- [ ] Phase 42: PMV Checklists — Per-equipment-type maintenance checks with fault diagnosis and sign-off
- [ ] Phase 43: AI Troubleshooting — QR scan triggers AI-guided device-specific troubleshooting workflow

---

## Backlog

### Phase 999.1: v2.0 Engineering-Grade AV Drawings (BACKLOG)

**Goal:** Captured for future planning — produce Lucidchart/Visio-grade auto-generated AV schematics, with port-aware device cards, port-to-port cable routing, Konva canvas editor for engineer overrides, and AI generate-from-project + chat-edit operations. Companion outputs: rack elevations + floor plans + DXF export at the same engineering-grade fidelity. Reference: Duke "Extron Concept" Lucidchart drawing the user shared.

**Requirements absorbed from v1.3:**

- DRAW-14, DRAW-15, DRAW-16, DRAW-17, DRAW-18, DRAW-19, DRAW-20 — Floor Plans (originally Phase 19, deferred 2026-05-02)
- DRAW-29 — DXF export (originally Phase 20 stretch, moved with floor plans)
- DRAW-05 functional schematic editor (Phase 17 ships scaffolding only — full editor needs port catalog)
- DRAW-30 functional schematic chat (Phase 17 ships adapter scaffolding — functional impl needs editor)

**Requirements net-new for v2.0:**

- Per-device port catalog (manufacturer specs)
- Cable schedule with device-level FKs
- Sub-room location zones (Behind Screen / Ceiling / Table)
- Custom device card templates (manufacturer logo + model + port rails)
- Multi-page schematic (system overview + per-subsystem)

**Notes:**

- Full plan in memory: `v2_engineering_grade_drawings_plan.md`
- Run a 1-week build-vs-buy spike (Lucidchart API / draw.io embed / XTEN-AV / D-Tools) BEFORE committing to native build — could compress 14-19 weeks → 3-4 weeks of integration work
- Wave 1 (port catalog + cable FKs) parallelisable across 2 sessions (~30% time saving)
- Phase 23 (renderer) and Phase 25 (AI) cannot parallelise — depend on prior waves
- v1.3 ships at "passable basic" (schematics + racks + bound PDF + O&M handover) — this milestone is the engineering-deliverable-grade upgrade

Plans:

- [ ] TBD (promote with /gsd-review-backlog when ready)

---

## Progress

| Phase | Milestone | Plans Complete | Status | Completed |
|-------|-----------|----------------|--------|-----------|
| 17. System Schematics + Shared Foundations | v1.3 | 3/3 | Complete    | 2026-05-02 |
| 18. Rack Elevations | v1.3 | 2/2 | Complete    | 2026-05-02 |
| 19. Floor Plans (Konva) | v1.3 → v2.0 | 0/0 | Deferred to backlog 999.1 | - |
| 20. Drawing Export + O&M Integration | v1.3 | 2/2 | Complete    | 2026-05-03 |
| 21. Device Port Catalog + Stencil Cache | v2.0 | 3/3 | Complete | 2026-05-10 |
| 22. Cable Schedule with Port-Level FKs | v2.0 | 3/3 | Complete | 2026-05-12 |
| 22.1. RAMS Scope/Room-Data Consolidation | v2.0 | 7/7 | Complete | 2026-05-13 |
| 23. XTEN-AV-Style Renderer | v2.0 | 7/7 | Complete | 2026-05-15 |
| 24. Stencil Curation UI + Quote-Import Auto-Stub | v2.0 | 11/12 | Paused — 24-09 open (human checkpoint) | - |
| 25. AI Assist + Replacement Wiring | v2.0 | 0/0 | Not started | - |
| 26. Hazard Library Structural Inversion | v3.0 | 8/8 | Code complete — open: 26-06 Task 3 live deploy checkpoint, HAZ-02 live re-verification | - |
| 27. Manual-Handling & Display-Lift House Rules | v3.0 | 4/5 | In Progress|  |
| 28. PPE, Ceiling & Electrical Boundary House Rules | v3.0 | 8/8 | Complete   | 2026-09-07 |
| 29. CDM Duty-Holder & Emergency Arrangements | v3.0 | 12/14 | In Progress|  |
| 30. Structural Validation Gates | v3.0 | 9/9 | Complete   | 2026-09-14 |
| 31. Standards/COSHH Scoping & Padding Gates | v3.0 | 0/0 | Not started | - |
| 999.1. v2.0 Engineering-Grade AV Drawings (incl. floor plans + DXF) | Backlog | 0/0 | Backlog | - |
