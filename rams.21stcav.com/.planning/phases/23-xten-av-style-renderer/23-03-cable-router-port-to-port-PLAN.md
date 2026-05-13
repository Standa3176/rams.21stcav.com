---
phase: 23
plan: 03
type: execute
wave: 2
depends_on: [23-01]
files_modified:
  - app/Services/Drawings/CableRouter.php
  - tests/Feature/Drawings/CableRouterTest.php
autonomous: true
requirements:
  - DRAW-43
  - DRAW-44
  - DRAW-45
tags: [renderer, cables, port-fk, signal-colour, mxgraph, deterministic, v2.0]
must_haves:
  truths:
    - "CableRouter::emitCables() reads cable_schedule_items with both source_port_id and dest_port_id populated and emits one mxGraph edge per cable (DRAW-43)"
    - "Each edge style includes strokeColor + fontColor sourced from config('cables.signal_type_colours')[$signalType] (DRAW-44)"
    - "Each edge value attribute is the literal cable_id string from cable_schedule_items (DRAW-45)"
    - "When source_port_id OR dest_port_id is NULL but the respective device_id is populated, the router falls back to coordinate-style edge geometry per D-07 and emits an additional ⚠ glyph cell at cable midpoint"
    - "When BOTH source_device_id AND dest_device_id are NULL, the cable is SKIPPED entirely (delegated to v1.3 surface per Phase 22 D-10)"
    - "Eager-loading happens AT THE CALL SITE (i.e. inside emitCables — Phase 22 D-10 forbids class-level \\$with on CableScheduleItem)"
    - "All cable text (cable_id, fallback labels) is XML-escaped via htmlspecialchars(ENT_XML1 | ENT_QUOTES)"
    - "Cross-project FK injection cannot happen at render time — Phase 22 T-22-A4 already blocks writes; renderer is read-only"
  artifacts:
    - path: "app/Services/Drawings/CableRouter.php"
      provides: "Port-to-port edge emission + signal-type colours + cable-id labels + D-07 NULL-FK fallback"
      exports: ["emitCables"]
    - path: "tests/Feature/Drawings/CableRouterTest.php"
      provides: "DRAW-43/44/45 + D-07 fallback ladder tests"
      contains: "test_port_to_port_edge_uses_exit_port_id"
  key_links:
    - from: "app/Services/Drawings/CableRouter.php"
      to: "config('cables.signal_type_colours')"
      via: "Phase 22 locked single source of truth"
      pattern: "config\\('cables\\.signal_type_colours'\\)"
    - from: "app/Services/Drawings/CableRouter.php"
      to: "App\\Models\\CableScheduleItem (port FKs, cable_id)"
      via: "iterate $project->cableSchedules->flatMap(items)"
      pattern: "cableSchedules.*items"
    - from: "app/Services/Drawings/CableRouter.php"
      to: "Plan 02 XtenAvLayoutEngine device cell IDs (dev-{zone}-{index})"
      via: "edge source / target attribute"
      pattern: "source.*dev-"
---

<objective>
Ship `CableRouter` — the read-only helper that takes the Plan 02 device-cell descriptors plus the project's cable_schedule_items and emits one mxGraph edge descriptor per cable. Resolves three requirements at once:
- DRAW-43: port-to-port routing via `exitPortId="..."` / `entryPortId="..."` referencing the stencil's `<constraint name="port-id">` element
- DRAW-44: signal-type colour from `config('cables.signal_type_colours')` (Phase 22 single source of truth — do NOT modify)
- DRAW-45: cable_id literal label at edge midpoint (mxGraph default behaviour — set `value="..."` on the edge cell)

Plus D-07 NULL-FK fallback ladder:
- both `source_device_id` AND `dest_device_id` NULL → skip the cable entirely (v1.3 surface handles it per Phase 22 D-10)
- either `source_port_id` OR `dest_port_id` NULL but device_id present → coordinate-style edge with ⚠ glyph at junction
- both port_ids present → happy path, `exitPortId`/`entryPortId` (preferred per 23-RESEARCH.md Example 3) OR coordinate fallback per OQ-4 disposition for Tier 1.5 stencils

Output: `app/Services/Drawings/CableRouter.php` + feature test covering 9 cases.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/phases/23-xten-av-style-renderer/23-CONTEXT.md
@.planning/phases/23-xten-av-style-renderer/23-RESEARCH.md
@.planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-4-TIER15-PORTS.md
@.planning/phases/22-cable-schedule-with-port-level-fks/22-CONTEXT.md
@.planning/phases/22-cable-schedule-with-port-level-fks/22-01-SUMMARY.md
@.planning/phases/22-cable-schedule-with-port-level-fks/22-03-SUMMARY.md
@app/Models/CableScheduleItem.php
@app/Models/DevicePort.php
@app/Models/CableSchedule.php
@app/Models/Project.php
@app/Services/Drawings/DrawIoBuilderService.php
@config/cables.php

