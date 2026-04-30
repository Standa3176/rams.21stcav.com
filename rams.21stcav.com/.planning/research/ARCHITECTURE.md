# Architecture — v1.3 Technical Drawings & Schematics

**Project:** RAMS Platform — AV Operations System
**Milestone:** v1.3 (Phases 17–20)
**Researched:** 2026-04-30
**Scope:** Integration architecture only. The existing Laravel 12 / MVC + Service / Browsershot / Alpine.js / DocumentArtifactStorage / queue stack is LOCKED. This document describes how schematic, rack-elevation, floor-plan, and drawing-export capabilities slot into that stack without breaking the canonical 4-tier merge contract.

---

## 1. Recommended Architecture (one-paragraph)

Drawings are first-class artifacts produced from the same canonical project dataset that powers RAMS / O&M / Worksheet / Cable Schedule. A new `project_drawings` table stores **(a) auto-generated source state** (regenerable, tracks which canonical revision it was generated from) and **(b) optional user-edited canvas overrides** in the same row. Generators follow the existing pattern — thin controller → orchestration service → builder/renderer service that **reads only from `ProjectDataService::resolve()`**. PDF export rides the existing `PdfRenderService::fromBlade()` pipeline (Browsershot → chrome-headless-shell). Editing is browser-side (Konva.js, vanilla — no React) loaded as a separate Vite entry only on `/projects/{project}/drawings/*` routes so the rest of the platform's Alpine.js footprint is unaffected. DOCX handover via `OmManualDocxService` consumes a flattened **PNG snapshot** of each drawing (PhpWord cannot embed SVG reliably). All long-running renders are queued as `BuildDrawing*Job` classes following the four existing `Build*Job` patterns exactly (status state machine, idempotent notification timestamp, `failed()` admin alert).

---

## 2. Data Model — Where Drawings Live

### 2.1 Core Table: `project_drawings`

A single polymorphic-ish table holds every drawing kind (schematic, rack, floor plan). Per-project granularity is the wrong level — most projects have several rooms, each with its own floor plan and possibly its own rack. Per-drawing (with parent FK to `project_id` and optional `site_survey_room_id` / `rack_index`) keeps queries simple and matches how the existing `worksheets`, `cable_schedules`, and `om_manuals` tables relate.

```
project_drawings
├── id (PK)
├── project_id (FK projects, indexed)
├── site_survey_room_id (FK, NULLABLE — set for floor plans + per-room schematics)
├── kind (ENUM: 'schematic' | 'rack' | 'floor_plan')
├── rack_label (string NULL — e.g. "Comms Rack 1" — set when kind=rack)
├── version (integer, default 1)
├── superseded_by_id (FK self NULL — points to the replacement when regenerated)
│
├── source_data (JSON)      — INPUT snapshot from ProjectDataService.resolve()
│                             at generation time. Lets us detect "does the
│                             source equipment list still match the drawing?"
│
├── generated_svg (LONGTEXT NULL) — auto-generated SVG (D2 → svg or our own
│                                   builder → svg). Always regenerable from
│                                   source_data; nullable so a fully-manual
│                                   floor plan can exist with NULL here.
│
├── canvas_state (JSON NULL)      — Konva scene graph for user edits. NULL
│                                   until the user opens the editor and saves.
│                                   Once non-null, this is the source-of-truth
│                                   for rendering. Auto-regen still archives
│                                   the previous row (see §2.3).
│
├── thumbnail_png_path (string NULL) — relative path on the `documents` disk
│                                      under `drawings/thumbnails/` for list
│                                      views. Cheap PNG, ~200×150.
│
├── status (ENUM: 'draft' | 'generating' | 'ready' | 'failed')
├── error_message (text NULL)
├── filename (string NULL — stored PDF/SVG/PNG export filename)
├── completion_email_sent_at (timestamp NULL)  -- mirrors NOTF-01 idempotency
├── failed_email_sent_at (timestamp NULL)      -- mirrors NOTF-04 idempotency
├── generated_by (FK users NULL)
├── timestamps (created_at, updated_at)
└── deleted_at (SoftDeletes — same as RamsDocument, OmManual, Worksheet)
```

**Why one table over per-kind tables:** Three near-identical models (`ProjectSchematic`, `ProjectRackDrawing`, `ProjectFloorPlan`) would duplicate the status state machine, idempotency timestamps, soft-delete handling, and policy logic. The kind-specific differences are just "which fields drive the auto-generation" — that belongs in the builder/renderer service, not the model. This matches the H-07 ethos of *one* `DocumentArtifactStorage` over four bespoke disk layouts.

