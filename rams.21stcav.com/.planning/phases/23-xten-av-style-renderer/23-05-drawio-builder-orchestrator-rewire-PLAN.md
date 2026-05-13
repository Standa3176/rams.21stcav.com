---
phase: 23
plan: 05
type: execute
wave: 3
depends_on: [23-02, 23-03, 23-04]
files_modified:
  - app/Services/Drawings/DrawIoBuilderService.php
  - tests/Feature/Drawings/DrawIoBuilderServiceTest.php
  - tests/Feature/Drawings/DrawIoBuilderServiceMultiSheetTest.php
autonomous: true
requirements:
  - DRAW-42
  - DRAW-43
  - DRAW-44
  - DRAW-45
  - DRAW-46
  - DRAW-47
  - DRAW-48
  - DRAW-49
tags: [orchestrator, mxfile, integration, deterministic, v2.0]
must_haves:
  truths:
    - "DrawIoBuilderService::build(Project): string PUBLIC CONTRACT UNCHANGED — same signature as Phase 21 P03"
    - "Phase 21 P03 tests (DrawIoBuilderServiceTest) continue to pass — test_d08_spike_controller_constructor_has_two_parameters + test_spike_builder_shim_still_exists_and_delegates green"
    - "DrawIoSpikeBuilderService shim still delegates to DrawIoBuilderService (Phase 21 D-08)"
    - "DrawIoSpikeController constructor signature unchanged (`DrawIoBuilderService $builder, DrawingService $drawings`)"
    - "Builder output for a project with >1 sheet is wrapped in `<mxfile>` with one `<diagram>` per sheet — multi-page mxGraph document format"
    - "Builder output for an empty project (no equipment) returns the legacy empty-graph shape (backwards-compat preserved)"
    - "Determinism contract holds: same Project input → same XML bytes (frozen via Carbon::setTestNow in tests, no actingAs)"
    - "STENCIL_ROLES + ROLE_COLUMN + canonical Teams Room cable chain heuristics from Phase 21 P03 are DELETED — replaced by the Plan 02/03/04 helpers"
  artifacts:
    - path: "app/Services/Drawings/DrawIoBuilderService.php"
      provides: "Phase 23 evolved orchestrator — public build(Project) contract preserved; internals call ZoneGrouper + XtenAvLayoutEngine + CableRouter + SheetPaginator + TitleBlockRenderer + SheetBorderRenderer"
      contains: "<mxfile"
    - path: "tests/Feature/Drawings/DrawIoBuilderServiceMultiSheetTest.php"
      provides: "End-to-end orchestration test on the 4 fixture projects"
      contains: "test_multi_page_wraps_in_mxfile"
  key_links:
    - from: "app/Services/Drawings/DrawIoBuilderService.php"
      to: "app/Services/Drawings/ZoneGrouper.php (Plan 02), XtenAvLayoutEngine.php (Plan 02), CableRouter.php (Plan 03), SheetPaginator.php (Plan 04), TitleBlockRenderer.php (Plan 04), SheetBorderRenderer.php (Plan 04)"
      via: "constructor-injected dependencies"
      pattern: "ZoneGrouper|XtenAvLayoutEngine|CableRouter|SheetPaginator|TitleBlockRenderer|SheetBorderRenderer"
    - from: "app/Services/Drawings/DrawIoBuilderService.php"
      to: "/admin/drawings/draw-io-spike/{project}"
      via: "DrawIoSpikeController::show (Phase 21 D-08 preserved)"
      pattern: "DrawIoSpikeController"
---

<objective>
Rewire `DrawIoBuilderService::build(Project): string` internals to call the 6 Phase 23 helpers from Plans 02-04, emit the multi-sheet `<mxfile>` wrapper, and DELETE the Phase 21 P03 shallow heuristics (STENCIL_ROLES + ROLE_COLUMN + canonical Teams Room cable chain).

PRESERVES (D-05):
- public method signature `build(Project): string`
- constructor signature accepting `DeviceStencilCacheService`, `ManufacturerLogoResolver` (extended with the 6 new helpers)
- DrawIoSpikeBuilderService shim
- DrawIoSpikeController constructor (2 params: `DrawIoBuilderService $builder, DrawingService $drawings`)
- spike admin route `/admin/drawings/draw-io-spike/{project}`
- empty-graph behaviour for projects without packages

This is the integration moment — Plans 02/03/04 ship the helpers; Plan 05 wires them. After this plan, opening `/admin/drawings/draw-io-spike/{project}` in a browser renders the XTEN-AV-style output.