<interfaces>
<!-- Contracts CableRouter must honour. -->

From CableScheduleItem (Phase 22 P22-01):
```php
// Properties:
//   $item->id                       int
//   $item->cable_id                 string|null   — e.g. 'LAN-1004', 'USB-1000' (DRAW-45)
//   $item->source_device_id         int|null      (Phase 22 FK)
//   $item->source_port_id           int|null      (Phase 22 FK)
//   $item->dest_device_id           int|null      (Phase 22 FK)
//   $item->dest_port_id             int|null      (Phase 22 FK)
//   $item->from_location            string|null   — legacy free text (D-07 only used for warning hover)
//   $item->to_location              string|null   — legacy free text
//   $item->cable_type               string|null   — e.g. 'HDMI', 'CAT6'
//   $item->connector_override_note  string|null

// Relations (call-site eager load only — D-10 guard):
//   $item->sourcePort      BelongsTo DevicePort
//   $item->destPort        BelongsTo DevicePort
//   $item->sourceDevice    BelongsTo Device
//   $item->destDevice      BelongsTo Device
```

From DevicePort (Phase 21):
```php
// $port->port_id          string  — the canonical port name (matches stencil <constraint name="...">)
//                                  e.g. 'hdmi-out-1', 'rj45', 'usb-c-1'
// $port->signal_type      string  — 'audio'|'video'|'control'|'network'|'usb'|'speaker'|'power' etc.
// $port->side             string  — 'left'|'right'|'top'|'bottom'
// $port->x_pct            decimal — for coordinate-style fallback (top/bottom side only)
// $port->y_pct            decimal — for coordinate-style fallback (left/right side only)
// $port->device_stencil_id int     — FK to device_stencils
```

From config/cables.php (Phase 22 LOCKED — DO NOT MODIFY):
```php
'signal_type_colours' => [
    'audio'   => '#C0392B',   'video'   => '#2980B9',
    'control' => '#27AE60',   'network' => '#8E44AD',
    'usb'     => '#E67E22',   'speaker' => '#16A085',
    'power'   => '#7F8C8D',   'unknown' => '#000000',
],
```

From 23-RESEARCH.md Example 3 (lines 519-545) — preferred named-port edge:
```xml
<mxCell id="cab-1" value="LAN-1004"
        style="edgeStyle=orthogonalEdgeStyle;rounded=1;html=1;strokeColor=#8E44AD;strokeWidth=2;fontSize=10;fontColor=#8E44AD;exitPortId=hdmi-out-1;entryPortId=hdmi-in;"
        edge="1" parent="1" source="dev-rack-0" target="dev-wall-0">
  <mxGeometry relative="1" as="geometry"/>
</mxCell>
```

Coordinate-style fallback (used for D-07 NULL-FK + OQ-4 Tier 1.5 if disposition selected Path B):
```xml
<mxCell ... style="...exitX=1;exitY=0.5;exitDx=0;exitDy=0;exitPerimeter=0;..." source="dev-rack-0" target="dev-wall-0">
```

NULL-FK warning glyph (per CONTEXT specifics line 215):
```xml
<mxCell id="cab-1-warn"
        value="⚠"
        style="text;html=1;align=center;verticalAlign=middle;fontSize=12;fontColor=#E67E22;"
        vertex="1" parent="1">
  <mxGeometry x="..." y="..." width="20" height="20" as="geometry"/>
</mxCell>
```

device-cell-id format from Plan 02 XtenAvLayoutEngine (carried via $deviceCells argument):
```php
// Each device descriptor carries 'id' (string, e.g. 'dev-rack-0') and 'part_number'.
// CableRouter MUST lookup source/dest device cell ids via $item->source_device_id matched against
// $deviceCells[N]['stencil']->id (or some equivalent — see action below).
```

Eager-load discipline (Phase 22 D-10 — CRITICAL):
```php
// At the call-site (CableRouter::emitCables OR DrawIoBuilderService Plan 05):
$project->loadMissing([
    'cableSchedules.items.sourcePort',
    'cableSchedules.items.destPort',
    'cableSchedules.items.sourceDevice',
    'cableSchedules.items.destDevice',
]);
// MUST NOT add $with property to CableScheduleItem (Pitfall 1 + Pitfall 9).
```
</interfaces>
</context>

<threat_model>

## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| `cable_schedule_items.cable_id` | Engineer-typed string (Phase 22 picker writes the canonical format but freeform edits are allowed) — interpolated into mxCell value |
| `cable_schedule_items.from_location` / `to_location` | Legacy free-text (Phase 22 D-04 normalises on picker apply; older rows have engineer-typed strings) — only used by D-07 warning glyph hover label |
| Cross-project FK injection (Phase 22 T-22-A4) | Already mitigated at write side; renderer is read-only |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-23-03-A1 | Tampering (XSS) | `cable_id` rendered as edge `value="..."` | mitigate | `xml()` helper (htmlspecialchars ENT_XML1 \| ENT_QUOTES) on every interpolation. Same pattern as XtenAvLayoutEngine + DrawIoBuilderService line 407. Per Pitfall 8. |
| T-23-03-A2 | Tampering (XSS) | `from_location` / `to_location` interpolated into D-07 warning glyph label OR tooltip | mitigate | Same `xml()` helper. Phase 22 picker normalises text on apply, but older rows + the D-04 escape hatch may carry raw text. Defence-in-depth. |
| T-23-03-A3 | Tampering | Engineer types invalid signal_type via picker → unknown colour key → DEFAULT to 'unknown' (#000000) | accept | Phase 22 picker constrains signal_type via DevicePort.signal_type which is set by the seed pack. Tier 1 placeholders have no ports → no edges through them. Unknown colour fallback is the documented behaviour per config/cables.php. |
| T-23-03-A4 | DoS | Project with 500+ cable_schedule_items overwhelms render | accept | Pitfall 4: 5 MB postMessage cap already enforced on `DrawIoSpikeController::saveXml` line 59. CableRouter emits a Log::warning if `count($edges) > 200`. Pre-existing spike mitigation handles the cap. |

</threat_model>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: CableRouter — port-to-port edges + signal colours + cable_id labels + D-07 fallback ladder</name>
  <files>
    app/Services/Drawings/CableRouter.php,
    tests/Feature/Drawings/CableRouterTest.php
  </files>
  <read_first>
    - app/Models/CableScheduleItem.php (full file — relations + fillable from Phase 22)
    - app/Models/DevicePort.php (full file — port_id + signal_type + side semantics)
    - .planning/phases/23-xten-av-style-renderer/23-CONTEXT.md D-07 (line 92-94) + D-09 (line 107) + D-10 (lines 111) — fallback ladder + colour single source + open issue
    - .planning/phases/23-xten-av-style-renderer/23-RESEARCH.md §"Example 3" (lines 517-546) — port-to-port edge XML + Example 4 (line 547+)
    - .planning/phases/23-xten-av-style-renderer/23-RESEARCH.md §"Pitfall 1" (line 355) + §"Pitfall 7" (line 391) + §"Pitfall 9" (line 406) — eager-load + NULL-FK ladder + N+1
    - .planning/phases/22-cable-schedule-with-port-level-fks/22-01-SUMMARY.md (table column shape + 4 belongsTo relations + empty $with guard)
    - .planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-4-TIER15-PORTS.md (full file — disposition tells us how aggressively to fall back to device-edge for Tier 1.5 stencils)
    - config/cables.php (signal_type_colours array — DO NOT MODIFY)
    - app/Services/Drawings/DrawIoBuilderService.php lines 400-415 (xml() escape helper to mirror)
  </read_first>
  <behavior>
    - `emitCables(Project $project, array $deviceCells): array<int, array<string,mixed>>` returns a flat list of edge + glyph mxCell descriptors
    - Builds a lookup map: `$deviceIdToCellId = [device_id => cell_id]` from `$deviceCells` (Plan 02 output) so the router can resolve `$item->source_device_id` to a cell id
    - Iterates `$project->cableSchedules->flatMap(items)`. For each item:
      1. If `$item->source_device_id` AND `$item->dest_device_id` BOTH NULL → continue (skip; v1.3 handles)
      2. If `$item->source_device_id` OR `$item->dest_device_id` doesn't appear in `$deviceIdToCellId` (e.g. device was deleted; FK nullOnDelete fired) → continue + Log::warning
      3. Resolve `signal_type` from source port (fallback to dest port) (fallback to 'unknown' if both NULL or Tier 1.5)
      4. Look up colour from `config('cables.signal_type_colours')[$signalType] ?? '#000000'`
      5. Build the edge descriptor with:
         - `id` = 'cab-' + $item->id
         - `value` = xml($item->cable_id ?? '')
         - `style` = 'edgeStyle=orthogonalEdgeStyle;rounded=1;html=1;strokeColor={hex};strokeWidth=2;fontSize=10;fontColor={hex};' + port attachment style
         - `source` / `target` = the device-cell ids from the lookup map
      6. Port attachment style:
         - If BOTH port_ids set AND OQ-4 disposition is Path A OR both stencils are Tier 2: `exitPortId={src->port_id};entryPortId={dst->port_id};`
         - Else if BOTH port_ids set but a stencil is Tier 1.5 AND OQ-4 disposition is Path B: coordinate-style derived from port's `side` (`right` → `exitX=1;exitY=0.5;`; `left` → `exitX=0;exitY=0.5;`; `top` → `exitX=0.5;exitY=0;`; `bottom` → `exitX=0.5;exitY=1;`)
         - Else (D-07 either-port-NULL fallback): coordinate-style from device-edge heuristic — source side = right edge if category in {videobar, byod, mic}; else left edge. Same for dest. Additionally emit a separate ⚠ glyph mxCell at cable midpoint (approximated as midway between source + dest device coordinates)
    - Eager-load via `$project->loadMissing([...])` AT THE TOP of `emitCables` — Phase 22 D-10 guard
    - DETERMINISTIC: same project state → same edge descriptor array (no `now()`, no random, no DB writes)
  </behavior>
  <action>
**Step 1 — TDD RED — write `tests/Feature/Drawings/CableRouterTest.php`:**

```php
<?php

namespace Tests\Feature\Drawings;

use App\Models\CableSchedule;
use App\Models\CableScheduleItem;
use App\Models\Device;
use App\Models\DevicePort;
use App\Models\DeviceStencil;
use App\Models\Project;
use App\Services\Drawings\CableRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 23 Plan 03 — DRAW-43 (port-to-port) + DRAW-44 (colour) + DRAW-45 (cable_id)
 * + D-07 (NULL-FK fallback ladder).
 */
class CableRouterTest extends TestCase
{
    use RefreshDatabase;

    private CableRouter $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = app(CableRouter::class);
    }

    /**
     * Build a project with one CableSchedule + N items.
     * Each item is fully-populated (both port FKs) unless the test overrides.
     *
     * Returns ['project' => Project, 'deviceCells' => array<int, array>] —
     * deviceCells mirrors XtenAvLayoutEngine output shape.
     */
    private function makeProjectWithCables(array $itemOverrides = []): array
    {
        $project = Project::factory()->create();

        // Two curated stencils with named ports
        $sourceStencil = DeviceStencil::create([
            'part_number' => 'src-stencil-1',
            'manufacturer' => 'Acme',
            'model' => 'Source-1',
            'mxgraph_xml' => '<shape><connections><constraint name="hdmi-out-1" x="1" y="0.5" perimeter="0"/></connections></shape>',
            'source' => DeviceStencil::SOURCE_ENGINEER_CURATED,
        ]);
        $destStencil = DeviceStencil::create([
            'part_number' => 'dst-stencil-1',
            'manufacturer' => 'Acme',
            'model' => 'Dest-1',
            'mxgraph_xml' => '<shape><connections><constraint name="hdmi-in" x="0" y="0.5" perimeter="0"/></connections></shape>',
            'source' => DeviceStencil::SOURCE_ENGINEER_CURATED,
        ]);

        $sourcePort = DevicePort::create([
            'device_stencil_id' => $sourceStencil->id,
            'port_id' => 'hdmi-out-1', 'label' => 'HDMI OUT 1',
            'side' => DevicePort::SIDE_RIGHT, 'connector_type' => 'hdmi',
            'signal_type' => 'video', 'direction' => DevicePort::DIRECTION_OUT, 'sort_order' => 1,
        ]);
        $destPort = DevicePort::create([
            'device_stencil_id' => $destStencil->id,
            'port_id' => 'hdmi-in', 'label' => 'HDMI IN',
            'side' => DevicePort::SIDE_LEFT, 'connector_type' => 'hdmi',
            'signal_type' => 'video', 'direction' => DevicePort::DIRECTION_IN, 'sort_order' => 1,
        ]);

        $sourceDevice = Device::factory()->create(['project_id' => $project->id, 'part_no' => 'src-stencil-1']);
        $destDevice   = Device::factory()->create(['project_id' => $project->id, 'part_no' => 'dst-stencil-1']);

        $schedule = CableSchedule::create([
            'project_id' => $project->id,
            'name' => 'Schedule 1',
        ]);

        $defaults = [
            'cable_schedule_id' => $schedule->id,
            'source_device_id'  => $sourceDevice->id,
            'source_port_id'    => $sourcePort->id,
            'dest_device_id'    => $destDevice->id,
            'dest_port_id'      => $destPort->id,
            'cable_id'          => 'VID-1000',
            'cable_type'        => 'HDMI',
            'from_location'     => 'Acme Source-1 (HDMI OUT 1)',
            'to_location'       => 'Acme Dest-1 (HDMI IN)',
        ];

        CableScheduleItem::create(array_merge($defaults, $itemOverrides));

        // Mirror XtenAvLayoutEngine output for the two devices
        $deviceCells = [
            ['kind' => 'device', 'id' => 'dev-rack-0', 'device_id' => $sourceDevice->id, 'part_number' => 'src-stencil-1', 'stencil' => $sourceStencil, 'x' => 80, 'y' => 80, 'w' => 220, 'h' => 140],
            ['kind' => 'device', 'id' => 'dev-wall-0', 'device_id' => $destDevice->id, 'part_number' => 'dst-stencil-1', 'stencil' => $destStencil, 'x' => 500, 'y' => 80, 'w' => 220, 'h' => 140],
        ];

        return ['project' => $project->fresh(), 'deviceCells' => $deviceCells];
    }

    public function test_port_to_port_edge_uses_exit_port_id(): void
    {
        $f = $this->makeProjectWithCables();
        $edges = $this->router->emitCables($f['project'], $f['deviceCells']);

        $edge = collect($edges)->firstWhere('kind', 'edge');
        $this->assertNotNull($edge);
        $this->assertStringContainsString('exitPortId=hdmi-out-1', $edge['style']);
        $this->assertStringContainsString('entryPortId=hdmi-in', $edge['style']);
    }

    public function test_edge_value_is_cable_id(): void
    {
        $f = $this->makeProjectWithCables(['cable_id' => 'LAN-1004']);
        $edges = $this->router->emitCables($f['project'], $f['deviceCells']);
        $edge = collect($edges)->firstWhere('kind', 'edge');
        $this->assertSame('LAN-1004', $edge['value']);
    }

    public function test_cable_colour_from_config_signal_type_colours(): void
    {
        // video → '#2980B9' from config/cables.php (Phase 22 locked)
        $f = $this->makeProjectWithCables();
        $edges = $this->router->emitCables($f['project'], $f['deviceCells']);
        $edge = collect($edges)->firstWhere('kind', 'edge');
        $expected = config('cables.signal_type_colours.video');
        $this->assertStringContainsString("strokeColor={$expected}", $edge['style']);
        $this->assertStringContainsString("fontColor={$expected}", $edge['style']);
    }

    public function test_unknown_signal_type_falls_back_to_unknown_colour(): void
    {
        // Set signal_type to a key not in config
        DB::table('device_ports')->update(['signal_type' => 'made-up-signal']);
        $f = $this->makeProjectWithCables();
        $edges = $this->router->emitCables($f['project'], $f['deviceCells']);
        $edge = collect($edges)->firstWhere('kind', 'edge');
        $expected = config('cables.signal_type_colours.unknown');
        $this->assertStringContainsString("strokeColor={$expected}", $edge['style']);
    }

    public function test_null_fk_renders_with_warning_glyph(): void
    {
        // Device IDs present, port IDs NULL → D-07 device-edge fallback + ⚠
        $f = $this->makeProjectWithCables([
            'source_port_id' => null,
            'dest_port_id'   => null,
            'cable_id'       => 'WARN-001',
        ]);
        $cells = $this->router->emitCables($f['project'], $f['deviceCells']);

        $edge = collect($cells)->firstWhere('kind', 'edge');
        $this->assertNotNull($edge);
        $this->assertStringNotContainsString('exitPortId', $edge['style']);
        $this->assertStringContainsString('exitX=', $edge['style']); // coordinate-style fallback

        $warn = collect($cells)->firstWhere('kind', 'warn');
        $this->assertNotNull($warn);
        $this->assertStringContainsString('⚠', $warn['value']);
    }

    public function test_double_null_fk_cable_is_skipped(): void
    {
        $f = $this->makeProjectWithCables([
            'source_device_id' => null,
            'source_port_id'   => null,
            'dest_device_id'   => null,
            'dest_port_id'     => null,
        ]);
        $cells = $this->router->emitCables($f['project'], $f['deviceCells']);
        $this->assertSame([], $cells);
    }

    public function test_cable_id_xss_escaped(): void
    {
        $f = $this->makeProjectWithCables(['cable_id' => '<script>alert(1)</script>']);
        $edges = $this->router->emitCables($f['project'], $f['deviceCells']);
        $edge = collect($edges)->firstWhere('kind', 'edge');
        $this->assertStringNotContainsString('<script>', $edge['value']);
        $this->assertStringContainsString('&lt;script&gt;', $edge['value']);
    }

    public function test_eager_loading_keeps_query_count_bounded(): void
    {
        $f = $this->makeProjectWithCables();
        DB::enableQueryLog();
        $this->router->emitCables($f['project'], $f['deviceCells']);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // With loadMissing on cableSchedules.items + relations, total queries should be ≤ 5
        // (1 cableSchedules + 1 items + 1 sourcePort + 1 destPort + 1 source/destDevice batched)
        $this->assertLessThan(10, count($queries), 'Eager-loading must prevent N+1 per Pitfall 9');
    }

    public function test_router_does_not_write_to_database(): void
    {
        $f = $this->makeProjectWithCables();
        $beforeCounts = [
            'cable_schedule_items' => DB::table('cable_schedule_items')->count(),
            'device_ports'         => DB::table('device_ports')->count(),
            'device_stencils'      => DB::table('device_stencils')->count(),
            'devices'              => DB::table('devices')->count(),
            'projects'             => DB::table('projects')->count(),
        ];
        $this->router->emitCables($f['project'], $f['deviceCells']);
        foreach ($beforeCounts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "router wrote to {$table} — D-LOCK-5/6 violated");
        }
    }
}
```

Commit RED: `git commit -am "test(23-03): RED — CableRouter port-to-port + signal colour + cable_id + D-07 fallback"`

**Step 2 — Write `app/Services/Drawings/CableRouter.php`:**

```php
<?php

namespace App\Services\Drawings;

use App\Models\CableScheduleItem;
use App\Models\Project;
use Illuminate\Support\Facades\Log;

/**
 * Phase 23 — Port-to-port cable router.
 *
 * Reads each `cable_schedule_items` row on the project and emits ONE mxGraph
 * edge descriptor per cable. The orchestrator (DrawIoBuilderService Plan 05)
 * serialises these into the final mxGraph XML alongside Plan 02's device cells.
 *
 * Implements (per CONTEXT + RESEARCH):
 *   DRAW-43: port-to-port routing (exitPortId / entryPortId, preferred)
 *   DRAW-44: signal-type colour from config('cables.signal_type_colours')
 *            — single source of truth locked by Phase 22; DO NOT MODIFY here
 *   DRAW-45: cable_id literal label at edge midpoint (mxGraph default)
 *   D-07:    NULL-FK fallback ladder
 *              - both device_ids NULL  → skip (v1.3 surface handles per Phase 22 D-10)
 *              - either port_id NULL   → coordinate-style edge + ⚠ glyph
 *              - both port_ids present → port-to-port (preferred)
 *
 * Eager-load discipline (Phase 22 D-10):
 *   loadMissing at the call site INSIDE emitCables. NEVER add $with to CableScheduleItem.
 *
 * Pure read function: NO Eloquent writes (D-LOCK-5/6 determinism).
 *
 * @see .planning/phases/23-xten-av-style-renderer/23-CONTEXT.md D-07/D-09/D-10
 * @see .planning/phases/23-xten-av-style-renderer/23-RESEARCH.md Example 3 (port-to-port edge)
 */
class CableRouter
{
    private const EDGE_STYLE_TEMPLATE = 'edgeStyle=orthogonalEdgeStyle;rounded=1;html=1;strokeWidth=2;fontSize=10;';
    private const WARN_STYLE = 'text;html=1;align=center;verticalAlign=middle;fontSize=12;fontColor=#E67E22;';
    /** D-07 source-side heuristic — cards in these categories project from the right edge by default. */
    private const SOURCE_LIKE_CATEGORIES = ['videobar', 'byod', 'mic', 'desk-mic', 'ceiling-mic', 'paging-station', 'call-station'];

    /**
     * @param  array<int, array{kind: string, id: string, device_id?: int|null, stencil?: object, x?: int, y?: int, w?: int, h?: int}>  $deviceCells
     * @return array<int, array<string, mixed>>
     */
    public function emitCables(Project $project, array $deviceCells): array
    {
        // CALL-SITE eager-load — Phase 22 D-10 guard.
        $project->loadMissing([
            'cableSchedules.items.sourcePort',
            'cableSchedules.items.destPort',
            'cableSchedules.items.sourceDevice',
            'cableSchedules.items.destDevice',
        ]);

        // Build device_id → cell descriptor index for O(1) lookup
        $byDeviceId = [];
        foreach ($deviceCells as $cell) {
            if ($cell['kind'] !== 'device' || empty($cell['device_id'])) {
                continue;
            }
            $byDeviceId[$cell['device_id']] = $cell;
        }

        $cells = [];
        $items = $project->cableSchedules->flatMap(fn ($s) => $s->items);

        foreach ($items as $item) {
            // Step 1 — both device_ids NULL → skip (v1.3 surface handles)
            if ($item->source_device_id === null && $item->dest_device_id === null) {
                continue;
            }

            // Step 2 — device id not in current sheet's device set
            $src = $byDeviceId[$item->source_device_id] ?? null;
            $dst = $byDeviceId[$item->dest_device_id]   ?? null;
            if ($src === null || $dst === null) {
                Log::warning('CableRouter: skipping cable, device not on sheet', [
                    'cable_id'         => $item->cable_id,
                    'source_device_id' => $item->source_device_id,
                    'dest_device_id'   => $item->dest_device_id,
                    'project_id'       => $project->id,
                ]);
                continue;
            }

            // Step 3 — resolve signal_type (source preferred; dest fallback; 'unknown' default)
            $signal = (string) ($item->sourcePort?->signal_type ?? $item->destPort?->signal_type ?? 'unknown');
            $colour = (string) (config('cables.signal_type_colours.' . $signal)
                ?? config('cables.signal_type_colours.unknown')
                ?? '#000000');

            // Step 4 — port attachment style
            $bothPortsPresent = $item->source_port_id !== null && $item->dest_port_id !== null;
            $portStyle = '';
            $needsWarnGlyph = false;
            if ($bothPortsPresent) {
                $portStyle = $this->portToPortStyle($item);
            } else {
                // D-07 fallback — device-edge heuristic + warning
                $portStyle = $this->deviceEdgeStyle($src, $dst);
                $needsWarnGlyph = true;
            }

            $edgeStyle = self::EDGE_STYLE_TEMPLATE
                . "strokeColor={$colour};fontColor={$colour};"
                . $portStyle;

            $cells[] = [
                'kind'    => 'edge',
                'id'      => 'cab-' . $item->id,
                'value'   => $this->xml((string) ($item->cable_id ?? '')),
                'style'   => $edgeStyle,
                'source'  => $src['id'],
                'target'  => $dst['id'],
                'signal'  => $signal,
                'colour'  => $colour,
            ];

            if ($needsWarnGlyph) {
                // Midpoint between source + dest device centres.
                $midX = (int) ((($src['x'] + $src['w'] / 2) + ($dst['x'] + $dst['w'] / 2)) / 2);
                $midY = (int) ((($src['y'] + $src['h'] / 2) + ($dst['y'] + $dst['h'] / 2)) / 2);
                $cells[] = [
                    'kind'   => 'warn',
                    'id'     => 'cab-' . $item->id . '-warn',
                    'value'  => '⚠',
                    'style'  => self::WARN_STYLE,
                    'parent' => '1',
                    'x'      => $midX - 10,
                    'y'      => $midY - 10,
                    'w'      => 20,
                    'h'      => 20,
                ];
            }
        }

        if (count($cells) > 200) {
            Log::warning('CableRouter: large edge count', [
                'count'      => count($cells),
                'project_id' => $project->id,
            ]);
        }

        return $cells;
    }

    /**
     * Preferred port-to-port style. Uses exitPortId / entryPortId referencing
     * the stencil's <constraint name="..."> elements.
     *
     * Per OQ-4 disposition: if Tier 1.5 stencil disposition was Path B (Tier
     * 1.5 stencils lack constraints), this fallback to coordinate-style happens
     * here based on port->side. The disposition file (Plan 01 Task 1) is the
     * source of truth for which path to take — and we conservatively emit
     * BOTH style fragments so the renderer works either way (named-port + side-coord).
     */
    private function portToPortStyle(CableScheduleItem $item): string
    {
        $srcPortId = (string) ($item->sourcePort?->port_id ?? '');
        $dstPortId = (string) ($item->destPort?->port_id ?? '');

        // Side-based coordinate fallback (used when stencil lacks the constraint;
        // mxGraph silently ignores exitPortId in that case — coords take over)
        $srcCoord = $this->sideToCoord($item->sourcePort?->side ?? 'right');
        $dstCoord = $this->sideToCoord($item->destPort?->side ?? 'left');

        return 'exitPortId=' . $srcPortId . ';'
             . 'entryPortId=' . $dstPortId . ';'
             . "exitX={$srcCoord['x']};exitY={$srcCoord['y']};exitDx=0;exitDy=0;exitPerimeter=0;"
             . "entryX={$dstCoord['x']};entryY={$dstCoord['y']};entryDx=0;entryDy=0;entryPerimeter=0;";
    }

    /**
     * D-07 fallback: project source from right edge if it's "source-like" (videobar/byod/mic),
     * else from left edge. Same heuristic for dest.
     */
    private function deviceEdgeStyle(array $src, array $dst): string
    {
        $srcCategory = (string) ($src['stencil']->source_category ?? $src['category'] ?? '');
        $srcSide = in_array($srcCategory, self::SOURCE_LIKE_CATEGORIES, true) ? 'right' : 'left';
        $dstSide = 'left'; // dest defaults to left edge

        $srcCoord = $this->sideToCoord($srcSide);
        $dstCoord = $this->sideToCoord($dstSide);

        return "exitX={$srcCoord['x']};exitY={$srcCoord['y']};exitDx=0;exitDy=0;exitPerimeter=0;"
             . "entryX={$dstCoord['x']};entryY={$dstCoord['y']};entryDx=0;entryDy=0;entryPerimeter=0;";
    }

    /** @return array{x: float, y: float} */
    private function sideToCoord(string $side): array
    {
        return match (strtolower($side)) {
            'right'  => ['x' => 1,   'y' => 0.5],
            'left'   => ['x' => 0,   'y' => 0.5],
            'top'    => ['x' => 0.5, 'y' => 0],
            'bottom' => ['x' => 0.5, 'y' => 1],
            default  => ['x' => 0.5, 'y' => 0.5],
        };
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
```

**Step 3 — Run GREEN:**
```
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l app/Services/Drawings/CableRouter.php
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l tests/Feature/Drawings/CableRouterTest.php
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test --filter=CableRouterTest --stop-on-failure
git diff --stat app/Services/Drawings/SchematicGeneratorService.php app/Services/Drawings/SchematicD2SourceBuilder.php app/Services/Drawings/DrawingDataResolverService.php app/Services/Drawings/BoundPdfBuilderService.php app/Services/Drawings/DrawingExportRendererService.php
git diff --stat app/Services/Drawings/DrawIoBuilderService.php app/Models/CableScheduleItem.php config/cables.php
```

All listed files (v1.3 invariants + orchestrator + CableScheduleItem + config/cables.php) must show empty diff (Phase 22 D-10 + Phase 21 D-10 carry-forward + D-10 colour single-source-of-truth lock).

**Step 4 — Commit GREEN:**
```
git add app/Services/Drawings/CableRouter.php
git commit -m "feat(23-03): CableRouter — DRAW-43/44/45 + D-07 NULL-FK fallback"
```
  </action>
  <verify>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l app/Services/Drawings/CableRouter.php</automated>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l tests/Feature/Drawings/CableRouterTest.php</automated>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test --filter=CableRouterTest --stop-on-failure</automated>
  </verify>
  <acceptance_criteria>
    - `app/Services/Drawings/CableRouter.php` exists
    - `php artisan test --filter=CableRouterTest` exits 0 (9 tests pass)
    - `grep -c "AIManager\|AICache\|AIUsage" app/Services/Drawings/CableRouter.php` returns 0
    - `grep -c "->update(\|->save(\|::create(\|DB::insert\|DB::update" app/Services/Drawings/CableRouter.php` returns 0 (D-LOCK-5/6)
    - `grep -c "htmlspecialchars" app/Services/Drawings/CableRouter.php` returns ≥1 (T-23-03-A1)
    - `grep -c "config('cables.signal_type_colours" app/Services/Drawings/CableRouter.php` returns ≥1 (DRAW-44 single source of truth)
    - `grep -c "exitPortId" app/Services/Drawings/CableRouter.php` returns ≥1 (DRAW-43 preferred path)
    - `grep -c "loadMissing" app/Services/Drawings/CableRouter.php` returns ≥1 (Pitfall 9 N+1 prevention)
    - `grep -c "protected \$with" app/Models/CableScheduleItem.php` returns 0 (Phase 22 D-10 — class-level $with stays empty)
    - `git diff --stat app/Services/Drawings/SchematicGeneratorService.php app/Services/Drawings/SchematicD2SourceBuilder.php app/Services/Drawings/DrawingDataResolverService.php app/Services/Drawings/BoundPdfBuilderService.php app/Services/Drawings/DrawingExportRendererService.php` returns empty
    - `git diff --stat config/cables.php` returns empty (D-10 single source of truth — DO NOT MODIFY in Phase 23)
    - `git diff --stat app/Models/CableScheduleItem.php` returns empty
    - `git diff --stat app/Services/Drawings/DrawIoBuilderService.php` returns empty (orchestrator rewire is Plan 05)
    - `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l app/Services/Drawings/CableRouter.php` prints "No syntax errors"
  </acceptance_criteria>
  <done>CableRouter class + 9 green tests; all invariants intact; eager-load discipline verified.</done>
</task>

</tasks>

<verification>
- 1 task committed atomically (TDD RED → GREEN)
- `php artisan test --filter=CableRouterTest` exits 0
- `git diff --stat` on the 5 v1.3 invariant files + config/cables.php + CableScheduleItem.php + DrawIoBuilderService.php all return empty
- `grep -rE "AIManager|AICache|AIUsage|->update\(|->save\(|::create\(" app/Services/Drawings/CableRouter.php` returns empty (D-LOCK-5/6)
- `grep -c "config('cables.signal_type_colours" app/Services/Drawings/CableRouter.php` returns ≥1 (D-10)
</verification>

<success_criteria>
Plan 05 (DrawIoBuilderService orchestrator) calls:
1. `app(CableRouter::class)->emitCables($project, $deviceCells)` where `$deviceCells` comes from Plan 02 XtenAvLayoutEngine
2. Receives a flat ordered array of edge + warn descriptors ready to serialise into mxGraph XML
3. The descriptors slot in AFTER zone + device cells, before title block (Plan 04)

Plan 07 (final verification) reads D-10 colour mapping from `config('cables.signal_type_colours')` and compares side-by-side against the XTEN-AV PAGING SYSTEM reference image. If mismatched, Plan 07 raises a SEPARATE config-update ticket — Phase 23 does NOT modify the config.
</success_criteria>

<output>
After completion, create `.planning/phases/23-xten-av-style-renderer/23-03-SUMMARY.md` documenting:
- D-07 NULL-FK fallback ladder semantics verbatim
- DRAW-43 preferred (exitPortId/entryPortId) + coordinate-fallback decision flow
- DRAW-44 colour resolution path verbatim (config/cables.php is the only read site)
- DRAW-45 cable_id rendered via edge `value` attribute (no extra cell)
- Decision IDs implemented: D-07 (NULL-FK ladder), D-09 (no `rams_` prefix in class name), D-10 (config-cables read-only)
- Pitfall 1 + Pitfall 9 verifications (no $with on CableScheduleItem; loadMissing at call site)
- T-23-03-A1 + T-23-03-A2 XSS mitigations verified

End with the 🚨 "Files to upload to live" section listing:
- `app/Services/Drawings/CableRouter.php`
- Note: no migration / no controller change. Plan 03 is pure additive read-only service code.
</output>