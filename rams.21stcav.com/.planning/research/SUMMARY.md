# v1.3 Research Summary — Technical Drawings & Schematics

**Project:** RAMS Platform — AV Operations System (rams.21stcav.com)
**Domain:** AV technical drawings (schematics, rack elevations, floor plans, drawing export) layered onto existing Laravel 12 / Browsershot / Alpine.js / ProjectDataService stack
**Milestone:** v1.3 Phases 17–20
**Researched:** 2026-04-30
**Overall confidence:** MEDIUM-HIGH

> Companion documents (read these for detail):
> - `STACK.md` — library shortlist + license analysis
> - `FEATURES.md` — table-stakes vs differentiators per phase
> - `ARCHITECTURE.md` — data model, services, render pipeline
> - `PITFALLS.md` — risk register with phase tagging

---

## Executive Summary

v1.3 layers four AV-drawing capabilities onto the existing canonical-data platform. The non-negotiable rule from v1.0 carries through: every drawing is generated from `ProjectDataService::resolve()` and never invents equipment. The recommended stack is **D2 CLI** (MPL-2.0 Go binary) for auto-generated signal-flow schematics, a **custom server-side Blade SVG builder** for rack elevations, **Konva.js** (MIT, vanilla JS, ~155 KB) for the in-browser floor plan editor, and the **existing Browsershot pipeline** for PDF/SVG/PNG export. No React, no Mermaid SSR, no PlantUML (GPL), no LibreDWG (GPL). Drawings live in a **single `project_drawings` table with a `kind` discriminator** and store both auto-generated SVG (regenerable) and user-edited Konva canvas state in the same row.

The headline risks are concentrated in Phase 19 (the floor-plan canvas tool) — the Browsershot+Konva PDF round-trip has not been done in this codebase before and must be spike-validated before commitment. Other top risks: silent data drift between user-edited drawings and a refreshed equipment list (CRIT-02 — solved by lock-on-edit + archive-prior pattern, mirroring the Phase 12 InstallProgrammeService precedent), reversed signal-flow direction in auto-generated schematics (CRIT-05 — solved by classifying equipment role rather than inferring from cable-row order), Browsershot worker memory blow-up rendering large floor plans (CRIT-03 — solved by `--disable-dev-shm-usage`, a dedicated drawings queue, and PNG fallback for huge SVGs), and Chrome version drift between dev and prod chrome-headless-shell (CRIT-04 — solved by extending the `pdf:smoke-test --drawings` runbook from 260427-qvr).

**Build order is shared-infrastructure-first.** Phase 17 carries the full `project_drawings` schema, model, policy, `DocumentArtifactStorage::TYPE_DRAWING` constant, the `BuildDrawing*Job` shape, and the `PdfRenderService::waitForJs` extension — even fields and stubs that Phases 18/19 use. This makes 18 and 19 pure additions and Phase 20 a small pipeline phase rather than a re-architect. DXF is a stretch goal at the back of Phase 20 only; DWG is **out of scope**.

---

## Recommended Stack

| Concern | Pick | License | Why |
|---|---|---|---|
| Schematic engine (Phase 17) | **D2 CLI v0.7.1** (Go binary, server-side) | MPL-2.0 | Text → SVG, deterministic, no Node/headless-browser at gen time, single static binary install |
| Rack engine (Phase 18) | **Custom Blade SVG view** (PHP `SvgBuilder` helper) | Internal | Rack is a stacked list, not a graph — D2/Konva are overkill; ~150 LOC of pure server-side rendering |
| Canvas editor (Phase 19) | **Konva.js v10.2.5** (vanilla JS) | MIT | The only mainstream MIT canvas lib that is genuinely vanilla-first, has a documented floor-plan demo, serialises scenes to JSON cleanly. Wraps inside Alpine.js without bringing React in. |
| PDF/SVG/PNG export (Phase 20) | **Existing Spatie Browsershot ^5.3** + 5-line `waitForJs` extension to `PdfRenderService` | MIT | Already wired, queue-compatible, AlmaLinux-proven post-260427-qvr |
| AV symbol pack | **Build in-house** ~25-symbol SVG set following AVIXA conventions | Internal | No OSS AVIXA-compliant SVG library exists; AVIXA standard is descriptive, not a vector library |
| DXF export (Phase 20 stretch) | **DXFighter, vendored** (PHP, BSD-3) | BSD-3-Clause | Pure PHP, no Node child process. Author inactive — vendor a specific commit into `app/Vendor/DXFighter/` |
| DWG export | **Skip** | — | LibreDWG = GPLv3 hard blocker; ODA Teigha = paid |