Output:
- Evolved `DrawIoBuilderService.php` (TODO(phase-23) marker at line ~32 resolved; STENCIL_ROLES const + deriveCables() Teams Room chain removed; new methods + helper-injection in constructor)
- New end-to-end multi-sheet test
- Existing DrawIoBuilderServiceTest still green
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/phases/23-xten-av-style-renderer/23-CONTEXT.md
@.planning/phases/23-xten-av-style-renderer/23-RESEARCH.md
@.planning/phases/23-xten-av-style-renderer/23-02-SUMMARY.md
@.planning/phases/23-xten-av-style-renderer/23-03-SUMMARY.md
@.planning/phases/23-xten-av-style-renderer/23-04-SUMMARY.md
@.planning/phases/21-device-port-catalog-stencil-cache/21-03-manufacturer-logos-builder-integration-SUMMARY.md
@app/Services/Drawings/DrawIoBuilderService.php
@app/Services/Drawings/ZoneGrouper.php
@app/Services/Drawings/XtenAvLayoutEngine.php
@app/Services/Drawings/CableRouter.php
@app/Services/Drawings/SheetPaginator.php
@app/Services/Drawings/TitleBlockRenderer.php
@app/Services/Drawings/SheetBorderRenderer.php
@app/Http/Controllers/Admin/DrawIoSpikeController.php
@tests/Feature/Drawings/DrawIoBuilderServiceTest.php
@tests/Fixtures/Drawings/Phase23FixtureFactory.php

<interfaces>
<!-- Contracts to preserve and integrate -->

DrawIoBuilderService public contract (Phase 21 P03 — UNCHANGED):
```php
public function __construct(
    private readonly DeviceStencilCacheService $stencilCache,
    private readonly ManufacturerLogoResolver  $logos,
    // Phase 23 ADDS these — same readonly + promoted-property pattern:
    private readonly ZoneGrouper               $zones,
    private readonly XtenAvLayoutEngine        $layout,
    private readonly CableRouter               $cables,
    private readonly SheetPaginator            $paginator,
    private readonly TitleBlockRenderer        $titleBlock,
    private readonly SheetBorderRenderer       $sheetBorder,
) {}

public function build(Project $project): string; // SAME signature — returns mxGraph XML
```

DrawIoSpikeBuilderService shim (Phase 21 D-08 — DO NOT BREAK):
```php
class DrawIoSpikeBuilderService
{
    public function __construct(private readonly DrawIoBuilderService $builder) {}
    public function build(Project $project): string { return $this->builder->build($project); }
}
```
Phase 21 P03 added all 6 NEW helpers to the new constructor — Laravel's service container resolves them automatically when the orchestrator is constructed. NO BINDINGS NEEDED in AppServiceProvider unless an interface is involved (planner can leave the auto-resolution alone).

DrawIoSpikeController constructor (Phase 21 P03 — DO NOT MODIFY):
```php
public function __construct(
    private readonly DrawIoBuilderService $builder,
    private readonly DrawingService       $drawings,
) {}
```

`<mxfile>` wrapper shape (23-RESEARCH.md Example 4 lines 547-575):
```xml
<mxfile host="app.diagrams.net" agent="21cav-rams-renderer" version="29.7.12">
  <diagram name="System Overview" id="sheet-01">
    <mxGraphModel dx="1200" dy="800" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="1600" pageHeight="1000" math="0" shadow="0">
      <root>
        <mxCell id="0"/>
        <mxCell id="1" parent="0"/>
        <!-- border + title block + zones + devices + cables -->
      </root>
    </mxGraphModel>
  </diagram>
  <diagram name="Audio Subsystem" id="sheet-02">...</diagram>
</mxfile>
```

