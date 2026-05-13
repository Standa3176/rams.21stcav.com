---
phase: 23
plan: 04
type: execute
wave: 2
depends_on: [23-01]
files_modified:
  - app/Services/Drawings/SheetPaginator.php
  - app/Services/Drawings/TitleBlockRenderer.php
  - app/Services/Drawings/SheetBorderRenderer.php
  - tests/Feature/Drawings/SheetPaginatorTest.php
  - tests/Feature/Drawings/TitleBlockRendererTest.php
  - tests/Feature/Drawings/SheetBorderRendererTest.php
autonomous: true
requirements:
  - DRAW-47
  - DRAW-48
  - DRAW-49
tags: [renderer, paginator, title-block, border, mxfile, deterministic, v2.0]
must_haves:
  truths:
    - "SheetPaginator emits system-overview sheet ALWAYS (sheet 1), plus audio/video/control/network sub-sheets only when BOTH thresholds met per D-06 (≥5 cables of that signal type AND ≥3 devices touching that signal type)"
    - "Project.metadata.force_sheets tinker override forces sub-sheets regardless of threshold (D-06 deferred-UI escape hatch)"
    - "TitleBlockRenderer emits 8 fields per sheet (project, client, designed-by, drawn-by, checked-by, sheet #, date, revision) per D-08 source resolution"
    - "checked-by falls back to '—' when Project.metadata.drawing_checked_by is unset"
    - "designed-by falls back to '—' when Auth::user() is null (test context — no actingAs)"
    - "date is read from now()->format('Y-m-d') — determinism preserved by Carbon::setTestNow in the test setUp from Plan 01 Task 3"
    - "SheetBorderRenderer emits exactly ONE dashed-border mxCell per sheet at page bounds insets per config('drawings.page_dimensions.border_inset') (DRAW-49)"
  artifacts:
    - path: "app/Services/Drawings/SheetPaginator.php"
      provides: "Sheet classification + D-06 threshold + force_sheets override"
      exports: ["classify"]
    - path: "app/Services/Drawings/TitleBlockRenderer.php"
      provides: "8-field title block per sheet (DRAW-48)"
      exports: ["render"]
    - path: "app/Services/Drawings/SheetBorderRenderer.php"
      provides: "Dashed sheet border per page (DRAW-49)"
      exports: ["render"]
  key_links:
    - from: "app/Services/Drawings/SheetPaginator.php"
      to: "config('drawings.sub_sheet_thresholds')"
      via: "config read"
      pattern: "sub_sheet_thresholds"
    - from: "app/Services/Drawings/SheetPaginator.php"
      to: "Project.metadata.force_sheets"
      via: "model accessor"
      pattern: "metadata.*force_sheets"
    - from: "app/Services/Drawings/TitleBlockRenderer.php"
      to: "Auth::user() + Project.metadata + config('drawings.sheet_number_format')"
      via: "D-08 source resolution"
      pattern: "Auth::user|metadata.*drawing_checked_by|sheet_number_format"
---

<objective>
Ship the three per-sheet renderers that wrap the device + edge cells into a full draw.io page:
- **SheetPaginator** (DRAW-47): classifies the project's cables and devices into sheets — system overview always, audio/video/control/network sub-sheets only when both D-06 thresholds met. Reads `Project.metadata.force_sheets` tinker override.
- **TitleBlockRenderer** (DRAW-48): emits 8 title-block fields at the bottom of each page per D-08 source resolution.
- **SheetBorderRenderer** (DRAW-49): emits the dashed sheet border around each page.

These are independent of Plan 02 (layout) and Plan 03 (cables) — they emit per-page chrome that wraps content. Plan 05 orchestrator wires them together inside the `<mxfile>` wrapper.

Output:
- 3 new service classes under `app/Services/Drawings/`
- 3 PHPUnit feature tests
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/phases/23-xten-av-style-renderer/23-CONTEXT.md
@.planning/phases/23-xten-av-style-renderer/23-RESEARCH.md
@app/Models/Project.php
@app/Models/CableScheduleItem.php
@config/drawings.php
@app/Services/Drawings/DrawIoBuilderService.php

<interfaces>
<!-- Contracts each of the three classes must honour. -->

From config/drawings.php (Phase 23 Plan 01 added these):
```php
'sub_sheet_thresholds' => [
    'min_cables_per_signal'       => 5,
    'min_devices_touching_signal' => 3,
],
'sheet_number_format' => [
    'system_overview' => 'AV-201',
    'audio'           => 'AV-202',
    'video'           => 'AV-203',
    'control'         => 'AV-204',
    'network'         => 'AV-205',
],
'page_dimensions' => [
    'width'         => 1600,
    'height'        => 1000,
    'border_inset'  => 20,
    'title_block_y' => 940,
],
```

From Project model (Phase 23 Plan 01 added):
```php
// $project->metadata             array|null  — cast 'metadata' => 'array'
// $project->metadata['force_sheets']        array<string>|null  — e.g. ['audio', 'video']
// $project->metadata['drawing_checked_by']  string|null
```

23-RESEARCH.md Example 6 (lines 599-615) — title block shape:
```xml
<mxCell id="tb-project" value="Project: Acme Boardroom Refurb"
        style="text;html=1;align=left;verticalAlign=middle;strokeColor=none;fillColor=none;fontSize=10;"
        vertex="1" parent="1">
  <mxGeometry x="80" y="940" width="280" height="20" as="geometry"/>
</mxCell>
```

