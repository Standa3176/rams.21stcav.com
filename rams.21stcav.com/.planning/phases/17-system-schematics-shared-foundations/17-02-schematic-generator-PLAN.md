---
phase: 17-system-schematics-shared-foundations
plan: 02
type: execute
wave: 2
depends_on: ["17-01"]
files_modified:
  - app/Services/Drawings/DrawingDataResolverService.php
  - app/Services/Drawings/SchematicGeneratorService.php
  - app/Services/Drawings/SchematicD2SourceBuilder.php
  - app/Jobs/BuildSchematicJob.php
  - resources/svg/av-symbols/display.svg
  - resources/svg/av-symbols/projector.svg
  - resources/svg/av-symbols/speaker.svg
  - resources/svg/av-symbols/microphone.svg
  - resources/svg/av-symbols/camera.svg
  - resources/svg/av-symbols/switcher.svg
  - resources/svg/av-symbols/dsp.svg
  - resources/svg/av-symbols/amplifier.svg
  - resources/svg/av-symbols/codec.svg
  - resources/svg/av-symbols/control-processor.svg
  - resources/svg/av-symbols/touch-panel.svg
  - resources/svg/av-symbols/byod-dongle.svg
  - resources/svg/av-symbols/clickshare.svg
  - resources/svg/av-symbols/network-switch.svg
  - resources/svg/av-symbols/usb-hub.svg
  - resources/svg/av-symbols/source-pc.svg
  - resources/svg/av-symbols/laptop.svg
  - resources/svg/av-symbols/hdmi-port.svg
  - resources/svg/av-symbols/usb-port.svg
  - resources/svg/av-symbols/network-port.svg
  - resources/svg/av-symbols/generic-source.svg
  - resources/svg/av-symbols/generic-destination.svg
  - resources/svg/av-symbols/blanking-panel.svg
  - resources/svg/av-symbols/pdu.svg
  - resources/svg/av-symbols/equipment-rack.svg
  - resources/svg/av-symbols/README.md
  - resources/views/pdf/drawings/schematic.blade.php
  - resources/views/pdf/drawings/_title-block.blade.php
  - config/drawings.php
  - tests/Feature/Drawings/SchematicGeneratorServiceTest.php
autonomous: true
requirements: [DRAW-01, DRAW-02, DRAW-03, DRAW-04, DRAW-22]
must_haves:
  truths:
    - "User can dispatch BuildSchematicJob and the job invokes D2 CLI to produce a real (non-placeholder) SVG written into project_drawings.generated_svg"
    - "Auto-generated schematic uses signal-type colour coding: audio=red, video=blue, control=green, network=purple, usb=orange (per FEATURES.md Phase 17 + AVIXA conventions)"
    - "Cable IDs and port labels on the schematic match cable schedule character-for-character (no rewriting; pulled from canonical project data)"
    - "SchematicD2SourceBuilder classifies devices by Device::isSource()/isDestination()/isProcessor() — never row order — and renders ambiguous/unclassified cables as undirected lines (CRIT-05 prevention)"
    - "AV symbol pack (~25 SVGs) lives in resources/svg/av-symbols/ with total <100 KB; D2 source references symbols via shape: image; icon: file:///<absolute path>/<symbol>.svg"
    - "Schematic Blade view (resources/views/pdf/drawings/schematic.blade.php) embeds the generated SVG inline + renders a standard title block (project ref, client, drawn-by, date, revision, status)"
    - "DrawingDataResolverService::adjacencyForProject returns per-room arrays of {devices, cables} sourced exclusively from ProjectDataService::resolve()"
    - "config/drawings.php exposes the D2 binary path (env D2_BINARY_PATH defaulting to /usr/local/bin/d2) and a layout engine name (env D2_LAYOUT defaulting to elk)"
    - "BuildSchematicJob handle() invokes SchematicGeneratorService::generate($drawing) and the placeholder SVG body from Plan 01 is removed"
    - "SchematicD2SourceBuilder::sanitiseLabel() escapes ALL D2-DSL meta characters (backslash, double-quote, backtick, newline, control chars) — verified by a unit test that feeds a crafted equipment name and asserts the resulting D2 source still parses cleanly (Warning 7)"
  artifacts:
    - path: "app/Services/Drawings/SchematicGeneratorService.php"
      provides: "End-to-end generator: takes ProjectDrawing, calls DrawingDataResolverService, builds D2 source via SchematicD2SourceBuilder, shells out to D2 CLI via Symfony Process, writes generated_svg + filename + thumbnail"
    - path: "app/Services/Drawings/SchematicD2SourceBuilder.php"
      provides: "D2 DSL emitter — devices grouped by signal_role, cables typed by signal_type, symbols referenced from resources/svg/av-symbols/. Includes sanitiseLabel() with full D2-DSL escape (T-17.02-01 mitigation)."
    - path: "config/drawings.php"
      provides: "Drawings configuration (D2 binary path, layout engine, symbol pack directory, signal-type colour map)"
      contains: "d2_binary_path"
    - path: "resources/svg/av-symbols/README.md"
      provides: "Symbol pack catalogue + AVIXA D401.01 alignment note"
    - path: "resources/views/pdf/drawings/schematic.blade.php"
      provides: "Blade view embedding SVG inline + title block partial"
    - path: "resources/views/pdf/drawings/_title-block.blade.php"
      provides: "Standard title block partial (DRAW-22) — reused by Phases 18/19/20 too"
  key_links:
    - from: "app/Services/Drawings/SchematicGeneratorService.php"
      to: "DrawingDataResolverService::adjacencyForProject"
      via: "Constructor-injected dependency"
      pattern: "DrawingDataResolverService"
    - from: "app/Services/Drawings/SchematicD2SourceBuilder.php"
      to: "Device::isSource()/isDestination()/isProcessor()"
      via: "Adjacency rows reshaped by signal_role classification"
      pattern: "isSource\\(\\)|isDestination\\(\\)|isProcessor\\(\\)|signal_role"
    - from: "app/Jobs/BuildSchematicJob.php"
      to: "SchematicGeneratorService::generate"
      via: "handle() calls generator instead of writing placeholder SVG"
      pattern: "SchematicGeneratorService"
    - from: "resources/views/pdf/drawings/schematic.blade.php"
      to: "_title-block.blade.php partial"
      via: "@include('pdf.drawings._title-block', [...])"
      pattern: "_title-block"
---

<objective>
Replace the Plan 01 placeholder schematic generation with the real auto-generation pipeline: AV symbol pack (~25 SVGs in `resources/svg/av-symbols/`), D2 source DSL builder driven by `Device::isSource/isDestination/isProcessor` classification, `SchematicGeneratorService` invoking the D2 CLI binary, schematic Blade view + reusable title block partial, configuration file with D2 binary path + signal-type colour map, and a feature test that exercises the full pipeline against a deterministic fixture.

