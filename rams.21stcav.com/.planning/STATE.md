---
gsd_state_version: 1.0
milestone: v1.2
milestone_name: Installation Programme & Field Management — SHIPPED 2026-04-25
status: verifying
last_updated: "2026-05-01T17:40:25.170Z"
last_activity: 2026-05-01
progress:
  total_phases: 1
  completed_phases: 1
  total_plans: 3
  completed_plans: 3
  percent: 100
---

## Project Reference

See: .planning/PROJECT.md (updated 2026-04-30)

**Core value:** One dataset powers every document.
**Current focus:** Phase 17 — System Schematics + Shared Foundations

## Current Position

Phase: 17 (System Schematics + Shared Foundations) — EXECUTING
Plan: 3 of 3
Status: Phase complete — ready for verification
Last activity: 2026-05-01

## Milestone Progress (v1.3)

| Phase | Plans | Status |
|-------|-------|--------|
| 17. System Schematics + Shared Foundations | 3/3 | Awaiting verification |
| 18. Rack Elevations | 0/2 | Not started |
| 19. Floor Plans (Konva) | 0/3 | Not started |
| 20. Drawing Export + O&M + DXF stretch | 0/2 | Not started |

**Total:** 3/10 plans, 0/4 phases (30%)

## Performance Metrics

| Metric | Value |
|--------|-------|
| Requirements (v1.3) | 30 (DRAW-01..DRAW-30) |
| Phases | 4 (Phases 17–20) |
| Coverage | 30/30 mapped (100%) |
| Plans estimated | 10 |
| Granularity | standard |
| Completed milestones | v1.0 + v1.1 + v1.2 |
| Phase 17 P01 | 12min | 3 tasks | 19 files |
| Phase 17 P02 | 13min | 3 tasks | 32 files |
| Phase 17 P03 | 11min | 3 tasks | 12 files |

## Accumulated Context

### Key Decisions (v1.3)

- **Plan 17-03 LANDED** (2026-05-01) — Drawings render UI + O&M handover wiring complete. DrawingExportRendererService delegates PNG to PdfRenderService::fromBladeAsPng (Warning 8 paid off — Phase 20 CRIT-03 hardening lands in one place). OmManualDocxService Drawings section opens fresh `$drawingsSection = $phpWord->addSection(...)` (Blocker 3 fix — no `$section` reuse). createSchematic uses generateInitial not regenerate (Warning 9 — first version is R0). BuildSchematicJob thumbnail block inserted disjoint from Plan 02's mail dispatch (Warning 6 preserved; DrawingReadyMail/completion_email_sent_at grep returns 6). pdf:smoke-test --drawings flag delivered. 6 drawings routes registered. DRAW-05 (UX scaffolding only) / DRAW-06 / DRAW-26 / DRAW-27 covered. Phase 17 complete (3/3 plans).
- **Plan 17-02 LANDED** (2026-05-01) — D2-driven schematic generator: SchematicGeneratorService + SchematicD2SourceBuilder with full sanitiseLabel() escape (Warning 7), 25-symbol AVIXA-aligned SVG pack (~18 KB), config/drawings.php (D2 binary path + signal-type colour map), DrawingDataResolverService::adjacencyForProject body filled, BuildSchematicJob wired to real generator (placeholder removed; Plan 03 thumbnail-render marker left). 5 feature tests (4 pass deterministic, 1 skips when D2 binary missing on dev). DRAW-01/02/03/04/22 + CRIT-05 + Warning 7 mitigated.
- **Plan 17-01 LANDED** (2026-05-01) — shared drawings foundations now live: project_drawings table, ProjectDrawing model + policy, TYPE_DRAWING storage type, DrawingService (createForProject/generateInitial/regenerate/archivePrior), BuildSchematicJob skeleton with full handle()+failed() bodies, DrawingReadyMail single mailable, DrawingEditAdapter scaffolding, PdfRenderService::waitForJs option + new fromBladeAsPng method.
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

**Last session ended:** 2026-05-01 — Plan 17-03 (Render UI + Handover) completed: DrawingExportRendererService (PDF/SVG/PNG with PNG riding on PdfRenderService::fromBladeAsPng — Warning 8), Drawings index/show Blade views with status pill + lock-on-edit confirm modal (DRAW-05 UX scaffolding), per-format download endpoints (DRAW-06 + DRAW-27), createSchematic via generateInitial (Warning 9 — first version is R0), updateStatus through DocumentEditAdapter::set_status, BuildSchematicJob thumbnail block disjoint from Plan 02 mail dispatch (Warning 6), OmManualDocxService Drawings section via fresh `$drawingsSection = $phpWord->addSection(...)` (Blocker 3 fix — DRAW-26 PNG embed, one drawing per page), pdf:smoke-test --drawings flag, project show page Drawings link. Phase 17 complete (3/3 plans, awaiting verification).

**Next session starts:** Verify Phase 17 via `/gsd-verify-phase 17`, then plan Phase 18 (Rack Elevations) — pure addition on Phase 17 foundations (TYPE_DRAWING storage, ProjectDrawing model with KIND_RACK constant, BuildSchematicJob shape, DrawingEditAdapter pattern, DrawingExportRendererService::bladeViewFor() Phase 18 swap point all already in place).

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