23-RESEARCH.md Example 7 (lines 619-624) — sheet border:
```xml
<mxCell id="page-border"
        style="rounded=0;dashed=1;dashPattern=8 4;fillColor=none;strokeColor=#1B7A7A;strokeWidth=1.5;"
        vertex="1" parent="1">
  <mxGeometry x="20" y="20" width="1560" height="960" as="geometry"/>
</mxCell>
```

Sheet shape (output of SheetPaginator → input to Plan 05 orchestrator):
```php
// array<int, array{
//   'key'         => 'system_overview' | 'audio' | 'video' | 'control' | 'network',
//   'sheet_number'=> 'AV-201' | ... ,    // from config
//   'title'       => 'System Overview' | 'Audio Subsystem' | ...,
//   'signal_filter' => ?string,           // null for system_overview; signal_type for sub-sheets
// }>
```
</interfaces>
</context>

<threat_model>

## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| `Project.metadata.force_sheets` | Phase 23 writes via tinker only; Phase 24 ships the UI |
| `Project.metadata.drawing_checked_by` | Same as above — engineer-typed string |
| Title block field values | Project.name / client_name / Auth::user()->name — interpolated into mxCell value |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-23-04-A1 | Tampering (XSS) | `drawing_checked_by` / `Project.name` / `Auth::user()->name` interpolated into title block | mitigate | `xml()` helper (htmlspecialchars ENT_XML1 \| ENT_QUOTES) on every interpolation. Same pattern as XtenAvLayoutEngine. |
| T-23-04-A2 | Tampering | `force_sheets` value type confusion — e.g. `['audio' => 'string-not-array']` or non-string entries | mitigate | SheetPaginator validates the metadata read: `is_array($metadata['force_sheets'] ?? null)` AND every entry is a known signal-type key (`audio|video|control|network`). Invalid → log warning + ignore (defaults to threshold). |
| T-23-04-A3 | Information Disclosure | Title block leaks `Auth::user()->email` if name field misused | accept | D-08 reads `Auth::user()->name` not `->email`. Tests confirm. |

</threat_model>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: SheetPaginator — D-06 threshold + force_sheets override + `<mxfile>` page list</name>
  <files>
    app/Services/Drawings/SheetPaginator.php,
    tests/Feature/Drawings/SheetPaginatorTest.php
  </files>
  <read_first>
    - .planning/phases/23-xten-av-style-renderer/23-CONTEXT.md D-06 (lines 89-92) — paginator policy locked
    - .planning/phases/23-xten-av-style-renderer/23-RESEARCH.md §"Example 4" (lines 547-575) — multi-page mxfile wrapper
    - app/Models/CableScheduleItem.php (port FK + signal_type via sourcePort/destPort)
    - app/Models/Project.php (metadata cast — from Plan 01 Task 2)
    - config/drawings.php (sub_sheet_thresholds + sheet_number_format from Plan 01 Task 3)
  </read_first>
  <behavior>
    - `classify(Project $project): array<int, array<string,mixed>>` returns a list of sheet descriptors
    - Always includes `system_overview` sheet first
    - For each of `audio|video|control|network`:
      - Count cables where source-or-dest port has `signal_type == $key` AND device_id is non-null
      - Count distinct devices that touch a port with `signal_type == $key`
      - If BOTH counts meet config thresholds → emit sub-sheet
      - OR if `Project.metadata.force_sheets` contains $key → emit sub-sheet regardless
    - Each sheet descriptor: `['key', 'sheet_number', 'title', 'signal_filter']`
    - Signal-key vocab is strict: `audio, video, control, network` (no 'speaker' / 'power' / 'usb' sub-sheets per CONTEXT — those mix into system overview)
    - Deterministic across calls: same project state → same sheet list (order: system_overview, audio, video, control, network)
  </behavior>
  <action>
**Step 1 — TDD RED — write `tests/Feature/Drawings/SheetPaginatorTest.php`:**

