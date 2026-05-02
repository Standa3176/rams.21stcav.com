---
phase: 18-rack-elevations
plan: 03
type: execute
wave: 2
depends_on:
  - 18-01
files_modified:
  - app/Services/Drawings/RackElevationRenderService.php
  - app/Services/Drawings/DrawingExportRendererService.php
  - app/Http/Controllers/ProjectDrawingController.php
  - resources/views/projects/drawings/rack-edit.blade.php
  - resources/views/projects/drawings/show.blade.php
  - resources/views/pdf/drawings/rack.blade.php
  - resources/js/rack-editor.js
  - resources/js/app.js
  - vite.config.js
  - package.json
  - routes/web.php
  - tests/Feature/Drawings/RackElevationRenderServiceTest.php
  - tests/Feature/Drawings/RackEditorEndpointsTest.php
autonomous: true
requirements:
  - DRAW-07
  - DRAW-08
  - DRAW-09
  - DRAW-10
  - DRAW-11
  - DRAW-12
  - DRAW-13
requirements_notes:
  - "DRAW-09 (partial — palette ordering only at the resolver layer + bottom-up u_position rendering; AVIXA auto-ordering algorithm deferred per CONTEXT.md decision, lands in v1.3.x/v2.0)"

plan_metadata:
  scope_note: |
    Plan size sits at the borderline of GSD scope sanity (3 tasks, ~13 files,
    ~900 net LOC). Accepted as-is because Task 3 is JS-only with low coupling
    to Tasks 1-2. If a single executor agent's context exceeds typical envelope
    during execution, Task 3 can be split into 3a (Sortable.js + Vite + package.json
    bootstrap) and 3b (Blade rack-edit view) as an in-flight adjustment. No
    structural change required upfront.

must_haves:
  truths:
    - "User can open a rack drawing's edit page (/projects/{p}/drawings/{d}/edit) and see an equipment palette on the left + 42U rack scaffold on the right with U-numbered rail (DRAW-08)"
    - "User sees palette equipment with is_rack_mounted=true grouped FIRST and other equipment SECOND (greyed but draggable)"
    - "User can click is_rack_mounted checkbox in the palette to flip an equipment item's flag (engineer-set, no auto-classification) — endpoint authorises against the Project (not a specific Drawing) so it works BEFORE the engineer creates their first rack"
    - "User can drag a palette item into a U-slot — Sortable.js DnD lands the item at that U-position; rack canvas state persists via AJAX POST to /drawings/{d}/rack-canvas"
    - "User can drag-reorder rack items; locked items don't move (DRAW-10 — per-item U-position lock toggle)"
    - "User clicks Save → server runs RackElevationRenderService synchronously (no BuildRackElevationJob), writes generated_svg to the row, flips status to ready"
    - "User can manage multiple racks per project (DRAW-11 — picker creates each rack as its own ProjectDrawing row; index lists them all)"
    - "User sees totals footer: Weight: X kg* (n/m known), Current: Y A* (n/m known), BTU: Z* (n/m known), U-utilisation: U/42U — asterisk + ratio when partial data (DRAW-12)"
    - "Equipment outside the manufacturer JSON pack renders with U-height unknown warning + 1U placeholder (CRIT-06: never silent guess)"
    - "User can download rack as PDF (landscape A4 via PdfRenderService::fromBlade) or SVG (direct write of generated_svg) — DRAW-13"
    - "DrawingExportRendererService no longer throws for kind=rack — bladeViewFor returns 'pdf.drawings.rack'"
    - "ProjectDrawingPolicy gates rack-edit + rack-canvas-save endpoints (owner OR admin); flipRackMountedFlag endpoint authorises against the Project (Phase 17 owner-OR-admin gate) since the Device-row mutation is conceptually project-owned, not drawing-owned"
    - "RackElevationRenderService::render(ProjectDrawing) returns a non-empty SVG string with U-numbered rail, equipment rectangles at correct U-positions, and a totals footer"
  artifacts:
    - path: "app/Services/Drawings/RackElevationRenderService.php"
      provides: "Synchronous SVG renderer — consumes ProjectDrawing.source_data.rack_items + DeviceCatalogService for partial-data fallback"
      exports: ["render"]
    - path: "resources/views/projects/drawings/rack-edit.blade.php"
      provides: "Rack editor UI — palette + 42U scaffold + Save button"
      min_lines: 100
    - path: "resources/views/pdf/drawings/rack.blade.php"
      provides: "PDF Blade view embedding generated_svg + title block"
      min_lines: 30
    - path: "resources/js/rack-editor.js"
      provides: "Sortable.js mount + AJAX save + lock toggle Alpine component"
    - path: "tests/Feature/Drawings/RackElevationRenderServiceTest.php"
      provides: "Asserts U-numbered rail + equipment positions + totals footer + asterisk on partial data + render-time budget"
    - path: "tests/Feature/Drawings/RackEditorEndpointsTest.php"
      provides: "Asserts edit page renders, rack-canvas POST persists + flips status, download routes work, Sortable cursor logic preserves locks through reorder+save"
  key_links:
    - from: "ProjectDrawingController::saveRackCanvas"
      to: "RackElevationRenderService::render"
      via: "synchronous render on save"
      pattern: "RackElevationRenderService"
    - from: "RackElevationRenderService"
      to: "DeviceCatalogService"
      via: "partial-data fallback for unknown parts"
      pattern: "DeviceCatalogService"
    - from: "DrawingExportRendererService::bladeViewFor"
      to: "pdf.drawings.rack"
      via: "kind=rack arm of the match expression"
      pattern: "pdf\\.drawings\\.rack"
    - from: "resources/views/projects/drawings/rack-edit.blade.php"
      to: "resources/js/rack-editor.js"
      via: "@vite + Alpine x-data"
      pattern: "rack-editor\\.js"
    - from: "resources/views/projects/drawings/show.blade.php"
      to: "rack-edit.blade.php"
      via: "Edit Rack button when kind=rack (existing SVG render branch already covers all kinds — Plan 18-03 only ADDS the button, does NOT modify the SVG render branch)"
      pattern: "drawings\\.rack-edit"
---

<objective>
Land the Phase 18 rack editor + render pipeline on top of Plan 18-01's foundations:
1. Synchronous server-side `RackElevationRenderService` (custom Blade SVG, ~150 LOC builder + helper) — consumes `ProjectDrawing.source_data.rack_items`, falls back to `DeviceCatalogService` for U-height/weight/current/BTU, surfaces "U-height unknown" warnings (CRIT-06), produces a totals footer with asterisks on partial metrics (DRAW-12).
2. Rack editor Blade view (`rack-edit.blade.php`) with palette (left, sorted is_rack_mounted-first) + 42U rack scaffold (right) + Sortable.js drag-into-U-slots + per-item U-position lock toggle (DRAW-10).
3. AJAX `POST /projects/{p}/drawings/{d}/rack-canvas` endpoint that validates JSON shape, writes to `source_data.rack_items`, runs `RackElevationRenderService::render` synchronously, persists generated_svg + flips status to READY.
4. Extend `DrawingExportRendererService::bladeViewFor` to handle `kind=rack` → returns `'pdf.drawings.rack'` (PDF + SVG + PNG download endpoints from Phase 17 light up automatically).
5. Add Sortable.js to package.json + new Vite entry for `resources/js/rack-editor.js`.

**Scope acknowledgement:** This plan sits at the borderline of GSD scope sanity (3 tasks, 13 files, ~900 net LOC). Accepted as-is because Task 3 is JS-only with low coupling to Tasks 1-2. If a single executor agent's context envelope is breached during execution, Task 3 may be split into 3a (Sortable.js + Vite + package.json bootstrap, 3 files) and 3b (Blade rack-edit view + show.blade.php button, 2 files) as an in-flight adjustment. No structural change required upfront.

Purpose: Phase 18 success criteria #1-5 all hinge on this plan. Engineer's day-to-day rack workflow: pick a rack → drag equipment in → save → share PDF/SVG with the install team. No AI, no D2, no Konva — pure Blade SVG (per CONTEXT.md custom Blade SVG renderer + ARCHITECTURE.md §4.2).

Output: rack drawings render correctly + are exportable as PDF/SVG, multi-rack-per-project works, totals footer is honest about partial data, and CRIT-06 is enforced (no silent 1U guesses for unknown parts).
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/STATE.md
@.planning/REQUIREMENTS.md
@.planning/phases/18-rack-elevations/18-CONTEXT.md
@.planning/phases/18-rack-elevations/18-01-picker-and-schema-PLAN.md
@.planning/research/SUMMARY.md
@.planning/research/STACK.md
@.planning/research/ARCHITECTURE.md
@.planning/research/PITFALLS.md
@./CLAUDE.md

# Phase 17 foundations + Plan 18-01 outputs
@app/Models/ProjectDrawing.php
@app/Models/Device.php
@app/Services/Drawings/DrawingService.php
@app/Services/Drawings/DrawingDataResolverService.php
@app/Services/Drawings/DrawingExportRendererService.php
@app/Services/Drawings/SchematicGeneratorService.php
@app/Services/PdfRenderService.php
@app/Services/DocumentArtifactStorage.php
@app/Http/Controllers/ProjectDrawingController.php
@app/Policies/ProjectDrawingPolicy.php
@resources/views/projects/drawings/index.blade.php
@resources/views/projects/drawings/show.blade.php
@routes/web.php
@vite.config.js
@package.json

<interfaces>
<!-- Contracts the executor needs without spelunking. -->

From app/Models/ProjectDrawing.php (Phase 17 — already shipped):
```
public const KIND_RACK = 'rack';
public const STATUS_DRAFT = 'draft';
public const STATUS_READY = 'ready';
public const STATUS_FAILED = 'failed';

protected $casts = ['source_data' => 'array', ...];
public function isRack(): bool;
public function isReady(): bool;
public function statusBadgeClass(): string;
public function revisionLabel(): string;
```