Per-sheet cell composition (orchestrator's responsibility):
1. SheetBorder cell (1)
2. TitleBlockRenderer cells (8)
3. Zone containers (N) — from XtenAvLayoutEngine output filtered by sheet's signal_filter (system_overview includes all; sub-sheets include only devices/zones touching that signal)
4. Device cells (M)
5. Edge cells from CableRouter (P) — filtered by sheet's signal_filter
6. Optional warn glyph cells (Q) — accompany NULL-FK edges

Cell-to-XML serialisation pattern (mirror Phase 21 P03 `emitMxGraph`):
```php
// For each cell descriptor (kind/id/value/style/parent/x/y/w/h):
$xml .= sprintf(
    '<mxCell id="%s" value="%s" style="%s" vertex="1" parent="%s"><mxGeometry x="%d" y="%d" width="%d" height="%d" as="geometry"/></mxCell>',
    $cell['id'], $cell['value'], $cell['style'], $cell['parent'],
    $cell['x'], $cell['y'], $cell['w'], $cell['h']
);
// For edges (kind=edge): vertex="1" → edge="1"; no mxGeometry x/y/w/h, just <mxGeometry relative="1" as="geometry"/>
//                       + source="..." target="..." attributes
```

Empty-graph backwards-compat shape (Phase 21 P03 — preserve verbatim for projects with no equipment):
```xml
<mxGraphModel dx="1200" dy="800" pageWidth="1600" pageHeight="1000" ...>
  <root>
    <mxCell id="0"/>
    <mxCell id="1" parent="0"/>
  </root>
</mxGraphModel>
```
This shape is what Phase 21 P03 emits when `Project::devicesWithStencils()` returns empty. Plan 05 preserves it for empty projects (no `<mxfile>` wrapper — single-page legacy shape).
</interfaces>
</context>

<threat_model>

## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| All interpolated user values (carried through from Plans 02/03/04 helpers) | All helpers already xml-escape via htmlspecialchars; orchestrator's serialiser must NOT double-escape OR forget to escape |
| 5 MB postMessage cap (spike T-260509-ibx-03) | DrawIoSpikeController validates incoming XML at save; orchestrator emits, the cap applies on engineer edit+save round-trip |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-23-05-A1 | Tampering (XSS) | Double-escape / non-escape during sprintf serialisation | mitigate | Each helper already returns `value`/`style` as escaped strings — orchestrator interpolates verbatim WITHOUT re-escaping. Test asserts known XSS payload in Project.name passes through ONLY escaped (not raw, not double-escaped). |
| T-23-05-A2 | DoS | Multi-sheet render > 5 MB postMessage cap (Pitfall 4) | mitigate | Orchestrator emits `Log::warning('DrawIoBuilderService: large XML payload', [...])` when `strlen($xml) > 4_500_000` — gives ops a heads-up before the 5 MB cap bites at save time. |
| T-23-05-A3 | Tampering | DrawIoSpikeController constructor signature changes break the shim contract | mitigate | Phase 21 reflection assertion `test_d08_spike_controller_constructor_has_two_parameters` is retained — failing it red-blocks the plan. |
| T-23-05-A4 | Information Disclosure | XML payload contains Project.name etc. — already gated by admin middleware on the spike route | accept | No change from Phase 21 P03; admin-only surface (D-LOCK-7). |

</threat_model>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Extend DrawIoBuilderService — inject 6 helpers + emit per-sheet content + <mxfile> wrap</name>
  <files>
    app/Services/Drawings/DrawIoBuilderService.php,
    tests/Feature/Drawings/DrawIoBuilderServiceMultiSheetTest.php
  </files>
  <read_first>
    - app/Services/Drawings/DrawIoBuilderService.php (FULL file — current Phase 21 P03 shape; orchestrator's emitMxGraph + xml() + STENCIL_ROLES + deriveCables to REPLACE)
    - app/Services/Drawings/ZoneGrouper.php (Plan 02 contract)
    - app/Services/Drawings/XtenAvLayoutEngine.php (Plan 02 — descriptor shape carries part_number, x/y/w/h, parent)
    - app/Services/Drawings/CableRouter.php (Plan 03 — emitCables signature + edge descriptor shape)
    - app/Services/Drawings/SheetPaginator.php (Plan 04 — classify return shape)
    - app/Services/Drawings/TitleBlockRenderer.php (Plan 04 — render signature)
    - app/Services/Drawings/SheetBorderRenderer.php (Plan 04 — render signature)
    - tests/Feature/Drawings/DrawIoBuilderServiceTest.php (FULL file — Phase 21 P03 tests that MUST stay green)
    - .planning/phases/23-xten-av-style-renderer/23-RESEARCH.md §"Pattern 1" (lines 221-272 — orchestrator sketch) + §"Example 4" (mxfile wrapper)
    - tests/Fixtures/Drawings/Phase23FixtureFactory.php (Plan 01 — 4 fixtures Plan 05 tests against)
  </read_first>
  <behavior>
    - DrawIoBuilderService keeps `build(Project): string` signature
    - Constructor extended with the 6 new helpers as readonly promoted properties (Laravel auto-resolves)
    - For empty project (no latestPackage OR `devicesWithStencils() === []`): return the legacy single-`<mxGraphModel>` empty-graph shape (Phase 21 P03 backwards-compat)
    - For non-empty project: build calls
      1. `$lines = $project->devicesWithStencils()` (Phase 21 Plan 01)
      2. `$zoned = $this->zones->assign($lines)` (Plan 02)
      3. `$deviceCells = $this->layout->placeDevices($zoned)` (Plan 02)
      4. `$cableCells = $this->cables->emitCables($project, $deviceCells)` (Plan 03)
      5. `$sheets = $this->paginator->classify($project)` (Plan 04)
      6. For each sheet: build per-sheet cell list (border + title block + filtered zones/devices/edges) → serialise into `<diagram>` payload
      7. Wrap all `<diagram>` elements in `<mxfile>` wrapper per RESEARCH Example 4
    - Sheet filtering rule: `system_overview` sheet shows ALL devices + cells. Sub-sheets (audio/video/control/network) filter cables by `$edgeCell['signal'] === $sheet['signal_filter']` AND filter devices to the union of devices touched by surviving edges (otherwise the diagram shows orphan devices)
    - Determinism preserved: NO `Auth::user()->id` random-order reads; NO `Str::random()`; NO `now()` outside Plan 04's title-block path (which the test freezes)
    - DELETE the const STENCIL_ROLES + ROLE_COLUMN + deriveCables() body — replaced by Plan 02/03 helpers; TODO(phase-23) marker resolved
    - Emit `Log::warning` if final XML > 4.5 MB (Pitfall 4)
  </behavior>
  <action>
**Step 1 — Read the full current DrawIoBuilderService.php** to understand the exact shape being evolved. Don't modify the rename history (Phase 21 D-08) — the class is still `DrawIoBuilderService`.

**Step 2 — TDD RED — write `tests/Feature/Drawings/DrawIoBuilderServiceMultiSheetTest.php`:**

```php
<?php

namespace Tests\Feature\Drawings;

use App\Models\Project;
use App\Services\Drawings\DrawIoBuilderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Drawings\Phase23FixtureFactory;
use Tests\TestCase;

/**
 * Phase 23 Plan 05 — end-to-end orchestration tests against the 4 Phase 23 fixtures.
 */
class DrawIoBuilderServiceMultiSheetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-05-13 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_empty_project_emits_legacy_single_mxgraphmodel(): void
    {
        $project = Project::factory()->create();
        $xml = app(DrawIoBuilderService::class)->build($project);
        $this->assertStringContainsString('<mxGraphModel', $xml);
        $this->assertStringNotContainsString('<mxfile', $xml); // empty → legacy single-page
    }

    public function test_small_mtr_fixture_emits_single_sheet_inside_mxfile(): void
    {
        $project = Phase23FixtureFactory::smallMtr();
        $xml = app(DrawIoBuilderService::class)->build($project);

        $this->assertStringContainsString('<mxfile', $xml);
        $this->assertStringContainsString('<diagram', $xml);
        // System overview always emits — sub-sheets only above threshold
        $this->assertSame(1, substr_count($xml, '<diagram '));
    }

    public function test_paging_system_fixture_emits_multiple_sheets(): void
    {
        $project = Phase23FixtureFactory::pagingSystem();
        $xml = app(DrawIoBuilderService::class)->build($project);

        $this->assertStringContainsString('<mxfile', $xml);
        // ≥2 sheets given the fixture's >5 cables × >3 devices per signal type
        $this->assertGreaterThanOrEqual(2, substr_count($xml, '<diagram '));
    }

    public function test_legacy_null_fk_fixture_renders_with_warning_glyphs(): void
    {
        $project = Phase23FixtureFactory::legacyNullFk();
        $xml = app(DrawIoBuilderService::class)->build($project);

        // D-07 ⚠ glyph emitted for at least 1 NULL-FK row
        $this->assertStringContainsString('⚠', $xml);
    }

    public function test_each_sheet_has_dashed_border_and_title_block(): void
    {
        $project = Phase23FixtureFactory::pagingSystem();
        $xml = app(DrawIoBuilderService::class)->build($project);

        $sheetCount = substr_count($xml, '<diagram ');
        // One border per sheet (page-border id pattern — Plan 04 Task 3)
        $borderCount = substr_count($xml, 'id="page-border"') + substr_count($xml, 'kind="border"');
        $this->assertGreaterThanOrEqual($sheetCount, max($borderCount, substr_count($xml, 'dashed=1;dashPattern=8 4')));
        // 8 title-block fields × sheetCount minimum
        $tbCount = substr_count($xml, 'tb-');
        $this->assertGreaterThanOrEqual(8 * $sheetCount, $tbCount);
    }

    public function test_signal_colours_match_config_cables(): void
    {
        $project = Phase23FixtureFactory::smallMtr();
        $xml = app(DrawIoBuilderService::class)->build($project);
        $networkColour = config('cables.signal_type_colours.network');
        if (str_contains($xml, 'signal=network')) {
            $this->assertStringContainsString("strokeColor={$networkColour}", $xml);
        }
        $this->assertNotEmpty($networkColour);
    }

    public function test_determinism_across_calls(): void
    {
        $project = Phase23FixtureFactory::smallMtr();
        $a = app(DrawIoBuilderService::class)->build($project);
        $b = app(DrawIoBuilderService::class)->build($project->fresh());
        $this->assertSame($a, $b, 'D-LOCK-5/6 determinism contract — same project → same bytes');
    }

    public function test_xss_payload_in_project_name_is_escaped(): void
    {
        $project = Project::factory()->create(['name' => '<script>alert(1)</script>']);
        $xml = app(DrawIoBuilderService::class)->build($project);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $xml);
        $this->assertStringContainsString('&lt;script&gt;', $xml);
    }
}
```

Commit RED: `git commit -am "test(23-05): RED — end-to-end multi-sheet orchestration on 4 fixtures"`

**Step 3 — Modify `app/Services/Drawings/DrawIoBuilderService.php`:**

Make these changes (preserving the class name + namespace + Phase 21 P03 backwards-compat shape):

1. **Constructor** — extend with 6 new readonly promoted properties (keep existing 2):
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

2. **DELETE** the `STENCIL_ROLES` const (lines ~65-71) and the `ROLE_COLUMN` const (whatever follows). Replace with a comment block:
```php
// Phase 23: layout heuristics replaced by ZoneGrouper + XtenAvLayoutEngine
// (per CONTEXT D-01, D-02, D-04). The Phase 21 P03 STENCIL_ROLES const +
// 4-column grid + canonical Teams Room cable chain (deriveCables) have all
// been removed; their TODO(phase-23) marker resolved.
```

3. **Rewrite `build()` method** to:
```php
public function build(Project $project): string
{
    $package = $project->latestPackage;
    if ($package === null) {
        return $this->emitEmptyGraph();
    }

    $lines = $project->devicesWithStencils();
    if ($lines === []) {
        return $this->emitEmptyGraph();
    }

    // 1. Zone-group all devices (D-01/D-02/D-04 — Plan 02)
    $zoned = $this->zones->assign($lines);

    // 2. Layout: zone containers + device cells (DRAW-42, DRAW-46 — Plan 02)
    $deviceCells = $this->layout->placeDevices($zoned);

    // 3. Cables: port-to-port edges with signal colours + cable_id labels (DRAW-43/44/45 — Plan 03)
    $cableCells = $this->cables->emitCables($project, $deviceCells);

    // 4. Paginate: classify into 1-5 sheets (DRAW-47 — Plan 04)
    $sheets = $this->paginator->classify($project);

    // 5. Resolve the project drawing for revision lookup (D-08 — read latest non-superseded)
    $drawing = $project->drawings()
        ->where('kind', 'schematic')                  // schematic-kind drawings only
        ->whereNot('status', 'superseded')            // skip versioned-prior rows
        ->latest('updated_at')
        ->first();                                     // null OK — TitleBlockRenderer falls back to 'R0'

    // 6. Serialise each sheet → <diagram> → wrap all in <mxfile>
    $diagrams = [];
    foreach ($sheets as $sheet) {
        $sheetCells = $this->composeSheet($sheet, $deviceCells, $cableCells, $project, $drawing);
        $diagrams[] = $this->emitDiagram($sheet, $sheetCells);
    }

    $xml = $this->emitMxFile($diagrams);

    if (strlen($xml) > 4_500_000) {
        Log::warning('DrawIoBuilderService: large XML payload approaching 5 MB postMessage cap', [
            'project_id' => $project->id,
            'sheet_count'=> count($sheets),
            'byte_count' => strlen($xml),
        ]);
    }

    return $xml;
}
```

4. **Add the helper methods**:
```php
/**
 * Compose the ordered cell list for a single sheet.
 *
 * For system_overview: all border + title-block + zone + device + edge + warn cells.
 * For sub-sheets: filter cable cells by signal type; filter zone+device cells to the
 *   union of devices touched by the surviving cables (otherwise orphan devices appear).
 */
private function composeSheet(
    array $sheet,
    array $deviceCells,
    array $cableCells,
    Project $project,
    ?\App\Models\ProjectDrawing $drawing,
): array
{
    $cells = [];

    // Border first (so it renders behind content)
    foreach ($this->sheetBorder->render() as $border) {
        $cells[] = $border;
    }

    // Filter cells by sheet signal
    if ($sheet['signal_filter'] !== null) {
        $filteredEdges = array_values(array_filter(
            $cableCells,
            fn ($c) => ($c['kind'] === 'warn') || (isset($c['signal']) && $c['signal'] === $sheet['signal_filter'])
        ));
        // Union of devices touched by surviving edges
        $touchedDeviceCellIds = [];
        foreach ($filteredEdges as $edge) {
            if (! empty($edge['source'])) { $touchedDeviceCellIds[$edge['source']] = true; }
            if (! empty($edge['target'])) { $touchedDeviceCellIds[$edge['target']] = true; }
        }
        $filteredDeviceAndZone = array_values(array_filter(
            $deviceCells,
            function ($c) use ($touchedDeviceCellIds) {
                if ($c['kind'] === 'zone') {
                    // Keep a zone only if it has at least 1 surviving child device.
                    // The orchestrator does a second pass below to drop empty zones.
                    return true;
                }
                return isset($touchedDeviceCellIds[$c['id']]);
            }
        ));
        // Second pass: drop zones with no surviving children
        $survivingDeviceIds = collect($filteredDeviceAndZone)
            ->where('kind', 'device')
            ->pluck('id')
            ->all();
        $filteredDeviceAndZone = array_values(array_filter(
            $filteredDeviceAndZone,
            function ($c) use ($filteredDeviceAndZone, $survivingDeviceIds) {
                if ($c['kind'] !== 'zone') { return true; }
                // Look for at least one device cell whose parent === this zone's id
                foreach ($filteredDeviceAndZone as $other) {
                    if ($other['kind'] === 'device' && ($other['parent'] ?? '') === $c['id']) {
                        return true;
                    }
                }
                return false;
            }
        ));
        foreach ($filteredDeviceAndZone as $c) { $cells[] = $c; }
        foreach ($filteredEdges as $c) { $cells[] = $c; }
    } else {
        // System overview — everything
        foreach ($deviceCells as $c) { $cells[] = $c; }
        foreach ($cableCells as $c) { $cells[] = $c; }
    }

    // Title block last (renders above other cells visually since draw.io z-order = document order)
    foreach ($this->titleBlock->render($sheet, $project, $drawing) as $c) {
        $cells[] = $c;
    }

    return $cells;
}

/**
 * Serialise one sheet's cells into a <diagram><mxGraphModel>...</mxGraphModel></diagram>.
 */
private function emitDiagram(array $sheet, array $cells): string
{
    $page = (array) config('drawings.page_dimensions', []);
    $w = (int) ($page['width']  ?? 1600);
    $h = (int) ($page['height'] ?? 1000);

    $body = '<root><mxCell id="0"/><mxCell id="1" parent="0"/>';
    foreach ($cells as $cell) {
        $body .= $this->serialiseCell($cell);
    }
    $body .= '</root>';

    return sprintf(
        '<diagram name="%s" id="sheet-%s"><mxGraphModel dx="1200" dy="800" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="%d" pageHeight="%d" math="0" shadow="0">%s</mxGraphModel></diagram>',
        $this->xml((string) $sheet['title']),
        $this->xml((string) $sheet['key']),
        $w, $h, $body
    );
}

/**
 * Wrap N <diagram> elements in <mxfile>.
 */
private function emitMxFile(array $diagrams): string
{
    return '<mxfile host="app.diagrams.net" agent="21cav-rams-renderer" version="29.7.12">'
        . implode('', $diagrams)
        . '</mxfile>';
}

/**
 * Serialise one cell descriptor (already xml-escaped by its emitter — DO NOT re-escape value/style).
 */
private function serialiseCell(array $cell): string
{
    if ($cell['kind'] === 'edge') {
        return sprintf(
            '<mxCell id="%s" value="%s" style="%s" edge="1" parent="1" source="%s" target="%s"><mxGeometry relative="1" as="geometry"/></mxCell>',
            $cell['id'], $cell['value'], $cell['style'],
            $cell['source'] ?? '', $cell['target'] ?? ''
        );
    }

    return sprintf(
        '<mxCell id="%s" value="%s" style="%s" vertex="1" parent="%s"><mxGeometry x="%d" y="%d" width="%d" height="%d" as="geometry"/></mxCell>',
        $cell['id'], $cell['value'], $cell['style'],
        $cell['parent'] ?? '1',
        (int) ($cell['x'] ?? 0),  (int) ($cell['y'] ?? 0),
        (int) ($cell['w'] ?? 80), (int) ($cell['h'] ?? 40)
    );
}

/**
 * Empty-project backwards-compat shape — single <mxGraphModel> (no <mxfile> wrapper).
 * Mirrors Phase 21 P03 behaviour to preserve the test_builds_valid_mxgraph_xml... assertion.
 */
private function emitEmptyGraph(): string
{
    $page = (array) config('drawings.page_dimensions', []);
    $w = (int) ($page['width']  ?? 1600);
    $h = (int) ($page['height'] ?? 1000);
    return sprintf(
        '<mxGraphModel dx="1200" dy="800" pageWidth="%d" pageHeight="%d"><root><mxCell id="0"/><mxCell id="1" parent="0"/></root></mxGraphModel>',
        $w, $h
    );
}

private function xml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}
```

5. **DELETE** the existing `deriveCables`, the existing per-line `for`-loop layout logic, the existing emitMxGraph private method body (whatever remains after the deletes). Keep only the constructor + the new methods.

6. **Update the existing `tests/Feature/Drawings/DrawIoBuilderServiceTest.php`** ONLY to extend setUp() with `Carbon::setTestNow('2026-05-13 12:00:00')` so the Phase 21 P03 determinism test stays green after the title-block introduces `now()->format('Y-m-d')`. DO NOT modify the test assertions — they must continue to pass against the new builder's output for an empty project (Phase 21 tests use a 3-device single-sheet fixture; verify they still pass against the Plan 05 output, modifying ONLY the expected `pageWidth`/`pageHeight` IF the existing test hardcodes values that differ from config — adjust to read from config to keep flexibility).

**Step 4 — Run linters + tests + invariants:**
```
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l app/Services/Drawings/DrawIoBuilderService.php
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l tests/Feature/Drawings/DrawIoBuilderServiceMultiSheetTest.php
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test --filter='DrawIoBuilderService' --stop-on-failure
git diff --stat app/Services/Drawings/SchematicGeneratorService.php app/Services/Drawings/SchematicD2SourceBuilder.php app/Services/Drawings/DrawingDataResolverService.php app/Services/Drawings/BoundPdfBuilderService.php app/Services/Drawings/DrawingExportRendererService.php
git diff --stat app/Services/Drawings/DrawIoSpikeBuilderService.php app/Http/Controllers/Admin/DrawIoSpikeController.php config/cables.php
```

The 5 v1.3 surfaces + spike shim + spike controller + config/cables.php MUST all show empty diff.

The full Phase 21 test (`test_d08_spike_controller_constructor_has_two_parameters`, `test_spike_builder_shim_still_exists_and_delegates`, `test_build_is_deterministic`, `test_curated_stencil_mxgraph_xml_is_base64_embedded`, `test_builds_valid_mxgraph_xml_with_two_vertex_cells`) must all stay green.

**Step 5 — Commit:**
```
git add app/Services/Drawings/DrawIoBuilderService.php tests/Feature/Drawings/DrawIoBuilderServiceMultiSheetTest.php tests/Feature/Drawings/DrawIoBuilderServiceTest.php
git commit -m "feat(23-05): wire ZoneGrouper+XtenAvLayoutEngine+CableRouter+SheetPaginator+TitleBlock+SheetBorder into DrawIoBuilderService (per D-05; resolves TODO(phase-23))"
```
  </action>
  <verify>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l app/Services/Drawings/DrawIoBuilderService.php</automated>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l tests/Feature/Drawings/DrawIoBuilderServiceMultiSheetTest.php</automated>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test --filter='DrawIoBuilderService' --stop-on-failure</automated>
  </verify>
  <acceptance_criteria>
    - `app/Services/Drawings/DrawIoBuilderService.php` modified — constructor extended with 6 new helpers
    - `php artisan test --filter='DrawIoBuilderService'` exits 0 (includes Phase 21 P03 tests + new Plan 05 tests — at minimum 14 tests pass)
    - `grep -c "STENCIL_ROLES" app/Services/Drawings/DrawIoBuilderService.php` returns 0 (Phase 21 heuristic deleted)
    - `grep -c "ROLE_COLUMN" app/Services/Drawings/DrawIoBuilderService.php` returns 0
    - `grep -c "deriveCables" app/Services/Drawings/DrawIoBuilderService.php` returns 0
    - `grep -c "TODO(phase-23)" app/Services/Drawings/DrawIoBuilderService.php` returns 0 (marker resolved)
    - `grep -c "<mxfile" app/Services/Drawings/DrawIoBuilderService.php` returns ≥1 (DRAW-47 wrapper)
    - `grep -c "ZoneGrouper\|XtenAvLayoutEngine\|CableRouter\|SheetPaginator\|TitleBlockRenderer\|SheetBorderRenderer" app/Services/Drawings/DrawIoBuilderService.php` returns ≥6 (one per injected helper)
    - `grep -c "AIManager\|AICache\|AIUsage" app/Services/Drawings/DrawIoBuilderService.php` returns 0 (D-LOCK-5)
    - `grep -c "->update(\|->save(\|::create(" app/Services/Drawings/DrawIoBuilderService.php` returns 0 (D-LOCK-5/6 — no writes in builder)
    - `git diff --stat app/Services/Drawings/SchematicGeneratorService.php app/Services/Drawings/SchematicD2SourceBuilder.php app/Services/Drawings/DrawingDataResolverService.php app/Services/Drawings/BoundPdfBuilderService.php app/Services/Drawings/DrawingExportRendererService.php` returns empty
    - `git diff --stat app/Services/Drawings/DrawIoSpikeBuilderService.php` returns empty (Phase 21 D-08 shim untouched)
    - `git diff --stat app/Http/Controllers/Admin/DrawIoSpikeController.php` returns empty (Phase 21 D-08 controller untouched)
    - `git diff --stat config/cables.php` returns empty (D-10 single source of truth)
    - `php artisan route:list --name=draw-io-spike` returns the 3 spike routes (show / saveXml / exportSvg) unchanged
    - `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l app/Services/Drawings/DrawIoBuilderService.php` prints "No syntax errors"
  </acceptance_criteria>
  <done>Orchestrator wired; Phase 21 tests still green; new multi-sheet tests green; v1.3 + spike + config invariants all intact.</done>
</task>

</tasks>

<verification>
- Task 1 committed atomically (RED → GREEN)
- `php artisan test --filter='DrawIoBuilderService'` exits 0 (Phase 21 P03 tests + Plan 05 tests — 14+ tests)
- `git diff --stat` empty on: 5 v1.3 surfaces + spike shim + spike controller + config/cables.php + CableScheduleItem.php
- Spike route names + controller constructor signature unchanged (route:list + reflection assertion both green)
- D-LOCK invariants intact: no AI, no Eloquent writes in any of the 7 builder classes, determinism harness passes
</verification>

<success_criteria>
End-to-end ready:
1. Engineer hits `/admin/drawings/draw-io-spike/{project}` on any project with equipment_list data
2. The builder produces XTEN-AV-style XML wrapped in `<mxfile>` with 1-5 sheets per D-06 threshold
3. Devices grouped into dashed zones per D-01/D-02/D-04
4. Cables drawn port-to-port with signal-type colours from `config/cables.php`
5. Cable IDs labelled at midpoint
6. Title block + sheet border on every page
7. Engineer can edit in the iframe + save back via the existing spike postMessage protocol (unchanged)

Plan 06 (zone-dropdown review UX) ships independently — engineers can already see zones derived from D-01 category map; Plan 06 adds the override UX.

Plan 07 (final verification) runs the v1.3 invariant audit + manual D-10 colour side-by-side + browser UAT for multi-page embed UX (Open Question 3).
</success_criteria>

<output>
After completion, create `.planning/phases/23-xten-av-style-renderer/23-05-SUMMARY.md` documenting:
- Constructor parameter list verbatim (8 readonly promoted properties)
- build() control flow narrative — step 1 through 6
- composeSheet() sheet-filter rule (system_overview = all; sub-sheets = filter cables by signal then filter devices to union touched + drop empty zones)
- `<mxfile>` wrapper shape verbatim
- Empty-project backwards-compat shape preserved
- DELETED Phase 21 P03 surfaces: STENCIL_ROLES const, ROLE_COLUMN const, deriveCables() Teams Room chain, TODO(phase-23) marker
- Phase 21 P03 tests still green (list test names)
- 8 new multi-sheet tests green
- Decision IDs implemented: D-05 (public contract preserved + spike route stays live), D-08 (revision resolved from latest non-superseded drawing), D-09 (generic naming verified across all 6 helpers)
- T-23-05-A1/A2/A3 mitigations verified

End with the 🚨 "Files to upload to live" section listing:
- `app/Services/Drawings/DrawIoBuilderService.php` (only file modified — orchestrator)
- All 6 helpers already uploaded by Plans 02-04
- Note: no migration. Run `php artisan config:clear` on live after upload.
</output>