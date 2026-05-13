# Phase 23: XTEN-AV-Style Renderer — Research

**Researched:** 2026-05-13
**Domain:** mxGraph stencil rendering, port-to-port edge routing, multi-page mxGraph documents, deterministic builder evolution
**Confidence:** HIGH (verified against vendored draw.io v29.7.12 + existing spike stencil JSON + Phase 21/22 SUMMARYs + current production code)

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**Carry-forward (from prior phases):**
- **Platform = draw.io / mxGraph self-hosted** (spike `260509-ibx` validated 2026-05-09). No Konva, no SVG-direct.
- **Generic naming, no `rams_` prefix** (Phase 21 D-09). Any new tables/columns use generic names.
- **v1.3 D2 generator stays untouched** (Phase 21 D-10). `SchematicGeneratorService`, `SchematicD2SourceBuilder`, `DrawingDataResolverService`, `CableScheduleGeneratorService`, bound-PDF cable-list rendering — strictly additive.
- **NULL-FK rows render via v1.3 surfaces** (Phase 22 D-10 invariant). XLSX export + schematic SVG + bound-PDF keep handling legacy rows; Phase 23 renderer handles them per D-07.
- **Cable signal-type colour map = `config/cables.php` `signal_type_colours`** (Phase 22 locked). Single source of truth: `audio` #C0392B · `video` #2980B9 · `control` #27AE60 · `network` #8E44AD · `usb` #E67E22 · `speaker` #16A085 · `power` #7F8C8D · `unknown` #000000.
- **Tier 1 + Tier 2 stencils both render** (Phase 21 D-04). Auto-generic placeholders AND engineer-curated stencils side by side.
- **Spike admin route stays live** (Phase 21 D-08). `/admin/drawings/draw-io-spike/{project}` URL + `DrawIoSpikeController` class + Blade path preserved. Phase 23 evolves the BUILDER behind it.
- **ClickShare-before-Barco resolver order** (Phase 21 D-14).
- **AI is NEVER used for inventing scope, equipment, or design** (CLAUDE.md). Renderer is DETERMINISTIC — same project data → same XML bytes.
- **Local-edit-then-upload deployment** (`feedback_local_then_upload.md`). Each plan SUMMARY ends with 🚨 Files to upload section.
- **PHP lint before commit** — `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l <file>` on every touched PHP file.
- **Phase 22 canonical port label format** = `"{Manufacturer} {Model} ({Port label})"` in `from_location` / `to_location` text columns (Phase 22 D-04). Phase 23 prefers FK over text but text is the legacy fallback.

**Sub-Room Zones (D-01..D-04):**
- **D-01** — Default zone derived from device category via `config/drawings.php` `category_to_zone` map. Renderer resolution: `$zone = $line['zone'] ?? $config['category_to_zone'][$line['category']] ?? 'OTHER'`. Seed map: `rack-mount-switch | network-switch | poe-switch | amplifier | dsp | matrix | processor → RACK`; `ceiling-mic | ceiling-speaker | ceiling-camera → CEILING`; `display | screen | projector → WALL`; `touchpanel | desk-mic | tabletop-codec → TABLE`; `paging-station | call-station → PAGING_STATION`; `intercom | door-station → RECEPTION`; `ups | distribution-strip → FLOOR`; uncategorised → `OTHER`. **Planner verifies category strings against real data (see Open Question 1 below).**
- **D-02** — Per-device-instance zone override = `zone` key on the `latestPackage->extracted_data['equipment'][N]` JSON. Zero migrations. Lives with the device-instance data `Project::devicesWithStencils()` already reads.
- **D-03** — Override UI ships IN Phase 23. Add a `zone` dropdown column to the quote-review equipment table on `project-packages/review.blade.php`. Save path uses the existing review form POST.
- **D-04** — Zone vocab in `config/drawings.php`: `RACK, CEILING, WALL, TABLE, RECEPTION, FLOOR, PAGING_STATION, EXTERNAL, OTHER` + "Other (free text)" escape hatch.

**Activation Surface (D-05):**
- Evolve the existing spike route in place. NO new admin routes. Internal renaming of helper classes (`XtenAvLayoutEngine`, `SheetPaginator`, `TitleBlockRenderer`, `ZoneGrouper`, `CableRouter`) permitted. Spike Blade can grow optional UI controls (additive only).

### Claude's Discretion (planner defaults — reversible if pushback)

- **D-06 — Paginator policy:** Always emit sheet 1 (system overview). Sub-sheets (audio/video/control/network) emit only when BOTH ≥5 cables AND ≥3 devices touching that signal type on the project. Engineer override deferred to Phase 24 (Phase 23 tinker-only via `Project.metadata.force_sheets`).
- **D-07 — NULL-FK fallback:** When `source_port_id` NULL but `source_device_id` set, draw cable from device-card edge (heuristic side based on category). Warning ⚠ glyph at cable-card junction. Skip cable entirely if BOTH device IDs NULL (already handled by v1.3 per Phase 22 D-10).
- **D-08 — Title-block sources:**
  - `project` → `Project.name`
  - `client` → `Project.client_name`
  - `sheet #` → from sheet allocator (AV-201 system overview; AV-202 audio; AV-203 video; AV-204 control; AV-205 network — extends Phase 20 AV-201..299 schematic range)
  - `date` → `now()->format('Y-m-d')`
  - `revision` → `ProjectDrawing.version` (from spike's lock-on-edit + archive-prior pattern)
  - `designed-by` → `Auth::user()->name` at render time
  - `drawn-by` → same as designed-by by default
  - `checked-by` → `Project.metadata.drawing_checked_by` (planner adds JSON-cast `metadata` column if missing — generic name per D-09 carry-forward). Defaults to "—".
- **D-09 — Cable routing:** draw.io default orthogonal routing. Cable-ID labels at midpoint (draw.io anti-overlap). NO custom router. Bundle-parallel-cables deferred to v2.1.

### Open Issue (must verify before ship)

- **D-10 — Signal-type colour discrepancy with REQUIREMENTS.md narrative.** `config/cables.php` (Phase 22 locked) says: `audio` red / `video` blue / `control` green / `network` purple / `usb` orange / `speaker` teal. REQUIREMENTS.md DRAW-44 narrative reads: "audio purple, video purple, control blue, network blue, USB yellow/orange, speaker/SPOUT green". Phase 23 reads config (D-10 locked); planner inserts a verification task that opens the XTEN-AV PAGING SYSTEM reference image side-by-side. If reference matches the narrative → raise a separate config-update ticket BEFORE the renderer ships. DO NOT silently change colours during Phase 23.

### Claude's Discretion areas (planner judgement)

- Internal class boundaries inside `DrawIoBuilderService` (single beefy service vs split into `XtenAvLayoutEngine` / `SheetPaginator` / etc.) — planner decides.
- Test fixture shape (single Teams Room vs multi-room boardroom vs paging-system project) — planner decides which projects to fixture against the listed must-cover scenarios.
- Whether to add additive UI affordances to the spike Blade (force-sheet toggles, zone-override quick edits) IN Phase 23 or defer wholesale to Phase 24 — planner decides.

### Deferred Ideas (OUT OF SCOPE)

- Stencil curation UI — Phase 24 (DRAW-50..53)
- AI port extraction from datasheets — Phase 25 (DRAW-54)
- Chat-edit operations — Phase 25 (DRAW-55)
- Bound PDF + O&M auto-embed swap — Phase 25 (DRAW-57 / DRAW-58)
- Title-block edit UI (designed-by / drawn-by / checked-by per-project overrides) — Phase 24 or 23.1 (Phase 23 reads auth context + Project.metadata only)
- Force-sheet toggle UI — Phase 24
- Bundle-parallel-cable router + aggressive label collision avoidance — v2.1 polish
- Floor plans — v2.1 backlog (DRAW-14..20)
- Re-align REQUIREMENTS.md DRAW-44 narrative vs `config/cables.php` — separate ticket (if D-10 verification finds mismatch)

</user_constraints>

---

<phase_requirements>
## Phase Requirements (DRAW-42 .. DRAW-49)

| ID | Description | Research Support |
|----|-------------|------------------|
| **DRAW-42** | Custom device-card stencil layout — manufacturer logo top, generic name centre, model number bottom, port rails (inputs left, outputs right), connector glyphs per port | §"mxGraph Stencil XML Format" + §"Spike stencil JSON evidence". Curated stencils ALREADY ship this layout in their `mxgraph_xml` (see `resources/data/draw-io-stencils/21cav-mtr-spike.json` worked sample). Phase 23 renderer's job: continue base64-embedding `device_stencils.mxgraph_xml` into `shape=stencil(...)` style fragments. The auto-generic Tier 1 stencil's mxgraph_xml is intentionally bare per D-04 — leave alone. |
| **DRAW-43** | Port-to-port cable routing — renderer reads `cable_schedule_items.source_port_id` + `dest_port_id` and draws the cable from one stencil's exact port to the other | §"Port constraint XML + edge geometry". Use `exitX/exitY` + `entryX/entryY` style fragments resolved from `DevicePort.x_pct / y_pct` (Phase 21 columns), OR — preferred — use the stencil's named port `<constraint name="X">` via the mxGraph `exitPortId="X"` attribute on the edge. Either approach is mxGraph-native. |
| **DRAW-44** | Signal-type colour coding — reads `config/cables.php` `signal_type_colours` | Already locked by Phase 22. `CableRouter` (or whatever the planner names it) reads `config('cables.signal_type_colours')[$port->signal_type]`. **D-10 verification required against XTEN-AV reference image BEFORE ship.** |
| **DRAW-45** | Cable ID labels at cable midpoint — `cable_schedule_items.cable_id` | mxGraph default behaviour: setting `<mxCell value="...">` on an edge renders the value at midpoint with built-in label-anti-overlap. D-09: NO custom collision avoidance. Just emit `value="{{ cable_id }}"` on the edge cell. |
| **DRAW-46** | Sub-room zones — dashed groups within a room, auto-derived from category, engineer override via D-03 dropdown | Pattern: emit a `<mxCell vertex="1" style="rounded=0;dashed=1;fillColor=none;strokeColor=#888;" />` group container BEFORE its child device cells, with `parent="<zone-cell-id>"` on each contained device. zone derivation per D-01/D-02. |
| **DRAW-47** | Multi-page paginator — system overview + per-subsystem sub-sheets on threshold | §"Multi-page mxGraph documents" — `<mxfile><diagram name="..."><mxGraphModel>...</mxGraphModel></diagram><diagram name="...">...</diagram></mxfile>`. The current builder returns a single `<mxGraphModel>` (no wrapping). Phase 23 wraps in `<mxfile>` and emits one `<diagram>` per sheet. The spike's draw.io embed Blade ALREADY accepts `<mxfile>` (draw.io's index.html embed mode handles both single-page and multi-page formats natively). |
| **DRAW-48** | Standardised title block — project / client / designed-by / drawn-by / checked-by / sheet # / date / revision | Renderer emits a row of mxCells at the bottom of each page (`y > pageHeight - 100`), one per field. `TitleBlockRenderer` resolves all 8 fields per D-08 sources. |
| **DRAW-49** | Dashed sheet border | Emit a single full-page-bounds mxCell with `dashed=1;strokeColor=#888;fillColor=none;` once per `<diagram>` page. |

</phase_requirements>

---

## Summary

