# RAMS Platform — Requirements

> Current milestone: **v3.0 RAMS Skill Parity**. v2.0 requirements are preserved
> below; v2.0 is **PARKED** (see MILESTONES.md), not cancelled.

---

## Milestone v3.0 Requirements

**Milestone:** v3.0 RAMS Skill Parity
**Defined:** 2026-08-23
**Source of truth:** the `21cav-rams` Claude skill, supplied by the user 2026-08-23 —
`references/house-rules.md`, `references/hazard-library.md`, `PORTING-NOTES.md`.
Those documents are **settled 21CAV positions**, not proposals. Where the app and
the skill disagree, the skill wins unless the user says otherwise for a specific job.
**Total requirements:** 26 (GATE-01..12 = 12, RULE-01..10 = 10, HAZ-01..04 = 4).
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

- [ ] **GATE-01**: Orphan controls — every method step or hazard control referencing a document, permit or hold point has a matching hazard row AND a matching `clientReqs` entry. Canonical failure: "review the asbestos register" with no asbestos hazard behind it.
- [ ] **GATE-02**: Every area has at least one method step.
- [x] **GATE-03**: Every method step has exactly one `risks` line, and every RA reference resolves to a hazard that exists. *(Shipped 260817-r5e — includes the index-vs-id dangling-reference fix.)*
- [ ] **GATE-04**: Residual score ≤ initial score on every hazard, and residual severity normally unchanged. Flag `s2 < s1` for human review rather than accepting it — controls reduce likelihood, not severity.
- [ ] **GATE-05**: Uniform-scoring detection — if most hazards share the same initial score, the register was assembled from the library rather than the job. Warn.
- [ ] **GATE-06**: FFP2 anywhere → error. House rule is FFP3 with face-fit testing.
- [ ] **GATE-07**: "Confined space" applied to a ceiling void, comms room or riser → error. Not confined spaces under the 1997 Regulations.
- [x] **GATE-08**: Access-equipment contradiction — something excluded in one section and required as a control in another. *(Shipped 260817-r5e — podium steps.)*
- [ ] **GATE-09**: Display lift specified as anything other than two-operative → error.
- [ ] **GATE-10**: COSHH and standards padding — cross-check every COSHH substance and cited standard against the activity list. Named offenders: BS EN 60849, BS 8492, HSG 47, laser safety on a job with no laser, soldering flux with no soldering.
- [ ] **GATE-11**: CDM duty-holder table left as "[To be confirmed]" on an occupied-premises job → error. There is a settled position.
- [ ] **GATE-12**: Named A&E must be a real A&E. A subcontractor RAMS once named a hospital whose A&E closed in 2014.

### Group B — House rules enforced in code

From `references/house-rules.md`. Settled positions applied without asking.

- [ ] **RULE-01**: FFP3 with face-fit testing replaces FFP2 wherever respiratory PPE is specified. `config/rams_tier1.php:129` currently contradicts `:286` in the same file.
- [ ] **RULE-02**: All displays are two-operative team lifts regardless of panel size. Never four-operative; never conditional on screen size. Mechanical aids are additional, not a substitute for the second person.
- [ ] **RULE-03**: Removal of a display *from* an existing wall mount is stated explicitly as the highest-risk lift on a strip-out — controlled to lowest practicable height, one operative each side, before release from the mount.
- [ ] **RULE-04**: Standards table cites only what the job involves. No library padding.
- [ ] **RULE-05**: COSHH lists only substances actually carried.
- [ ] **RULE-06**: Restricted-access hazard is titled "Restricted access and ceiling void working" — never "confined space".
- [ ] **RULE-07**: CDM duty-holder note states the settled sole-Contractor position rather than "[To be confirmed]".
- [ ] **RULE-08**: Nearest A&E named with address; "to be identified at site induction" is not acceptable output.
- [ ] **RULE-09**: Electrical scope boundary stated — works terminate at existing socket or client data outlet, no alteration to fixed installation, no live working.
- [ ] **RULE-10**: Ceiling load stated as supported from structural soffit or purpose-designed mount kit — never suspended grid, pipework or sprinkler pipe.

### Group C — Hazard library reconciliation

From `references/hazard-library.md` (18 hazards, each with an explicit "Include when").
The app has 11, applied unconditionally.

- [x] **HAZ-01**: Port the 8 hazards present in the skill and absent from the app — Noise and vibration, Restricted access and ceiling voids, Low voltage AV connections, Asbestos-containing materials, Vehicle and plant movement, Lone and small-team working, Fire and evacuation, Decommissioning and WEEE.
- [ ] **HAZ-02**: Each hazard carries an **include-when** condition; a hazard is included only when the job meets it. This is the inversion — register starts empty and is added to. (Plan 26-02 landed the tiered evaluation logic; the requirement is fully met only once Plan 26-04 wires it into the live RAMS pipeline.)
- [x] **HAZ-03**: Align scores to the skill's typical values, including residual severity held at initial severity where the skill does so (Working at Height residual `1×4`, not `2×3`).
- [ ] **HAZ-04**: Typical scores are **defaults a user or the model adjusts**, never silently applied — per `PORTING-NOTES.md`: *"Do not let the app apply the typical scores silently."*

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
| GATE-01 | Phase 30 | Pending |
| GATE-02 | Phase 30 | Pending |
| GATE-03 | — | Shipped (260817-r5e) |
| GATE-04 | Phase 30 | Pending |
| GATE-05 | Phase 31 | Pending |
| GATE-06 | Phase 28 | Pending |
| GATE-07 | Phase 28 | Pending |
| GATE-08 | — | Shipped (260817-r5e) |
| GATE-09 | Phase 27 | Pending |
| GATE-10 | Phase 31 | Pending |
| GATE-11 | Phase 29 | Pending |
| GATE-12 | Phase 29 | Pending |
| RULE-01 | Phase 28 | Pending |
| RULE-02 | Phase 27 | Pending |
| RULE-03 | Phase 27 | Pending |
| RULE-04 | Phase 31 | Pending |
| RULE-05 | Phase 31 | Pending |
| RULE-06 | Phase 28 | Pending |
| RULE-07 | Phase 29 | Pending |
| RULE-08 | Phase 29 | Pending |
| RULE-09 | Phase 28 | Pending |
| RULE-10 | Phase 28 | Pending |
| HAZ-01 | Phase 26 | Complete |
| HAZ-02 | Phase 26 | Pending (logic landed Plan 26-02; wiring is Plan 26-04) |
| HAZ-03 | Phase 26 | Complete |
| HAZ-04 | Phase 26 | Pending |

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