Purpose: Deliver DRAW-01 (auto-generate per-room signal-flow schematic from canonical project data), DRAW-02 (signal-type colour coding), DRAW-03 (cable IDs and port labels match cable schedule character-for-character), DRAW-04 (AVIXA-style symbol library), DRAW-22 (standard title block on every sheet). The generator must satisfy CRIT-05 (signal direction NEVER inferred from cable-row order) and the "AI never invents content" constraint (zero AI calls in this plan — pure deterministic generation).

**Coordination note (Warning 6):** Both Plan 02 (this plan) and Plan 03 modify `app/Jobs/BuildSchematicJob.php`. Plan 02 replaces the `handle()` body's SVG-writing block (the "Plan 17-01 placeholder body" section in Plan 01's job skeleton); Plan 03 inserts a thumbnail render block in the success branch BEFORE the completion email dispatch. Disjoint regions but the same file — Plan 03's `depends_on: ["17-01", "17-02"]` forces this plan to run FIRST. Plan 03 will see the post-Plan-02 `handle()` body when it edits.

Output:
- `DrawingDataResolverService::adjacencyForProject()` body (the Plan 01 stub returns []; here we implement it).
- `SchematicGeneratorService` — the end-to-end orchestrator: data → D2 source → SVG → persistence.
- `SchematicD2SourceBuilder` — the D2 DSL emitter (deterministic; can be unit-tested against fixtures).
- ~25 SVG symbol files in `resources/svg/av-symbols/` plus a README.md catalogue.
- `resources/views/pdf/drawings/schematic.blade.php` + `_title-block.blade.php` partial (DRAW-22).
- `config/drawings.php` (D2 binary path, layout engine, signal-type colour map).
- Updated `BuildSchematicJob::handle()` calling the real generator (replacing only the placeholder SVG block from Plan 01 — leave the surrounding mail dispatch + failed() hook UNTOUCHED).
- Feature test: dispatching BuildSchematicJob synchronously against a fixture project produces a non-empty `generated_svg` containing each cable_id from the fixture cable schedule (CRIT-02 character-for-character verification) AND a "crafted equipment name" test that proves the D2 escape (Warning 7).
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/STATE.md
@.planning/phases/17-system-schematics-shared-foundations/17-CONTEXT.md
@.planning/phases/17-system-schematics-shared-foundations/17-01-foundations-PLAN.md
@.planning/research/SUMMARY.md
@.planning/research/STACK.md
@.planning/research/FEATURES.md
@.planning/research/ARCHITECTURE.md
@.planning/research/PITFALLS.md
@CLAUDE.md
@app/Models/ProjectDrawing.php
@app/Models/Device.php
@app/Models/Project.php
@app/Services/Drawings/DrawingService.php
@app/Services/Drawings/DrawingDataResolverService.php
@app/Jobs/BuildSchematicJob.php
@app/Services/InstallTaskGeneratorService.php
@app/Core/Modules/Projects/ProjectDataService.php
@app/Services/PdfOcrExtractorService.php

<interfaces>
<!-- Phase 17 Plan 01 landed these — Plan 02 builds against them, no exploration needed. -->

From app/Models/Device.php (Plan 01 added):
```php
public const ROLE_SOURCE      = 'source';
public const ROLE_DESTINATION = 'destination';
public const ROLE_PROCESSOR   = 'processor';
public function isSource(): bool;
public function isDestination(): bool;
public function isProcessor(): bool;
public function hasUnknownSignalRole(): bool;
// Field: signal_role (varchar 16, nullable). Values: 'source' | 'destination' | 'processor' | null.
```

From app/Models/ProjectDrawing.php (Plan 01 landed):
```php
const KIND_SCHEMATIC = 'schematic';
const STATUS_GENERATING = 'generating';
const STATUS_READY = 'ready';
const STATUS_FAILED = 'failed';
// Fillables include: source_data, generated_svg, status, error_message, filename, completion_email_sent_at, failed_email_sent_at.
public function project(): BelongsTo;
public function room(): BelongsTo;             // returns SiteSurveyRoom
public function revisionLabel(): string;       // 'R0' for version=1, 'R1' for version=2…
```

From app/Services/Drawings/DrawingDataResolverService.php (Plan 01 stubbed):
```php
public function adjacencyForProject(Project $project): array;   // body returns [] in Plan 01 — Plan 02 implements
// Expected return shape (from PHPDoc):
// array<int, array{
//   room_id: int|null,
//   room_name: string,
//   devices: array<int, array{equipment_id, name, manufacturer, model, signal_role}>,
//   cables: array<int, array{cable_id, source_equipment_id, source_port, dest_equipment_id, dest_port, signal_type}>,
// }>
```

From app/Services/PdfOcrExtractorService.php (look for the exact pattern — uses Symfony\Component\Process\Process):
```php
use Symfony\Component\Process\Process;
$process = new Process([$binaryPath, $arg1, $arg2]);
$process->setTimeout(60);
$process->run();
if (!$process->isSuccessful()) { throw new ProcessFailedException($process); }
$output = $process->getOutput();
```
Mirror this for the D2 invocation.

From app/Core/Modules/Projects/ProjectDataService.php:
```php
public function resolve(Project $project): array;
// Returns: ['project'=>..., 'equipment'=>..., 'rooms'=>..., 'cables'=>..., ...]
// Each cable row: ['cable_id'=>string, 'source'=>['equipment_id', 'port'], 'destination'=>['equipment_id', 'port'], 'signal_type'=>string|null, 'room_id'=>int|null] — actual shape depends on existing implementation; the resolver must reshape from whatever resolve() emits.
```
</interfaces>
</context>

<tasks>

