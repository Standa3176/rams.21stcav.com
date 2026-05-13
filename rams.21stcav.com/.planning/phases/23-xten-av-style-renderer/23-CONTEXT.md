# Phase 23: XTEN-AV-Style Renderer — Context

**Gathered:** 2026-05-13
**Status:** Ready for planning
**Milestone:** v2.0 Engineering-Grade AV Drawings (Phase 3 of 5)

<domain>
## Phase Boundary

Take Phase 21's device port catalog (`device_stencils` + `device_ports`) and Phase 22's port-level cable FKs (`cable_schedule_items.source_port_id` / `dest_port_id`) and render them as engineering-grade output inside the draw.io / mxGraph embed validated by spike `260509-ibx`. Output matches the XTEN-AV PAGING SYSTEM visual contract shared 2026-05-09.

Phase 23 ships:
1. **Custom device-card stencil layout** (DRAW-42) — manufacturer logo top, generic name centre, model bottom, port rails inputs-left / outputs-right, connector glyphs per port. Renders curated stencils with full port detail AND Tier 1 auto-generic placeholders side-by-side.
2. **Port-to-port cable routing** (DRAW-43) — renderer reads `cable_schedule_items.source_port_id` + `dest_port_id` and draws each cable from one stencil's exact port to the other's. NULL-FK rows fall back per D-07.
3. **Signal-type colour coding** (DRAW-44) — reads `config/cables.php` `signal_type_colours` (locked by Phase 22 — single source of truth shared with the picker validator).
4. **Cable ID labels** (DRAW-45) — `cable_schedule_items.cable_id` rendered at cable midpoint.
5. **Sub-room zones** (DRAW-46) — dashed-bordered groups within a room (RACK / CEILING / RECEPTION / etc). Auto-derived from a category-map; engineer can override per device-instance via a new zone dropdown on the quote-review equipment table.
6. **Multi-page paginator** (DRAW-47) — system overview always; audio/video/control/network sub-sheets emit on threshold per D-06.
7. **Standardised title block** (DRAW-48) — project / client / designed-by / drawn-by / checked-by / sheet # / date / revision. Source-of-truth resolution per D-08.
8. **Dashed sheet border** (DRAW-49) — uniform on every page.

**NOT in scope:**
- Stencil curation UI to drag/edit ports on the placeholders — Phase 24 (DRAW-50..53)
- AI port extraction from datasheets — Phase 25 (DRAW-54)
- Chat-edit operations on rendered drawings (`move_device_to_zone` etc.) — Phase 25 (DRAW-55)
- Swap of v1.3 D2 schematic output in the bound PDF + O&M Manual — Phase 25 (DRAW-57 / DRAW-58)
- Title-block edit UI for designed-by / drawn-by / checked-by overrides — Phase 24 / 23.1 (Phase 23 auto-fills from auth context + defaults; manual override is tinker-only in Phase 23)
- Floor plans — v2.1 backlog (DRAW-14..20)
- Bundle-parallel-cable router + aggressive label collision avoidance — v2.1 polish (D-09 rationale)

</domain>

<decisions>
## Implementation Decisions

All decisions are locked. Planner and researcher must NOT revisit. Decision IDs referenced in tasks via "per D-XX" so traceability is explicit.

### Carry-forward (locked in prior phases, applies to Phase 23)

