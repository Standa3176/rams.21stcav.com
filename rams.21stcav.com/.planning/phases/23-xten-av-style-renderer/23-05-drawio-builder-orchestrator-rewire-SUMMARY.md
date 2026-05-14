---
phase: 23
plan: 05
subsystem: drawings/renderer
tags: [orchestrator, mxfile, integration, deterministic, v2.0, xten-av]
dependency_graph:
  requires:
    - Phase 23 Plan 02 (ZoneGrouper + XtenAvLayoutEngine)
    - Phase 23 Plan 03 (CableRouter — port-to-port edges + ⚠ glyphs)
    - Phase 23 Plan 04 (SheetPaginator + TitleBlockRenderer + SheetBorderRenderer)
    - Phase 21 Plan 03 (DrawIoBuilderService Phase 21 contract — public signature preserved)
    - Phase 21 Plan 01 (Project::devicesWithStencils() accessor)
    - Phase 4 (Project.devices relation — Device.part_no FK used for cable wire-up)
  provides:
    - DrawIoBuilderService::build(Project): string — `<mxfile>` multi-sheet output for non-empty projects, legacy `<mxGraphModel>` for empty
    - Spike admin route /admin/drawings/draw-io-spike/{project} now serves the XTEN-AV-style renderer behind unchanged URL + controller
  affects:
    - Plan 23-06 (zone-dropdown review UX) — engineers see the rewired output when verifying zone overrides
    - Plan 23-07 (final verification + browser UAT) — validates the multi-sheet `<diagram>` tabs in the draw.io v29.7.12 embed
tech_stack:
  added: []
  patterns:
    - "Orchestrator-composes-helpers pattern (6 pure-read helpers injected, no behavioural logic in orchestrator)"
    - "Public-contract preservation via internal renaming (D-05) — build(Project): string unchanged"
    - "Empty-project backwards-compat branch (Phase 21 P03 test_empty_package_emits_valid_empty_graph green)"
    - "Sheet-filter rule (system_overview = all; sub-sheets = filter edges by signal then filter devices to union touched + drop empty zones)"
    - "Call-site Eloquent loadMissing — never class-level $with (Phase 22 D-10 carry-forward)"
    - "Generic naming D-09 carry-forward — class is DrawIoBuilderService, no Rams prefix"
key_files:
  created:
    - tests/Feature/Drawings/DrawIoBuilderServiceMultiSheetTest.php
    - .planning/phases/23-xten-av-style-renderer/23-05-drawio-builder-orchestrator-rewire-SUMMARY.md
  modified:
    - app/Services/Drawings/DrawIoBuilderService.php
    - tests/Feature/Drawings/DrawIoBuilderServiceTest.php
decisions:
  - "D-05 implemented — DrawIoBuilderService::build(Project): string signature unchanged; constructor extended with 6 helpers (Laravel auto-resolves); DrawIoSpikeBuilderService shim still delegates; DrawIoSpikeController constructor untouched; spike admin route URL + name unchanged"
  - "D-06 implemented — SheetPaginator::classify called from build(); force_sheets metadata override propagates through the orchestrator"
  - "D-07 implemented — CableRouter emits ⚠ warn glyphs which the orchestrator carries through both system_overview and sub-sheet filters"
  - "D-08 implemented — orchestrator resolves the latest non-superseded schematic drawing for the title block's revision field via Project::drawings()->where('kind', KIND_SCHEMATIC)->where('status', '!=', STATUS_SUPERSEDED)->latest('updated_at')"
  - "D-09 verified — generic naming preserved (no Rams prefix on DrawIoBuilderService or any helper)"
  - "D-10 verified — config/cables.php diff empty; CableRouter is the only colour-map reader, orchestrator just splices its output"
  - "T-23-05-A1 mitigated — every interpolated user string is already xml-escaped by its helper before reaching the orchestrator; orchestrator's serialiseCell() does NOT re-escape value (avoid double-encoding) but DOES escape style strings as defence-in-depth"
  - "T-23-05-A2 mitigated — Log::warning fires when output XML > 4.5 MB (approaches the 5 MB postMessage cap enforced by DrawIoSpikeController::saveXml)"
  - "T-23-05-A3 mitigated — DrawIoSpikeController constructor reflection assertion (test_d08_spike_controller_constructor_has_two_parameters) stays green; signature unchanged"
  - "TODO(phase-23) marker at line ~32 of the Phase 21 P03 builder — RESOLVED. Marker text + the shallow STENCIL_ROLES / ROLE_COLUMN / deriveCables logic all deleted."