```php
<?php

namespace Tests\Feature\Drawings;

use App\Models\CableSchedule;
use App\Models\CableScheduleItem;
use App\Models\Device;
use App\Models\DevicePort;
use App\Models\DeviceStencil;
use App\Models\Project;
use App\Services\Drawings\SheetPaginator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SheetPaginatorTest extends TestCase
{
    use RefreshDatabase;

    private SheetPaginator $paginator;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-05-13 12:00:00');
        $this->paginator = app(SheetPaginator::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Build a project with N audio cables across M devices.
     */
    private function makeProjectWithSignal(string $signal, int $cableCount, int $deviceCount): Project
    {
        $project = Project::factory()->create();
        $stencil = DeviceStencil::create([
            'part_number' => "stencil-{$signal}",
            'manufacturer' => 'Test',
            'model' => 'M',
            'mxgraph_xml' => '<shape/>',
            'source' => DeviceStencil::SOURCE_ENGINEER_CURATED,
        ]);
        $port = DevicePort::create([
            'device_stencil_id' => $stencil->id,
            'port_id' => 'p1', 'label' => 'P1', 'side' => DevicePort::SIDE_LEFT,
            'connector_type' => 'rj45', 'signal_type' => $signal,
            'direction' => DevicePort::DIRECTION_IO, 'sort_order' => 1,
        ]);
        $devices = collect(range(1, $deviceCount))->map(
            fn ($i) => Device::factory()->create(['project_id' => $project->id, 'part_no' => "stencil-{$signal}-{$i}"])
        );
        $schedule = CableSchedule::create(['project_id' => $project->id, 'name' => 'S']);
        for ($i = 0; $i < $cableCount; $i++) {
            $src = $devices[$i % $deviceCount];
            $dst = $devices[($i + 1) % $deviceCount];
            CableScheduleItem::create([
                'cable_schedule_id' => $schedule->id,
                'source_device_id'  => $src->id,
                'source_port_id'    => $port->id,
                'dest_device_id'    => $dst->id,
                'dest_port_id'      => $port->id,
                'cable_id'          => "C-{$signal}-{$i}",
            ]);
        }
        return $project->fresh();
    }

    public function test_empty_project_emits_one_diagram(): void
    {
        $project = Project::factory()->create();
        $sheets = $this->paginator->classify($project);
        $this->assertCount(1, $sheets);
        $this->assertSame('system_overview', $sheets[0]['key']);
        $this->assertSame('AV-201', $sheets[0]['sheet_number']);
    }

    public function test_below_threshold_no_sub_sheet(): void
    {
        $project = $this->makeProjectWithSignal('audio', cableCount: 4, deviceCount: 3); // 4 < 5
        $sheets = $this->paginator->classify($project);
        $keys = array_column($sheets, 'key');
        $this->assertSame(['system_overview'], $keys);
    }

    public function test_below_device_threshold_no_sub_sheet(): void
    {
        $project = $this->makeProjectWithSignal('audio', cableCount: 5, deviceCount: 2); // 2 < 3
        $sheets = $this->paginator->classify($project);
        $this->assertSame(['system_overview'], array_column($sheets, 'key'));
    }

    public function test_above_threshold_emits_sub_sheet(): void
    {
        $project = $this->makeProjectWithSignal('audio', cableCount: 5, deviceCount: 3);
        $sheets = $this->paginator->classify($project);
        $this->assertSame(['system_overview', 'audio'], array_column($sheets, 'key'));
        $audio = collect($sheets)->firstWhere('key', 'audio');
        $this->assertSame('AV-202', $audio['sheet_number']);
        $this->assertSame('audio', $audio['signal_filter']);
    }

    public function test_force_sheets_metadata_override(): void
    {
        $project = Project::factory()->create(['metadata' => ['force_sheets' => ['audio', 'control']]]);
        $sheets = $this->paginator->classify($project);
        $keys = array_column($sheets, 'key');
        $this->assertContains('system_overview', $keys);
        $this->assertContains('audio', $keys);
        $this->assertContains('control', $keys);
        $this->assertNotContains('video', $keys);
    }

    public function test_force_sheets_invalid_entry_is_ignored(): void
    {
        $project = Project::factory()->create(['metadata' => ['force_sheets' => ['audio', 'made-up-signal']]]);
        $sheets = $this->paginator->classify($project);
        $keys = array_column($sheets, 'key');
        $this->assertContains('audio', $keys);
        $this->assertNotContains('made-up-signal', $keys);
    }

    public function test_force_sheets_non_array_metadata_is_ignored(): void
    {
        $project = Project::factory()->create(['metadata' => ['force_sheets' => 'audio']]);
        $sheets = $this->paginator->classify($project);
        $this->assertSame(['system_overview'], array_column($sheets, 'key'));
    }

    public function test_sheet_order_is_deterministic(): void
    {
        // Force all 4 sub-sheets to verify order
        $project = Project::factory()->create([
            'metadata' => ['force_sheets' => ['network', 'audio', 'control', 'video']],
        ]);
        $sheets = $this->paginator->classify($project);
        // Order MUST be: system_overview, audio, video, control, network (insertion order locked)
        $this->assertSame(
            ['system_overview', 'audio', 'video', 'control', 'network'],
            array_column($sheets, 'key'),
        );
    }
}
```

Commit RED: `git commit -am "test(23-04): RED — SheetPaginator D-06 threshold + force_sheets override"`

**Step 2 — Write `app/Services/Drawings/SheetPaginator.php`:**