- **Platform = draw.io / mxGraph self-hosted** (v2.0 milestone decision; spike `260509-ibx` validated 2026-05-09). No Konva, no SVG-direct output.
- **Generic naming, no `rams_` prefix** (Phase 21 D-09 — SCC merge readiness). Any new tables/columns Phase 23 adds use generic names.
- **v1.3 D2 generator stays untouched** (Phase 21 D-10). `SchematicGeneratorService`, `SchematicD2SourceBuilder`, `DrawingDataResolverService`, `CableScheduleGeneratorService`, and the bound-PDF cable-list section continue rendering existing data without behaviour change. Phase 23 is strictly additive.
- **NULL-FK rows render via v1.3 surfaces** (Phase 22 D-10 invariant). XLSX export + schematic SVG + bound-PDF cable list keep rendering legacy rows; Phase 23's renderer handles them per D-07.
- **Cable signal-type colour map = `config/cables.php` `signal_type_colours`** (Phase 22 locked). Single source of truth shared with the picker validator. Current values: `audio` #C0392B · `video` #2980B9 · `control` #27AE60 · `network` #8E44AD · `usb` #E67E22 · `speaker` #16A085 · `power` #7F8C8D · `unknown` #000000.
- **Tier 1 + Tier 2 stencils both render** (Phase 21 D-04). Renderer must handle auto-generic placeholders (header bar + manufacturer + model + part_number, no ports) AND engineer-curated stencils (full port rails + connector glyphs). No "require curated stencils" gate.
- **Spike admin route stays live** (Phase 21 D-08). `/admin/drawings/draw-io-spike/{project}` URL + controller + Blade view path preserved. Phase 23 evolves the builder BEHIND it (D-05).
- **ClickShare slug priority** (Phase 21 D-14). Manufacturer logo resolver matches `clickshare` substring before `barco`.
- **AI is NEVER used for inventing scope, equipment, or design** (CLAUDE.md constraint). Renderer is deterministic. Same project data in → same mxGraph XML out.
- **Local-edit-then-upload deployment** (`feedback_local_then_upload.md`). Each plan's SUMMARY.md ends with a 🚨 Files to upload to live section. RAMS = manual upload list (no working webhook).
- **PHP lint before commit** (`feedback_php_lint_before_push.md`). `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l <file>` on every touched PHP file.
- **Phase 22 canonical port label format** (Phase 22 D-04 specifics). `"{Manufacturer} {Model} ({Port label})"` already lives in `from_location` / `to_location` text columns where the picker was used. Phase 23 renderer doesn't need to rebuild this — it has the FKs directly via the picker; the text columns are a fallback for legacy/freeform rows.

### Sub-Room Zones (DRAW-46)

- **D-01 — Default zone derived from category via `config/drawings.php` map.** Static lookup table: device category → zone name. Mirrors the `config/cables.php` Phase-22-locked pattern (engineer tunes by editing config; no code change required). Seed mapping (planner refines from quote-volume sample):
  ```
  rack-mount-switch | network-switch | poe-switch   → RACK
  amplifier | dsp | matrix | processor              → RACK
  ceiling-mic | ceiling-speaker | ceiling-camera    → CEILING
  display | screen | projector                      → WALL
  touchpanel | desk-mic | tabletop-codec            → TABLE
  paging-station | call-station                     → PAGING_STATION
  intercom | door-station                           → RECEPTION
  ups | distribution-strip                          → FLOOR
  (uncategorised / fallback)                        → OTHER
  ```
  Renderer resolution: `$zone = $line['zone'] ?? $config['category_to_zone'][$line['category']] ?? 'OTHER'`.

- **D-02 — Per-device-instance zone override lives on the `equipment_list` line.** Add a `zone` key to the per-equipment JSON in `latestPackage->extracted_data['equipment']` (and the `equipment_list` legacy fallback shape if used). Zero migrations. Lives WITH the device-instance data `Project::devicesWithStencils()` already reads. Rejected the `project_device_zones` pivot table option — fragile equipment-line identity ("which line index? part_number? both?") makes re-imports brittle.

- **D-03 — Override UI ships IN Phase 23.** Add a `zone` dropdown column to the existing quote-review equipment table on `project-packages/review.blade.php`. Engineers can set the zone per equipment line during review. Save path uses the existing review form POST — picks up the new `equipment[N][zone]` field. Form-side validation: nullable string, must be in the zone vocab OR free-text per D-04.