The spike validated draw.io v29.7.12 as the v2.0 platform; Phase 21 promoted the stencil pack and seeded 96 DeviceStencil rows + 40 DevicePort rows; Phase 22 added the four `cable_schedule_items` port FKs + the deterministic backfill resolver. Phase 23's job is **purely renderer evolution**: take the SAME `DrawIoBuilderService::build(Project): string` public contract that has been in place since Phase 21 Plan 03, and grow its internals to honour the 8 visual-contract deliverables (DRAW-42..49) without touching the controller, the Blade view path, the v1.3 D2 generator, or the database tables.

The core technical fundamentals are all in place: stencil XML format with `<connections><constraint name="X" x="0" y="0.5" perimeter="0"/>` IS the spike's own stencil pack convention (5 hand-coded stencils prove it works). Port FKs are populated by the picker + backfill. Signal-type colours have a single config source. Manufacturer logos resolve via a top-20 SVG pack. The mxGraph `<mxfile>` multi-page wrapper is native draw.io and requires no embed-side change. Sheet numbering already follows the AV-201..299 schematic range from Phase 20.

**The substantive new code** is:
1. A `category_to_zone` config + `ZoneGrouper` that emits dashed group containers
2. A `CableRouter` that reads `cable_schedule_items` (eager-loaded at the call site only — Phase 22 D-10 forbids `$with`) and emits per-port edges with `exitX/exitY`+`entryX/entryY` style fragments
3. A `SheetPaginator` that classifies cables by signal type, applies the D-06 threshold (≥5 cables AND ≥3 devices), and emits one `<diagram>` per sheet inside an `<mxfile>` wrapper
4. A `TitleBlockRenderer` that emits 8 fields per sheet from the D-08 sources
5. A migration for `projects.metadata` JSON column (column does NOT currently exist — verified)
6. A `zone` column + dropdown UI on the quote-review equipment table

**Primary recommendation:** Split the builder into 5 supporting classes (`XtenAvLayoutEngine`, `SheetPaginator`, `TitleBlockRenderer`, `ZoneGrouper`, `CableRouter`) all under `app/Services/Drawings/`. Keep `DrawIoBuilderService::build(Project): string` as the single public entry point — internal orchestrator that calls the others in deterministic order. This preserves the test contract (`DrawIoBuilderServiceTest::test_build_is_deterministic` already locks byte-identity) and the controller's signature (`test_d08_spike_controller_constructor_has_two_parameters` locks the 2-parameter constructor).

---

## Project Constraints (from CLAUDE.md)

These directives carry the same authority as CONTEXT.md locked decisions. The planner must NOT recommend approaches that contradict them.