```php
<?php

namespace App\Services\Drawings;

use App\Models\Project;
use Illuminate\Support\Facades\Log;

/**
 * Phase 23 — Multi-sheet paginator (DRAW-47).
 *
 * Classifies the project into a list of sheets. Always emits system_overview;
 * audio/video/control/network sub-sheets emit only when BOTH thresholds met
 * per D-06 (≥config('drawings.sub_sheet_thresholds.min_cables_per_signal')
 * cables AND ≥min_devices_touching_signal devices).
 *
 * Engineer tinker override: Project.metadata.force_sheets = ['audio', ...]
 * forces sub-sheets regardless of threshold (D-06 deferred-UI escape hatch;
 * Phase 24 ships the proper toggle UI).
 *
 * Pure read function — NO Eloquent writes.
 *
 * @see .planning/phases/23-xten-av-style-renderer/23-CONTEXT.md D-06
 */
class SheetPaginator
{
    private const VALID_SUB_SHEETS = ['audio', 'video', 'control', 'network'];

    /**
     * @return array<int, array{key: string, sheet_number: string, title: string, signal_filter: ?string}>
     */
    public function classify(Project $project): array
    {
        $sheets = [];
        $sheets[] = [
            'key'           => 'system_overview',
            'sheet_number'  => (string) (config('drawings.sheet_number_format.system_overview') ?? 'AV-201'),
            'title'         => 'System Overview',
            'signal_filter' => null,
        ];

        $forced = $this->forcedSheets($project);
        $thresholds = (array) config('drawings.sub_sheet_thresholds', []);
        $minCables = (int) ($thresholds['min_cables_per_signal'] ?? 5);
        $minDevices = (int) ($thresholds['min_devices_touching_signal'] ?? 3);

        $project->loadMissing([
            'cableSchedules.items.sourcePort',
            'cableSchedules.items.destPort',
        ]);

        // Maintain deterministic order: audio → video → control → network
        foreach (self::VALID_SUB_SHEETS as $signal) {
            $emit = in_array($signal, $forced, true)
                || $this->meetsThreshold($project, $signal, $minCables, $minDevices);

            if (! $emit) {
                continue;
            }

            $sheets[] = [
                'key'           => $signal,
                'sheet_number'  => (string) (config("drawings.sheet_number_format.{$signal}") ?? 'AV-2XX'),
                'title'         => ucfirst($signal) . ' Subsystem',
                'signal_filter' => $signal,
            ];
        }

        return $sheets;
    }

    /**
     * @return array<int, string>
     */
    private function forcedSheets(Project $project): array
    {
        $raw = $project->metadata['force_sheets'] ?? null;
        if (! is_array($raw)) {
            if ($raw !== null) {
                Log::warning('SheetPaginator: force_sheets is not an array — ignoring', [
                    'project_id' => $project->id,
                    'value'      => is_scalar($raw) ? (string) $raw : gettype($raw),
                ]);
            }
            return [];
        }

        $valid = [];
        foreach ($raw as $entry) {
            if (! is_string($entry)) {
                continue;
            }
            if (in_array($entry, self::VALID_SUB_SHEETS, true)) {
                $valid[] = $entry;
            }
        }
        return $valid;
    }

    private function meetsThreshold(Project $project, string $signal, int $minCables, int $minDevices): bool
    {
        $items = $project->cableSchedules->flatMap(fn ($s) => $s->items);

        $cableCount = 0;
        $deviceIds = [];
        foreach ($items as $item) {
            $srcSignal = $item->sourcePort?->signal_type;
            $dstSignal = $item->destPort?->signal_type;
            $touches = $srcSignal === $signal || $dstSignal === $signal;
            if (! $touches) {
                continue;
            }
            if ($item->source_device_id === null && $item->dest_device_id === null) {
                continue;
            }
            $cableCount++;
            if ($item->source_device_id !== null) { $deviceIds[$item->source_device_id] = true; }
            if ($item->dest_device_id !== null)   { $deviceIds[$item->dest_device_id]   = true; }
        }

        return $cableCount >= $minCables && count($deviceIds) >= $minDevices;
    }
}
```

**Step 3 — Run GREEN + invariants:**
```
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l app/Services/Drawings/SheetPaginator.php
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l tests/Feature/Drawings/SheetPaginatorTest.php
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test --filter=SheetPaginatorTest --stop-on-failure
git diff --stat app/Services/Drawings/SchematicGeneratorService.php app/Services/Drawings/SchematicD2SourceBuilder.php app/Services/Drawings/DrawingDataResolverService.php app/Services/Drawings/BoundPdfBuilderService.php app/Services/Drawings/DrawingExportRendererService.php
git diff --stat app/Services/Drawings/DrawIoBuilderService.php config/cables.php
```

