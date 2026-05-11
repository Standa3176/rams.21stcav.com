---
milestone: v2.0
milestone_name: Engineering-Grade AV Drawings
last_updated: "2026-05-09"
---

# Roadmap

## Project Reference

See: `.planning/PROJECT.md` (updated 2026-05-09)

**Current milestone:** v2.0 Engineering-Grade AV Drawings. Visual contract: XTEN-AV PAGING SYSTEM reference. Platform: draw.io / mxGraph (validated by spike `260509-ibx`).

## Roadmap Overview

| Milestone | Theme | Phases | Status |
|-----------|-------|--------|--------|
| v1.0 | RAMS MVP | 01–07 | ✅ Shipped — [archive](milestones/v1.0-ROADMAP.md) |
| v1.1 | Operations Dashboard & Notifications | 08–09 (10/11 deferred) | ✅ Shipped 2026-04-25 — [archive](milestones/v1.1-ROADMAP.md) |
| v1.2 | Installation Programme & Field Management | 12–16 | ✅ Shipped 2026-04-25 — [archive](milestones/v1.2-ROADMAP.md) |
| v1.3 | Technical Drawings & Schematics | 17–20 (19 → v2.0) | ✅ Shipped 2026-05-09 — [archive](milestones/v1.3-ROADMAP.md) |
| **v2.0** | **Engineering-Grade AV Drawings** | **21–25** | **🚧 In progress** |
| v1.4 | Client Portal & Project Visibility | 26–29 | 📋 Planned (renumbered after v2.0) |
| v1.5 | Financial & Proposal Engine | 30–33 | 📋 Planned |
| v1.6 | Service & Inventory | 34–37 | 📋 Planned |

---

## 🚧 v2.0 Engineering-Grade AV Drawings (In Progress)

**Milestone Goal:** Auto-generate AV technical drawings at the engineering-grade fidelity of XTEN-AV / D-Tools / Lucidchart. Custom device cards (manufacturer logo + name + model + port rails), port-to-port cable routing, signal-type colour coding, sub-room zones, multi-page paginator with title block, sheet border. Output renders in the draw.io / mxGraph embed validated by spike `260509-ibx`. Visual contract = the XTEN-AV PAGING SYSTEM reference user shared 2026-05-09.

**Reference image:** XTEN-AV PAGING SYSTEM (saved in conversation 2026-05-09). Every PR is evaluated against "does it move us closer to this output?"

**Platform decision:** draw.io / mxGraph self-hosted (Apache 2.0). Spike validated 2026-05-09. Native build was the alternative — saves ~5–7 weeks vs full Konva canvas + custom SVG renderer.

**Phases:** 21–25 (5 phases, ~25-30 plans estimated, ~10–15 weeks)

**Strategy summary:** Tier 1 (auto-generic stencil per part_number) + Tier 2 (engineer-curated catalog growth via UI) combined. AI port extraction (Tier 3) lands as polish in Phase 25. v1.3 D2-based renderer stays usable as fallback for projects without sufficient catalog coverage.

### Phases

- [x] **Phase 21: Device Port Catalog + Stencil Cache** — `device_ports` + `device_stencils` tables; hand-curated top-50 device seed pack; auto-generic placeholder for uncatalogued parts; cross-project caching via `firstOrCreate` on part_number; manufacturer logo glyphs for top 20 brands. Foundation for all other phases. ✅ COMPLETE 2026-05-10 (3/3 plans, ~43 min total exec time).
- [ ] **Phase 22: Cable Schedule with Port-Level FKs** — `source_port_id` + `dest_port_id` columns on `cable_schedule_items`; cascading dropdown UI (room → device → port); connector-compatibility validation; auto-derive from quote `cable_list` "X to Y" naming where unambiguous; one-shot backfill command. Depends on Phase 21. Estimate: 2–3 weeks.
- [ ] **Phase 23: XTEN-AV-Style Renderer** — custom device-card stencils with port rails; port-to-port cable routing; signal-type colour coding (audio/video/control/network/USB); cable ID labels; sub-room zones (RACK / CEILING / etc) auto-derived + engineer-overridable; multi-page paginator (system + audio + video + control sub-sheets); standardised title block; sheet border. Depends on Phase 21+22. Estimate: 2–4 weeks (faster via draw.io vs ~4–5 weeks native).
- [ ] **Phase 24: Stencil Curation UI** — admin route + edit screen for upgrading auto-generic stencils to engineer-curated ones; drag-port handles, label inputs, manufacturer-logo upload; "promote" action flips `device_stencils.source` from auto-generated → engineer-curated; cross-project propagation automatic via cache lookup. Depends on Phase 21. Estimate: 1–2 weeks.
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

### Phase 21 Detail (planned 2026-05-10)

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
**Plans**: TBD — to be planned during `/gsd-plan-phase 22`
**UI hint**: yes (cascading dropdown UI on cable schedule edit; backend command + form changes)
**Canonical refs**:
  - `.planning/phases/21-device-port-catalog-stencil-cache/21-CONTEXT.md` (Phase 21 decisions D-01..D-15 — port catalog contract)
  - `.planning/phases/21-device-port-catalog-stencil-cache/21-01-schema-models-cache-service-SUMMARY.md` (DevicePort model API surface, side/direction enum constants, FK semantics)
  - `.planning/REQUIREMENTS.md` §"Phase 22 — Cable Schedule with Port-Level FKs" (DRAW-37..41 acceptance criteria)
  - Visual contract: XTEN-AV PAGING SYSTEM reference image (conversation 2026-05-09) — port-to-port routing pattern Phase 23 will render from this data

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

### v1.4 Client Portal & Project Visibility
*"Clients see what they need, when they need it"*

- [x] Phase 21: Client Portal — Branded project status page per client/site with secure access (completed 2026-05-10)
- [ ] Phase 22: Document Access — Clients download RAMS, O&M, drawings and certificates from portal
- [ ] Phase 23: Survey & Installation Progress — Live completion percentages per room visible to client
- [ ] Phase 24: Notification & Communication — Client receives updates on project milestones and document availability

### v1.5 Financial & Proposal Engine
*"From pricing rules to signed proposal"*

- [ ] Phase 25: Pricing Engine — Multiplier-based config (HW value x multiplier with min/max), admin+sales accessible
- [ ] Phase 26: Proposal Generator — New client + renewal flows, PDF/DOCX branded output
- [ ] Phase 27: Budget Tracking — Project cost monitoring, margin alerts, forecast vs actual
- [ ] Phase 28: Renewal Workflow — Auto-populate from existing contract hardware, year-on-year escalation

### v1.6 Service & Inventory
*"Post-install lifecycle"*

- [ ] Phase 29: Asset Registry — Track installed equipment as live assets with QR codes per item
- [ ] Phase 30: Service Tickets — Contract search, room/asset select, auto-fill site/contact, callback scheduling
- [ ] Phase 31: PMV Checklists — Per-equipment-type maintenance checks with fault diagnosis and sign-off
- [ ] Phase 32: AI Troubleshooting — QR scan triggers AI-guided device-specific troubleshooting workflow

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
| 999.1. v2.0 Engineering-Grade AV Drawings (incl. floor plans + DXF) | Backlog | 0/0 | Backlog | - |