metrics:
  duration: ~40min
  tasks_completed: 1
  files_created: 2
  files_modified: 2
  tests_added: 8
  assertions: 24
  completed: 2026-05-14
requirements: [DRAW-42, DRAW-43, DRAW-44, DRAW-45, DRAW-46, DRAW-47, DRAW-48, DRAW-49]
---

# Phase 23 Plan 05: DrawIoBuilderService Orchestrator Rewire Summary

**One-liner:** Phase 23's integration moment — `DrawIoBuilderService::build(Project): string` now composes ZoneGrouper + XtenAvLayoutEngine + CableRouter + SheetPaginator + TitleBlockRenderer + SheetBorderRenderer into a `<mxfile>` multi-sheet document while preserving Phase 21 P03's public contract, empty-project shape, spike route, controller signature, shim delegation, and all 5 v1.3 surfaces.

## Outcome

The orchestrator rewire activates the six helpers shipped by Plans 02–04 behind the unchanged `/admin/drawings/draw-io-spike/{project}` URL. Engineers hitting that admin route on any project with `equipment_list` data now see the XTEN-AV-style output: dashed zone groups with manufacturer-logo + part-number stencil cards, port-to-port cables coloured by signal type, cable IDs labelled mid-line, a brand-teal dashed page border, an 8-field title block, and a `<mxfile>` envelope holding `<diagram>` children for the system overview + any conditionally-emitted audio/video/control/network sub-sheets.

Phase 21 P03's shallow 4-column-grid + canonical-Teams-Room-cable-chain heuristics are GONE. The TODO(phase-23) marker is resolved.

## Constructor (verbatim — 8 readonly promoted properties)

```php
public function __construct(
    private readonly DeviceStencilCacheService $stencilCache,
    private readonly ManufacturerLogoResolver  $logos,
    private readonly ZoneGrouper               $zones,
    private readonly XtenAvLayoutEngine        $layout,
    private readonly CableRouter               $cables,
    private readonly SheetPaginator            $paginator,
    private readonly TitleBlockRenderer        $titleBlock,
    private readonly SheetBorderRenderer       $sheetBorder,
) {}
```

Laravel auto-resolves all 8 dependencies via the service container — no explicit `AppServiceProvider` bindings needed. `$stencilCache` remains in the contract even though `build()` no longer calls it directly; the cache-miss → Tier 1 auto-create side-effect lives inside `Project::devicesWithStencils()` (Phase 21 D-07).

## build() Control Flow

1. **Guard clauses** — if `$project->latestPackage` is null OR `$project->devicesWithStencils()` returns `[]`, emit the legacy single-`<mxGraphModel>` empty-graph shape (no `<mxfile>` wrapper). Backwards-compat with Phase 21 P03.

2. **`ZoneGrouper::assign($lines)`** — derive zone → device-line map per D-01 category lookup + D-02 per-device override + D-04 free-text escape hatch + OQ-1 Path B name-keyword fallback. Deterministic ordering: vocab zones first, free-text alphabetical after.

3. **`XtenAvLayoutEngine::placeDevices($zoned)`** — flat ordered descriptor list with zone container BEFORE its child devices. Each device descriptor carries `part_number` + `stencil` for downstream helpers.

4. **`enrichDeviceCellsWithDeviceIds($project, $deviceCells)`** — orchestrator-owned helper that splices `device_id` + `category` onto each device descriptor by matching the cell's `part_number` against `Project::devices->part_no` (Phase 4 Device table). CableRouter needs `device_id` to map `cable_schedule_items.(source|dest)_device_id` back to a device cell.

5. **`CableRouter::emitCables($project, $enrichedDeviceCells)`** — port-to-port edge descriptors (DRAW-43/44/45) + ⚠ glyph descriptors for D-07 NULL-FK fallback rows and OQ-4 Path B Tier 1.5 fallback rows.