From app/Services/Drawings/DeviceCatalogService.php (Plan 18-01):
```php
public function lookupByPartNo(?string $partNo): ?array;
// Returns ['part_no', 'manufacturer', 'model', 'u_height', 'is_rack_mounted',
//          'current_draw_a', 'weight_kg', 'btu_per_hour'] or null.
```

From app/Services/Drawings/DrawingDataResolverService.php (Plan 18-01):
```php
public function rackStackForProject(Project $project): array;
// Returns ['palette' => array<int, [
//     'equipment_id', 'name', 'manufacturer', 'part_no', 'qty',
//     'u_height', 'is_rack_mounted',
//     'requires_ventilation_gap_above', 'requires_ventilation_gap_below',
// ]>]
// Plan 18-01 RackStackForProjectTest locks this exact shape — Plan 18-03 can rely on it
// without re-discovering the contract.
```

From app/Services/Drawings/DrawingExportRendererService.php (Phase 17 — needs extending):
```php
private function bladeViewFor(ProjectDrawing $drawing): string {
    return match ($drawing->kind) {
        ProjectDrawing::KIND_SCHEMATIC => 'pdf.drawings.schematic',
        ProjectDrawing::KIND_RACK => throw new RuntimeException(
            'DrawingExportRendererService: rack drawings land in Phase 18 (pdf.drawings.rack)'
        ),
        // ...
    };
}
// Plan 18-03 must replace the rack-arm throw with: => 'pdf.drawings.rack',
```

From app/Services/PdfRenderService.php (Phase 17):
```php
public function fromBlade(
    string $view, array $data, ?string $writeToPath = null, array $options = [],
): string;
// $options: 'marginTop|marginRight|marginBottom|marginLeft' (mm), 'waitForJs',
// 'headerHtml', 'footerHtml'.
// For rack: pass marginLeft/Right=10mm, no waitForJs (server-rendered SVG already in HTML),
// landscape via... DEFAULT IS A4 PORTRAIT — see Step 2.4 below for landscape A4 override.
```

From routes/web.php Phase 17 drawings block:
```php
Route::post('projects/{project}/drawings/picker', ...)->name('projects.drawings.picker');
Route::post('projects/{project}/drawings/create-rack', ...)->name('projects.drawings.create-rack');
// ...
Route::get('projects/{project}/drawings/{drawing}/download/{format}', ...)
    ->where('format', 'pdf|svg|png')
    ->name('projects.drawings.download');
// — Plan 18-03 ADDS BEFORE the {drawing} wildcard:
//   GET  /projects/{project}/drawings/{drawing}/edit          → ProjectDrawingController@editRack (kind=rack only)
//   POST /projects/{project}/drawings/{drawing}/rack-canvas   → ProjectDrawingController@saveRackCanvas
//   POST /projects/{project}/drawings/flip-rack-mounted       → ProjectDrawingController@flipRackMountedFlag (project-scoped, no drawing required)
```

From resources/views/projects/drawings/show.blade.php (Phase 17 — line ~55):
```blade
@if ($drawing->isReady() && ! empty($drawing->generated_svg))
    <div class="bg-white border border-gray-200 rounded-lg p-6 overflow-auto drawing-svg-container">
        {!! $drawing->generated_svg !!}
    </div>
@elseif ...
```
**This existing branch ALREADY covers kind=rack** (the conditional only checks status + generated_svg, kind-agnostic). Plan 18-03 only ADDS an "Edit Rack" button next to the existing kind-aware buttons — DO NOT modify the SVG render branch.

Rack source_data shape established by Plan 18-01:
```json
{
  "rack_meta": {
    "rack_label": "Rack 1",
    "rack_height_u": 42,
    "nominal_voltage_v": 230,
    "floor": null
  },
  "rack_items": [
    {
      "equipment_id": "AM-3200-GV",
      "name": "Crestron AirMedia 3200",
      "part_no": "AM-3200-GV",
      "u_position": 1,
      "u_height": 1.0,
      "locked": false,
      "current_draw_a": 0.5,
      "weight_kg": 1.8,
      "btu_per_hour": 60
    }
  ]
}
```
`u_position` is 1-indexed FROM THE BOTTOM (AVIXA convention — PDU bottom → patches top). U-numbering on the rail also goes 1-at-bottom.
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: RackElevationRenderService + tests + Blade PDF view + DrawingExportRendererService extension</name>
  <files>
    - app/Services/Drawings/RackElevationRenderService.php
    - app/Services/Drawings/DrawingExportRendererService.php
    - resources/views/pdf/drawings/rack.blade.php
    - tests/Feature/Drawings/RackElevationRenderServiceTest.php
  </files>
  <read_first>
    - app/Services/Drawings/SchematicGeneratorService.php (Phase 17 generator pattern — pre-flight guards, set status, write generated_svg)
    - app/Services/Drawings/DrawingExportRendererService.php (bladeViewFor — extend rack arm)
    - app/Services/Drawings/DrawingDataResolverService.php (palette + rackStackForProject from Plan 18-01)
    - app/Services/Drawings/DeviceCatalogService.php (Plan 18-01 lookup API)
    - tests/Feature/Drawings/SchematicGeneratorServiceTest.php (test pattern to mirror)
    - resources/views/pdf/drawings/schematic.blade.php (existing rack PDF view structure to mirror)
    - .planning/research/PITFALLS.md CRIT-06 (U-height accuracy — never silent 1U guess)
  </read_first>
  <behavior>
    - Test 1: render() throws RuntimeException for kind != KIND_RACK.
    - Test 2: A rack with rack_height_u=42 produces SVG containing 42 numbered rail labels (text "1" through "42") at correct y-positions (1U at bottom, 42U at top). Match via regex on text contents.
    - Test 3: A rack with one item at u_position=1, u_height=1.0 produces a `<rect>` at the bottom-most U slot. Item at u_position=10, u_height=2.0 produces a 2U-tall rect spanning U-10 + U-11.
    - Test 4: All known metrics produce a totals footer WITHOUT asterisks: "Weight: 1.8 kg" / "Current: 0.5 A" / "BTU: 60" / "U-utilisation: 1/42U".
    - Test 5: Mix of known + unknown parts produces asterisks + ratio: "Weight: 1.8 kg* (1/2 known)". Tooltip text lists unclassified device names.
    - Test 6: An item with NO u_height (DeviceCatalog has no entry) renders as 1U placeholder, BUT the device name is included in an "Unknown U-height" warning region (CRIT-06: warning surfaces, layout still proceeds with 1U placeholder).
    - Test 7: A locked item is annotated in SVG (e.g. `data-locked="true"` attribute or a small lock glyph). The render does NOT change a locked item's u_position regardless of input.
    - Test 8 (Warning 8 fix): Render-time budget — full 42U rack with 30 items completes in < 1 second.
  </behavior>
  <action>
**Step 1.1 — Write failing tests FIRST (RED):**

Create `tests/Feature/Drawings/RackElevationRenderServiceTest.php`. Mirror `SchematicGeneratorServiceTest.php` structure (use `RefreshDatabase`, build a Project + ProjectDrawing fixture inline). Cover all 8 behaviour tests above.