**Anti-stack:** TLDraw / Excalidraw (React-only), Mermaid SSR (duplicates Browsershot), PlantUML (GPL-3.0), LibreDWG (GPLv3), drawio iframe embed, Fabric.js (Konva edges out on floor-plan precedent and known-good iPad behaviour).

**One server install:** `curl -fsSL https://d2lang.com/install.sh | sh -s -- --version v0.7.1` to `/usr/local/bin/d2`. **One npm dep:** `konva@^10.2.5`. Nothing else added.

---

## Expected Features

**Phase 17 — System Schematics (table stakes):** auto-generate per-room schematic; signal-type colour coding (audio/video/control/network/USB); cable IDs + port labels matching schedule character-for-character; AVIXA-style symbol library; auto-then-editable workflow; equipment-ID-anchored merge on regenerate; PDF + SVG export.

**Phase 18 — Rack Elevations (table stakes):** auto-generate from `equipment.form_factor='rack'`; 1U-precise scale, U-numbered down side; sensible default ordering (PDU → switches → DSP → amps → patches/IO); manual U-position override + lock flag; multi-rack per project; blanking-panel auto-fill; totals footer (weight, current, BTU, U-utilisation).

**Phase 19 — Floor Plans (table stakes):** in-browser canvas with wall/door/window/text primitives; snap-to-grid 50/100/250 mm; per-room canvas tied to `Project.room`; equipment glyph palette filtered to room equipment; auto-place with anchor-wall designation; mount-height annotations + dimension lines + scale bar + north arrow; persisted Konva scene state with debounced auto-save.

**Phase 20 — Drawing Export (table stakes):** single bound PDF per project (cover + drawing register + paginated); configurable sheet numbering (`AV-001`, `AV-101`…); standard title block on every sheet; per-drawing PDF/SVG/PNG download; revision tracking (R0, R1, R2…); inclusion in O&M Manual handover; status enum (draft / for review / approved / superseded).

**Defer to v1.3.x / v1.4+:** DXF export (engineer-demand-driven; floor plans first if at all); DWG export (license blocker); architect-PDF tracing background; coverage cones / heat maps; conflict detection; reflected ceiling plan; drawing approval workflow; per-revision diff overlay PDF.

---

## Architecture Approach

A single `project_drawings` table holds every drawing kind (`kind ENUM 'schematic'|'rack'|'floor_plan'`) with both auto-generated SVG (regenerable from `source_data`) and an optional user-edited `canvas_state` (Konva JSON) in the same row. Generators live under `app/Services/Drawings/`, all read canonical data **only** through a thin `DrawingDataResolverService` wrapper around `ProjectDataService::resolve()`. Editing is browser-side Konva loaded as a separate Vite entry (`resources/js/drawings.js`) only on `/projects/{project}/drawings/*` routes — keeping the global Alpine bundle untouched. PDF/SVG/PNG export rides the existing `PdfRenderService::fromBlade()` pipeline with a 5-line `waitForJs` flag for Konva-renders. DOCX handover (O&M) flattens each drawing to PNG (PhpWord SVG support is unreliable across Word/Word Online/LibreOffice).

**Major components:**

