# Technology Stack — v1.3 Technical Drawings & Schematics

**Project:** RAMS Platform (rams.21stcav.com)
**Milestone:** v1.3 Technical Drawings & Schematics (Phases 17–20)
**Researched:** 2026-04-30
**Overall confidence:** MEDIUM-HIGH

---

## Scope of This Document

Stack additions for v1.3 only. The existing stack (Laravel 12 / PHP 8.2 / MySQL / Blade+Tailwind+Alpine.js / PhpWord / PhpSpreadsheet / spatie/browsershot ^5.3 / Claude+OpenAI via AIManager / ProjectDataService / DocumentArtifactStorage) is LOCKED and not re-researched here. Recommendations focus on:

1. **Schematic engine** — auto-generated signal-flow + rack elevations from canonical project data
2. **Canvas drawing library** — in-app floor plan tool
3. **DXF/DWG export** — feasibility only (nice-to-have for v1.3)
4. **PDF/SVG export** — reuse Browsershot pipeline
5. **AV stencil/symbol library** — buy vs build

---

## Top-Line Recommendations (TL;DR)

| Concern | Recommendation | License | Why |
|---------|----------------|---------|-----|
| Schematic engine | **D2 CLI** ^0.7.1 + dot fallback for free-graph signal flow; **server-rendered Blade SVG** for rack elevations | MPL-2.0 | Text→SVG, server-side, no Node/browser dep at gen time, deterministic, reproducible from canonical data |
| Canvas library | **Konva.js** ^10.2.5 | MIT | Vanilla JS (matches Alpine.js stack, no React), purpose-built for floor plans, JSON-serialisable scene state |
| DXF export | **DXFighter** (PHP, BSD-3) — server-side from canvas JSON state | BSD-3-Clause | Pure PHP, no Node child process, but unmaintained — vendor it; flag as stretch goal |
| DWG export | **Skip for v1.3.** All viable libs are GPL (LibreDWG) or paid (Teigha) | — | License risk + scope creep; AutoCAD opens DXF natively |
| PDF/SVG export | **Existing Browsershot ^5.3** pipeline + Blade view rendering Konva-replay or D2-emitted SVG | MIT | Already wired, queue-compatible, DocumentArtifactStorage-aware |
| AV stencils | **Hybrid:** ship a small in-house SVG symbol set (~25 shapes) following AVIXA conventions; D2 sprites for system schematics | Internal | No OSS AVIXA-compliant set exists; AVIXA standard is descriptive, not a vector library |

**Anti-recommendations (DO NOT add):**
- TLDraw — React-only, conflicts with Alpine.js stack; bundle weight unjustified
- Excalidraw — React-only + hand-drawn aesthetic too informal for AV deliverables
- PlantUML — GPL-3.0, license risk for closed-source internal tool
- LibreDWG — GPLv3, license risk
- Mermaid via headless-chromium round-trip — duplicates Browsershot infrastructure for inferior diagram quality
- drawio embed-mode iframe as the primary editor — overkill, adds postMessage complexity, multi-MB bundle

---

## 1. Schematic Engine

### Comparison

| Engine | Run Mode | Output | License | AV Stencils | Verdict |
|--------|----------|--------|---------|-------------|---------|
| **D2** ^0.7.1 | Go CLI (server) OR `d2.wasm` (browser) | SVG, PNG, PDF | MPL-2.0 | None native; sprite system supports custom SVG | ✓ **Pick** for signal flow |
| **Mermaid** ^11 | Browser JS (~500 kB) or Node SSR via headless-chromium | SVG | MIT | None; flowchart shapes only | ✗ Not deterministic enough; SSR duplicates Browsershot |
| **DrawIO** (jgraph) | Browser iframe + postMessage | XML, SVG, PNG, PDF | Apache-2.0 | 1000+ shapes incl. AV community libs | ✗ Editor-first, not auto-gen-first; iframe overhead |
| **Graphviz/dot** | Server CLI (binary) | SVG, PNG, PDF | EPL-1.0 | None | ⚠ Fallback only — ugly defaults, weak label control |
| **PlantUML** | Java JAR (server) | SVG, PNG | **GPL-3.0** | None | ✗ License blocker for closed-source |
| **Custom SVG** | PHP Blade-rendered | SVG | Internal | We define | ✓ **Pick** for rack elevations |