6. **`SheetPaginator::classify($project)`** — ordered sheet list. `system_overview` always emits at index 0; `audio/video/control/network` sub-sheets emit only when BOTH D-06 thresholds are met OR `Project.metadata.force_sheets` forces them.

7. **Drawing-revision resolution** — `Project::drawings()->where('kind', KIND_SCHEMATIC)->where('status', '!=', STATUS_SUPERSEDED)->latest('updated_at')->first()` resolves the latest non-superseded schematic drawing for the title block's revision field. Null is acceptable (TitleBlockRenderer falls back to `'R0'`).

8. **Per-sheet compose + serialise** — for each sheet in `$sheets`, call `composeSheet()` to produce the ordered cell list, then `emitDiagram()` to wrap it in `<diagram><mxGraphModel>...</mxGraphModel></diagram>`. Concatenate all diagrams inside `emitMxFile()` to produce the final `<mxfile>` document.

9. **DoS warning** — if final XML > 4,500,000 bytes, fire `Log::warning('DrawIoBuilderService: large XML payload approaching 5 MB postMessage cap', […])` so ops gets a heads-up before the 5 MB cap bites on the engineer's next save round-trip.

## composeSheet() Filter Rule

```
IF $sheet['signal_filter'] === null (system_overview):
    cells = [border, ...$deviceCells, ...$cableCells, ...$titleBlockFields]

ELSE (sub-sheet — audio/video/control/network):
    filteredEdges = warn-glyphs + edges where signal == filter
    touchedIds    = union(source, target) for filteredEdges
    survivingDevicesAndZones = devices where id ∈ touchedIds, plus zones (provisional)
    Pass 2: drop zones with no surviving device children
    cells = [border, ...survivingDevicesAndZones, ...filteredEdges, ...$titleBlockFields]
```

Border first (renders behind content). Title block last (mxGraph z-order = document order, so it draws on top).

## `<mxfile>` Wrapper Shape (verbatim)

```xml
<mxfile host="app.diagrams.net" agent="21cav-rams-renderer/v23" version="29.7.12">
  <diagram name="System Overview" id="sheet-system_overview">
    <mxGraphModel dx="1200" dy="800" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="1600" pageHeight="1000" math="0" shadow="0">
      <root>
        <mxCell id="0"/>
        <mxCell id="1" parent="0"/>
        {border + zones + devices + cables + warn-glyphs + title-block fields}
      </root>
    </mxGraphModel>
  </diagram>
  {<diagram> child per sub-sheet…}
</mxfile>
```

## Empty-Project Backwards-Compat Shape (verbatim — preserved from Phase 21 P03)

```xml
<mxGraphModel dx="1200" dy="800" pageWidth="1600" pageHeight="1000"><root><mxCell id="0"/><mxCell id="1" parent="0"/></root></mxGraphModel>
```

No `<mxfile>` wrapper — single-page legacy shape. Returned when `latestPackage === null` OR `devicesWithStencils() === []`. `test_empty_package_emits_valid_empty_graph` from Phase 21 P03 stays green.

## DELETED Phase 21 P03 Surfaces

| Symbol | Lines (pre-rewire) | Replacement |
|--------|--------------------|-------------|
| `STENCIL_ROLES` const | 65–71 | `ZoneGrouper::NAME_KEYWORD_TO_ZONE` (Plan 23-02 OQ-1 Path B) |
| `ROLE_COLUMN` const | 84–91 | `XtenAvLayoutEngine` column-major flow per zone |
| `COLUMN_ANCHORS` const | 93 | dynamic zone x-coordinates from `XtenAvLayoutEngine::ZONE_X_START` + bounds |
| `QUANTITY_CAP` const | 100 | `XtenAvLayoutEngine::MAX_COLS_PER_ZONE` |
| `mapLinesToCells()` method | 152–212 | `XtenAvLayoutEngine::placeDevices()` (Plan 23-02) |
| `deriveCables()` method (Teams Room chain) | 228–269 | `CableRouter::emitCables()` (Plan 23-03) |
| `emitMxGraph()` method (per-line rich-value markup) | 282–385 | per-cell descriptors emitted by each helper + `serialiseCell()` |
| `emptyGraph()` method | 390–400 | `emitEmptyGraph()` (renamed; same shape) |
| TODO(phase-23) marker text | ~32 | resolved — block deleted |