**Step 4 — Commit:**
```
git add app/Services/Drawings/SheetPaginator.php
git commit -m "feat(23-04): SheetPaginator — DRAW-47 + D-06 threshold + force_sheets override"
```
  </action>
  <verify>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l app/Services/Drawings/SheetPaginator.php</automated>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l tests/Feature/Drawings/SheetPaginatorTest.php</automated>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test --filter=SheetPaginatorTest --stop-on-failure</automated>
  </verify>
  <acceptance_criteria>
    - `app/Services/Drawings/SheetPaginator.php` exists
    - `php artisan test --filter=SheetPaginatorTest` exits 0 (8 tests pass)
    - `grep -c "config('drawings.sub_sheet_thresholds" app/Services/Drawings/SheetPaginator.php` returns ≥1
    - `grep -c "force_sheets" app/Services/Drawings/SheetPaginator.php` returns ≥1 (D-06 override)
    - `grep -c "AIManager\|AICache\|->update\|->save\|::create" app/Services/Drawings/SheetPaginator.php` returns 0
    - `git diff --stat app/Services/Drawings/SchematicGeneratorService.php app/Services/Drawings/SchematicD2SourceBuilder.php app/Services/Drawings/DrawingDataResolverService.php app/Services/Drawings/BoundPdfBuilderService.php app/Services/Drawings/DrawingExportRendererService.php` returns empty
    - `git diff --stat config/cables.php` returns empty
    - `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l app/Services/Drawings/SheetPaginator.php` prints "No syntax errors"
  </acceptance_criteria>
  <done>SheetPaginator + 8 green tests; v1.3 invariants intact.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: TitleBlockRenderer — 8-field title block per sheet (DRAW-48, D-08 sources)</name>
  <files>
    app/Services/Drawings/TitleBlockRenderer.php,
    tests/Feature/Drawings/TitleBlockRendererTest.php
  </files>
  <read_first>
    - .planning/phases/23-xten-av-style-renderer/23-CONTEXT.md D-08 (lines 95-105) — 8-field source resolution table
    - .planning/phases/23-xten-av-style-renderer/23-RESEARCH.md §"Example 6" (lines 599-615) — title block mxCell pattern
    - app/Models/Project.php (Phase 23 Plan 01 — metadata cast)
    - app/Models/ProjectDrawing.php (full file — `version` column the title block reads for revision)
    - app/Services/Drawings/DrawIoBuilderService.php (xml() helper at lines 400-415)
  </read_first>
  <behavior>
    - `render(array $sheet, Project $project, ?ProjectDrawing $drawing = null): array<int, array<string,mixed>>` returns 8 mxCell descriptors for one sheet
    - Field source resolution (per D-08):
      - `project` → `Project.name` (escape, truncate to 80 chars if longer)
      - `client` → `Project.client_name` (escape)
      - `sheet #` → `$sheet['sheet_number']` (from SheetPaginator)
      - `date` → `now()->format('Y-m-d')` (frozen in tests via Carbon::setTestNow per Plan 01 Task 3 harness)
      - `revision` → `$drawing?->version` (Phase 21 ProjectDrawing model) or `'R0'` if null
      - `designed-by` → `Auth::user()?->name` or `'—'` if null
      - `drawn-by` → same as designed-by
      - `checked-by` → `Project.metadata['drawing_checked_by']` or `'—'` if null
    - Title-block row at `y = config('drawings.page_dimensions.title_block_y')` (default 940)
    - Each field gets `kind = 'title-block-field'`, distinct `id`, mxCell value `"{Label}: {value}"` style text-only (transparent)
  </behavior>
  <action>
**Step 1 — TDD RED — write `tests/Feature/Drawings/TitleBlockRendererTest.php`:**

```php
<?php

namespace Tests\Feature\Drawings;

use App\Models\Project;
use App\Models\ProjectDrawing;
use App\Models\User;
use App\Services\Drawings\TitleBlockRenderer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TitleBlockRendererTest extends TestCase
{
    use RefreshDatabase;

    private TitleBlockRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-05-13 12:00:00');
        $this->renderer = app(TitleBlockRenderer::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sheet(string $number = 'AV-201'): array
    {
        return ['key' => 'system_overview', 'sheet_number' => $number, 'title' => 'System Overview', 'signal_filter' => null];
    }

    public function test_title_block_emits_eight_fields(): void
    {
        $project = Project::factory()->create();
        $cells = $this->renderer->render($this->sheet(), $project);
        $this->assertCount(8, $cells);
        $allTitleBlock = collect($cells)->every(fn ($c) => $c['kind'] === 'title-block-field');
        $this->assertTrue($allTitleBlock);
    }

    public function test_title_block_field_sources(): void
    {
        $project = Project::factory()->create([
            'name' => 'Acme Boardroom Refurb',
            'client_name' => 'Acme Ltd',
        ]);
        $cells = $this->renderer->render($this->sheet('AV-201'), $project);
        $values = collect($cells)->pluck('value')->all();

        $this->assertContains('Project: Acme Boardroom Refurb', $values);
        $this->assertContains('Client: Acme Ltd', $values);
        $this->assertContains('Sheet: AV-201', $values);
        $this->assertContains('Date: 2026-05-13', $values);
    }

    public function test_checked_by_fallback_to_dash(): void
    {
        $project = Project::factory()->create(['metadata' => null]);
        $cells = $this->renderer->render($this->sheet(), $project);
        $values = collect($cells)->pluck('value')->all();
        $this->assertContains('Checked by: —', $values);
    }

    public function test_checked_by_reads_metadata(): void
    {
        $project = Project::factory()->create([
            'metadata' => ['drawing_checked_by' => 'Bob Reviewer'],
        ]);
        $cells = $this->renderer->render($this->sheet(), $project);
        $values = collect($cells)->pluck('value')->all();
        $this->assertContains('Checked by: Bob Reviewer', $values);
    }

    public function test_designed_by_falls_back_to_dash_when_no_user(): void
    {
        $project = Project::factory()->create();
        $cells = $this->renderer->render($this->sheet(), $project);
        $values = collect($cells)->pluck('value')->all();
        $this->assertContains('Designed by: —', $values);
        $this->assertContains('Drawn by: —', $values);
    }

    public function test_designed_by_reads_auth_user_name(): void
    {
        $user = User::factory()->create(['name' => 'Alice Engineer']);
        $this->actingAs($user);
        $project = Project::factory()->create();
        $cells = $this->renderer->render($this->sheet(), $project);
        $values = collect($cells)->pluck('value')->all();
        $this->assertContains('Designed by: Alice Engineer', $values);
        $this->assertContains('Drawn by: Alice Engineer', $values);
    }

    public function test_revision_falls_back_to_r0(): void
    {
        $project = Project::factory()->create();
        $cells = $this->renderer->render($this->sheet(), $project, null);
        $values = collect($cells)->pluck('value')->all();
        $this->assertContains('Rev: R0', $values);
    }

    public function test_revision_reads_drawing_version(): void
    {
        $project = Project::factory()->create();
        $drawing = ProjectDrawing::factory()->create(['project_id' => $project->id, 'version' => 3]);
        $cells = $this->renderer->render($this->sheet(), $project, $drawing);
        $values = collect($cells)->pluck('value')->all();
        $this->assertContains('Rev: 3', $values);
    }

    public function test_xss_escaped_in_project_name(): void
    {
        $project = Project::factory()->create(['name' => '<script>alert(1)</script>']);
        $cells = $this->renderer->render($this->sheet(), $project);
        $projectField = collect($cells)->firstWhere(fn ($c) => str_starts_with($c['value'], 'Project:'));
        $this->assertStringNotContainsString('<script>', $projectField['value']);
        $this->assertStringContainsString('&lt;script&gt;', $projectField['value']);
    }

    public function test_title_block_y_from_config(): void
    {
        config()->set('drawings.page_dimensions.title_block_y', 940);
        $project = Project::factory()->create();
        $cells = $this->renderer->render($this->sheet(), $project);
        foreach ($cells as $c) {
            $this->assertSame(940, $c['y']);
        }
    }
}
```