1. **`project_drawings` table** — single polymorphic-ish table, `kind` discriminator, archive-prior versioning (`superseded_by_id` self-FK), `access_token` column added v1.3 nullable for v1.4 portal forward-compat
2. **`app/Services/Drawings/`** — `DrawingService` (orchestrator), `SchematicGeneratorService` (D2 CLI), `RackElevationGeneratorService` (custom SVG builder), `FloorPlanInitialStateService` (Konva JSON seeder), `DrawingExportRendererService` (PDF/PNG/SVG entrypoint), `DrawingDataResolverService` (canonical data reshape)
3. **`app/Jobs/Build{Schematic,RackElevation,FloorPlanPdf}Job.php` + `RegenerateDrawingsJob.php`** — mirror four existing `Build*Job` classes exactly: `$tries=2`, `$timeout=300`, status state machine, idempotent notification timestamps, `failed()` admin alert
4. **`DocumentArtifactStorage::TYPE_DRAWING`** — single new constant; sub-kind in filename convention (`drawings/{kind}-{drawingId}-v{version}-{ulid}.{format}`), not three constants
5. **`resources/js/drawings.js`** — separate Vite entry, lazy-loaded only on edit pages, wraps Konva in an Alpine.js `x-data` component (mirrors v1.2 frappe-gantt)
6. **`PdfRenderService::fromBlade(..., waitForJs: true)`** — 5-line additive option, default `false`
7. **`OmManualDocxService` patch** — appends a "Drawings" section, embeds PNG flattens of each ready drawing, one-page-per-drawing

---

## Critical Pitfalls

1. **CRIT-01 — Browsershot can't render React-canvas libs (TLDraw/Excalidraw)** → Konva pick + "render server-side SVG, never run a hydrating React canvas inside Browsershot." Phase 17 sets the rule; Phase 20 verifies via `pdf:smoke-test --drawings`.
2. **CRIT-02 — Drawing data drift vs canonical project data** → silent overwrite of user edits OR silent staleness. Lock-on-edit + archive-prior versioning + UI confirm prompt + equipment-ID-anchored merge for schematics. Decision lands in Phase 17.
3. **CRIT-03 — Browsershot queue worker memory ballooning on large drawings** → `--disable-dev-shm-usage`, dedicated drawings queue (concurrency=1), per-job memory probe, PNG fallback for huge SVGs. Phase 20.
4. **CRIT-04 — chrome-headless-shell version drift between dev and prod** → pin version in `.env.example`, extend `pdf:smoke-test` with `--drawings` flag, embed fonts via `@font-face` from `public/fonts/`, `setOption('waitForFunction', 'document.fonts.ready')`. Phase 20.
5. **CRIT-05 — Reversed signal-flow direction in auto-generated schematics** → `Device::isSource()/isDestination()/isProcessor()` classification, never row order; ambiguous cables render undirected with warning. Phase 17 — must be right at v1.
6. **CRIT-06 — U-height accuracy in rack elevations** → add `u_height` (decimal, allows 1.5) + `requires_ventilation_gap_above/below` to `Device`; default NULL with "U-height unknown" warning, never guess. Phase 18.

Plus MODERATE pitfalls: GPL trap on DXF/DWG (MOD-01); canvas Y-down vs DXF Y-up flip (MOD-02); unit confusion px/mm/inch (MOD-03 — store in mm); auto-save thrashing/lost work (MOD-04 — debounce + `beforeunload` + `visibilitychange` flush); canvas-JSON storage size (MOD-05 — `MEDIUMTEXT` + gzcompress); iPad Safari touch-action (MOD-08); HiDPI/retina blur (MOD-09 — reuse v1.2 commissioning DPR fix).

---

## Resolved Conflicts Across Research Files

### Konva vs TLDraw for floor plans
- STACK rejected TLDraw outright (React-only conflict); PITFALLS noted TLDraw has best iPad/Pencil palm rejection
- **Resolution: Konva.** React-only blocker dominates — adopting React for one screen contradicts v1.2 STACK lock and adds ~600 KB. Konva supports touch natively; iPad gap closed by `touch-action: none`, snap-to-grid, and limiting tablet edit to "verify auto-place" rather than freehand. Apple Pencil tilt is out of scope.