- **D-04 — Zone vocabulary = config enum + "Other (free text)" escape hatch.** Hard-coded list in `config/drawings.php`:
  ```
  RACK, CEILING, WALL, TABLE, RECEPTION, FLOOR, PAGING_STATION, EXTERNAL, OTHER
  ```
  Dropdown renders the config enum + an "Other..." option that opens a free-text input below. Strict-by-default with an escape hatch. Engineer adds a permanent zone by editing config (single-line append) — no migration. Free-text overrides write the raw string to `equipment[N][zone]`; renderer creates a dashed group per unique string on the project.

### Activation Surface

- **D-05 — Evolve the existing spike route in place. NO new admin routes.** Phase 23 upgrades the renderer behind `/admin/drawings/draw-io-spike/{project}` (Phase 21 D-08 preserved this surface). Same URL, same `App\Http\Controllers\Admin\DrawIoSpikeController` class name, same `resources/views/admin/drawings/draw-io-spike.blade.php` Blade view path. The `DrawIoBuilderService` (Phase 21 rewired) gets the Phase 23 upgrade — its `build(Project)` contract stays, but internal methods (layout, zone derivation, sheet allocation, title block, sheet border) are added or replaced. The TODO(phase-23) marker at `app/Services/Drawings/DrawIoBuilderService.php` line ~32 gets resolved in this phase.
  - **Internal renaming permitted.** New supporting classes (e.g. `XtenAvLayoutEngine`, `SheetPaginator`, `TitleBlockRenderer`, `ZoneGrouper`, `CableRouter`) live alongside `DrawIoBuilderService` in `app/Services/Drawings/` — the controller calls only `DrawIoBuilderService::build()`. Internal name churn is fine; the public route + service method signature is the contract.
  - **Spike Blade can grow optional UI controls** (force-sheet toggles, zone-override quick edits) without breaking — additive only. Existing draw.io iframe + postMessage handlers stay.

### Claude's Discretion (planner defaults — reversible if pushback)

These were NOT discussed but need a default so the planner can act. Planner adopts these unless research surfaces a reason to change. Engineers can push back before plan execution.

- **D-06 — Paginator policy (DRAW-47): threshold-based with engineer toggle deferred.** Renderer always emits the system overview sheet (sheet 1). Sub-sheets (audio / video / control / network) emit only when BOTH:
  - ≥5 cables of that signal type on the project, AND
  - ≥3 devices touching that signal type on the project
  Engineer override (force a sub-sheet on/off) deferred to Phase 24 — for Phase 23 the override is tinker-only via `Project.metadata.force_sheets = ['audio', ...]`. Rationale: most small Teams Rooms shouldn't paginate; large boardroom / divisible / classroom projects need the breakdown.

- **D-07 — NULL-FK fallback: render to device-card edge with warning glyph.** When `source_port_id` is NULL but `source_device_id` is set, draw the cable from the device card's outside edge (heuristic: source-side = right edge if `category` is "source-like" e.g. `videobar`, `byod`, `mic`; else left edge). Same logic for dest. Add a small ⚠ glyph at the cable-card junction. Skip the cable entirely if BOTH device IDs are NULL (pure-text legacy row — already handled by v1.3 surface per Phase 22 D-10). Rationale: skipping the cable hides data; warning glyph signals "this row needs port disambiguation" without breaking the visual.

- **D-08 — Title block source of truth (DRAW-48): mixed defaults + override stub.**
  - `project` → `Project.name`
  - `client` → `Project.client_name` (or related ClientModel name)
  - `sheet #` → from the sheet allocator (e.g. "AV-201" for sheet 1, "AV-202" for audio sub-sheet, mirrors v1.3 Phase 20 AV-201..299 schematic sheet numbering)
  - `date` → `now()->format('Y-m-d')` at render time
  - `revision` → `Drawing.version` from the existing lock-on-edit + archive-prior pattern (Phase 21 D-08 / spike Task 5)
  - `designed-by` → currently signed-in admin user's name (`Auth::user()->name`) at render time
  - `drawn-by` → same as `designed-by` by default
  - `checked-by` → free-text field in `Project.metadata.drawing_checked_by` (if column missing, planner adds a JSON-cast `metadata` column to `projects` — generic name per D-09 carry-forward; if `Project.metadata` already exists, append). Defaults to "—".
  - Title-block edit UI deferred to Phase 24 / 23.1. Phase 23 reads from the above sources only.