Commit RED: `git commit -am "test(23-04): RED — TitleBlockRenderer 8-field DRAW-48"`

**Step 2 — Write `app/Services/Drawings/TitleBlockRenderer.php`:**

```php
<?php

namespace App\Services\Drawings;

use App\Models\Project;
use App\Models\ProjectDrawing;
use Illuminate\Support\Facades\Auth;

/**
 * Phase 23 — Title block renderer (DRAW-48).
 *
 * Emits 8 mxCell text descriptors per sheet at the bottom of the page,
 * with field values resolved per CONTEXT D-08:
 *   project     → Project.name
 *   client      → Project.client_name
 *   sheet #     → $sheet['sheet_number']
 *   date        → now()->format('Y-m-d')  — freeze via Carbon::setTestNow in tests
 *   revision    → $drawing->version (or 'R0' if null)
 *   designed-by → Auth::user()->name (or '—' if null)
 *   drawn-by    → same as designed-by
 *   checked-by  → Project.metadata.drawing_checked_by (or '—')
 *
 * Pure read function — NO Eloquent writes.
 *
 * @see .planning/phases/23-xten-av-style-renderer/23-CONTEXT.md D-08
 */
class TitleBlockRenderer
{
    private const FIELD_STYLE = 'text;html=1;align=left;verticalAlign=middle;strokeColor=none;fillColor=none;fontSize=10;fontColor=#333333;';
    private const FIELD_WIDTH = 200;
    private const FIELD_HEIGHT = 20;
    private const FIELD_START_X = 60;
    private const FIELD_GAP = 30;

    /**
     * @param  array{key: string, sheet_number: string, title: string, signal_filter: ?string}  $sheet
     * @return array<int, array<string, mixed>>
     */
    public function render(array $sheet, Project $project, ?ProjectDrawing $drawing = null): array
    {
        $y = (int) (config('drawings.page_dimensions.title_block_y') ?? 940);

        $userName = Auth::user()?->name ?? '—';
        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $checkedBy = (string) ($metadata['drawing_checked_by'] ?? '—');
        if ($checkedBy === '') {
            $checkedBy = '—';
        }
        $revision = $drawing?->version !== null ? (string) $drawing->version : 'R0';

        $fields = [
            ['label' => 'Project',     'value' => (string) $project->name],
            ['label' => 'Client',      'value' => (string) $project->client_name],
            ['label' => 'Designed by', 'value' => $userName],
            ['label' => 'Drawn by',    'value' => $userName],
            ['label' => 'Checked by',  'value' => $checkedBy],
            ['label' => 'Sheet',       'value' => (string) $sheet['sheet_number']],
            ['label' => 'Date',        'value' => now()->format('Y-m-d')],
            ['label' => 'Rev',         'value' => $revision],
        ];

        $cells = [];
        foreach ($fields as $i => $field) {
            $cells[] = [
                'kind'   => 'title-block-field',
                'id'     => 'tb-' . $sheet['key'] . '-' . $i,
                'value'  => $this->xml($field['label'] . ': ' . $field['value']),
                'style'  => self::FIELD_STYLE,
                'parent' => '1',
                'x'      => self::FIELD_START_X + $i * (self::FIELD_WIDTH + self::FIELD_GAP),
                'y'      => $y,
                'w'      => self::FIELD_WIDTH,
                'h'      => self::FIELD_HEIGHT,
            ];
        }

        return $cells;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
```

**Step 3 — Run GREEN + invariants:**
```
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l app/Services/Drawings/TitleBlockRenderer.php
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l tests/Feature/Drawings/TitleBlockRendererTest.php
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test --filter=TitleBlockRendererTest --stop-on-failure
git diff --stat app/Services/Drawings/SchematicGeneratorService.php app/Services/Drawings/SchematicD2SourceBuilder.php app/Services/Drawings/DrawingDataResolverService.php app/Services/Drawings/BoundPdfBuilderService.php app/Services/Drawings/DrawingExportRendererService.php
```

