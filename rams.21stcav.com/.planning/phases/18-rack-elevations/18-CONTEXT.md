# Phase 18: Rack Elevations - Context

**Gathered:** 2026-05-02
**Status:** Ready for planning
**Discussion outcome:** All clear — no additional discussion needed; research locks the architectural decisions. Four gray areas (multi-rack data model, equipment-to-rack assignment, U-height/power data source, partial-data footer behaviour) deferred to planner discretion with documented recommendations below.

<domain>
## Phase Boundary

Auto-generate 1U-precise rack elevations from rack-mounted equipment. Drag-reorder editor with per-item U-position lock. Multi-rack per project. Per-rack totals footer (weight / current draw / BTU / U-utilisation). Custom server-side Blade SVG renderer — no D2, no Konva, no React. PDF + SVG export via the existing `PdfRenderService` extensions from Phase 17.

**Maps requirements:** DRAW-07, DRAW-08, DRAW-09, DRAW-10, DRAW-11, DRAW-12, DRAW-13 (7 of 30 v1.3 requirements).

</domain>

<decisions>
## Implementation Decisions

All major decisions are locked in `.planning/research/SUMMARY.md` + STACK.md §1.2 + FEATURES.md Phase 18 + ARCHITECTURE.md §4.2 + PITFALLS.md CRIT-06. The user reviewed the gray areas and selected "All clear" — research + planner discretion is sufficient. The planner should treat the research files as canonical input and make the recommended choices on the four open items below.

### Locked from research (not up for revision in Phase 18 planning)

- **Custom Blade SVG renderer** — `RackElevationGeneratorService` + a private `SvgBuilder` helper (~150 LOC). D2/Konva are overkill for a stacked-list shape. SVG built directly via PHP string concatenation; rendered to PDF via `PdfRenderService::fromBlade()` (extension landed in Phase 17 Plan 1).
- **Single `project_drawings` table reused** — `kind='rack'` discriminator; mirrors Phase 17 schematic pattern. No new tables for the drawing record itself.
- **`BuildRackElevationJob` mirrors `BuildSchematicJob` exactly** — `$tries=2`, `$timeout=300`, idempotency timestamps, single `DrawingReadyMail` (kind discriminator), `failed()` admin alert via `DocumentGenerationFailedMail`.
- **`Device` schema migration owns `u_height` (decimal, allows 1.5) + `requires_ventilation_gap_above` (boolean) + `requires_ventilation_gap_below` (boolean)** — default NULL. Surfaces "U-height unknown" warnings, never silent 1U guess (CRIT-06).
- **Default ordering — AVIXA convention** — PDU bottom → switches → DSP → amps → patches/IO top. Equipment without a clear category falls to the middle. Manual drag-reorder overrides default; locks survive regenerate.
- **Drag-reorder via Alpine.js + Sortable.js** — vanilla JS pattern matching the existing install programme task list. No React.
- **Output formats** — PDF (via `PdfRenderService::fromBlade` + landscape A4 + ``waitForJs: false``) + SVG (direct download from `project_drawings.generated_svg`) + PNG (via `PdfRenderService::fromBladeAsPng` for O&M handover in Phase 20).
- **Lock-on-edit + archive-prior-version semantics** — reuse the Phase 17 pattern; per-item U-position lock survives regenerate.
- **Existing AV symbol pack reused where applicable** — `resources/svg/av-symbols/equipment-rack.svg`, `resources/svg/av-symbols/pdu.svg`, `resources/svg/av-symbols/network-switch.svg`. Most rack equipment renders as labelled rectangles, not icons.

### Recommended defaults for the four open gray areas (planner can adjust)

**Gray Area A — Multi-rack data model: RECOMMENDATION = each rack is its own `project_drawings` row with `kind='rack'`.** Mirrors schematic pattern. N rack drawings → N entries in the drawings index. Phase 20's drawing register paginates per-rack. No new tables. If per-rack metadata (rack name, floor, voltage) is needed, add to `project_drawings.source_data` JSON — not a separate `racks` table.

**Gray Area B — Equipment-to-rack assignment: RECOMMENDATION = hybrid keyword classifier + engineer override.** Auto-classify rack-mountable from name/category keywords (switcher, amplifier, PDU, DSP, codec, receiver, control processor, network switch, patch panel = rack-mounted; display, speaker, mic, dongle, mount, bracket = NOT rack-mounted). Engineer can override via a per-rack "add equipment to this rack" UI on the rack edit page. The schematic resolver's existing keyword filter (`mount`, `bracket`, `caddy`, `tray`) is the inverse hint.

**Gray Area C — U-height / power / weight data source: RECOMMENDATION = hand-curate a top-50 manufacturer pack as a seeded JSON file shipped in repo.** Cover the equipment 21CAV uses most often (Crestron RMC4 = 1U, AM-3200-GV = 1U, Netgear M4250-10G2F = 1U, etc.). Devices outside the pack render with "U-height unknown" warning + take 1U as a placeholder for layout purposes (with the warning visible). AI extraction from datasheets is v2.0 Phase 21 scope — defer.

