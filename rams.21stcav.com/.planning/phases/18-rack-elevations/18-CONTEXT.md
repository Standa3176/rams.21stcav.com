# Phase 18: Rack Elevations - Context

**Gathered:** 2026-05-02
**Status:** Ready for planning
**Discussion outcome:**
- User feedback received after first CONTEXT.md draft. Phase 18 expanded to include a **unified drawing-creation picker UX** (single "+ Create Drawing" button on the project drawings index) that supersedes the per-kind buttons currently shipped from Phase 17.
- For each drawing kind the picker offers an **auto-generate-or-blank toggle**: engineer can either start from auto-derived layout (keyword classifier + AVIXA ordering) or a blank canvas they build themselves.
- "Connection" / port-level wiring drawing kind is **explicitly deferred to v2.0** (backlog 999.1) — needs the device port catalog.

<domain>
## Phase Boundary

Two work streams in this phase:

1. **Unified drawing-creation picker** — single "+ Create Drawing" button on the project drawings index. Picker offers three kinds: Signal Flow (Schematic), Rack Elevation, Floor Plan / Elevation. Each kind asks "Auto-generate from project data?" with Yes / No paths. Replaces the per-kind buttons currently rendered from Phase 17.
2. **Rack Elevation drawing kind** — auto-generate (keyword classifier picks rack-mountable equipment + applies AVIXA ordering) OR blank (engineer drags equipment from a palette into U-slots manually). 1U-precise vertical rack with U-numbered rail, drag-reorder + per-item U-position lock, multi-rack per project, totals footer (weight / current draw / BTU / U-utilisation). Custom server-side Blade SVG renderer — no D2, no Konva, no React. PDF + SVG export via existing `PdfRenderService` extensions from Phase 17.

**Maps requirements:** DRAW-07, DRAW-08, DRAW-09, DRAW-10, DRAW-11, DRAW-12, DRAW-13 (7 of 30 v1.3 requirements). The picker UX is foundational infrastructure that supports DRAW-25 (status enum) + benefits Phase 19 (floor plans) + Phase 20 (exports) — but lands in Phase 18 because that's when a second drawing kind appears and a picker becomes useful.

</domain>

<decisions>
## Implementation Decisions

All major decisions are locked in `.planning/research/SUMMARY.md` + STACK.md §1.2 + FEATURES.md Phase 18 + ARCHITECTURE.md §4.2 + PITFALLS.md CRIT-06.

### Drawing-creation picker (NEW — user feedback)

- **Single "+ Create Drawing" button** on `/projects/{id}/drawings/`. Replaces the per-kind buttons.
- **Picker modal lists three kinds:** Signal Flow / Rack Elevation / Floor Plan / Elevation. "Connection" is intentionally absent — deferred to v2.0.
- **Each kind asks "Auto-generate from project data?"** with Yes / No paths:
  - **Yes** — kind-specific auto-gen runs (schematic = existing R0 flow; rack = keyword classifier + AVIXA ordering; floor plan = anchor-wall placement, Phase 19)
  - **No** — empty drawing created with `canvas_state` initialised to a blank kind-specific scaffold; engineer builds manually
- **Existing "+ Generate Schematic" button stays as a shortcut** — clicks default to picker → Signal Flow → Auto-gen yes (one-click flow preserved for engineers used to it).
- **Picker behaviour by kind:**
  - **Signal Flow** — picker offers "Auto-generate from project data?" Yes / No toggle. Yes runs the existing Phase 17 auto-gen flow (R0-style). No creates a blank schematic for engineer to build.
  - **Rack Elevation** — NO auto-generate option. Picker creates an empty rack and opens the rack editor directly. Engineer always builds the rack manually. (Auto-place keyword classifier + AVIXA ordering deferred to v1.3.x or v2.0 per user feedback.)
  - **Floor Plan / Elevation** — handed off to Phase 19 (Konva canvas, blank by default).
- **Picker UX itself is small** — Alpine.js modal with 3 kind cards. Schematic card has a Yes/No toggle; Rack and Floor Plan cards have a single "Create" action. ~1 plan-task of work.

### Rack-specific decisions

