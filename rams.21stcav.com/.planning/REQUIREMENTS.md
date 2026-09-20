# RAMS Platform — Requirements

> Current milestone: **v4.0 Project Cockpit**. v3.0 requirements are preserved below and
> remain live work (Phases 29-31 carry open gates). v2.0 is **PARKED** (see MILESTONES.md),
> not cancelled.

---

## Milestone v4.0 Requirements

**Milestone:** v4.0 Project Cockpit
**Defined:** 2026-09-19
**Source of truth:** the interactive design decisions taken with the user on 2026-09-19,
recorded in `.planning/sketches/002-install-cockpit/README.md` (cockpit + panels) and
`.planning/sketches/003-quote-import/README.md` (import review). The sketches are the
**visual contract**; where an implementation and a sketch disagree, the sketch wins unless
the user says otherwise.
**Total requirements:** 27 defined so far (LR-01..LR-05, Phase 44; VIS-01..VIS-10, Phase 45;
VL-01..VL-12, Phase 46).
Phases 47–51 are outlined in the roadmap but their requirement IDs are **not yet minted** — each
will be added here when its phase is planned.

### Why this milestone exists

The project page is eleven tabs. A PM cannot see the state of a job without visiting most
of them, and the same fact is recorded in more than one place. Worse, the app is good at
*collecting* structured information from engineers and bad at *turning it into work*:
outstanding items are inert text, serials go unconfirmed, and survey returns generate no
actions.

v4.0 collapses the eleven tabs into one cockpit of section drawers, closed at rest, where
each section **is** its deliverable. Every attendance on site becomes a typed visit, and the
type drives both what the engineer's link contains and what must come back.

### Group LR — Labour resources (Phase 44)

These five were minted at planning time on 2026-09-19 — no formal requirement IDs existed
for this milestone yet — and map 1:1 to the Phase 44 success criteria in `ROADMAP.md`.

- **LR-01** — One table holds every labour resource with a role field (engineer / programmer /
  other). A person may hold more than one role rather than appearing as two rows.
- **LR-02** — An admin can add, edit and **deactivate** a resource. Deactivating preserves the
  person on past visits rather than deleting them; there is no hard-delete path.
- **LR-03** — A PM can select one or more resources, by name, anywhere work is assigned. The
  selector is for the PM only — explicitly **not** on engineer-facing forms.
- **LR-04** — **A client is never given an engineer's or programmer's phone or email. Name
  only.** Contact details exist on the record for the PM's use and are visible to PM and admin
  only. This is a hard rule, not a convention, and must be proven by a test asserting that no
  client-facing document or page renders a resource's email or phone.
- **LR-05** — Engineer names already recorded as free text (`captured_by` on device label
  photos, worksheet sign-offs, survey returns) still display correctly and are not broken or
  rewritten by the new table.

*User's own words on LR-04, quoted in the test docblock so the intent survives: "Client should
only be given engineer name never phone or email — this is for PM only."*

### Group VIS — Visit model + read-only cockpit (Phase 45)

These ten were minted at planning time on 2026-09-19 and map to the Phase 45 success criteria in
`ROADMAP.md`, with one deliberate narrowing recorded in VIS-02.

- **VIS-01** — A `visits` table exists carrying a type (site survey / first fix / install /
  programming / snag / commissioning), its project, its scheduled date, its assigned labour
  resources, and its status.
- **VIS-02** — Every existing `SiteSurvey` row, and every `Worksheet` row that has at least one
  `WorksheetSignoff`, is wrapped by a backfilled visit. Nothing is deleted and no existing row is
  rewritten. (Deliberately narrower than ROADMAP criterion 2 — per D-01, an unsigned worksheet is a
  document, not an attendance. D-01 is authoritative over the criterion wording.)
- **VIS-03** — Every live `/survey/{token}` and `/worksheet/{token}` link keeps working exactly as
  it does today.
- **VIS-04** — The cockpit page renders read-only behind a feature flag, on its own route, showing
  the programme spine and its section drawers closed at rest.
- **VIS-05** — The existing eleven-tab project page is untouched and remains the default. With the
  flag off the application behaves exactly as it does today — proven by a test, not by inspection.
- **VIS-06** — Traffic lights on the cockpit derive from data that already exists. This phase adds
  no new engineer-facing capture and no new writes from any user-facing surface.
- **VIS-07** — The backfill is a separate idempotent console command; re-running it creates no
  duplicate visits.
- **VIS-08** — A visit survives its wrapped record being superseded or soft-deleted, and the cockpit
  marks it as superseded rather than hiding it.
- **VIS-09** — A durable install record exists that is not replaced when the task list is
  regenerated, and the existing programme generate / activate / archive behaviour is unchanged.
- **VIS-10** — Cockpit presentation tokens are introduced as reusable tokens consumable by
  phases 46-51, declared under a **scoping class and never on the shared layout's root block**,
  so that no existing page is retoned: `resources/views/layouts/app.blade.php`,
  `resources/css/app.css` and `tailwind.config.js` stay **byte-identical** (asserted by sha256
  in `tests/Feature/Cockpit/FlagOffBehaviourUnchangedTest.php` against pre-phase `4abd2b24`).
  - *Amended 2026-09-20 by D-08 (sketch 004 accepted); the original teal/gold/Verdana/Poppins
    palette is superseded, the scoping constraint is not. The requirement now states the
    MECHANISM, not the palette — the tokens resolve to the app's own blue and Inter
    (`resources/css/cav-tokens.css`, retargeted by Plan 45-09).*

### Group VL — Visit lifecycle (Phase 46)

These eleven were minted at planning time on 2026-09-20 and map to the Phase 46 success criteria
in `ROADMAP.md` and to D-01..D-04 in `46-CONTEXT.md`. Two deliberate narrowings are recorded in
VL-02 and VL-08; one deliberate GAP against ROADMAP criterion 2 is recorded in VL-12.

- **VL-01** — A PM can create a visit from inside its module drawer: the module fixes the type,
  and the PM sets the date, the rooms in scope and the assigned labour resources. The visit is
  written with `is_backfilled = false`, so a PM-created visit is never mistaken for an inference.