### One drawings table vs three
- ARCHITECTURE: one `project_drawings` with `kind`. FEATURES: three drawing kinds with shared infra
- **Resolution: one table.** Three near-identical models duplicate state machine + idempotency + soft-delete + policy. Kind-specific differences live entirely in builder/renderer layer. Matches H-07 precedent. Single `TYPE_DRAWING` constant; sub-kind in filename.

### Build order — which phase owns the foundations?
- STACK: Phase 17 first. ARCHITECTURE: Phase 17 owns foundations. FEATURES: Phase 20 builds the foundation
- **Resolution: Phase 17 owns foundations.** Phase 17 ships full `project_drawings` migration (all columns 18/19 need), `ProjectDrawing` model, `TYPE_DRAWING`, policy, `BuildSchematicJob`, single `DrawingReadyMail`, `PdfRenderService::waitForJs`, `SchematicGeneratorService` + D2 binary, routes. Konva is **not** loaded in Phase 17.

### DXF support
- STACK: feasible via DXFighter (BSD, vendor it). PITFALLS: GPL traps with LibreDWG. FEATURES: "DXF only if engineer demand emerges"
- **Resolution: DXF deferred to Phase 20 stretch goal; DWG out of scope indefinitely.** Phase 20 spike confirms DXFighter into AutoCAD LT 2024+ / BricsCAD trial. License audit hard rule on every dep change.

### AV symbol pack
- STACK: build in-house ~25 shapes, ~1 day. FEATURES: AVIXA standard. PITFALLS: AV-domain consistency
- **Resolution: build in-house, AVIXA-conventions-aligned, ~25 symbols.** No OSS AVIXA-compliant SVG library exists. Build in `resources/svg/av-symbols/`, total <100 KB. Standardise on AVIXA D401.01 conventions where they exist.

---

## Implications for Roadmap

### Phase 17 — System Schematics + Shared Foundations
Simplest auto-gen case (text → SVG via D2). Proves data model, storage, job shape, notification flow, `waitForJs`. Foundations land here so 18/19 are pure additions.
- Delivers: `project_drawings` table + model + policy + `TYPE_DRAWING` + `BuildSchematicJob` + `DrawingReadyMail` + auto-generated per-room signal-flow schematics with cable IDs + signal-type colours + AVIXA-style symbols + PDF/SVG export. Edit-override is stretch.
- Avoids: CRIT-01 (no React canvas in Browsershot), CRIT-02 (lock-on-edit pattern), CRIT-05 (`Device::isSource/isDestination/isProcessor`).
- **Plans estimated: 3** — (a) data model + storage + policy + jobs + routes foundations, (b) D2 integration + `SchematicGeneratorService` + symbol pack, (c) PDF render via `waitForJs` + smoke-test.

### Phase 18 — Rack Elevations
Smallest UI. Can run parallel with Phase 17 once foundations land. Custom Blade SVG faster than learning a DSL.
- Delivers: `RackElevationGeneratorService` + custom `SvgBuilder` + `BuildRackElevationJob` + Alpine drag-reorder editor + per-rack PDF + totals footer.
- Avoids: CRIT-06 — adds `u_height` (decimal) + `requires_ventilation_gap_above/below` to `Device`; default NULL not 1U.
- **Plans estimated: 2** — (a) Device U-height schema + RackElevationGeneratorService + SvgBuilder, (b) drag-reorder editor + BuildRackElevationJob + PDF render.

