---
gsd_state_version: 1.0
milestone: v1.3
milestone_name: Technical Drawings & Schematics
status: roadmap_ready
last_updated: "2026-04-30T22:30:00.000Z"
last_activity: 2026-04-30
progress:
  total_phases: 4
  completed_phases: 0
  total_plans: 10
  completed_plans: 0
  percent: 0
---

## Project Reference

See: .planning/PROJECT.md (updated 2026-04-30)

**Core value:** One dataset powers every document.
**Current focus:** v1.3 Technical Drawings & Schematics — roadmap created (Phases 17–20). Ready to plan Phase 17.

## Current Position

Phase: Phase 17 — System Schematics + Shared Foundations (not started)
Plan: —
Status: Roadmap ready; awaiting `/gsd-plan-phase 17`
Last activity: 2026-04-30 — v1.3 roadmap created. 4 phases derived from 30 DRAW-XX requirements (100% coverage). Phase 17 owns shared foundations (project_drawings table, model, policy, TYPE_DRAWING storage, BuildSchematicJob pattern, DrawingReadyMail, PdfRenderService::waitForJs extension, DrawingEditAdapter) so Phases 18–20 are pure additions.

## Milestone Progress (v1.3)

| Phase | Plans | Status |
|-------|-------|--------|
| 17. System Schematics + Shared Foundations | 0/3 | Not started |
| 18. Rack Elevations | 0/2 | Not started |
| 19. Floor Plans (Konva) | 0/3 | Not started |
| 20. Drawing Export + O&M + DXF stretch | 0/2 | Not started |

**Total:** 0/10 plans, 0/4 phases (0%)

## Performance Metrics

| Metric | Value |
|--------|-------|
| Requirements (v1.3) | 30 (DRAW-01..DRAW-30) |
| Phases | 4 (Phases 17–20) |
| Coverage | 30/30 mapped (100%) |
| Plans estimated | 10 |
| Granularity | standard |
| Completed milestones | v1.0 + v1.1 + v1.2 |

## Accumulated Context

### Key Decisions (v1.3)

- **Phase 17 owns shared foundations** — `project_drawings` table, `ProjectDrawing` model + policy, `DocumentArtifactStorage::TYPE_DRAWING`, `BuildSchematicJob` + base job pattern, `DrawingReadyMail` (single mailable + kind discriminator), `PdfRenderService::waitForJs` extension, `DrawingEditAdapter` (extends existing DocumentEdits). Phases 18/19/20 become pure additions.
- **One `project_drawings` table, kind discriminator** — over three near-identical models (mirrors H-07 collapse to one `DocumentArtifactStorage`).
- **Konva.js (vanilla, MIT) over React canvas libs** — TLDraw / Excalidraw rejected (React-only conflicts with Alpine.js stack; ~600 KB framework cost for one screen).
- **D2 CLI for schematics** — server-side native binary (MPL-2.0), SVG output, no headless browser at gen time.
- **Custom Blade SVG for racks** — racks are list-shaped not graph-shaped; D2 + Konva are overkill.
- **DXF stretch goal only (Phase 20)**; DWG is out of scope (LibreDWG GPLv3 = hard blocker).

### Open Decisions (carried into Phase 17 planning)

- **GAP-3** — Phase 19 mandatory Day-1 Browsershot+Konva PDF spike with documented fallback (`stage.toSVG()` client-side → embed SVG)
- **GAP-4** — Schematic edit-override placement: foundationally Phase 17 (DRAW-05), but defers to Phase 19 if Phase 17 ships auto-only first
- **GAP-5** — `Device` schema migration timing: `u_height` (decimal) + `requires_ventilation_gap_above/below` (boolean) must land before Phase 18 rack generator runs

### Blockers / Risks

- **CRIT-01..CRIT-06** documented in `.planning/research/PITFALLS.md`; risk register attached to each phase via canonical refs
- Outstanding human UAT carried from v1.2 (Gantt browser confirmation, iOS HEIC end-to-end) — deployment-gated, not blocking v1.3 roadmap