<task type="auto" tdd="false">
  <name>Task 1: AV symbol pack + config + DrawingDataResolverService::adjacencyForProject</name>
  <read_first>
    - .planning/research/STACK.md §5 AV Symbol Pack (top-25 candidate list)
    - .planning/research/FEATURES.md Phase 17 — symbol-set anatomy
    - .planning/research/PITFALLS.md MIN-01 (single symbol set, AVIXA D401.01)
    - .planning/research/PITFALLS.md MIN-03 (avoid foreignObject)
    - app/Core/Modules/Projects/ProjectDataService.php (resolve() return shape)
    - app/Services/Drawings/DrawingDataResolverService.php (Plan 01 stub to fill in)
    - app/Models/Device.php (signal_role + classification helpers — Plan 01)
  </read_first>
  <action>
    Three deliverables: symbol pack, config file, and resolver implementation.

    **(a) AV symbol pack — `resources/svg/av-symbols/*.svg`:**

    Create 25 SVG files following AVIXA D401.01 conventions. Each file MUST:
    - Use only `<svg>`, `<g>`, `<rect>`, `<circle>`, `<line>`, `<polygon>`, `<polyline>`, `<path>`, `<text>` (NEVER `<foreignObject>` per MIN-03).
    - Be ≤4 KB; total pack <100 KB.
    - Have `viewBox="0 0 100 100"` so D2 can scale uniformly.
    - Use `stroke="currentColor"` and `fill="none"` (or solid where AVIXA convention dictates) so the schematic's CSS controls colour.

    The 25 symbols (per CONTEXT.md "Claude's Discretion" symbol pack v1):
    1. `display.svg` — rectangle (16:9 aspect), centered stand
    2. `projector.svg` — trapezoid + lens cone
    3. `speaker.svg` — circle inside trapezoid (AVIXA convention)
    4. `microphone.svg` — circle with diaphragm slash + body
    5. `camera.svg` — body box + lens circle + indicator dot
    6. `switcher.svg` — long rectangle with input/output port pips
    7. `dsp.svg` — rectangle with "DSP" text + waveform line inside (text element, not foreignObject)
    8. `amplifier.svg` — rectangle with chevron pattern
    9. `codec.svg` — rectangle labeled "CODEC" with antenna marks
    10. `control-processor.svg` — rectangle with "CTRL" text + status LED dots
    11. `touch-panel.svg` — rounded rectangle representing touch UI
    12. `byod-dongle.svg` — small rectangle with HDMI/USB cable lead
    13. `clickshare.svg` — round button (ClickShare/wireless presentation)
    14. `network-switch.svg` — long rectangle with port grid
    15. `usb-hub.svg` — small rectangle with branching ports
    16. `source-pc.svg` — desktop tower outline
    17. `laptop.svg` — clamshell laptop outline
    18. `hdmi-port.svg` — trapezoidal HDMI connector
    19. `usb-port.svg` — rectangular USB connector
    20. `network-port.svg` — RJ45 outline
    21. `generic-source.svg` — circle with arrow-out
    22. `generic-destination.svg` — circle with arrow-in
    23. `blanking-panel.svg` — solid rectangle (rack filler)
    24. `pdu.svg` — long rectangle with outlet pips
    25. `equipment-rack.svg` — vertical rectangle with U-numbered side rail

    Each file's first line includes XML prolog `<?xml version="1.0" encoding="UTF-8"?>`. Each SVG ends with a comment `<!-- AVIXA D401.01-aligned. Phase 17 v1. -->` so future audits can grep.

    **`resources/svg/av-symbols/README.md`** — catalogue listing each symbol, its AVIXA D401.01 reference (where one exists), and a pointer to research SUMMARY.md GAP-2 (AVIXA symbol licensing — using conventions to draw our own SVGs is fine; redistributing AVIXA artwork is not). The README also includes a "Visual verification (Nit 11)" section noting that Task 3's feature tests cover the D2 escape + signal-flow output path, but per-symbol AVIXA visual fidelity is a manual review item — open each SVG in a browser, eyeball against the D401.01 reference, and check the symbol README off in code review.

    **(b) `config/drawings.php`:**

    ```php
    <?php

    /*
     * Configuration for v1.3 drawings (Phases 17–20).
     *
     * Phase 17: schematic generator (D2 CLI) + signal-type colour coding.
     * Phase 18: rack elevations (custom Blade SVG — no D2).
     * Phase 19: floor plans (Konva — separate Vite entry).
     * Phase 20: drawing export pipeline (PDF/SVG/PNG/ZIP, O&M integration).
     */

    return [
        // ── D2 CLI (Phase 17) ─────────────────────────────────────────────────
        // Production AlmaLinux: install via `curl -fsSL https://d2lang.com/install.sh | sh -s -- --version v0.7.1` to /usr/local/bin/d2.
        // Local dev: per-engineer install path (set D2_BINARY_PATH in .env).
        // See .planning/research/STACK.md §1.1 + .planning/research/SUMMARY.md installation summary.
        'd2_binary_path' => env('D2_BINARY_PATH', '/usr/local/bin/d2'),
        'd2_layout'      => env('D2_LAYOUT', 'elk'),     // elk | dagre | tala (paid) — elk is best for AV signal flow
        'd2_timeout'     => 60,                           // seconds before Process aborts
        'd2_pinned_version' => '0.7.1',                   // for runbook / version-drift checks

        // ── Symbol pack (Phase 17) ────────────────────────────────────────────
        // Resolved to absolute path at render time so D2 can `shape: image; icon: file://...`.
        'symbol_pack_path' => resource_path('svg/av-symbols'),

        // ── Signal-type colour map (DRAW-02) ──────────────────────────────────
        // AVIXA convention + FEATURES.md Phase 17 anatomy. Hex values chosen for
        // accessibility (WCAG AA on white background) — DO NOT pick brighter
        // alternatives unless reviewed against the same standard.
        'signal_colours' => [
            'audio'   => '#C0392B',  // red — clear audio chain marker
            'video'   => '#2980B9',  // blue
            'control' => '#27AE60',  // green
            'network' => '#8E44AD',  // purple — Dante / IP / Cat6
            'usb'     => '#E67E22',  // orange
            'power'   => '#7F8C8D',  // grey (dashed) — DC trigger / 12V
            'unknown' => '#000000',  // black — undirected line for ambiguous cables
        ],

        // ── Schematic title block fields (DRAW-22) ────────────────────────────
        // Minimum set per CONTEXT.md "Claude's Discretion".
        // Phase 20 may extend with "Checked by" / "Approved by" once status workflow matures.
        'title_block_fields' => ['project_ref', 'client', 'drawn_by', 'date', 'revision', 'status'],
    ];
    ```

    **(c) `app/Services/Drawings/DrawingDataResolverService.php` — implement `adjacencyForProject()`:**

    Replace the Plan 01 `return []` stub with the real implementation. Body should:
    1. `$data = $this->projectDataService->resolve($project);`
    2. Iterate `$data['rooms']` (canonical room list).
    3. For each room: filter `$data['equipment']` to that room (matching by `room_id` or `room_name` per existing convention in `InstallTaskGeneratorService`).
    4. Lookup each device's `signal_role` from the `devices` table (the package equipment list may not have it — fall back to checking the `Device` model where `project_id + part_no` matches; if no match, leave `signal_role => null`).
    5. Filter `$data['cables']` to that room (cables include a `room_id` per existing schema; if absent, include all cables that touch any equipment in this room).
    6. Reshape into the documented return shape:
       ```php
       [
         'room_id' => $room['id'] ?? null,
         'room_name' => (string) ($room['name'] ?? ''),
         'devices' => [
           ['equipment_id' => ..., 'name' => ..., 'manufacturer' => ..., 'model' => ..., 'signal_role' => ...],
           ...
         ],
         'cables' => [
           ['cable_id' => ..., 'source_equipment_id' => ..., 'source_port' => ..., 'dest_equipment_id' => ..., 'dest_port' => ..., 'signal_type' => ...],
           ...
         ],
       ]
       ```

    Add a defensive guard: if `$data['cables']` is missing or empty, return an empty cables array per room (the schematic generator will then emit only the device boxes). Log via `Log::info('DrawingDataResolverService: no cables for project', ['project_id' => $project->id])` (matches CLAUDE.md logging convention).

    Important: this method MUST consume `$data['cables']` exactly as resolved — never re-derive cable IDs or ports from raw extracted_data/reviewed_data (DATA-03 contract).
  </action>
  <acceptance_criteria>
    - `ls resources/svg/av-symbols/*.svg | wc -l` returns 25.
    - `du -sb resources/svg/av-symbols/` reports total size <102400 bytes (100 KB).
    - Every SVG file starts with `<?xml version="1.0"` (`grep -c '<?xml version="1.0"' resources/svg/av-symbols/*.svg | grep -v ':0$' | wc -l` equals 25).
    - No SVG contains `<foreignObject>` (`grep -l "foreignObject" resources/svg/av-symbols/*.svg` returns nothing).
    - `resources/svg/av-symbols/README.md` exists and lists all 25 symbols.
    - `config/drawings.php` exists with keys `d2_binary_path`, `d2_layout`, `signal_colours`, `symbol_pack_path`, `title_block_fields` — `php artisan tinker --execute="echo array_key_exists('d2_binary_path', config('drawings')) ? 'PASS' : 'FAIL';"` returns `PASS`.
    - `config('drawings.signal_colours.audio')` returns `'#C0392B'` (and similarly for video/control/network/usb).
    - `app/Services/Drawings/DrawingDataResolverService.php::adjacencyForProject` no longer returns `[]` — `grep -n "ProjectDataService\|signal_role\|cables" app/Services/Drawings/DrawingDataResolverService.php` shows the resolver wired.
    - `php -l app/Services/Drawings/DrawingDataResolverService.php` reports no syntax errors.
    - `php -l config/drawings.php` reports no syntax errors.
  </acceptance_criteria>
  <verify>
    <automated>php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->boot(); echo array_key_exists('d2_binary_path', config('drawings')) && count(config('drawings.signal_colours')) >= 6 ? 'PASS' : 'FAIL';"</automated>
  </verify>
  <done>Symbol pack present, config wired, resolver implemented and consumes ProjectDataService::resolve() exclusively.</done>
</task>

<task type="auto" tdd="false">
  <name>Task 2: SchematicD2SourceBuilder + SchematicGeneratorService + Job wire-up</name>
  <read_first>
    - .planning/research/PITFALLS.md CRIT-05 (NEVER infer signal direction from cable-row order)
    - .planning/research/PITFALLS.md MIN-08 (AI usage constraint — Phase 17 generation has ZERO AI calls)
    - app/Services/PdfOcrExtractorService.php (Symfony Process pattern for shelling out)
    - app/Services/Drawings/DrawingService.php (Plan 01 — orchestrator that dispatches BuildSchematicJob)
    - app/Jobs/BuildSchematicJob.php (Plan 01 placeholder — Plan 02 replaces ONLY the SVG-writing block in handle(); leave the surrounding mail dispatch + failed() hook UNTOUCHED — Plan 03 also edits this file in a different region per Warning 6)
    - .planning/research/STACK.md §1.1 D2 — install + run model
  </read_first>
  <action>
    Three files: D2 source builder, generator orchestrator, and job replacement. Plus updating BuildSchematicJob.

    **`app/Services/Drawings/SchematicD2SourceBuilder.php`:**

    Pure deterministic emitter — no I/O, no Eloquent, no AI. Takes the per-room adjacency array from `DrawingDataResolverService::adjacencyForProject()` (one room's worth) and emits a D2 source string.

    Class signature:
    ```php
    namespace App\Services\Drawings;

    /**
     * Phase 17 — Pure D2 DSL emitter. Deterministic, no I/O, no AI.
     *
     * Direction enforcement: cable arrows derive from Device::signal_role classification
     * (loaded into adjacency rows). Cables touching a device with signal_role=null OR
     * connecting two processors render as undirected lines (CRIT-05).
     *
     * Label safety (T-17.02-01 / Warning 7): sanitiseLabel() escapes the FULL D2 DSL
     * meta-character set so a crafted equipment name from a QuoteWerks import cannot
     * inject D2 directives. See sanitiseLabel() docblock for the exact escape table.
     */
    class SchematicD2SourceBuilder
    {
        public function __construct(private readonly array $config) {}
        // $config is config('drawings').

        /**
         * @param array{room_id:int|null,room_name:string,devices:array,cables:array} $room
         * @return array{source:string, warnings:list<string>, ambiguous_cables:int}
         */
        public function build(array $room): array;

        /**
         * Public so the test suite can exercise it in isolation (Warning 7 crafted-name test).
         *
         * D2 DSL meta characters that MUST be escaped when inlining a string into
         * a double-quoted D2 label:
         *   1. Backslash         \    → \\
         *   2. Double-quote      "    → \"
         *   3. Backtick          `    → \`        (D2 supports raw-string blocks delimited by backticks)
         *   4. Newline / CR      \n   → space    (multi-line strings use a different delimiter; collapse to space)
         *   5. Other control     0x00–0x1F → strip (avoids smuggling NULs / VT into the source file)
         *
         * Order matters: escape backslash FIRST, then double-quote, then backtick.
         * Otherwise the backslash escape will double-escape the others.
         */
        public function sanitiseLabel(string $raw): string;
    }
    ```

    The `build()` method emits:

    1. **Header** — `direction: right` (AVIXA convention: source → destination flows left-to-right).
    2. **Style block** — labels font, edge label style.
    3. **Per-device node** — one D2 node per device, keyed on a sanitised `equipment_id`:
       ```
       device_42: "Sony Bravia 65\""  {
         shape: image
         icon: file:///<absolute path to resources/svg/av-symbols/{symbol}.svg>
       }
       ```
       - Symbol selection: a small allowlist matching device name/category to one of the 25 symbol filenames. Default → `generic-source.svg` (sources), `generic-destination.svg` (destinations), `switcher.svg` (processors).
       - Sources placed in column "sources" group, destinations in "destinations", processors in "middle".
       - **Labels run through `sanitiseLabel()` BEFORE inlining into the D2 source string. Equipment IDs run through the existing `preg_replace('/[^a-zA-Z0-9_]/', '_', ...)` ID sanitiser.**
    4. **Per-cable edge** — one D2 connection per cable:
       - When `cable.signal_type` is set → use the corresponding `signal_colours.{type}` hex.
       - When source-device's `signal_role === 'source'` AND destination-device's `signal_role === 'destination'` → directed arrow (`->`).
       - When either device has `signal_role === null` OR both are processors → undirected line (`--`) and append a warning to the warnings list (CRIT-05).
       - Edge label: `sanitiseLabel("{cable_id} ({source_port} → {dest_port})")` — character-for-character from the cable schedule (DRAW-03), with the same escape pass.
    5. **Title** — top-level `title: sanitiseLabel("Signal Flow — {room_name}")`.

    `sanitiseLabel()` reference implementation (executor implements verbatim):
    ```php
    public function sanitiseLabel(string $raw): string
    {
        // 1. Strip control characters (0x00–0x1F) — collapses newlines too.
        $clean = preg_replace('/[\x00-\x1F]/u', ' ', $raw) ?? '';
        // 2. Order matters: backslash FIRST, otherwise it double-escapes the next two.
        $clean = str_replace('\\', '\\\\', $clean);
        // 3. Double-quote.
        $clean = str_replace('"', '\\"', $clean);
        // 4. Backtick (D2 raw-string delimiter — escape so labels can never open one).
        $clean = str_replace('`', '\\`', $clean);
        return $clean;
    }
    ```

    Note on the dollar sign / `${...}` interpolation concern from the checker: D2 v0.7.1 does NOT perform shell-style `$var` interpolation inside double-quoted labels (interpolation lives in `vars: { ... }` blocks at the source-document level, not inside string literals). No escape needed for `$`. Documented here so a future executor doesn't add a redundant `$ → \$` step.

    Return an array with the source string + warnings + ambiguous_cables count.

    **`app/Services/Drawings/SchematicGeneratorService.php`:**

    The orchestrator. Constructor injects `DrawingDataResolverService`, `SchematicD2SourceBuilder`, `DocumentArtifactStorage`. Public method:

    ```php
    public function generate(ProjectDrawing $drawing): void
    ```

    Body:
    1. Validate `$drawing->kind === ProjectDrawing::KIND_SCHEMATIC`; throw if not.
    2. Validate `$drawing->status === ProjectDrawing::STATUS_GENERATING` (job sets this); throw if not.
    3. `$adjacency = $this->resolver->adjacencyForProject($drawing->project)`.
    4. Find the room matching `$drawing->site_survey_room_id` (or first room when null = whole-project schematic, deferred per CONTEXT.md but stub the path so it returns "no room" SVG safely).
    5. `$result = $this->builder->build($room)` → D2 source.
    6. Write source to a temp file under `storage/app/tmp/d2/schematic-{drawing.id}-v{version}.d2`.
    7. Determine output path via `$artifacts->writePath(TYPE_DRAWING, "schematic-{$drawing->id}-v{$drawing->version}-" . strtolower(\Illuminate\Support\Str::ulid()) . ".svg")`.
    8. Invoke D2 via Symfony Process — same pattern as `PdfOcrExtractorService`:
       ```php
       $process = new \Symfony\Component\Process\Process([
           config('drawings.d2_binary_path'),
           "--layout=" . config('drawings.d2_layout'),
           $tmpD2Path,
           $outputSvgPath,
       ]);
       $process->setTimeout(config('drawings.d2_timeout'));
       $process->run();
       if (!$process->isSuccessful()) {
           throw new \RuntimeException('D2 CLI failed: ' . substr($process->getErrorOutput(), 0, 400));
       }
       ```
    9. Read SVG bytes from `$outputSvgPath`; write into `$drawing->generated_svg`.
    10. Set `$drawing->filename = basename($outputSvgPath)`, `$drawing->status = STATUS_READY`, save.
    11. Log warnings (`Log::warning('SchematicGeneratorService: ambiguous cables', ['drawing_id' => $drawing->id, 'count' => $result['ambiguous_cables']])`) when present.
    12. Clean up tmp file.

    No AI calls anywhere. Pure data → text → SVG.

    **`app/Jobs/BuildSchematicJob.php` — replace ONLY the placeholder SVG-writing block in `handle()`:**

    Plan 01 wrote a placeholder SVG inline inside a clearly-marked block (`// ── Plan 17-01 placeholder body — Plan 17-02 REPLACES this block ──` / `// ── End Plan 17-01 placeholder body ──`). Replace JUST that block; leave the surrounding mail dispatch + idempotency timestamp + try/catch error handling + `failed()` method UNTOUCHED. Inject `SchematicGeneratorService` into `handle()` (Laravel's container resolves it).

    Resulting `handle()` shape (only the diffed region shown; the rest is verbatim from Plan 01):

    ```php
    public function handle(SchematicGeneratorService $generator): void
    {
        $drawing = ProjectDrawing::find($this->drawingId);
        if (! $drawing) {
            Log::warning('BuildSchematicJob: record not found, discarding', ['drawing_id' => $this->drawingId]);
            return;
        }

        if ($drawing->status !== ProjectDrawing::STATUS_GENERATING) {
            $drawing->update(['status' => ProjectDrawing::STATUS_GENERATING]);
        }

        Log::info('BuildSchematicJob: starting', [
            'drawing_id' => $this->drawingId,
            'attempt'    => $this->attempts(),
            'kind'       => $drawing->kind,
        ]);

        try {
            // ── Plan 17-02 REAL generator (replaces Plan 17-01 placeholder block) ──
            $generator->generate($drawing);
            // SchematicGeneratorService sets generated_svg, filename, and status=READY itself.
            // ── End Plan 17-02 generator block ──────────────────────────────────────

            // ↓ EVERYTHING BELOW THIS LINE IS UNCHANGED FROM PLAN 17-01 ↓
            Log::info('BuildSchematicJob: completed successfully', [...]);

            // Idempotent completion email — UNCHANGED from Plan 01.
            $drawing->refresh();
            if ($drawing->status === ProjectDrawing::STATUS_READY
                && $drawing->completion_email_sent_at === null) {
                $drawing->update(['completion_email_sent_at' => now()]);
                try {
                    $resolver  = app(NotificationRecipientResolver::class);
                    $recipient = $resolver->resolveProjectRecipient($drawing->project);
                    if ($recipient?->email) {
                        $pending = Mail::to($recipient->email);
                        $bcc = config('rams.notifications.bcc');
                        if (is_string($bcc) && trim($bcc) !== '') {
                            $pending->bcc(trim($bcc));
                        }
                        $pending->send(new DrawingReadyMail($drawing));
                    }
                } catch (\Throwable $mailErr) {
                    Log::warning('BuildSchematicJob: completion email send failed', ['drawing_id' => $drawing->id, 'error' => $mailErr->getMessage()]);
                }
            }
        } catch (\Throwable $e) {
            // UNCHANGED failed-status flip + rethrow from Plan 01.
            Log::error('BuildSchematicJob: failed', [...]);
            try { $drawing->update(['status' => ProjectDrawing::STATUS_FAILED, 'error_message' => substr($e->getMessage(), 0, 500)]); }
            catch (\Throwable $dbErr) { Log::critical('BuildSchematicJob: could not set failed status', [...]); }
            throw $e;
        }
    }
    ```

    The `failed()` method stays UNTOUCHED from Plan 01 — admin alert pattern is identical regardless of placeholder vs real generator.

    **Cross-file coordination:** Plan 03 Task 1 will ALSO edit this file to insert a thumbnail render block AFTER `$generator->generate($drawing)` succeeds and BEFORE the completion email send. Plan 03's `depends_on: ["17-01", "17-02"]` (Warning 6) ensures Plan 02 runs first and Plan 03 sees this file's post-Plan-02 state.
  </action>
  <acceptance_criteria>
    - `app/Services/Drawings/SchematicD2SourceBuilder.php` exists; `grep -n "build\|signal_role\|undirected\|ambiguous_cables\|signal_colours\|cable_id\|sanitiseLabel" app/Services/Drawings/SchematicD2SourceBuilder.php` shows all seven tokens.
    - `app/Services/Drawings/SchematicGeneratorService.php` exists; `grep -n "DrawingDataResolverService\|SchematicD2SourceBuilder\|DocumentArtifactStorage\|TYPE_DRAWING\|Symfony.*Process\|d2_binary_path" app/Services/Drawings/SchematicGeneratorService.php` shows all six tokens.
    - `grep -n "SchematicGeneratorService\|generator->generate" app/Jobs/BuildSchematicJob.php` confirms job calls real generator.
    - Searching for the Plan 01 placeholder marker should fail: `grep -n "Phase 17 Plan 02 will implement" app/Jobs/BuildSchematicJob.php` returns nothing (placeholder removed).
    - **Surrounding mail dispatch preserved** (Warning 6 — Plan 03 will edit this file next): `grep -n "DrawingReadyMail\|completion_email_sent_at\|resolveProjectRecipient" app/Jobs/BuildSchematicJob.php` still returns hits (NOT removed by accident).
    - Zero AI calls in the generator path: `grep -rn "AIManager\|->run(" app/Services/Drawings/SchematicGeneratorService.php app/Services/Drawings/SchematicD2SourceBuilder.php` returns nothing.
    - `php -l app/Services/Drawings/SchematicD2SourceBuilder.php app/Services/Drawings/SchematicGeneratorService.php app/Jobs/BuildSchematicJob.php` reports no syntax errors.
    - When D2 binary is unavailable on a dev machine, `SchematicGeneratorService::generate()` throws `RuntimeException` with a clear message — guard tested by Task 3.
  </acceptance_criteria>
  <verify>
    <automated>php -l app/Services/Drawings/SchematicD2SourceBuilder.php app/Services/Drawings/SchematicGeneratorService.php app/Jobs/BuildSchematicJob.php 2>&1 | grep -c "No syntax errors detected" | grep -q "^3$" && echo PASS || echo FAIL</automated>
  </verify>
  <done>Real D2-driven generator wired into BuildSchematicJob; CRIT-05 prevented at the source builder; full D2 DSL escape (Warning 7) implemented in sanitiseLabel(); zero AI calls in generation path; Plan 01's mail-dispatch surroundings preserved for Plan 03 (Warning 6).</done>
