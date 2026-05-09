# RAMS Platform — v2.0 Requirements

**Milestone:** v2.0 Engineering-Grade AV Drawings
**Defined:** 2026-05-09
**Phases:** 21–25 (5 phases)
**Total requirements:** 28
**Visual contract:** XTEN-AV PAGING SYSTEM reference (shared 2026-05-09)
**Platform:** draw.io / mxGraph (validated by spike `260509-ibx` 2026-05-09)

---

## Milestone v2.0 Requirements

### Phase 21 — Device Port Catalog + Stencil Cache

Foundation. Every hardware item that appears in a project's equipment_list gets a stencil — auto-generated as a generic placeholder for uncatalogued parts (Tier 1), promotable to a properly-curated card (Tier 2 in Phase 24). Cross-project caching via `firstOrCreate` on part_number.

- [ ] **DRAW-31**: `device_ports` table — per-device port metadata: `label`, `side` (left/right), `connector_type` (HDMI/USB-A/USB-B/USB-C/RJ45/RS-232/3.5mm/XLR/PHX/etc.), `signal_type` (audio/video/control/network/USB/power), `sort_order`
- [ ] **DRAW-32**: `device_stencils` table — `part_number` (unique), `manufacturer`, `model`, `display_name`, `mxgraph_xml`, `logo_svg`, `source` enum (auto-generated / engineer-curated / ai-extracted)
- [ ] **DRAW-33**: Hand-curated seed pack: top 50 devices from last 12 months of 21CAV quote volume — Crestron RMC4 / Sony FW-displays / ClickShare Bar Pro / Cisco SF300 / Bogen NQ-* / Sennheiser TC mics / Netgear M4250 / Q-SYS Core / etc.
- [ ] **DRAW-34**: Auto-generic placeholder stencil for any uncatalogued `part_number` — rectangle with manufacturer+model+name, no port detail. `firstOrCreate` caches per part_number for cross-project reuse.
- [ ] **DRAW-35**: Manufacturer logo glyphs (inline SVG) for top 20 brands present in the seed pack
- [ ] **DRAW-36**: `Project::devicesWithStencils()` accessor — returns equipment_list hardware items joined to device_stencils, ready for the renderer

### Phase 22 — Cable Schedule with Port-Level FKs

Enables port-to-port cable routing in the renderer. Existing cable_schedule_items become typed via FKs to source_port and dest_port. Backfill where unambiguous (e.g. "Bar to Display - HDMI" with a single HDMI port on each side).

- [ ] **DRAW-37**: `cable_schedule_items.source_device_id`, `source_port_id`, `dest_device_id`, `dest_port_id` FK columns (nullable for legacy rows)
- [ ] **DRAW-38**: Cascading dropdown UI on cable schedule edit: room → source device → source port → dest device → dest port; client-side filtering by signal_type compatibility
- [ ] **DRAW-39**: Connector-compatibility validation at form submit — HDMI must terminate on HDMI, not RJ45; warning rather than hard block (engineer override allowed with note)
- [ ] **DRAW-40**: Auto-derive port FKs from quote `cable_list` "X to Y" naming where each side has exactly one matching connector (deterministic pass; fallback to nullable when ambiguous)
- [ ] **DRAW-41**: One-shot backfill command for existing cable_schedule_items — populates port FKs where unambiguous, leaves nullable where ambiguous

### Phase 23 — XTEN-AV-Style Renderer

The visual deliverable. Custom device-card stencils with port rails, port-to-port cable routing with signal-type colours and cable IDs, sub-room zones (RACK / CEILING / etc), title block, sheet border. Output renders in the draw.io embed from spike `260509-ibx`.

- [ ] **DRAW-42**: Custom device-card stencil layout — manufacturer logo (top), generic name (centre), model number (bottom), port rails (inputs left, outputs right), connector glyphs per port. Matches XTEN-AV reference visual
- [ ] **DRAW-43**: Port-to-port cable routing — renderer reads cable_schedule_items.source_port_id + dest_port_id and draws the cable from one stencil's exact port to the other's
- [ ] **DRAW-44**: Signal-type colour coding — `audio` purple, `video` purple, `control` blue, `network` blue, `USB` yellow/orange, `speaker/SPOUT` green. Configurable in `config/drawings.php`
- [ ] **DRAW-45**: Cable ID labels rendered along the cable midpoint (e.g. `LAN-1004`, `USB-1000`, `SPOUT-1000` matching cable_schedule numbering)
- [ ] **DRAW-46**: Sub-room zones — dashed-bordered groups within a room (RACK / CEILING / RECEPTION / etc). Auto-derived from device category as default; engineer can override per device
- [ ] **DRAW-47**: Multi-page paginator — system overview (sheet 1) + audio subsystem (sheet 2) + video subsystem (sheet 3) + control subsystem (sheet 4) when scope warrants
- [ ] **DRAW-48**: Standardised title block — project / client / designed-by / drawn-by / checked-by / sheet number / date / revision. Renders on every page
- [ ] **DRAW-49**: Dashed sheet border around every page