### Todos

- Plan Phase 17 via `/gsd-plan-phase 17`
- Confirm DRAW-29 (DXF) stretch-goal stance with engineering leadership before Phase 20 starts (per GAP-1 in research SUMMARY)

## Session Continuity

**Last session ended:** 2026-04-30 — ROADMAP.md updated with v1.3 phases 17–20; v1.2 SHIPPED block preserved; v1.4–v1.6 outline preserved; Roadmap Overview table now shows v1.3 = 🚧 In progress; REQUIREMENTS.md traceability table populated.

**Next session starts:** Run `/gsd-plan-phase 17` to begin Phase 17 (System Schematics + Shared Foundations) decomposition.

## Roadmap Overview

| Milestone | Theme | Phases | Status |
|-----------|-------|--------|--------|
| v1.0 | RAMS MVP | 01–07 | ✅ Shipped |
| v1.1 | Operations Dashboard & Notifications | 08–09 (10/11 deferred) | ✅ Shipped 2026-04-25 |
| v1.2 | Installation Programme & Field Management | 12–16 | ✅ Shipped 2026-04-25 |
| v1.3 | Technical Drawings & Schematics | 17–20 | 🚧 In progress |
| v1.4 | Client Portal & Project Visibility | 21–24 | 📋 Planned |
| v1.5 | Financial & Proposal Engine | 25–28 | 📋 Planned |
| v1.6 | Service & Inventory | 29–32 | 📋 Planned |

## Quick Tasks

| ID | Date | Description | Status | Commit |
|----|------|-------------|--------|--------|
| 260413-f2h | 2026-04-13 | Fix room detection, room_overviews scaffold, prepared-by multi-line | ✅ Done | 616ec5a |
| 260413-fjj | 2026-04-13 | Widen part-number regex to accept digit-starting part numbers | ✅ Done | 1ec01b3 |
| 260413-qxb | 2026-04-13 | Pre-fill RAMS create form from project data when no package exists | ✅ Done | 0cf006e |
| 260413-rm9 | 2026-04-13 | Restructure RAMS DOCX to match reference PDF format (9-section, scope items, risk badges, numbered steps) | ✅ Done | 977c0a9 |
| 260414-cnf | 2026-04-14 | Rewrite rams.blade.php PDF template to match 9-section reference format (mirrors DOCX output) | ✅ Done | f9790a1 |
| 260414-gua | 2026-04-14 | Redesign RAMS PDF output to match 21CQ30017 reference document and add PDF download to project page | ✅ Done | ef64a9e |
| 260414-j5p | 2026-04-14 | Add start/end times, waste removal, permits, material handling, CDM, COSHH, welfare, toolbox talk to RAMS | ✅ Done | c428316 |
| 260414-jli | 2026-04-14 | Add scope traceability, client responsibilities expanded, exclusions, decommissioning, commissioning criteria to RAMS | ✅ Done | f33b8b9 |
| 260415-en6 | 2026-04-15 | Fix site survey gaps: PM email, cable routes, rack room, projection/network/sign-off fields | ✅ Done | d47d653 |
| 260426-gvm | 2026-04-26 | Public worksheet sign-off link (UUID-token, single-signature acceptance, optional outstanding-items comments, append-only signoffs, DOCX signature embed) | ✅ Done | e31f58d |
| 260427-qvr | 2026-04-27 | Migrate PDF rendering to Browsershot (RAMS + O&M + Site Survey via single PdfRenderService, pdf:smoke-test command, deployment runbook for chrome symlink + queue-worker user fix + chown). Existing dompdf/mpdf retained for rollback. | ⚠️ Needs Review | 92c95da |
| 260430-um1 | 2026-04-30 | Fix install programme 0 tasks — port two-strategy room/equipment distribution (area-tag grouping + flat-equipment fallback with NON_PHYSICAL_ROOMS guard) into InstallTaskGeneratorService. Verified locally on 3 projects (3/43/31 tasks generated). | ✅ Done | c479364 |