### Phase 19 — Floor Plans (Konva)
Riskiest of the four. **Mandatory Day-1 spike** before plan commitment. Konva is only React-free MIT canvas with floor-plan precedent.
- Delivers: `resources/js/drawings.js` Vite entry + Konva dep + `FloorPlanInitialStateService` + editor Blade view + Alpine wrapper + AJAX canvas save + `BuildFloorPlanPdfJob` (`waitForJs=true`) + iPad smoke test.
- Avoids: MOD-04 (debounce + `beforeunload` + `visibilitychange` flush), MOD-05 (`MEDIUMTEXT` + gzcompress), MOD-06 (lazy-load via separate Vite entry), MOD-07 (server-truth, localStorage read-only), MOD-08 (`touch-action: none`, real iPad smoke test), MOD-09 (apply v1.2 DPR fix).
- **Plans estimated: 3** — (a) **spike day** + Vite entry + Konva integration + initial state service, (b) editor UI (walls/doors/windows/glyphs/snap/auto-place), (c) BuildFloorPlanPdfJob + Browsershot waitForJs round-trip + iPad smoke test.

### Phase 20 — Drawing Export Pipeline + O&M Integration
Pipeline phase. Concentrates production-hardening risks. Wires drawings into O&M handover.
- Delivers: Drawing register cover sheet + standard title block + sheet numbering + revision tracking + bound multi-page PDF + per-drawing PDF/SVG/PNG download + ZIP-bundle download + `OmManualDocxService` "Drawings" section appending PNG flattens + `RegenerateDrawingsJob`.
- Avoids: CRIT-03 (`--disable-dev-shm-usage`, dedicated queue, memory probe, PNG fallback), CRIT-04 (extend `pdf:smoke-test --drawings`, pin chrome-headless-shell, `@font-face`, `document.fonts.ready` wait), MOD-01 (license audit, DXF text-only, no LibreDWG link), MOD-10 (versioned filenames + `regen_recommended` flag), MOD-12 (notification fires from LAST job via `Bus::chain`).
- Stretch: DXFighter-vendored DXF export for floor plans only. Defer DWG.
- **Plans estimated: 2** — (a) export pipeline, (b) O&M integration + production hardening + DXF spike.

### Phase Ordering Rationale
- **Phase 17 first** — only auto-gen phase needing no canvas runtime; simplest case to prove data model and pipeline.
- **Phase 18 parallel with 17** once foundations land — different builders, no shared code.
- **Phase 19 last of per-kind phases** — by then foundations + two simpler generators are proven.
- **Phase 20 necessarily last** — pipeline phase needs at least one drawing kind ready.

---

## Confidence Assessment

| Area | Confidence | Notes |
|---|---|---|
| Stack — D2, Konva, Browsershot reuse | HIGH | All verified via official repos/docs |
| Stack — DXFighter | LOW | Author publicly inactive; vendoring required; treat as stretch only |
| Stack — AV symbol availability | MEDIUM | No AVIXA-compliant OSS SVG set exists; in-house build is only path |
| Features — table stakes | HIGH | Cross-source consensus (AVIXA + D-Tools + Stardraw + XTEN-AV + SymbolLogic) |
| Features — auto-gen algorithm specifics | MEDIUM | Commercial tools don't publish heuristics; algorithms synthesised from output observation |
| Architecture — `project_drawings` shape | HIGH | Mirrors `RamsDocument` + `InstallProgramme` + `OmManual` (proven through three milestones) |
| Architecture — D2 for schematics | HIGH | Server-side native binary, SVG output, no headless browser, no Node runtime |
| Architecture — Browsershot waits for Konva client-side render | MEDIUM | Pattern well-documented for Puppeteer; Phase 19 spike confirms round-trip on AlmaLinux + chrome-headless-shell |
| Architecture — PNG-flatten-for-DOCX-handover | MEDIUM | PhpWord 1.4+ has SVG but real-world Word rendering inconsistent; PNG safe |
| Architecture — DXF/DWG export feasibility | LOW | No robust OSS PHP DXF *writer* surfaced; DWG = GPL or paid only |
| Pitfalls — CRIT-01 to CRIT-06 | HIGH | Grounded in codebase precedent (260427-qvr) or AVIXA convention |
| Pitfalls — MOD-08 (iPad touch) | HIGH | Konva touch handling well-documented; `touch-action: none` standard fix |
| Pitfalls — MOD-01 (GPL legal interpretation) | LOW-MEDIUM | License interpretation; consult counsel before any DWG export |