`grep -c "STENCIL_ROLES" app/Services/Drawings/DrawIoBuilderService.php` returns **0**.
`grep -c "ROLE_COLUMN" app/Services/Drawings/DrawIoBuilderService.php` returns **0**.
`grep -c "deriveCables" app/Services/Drawings/DrawIoBuilderService.php` returns **0**.
`grep -c "TODO(phase-23)" app/Services/Drawings/DrawIoBuilderService.php` returns **0**.

## Tests Added + Adjusted

### New: `tests/Feature/Drawings/DrawIoBuilderServiceMultiSheetTest.php` — 8 tests / 24 assertions

| Test | Requirement | What it locks |
|------|-------------|---------------|
| test_empty_project_emits_legacy_single_mxgraphmodel | Phase 21 P03 backwards-compat | empty path emits `<mxGraphModel>`, NOT `<mxfile>` |
| test_small_mtr_fixture_emits_single_sheet_inside_mxfile | DRAW-47 floor + D-06 below-threshold | non-empty project wraps in `<mxfile>`; smallMtr (6 cables) emits only system_overview |
| test_paging_system_fixture_emits_multiple_sheets | D-06 force_sheets escape hatch | `metadata.force_sheets = ['audio','network']` forces 3+ sheets |
| test_legacy_null_fk_fixture_renders_with_warning_glyphs | D-07 | ⚠ glyph present in rendered XML for null-port-id rows |
| test_each_sheet_has_dashed_border_and_title_block | DRAW-48 + DRAW-49 | `dashPattern=8 4` border count >= sheet count; 8 `id="tb-…"` cells per sheet |
| test_signal_colours_match_config_cables | DRAW-44 + D-10 | `strokeColor={config(cables.signal_type_colours.network)}` appears |
| test_determinism_across_calls | D-LOCK-5/6 | same project state → byte-identical XML (Carbon::setTestNow honoured) |
| test_xss_payload_in_project_name_is_escaped | T-23-05-A1 | `<script>alert(1)</script>` in Project.name → `&lt;script&gt;` in output |

### Adjusted: `tests/Feature/Drawings/DrawIoBuilderServiceTest.php` — 6 Phase 21 P03 tests still green

| Test | Adjustment |
|------|------------|
| test_builds_valid_mxgraph_xml_with_two_vertex_cells | Now asserts `<mxfile` start instead of `<mxGraphModel` start (non-empty wraps); device labels checked by content rather than vertex count (zones + border + title block also emit `vertex="1"`); cable filter assertion unchanged |
| test_curated_stencil_mxgraph_xml_is_base64_embedded | Unchanged — base64 stencil embed pattern preserved by XtenAvLayoutEngine |
| test_empty_package_emits_valid_empty_graph | Unchanged — empty path still emits the legacy single `<mxGraphModel>` shape |
| test_build_is_deterministic | Unchanged assertions, but setUp() now calls Carbon::setTestNow('2026-05-14 12:00:00') because TitleBlockRenderer interpolates now()->format('Y-m-d') |
| test_d08_spike_controller_constructor_has_two_parameters | Unchanged — reflection check stays green |
| test_spike_builder_shim_still_exists_and_delegates | Unchanged — shim delegates to identical builder output |

**Total:** 14 tests / 46 assertions GREEN on `php artisan test --filter='DrawIoBuilderService'`. All 153 Drawings feature tests green (Phase 23 Plans 02/03/04 helpers + this orchestrator + Phase 21 P03 + Phase 22 + v1.3).

## Decisions Implemented

- **D-05 (public contract + spike route preservation)** — `build(Project): string` signature unchanged; spike admin route URL + controller class + constructor signature all unchanged. Verified via:
  - `php artisan route:list --name=draw-io-spike` returns the 3 spike routes (show / saveXml / exportSvg).
  - `git diff --stat app/Http/Controllers/Admin/DrawIoSpikeController.php` returns empty.
  - `git diff --stat app/Services/Drawings/DrawIoSpikeBuilderService.php` returns empty.
  - `test_d08_spike_controller_constructor_has_two_parameters` GREEN.
  - `test_spike_builder_shim_still_exists_and_delegates` GREEN.

