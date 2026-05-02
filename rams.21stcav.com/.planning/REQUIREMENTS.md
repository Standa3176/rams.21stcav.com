# RAMS Platform — v1.3 Requirements

**Milestone:** v1.3 Technical Drawings & Schematics
**Defined:** 2026-05-01
**Phases:** 17–20
**Total requirements:** 30

---

## Milestone v1.3 Requirements

### Schematics (Phase 17)

- [x] **DRAW-01**: User can auto-generate a signal-flow schematic per room from canonical project data (equipment + cable schedule)
- [x] **DRAW-02**: Schematic uses signal-type colour coding (audio / video / control / network / USB)
- [x] **DRAW-03**: Cable IDs and port labels on the schematic match the cable schedule character-for-character
- [x] **DRAW-04**: Schematic uses an AVIXA-style symbol library (sources, destinations, switchers, DSPs, amps, displays, control processors)
- [x] **DRAW-05**: User can edit an auto-generated schematic; manual edits are preserved on regenerate (lock-on-edit + archive-prior-version)
- [x] **DRAW-06**: User can export schematic as PDF and SVG

### Rack Elevations (Phase 18)

- [x] **DRAW-07**: User can auto-generate a rack elevation per rack from rack-mounted equipment *(Phase 18 ships engineer-builds-manually flow per CONTEXT.md decision; auto-fill keyword classifier deferred to v1.3.x quick task or v2.0)*
- [x] **DRAW-08**: Rack elevation renders at 1U-precise scale with U-numbered side rail
- [~] **DRAW-09**: Default equipment ordering follows AVIXA convention (PDU bottom → switches → DSP → amps → patches/IO top) *(PARTIAL — palette ordering at resolver layer + bottom-up u_position rendering shipped; full AVIXA auto-place algorithm deferred per CONTEXT.md to v1.3.x or v2.0)*
- [x] **DRAW-10**: User can drag-reorder equipment in a rack with per-item U-position lock
- [x] **DRAW-11**: Multiple racks per project are supported (no single-rack limit)
- [x] **DRAW-12**: Rack elevation footer shows totals — weight, current draw, BTU, U-utilisation
- [x] **DRAW-13**: User can export rack elevation as PDF and SVG

### Drawing Export & Cross-cutting (Phase 20 + foundations in Phase 17)

- [ ] **DRAW-21**: User can generate a single bound multi-page PDF per project (cover + drawing register + paginated per-section sheets)
- [x] **DRAW-22**: Every sheet renders a standard title block (project ref, client, drawn-by, revision, date)
- [ ] **DRAW-23**: Sheet numbering is configurable per project (default `AV-101`, `AV-201`, `AV-301` per drawing kind)
- [x] **DRAW-24**: Each drawing supports revision tracking (R0, R1, R2…)
- [x] **DRAW-25**: Each drawing has a status enum — draft / for review / approved / superseded
- [x] **DRAW-26**: Drawings are included in the O&M Manual handover via PNG embed (no SVG-in-DOCX)
- [x] **DRAW-27**: User can download an individual drawing as PDF, SVG, or PNG
- [ ] **DRAW-28**: User can download a ZIP bundle of all drawings for a project
- [x] **DRAW-30**: User can edit/modify any drawing via AI chat ("Edit via Chat" pattern, mirroring existing RAMS/O&M/Worksheet adapters). Chat operations are constrained to layout, positioning, grouping, formatting, and styling — within canonical project data. AI cannot invent or add equipment/cables/rooms that aren't already in the project source data. *(Scaffolding only in Phase 17 — functional schematic chat deferred to v2.0 alongside engineering-grade renderer.)*

### Deferred to v2.0 (backlog 999.1)

The following requirements were originally scoped for v1.3 Phase 19 but moved to v2.0 to avoid building work that the engineering-grade upgrade will replace. The Konva floor-plan editor + Browsershot+Konva PDF round-trip have the highest throwaway risk if v2.0 takes the build-vs-buy "integrate with Lucidchart/draw.io" path; v2.0 also needs to build floor plans properly with port catalog + zones anyway. See `.planning/phases/999.1-v2-engineering-grade-av-drawings/` and the memory note `v2_engineering_grade_drawings_plan.md`.

