---
gsd_state_version: 1.0
milestone: v1.2
milestone_name: Installation Programme & Field Management — SHIPPED 2026-04-25
status: executing
last_updated: "2026-05-02T21:36:00.000Z"
last_activity: 2026-05-02 -- Phase 18 COMPLETE — Plan 18-03 LANDED (rack editor + Sortable.js + sync render)
progress:
  total_phases: 4
  completed_phases: 2
  total_plans: 5
  completed_plans: 5
  percent: 100
---

## Project Reference

See: .planning/PROJECT.md (updated 2026-04-30)

**Core value:** One dataset powers every document.
**Current focus:** Phase 18 — Rack Elevations

## Current Position

Phase: 18 (Rack Elevations) — COMPLETE
Plan: 2 of 2 done — phase next is 20 (Drawing Export + O&M Integration)
Status: Phase 18 fully shipped (Plans 18-01 + 18-03); v1.3 next phase is 20.
Last activity: 2026-05-02 -- Phase 18 COMPLETE — Plan 18-03 LANDED (rack editor + Sortable.js + sync render)

## Milestone Progress (v1.3)

| Phase | Plans | Status |
|-------|-------|--------|
| 17. System Schematics + Shared Foundations | 3/3 | ✓ Complete |
| 18. Rack Elevations | 2/2 | ✓ Complete (18-01 + 18-03 done) |
| 19. Floor Plans (Konva) | 0/0 | ⤳ Deferred to v2.0 backlog 999.1 (2026-05-02) |
| 20. Drawing Export + O&M Integration | 0/2 | Not started |

**Total:** 5/7 plans, 2/3 phases (71%) — Phase 19 deferred from v1.3 scope.

## Performance Metrics

| Metric | Value |
|--------|-------|
| Requirements (v1.3 active) | 22 (DRAW-01..13, 21..28, 30) |
| Requirements (v2.0 deferred) | 8 (DRAW-14..20, 29) |
| Phases | 3 active (17, 18, 20) + 1 deferred (19) |
| Coverage | 22/22 v1.3 mapped (100%) |
| Plans estimated (v1.3) | 7 (3 done + 2 + 2) |
| Granularity | standard |
| Completed milestones | v1.0 + v1.1 + v1.2 |
| Phase 17 P01 | 12min | 3 tasks | 19 files |
| Phase 17 P02 | 13min | 3 tasks | 32 files |
| Phase 17 P03 | 11min | 3 tasks | 12 files |
| Phase 18 P01 | 65min | 3 tasks | 18 files |
| Phase 18 P03 | 12min | 3 tasks | 15 files |

## Accumulated Context

### Key Decisions (v1.3)