**Step 4 — Commit:**
```
git add app/Services/Drawings/TitleBlockRenderer.php
git commit -m "feat(23-04): TitleBlockRenderer — DRAW-48 8-field title block per D-08"
```
  </action>
  <verify>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l app/Services/Drawings/TitleBlockRenderer.php</automated>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l tests/Feature/Drawings/TitleBlockRendererTest.php</automated>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test --filter=TitleBlockRendererTest --stop-on-failure</automated>
  </verify>
  <acceptance_criteria>
    - `app/Services/Drawings/TitleBlockRenderer.php` exists
    - `php artisan test --filter=TitleBlockRendererTest` exits 0 (10 tests pass)
    - `grep -c "Auth::user" app/Services/Drawings/TitleBlockRenderer.php` returns ≥1 (D-08 designed-by source)
    - `grep -c "drawing_checked_by" app/Services/Drawings/TitleBlockRenderer.php` returns ≥1 (D-08 checked-by source)
    - `grep -c "htmlspecialchars" app/Services/Drawings/TitleBlockRenderer.php` returns ≥1 (T-23-04-A1)
    - `grep -c "AIManager\|AICache\|->update\|->save\|::create" app/Services/Drawings/TitleBlockRenderer.php` returns 0
    - `git diff --stat app/Services/Drawings/SchematicGeneratorService.php app/Services/Drawings/SchematicD2SourceBuilder.php app/Services/Drawings/DrawingDataResolverService.php app/Services/Drawings/BoundPdfBuilderService.php app/Services/Drawings/DrawingExportRendererService.php` returns empty
    - `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l app/Services/Drawings/TitleBlockRenderer.php` prints "No syntax errors"
  </acceptance_criteria>
  <done>TitleBlockRenderer + 10 green tests; D-08 source resolution verified.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: SheetBorderRenderer — dashed border per page (DRAW-49)</name>
  <files>
    app/Services/Drawings/SheetBorderRenderer.php,
    tests/Feature/Drawings/SheetBorderRendererTest.php
  </files>
  <read_first>
    - .planning/phases/23-xten-av-style-renderer/23-CONTEXT.md (DRAW-49 line — uniform dashed border on every page)
    - .planning/phases/23-xten-av-style-renderer/23-RESEARCH.md §"Example 7" (lines 619-627) — dashed border XML
    - config/drawings.php (page_dimensions.border_inset from Plan 01 Task 3)
  </read_first>
  <behavior>
    - `render(): array<int, array<string,mixed>>` returns a single-element array with one dashed border mxCell descriptor
    - Geometry: x=insets, y=insets, w=pageWidth-2*insets, h=pageHeight-2*insets — read from `config('drawings.page_dimensions')`
    - Style includes `dashed=1`, `dashPattern=8 4`, `fillColor=none`, `strokeColor=#1B7A7A` (brand teal), `strokeWidth=1.5`
    - Deterministic — same config → same descriptor
  </behavior>
  <action>
**Step 1 — TDD RED — write `tests/Feature/Drawings/SheetBorderRendererTest.php`:**

```php
<?php

namespace Tests\Feature\Drawings;

use App\Services\Drawings\SheetBorderRenderer;
use Tests\TestCase;

class SheetBorderRendererTest extends TestCase
{
    public function test_emits_one_border_cell(): void
    {
        $cells = app(SheetBorderRenderer::class)->render();
        $this->assertCount(1, $cells);
        $this->assertSame('border', $cells[0]['kind']);
    }

    public function test_border_geometry_inset_from_page_bounds(): void
    {
        config()->set('drawings.page_dimensions', [
            'width' => 1600, 'height' => 1000, 'border_inset' => 20, 'title_block_y' => 940,
        ]);
        $cells = app(SheetBorderRenderer::class)->render();
        $this->assertSame(20, $cells[0]['x']);
        $this->assertSame(20, $cells[0]['y']);
        $this->assertSame(1560, $cells[0]['w']); // 1600 - 2*20
        $this->assertSame(960, $cells[0]['h']);  // 1000 - 2*20
    }

    public function test_border_style_is_dashed(): void
    {
        $cells = app(SheetBorderRenderer::class)->render();
        $this->assertStringContainsString('dashed=1', $cells[0]['style']);
        $this->assertStringContainsString('fillColor=none', $cells[0]['style']);
        $this->assertStringContainsString('strokeColor=#1B7A7A', $cells[0]['style']);
    }

    public function test_render_is_deterministic(): void
    {
        $a = app(SheetBorderRenderer::class)->render();
        $b = app(SheetBorderRenderer::class)->render();
        $this->assertSame($a, $b);
    }
}
```

Commit RED: `git commit -am "test(23-04): RED — SheetBorderRenderer DRAW-49"`

**Step 2 — Write `app/Services/Drawings/SheetBorderRenderer.php`:**