- **D-06 (multi-sheet paginator)** — `SheetPaginator::classify($project)` is the orchestrator's sole sheet allocator. `test_paging_system_fixture_emits_multiple_sheets` exercises the `force_sheets` metadata override path that bypasses thresholds.

- **D-07 (NULL-FK fallback)** — CableRouter emits ⚠ glyph descriptors, the orchestrator carries them through the sub-sheet filter unconditionally (warn glyphs follow their parent edge), and the warn glyph text is preserved verbatim in the final XML. `test_legacy_null_fk_fixture_renders_with_warning_glyphs` GREEN.

- **D-08 (title block revision)** — Drawing-revision resolved at orchestrator level via `Project::drawings()->where('kind', KIND_SCHEMATIC)->where('status', '!=', STATUS_SUPERSEDED)->latest('updated_at')->first()`. Null result acceptable — TitleBlockRenderer falls back to `'R0'`.

- **D-09 (generic naming)** — Class name `DrawIoBuilderService` carries no `Rams` prefix. All 6 newly-injected helpers (ZoneGrouper, XtenAvLayoutEngine, CableRouter, SheetPaginator, TitleBlockRenderer, SheetBorderRenderer) also generic-named. SCC merge readiness preserved.

## Threat Model Outcomes

| Threat ID | Disposition | Status |
|-----------|-------------|--------|
| T-23-05-A1 (XSS via double-escape or non-escape in serialiseCell) | mitigate | Implemented — serialiseCell() interpolates the helper-pre-escaped `value` attribute VERBATIM (no re-escape), but applies htmlspecialchars to the `style` attribute as defence-in-depth. test_xss_payload_in_project_name_is_escaped GREEN — `<script>alert(1)</script>` in Project.name reaches the output as `&lt;script&gt;alert(1)&lt;/script&gt;`. |
| T-23-05-A2 (DoS / 5 MB postMessage cap) | mitigate | Implemented — `Log::warning('DrawIoBuilderService: large XML payload approaching 5 MB postMessage cap', […])` fires at `strlen($xml) > 4_500_000`. Constant `LARGE_XML_THRESHOLD_BYTES = 4_500_000` documented inline. |
| T-23-05-A3 (DrawIoSpikeController constructor regression) | mitigate | Implemented — reflection assertion test_d08_spike_controller_constructor_has_two_parameters retained and GREEN. Constructor not touched. |
| T-23-05-A4 (Info disclosure via Project.name etc.) | accept | No change from Phase 21 P03 — admin-only surface (D-LOCK-7). |

## Pitfall Verifications

- **Pitfall 4 (XML size + postMessage cap):** `Log::warning` wired with `byte_count` and `sheet_count` context. Tested manually — small MTR (~6 cables) produces ~30 KB; well below threshold. The largest fixture (paging system with force_sheets) is still under 100 KB.

- **Pitfall 8 (XML escaping symmetry):** orchestrator's `serialiseCell()` escapes `style` (defence-in-depth) but NOT `value` (helpers already escaped). Confirmed against:
  - Zone label XSS path (Plan 23-02 mitigation T-23-02-A1)
  - Device name XSS path (Plan 23-02 mitigation T-23-02-A2)
  - Cable ID XSS path (Plan 23-03 mitigation T-23-03-A1)
  - Title block field XSS paths (Plan 23-04 mitigations T-23-04-A1)
  - Project name passes through both the title block (Plan 23-04 path) AND any future hover-text path — covered.

- **Determinism (D-LOCK-5/6):** `test_determinism_across_calls` calls `build()` twice on `$project` and `$project->fresh()`, asserts byte-identical output. GREEN. Carbon::setTestNow freezes the title-block date field.

## Deviations from Plan

### 1. [Rule 3 — Blocking] enrichDeviceCellsWithDeviceIds() helper added inside the orchestrator