- ~~**DRAW-14**~~: Floor plan in-app drawing tool (Konva-based canvas) — **moved to v2.0**
- ~~**DRAW-15**~~: Snap-to-grid 50/100/250 mm — **moved to v2.0**
- ~~**DRAW-16**~~: Per-room canvas with equipment glyph palette — **moved to v2.0**
- ~~**DRAW-17**~~: Anchor-wall auto-place — **moved to v2.0**
- ~~**DRAW-18**~~: Floor plan dimension lines + scale bar + north arrow — **moved to v2.0**
- ~~**DRAW-19**~~: Mount-height annotations — **moved to v2.0**
- ~~**DRAW-20**~~: Floor plan PDF/SVG export — **moved to v2.0**
- ~~**DRAW-29**~~: Floor plan DXF export (was Phase 20 stretch) — **moved to v2.0** (becomes part of v2.0 Phase 20 export pipeline naturally)
- **DRAW-05** schematic editor (full Konva-based) — Phase 17 ships scaffolding only (lock-on-edit prompt UX); the functional editor was originally planned for Phase 19 alongside Konva. Now deferred to v2.0 alongside the port-aware schematic renderer.
- **DRAW-30** functional schematic chat — Phase 17 ships adapter scaffolding; functional chat was originally planned for Phase 19 alongside the editor. Now deferred to v2.0.
- [x] **DRAW-30**: User can edit/modify any drawing via AI chat ("Edit via Chat" pattern, mirroring existing RAMS/O&M/Worksheet adapters). Chat operations are constrained to layout, positioning, grouping, formatting, and styling — within canonical project data. AI cannot invent or add equipment/cables/rooms that aren't already in the project source data.

---

## Future Requirements (deferred to v1.3.x or v1.4+)

- DXF export for rack elevations and schematics (only floor plans get DXF in v1.3 stretch)
- Architect's PDF as background reference for floor plan tracing
- Coverage cones / heat maps (e.g. mic pickup zones, camera FoV)
- Conflict detection (equipment overlap, cable-route clashes)
- Reflected ceiling plan (RCP) as a separate drawing kind
- Multi-stage drawing approval workflow (engineer → senior → client)
- Per-revision diff overlay PDF (visual changelog between R0 and R1)
- Per-rack PDU outlet mapping (which equipment plugs into which outlet)
- Formal AVIXA-compliance audit pass (if a client RFP requires strict compliance)

---

## Out of Scope

- **True DWG export** — LibreDWG is GPLv3 (license blocker); Teigha is paid; no acceptable OSS path
- **Apple Pencil tilt/pressure support** — Konva limitation; not worth the scope
- **AI-generated schematics from natural language** (free-form design) — violates "AI never invents content" core constraint. AI chat (DRAW-30) is constrained to layout operations on existing data only.
- **Real-time multi-user collaborative editing** — single-user-at-a-time matches existing workflow
- **Mobile-first drawing creation** — drawings are desktop/tablet authoring; mobile field-view stays read-only
- **Custom symbol library editor in-app** — symbol pack is in-codebase SVGs; updates via PR

---

## Constraints (carried from v1.0/v1.2)

- **AI usage**: AI is ONLY allowed for formatting and content reorganisation (e.g. RAMS method statements, drawing layout via DRAW-30) — never for inventing scope, equipment, cables, rooms, or design.
- **Data integrity**: All drawing content must trace back to canonical project data via `ProjectDataService::resolve()`. Generators may not access `extracted_data` or `reviewed_data` directly.
- **Existing pipeline**: Must not break existing RAMS/O&M/Worksheet/Cable Schedule pipelines or Phase 12 install programme/field view/commissioning paths.
- **Architecture**: Laravel service-based, thin controllers, shared data services, queue-compatible.
- **Output formats**: Drawings as PDF (must) + SVG (must) + PNG (must, for DOCX embed) + DXF (stretch).

---

## Stack Additions

- **D2 CLI v0.7.1** (MPL-2.0, Go binary) — installed at `/usr/local/bin/d2` for schematic generation
- **Konva.js v10.2.5** (MIT, npm) — vanilla JS canvas library, loaded via separate Vite entry on drawing edit pages only
- **DXFighter** (BSD-3, vendored at specific commit) — DXF writer for the Phase 20 stretch goal
- **In-house AV symbol pack** (~25 SVGs in `resources/svg/av-symbols/`) — AVIXA-conventions-aligned