</task>

<task type="auto" tdd="false">
  <name>Task 3: Schematic Blade view + title block partial + feature test (incl. crafted-name escape test)</name>
  <read_first>
    - resources/views/pdf/site-survey/_styles.blade.php (Browsershot-friendly CSS pattern)
    - resources/views/pdf/site-survey/summary.blade.php (existing PDF Blade reference)
    - .planning/research/PITFALLS.md MIN-04 (page-break-inside: avoid for embedded SVGs)
    - .planning/research/PITFALLS.md MIN-09 (avoid asserting HTML internals — test file_exists + bytes)
    - .planning/research/FEATURES.md Phase-Crossing AV Conventions (title block fields)
    - app/Services/PdfRenderService.php (rendering wrapper used by all phases)
    - tests/ — directory of existing feature tests for shape reference
  </read_first>
  <action>
    Three deliverables: schematic Blade view, title block partial, and feature test.

    **`resources/views/pdf/drawings/_title-block.blade.php`:**

    Reusable partial consumed by Phases 17/18/19/20. Receives `$drawing` (ProjectDrawing) and `$config` (config('rams.company') + project metadata). Renders an A4-fit title block at the bottom-right of the page (matches AV deliverable convention from FEATURES.md "Phase-Crossing AV Conventions"):

    ```blade
    @php
        $project = $drawing->project;
        $drawnBy = optional($drawing->generatedBy)->name ?? '—';
        $today   = $drawing->updated_at?->format('Y-m-d') ?? now()->format('Y-m-d');
        $rev     = $drawing->revisionLabel();
        $statusLbl = $drawing->statusLabel();
    @endphp
    <table class="title-block">
        <tr>
            <td colspan="2" class="title-block__title">{{ $drawing->kindLabel() }}</td>
        </tr>
        <tr><td>Project Ref</td><td>{{ $project->ref ?? '—' }}</td></tr>
        <tr><td>Client</td><td>{{ $project->client_name ?? $project->client ?? '—' }}</td></tr>
        <tr><td>Drawn by</td><td>{{ $drawnBy }}</td></tr>
        <tr><td>Date</td><td>{{ $today }}</td></tr>
        <tr><td>Revision</td><td>{{ $rev }}</td></tr>
        <tr><td>Status</td><td>{{ $statusLbl }}</td></tr>
    </table>
    ```

    Inline `<style>` block at top of partial defines `.title-block` table (border, sans-serif font, 10pt, fits within bottom-right corner of A4 portrait).

    **`resources/views/pdf/drawings/schematic.blade.php`:**

    Top-level Blade view rendered by `PdfRenderService::fromBlade('pdf.drawings.schematic', ['drawing' => $drawing])` in Plan 03. Phase 17 Plan 02 creates the file; Plan 03 wires PdfRenderService to use it.

    Structure:
    ```blade
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>{{ $drawing->kindLabel() }} — {{ $drawing->project->ref ?? '' }}</title>
        <style>
            @page { size: A4 landscape; margin: 10mm; }
            body  { font-family: 'Figtree', sans-serif; margin: 0; padding: 0; }
            .schematic-page { display: flex; flex-direction: column; height: 100vh; page-break-inside: avoid; break-inside: avoid; }
            .schematic-svg-wrap { flex: 1; display: flex; align-items: center; justify-content: center; max-height: 80vh; overflow: hidden; }
            .schematic-svg-wrap svg { max-width: 100%; max-height: 100%; }
            /* Title block bottom-right */
            .title-block-wrap { display: flex; justify-content: flex-end; padding: 6mm 10mm; }
        </style>
    </head>
    <body>
        <div class="schematic-page">
            <div class="schematic-svg-wrap">
                @if (!empty($drawing->generated_svg))
                    {!! $drawing->generated_svg !!}
                @else
                    <p>Schematic SVG not available — regenerate this drawing.</p>
                @endif
            </div>
            <div class="title-block-wrap">
                @include('pdf.drawings._title-block', ['drawing' => $drawing])
            </div>
        </div>
    </body>
    </html>
    ```

    Notes:
    - `{!! ... !!}` is required because `generated_svg` IS the SVG markup (DRAW-04 + DRAW-02). Trust source: only the deterministic D2 CLI writes this column; no user-controlled content; CRIT-01 prevented by never running React/Konva inside Browsershot at this stage.
    - `page-break-inside: avoid` per MIN-04.
    - **Cross-format composition smoke test (Nit 12):** the title-block-HTML + generated-SVG composition isn't acceptance-tested in Plan 02 — it's exercised end-to-end by Plan 03 Task 1's `pdf:smoke-test --drawings` extension (which renders this Blade through PdfRenderService::fromBlade). No additional test here.

    **`tests/Feature/Drawings/SchematicGeneratorServiceTest.php`:**

    Feature test verifying end-to-end generation. Use existing test conventions (look at any commissioning_items or InstallTaskGenerator test for shape).

    Test scenarios:
    1. **`it_returns_empty_svg_for_a_project_with_no_cables()`** — given a Project with rooms but no cables in canonical data, the generator writes a non-empty SVG (just the room title + devices, no edges) without throwing.
    2. **`it_writes_cable_ids_character_for_character_into_svg()`** — fixture project with 3 cables `'CBL-001'`, `'AUDIO-12'`, `'CTRL-3'`; after generate, `$drawing->generated_svg` contains all three cable_id strings (DRAW-03 verification).
    3. **`it_renders_undirected_lines_when_signal_role_is_unknown()`** — fixture device with `signal_role = null`; SchematicD2SourceBuilder result `ambiguous_cables` count > 0, and the D2 source string contains `--` (undirected) for cables touching that device, not `->`.
    4. **`it_uses_signal_type_colours_per_config()`** — fixture cable with `signal_type='audio'`; D2 source string contains `'#C0392B'` (per `config('drawings.signal_colours.audio')`).
    5. **`it_escapes_d2_dsl_meta_characters_in_crafted_equipment_names()`** (Warning 7) — feed the builder a fixture device whose name contains `Sony "Bravia" 65\` and another containing a backtick + newline + control character (e.g. `"Demo`\u{0001}\nName"`). Assertions:
       - The D2 source string emitted by `build()` does NOT contain a raw unescaped `"` after the opening label quote (i.e. the embedded quote is `\"`).
       - The D2 source contains exactly TWO node label boundaries per device (one open, one close) — verified by counting unescaped `"` per node line and asserting it equals 2.
       - `sanitiseLabel("Demo`\u{0001}\nName")` returns a string with no `\x00–\x1F` characters present (the control byte and newline are stripped to spaces).
       - When the resulting D2 source is written to a temp file and the D2 CLI is invoked (`is_executable(config('drawings.d2_binary_path'))` skip-guard), the process exits successfully — proves the source PARSES even with adversarial labels. If D2 binary unavailable, fall back to asserting that `sanitiseLabel('Crafted "boom`\\\nstop"')` returns `Crafted \\"boom\\` stop\\"` (no raw newline, escaped quote+backtick+backslash).

    For these tests, mock or skip the actual D2 CLI invocation — test the **builder** output (deterministic, fast, no binary needed) for tests 2/3/4/5. Test 1 can run end-to-end if D2 is available locally; otherwise skip with `$this->markTestSkipped('D2 binary not available')` when `!is_executable(config('drawings.d2_binary_path'))`. The CI / production smoke is covered separately by Plan 03's `pdf:smoke-test --drawings` extension.

    Use `Illuminate\Foundation\Testing\RefreshDatabase` trait. Seed minimal fixtures in `setUp()`: User, Project, ProjectPackage with extracted_data containing equipment + cables, Device rows with explicit signal_role values, ProjectDrawing in STATUS_GENERATING.
  </action>
  <acceptance_criteria>
    - `resources/views/pdf/drawings/_title-block.blade.php` exists and references all six fields from `config('drawings.title_block_fields')` (project_ref, client, drawn_by, date, revision, status).
    - `resources/views/pdf/drawings/schematic.blade.php` exists; `grep -n "page-break-inside.*avoid\|generated_svg\|@include.*_title-block" resources/views/pdf/drawings/schematic.blade.php` shows all three tokens.
    - Schematic Blade does not contain `<foreignObject>` (`grep -n "foreignObject" resources/svg/av-symbols/*.svg resources/views/pdf/drawings/schematic.blade.php` returns nothing).
    - `tests/Feature/Drawings/SchematicGeneratorServiceTest.php` exists with at least the FIVE named test methods (the four originals PLUS `it_escapes_d2_dsl_meta_characters_in_crafted_equipment_names` from Warning 7).
    - `php artisan test --filter=SchematicGeneratorServiceTest` passes (or each builder-only test passes; the end-to-end test may skip when D2 binary unavailable on dev machine).
    - **Warning 7 verification** — the crafted-name test passes its assertions on `sanitiseLabel()` output regardless of whether D2 binary is available.
    - Existing PDF smoke test command still works: `php artisan pdf:smoke-test` produces a valid PDF (regression check).
  </acceptance_criteria>
  <verify>
    <automated>php artisan test --filter=SchematicGeneratorServiceTest 2>&1 | tail -5</automated>
  </verify>
  <done>Schematic Blade view + reusable title-block partial in place; feature test exercises builder output deterministically (tests 2/3/4) and the Warning 7 crafted-name test (test 5) and end-to-end pipeline conditionally on D2 binary availability (test 1).</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Eloquent canonical data → SchematicD2SourceBuilder | Adjacency rows enter the D2 source builder; cable_id and port labels come from canonical data only |