### 1.1 D2 — chosen for signal flow

**Verified facts (HIGH confidence):**
- Latest stable: **v0.7.1** (released 2025-08-19 per GitHub releases). No newer stable as of research date — pin `v0.7.1` in install script.
- License: **MPL-2.0** — file-level copyleft. Compatible with closed-source internal use; we don't redistribute or modify D2 source files.
- Supports nested grids (good if we wanted rack elevations there — we won't, see §1.2).
- Output: SVG, PNG, PDF directly from CLI. No browser required at generation time.
- Sprites: custom SVG shapes loaded via `shape: image; icon: ./mic.svg`.

**Run model:**
```bash
# Install once on server (admin task, not in deploy)
curl -fsSL https://d2lang.com/install.sh | sh -s -- --version v0.7.1
# Render at job time
d2 --layout=elk input.d2 output.svg
```

The Go binary is self-contained, ~30 MB, lives at `/usr/local/bin/d2`. No `pdftoppm`/`tesseract`-style apt dependency chain — single static binary.

**Integration with existing pipeline:**
1. New `SchematicBuilderService` reads `ProjectDataService::canonical($project)` to extract equipment + cable list.
2. `SchematicTextEmitter` emits D2 source (`source -> dest: cable_label`), persisted to disk under TYPE_DRAWING artifact subdir.
3. `BuildSchematicJob` shells out to `d2` binary via `Symfony\Process` — same pattern as existing OCR `pdftotext` shell-out in `PdfOcrExtractorService`.
4. SVG written through `DocumentArtifactStorage::writePath(TYPE_DRAWING, ...)` (new constant — see §6).
5. PDF version: feed SVG into a Blade wrapper, render via existing Browsershot call (§4).

### 1.2 Rack elevations — server-rendered Blade SVG

D2's nested grid is technically capable but a poor fit for rack elevations:
- Rack U-positions are pixel-precise and rigid (1U = 44.45 mm)
- Connections aren't relevant in a rack diagram (just stacked boxes with labels)
- We need front/rear views, ventilation hashing, blanking panels — schematic-engine ergonomics fight this

**Recommendation:** A pure Blade SVG view (`resources/views/drawings/rack-elevation.blade.php`) consuming a `RackElevationData` DTO. Each U-block is a `<rect>` positioned by `top = (totalU - startU) * unitHeight`. ~150 lines of Blade. Renders to PDF via Browsershot, exports to SVG via direct file write.

**Why not D2 for both:**
- Reusing Browsershot for the rack view costs ~0 effort and gives us full CSS layout control
- Two engines for two intrinsically different drawing types is fine — same pattern as DOCX (PhpWord) vs XLSX (PhpSpreadsheet) we already accept

### Rationale

D2 is text-driven, server-rendered, deterministic, MPL-2.0 (closed-source-internal-friendly), and outputs SVG natively — a perfect match for canonical-data-driven generation. Mermaid would force us to either add a Node.js SSR layer or render in-browser (breaks the "queue-generated artifact" pattern). PlantUML's GPL is a hard blocker. DrawIO is fundamentally an editor, not a generator. Graphviz works but produces aesthetically poor output for AV system schematics — we keep it as a documented fallback.

---

## 2. Canvas Drawing Library — Floor Plan Tool

### Comparison

| Library | Latest | Framework | License | Bundle | Persistence | Verdict |
|---------|--------|-----------|---------|--------|-------------|---------|
| **Konva.js** | ^10.2.5 | Vanilla JS (with optional React/Vue/Svelte/Angular bindings) | MIT | ~155 kB min+gz | `Konva.Node::toJSON()` → MySQL JSON column | ✓ **Pick** |
| **Fabric.js** | ^6.4.3 (v7 rolling) | Vanilla JS (TS-rewritten in v6) | MIT | ~180 kB min+gz | `canvas.toJSON()` → MySQL JSON | ⚠ Solid alt; less floor-plan precedent |
| **TLDraw** | ^4.5.10 | **React-only** | Apache-2.0 (SDK) / TLDraw watermark on free tier | ~600+ kB | tldraw store snapshot | ✗ React conflict |
| **Excalidraw** | `@excalidraw/excalidraw` ^0.18 | **React-only** | MIT | ~400+ kB | scene JSON | ✗ React + hand-drawn aesthetic |
| **jsPlumb Community** | 6.x (archived 2023) | Vanilla JS | MIT/GPL2 | ~80 kB | XML/JSON | ✗ Connection-focused, not free drawing; archived |

### Konva.js — chosen

**Verified facts (HIGH confidence):**
- Latest: **v10.2.5** (~April 2026 per npm)
- License: **MIT** — fully OSS, closed-source-internal compatible
- Vanilla JS first; React/Vue/Svelte/Angular bindings exist but optional. We use the vanilla `Konva` global from `import Konva from 'konva'`, then wrap interactions in Alpine.js components — no framework conflict.
- Documented [Interactive Building Map demo](https://konvajs.org/docs/sandbox/Interactive_Building_Map.html) is essentially our floor-plan use case.
- Layer/group/shape model maps cleanly to: walls layer + equipment layer + annotations layer. Equipment shapes are `Konva.Group` instances with custom data attrs (`equipment_id`, `room_id`).
- Scene serialises to JSON via `stage.toJSON()` — ready for storage in a `floor_plans.canvas_json` MySQL JSON column.

**Persistence shape (planning only — concrete schema in roadmap):**
```
floor_plans
├── id
├── project_id (FK projects)
├── room_id (FK rooms, nullable for whole-site plan)
├── canvas_json (LONGTEXT — Konva scene)
├── thumbnail_path (path within DocumentArtifactStorage::TYPE_DRAWING)
├── version (int — bumped on save)
├── created_by, created_at, updated_at
```

**Integration with existing stack:**
- Drawing UI: `resources/views/floor-plans/edit.blade.php` — Alpine.js component holds Konva stage instance. Save POSTs `canvas_json` to a `FloorPlanController@update` endpoint.
- Auto-place equipment: server-side service reads `canvas_json`, parses room boundary polygon, places equipment groups based on `ProjectDataService` room equipment list. Returns updated `canvas_json` for client to re-render.
- Render to PDF: server-side Blade view loads `canvas_json` via a small JS shim that reconstructs the Konva stage in headless Chrome (Browsershot already executes JS). Wait selector on `window.appReady === true`, then snapshot.

**Bundle considerations:** ~155 kB min+gz is acceptable for an editor-only screen. Use Vite code-splitting so the floor-plan editor chunk only loads on `/floor-plans/*` routes — does not bloat dashboard or project pages.

### Rationale

Konva.js is the only mainstream MIT-licensed canvas library that (a) is genuinely vanilla-JS-first, (b) has a documented floor-plan demo, and (c) serialises scenes to JSON cleanly. TLDraw and Excalidraw are React-only — adopting them would mean shipping React just for one screen, conflicting with our Alpine.js+Blade architecture and adding ~600 kB of framework code for a single editor. Fabric.js is a credible alternative (also MIT, vanilla, similar size) but Konva has stronger floor-plan/seat-map precedent in its docs and a slightly cleaner shape API. jsPlumb is archived and primarily a connection-line library, not a free-draw canvas.

---

## 3. DXF / DWG Export

### DXF (target — feasible)

| Library | Side | License | Maint | Verdict |
|---------|------|---------|-------|---------|
| **DXFighter** (`enjoping/DXFighter`) | PHP server | BSD-3-Clause | **Unmaintained** (author: "no active dev") | ⚠ Workable; vendor it |
| `digitalfotografen/DXFwriter` | PHP server | unclear | Stale | ✗ |
| `KOYU-Tech/DXF-Creator-for-PHP` | PHP server | unclear | Stale | ✗ |
| `dxf-writer` (npm) | Node/browser | MIT | Active | Alt if PHP route fails — adds Node child process |
| `@tarikjabiri/dxf` (npm) | Node/browser | MIT | Active | Best JS DXF lib if forced to Node |

**Recommendation:** DXF export is a **stretch goal for v1.3** — only attempt if Phase 19 schedule allows. Implementation path:
1. Server-side: `DxfExportService` reads Konva `canvas_json`, walks layers, emits DXF via DXFighter (BSD-3 — vendor a specific commit since upstream is unmaintained).
2. Test only against AutoCAD LT 2024+ and BricsCAD trial (the 21CAV team's known consumers).
3. Output written through `DocumentArtifactStorage` under TYPE_DRAWING with `.dxf` extension.

Flag for the planner: DXFighter is unmaintained. Vendor it (commit it into `app/Vendor/DXFighter/`) rather than depending on Packagist updates that won't come.

### DWG — skip

- **LibreDWG**: GPLv3 — file-level copyleft. Linking from a closed-source PHP service is a **hard license blocker**.
- **Teigha (ODA)**: Paid. ODA membership starts at thousands USD/year. Out of scope.
- **No middle ground exists.**

**Verdict:** DXF is our realistic ceiling for v1.3. AutoCAD opens DXF natively, so DWG export buys us nothing the customer can't already consume. **Confirmed: DXF is the ceiling.**

---

## 4. PDF / SVG Export — Reuse Browsershot

**Verdict: yes, ride the existing pipeline.** No new packages needed.

### How it works per drawing type

| Drawing | Source | PDF path | SVG path |
|---------|--------|----------|----------|
| **System schematic** | D2 CLI emits SVG | Wrap SVG in Blade `<img>`/inline → Browsershot → PDF | D2-emitted SVG written direct to disk |
| **Rack elevation** | Blade SVG view | Browsershot (existing) | Render Blade-as-string → save `.svg` |
| **Floor plan** | Konva `canvas_json` | Blade view boots Konva headlessly → Browsershot waits for `window.konvaReady === true` → PDF | Konva stage `toCanvas()` then serialise; or have the headless page eval `stage.toSVG()` (Konva supports SVG export) and dump to disk |

### Caveats for canvas content under Browsershot

- **Wait for ready signal.** Browsershot defaults to fixed-time waits unless we use `setOption('waitUntil', 'networkidle0')` or a custom selector. For Konva floor plans, set a `window.appReady = true` flag after the stage hydrates from JSON, then `->waitForFunction('window.appReady')`.
- **Font availability.** Drawing labels use the Figtree font already loaded by `app.blade.php`. Browsershot passes through CSS @font-face — no extra config.
- **Headless Chrome JS heap.** Large floor plans (200+ shapes) may need `--js-flags=--max-old-space-size=512`. Add to Browsershot setNodeArguments call only if profiling shows OOM.
- **Vector PDF quality.** Browsershot/Chrome emits proper vector PDFs (not rasterised) when the input is SVG. Confirmed in our existing site-survey/RAMS PDFs (post 260427-qvr migration).

### Queue compatibility

All drawing generation jobs follow the existing pattern:
```
BuildSchematicJob, BuildRackElevationJob, BuildFloorPlanPdfJob
extends ShouldQueue, timeout=300, tries=2
status: pending → generating → completed | failed
artifact path resolved via DocumentArtifactStorage
```

No queue-config changes needed.

### DocumentArtifactStorage extension

Add a new type constant alongside existing TYPE_RAMS / TYPE_OM / TYPE_WORKSHEET / TYPE_CABLE / TYPE_SNAGGING:

```php
public const TYPE_DRAWING = 'drawing'; // sub-dir: documents/drawings/
```

Stores: `schematic-{project_ref}.svg`, `schematic-{project_ref}.pdf`, `rack-{rack_id}.svg`, `floor-plan-{room_id}.pdf`, etc. Existing legacy-path fallback in `readPath()` requires no change for new type (no legacy artifacts exist).

**O&M Manual integration:** OmManualDocxService gains an "Appendix C: Drawings" section. PhpWord supports inline images via `addImage()` — feed PNG conversions of the SVGs (Browsershot can also emit PNG from the same SVG inputs in one shot).

---

## 5. AV-Domain Stencil/Symbol Library

### Existing options surveyed

| Source | License | Format | Verdict |
|--------|---------|--------|---------|
| **AVIXA Architectural Drawing Symbols Standard** | Free standard PDF | PDF spec — descriptive, not a usable vector lib | Reference standard, not a deliverable asset |
| **Drawio-AV-Design** ([Fe-Lit/Drawio-AV-Design](https://github.com/Fe-Lit/Drawio-AV-Design)) | MIT | drawio XML stencils | Decent starting point for a few specific devices; not general AV |
| **krrkrr/draw-io-av-templates** | (verify per repo) | drawio XML | Smaller/less curated |
| **NetZoom Visio stencils** | Commercial | Visio VSS | Out — paid + Visio-locked |
| **D-Tools / SymbolLogic** | Commercial AV CAD | proprietary | Out — paid |
| **AVSnap** | Freeware (not OSS) | proprietary | License unclear; not redistributable |
| **noun-project / iconscout architecture icons** | Mixed (per-icon) | SVG | Generic, not AV-specific |

### Recommendation: hybrid build

**No OSS AVIXA-compliant SVG library exists.** What does exist:
1. AVIXA publishes a **descriptive standard** — symbol shapes documented in a PDF spec, not a vector library.
2. The [Fe-Lit/Drawio-AV-Design](https://github.com/Fe-Lit/Drawio-AV-Design) MIT library covers ~10–15 specific commercial devices (Luminex, Blackmagic, AJA) — not a general AV symbol set.

**Plan:**
- **In-house SVG symbol pack** at `resources/svg/av-symbols/{display,mic,dsp,amp,switcher,camera,speaker,...}.svg`. Target ~25 shapes for v1.3 covering 90% of 21CAV's standard equipment categories. Each shape ~1–5 kB — total <100 kB.
- Symbols follow AVIXA conventions where they exist (mic = circle with diaphragm slash, speaker = trapezoid, etc.).
- D2 schematics reference these via `shape: image; icon: file:///app/resources/svg/av-symbols/display.svg`.
- Floor-plan Konva tool ships a stencil palette pulling the same SVGs via `Konva.Image.fromURL()`.
- Optionally seed-import a curated subset from Drawio-AV-Design (MIT — attribution-compatible) for any device-specific stencils that match 21CAV inventory.

**Decision:** **Build, don't buy.** Total effort estimated at ~1 day of an illustrator/design pass + a `<EquipmentSymbol>` Blade component for previews. Pays back across all three drawing types (schematic, floor plan, O&M illustrations) and is reusable for future client portal v1.4.

---

## 6. Installation Summary

### Server-side (one-time)

```bash
# D2 binary (production server provisioning)
curl -fsSL https://d2lang.com/install.sh | sh -s -- --version v0.7.1
# Verify
d2 --version  # expects: v0.7.1
```

ELK is fetched on first ELK-layout invocation; no extra install needed. Bundled dot/dagre layouts ship inside the D2 binary.

### Composer (optional — DXF stretch only)

```bash
# Only if DXF export is in scope for the milestone
# Recommended: vendor the lib at app/Vendor/DXFighter/ to insulate from upstream abandonment
# (Author has marked the project as not actively developed)
```

### npm (Vite-bundled front-end)

```bash
npm install konva@^10.2.5
# No new dev deps — Vite + Tailwind + Alpine already configured
```

### No additions to existing list

- **No React, no Vue** — both rejected (TLDraw/Excalidraw out)
- **No Mermaid** — D2 covers schematic needs better
- **No PlantUML** — GPL blocker
- **No drawio embed** — postMessage iframe complexity not justified
- **No LibreDWG / Teigha** — license blocker / paid
- **No node-canvas / Puppeteer (separate)** — Browsershot already wraps Puppeteer

---

## 7. Alternatives Considered (and why rejected)

| Category | Recommended | Alternative | Why Not |
|----------|-------------|-------------|---------|
| Schematic | D2 CLI | Mermaid SSR | Adds Node.js infra, duplicates Browsershot capability, weaker styling control |
| Schematic | D2 CLI | drawio iframe | Editor-first not generator-first; iframe bundle multi-MB; postMessage complexity |
| Schematic | D2 CLI | PlantUML | GPL-3.0 license blocker for closed-source internal app |
| Schematic | D2 CLI | Custom SVG only | Reinvents layout algorithm; D2 gives free dot/elk graph layout |
| Rack | Custom Blade SVG | D2 nested grids | D2 grid layout fights pixel-precise U-positions |
| Canvas | Konva.js | TLDraw | React-only, ~600 kB+, conflicts with Alpine.js |
| Canvas | Konva.js | Excalidraw | React-only + hand-drawn look unsuitable for client deliverables |
| Canvas | Konva.js | Fabric.js | Equally valid; Konva edges out on floor-plan precedent + cleaner shape API |
| Canvas | Konva.js | jsPlumb | Archived 2023; connection-focused not free-draw |
| DXF | DXFighter (vendored) | Node `dxf-writer` child process | Adds Node runtime to PHP server for one feature |
| DWG | Skip | LibreDWG | GPLv3 hard blocker |
| DWG | Skip | Teigha | Paid, expensive |
| Stencils | In-house SVG set | NetZoom/D-Tools | Paid + Visio-locked |
| Stencils | In-house SVG set | AVSnap | Freeware not OSS, redistribution unclear |

---

## 8. Confidence Assessment & Open Risks

| Area | Confidence | Notes |
|------|------------|-------|
| D2 v0.7.1 + MPL-2.0 + CLI install | HIGH | Verified via official GitHub releases page |
| Konva v10.2.5 + MIT + vanilla JS | HIGH | Verified via npm + official docs floor-plan demo |
| Browsershot reusability for canvas content | HIGH | Browsershot already executes JS; pattern proven in existing codebase post-260427-qvr |
| DXFighter health | LOW | Author publicly marks it inactive; vendoring required if used |
| AV stencil OSS availability | MEDIUM | Searched thoroughly; no AVIXA-compliant OSS SVG set found. AVIXA standard is descriptive only |
| TLDraw React-only assertion | HIGH | Confirmed via tldraw.dev — "Infinite Canvas SDK for React" |
| Excalidraw React-only assertion | HIGH | Confirmed via npm @excalidraw/excalidraw peer deps |
| PlantUML GPL-3.0 | HIGH | Long-standing public fact, license unchanged |
| LibreDWG GPL-3.0 | HIGH | FSF project, license verified |

### Open risks to flag for roadmap

1. **DXFighter abandonment** — if DXF export is committed to v1.3, vendor the library and own a small PHP file. Estimate +0.5 day.
2. **D2 ELK layout determinism** — D2 changed default layouts across minor versions. Pin exactly v0.7.1 and snapshot-test schematic SVGs in CI to catch upstream drift if we ever upgrade.
3. **Browsershot waiting strategy for Konva** — first floor-plan PDF generation may need 2–3 iterations to land the right `waitForFunction` selector. Budget Phase 18 review wave for this.
4. **AVIXA-compliance audit** — if a client RFP later requires strict AVIXA symbol compliance, the in-house pack needs a formal review pass against the AVIXA standard PDF. Add as v1.4 backlog item.

---

## 9. Sources

### High-confidence (official docs / repos)

- [D2 GitHub releases](https://github.com/terrastruct/d2/releases) — v0.7.1 confirmed
- [D2 install docs](https://github.com/terrastruct/d2/blob/master/docs/INSTALL.md) — CLI install path
- [D2 grid diagrams](https://d2lang.com/tour/grid-diagrams/) — nested grid capability
- [Konva.js npm](https://www.npmjs.com/package/konva) — v10.2.5
- [Konva floor plan demo](https://konvajs.org/docs/sandbox/Interactive_Building_Map.html) — purpose-built precedent
- [Konva framework bindings](https://konvajs.org/docs/guides/why-konva.html) — vanilla-first confirmed
- [Spatie Browsershot docs](https://spatie.be/docs/browsershot/v4/introduction) — JS execution + PDF capability
- [LibreDWG GNU project](https://www.gnu.org/software/libredwg/) — GPLv3 license confirmed

### Medium-confidence (verified via repo inspection)

- [DXFighter GitHub](https://github.com/enjoping/DXFighter) — BSD-3, marked inactive by author
- [Drawio-AV-Design GitHub](https://github.com/Fe-Lit/Drawio-AV-Design) — MIT, last updated 2025-09-30
- [Fabric.js npm](https://www.npmjs.com/package/fabric) — v6.4.3 (research date) / v7.x rolling
- [TLDraw npm](https://www.npmjs.com/package/tldraw) — v4.5.10, React peer dep
- [@excalidraw/excalidraw npm](https://www.npmjs.com/package/@excalidraw/excalidraw) — React peer dep

### Reference

- [AVIXA Symbols Standard](https://www.avixa.org/standards/audio-video-and-control-architectural-drawing-symbols) — descriptive standard (not a library)
- [DrawIO embed mode](https://www.drawio.com/doc/faq/embed-mode) — Apache-2.0, iframe + postMessage
- [jsPlumb community-edition repo](https://github.com/jsplumb/community-edition) — archived 2023
