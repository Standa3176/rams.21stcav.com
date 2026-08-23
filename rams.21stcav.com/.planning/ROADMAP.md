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

- [ ] **Phase 26: Hazard Library Structural Inversion** — Port all 18 `hazard-library.md` hazards with include-when conditions, replacing `config/rams_tier1.php` baseline_hazards AND `HazardLibraryService::MANDATORY_KEYWORDS`; align scores to the skill (incl. Working at Height residual 1×4); typical scores are editable defaults, never silently applied. Foundation for Phases 27–31.
- [ ] **Phase 27: Manual-Handling & Display-Lift House Rules** — All displays are two-operative team lifts regardless of size; wall-mount removal stated as the highest-risk lift; GATE-09 errors on anything else.
- [ ] **Phase 28: PPE, Ceiling & Electrical Boundary House Rules** — FFP3 (not FFP2) everywhere; "confined space" never applied to ceiling void/comms room/riser; electrical scope boundary + ceiling load statements land in output; GATE-06 + GATE-07 ship alongside.
- [ ] **Phase 29: CDM Duty-Holder & Emergency Arrangements** — Settled sole-Contractor CDM position replaces "[To be confirmed]"; named A&E with address replaces "to be identified at site induction"; GATE-11 + GATE-12 ship alongside.
- [ ] **Phase 30: Structural Validation Gates** — Orphan-controls check, every-area-has-a-method-step check, residual-≤-initial-score check (GATE-01, GATE-02, GATE-04).
- [ ] **Phase 31: Standards/COSHH Scoping & Padding Gates** — Standards table and COSHH list become job-conditional (extends Phase 26's include-when pattern); uniform-scoring detection + COSHH/standards padding cross-check (GATE-05, GATE-10).

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
**Plans**: TBD
**UI hint**: yes (empty-register UX, include-when-driven hazard population, editable score inputs on the RAMS review screen)

### Phase 27: Manual-Handling & Display-Lift House Rules
**Goal**: Every generated RAMS states the two-operative display-lift position without exception and calls out wall-mount removal as the highest-risk lift on a strip-out; the generator errors rather than silently accepting anything else.
**Depends on**: Phase 26 (RULE-02/03 edit the Manual Handling hazard's control text, which Phase 26 replaces wholesale — editing the pre-Phase-26 content would be immediately overwritten)
**Requirements**: RULE-02, RULE-03, GATE-09
**Success Criteria** (what must be TRUE):
  1. The Manual Handling hazard's control text mandates a two-operative team lift for every display regardless of panel size — the current `config/rams_tier1.php:78` "Two-person lift mandatory for displays 55" and above" size threshold is gone, and no wording implies a four-operative or size-conditional lift; mechanical aids are stated as additional, never a substitute for the second person
  2. Where a job's scope includes decommission/strip-out of a wall-mounted display, the generated method statement or hazard control explicitly states the removal-from-mount sequence: controlled to lowest practicable height, one operative each side, before release from the mount
  3. GATE-09 errors when any reviewed or generated RAMS specifies a display lift as anything other than two-operative — proven by reverting the fix on a fixture and observing the gate fire, then restoring
  4. Regenerating 21CQ30960 (or another live strip-out job) does not trip GATE-09 — confirms the fix and the gate agree against real data, not just a fixture
**Plans**: TBD
**UI hint**: yes (gate errors surface on the RAMS review screen)

### Phase 28: PPE, Ceiling & Electrical Boundary House Rules
**Goal**: Fix the FFP2/FFP3 contradiction and the "confined space" mislabel at every occurrence (config, `HazardLibraryService` fallback, and Phase 26's ported library), and ensure the ceiling-load and electrical-scope-boundary statements land in generated output; ship GATE-06 and GATE-07 in the same phase so neither fires against a still-broken default.
**Depends on**: Phase 26 (RULE-01/RULE-06 edit hazard content Phase 26 restructures; GATE-07's "confined space" check needs the retitled "Restricted access and ceiling void working" hazard from HAZ-01 to exist first, or it fires against the app's own pre-fix baseline)
**Requirements**: RULE-01, RULE-06, RULE-09, RULE-10, GATE-06, GATE-07
**Success Criteria** (what must be TRUE):
  1. No respiratory-PPE mention anywhere in generated output reads FFP2 — `config/rams_tier1.php:129` (Dust from drilling) and `:286` (Expanding Foam COSHH entry, already FFP3) agree; face-fit testing is stated
  2. No hazard title, fallback string, or generated document text labels a ceiling void, comms room or riser "confined space[s]" — including `HazardLibraryService`'s prior "confined spaces" mandatory-keyword fallback (`:36-44`, `:210-215`) — all read "Restricted access and ceiling void working"
  3. A generated RAMS for a job with ceiling-mounted AV equipment states the ceiling-load position (supported from structural soffit or purpose-designed mount kit — never suspended grid, pipework or sprinkler pipe) and, where the job touches mains power, the electrical scope boundary (terminates at existing socket/data outlet, no alteration to fixed installation, no live working)
  4. GATE-06 errors on any FFP2 occurrence and GATE-07 errors on any ceiling-void/comms-room/riser hazard mislabelled "confined space" — both verified by reintroducing the defect on a fixture and observing the error, then restoring, and both pass clean against a freshly regenerated real project
**Plans**: TBD
**UI hint**: yes (gate errors surface on the RAMS review screen)

### Phase 29: CDM Duty-Holder & Emergency Arrangements
**Goal**: Replace the unconditional CDM duty-holder placeholder and the hardcoded "to be identified at site induction" A&E line with the settled positions, and ship GATE-11/GATE-12 so a RAMS can no longer go out the door with either placeholder.
**Depends on**: Nothing structurally — touches `RamsComplianceUpgradeService.php` and the PDF templates, not the hazard register Phase 26 restructures; may run before or after Phases 27–28
**Requirements**: RULE-07, RULE-08, GATE-11, GATE-12
**Success Criteria** (what must be TRUE):
  1. `RamsComplianceUpgradeService::upgrade()`'s CDM duty-holder defaults (`:1035-1036`, currently hardcoded `'[To be confirmed]'` for `principal_designer`/`principal_contractor` on every job) state the settled sole-Contractor position on an occupied-premises job instead
  2. The Emergency Procedures section's nearest-A&E line (`resources/views/pdf/rams.blade.php:1953`, currently the literal string "Nearest hospital A&E to be identified at site induction.", plus `:1976`'s `'TBC'` fallback) is replaced by a named A&E with address by default
  3. GATE-11 errors when the CDM duty-holder table is left as "[To be confirmed]" on an occupied-premises job; GATE-12 errors when the named A&E does not resolve to a real, currently-open A&E — **scoping flag**: no UK A&E open/closed dataset exists in this codebase today, and site-level A&E storage is explicitly out of scope for v3.1 (see Out of Scope above), so phase planning must pick an approach (curated static list, plausibility check, or explicit narrower scope) rather than assume a live lookup exists
  4. Regenerating a live occupied-premises project shows a stated CDM position and a real named A&E with address, not either placeholder — verified against production data, not just a fixture
**Plans**: TBD
**UI hint**: yes (gate errors surface on the RAMS review screen; CDM/A&E fields may need review-form inputs)

### Phase 30: Structural Validation Gates
**Goal**: Ship the three gates that check document-structural consistency — orphan controls, area/method-step coverage, and residual-vs-initial scoring — against the finished Phase 26 hazard shape.
**Depends on**: Phase 26 (GATE-01's "matching hazard row" check and GATE-04's residual-score check need the final hazard set and HAZ-03's aligned scores to be meaningful, not the old fixed 11-hazard baseline)
**Requirements**: GATE-01, GATE-02, GATE-04
**Success Criteria** (what must be TRUE):
  1. GATE-01 errors when a method step or hazard control references a document, permit or hold point (e.g. "review the asbestos register") with no matching hazard row AND no matching `clientReqs` entry — proven against a fixture reproducing the canonical asbestos-orphan failure named in `PORTING-NOTES.md`
  2. GATE-02 errors when any area/room in the RAMS has zero method steps
  3. GATE-04 flags (does not silently accept) any hazard where residual severity is lower than initial severity, and errors when residual score exceeds initial score on any hazard
  4. Running all three gates against a real regenerated project (21CQ30960) with the Phase 26 hazard set passes clean — no false positives against legitimate, correctly-scoped output
**Plans**: TBD
**UI hint**: yes (gate errors/warnings surface on the RAMS review screen)

### Phase 31: Standards/COSHH Scoping & Padding Gates
**Goal**: Extend Phase 26's include-when pattern to the standards-references and COSHH-substances tables so they cite only what the job involves, then ship the two gates the source notes call out as "far more reliable... once inclusion is conditional" — uniform-scoring detection and COSHH/standards padding cross-check.
**Depends on**: Phase 26 (extends the same include-when mechanism to a second config table; GATE-05's uniform-scoring detection is only meaningful once hazard inclusion is conditional)
**Requirements**: RULE-04, RULE-05, GATE-05, GATE-10
**Success Criteria** (what must be TRUE):
  1. The generated standards table cites only standards the job's captured activities actually involve — `config/rams_tier1.php:352-397`'s always-rendered 9-entry table (including BS EN 60849 voice-alarm, BS 8492 PA systems, HSG 47 underground services and BS EN 60825-1 laser safety, none of which are job-conditional today) becomes include-when scoped
  2. The generated COSHH table lists only substances the job actually carries — `Tier1RamsDefaultsService::injectDefaultsIntoRamsData()`'s unconditional `coshh_baseline` assignment (`:82`, currently ALWAYS set regardless of scope, unlike hazards/standards which are fallback-only) becomes include-when scoped, so a Teams Rooms install no longer shows solder flux or expanding-foam entries
  3. GATE-05 warns when most hazards on a RAMS share the same initial score (assembled-from-library signal) — verified against a fixture that deliberately reintroduces uniform scoring
  4. GATE-10 errors when a cited standard or COSHH substance has no supporting activity in the job's scope — verified against a fixture reproducing the named offenders (BS EN 60849, BS 8492, HSG 47, laser safety with no laser, soldering flux with no soldering) and passing clean against a freshly regenerated real project
**Plans**: TBD
**UI hint**: yes (gate errors/warnings surface on the RAMS review screen)

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
| 26. Hazard Library Structural Inversion | v3.0 | 0/0 | Not started | - |
| 27. Manual-Handling & Display-Lift House Rules | v3.0 | 0/0 | Not started | - |
| 28. PPE, Ceiling & Electrical Boundary House Rules | v3.0 | 0/0 | Not started | - |
| 29. CDM Duty-Holder & Emergency Arrangements | v3.0 | 0/0 | Not started | - |
| 30. Structural Validation Gates | v3.0 | 0/0 | Not started | - |
| 31. Standards/COSHH Scoping & Padding Gates | v3.0 | 0/0 | Not started | - |
| 999.1. v2.0 Engineering-Grade AV Drawings (incl. floor plans + DXF) | Backlog | 0/0 | Backlog | - |