Each test:
- Builds a ProjectDrawing with kind='rack', status='draft' (NOT generating — rack flow doesn't go through STATUS_GENERATING; the render itself flips draft → ready).
- Sets source_data with rack_meta + rack_items per the Plan 18-01 shape.
- Calls `app(RackElevationRenderService::class)->render($drawing)`.
- Asserts on the returned SVG string.

Example assertion patterns:
```php
$this->assertStringContainsString('<svg', $svg);
$this->assertMatchesRegularExpression('/<text[^>]*>1<\/text>/', $svg);
$this->assertMatchesRegularExpression('/<text[^>]*>42<\/text>/', $svg);
$this->assertStringContainsString('Weight:', $svg);
$this->assertStringContainsString('1/2 known', $svg);
$this->assertStringContainsString('U-height unknown', $svg);
```

**Test 8 — Render-time budget (Warning 8 fix). 7-LOC test:**
```php
public function test_render_completes_within_one_second_for_full_rack(): void
{
    $project = Project::factory()->create();
    $drawing = ProjectDrawing::factory()->create([
        'project_id' => $project->id,
        'kind' => 'rack',
        'status' => 'draft',
        'source_data' => [
            'rack_meta' => ['rack_label' => 'Rack 1', 'rack_height_u' => 42, 'nominal_voltage_v' => 230, 'floor' => null],
            'rack_items' => array_map(fn($i) => [
                'equipment_id' => "EQ-{$i}",
                'name' => "Unit {$i}",
                'part_no' => "PN-{$i}",
                'u_position' => $i,
                'u_height' => 1.0,
                'locked' => false,
            ], range(1, 30)),
        ],
    ]);

    $start = microtime(true);
    app(\App\Services\Drawings\RackElevationRenderService::class)->render($drawing);
    $elapsed = microtime(true) - $start;

    // Generous 1s budget — catches gross regressions without flapping in CI.
    $this->assertLessThan(1.0, $elapsed,
        sprintf('Rack render took %.3fs — budget is 1.0s', $elapsed));
}
```

This test replaces the prior unsubstantiated "<500ms" claim. The verification block now states an actual measured budget rather than a wish.

Run `php artisan test --filter=RackElevationRenderServiceTest` — MUST fail (service doesn't exist yet).

**Step 1.2 — Build RackElevationRenderService (GREEN):**

Create `app/Services/Drawings/RackElevationRenderService.php`. Single public method `render(ProjectDrawing $drawing): string` returning a complete SVG document.

Layout constants:
```php
private const U_HEIGHT_PX = 24;            // 1U vertical pixel height
private const RACK_WIDTH_PX = 380;         // visual width of the rack frame
private const RAIL_LABEL_WIDTH_PX = 28;    // left rail "U number" column width
private const FOOTER_HEIGHT_PX = 90;       // totals footer below the rack
private const PADDING_PX = 16;
private const FONT_FAMILY = "'Helvetica Neue', Arial, sans-serif";
```

Architecture — three private methods compose the SVG:
1. `renderRail(int $heightU): string` — emits `<line>` + `<text>` rail labels for each U; 1 at the BOTTOM (`y = totalHeight - (1) * U_HEIGHT_PX`), heightU at the top.
2. `renderItems(array $items, int $heightU, array &$unknownDevices, array &$totals): string` — emits a `<rect>` + `<text>` per item. For each item:
   - If `u_height` is null → set to 1.0 (placeholder), append device name to `$unknownDevices` (CRIT-06 warning fed to footer).
   - Compute rect y: `y = totalRackHeightPx - ($item['u_position'] + $item['u_height'] - 1) * U_HEIGHT_PX`.
   - Compute rect height: `$item['u_height'] * U_HEIGHT_PX`.
   - Add `data-locked="true"` attribute if locked.
   - Sum into `$totals` (weight_kg, current_draw_a, btu_per_hour) — only when value is non-null. Track known/unknown counts per metric.
3. `renderFooter(array $totals, int $totalItems, int $heightU, int $usedU, array $unknownDevices): string` — emits totals lines:
   - `Weight: 1.8 kg* (1/2 known)` — asterisk only when `$totals['weight_known'] < $totalItems`.
   - `Current: 0.5 A* (1/2 known)`
   - `BTU: 60* (1/2 known)`
   - `U-utilisation: 1U / 42U`
   - When `$unknownDevices` non-empty, append `<title>` element with the joined names (SVG-native tooltip — works in browser; PDF render shows it as alt-text).
   - Above the totals, render an `<text>U-height unknown:</text>` warning row listing the unknown device names if `count($unknownDevices) > 0`.

Pre-flight guards (mirror SchematicGeneratorService::generate):
```php
if ($drawing->kind !== ProjectDrawing::KIND_RACK) {
    throw new \RuntimeException(
        "RackElevationRenderService::render: kind '{$drawing->kind}' is not 'rack'"
    );
}
$source = (array) ($drawing->source_data ?? []);
$meta = (array) ($source['rack_meta'] ?? []);
$items = (array) ($source['rack_items'] ?? []);
$heightU = (int) ($meta['rack_height_u'] ?? 42);
if ($heightU < 1 || $heightU > 99) {
    throw new \RuntimeException(
        "RackElevationRenderService::render: invalid rack_height_u={$heightU}"
    );
}
```

Constructor injects DeviceCatalogService (for fallback when an item's source_data has no u_height/weight/etc — the catalog can fill in):
```php
public function __construct(private readonly DeviceCatalogService $catalog) {}
```

Implement defensively — never silent-guess U-height (CRIT-06):
```php
foreach ($items as $item) {
    $partNo = (string) ($item['part_no'] ?? '');
    $catalogRow = $this->catalog->lookupByPartNo($partNo);

    // Resolve u_height: prefer item override, then catalog, then NULL → 1.0 placeholder + warning
    $uHeight = $item['u_height'] ?? $catalogRow['u_height'] ?? null;
    if ($uHeight === null) {
        $unknownDevices[] = (string) ($item['name'] ?? $partNo);
        $uHeight = 1.0; // PLACEHOLDER — rendered with warning, never silent
    }

    // Same fallback chain for weight / current / btu
    $weight  = $item['weight_kg']     ?? $catalogRow['weight_kg']     ?? null;
    $current = $item['current_draw_a'] ?? $catalogRow['current_draw_a'] ?? null;
    $btu     = $item['btu_per_hour']  ?? $catalogRow['btu_per_hour']  ?? null;
    // ... aggregate into $totals
}
```

Final SVG output is wrapped in:
```xml
<svg xmlns="http://www.w3.org/2000/svg" width="..." height="..." viewBox="...">
  <style>text { font-family: ...; }</style>
  <rect class="rack-frame" .../>
  {rail}
  {items}
  {footer}
</svg>
```

Total file ~150-200 LOC including constants + helpers. Mirror the cleanliness of SchematicD2SourceBuilder.

**Step 1.3 — Run tests (GREEN):**

`php artisan test --filter=RackElevationRenderServiceTest` — all 8 tests pass (including the 1s budget test).

**Step 1.4 — Build the PDF Blade view:**

Create `resources/views/pdf/drawings/rack.blade.php`. Mirrors `pdf/drawings/schematic.blade.php` shape (title block + embedded SVG + footer):
```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $drawing->rack_label ?? 'Rack' }} — {{ $drawing->project->name ?? '' }}</title>
    <style>
        @page { size: A4 landscape; margin: 12mm; }
        body { font-family: 'Helvetica Neue', Arial, sans-serif; color: #111; margin: 0; padding: 0; }
        .title-block { border: 1px solid #333; padding: 8px 12px; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; font-size: 11px; }
        .title-block .left { font-size: 13px; font-weight: 600; }
        .title-block .right { text-align: right; line-height: 1.4; }
        .rack-canvas { display: flex; justify-content: center; align-items: flex-start; }
        .rack-canvas svg { max-width: 100%; height: auto; }
    </style>
</head>
<body>
    <div class="title-block">
        <div class="left">
            {{ $drawing->rack_label ?? 'Rack' }}
            — {{ $drawing->project->name ?? '' }}
        </div>
        <div class="right">
            Project ref: {{ $drawing->project->ref ?? '—' }}<br>
            Revision: {{ $drawing->revisionLabel() }}
            · Drawn by: {{ $drawing->generatedBy?->name ?? '—' }}<br>
            Date: {{ ($drawing->updated_at ?? $drawing->created_at)?->format('d M Y') }}
        </div>
    </div>

    <div class="rack-canvas">
        @php($svg = (string) ($drawing->generated_svg ?? ''))
        @if ($svg !== '')
            {!! $svg !!}
        @else
            <p style="color: #c00; font-style: italic;">Rack has not been rendered yet — open the editor to build it.</p>
        @endif
    </div>
</body>
</html>
```

Note: `{!! $svg !!}` is INTENTIONAL here — generated_svg is server-built, never user-provided HTML. Per CONTEXT.md security note: "Sortable.js DnD — client-side only, no security implication beyond standard XSS hygiene on equipment names (use `{{ }}` blade escaping not `{!! !!}`)" — that rule applies to user-typed text. The SVG is service-emitted; equipment names are escaped INSIDE the SVG via `htmlspecialchars()` in RackElevationRenderService.

In RackElevationRenderService, every `<text>` content goes through `htmlspecialchars($name, ENT_XML1 | ENT_QUOTES, 'UTF-8')` — verify in tests.

**Step 1.5 — Extend DrawingExportRendererService::bladeViewFor:**

Replace the rack-arm throw:
```php
ProjectDrawing::KIND_RACK => 'pdf.drawings.rack',
```

The `renderPdf()`, `renderSvg()`, `renderPng()`, and `ensurePngForHandover()` paths automatically light up for rack drawings — no other changes needed.

For `renderPdf` specifically: rack PDFs are LANDSCAPE A4. The Blade view uses `@page { size: A4 landscape; }` so Browsershot honours it via `emulateMedia('print')` already set in PdfRenderService.

Run the renderer test suite to confirm no regressions:
```bash
php artisan test --filter='DrawingExportRendererService|RackElevationRenderService'
```
  </action>
  <acceptance_criteria>
    - `app/Services/Drawings/RackElevationRenderService.php` exists with public `render(ProjectDrawing): string`.
    - All 8 tests in `RackElevationRenderServiceTest` pass (including 1s render-time budget).
    - SVG output contains 42 numbered rail labels for a default 42U rack.
    - Items at u_position=1 are at the BOTTOM (highest y-coordinate).
    - Mixed-data totals footer includes asterisks + ratio (e.g. `(1/2 known)`).
    - Unknown-part items render with 1U placeholder AND surface a warning region listing them.
    - Equipment names are htmlspecialchars-escaped inside SVG text.
    - `pdf/drawings/rack.blade.php` renders with title block + landscape A4 + embedded `{!! $generated_svg !!}`.
    - `DrawingExportRendererService::bladeViewFor` returns `'pdf.drawings.rack'` for kind=rack (no longer throws).
    - All Phase 17 tests still green: `php artisan test --filter='Drawings'`.
  </acceptance_criteria>
  <verify>
    <automated>php artisan test --filter='RackElevationRenderServiceTest|DrawingExportRendererService|Drawings'</automated>
  </verify>
  <done>
    Synchronous rack renderer ships; PDF Blade view exists; export pipeline accepts rack kind. CRIT-06 enforced. Render-time budget asserted (1s for full 42U rack with 30 items). DRAW-08, DRAW-09 (partial — palette ordering only; auto-ordering algorithm deferred per CONTEXT.md), DRAW-12, DRAW-13 covered at the render-pipeline layer.
  </done>
</task>

<task type="auto">
  <name>Task 2: Rack editor controller actions + AJAX save endpoint + routes</name>
  <files>
    - app/Http/Controllers/ProjectDrawingController.php
    - app/Services/Drawings/DrawingExportRendererService.php
    - routes/web.php
    - tests/Feature/Drawings/RackEditorEndpointsTest.php
  </files>
  <read_first>
    - app/Http/Controllers/ProjectDrawingController.php (Plan 18-01 added picker + createRack)
    - app/Policies/ProjectDrawingPolicy.php (owner OR admin gate to mirror)
    - app/Policies/ProjectPolicy.php (Phase 17 — owner-OR-admin gate at the Project level; flipRackMountedFlag will use this)
    - app/Services/Drawings/RackElevationRenderService.php (Task 1 output — synchronous render)
    - routes/web.php (Phase 17 + Plan 18-01 drawings block)
  </read_first>
  <action>
**Step 2.1 — Add `editRack` controller action:**

```php
/**
 * Phase 18 — rack editor page. Engineer drags equipment from a palette
 * into U-slots; saves via AJAX. Synchronous render runs server-side on
 * each save.
 *
 * Route binds {drawing} via Eloquent. Policy gate enforces owner OR admin.
 * Cross-project URL tampering blocked by project_id match check.
 */
public function editRack(
    Project $project,
    ProjectDrawing $drawing,
    \App\Services\Drawings\DrawingDataResolverService $resolver,
): View {
    $this->authorize('update', $drawing);

    if ($drawing->project_id !== $project->id) {
        abort(404);
    }
    if (! $drawing->isRack()) {
        abort(404, 'editRack only handles kind=rack drawings.');
    }

    $rackStack = $resolver->rackStackForProject($project);
    // Group palette: is_rack_mounted=true first, then null/false.
    $rackMounted = [];
    $other = [];
    foreach ($rackStack['palette'] as $row) {
        if (($row['is_rack_mounted'] ?? null) === true) {
            $rackMounted[] = $row;
        } else {
            $other[] = $row;
        }
    }

    return view('projects.drawings.rack-edit', [
        'project' => $project,
        'drawing' => $drawing,
        'palette_rack_mounted' => $rackMounted,
        'palette_other' => $other,
    ]);
}
```

**Step 2.2 — Add `saveRackCanvas` AJAX endpoint:**

```php
/**
 * Phase 18 — AJAX save endpoint. Validates rack_items JSON shape, persists
 * to source_data, runs RackElevationRenderService synchronously, flips
 * status to READY. Failures return 422 with field-level errors.
 *
 * Threat model T-18.03-02: validate rack_items as a typed list — no
 * arbitrary keys, integer u_position, decimal u_height, string equipment
 * id/name/part_no. Reject anything else BEFORE writing.
 */
public function saveRackCanvas(
    Request $request,
    Project $project,
    ProjectDrawing $drawing,
    \App\Services\Drawings\RackElevationRenderService $renderer,
): \Illuminate\Http\JsonResponse {
    $this->authorize('update', $drawing);
    if ($drawing->project_id !== $project->id) {
        abort(404);
    }
    if (! $drawing->isRack()) {
        abort(422, 'saveRackCanvas only handles kind=rack drawings.');
    }

    $validated = $request->validate([
        'rack_meta' => ['required', 'array'],
        'rack_meta.rack_label' => ['required', 'string', 'max:120'],
        'rack_meta.rack_height_u' => ['required', 'integer', 'min:1', 'max:99'],
        'rack_meta.nominal_voltage_v' => ['nullable', 'integer', 'min:100', 'max:480'],
        'rack_meta.floor' => ['nullable', 'string', 'max:60'],
        'rack_items' => ['array'],
        'rack_items.*.equipment_id' => ['required', 'string', 'max:120'],
        'rack_items.*.name' => ['required', 'string', 'max:200'],
        'rack_items.*.part_no' => ['nullable', 'string', 'max:120'],
        'rack_items.*.u_position' => ['required', 'integer', 'min:1', 'max:99'],
        'rack_items.*.u_height' => ['nullable', 'numeric', 'min:0.5', 'max:42'],
        'rack_items.*.locked' => ['boolean'],
        'rack_items.*.weight_kg' => ['nullable', 'numeric', 'min:0', 'max:9999'],
        'rack_items.*.current_draw_a' => ['nullable', 'numeric', 'min:0', 'max:99'],
        'rack_items.*.btu_per_hour' => ['nullable', 'integer', 'min:0', 'max:99999'],
    ]);

    $drawing->source_data = array_merge(
        (array) ($drawing->source_data ?? []),
        [
            'rack_meta' => $validated['rack_meta'],
            'rack_items' => $validated['rack_items'] ?? [],
        ],
    );
    $drawing->rack_label = $validated['rack_meta']['rack_label'];

    try {
        $svg = $renderer->render($drawing);
        $drawing->generated_svg = $svg;
        $drawing->status = ProjectDrawing::STATUS_READY;
        $drawing->error_message = null;
    } catch (\Throwable $e) {
        $drawing->status = ProjectDrawing::STATUS_FAILED;
        $drawing->error_message = $e->getMessage();
        \Illuminate\Support\Facades\Log::error('ProjectDrawingController: rack render failed', [
            'drawing_id' => $drawing->id,
            'error' => $e->getMessage(),
        ]);
        $drawing->save();
        return response()->json([
            'ok' => false,
            'error' => $e->getMessage(),
        ], 500);
    }

    $drawing->save();

    return response()->json([
        'ok' => true,
        'drawing_id' => $drawing->id,
        'status' => $drawing->status,
        'updated_at' => $drawing->updated_at?->toIso8601String(),
    ]);
}
```

**Step 2.2.1 — Add `flipRackMountedFlag` endpoint (Blocker 2 fix — authorise against the Project, not a Drawing):**

CONTEXT.md "is_rack_mounted checkbox UX" allows the engineer to flip an equipment item's `is_rack_mounted` flag from EITHER the project-package review page OR the rack editor's palette. The flag is a property of the equipment within the project — it makes no sense to require an existing rack drawing to set it. The previous draft of this plan called `firstOrFail()` on the project's latest rack drawing, which 404s when no rack exists yet — making the endpoint unreachable from the project-package-review page (where Plan 18-01's picker silences the disabled option) and broken for the engineer's first rack on a project.

Authorise against the **Project** directly using the existing project-level policy (Phase 17's `ProjectPolicy::update` — owner OR admin):

```php
/**
 * Phase 18 — engineer marks (or unmarks) an equipment item as rack-mounted
 * from the palette OR from the project-package review page. CONTEXT.md:
 * "engineer-set via a checkbox in the palette OR the project-package review
 * page — NO automatic classification."
 *
 * Authorisation: owner OR admin on the PROJECT (not on any specific drawing).
 * The Device-row mutation is conceptually project-owned. This endpoint must
 * remain reachable BEFORE the engineer creates their first rack drawing.
 */
public function flipRackMountedFlag(
    Request $request,
    Project $project,
): \Illuminate\Http\JsonResponse {
    // Authorise against the Project itself — not a drawing. Use the existing
    // ProjectPolicy::update gate (owner-OR-admin), which mirrors the gate on
    // the project-package review page where this endpoint is also invoked.
    $this->authorize('update', $project);

    $partNo = (string) $request->input('part_no', '');
    $isRackMounted = (bool) $request->input('is_rack_mounted', false);
    if (trim($partNo) === '') {
        return response()->json(['ok' => false, 'error' => 'part_no required'], 422);
    }

    $updated = \App\Models\Device::query()
        ->where('project_id', $project->id)
        ->whereRaw('LOWER(TRIM(part_no)) = ?', [strtolower(trim($partNo))])
        ->update(['is_rack_mounted' => $isRackMounted]);

    \Illuminate\Support\Facades\Log::info('ProjectDrawingController: flipped is_rack_mounted', [
        'project_id' => $project->id,
        'part_no' => $partNo,
        'is_rack_mounted' => $isRackMounted,
        'rows_updated' => $updated,
    ]);

    return response()->json(['ok' => true, 'updated' => $updated]);
}
```

**Verification of the policy gate:** Confirm `app/Policies/ProjectPolicy.php` exists and registers an `update` method (owner-OR-admin). If not, add the policy in this task — but Phase 17 already shipped it for project-package review, so reuse.

NOTE: Devices with no row yet (quote-only project, no Device rows) won't flip via this endpoint — the palette item shows is_rack_mounted=null until the engineer first edits the project's devices. That's acceptable for v1.3; project-package review extension is a follow-up quick task per CONTEXT.md "Claude's Discretion".

**Step 2.3 — Wire routes:**

In `routes/web.php` Phase 17 drawings block, add BEFORE the existing `{drawing}` GET wildcard:
```php
Route::get('projects/{project}/drawings/{drawing}/edit',
    [ProjectDrawingController::class, 'editRack'])
    ->name('projects.drawings.edit');
Route::post('projects/{project}/drawings/{drawing}/rack-canvas',
    [ProjectDrawingController::class, 'saveRackCanvas'])
    ->middleware('throttle:60,1') // T-18.03-04 — rack-canvas spam DoS guard
    ->name('projects.drawings.rack-canvas');
Route::post('projects/{project}/drawings/flip-rack-mounted',
    [ProjectDrawingController::class, 'flipRackMountedFlag'])
    ->middleware('throttle:60,1')
    ->name('projects.drawings.flip-rack-mounted');
```

The `flip-rack-mounted` route is at the PROJECT scope (no `{drawing}` segment) — matches the Project-level authorisation in the controller. The `edit` route is generic — only kind=rack short-circuits via the controller guard. Phase 19 floor plan editor will add its own `editFloorPlan` controller action and update the `edit` route to dispatch by kind (deferred).

**Step 2.4 — Build feature test:**

Create `tests/Feature/Drawings/RackEditorEndpointsTest.php` covering:
- `test_edit_page_renders_for_rack_drawing` — 200 response, palette section visible, U-rail visible.
- `test_edit_page_404s_for_non_rack_drawing` — schematic drawing → 404.
- `test_edit_page_403s_for_non_owner_non_admin` — different user → 403.
- `test_save_rack_canvas_persists_items_and_renders` — POST valid payload → status flips to ready, generated_svg non-empty, rack_items persisted.
- `test_save_rack_canvas_validates_u_position_range` — POST with u_position=999 → 422.
- `test_save_rack_canvas_rejects_unknown_keys` — POST with rack_items[0].arbitrary_attack='<script>' → 422 (extra keys not in $request->validate are silently dropped, so assert the persisted rack_items doesn't contain it).
- `test_save_rack_canvas_with_locked_item_preserves_lock_through_save` — POST with locked=true → reload, locked still true.

**Test (Warning 7 fix) — Sortable cursor logic with locked items, server-round-trip:**

Extend `test_save_rack_canvas_with_locked_item_preserves_lock_through_save` (or add a sibling test) that simulates the JS cursor-walk for a 2U-after-locked-1U scenario:

```php
public function test_save_rack_canvas_locked_item_holds_position_when_others_reflow_around_it(): void
{
    // Layout sent BY the client AFTER the engineer drags the U-5 item to top:
    //   - U-1 (locked, 2U)   ← unchanged: lock holds, even if dragged across
    //   - U-3 (unlocked, 2U) ← was at U-3, stays
    //   - U-5 (unlocked, 1U) ← dragged from "top" — JS cursor logic places it
    //                          at next free U above the locked U-1 + reflow.
    //
    // Per the Sortable cursor walk in resources/js/rack-editor.js:
    //   - Locked items keep their u_position regardless of DOM order.
    //   - Unlocked items get u_position assigned by the cursor walking
    //     bottom-up over the reordered DOM.
    //
    // Expected SERVER-VALIDATED outcome (the client sends this; the server
    // persists it and re-renders):
    //   - locked 2U at u_position=1 (lock preserved)
    //   - unlocked 2U at u_position=3 (unchanged — reflowed around the lock)
    //   - unlocked 1U at u_position=5 (cursor: 1 + 2 + 0(reserved by lock skip) = 5
    //                                  per the rack-editor.js algorithm)
    //
    // This test asserts the server faithfully persists what the client sends
    // AND that the lock attribute survives the round-trip. The cursor algorithm
    // itself is in JS — server-side, we just validate that the client's
    // lock-aware ordering is faithfully written.

    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $drawing = ProjectDrawing::factory()->create([
        'project_id' => $project->id,
        'kind' => 'rack',
        'status' => 'draft',
        'source_data' => ['rack_meta' => [
            'rack_label' => 'Rack 1', 'rack_height_u' => 42,
            'nominal_voltage_v' => 230, 'floor' => null,
        ], 'rack_items' => []],
    ]);

    $payload = [
        'rack_meta' => [
            'rack_label' => 'Rack 1', 'rack_height_u' => 42,
            'nominal_voltage_v' => 230, 'floor' => null,
        ],
        'rack_items' => [
            ['equipment_id' => 'AMP', 'name' => 'Power Amp 2U',
             'part_no' => 'AMP-2U', 'u_position' => 1, 'u_height' => 2.0, 'locked' => true],
            ['equipment_id' => 'DSP', 'name' => 'DSP 2U',
             'part_no' => 'DSP-2U', 'u_position' => 3, 'u_height' => 2.0, 'locked' => false],
            ['equipment_id' => 'SW',  'name' => 'Switch 1U',
             'part_no' => 'SW-1U',  'u_position' => 5, 'u_height' => 1.0, 'locked' => false],
        ],
    ];

    $response = $this->actingAs($user)
        ->postJson(route('projects.drawings.rack-canvas', [$project, $drawing]), $payload);

    $response->assertOk();

    $drawing->refresh();
    $items = collect($drawing->source_data['rack_items']);

    // Locked item — position pinned at U-1 even though the client could have moved it.
    $locked = $items->firstWhere('equipment_id', 'AMP');
    $this->assertSame(1, $locked['u_position']);
    $this->assertTrue($locked['locked']);

    // Unlocked DSP — reflowed around the lock at U-3.
    $dsp = $items->firstWhere('equipment_id', 'DSP');
    $this->assertSame(3, $dsp['u_position']);
    $this->assertFalse($dsp['locked']);

    // Unlocked switch — cursor walk in JS placed it at U-5 (the next free
    // slot after the 2U DSP — 3 + 2 = 5).
    $sw = $items->firstWhere('equipment_id', 'SW');
    $this->assertSame(5, $sw['u_position']);
    $this->assertFalse($sw['locked']);

    // Status flipped to ready — render succeeded with mixed locked/unlocked items.
    $this->assertSame(ProjectDrawing::STATUS_READY, $drawing->status);
}
```

This test documents (in the docblock and in code) the expected cursor behaviour for the 2U-after-locked-1U case. The JS-side algorithm in `rack-editor.js` Step 3.2's `onRackReorder` has the actual cursor-walk; the test locks the server's contract for what the client sends.

- `test_flip_rack_mounted_updates_devices_table_for_matching_part_no` — POST → Device row's is_rack_mounted is the requested value. Specifically test the project-scope authorisation: a project owner (no rack drawings yet) can call this endpoint and 200s; a non-owner gets 403 even when the project HAS racks.
- `test_flip_rack_mounted_works_before_any_rack_drawing_exists` — Project has zero ProjectDrawings of kind=rack. Owner POSTs to flip-rack-mounted. Endpoint returns 200, Device row updated. (Regression test for the Blocker 2 fix.)
- `test_download_rack_pdf_works_after_save` — POST save → GET /download/pdf → 200 binary response (skip if Browsershot unavailable on CI; gate behind Storage::fake or env check).
  </action>
  <acceptance_criteria>
    - `php artisan route:list --name=drawings` lists projects.drawings.edit + projects.drawings.rack-canvas + projects.drawings.flip-rack-mounted.
    - GET `/projects/{p}/drawings/{rack}/edit` returns 200 for owner; 403 for non-owner; 404 for non-rack drawings.
    - POST `/projects/{p}/drawings/{rack}/rack-canvas` with valid payload writes source_data, runs render, flips status to ready in <2s.
    - Invalid payloads (out-of-range u_position, missing required fields) return 422 with field errors.
    - POST `/projects/{p}/drawings/flip-rack-mounted` works for an OWNER even when the project has ZERO rack drawings (Blocker 2 regression test).
    - `flipRackMountedFlag` authorises against `ProjectPolicy::update` (owner-OR-admin) — NOT against a specific Drawing's policy.
    - Locked-item lock-survives-reorder test passes (Warning 7).
    - All RackEditorEndpointsTest cases pass.
    - DrawingExportRendererService PDF/SVG download endpoints work for kind=rack drawings (verified via integration test or curl).
  </acceptance_criteria>
  <verify>
    <automated>php artisan test --filter='RackEditorEndpointsTest' && php artisan route:list --name=drawings | grep -E 'projects\.drawings\.(edit|rack-canvas|flip-rack-mounted)'</automated>
  </verify>
  <done>
    Editor page renders. AJAX save validates + renders + persists synchronously. Cross-project / non-owner access rejected. flipRackMountedFlag authorises against the Project (works before any rack exists; Blocker 2 fix). Locked items survive Sortable cursor walk through server round-trip (Warning 7).
  </done>
</task>

<task type="auto">
  <name>Task 3: Rack editor Blade view + Sortable.js + show.blade.php Edit Rack button + Vite entry</name>
  <files>
    - resources/views/projects/drawings/rack-edit.blade.php
    - resources/views/projects/drawings/show.blade.php
    - resources/js/rack-editor.js
    - vite.config.js
    - package.json
  </files>
  <read_first>
    - package.json (current deps; verify Sortable.js NOT installed)
    - vite.config.js (input array — add rack-editor.js entry)
    - resources/js/app.js (Alpine + axios setup pattern)
    - resources/views/projects/drawings/show.blade.php (verify line ~55 already has the kind-agnostic SVG render branch — Plan 18-03 ONLY adds the Edit Rack button next to existing kind-aware buttons)
    - resources/views/projects/drawings/index.blade.php (Tailwind class conventions to mirror)
  </read_first>
  <action>
**Step 3.1 — Add Sortable.js + new Vite entry:**

Edit `package.json` — add to dependencies:
```json
"sortablejs": "^1.15.6"
```
Run `npm install`. Verify installation: `npm ls sortablejs`.

Edit `vite.config.js` — add the new entry to the `input` array:
```js
input: [
    'resources/css/app.css',
    'resources/js/app.js',
    'resources/js/rack-editor.js',  // NEW — rack editor only
],
```

This keeps Sortable.js out of the global Alpine bundle. Only `/projects/{p}/drawings/{r}/edit` loads it.

**Step 3.2 — Build `resources/js/rack-editor.js`:**

```js
import Sortable from 'sortablejs';
import axios from 'axios';

window.rackEditor = function (initialState) {
    return {
        rackHeightU: initialState.rack_meta.rack_height_u || 42,
        rackLabel: initialState.rack_meta.rack_label || 'Rack 1',
        nominalVoltageV: initialState.rack_meta.nominal_voltage_v || 230,
        floor: initialState.rack_meta.floor || '',
        rackItems: initialState.rack_items || [],
        paletteRackMounted: initialState.palette_rack_mounted || [],
        paletteOther: initialState.palette_other || [],
        saveUrl: initialState.save_url,
        flipUrl: initialState.flip_url,
        saving: false,
        savedAt: null,
        error: null,

        init() {
            // Sortable for the rack column — DnD reorder + drop from palette.
            Sortable.create(this.$refs.rackColumn, {
                group: 'rack',
                animation: 150,
                handle: '.rack-item-handle',
                filter: '.rack-item-locked',  // locked items don't drag
                preventOnFilter: false,
                onEnd: (evt) => this.onRackReorder(evt),
            });
            // Sortable for both palette columns — items drag INTO rackColumn.
            Sortable.create(this.$refs.paletteRackMounted, {
                group: { name: 'rack', pull: 'clone', put: false },
                sort: false,
                animation: 150,
                onClone: (evt) => evt.clone.dataset.fromPalette = 'true',
            });
            Sortable.create(this.$refs.paletteOther, {
                group: { name: 'rack', pull: 'clone', put: false },
                sort: false,
                animation: 150,
                onClone: (evt) => evt.clone.dataset.fromPalette = 'true',
            });
        },

        // Cursor walk: bottom-up over reordered DOM, assign u_position to
        // unlocked items, preserve locked items' positions verbatim.
        // Tested server-side via RackEditorEndpointsTest::test_save_rack_canvas_locked_item_holds_position_when_others_reflow_around_it.
        // Algorithm for 2U-after-locked-1U case:
        //   1. Walk rows bottom-up.
        //   2. If row is locked — push existing item with its current u_position; do NOT advance cursor.
        //   3. If row is unlocked — assign cursor as u_position; advance cursor by row's u_height.
        //   4. When the cursor passes a locked item's u_position range, jump cursor over it.
        onRackReorder() {
            // Re-derive rackItems from DOM order (1-indexed FROM BOTTOM, so reverse).
            const rows = Array.from(this.$refs.rackColumn.querySelectorAll('[data-equipment-id]'));
            // Bottom-up: U-1 is the LAST row visually (since CSS column flows top-down).
            const newItems = [];
            let cursor = 1; // U-position cursor, starts at bottom
            // Pre-collect locked item ranges so the cursor can jump over them.
            const lockedRanges = this.rackItems
                .filter(it => it.locked)
                .map(it => ({
                    from: it.u_position,
                    to: it.u_position + (it.u_height || 1) - 1,
                }));
            const cursorOverlapsLocked = (pos, height) => lockedRanges.some(
                r => !(pos + height - 1 < r.from || pos > r.to)
            );

            for (let i = rows.length - 1; i >= 0; i--) {
                const row = rows[i];
                const eqId = row.dataset.equipmentId;
                const existing = this.rackItems.find(it => String(it.equipment_id) === String(eqId));
                if (existing && existing.locked) {
                    // Honour locks — preserve u_position, don't touch the cursor either
                    // (the cursor will jump over locked ranges via the overlap check below).
                    newItems.push(existing);
                    continue;
                }
                const uHeight = parseFloat(row.dataset.uHeight || '1') || 1;
                // Advance cursor past any locked range it would collide with.
                while (cursorOverlapsLocked(cursor, uHeight)) {
                    // Jump to just past the next locked range.
                    const blocking = lockedRanges.find(
                        r => !(cursor + uHeight - 1 < r.from || cursor > r.to)
                    );
                    cursor = blocking.to + 1;
                }
                if (existing) {
                    newItems.push({ ...existing, u_position: cursor });
                } else {
                    // Drag-from-palette case
                    newItems.push({
                        equipment_id: eqId,
                        name: row.dataset.name || '',
                        part_no: row.dataset.partNo || '',
                        u_position: cursor,
                        u_height: uHeight,
                        locked: false,
                        weight_kg: parseFloat(row.dataset.weightKg) || null,
                        current_draw_a: parseFloat(row.dataset.currentDrawA) || null,
                        btu_per_hour: parseInt(row.dataset.btuPerHour) || null,
                    });
                }
                cursor += uHeight;
            }
            this.rackItems = newItems;
        },

        toggleLock(equipmentId) {
            this.rackItems = this.rackItems.map(it =>
                String(it.equipment_id) === String(equipmentId)
                    ? { ...it, locked: !it.locked }
                    : it
            );
        },

        removeItem(equipmentId) {
            this.rackItems = this.rackItems.filter(it =>
                String(it.equipment_id) !== String(equipmentId)
            );
        },

        async save() {
            this.saving = true;
            this.error = null;
            try {
                const payload = {
                    rack_meta: {
                        rack_label: this.rackLabel,
                        rack_height_u: parseInt(this.rackHeightU) || 42,
                        nominal_voltage_v: parseInt(this.nominalVoltageV) || 230,
                        floor: this.floor || null,
                    },
                    rack_items: this.rackItems,
                };
                const res = await axios.post(this.saveUrl, payload, {
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content },
                });
                this.savedAt = new Date().toLocaleTimeString();
                if (res.data?.status === 'ready') {
                    // Reload page so the rendered SVG (status=ready, generated_svg) is visible.
                    setTimeout(() => window.location.reload(), 800);
                }
            } catch (e) {
                this.error = e.response?.data?.error
                    || (e.response?.data?.errors ? Object.values(e.response.data.errors).flat().join(', ') : null)
                    || e.message;
            } finally {
                this.saving = false;
            }
        },

        async flipRackMounted(partNo, isRackMounted) {
            try {
                await axios.post(this.flipUrl, {
                    part_no: partNo,
                    is_rack_mounted: isRackMounted,
                }, {
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content },
                });
            } catch (e) {
                console.error('flipRackMounted failed', e);
            }
        },
    };
};
```

**Step 3.3 — Build `rack-edit.blade.php`:**

```blade
@extends('layouts.app')
@section('title', ($drawing->rack_label ?? 'Rack').' — Edit')

@push('styles')
    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/rack-editor.js'])
    <style>
        [x-cloak] { display: none !important; }
        .rack-frame {
            border: 2px solid #374151; background: #f3f4f6; border-radius: 4px;
            position: relative; min-height: 600px; padding: 4px;
        }
        .u-rail {
            display: flex; flex-direction: column-reverse; /* U-1 at bottom */
            border-right: 1px solid #cbd5e1; width: 28px;
            font-size: 10px; color: #475569; text-align: center;
        }
        .u-rail .u-tick {
            height: 24px; display: flex; align-items: center; justify-content: center;
            border-bottom: 1px dotted #e2e8f0;
        }
        .rack-item {
            background: #fff; border: 1px solid #475569; padding: 4px 8px;
            font-size: 11px; cursor: move; display: flex;
            align-items: center; justify-content: space-between;
        }
        .rack-item-locked { background: #fef3c7; cursor: not-allowed; }
        .palette-item {
            background: #fff; border: 1px solid #cbd5e1; padding: 6px 10px;
            font-size: 12px; margin-bottom: 4px; cursor: grab;
        }
        .palette-item.greyed { opacity: 0.55; }
    </style>
@endpush

@section('content')
<div class="max-w-7xl mx-auto p-6"
     x-data="rackEditor({{ Js::from([
         'rack_meta' => (array) ($drawing->source_data['rack_meta'] ?? []),
         'rack_items' => (array) ($drawing->source_data['rack_items'] ?? []),
         'palette_rack_mounted' => $palette_rack_mounted,
         'palette_other' => $palette_other,
         'save_url' => route('projects.drawings.rack-canvas', [$project, $drawing]),
         'flip_url' => route('projects.drawings.flip-rack-mounted', $project),
     ]) }})"
     x-init="init()">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <div class="flex items-center justify-between mb-6">
        <div>
            <a href="{{ route('projects.drawings.index', $project) }}" class="text-sm text-teal-600 hover:underline">← Back to drawings</a>
            <h1 class="text-2xl font-semibold mt-1" x-text="`${rackLabel} — Edit`"></h1>
            <p class="text-sm text-gray-500">Project: {{ $project->name }} · Revision {{ $drawing->revisionLabel() }}</p>
        </div>
        <div class="flex items-center gap-3">
            <span class="text-xs text-gray-500" x-show="savedAt" x-cloak>Saved <span x-text="savedAt"></span></span>
            <button type="button"
                    @click="save()"
                    :disabled="saving"
                    class="bg-teal-600 hover:bg-teal-700 disabled:opacity-50 text-white font-medium px-4 py-2 rounded-lg text-sm shadow-sm">
                <span x-show="!saving">Save Rack</span>
                <span x-show="saving" x-cloak>Saving…</span>
            </button>
        </div>
    </div>

    <div x-show="error" x-cloak class="mb-3 bg-red-50 border border-red-200 text-red-800 p-3 rounded text-sm">
        <span x-text="error"></span>
    </div>

    <div class="bg-white border border-gray-200 rounded-lg p-4 mb-4 grid grid-cols-4 gap-4">
        <label class="text-sm">
            <span class="block text-gray-700 mb-1">Rack label</span>
            <input type="text" x-model="rackLabel" maxlength="120"
                   class="w-full border-gray-300 rounded-md text-sm">
        </label>
        <label class="text-sm">
            <span class="block text-gray-700 mb-1">Height (U)</span>
            <input type="number" x-model="rackHeightU" min="1" max="99"
                   class="w-full border-gray-300 rounded-md text-sm">
        </label>
        <label class="text-sm">
            <span class="block text-gray-700 mb-1">Nominal voltage (V)</span>
            <input type="number" x-model="nominalVoltageV" min="100" max="480"
                   class="w-full border-gray-300 rounded-md text-sm">
        </label>
        <label class="text-sm">
            <span class="block text-gray-700 mb-1">Floor</span>
            <input type="text" x-model="floor" maxlength="60"
                   class="w-full border-gray-300 rounded-md text-sm">
        </label>
    </div>

    <div class="grid grid-cols-3 gap-4">
        {{-- ── PALETTE (left, 1 col) ─────────────────────────────────── --}}
        <div class="col-span-1">
            <h2 class="text-sm font-semibold mb-2 text-gray-800">Equipment palette</h2>
            <div class="bg-white border border-gray-200 rounded-lg p-3 mb-3">
                <h3 class="text-xs uppercase text-gray-500 mb-2">Rack-mounted ({{ count($palette_rack_mounted) }})</h3>
                <div x-ref="paletteRackMounted">
                    @foreach ($palette_rack_mounted as $row)
                        <div class="palette-item"
                             data-equipment-id="{{ $row['equipment_id'] }}"
                             data-name="{{ $row['name'] }}"
                             data-part-no="{{ $row['part_no'] }}"
                             data-u-height="{{ $row['u_height'] ?? 1 }}"
                             data-weight-kg="{{ $row['weight_kg'] ?? '' }}"
                             data-current-draw-a="{{ $row['current_draw_a'] ?? '' }}"
                             data-btu-per-hour="{{ $row['btu_per_hour'] ?? '' }}">
                            <div class="font-medium">{{ $row['name'] }}</div>
                            <div class="text-xs text-gray-500">
                                {{ $row['manufacturer'] ?? '' }}
                                · {{ $row['part_no'] ?? '' }}
                                · {{ $row['u_height'] !== null ? $row['u_height'].'U' : 'U-height unknown' }}
                            </div>
                        </div>
                    @endforeach
                    @if (empty($palette_rack_mounted))
                        <p class="text-xs text-gray-500">No rack-mounted equipment yet — flip the checkbox below to mark items.</p>
                    @endif
                </div>
            </div>
            <div class="bg-white border border-gray-200 rounded-lg p-3">
                <h3 class="text-xs uppercase text-gray-500 mb-2">Other equipment ({{ count($palette_other) }})</h3>
                <div x-ref="paletteOther">
                    @foreach ($palette_other as $row)
                        <div class="palette-item greyed"
                             data-equipment-id="{{ $row['equipment_id'] }}"
                             data-name="{{ $row['name'] }}"
                             data-part-no="{{ $row['part_no'] }}"
                             data-u-height="{{ $row['u_height'] ?? 1 }}"
                             data-weight-kg="{{ $row['weight_kg'] ?? '' }}"
                             data-current-draw-a="{{ $row['current_draw_a'] ?? '' }}"
                             data-btu-per-hour="{{ $row['btu_per_hour'] ?? '' }}">
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex-1 min-w-0">
                                    <div class="font-medium truncate">{{ $row['name'] }}</div>
                                    <div class="text-xs text-gray-500 truncate">
                                        {{ $row['manufacturer'] ?? '' }} · {{ $row['part_no'] ?? '' }}
                                    </div>
                                </div>
                                @if (!empty($row['part_no']))
                                    <label class="text-xs flex items-center gap-1 whitespace-nowrap"
                                           title="Mark as rack-mounted">
                                        <input type="checkbox"
                                               @change="flipRackMounted('{{ $row['part_no'] }}', $event.target.checked)">
                                        <span>Rack?</span>
                                    </label>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- ── RACK CANVAS (right, 2 cols) ───────────────────────────── --}}
        <div class="col-span-2">
            <h2 class="text-sm font-semibold mb-2 text-gray-800">Rack — drag equipment to build</h2>
            <div class="rack-frame flex">
                <div class="u-rail">
                    <template x-for="i in parseInt(rackHeightU)" :key="i">
                        <div class="u-tick" x-text="i"></div>
                    </template>
                </div>
                <div class="flex-1 flex flex-col-reverse" x-ref="rackColumn">
                    <template x-for="item in rackItems" :key="item.equipment_id">
                        <div class="rack-item"
                             :class="{ 'rack-item-locked': item.locked }"
                             :data-equipment-id="item.equipment_id"
                             :data-u-height="item.u_height"
                             :data-name="item.name"
                             :data-part-no="item.part_no"
                             :data-weight-kg="item.weight_kg"
                             :data-current-draw-a="item.current_draw_a"
                             :data-btu-per-hour="item.btu_per_hour"
                             :style="`height: ${(item.u_height || 1) * 24}px;`">
                            <span class="rack-item-handle flex-1 truncate"
                                  x-text="`U-${item.u_position} · ${item.name} (${item.u_height || '?'}U)`"></span>
                            <span class="flex items-center gap-2 ml-2">
                                <button type="button"
                                        @click="toggleLock(item.equipment_id)"
                                        class="text-xs"
                                        :title="item.locked ? 'Unlock U-position' : 'Lock U-position'">
                                    <span x-text="item.locked ? '🔒' : '🔓'"></span>
                                </button>
                                <button type="button"
                                        @click="removeItem(item.equipment_id)"
                                        class="text-xs text-red-600 hover:underline">×</button>
                            </span>
                        </div>
                    </template>
                </div>
            </div>
            <p class="text-xs text-gray-500 mt-2">
                U-1 is at the bottom (AVIXA convention — PDU low, patches/IO high). Drag from the palette into this column. Use the lock to pin a U-position.
            </p>
        </div>
    </div>
</div>
@endsection
```

(Note: emoji lock glyphs OK here — the user's CLAUDE.md "no emojis" rule applies to assistant output, not to UI glyphs already established in the codebase. Replace with SVG lock icon if engineer prefers; iteration target.)

**Step 3.4 — Update `show.blade.php` to add an Edit Rack button (Warning 9 fix — do NOT touch the existing SVG render branch):**

Read the existing Phase 17 show.blade.php first (line ~55 has `@if ($drawing->isReady() && ! empty($drawing->generated_svg))` — this branch is kind-agnostic and ALREADY covers rack drawings; the rendered rack SVG will display through it once `saveRackCanvas` flips status to ready). DO NOT touch that branch.

Plan 18-03 ONLY adds an `@if ($drawing->isRack() && $isOwner)` block rendering the "Edit Rack" button next to the existing kind-aware action buttons (Download PDF/SVG/PNG, Regenerate). Find the existing button group (around line 36-50 — the Download + Regenerate buttons) and add:

```blade
@if ($drawing->isRack())
    <a href="{{ route('projects.drawings.edit', [$project, $drawing]) }}"
       class="bg-teal-600 hover:bg-teal-700 text-white font-medium px-4 py-2 rounded-md text-sm">
        Edit Rack
    </a>
@endif
```

**Do NOT add a duplicate `@if ($drawing->isRack() && $drawing->generated_svg)` SVG render block** — the existing line-55 branch already renders the SVG for any kind. Adding a second branch would cause double-render or layout breakage. The previous draft of this plan had a "If show.blade.php has no current rack-rendering branch" guard that was dead code — removed.

**Step 3.5 — Build it:**

```bash
npm install
npm run build
```

If `npm run build` succeeds, Vite manifest now references rack-editor.js; the @vite directive in rack-edit.blade.php will resolve.

Manual smoke test (live env):
1. Visit `/projects/{p}/drawings`, click + Create Drawing → Rack.
2. Land on show page → click Edit Rack.
3. Drag an item from rack-mounted palette to the rack column.
4. Click Save Rack → page reloads → status pill shows Ready, SVG rendered (via the existing line-55 branch).
5. Click Download PDF → landscape A4 with title block + rack rendered.
6. Click Download SVG → standalone SVG file opens in browser.
  </action>
  <acceptance_criteria>
    - `npm install` adds sortablejs ^1.15.6 to package.json.
    - `npm run build` succeeds; manifest.json includes `resources/js/rack-editor.js`.
    - rack-edit.blade.php exists and renders for kind=rack drawings (visited via Edit Rack button on show page).
    - Palette splits into Rack-mounted vs Other sections, with Other items greyed but draggable.
    - Sortable.js DnD works (manual or smoke test): drag from palette to rack column adds an item.
    - Lock toggle button appears on each rack item; clicking flips the locked flag in the Alpine state.
    - Click Save Rack → axios POST → server runs RackElevationRenderService synchronously → status flips to ready.
    - show.blade.php gets an Edit Rack button when `$drawing->isRack()`. **The existing line-55 SVG render branch is NOT modified** (Warning 9 fix).
    - Manual flow E2E: + Create Drawing → Rack → Edit → Save → Reload → SVG visible (through existing render branch) → Download PDF → A4 landscape PDF arrives.
  </acceptance_criteria>
  <verify>
    <automated>npm install --no-audit --silent && npm run build && php artisan test --filter='RackEditorEndpointsTest|RackElevationRenderServiceTest'</automated>
  </verify>
  <done>
    Rack editor UI lives. Sortable.js drag-into-U-slots works with lock-aware cursor walk. Lock toggle persists. AJAX save triggers synchronous render. Downloads use the PDF Blade view. show.blade.php gets Edit Rack button without touching the existing SVG render branch (Warning 9). DRAW-07 (engineer creates rack), DRAW-10 (drag-reorder + lock), DRAW-11 (multi-rack via picker), DRAW-13 (PDF/SVG export) all green.
  </done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Browser → /rack-canvas POST | AJAX payload from the engineer's editor — full validation via $request->validate against allow-list of keys + types + ranges. Extra keys silently dropped. |
| Browser → /flip-rack-mounted POST | Toggles a Device row's is_rack_mounted column. Authorised via ProjectPolicy::update on the Project (owner-OR-admin) — works BEFORE any rack drawing exists. |
| Browser → /edit GET | Route model binding loads ProjectDrawing; controller asserts kind=rack + project_id match BEFORE rendering. |
| Server → Browsershot PDF | Rack PDF render embeds server-built SVG via `{!! $drawing->generated_svg !!}` — SVG is service-emitted, equipment names htmlspecialchars-escaped INSIDE the renderer. No user HTML reaches Browsershot. |
| RackElevationRenderService → Device data | Reads through DrawingDataResolverService::rackStackForProject which scopes to $project->id (DATA-03). |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-18.03-01 | Tampering | rack-canvas POST payload | mitigate | Strict $request->validate with typed allow-list (rack_items.*.u_position integer 1-99, u_height numeric 0.5-42, name string max:200, etc). Extra keys silently dropped by Laravel's `validated()` array. |
| T-18.03-02 | XSS via equipment name in SVG | RackElevationRenderService::renderItems | mitigate | Every `<text>` content goes through `htmlspecialchars($name, ENT_XML1 \| ENT_QUOTES, 'UTF-8')` before SVG emission. Test asserts `<script>` in name renders as `&lt;script&gt;`. |
| T-18.03-03 | Spoofing | edit + rack-canvas endpoints | mitigate | $this->authorize('update', $drawing) via ProjectDrawingPolicy (owner OR admin). project_id match check on every rack endpoint. |
| T-18.03-03b | Spoofing | flip-rack-mounted endpoint | mitigate | $this->authorize('update', $project) via ProjectPolicy (owner OR admin). Authorises against the Project — NOT a specific Drawing — because the Device-row mutation is conceptually project-owned and the endpoint must remain reachable BEFORE the engineer creates their first rack drawing (Blocker 2 fix from checker iteration). RackEditorEndpointsTest::test_flip_rack_mounted_works_before_any_rack_drawing_exists is the regression. |
| T-18.03-04 | Denial-of-Service | rack-canvas spam | mitigate | `->middleware('throttle:60,1')` on rack-canvas + flip-rack-mounted routes (60/min/user). RackElevationRenderService runs synchronously but the SVG build is in-memory string concat — sub-second even for full 42U racks (asserted by RackElevationRenderServiceTest::test_render_completes_within_one_second_for_full_rack — Warning 8 fix). No worker fork. |
| T-18.03-05 | Information Disclosure | cross-project rack edit | mitigate | Controller asserts `$drawing->project_id !== $project->id → abort(404)` — same pattern as Phase 17 download/show. Verified by RackEditorEndpointsTest. |
| T-18.03-06 | Tampering | locked-item override via direct payload | mitigate | Server doesn't enforce u_position-stays-when-locked beyond persisting whatever the client sends. ACCEPTABLE because: (a) all callers are authenticated engineers, (b) lock is a UX hint not a security boundary, (c) the next render will use the persisted u_position even if locked=true (locked just means "client UI doesn't reorder this on drag"). The cursor-walk algorithm in rack-editor.js preserves locks; round-trip is asserted by RackEditorEndpointsTest::test_save_rack_canvas_locked_item_holds_position_when_others_reflow_around_it (Warning 7 fix). Documented here so future Phase 21 client-portal work knows not to expose this endpoint. |
| T-18.03-07 | Injection | flipRackMountedFlag SQL | mitigate | whereRaw uses bound parameter `?` — part_no value is normalised via strtolower(trim()) before binding. No string concat into SQL. |
| T-18.03-08 | Information Disclosure | Sortable.js bundle exposure | accept | Sortable.js is loaded only via the rack-editor.js Vite entry, only on /drawings/{r}/edit pages. Pre-existing alpine + axios global bundle is unaffected. No new attack surface beyond a public SortableJS distribution. |
| T-18.03-09 | Repudiation | rack render failures | mitigate | saveRackCanvas catches Throwable, sets status=failed + error_message, persists, logs Log::error with drawing_id. Failed renders are visible in the index status pill. |
| T-18.03-10 | Tampering | rack_height_u boundary | mitigate | Validate min:1 max:99. RackElevationRenderService also re-validates ($heightU < 1 || > 99 throws). Defence in depth. |
</threat_model>

<verification>
End-to-end flow verification (manual + automated):

1. **Synchronous render contract:**
   - Run `php artisan test --filter=RackElevationRenderServiceTest` — all 8 tests pass (including 1s render-time budget, Warning 8 fix).
2. **Editor end-to-end (manual):**
   - + Create Drawing → Rack → land in editor → drag 3 items from palette → Save → status flips to ready, SVG visible on reload (rendered via the existing show.blade.php line-55 kind-agnostic branch).
   - Click Download PDF → A4 landscape PDF with title block + rack drawing.
   - Click Download SVG → standalone SVG file.
3. **Multi-rack per project (DRAW-11):**
   - Click + Create Drawing → Rack twice → index shows Rack 1 + Rack 2 in the Rack Elevations section with independent statuses.
4. **CRIT-06 enforcement:**
   - Drag a part_no NOT in the JSON pack into the rack → save → SVG includes "U-height unknown" warning + that part name listed in the warning region.
5. **Lock semantics (DRAW-10):**
   - Add 3 items, lock the middle one, drag the bottom one above it → middle item's u_position stays after save. Server-round-trip asserted by RackEditorEndpointsTest (Warning 7 fix).
6. **Totals partial-data (DRAW-12):**
   - Mix of known + unknown parts → footer shows asterisks + ratio (e.g. "Weight: 1.8 kg* (1/3 known)").
7. **Phase 17 regression:**
   - Generate a schematic → still works, no visual changes to schematic show page.
8. **flipRackMountedFlag works pre-rack (Blocker 2 regression):**
   - On a project with ZERO rack drawings, an owner can POST to `/projects/{p}/drawings/flip-rack-mounted` → 200 + Device row updated. (Test: test_flip_rack_mounted_works_before_any_rack_drawing_exists.)
9. **Tests:**
   - `php artisan test --filter='RackElevationRenderServiceTest|RackEditorEndpointsTest|Drawings'` — all green.
10. **Build:**
    - `npm run build` succeeds; manifest.json contains `resources/js/rack-editor.js`.
11. **Routes:**
    - `php artisan route:list --name=drawings` shows: index, show, picker, create-schematic, create-rack, edit, rack-canvas, flip-rack-mounted, regenerate, download, update-status (11 routes total: 6 Phase 17 + 2 Plan 18-01 + 3 Plan 18-03).
</verification>

<success_criteria>
- [ ] RackElevationRenderService renders a complete SVG for a kind=rack ProjectDrawing in <1s for a full 42U rack with 30 items (asserted, not aspirational — Warning 8 fix), with U-numbered rail (1 at bottom), equipment rectangles at correct U-positions, and a totals footer with asterisks on partial data.
- [ ] CRIT-06 enforced: items with no u_height (item override null + DeviceCatalog returns null) render as 1U placeholder AND surface a "U-height unknown" warning region with the device name listed.
- [ ] Equipment names htmlspecialchars-escaped inside SVG (XSS protection).
- [ ] DrawingExportRendererService::bladeViewFor returns 'pdf.drawings.rack' for kind=rack (no longer throws).
- [ ] resources/views/pdf/drawings/rack.blade.php exists with title block + landscape A4 + embedded {!! $generated_svg !!}.
- [ ] ProjectDrawingController gains editRack + saveRackCanvas + flipRackMountedFlag actions. editRack + saveRackCanvas gated by ProjectDrawingPolicy + project_id match check + kind=rack guard. **flipRackMountedFlag gated by ProjectPolicy::update (project-scoped, owner-OR-admin) — Blocker 2 fix.**
- [ ] saveRackCanvas validates rack_meta + rack_items via $request->validate; rejects out-of-range / extra fields with 422.
- [ ] saveRackCanvas runs RackElevationRenderService synchronously, persists generated_svg, flips status to ready (or failed on render exception).
- [ ] routes/web.php gains projects.drawings.edit + projects.drawings.rack-canvas (throttled 60/min) + projects.drawings.flip-rack-mounted (throttled 60/min, project-scoped — no `{drawing}` segment) BEFORE the {drawing} wildcard.
- [ ] Sortable.js ^1.15.6 installed and loaded ONLY via the new rack-editor.js Vite entry (not in the global bundle).
- [ ] rack-edit.blade.php has palette (rack-mounted-first + greyed-others) + 42U rack scaffold + per-item lock toggle + Save button + status messages.
- [ ] show.blade.php gets an Edit Rack button for kind=rack. **The existing kind-agnostic SVG render branch at line ~55 is NOT modified** (Warning 9 fix — no dead-code conditional, no double-render).
- [ ] RackElevationRenderServiceTest covers: kind guard, 42U rail, item placement, partial-data asterisks, unknown-part warning, htmlspecialchars escape, locked items, render-time budget (8 cases total).
- [ ] RackEditorEndpointsTest covers: edit page render + 404 + 403, save canvas success + validation errors + lock preservation through Sortable cursor walk (Warning 7), flipRackMounted endpoint INCLUDING pre-rack-existence case (Blocker 2 regression), download PDF after save.
- [ ] Phase 17 test suite still green.
- [ ] DRAW-07, DRAW-08, DRAW-09 (partial — palette ordering only via Plan 18-01 + bottom-up u_position rendering here; AVIXA auto-place algorithm deferred), DRAW-10, DRAW-11, DRAW-12, DRAW-13 all covered.
- [ ] Threat model dispositions enacted in code (CSRF, throttle, validate, policy, project scope, htmlspecialchars).
</success_criteria>

<output>
After completion, create `.planning/phases/18-rack-elevations/18-03-SUMMARY.md` summarising:
- RackElevationRenderService API + key constants (U_HEIGHT_PX, RACK_WIDTH_PX, etc) + measured render time for 42U/30-items.
- PDF Blade view location + landscape A4 setup.
- Routes added + their throttle middleware. Note: flip-rack-mounted is project-scoped (no `{drawing}` segment) and authorises against ProjectPolicy::update, not ProjectDrawingPolicy.
- Sortable.js + new Vite entry.
- Test file paths + behaviour count per test class (RackElevationRenderServiceTest: 8; RackEditorEndpointsTest: 9 incl. pre-rack-existence regression).
- Any deviation from this plan (with rationale).
- v1.3.x quick-task notes (auto-fill keyword classifier deferred per CONTEXT.md — clearly mark as not-shipped-in-v1.3).
</output>