**Gray Area D — Footer with partial data: RECOMMENDATION = show available totals + asterisk on incomplete metrics.** Example: "Weight: 28 kg* (4/7 known)", "Current: ?* (1/7 known)". Tooltip lists unclassified devices. Asterisk + ratio is honest about data quality without hiding the metric entirely.

### Claude's Discretion (planner decides)

- Rack frame default visual style (solid rectangle vs ventilated rails vs front-and-rear two-up view). RECOMMENDATION: solid rectangle with U-numbered rail on the left, simplest first.
- Default rack height — 21U / 27U / 42U starter. RECOMMENDATION: 42U baseline; engineer overrides per rack via a height field.
- Page orientation — A4 portrait (rack tall) vs A4 landscape (multiple racks side-by-side). RECOMMENDATION: portrait per-rack; Phase 20 paginates multiple racks across multiple pages.
- Footer formula sources — Device columns (`weight_kg`, `current_draw_a`, `btu_per_hour`) added in this phase OR pulled from an extension of the manufacturer JSON pack. RECOMMENDATION: extend the JSON pack from Gray Area C, no Device columns yet (defer Device-column expansion to v2.0 Phase 21).

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
- `app/Services/Drawings/DrawingDataResolverService.php` — `adjacencyForProject` already there; this phase adds `racksForProject` (or similar) helper
- `app/Services/PdfRenderService.php` — `fromBlade` + `fromBladeAsPng` extensions
- `app/Jobs/BuildSchematicJob.php` — pattern to mirror for `BuildRackElevationJob`
- `app/Mail/DrawingReadyMail.php` — single mailable, kind discriminator already wired
- `app/Services/DocumentArtifactStorage.php` — TYPE_DRAWING already exists
- `app/Http/Controllers/ProjectDrawingController.php` — extend with `createRack` action
- `routes/web.php` — extend with rack-specific routes (or kind-parameterised)

### Existing codebase precedent
- `app/Services/InstallProgrammeService.php` — drag-reorder + lock-position pattern from Phase 12 (rack drag-reorder mirrors this)
- `app/Services/InstallTaskGeneratorService.php` (post-260430-um1) — area-tag distribution from canonical project data; rack uses similar room-equipment filtering
- `app/Models/Device.php` — has `signal_role` + `isSource/isDestination/isProcessor` from Phase 17; this phase adds `u_height` + ventilation flags
- `app/Core/Modules/Projects/ProjectDataService.php` — DATA-03 contract; rack generators MUST consume `resolve()`, never raw extracted_data/reviewed_data

### Industry standards
- AVIXA F502.01 rack building (thermal management — drives ventilation gap requirements)
- EIA-310-D rack unit standard (1U = 1.75 in / 44.45 mm; defines mounting hole pitch)
- AVIXA Standard Guide for AV Systems Design and Coordination — rack ordering convention (PDU bottom up to patches/IO top)

### Operational precedent
- `.planning/quick/260427-qvr-migrate-pdf-rendering-to-browsershot/260427-qvr-SUMMARY.md` — Browsershot deployment runbook (chrome-headless-shell + AlmaLinux)
- Phase 17 ship — D2 binary install, font metrics fix (`Arial fallback` in schematic Blade), drawings queue config

</canonical_refs>

<specifics>
## Specific Ideas

User confirmed during milestone setup + Phase 17 iterations:
- Rack elevations are part of the v1.3 four-phase scope (17–20) — no scaling back
- DXF export for racks NOT in scope (DXF stretch is floor plans only — Phase 20)
- Engineering-grade fidelity (port-aware racks with rear views) deferred to v2.0 — see backlog 999.1
- Phase 18 ships "passable basic" rack elevations: front-view 2D rectangles, U-numbered rail, totals footer, drag-reorder, multi-rack

</specifics>

<deferred>
## Deferred Ideas

### To later phases (within v1.3)
- **PNG embed for O&M handover** — wired in Phase 20's OmManualDocxService extension (DRAW-26)
- **Drawing register pagination of multiple racks** — Phase 20 (DRAW-21)
- **Sheet numbering for racks** (e.g. AV-301, AV-302) — Phase 20 (DRAW-23)

### To v2.0 / future milestones (per backlog 999.1)
- **Per-equipment power circuit mapping** (which device plugs into which PDU outlet) — needs port catalog data engineering
- **Front-and-rear rack view** (two-up render) — not in v1.3 baseline; v2.0 if engineers ask
- **Rack thermal map / airflow visualisation** — out of scope
- **Per-rack cable management visualisation** — out of scope
- **AI auto-derive U-height from datasheet PDFs** — v2.0 Phase 21 scope
- **Rack-specific Device columns** (weight_kg, current_draw_a, btu_per_hour) — v2.0 Phase 21 scope; Phase 18 uses JSON pack only

</deferred>

---

*Phase: 18-rack-elevations*
*Context gathered: 2026-05-02*
*All gray areas reviewed; user selected "All clear" — research files + recommended defaults are canonical input for the planner*