- **Plan 18-03 LANDED** (2026-05-02) — Phase 18 COMPLETE. RackElevationRenderService synchronous custom Blade SVG renderer (~340 LOC, measured 0.06s for full 42U/30-items vs 1s budget — Warning 8 fix); rack editor Blade view with Alpine + Sortable.js drag-into-U-slots palette + 42U scaffold + per-item lock toggle + cursor-walk lock-aware reorder algorithm in JS; AJAX saveRackCanvas endpoint validates JSON allow-list, runs render synchronously, flips status to ready (or failed on render exception); flipRackMountedFlag endpoint authorises against ProjectPolicy::update (project-scoped, owner-OR-admin) so it works BEFORE any rack drawing exists (Blocker 2 fix from checker iteration 2); pdf/drawings/rack.blade.php landscape A4 view; DrawingExportRendererService::bladeViewFor rack arm now returns 'pdf.drawings.rack' (PDF/SVG/PNG endpoints all light up); Sortable.js dependency in dedicated Vite entry (39 kB gzip 14 kB chunk separate from Alpine bundle); show.blade.php Edit Rack button next to Download buttons (existing kind-agnostic line-66 SVG render branch UNCHANGED — Warning 9 fix). NEW App\Policies\ProjectPolicy registered (Phase 17 didn't ship one; deviation Rule 2). 20 new test cases / 99 assertions: render kind guard, 42U rail, item placement, partial-data asterisks/ratios, CRIT-06 unknown-u_height warning, lock annotation, 1s render budget, XSS escape, edit-page render/404/403, save-canvas success/422/extra-key-drop/lock-roundtrip/cursor-walk-Warning-7, flipRackMounted update / pre-rack regression / non-owner 403. DRAW-07 / DRAW-08 / DRAW-09 (partial — palette ordering + bottom-up rendering, AVIXA auto-place algorithm deferred to v1.3.x or v2.0) / DRAW-10 / DRAW-11 / DRAW-12 / DRAW-13 covered. 11 drawings routes total (6 P17 + 2 P18-01 + 3 P18-03). 48 Drawings tests pass + 1 expected D2 skip on dev.
- **Plan 18-01 LANDED** (2026-05-02) — Phase 18 foundations: devices.u_height + ventilation/is_rack_mounted columns (CRIT-06 nullable-first); 53-entry hand-curated manufacturer JSON pack at resources/data/device-port-catalog.json + DeviceCatalogService memoised reader + idempotent DeviceCatalogSeeder (whereRaw LOWER(TRIM) bound parameter — devices outside the pack stay NULL); DrawingService::generateInitial dispatches by kind via match (schematic = Phase 17 async, rack = synchronous + 42U + 230V scaffold, floor_plan deferred to v2.0); DrawingDataResolverService::rackStackForProject body filled with rack-mounted-first palette; ProjectDrawingController::picker + createRack actions; unified Alpine + Create Drawing modal replaces per-kind buttons (Floor Plan card disabled with "Coming in v2.0" tooltip). 24 new test cases / 72 assertions. DRAW-08 (schema), DRAW-09 (palette ordering — partial), DRAW-11 (multi-rack picker), DRAW-12 (metadata schema) covered.
- **Plan 17-03 LANDED** (2026-05-01) — Drawings render UI + O&M handover wiring complete. DrawingExportRendererService delegates PNG to PdfRenderService::fromBladeAsPng (Warning 8 paid off — Phase 20 CRIT-03 hardening lands in one place). OmManualDocxService Drawings section opens fresh `$drawingsSection = $phpWord->addSection(...)` (Blocker 3 fix — no `$section` reuse). createSchematic uses generateInitial not regenerate (Warning 9 — first version is R0). BuildSchematicJob thumbnail block inserted disjoint from Plan 02's mail dispatch (Warning 6 preserved; DrawingReadyMail/completion_email_sent_at grep returns 6). pdf:smoke-test --drawings flag delivered. 6 drawings routes registered. DRAW-05 (UX scaffolding only) / DRAW-06 / DRAW-26 / DRAW-27 covered. Phase 17 complete (3/3 plans).
- **Plan 17-02 LANDED** (2026-05-01) — D2-driven schematic generator: SchematicGeneratorService + SchematicD2SourceBuilder with full sanitiseLabel() escape (Warning 7), 25-symbol AVIXA-aligned SVG pack (~18 KB), config/drawings.php (D2 binary path + signal-type colour map), DrawingDataResolverService::adjacencyForProject body filled, BuildSchematicJob wired to real generator (placeholder removed; Plan 03 thumbnail-render marker left). 5 feature tests (4 pass deterministic, 1 skips when D2 binary missing on dev). DRAW-01/02/03/04/22 + CRIT-05 + Warning 7 mitigated.
- **Plan 17-01 LANDED** (2026-05-01) — shared drawings foundations now live: project_drawings table, ProjectDrawing model + policy, TYPE_DRAWING storage type, DrawingService (createForProject/generateInitial/regenerate/archivePrior), BuildSchematicJob skeleton with full handle()+failed() bodies, DrawingReadyMail single mailable, DrawingEditAdapter scaffolding, PdfRenderService::waitForJs option + new fromBladeAsPng method.
- **Phase 17 owns shared foundations** — `project_drawings` table, `ProjectDrawing` model + policy, `DocumentArtifactStorage::TYPE_DRAWING`, `BuildSchematicJob` + base job pattern, `DrawingReadyMail` (single mailable + kind discriminator), `PdfRenderService::waitForJs` extension, `DrawingEditAdapter` (extends existing DocumentEdits). Phases 18/19/20 become pure additions.
- **One `project_drawings` table, kind discriminator** — over three near-identical models (mirrors H-07 collapse to one `DocumentArtifactStorage`).
- **Konva.js (vanilla, MIT) over React canvas libs** — TLDraw / Excalidraw rejected (React-only conflicts with Alpine.js stack; ~600 KB framework cost for one screen).
- **D2 CLI for schematics** — server-side native binary (MPL-2.0), SVG output, no headless browser at gen time.
- **Custom Blade SVG for racks** — racks are list-shaped not graph-shaped; D2 + Konva are overkill.
- **DXF stretch goal only (Phase 20)**; DWG is out of scope (LibreDWG GPLv3 = hard blocker).

### Open Decisions

- **GAP-3** ~~Phase 19 mandatory Browsershot+Konva spike~~ — RESOLVED: Phase 19 deferred to v2.0 (2026-05-02). Spike no longer needed in v1.3.
- **GAP-4** ~~Schematic edit-override Phase 19 placement~~ — RESOLVED: full Konva-based schematic editor deferred to v2.0 alongside floor plans. Phase 17 lock-on-edit prompt UX scaffolding stays as the v1.3 ceiling for DRAW-05.
- **GAP-5** — `Device` schema migration timing: `u_height` (decimal) + `requires_ventilation_gap_above/below` (boolean) + `is_rack_mounted` must land in Phase 18 plan 18-01 before the rack editor runs.
- **NEW (2026-05-02)** — Build-vs-buy spike for v2.0 should run when v1.3 ships. 1-week investigation: Lucidchart API / draw.io embed / XTEN-AV / D-Tools. Decides whether v2.0 is native build (~14 weeks) or vendor integration (~3-4 weeks). See memory `v2_engineering_grade_drawings_plan.md`.

### Blockers / Risks

- **CRIT-01..CRIT-06** documented in `.planning/research/PITFALLS.md`; risk register attached to each phase via canonical refs
- Outstanding human UAT carried from v1.2 (Gantt browser confirmation, iOS HEIC end-to-end) — deployment-gated, not blocking v1.3 roadmap

### Todos

- Plan Phase 17 via `/gsd-plan-phase 17`
- Confirm DRAW-29 (DXF) stretch-goal stance with engineering leadership before Phase 20 starts (per GAP-1 in research SUMMARY)

## Session Continuity

**Last session ended:** 2026-05-02 — Plan 18-03 (rack editor + Sortable.js + sync render) completed: synchronous custom Blade SVG RackElevationRenderService (~340 LOC, 0.06s measured for 42U/30-items vs 1s budget); rack editor Blade view with Alpine + Sortable.js cursor-walk lock-aware reorder; AJAX saveRackCanvas endpoint (typed validate, sync render, status flip); flipRackMountedFlag at project-scope authorising against new App\Policies\ProjectPolicy (works pre-rack — Blocker 2 fix); pdf/drawings/rack.blade.php landscape A4; DrawingExportRendererService rack arm lit up; Sortable.js as dedicated Vite entry chunk; show.blade.php Edit Rack button (line-66 kind-agnostic SVG branch UNCHANGED). 20 new test cases / 99 assertions. Phase 18 COMPLETE.

**Next session starts:** Phase 20 (Drawing Export Pipeline + O&M Integration) — bound multi-page project PDF, drawing register, sheet numbering (AV-301, AV-302), revision tracking, embeds drawings (schematic + rack — floor plans deferred) in O&M handover via PNG flatten. DRAW-21, DRAW-23, DRAW-26 already partially scaffolded by Plan 17-03. Estimated 2 plans (~80-100 min total).

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