- **Found during:** Task 1 GREEN initial integration — the plan's pseudo-code skipped the step of bridging `XtenAvLayoutEngine`'s device descriptors (which carry `part_number` + `stencil` but NO `device_id`) to `CableRouter`'s expected input shape (which keys off `device_id` from the Phase 4 `Device` table).
- **Issue:** Without this bridge, `CableRouter::emitCables()` builds an empty `byDeviceId` map and skips every cable (logs `CableRouter: skipping cable, device not on sheet` for each row). Result: no cables ever render in the multi-sheet output — silent failure that breaks DRAW-43/44/45 visually.
- **Fix:** Added `enrichDeviceCellsWithDeviceIds(Project $project, array $deviceCells): array` — a pure private helper that calls `loadMissing('devices')` once, builds a `part_number → Device.id` map (case-insensitive), and splices `device_id` + `category` onto each device descriptor. Non-device descriptors (zones) flow through unchanged. The orchestrator's `build()` calls this AFTER `placeDevices()` and BEFORE `emitCables()`.
- **Files modified:** `app/Services/Drawings/DrawIoBuilderService.php` only.
- **Commit:** `51f41fb` (the GREEN commit).

### 2. [Rule 3 — Test fixture mechanic] paging-system fixture port_id=NULL forces test to use force_sheets

- **Found during:** Task 1 GREEN — the planner's literal pseudo-code for `test_paging_system_fixture_emits_multiple_sheets` asserts `>= 2 diagram` count BUT the `Phase23FixtureFactory::wirePagingCables()` writes `source_port_id => null, dest_port_id => null` on every row (Tier 1.5 stencils have no DevicePort rows seeded). Without ports, `SheetPaginator::meetsThreshold()` can't read `signal_type` from cables, so the BOTH-AND D-06 threshold ALWAYS fails and only `system_overview` emits.
- **Issue:** Plan's test would fail as written — paging-system fixture without metadata override emits a single sheet, not multiple. This isn't a builder bug; it's a fixture-data limitation that the plan's pseudo-code didn't account for.
- **Fix:** Test sets `$project->metadata = ['force_sheets' => ['audio', 'network']]` via `forceFill(...)->save()` BEFORE calling build(). This exercises the D-06 tinker override path documented in CONTEXT D-06 deferred-UI line. The test still validates the multi-sheet wrapper + `<diagram>` element counts as the plan intended.
- **Files modified:** `tests/Feature/Drawings/DrawIoBuilderServiceMultiSheetTest.php` only.
- **Commit:** `d221c34` (the RED commit).

### 3. [Rule 2 — Critical functionality] XSS test uses fixture-backed project, not bare `Project::factory()->create()`

- **Found during:** Task 1 RED — the planner's literal pseudo-code for `test_xss_payload_in_project_name_is_escaped` calls `Project::factory()->create(['name' => '<script>...'])` with no `latestPackage`. The bare project hits the empty-graph branch which emits ZERO title block (legacy backwards-compat shape preserves the Phase 21 P03 empty-graph behaviour verbatim — no project name interpolated). The XSS payload never reaches the output, so the assertion `assertStringNotContainsString('<script>alert(1)</script>')` would PASS trivially (the project name never appears) but the assertion `assertStringContainsString('&lt;script&gt;', $xml)` would FAIL (no title block, no escaped payload either).
- **Issue:** As-written test gives false confidence — passes regardless of whether the orchestrator's serialiser ever interpolates the project name escaped or not.
- **Fix:** Test uses `Phase23FixtureFactory::smallMtr()` (which has a package + devices) then `forceFill(['name' => '<script>alert(1)</script>'])->save()` to inject the XSS payload. The fresh build emits a title block that interpolates the escaped project name. Both assertions now meaningfully test the T-23-05-A1 mitigation.
- **Files modified:** `tests/Feature/Drawings/DrawIoBuilderServiceMultiSheetTest.php` only.
- **Commit:** `d221c34` (the RED commit).

### 4. [Rule 3 — Test-mechanics] Existing `test_builds_valid_mxgraph_xml_with_two_vertex_cells` vertex count assertion relaxed to content-based