- **Engineer always builds the rack manually** — no auto-place flow. Rack creation = empty 42U rack opens in the editor; engineer drags equipment from a palette into U-slots. AVIXA-default-ordering algorithm and keyword auto-classifier are NOT in v1.3 scope.
- **Custom Blade SVG renderer** — `RackElevationRenderService` + private `SvgBuilder` helper (~150 LOC). D2/Konva are overkill for a stacked-list shape.
- **Single `project_drawings` table reused** — `kind='rack'` discriminator. Mirrors Phase 17 schematic pattern. No new tables for the drawing record itself. Per-rack metadata (rack name / floor / nominal voltage / rack height in U) stored in `project_drawings.source_data` JSON.
- **One `project_drawings` row per rack** (Gray Area A resolved). N rack drawings → N entries in the drawings index. Phase 20's drawing register paginates per-rack.
- **`Device` schema migration owns `u_height` (decimal, allows 1.5)** + `requires_ventilation_gap_above` (boolean) + `requires_ventilation_gap_below` (boolean) + `is_rack_mounted` (boolean). All default NULL. Surfaces "U-height unknown" warnings, never silent 1U guess (CRIT-06).
- **Equipment palette filtering** — palette shows project equipment with `is_rack_mounted=true` first, all other equipment second (greyed but draggable). The `is_rack_mounted` field is engineer-set during quote review or by clicking a checkbox in the rack editor's palette ("This is rack-mounted equipment"). No automatic classification — engineer makes the call.
- **Drag-reorder via Alpine.js + Sortable.js** — vanilla JS pattern, mirrors install programme task list. Each U-slot is a `<div>` drop zone; equipment row is draggable item with computed U-height span.
- **U-position lock** — per-item lock toggle. Locked items don't move on regenerate / drag operations. Unlocked items reflow.
- **Multi-rack per project** — engineer creates each rack via the picker. Each rack is independent (own drawing row, own status, own revisions). No cross-rack relationships.
- **Hand-curated top-50 manufacturer JSON pack** for U-height / current / weight / BTU data. Devices outside the pack get "unknown" warnings + 1U placeholder for layout. AI extraction deferred to v2.0.
- **NO `BuildRackElevationJob`** — rendering is synchronous when the engineer clicks "render" / "save". The rack canvas state is the source of truth; SVG generation happens server-side on save in <2s. PDF render via `PdfRenderService::fromBlade` happens on demand at download time. This differs from Phase 17 schematics (which use a queued job) — the rack flow has no AI/D2/heavy work to defer.
- **Output formats** — PDF (via `PdfRenderService::fromBlade` + landscape A4 + `waitForJs: false`) + SVG (direct download from `project_drawings.generated_svg`) + PNG (via `PdfRenderService::fromBladeAsPng` for O&M handover in Phase 20).
- **Lock-on-edit + archive-prior-version semantics** — reuse the Phase 17 pattern. Per-item U-position lock survives revision changes.
- **Footer behaviour with partial data (Gray Area D resolved)** — show available totals + asterisk on incomplete metrics. Example: "Weight: 28 kg* (4/7 known)", "Current: ?* (1/7 known)". Tooltip lists unclassified devices. Asterisk + ratio is honest about data quality.

### Existing AV symbol pack reused

`resources/svg/av-symbols/equipment-rack.svg` (rack frame), `resources/svg/av-symbols/pdu.svg`, `resources/svg/av-symbols/network-switch.svg`. Most rack equipment renders as labelled rectangles, not icons.

### Claude's Discretion (planner decides)

- Rack frame default visual style (solid rectangle vs ventilated rails vs front-and-rear two-up view). RECOMMENDATION: solid rectangle with U-numbered rail on the left, simplest first. Two-up view → v2.0.
- Default rack height — 21U / 27U / 42U starter. RECOMMENDATION: 42U baseline; engineer overrides per rack via a height field on rack create.
- Page orientation — A4 portrait (rack tall) vs A4 landscape (multiple racks side-by-side). RECOMMENDATION: portrait per-rack; Phase 20 paginates multiple racks across multiple pages.
- Picker visual style — modal vs slide-over vs inline expansion. RECOMMENDATION: Alpine modal matching the existing regenerate-confirm modal pattern from Phase 17 Plan 3.
- `is_rack_mounted` checkbox UX — engineer-set in quote review (existing project-package review page) OR inside the rack editor's palette. RECOMMENDATION: both — palette shows checkbox per equipment row so engineer can flip during rack-build; quote-review page also gets the column for bulk-set during quote import review.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents (planner, executor) MUST read these before planning or implementing.**

### Research (this milestone)
- `.planning/research/SUMMARY.md` — top-level synthesis; Phase 18 sub-section
- `.planning/research/STACK.md` §1.2 Rack Elevations (custom Blade SVG)
- `.planning/research/FEATURES.md` Phase 18 — Rack Elevations
- `.planning/research/ARCHITECTURE.md` §4.2 Phase 18 render pipeline
- `.planning/research/PITFALLS.md` CRIT-06 (U-height accuracy) — must not silent-guess 1U

