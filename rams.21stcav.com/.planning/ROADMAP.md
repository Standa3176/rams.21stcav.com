---
milestone: v1.3
milestone_name: Technical Drawings & Schematics
last_updated: "2026-04-30"
---

# Roadmap

## Project Reference

See: `.planning/PROJECT.md` (updated 2026-04-30)

**Current milestone:** v1.3 — Technical Drawings & Schematics

## Roadmap Overview

| Milestone | Theme | Phases | Status |
|-----------|-------|--------|--------|
| v1.0 | RAMS MVP | 01–07 | ✅ Shipped — [archive](milestones/v1.0-ROADMAP.md) |
| v1.1 | Operations Dashboard & Notifications | 08–09 (10/11 deferred) | ✅ Shipped 2026-04-25 — [archive](milestones/v1.1-ROADMAP.md) |
| v1.2 | Installation Programme & Field Management | 12–16 | ✅ Shipped 2026-04-25 — [archive](milestones/v1.2-ROADMAP.md) |
| v1.3 | Technical Drawings & Schematics | 17–20 | 🚧 In progress |
| v1.4 | Client Portal & Project Visibility | 21–24 | 📋 Planned |
| v1.5 | Financial & Proposal Engine | 25–28 | 📋 Planned |
| v1.6 | Service & Inventory | 29–32 | 📋 Planned |

---

## ✅ v1.2 Installation Programme & Field Management — SHIPPED 2026-04-25

5 phases, 21 plans — full installation delivery loop from auto-generated task list → mobile field view → time tracking → commissioning sign-off with snagging PDF. See [milestones/v1.2-ROADMAP.md](milestones/v1.2-ROADMAP.md) for full details.

---

## 🚧 v1.3 Technical Drawings & Schematics (In Progress)

**Milestone Goal:** Generate AV technical drawings — schematics, rack elevations, and floor plans — from the same canonical project data that powers RAMS, O&M, and worksheets. Internal engineers view drawings on tablets and print during install; clients receive them as part of the O&M Manual handover. Drawings derive from canonical project data only — AI may assist with layout but never invents equipment, cables, or rooms.

**Phases:** 17–20 (4 phases, ~10 plans estimated)

### Phases

- [x] **Phase 17: System Schematics + Shared Foundations** — Auto-generate per-room signal-flow SVG schematics via D2 CLI; lays the `project_drawings` table, model, policy, storage type, job pattern, and `waitForJs` PDF extension that Phases 18–20 depend on (completed 2026-05-01)
- [ ] **Phase 18: Rack Elevations** — 1U-precise rack drawings from equipment list with U-height + ventilation data; drag-reorder editor + per-rack totals footer
- [ ] **Phase 19: Floor Plans (Konva)** — In-browser canvas drawing tool with walls/doors/windows/equipment glyphs; anchor-wall auto-place; mandatory Day-1 Browsershot+Konva PDF spike with documented fallback
- [ ] **Phase 20: Drawing Export Pipeline + O&M Integration** — Bound multi-page project PDF, drawing register, sheet numbering, revision tracking, status state machine; embeds drawings in O&M handover via PNG flatten; DXF for floor plans as stretch goal

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
**Goal**: Engineers can auto-generate 1U-precise rack elevations from rack-mounted equipment (with U-height + ventilation metadata), reorder items by drag, and download per-rack PDF/SVG with totals footer (weight, current, BTU, U-utilisation).
**Depends on**: Phase 17 (foundations: `project_drawings` table, model, policy, `TYPE_DRAWING` storage, `BuildSchematicJob` pattern, `DrawingReadyMail`, edit-adapter pattern)
**Requirements**: DRAW-07, DRAW-08, DRAW-09, DRAW-10, DRAW-11, DRAW-12, DRAW-13
**Success Criteria** (what must be TRUE):
  1. User can click "Generate Rack Elevation" and see a 1U-precise rack drawing with U-numbered side rail and equipment ordered to AVIXA convention (PDU bottom → switches → DSP → amps → patches/IO top)
  2. User can drag-reorder equipment within a rack and lock items to specific U-positions; locks survive regeneration
  3. User can manage multiple racks per project (no single-rack limit) — each rack renders on its own page
  4. User can read per-rack totals (weight, current draw, BTU, U-utilisation) in the footer of every rack drawing
  5. User can download each rack as PDF or SVG; missing U-heights surface as "U-height unknown" warnings, never as silent 1U guesses
**Plans**: 2 plans (estimated)
**UI hint**: yes
**Canonical refs**:
  - `.planning/research/SUMMARY.md`
  - `.planning/research/STACK.md` §1.2 Rack Elevations (custom Blade SVG)
  - `.planning/research/FEATURES.md` Phase 18 — Rack Elevations
  - `.planning/research/ARCHITECTURE.md` §4.2 Phase 18 render pipeline
  - `.planning/research/PITFALLS.md` CRIT-06 (U-height accuracy)