No new framework, no React, no PlantUML, no LibreDWG.

---

## Traceability

*Each requirement maps to exactly one phase. Plan column populated by `/gsd-plan-phase`. Validated column populated by `/gsd-verify-phase`.*

| REQ-ID | Phase | Plan | Validated |
|--------|-------|------|-----------|
| DRAW-01 | Phase 17 | — | — |
| DRAW-02 | Phase 17 | — | — |
| DRAW-03 | Phase 17 | — | — |
| DRAW-04 | Phase 17 | — | — |
| DRAW-05 | Phase 17 | — | — |
| DRAW-06 | Phase 17 | — | — |
| DRAW-07 | Phase 18 | 18-03 (rack editor — engineer builds manually) | — |
| DRAW-08 | Phase 18 | 18-01 (schema) + 18-03 (1U rail render) | — |
| DRAW-09 | Phase 18 | 18-01 (palette ordering) + 18-03 (bottom-up render — partial; AVIXA auto-place deferred) | — |
| DRAW-10 | Phase 18 | 18-03 (drag-reorder + per-item lock + cursor walk) | — |
| DRAW-11 | Phase 18 | 18-01 (multi-rack picker) + 18-03 (independent editor per rack) | — |
| DRAW-12 | Phase 18 | 18-01 (metadata schema) + 18-03 (totals footer with asterisks) | — |
| DRAW-13 | Phase 18 | 18-03 (PDF/SVG/PNG export endpoints lit up) | — |
| DRAW-14 | v2.0 backlog 999.1 | — | — |
| DRAW-15 | v2.0 backlog 999.1 | — | — |
| DRAW-16 | v2.0 backlog 999.1 | — | — |
| DRAW-17 | v2.0 backlog 999.1 | — | — |
| DRAW-18 | v2.0 backlog 999.1 | — | — |
| DRAW-19 | v2.0 backlog 999.1 | — | — |
| DRAW-20 | v2.0 backlog 999.1 | — | — |
| DRAW-21 | Phase 20 | — | — |
| DRAW-22 | Phase 17 | — | — |
| DRAW-23 | Phase 20 | — | — |
| DRAW-24 | Phase 17 | — | — |
| DRAW-25 | Phase 17 | — | — |
| DRAW-26 | Phase 17 | — | — |
| DRAW-27 | Phase 17 | — | — |
| DRAW-28 | Phase 20 | — | — |
| DRAW-29 | v2.0 backlog 999.1 | — | — |
| DRAW-30 | Phase 17 (scaffolding) + v2.0 (functional) | — | — |

**Coverage check:** 30/30 requirements mapped. v1.3 active = 22 reqs across 3 phases. v2.0 deferred = 8 reqs (DRAW-14..20 + DRAW-29).

| Phase | Requirements |
|-------|--------------|
| Phase 17 | DRAW-01, DRAW-02, DRAW-03, DRAW-04, DRAW-05 (scaffolding), DRAW-06, DRAW-22, DRAW-24, DRAW-25, DRAW-26, DRAW-27, DRAW-30 (scaffolding) (12) |
| Phase 18 | DRAW-07, DRAW-08, DRAW-09, DRAW-10, DRAW-11, DRAW-12, DRAW-13 (7) |
| Phase 20 | DRAW-21, DRAW-23, DRAW-28 (3) |
| **v1.3 total** | **22** |
| v2.0 backlog 999.1 | DRAW-14, DRAW-15, DRAW-16, DRAW-17, DRAW-18, DRAW-19, DRAW-20, DRAW-29 (8) — plus DRAW-05 functional + DRAW-30 functional carry over |

---

## Open Decisions for Phase Planning

- **GAP-4 — Schematic edit-override placement** — DRAW-05 is foundationally a Phase 17 requirement; Phase 17 shipped scaffolding only (lock-on-edit prompt UX). The functional editor was originally planned for Phase 19 alongside Konva — now deferred to v2.0 where the engineering-grade schematic renderer will subsume it.
- **GAP-5 — `Device` schema migration timing** — `u_height` (decimal) + `requires_ventilation_gap_above/below` (boolean) must land before Phase 18; nullable-first migration with backfill prompt during QuoteWerks-import-review

---

*v1.3 requirements mapped to phases — ready for `/gsd-plan-phase 17`*