### Phase 17 foundations (already shipped — Phase 18 builds on these)
- `app/Models/ProjectDrawing.php` — status state machine + KIND_* constants (KIND_RACK already exists)
- `app/Services/Drawings/DrawingService.php` — createForProject + generateInitial + regenerate + archivePrior
- `app/Services/Drawings/DrawingDataResolverService.php` — has `adjacencyForProject` for schematic; this phase adds `racksForProject` (or extends `adjacencyForProject` with kind awareness)
- `app/Services/PdfRenderService.php` — `fromBlade` + `fromBladeAsPng` extensions
- `app/Jobs/BuildSchematicJob.php` — pattern to mirror for `BuildRackElevationJob`
- `app/Mail/DrawingReadyMail.php` — single mailable, kind discriminator already wired
- `app/Services/DocumentArtifactStorage.php` — TYPE_DRAWING already exists
- `app/Http/Controllers/ProjectDrawingController.php` — extend with `createRack` action + replace per-kind buttons with picker
- `app/Services/DocumentEdits/Adapters/DrawingEditAdapter.php` — extends operation enum for rack-specific ops (`set_rack_height`, `lock_u_position`, etc.)
- `routes/web.php` — extend with rack-specific routes (or kind-parameterised)
- `resources/views/projects/drawings/index.blade.php` — replace per-kind buttons with single picker entry point
- `resources/views/projects/drawings/_regenerate-confirm-modal.blade.php` — pattern to mirror for the new "+ Create Drawing" picker modal

### Existing codebase precedent
- `app/Services/InstallProgrammeService.php` — drag-reorder + lock-position pattern from Phase 12 (rack drag-reorder mirrors this exactly)
- `app/Services/InstallTaskGeneratorService.php` (post-260430-um1) — area-tag distribution from canonical project data
- `app/Models/Device.php` — has `signal_role` + `isSource/isDestination/isProcessor` from Phase 17; this phase adds `u_height` + ventilation flags + `is_rack_mounted`
- `app/Core/Modules/Projects/ProjectDataService.php` — DATA-03 contract; rack generators MUST consume `resolve()`, never raw extracted_data/reviewed_data
- `package.json` — Sortable.js may need adding; check first

### Industry standards
- AVIXA F502.01 rack building (thermal management — drives ventilation gap requirements)
- EIA-310-D rack unit standard (1U = 1.75 in / 44.45 mm; defines mounting hole pitch)
- AVIXA Standard Guide for AV Systems Design and Coordination — rack ordering convention (PDU bottom → patches/IO top)

### Operational precedent
- `.planning/quick/260427-qvr-migrate-pdf-rendering-to-browsershot/260427-qvr-SUMMARY.md` — Browsershot deployment runbook (chrome-headless-shell + AlmaLinux)
- Phase 17 ship — D2 binary install, font metrics fix (Arial fallback in schematic Blade), drawings queue config

</canonical_refs>

<specifics>
## Specific Ideas

User confirmed during this discussion:
- Single "+ Create Drawing" button replaces per-kind buttons (UX from Lucidchart / Visio / draw.io)
- Picker offers Signal Flow (Schematic) + Rack Elevation + Floor Plan / Elevation — three kinds
- **Schematic kind keeps the auto-generate-or-blank toggle** (Phase 17 flow already shipped)
- **Rack kind has NO auto-generate option** — engineer always builds the rack manually. Picker creates an empty rack and opens the editor directly.
- "Connection" / port-level wiring drawing is explicitly deferred to v2.0 (backlog 999.1)
- Engineering-grade fidelity (port-aware racks with rear views, manufacturer-specific port rails) deferred to v2.0
- Phase 18 ships "passable basic" rack elevations: front-view 2D rectangles, U-numbered rail, totals footer, drag-reorder, multi-rack, unified picker entry point
- Phase 18 = 2 plans (18-01 picker + schema + manufacturer pack; 18-03 rack editor with palette + drag + render)

</specifics>

<deferred>
## Deferred Ideas

### To later phases (within v1.3)
- **PNG embed for O&M handover** — wired in Phase 20's OmManualDocxService extension (DRAW-26)
- **Drawing register pagination of multiple racks** — Phase 20 (DRAW-21)
- **Sheet numbering for racks** (e.g. AV-301, AV-302) — Phase 20 (DRAW-23)

### To v1.3.x (post-v1.3 quick task) or v2.0
- **Auto-place keyword classifier + AVIXA ordering for racks** — was in scope until user feedback; engineer always manually builds racks in v1.3. If engineers later request "auto-fill from project equipment", ship as a quick task that adds a "+ Auto-fill" button inside the rack editor (palette → bulk-add filtered by `is_rack_mounted=true` + applies AVIXA ordering to unlocked items). Estimated 1 day.

### To v2.0 / future milestones (per backlog 999.1)
- **Connection diagram drawing kind** — port-level wiring with connector pinouts; needs port catalog from v2.0 Phase 21
- **Per-equipment power circuit mapping** (which device plugs into which PDU outlet) — needs port catalog
- **Front-and-rear rack view** (two-up render) — not in v1.3 baseline
- **Rack thermal map / airflow visualisation** — out of scope
- **Per-rack cable management visualisation** — out of scope
- **AI auto-derive U-height from datasheet PDFs** — v2.0 Phase 21 scope
- **Rack-specific Device columns** (weight_kg, current_draw_a, btu_per_hour) — v2.0 Phase 21 scope; Phase 18 uses JSON pack only

</deferred>

---

*Phase: 18-rack-elevations*
*Context gathered: 2026-05-02 (revised after user UX feedback)*
*Decisions: unified picker + auto-or-blank toggle locked; remaining choices recommended-as-default for the planner*