### Phase 19: Floor Plans (Konva)
**Goal**: Engineers can draw a per-room floor plan in the browser (walls, doors, windows, text), auto-place equipment glyphs by designating an anchor wall, and download each plan as PDF or SVG with dimension lines, scale bar, north arrow, and mount-height annotations. Day-1 Browsershot+Konva PDF spike is mandatory; a documented fallback (client-side `stage.toSVG()` → embed SVG) covers the spike-failure case.
**Depends on**: Phase 17 (foundations + drawing edit-adapter pattern)
**Requirements**: DRAW-14, DRAW-15, DRAW-16, DRAW-17, DRAW-18, DRAW-19, DRAW-20
**Success Criteria** (what must be TRUE):
  1. User can draw a room floor plan in-browser using wall, door, window, and text primitives with snap-to-grid at 50 mm / 100 mm / 250 mm
  2. User can open a per-room canvas with an equipment glyph palette filtered to that room's equipment (from canonical project data) and auto-place equipment by designating an anchor wall
  3. User can read every floor plan with dimension lines, a scale bar, a north arrow, and mount-height annotations on each equipment glyph
  4. User can download each floor plan as PDF or SVG; tablet/iPad users can view (and verify auto-place) without the page zooming on touch
  5. User canvas edits auto-save reliably across navigation, tab-close, and tab-background events (no lost work)
**Plans**: 3 plans (estimated; Plan 1 includes the mandatory Day-1 spike)
**UI hint**: yes
**Canonical refs**:
  - `.planning/research/SUMMARY.md`
  - `.planning/research/STACK.md` §2 Canvas Drawing Library (Konva)
  - `.planning/research/ARCHITECTURE.md` §5 Frontend Integration (Konva + Alpine + separate Vite entry)
  - `.planning/research/PITFALLS.md` MOD-04 (auto-save), MOD-05 (canvas-JSON storage), MOD-06 (bundle bloat), MOD-07 (multi-device truth), MOD-08 (iPad touch), MOD-09 (HiDPI/Retina) + spike contingency note in `SUMMARY.md` GAP-3

### Phase 20: Drawing Export Pipeline + O&M Integration + DXF stretch
**Goal**: Engineers can produce a single bound multi-page PDF per project (cover sheet + drawing register + paginated drawings) with configurable sheet numbering and standard title blocks; download all drawings as a ZIP bundle; and ship drawings inside the O&M Manual handover via PNG embed. Production hardening (dedicated drawings queue, smoke test, font loading, license audit) lands here. DXF export for floor plans is a stretch goal.
**Depends on**: Phase 17 (foundations) + Phase 18 (at least one drawing kind to render) + Phase 19 (floor plans for the DXF stretch)
**Requirements**: DRAW-21, DRAW-23, DRAW-28, DRAW-29
**Success Criteria** (what must be TRUE):
  1. User can download a single bound multi-page PDF per project that opens with a cover sheet, a drawing register table (sheet number / title / revision / date), and the paginated per-section drawings (floor plans → schematics → rack elevations)
  2. User can configure sheet numbering per project (default `AV-101` floor plans, `AV-201` schematics, `AV-301` racks) and see the chosen numbers on every drawing's title block
  3. User can download a ZIP bundle of all of a project's drawings (PDF + SVG + PNG) in one action
  4. User who opens an O&M Manual sees a "Drawings" section with each ready drawing embedded as a high-resolution PNG, one drawing per page, matching the bound PDF
  5. *(Stretch)* User can export floor plans as DXF for architect/MEP CAD handoff (AutoCAD LT 2024+ / BricsCAD verified); if the DXFighter spike fails the milestone ships without DXF and the gap is documented
**Plans**: 2 plans (estimated; Plan 2 includes O&M integration, production hardening, and the DXF spike)
**UI hint**: yes
**Canonical refs**:
  - `.planning/research/SUMMARY.md`
  - `.planning/research/PITFALLS.md` CRIT-03 (queue OOM), CRIT-04 (Chrome version drift), MOD-01 (DXF/DWG GPL trap), MOD-10 (O&M references), MOD-12 (notification timing)
  - `.planning/quick/260427-qvr-migrate-pdf-rendering-to-browsershot/260427-qvr-SUMMARY.md` — Browsershot deployment runbook precedent

---

## Future Milestones (Outline)

### v1.4 Client Portal & Project Visibility
*"Clients see what they need, when they need it"*

- [ ] Phase 21: Client Portal — Branded project status page per client/site with secure access
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

**Goal:** Captured for future planning — produce Lucidchart/Visio-grade auto-generated AV schematics, with port-aware device cards, port-to-port cable routing, Konva canvas editor for engineer overrides, and AI generate-from-project + chat-edit operations. Companion outputs: rack elevations + floor plans at the same engineering-grade fidelity. Reference: Duke "Extron Concept" Lucidchart drawing the user shared.

**Requirements:** TBD (full plan in `.claude/projects/.../memory/v2_engineering_grade_drawings_plan.md`)

**Plans:** 3/3 plans complete

**Notes:**
- Run a 1-week build-vs-buy spike (Lucidchart API / draw.io embed / XTEN-AV / D-Tools) BEFORE committing to native build — could compress 14-19 weeks → 3-4 weeks of integration work
- Wave 1 (port catalog + cable FKs) parallelisable across 2 sessions (~30% time saving)
- Phase 23 (renderer) and Phase 25 (AI) cannot parallelise — depend on prior waves
- v1.3 ships at "passable basic" — this milestone is the engineering-deliverable-grade upgrade

Plans:
- [ ] TBD (promote with /gsd-review-backlog when ready)

---

## Progress

| Phase | Milestone | Plans Complete | Status | Completed |
|-------|-----------|----------------|--------|-----------|
| 17. System Schematics + Shared Foundations | v1.3 | 3/3 | Complete    | 2026-05-02 |
| 18. Rack Elevations | v1.3 | 0/2 | Not started | - |
| 19. Floor Plans (Konva) | v1.3 | 0/3 | Not started | - |
| 20. Drawing Export + O&M + DXF stretch | v1.3 | 0/2 | Not started | - |
| 999.1. v2.0 Engineering-Grade AV Drawings | Backlog | 0/0 | Backlog | - |