- **D-09 — Cable routing + labels (DRAW-43/45): draw.io default orthogonal routing.** No custom router in Phase 23. Cable ID labels (read from `cable_schedule_items.cable_id`) placed at draw.io's default midpoint with built-in anti-overlap. Bundle-parallel-cables and aggressive collision avoidance deferred to v2.1. Rationale: typical 21CAV project has <30 cables per sheet, default routing is acceptable. If visual review post-Phase-23 reveals collision problems on larger projects, raise a v2.1 polish ticket.

### Open Issue (planner must verify against visual contract)

- **D-10 — Signal-type colour discrepancy with REQUIREMENTS.md narrative.** `config/cables.php` `signal_type_colours` (Phase 22 locked) defines: audio=#C0392B (red), video=#2980B9 (blue), control=#27AE60 (green), network=#8E44AD (purple), usb=#E67E22 (orange), speaker=#16A085 (teal). REQUIREMENTS.md DRAW-44 narrative reads: "audio purple, video purple, control blue, network blue, USB yellow/orange, speaker/SPOUT green". Two different mappings. Phase 22 locked the config, so Phase 23 reads config/cables.php. Planner MUST verify the chosen mapping against the XTEN-AV PAGING SYSTEM reference image side-by-side and flag any visual mismatch BEFORE shipping. If the reference matches the narrative (purple LAN, green SPOUT), the planner raises a separate config-update ticket and re-aligns `config/cables.php` — do NOT silently change the colours during Phase 23.

### Folded Todos

No pending todos matched Phase 23 scope (`gsd-tools todo match-phase 23` returned 0).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase 23 spec
- `.planning/REQUIREMENTS.md` §"Phase 23 — XTEN-AV-Style Renderer" (lines 45–56) — DRAW-42..49 acceptance criteria (binding contract)
- `.planning/REQUIREMENTS.md` §"Visual contract" (lines 79–91) — what every PR is evaluated against
- `.planning/ROADMAP.md` §"Phase 23: XTEN-AV-Style Renderer" (line 46) — goal, depends on Phase 21+22, estimate 2–4 weeks via draw.io

### Visual contract (the bar)
- **XTEN-AV PAGING SYSTEM reference image** (conversation 2026-05-09) — red-bordered device cards, manufacturer logo top, name + model bottom, port rails left/right with port-type indicators on outside edge, sub-room zones as dashed groups (RACK / CEILING / PAGING STATION / RECEPTION), signal-type-coloured cables (LAN purple/blue, SPOUT green, USB yellow), cable IDs labelled mid-line (LAN-1004, USB-1000, SPOUT-1000), title block bottom, dashed sheet border.

### Phase 21 carry-forward
- `.planning/phases/21-device-port-catalog-stencil-cache/21-CONTEXT.md` — D-04 (Tier 1 auto-generic shape, no port rails), D-08 (preserve spike admin route + controller signature), D-09 (generic naming), D-10 (v1.3 D2 generator untouched), D-14 (clickshare-before-barco resolver order)
- `.planning/phases/21-device-port-catalog-stencil-cache/21-01-schema-models-cache-service-SUMMARY.md` — `DeviceStencil` + `DevicePort` model API, `SIDE_*` / `DIRECTION_*` enum constants, mxgraph_xml column shape
- `.planning/phases/21-device-port-catalog-stencil-cache/21-03-manufacturer-logos-builder-integration-SUMMARY.md` — `DrawIoBuilderService` current contract + the TODO(phase-23) marker Phase 23 resolves

