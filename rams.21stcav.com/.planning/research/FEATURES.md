# Feature Landscape: v1.3 Technical Drawings & Schematics

**Domain:** AV technical drawings — system schematics, rack elevations, floor plans, drawing export
**Researched:** 2026-04-30
**Confidence:** HIGH for AV-domain conventions (AVIXA standards, D-Tools/Stardraw/XTEN-AV/SymbolLogic verified); MEDIUM for auto-generation algorithm specifics (commercial tools don't publish heuristics); LOW for OSS DXF/DWG fidelity at the detail level engineers expect

## Scope Note

v1.3 layers technical-drawing capabilities onto an internal AV ops platform that already owns canonical project data (equipment, cables, rooms, surveys). All four phases must read from `ProjectDataService` — no parallel data sources, no AI invention. Reference platform: D-Tools SI v24, Stardraw Design 7, XTEN-AV X-DRAW, WireCAD, SymbolLogic stencils.

Existing v1.0–v1.2 features (quote import, surveys, RAMS, O&M, worksheets, cable schedules, install programmes, commissioning) are LOCKED context — drawings consume their data, never duplicate it.

---

## Phase 17 — System Schematics (Signal Flow Diagrams)

A signal-flow schematic answers: "What plugs into what, and what kind of signal travels between them?" It is a single-line diagram (SLD) where each line is a signal path between two device ports.

### Anatomy of a Real AV Schematic

Confirmed across AVIXA Standard Guide, Stardraw Block Schematic module, D-Tools Interconnect Schematics, and XTEN-AV X-DRAW:

- **Sources** (laptops, room PCs, cameras, ceiling mics, room controller inputs) — placed on the LEFT
- **Switching/processing** (matrix switchers, DSPs, video presentation switchers, encoders/decoders, scalers) — placed in the MIDDLE
- **Destinations** (displays, projectors, ceiling speakers, line outs, recording outputs) — placed on the RIGHT
- **Control** (Crestron/Extron/AMX processor, touch panels, network switch) — usually a separate band along bottom or top
- **Signal type per line** colour-coded — red=audio, blue=video, green=control, purple=network/Dante, orange=USB, dashed=power/DC trigger
- **Cable IDs on each line** (e.g. `HDMI-1`, `DANTE-12`, `RS232-3`) — references the cable schedule
- **Port labels at each connection** (`HDMI 1`, `OUT 2`, `Dante Tx 1-4`)
- **Room grouping boxes** — when one drawing covers multiple rooms, each room gets a labelled boundary

### Table Stakes

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Auto-generate schematic from canonical equipment + cable data per project | Core value prop — competitors (XTEN-AV, D-Tools v24, Stardraw) all do this; doing it by hand defeats the platform | XL | Algorithm: classify each equipment as source/processor/destination/control by signal-type heuristics; topologically sort by cable connections; place sources left, destinations right, processors middle |
| Per-room schematic page (one room = one drawing) | Engineers and clients read room-by-room; whole-project SLDs become unreadable past ~15 devices | M | Loop over `ProjectDataService` rooms; each room produces its own page |
| Signal-type colour coding (audio/video/control/network/USB/power) | Universal AV convention; line uniqueness without colour is unreadable | S | Map signal type → CSS colour at render time |
| Cable ID labels on every line | Ties schematic to cable schedule (existing v1.0 deliverable); install team uses these to verify | S | Cable IDs already exist in canonical data — just render |
| Port labels at endpoints | Engineer needs to know "HDMI 2 IN" vs "HDMI 1 OUT" — without this it's just connectivity, not wiring | M | Requires port metadata on equipment models — may need migration |
| Standard AV symbol library (display, speaker, mic, camera, switcher, DSP, amp) | AVIXA Architectural Drawing Symbols Standard expectation; generic boxes look amateur | L | Build SVG stencil set OR licence/adapt SymbolLogic-style; ~30–50 symbols cover 95% of cases |
| Title block (project ref, drawing number, revision, drawn by, date) | Every engineering deliverable expects this; missing it = not a credible deliverable | S | Reuse Project model fields; add `drawn_by`/`revision` to drawing record |
| PDF export per drawing | Tablet viewing + printout — primary delivery format | M | Render SVG → PDF via DomPDF or mPDF (already in stack) |
| Single multi-page PDF per project (cover + per-room schematics) | Bound deliverable matches O&M Manual handover pattern | S | Concatenate page PDFs |
| Auto-generated then editable workflow (NOT read-only) | Auto-generation is never perfect; engineers must be able to drag, re-route, override before approving | XL | Persist edits in `drawing_overrides` table — keyed to (project, drawing_type, room) so re-generation preserves manual overrides |
| Signal-flow direction (left-to-right or with arrowheads) | Anyone reading a schematic must instantly trace the flow; AVIXA + every tool surveyed enforces this | S | Place algorithm orders columns by topo-depth; arrow heads on every line |
| Re-generate without losing manual overrides | Equipment list changes mid-project (substitutions, additions); engineer can't redo all layout work | L | Diff old vs new equipment, preserve untouched device positions, mark new devices for placement review |

### Differentiators

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| Whole-project SLD (single overview page across all rooms) | Useful for design review and BOM verification; complements per-room | M | Same algorithm, larger canvas, room boundary boxes |
| Live link from schematic device → quote/spec sheet/O&M record | Click a device on the PDF (when SVG embedded), see its spec | M | Requires embedded hyperlinks in SVG; works in modern PDF viewers |
| Hover/click in web viewer reveals cable ID, port, length | Saves engineer cross-referencing the cable schedule | M | SVG `<title>` + Alpine tooltip in web view; doesn't help PDF/print |
| Network port matching auto-suggest (HDMI to HDMI, Dante Tx to Dante Rx) | Catches mis-wires at design time; XTEN-AV X-DRAW does this | L | Adapter insertion logic (e.g. flag HDMI-source → DisplayPort-sink without converter) |
| Conflict detection (over-subscribed switcher, missing converter, signal-type mismatch) | Prevents site-day surprises | L | Rule engine over canonical data; warns at generation time |
| AVIXA-standard architectural symbols for floor-plan-style schematic mode | Some clients prefer schematics overlaid on floor plan rather than block-flow | L | Optional second render mode; defer to v1.4+ |
| SVG export (separate from PDF) | Client portal embed — scales to any viewport without re-render | S | SVG is the rendering primitive anyway; just expose download |
| Auto-route lines around device blocks (no crossings where avoidable) | Reduces "spaghetti" appearance — what differentiates auto-gen from real engineering | XL | Orthogonal routing algorithms (Manhattan, A* on grid) — use library (e.g. ELK, dagre) rather than build |
| Comparison to prior revision (highlight added/removed/changed lines) | Useful when scope changes mid-project | M | Diff at data layer + visual overlay |

### Auto-Generation Algorithm (table-stakes core)

Confirmed pattern across XTEN-AV X-DRAW and D-Tools Interconnect Schematics:

1. **Classify** each device using equipment-type heuristics: source (laptop, camera, mic, BYOD plate), processor (matrix, DSP, scaler, codec), destination (display, projector, speaker, line out), control (processor, touch panel)
2. **Build directed graph** from cable schedule: for each cable, edge `(source_device:source_port) → (dest_device:dest_port)` with signal type
3. **Topological sort** — produces column buckets (column 0 = pure sources, column N = pure sinks)
4. **Layout** — sources left, sinks right, processors per their topological column; vertical position by cable connections (minimise crossings via barycentric heuristic)
5. **Render SVG** — symbols at computed (x,y), lines from port to port with cable ID + signal-type colour
6. **Persist** — store generated drawing JSON alongside any user overrides

### Workflow Recommendation

**Auto-generate then editable** is the only model that matches engineer reality. Pure auto-generation always produces 80%-correct layouts; engineers tweak the rest. Pure manual is what we're trying to escape. Read-only output is rejected by users (verified across XTEN-AV review, D-Tools positioning, Stardraw user feedback).

### Export Targets

- **PDF**: Per-room page + multi-page bound document (table stakes)
- **SVG**: For client portal viewing (table stakes)
- **DXF/DWG**: Defer to Phase 20 — block-flow schematics convert to DXF poorly anyway; users who need DXF want floor plans

---

## Phase 18 — Rack Elevations

A rack elevation is a vertical 19" rack drawing showing what equipment occupies which U-slot, front and rear views.

### Anatomy of a Real Rack Elevation

Verified across SymbolLogic AV stencil pack, D-Tools Rack Builder, Stardraw 19" rack module:

- **Vertical rack frame** — typically 21U, 27U, 42U, or 47U (standard EIA-310-D — 1U = 1.75" = 44.45mm)
- **U numbering** down the side (1 at bottom, increasing upward — industry convention; some tools number top-down so this is a config switch)
- **Front view** — shows front panels of all front-mounted equipment
- **Rear view** — shows rear panels (PDU strips, cable management, switch ports, optional)
- **Equipment blocks** sized to actual U-height (1U, 2U, 3U, 4U)
- **PDU(s)** — typically rear-mounted at bottom or running vertically along the side; rated A/circuit count visible
- **Blanking panels** — fill empty Us for airflow (often 1U/2U)
- **Ventilation/fan units** — typically every 6–10U depending on heat load
- **Cable management bars** between sections
- **Rack ears + meta panel header** — project ref, rack number, location
- **Per-equipment block content**: model name, manufacturer, U-height, sometimes serial/asset tag, port count, weight, power draw (W or A), heat output (BTU)
- **Total footer**: total weight, total amps drawn, total BTU, U-utilisation %

### Table Stakes

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Auto-generate rack from equipment list filtered to "rack-mounted" | Core value; equipment list already has form-factor data from quote | L | Filter `equipment.form_factor = 'rack'`; need U-height per item |
| 1U = consistent pixel scale (e.g. 1U = 30px), 19" wide rack drawn to scale | Engineers eyeball-check fit; non-scaled drawings are useless | S | Fixed scale constant in renderer |
| U-numbering down the side | Reference for cable schedule rack location columns ("Rack 1, U-12") | S | Render `1` at bottom, increment up |
| Equipment blocks labelled with model + manufacturer | Without this it's just coloured boxes | S | Text within block, scaled to U-height |
| Sensible default ordering (PDU bottom → switches/network → DSP/processing → amps → patches/IO top) | Engineers expect this — heat rises, so heat-emitters low; quiet/IO at top | M | Order rules per equipment category; documented heuristic |
| Manual U-position override (drag to a different slot) | Auto-gen can't know site-specific preferences | M | Click + arrow keys OR drag in canvas; persist in `rack_overrides` |
| Multi-rack support per project | Big projects have 2+ racks; can't crowd one | M | Project → has many racks; each rack its own elevation |
| Rear view (toggleable) | Cabling crew need rear; install crew need front; both ship | M | Render two side-by-side or two pages |
| Ventilation gaps + blanking panel auto-fill | Empty slots in finished rack indicate "did engineer forget?" — auto-blank reads as deliberate | S | Fill all unallocated Us with `BLANK_1U` after equipment placed |
| Per-rack totals: weight, current draw, BTU, U-utilisation | Critical for HVAC sizing + circuit allocation; expected on professional decks | M | Sum per rack; render in footer |
| Title block (project ref, rack ID, drawn-by, revision, date) | Same as schematic | S | Reuse drawing-meta component |
| PDF export — single page per rack | Print-on-tablet primary use | S | SVG → PDF |
| Re-generate preserves manual overrides | Adding a switch shouldn't reorder the whole rack | L | Same diff-then-merge approach as schematic |

### Differentiators

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| Drag-and-drop equipment from "unplaced" tray to rack | Visual workflow for the manual-override case | L | Konva.js / Fabric.js draggable; persist on drop |
| Lock specific equipment to specific U-position | Engineer can pin "DSP must be at U-15" before re-gen | S | `is_locked` flag per rack item |
| Heat map overlay (red zones = high BTU) | Visualises ventilation pinch points | M | Compute BTU/U, gradient overlay |
| Power draw per circuit (PDU port mapping) | Most rack-PDUs have 8/12/24 outlets; show which outlet each device plugs into | L | Requires PDU port assignment data |
| Cable management visualisation (IDs at U-edge) | Helps cabling team know where cables enter/exit | M | Render cable IDs at rack-side; link to schedule |
| Rack-mount accessory automatic insertion (1U cable management every 8U, 2U fan every 12U) | Saves engineer the manual blanking work | M | Rules engine over rack contents |
| Compare actual install vs design (post-commissioning) | Show what was installed where vs designed | L | Requires post-install asset register — overlap with v1.6 SVC-01 |
| Asset tag / serial barcode label printable | Field crew scan-and-go | M | QR codes at install — overlaps v1.6 |
| Rear-view PDU outlet mapping | Detailed enough to wire from drawing alone | L | PDU port-by-port; nice-to-have |

### Auto-Generation Algorithm (table-stakes core)

1. **Filter** `ProjectDataService` equipment to `form_factor = 'rack'` (or where U-height > 0)
2. **Group by rack assignment** — equipment may already have `rack_id` from canonical data; default-bucket unassigned items into `Rack 1`
3. **Sort within rack** by category-priority constants:
   - Bottom: PDU (always U1–U2)
   - Lower-mid: Network switches, patches
   - Mid: DSP, video processors, codec
   - Upper-mid: Amplifiers (heat — keep below sensitive gear is debated; some shops put amps at top, configurable)
   - Top: Patch panels, I/O plates, monitoring
4. **Place sequentially** from bottom: each item occupies next available U-block of its U-height
5. **Insert ventilation** — every 8U gap if heat-emitters present
6. **Fill remainder** with blanking panels
7. **Compute totals** — sum weight, current, BTU
8. **Render** SVG with U-grid, equipment blocks, labels, footer

### Workflow Recommendation

Same as schematics: **auto-generate then editable**. Engineer's edit flow is "drag this to U-15, lock it, re-gen, accept the rest."

### Export Targets

- **PDF**: One page per rack (front view; rear view as second page if rear is enabled) — table stakes
- **SVG**: Portal viewing — table stakes
- **DXF/DWG**: Rack elevations are the easiest DXF target (just rectangles + text), so include if Phase 20 ships DXF at all

---

## Phase 19 — Floor Plans (In-App Drawing Tool)

The hardest of the four phases — engineers must draw walls, doors, windows in a browser, then auto-place equipment per room.

### Anatomy of a Real AV Floor Plan

Verified across SymbolLogic AV floor plan stencils, AVIXA Architectural Drawing Symbols Standard, Mondo Media commercial AV deliverables guide:

- **Walls** — rectangles or thick lines (typical wall thickness ~100–150mm for stud, ~200–300mm structural)
- **Doors** — rectangle with arc showing swing direction
- **Windows** — wall-segment with double-line + sill notation
- **Dimensions** — between walls, door widths, equipment offsets (mm or feet+inches per project locale; UK = mm)
- **North arrow** + **scale bar** + **scale ratio** (e.g. 1:50, 1:100)
- **Ceiling height note** in title block
- **Room labels** (centered, large)
- **Equipment glyphs** — display, speaker, mic, camera, projector, screen, BYOD plate, control panel — placed at install location
- **Cable routes** — dashed/dotted lines showing wire paths through walls/ceiling
- **Mount heights** annotated next to each device (e.g. `MTG HT 1500 AFFL` — above finished floor level)
- **Coverage cones** for cameras, projectors, speakers (differentiator)
- **Title block + drawing register reference**

### Table Stakes

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| In-browser canvas drawing tool with primitives: wall (line/rect), door, window, text label | Phase 19 is essentially this — without it nothing else works | XL | Konva.js is best fit (object model + transformer + drag); Excalidraw too sketchy for engineering, TLDraw too whiteboard-flavoured |
| Snap-to-grid (configurable grid: 50mm/100mm/250mm) | Without snap, walls don't align — looks unprofessional, can't measure | M | Round (x,y) to grid on commit |
| Per-room canvas (one drawing per room) — tied to Project rooms | Already the data model; one big canvas across rooms is unmanageable | M | Each `Project.room` has 0..1 floor plan |
| Wall drawing with thickness (not just lines) | Engineers measure off wall faces; lines aren't measurable | M | Rect-with-thickness primitive; centre-line snap |
| Door primitive with swing arc + width annotation | Standard arch convention | S | Symbol stencil (rect + arc) |
| Window primitive (double-line in wall opening) | Standard arch convention | S | Symbol stencil |
| Dimension lines (between two points, auto-displayed measurement) | Without dimensions a floor plan is decoration | M | Line + computed length text + extension lines |
| Equipment glyph palette (display, speaker, mic, camera, projector, screen, BYOD, control panel) | Drag from palette to canvas; uses canonical equipment list per room | L | Palette filtered to room's equipment from `ProjectDataService` |
| Auto-place equipment to default positions on plan creation | Otherwise engineer drags every device manually for every room | L | Heuristic: display on user-tagged "presentation wall" (anchor wall concept); ceiling speakers in 2×2 or 3×3 grid centred on room; ceiling mic centred; rack adjacent to door — see algorithm below |
| Anchor-wall designation per room | Auto-place needs to know the "front" of the room | S | One-click in room setup ("this wall faces the audience") |
| Mount-height label on each equipment | Field crew install at correct height; without this it's wrong | S | Property on equipment-on-plan; default from equipment-type |
| Title block + scale bar + north arrow | Engineering deliverable expectation | M | Auto-include in render |
| PDF export per room | Same as other phases | S | SVG → PDF |
| Multi-room PDF (one per page) bound | Same as other phases | S | Concatenate |
| Persist canvas state (load + edit + save) | Stop work, resume later — basic | M | Serialise Konva JSON to DB; load on view |

### Differentiators

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| Architect's PDF/image as background reference layer | Hugely useful — engineer traces walls instead of measuring; XTEN-AV and D-Tools both support | M | Upload PDF, render as background image with opacity slider |
| Auto-trace walls from architect's PDF (CV-assisted) | Saves the tracing entirely; cutting-edge in 2026 | XL | OpenCV / contour detection; experimental — defer |
| Dimension auto-display (live as user drags wall endpoints) | Smooth UX during drawing | M | Recompute distances on drag |
| Coverage cones for cameras/projectors/speakers | Visualises coverage holes; AVIXA-aligned | M | Compute from FOV + mount data; render translucent overlay |
| Cable routing tool (path through walls/ceiling) | Useful for first-fix coordination | L | Polyline tool with snap + dashed style |
| Multi-room project on single canvas (whole-floor view) | Useful for cable runs spanning rooms | XL | Spatial linking of room canvases — major architectural decision |
| Layer system (walls / equipment / cables / dimensions / annotations) | Toggle visibility for clarity in different views | M | Konva supports layers natively |
| Ceiling plan mode (RCP — reflected ceiling plan) | AVIXA convention; ceiling-mounted devices belong here | L | Second canvas type per room; reuses primitives |
| Equipment data popover on click (model, spec, cable IDs) | Web view richness | M | Click handler + side panel |
| Scale calibration via known-distance line on background image | Lets engineer scale-match an architect's image quickly | M | Click two points + enter real distance |
| DXF/DWG export (separate from Phase 20 generic export) | Architect/MEP coordination — "send me the DWG" is the standard ask | L | Floor plans are the highest-value DXF target by far |
| Door/window auto-snap to wall edges | UX polish | M | Detect nearest wall, project onto centre |
| Undo/redo stack | Standard editor expectation | M | Konva has node-state, build undo wrapper |
| Print at scale (1:50, 1:100) — actual scale on A3 | Some engineers print and measure | M | Scaling math in PDF render |

### Auto-Placement Algorithm (table-stakes core)

Realistic for v1.3 — limited to obvious cases, manual drag for the rest:

1. Engineer marks one wall as **anchor (presentation) wall** during room setup (one-click)
2. For each piece of equipment in the room:
   - **Display / projector screen**: centred on anchor wall, at standard mount height (1.4–1.6m AFFL for displays, ceiling for projector)
   - **Camera**: above display centre on same wall, ~2m AFFL or ceiling
   - **Ceiling speakers**: count = room area / standard coverage (~12m² each); arrange in grid centred on room; if odd count, cluster toward presentation end
   - **Ceiling microphone**: 1 centred OR grid scaled to room size (4-mic grid >40m²)
   - **Floor box / table BYOD plate**: centre of room (engineer must drag — too site-specific)
   - **Rack / wall plate**: nearest corner to door (assumption — engineer overrides)
   - **Touch panel**: on wall adjacent to door at 1.1m AFFL
3. All placements persisted; engineer drags any to correct
4. Re-generate (after equipment change) only places NEW items, leaves existing placements

This is good-enough automation; XTEN-AV X-DRAW does similar; the gap from this to "actually correct" is always engineer judgement.

### Workflow Recommendation

**Hybrid**: optional architect PDF as background → engineer draws walls → auto-place equipment → engineer fine-tunes → save. Allow blank-canvas drawing for projects without architect PDFs.

### Export Targets

- **PDF**: Per-room page — table stakes
- **SVG**: Client portal — table stakes
- **DXF/DWG**: Floor plans are the most commonly requested DXF target — best-effort export with DXFighter (lines, polylines, text); BSD-licensed, 2D-only output is sufficient for AV handoff to architect/MEP

---

## Phase 20 — Drawing Export (Format Pipeline)

This phase ties the previous three together into a deliverable.

### How AV Deliverables Combine Drawings

Verified across National CAD Standard NCS sheet-organisation conventions, AVIXA Standard Guide for AV Systems Design, D-Tools deliverable-pack output:

- **Cover sheet** (project name, client, site, drawing register table — list of all sheets with sheet number + revision + date)
- **Sheet numbering convention** — discipline-prefix + sequential. AV-industry common practice (no single official AV standard, but consistent across SymbolLogic/D-Tools/Stardraw output):
  - `AV-001` Cover + drawing register
  - `AV-100` series Floor plans (`AV-101` Room 1, `AV-102` Room 2…)
  - `AV-200` series System schematics (`AV-201` Room 1 SLD…)
  - `AV-300` series Rack elevations (`AV-301` Rack 1…)
  - `AV-400` series Cable schedules (already shipped as XLSX in v1.0)
  - `AV-500` series Reflected ceiling plans (if Phase 19 ships RCP)
  - National CAD Standard discipline designator `Y` or `T` is sometimes used for AV/telecoms; project-locked rather than universal — make it configurable
- **Pagination** — per section, with consistent title block on every page
- **Title block** identical across every drawing — project ref, client, site, drawing number, sheet number (e.g. "Sheet 3 of 12"), revision, drawn/checked/approved with dates, scale, drawing type

### Table Stakes

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Single bound PDF per project (cover + drawing register + all drawings, paginated) | The AV deliverable expectation; loose pages aren't shippable | L | Concatenate per-drawing PDFs with cover sheet generated from drawing index |
| Drawing register cover sheet (table of sheet numbers, titles, revisions, dates) | Project handover requirement; first page recipients see | M | Auto-generate from drawings collection on project |
| Standard title block on every sheet (project ref, sheet num, rev, drawn-by, date, scale) | Engineering deliverable convention; reused across all 4 phases | M | Shared Blade partial / SVG component injected into every drawing |
| Sheet numbering scheme (configurable prefix + sequential) | Engineers reference drawings by sheet number ("see AV-201") in RAMS, O&M, on-site comms | M | Settings per project OR global default; auto-assign on drawing creation |
| Per-drawing PDF download (individual sheet) | Quick pull during install — don't always want the bound pack | S | Reuse per-phase PDF render |
| SVG export per drawing | Client portal viewing (v1.4 PORT-02 dependency) | S | SVG is the source format |
| Revision tracking per drawing (R0, R1, R2…) with date + author | Industry expectation; lets engineers know "is this current?" | M | `drawing_revisions` table; bump on save |
| Inclusion in O&M Manual handover document | Already-stated v1.3 goal; existing O&M generator picks up drawings as section | L | Hook into `OmManualDocxService` — embed PDFs or reference by sheet number |
| Drawn-by / checked-by / approved-by sign-off fields | Quality gate; AVIXA AV9000 expectation | S | User picker + approval timestamp |
| Drawing status (draft / for review / approved / superseded) | Lifecycle for review workflow | M | Status enum on drawing |

### Differentiators

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| DXF export per drawing (DXFighter library) | Architect/MEP coordination — common request; rack elevations + floor plans only (schematic DXF is low-value) | L | DXFighter is BSD, R15 (AC1012); 2D entities only; sufficient for floor plans + racks |
| DWG export | Some clients explicitly require DWG, not DXF | XL | No usable OSS for DWG write; would need ODA SDK (commercial, expensive) or Aspose.CAD Cloud — defer |
| Email drawing pack to client/internal | Reuse existing v1.1 notification pipeline | S | New `DrawingsReadyMail` mailable |
| Per-revision diff PDF (overlay R1 over R0 with changes highlighted) | Useful in change-control situations | XL | Hard — defer |
| Drawing approval workflow (reviewer comments + approve/reject) | Internal QA loop | L | Comments table + approver role |
| Watermark "DRAFT" / "FOR REVIEW" / "APPROVED" overlay per status | Stops draft drawings being treated as final | S | CSS overlay per status |
| Custom title block per client (logo, address, registration numbers) | White-label feel for client deliverables | M | Per-client branding fields |
| Drawing pack zip download (PDFs + SVGs + DXFs) | Power-user "give me everything" button | S | Zip artifact files |
| Plot to A3 with correct scale | Engineers print A3 routinely; scale must hold | M | Scale-aware PDF output (already in Phase 19 differentiators) |
| Auto-include in client portal (v1.4) | Foundation for portal-driven document access | M | Storage in `documents` disk, exposed via Phase 21+ |

---

## Phase-Crossing AV-Specific Conventions (Easy to Miss)

These are conventions that generic diagram tools (Lucidchart, DrawIO, Visio with AV stencils) routinely miss but engineers expect:

| Convention | Why It Matters | Implementation Note |
|------------|---------------|---------------------|
| Cable IDs match cable schedule exactly | Field crew uses the schedule on tablet + drawing on print together — IDs must match character-for-character | Single source of truth — drawings render from `cable_schedule` rows, never duplicate |
| Equipment model labels match O&M Manual exactly | Engineer cross-references "amp 1" between docs — naming drift is rejected at QA | Render from canonical `equipment` model |
| Title block consistent across ALL deliverables (RAMS, O&M, drawings) | Shows it's one project; competing docs from one project should match | Reuse RAMS/O&M title block style |
| Revision blocks with description ("R1: Added 2× ceiling speakers") | "What changed" is more useful than just a version number | Required text field on revision bump |
| Drawing register on cover sheet | Industry expectation; without it the pack doesn't read as a deliverable | Auto-generate |
| Discipline-prefix sheet numbers (`AV-201` not `Sheet 5`) | Engineers reference drawings by sheet number in RAMS method statements, O&M, on-site comms | Configurable prefix per project |
| AVIXA-standard symbols (or close to) | Differentiates from generic "boxes with labels" output | Custom SVG stencil set — invest once, reuse everywhere |
| Signal-flow direction always source→destination | AVIXA + every commercial tool enforces; reverse direction is rejected | Place algorithm enforces |
| Mount-height annotations on floor plans | Without these, install crew installs at wrong height | Default per equipment-type, overridable |
| Scale bar + north arrow on every floor plan | AVIXA + general drafting convention | Auto-include |
| One-line vs multi-line schematic distinction | "Single-line diagram" specifically means one-line-per-connection regardless of conductor count | Phase 17 is one-line by default |
| Reflected ceiling plan separate from floor plan | Ceiling devices belong on RCP; mixing makes drawings unreadable | Defer to differentiator unless table-stakes pressure emerges |
| British conventions if UK project — mm not inches, AFFL not AFF, "earthing" not "grounding" | 21CAV is UK | Locale-aware label rendering |
| 1:50 scale most common for room-size floor plans, 1:100 for larger | Engineers know these conventions, expect consistent scale labelling | Scale-aware export |

---

## Anti-Features

Features explicitly NOT to build, even though tempting:

| Anti-Feature | Why Avoid | What to Do Instead |
|--------------|-----------|-------------------|
| AI-generated drawings (full layout from prompt) | Violates the project constraint — AI may not invent design; also produces drawings that look right but are wrong (XTEN-AV's AI features have known mis-routing issues) | AI may help with naming/layout heuristics, never with what gets drawn |
| Real-time multi-user collaborative editing | Out of scope per PROJECT.md; Konva.js supports it but complexity isn't justified for internal team | Single-user edit per session — match existing pattern |
| 3D rack visualisation | Looks impressive in demos; engineers print and read 2D | Stick to 2D front + rear views |
| BIM/Revit integration | XL+ effort, niche use case for AV-only deliverable | Export DXF for architect's BIM team |
| Native mobile drawing app | Out of scope per PROJECT.md; engineers draw at desk | Mobile = view-only PDF on tablet |
| Generic flowchart/whiteboard mode | Mission creep; engineers want AV-specific tools, not yet-another DrawIO | Stay AV-domain only |
| Drag-drop schematic from blank canvas (no auto-gen) | Defeats the platform's value prop ("drawings derive from canonical data") | Auto-generate first, edit second; never blank-canvas |
| Spec-sheet PDF stitching into drawings | Bloats the pack; O&M Manual already has spec sheets | Keep drawings clean; cross-reference O&M sheet numbers |

---

## Feature Dependencies

```
Phase 17 Schematic
  ├─ Requires: cable schedule (v1.0 ✓), equipment with port metadata (NEW)
  ├─ Requires: SVG render pipeline (NEW)
  ├─ Requires: drawing-meta + revision data model (NEW — shared with all phases)

Phase 18 Rack Elevation
  ├─ Requires: equipment with U-height + form_factor metadata (NEW — likely composer.json migration)
  ├─ Requires: SVG render pipeline (NEW — shared with Phase 17)
  ├─ Requires: drawing-meta + revision data model (NEW — shared)

Phase 19 Floor Plan
  ├─ Requires: in-browser canvas library (NEW — Konva.js recommended)
  ├─ Requires: equipment glyph stencil set (NEW)
  ├─ Requires: persisted canvas state per room (NEW table)
  ├─ Requires: anchor-wall designation per room (NEW field on Room model)

Phase 20 Drawing Export
  ├─ Requires: All of 17/18/19 producing SVG (DEPENDS)
  ├─ Requires: PDF concatenation (existing DomPDF/mPDF stack)
  ├─ Requires: drawing register data model (NEW — sheet numbers)
  ├─ Optional: DXFighter integration for DXF (NEW lib)
  └─ Hooks into: OmManualDocxService for handover inclusion (existing v1.0)
```

**Implication:** Build Phase 20's data-model foundation (drawings table, revision tracking, sheet numbering, title block component) FIRST — even before Phase 17's schematic generator — because all three drawing phases need it. This makes Phase 20's pure-export work much smaller at the end.

---

## MVP Recommendation

For v1.3, the must-ship MVP is:

1. **Phase 17 table stakes** — auto-generated per-room schematic, signal-type colours, cable IDs, port labels, edit-after-gen, PDF + SVG export
2. **Phase 18 table stakes** — auto-generated rack elevation per rack, U-numbered, sensible default ordering, manual override, totals footer, PDF + SVG export
3. **Phase 19 table stakes minus auto-trace** — Konva-based wall/door/window primitives, per-room canvas, equipment glyph palette, simple auto-place, PDF + SVG export. Skip RCP, skip background-PDF tracing for MVP.
4. **Phase 20 table stakes** — bound PDF, drawing register, title block, sheet numbering, revision tracking, individual SVG/PDF, O&M inclusion. Defer DXF unless time permits.

Defer to v1.4+ or follow-up phases:
- DXF/DWG export — defer DWG indefinitely (no OSS); DXF only if engineer demand emerges
- Architect PDF background tracing
- RCP separate from floor plan
- Coverage cones / heat maps
- Conflict detection / auto-routing
- Drawing approval workflow

Defer rationale: AV deliverable basics (per-room schematic + rack + floor plan + bound PDF) are what makes v1.3 credible. Polish and CAD-handoff features can come once engineers are using the basics every day and giving feedback.

---

## Sources

### Primary (HIGH confidence — verified industry standards)
- [AVIXA Audio Video and Control Architectural Drawing Symbols Standard](https://www.avixa.org/standards/audio-video-and-control-architectural-drawing-symbols)
- [AVIXA Standard Guide for AV Systems Design and Coordination](https://www.avixa.org/standards/standard-guide-for-audiovisual-systems-design-and-coordination-processes)
- [AVIXA Audiovisual Systems Performance Verification](https://www.avixa.org/standards/audiovisual-systems-performance-verification)
- [AVIXA AV System Documentation Overview](https://xchange.avixa.org/posts/av-system-documentation-a-comprehensive-overview)
- [Uniform Drawing System Module 1 - Sheet Identification (NCS)](https://www.nationalcadstandard.org/ncs6/pdfs/ncs6_uds1.pdf)
- [EIA-310-D Rack Unit Standard summary - Lianjie](https://www.lianjer.com/rack-units-ru/)

### Tool reference (HIGH confidence — capability surveys for table-stakes definition)
- [D-Tools System Integrator v24 features](https://www.d-tools.com/system-integrator-features)
- [D-Tools SI v24 Cloud release notes ISC West 2026](https://www.commercialintegrator.com/news/d-tools-to-showcase-si-v24-and-cloud-advancements-at-isc-west-2026/146833/)
- [Stardraw Design 7 Block Schematic module](https://www.stardraw.com/sd7/features/modules/blockschematic)
- [XTEN-AV X-DRAW capabilities](https://xtenav.com/x-draw/)
- [SymbolLogic AV floor plan stencils](https://symbollogic.com/drawings/avfloorplan/)
- [SymbolLogic rack elevation stencils](https://symbollogic.com/drawings/rackelevations/)

### Domain practice (MEDIUM confidence — practitioner sources)
- [21st Century AV — ideal AV rack depth/height](https://21stcenturyav.com/what-is-the-ideal-av-rack-depth-and-height/) (this project's company)
- [XTEN-AV rack elevation diagram guide](https://xtenav.com/rack-elevation-diagram/)
- [XTEN-AV 8 best practices for rack design](https://xtenav.com/8-best-practices-for-av-rack-design/)
- [XTEN-AV best AV single-line diagram software](https://xtenav.com/best-av-single-line-diagram-software/)
- [XTEN-AV signal flow diagram software](https://xtenav.com/signal-flow-diagram-software/)
- [Mondo Media — Architectural drawings for commercial AV](https://mmsproav.com/blog/understanding-types-of-architectural-drawings-for-commercial-av-projects/)
- [Vectorworks — Reflected ceiling plans](https://www.vectorworks.net/en-US/newsroom/introduction-to-reflected-ceiling-plans)
- [Beginner's guide to AV schematics](https://avtechsolutions.wixsite.com/avsolutions/post/beginner-s-guide-to-drawing-professional-av-schematics)
- [Common signal flow diagram mistakes](https://soundsightav.odoo.com/blog/our-blog-1/common-signal-flow-diagram-mistakes-and-how-to-avoid-them-173)
- [University of Houston AV Design Standards](https://www.uh.edu/infotech/services/computing/networks/network-infra-standards/av-standards-files/uhaudiovisualdesignstandards_v01_04.pdf)

### Tooling (HIGH confidence — verified library availability)
- [DXFighter PHP library](https://github.com/enjoping/DXFighter) — BSD 3-Clause, AC1012 R15
- [Konva.js — Interactive Building Map demo](https://konvajs.org/docs/sandbox/Interactive_Building_Map.html)
- [Konva.js — Free Drawing demo](https://konvajs.org/docs/sandbox/Free_Drawing.html)
- [EasySchematic — open-source AV signal flow tool](https://github.com/duremovich/EasySchematic)
- [draw.io SVG export documentation](https://www.drawio.com/doc/faq/export-to-svg)

### Lower confidence (LOW — limited/single-source)
- AV9000 quality standard title-block specifics — confirmed structure but exact field requirements not pulled from the standard text; treated as "standard engineering title block" baseline
- AV-discipline NCS designator — no single official letter for AV; D-Tools/SymbolLogic use `AV-XXX` prefix convention; codified as project-configurable rather than mandated
- Auto-placement algorithm specifics — competitors don't publish heuristics; stated algorithm is a synthesis of how outputs look, not how internals work