- **VL-02** — Creating a visit for a module that has an engineer link produces **exactly one**
  engineer link, and it is produced by the generator that already exists for that module
  (`site-surveys.from-project` for a survey visit, `worksheets.generate-from-project` for a
  first-fix/install visit). No new public route and no new document generator is written.
  (Deliberately narrower than the literal reading of ROADMAP criterion 1 — the link's content is
  driven by the visit's type *because the type selects which existing engineer link is issued*,
  per D-04's "use the generators that already exist".)
- **VL-03** — Every live `/survey/{token}` and `/worksheet/{token}` link keeps working exactly as
  it does today, and no access token becomes mass-assignable.
- **VL-04** — **The survey → install carry-forward (D-01).** An install engineer opening their
  link sees the survey's `access_constraints`, `parking_restraints`, `comms_room_access_status`,
  `comms_room_access_notes`, `delivery_routes`, `site_access_notes`, `site_risks`, `h_and_s_notes`,
  `general_notes` and `distance_from_base_miles`, **read live from the survey record and never
  copied** — proven by a test that edits the survey after the link was first rendered and asserts
  the link shows the new value.
- **VL-05** — A PM can **accept** a returned visit. Acceptance records who and when, and locks the
  visit's scope. The lock is visible on the page rather than silent.
- **VL-06** — A PM can **send a returned visit back** for more information. The engineer's link
  reopens, the engineer sees the office's reason, and nothing the engineer captured is altered or
  deleted to achieve it.
- **VL-07** — A PM can **add an office note** against a visit. The note is stored separately from
  engineer-captured data and can never overwrite or edit it.
- **VL-08** — A PM can **raise a snag** against a visit. A minimal snag record is created, linked
  to the visit it came from. (Deliberately minimal per D-03: parts, the three outcomes and linked
  follow-up chains are Phase 47. The record is shaped so Phase 47 extends it rather than replacing
  it.)
- **VL-09** — A module's document can be **generated from inside its drawer** using the module's
  existing generator. Sending it to a client, confirming sent, uploading and downloading remain
  absent (Phase 48).
- **VL-10** — Every PM action in this phase writes exactly one `ProjectActivityLog` entry, so the
  panel's Recent activity feed shows what happened without a second history mechanism.
- **VL-11** — **"Simple to use" is a requirement, not a platitude.** Every write is a plain form
  POST — the cockpit ships no JavaScript of its own and the read-only fence's nine banned handler
  attributes stay banned. Two caps, both asserted by test: the panel's **Quick actions** area
  renders **at most one** control per module (Create visit, or Generate document, or nothing), and a
  **visit row** renders **at most four** (Accept · Send back · Add note · Raise a snag), each only
  when it applies to that visit's state. A control is never rendered disabled — a disabled control
  is still an offer. *The user's own words, which this requirement exists to keep: "I want to make
  it look simple and less scary."*
- **VL-12** — **GAP, deliberately recorded, NOT delivered by Phase 46.** ROADMAP criterion 2 says a
  RAMS and a worksheet generated for a visit cover only that visit's type and rooms. Phase 46
  captures `rooms_in_scope` on the visit and shows it on the engineer link, but does **not** scope
  the generators' output: `RamsController::generateFromProject()` and
  `WorksheetController::generateFromProject()` take a `Project` and nothing else, and no decision
  exists about how a room-scoped RAMS should be authored from an AI pipeline driven by the whole
  quote. Raised at planning time on 2026-09-20 rather than silently dropped. Needs a user decision
  before it can be planned.

### Out of scope for v4.0

Each is **recorded in the admin Hidden Functions register**, not forgotten:

- **Visit costs** — no cost or day-rate capture on labour resources or visits. Deferred by
  explicit decision 2026-09-19 ("do not build yet").
- **Install programme task planner** — the 52-task scheduler, week view, Gantt, field view and
  task assignment are hidden for launch. ⚠️ `CommissioningItemGenerator` currently derives items
  from programme *tasks*; Phase 51 must re-source them from the asset register before unhiding
  is safe.
- **In-app drawing generation** — drawings are produced in StarDrawer and uploaded (Phase 48).
- **Project-level RAMS view** — RAMS becomes per-visit; an "every RAMS on this project" surface
  is not rebuilt.
- **Engineer logins** — labour resources are records, not accounts.
- **Linking historical free-text engineer names to resource rows** — possible later, explicitly
  not now (LR-05 requires only that they keep working).

### Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| LR-01 | Phase 44 | Complete (Plan 44-01, 2026-09-19 — `labour_resources` table, `roles` json column) |
| LR-02 | Phase 44 | Complete (Plans 44-01 + 44-02, 2026-09-19 — `is_active` flag; admin CRUD has no `destroy()` method or route by design) |
| LR-03 | Phase 44 | Complete (Plan 44-03, 2026-09-19 — `<x-labour-resource-select>`, active-only, name-only) |
| LR-04 | Phase 44 | Complete (Plan 44-04, 2026-09-19 — source-guard over 20 client-facing files + live HTTP render proof + non-vacuity meta-test) |
| LR-05 | Phase 44 | Complete (Plan 44-04, 2026-09-19 — free-text `captured_by` paths untouched and asserted) |
| VIS-01 | Phase 45 | Complete (Plan 45-02, 2026-09-19 — `visits` table + `Visit` model + factory; `(source_type, source_id)` unique, no FK so a visit outlives its source) |
| VIS-02 | Phase 45 | Complete (Plan 45-05, 2026-09-19 — `visits:backfill`, one visit per survey and per SIGNED worksheet per D-01; nothing deleted, nothing rewritten) |
| VIS-03 | Phase 45 | Complete (Plans 45-05 + 45-08, 2026-09-19 — `/survey/{token}` and `/worksheet/{token}` render 200 with both access tokens byte-identical afterwards) |
| VIS-04 | Phase 45 | Built and test-proven (Plans 45-06 + 45-07, **replaced by** 45-10 + 45-11 + 45-12 + 45-13, 2026-09-20 — nine module rows, URL-driven side panel, nothing open at rest per D-09). **Awaiting the 45-14 human check** — the design comparison against sketch 004 is not self-approvable. |
| VIS-05 | Phase 45 | Complete (Plan 45-08, 2026-09-19, re-run by 45-14 2026-09-20 — flag-off 404 incl. `?module=`/`?tab=`, eleven-tab page byte-identical, dashboard unchanged, three sha256 pins held) |
| VIS-06 | Phase 45 | Complete (Plans 45-06 + 45-07, carried by 45-10 + 45-13, 2026-09-20 — state derives from existing data; the read-only fence bans 18 deferred affordances and 9 handler attributes across every open panel, and a five-table row-count invariance test proves rendering writes nothing) |
| VIS-07 | Phase 45 | Complete (Plan 45-05, 2026-09-19 — dry-run by default; a second `--apply` creates nothing) |
| VIS-08 | Phase 45 | Complete (Plans 45-02 + 45-05 + 45-07, carried by 45-12 + 45-13, 2026-09-20 — a force-deleted source still renders; superseded is disclosed in the module row's at-rest count phrase, not hidden) |
| VIS-09 | Phase 45 | Complete (Plan 45-04, 2026-09-19 — `install_records` durable parent + nullable FK; D-06 baseline 159 passed / 0 failed re-measured at 45-08 and again at 45-14) |
| VIS-10 | Phase 45 | Mechanism complete (Plans 45-03 + 45-06, **retargeted to the app's blue and Inter** by Plan 45-09 per D-08, 2026-09-20 — tokens stay scoped to `.cav-brand`; `layouts/app.blade.php`, `app.css` and `tailwind.config.js` all still hash to their pre-phase `4abd2b24` values). **Awaiting the 45-14 human check** — greyscale distinctness and the 320px count slot are the two properties no test can settle. |
| VL-01 | Phase 46 | Complete (Plan 46-04, 2026-09-20 - POST projects/{project}/cockpit/visits creates from the module drawer; the module fixes the type, the PM sets date/rooms/people, `is_backfilled = false` asserted) |
| VL-02 | Phase 46 | Complete (Plan 46-04, 2026-09-20 - `VisitLinkIssuer` calls the module's existing generator; no new public route and no new document generator. A second survey visit ADOPTS the live survey, so one link stays one link) |
| VL-03 | Phase 46 | In progress (Plans 46-04 + 46-05 + 46-08). 46-04 half done 2026-09-20: no token became mass-assignable (S-02/S-03 `$fillable` omissions untouched, tokens still minted only in `boot::creating`), and a test asserts no token is rendered anywhere in the cockpit region |
| VL-04 | Phase 46 | Planned (Plan 46-03 — **the phase’s core feature**, D-01; proven by editing the survey and re-fetching the link) |
| VL-05 | Phase 46 | Planned (Plans 46-01 + 46-06) |
| VL-06 | Phase 46 | Planned (Plans 46-01 + 46-05 + 46-06 — the reopening is DERIVED, so `submitted_at` is never cleared) |
| VL-07 | Phase 46 | Planned (Plan 46-07 — append-only `visit_notes`; `office_review_notes` deliberately not reused) |
| VL-08 | Phase 46 | Planned (Plans 46-02 + 46-07 — minimal record only, D-03, fenced at both the schema and the HTTP boundary) |
| VL-09 | Phase 46 | Complete (Plan 46-04, 2026-09-20 - one `Generate document` control on RAMS / O&M / Cable schedule, posting to the existing generator route behind `Route::has()`. Issue to client, Add document, Upload files and Download all still banned by the fence) |
| VL-10 | Phase 46 | In progress (Plans 46-04 + 46-06 + 46-07). 46-04 half done 2026-09-20: `ProjectActivityLog::ACTION_VISIT_CREATED`, exactly one row per create, rendered by the panel's own feed |
| VL-11 | Phase 46 | In progress (Plans 46-04 + 46-06 + 46-07, human-checked in 46-08). 46-04 half done 2026-09-20: the Quick actions cap is EXECUTABLE - one control per module over five modules, none over four, never more than four controls in the block at any state, nothing disabled, and all nine banned handler attributes kept because Phase 46 considered retiring them and declined |
| VL-12 | Phase 46 | **GAP — not delivered by Phase 46.** Raised 2026-09-20 at planning time; needs a user decision (see VL-12 above). |

*Phases 47–51 have no requirement IDs yet. Mint them into this section as each phase is planned,
following the LR-xx / VIS-xx pattern.*

---

## Milestone v3.0 Requirements

**Milestone:** v3.0 RAMS Skill Parity
**Defined:** 2026-08-23
**Source of truth:** the `21cav-rams` Claude skill, supplied by the user 2026-08-23 —
`references/house-rules.md`, `references/hazard-library.md`, `PORTING-NOTES.md`.
Those documents are **settled 21CAV positions**, not proposals. Where the app and
the skill disagree, the skill wins unless the user says otherwise for a specific job.
**Total requirements:** 30 (GATE-01..14 = 14, RULE-01..12 = 12, HAZ-01..04 = 4).
*Addendum (2026-08-26): a **Group D** of 7 further requirements (RULE-13..19, GATE-15..17)
was recovered when the vendored skill was re-synced and found to have been a truncated
snapshot since 2026-08-23. **Group D is UNSCHEDULED and deliberately excluded from the
count above** — whether it joins v3.0 or becomes its own milestone is an open scope
decision. Two existing requirements were restated the same day against recovered text:
**RULE-11** (its open human decision is now closed — fire-stopping is excluded outright)
and **RULE-07** (the "settled sole-Contractor position" is forbidden wording; it must read
"currently anticipated"). See `.planning/reference/SKILL-RESYNC-2026-08-26.md`.*
*Addendum (2026-08-25): GATE-13, GATE-14, RULE-11 and RULE-12 added after an independent
professional review of the regenerated 21CQ30960 pack (RAMS 97). That review independently
re-derived most of the existing v3.0 requirements, confirming the milestone's scope, and
surfaced these four defects that no existing requirement covered.*
*Correction (2026-08-23, roadmapping pass): this section previously stated "24" —
the itemised list below sums to 26. GATE-03 and GATE-08 are already shipped, leaving
24 requiring new work; the roadmap covers all 26 IDs, with the 2 shipped ones marked
for traceability only. See `.planning/ROADMAP.md` v3.0 section for the discrepancy note.*

### Why this milestone exists

A professional review of a real generated RAMS (21CQ30960, VW Blakelands) found
defects that the skill's own documents had already predicted by name — the double
"Associated risks" line and the podium-steps contradiction are both written down in
`house-rules.md` as known failure modes. The app diverged from the methodology.

The structural cause is stated plainly in `PORTING-NOTES.md`:

> *"The default should be an empty register that the user adds to, never a full
> register the user prunes."*

`config/rams_tier1.php` ships 11 fixed `baseline_hazards` injected into every RAMS —
exactly the inversion that document warns against. Fixing individual lines (FFP2,
missing asbestos row) without fixing the shape leaves the defect generator intact.

**Roadmapping finding (2026-08-23):** a second, stronger injection mechanism exists
alongside `config/rams_tier1.php` — `App\Core\Modules\KnowledgeLibrary\HazardLibraryService::MANDATORY_KEYWORDS`
(7 keywords) is merged into every resolved hazard set unconditionally, regardless of
engineer selection, via `mergeWithMandatory()`. HAZ-02 (Phase 26) must fix both.

### Group A — Validation gates (deterministic checks)

From `PORTING-NOTES.md` "Validation gates worth implementing in code". These are the
recurring review defects; the notes argue they are far more reliable as code than as
instructions to a model. **GATE-03 and GATE-08 already shipped** (quick task 260817-r5e)
and are listed for traceability, not rework.

- [ ] **GATE-01**: Orphan controls — every method step or hazard control referencing a document, permit or hold point has a matching hazard row OR a matching client-responsibility entry (D-05 — either support missing is a violation; `clientReqs` is the skill's key, its app equivalent is the union of `client_responsibilities` and `client_responsibilities_expanded`, D-08). Canonical failure: "review the asbestos register" with no asbestos hazard behind it. *(Plan 30-03 LANDED 2026-09-13: `RamsComplianceUpgradeService::enforceOrphanControlGate()` code-complete and tested (`StructuralGatesTest`, `StructuralGatesSaveReviewGateTest`, `StructuralGatesDisarmedTest`), proven against the real 21CQ30960 document shape by Plan 30-09 (`StructuralGatesRealDocumentTest`). Ships behind `structural_gates_enabled` (`RAMS_STRUCTURAL_GATES`, `env(..., false)`), which still defaults `false` per D-03 pending the `30-MEASUREMENT.md` corpus pass. Per this project's own precedent (GATE-11/GATE-12 at `:72`), a disarmed gate is Pending, not Complete — left `[ ]` deliberately.)*
- [ ] **GATE-02**: Every area has at least one method step. *(Plan 30-03 LANDED 2026-09-13: `RamsComplianceUpgradeService::enforceAreaCoverageGate()` code-complete and tested — passes vacuously on zero areas (manual/form-only RAMS), proven against the real 21CQ30960 document shape by Plan 30-09. Ships behind the same `structural_gates_enabled` (`RAMS_STRUCTURAL_GATES`, `env(..., false)`) flag as GATE-01, which still defaults `false` per D-03 pending `30-MEASUREMENT.md`. Per this project's own precedent (GATE-11/GATE-12 at `:72`), a disarmed gate is Pending, not Complete — left `[ ]` deliberately.)*
- [x] **GATE-03**: Every method step has exactly one `risks` line, and every RA reference resolves to a hazard that exists. *(Shipped 260817-r5e — includes the index-vs-id dangling-reference fix.)*
- [ ] **GATE-04**: Residual score ≤ initial score on every hazard, and residual severity normally unchanged. Flag `s2 < s1` for human review rather than accepting it — controls reduce likelihood, not severity. *(Plan 30-06 LANDED 2026-09-13: `RamsComplianceUpgradeService::enforceResidualScoreGate()` code-complete and tested — the phase's only two-tier gate (errors on residual-exceeds-initial, warns-never-throws on residual-severity-below-initial), non-vacuity proven against the committed Tilda golden fixture's real hazard data and against the real 21CQ30960 document shape by Plan 30-09. Ships behind the same `structural_gates_enabled` (`RAMS_STRUCTURAL_GATES`, `env(..., false)`) flag as GATE-01/GATE-02, which still defaults `false` per D-03 pending `30-MEASUREMENT.md`. Per this project's own precedent (GATE-11/GATE-12 at `:72`), a disarmed gate is Pending, not Complete — left `[ ]` deliberately.)*
- [ ] **GATE-05**: Uniform-scoring detection — if most hazards share the same initial score, the register was assembled from the library rather than the job. Warn.
- [x] **GATE-06**: FFP2 anywhere → error. House rule is FFP3 with face-fit testing. *(Closed 2026-09-06, Plan 28-06: `RamsComplianceUpgradeService::enforceFfp2AndConfinedSpaceGate()` throws `RamsGenerationException` on any surviving hazard-control FFP2 text (via `ControlTextRuleViolations::detect()`) or literal `FFP2` token in `$data['ppe']`/`$data['ppe_matrix']`, config-gated by the independent `RAMS_PPE_CEILING_ELECTRICAL_GATE` flag. Reachable and caught with a friendly redirect on all 3 HTTP-reachable `upgrade()` call sites, including the previously-unguarded `downloadPdf()` path. Proven via reflection, a real Save-Review HTTP POST, and both `runPipeline()`/`runFromReview()` entry points. See `28-06-SUMMARY.md`.)*
- [x] **GATE-07**: "Confined space" applied to a ceiling void, comms room or riser → error. Not confined spaces under the 1997 Regulations. *(Closed 2026-09-06, Plan 28-06: the same gate's hazard-NAME check — unconditional per hazard, not nested inside any template-resolution branch — throws on a hazard named "Confined Space"/"Confined Spaces"/"Confined Space Entry", none of which `LegacyHazardNameFoldMap` folds and none of which reach Plan 28-01's tier-1 auto-correction (`RamsBuilderService::reviewedToRisk()` skips its `detectAll()` call entirely for an unmatched name). This was the one surface no other mechanism in the phase scanned. See `28-06-SUMMARY.md`.)*
- [x] **GATE-08**: Access-equipment contradiction — something excluded in one section and required as a control in another. *(Shipped 260817-r5e — podium steps.)*
- [x] **GATE-09**: Display lift that does not conform to RULE-02's bands → error. Specifically: **4 or more operatives at any size** → error; **2 operatives for a display above 90″** → error; **1 operative for a display 55″ or larger** → error. 1 operative below 55″ is **correct output, not a defect**. An unresolvable display size is **not** an error (it takes the 2-operative band silently — see 27-CONTEXT.md D-05). *(Amended 2026-08-25 alongside RULE-02; originally read "anything other than two-operative → error".)* *(Plan 27-06, 2026-08-26: coverage extended to engineer-typed `material_handling.large_items` rows — Plan 27-03's original gate could only ever check policy-derived items, which are conformant by construction and can never violate the bands. See 27-06-SUMMARY.md.)* *(Plan 27-07, 2026-08-26: closed the last two bypass paths — `RamsController::updateAndDownload()`'s Save Review route now mirrors `material_handling` before `upgrade()` so the gate can see it, and the live PDF template now reads the gated `generated_data['material_handling']` source (reviewed_data fallback for pre-phase documents only). GATE-09 now covers all three generation entry points plus the live PDF render. See 27-07-SUMMARY.md and deferred-items.md.)*
- [ ] **GATE-10**: COSHH and standards padding — cross-check every COSHH substance and cited standard against the activity list. Named offenders: BS EN 60849, BS 8492, HSG 47, laser safety on a job with no laser, soldering flux with no soldering.
- [ ] **GATE-11**: CDM duty-holder table left as "[To be confirmed]" on an occupied-premises job → error. There is a settled position. *(Corrected 2026-09-12 re-verification: implementation is code-complete and tested (`RamsComplianceUpgradeService::enforceCdmGate()`), but GATE-11 and GATE-12 share the single `cdm_ae_gate_enabled` flag (`config/rams_tier1.php:134`, `env('RAMS_CDM_AE_GATE', false)`), which still defaults `false` per D-03 pending the outstanding visual verification. Per this project's own precedent (GATE-06/07/09 were marked Complete only once their flags default `true`/armed), a disarmed gate is not Complete. 29-08's executor marked GATE-11 Complete while 29-07's executor correctly left GATE-12 Pending for the identical reason — reconciled here to Pending for both.)*
- [ ] **GATE-12**: Named A&E must be a real A&E. A subcontractor RAMS once named a hospital whose A&E closed in 2014.
- [ ] **GATE-13**: Hot-works contradiction — a RAMS asserting "No hot works of any kind included in this scope" while also requiring a hot-works permit, or listing solder/flux in COSHH, is self-contradictory and must error. Same contradiction class as the shipped GATE-08. *(Found 2026-08-25 in 21CQ30960: RA18 says no hot works, §6.8 requires a hot-works permit for soldering, and COSHH carries Tin/Lead solder and rosin flux — three sections disagreeing.)* *(Plan 30-07 LANDED 2026-09-13: `RamsComplianceUpgradeService::enforceHotWorksGate()` built whole with both halves — an unconditional hot-works permit requirement (permit_and_isolation), and solder/flux listed in coshh_baseline — cross-referenced against a `hot_works_assertion` absence-assertion detector (`ControlTextRuleViolations`). Proven not to fire on `addPermitAndIsolation()`'s own conditional permit line (built by invoking that method directly and asserting identity). Code-complete and fully tested (`HotWorksGateTest`, 8 tests; `StructuralGatesDisarmedTest` extended with the D-04 flag-independence proof), but `RAMS_HOT_WORKS_GATE` still defaults `false` per D-02 and is NOT flipped until Phase 31, once RULE-05/GATE-10 make the COSHH table job-conditional. Per this project's own precedent (GATE-11/GATE-12 at `:72`), a disarmed gate is Pending, not Complete — left `[ ]` deliberately.)*
- [ ] **GATE-14**: Missing risk references — every method step must cite the hazards its own text implies. GATE-03 (shipped) checks that cited references *resolve*; nothing checks that a step is *missing* a reference it plainly needs. *(Found 2026-08-25 in 21CQ30960: Step 4 "Display & Mount Installation" cites RA11/12/13/21 but omits RA01 Working at Height and RA02 Manual Handling, on a step entirely about lifting displays onto wall mounts.)* *(Plan 30-08 LANDED 2026-09-13: `RamsComplianceUpgradeService::enforceMissingRiskRefGate()` built as a WARN-tier gate (never throws — `associated_risks` is app-generated by `crossReferenceMethodStatementRisks()`, so a violation is a defect in the app's own derivation, unactionable from the review screen) reading the config-resident `missing_risk_implications` map via `StructuralGateVocabulary`, never `crossReferenceMethodStatementRisks()`'s `$keywordRiskMap`. Independence proven at METHOD scope by `MissingRiskRefGateSourceGuardTest` (reflection source-slice extraction, since both methods live in the same file). Code-complete and fully tested (`MissingRiskRefGateTest`, 8 tests; `StructuralGatesDisarmedTest`'s D-04 matrix now complete), but `RAMS_MISSING_RISK_REF_GATE` still defaults `false` per D-03 pending live corpus measurement (`30-MEASUREMENT.md`). Per this project's own precedent (GATE-11/GATE-12 at `:72`), a disarmed gate is Pending, not Complete — left `[ ]` deliberately.)*

### Group B — House rules enforced in code

From `references/house-rules.md`. Settled positions applied without asking.

- [x] **RULE-01**: FFP3 with face-fit testing replaces FFP2 wherever respiratory PPE is specified. `config/rams_tier1.php:129` currently contradicts `:286` in the same file. *(Closed 2026-09-06, Plan 28-06 — the last of a 3-plan sequence. Plan 28-01 added the `ffp2` detector to `ControlTextRuleViolations`; Plan 28-04 fixed all 14 live FFP2 source sites and shipped a repo-wide static ban test; Plan 28-06 adds the throwing runtime gate (GATE-06) that catches everything the static ban and tier-1 auto-correction cannot reach — most importantly a pre-Phase-28 document's stale `generated_data` on the Save Review path, which never runs tier-1 correction at all. Three independent layers (static source ban, auto-correct-on-match, throw-on-survival) now jointly guarantee FFP2 never reaches a rendered document. See `28-01-SUMMARY.md`, `28-04-SUMMARY.md`, `28-06-SUMMARY.md`.)*
- [x] **RULE-02**: Display lifts follow **banded team sizes**: scheduling/touch/control panels **≤14″ get no manual-handling row at all**; a display **under 55″ is 1 operative**; **55″ to 90″ inclusive is minimum 2**; **above 90″ is minimum 3**. **Never four or more operatives at any size.** Mechanical aids are additional, not a substitute for a required operative, and never discharge the third operative above 90″. An unresolvable display size takes the 2-operative band (see 27-CONTEXT.md D-05). *(**AMENDED 2026-08-25 — deliberate 21CAV override of the skill; corrected same day.** An intermediate version of this amendment set a two-operative floor at every size; the user reinstated the single-operative band below 55″ after research surfaced that the worksheet generator has no small-panel exclusion. The bands above are the settled position — see 27-CONTEXT.md D-01 and its correction block. Originally read "All displays are two-operative team lifts regardless of panel size. Never four-operative; never conditional on screen size." That text is sourced from `.planning/reference/21cav-rams-skill/references/house-rules.md:8-11`, which `ROADMAP.md:13` declares the winner in any app-vs-skill disagreement. The user was shown that conflict explicitly — including that a size ladder is the exact defect the 21CQ30960 professional review raised — and settled it in favour of the floor-plus-ladder position during Phase 27 discussion. The four-operative ban and the mechanical-aids clause are unchanged; only the "regardless of panel size" clause is amended. The skill file itself is **not** edited — the divergence is recorded on the app side only. Full rationale and the declined alternatives: `.planning/phases/27-manual-handling-display-lift-house-rules/27-CONTEXT.md` D-01/D-02.)* *(Closed 2026-08-26, Plan 27-02: `RamsComplianceUpgradeService::suggestHandlingMethod()`'s display branch now delegates every band to `DisplayLiftPolicy::forSize()` — the hardcoded 4-person/3-person ladder at 85″/65″ is gone. See `27-02-SUMMARY.md`.)* *(Extended 2026-08-26, Plan 27-04 (D-04): `SafetyProfileService`'s worksheet-side display-lift warning and `MethodStatementService`'s AI-fallback string now both read the same `DisplayLiftPolicy` bands — a genuine 32″/43″ display, which previously produced no worksheet warning at all under the old `LARGE_DISPLAY_INCHES=55` + fixed-size-list regex, now produces the correct 1-operative-band warning; the ≤14″ control-panel exclusion is mirrored so a genuine scheduling/touch panel still produces none. See `27-04-SUMMARY.md`.)*
- [x] **RULE-03**: Removal of a display *from* an existing wall mount is stated explicitly as the highest-risk lift on a strip-out — controlled to lowest practicable height, one operative each side, before release from the mount. *(Closed 2026-08-26, Plan 27-02: `deriveMaterialHandling()` now scans `scope_items.decommission` and appends `DisplayLiftPolicy::wallMountRemovalStatement()` for display items found there — the statement previously existed only as a buried hazard-control bullet, never on the generated §6.7 table for a real strip-out job. See `27-02-SUMMARY.md`.)* **Caveat added 2026-08-26 — the emission is correct but UNEXERCISED on live data.** Live verification of 21CQ30960 (RAMS 100/102) found `scope_items.decommission` empty — all 24 items classified `new_install` — on a job whose own method statement describes a decommissioned display. The scan works and has nothing to scan. Closed on the behaviour it owns; the classification gap is **DATA-01**. Plan 27-08 additionally removed the statement from the seeder's static controls, so it no longer appears unconditionally on installation-only jobs (`hazard-library.md` marks it "Removal jobs only").
- [ ] **RULE-04**: Standards table cites only what the job involves. No library padding.
- [ ] **RULE-05**: COSHH lists only substances actually carried.
- [x] **RULE-06**: Restricted-access hazard is titled "Restricted access and ceiling void working" — never "confined space".
- [x] **RULE-07**: CDM duty-holder note replaces "[To be confirmed]" with the **anticipated** sole-Contractor position — worded *"21CAV is currently anticipated to be the sole contractor for the AV works…"*, never an unequivocal assertion that 21CAV **is** the sole contractor. *(**RESTATED 2026-08-26.** Originally read "states the settled sole-Contractor position", which the skill explicitly forbids: `references/standards-and-legislation.md` §"CDM 2015 — how to word the duty holders" says* **"Do not state unequivocally that '21CAV is the sole contractor'. At preliminary stage the contractor make-up is usually unconfirmed."** *That file was absent from the 2026-08-23 vendoring and recovered on 2026-08-26 — see `.planning/reference/SKILL-RESYNC-2026-08-26.md` §C-2. Phase 29 as previously specified would have shipped the forbidden assertion.)*
- [x] **RULE-08**: Where a 24/7 Emergency Department is verified for the site, it is named with full address and postcode, and route/travel time are confirmed at induction. Where it is not verified, the document states the hold point and the 24/7 ED requirement. The passive string "to be identified at site induction" never appears in either branch. *(**RESTATED 2026-09-11.** Originally read "Nearest A&E named with address; 'to be identified at site induction' is not acceptable output." `house-rules.md` §"Emergency arrangements" (`:265-273`) is explicit that asserting a named A&E without verification is the exact defect GATE-12 exists to catch — see `.planning/phases/29-cdm-duty-holder-emergency-arrangements/29-CONTEXT.md` D-05/D-06.)*
- [x] **RULE-09**: Electrical scope boundary stated — works terminate at existing socket or client data outlet, no alteration to fixed installation, no live working. *(Closed 2026-09-06, Plan 28-07 — the last of a 2-plan sequence.)* Plan 28-05 unconditionally seeded the sentence as a 6th bullet in `RamsDisplayPatchService`'s default `exclusions` array, proven to reach the live DOCX render path (`buildLegacy()`) for a never-reviewed document — but the `!isset` seed only fires once, so any document already reviewed even once (`reviewed_data['exclusions']` permanently `isset`) could never receive it. Plan 28-07's backfill migration (`2026_09_05_180000_backfill_ppe_ffp2_and_electrical_exclusion_bullet.php`) closes exactly that gap: it appends the identical verbatim bullet to every already-`isset` `exclusions` array lacking it (32 production documents, measured), on both `reviewed_data` and `generated_data`, without disturbing any engineer-authored exclusion — idempotent and test-proven. **Note:** the migration is code-complete and tested but has not yet been run against production; Plan 28-08 owns the deploy sequence (ship gate-off, run this migration, re-measure, arm the gate). D-07 also explicitly deferred four further `house-rules.md` positions (BS 7671/lock-off wording — open user decision pending; live-working PPE-row ban; "first-fix power"→"first-fix AV signal/data/ELV cabling" rename; hardwired-supply client-isolation wording) — none built, all named rather than silently dropped, and not part of this requirement's scope. See `28-05-SUMMARY.md`, `28-07-SUMMARY.md`, `28-07-MEASUREMENT.md`.
- [x] **RULE-10**: Ceiling load stated as supported from structural soffit or purpose-designed mount kit — never suspended grid, pipework or sprinkler pipe. *(Closed 2026-09-06, Plan 28-05: no new trigger was needed — research Q1 already proved `signal:ceiling_void_access` fires for a ceiling-MOUNTED (non-void-entry) job via the broad `ceiling_works` keyword match, and the seeded hazard already carries the correct sentence. This plan added `CeilingLoadStatementRegressionTest`, an end-to-end proof against the REAL seeded `HazardTemplateSeeder` library — not a hand-built fixture — locking the finding in as a durable regression test. See `28-05-SUMMARY.md`.)*
- [ ] **RULE-11**: **Fire-stopping is excluded from 21CAV scope, stated the same way in the exclusions, the hazard register and QA.** Any penetration of a fire-rated element is sealed by others / referred to the client with a specified fire-stopping detail before proceeding. Expanding foam is never described as a cable-penetration fire-stop. 21CAV must never both exclude fire-stopping and claim in a hazard row or QA to fire-stop penetrations "to the original rating" — state that 21CAV fire-stops **only** where the quote scope explicitly includes an approved fire-stopping system. *(Found 2026-08-25 in 21CQ30960: RA13 and RA18 both state the correct position, then the COSHH table lists "Expanding Foam — cable-penetration fire-stop", contradicting them.)* *(**RESTATED 2026-08-26, and the open human decision is CLOSED.** The requirement previously read that penetrations are "sealed with the client-specified system restoring the original compartment rating" and carried* **"Needs a human decision on the actual approved product/system before implementation."** *There is no 21CAV product to name — that is the answer. `references/house-rules.md` §"Fire-stopping — one consistent position" settles it: fire-stopping is excluded outright. That section was absent from the 2026-08-23 vendoring and recovered on 2026-08-26 — see `.planning/reference/SKILL-RESYNC-2026-08-26.md` §B-1. Phase 28 is no longer blocked.)* *(**REASSIGNED 2026-09-05: Phase 28 → Phase 31.** RULE-11 was listed in Phase 28's requirements but appeared in none of its four success criteria, so it would have shipped silently unbuilt or been built with nothing to verify against. It now sits in Phase 31 with its own success criterion (ROADMAP Phase 31 criterion 5). Rationale: the concrete defect is a COSHH-table entry — Expanding Foam described as a cable-penetration fire-stop — which Phase 31 criterion 2 already names, and RULE-05/GATE-10 own that table. **Note for Phase 31 planning: this requirement is wider than a COSHH scoping fix** — the exclusions list and the hazard register must state the same position, which is new surface for that phase. See `.planning/phases/28-ppe-ceiling-electrical-boundary-house-rules/28-CONTEXT.md` D-09.)*
- [ ] **RULE-12**: Manual-handling controls derive from actual equipment weight, manufacturer handling requirements and route assessment — never from screen diagonal alone. Mount and bracket rows must not inherit display handling text. *(Found 2026-08-25 in 21CQ30960 §6.7: a double-arm wall mount and a tilting wall mount both carry the "minimum 3 persons recommended for 65-inch" wording belonging to a display.)* **PARTIAL — 2026-08-26, Plan 27-02:** the "mount rows must not inherit display handling text" clause is CLOSED — `suggestHandlingMethod()`'s mount/bracket branch now runs before the display branch, so a description containing both words resolves as a mount, never inheriting display text (proven by an explicit revert/restore non-vacuity test). The "derive from actual equipment weight, manufacturer handling requirements and route assessment" clause remains OPEN and is deliberately NOT closed by this checkbox — RESEARCH.md confirmed `weight_kg`/`display_size_in` structured tags never reach RAMS-path equipment items on the real quote-ingestion pipeline (`QuoteWerksImportService`/`ExtractQuoteJob` produce raw description/qty/category only), so weight-driven derivation has no live data source to read yet. See `.planning/phases/27-manual-handling-display-lift-house-rules/27-02-SUMMARY.md` ("RULE-12 Scope Note") and `27-CONTEXT.md`'s `<deferred>` section, which already anticipates this as its own future phase.

### Group D — Recovered from the 2026-08-26 skill re-sync (UNSCHEDULED)

The vendored skill was a truncated snapshot until 2026-08-26; 8 of `house-rules.md`'s
21 sections and `references/standards-and-legislation.md` were never present. These
requirements come from the recovered material and have **no phase assignment**.

**They are deliberately NOT in v3.0.** Adding seven requirements to a running milestone
is a scope decision, not a planning detail — the user has not made it. Do not plan a
phase against Group D without that decision. Full evidence:
`.planning/reference/SKILL-RESYNC-2026-08-26.md` §D.

- [x] **RULE-13**: Manual-handling controls never state a fixed kg threshold. Team size and mechanical aids follow the weight, dimensions, shape, carry route and the task-specific manual handling assessment — *"there is no fixed 'safe' lifting weight in UK law"* (`hazard-library.md`, Manual handling control 1). *(**Code already corrected 2026-08-26** across 6 sites — `HazardTemplateSeeder` and `RamsComplianceUpgradeService:558/713/1494/1619/1646` — porting the skill's own replacement wording. This ID exists so the position is recorded and testable, not because work is outstanding. Needs a gate to stay closed.)* **CLOSED 2026-08-26 (live, RAMS 102).** Plan 27-08 added `ControlTextRuleViolations::detect()`'s `kg_threshold` detector, which replaces the breaching control text on regeneration even when an engineer authored it. Verified on production: the banned-wording scan reads `clean "over 20 kg"` where it read `FOUND` before. Note this closes RULE-13 for **generated** output; it is a content rule with a detector behind it, not a full validation gate — a `GATE` ID policing it at document level is not yet raised.
- [ ] **RULE-14**: Fixings are selected **after** the substrate is verified, per the mount and fixing manufacturer's published design/load data, and for post-installed anchors in concrete or masonry per **BS 8539:2012+A1:2021**, with structural-engineering advice where load, edge distance or substrate is marginal. Never assume an anchor diameter, drill size or embedment; never mandate a blanket safety factor in place of the manufacturer's data; never promise a blanket pull-test of every fixing to an assumed value. *(Seeded Fixings controls currently do both of the last two. `hazard-library.md` Fixings controls 1-2 and 6.)*
- [ ] **RULE-15 / GATE-15**: Document status defaults to **"Draft — Preliminary (subject to technical survey)"** while room names, equipment schedule, item weights, mounting heights, substrates, access method, named engineers or site contact are unknown. "For Issue" only once those are populated. A document stamped "For Issue" whose own text says the survey or equipment schedule is outstanding is a self-contradiction and must **error** — same class as the shipped GATE-08. The `revisions` document-control table must always be populated. *(`house-rules.md` §"Document status".)*
- [ ] **RULE-16**: Before drilling in occupied premises, coordinate with Facilities to identify smoke/heat detectors near the work, arrange permitted temporary isolation or detector protection through the client's authorised person, and reinstate and confirm detection immediately after. Never say "reposition the detector"; never invent a fixed no-drilling distance. *(`house-rules.md` §"Drilling near fire detection"; `hazard-library.md` dust control 6.)*
- [ ] **RULE-17 / GATE-16**: No invented numerics anywhere in output — drilling noise figures, anchor sizes, equipment weights, no-drilling distances, access-height switching thresholds, travel times. Weights are indicative and marked confirm-at-survey. The 21CAV operations number **01189 977770** is always populated, never "to be confirmed". Qty columns hold a number or "TBC" only, never a phrase. *(`house-rules.md` §"Don't invent figures". Highly checkable deterministically.)*
- [ ] **GATE-17**: Respirable crystalline silica is controlled below its **WEL (0.1 mg/m³, 8-hour TWA)** — never called an "EAV", which belongs to noise and vibration only. No asbestos-awareness training in the RCS control. RIDDOR reporting is **0345 300 9923** or online; never an invented "HSE Incident Hotline". Error on each. *(`house-rules.md` §"Exposure limits and RIDDOR". Extends Phase 28's RULE-01.)*
- [ ] **RULE-18**: Where a residual score remains MEDIUM, state explicitly that it is **reduced ALARP and accepted with the listed controls in place** — never leave a medium residual against matrix wording that says medium "requires further action". *(`house-rules.md` §"Residual risk and the matrix". The "residual never exceeds initial" half of that section is already GATE-04, Phase 30.)*
- [ ] **RULE-19**: Team and client fields used consistently — a supplied lead engineer appears on the cover **and** in the team table, never "Named at survey" alongside a supplied name. Do not assert CSCS/PASMA/Asbestos-Awareness for every operative as fact; use *"Relevant competence and certification confirmed for the tasks allocated; PASMA-trained operatives used where mobile access towers are erected, altered or dismantled."* The client's own name goes in the CDM Client row, never "To be confirmed" beside it. "Accepted by (Client)" is left blank for the client to sign. *(`house-rules.md` §"Team, competence and consistency".)*

### Group E — Pipeline / data gaps (UNSCHEDULED)

Not house rules or gates — defects in the data the generator is given. Recorded so a
correct-but-unreachable implementation is not mistaken for a broken one.

- [ ] **DATA-01**: `scope_items.decommission` is populated from the quote package whenever the job removes existing equipment. Live evidence 2026-08-26 (21CQ30960 / RAMS 100 and 102): `decommission: 0 items`, `retained: 0 items`, `new_install: 24 items`, on a job whose generated §6.6 step 3 reads *"the decommissioned 55-inch display from the former Nadin open space"*. `RamsDisplayPatchService:307-311` writes the bucket only when the package's own classification produced decommission items, and it produced none. **Consequence:** RULE-03's wall-mount removal sequence (built, tested and deployed in Phase 27) can never fire on a real job, and the *Decommissioning and WEEE* hazard's `signal:strip_out_or_decommission` include-when can never match. Belongs with the quote-import/classification work, not with Phase 27. *(Raised 2026-08-26 when Phase 27's success criterion 2 was restated to the behaviour Phase 27 owns — see ROADMAP Phase 27 criterion 2 and `27-VERIFICATION.md` Blocker 2.)*

**Sharpens an existing requirement rather than adding one:** RULE-05 / GATE-10 (Phase 31)
are currently framed only as anti-padding. The recovered text adds a wrong-category rule:
**the COSHH table holds substances and processes with a health hazard only** — never
electrical, manual-handling or working-at-height entries. *"'Electrical hazards' in a
COSHH assessment is a standard review reject."* Keep COSHH consistent with the tools and
method. Fold this into Phase 31's scope when it is planned.

### Group C — Hazard library reconciliation

From `references/hazard-library.md` (18 hazards, each with an explicit "Include when").
The app has 11, applied unconditionally.

- [x] **HAZ-01**: Port the 8 hazards present in the skill and absent from the app — Noise and vibration, Restricted access and ceiling voids, Low voltage AV connections, Asbestos-containing materials, Vehicle and plant movement, Lone and small-team working, Fire and evacuation, Decommissioning and WEEE.
- [x] **HAZ-02**: Each hazard carries an **include-when** condition; a hazard is included only when the job meets it. This is the inversion — register starts empty and is added to. (Plan 26-02 landed the tiered evaluation logic; Plan 26-03 removed all four fixed-baseline re-injection paths; Plan 26-04 wired `HazardIncludeWhenResolver` into `RiskTemplateResolverService` and the `RamsExtractionDraftBuilderService::build()` / `RamsBuilderService::runPipeline()` call sites. **CORRECTION 2026-08-24 — reopened after live verification.** Marked complete in error on 2026-08-23: a THIRD generation path exists that was never wired. `RamsBuilderService::runFromReview()` (`:131`) resolves hazards via `reviewedToRisk()` (`:136`), which maps reviewed hazards 1:1 and calls `resolveFromSeeds()` per name only to fill missing scores/controls — it never calls `HazardIncludeWhenResolver`. Any project whose quote package has already been reviewed takes this path, which is most real projects. Proven on live 2026-08-24: a fresh generation of 21CQ30960 (RAMS id 96, project 92, `superseded_by=null` so genuinely new, not a regenerate) produced 11 old-vocabulary hazards including "Confined Spaces", with none of the 4 always-tier or 5 confirm-tier hazards present. Requirement holds on the `runPipeline` path only. Gap closure: Plan 26-07. **CLOSED 2026-08-24 — Plan 26-07.** `reviewedToRisk()` now merges tier-1/3 candidates via `RiskTemplateResolverService::tieredRowsNotAlreadyPresent()`, forwarding a real derived drilling signal (`EquipmentClassifierService::textIndicatesDrilling()`) instead of a hardcoded `false`. Also traced and gated a previously-undocumented SIXTH injection path, `RamsComplianceUpgradeService::addProjectSpecificRisks()`, which the investigation found unconditionally appended 3 old-vocabulary hazard rows on every generation regardless of the tiering flag — the proven cause of the unexplained 7→11 delta. `ReviewedHazardTieringTest` (real seeded DB, real resolver) proves both always/confirm-tier merge and drilling-gated auto-population on `runFromReview()`, in both directions; `HazardResolutionPathGuardTest` is a structural guard against a future untiered path. `RAMS_HAZARD_LIBRARY_TIERING=false` verified as a genuine rollback on both the `runFromReview()` merge point and the sixth path, with legacy behaviour preserved byte-identically on the latter. Requirement now holds on both generation entry points. Manual/live re-verification of RAMS 96 (21CQ30960) remains outstanding per `26-VERIFICATION.md` item 7 — a post-deploy step, not part of this plan's automated scope.) **REOPENED 2026-08-24 (live round 2, RAMS 97).** Tiering now fires on runFromReview, but legacy reviewed-hazard names collide with their library equivalents instead of deduping: RA04 'Slips, Trips & Falls (Same Level)' vs RA08 'Slips, trips and falls'; RA06 'Working in Occupied Premises' vs RA09 'Occupied premises'; RA07 'Confined Spaces' vs RA10 'Restricted access and ceiling voids'. 21 rows with 3 duplicate pairs, and the banned 'Confined Spaces' mislabel still renders in a client-facing document. Dedup matches near-exact names only. D-02's fold mapping was applied to the seeder but never to reviewed-data passthrough. Gap closure: Plan 26-08. **CODE FIX LANDED 2026-08-24 — Plan 26-08, AWAITING LIVE RE-VERIFICATION.** `LegacyHazardNameFoldMap` (16-entry, documented git provenance) is now consumed by `HazardLibraryService::fuzzyMatch()` as the first step, before the existing 3-tier match — the single choke point both `RiskTemplateResolverService::buildHazards()` (explicit-picks path) and `RamsBuilderService::reviewedToRisk()` (reviewed-data path) share via `resolveFromSeeds()`. `reviewedToRisk()` now renames a matched reviewed row to its resolved template's canonical name, replaces controls unconditionally on a genuine rename (never on a case-only casing fix), and runs a same-batch dedup pass before the tiered merge. Proven by `HazardLibraryServiceTest` (fold reaches a real seeded template through the full call chain, including a map/seeder drift guard), `RamsBuilderServiceTest` (rename + controls-replacement + same-batch-collision behaviours), `ReviewedHazardTieringTest::test_real_legacy_vocabulary_folds_dedupes_and_restores_library_scores` (the REAL 7-name live-evidence vocabulary — Working at Height, Manual Handling, Electrical Hazards, Slips/Trips & Falls, Noise and Vibration, Working in Occupied Premises, Confined Spaces — regenerates to zero duplicates and zero "Confined Spaces" rows in output), and `RiskTemplateResolverServiceTest` (fold reaches the SECOND generation entry point too). **This requirement stays OPEN.** It has been closed prematurely twice (2026-08-23, then again implicitly by Plan 26-07's partial fix). Per the round-2 gap-closure plan's explicit gate, HAZ-02 may only be marked complete after live re-verification against real project data (21CQ30960 / RAMS 97 regenerated a third time) confirms zero duplicate names and no "Confined Spaces" row in the live-rendered document — not on automated-test evidence alone.) **CLOSED 2026-08-25 (live round 3, RAMS 98).** Verified on production: 18 rows (was 21), zero duplicate pairs, no `Confined Spaces` row, every row on canonical library vocabulary and library scores. Legacy names folded correctly (`Confined Spaces`->`Restricted access and ceiling voids`, `Electrical Hazards`->`Electrical`, `Slips, Trips & Falls (Same Level)`->`Slips, trips and falls`, `Working in Occupied Premises`->`Occupied premises`). 5 tier-3 rows flagged needs_confirmation. Closed on live evidence after being closed prematurely twice.
- [x] **HAZ-03**: Align scores to the skill's typical values, including residual severity held at initial severity where the skill does so (Working at Height residual `1×4`, not `2×3`). **REOPENED 2026-08-24 (live round 2, RAMS 97).** Working at Height renders 3x3 -> 2x2 on the runFromReview path, not the required 1x4: the stale legacy reviewed row wins over the library default. The legacy scores are not engineer judgement, they are old MANDATORY_KEYWORDS baseline output saved in the project package in August. Needs the score_reviewed rule (library default wins where score_reviewed is false). Gap closure: Plan 26-08. **CLOSED 2026-08-24 — Plan 26-08.** `reviewedToRisk()`'s score precedence is now gated on `score_reviewed`: `true` keeps the existing gap-fill-only behaviour (engineer values win); `false` OR ABSENT sets all four score fields unconditionally from the matched library template. `ReviewedHazardTieringTest::test_real_legacy_vocabulary_folds_dedupes_and_restores_library_scores` proves the named checkable claim with a fixture using the real legacy vocabulary and no `score_reviewed` key present at all (not merely `false`): Working at Height regenerates to `3x4 -> 1x4`, restored from stale `3x3 -> 2x2` reviewed_data. `test_reviewedToRisk_score_reviewed_false_or_absent_defers_to_library_scores` (unit) proves the same rule at the mechanism level, and the sibling `test_reviewedToRisk_prefers_row_numeric_scores_over_library_match` proves HAZ-04 non-regression (`score_reviewed=true` keeps engineer values). Provable by automated test, satisfying this plan's requirements gate for HAZ-03.
- [x] **HAZ-04**: Typical scores are **defaults a user or the model adjusts**, never silently applied — per `PORTING-NOTES.md`: *"Do not let the app apply the typical scores silently."*

### Out of scope for v3.0 (deferred to v3.1+)

Deliberately excluded to keep the document-quality core shippable:

- Hold points as first-class objects (owner / state / blocking) — `PORTING-NOTES.md` calls this the single biggest upgrade over the skill; it is new capability, not parity
- Site-level inheritance (asbestos register, access, welfare, A&E per site) — note: GATE-12 (named A&E must be real) wants exactly this kind of per-site data; Phase 29 must scope around its absence
- Revision letters, supersede handling and diffing between revisions
- Persisting the source JSON as an audit trail
- Dynamic section cross-reference resolution (`§6.4` breaking when optional sections are omitted)
- Toolbox-talk capture surface with signatures
- Making `itIntegration` and similar Teams-Rooms-shaped sections conditional on activity

### Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| GATE-01 | Phase 30 | Pending (code-complete 2026-09-13, Plan 30-03; shares `structural_gates_enabled`/`RAMS_STRUCTURAL_GATES` flag with GATE-02/GATE-04; disarmed per D-03 pending `30-MEASUREMENT.md`) |
| GATE-02 | Phase 30 | Pending (code-complete 2026-09-13, Plan 30-03; shares `structural_gates_enabled`/`RAMS_STRUCTURAL_GATES` flag with GATE-01/GATE-04; disarmed per D-03 pending `30-MEASUREMENT.md`) |
| GATE-03 | — | Shipped (260817-r5e) |
| GATE-04 | Phase 30 | Pending (code-complete 2026-09-13, Plan 30-06; shares `structural_gates_enabled`/`RAMS_STRUCTURAL_GATES` flag with GATE-01/GATE-02; disarmed per D-03 pending `30-MEASUREMENT.md`) |
| GATE-05 | Phase 31 | Pending |
| GATE-06 | Phase 28 | Complete |
| GATE-07 | Phase 28 | Complete |
| GATE-08 | — | Shipped (260817-r5e) |
| GATE-09 | Phase 27 | Complete |
| GATE-10 | Phase 31 | Pending |
| GATE-11 | Phase 29 | Pending (corrected 2026-09-12 — code-complete, disarmed; shares `cdm_ae_gate_enabled` flag with GATE-12) |
| GATE-12 | Phase 29 | Pending |
| GATE-13 | Phase 30 | Pending (added 2026-08-25; Plan 30-07 landed 2026-09-13 — code-complete and tested, ships built-but-disarmed per D-02, armed in Phase 31) |
| GATE-14 | Phase 30 | Pending (added 2026-08-25; Plan 30-08 landed 2026-09-13 — code-complete and tested, ships as WARN-tier, disarmed per D-03) |
| RULE-01 | Phase 28 | Complete |
| RULE-02 | Phase 27 | Complete (Plan 27-02, 2026-08-26 — bands resolved via DisplayLiftPolicy; extended to worksheet + method-statement fallback by Plan 27-04, 2026-08-26) |
| RULE-03 | Phase 27 | Complete (Plan 27-02, 2026-08-26 — decommission-scope scan appends wall-mount-removal statement) |
| RULE-04 | Phase 31 | Pending |
| RULE-05 | Phase 31 | Pending |
| RULE-06 | Phase 28 | Complete |
| RULE-07 | Phase 29 | Complete |
| RULE-08 | Phase 29 | Complete |
| RULE-09 | Phase 28 | Complete (Plan 28-05 shipped the forward path, Plan 28-07 backfilled already-reviewed documents, 2026-09-06; migration not yet run against production — Plan 28-08 owns deploy) |
| RULE-10 | Phase 28 | Complete (Plan 28-05, 2026-09-06 — end-to-end regression lock, no new trigger needed) |
| RULE-11 | Phase 31 | Pending (added 2026-08-25; **reassigned Phase 28 → Phase 31 on 2026-09-05** — it was assigned to Phase 28 but covered by none of that phase's success criteria. See `.planning/phases/28-ppe-ceiling-electrical-boundary-house-rules/28-CONTEXT.md` D-09; now carried by ROADMAP Phase 31 criterion 5) |
| RULE-12 | Phase 27 | Pending — PARTIAL (Plan 27-02, 2026-08-26: branch-order clause closed, weight-derivation clause deferred — see 27-02-SUMMARY.md) |
| HAZ-01 | Phase 26 | Complete |
| HAZ-02 | Phase 26 | Complete (verified live 2026-08-25, RAMS 98 — 18 rows, zero duplicates, no Confined Spaces) |
| HAZ-03 | Phase 26 | Complete (score_reviewed-gated precedence, Plan 26-08) |
| HAZ-04 | Phase 26 | Complete |

---

## Milestone v2.0 Requirements

### Phase 21 — Device Port Catalog + Stencil Cache

Foundation. Every hardware item that appears in a project's equipment_list gets a stencil — auto-generated as a generic placeholder for uncatalogued parts (Tier 1), promotable to a properly-curated card (Tier 2 in Phase 24). Cross-project caching via `firstOrCreate` on part_number.

- [x] **DRAW-31**: `device_ports` table — per-device port metadata: `label`, `side` (left/right), `connector_type` (HDMI/USB-A/USB-B/USB-C/RJ45/RS-232/3.5mm/XLR/PHX/etc.), `signal_type` (audio/video/control/network/USB/power), `sort_order`
- [x] **DRAW-32**: `device_stencils` table — `part_number` (unique), `manufacturer`, `model`, `display_name`, `mxgraph_xml`, `logo_svg`, `source` enum (auto-generated / engineer-curated / ai-extracted)
- [x] **DRAW-33**: Hand-curated seed pack: top 50 devices from last 12 months of 21CAV quote volume — Crestron RMC4 / Sony FW-displays / ClickShare Bar Pro / Cisco SF300 / Bogen NQ-* / Sennheiser TC mics / Netgear M4250 / Q-SYS Core / etc.
- [x] **DRAW-34**: Auto-generic placeholder stencil for any uncatalogued `part_number` — rectangle with manufacturer+model+name, no port detail. `firstOrCreate` caches per part_number for cross-project reuse.
- [x] **DRAW-35**: Manufacturer logo glyphs (inline SVG) for top 20 brands present in the seed pack
- [x] **DRAW-36**: `Project::devicesWithStencils()` accessor — returns equipment_list hardware items joined to device_stencils, ready for the renderer

### Phase 22 — Cable Schedule with Port-Level FKs

Enables port-to-port cable routing in the renderer. Existing cable_schedule_items become typed via FKs to source_port and dest_port. Backfill where unambiguous (e.g. "Bar to Display - HDMI" with a single HDMI port on each side).

- [x] **DRAW-37**: `cable_schedule_items.source_device_id`, `source_port_id`, `dest_device_id`, `dest_port_id` FK columns (nullable for legacy rows)
- [x] **DRAW-38**: Cascading dropdown UI on cable schedule edit: room → source device → source port → dest device → dest port; client-side filtering by signal_type compatibility
- [x] **DRAW-39**: Connector-compatibility validation at form submit — HDMI must terminate on HDMI, not RJ45; warning rather than hard block (engineer override allowed with note)
- [x] **DRAW-40**: Auto-derive port FKs from quote `cable_list` "X to Y" naming where each side has exactly one matching connector (deterministic pass; fallback to nullable when ambiguous)
- [x] **DRAW-41**: One-shot backfill command for existing cable_schedule_items — populates port FKs where unambiguous, leaves nullable where ambiguous

### Phase 22.1 — RAMS Scope/Room-Data Consolidation

Eliminate field-duplication across the 3-stage RAMS pipeline (`form_data` → `reviewed_data` → `generated_data`). The 2026-05-13 audit identified 5 overlapping "scope/works/space narrative" fields at 3 granularities stored in 5 different JSON locations with inconsistent fallback chains. This phase keeps `generated_data` shape backward-compatible (already-rendered RAMS docs unaffected) but consolidates the canonical source of truth, deprecates redundant fields with a backfill, removes dead-path code, and surfaces previously-invisible AI prose for engineer review.

- [x] **DATA-01**: Single canonical scope location — a project-wide scope edit propagates to ONE canonical JSON location (`reviewed_data.scope_of_works`). The other 4 storage paths (`form_data.works_description`, `reviewed_data.method_statement_notes`, `reviewed_data.project.overview`, `extracted_data.overview` auto-seed to `Project.works_description`) are deprecated with backfill where data must be preserved.
- [x] **DATA-02**: Per-room narrative carries exactly TWO text fields — `overview` (engineer-typed prose, the human source of truth) and `works_summary` (AI install-action bullets). The legacy `summary`, `description`, and `scope` fields are removed from the `RamsReviewDataService::normaliseRoomOverviews()` schema. Existing data preserved via the DATA-04 backfill.
- [x] **DATA-03**: Five dead-path files/code paths removed per the 2026-05-13 audit — `app/Services/RamsGeneratorService.php` (legacy alternate generator), `app/Core/AI/Prompts/RamsPrompt.php` (would violate CLAUDE.md AI-only-for-formatting constraint if called), `app/Core/AI/Prompts/WorksBulletsPrompt.php` (no remaining consumer), the `reviewed_data.project.overview` round-trip in `RamsReviewDataService::normaliseProject()` line 113, and the project-wide `works_bullets` textarea on `project-packages/review.blade.php` lines 449-469.
- [x] **DATA-04**: Backfill artisan command `php artisan rams:backfill-room-overview-summary` populates `room_overviews[*].works_summary` from any non-empty legacy `summary` field. Dry-run-default with `--apply` flag (mirrors Phase 22 `cables:backfill-port-fks` pattern). Idempotent. Reports 4 outcome categories per row: `backfilled` / `already-set` / `both-set-no-action` / `neither-set`.
- [x] **DATA-05**: Byte-equivalence golden-file regression test in `tests/Feature/Rams/RamsRenderRegressionTest.php` asserts existing `reviewed_data` records render byte-identical PDFs before and after the cleanup. Uses `hash_file('sha256', $path)` (Phase 22 WR-02 convention). Skips cleanly via `class_exists` / `is_file($binary)` guards when puppeteer / D2 binaries absent (Phase 22 skip pattern).

### Phase 23 — XTEN-AV-Style Renderer

The visual deliverable. Custom device-card stencils with port rails, port-to-port cable routing with signal-type colours and cable IDs, sub-room zones (RACK / CEILING / etc), title block, sheet border. Output renders in the draw.io embed from spike `260509-ibx`.

- [ ] **DRAW-42**: Custom device-card stencil layout — manufacturer logo (top), generic name (centre), model number (bottom), port rails (inputs left, outputs right), connector glyphs per port. Matches XTEN-AV reference visual
- [ ] **DRAW-43**: Port-to-port cable routing — renderer reads cable_schedule_items.source_port_id + dest_port_id and draws the cable from one stencil's exact port to the other's
- [ ] **DRAW-44**: Signal-type colour coding — `audio` purple, `video` purple, `control` blue, `network` blue, `USB` yellow/orange, `speaker/SPOUT` green. Configurable in `config/drawings.php`
- [ ] **DRAW-45**: Cable ID labels rendered along the cable midpoint (e.g. `LAN-1004`, `USB-1000`, `SPOUT-1000` matching cable_schedule numbering)
- [ ] **DRAW-46**: Sub-room zones — dashed-bordered groups within a room (RACK / CEILING / RECEPTION / etc). Auto-derived from device category as default; engineer can override per device
- [ ] **DRAW-47**: Multi-page paginator — system overview (sheet 1) + audio subsystem (sheet 2) + video subsystem (sheet 3) + control subsystem (sheet 4) when scope warrants
- [ ] **DRAW-48**: Standardised title block — project / client / designed-by / drawn-by / checked-by / sheet number / date / revision. Renders on every page
- [ ] **DRAW-49**: Dashed sheet border around every page

### Phase 24 — Stencil Curation UI

Engineer/PM-facing UI to upgrade auto-generic stencils to proper ones. Drag handles for ports, label inputs, manufacturer-logo upload, save back to `device_stencils.mxgraph_xml`. Once curated, every project using that part_number gets the upgraded version automatically.

- [x] **DRAW-50**: Admin route `/admin/device-stencils` — list view with filter by source (auto-generated / curated / ai-extracted) and search by part_number
- [x] **DRAW-51**: Stencil edit screen — open the auto-generic placeholder in an editor, drag connectors onto the rails, label them, save (Plan 24-01 shipped the mxgraph_xml/constraint regeneration contract this screen depends on — not yet checked complete; the editor UI itself ships across Plans 24-04/24-05)
- [x] **DRAW-52**: Manufacturer logo upload (PNG/SVG) per stencil — stored alongside the stencil's `mxgraph_xml`
- [x] **DRAW-53**: "Promote to curated" action flips `source` enum from `auto-generated` → `engineer-curated`. Cross-project propagation is automatic via the cache lookup

### Phase 25 — AI Assist (datasheet extraction + chat-edit)

Optional polish layer. AI helps with the long tail of devices the seed pack and curation didn't reach — drops a datasheet PDF, AI extracts ports, engineer reviews and confirms. Chat-edit operations on rendered drawings (move device to zone, relabel, add cable). All AI operations bounded by canonical project data — no inventing equipment / cables / rooms.

- [ ] **DRAW-54**: `DevicePortExtractorService` — Claude vision over manufacturer datasheet PDFs, returns structured port JSON, engineer reviews + approves before persist (stays inside "AI never invents" rule because verified)
- [ ] **DRAW-55**: AI chat-edit operations on a drawing — `move_device_to_zone`, `add_cable_between_ports`, `change_signal_type`, `relabel_device`. Operations bounded by canonical-data validity (can't add a port that doesn't exist on the device)
- [ ] **DRAW-56**: Engineer reviews AI suggestions before they apply — rejection preserves original; acceptance mutates the canvas
- [ ] **DRAW-57**: Bound PDF (from v1.3 Phase 20) replaces D2-based schematic output with the new XTEN-AV-style renderer output for projects whose devices are 80%+ catalogued
- [ ] **DRAW-58**: O&M Manual auto-embed (from v1.3 Phase 17 P03) replaces D2-based PNG with the new renderer's PNG when available

---

## Visual contract

Every PR in this milestone is evaluated against the **XTEN-AV PAGING SYSTEM reference** the user shared 2026-05-09. The reference shows:

- Custom device cards with red border, manufacturer logo top, name + model bottom, port rails on left/right
- Port labels (USB A1, RJ45, PHX, LAN POE+1, etc.) with connector type indicators on the outside edge
- Sub-room zones (RACK, CEILING, PAGING STATION, RECEPTION) as dashed-border groups
- Signal-type-coloured cables (LAN purple/blue, SPOUT green, USB yellow)
- Cable IDs labelled mid-line (LAN-1004, USB-1000, SPOUT-1000)
- Title block at the bottom with project / client / designed-by / drawn-by / checked-by columns
- Dashed sheet border

That's the bar. Phase 23 ships against this contract.

---

## Out of scope for v2.0 (deferred to v2.1+)

- **DWG export** — LibreDWG is GPLv3 (license blocker), Teigha is paid. Defer.
- **Real-time multi-user collaborative editing** — significant infrastructure investment, low immediate ROI.
- **Apple Pencil pressure / tilt** — drawings stay desktop/tablet authoring, not iPad-pencil-native.
- **Mobile-first drawing creation** — engineers create drawings at the desk, not on-site.
- **Custom symbol library editor in-app** — symbols stay in-codebase via the device_stencils table; full library editor is overkill.
- **Floor plans** (DRAW-14..20 from v1.3 backlog) — held for v2.1. Same renderer should work, just needs floor-plan templates + room-shape stencils.

---

## Dependencies (for milestone planning)

- v1.3 shipped (drawings foundation + bound PDF + O&M auto-embed) ✓
- draw.io spike validated (`260509-ibx`) ✓
- 5 hand-coded MTR stencils from spike are seed for Phase 21 catalog ✓
- v1.3 D2-based schematic generator stays running alongside the new renderer; the new renderer takes over for projects with sufficient catalog coverage (DRAW-57)

## Success criteria (milestone-level)

1. A real client project's drawing output, rendered in v2.0, is visually indistinguishable from the XTEN-AV PAGING SYSTEM reference at the device-card and cable-routing level
2. Top-50 device coverage hand-curated by end of Phase 21 — sufficient for 80%+ of recent quote volume to render with full port detail
3. Engineer can drop a new datasheet PDF and AI extracts ports for review (Phase 25 deliverable) — covers the long tail without manual catalog growth
4. Bound PDF + O&M Manual handover swap from D2 output to engineering-grade output when project devices are catalogued
5. v1.3 D2 generator stays usable as a fallback for projects without sufficient catalog coverage — no regression