| SchematicD2SourceBuilder → D2 CLI | D2 source written to a temp file; D2 binary invoked via Symfony Process with explicit args |
| D2 CLI → DocumentArtifactStorage | Generated SVG written via writePath (TYPE_DRAWING) — no user-controlled filename component |
| Blade view → Browsershot (Plan 03) | generated_svg embedded as `{!! ... !!}` — trust source: deterministic D2 output, never user input |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-17.02-01 | T (Tampering) | SchematicD2SourceBuilder label/ID escape | mitigate | **Equipment IDs sanitised via `preg_replace('/[^a-zA-Z0-9_]/', '_', ...)` (id namespace). Labels run through `SchematicD2SourceBuilder::sanitiseLabel()` which escapes the FULL D2 DSL meta-character set:** backslash (`\`→`\\`), double-quote (`"`→`\"`), backtick (`` ` ``→`` \` ``), control characters 0x00–0x1F (stripped to space, including newline/CR). Order matters — backslash escapes FIRST. The `$` character does NOT need escaping (D2 v0.7.1 doesn't perform shell-style interpolation inside double-quoted labels — interpolation lives in `vars: { ... }` blocks at document level). Verified by Task 3 unit test `it_escapes_d2_dsl_meta_characters_in_crafted_equipment_names` which feeds a name containing `"`, newline, backtick, and 0x01 and asserts the resulting D2 source still parses cleanly. (Warning 7 fix.) |
| T-17.02-02 | E (Elevation of privilege) | D2 CLI invocation | mitigate | Symfony Process invoked with `new Process([$binary, '--layout=...', $inFile, $outFile])` — array form, no shell interpolation. Filename is server-generated (ULID + drawing id), no user input. Timeout enforced via `setTimeout(config('drawings.d2_timeout'))`. |
| T-17.02-03 | I (Information disclosure) | Temporary D2 source file | mitigate | Temp file written under `storage/app/tmp/d2/` (private storage, not public disk). Cleaned up after Process completes (try/finally). No PII in D2 source — only equipment names and cable IDs already in canonical project data. |
| T-17.02-04 | I (Information disclosure) | Schematic SVG embed | accept | `generated_svg` rendered with `{!! ... !!}` — XSS risk only if D2 output is malicious. D2 is a trusted server-side binary processing only canonical data; no user-controlled fields enter the source. Trust boundary documented in CRIT-01. |
| T-17.02-05 | T (Tampering) | Symbol pack file:// URI | mitigate | D2 references symbols via `file://{absolute path}/{symbol}.svg` from `config('drawings.symbol_pack_path')`. Symbol filename whitelist enforced in builder (allowlist of 25 names matching files in resources/svg/av-symbols/); unknown name falls back to `generic-source.svg`. No user-controlled filename. |
| T-17.02-06 | D (Denial of service) | D2 CLI runaway | mitigate | `setTimeout(config('drawings.d2_timeout'))` (60s default) caps any runaway. BuildSchematicJob `$tries=2`, `$timeout=300` provides a second cap. No queue concurrency cap in Phase 17 (CRIT-03 hardening lands in Phase 20). |
| T-17.02-07 | I (Information disclosure) | DRAW-30 AI invention | mitigate | This plan has ZERO AI calls — pure deterministic generation. The DocumentEdits adapter from Plan 01 has a fixed operation enum; no path from AI chat into equipment list or D2 source in Phase 17. |
| T-17.02-08 | T (Tampering) | Cable ID drift vs cable schedule | mitigate | DrawingDataResolverService consumes `$data['cables']` from ProjectDataService::resolve() exactly — no rewriting. Cable IDs render character-for-character (DRAW-03), verified by test `it_writes_cable_ids_character_for_character_into_svg()`. |
| T-17.02-09 | T (Tampering) | BuildSchematicJob.php co-edited by Plan 02 + Plan 03 (Warning 6) | mitigate | Plan 17-03 frontmatter `depends_on: ["17-01", "17-02"]` forces Plan 02 to run BEFORE Plan 03. Plan 02 replaces only the placeholder SVG-writing block within `handle()`'s try block; Plan 03 inserts a thumbnail render block AFTER `$generator->generate()` succeeds and BEFORE the completion email. Disjoint regions, sequential execution, no merge conflict surface. |
</threat_model>

<verification>
1. Symbol pack: 25 SVGs present, total <100 KB, no foreignObject.
2. Config: `config('drawings.signal_colours.audio')` returns hex; `config('drawings.d2_binary_path')` resolves.
3. Generator: `php artisan tinker --execute="..."` instantiating SchematicGeneratorService and calling builder with a fixture room produces D2 source with arrow direction matching signal_role classification.
4. Job: `grep -n "Phase 17 Plan 02 will implement" app/Jobs/BuildSchematicJob.php` returns nothing (placeholder removed).
5. Test: `php artisan test --filter=SchematicGeneratorServiceTest` passes (or skips D2-dependent test cleanly when binary missing).
6. Regression: `php artisan pdf:smoke-test` (RAMS smoke baseline) still passes.
7. CRIT-05: source builder unit-test `it_renders_undirected_lines_when_signal_role_is_unknown` passes.
8. DRAW-03: source builder unit-test `it_writes_cable_ids_character_for_character_into_svg` passes.
9. DRAW-04: 25 symbols on disk, README catalogue present.
10. DRAW-02: signal-type colour map applied — verified by `it_uses_signal_type_colours_per_config` test.
11. **Warning 7** — `it_escapes_d2_dsl_meta_characters_in_crafted_equipment_names` test passes; `sanitiseLabel` is callable and idempotent.
12. **Warning 6 coordination** — `app/Jobs/BuildSchematicJob.php` post-Plan-02 still contains the mail dispatch block (`grep -q DrawingReadyMail`) so Plan 03's edit has the surrounding context it expects.
</verification>

<success_criteria>
- DRAW-01 (auto-generate per-room schematic from canonical data) — observable: dispatching BuildSchematicJob produces a generated_svg derived from ProjectDataService::resolve().
- DRAW-02 (signal-type colour coding) — observable: D2 source contains the configured hex codes for each signal_type.
- DRAW-03 (cable IDs + port labels match cable schedule character-for-character) — observable: cable_id strings appear unchanged in generated_svg; verified by feature test.
- DRAW-04 (AVIXA-style symbol library) — observable: 25 SVGs in resources/svg/av-symbols/, README.md catalogues them.
- DRAW-22 (standard title block on every sheet) — observable: schematic Blade includes the title block partial with all 6 configured fields.
- CRIT-05 prevented — observable: signal direction derives from Device::signal_role classification (never row order); ambiguous cables render as undirected (`--`) with logged warnings.
- Warning 7 mitigated — observable: `sanitiseLabel()` covers full D2 DSL escape table; crafted-name test passes.
- Warning 6 coordination — observable: BuildSchematicJob still has its mail dispatch surroundings post-Plan-02 so Plan 03 can layer thumbnail render in.
- Build-order doctrine (ARCHITECTURE.md §8) honoured: no Konva in Phase 17, no AI calls in generation, no schematic editor (deferred to Phase 19), no rack/floor-plan generators (Phase 18/19).
</success_criteria>

<output>
After completion, create `.planning/phases/17-system-schematics-shared-foundations/17-02-SUMMARY.md` documenting:
- Symbol pack contents + AVIXA alignment notes.
- Per-signal-type colour values chosen and why.
- D2 binary install steps for production AlmaLinux + local dev.
- Test coverage summary (which DRAW-XX requirements each test verifies; which test covers Warning 7).
- The exact `sanitiseLabel()` escape table (Warning 7 — for future audits).
- Pointer to Plan 03 (PDF render via PdfRenderService::fromBlade with new Blade view + UI + O&M wiring + downloads + thumbnail render block insertion in BuildSchematicJob).
</output>
</output>