- **Found during:** Task 1 RED — the existing Phase 21 P03 test asserts `substr_count($xml, 'vertex="1"') === 2` (exactly 2 device vertex cells for the 4-column shallow heuristic). The new orchestrator emits MANY more vertex cells per sheet: 1 page border + N zone containers + M device cells + 8 title-block field cells. Even with the test's 2 hardware lines, the new output has 1 border + 2 zones + 2 devices + 8 title-block fields = 13 vertex cells (the cable line is still filtered).
- **Issue:** Strict count assertion is too brittle to evolve with the layout engine. The semantic invariant (curated stencil + Tier 1 placeholder both land; cable category filtered) survives — only the count check needed to relax.
- **Fix:** Replaced `assertSame(2, $vertexCount)` with `assertStringContainsString('Neat Bar Pro', $xml)` + `assertStringContainsString('Mystery Device', $xml)`. The cable filter assertion (`assertStringNotContainsString('CAB-HDMI-3M', $xml)`) is unchanged. Phase 21 P03's intent — both curated and Tier 1 land; cables filtered — survives.
- **Files modified:** `tests/Feature/Drawings/DrawIoBuilderServiceTest.php` only.
- **Commit:** `d221c34` (the RED commit).

### 5. [Rule 3 — Test-mechanics] Existing `DrawIoBuilderServiceTest::setUp()` adds Carbon::setTestNow

- **Found during:** Task 1 GREEN — `test_build_is_deterministic` builds the same project twice and asserts byte-identical XML. The new builder's title block reads `now()->format('Y-m-d')` via `TitleBlockRenderer::render()`. Across two `build()` calls on the same project, `now()` returns the same value WITHIN the test (clock granularity), but across CI runs spanning a day boundary the date string changes — and across the test suite, other tests may have called `Carbon::setTestNow()` and left it active.
- **Fix:** Added `Carbon::setTestNow('2026-05-14 12:00:00')` to the existing test's `setUp()` and `Carbon::setTestNow()` (no-arg reset) to a new `tearDown()`. Same pattern as the new `DrawIoBuilderServiceMultiSheetTest`.
- **Files modified:** `tests/Feature/Drawings/DrawIoBuilderServiceTest.php` only.
- **Commit:** `d221c34` (the RED commit).

No other deviations.

## Authentication Gates

None — no external auth or paid API surface touched.

## Self-Check: PASSED

Verified at commits `d221c34` (RED) + `51f41fb` (GREEN):

Files exist:
- FOUND: `app/Services/Drawings/DrawIoBuilderService.php` (modified)
- FOUND: `tests/Feature/Drawings/DrawIoBuilderServiceMultiSheetTest.php` (new)
- FOUND: `tests/Feature/Drawings/DrawIoBuilderServiceTest.php` (modified)
- FOUND: `.planning/phases/23-xten-av-style-renderer/23-05-drawio-builder-orchestrator-rewire-SUMMARY.md` (this file)

Commits exist (in `git log --oneline -5`):
- FOUND: `d221c34` (test RED — multi-sheet harness)
- FOUND: `51f41fb` (feat GREEN — orchestrator rewire)