**Why JSON columns for `source_data` / `canvas_state`:** MySQL 8 has indexable JSON. Drawings store inherently structured-but-evolving data (Konva scene graphs change shape with each Konva release; we don't want a migration per shape type). Same pattern as `ramsDocuments.reviewed_data` + `omManuals.generated_data`.

### 2.2 Source-of-Truth Resolution Order (mirrors ProjectDataService 4-tier idea)

When the renderer needs to draw the artifact:

```
canvas_state IS NOT NULL  →  render from canvas_state (user-edited)
canvas_state IS NULL      →  render from generated_svg (auto)
generated_svg IS NULL     →  re-run auto-generation from source_data first
source_data is stale      →  warn UI; do not auto-regenerate without consent
```

This mirrors `reviewed_data > survey_data > quotewerks_sql > extracted_data` exactly: the highest-priority, most-recently-edited tier wins, and lower tiers are fallbacks. **Critically, a user-edited drawing is never silently overwritten** — the regenerate flow must explicitly archive the old row (see §2.3).

### 2.3 Versioning — Archive Pattern (mirrors InstallProgrammeService)

Phase 12 set the precedent: when an install programme is regenerated, the old one's status moves to `archived` and the row stays in the table for audit. v1.3 follows the same pattern:

```php
// Pseudocode for ProjectDrawingService::regenerate(Project, kind, ?roomId)
$existing = ProjectDrawing::where('project_id', ...)
    ->where('kind', $kind)
    ->where('site_survey_room_id', $roomId)
    ->whereIn('status', ['draft', 'ready'])
    ->latest('version')
    ->first();

$new = $existing
    ? $existing->replicate(['canvas_state', 'thumbnail_png_path', 'filename'])
    : new ProjectDrawing();

$new->version = ($existing?->version ?? 0) + 1;
$new->source_data = $projectDataService->resolve($project);
// ... auto-build generated_svg from source_data
$new->save();

if ($existing) {
    $existing->update(['superseded_by_id' => $new->id]);
}
```

**Critical rule (PITFALLS-bound):** When `$existing->canvas_state` is non-null (user has edited), the regen flow **must prompt** the user — "this will discard your manual edits unless you transfer them. Continue / cancel". This mirrors the install-programme regen UX (PM confirm gate), not a silent overwrite. The rule lives in the *service*, not the controller, so Job-driven regens see it too.

### 2.4 Migration Safety

- **Nullable-first:** All v1.3 columns are added as NULL with backfill defaults — no downtime, no broken existing pipelines.
- **Forward-only:** No data backfill needed for old projects (they simply have no drawings — the empty state is the default).
- **Polymorphic note:** We deliberately do *not* use Laravel polymorphic relations (`drawables_type` / `drawables_id`). The kind discriminator (`kind`) is enough; polymorphic FKs cannot be enforced at the DB level and add eager-loading complexity that gives nothing back here.
- **Confidence:** HIGH — pattern is identical to the proven nullable-first migrations of v1.2 (`commissioning_items`, `time_entries`, `install_tasks`).

---

## 3. Service Layer — Generators Fit ProjectDataService

### 3.1 New Services (live under `app/Services/Drawings/`)

The `app/Services/{Domain}/` sub-namespace pattern (already used: `app/Services/OandM/`, `app/Services/Cable/`, `app/Services/Survey/`, `app/Services/Rams/`, `app/Services/Worksheet/`) is the right home — keeps the flat-namespace `app/Services/` from getting a dozen new top-level entries.

```
app/Services/Drawings/
├── DrawingService.php                  -- orchestration (createForProject,
│                                          regenerate, archiveExisting, etc.)
│                                          MIRRORS InstallProgrammeService.
│
├── SchematicGeneratorService.php       -- consumes ProjectDataService.resolve()
│                                          → builds D2 source string → invokes
│                                          d2 CLI (or in-process Mermaid) →
│                                          captures SVG → writes to
│                                          ProjectDrawing.generated_svg.
│                                          NO AI call.
│
├── RackElevationGeneratorService.php   -- consumes equipment list, filters by
│                                          rack-mounted flag + U-height,
│                                          generates SVG via direct
│                                          server-side rendering (no D2 — racks
│                                          are simpler, custom SVG builder is
│                                          cheaper than learning a DSL).
│                                          NO AI call.
│
├── FloorPlanInitialStateService.php    -- consumes survey rooms + equipment,
│                                          produces initial Konva scene graph
│                                          (background grid + equipment chips
│                                          stacked at room origin). User
│                                          drags into position. NO auto-render
│                                          to SVG until user saves.
│
├── DrawingExportRendererService.php    -- single entrypoint for "drawing X →
│                                          PDF" via PdfRenderService::fromBlade
│                                          and "drawing X → PNG snapshot" via
│                                          a Browsershot screenshot pass for
│                                          DOCX embedding.
│
└── DrawingDataResolverService.php      -- TIGHT WRAPPER around
                                          ProjectDataService::resolve() that
                                          reshapes the canonical dataset into
                                          drawing-specific shapes (signal-flow
                                          adjacency for schematics, U-stacks
                                          for racks, room-grouped chips for
                                          floor plans). Read-only. Does not
                                          merge any new data sources.
```

**Cardinal rule (carries the DATA-03 contract forward):** Every drawing builder reads the canonical dataset *only* through `DrawingDataResolverService` → `ProjectDataService::resolve()`. **No drawing service ever touches `extracted_data`, `reviewed_data`, `survey_data`, or `quotewerks_sql` directly.** This matches `RamsBuilderService`, `WorksheetGeneratorService`, `OmManualDocxService`, `CableScheduleGeneratorService`, and `InstallTaskGeneratorService` — all consume `ProjectDataService` only. Extending the rule to v1.3 closes the door on a pattern drift.

**Why a thin `DrawingDataResolverService` wrapper rather than each generator calling `ProjectDataService` directly:** The schematic builder needs *signal-flow adjacency* (source equipment → cable → destination equipment), which is a derived shape, not a raw `ProjectDataService` key. Reshaping logic doesn't belong in a builder (which should be a pure renderer); it doesn't belong in `ProjectDataService` (whose contract is "all generators consume the same shape"). A drawings-specific resolver is the right home — it can call `$projectDataService->resolve($project)` and then produce schematic-shaped / rack-shaped / floor-plan-shaped views over the canonical data without polluting the canonical dataset with renderer concerns.

### 3.2 Auto-Generate vs User-Edit Boundary

| Drawing kind | Initial state | User can edit? | Re-derive on equipment change? |
|---|---|---|---|
| **Schematic** (Phase 17) | Auto-generated SVG from D2 source | YES (override via canvas_state) | YES, but archives prior version. PROMPT if canvas_state is non-null. |
| **Rack elevation** (Phase 18) | Auto-generated SVG from equipment list | LOW priority — rack layouts rarely need drag edits, but possible | YES (auto-regen non-destructive when canvas_state is null). |
| **Floor plan** (Phase 19) | Initial Konva scene graph (room outline + equipment chips at origin) | YES — primary mode (drag-to-place is the whole point) | NO — floor plans are inherently user-edited. Equipment additions surface as a "new equipment available — add to floor plan?" prompt, not an auto-place. |

This mapping is exactly the pattern Phase 12 codified (`InstallTaskGeneratorService` → re-generation archives prior; PM confirm gate before activation). **Zero new patterns in v1.3** — just three new applications of an already-shipped pattern.

### 3.3 Service Wiring (existing-vs-new)

Each generator service is constructor-injected with `ProjectDataService` (and where needed, the `DocumentArtifactStorage`, `PdfRenderService`, `AIManager` for Phase 17 layout heuristics — see Phase 17 note below). Bound as singletons in `AppServiceProvider` if they accumulate request-scoped state (mirrors `ProjectDataService`'s memoisation pattern).

**AI usage (Phase 17 only, optional):** `AIManager::run(SchematicLayoutPrompt, $context)` may be used for *layout heuristic* hints (signal-flow grouping order, e.g. "audio chain first, video chain second") — never to *invent* equipment or connections. This honours the v1.0 constraint: AI formats, never invents. If AI fails, fall back to a deterministic ordering (alphabetical-by-room, then category). The static-fallback pattern is identical to `MethodStatementService::getDefaultFiveStage()`.

---

## 4. Render Pipeline — Schematic Generation Strategy

### 4.1 Decision Matrix

| Strategy | Schematics (17) | Rack (18) | Floor plans (19) |
|---|---|---|---|
| **A. Server-side text-to-diagram** (D2 / Mermaid CLI) → SVG → Blade → Browsershot → PDF | **CHOSEN** | Not chosen | Not chosen |
| **B. Server-side custom SVG builder** (PHP code emits SVG strings) | Backup if D2 binary unavailable | **CHOSEN** | Not chosen |
| **C. Browser canvas (Konva)** → save scene graph → headless-render PDF | Edit-mode override only | Edit-mode override only | **CHOSEN** |

### 4.2 Phase-by-Phase Render Pipeline

#### Phase 17 — Schematics (text-to-diagram)

- **Generation:** `SchematicGeneratorService` consumes `DrawingDataResolverService::adjacencyForProject($project)` → builds a D2 source string (text, ~50 lines for a typical project) → shells out to the `d2` CLI binary (`d2 in.d2 out.svg`) → reads SVG → writes to `ProjectDrawing.generated_svg`. **Stateless, sub-second**, no headless browser needed for the SVG step.
- **Why D2 over Mermaid:** D2 is Go-native and produces SVG without a headless browser; Mermaid's official CLI uses Puppeteer (which means another Chrome process at render time on top of the one Browsershot already runs). D2 is also designed for "system architecture diagrams" — exactly the AV signal-flow shape — and has better edge routing for dense node clusters.
- **Why not custom-PHP-emits-SVG:** Edge routing for a 30-node schematic is a non-trivial graph algorithm. We are not in the business of writing Sugiyama layered layouts in PHP. D2 has solved this.
- **PDF export:** `resources/views/pdf/drawings/schematic.blade.php` embeds the SVG inline (Browsershot renders inline `<svg>` cleanly). `PdfRenderService::fromBlade('pdf.drawings.schematic', [...], $writePath)` delegates to Browsershot, which is already proven for RAMS/O&M/Survey.
- **Edit override:** If `canvas_state` is non-null, the schematic Blade view loads Konva client-side and replaces the static SVG with the edited scene graph. PDF render then uses Browsershot's `waitUntilNetworkIdle()` to capture the post-Konva-render canvas as PDF — same exact pattern as the existing `PdfRenderService` flow, just with one extra `wait` option.
- **Confidence:** HIGH for D2 SVG generation; MEDIUM for the edit-override Browsershot wait pattern (will need a Phase 17 spike to confirm Konva→PDF round-trip is faithful).

#### Phase 18 — Rack Elevations (custom SVG builder)

- **Generation:** `RackElevationGeneratorService` filters equipment for `rack_mounted=true` + `u_height`, sorts top-to-bottom, builds a flat SVG via PHP string construction (~300 LOC). A rack elevation is a single column of stacked rectangles with labels — the simplest possible diagram type. D2 is overkill; Konva is overkill.
- **Why not D2:** D2's strength is *graph-shaped* diagrams (nodes + edges). Racks are *list-shaped* (sequence of U-blocks). A 100-line PHP `SvgBuilder` helper is faster than learning D2's grid syntax for this case.
- **Why not Konva:** Editing a rack means *swapping U-positions* — easier as a drag-and-drop list than a free-form canvas. Phase 18 does not need full canvas; it needs a list-reorder UI. **Phase 18 is the smallest UI footprint of the four.**
- **PDF export:** Same `PdfRenderService::fromBlade('pdf.drawings.rack', [...])` path.
- **Confidence:** HIGH — custom SVG for a fixed layout shape is a solved problem.

#### Phase 19 — Floor Plans (browser canvas, Konva.js)

- **Generation:** `FloorPlanInitialStateService` produces an initial Konva scene graph: room outline rectangle (from survey room dimensions if captured, otherwise a default) + equipment chips stacked at origin awaiting drag-to-place. **No SVG generated server-side initially** — `generated_svg` stays NULL until the user's first save triggers a server-side SVG snapshot (or we simply render directly from `canvas_state` JSON every time).
- **Why Konva over TLDraw / Excalidraw:**
  - TLDraw is React-only. Adopting React solely for floor plans contradicts the locked decision in v1.2 STACK.md ("No SPA framework warranted. Existing Blade + Alpine.js is sufficient. Adding a second reactive framework fragments the codebase.")
  - Excalidraw is also React-based and is more aimed at hand-drawn whiteboarding than precise floor plans.
  - Konva is **vanilla JS**, ~140 KB minified, embeds inside an Alpine.js component without bringing React into the bundle. Established (active maintenance), MIT, has snap-to-grid + custom shape support — exactly what a floor plan editor needs.
- **PDF export:** Server renders a hidden Blade view that includes the Konva runtime + the scene graph JSON; Browsershot loads it, waits for `Konva.stage.toDataURL()` to resolve via a `window.__drawingReady = true` signal, then captures as PDF. Same Browsershot binary, same `PdfRenderService` shape — **the wait-for-JS-render pattern is the only new bit**, and that's a 5-line addition to `PdfRenderService` (see §4.3).
- **DOCX export (for O&M handover):** Browsershot's `screenshot()` captures the same hidden page as PNG → embedded via PhpWord `addImage($pngPath)`.
- **Confidence:** HIGH for Konva itself; MEDIUM for the Browsershot-waits-for-Konva pattern (Phase 19 spike will confirm).

#### Phase 20 — Drawing Export

- Phase 20 is **purely a pipeline phase** — no new data, no new generators, only export formats and DocumentArtifactStorage wiring (see §6).

### 4.3 Required PdfRenderService Extension (one tiny addition)

`PdfRenderService::fromBlade()` today renders Blade → HTML → Browsershot → PDF (synchronous). Phases 17 (edit override) + 19 (Konva canvas) need Browsershot to **wait for client-side JS** to finish before snapshotting. Add an optional flag:

```php
// New option: 'waitForJs' (bool, default false)
//   When true, Browsershot uses ->waitUntilNetworkIdle() and then
//   ->waitForFunction('window.__drawingReady === true') before snapshot.
//   The Blade view is responsible for setting window.__drawingReady = true
//   after Konva (or any other client renderer) has finished drawing.
$shot->waitUntilNetworkIdle()
     ->waitForFunction('window.__drawingReady === true');
```

This is a five-line addition; it does not change any existing call site (default `false`). Existing RAMS / O&M / Survey PDF renders are unaffected.

### 4.4 What Lives Server-Side vs Browser-Side

| Concern | Server | Browser |
|---|---|---|
| Auto-generate schematic SVG | YES (D2 CLI) | — |
| Auto-generate rack SVG | YES (custom builder) | — |
| Auto-generate floor plan initial state | YES (Konva JSON, sent to client) | — |
| User edits / drag-to-place | — | YES (Konva) |
| Save canvas state | (receives POST) | YES (Axios, debounced blur-save) |
| Render to PDF | YES (Browsershot, `PdfRenderService`) | — |
| Render to PNG (for DOCX embed) | YES (Browsershot screenshot) | — |
| Render to SVG (Phase 20 export) | YES (server captures Konva `stage.toSVG()` via Browsershot eval) | — |

---

## 5. Frontend Integration — Drawing Tool Placement

### 5.1 Routes

```
GET  /projects/{project}/drawings                                -- index (all kinds)
GET  /projects/{project}/drawings/{drawing}                      -- view (read-only PDF preview)
GET  /projects/{project}/drawings/{drawing}/edit                 -- editor (Konva loaded here ONLY)
POST /projects/{project}/drawings/{drawing}/canvas               -- AJAX save canvas_state
POST /projects/{project}/drawings/regenerate                     -- enqueue BuildDrawingJob
GET  /projects/{project}/drawings/{drawing}/download/{format}    -- pdf|svg|png|dxf
```

Authenticated only. Policy: `ProjectDrawingPolicy` mirroring `RamsDocumentPolicy` (admins all, owners own). **No public-token routes for v1.3** — drawings are internal-engineer + O&M-handover, not site-survey-style external.

### 5.2 Alpine.js + Konva Coexistence (the key frontend decision)

The platform-wide JS bundle is Alpine.js + axios + signature_pad + frappe-gantt + dexie (v1.2). Adding Konva globally would push every page to ~140 KB more bundle for a feature 95% of admin users never touch.

**Solution: separate Vite entry, lazy-loaded only on `/drawings/edit` routes.**

```js
// vite.config.js — extend the existing input array
export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/drawings.js',   // <-- NEW, only loaded on drawing edit pages
            ],
            refresh: true,
        }),
    ],
});
```

```blade
{{-- resources/views/projects/drawings/edit.blade.php --}}
@vite(['resources/css/app.css', 'resources/js/drawings.js'])
```

```js
// resources/js/drawings.js — separate bundle
import Konva from 'konva';
import './drawings/floor-plan-editor.js';
import './drawings/schematic-editor.js';
import './drawings/rack-editor.js';
window.Konva = Konva;
```

```html
<div x-data="floorPlanEditor()" x-init="mount($refs.stage)">
    <div x-ref="stage" class="konva-stage"></div>
</div>
```

The Konva component is a vanilla-JS module that Alpine wraps via `x-data` + `$refs`. **No React, no second reactive framework, no SPA route.** Same pattern as v1.2's frappe-gantt integration (proven shipped).

### 5.3 Save UX (debounced auto-save)

v1.2 codified the field-view AJAX-save pattern (worksheet edits, programme edits, time entries). v1.3 follows it:

- User drags equipment chip → Konva fires `dragend` → debounced (500 ms) Axios `POST /drawings/{id}/canvas` with the full `stage.toJSON()`.
- Server validates JSON shape (size cap ~500 KB), updates `canvas_state` + bumps `updated_at`, returns 204.
- Visible save indicator ("Saved 2s ago") — same pattern as worksheet-edit drawer.

**No explicit save button.** No "are you sure you want to leave" modals. Match the worksheet-edit UX users already trust.

### 5.4 Mobile / Tablet

- Konva supports touch events natively (tap, pinch-zoom, two-finger pan) — no extra library.
- Same audience as v1.2 field view: engineers on iPad / Android tablets during install. Editor must be at minimum *viewable* on tablet; full edit on tablet is a nice-to-have (drag is fine; precision placement is harder without a stylus, but acceptable for a "verify the auto-placed layout looks right" workflow).
- Floor plan editor's grid snap (Konva built-in) reduces the precision-placement-on-touch pain.
- **Out of scope for v1.3:** native iOS app, Apple Pencil tilt support — explicit per PROJECT.md.

---

## 6. Export Pipeline Integration

### 6.1 New DocumentArtifactStorage Type

Extend `DocumentArtifactStorage` with one new constant:

```php
public const TYPE_DRAWING = 'drawings';

// Update LEGACY_ROOTS — TYPE_DRAWING has no legacy (new table, post-H-07).
// Same shape as TYPE_SNAGGING (also no legacy entry).
// Update types() to include TYPE_DRAWING.
```

**Why one type, not three:** Drawings are still *one* artifact category. Sub-pathing inside the disk (`drawings/schematics/`, `drawings/racks/`, `drawings/floor-plans/`) is a *filename convention*, not a separate disk type. Three TYPE_* constants would just cause typos (`TYPE_DRAWING_RACK` vs `TYPE_DRAWING_RACKS`) without buying anything. Filename convention:

```
drawings/{kind}-{drawingId}-v{version}-{ulid}.{format}

e.g.
drawings/schematic-42-v1-01HW...AB.pdf
drawings/rack-43-v2-01HW...CD.svg
drawings/floor-plan-44-v1-01HW...EF.png
```

`thumbnail_png_path` lives at `drawings/thumbnails/{kind}-{drawingId}.png`.

### 6.2 Export Formats

| Format | Path | Notes |
|---|---|---|
| **PDF** | `PdfRenderService::fromBlade('pdf.drawings.{kind}', [...], $artifacts->writePath(TYPE_DRAWING, $filename))` | Single must-have format. Re-uses Browsershot already in the stack. |
| **SVG** | Server reads `generated_svg` (or invokes Browsershot `evaluate('Konva.stages[0].toSVG()')` for canvas-state drawings) → writes via `$artifacts->writePath(TYPE_DRAWING, $filename)`. | Must-have for clients who want vector source. |
| **PNG** | Browsershot `screenshot()` of the same Blade view used for PDF. | Used for DOCX embed (O&M handover) + thumbnails. |
| **DXF/DWG** | **NICE-TO-HAVE only.** Recommend deferring to a v1.3.x patch milestone — DXF is a non-trivial format and PHP/JS OSS support is thin (`mljs/dxf-parser` is read-only; writing DXF requires either a paid lib or a Python sidecar via `ezdxf`). Floor plans → DXF is the only realistic candidate (rack/schematic → DXF makes little CAD sense). Mark as Phase 20 stretch, ship without it if it slips. | LOW confidence — research has not found a strong OSS PHP DXF *writer*. |

### 6.3 OmManualDocxService Integration (drawings in handover)

`OmManualDocxService` already builds DOCX via PhpWord. PhpWord's `addImage()` supports raster (PNG/JPEG) cleanly; SVG is technically supported in PhpWord 1.4+ but **renders inconsistently across Word/Word Online/LibreOffice** (verified pain point — Microsoft Word SVG ticket history).

**Recommended pattern:** flatten every drawing to PNG at handover time and embed the PNG. Resolution: 1920px-wide PNG is enough for an A4 drawing page.

```php
// In OmManualDocxService — new section after equipment list.
$drawings = $project->drawings()->where('status', 'ready')->get();

foreach ($drawings as $drawing) {
    $pngPath = $artifacts->readPath(
        DocumentArtifactStorage::TYPE_DRAWING,
        $this->ensurePngForHandover($drawing)  // generates from canvas_state if missing
    );
    if ($pngPath) {
        $section->addImage($pngPath, [
            'width'  => 600,    // pt, fits A4 portrait with margins
            'height' => 'auto',
            'wrappingStyle' => 'square',
        ]);
        $section->addText($drawing->title());
        $section->addPageBreak();  // each drawing on its own page
    }
}
```

`ensurePngForHandover()` is idempotent — generates the PNG once per drawing version, caches under `drawings/handover-png/{drawingId}-v{version}.png`. **OmManualDocxService never reaches into `project_drawings.canvas_state` directly** — it goes through a small helper method on the `ProjectDrawing` model or a `DrawingExportRendererService::renderPng($drawing)` call. Honors the read-only-canonical-source rule.

**Tech-debt watch:** If a Word version eventually renders SVG cleanly across the board, switch the handover pipeline to SVG-embed for crisper print output. Until then, PNG is the safe bet.

### 6.4 Client Portal (v1.4) — Forward Compatibility

v1.4 adds a branded client portal (PORT-02: client document access — RAMS, O&M, drawings, certificates). v1.3's storage layout is **portal-ready by design**:

- Drawings live under `DocumentArtifactStorage::TYPE_DRAWING` — same access pattern as RAMS/O&M.
- A new `ProjectDrawingPolicy::viewByPortalToken(string $token, ProjectDrawing $drawing)` method can be bolted on without touching the table schema.
- PDF export is the v1.4-portal-friendly format (browsers render natively; no Konva runtime needed in the portal bundle).
- **Recommendation:** v1.3 should ship with the `access_token` column **added** (nullable, similar to `worksheets.access_token` in Phase 16's `2026_04_26_000001_add_access_token_to_worksheets_table.php`). Even if v1.3 doesn't expose token routes, having the column means v1.4 needs zero schema changes. Cheap insurance.

---

## 7. Queue + Async Strategy

### 7.1 Sync vs Queued Decision Matrix

| Operation | Sync or Queue | Reason |
|---|---|---|
| Auto-generate schematic SVG (D2) | **Sync** (sub-second) | Same as `InstallTaskGeneratorService::generate()` — fast enough that a job queue adds latency without benefit. |
| Auto-generate rack SVG (custom) | **Sync** (millisecond range) | Pure PHP string construction. |
| Build initial floor-plan Konva state | **Sync** (fast) | Just shapes Konva JSON from canonical data. |
| Save user-edited canvas_state (AJAX) | **Sync** | Standard form-handling controller; sub-100 ms. |
| Render drawing → PDF | **Queue** (`BuildDrawingPdfJob`) | Browsershot is 2–5 s. Same threshold the existing 4 `Build*Job` classes use. |
| Render drawing → PNG (for handover) | **Queue** (or piggyback on `BuildOmManualJob`) | 1–3 s. Same justification. |
| Render drawing → SVG (post-edit) | **Sync** if `generated_svg` already populated (instant); **Queue** if requires Browsershot SVG export from canvas | Conditional — easy to handle in the controller. |
| Bulk regenerate after equipment-list change | **Queue** (one job per drawing) | Plays nice with retry policy + admin failure alert pattern. |

### 7.2 Job Classes (mirror the existing four exactly)

```
app/Jobs/BuildSchematicJob.php       -- mirrors BuildOmManualJob shape exactly:
                                        $tries=2, $timeout=300; status state
                                        machine generating→ready→failed; the
                                        completion_email_sent_at + failed_email_sent_at
                                        idempotency timestamps; the failed() admin alert.

app/Jobs/BuildRackElevationJob.php   -- ditto

app/Jobs/BuildFloorPlanPdfJob.php    -- ditto. Note "Pdf" in the name because
                                        the floor plan canvas already exists;
                                        the job specifically renders to PDF
                                        (or PNG for handover).

app/Jobs/RegenerateDrawingsJob.php   -- bulk regenerate dispatcher: queries
                                        ProjectDrawing for the project, fans out
                                        one of the above three jobs per
                                        drawing. Used after a quote
                                        re-import or an equipment-list edit.
```

**Each job gets:**
- `$tries = 2` (matches existing 4 Build*Jobs)
- `$timeout = 300` (matches `BuildOmManualJob`; PDF render dominates)
- Idempotent status flips (status set BEFORE work to claim the job, set AFTER to confirm completion)
- `completion_email_sent_at` set BEFORE the mail send (per D-14 / NOTF-01 pattern)
- `failed()` method dispatches `DocumentGenerationFailedMail` to admins (per NOTF-04)

**Dispatch points:**
- User clicks "Regenerate schematic" → controller dispatches `BuildSchematicJob`.
- Quote re-imported → `ExtractQuoteJob::handle()` end → check if any drawings exist → dispatch `RegenerateDrawingsJob` (UI prompt: "your quote was re-imported; review your drawings").
- O&M generation triggered (`BuildOmManualJob`) → ensure each ready drawing has a fresh handover PNG; if not, the OmManualDocxService blocks waiting on a sync `DrawingExportRendererService::renderPng()` per drawing (acceptable: O&M itself is a queued job; another 5 s of work inside it doesn't change the user-visible experience).

### 7.3 Notifications (mirror Phase 09 / NOTF-* exactly)

Add three new mailables — same shape as `RamsReadyMail`, `OmManualReadyMail`, `WorksheetReadyMail`, `CableScheduleReadyMail`:

```
app/Mail/SchematicReadyMail.php
app/Mail/RackElevationReadyMail.php
app/Mail/FloorPlanReadyMail.php
```

Or **one** generic `DrawingReadyMail($drawing)` whose subject + body templates branch on `$drawing->kind` — matches the "kind discriminator on a single table" approach in §2.1. Recommend the single mailable to keep mail count down.

`NotificationRecipientResolver::resolveProjectRecipient($drawing->project)` reused as-is. `RAMS_NOTIFICATION_BCC` reused as-is. **Zero new notification infrastructure.**

---

## 8. Build Order Recommendation Across Phases 17–20

### 8.1 The Shared-Infrastructure-First Principle

The four phases (17 schematic / 18 rack / 19 floor plan / 20 export) share:

- The `project_drawings` table (§2)
- The `DrawingService` orchestrator + `DrawingDataResolverService` (§3)
- The `DocumentArtifactStorage::TYPE_DRAWING` constant (§6)
- The `ProjectDrawingPolicy` (§5)
- The `BuildDrawing*Job` shape (§7)
- The new `PdfRenderService` `waitForJs` option (§4.3)
- Project model `hasMany` drawings relation (§9)

**Bake this all in Phase 17.** The cost to do it earliest is small (one migration + one model + one orchestrator service + one policy + one storage constant + one route group). The cost to bolt it on after Phase 18/19 doubles because each phase's controllers / services would need rewiring.

### 8.2 Recommended Order

**Phase 17 — Schematics + Foundations**
1. Migration: `create_project_drawings_table` (all columns from §2 — even fields Phase 18/19 need).
2. Model: `ProjectDrawing` (full shape; floor-plan-specific behaviour stubs throw "implemented in Phase 19").
3. `DocumentArtifactStorage::TYPE_DRAWING` constant + tests.
4. `DrawingDataResolverService::adjacencyForProject()` (only — rack + floor methods stubbed).
5. `SchematicGeneratorService` + D2 binary integration + unit tests against fixture quotes.
6. `BuildSchematicJob` + `DrawingReadyMail`.
7. Routes: `/projects/{project}/drawings` index + schematic show/regenerate.
8. `PdfRenderService::fromBlade()` `waitForJs` option (additive, no breaking change).
9. Konva is **not** loaded in Phase 17 — schematic edit override is Phase 17.5 / pushed back unless time permits. Phase 17 ships *auto-only* schematics if needed.

**Phase 18 — Rack Elevations**
1. Extend `DrawingDataResolverService` with `rackStackForProject($project)`.
2. `RackElevationGeneratorService` + custom `SvgBuilder` helper.
3. `BuildRackElevationJob`.
4. Edit UI: simple Alpine drag-reorder list (no Konva needed for racks).
5. Routes: rack show/regenerate.

**Phase 19 — Floor Plans (Konva)**
1. Add `resources/js/drawings.js` Vite entry + Konva dependency.
2. `FloorPlanInitialStateService` (canonical data → Konva scene graph).
3. Floor plan editor Blade view + Alpine wrapper around Konva.
4. AJAX `POST /drawings/{id}/canvas` for canvas_state save.
5. `BuildFloorPlanPdfJob` (uses `PdfRenderService` with `waitForJs=true`).
6. Mobile/tablet touch-event smoke test.

**Phase 20 — Drawing Export Pipeline**
1. SVG export for canvas-state drawings (Browsershot `evaluate(stage.toSVG())`).
2. PNG export hook for OmManualDocxService handover.
3. `OmManualDocxService` patch — append "Drawings" section, embed PNGs.
4. ZIP-bundle download endpoint (all drawings for project as a single ZIP — nice-to-have for handover).
5. **DXF/DWG: spike only.** Confirm no PHP-native writer exists; document the gap; defer to a v1.3.x patch.
6. `RegenerateDrawingsJob` bulk regen entry-point; controller hook on quote re-import.

### 8.3 Cross-Phase Sequencing Rules

- **Phase 17 ships first because it's the only one with auto-generation that needs no canvas runtime.** This proves the data model, storage type, job shape, and notification flow on the simplest case.
- **Phase 19 is the riskiest** (Konva integration, Browsershot wait-for-JS, mobile testing). Schedule a Phase 19 spike day 1 to verify Browsershot can capture a Konva canvas to PDF before committing to the full plan.
- **Phase 20 cannot start until 17 + 18 + 19 are at least to draft** (it's a pipeline phase that needs at least one drawing kind to render).
- **Phase 18 can run in parallel with Phase 17** if engineering bandwidth allows (different builders, different services, no shared code beyond the table+model already shipped in Phase 17).

---

## 9. Integration Points — What Existing Code Touches

### 9.1 Must-Touch (mandatory edits to existing files)

| File | Edit | Phase |
|---|---|---|
| `app/Models/Project.php` | Add `drawings(): HasMany` relation. | 17 |
| `app/Services/DocumentArtifactStorage.php` | Add `TYPE_DRAWING` constant + update `types()`. Update tests in `tests/Unit/Services/DocumentArtifactStorageTest.php`. | 17 |
| `app/Services/PdfRenderService.php` | Add optional `waitForJs` flag (5-line addition; default `false` keeps every existing call site identical). | 17 |
| `vite.config.js` | Add `resources/js/drawings.js` entry. | 19 |
| `app/Services/OmManualDocxService.php` | Append a "Drawings" section that iterates ready drawings + embeds each PNG. | 20 |
| `app/Jobs/ExtractQuoteJob.php` | After successful re-import: if any drawings exist for project, dispatch `RegenerateDrawingsJob`. (Or guard in the controller and leave the job alone — preferable.) | 20 |
| `app/Providers/AppServiceProvider.php` | Bind new policies + (if memoising) any new singletons. | 17 |
| `routes/web.php` | Add `Route::resource('projects.drawings', ProjectDrawingController::class)` + auxiliary edit/regenerate/download routes. | 17 |
| `app/Services/NotificationRecipientResolver.php` | **No changes.** Reused as-is (the whole point of Phase 09's design). | — |
| `config/rams.php` (`notifications.bcc`) | **No changes.** Reused. | — |
| `app/Core/Modules/Projects/ProjectDataService.php` | **No changes.** Drawings consume its output; do not extend its contract. | — |

### 9.2 Nice-to-Touch (housekeeping, not blocking)

| File | Why |
|---|---|
| `resources/views/projects/show.blade.php` | Add a "Drawings" tab + "Regenerate" button. Same pattern as the existing "Worksheets" + "RAMS" tabs. |
| `resources/views/dashboard.blade.php` | Add drawing-status chip alongside RAMS/O&M/Worksheet chips. Same pattern as v1.1 DASH-01. |
| `app/Services/ProjectHealthService.php` | If drawings exist + status=failed, surface as red. Same pattern as RAMS/O&M failure surfacing. |

### 9.3 Touch-Free Boundaries (intentional non-targets)

**Do NOT touch** these — preserving them is part of the v1.3 contract:

- `ProjectDataService` — its merge contract is locked. Extending it for drawings would re-open the "every generator resolves its own data" anti-pattern.
- The four existing generators (`RamsBuilderService`, `OmManualDocxService`, `WorksheetGeneratorService`, `CableScheduleGeneratorService`) — drawings consume *their inputs*, not *their outputs*.
- The four existing `Build*Job` classes — drawings get their own `BuildDrawing*Job` siblings.
- `ExtractQuoteJob` and the extraction pipeline — drawings sit downstream; quote import does not need to know they exist (use a controller hook on re-import success instead).
- `routes/web.php` for survey + quote-import + RAMS routes — additive only.
- The `creagia/laravel-sign-pad` integration — drawings have no signature requirement (drawings are not signed; only commissioning/worksheets are).

### 9.4 New Files Inventory (estimate for sizing)

| Category | New Files | Approx LOC |
|---|---|---|
| Migration | `2026_05_xx_create_project_drawings_table.php` | ~80 |
| Model | `app/Models/ProjectDrawing.php` | ~250 |
| Policy | `app/Policies/ProjectDrawingPolicy.php` | ~80 |
| Services | `app/Services/Drawings/{Drawing,Schematic,RackElevation,FloorPlanInitialState,DrawingExportRenderer,DrawingDataResolver}Service.php` | ~1500 total |
| Jobs | `app/Jobs/Build{Schematic,RackElevation,FloorPlanPdf}Job.php` + `RegenerateDrawingsJob.php` | ~600 total |
| Controllers | `app/Http/Controllers/ProjectDrawingController.php` | ~250 |
| Form Requests | `app/Http/Requests/Drawing{Save,Regenerate}Request.php` | ~120 |
| Mailables | `app/Mail/DrawingReadyMail.php` (single, kind-discriminated) | ~80 |
| Blade views | `resources/views/projects/drawings/{index,show,edit}.blade.php`, `resources/views/pdf/drawings/{schematic,rack,floor-plan}.blade.php`, `resources/views/emails/drawing-ready.blade.php` | ~700 total |
| JS | `resources/js/drawings.js` + `resources/js/drawings/{floor-plan,schematic,rack}-editor.js` | ~800 total |
| Tests | unit + feature for each service + each job + each controller | ~2000 total |

**Rough total: ~6500 LOC across ~30 files.** Comparable in size to v1.2 Phase 14 (mobile field view) which was ~5800 LOC across ~25 files. Estimate: **3 plans for Phase 17, 2 for Phase 18, 3 for Phase 19, 2 for Phase 20 = 10 plans across 4 phases.**

---

## 10. Anti-Patterns to Forbid

| Anti-Pattern | Why It's Bad | What to Do Instead |
|---|---|---|
| Adding `drawings_data JSON` column to the `projects` table | Stale-data risk identical to the pre-DATA-01 era when each generator merged its own dataset; collides with soft-delete + version history. | Dedicated `project_drawings` table with foreign key — same shape as RAMS/O&M/worksheets. |
| Generators reading `extracted_data` / `reviewed_data` directly | Breaks DATA-03's contract (locked). Drawings would drift from the canonical dataset within one quote re-import. | Always go through `ProjectDataService` (via `DrawingDataResolverService` reshape). |
| Adopting React for floor plans | Fragments the codebase. Contradicts v1.2 STACK.md decision. | Konva is vanilla — wrap in Alpine.js, ship in a separate Vite bundle. |
| Loading Konva globally in `app.js` | Adds ~140 KB to every admin page bundle for a feature 95% of pages don't need. | Separate Vite entry (`drawings.js`), `@vite()` only on edit pages. |
| Auto-overwriting user-edited drawings on regenerate | Engineers will lose edits silently and stop trusting the auto-regen. | Archive prior version (mirror Phase 12 install programme regen) + UI confirm prompt. |
| Embedding SVG directly into PhpWord `addImage()` for handover | Word's SVG support is inconsistent across versions/platforms. Renders differently in Word vs Word Online vs LibreOffice. | Flatten to PNG at handover time; cache the PNG per drawing version. |
| Synchronous PDF render inside controller actions | A 3-second Browsershot blocks the request thread; doesn't match the existing 4 generator patterns. | `BuildDrawing*Job` queue dispatch + status state machine + completion email. |
| One `TYPE_DRAWING_SCHEMATIC` / `TYPE_DRAWING_RACK` / `TYPE_DRAWING_FLOORPLAN` per kind in `DocumentArtifactStorage` | Three constants for one logical category. Causes typos. | Single `TYPE_DRAWING`; sub-kind lives in the filename convention. |
| Storing thumbnails in the same column as the source SVG | Thumbnails are lossy + low-res; mixing them with source throws away one or the other on read. | Separate `thumbnail_png_path` column pointing to the disk. |
| Touching `ProjectDataService` to add drawing-shaped methods | Pollutes the canonical dataset contract with renderer-specific reshaping. | Reshape in `DrawingDataResolverService`, which calls `ProjectDataService::resolve()`. |
| Public-token routes for drawings in v1.3 | Drawings are internal + handover; v1.3 has no external-recipient flow. | Defer to v1.4 client portal (PORT-02). Add `access_token` column nullable in v1.3 to make v1.4 a zero-schema-change phase. |

---

## 11. Scalability Considerations (Internal-Tool Scale)

The platform is a single-tenant internal tool for 21st Century AV. Order-of-magnitude bounds (mirroring v1.2 STACK.md philosophy):

| Concern | At 100 projects | At 1000 projects | At 10k projects |
|---|---|---|---|
| `project_drawings` row count | ~300 (3/proj avg) | ~3000 | ~30k — still under MySQL JSON-index pain threshold |
| Storage on `documents` disk | ~1 GB (3 MB/drawing avg) | ~10 GB | ~100 GB — provision filesystem accordingly |
| Browsershot concurrent renders | 1–2 queue workers fine | 2–4 workers; consider dedicated render queue | 4+ workers; possibly separate render server |
| Konva client-side perf | Trivial | Trivial | Per-drawing perf (single-room floor plan) is constant |

**Bottom line:** v1.3 has no scalability concerns for the foreseeable life of the platform. Same architectural call as v1.2.

---

## 12. Confidence Assessment

| Area | Level | Basis |
|---|---|---|
| `project_drawings` table shape | HIGH | Mirrors RamsDocument + InstallProgramme + OmManual table shapes (proven through three milestones). |
| `app/Services/Drawings/` sub-namespace | HIGH | Matches the four sub-namespaces already in use (`OandM/`, `Rams/`, `Cable/`, `Worksheet/`). |
| ProjectDataService consumption pattern | HIGH | DATA-03 is locked; every existing generator follows it; v1.3 generators do likewise. |
| D2 for schematic SVG | HIGH | Server-side native binary, SVG output — no headless browser, no Node runtime. Matches the constraint that Browsershot is the only headless browser we want to keep on the box. |
| Konva for floor plans (vs React-based TLDraw / Excalidraw) | HIGH | Vanilla JS lib, ~140 KB, MIT, mature. Honors the v1.2 "no React" decision. |
| Browsershot waits for Konva client-side render | MEDIUM | Pattern is well-documented for Puppeteer (`page.waitForFunction(...)`); Browsershot exposes the same API via `waitForFunction`. Phase 19 spike will confirm round-trip fidelity for canvas → PDF on the AlmaLinux + chrome-headless-shell production combination. |
| Custom SVG builder for racks | HIGH | Trivial graphics shape. PHP string construction is faster than learning a DSL for this case. |
| PNG-flatten-for-DOCX-handover (vs SVG-embed) | MEDIUM | PhpWord 1.4+ ships SVG support but real-world Word rendering of SVG is inconsistent. PNG is the proven safe path; if Word's SVG support firms up, switch later. |
| DXF/DWG export feasibility | LOW | Research has not surfaced a robust OSS PHP DXF *writer*. Recommend deferring DWG/DXF and shipping v1.3 with PDF + SVG + PNG only. Mark DXF as a v1.3.x stretch / Python sidecar candidate. |
| Single `TYPE_DRAWING` constant (vs three) | HIGH | Mirrors the H-07 collapse of four bespoke directory layouts into one disk + one type registry. |
| Job shape (status state machine + idempotency timestamps + failed() admin alert) | HIGH | Identical to all four existing `Build*Job` classes — no new pattern. |
| `DrawingReadyMail` single mailable (vs three) | MEDIUM | Could go either way. Single is simpler; three matches the existing mailable count more uniformly. Recommend single + kind discriminator; reverse if a Phase 17 plan finds the templating awkward. |
| Phase 19 the riskiest of the four | HIGH | Konva + Browsershot + AlmaLinux production combo has not been done in this codebase before. Spike is mandatory. |

---

## 13. Sources

- Existing codebase analysis:
  - `app/Services/DocumentArtifactStorage.php` (TYPE_* registry pattern)
  - `app/Services/PdfRenderService.php` (Browsershot wrapper, AlmaLinux config)
  - `app/Services/InstallProgrammeService.php` (regenerate-archives-prior pattern)
  - `app/Services/InstallTaskGeneratorService.php` (sync generation, ProjectDataService consumer)
  - `app/Jobs/BuildOmManualJob.php` (status + idempotency + failed() pattern)
  - `app/Core/Modules/Projects/ProjectDataService.php` (DATA-03 contract)
  - `app/Models/Project.php` (existing relations, lifecycle)
  - `vite.config.js` (Vite entry pattern for v1.2 frappe-gantt)
  - `.planning/PROJECT.md` (v1.3 scope + constraints)
- Diagram-engine evaluation:
  - [D2 Documentation FAQ](https://d2lang.com/tour/faq/)
  - [D2 GitHub (terrastruct/d2)](https://github.com/terrastruct/d2)
  - [Mermaid vs D2 comparison (AaronJBecker.com)](https://aaronjbecker.com/posts/mermaid-vs-d2-comparing-text-to-diagram-tools/)
  - [Mermaid CLI (uses Puppeteer headless Chrome)](https://github.com/mermaid-js/mermaid-cli)
- Canvas library evaluation:
  - [Top 5 JavaScript Whiteboard & Canvas Libraries (byby.dev)](https://byby.dev/js-whiteboard-libs)
  - [Excalidraw vs Tldraw 2026 (OpenAlternative.co)](https://openalternative.co/compare/excalidraw/vs/tldraw)
  - [Tldraw alternatives (AlternativeTo)](https://alternativeto.net/software/tldraw/)
- Existing v1.2 stack pattern: `.planning/research/STACK.md` (frappe-gantt as Alpine.js wrapper precedent)

---

*Last updated: 2026-04-30 — v1.3 milestone research, Phase 17–20 architecture integration*