| Constraint | Source | Phase 23 Implication |
|------------|--------|----------------------|
| **AI ONLY for formatting / method statement structuring — NEVER for inventing scope/equipment/design** | CLAUDE.md "Constraints" | Renderer is deterministic. NO `AIManager::run()` calls inside the builder or any of its supporting classes. Grep guard: `app/Services/Drawings/` must NEVER reference `AIManager`, `AICache`, `AIUsage`, or `*Prompt` classes (already enforced by D-LOCK-5 from spike — verify Phase 23 doesn't regress this). |
| **Existing pipeline must not break** | CLAUDE.md "Constraints" | Phase 21 D-10 + Phase 22 D-10 carry-forward: `SchematicGeneratorService`, `SchematicD2SourceBuilder`, `DrawingDataResolverService`, `BoundPdfBuilderService`, `DrawingExportRendererService`, `CableScheduleGeneratorService`, `CableScheduleXlsxService` — Phase 23 must not modify ANY of these. Verify with `git diff --stat` against these 7 paths at end of every plan. |
| **Architecture: Laravel service-based, thin controllers, shared data services, queue-compatible** | CLAUDE.md "Constraints" | `DrawIoSpikeController` stays thin (already is — just resolves drawing + calls builder + returns view). Renderer logic lives in `app/Services/Drawings/` classes. Phase 23 dispatches NO new jobs (render is synchronous — fast enough per spike measurement). |
| **PHP 8.2+ / Laravel 12** | composer.json + CLAUDE.md | Use PHP 8.2 features (readonly promoted properties, enum-style consts, `match` expressions). Already the pattern in `DrawIoBuilderService::__construct(private readonly DeviceStencilCacheService $cache, ...)`. |
| **Service class naming: PascalCase + Service suffix** | CLAUDE.md "Conventions" | `XtenAvLayoutEngine` (no Service suffix — engine vs service distinction is fine per existing precedent like `SheetNumberAllocator`), `SheetPaginator`, `TitleBlockRenderer`, `ZoneGrouper`, `CableRouter`. All under `app/Services/Drawings/` namespace. |
| **Method camelCase** | CLAUDE.md | Standard Laravel; no friction. |
| **PHPDoc on classes + public methods (with `@param` / `@return` / `@throws`)** | CLAUDE.md "Comments" | Each new class needs a class-level docblock pointing at this CONTEXT.md decision IDs ("per D-XX") so traceability is explicit. |
| **Logging: prefix `'ClassName: message'` + structured context array** | CLAUDE.md "Logging" | `Log::info('CableRouter: skipped NULL-FK row', ['cable_id' => ..., 'project_id' => ...])` — already the pattern in `DrawIoBuilderService::build()` line 126. |
| **PHP lint before commit** | feedback_php_lint_before_push.md | `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l <file>` on every touched PHP file before staging. NON-NEGOTIABLE — broke prod once already. |
| **Local-edit-then-upload deploy for RAMS** | feedback_local_then_upload.md | RAMS = manual upload list (no working webhook). Each plan SUMMARY ends with 🚨 Files to upload to live + post-upload commands (migrate, route:clear, cache:clear, view:clear, config:clear as appropriate). |
| **GSD workflow enforcement** | CLAUDE.md | Phase 23 work is started via `/gsd-execute-phase`. |
| **H-07 storage convention** | CLAUDE.md | Phase 23 produces NO file artifacts (renders to XML in DB column via `saveSpikeXml`). The DocumentArtifactStorage `TYPE_DRAWING` path is already used by `saveSpikeSvg` for the SVG preview-only output — DO NOT introduce hand-built paths. |
| **Existing test discipline (PHPUnit)** | composer.json + phpunit.xml | All Phase 23 tests use `RefreshDatabase` + sqlite `:memory:`. Phase 23 should follow the established TDD-RED-then-GREEN-per-task pattern (every prior Phase 21/22 plan did this). |

---

## Standard Stack

### Core

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| draw.io (self-hosted) | v29.7.12 (pinned in `public/vendor/drawio/VERSION.md`) | Renders mxGraph XML in an iframe + provides postMessage save/export round-trip | Locked by spike `260509-ibx`. Apache 2.0. Already vendored at `public/vendor/drawio/` (132 MB, 2899 files). [VERIFIED: `public/vendor/drawio/VERSION.md`] |
| mxGraph | bundled with draw.io v29.7.12 | Underlying graph library; defines stencil XML schema (`<shape>` + `<connections><constraint>`), edge geometry (`exitX/exitY/entryX/entryY`), `<mxfile>` multi-page wrapper | mxGraph IS the schema draw.io speaks. Verified in spike stencil JSON. [VERIFIED: `resources/data/draw-io-stencils/21cav-mtr-spike.json` worked stencils prove `<constraint name="X" x="0" y="0.5" perimeter="0"/>` works in production] |
| Laravel | ^12.0 | Backend framework | Project default. [VERIFIED: composer.json] |
| PHPUnit | ^11.5.3 | Test framework | Project default. [VERIFIED: composer.json] |
| Alpine.js | 3.14.1 (CDN in spike Blade) | Reactive UI for the embed Blade + future zone-dropdown column | Already the pattern in `resources/views/admin/drawings/draw-io-spike.blade.php` line 8. [VERIFIED: file read] |
| `phpoffice/phpword` | ^1.4 | NOT USED by Phase 23 (DOCX gen only) | Listed for completeness; renderer output is mxGraph XML, not DOCX. |

### Supporting

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `DeviceStencilCacheService` | Phase 21 Plan 01 | `firstOrCreate(part_number)` cross-project stencil cache | Already injected into `DrawIoBuilderService` constructor. Renderer NEVER bypasses this. |
| `ManufacturerLogoResolver` | Phase 21 Plan 03 | `resolveSvg(?manufacturer): ?string` — top-20 inline-SVG glyph lookup | Already injected. Returns null on unmatched manufacturer (graceful degrade — already verified in Phase 21 Plan 03 SUMMARY). |
| `CableConnectorCompatibilityService` | Phase 22 Plan 01 | `check(src, dst): array{compatible: bool, reason: ?string}` | Renderer doesn't directly need this — it's a write-side validator. Phase 23 reads the FK rows after the picker already enforced compatibility. |
| `CablePortFkResolverService` | Phase 22 Plan 03 | Pure deterministic matcher | NOT used at render time — used by the backfill command only. |
| `DrawingService` | v1.3 Phase 17 + spike Task 5 | Lock-on-edit + archive-prior on `drawings.canvas_state` | Reused unchanged — Phase 23 doesn't touch the save path. |
| `SheetNumberAllocator` | Phase 20 (v1.3 DRAW-23) | AV-201..299 schematic sheet allocation | Already exists. Phase 23 wraps it for multi-page allocation: AV-201 system / AV-202 audio / AV-203 video / AV-204 control / AV-205 network. [VERIFIED: referenced in `DrawingService::createForProject` line 70 of `DrawingService.php`] |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Split internal classes (XtenAvLayoutEngine / SheetPaginator / etc.) | Single beefier `DrawIoBuilderService` | Single class = less indirection but ~600-line monster file (current is 411 lines BEFORE Phase 23 additions; growth estimate +800 lines). Split mirrors Phase 22's `app/Services/Cable/` namespace pattern and matches the 5 named classes in CONTEXT.md specifics. **Recommend: SPLIT** per CONTEXT spec. |
| `<mxfile>` multi-page wrapper | Single `<mxGraphModel>` with all sheets in a giant `pageWidth`-grid | mxfile is mxGraph-native + draw.io renders pages as tabs. Single-grid would force a custom paginator on the renderer side AND break draw.io's native zoom-to-page UX. **Recommend: USE mxfile.** [VERIFIED: draw.io embed mode supports both] |
| Named-constraint port termination (`exitPortId="hdmi-1"`) | Coordinate-based termination (`exitX=1;exitY=0.5;exitDx=0;exitDy=0`) | Named is semantic + survives stencil-resize. Coordinate is what the current spike emits (verified at `DrawIoBuilderService` line 346-350) when ports are present. **Both are mxGraph-native.** Recommend: when source/dest port FKs are present, look up the port's `port_id` and emit `exitPortId="hdmi-1"` (the port's `port_id` IS the constraint `name` in the stencil's `<connections>`). This is the cleanest mapping from `DevicePort.port_id` → mxGraph constraint. Fall back to coordinate style when FK is NULL (D-07). |

### Installation

No new packages required. All dependencies in place.

**Version verification:**
- draw.io: pinned at v29.7.12 in `public/vendor/drawio/VERSION.md` — manual update only [VERIFIED: file read]
- Laravel: `^12.0` per `composer.json` [VERIFIED]
- PHPUnit: `^11.5.3` per `composer.json` [VERIFIED]
- Alpine.js: 3.14.1 (CDN) per `draw-io-spike.blade.php` line 8 [VERIFIED]

---

## Architecture Patterns

### Recommended Project Structure (Phase 23 additions only)

```
app/
├── Services/
│   └── Drawings/
│       ├── DrawIoBuilderService.php          # EVOLVED — top-level orchestrator
│       ├── XtenAvLayoutEngine.php            # NEW — places device cells per zone+sheet
│       ├── SheetPaginator.php                # NEW — classifies cables → sheets, applies D-06 threshold
│       ├── ZoneGrouper.php                   # NEW — derives zone per device, emits group containers
│       ├── CableRouter.php                   # NEW — reads cable_schedule_items → emits port-to-port edges
│       ├── TitleBlockRenderer.php            # NEW — emits the 8-field title block per sheet
│       ├── SheetBorderRenderer.php           # NEW (optional — could inline into TitleBlockRenderer)
│       ├── DeviceStencilCacheService.php     # UNCHANGED
│       ├── ManufacturerLogoResolver.php      # UNCHANGED
│       ├── AutoGenericStencilGenerator.php   # UNCHANGED
│       ├── DrawIoSpikeBuilderService.php     # UNCHANGED — shim
│       ├── DrawingService.php                # UNCHANGED
│       ├── SchematicGeneratorService.php     # UNCHANGED (v1.3 D-10 invariant)
│       ├── SchematicD2SourceBuilder.php      # UNCHANGED (v1.3 D-10 invariant)
│       ├── DrawingDataResolverService.php    # UNCHANGED (v1.3 D-10 invariant)
│       ├── BoundPdfBuilderService.php        # UNCHANGED (v1.3 D-10 invariant)
│       ├── DrawingExportRendererService.php  # UNCHANGED (v1.3 D-10 invariant)
│       └── SheetNumberAllocator.php          # UNCHANGED (extended usage only)
config/
├── drawings.php                              # MODIFIED — add category_to_zone + zone_vocab keys
├── cables.php                                # UNCHANGED (Phase 22 locked single source of truth)
database/
└── migrations/
    └── 2026_05_NN_NNNNNN_add_metadata_to_projects_table.php  # NEW — JSON metadata column
resources/views/
├── admin/drawings/draw-io-spike.blade.php    # UNCHANGED (optional: additive force-sheet toggles per D-06)
└── project-packages/review.blade.php         # MODIFIED — add zone dropdown column (D-03)
app/Http/Controllers/
└── ProjectPackageReviewController.php        # MODIFIED — validate + persist equipment[N][zone] (D-03)
app/Models/
└── Project.php                               # MODIFIED — add 'metadata' to $fillable, 'metadata' => 'array' cast
```

### Pattern 1: Top-level Orchestrator + Single-Responsibility Helpers

**What:** `DrawIoBuilderService::build()` becomes a thin orchestrator that calls each helper in deterministic order. Each helper has ONE job and returns plain arrays / strings.

**When to use:** When a builder grows past ~400 lines and has multiple distinct responsibilities (layout, pagination, routing, decoration). Phase 22 already did this with `app/Services/Cable/` namespace (`CableConnectorCompatibilityService` + `CablePortFkResolverService` + `BackfillCablePortFksCommand`).

**Example (planner-facing sketch — exact shape decided in PLAN):**
```php
// app/Services/Drawings/DrawIoBuilderService.php — EVOLVED
public function build(Project $project): string
{
    $package = $project->latestPackage;
    if ($package === null) {
        return $this->emptyGraph();
    }

    // Eager-load AT THE CALL SITE only (Phase 22 D-10 forbids class-level $with).
    $project->loadMissing([
        'cableSchedules.items.sourcePort.stencil',
        'cableSchedules.items.destPort.stencil',
        'cableSchedules.items.sourceDevice',
        'cableSchedules.items.destDevice',
    ]);

    $lines = $project->devicesWithStencils();  // existing Phase 21 accessor
    if ($lines === []) {
        return $this->emptyGraph();
    }

    // 1. Group devices by sub-room zone (D-01/D-02).
    $zoned = $this->zoneGrouper->assign($lines);

    // 2. Decide which sheets to emit (D-06 threshold).
    $sheets = $this->paginator->classify($project, $zoned);

    // 3. Render each sheet's mxGraph payload.
    $diagrams = [];
    foreach ($sheets as $sheet) {
        $deviceCells = $this->layoutEngine->placeDevices($sheet, $zoned);
        $cableCells = $this->cableRouter->emitCables($sheet, $project);
        $titleBlock = $this->titleBlock->render($sheet, $project);
        $border = $this->sheetBorder->render();
        $diagrams[] = $this->emitDiagram($sheet, $deviceCells, $cableCells, $titleBlock, $border);
    }

    // 4. Wrap as <mxfile> multi-page document.
    return $this->emitMxFile($diagrams);
}
```

Each helper's return is a plain array of mxCell descriptors; the orchestrator's `emitDiagram` serialises them. Same `htmlspecialchars(ENT_XML1 | ENT_QUOTES)` escape pattern as line 407-410 of the current builder.

### Pattern 2: Config-driven mappings (D-01 / D-04 zone vocab)

**What:** Zone vocabulary + category→zone lookup live in `config/drawings.php`, not code. Engineer tunes by editing the file.

**When to use:** When the mapping is taxonomy-stable but volume-tunable. Matches Phase 22's `config/cables.php` `compatibility_aliases` + `signal_type_colours` pattern.

**Example:**
```php
// config/drawings.php — Phase 23 additions
return [
    // ... existing v1.3 keys (d2_binary_path, signal_colours, etc.) untouched ...

    // ── Phase 23 zone derivation (DRAW-46) ────────────────────────────────
    'zone_vocab' => ['RACK', 'CEILING', 'WALL', 'TABLE', 'RECEPTION', 'FLOOR', 'PAGING_STATION', 'EXTERNAL', 'OTHER'],
    'category_to_zone' => [
        // PLANNER: refine against real quote category strings — see Open Question 1.
        'rack-mount-switch' => 'RACK',
        'network-switch' => 'RACK',
        // ... full seed from CONTEXT D-01 ...
    ],

    // ── Phase 23 paginator threshold (D-06) ───────────────────────────────
    'sub_sheet_thresholds' => [
        'min_cables_per_signal' => 5,
        'min_devices_touching_signal' => 3,
    ],

    // ── Phase 23 sheet numbering (D-08) ───────────────────────────────────
    'sheet_number_format' => [
        'system_overview' => 'AV-201',
        'audio' => 'AV-202',
        'video' => 'AV-203',
        'control' => 'AV-204',
        'network' => 'AV-205',
    ],
];
```

### Pattern 3: Determinism contract (D-LOCK-5/6 from spike)

**What:** Same `Project` data in → same mxGraph XML bytes out. NO `now()`, `uniqid()`, `Str::random()`, `Auth::user()->id` random-order, `Project::all()->shuffle()`, anything else stateful in the builder. Locked by `DrawIoBuilderServiceTest::test_build_is_deterministic` (Phase 21 P03 test) which calls `build()` twice on `$project` + `$project->fresh()` and asserts byte-identity.

**When to use:** ALWAYS in Phase 23. Test must continue to pass.

**Exception:** Title block's `date` field is rendered from `now()->format('Y-m-d')` per D-08. This is acceptable IF the test passes both calls within the same second OR the test freezes time via `Carbon::setTestNow(...)`. **Planner: add `Carbon::setTestNow('2026-05-13 12:00:00')` to the determinism test setup so the title-block date doesn't break byte-identity.** Same applies to `revision` (it's `ProjectDrawing.version` — stable across calls unless an engineer saves in between, which the test doesn't do).

### Pattern 4: Lock-on-edit + archive-prior (already in place — DO NOT TOUCH)

Phase 23 reuses `DrawingService::saveSpikeXml()` and `saveSpikeSvg()` unchanged. The renderer's job ends at returning the XML; persistence (engineer edits the rendered XML in the iframe → posts back → `saveSpikeXml` locks-on-first-edit + archives-prior-on-subsequent) is already solved by the spike + Phase 21 carry-forward.

### Anti-Patterns to Avoid

- **DO NOT add `protected $with = ['sourcePort', ...]` to `CableScheduleItem`** — Phase 22 D-10 explicit guard. Class-level eager load would force 4 LEFT JOINs on every legacy NULL-FK row across XLSX export + bound-PDF + schematic generator read paths. Eager-load AT THE CALL SITE ONLY. [VERIFIED: `tests/Unit/Models/CableScheduleItemRelationsTest.php::test_with_property_is_empty_to_prevent_eager_load_regression`]
- **DO NOT call AI inside the renderer.** D-LOCK-5 invariant from spike. The static analysis guard is `grep -E "AIManager|AICache|AIUsage" app/Services/Drawings/` returning empty. Phase 23 must NOT regress this. [VERIFIED: current state at spike completion]
- **DO NOT modify the 5 v1.3 surfaces** listed in CONTEXT canonical_refs. Phase 23 is strictly additive; the swap to v2.0 output for bound PDF + O&M lands in Phase 25 (DRAW-57 / DRAW-58). The verification gate is `git diff --stat -- app/Services/Drawings/SchematicGeneratorService.php app/Services/Drawings/SchematicD2SourceBuilder.php app/Services/Drawings/DrawingDataResolverService.php app/Services/Drawings/BoundPdfBuilderService.php app/Services/Drawings/DrawingExportRendererService.php` returning empty at the end of every plan.
- **DO NOT hand-roll cable label collision avoidance.** D-09 locks draw.io's default behaviour for Phase 23. Bundle-parallel routing is v2.1.
- **DO NOT rename or move the `DrawIoBuilderService` class.** Plan 21-03 already shipped the rename from `DrawIoSpikeBuilderService` → `DrawIoBuilderService`. Phase 23 evolves it INTERNALLY only. The `DrawIoSpikeController` constructor's two-parameter contract (`DrawIoBuilderService $builder, DrawingService $drawings`) is locked by `DrawIoBuilderServiceTest::test_d08_spike_controller_constructor_has_two_parameters`.
- **DO NOT delete `clickshare.svg` or change its name.** D-14 invariant from Phase 21.
- **DO NOT introduce a class-level `protected $with` on `Project` for the new metadata column.** Same eager-load discipline applies.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Multi-page PDF/diagram document layout | A custom paginator that hand-positions cells across N `<mxGraphModel>` payloads | `<mxfile><diagram name="...">` wrapper — mxGraph-native multi-page document format | draw.io renders pages as tabs; the embed mode handles `<mxfile>` natively per spike documentation. No JS-side change. |
| Cable label midpoint placement + anti-overlap | Custom geometry routing | draw.io's default orthogonal edge rendering + `<mxCell value="...">` on edges | D-09: default routing is acceptable for projects with <30 cables per sheet. |
| Port-to-port edge attachment | Computing absolute (x, y) screen coordinates and manually positioning the edge endpoint | `exitX/exitY/entryX/entryY` style fragments OR `exitPortId="port-name"` referencing the stencil's `<constraint name="port-name">` | mxGraph resolves the port position from the constraint at render time. Survives stencil resize / move. [VERIFIED: spike stencil pack uses this exact pattern with constraint `name="hdmi-1"` etc.] |
| Manufacturer logo glyph rendering | Sourcing licensed brand assets | `ManufacturerLogoResolver::resolveSvg()` returns top-20 hand-traced inline SVGs | Phase 21 Plan 03 already shipped this. Nominative fair use for internal tooling. |
| Stencil XML schema | Hand-coding device cards per project | DB-backed `device_stencils.mxgraph_xml` populated by Phase 21 Plan 02 seeder (96 stencils) + Tier 1 auto-create for cache misses | Cross-project propagation + Phase 24 upgrade path already work. |
| Connector compatibility validation at the renderer | Re-implementing the picker validator | `App\Services\Cable\CableConnectorCompatibilityService` (Phase 22) — but **renderer doesn't need it** (it reads FKs the picker already validated) | Phase 22 already solved this at the write side. Renderer is pure read. |
| Sheet numbering | Inventing a new scheme | Reuse `SheetNumberAllocator` (Phase 20 DRAW-23) for AV-201..299 range — extend usage to allocate AV-202/203/204/205 for sub-sheets | Already in place; Phase 20 set the convention. [VERIFIED: `DrawingService::createForProject` line 70] |
| Lock-on-edit + archive-prior versioning | A new save-path for the rendered drawing | Existing `DrawingService::saveSpikeXml` + `saveSpikeSvg` | Spike Task 5 already shipped this with DB::transaction + supersede flip. Phase 23 doesn't touch the save path. |
| `projects.metadata` JSON column | Hand-crafted EAV table or new `project_drawing_overrides` table | Lightweight `metadata` JSON column on `projects` (single migration, generic name per D-09 carry-forward) | D-08 (`drawing_checked_by`) + D-06 (`force_sheets`) are project-scoped scalars + small arrays. JSON column is the right shape. |

**Key insight:** Phase 23 is unusual for a v2.0 phase in that almost everything it needs is ALREADY in place from Phases 21 + 22 + the spike. The renderer's complexity is in **orchestrating** existing primitives correctly per the visual contract, not in building new primitives. The new code is mostly: glue logic + the multi-page mxfile wrapper + the zone derivation + the title block + the sheet border. Each is shallow.

---

## Common Pitfalls

### Pitfall 1: Class-level eager-load regression on `CableScheduleItem`
**What goes wrong:** Adding `protected $with = ['sourcePort', 'destPort', 'sourceDevice', 'destDevice']` to make the renderer queries simpler.
**Why it happens:** Renderer code feels cleaner with eager-loaded relations; "DRY" instinct.
**How to avoid:** Eager-load AT THE CALL SITE inside the orchestrator (`Project::loadMissing([...])`). The CableScheduleItem $with property is locked empty by `test_with_property_is_empty_to_prevent_eager_load_regression` — DO NOT regress.
**Warning signs:** XLSX export OR bound-PDF cable section suddenly slows down; `EXPLAIN` on the export queries shows LEFT JOIN device_ports.

### Pitfall 2: Builder accidentally writes to the database
**What goes wrong:** A method on `XtenAvLayoutEngine` calls `$device->update([...])` or similar.
**Why it happens:** A "zone resolved" caching impulse. Or "save the rendered SVG back to the drawing row".
**How to avoid:** ONLY `Project::devicesWithStencils()` is allowed to write (Tier 1 auto-create per D-07 / Phase 21). EVERY new class added in Phase 23 must be a pure read function — `Project` in → arrays of mxCell descriptors out. Phase 23's persistence happens ONLY via `DrawingService::saveSpikeXml` after the engineer posts back from the iframe.
**Warning signs:** Determinism test (`test_build_is_deterministic`) starts failing on second call.

### Pitfall 3: Determinism broken by `now()` in title block
**What goes wrong:** `TitleBlockRenderer::render()` calls `now()->format('Y-m-d')`; determinism test runs at a second boundary and gets different output bytes.
**Why it happens:** Title block date IS per-render by spec (D-08).
**How to avoid:** Determinism test setUp() uses `Carbon::setTestNow('2026-05-13 12:00:00')` to freeze time. ALSO freeze the random-order of `Project::devicesWithStencils()` by asserting `orderBy('sort_order')` or stable iteration of the array. ALSO freeze `Auth::user()` (the test does NOT call `actingAs(...)` — designed-by would be `'—'` fallback).
**Warning signs:** Determinism test passes locally but flaps in CI.

### Pitfall 4: Multi-page render exceeds the 5 MB postMessage payload cap
**What goes wrong:** A large boardroom project emits 5 sub-sheets × 80 devices × full stencil XML = >5 MB of XML, which `DrawIoSpikeController@saveXml` rejects (line 59: `'xml' => ['required', 'string', 'min:50', 'max:5242880']`).
**Why it happens:** Stencils embed via `shape=stencil(<base64-of-shape-xml>)` per the spike pattern (line 296 of `DrawIoBuilderService`). Each device card is ~3 KB of base64. 80 × 5 = 400 cells × 3 KB = 1.2 MB. Doable but tight if stencil XML grows.
**How to avoid:** Single shared stencil definition via `<mxGraphModel>`'s shape library — DEFER for Phase 24 (where stencil curation UI is anyway). For Phase 23, the postMessage cap is a known-acceptable upper bound; emit a warning log if `strlen($xml) > 4_500_000` so the planner knows to investigate before it bites prod.
**Warning signs:** Save returns HTTP 422 with the `xml.max` validation message on large projects.

### Pitfall 5: Zone-dropdown free-text divergence (D-04)
**What goes wrong:** Engineer types "Equipment Rack" in one row + "RACK" in another → two separate dashed groups on the diagram for the same conceptual zone.
**Why it happens:** D-04 free-text escape hatch + non-normalising writer.
**How to avoid:** Renderer creates a dashed group per UNIQUE string (case-sensitive, as-written). Display helper text on the dropdown: "Free text creates a separate group — use the dropdown for consistency". This is the documented tradeoff (CONTEXT specifics). NOT a renderer bug.
**Warning signs:** Engineer complaint "why are there two RACK groups?"; visual inspection reveals two strings.

### Pitfall 6: Phase 21 STENCIL_ROLES shallow heuristic carried forward into the new layout engine
**What goes wrong:** `XtenAvLayoutEngine` reads the same 5-slug → role lookup that Phase 21 Plan 03 used (`STENCIL_ROLES` const at line 65-71 of current builder).
**Why it happens:** Easy port from the existing code.
**How to avoid:** Replace with the D-01 category→zone derivation (which is the real layout signal — RACK vs CEILING vs WALL, not videobar vs display). The Phase 21 STENCIL_ROLES is sized to "stop the spike from regressing visually" only and is explicitly deferred in CONTEXT.md line 236. Phase 23 replaces it. The TODO(phase-23) comment at line 32-37 of the current builder points right at this.
**Warning signs:** Tier 1 placeholders always end up in column 3 ("other") regardless of category; rack-mount switches don't group with amplifiers in the RACK zone.

### Pitfall 7: NULL-FK fallback skips the cable instead of rendering it with a warning
**What goes wrong:** A legacy `cable_schedule_items` row with all 4 FKs NULL but `from_location` text populated gets silently dropped from the diagram, despite the engineer knowing the cable exists.
**Why it happens:** Easy `if (!$item->source_port_id || !$item->dest_port_id) continue;` in the cable router.
**How to avoid:** D-07 fallback ladder:
  - BOTH `source_device_id` AND `dest_device_id` NULL → SKIP (already handled by v1.3 surface)
  - Either `source_port_id` OR `dest_port_id` NULL but device_id present → render with edge-side heuristic + ⚠ glyph at midpoint
  - Both port_ids present → port-to-port renderer (the happy path)
**Warning signs:** Engineer reports "cable X is missing from the diagram" — confirm against the underlying `cable_schedule_items` row first.

### Pitfall 8: Free-text `zone` written to `equipment[N][zone]` accepts XSS payload
**What goes wrong:** Engineer pastes `<script>alert(1)</script>` into the free-text zone input; renderer interpolates it into the mxGraph XML; the postMessage round-trip persists it to `canvas_state` mediumtext; subsequent render emits the script tag.
**Why it happens:** mxGraph XML is XML, but draw.io renders some HTML in `value="..."` attributes — escape discipline must match the spike's `htmlspecialchars(ENT_XML1 | ENT_QUOTES)` pattern at line 407-410 of `DrawIoBuilderService`.
**How to avoid:** ALWAYS pass user-supplied strings through `$this->xml($value)` before interpolation. Validation on the review form: `equipment.*.zone` => `nullable|string|max:50|regex:/^[A-Za-z0-9 _\-]+$/u` (or similar — keeps the surface tight).
**Warning signs:** XSS scanner finds reflected payload in the rendered XML.

### Pitfall 9: Eager-load N+1 across cable schedules
**What goes wrong:** Cable router iterates `$project->cableSchedules->flatMap->items` without eager-loading `sourcePort`, `destPort`, `sourceDevice`, `destDevice`, `cable_id`. Result: 4N queries on a 100-cable project.
**Why it happens:** Phase 22 D-10 forbids class-level `$with` — the call-site forgets to compensate.
**How to avoid:** At the top of `DrawIoBuilderService::build()`, eager-load explicitly:
```php
$project->loadMissing([
    'cableSchedules.items.sourcePort',
    'cableSchedules.items.destPort',
    'cableSchedules.items.sourceDevice',
    'cableSchedules.items.destDevice',
]);
```
Also eager-load `latestPackage` if not already. Verify with Laravel debugbar / `DB::enableQueryLog()` in a feature test.
**Warning signs:** Builder takes >2 seconds on a real project (spike measured sub-second on the same data).

---

## Code Examples

Verified patterns from official sources + the project's own seed pack.

### Example 1: mxGraph Stencil XML Format (DRAW-42)

Source: `resources/data/draw-io-stencils/21cav-mtr-spike.json` — Neat Bar Pro stencil (line read 2026-05-13).

```xml
<shape name="21cav.mtr.neat-bar-pro" h="160" w="240" aspect="variable" strokewidth="inherit">
  <connections>
    <constraint x="0"   y="0.20" perimeter="0" name="hdmi-in"/>
    <constraint x="0"   y="0.45" perimeter="0" name="usb-c"/>
    <constraint x="0"   y="0.85" perimeter="0" name="power"/>
    <constraint x="1"   y="0.20" perimeter="0" name="hdmi-out"/>
    <constraint x="1"   y="0.45" perimeter="0" name="lan"/>
    <constraint x="1"   y="0.70" perimeter="0" name="audio-out"/>
  </connections>
  <background>
    <fillcolor color="#FAFAF6"/>
    <strokecolor color="#1B7A7A"/>
    <roundrect x="0" y="0" w="240" h="160" arcsize="8"/>
    <fillstroke/>
    <fillcolor color="#1B7A7A"/>
    <rect x="0" y="0" w="240" h="30"/>  <!-- header bar -->
    <fill/>
  </background>
  <foreground>
    <fontcolor color="#FFFFFF"/><fontsize size="12"/><fontstyle style="1"/>
    <text str="NEAT  BAR  PRO" x="120" y="19" align="center"/>
    <fontcolor color="#1B7A7A"/><fontsize size="11"/><fontstyle style="0"/>
    <text str="Videobar" x="120" y="55" align="center"/>
    <!-- port labels -->
    <fontcolor color="#555555"/><fontsize size="9"/>
    <text str="HDMI" x="4" y="36" align="start"/>      <!-- left side input -->
    <text str="HDMI" x="236" y="36" align="end"/>      <!-- right side output -->
    <!-- ... etc ... -->
    <!-- port-rail accent tick marks -->
    <strokecolor color="#C07000"/><strokewidth width="1.5"/>
    <line x1="0" y1="60" x2="8" y2="60"/>
    <line x1="232" y1="60" x2="240" y2="60"/>
    <stroke/>
  </foreground>
</shape>
```

**Key observations:**
- `<connections><constraint name="hdmi-in" x="0" y="0.20" perimeter="0"/>` — the `name` is the canonical port identifier. `x=0` = left edge, `x=1` = right edge. `y` is the percentage down the card (0..1). `perimeter="0"` makes the constraint a hard attachment point (not "anywhere on the perimeter near this y").
- `<background>` renders the card body (rounded rect + header bar). `<foreground>` renders text + connector tick marks.
- The whole `<shape>` is then base64-encoded and embedded in an mxCell style via `shape=stencil(<base64>)` — exactly as the current builder does at line 297-298.

**Phase 21 Plan 02 seeder ALREADY shipped this for 5 spike stencils + ~91 v1.3 promoted stencils. Phase 23's renderer doesn't BUILD stencil XML — it base64-embeds the existing `device_stencils.mxgraph_xml` column.**

### Example 2: Worked stencil for a 4-port matrix switcher (planner reference)

Generic shape Phase 24 might curate (Phase 23 renders this if `DeviceStencil` row exists; Tier 1 placeholder otherwise). Used here to show the planner what a "full curated stencil" looks like:

```xml
<shape name="generic-4port-matrix" h="140" w="220" aspect="variable" strokewidth="inherit">
  <connections>
    <constraint x="0" y="0.30" perimeter="0" name="hdmi-in-1"/>
    <constraint x="0" y="0.50" perimeter="0" name="hdmi-in-2"/>
    <constraint x="0" y="0.70" perimeter="0" name="hdmi-in-3"/>
    <constraint x="0" y="0.90" perimeter="0" name="hdmi-in-4"/>
    <constraint x="1" y="0.30" perimeter="0" name="hdmi-out-1"/>
    <constraint x="1" y="0.50" perimeter="0" name="hdmi-out-2"/>
    <constraint x="1" y="0.70" perimeter="0" name="hdmi-out-3"/>
    <constraint x="1" y="0.90" perimeter="0" name="hdmi-out-4"/>
    <constraint x="0.5" y="0" perimeter="0" name="power"/>
    <constraint x="0.5" y="1" perimeter="0" name="rs232"/>
  </connections>
  <background>
    <fillcolor color="#FAFAF6"/><strokecolor color="#1B7A7A"/>
    <roundrect x="0" y="0" w="220" h="140" arcsize="6"/><fillstroke/>
    <fillcolor color="#1B7A7A"/><rect x="0" y="0" w="220" h="28"/><fill/>
  </background>
  <foreground>
    <fontcolor color="#FFFFFF"/><fontsize size="11"/><fontstyle style="1"/>
    <text str="MANUFACTURER MODEL" x="110" y="18" align="center"/>
    <fontcolor color="#555"/><fontsize size="9"/>
    <text str="IN 1" x="4" y="46" align="start"/>
    <text str="IN 2" x="4" y="74" align="start"/>
    <text str="IN 3" x="4" y="102" align="start"/>
    <text str="IN 4" x="4" y="130" align="start"/>
    <text str="OUT 1" x="216" y="46" align="end"/>
    <text str="OUT 2" x="216" y="74" align="end"/>
    <text str="OUT 3" x="216" y="102" align="end"/>
    <text str="OUT 4" x="216" y="130" align="end"/>
  </foreground>
</shape>
```

Phase 24 curation UI builds this kind of XML; Phase 23 only reads it from the DB.

### Example 3: Port-to-port edge geometry (DRAW-43)

mxGraph edges use `exitX/exitY` (source side) + `entryX/entryY` (destination side) in the cell style, OR `exitPortId="port-name"` referencing a named constraint. Source: spike `DrawIoBuilderService.php` line 346-350 (coordinate style) + draw.io postMessage protocol docs.

**Coordinate-style edge (current spike pattern, used when port-name not derivable):**
```xml
<mxCell id="cab-1" value="HDMI" style="edgeStyle=orthogonalEdgeStyle;rounded=1;html=1;strokeColor=#2980B9;strokeWidth=2;fontSize=10;fontColor=#2980B9;exitX=1;exitY=0.2;exitDx=0;exitDy=0;exitPerimeter=0;entryX=0;entryY=0.18;entryDx=0;entryDy=0;entryPerimeter=0;"
        edge="1" parent="1" source="dev-1" target="dev-2">
  <mxGeometry relative="1" as="geometry"/>
</mxCell>
```

**Named-port-style edge (Phase 23 preferred when source_port_id + dest_port_id are populated):**
```xml
<mxCell id="cab-1" value="HDMI-001" style="edgeStyle=orthogonalEdgeStyle;rounded=1;html=1;strokeColor=#2980B9;strokeWidth=2;fontSize=10;fontColor=#2980B9;exitPortId=hdmi-out-1;entryPortId=hdmi-in;"
        edge="1" parent="1" source="dev-1" target="dev-2">
  <mxGeometry relative="1" as="geometry"/>
</mxCell>
```

**Where the port name comes from:** `DevicePort.port_id` (varchar 50, unique per stencil) IS the constraint `name` attribute in the stencil's `<connections>` per Phase 21 D-02. So:
```php
$sourcePort = $item->sourcePort;   // DevicePort instance via FK
$exitPortId = $sourcePort?->port_id;  // e.g. "hdmi-out-1"
```

**Cable value label (DRAW-45):** `value="HDMI-001"` IS the `cable_schedule_items.cable_id` column populated by Phase 22. draw.io renders it at the edge midpoint by default.

**Cable colour (DRAW-44):** `strokeColor` + `fontColor` come from `config('cables.signal_type_colours')[$port->signal_type]` — derived from `DevicePort.signal_type` of either source or dest port (planner decides which side wins on signal_type mismatch — likely source).

### Example 4: Multi-page mxfile wrapper (DRAW-47)

Source: draw.io docs (https://www.drawio.com/doc/faq/embed-mode) + mxGraph schema.

```xml
<mxfile host="app.diagrams.net" agent="21cav-rams-renderer" version="29.7.12">
  <diagram name="System Overview" id="sheet-01">
    <mxGraphModel dx="1200" dy="800" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="1600" pageHeight="1000" math="0" shadow="0">
      <root>
        <mxCell id="0"/>
        <mxCell id="1" parent="0"/>
        <!-- ... cells for sheet 1 ... -->
      </root>
    </mxGraphModel>
  </diagram>
  <diagram name="Audio Subsystem" id="sheet-02">
    <mxGraphModel dx="1200" dy="800" ...>
      <root>
        <mxCell id="0"/>
        <mxCell id="1" parent="0"/>
        <!-- ... cells for sheet 2 ... -->
      </root>
    </mxGraphModel>
  </diagram>
  <!-- ... additional <diagram> elements per emitted sheet ... -->
</mxfile>
```

**Backward compatibility:** The current builder emits a bare `<mxGraphModel>` (no `<mxfile>` wrapper). draw.io's embed mode handles both shapes — single-`<mxGraphModel>` payloads load as a single-page document. Phase 23's switch to `<mxfile>` is a renderer-internal change; the existing `canvas_state` mediumtext column (16 MB) easily accommodates either shape. **Verify in browser UAT that the iframe correctly renders multiple tabs for a multi-`<diagram>` payload.**

### Example 5: Sub-room zone group (DRAW-46)

mxGraph groups cells via a parent mxCell. Source: spike stencil pack visual contract + mxGraph schema.

```xml
<!-- Zone container — dashed rect, no fill, serves as the parent for child cells -->
<mxCell id="zone-rack" value="RACK"
        style="rounded=0;dashed=1;dashPattern=5 5;fillColor=none;strokeColor=#888888;strokeWidth=1;fontSize=10;fontColor=#666666;verticalAlign=top;align=left;spacingTop=4;spacingLeft=8;"
        vertex="1" parent="1">
  <mxGeometry x="60" y="60" width="500" height="320" as="geometry"/>
</mxCell>

<!-- Devices inside the zone — note parent="zone-rack" (not "1") -->
<mxCell id="dev-1" value="..." style="..." vertex="1" parent="zone-rack">
  <mxGeometry x="80" y="80" width="220" height="140" as="geometry"/>
</mxCell>
```

`ZoneGrouper` computes the zone container's bounding box from the union of its children's geometry + padding (~20-30 px). Devices' `parent` attribute references the zone cell's id.

### Example 6: Title block (DRAW-48)

Source: v1.3 Phase 17 reusable title-block partial pattern (`title_block_fields` config key in current `config/drawings.php` line 49 — used by v1.3 D2 schematic title blocks).

Phase 23 renderer emits the title block as mxCells at the bottom of each sheet:

```xml
<!-- Title block row — fixed y near bottom of page -->
<mxCell id="tb-project" value="Project: Acme Boardroom Refurb" style="text;html=1;align=left;verticalAlign=middle;strokeColor=none;fillColor=none;fontSize=10;" vertex="1" parent="1">
  <mxGeometry x="80" y="940" width="280" height="20" as="geometry"/>
</mxCell>
<mxCell id="tb-client" value="Client: Acme Ltd" style="..." vertex="1" parent="1">
  <mxGeometry x="380" y="940" width="240" height="20" as="geometry"/>
</mxCell>
<mxCell id="tb-designed-by" value="Designed by: Alice Engineer" style="..." vertex="1" parent="1">
  <mxGeometry x="640" y="940" width="200" height="20" as="geometry"/>
</mxCell>
<!-- ... drawn-by / checked-by / sheet-num / date / revision ... -->
```

Single-row layout works on landscape `pageWidth=1600`. Alternative: 2-row + boxed pattern matching the XTEN-AV reference — planner decides per the visual contract image.

### Example 7: Dashed sheet border (DRAW-49)

```xml
<mxCell id="page-border" style="rounded=0;dashed=1;dashPattern=8 4;fillColor=none;strokeColor=#1B7A7A;strokeWidth=1.5;" vertex="1" parent="1">
  <mxGeometry x="20" y="20" width="1560" height="960" as="geometry"/>
</mxCell>
```

One per `<diagram>` page. Geometry insets ~20 px from the page bounds.

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Hand-coded JSON stencil pack | DB-backed `device_stencils` table | Phase 21 Plan 03 (2026-05-10) | Renderer reads from DB; Phase 23 doesn't touch the pack JSON file |
| 4-column shallow grid layout | Category-driven zone layout | THIS PHASE (Phase 23) | Replaces `STENCIL_ROLES` const at line 65-71 of current `DrawIoBuilderService` |
| Canonical Teams Room cable chain (verbatim from spike) | Port-FK-driven cable routing | THIS PHASE (Phase 23) | Replaces `deriveCables()` lines 228-269 of current builder |
| Single-page `<mxGraphModel>` | `<mxfile>` wrapper with multiple `<diagram>` | THIS PHASE (Phase 23) | New paginator emits 1-5 sheets per project depending on D-06 threshold |
| `DrawIoSpikeBuilderService` (hand-coded stencil JSON reads) | `DrawIoSpikeBuilderService` shim → `DrawIoBuilderService` | Phase 21 Plan 03 | Spike shim preserved for backwards compat (10-line delegate) |
| In-band stencil JSON pack at `resources/data/draw-io-stencils/21cav-mtr-spike.json` | DB-backed `device_stencils` table | Phase 21 Plan 02-03 | Pack file kept for historical reference; not consumed at runtime after Phase 21 P03 |

**Deprecated / outdated:**
- The spike's `STENCIL_ALIASES` first-match table → replaced by Phase 21's DB lookup (already gone)
- The 4-column shallow grid (`ROLE_COLUMN`) → replaced by zone-based layout in Phase 23
- The `deriveCables()` canonical Teams Room chain → replaced by port-FK-driven routing in Phase 23

---

## Runtime State Inventory

Phase 23 is NOT a rename/refactor phase — it's an additive renderer evolution. The relevant non-code state Phase 23 touches:

| Category | Items | Action |
|----------|-------|--------|
| **Stored data** | `cable_schedule_items.source_port_id` / `dest_port_id` / `source_device_id` / `dest_device_id` / `cable_id` populated by Phase 22 picker + backfill | Read-only — renderer consumes FKs as-is |
| **Stored data** | `device_stencils.mxgraph_xml` populated by Phase 21 Plan 02 seeder + Tier 1 auto-create | Read-only — renderer base64-embeds the stencil XML |
| **Stored data** | `latestPackage->extracted_data['equipment'][N]['zone']` (NEW per D-02) | Writer = D-03 review form. Reader = `Project::devicesWithStencils()` already returns the line; Phase 23 reads the new `zone` key. No backfill needed (NULL → falls through to `config.category_to_zone[$category] ?? 'OTHER'`). |
| **Stored data** | `projects.metadata.drawing_checked_by` (NEW per D-08) | Writer = tinker-only in Phase 23 (manual override). Reader = `TitleBlockRenderer`. Column doesn't exist yet — Phase 23 migration creates it. |
| **Stored data** | `projects.metadata.force_sheets` (NEW per D-06) | Writer = tinker-only in Phase 23. Reader = `SheetPaginator`. Same migration as above. |
| **Stored data** | `drawings.canvas_state` mediumtext (existing from Phase 17 + spike Task 5) | Writer = `DrawingService::saveSpikeXml` after engineer edits in iframe. Reader = `DrawIoSpikeController::show` returns persisted state if present. Phase 23 doesn't touch the save path; just emits a larger XML payload that's still under the 5 MB / 16 MB caps. |
| **Live service config** | None | Phase 23 is pure-server-side rendering; no external services |
| **OS-registered state** | None | No tasks / daemons / cron added |
| **Secrets/env vars** | None | No new env vars |
| **Build artifacts** | None — `vendor/drawio/` is already deployed (132 MB pinned). Phase 23 doesn't update draw.io | n/a |

**Nothing found in remaining categories** — Phase 23 is pure-code/config addition with one lightweight migration.

---

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP 8.2+ | All backend | ✓ | Herd PHP 8.4 at `/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe` | — |
| Laravel 12 | All backend | ✓ | ^12.0 per composer.json | — |
| MySQL 8.0+ | New `projects.metadata` JSON column | ✓ (prod) / SQLite :memory: (test) | — | — |
| draw.io self-hosted bundle | Renderer round-trip (iframe + postMessage) | ✓ | v29.7.12 pinned in `public/vendor/drawio/VERSION.md` | — |
| Composer / npm | n/a | ✓ | — | — |
| D2 binary | NOT NEEDED by Phase 23 (only by v1.3 D2 generator which Phase 23 doesn't touch) | ✓ (dev only — production AlmaLinux installs at deploy) | 0.7.1 pinned | — |
| Browsershot / chrome-headless-shell | NOT NEEDED by Phase 23 (only by v1.3 PDF render — Phase 23 outputs mxGraph XML, not PDF) | ✓ | per env | — |
| Tesseract / poppler-utils | NOT NEEDED by Phase 23 | n/a | — | — |

**No missing dependencies. No blocking gaps.** Phase 23 ships entirely from existing infrastructure.

---

## Validation Architecture

> `workflow.nyquist_validation` is not explicitly disabled in `.planning/config.json` (researcher could not locate a config.json — section included by default).

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit ^11.5.3 |
| Config file | `phpunit.xml` (SQLite in-memory) |
| Quick run command | `php artisan test --filter='DrawIo|XtenAv\|SheetPaginator\|ZoneGrouper\|CableRouter\|TitleBlock'` |
| Full suite command | `php artisan test --filter='Cable\|Drawings\|Drawing\|Schematic'` |
| Phase gate | Full suite green before `/gsd-verify-work` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| DRAW-42 | Curated stencil `mxgraph_xml` is base64-embedded into rendered XML | feature | `php artisan test --filter=test_curated_stencil_mxgraph_xml_is_base64_embedded` | ✅ Already in `DrawIoBuilderServiceTest.php` (Phase 21 P03) |
| DRAW-42 | Tier 1 placeholder still renders alongside curated stencils | feature | `php artisan test --filter=test_builds_valid_mxgraph_xml_with_two_vertex_cells` | ✅ Already in `DrawIoBuilderServiceTest.php` |
| DRAW-43 | Edge XML carries `exitPortId="..."` matching source DevicePort.port_id when FK present | feature | `php artisan test --filter=test_port_to_port_edge_uses_exit_port_id` | ❌ Wave 0 — new feature test |
| DRAW-43 | Edge falls back to device-edge heuristic + ⚠ glyph when source_port_id NULL (D-07) | feature | `php artisan test --filter=test_null_fk_renders_with_warning_glyph` | ❌ Wave 0 — new feature test |
| DRAW-43 | Cable with BOTH device IDs NULL is SKIPPED entirely (D-07) | feature | `php artisan test --filter=test_double_null_fk_cable_is_skipped` | ❌ Wave 0 — new feature test |
| DRAW-44 | Edge strokeColor matches `config('cables.signal_type_colours')[$signal]` | unit | `php artisan test --filter=test_cable_color_from_config_signal_type_colours` | ❌ Wave 0 — new unit test on CableRouter |
| DRAW-45 | Edge `value="..."` is `cable_schedule_items.cable_id` literal | feature | `php artisan test --filter=test_edge_value_is_cable_id` | ❌ Wave 0 — new feature test |
| DRAW-46 | Zone container emitted as dashed-bordered mxCell with `parent="..."` linking child devices | feature | `php artisan test --filter=test_zone_emits_dashed_group_with_children` | ❌ Wave 0 — new feature test |
| DRAW-46 | D-01 derivation: device with `category="rack-mount-switch"` lands in RACK zone | feature | `php artisan test --filter=test_category_to_zone_derives_rack` | ❌ Wave 0 — new feature test |
| DRAW-46 | D-02 override: `equipment[N][zone] = "CEILING"` overrides the category default | feature | `php artisan test --filter=test_per_device_zone_override_wins` | ❌ Wave 0 — new feature test |
| DRAW-46 | D-04 free-text override: arbitrary string creates a separate dashed group | feature | `php artisan test --filter=test_free_text_zone_creates_separate_group` | ❌ Wave 0 — new feature test |
| DRAW-47 | Empty project: emits zero sub-sheets, system overview only | feature | `php artisan test --filter=test_empty_project_emits_one_diagram` | ❌ Wave 0 — new feature test |
| DRAW-47 | Below threshold (4 audio cables): NO audio sub-sheet | feature | `php artisan test --filter=test_below_threshold_no_sub_sheet` | ❌ Wave 0 — new feature test |
| DRAW-47 | Above threshold (≥5 cables + ≥3 devices): emits audio sub-sheet | feature | `php artisan test --filter=test_above_threshold_emits_sub_sheet` | ❌ Wave 0 — new feature test |
| DRAW-47 | Output is `<mxfile>` with N `<diagram>` children | feature | `php artisan test --filter=test_multi_page_wraps_in_mxfile` | ❌ Wave 0 — new feature test |
| DRAW-47 | D-06 tinker override: `Project.metadata.force_sheets = ['audio']` forces sub-sheet regardless of threshold | unit | `php artisan test --filter=test_force_sheets_metadata_override` | ❌ Wave 0 — new unit test on SheetPaginator |
| DRAW-48 | Title block emits 8 fields per sheet | feature | `php artisan test --filter=test_title_block_emits_eight_fields` | ❌ Wave 0 — new feature test |
| DRAW-48 | D-08 sources: client = `Project.client_name`, project = `Project.name`, etc. | feature | `php artisan test --filter=test_title_block_field_sources` | ❌ Wave 0 — new feature test |
| DRAW-48 | `checked-by` falls back to "—" when `Project.metadata.drawing_checked_by` is unset | feature | `php artisan test --filter=test_checked_by_fallback_to_dash` | ❌ Wave 0 — new feature test |
| DRAW-49 | Each `<diagram>` includes a dashed border cell at page bounds | feature | `php artisan test --filter=test_dashed_sheet_border_per_diagram` | ❌ Wave 0 — new feature test |
| DRAW-42..49 | **Determinism** — `build()` is byte-identical across calls (with `Carbon::setTestNow`) | feature | `php artisan test --filter=test_build_is_deterministic` | ✅ Already in `DrawIoBuilderServiceTest.php` — extend setUp() to freeze time |
| D-LOCK invariants | spike controller has 2-param constructor (`DrawIoBuilderService` + `DrawingService`) | feature | `php artisan test --filter=test_d08_spike_controller_constructor_has_two_parameters` | ✅ Already in `DrawIoBuilderServiceTest.php` |
| D-LOCK invariants | DrawIoSpikeBuilderService shim still delegates identically | feature | `php artisan test --filter=test_spike_builder_shim_still_exists_and_delegates` | ✅ Already in `DrawIoBuilderServiceTest.php` |
| Phase 21 D-10 | v1.3 surface byte-identity check via `git diff` | manual gate | `git diff --stat HEAD -- app/Services/Drawings/SchematicGeneratorService.php app/Services/Drawings/SchematicD2SourceBuilder.php app/Services/Drawings/DrawingDataResolverService.php app/Services/Drawings/BoundPdfBuilderService.php app/Services/Drawings/DrawingExportRendererService.php` returns empty | n/a — git gate per plan SUMMARY |
| Phase 22 D-10 | `CableScheduleItem` has empty `$with` | unit | `php artisan test --filter=test_with_property_is_empty_to_prevent_eager_load_regression` | ✅ Already in `tests/Unit/Models/CableScheduleItemRelationsTest.php` |
| AI invariant (D-LOCK-5) | `grep -E "AIManager\|AICache\|AIUsage" app/Services/Drawings/` returns empty | manual gate | shell command per plan | n/a |
| Eager-load N+1 | Builder issues bounded query count on a 30-device project | feature | `php artisan test --filter=test_builder_query_count_under_threshold` (uses `DB::enableQueryLog`) | ❌ Wave 0 — new feature test |
| Project.metadata migration | New JSON column exists + nullable + array cast | feature | `php artisan test --filter=test_projects_table_has_metadata_column` | ❌ Wave 0 — new feature test on migration |
| Project.metadata save path | Tinker write to metadata.drawing_checked_by persists | unit | `php artisan test --filter=test_metadata_array_cast_round_trip` | ❌ Wave 0 — new unit test on Project model |
| Quote-review zone dropdown | POST with `equipment[N][zone]=RACK` persists | feature | `php artisan test --filter=test_review_form_persists_zone` | ❌ Wave 0 — new feature test on ProjectPackageReviewController |

### Sampling Rate

- **Per task commit:** `php artisan test --filter='DrawIo\|XtenAv\|SheetPaginator\|ZoneGrouper\|CableRouter\|TitleBlock'` — Phase 23 surfaces only, ~30 seconds
- **Per wave merge:** `php artisan test --filter='Cable\|Drawings\|Drawing\|Schematic'` — Phase 23 + Phase 22 + v1.3 invariants, ~60 seconds
- **Phase gate:** Full suite green before `/gsd-verify-work` — `php artisan test`, expected ~3 minutes (existing baseline at end of Phase 22 was 73 + skipped / 330 assertions for the Cable suite alone; full suite is larger)

### Wave 0 Gaps

Before writing any production code in Wave 1+, the planner should ship a Wave 0 (test scaffolding) that includes:

- [ ] `tests/Feature/Drawings/XtenAvLayoutEngineTest.php` — covers DRAW-42 (zone-aware layout) + Pitfall 6 regression
- [ ] `tests/Feature/Drawings/CableRouterTest.php` — covers DRAW-43 (port-FK routing) + DRAW-44 (colour) + DRAW-45 (cable_id label) + D-07 fallback ladder
- [ ] `tests/Feature/Drawings/ZoneGrouperTest.php` — covers DRAW-46 (zone derivation, override precedence)
- [ ] `tests/Feature/Drawings/SheetPaginatorTest.php` — covers DRAW-47 (threshold logic, `<mxfile>` wrapper, force_sheets override)
- [ ] `tests/Feature/Drawings/TitleBlockRendererTest.php` — covers DRAW-48 (all 8 fields, fallback ladder)
- [ ] `tests/Feature/Drawings/SheetBorderTest.php` — covers DRAW-49 (one per diagram)
- [ ] `tests/Feature/Drawings/DrawIoBuilderServiceMultiSheetTest.php` — covers end-to-end orchestration on 4 fixture projects (single Teams Room / multi-room boardroom / paging-system project / NULL-FK-legacy project for D-07)
- [ ] `tests/Feature/Drawings/BuilderDeterminismTest.php` — explicit determinism test that freezes time via `Carbon::setTestNow` and asserts byte-identity on a complex multi-sheet project (extends the existing one which only covers a 3-device single-sheet case)
- [ ] `tests/Feature/Migrations/ProjectsMetadataMigrationTest.php` — covers the new JSON column migration + array cast round-trip
- [ ] `tests/Feature/ProjectPackages/ReviewZoneDropdownTest.php` — covers DRAW-46 D-03 review-form persistence of `equipment[N][zone]`

**Fixture projects to seed (each used by ≥1 test above):**

| Fixture | Devices | Cables | Purpose |
|---------|---------|--------|---------|
| `small_mtr_fixture` | 5 (Neat / Samsung / ClickShare / Sennheiser / Netgear from Phase 21 spike pack) | 6 | Happy path: all 5 stencils curated, all 6 cables port-to-port |
| `boardroom_fixture` | ~30 hardware across RACK + CEILING + WALL + TABLE zones | ~25 | Tests zoning + below-threshold-no-sub-sheet (DRAW-46/47) |
| `paging_system_fixture` | ~40 hardware (heavy audio + network) with sub-sheets above threshold | ~50 (≥5 per signal type) | Tests above-threshold sub-sheet emission (DRAW-47) |
| `legacy_null_fk_fixture` | 8 devices + 6 cables, 3 of which have NULL FKs but populated `from_location` text | 6 | Tests D-07 NULL-FK fallback + warning glyph |

---

## Security Domain

`security_enforcement` not explicitly disabled in `.planning/config.json`. Section included.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes (admin middleware on spike route) | Laravel auth — already in place. `Route::middleware('admin')` group at `routes/web.php:223-231`. [VERIFIED] |
| V3 Session Management | no | Phase 23 adds no new session state |
| V4 Access Control | yes (admin-only spike route) | Already enforced. Phase 23 doesn't add user-facing surfaces beyond the admin route. |
| V5 Input Validation | yes (zone dropdown free-text per D-04, override note) | New: `equipment.*.zone` validation rule on ProjectPackageReviewController (`nullable\|string\|max:50` + regex character allowlist) |
| V6 Cryptography | no | No new crypto |

### Known Threat Patterns for {Laravel 12 + draw.io embed}

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| XSS via free-text zone string interpolated into mxGraph XML | Tampering | `htmlspecialchars(ENT_XML1 \| ENT_QUOTES, 'UTF-8')` on every user-supplied value — already the pattern in current builder line 407-410. Phase 23's new helpers MUST follow the same discipline. Regex validation on the zone form input as defence-in-depth. |
| XSS via `cable_id` cell value (rendered by draw.io as HTML inside attribute) | Tampering | Same `xml()` helper applies. `cable_id` is populated via the picker UI which already validates string format. |
| XSS via `from_location` / `to_location` text interpolated into NULL-FK warning glyph label | Tampering | Same `xml()` helper. Phase 22 D-04 picker already normalises these strings. |
| Cross-project FK injection (engineer attaches Project A's device_port_id to Project B's cable_schedule_item) | Elevation of Privilege | Already mitigated by Phase 22 T-22-A6 — backfill command loads devices PER PROJECT. The picker UI's update handler validates `exists:device_ports,id` via Eloquent constraints. Phase 23 renderer is read-only — no new attack surface. |
| postMessage spoofing from a malicious iframe origin | Tampering | Already mitigated by spike T-260509-ibx-04: `e.source === iframe.contentWindow` filter at line 92 of `draw-io-spike.blade.php`. Phase 23 doesn't touch this. |
| Mass-assignment attack on `Project.metadata` via POST | Tampering | New: when Phase 23 adds `metadata` to `Project::$fillable`, ensure any controller that accepts user POST writing to `Project` validates the metadata payload shape — Phase 23's writes are tinker-only (no user-facing surface) so this is theoretical for Phase 23 but a Phase 24 concern. |
| 5 MB postMessage payload DoS | Denial of Service | Already mitigated by spike T-260509-ibx-03: `'xml' => ['required', 'string', 'min:50', 'max:5242880']` at line 59 of `DrawIoSpikeController.php`. Phase 23 emits larger payloads but stays under the cap (Pitfall 4). |
| `Project.metadata.force_sheets` payload manipulation forces expensive renders | Denial of Service | Phase 23 is tinker-only write path. Phase 24's force-sheet UI would need rate-limiting + payload validation. Out of Phase 23 scope. |

---

## Assumptions Log

Claims tagged `[ASSUMED]` in this research that the planner / discuss-phase should confirm before locking in:

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | mxGraph `<mxfile><diagram>...</diagram></mxfile>` multi-page wrapper renders correctly inside the existing draw.io v29.7.12 embed at `/vendor/drawio/index.html?embed=1&proto=json` | Pattern 1 / Example 4 | LOW — well-documented mxGraph + draw.io feature; verifiable in browser UAT. If wrong, fall back to per-page links emitted in the existing single-`<mxGraphModel>` payload (no multi-tab UX but functionally complete). |
| A2 | Edge `exitPortId="hdmi-out-1"` correctly references a stencil `<constraint name="hdmi-out-1">` and draws to that port position | Example 3 | LOW — verified pattern in spike stencil pack `<constraint name="hdmi-1">`. Worst case: fall back to coordinate-style edge (line 346-350 of current builder). |
| A3 | Default category strings present in `latestPackage->extracted_data['equipment'][N]['category']` match the D-01 seed map keys (`rack-mount-switch`, `ceiling-mic`, etc.) | D-01 seed map | **MEDIUM** — verified categoryOptions in review.blade.php uses different categories: `'hardware', 'cables', 'consumables', 'services', 'service_contracts', 'customer_supplied', 'option'`. These are HIGH-LEVEL categories. The D-01 seed map uses LOWER-LEVEL semantic categories (`rack-mount-switch`, `ceiling-mic`) which DO NOT appear to be a current canonical field. See **Open Question 1**. |
| A4 | `Project::metadata` column does NOT currently exist on the projects table | Architecture migrations | VERIFIED — read `database/migrations/2026_03_14_000001_create_projects_table.php` and subsequent project table alterations (`add_quote_reference_to_projects_table`, `add_handover_dates_to_projects_table`, `add_soft_deletes_to_projects_table`). No `metadata` column exists. Phase 23 migration creates it. [VERIFIED] |
| A5 | `config/drawings.php` exists and currently holds v1.3 keys (`d2_binary_path`, `signal_colours`, `title_block_fields`) | Architecture config | VERIFIED — read `config/drawings.php` directly. Phase 23 ADDS keys (`zone_vocab`, `category_to_zone`, `sub_sheet_thresholds`, `sheet_number_format`) without modifying existing keys. [VERIFIED] |
| A6 | Default `<mxGraphModel pageWidth="1600" pageHeight="1000">` layout from current builder (line 377) is acceptable for multi-page sheets | Examples 4-7 | LOW — current builder already emits these dimensions. Phase 23 keeps them. If XTEN-AV reference image uses different aspect ratio, adjust per visual contract. |
| A7 | D-09 default orthogonal routing handles the typical 21CAV project (<30 cables per sheet) acceptably without custom router | D-09 | LOW — locked by CONTEXT decision. If visual UAT reveals collision problems, raise v2.1 polish ticket (CONTEXT specifies this fallback). |
| A8 | The XTEN-AV PAGING SYSTEM reference image's colour mapping is the binding visual contract; if it disagrees with `config/cables.php`, the config is wrong | D-10 open issue | **MEDIUM** — researcher does not have the reference image. Planner must verify side-by-side. If config wrong, raise separate ticket per CONTEXT deferred bullet — do NOT change colours during Phase 23. |
| A9 | Title block 8-field single-row layout works on `pageWidth=1600`; alternative boxed-multi-row layout reserved per visual contract | Example 6 | LOW — pure visual concern, easily adjusted post-UAT. |
| A10 | Phase 23 produces NO new file artifacts — render output stays in `drawings.canvas_state` mediumtext column (existing 16 MB capacity adequate per spike measurement) | Runtime State Inventory | LOW — same column the spike uses; tested at end of Phase 21 with `DeviceStencil::count()` after multi-project loads. |
| A11 | `Carbon::setTestNow()` is sufficient to freeze `now()->format('Y-m-d')` for the determinism test | Pattern 3 | LOW — standard Laravel testing pattern. |
| A12 | `ProjectPackageReviewController` (or whichever controller handles the equipment review save) handles `equipment[N][zone]` via existing validation rule shape | D-03 / Pitfall 8 | LOW — pattern matches existing `equipment.*.part_number` / `equipment.*.area` rules verifiable in the controller. Planner reads the controller during Plan 23-XX. |
| A13 | Phase 23 makes NO new DocumentArtifactStorage TYPE_* additions | H-07 / Runtime State | LOW — verified `app/Services/DocumentArtifactStorage.php` has TYPE_DRAWING already; renderer output is mxGraph XML (DB column), not file artifact. Existing `saveSpikeSvg` uses TYPE_DRAWING already. |

---

## Open Questions

1. **What are the actual `category` enum values present in real `latestPackage->extracted_data['equipment'][N]['category']` data?**
   - What we know: The review.blade.php `categoryOptions` dropdown uses HIGH-LEVEL categories: `hardware, cables, consumables, services, service_contracts, customer_supplied, option`. The CONTEXT D-01 seed map uses LOWER-LEVEL semantic strings: `rack-mount-switch, network-switch, poe-switch, amplifier, dsp, matrix, processor, ceiling-mic, ceiling-speaker, ceiling-camera, display, screen, projector, touchpanel, desk-mic, tabletop-codec, paging-station, call-station, intercom, door-station, ups, distribution-strip`.
   - What's unclear: The D-01 seed map's keys do NOT appear to be a current canonical field. Either (a) the seed map describes a SECOND-LEVEL category Phase 23 must derive (from device name? from a new sub_category field?), (b) the seed map describes what categories WILL look like once Phase 24 curation lands, or (c) the strings are aspirational and the planner must propose a real derivation path.
   - **Recommendation:** Wave 1 Task 1 of the planner should: (a) inspect a real `ProjectPackage::query()->whereNotNull('extracted_data')->latest()->take(20)->get()->pluck('extracted_data.equipment.*.category')` query against the live DB to enumerate ACTUAL category strings in production data; (b) compare to the D-01 seed map; (c) decide whether to: derive a sub-category from device name keyword matching (e.g. `name LIKE '%ceiling%' → 'ceiling-mic'`), add a new `sub_category` field surfaced by the quote-review form, or reduce the D-01 seed map to map ONLY the 7 high-level `categoryOptions` strings to coarser zones (`hardware → OTHER`, no further breakdown). The latter is the simplest path and produces a usable Phase 23 with zones derivable from existing data — the D-01 finer-grained map becomes a Phase 24 polish target.

2. **What does the XTEN-AV PAGING SYSTEM reference image show for signal colours?** (D-10 open issue)
   - What we know: Two contradictory mappings in the repo — `config/cables.php` (red/blue/green/purple/orange/teal) vs REQUIREMENTS.md DRAW-44 narrative (purple/purple/blue/blue/yellow-orange/green).
   - What's unclear: Which is right.
   - **Recommendation:** Phase 23 reads `config/cables.php` per D-10 locked decision. Planner inserts a Wave-1 verification task: open reference image side-by-side with rendered output of a real project. If config wrong → raise separate config-update ticket; do NOT silently change Phase 23 code.

3. **Does the existing draw.io v29.7.12 embed iframe correctly render a multi-page `<mxfile>` payload with tab navigation?** (A1)
   - What we know: mxGraph schema supports it; draw.io docs at https://www.drawio.com/doc/faq/embed-mode document the format.
   - What's unclear: Whether the specific embed parameters in the spike Blade (`?embed=1&proto=json&libraries=1&spin=1` at line 53 of `DrawIoSpikeController.php`) enable tab UX.
   - **Recommendation:** Wave 1 Task 2 (or first task that touches the paginator) does a quick browser-side smoke test — emit a 2-page `<mxfile>` payload manually via tinker, load the spike route, confirm draw.io shows tabs. If not, planner investigates embed parameters (likely need to add a query param) before sinking effort into the full paginator.

4. **Do the existing 91 Tier 1.5 stencils (Phase 21 Plan 02 SUMMARY — `metadata.needs_phase_24_curation = true`) carry port `<constraint>` elements, or only the auto-generic header bar?**
   - What we know: Plan 21-02 SUMMARY says Tier 1.5 stencils have manufacturer + model + part_number metadata + ports where signal_type was inferable (HDMI/RJ45/USB-/XLR/line-in). Plan 22-03 SUMMARY says "Tier 1.5 stencils (91/96) don't backfill" because `connector_type` is empty.
   - What's unclear: Whether their `mxgraph_xml` includes the `<connections><constraint>` elements at all, or whether the constraints are present but the `DevicePort.connector_type` field is what's empty.
   - **Recommendation:** Wave 1 Task 3 reads a sample of `DeviceStencil` rows (`source='engineer-curated'` AND `metadata->needs_phase_24_curation=true`) to confirm. If the `mxgraph_xml` has constraints, port-to-port routing works for them via `exitPortId`. If not, they render as Tier 1 placeholders + their ports are absent — engineers see "stencil but no port routing" and know to use Phase 24 curation when it ships. Either outcome is acceptable; planner should know which it is so the test fixtures are realistic.

5. **Should Phase 23 add force-sheet toggle UI affordances to the spike Blade (D-06 deferred UI)?**
   - What we know: CONTEXT D-06 says force-sheet override is "tinker-only via `Project.metadata.force_sheets = ['audio', ...]`" for Phase 23 — full UI deferred to Phase 24.
   - What's unclear: Whether a minimal additive checkbox row in the spike Blade ("Force sheets: ☐ audio ☐ video ☐ control ☐ network") is in or out of Phase 23 scope.
   - **Recommendation:** Out of scope per CONTEXT D-06. Tinker-only is fine for Phase 23. Phase 24 ships the proper UI. Planner should NOT pad scope with UI affordances that have an explicit deferral.

6. **Do Tier 1 auto-generic placeholders pollute the layout when a project's quote has many uncatalogued part_numbers?**
   - What we know: Tier 1 placeholders are 220×140 rectangles with no ports. The current `STENCIL_ROLES` heuristic puts them in column 3 ("other"). Phase 23's zone-based layout will distribute them by category (when category is known).
   - What's unclear: Whether 20+ Tier 1 cards on a single sheet looks visually OK against the XTEN-AV reference or whether the renderer should special-case Tier 1 placement (e.g. always at the bottom of the OTHER zone, or in their own "Uncatalogued" zone).
   - **Recommendation:** Default to "they appear in their derived zone, no special-casing" for Phase 23. If visual review post-Phase-23 reveals a problem, raise a Phase 24 polish ticket (the curation UI eliminates the problem at source — engineers curate the uncatalogued items).

---

## Sources

### Primary (HIGH confidence)

- **`.planning/phases/23-xten-av-style-renderer/23-CONTEXT.md`** — locked decisions D-01..D-10, canonical refs section, code context
- **`.planning/REQUIREMENTS.md`** lines 45-56 (Phase 23 DRAW-42..49 acceptance criteria) + lines 79-91 (visual contract)
- **`.planning/ROADMAP.md`** line 46 (Phase 23 summary)
- **`.planning/phases/21-device-port-catalog-stencil-cache/21-CONTEXT.md`** — D-02 (`device_ports` schema), D-04 (Tier 1 shape), D-07 (`Project::devicesWithStencils()` shape), D-08 (preserve spike admin route), D-09 (generic naming), D-10 (v1.3 untouched), D-14 (clickshare-before-barco)
- **`.planning/phases/21-device-port-catalog-stencil-cache/21-01-schema-models-cache-service-SUMMARY.md`** — confirms DeviceStencil + DevicePort model contracts + tables shipped 2026-05-10
- **`.planning/phases/21-device-port-catalog-stencil-cache/21-03-manufacturer-logos-builder-integration-SUMMARY.md`** — confirms DrawIoBuilderService DB-backed read + TODO(phase-23) marker location
- **`.planning/phases/22-cable-schedule-with-port-level-fks/22-CONTEXT.md`** — D-04 picker text overwrite, D-10 v1.3 invariant, signal_type_colours config lock
- **`.planning/phases/22-cable-schedule-with-port-level-fks/22-01-SUMMARY.md`** — confirms 4 port FK columns + `connector_override_note` + `cable_id` exist on `cable_schedule_items`; `$with` empty
- **`.planning/phases/22-cable-schedule-with-port-level-fks/22-03-SUMMARY.md`** — confirms backfill resolver semantics, Tier 1.5 stencil non-resolution
- **`.planning/quick/260509-ibx-draw-io-embed-spike-sandbox-one-stencil-/260509-ibx-SUMMARY.md`** — D-LOCK audit (8 invariants), postMessage protocol, lock-on-edit + archive-prior pattern, deterministic builder
- **`public/vendor/drawio/VERSION.md`** — v29.7.12 pinned, Apache 2.0 license, manual update procedure
- **`app/Services/Drawings/DrawIoBuilderService.php`** (read end-to-end) — current builder shape, TODO(phase-23) marker at line 32-37, STENCIL_ROLES heuristic at line 65-71, deriveCables verbatim chain at line 228-269, emitMxGraph escape pattern at line 282-385
- **`app/Services/Drawings/DrawIoSpikeBuilderService.php`** — 10-line backwards-compat shim
- **`app/Services/Drawings/DeviceStencilCacheService.php`** — firstOrCreate cross-project cache with race-safety rationale
- **`app/Services/Drawings/ManufacturerLogoResolver.php`** — top-20 logo lookup with D-14 needle ordering
- **`app/Services/Drawings/DrawingService.php`** — `saveSpikeXml` lock-on-edit + archive-prior + `archivePrior` helper
- **`app/Http/Controllers/Admin/DrawIoSpikeController.php`** — 2-param constructor (D-08 invariant), embed_url at line 53, save validation at line 59
- **`resources/views/admin/drawings/draw-io-spike.blade.php`** — Alpine.js component with postMessage handler, sourceMatch filter at line 92, persistXml flow at line 138
- **`app/Models/CableScheduleItem.php`** — Phase 22 model with 4 belongsTo relations + cable_id field
- **`app/Models/DevicePort.php`** — SIDE_*/DIRECTION_* constants + `port_id` (unique per stencil)
- **`app/Models/DeviceStencil.php`** — SOURCE_* constants + isCurated() helper + normalisePartNumber() static
- **`app/Models/Project.php`** — devicesWithStencils() at line 281, returns enriched lines; metadata field NOT currently in $fillable
- **`config/cables.php`** — signal_type_colours (locked Phase 22 single source of truth) + compatibility_aliases
- **`config/drawings.php`** — existing v1.3 keys; Phase 23 ADDS keys
- **`resources/data/draw-io-stencils/21cav-mtr-spike.json`** — 5 spike stencils showing `<constraint name="X" x="..." y="..." perimeter="0"/>` worked patterns

### Secondary (MEDIUM confidence)

- mxGraph `<mxfile>` multi-page format — documented at https://www.drawio.com/doc/faq/embed-mode (referenced in spike Blade comment line 77). Format consistent with what draw.io's `index.html` parses by default. Verifiable in browser UAT.

### Tertiary (LOW confidence — flagged for validation)

- D-01 seed map's category strings (`rack-mount-switch`, `ceiling-mic`, etc.) — researcher could not find evidence these strings are present in `latestPackage->extracted_data['equipment'][N]['category']` in production data. The categories actually used in `review.blade.php` `$categoryOptions` are the HIGH-LEVEL set (`hardware`, `cables`, ...). See Open Question 1. **Planner must verify against real DB data in Wave 1 before locking the layout engine to a specific category vocabulary.**
- XTEN-AV PAGING SYSTEM reference image's colour mapping (D-10 open issue). Researcher has only seen the textual REQUIREMENTS.md description vs the config. Visual contract is binding — planner verifies side-by-side.

---

## Metadata

**Confidence breakdown:**
- mxGraph stencil XML format (DRAW-42): **HIGH** — verified in spike stencil pack JSON
- Port-to-port edge XML (DRAW-43): **HIGH** — verified in current builder line 346-350 (coordinate style) + spike stencil constraint names (named-port style)
- Signal-type colour map (DRAW-44): **HIGH** — Phase 22 locked config. Open Question 2 (visual mismatch) is OPEN but doesn't change Phase 23 code.
- Cable ID labels (DRAW-45): **HIGH** — Phase 22 ships `cable_id` field; draw.io renders edge `value=` at midpoint natively
- Sub-room zones (DRAW-46): **MEDIUM** — pattern is correct; Open Question 1 (category strings) needs Wave-1 verification
- Multi-page paginator (DRAW-47): **MEDIUM** — `<mxfile>` is mxGraph-native; Open Question 3 (embed tab UX) needs browser UAT
- Title block (DRAW-48): **HIGH** — D-08 sources all verifiable; existing field on Project model + new metadata column
- Sheet border (DRAW-49): **HIGH** — trivial dashed-rect mxCell
- Validation architecture: **HIGH** — PHPUnit pattern verified across all prior Phase 21/22 plans
- Eager-load N+1 risk: **HIGH** — Phase 22 D-10 invariant + Pitfall 1 + Pitfall 9 cover the specific load shape
- D-LOCK invariants (deterministic, no AI, no v1.3 regression): **HIGH** — locked by existing tests

**Research date:** 2026-05-13
**Valid until:** 2026-06-13 (30 days — stable platform, locked decisions in CONTEXT, no fast-moving deps)