### Phase 24 — Stencil Curation UI

Engineer/PM-facing UI to upgrade auto-generic stencils to proper ones. Drag handles for ports, label inputs, manufacturer-logo upload, save back to `device_stencils.mxgraph_xml`. Once curated, every project using that part_number gets the upgraded version automatically.

- [ ] **DRAW-50**: Admin route `/admin/device-stencils` — list view with filter by source (auto-generated / curated / ai-extracted) and search by part_number
- [ ] **DRAW-51**: Stencil edit screen — open the auto-generic placeholder in an editor, drag connectors onto the rails, label them, save
- [ ] **DRAW-52**: Manufacturer logo upload (PNG/SVG) per stencil — stored alongside the stencil's `mxgraph_xml`
- [ ] **DRAW-53**: "Promote to curated" action flips `source` enum from `auto-generated` → `engineer-curated`. Cross-project propagation is automatic via the cache lookup

### Phase 25 — AI Assist (datasheet extraction + chat-edit)

Optional polish layer. AI helps with the long tail of devices the seed pack and curation didn't reach — drops a datasheet PDF, AI extracts ports, engineer reviews and confirms. Chat-edit operations on rendered drawings (move device to zone, relabel, add cable). All AI operations bounded by canonical project data — no inventing equipment / cables / rooms.

- [ ] **DRAW-54**: `DevicePortExtractorService` — Claude vision over manufacturer datasheet PDFs, returns structured port JSON, engineer reviews + approves before persist (stays inside "AI never invents" rule because verified)
- [ ] **DRAW-55**: AI chat-edit operations on a drawing — `move_device_to_zone`, `add_cable_between_ports`, `change_signal_type`, `relabel_device`. Operations bounded by canonical-data validity (can't add a port that doesn't exist on the device)
- [ ] **DRAW-56**: Engineer reviews AI suggestions before they apply — rejection preserves original; acceptance mutates the canvas
- [ ] **DRAW-57**: Bound PDF (from v1.3 Phase 20) replaces D2-based schematic output with the new XTEN-AV-style renderer output for projects whose devices are 80%+ catalogued
- [ ] **DRAW-58**: O&M Manual auto-embed (from v1.3 Phase 17 P03) replaces D2-based PNG with the new renderer's PNG when available

---

## Visual contract

Every PR in this milestone is evaluated against the **XTEN-AV PAGING SYSTEM reference** the user shared 2026-05-09. The reference shows:

- Custom device cards with red border, manufacturer logo top, name + model bottom, port rails on left/right
- Port labels (USB A1, RJ45, PHX, LAN POE+1, etc.) with connector type indicators on the outside edge
- Sub-room zones (RACK, CEILING, PAGING STATION, RECEPTION) as dashed-border groups
- Signal-type-coloured cables (LAN purple/blue, SPOUT green, USB yellow)
- Cable IDs labelled mid-line (LAN-1004, USB-1000, SPOUT-1000)
- Title block at the bottom with project / client / designed-by / drawn-by / checked-by columns
- Dashed sheet border

That's the bar. Phase 23 ships against this contract.

---

## Out of scope for v2.0 (deferred to v2.1+)

- **DWG export** — LibreDWG is GPLv3 (license blocker), Teigha is paid. Defer.
- **Real-time multi-user collaborative editing** — significant infrastructure investment, low immediate ROI.
- **Apple Pencil pressure / tilt** — drawings stay desktop/tablet authoring, not iPad-pencil-native.
- **Mobile-first drawing creation** — engineers create drawings at the desk, not on-site.
- **Custom symbol library editor in-app** — symbols stay in-codebase via the device_stencils table; full library editor is overkill.
- **Floor plans** (DRAW-14..20 from v1.3 backlog) — held for v2.1. Same renderer should work, just needs floor-plan templates + room-shape stencils.

---

## Dependencies (for milestone planning)

- v1.3 shipped (drawings foundation + bound PDF + O&M auto-embed) ✓
- draw.io spike validated (`260509-ibx`) ✓
- 5 hand-coded MTR stencils from spike are seed for Phase 21 catalog ✓
- v1.3 D2-based schematic generator stays running alongside the new renderer; the new renderer takes over for projects with sufficient catalog coverage (DRAW-57)

## Success criteria (milestone-level)

1. A real client project's drawing output, rendered in v2.0, is visually indistinguishable from the XTEN-AV PAGING SYSTEM reference at the device-card and cable-routing level
2. Top-50 device coverage hand-curated by end of Phase 21 — sufficient for 80%+ of recent quote volume to render with full port detail
3. Engineer can drop a new datasheet PDF and AI extracts ports for review (Phase 25 deliverable) — covers the long tail without manual catalog growth
4. Bound PDF + O&M Manual handover swap from D2 output to engineering-grade output when project devices are catalogued
5. v1.3 D2 generator stays usable as a fallback for projects without sufficient catalog coverage — no regression