Acceptance criteria:
- `php artisan test --filter='DrawIoBuilderService'` exits 0 with **14 tests / 46 assertions** GREEN (8 multi-sheet + 6 Phase 21 P03).
- `php artisan test tests/Feature/Drawings` exits 0 with **153 passed / 2 skipped / 488 assertions** GREEN.
- `grep -c "STENCIL_ROLES" app/Services/Drawings/DrawIoBuilderService.php` = **0** (Phase 21 P03 heuristic removed).
- `grep -c "ROLE_COLUMN" app/Services/Drawings/DrawIoBuilderService.php` = **0**.
- `grep -c "deriveCables" app/Services/Drawings/DrawIoBuilderService.php` = **0**.
- `grep -c "TODO(phase-23)" app/Services/Drawings/DrawIoBuilderService.php` = **0** (marker resolved).
- `grep -c "<mxfile" app/Services/Drawings/DrawIoBuilderService.php` = **7** (≥1 required — DRAW-47 wrapper).
- `grep -cE "ZoneGrouper|XtenAvLayoutEngine|CableRouter|SheetPaginator|TitleBlockRenderer|SheetBorderRenderer" app/Services/Drawings/DrawIoBuilderService.php` = **24** (≥6 required — one per injected helper).
- `grep -cE "AIManager|AICache|AIUsage" app/Services/Drawings/DrawIoBuilderService.php` = **0** (D-LOCK-5).
- `grep -cE "update\(|save\(|create\(" app/Services/Drawings/DrawIoBuilderService.php` = **0** (D-LOCK-5/6 — no writes).
- `git diff --stat` against the 5 v1.3 invariant files (`SchematicGeneratorService.php`, `SchematicD2SourceBuilder.php`, `DrawingDataResolverService.php`, `BoundPdfBuilderService.php`, `DrawingExportRendererService.php`) = empty.
- `git diff --stat app/Services/Drawings/DrawIoSpikeBuilderService.php` = empty (Phase 21 D-08 shim untouched).
- `git diff --stat app/Http/Controllers/Admin/DrawIoSpikeController.php` = empty (Phase 21 D-08 controller untouched).
- `git diff --stat config/cables.php` = empty (D-10 single source of truth not mutated).
- `git diff --stat app/Models/CableScheduleItem.php` = empty (Phase 22 D-10 reflection lock honoured).
- `php artisan route:list --name=draw-io-spike` returns the 3 spike routes unchanged (show / saveXml / exportSvg).
- All touched PHP files pass `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l` with "No syntax errors detected".

## Known Stubs

None. The orchestrator wires all 6 helpers end-to-end and emits the full XTEN-AV-style output. The only "stub-like" element is the title block's `Checked by` field (defaults to `'—'` per D-08 when `Project.metadata.drawing_checked_by` is unset) — this is intentional (Phase 24 ships the override UI per CONTEXT deferred line) and not a stub blocking the plan's goal.

## Threat Flags

None. The orchestrator introduces no new network endpoints, no new auth paths, no file access, no schema changes. It is a pure in-memory read transform that composes existing helpers behind an unchanged public API.

## 🚨 Files to upload to live

Per `feedback_local_then_upload.md` + `feedback_php_lint_before_push.md`: RAMS deploy = `git push` to `live` remote → SSH to `/home/stcav/rams.21stcav.com/` → `sudo -u stcav git pull` + `sudo -u stcav php artisan config:clear`. **No migration.** **No view changes.** **No Composer / npm changes.** Single-file orchestrator rewrite.

Files this plan added/modified (for traceability — the actual deploy is a git pull):

- `app/Services/Drawings/DrawIoBuilderService.php`  *(modified — Phase 23 orchestrator; only production file in this plan)*
- `tests/Feature/Drawings/DrawIoBuilderServiceTest.php`  *(modified — test assertions evolved for `<mxfile>` wrapper)*
- `tests/Feature/Drawings/DrawIoBuilderServiceMultiSheetTest.php`  *(new — multi-sheet end-to-end test)*

All 6 helpers Plans 02–04 introduced are already uploaded by their respective plan deploys. After uploading Plan 23-05, the spike route immediately serves the new XTEN-AV-style output — no migration, no flag flip.

Post-deploy runbook (RAMS):

```bash
ssh stcav@rams.21stcav.com
cd /home/stcav/rams.21stcav.com
sudo -u stcav git pull
sudo -u stcav php artisan config:clear
```

(No migration step. No queue restart needed — no jobs touched. No view file changes; Blade cache safe.)

Engineer verification (Plan 23-07 owns this formally):

1. Hit `/admin/drawings/draw-io-spike/{project}` on any project with `equipment_list` data.
2. Expect: device cards grouped into dashed zones, port-to-port cables with signal-coloured strokes (where Tier 2 stencils + populated FKs allow) or coordinate-style edges + ⚠ glyphs (D-07 / OQ-4 Path B fallback), cable IDs labelled at mid-line, brand-teal dashed page border, 8-field title block bottom-left.
3. For large projects: verify multi-page `<diagram>` tabs render in the draw.io v29.7.12 embed (UAT in Plan 23-07).

Plan 23-06 (zone-dropdown review UX) and Plan 23-07 (final verification + visual contract side-by-side) are unblocked.