```php
<?php

namespace App\Services\Drawings;

/**
 * Phase 23 — Sheet border renderer (DRAW-49).
 *
 * Emits a single dashed-border mxCell that wraps the page bounds. One per
 * sheet inside the <mxfile> wrapper. Geometry insets per
 * config('drawings.page_dimensions.border_inset').
 *
 * Pure deterministic function — no input dependencies.
 *
 * @see .planning/phases/23-xten-av-style-renderer/23-RESEARCH.md Example 7
 */
class SheetBorderRenderer
{
    private const BORDER_STYLE = 'rounded=0;dashed=1;dashPattern=8 4;fillColor=none;strokeColor=#1B7A7A;strokeWidth=1.5;';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function render(): array
    {
        $page = (array) config('drawings.page_dimensions', []);
        $width  = (int) ($page['width']  ?? 1600);
        $height = (int) ($page['height'] ?? 1000);
        $inset  = (int) ($page['border_inset'] ?? 20);

        return [
            [
                'kind'   => 'border',
                'id'     => 'page-border',
                'value'  => '',
                'style'  => self::BORDER_STYLE,
                'parent' => '1',
                'x'      => $inset,
                'y'      => $inset,
                'w'      => $width  - 2 * $inset,
                'h'      => $height - 2 * $inset,
            ],
        ];
    }
}
```

**Step 3 — Run GREEN + invariants:**
```
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l app/Services/Drawings/SheetBorderRenderer.php
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test --filter=SheetBorderRendererTest --stop-on-failure
git diff --stat app/Services/Drawings/SchematicGeneratorService.php app/Services/Drawings/SchematicD2SourceBuilder.php app/Services/Drawings/DrawingDataResolverService.php app/Services/Drawings/BoundPdfBuilderService.php app/Services/Drawings/DrawingExportRendererService.php
```

**Step 4 — Commit:**
```
git add app/Services/Drawings/SheetBorderRenderer.php
git commit -m "feat(23-04): SheetBorderRenderer — DRAW-49 dashed border per page"
```
  </action>
  <verify>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l app/Services/Drawings/SheetBorderRenderer.php</automated>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test --filter=SheetBorderRendererTest --stop-on-failure</automated>
  </verify>
  <acceptance_criteria>
    - `app/Services/Drawings/SheetBorderRenderer.php` exists
    - `php artisan test --filter=SheetBorderRendererTest` exits 0 (4 tests pass)
    - `grep -c "dashed=1" app/Services/Drawings/SheetBorderRenderer.php` returns ≥1
    - `git diff --stat app/Services/Drawings/SchematicGeneratorService.php app/Services/Drawings/SchematicD2SourceBuilder.php app/Services/Drawings/DrawingDataResolverService.php app/Services/Drawings/BoundPdfBuilderService.php app/Services/Drawings/DrawingExportRendererService.php` returns empty
    - `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l app/Services/Drawings/SheetBorderRenderer.php` prints "No syntax errors"
  </acceptance_criteria>
  <done>SheetBorderRenderer + 4 green tests; DRAW-49 deliverable shipped.</done>
</task>

</tasks>

<verification>
- All 3 tasks committed atomically (TDD RED → GREEN per task)
- `php artisan test --filter='SheetPaginator|TitleBlockRenderer|SheetBorderRenderer'` exits 0 (~22 tests)
- `git diff --stat` on the 5 v1.3 invariant files returns empty
- `git diff --stat app/Services/Drawings/DrawIoBuilderService.php config/cables.php` returns empty
- `grep -rE "AIManager|AICache|->update\(|->save\(|::create\(" app/Services/Drawings/SheetPaginator.php app/Services/Drawings/TitleBlockRenderer.php app/Services/Drawings/SheetBorderRenderer.php` returns empty
</verification>

<success_criteria>
Plan 05 (DrawIoBuilderService orchestrator) calls these three classes in order per sheet:
1. `app(SheetPaginator::class)->classify($project)` → array of sheets
2. For each sheet: emit border via `app(SheetBorderRenderer::class)->render()` + title-block via `app(TitleBlockRenderer::class)->render($sheet, $project, $drawing)`
3. Combined with Plan 02 layout + Plan 03 cable cells (filtered per sheet's `signal_filter`), wraps the whole thing in `<mxfile><diagram>` per sheet

Plan 07 (verification) confirms Open Question 3 — the existing draw.io v29.7.12 embed iframe renders multi-page `<mxfile>` payloads with tab UX. Browser UAT task in Plan 07.
</success_criteria>

<output>
After completion, create `.planning/phases/23-xten-av-style-renderer/23-04-SUMMARY.md` documenting:
- D-06 threshold logic verbatim (min_cables_per_signal=5 AND min_devices_touching_signal=3 — both required)
- Force_sheets override semantics + validation rules (invalid entries logged + ignored)
- D-08 8-field title block source resolution table verbatim
- DRAW-49 single border cell per sheet
- Decision IDs implemented: D-06 (paginator policy + force_sheets), D-08 (title block sources), D-09 (no `rams_` prefix verified)
- Test count + assertions (~22 total across 3 tasks)
- T-23-04-A1 + T-23-04-A2 XSS mitigations verified
- Title-block field `Y` coordinate reads from `config('drawings.page_dimensions.title_block_y')`

End with the 🚨 "Files to upload to live" section listing:
- `app/Services/Drawings/SheetPaginator.php`
- `app/Services/Drawings/TitleBlockRenderer.php`
- `app/Services/Drawings/SheetBorderRenderer.php`
- Note: no migration. Plan 01 already added the metadata column + config keys these read.
</output>