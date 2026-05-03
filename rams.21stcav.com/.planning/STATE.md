---
gsd_state_version: 1.0
milestone: v1.2
milestone_name: Installation Programme & Field Management — SHIPPED 2026-04-25
status: executing
last_updated: "2026-05-03T09:07:29.630Z"
last_activity: 2026-05-03
progress:
  total_phases: 4
  completed_phases: 2
  total_plans: 7
  completed_plans: 6
  percent: 86
---

## Project Reference

See: .planning/PROJECT.md (updated 2026-04-30)

**Core value:** One dataset powers every document.
**Current focus:** Phase 20 — drawing-export-pipeline-o-m-integration

## Current Position

Phase: 20 (drawing-export-pipeline-o-m-integration) — EXECUTING
Plan: 2 of 2
Status: Ready to execute
Last activity: 2026-05-03

## Milestone Progress (v1.3)

| Phase | Plans | Status |
|-------|-------|--------|
| 17. System Schematics + Shared Foundations | 3/3 | ✓ Complete |
| 18. Rack Elevations | 2/2 | ✓ Complete (18-01 + 18-03 done) |
| 19. Floor Plans (Konva) | 0/0 | ⤳ Deferred to v2.0 backlog 999.1 (2026-05-02) |
| 20. Drawing Export + O&M Integration | 1/2 | 🚧 In progress (20-01 LANDED 2026-05-03; 20-02 next) |

**Total:** 6/7 plans complete, 2/3 phases shipped (86%) — Phase 19 deferred from v1.3 scope; Plan 20-01 (bound PDF + sheet numbering + ZIP) LANDED.

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
| Phase 20 P01 | 35min | 3 tasks | 19 files |

## Accumulated Context

### Key Decisions (v1.3)

- **Plan 20-01 LANDED** (2026-05-03) — DRAW-21 bound PDF + DRAW-23 sheet numbering + DRAW-28 ZIP bundle shipped. New: SheetNumberAllocator (AV-201..299 schematics, AV-301..399 racks, set-once on DrawingService::createForProject; superseded rows skipped from count; floor-plan throws InvalidArgumentException — v2.0); BoundPdfBuilderService (FPDI page-by-page concat of cover sheet + every per-drawing PDF; per-drawing failures isolated via try/catch — register row prefixed `[render failed]`, whole bound PDF still completes; on-disk version scan via glob `drawings/bound-{projectId}-v*-*.pdf`); BuildBoundPdfJob ($projectId not $drawingId — project-level artifact; tries=2; timeout=300; queue='drawings' via constructor onQueue() because typed `public string $queue` triggers PHP fatal vs untyped Queueable trait; WithoutOverlapping middleware keyed by bound-pdf-{projectId} releaseAfter(60s)); BoundPdfReadyMail (Project-typed, basename'd PDF attachment); pdf/drawings/bound-cover.blade.php (A4 portrait cover + drawing register table with red-row failure highlighting + banner); pdf/drawings/_title-block.blade.php gets new Sheet row consuming $drawing->sheet_number with @if defensive guard for pre-Phase-20 rows. Three new routes registered BEFORE {drawing} wildcard show route: GET projects/{project}/drawings/bound-pdf (downloadBoundPdf — fresh streamed OR ≤3-drawing inline build OR async dispatch), POST projects/{project}/drawings/bound-pdf/build (regenerateBoundPdf — always async), GET projects/{project}/drawings/bundle.zip (downloadBundle — ZipArchive on-disk build → streamDownload, EVERY addFile via basename($realPath) per T-20-02 + drawing-register.csv via addFromString). Index controller computes $boundPdfStaleBadge (latest mtime vs max drawings.updated_at — MOD-10) → amber pill in index Blade. Index Blade ships Project Documents block (bound PDF + ZIP buttons) + Sheet column on schematic + rack rows. New deps: setasign/fpdi:^2.6 (MIT) + setasign/fpdf:^1.8.6 (permissive — bumped from ^1.8.0 due to PHP 8 fatal in get_magic_quotes_runtime()). Composer downgraded symfony/http-client + symfony/postmark-mailer 8.x → 7.x as side effect (no test regressions). 12 plan tests / 35 assertions: 5 SheetNumberAllocator (block bases, superseded skip, floor-plan throw), 5 BoundPdfBuilder (3-page concat, failure isolation, floor-plan exclusion, kind-group order, ULID filename pattern), 4 BoundPdfDownload (200+%PDF body, 403 non-owner, regen-needed badge after touch, regenerate POST dispatches BuildBoundPdfJob), 3 ZipBundleDownload (ZIP regex'd entries for bound + per-kind-PDF/SVG/PNG + register.csv, no `..`/`/`/`\` in entry names, 403). Deviations: SQLite no FIELD() → CASE-based portable ordering (works MySQL+SQLite); literal-segment routes BEFORE {drawing} wildcard (Phase 18 precedent). 65-test drawings suite green (Phase 17/18 zero regressions). 14 drawings routes total. Commits: feefb41 + 428690d + 184beec.
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

**Last session ended:** 2026-05-03 — Plan 20-01 (bound PDF + sheet numbering + ZIP bundle) completed in 35 minutes / 3 commits / 19 files. SheetNumberAllocator + BoundPdfBuilderService + BuildBoundPdfJob + BoundPdfReadyMail + bound-cover Blade + title-block Sheet row + 3 new routes + Project Documents UI block + 12 tests/35 assertions. FPDF 1.8 PHP-8 fatal hot-fix + SQLite FIELD()→CASE portability + Queueable trait `$queue` conflict deferred to constructor onQueue() — three Rule-1 deviations all auto-fixed. License audit: setasign/fpdi MIT + setasign/fpdf permissive (no GPL/AGPL).

**Next session starts:** Plan 20-02 (production hardening + O&M rack embed) — extends pdf:smoke-test to cover rack render, dedicated `drawings` queue worker process + config, @font-face Arial/Liberation Sans embed in bound-cover.blade.php (CRIT-04), composer/npm license audit script, and OmManualDocxService Drawings section extension to embed rack PNGs alongside schematic PNGs (existing Phase 17 P03 patch grows the loop). Estimated ~60-90 min.

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