### Phase 22 carry-forward
- `.planning/phases/22-cable-schedule-with-port-level-fks/22-CONTEXT.md` — D-04 (picker overwrites from_location/to_location text with canonical labels; renderer prefers FK over text), D-10 (don't break v1.3 surfaces — invariant Phase 23 inherits), signal-type colour-map locking note
- `.planning/phases/22-cable-schedule-with-port-level-fks/22-01-SUMMARY.md` — `cable_schedule_items` schema after Phase 22 (4 FK columns + `connector_override_note`)
- `.planning/phases/22-cable-schedule-with-port-level-fks/22-03-SUMMARY.md` — `CablePortFkResolverService` semantics, what "ambiguous" means for NULL-FK rows (D-07 fallback)

### Spike platform validation
- `.planning/quick/260509-ibx-draw-io-embed-spike-sandbox-one-stencil-/260509-ibx-SUMMARY.md` — draw.io v29.7.12 self-hosted at `public/vendor/drawio/`, D-LOCK audit, postMessage round-trip protocol, lock-on-edit + archive-prior on `drawings.canvas_state`
- `public/vendor/drawio/VERSION.md` — pinned version + license + manual-update procedure

### Code to read before planning
- `app/Services/Drawings/DrawIoBuilderService.php` — current builder (Phase 21 rewired), TODO(phase-23) marker at ~line 32, STENCIL_ROLES shallow heuristic Phase 23 replaces
- `app/Services/Drawings/DrawIoSpikeBuilderService.php` — backwards-compat shim (kept; Phase 23 reads behind the new builder)
- `app/Services/Drawings/DeviceStencilCacheService.php` — `firstOrCreate(part_number)` cross-project cache; Phase 23 renderer triggers cache misses on uncatalogued items (Tier 1 auto-create)
- `app/Services/Drawings/ManufacturerLogoResolver.php` — logo lookup the device-card stencil renderer consumes (D-14 clickshare-before-barco order)
- `app/Services/Drawings/DrawingService.php` — `saveSpikeXml` / `saveSpikeSvg` lock-on-edit + archive-prior; Phase 23 reuses
- `app/Http/Controllers/Admin/DrawIoSpikeController.php` — controller behind the spike route (D-05 preservation)
- `resources/views/admin/drawings/draw-io-spike.blade.php` — Blade view; Phase 23 can grow additive UI controls
- `app/Models/CableScheduleItem.php` — Phase 22 model with 4 port-level FK columns + cable_id (used by DRAW-45)
- `app/Models/Project.php` — `devicesWithStencils()` accessor Phase 21 D-07 (Phase 23's primary data source)
- `app/Models/DevicePort.php` — Phase 21 port model; `SIDE_*` constants for port-rail placement
- `app/Models/DeviceStencil.php` — Phase 21 stencil model; `mxgraph_xml`, `source` enum, `metadata` JSON
- `config/cables.php` — `signal_type_colours` (D-10 single source of truth) + `compatibility_aliases` (Phase 22)
- `resources/views/project-packages/review.blade.php` — quote-review equipment table where the D-03 zone dropdown column lands

### v1.3 surface that must not regress (Phase 21 D-10 carry-forward)
- `app/Services/Drawings/SchematicGeneratorService.php`
- `app/Services/Drawings/SchematicD2SourceBuilder.php`
- `app/Services/Drawings/DrawingDataResolverService.php`
- `app/Services/Drawings/BoundPdfBuilderService.php`
- `app/Services/Drawings/DrawingExportRendererService.php`

### Deployment + ops
- Memory `feedback_local_then_upload.md` — RAMS deploy = manual upload list; SCC deploy = git push + remote git pull
- Memory `feedback_php_lint_before_push.md` — `php -l` every touched PHP file before commit
- Memory `rams_scc_merge.md` — generic naming so tables port to SCC without rename (Phase 21 D-09 carry-forward applies to any new Phase-23 tables/columns)

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **`DrawIoBuilderService::build(Project)`** — Phase 21 rewired contract Phase 23 evolves behind. Returns mxGraphModel XML string. Determinism contract preserved (same project → same XML).
- **`Project::devicesWithStencils()`** — Phase 21 D-07 accessor. Returns `array<int, array{part_number, manufacturer, model, name, quantity, area, stencil: ?DeviceStencil}>`. Primary data source for the renderer. Side-effect: Tier 1 auto-create on cache miss (intended).
- **`DeviceStencilCacheService::resolveForPartNumber()`** — `firstOrCreate(part_number)` cache. Cross-project propagation automatic. Phase 23 never bypasses this.
- **`ManufacturerLogoResolver`** — Logo SVG path lookup. D-14 clickshare-before-barco substring order. Renderer consumes for device-card header.
- **`DrawingService::saveSpikeXml` / `saveSpikeSvg`** — lock-on-edit + archive-prior on `drawings.canvas_state` (mediumtext column reused, no migration per spike D-LOCK-8). Phase 23 reuses for engineer-saved overrides.
- **`CableScheduleItem` Phase 22 FK columns** — `source_device_id`, `source_port_id`, `dest_device_id`, `dest_port_id`, `cable_id`, `connector_override_note`. Renderer reads via `with(['sourcePort', 'destPort', 'sourceDevice', 'destDevice'])` eager-load AT THE CALL SITE only (Phase 22 D-10 guard — never class-level `$with`).
- **`config/cables.php` `signal_type_colours`** — colour map for DRAW-44 (D-10 single source of truth).
- **draw.io self-hosted embed at `public/vendor/drawio/`** — 132 MB, 2899 files, v29.7.12 Apache 2.0. PostMessage protocol bridge (init→load, save, autosave, export xmlsvg) lives in `draw-io-spike.blade.php`.
- **mxGraph port `<constraint>` elements** — stencil XML in the 5 spike stencils + Phase 21 seed-pack curated stencils declares port constraints. Renderer terminates cables at named constraints when port FKs present.

### Established Patterns
- **Deterministic builder** (D-LOCK-5/6 from spike). Same project data in → same mxGraph XML out. NO AI. NO Eloquent writes inside the builder (cache-miss writes happen inside `Project::devicesWithStencils()`, not the builder).
- **Config-driven mappings** — `config/cables.php` Phase-22 locked the pattern. Phase 23 adds `config/drawings.php` (or extends it if it already exists) for the category→zone map (D-01) and the zone enum vocab (D-04). Engineering tunes by editing config.
- **Lock-on-edit + archive-prior** (spike Task 5; Phase 18 Plan 03 prior art). Versioned drawings reuse `archivePrior()` inside `DB::transaction`. First save = same row + lock flip; subsequent save = new version + prior `STATUS_SUPERSEDED`.
- **Sheet number allocator** — v1.3 Phase 20 uses AV-201..299 for schematics, AV-301..399 for racks. Phase 23 follows the same scheme; multi-page paginator allocates AV-201 for system overview, AV-202+ for sub-sheets.
- **PHP lint before commit** — `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l <file>` on every touched PHP file.
- **Local-edit-then-upload** — each plan's SUMMARY.md ends with a 🚨 Files to upload to live section.

### Integration Points
- **`DrawIoSpikeController@show`** — already invokes `DrawIoBuilderService::build()`. Phase 23 changes the builder's internal behaviour only; controller untouched.
- **`project-packages/review.blade.php` equipment table** — D-03 zone dropdown column lands here. Save path uses the existing review form POST (controller picks up `equipment[N][zone]`).
- **`CableScheduleController@edit` eager-load site** — Phase 22 added `$schedule->load('items.sourcePort.deviceStencil')` etc. Phase 23 renderer call site needs analogous eager-load to avoid N+1.
- **`DrawingService::archivePrior`** — versioning hook the renderer's save path uses if engineers edit + persist the rendered drawing back through the draw.io embed (existing spike round-trip).
- **`config/drawings.php`** — if it doesn't exist yet, Phase 23 creates it. Phase 21's renderer uses inline constants currently; Phase 23 externalises the category→zone map + zone vocab there.
- **`Project::metadata`** — JSON-cast column (if exists; if not, planner adds a generic `metadata` column via lightweight migration with `nullable()->default(null)`). Used by D-08 for `drawing_checked_by` override + D-06 for `force_sheets` array tinker override.

</code_context>

<specifics>
## Specific Ideas

- **Phase 23 admin URL stays:** `/admin/drawings/draw-io-spike/{project}` — engineers know this URL. Internal naming around the renderer can update; the route name `admin.drawings.draw-io-spike.show` is the public contract per Phase 21 D-08.
- **Zone enum names use uppercase snake_case** in config + JSON (RACK, CEILING, PAGING_STATION). Dashed-group labels on the drawing can render these as Title Case or Display Case ("Paging Station") — that's a renderer concern, not a data concern. Single canonical form in config.
- **Free-text zone overrides write the raw string** (not normalised) — engineer typing "Equipment Rack" creates a separate group from "RACK". Documenting the consistency-vs-flexibility tradeoff in the dropdown helper text mitigates this.
- **Default category→zone map seed values** in D-01 are PROVISIONAL — planner refines against a sample of the last 50 quotes' equipment categories. The map's values come from quote data, not assumption.
- **Sheet numbering format:** AV-201 for system overview, AV-202 for audio sub-sheet, AV-203 for video, AV-204 for control, AV-205 for network — extends v1.3 Phase 20 AV-201..299 schematic range. Format `AV-2{NN}` where NN = sheet ordinal.
- **Warning glyph for NULL-FK fallback (D-07)** uses a small ⚠ at 12pt at the cable-card junction. Yellow `#E67E22` fill (matching the `usb` signal-type colour by coincidence — picked for high-contrast visibility, not signal semantics).

</specifics>

<deferred>
## Deferred Ideas

- **Stencil curation UI** (drag-port handles, manufacturer logo upload per stencil, promote auto-generic → engineer-curated) — Phase 24 / DRAW-50..53.
- **Title-block edit UI** (engineer overrides designed-by / drawn-by / checked-by per project, revision history surface) — Phase 24 or decimal 23.1. Phase 23 reads from auth context + `Project.metadata` only.
- **Force-sheet toggle UI** (engineer flips audio/video/control sub-sheet on/off without hitting the threshold) — Phase 24. Phase 23 = tinker-only via `Project.metadata.force_sheets`.
- **AI port extraction from datasheets** — Phase 25 / DRAW-54.
- **Chat-edit operations on rendered drawings** (`move_device_to_zone`, `add_cable_between_ports`, `change_signal_type`, `relabel_device`) — Phase 25 / DRAW-55..56.
- **Bound PDF + O&M Manual auto-embed swap** from v1.3 D2 → Phase 23 output — Phase 25 / DRAW-57..58.
- **Bundle-parallel-cable router + aggressive label collision avoidance** — v2.1 polish per D-09. Default draw.io orthogonal routing for now.
- **Floor plans** (DRAW-14..20 from v1.3 backlog) — v2.1.
- **DWG export** — v2.1+ (LibreDWG GPLv3 license blocker; Teigha is paid).
- **Real-time multi-user collaborative drawing** — v2.1+ (significant infra investment, low immediate ROI).
- **Apple Pencil pressure / tilt + mobile-first drawing creation** — v2.1+ (drawings stay desktop/tablet authoring).
- **Custom symbol library editor in-app** — v2.1+ (symbols stay in `device_stencils` table).
- **Re-align REQUIREMENTS.md DRAW-44 narrative to match `config/cables.php` (or vice-versa)** — separate ticket per D-10. Planner raises this BEFORE shipping if the visual contract reference image disagrees with the current config colours.

### Reviewed Todos (not folded)
No todos surfaced for review.

</deferred>

---

*Phase: 23-xten-av-style-renderer*
*Context gathered: 2026-05-13*