**Overall confidence: MEDIUM-HIGH.** Foundations well-understood; Phase 19 Konva-via-Browsershot is the only genuinely new pattern, gated by spike before commitment.

---

## Open Questions / Gaps (for user / roadmapper / phase planner)

- **GAP-1 — DXF scope decision:** v1.3 commitment, stretch goal, or out-of-scope? Recommendation: stretch at back of Phase 20. Confirm with engineering leadership whether any active customer has actually asked for DXF, or whether PDF + SVG covers all current handover needs.
- **GAP-2 — AVIXA symbol licensing:** Confirm we can build SVG symbols *aligned with* AVIXA conventions without licensing the standard itself. Standard is published PDF — using its conventions to draw your own SVGs is fine; redistributing AVIXA's actual artwork is not.
- **GAP-3 — Phase 19 spike risk:** Browsershot + Konva PDF round-trip on AlmaLinux + chrome-headless-shell unproven in this codebase. Roadmapper must mark Phase 19 with explicit Day-1 spike + contingency (fallback: render Konva canvas to SVG client-side via `stage.toSVG()`, save SVG, embed in Blade — bypasses wait-for-JS).
- **GAP-4 — Edit override on Phase 17 schematics:** If Phase 17 ships auto-only, where does carry-over land — Phase 19 (when Konva is loaded anyway) or a Phase 17.5? Recommendation: fold into Phase 19.
- **GAP-5 — `Device` schema migration timing:** `u_height` (decimal) + `requires_ventilation_gap_above/below` (boolean) must land before Phase 18 rack generator runs. Migration nullable-first with backfill prompt at QuoteWerks-import-review time.
- **GAP-6 — `DrawingReadyMail` single vs three (low-stakes):** Recommendation: single mailable + kind discriminator. Defer to Phase 17 plan.

---

## Sources

### Existing codebase precedent (HIGH)
- `app/Services/DocumentArtifactStorage.php` — TYPE_* registry pattern, H-07 contract
- `app/Services/PdfRenderService.php` — Browsershot wrapper, AlmaLinux config
- `app/Services/InstallProgrammeService.php` — regenerate-archives-prior pattern
- `app/Services/InstallTaskGeneratorService.php` — sync generation, ProjectDataService consumer
- `app/Jobs/BuildOmManualJob.php` — status + idempotency + failed() pattern
- `app/Core/Modules/Projects/ProjectDataService.php` — DATA-03 contract
- `vite.config.js` — Vite entry pattern (frappe-gantt precedent)
- `.planning/quick/260427-qvr-migrate-pdf-rendering-to-browsershot/` — chrome symlink runbook + smoke-test pattern
- v1.2 INST-05 commissioning checklist — DPR signature canvas precedent

### Industry standards (HIGH)
- AVIXA Audio Video and Control Architectural Drawing Symbols Standard (D401.01)
- AVIXA Standard Guide for AV Systems Design and Coordination
- AVIXA F502.01 rack building (thermal management)
- Uniform Drawing System Module 1 — Sheet Identification (NCS)
- EIA-310-D rack unit standard

### Tooling (HIGH)
- D2 GitHub releases (v0.7.1) — https://github.com/terrastruct/d2/releases
- Konva.js npm (v10.2.5) + Interactive Building Map demo
- Spatie Browsershot v4 docs
- DXFighter GitHub (BSD-3, marked inactive)
- LibreDWG GNU project — GPLv3 confirmed

### Tool reference for table-stakes definition (HIGH)
- D-Tools System Integrator v24
- Stardraw Design 7 Block Schematic module
- XTEN-AV X-DRAW
- SymbolLogic AV stencils

---

*Research completed: 2026-04-30*
*Ready for roadmap: yes*
*Spike required before Phase 19 commitment: yes (Browsershot + Konva → PDF on AlmaLinux + chrome-headless-shell)*
