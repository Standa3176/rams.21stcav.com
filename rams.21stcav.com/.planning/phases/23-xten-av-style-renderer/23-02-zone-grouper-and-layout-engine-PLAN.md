---
phase: 23
plan: 02
type: execute
wave: 2
depends_on: [23-01]
files_modified:
  - app/Services/Drawings/ZoneGrouper.php
  - app/Services/Drawings/XtenAvLayoutEngine.php
  - tests/Feature/Drawings/ZoneGrouperTest.php
  - tests/Feature/Drawings/XtenAvLayoutEngineTest.php
autonomous: true
requirements:
  - DRAW-42
  - DRAW-46
tags: [renderer, layout, zone, mxgraph, deterministic, v2.0]
must_haves:
  truths:
    - "ZoneGrouper::assign() returns a deterministic array<string,array<int,array>> grouping device lines by resolved zone per the D-01/D-02/D-04 precedence ladder"
    - "Per-device zone override (D-02 — `$line['zone']` set) wins over the category-map default (D-01)"
    - "Free-text zone string (D-04 escape hatch) creates a distinct group per unique case-sensitive string"
    - "Missing/unrecognised category falls through to 'OTHER' zone deterministically"
    - "XtenAvLayoutEngine::placeDevices() emits an array of mxCell descriptors with zone-group parent ids and stable (x, y) coordinates per zone"
    - "XtenAvLayoutEngine emits a dashed-bordered zone container mxCell BEFORE its child device cells with `parent=` matching for child devices (DRAW-46)"
    - "Stencil base64 embedding follows the existing DrawIoBuilderService pattern (`shape=stencil(<base64>)`) for both Tier 2 curated AND Tier 1 placeholder stencils (DRAW-42, Phase 21 D-04 carry-forward)"
  artifacts:
    - path: "app/Services/Drawings/ZoneGrouper.php"
      provides: "Zone derivation per D-01/D-02/D-04 precedence ladder"
      exports: ["assign"]
    - path: "app/Services/Drawings/XtenAvLayoutEngine.php"
      provides: "Device-cell placement + zone group container emission"
      exports: ["placeDevices"]
    - path: "tests/Feature/Drawings/ZoneGrouperTest.php"
      provides: "DRAW-46 derivation tests (category default + per-device override + free-text + OTHER fallback)"
      contains: "test_per_device_zone_override_wins"
    - path: "tests/Feature/Drawings/XtenAvLayoutEngineTest.php"
      provides: "DRAW-42 + DRAW-46 layout tests (zone container before children, parent chain, determinism)"
      contains: "test_zone_emits_dashed_group_with_children"
  key_links:
    - from: "app/Services/Drawings/ZoneGrouper.php"
      to: "config('drawings.category_to_zone')"
      via: "constructor or config() read"
      pattern: "config\\('drawings\\.category_to_zone'\\)"
    - from: "app/Services/Drawings/XtenAvLayoutEngine.php"
      to: "app/Services/Drawings/ZoneGrouper.php"
      via: "constructor-injected dependency"
      pattern: "ZoneGrouper"
    - from: "app/Services/Drawings/XtenAvLayoutEngine.php"
      to: "app/Models/DeviceStencil.mxgraph_xml"
      via: "base64-embed via shape=stencil(...) style fragment"
      pattern: "base64_encode|shape=stencil"
---

<objective>
Ship the two read-only renderer helpers that take `Project::devicesWithStencils()` output and produce zone-grouped mxCell descriptors. ZoneGrouper resolves the zone per device per D-01/D-02/D-04 precedence; XtenAvLayoutEngine emits the dashed-group container + device cells with stable coordinates.

Purpose: this is the spine of Phase 23's visual deliverable. DRAW-42 (device-card stencils render with manufacturer + name + model + port rails) and DRAW-46 (sub-room zones as dashed groups) are the user-visible output. Decisions D-01 (config map), D-02 (per-device override), D-04 (free-text escape hatch) ship here. The mxGraph `parent="<zone-cell-id>"` group pattern from 23-RESEARCH.md Example 5 is the contract.

Output:
- `app/Services/Drawings/ZoneGrouper.php` — single-responsibility helper
- `app/Services/Drawings/XtenAvLayoutEngine.php` — single-responsibility helper
- 2 PHPUnit feature tests (covers 9 acceptance cases from 23-RESEARCH.md per-task verification map)
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/phases/23-xten-av-style-renderer/23-CONTEXT.md
@.planning/phases/23-xten-av-style-renderer/23-RESEARCH.md
@.planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-1-CATEGORIES.md
@.planning/phases/23-xten-av-style-renderer/23-01-SUMMARY.md
@.planning/phases/21-device-port-catalog-stencil-cache/21-01-schema-models-cache-service-SUMMARY.md
@.planning/phases/21-device-port-catalog-stencil-cache/21-03-manufacturer-logos-builder-integration-SUMMARY.md
@app/Services/Drawings/DrawIoBuilderService.php
@app/Models/DeviceStencil.php
@app/Models/DevicePort.php
@config/drawings.php

<interfaces>
<!-- Contracts ZoneGrouper + XtenAvLayoutEngine must honour. -->

From Project::devicesWithStencils() — input shape:
```php
// Returns array<int, array{
//   'part_number': string,         // e.g. 'NEAT-BAR-PRO'
//   'manufacturer': string,        // e.g. 'Neat'
//   'model': string,
//   'name': string,
//   'quantity': int,
//   'area': string,
//   'category': string,            // D-01 derivation key
//   'zone'?: string,               // D-02 per-device override (NEW in Phase 23)
//   'stencil': ?DeviceStencil,     // null only if part_number is empty
// }>
```

From DeviceStencil model (Phase 21):
```php
// Public properties:
//   $stencil->mxgraph_xml       // <shape>...</shape> XML — base64-embed via shape=stencil(...)
//   $stencil->source            // 'auto-generated' | 'engineer-curated' | 'ai-extracted'
//   $stencil->display_name
//   $stencil->manufacturer
//   $stencil->model
//   $stencil->default_width     // smallint, default 220
//   $stencil->default_height    // smallint, default 140
//   $stencil->isCurated(): bool // helper — Phase 24 source enum check
```

From DrawIoBuilderService::emitMxGraph (Phase 21 P03) — the base64 pattern to mirror:
```php
// File: app/Services/Drawings/DrawIoBuilderService.php
// Lines ~296-310 — current pattern for embedding mxgraph_xml as stencil:
//   $style = "shape=stencil(" . base64_encode($stencil->mxgraph_xml) . ");...";
// XtenAvLayoutEngine reuses the same pattern verbatim.
```

From config/drawings.php (Phase 23 Plan 01):
```php
'zone_vocab' => ['RACK', 'CEILING', 'WALL', 'TABLE', 'RECEPTION', 'FLOOR', 'PAGING_STATION', 'EXTERNAL', 'OTHER'],
'category_to_zone' => [...],   // map shaped per OQ-1 disposition
'page_dimensions' => ['width' => 1600, 'height' => 1000, ...],
```

From .planning/phases/23-xten-av-style-renderer/23-RESEARCH.md Example 5 (lines 581-595) — zone container shape:
```xml
<mxCell id="zone-rack" value="RACK"
        style="rounded=0;dashed=1;dashPattern=5 5;fillColor=none;strokeColor=#888888;strokeWidth=1;fontSize=10;fontColor=#666666;verticalAlign=top;align=left;spacingTop=4;spacingLeft=8;"
        vertex="1" parent="1">
  <mxGeometry x="60" y="60" width="500" height="320" as="geometry"/>
</mxCell>
<mxCell id="dev-1" value="..." style="..." vertex="1" parent="zone-rack">
  <mxGeometry x="80" y="80" width="220" height="140" as="geometry"/>
</mxCell>
```

XSS escape pattern (mandatory — mirror Phase 21):
```php
// File: app/Services/Drawings/DrawIoBuilderService.php lines 407-410
htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8')
```
EVERY user-supplied string (zone label, device name) passes through this BEFORE interpolation. T-23-02-A1 mitigation.
</interfaces>
</context>

<threat_model>

## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Free-text zone string | Engineer-typed (D-04 escape hatch) — interpolated into mxGraph XML `value="..."` attribute |
| Device name (from QuoteWerks parse) | Untrusted upstream — interpolated into mxCell value |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-23-02-A1 | Tampering (XSS) | Free-text zone string interpolated into `<mxCell value="...">` zone container | mitigate | Every interpolation in ZoneGrouper + XtenAvLayoutEngine passes through `htmlspecialchars(ENT_XML1 \| ENT_QUOTES, 'UTF-8')` — same pattern as DrawIoBuilderService line 407. Helper method `xml()` private to each class. Per Pitfall 8 (23-RESEARCH.md line 400-404). |
| T-23-02-A2 | Tampering (XSS) | Device `name` field interpolated into mxCell value attribute | mitigate | Same `xml()` helper. Already enforced by DrawIoBuilderService Phase 21 P02 Warning 7 — verify Plan 02 doesn't regress. |
| T-23-02-A3 | Denial of Service | Engineer types 10 different "RACK" variants ("RACK", "Rack", "Equipment Rack", ...) → many tiny dashed groups → diagram unreadable but renders | accept | D-04 documented tradeoff. Renderer creates a group per unique case-sensitive string. Pitfall 5 mitigation = dropdown helper text in Plan 06 ("Free text creates a separate group — use the dropdown for consistency"). Not a Phase 23 renderer bug. |

</threat_model>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: ZoneGrouper — assign zone per device per D-01/D-02/D-04 precedence</name>
  <files>
    app/Services/Drawings/ZoneGrouper.php,
    tests/Feature/Drawings/ZoneGrouperTest.php
  </files>
  <read_first>
    - .planning/phases/23-xten-av-style-renderer/23-CONTEXT.md D-01 (lines 53-67) + D-02 (lines 69) + D-04 (lines 73-77) — zone derivation contract
    - .planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-1-CATEGORIES.md (full file — selected Path informs the category-to-zone shape)
    - .planning/phases/23-xten-av-style-renderer/23-RESEARCH.md §"Pitfall 5" (lines 379-383 — free-text divergence) + §"Pitfall 8" (lines 400-404 — XSS via free-text)
    - app/Services/Drawings/DrawIoBuilderService.php lines 60-90 (current STENCIL_ROLES + ROLE_COLUMN heuristic — ZoneGrouper replaces this for Phase 23) AND lines 400-415 (xml() escape helper to mirror)
    - config/drawings.php (full file — confirm `category_to_zone` and `zone_vocab` keys are present from Plan 01 Task 3)
    - app/Models/Project.php (devicesWithStencils() return shape)
  </read_first>
  <behavior>
    - `ZoneGrouper::assign(array $lines): array<string, array<int, array>>` returns a map from zone name → ordered list of device lines belonging to that zone
    - Precedence per device line:
      1. If `$line['zone']` is a non-empty string → use it verbatim (D-02 override; D-04 free-text path)
      2. Else if `$line['category']` is in `config('drawings.category_to_zone')` → use the mapped zone (D-01)
      3. Else → 'OTHER' (fallback)
    - Zone groups returned in DETERMINISTIC order: zones present in `config('drawings.zone_vocab')` come first in vocab order; free-text zones come after, sorted alphabetically by zone string
    - Device lines within a zone preserve input order from `devicesWithStencils()` (stable)
    - Empty input → empty array
    - Lines with `$line['stencil'] === null` are EXCLUDED (these are empty-part_number rows per Phase 21 Plan 01)
  </behavior>
  <action>
**Step 1 — TDD RED — write `tests/Feature/Drawings/ZoneGrouperTest.php` first:**

```php
<?php

namespace Tests\Feature\Drawings;

use App\Services\Drawings\ZoneGrouper;
use Tests\TestCase;

/**
 * Phase 23 Plan 02 Task 1 — DRAW-46 zone derivation contract per D-01/D-02/D-04.
 */
class ZoneGrouperTest extends TestCase
{
    private ZoneGrouper $grouper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->grouper = app(ZoneGrouper::class);
        // Lock the config the test relies on; tests stay deterministic even
        // if OQ-1 disposition changes the seed config later.
        config()->set('drawings.zone_vocab', [
            'RACK', 'CEILING', 'WALL', 'TABLE', 'RECEPTION', 'FLOOR',
            'PAGING_STATION', 'EXTERNAL', 'OTHER',
        ]);
        config()->set('drawings.category_to_zone', [
            'rack-mount-switch' => 'RACK',
            'ceiling-mic'       => 'CEILING',
            'display'           => 'WALL',
        ]);
    }

    public function test_category_to_zone_derives_rack(): void
    {
        $lines = [
            ['part_number' => 'NETGEAR-1', 'category' => 'rack-mount-switch', 'stencil' => 'present'],
        ];
        $grouped = $this->grouper->assign($lines);
        $this->assertArrayHasKey('RACK', $grouped);
        $this->assertCount(1, $grouped['RACK']);
    }

    public function test_per_device_zone_override_wins(): void
    {
        // category would default to RACK; override should send to CEILING
        $lines = [
            ['part_number' => 'X', 'category' => 'rack-mount-switch', 'zone' => 'CEILING', 'stencil' => 'present'],
        ];
        $grouped = $this->grouper->assign($lines);
        $this->assertArrayHasKey('CEILING', $grouped);
        $this->assertArrayNotHasKey('RACK', $grouped);
    }

    public function test_free_text_zone_creates_separate_group(): void
    {
        $lines = [
            ['part_number' => 'A', 'category' => 'rack-mount-switch', 'zone' => 'Equipment Rack', 'stencil' => 'present'],
            ['part_number' => 'B', 'category' => 'rack-mount-switch', 'zone' => 'RACK', 'stencil' => 'present'],
        ];
        $grouped = $this->grouper->assign($lines);
        $this->assertArrayHasKey('Equipment Rack', $grouped);
        $this->assertArrayHasKey('RACK', $grouped);
        $this->assertCount(1, $grouped['Equipment Rack']);
        $this->assertCount(1, $grouped['RACK']);
    }

    public function test_unknown_category_falls_to_other(): void
    {
        $lines = [
            ['part_number' => 'Z', 'category' => 'unicorn-device', 'stencil' => 'present'],
        ];
        $grouped = $this->grouper->assign($lines);
        $this->assertArrayHasKey('OTHER', $grouped);
    }

    public function test_missing_category_falls_to_other(): void
    {
        $lines = [
            ['part_number' => 'Z', 'stencil' => 'present'],
        ];
        $grouped = $this->grouper->assign($lines);
        $this->assertArrayHasKey('OTHER', $grouped);
    }

    public function test_lines_without_stencil_are_excluded(): void
    {
        $lines = [
            ['part_number' => '', 'category' => 'rack-mount-switch', 'stencil' => null],
            ['part_number' => 'X', 'category' => 'ceiling-mic', 'stencil' => 'present'],
        ];
        $grouped = $this->grouper->assign($lines);
        $this->assertSame(['CEILING'], array_keys($grouped));
    }

    public function test_zone_order_follows_vocab_then_free_text_alphabetical(): void
    {
        $lines = [
            ['part_number' => 'B', 'category' => 'display', 'stencil' => 'present'],           // WALL
            ['part_number' => 'A', 'category' => 'rack-mount-switch', 'stencil' => 'present'], // RACK
            ['part_number' => 'C', 'zone' => 'Zebra Cage', 'stencil' => 'present'],            // free-text
            ['part_number' => 'D', 'zone' => 'Aardvark Bay', 'stencil' => 'present'],          // free-text
        ];
        $grouped = $this->grouper->assign($lines);
        $this->assertSame(
            ['RACK', 'WALL', 'Aardvark Bay', 'Zebra Cage'],
            array_keys($grouped),
            'RACK/WALL come first in vocab order; free-text alphabetical after',
        );
    }

    public function test_within_zone_order_preserves_input_order(): void
    {
        $lines = [
            ['part_number' => 'A', 'category' => 'rack-mount-switch', 'stencil' => 'present'],
            ['part_number' => 'B', 'category' => 'rack-mount-switch', 'stencil' => 'present'],
            ['part_number' => 'C', 'category' => 'rack-mount-switch', 'stencil' => 'present'],
        ];
        $grouped = $this->grouper->assign($lines);
        $this->assertSame(['A', 'B', 'C'], array_column($grouped['RACK'], 'part_number'));
    }

    public function test_empty_input_returns_empty_array(): void
    {
        $this->assertSame([], $this->grouper->assign([]));
    }
}
```

**Step 2 — Run RED — confirm tests fail:**
```
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test --filter=ZoneGrouperTest
```
Expected: class-not-found errors.

Commit RED: `git commit -am "test(23-02): RED — DRAW-46 zone derivation tests (D-01/D-02/D-04)"`

**Step 3 — Write `app/Services/Drawings/ZoneGrouper.php`:**

```php
<?php

namespace App\Services\Drawings;

/**
 * Phase 23 — Sub-room zone derivation for the XTEN-AV-style renderer.
 *
 * Assigns each device line to a zone per the D-01/D-02/D-04 precedence
 * ladder, returning a deterministic map from zone name → device lines.
 *
 * Precedence (per .planning/phases/23-xten-av-style-renderer/23-CONTEXT.md):
 *   1. $line['zone']        — D-02 per-device override (D-04 free-text path)
 *   2. config map lookup    — D-01 category-to-zone derivation
 *   3. 'OTHER'              — fallback
 *
 * Ordering rules:
 *   - Zones in config('drawings.zone_vocab') come first, in vocab order
 *   - Free-text zones come after, sorted alphabetically (case-sensitive)
 *   - Devices within a zone preserve input order (stable)
 *
 * Pure read function: NO Eloquent writes (Phase 23 determinism — D-LOCK-5).
 *
 * Per CONTEXT D-01, D-02, D-04 + 23-DISCOVERY-OQ-1-CATEGORIES.md.
 */
class ZoneGrouper
{
    /**
     * @param  array<int, array{part_number: string, category?: string, zone?: string, stencil: ?\App\Models\DeviceStencil}>  $lines
     * @return array<string, array<int, array>>
     */
    public function assign(array $lines): array
    {
        $vocabOrder = (array) config('drawings.zone_vocab', []);
        $categoryMap = (array) config('drawings.category_to_zone', []);

        $grouped = [];
        foreach ($lines as $line) {
            // Exclude lines without a resolved stencil (empty part_number rows
            // from Project::devicesWithStencils()).
            if (($line['stencil'] ?? null) === null) {
                continue;
            }

            // 1. Per-device override (D-02 / D-04).
            $override = isset($line['zone']) ? trim((string) $line['zone']) : '';
            if ($override !== '') {
                $zone = $override;
            }
            // 2. Category-map default (D-01).
            else {
                $category = isset($line['category']) ? (string) $line['category'] : '';
                $zone = $categoryMap[$category] ?? 'OTHER';
            }

            $grouped[$zone] ??= [];
            $grouped[$zone][] = $line;
        }

        return $this->sortByZoneOrder($grouped, $vocabOrder);
    }

    /**
     * Vocab zones first (in vocab order), free-text zones alphabetical after.
     *
     * @param  array<string, array<int, array>>  $grouped
     * @param  array<int, string>                $vocab
     * @return array<string, array<int, array>>
     */
    private function sortByZoneOrder(array $grouped, array $vocab): array
    {
        $sorted = [];

        // Vocab order first.
        foreach ($vocab as $zone) {
            if (isset($grouped[$zone])) {
                $sorted[$zone] = $grouped[$zone];
                unset($grouped[$zone]);
            }
        }

        // Remaining (free-text) zones sorted alphabetically, case-sensitive.
        $freeText = array_keys($grouped);
        sort($freeText, SORT_STRING);
        foreach ($freeText as $zone) {
            $sorted[$zone] = $grouped[$zone];
        }

        return $sorted;
    }
}
```

**Step 4 — Run GREEN:**
```
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l app/Services/Drawings/ZoneGrouper.php
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l tests/Feature/Drawings/ZoneGrouperTest.php
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test --filter=ZoneGrouperTest --stop-on-failure
git diff --stat app/Services/Drawings/SchematicGeneratorService.php app/Services/Drawings/SchematicD2SourceBuilder.php app/Services/Drawings/DrawingDataResolverService.php app/Services/Drawings/BoundPdfBuilderService.php app/Services/Drawings/DrawingExportRendererService.php
git diff --stat app/Services/Drawings/DrawIoBuilderService.php
```
First-5 must return empty (v1.3 D-10). DrawIoBuilderService must also return empty — Plan 02 doesn't touch the orchestrator (Plan 05 does).

**Step 5 — Commit GREEN:**
```
git add app/Services/Drawings/ZoneGrouper.php
git commit -m "feat(23-02): ZoneGrouper — D-01/D-02/D-04 zone derivation (DRAW-46)"
```
  </action>
  <verify>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l app/Services/Drawings/ZoneGrouper.php</automated>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l tests/Feature/Drawings/ZoneGrouperTest.php</automated>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test --filter=ZoneGrouperTest --stop-on-failure</automated>
  </verify>
  <acceptance_criteria>
    - `app/Services/Drawings/ZoneGrouper.php` exists
    - `php artisan test --filter=ZoneGrouperTest` exits 0 (9 tests pass)
    - `grep -c "AIManager\|AICache\|AIUsage" app/Services/Drawings/ZoneGrouper.php` returns 0 (D-LOCK-5)
    - `grep -c "->update\|->save\|::create\|DB::" app/Services/Drawings/ZoneGrouper.php` returns 0 (Phase 23 deterministic builder — no Eloquent writes per Pitfall 2)
    - `git diff --stat app/Services/Drawings/SchematicGeneratorService.php app/Services/Drawings/SchematicD2SourceBuilder.php app/Services/Drawings/DrawingDataResolverService.php app/Services/Drawings/BoundPdfBuilderService.php app/Services/Drawings/DrawingExportRendererService.php` returns empty
    - `git diff --stat app/Services/Drawings/DrawIoBuilderService.php` returns empty (orchestrator rewire is Plan 05's job)
    - `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l app/Services/Drawings/ZoneGrouper.php` prints "No syntax errors"
  </acceptance_criteria>
  <done>ZoneGrouper class + 9 green tests; no v1.3 surface diff; no Eloquent writes in the builder.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: XtenAvLayoutEngine — emit device cells + zone-group containers (DRAW-42 + DRAW-46)</name>
  <files>
    app/Services/Drawings/XtenAvLayoutEngine.php,
    tests/Feature/Drawings/XtenAvLayoutEngineTest.php
  </files>
  <read_first>
    - app/Services/Drawings/ZoneGrouper.php (just created in Task 1 — XtenAvLayoutEngine takes its output as input)
    - app/Services/Drawings/DrawIoBuilderService.php lines 280-385 (current `emitMxGraph` — base64-stencil pattern + xml() escape helper to MIRROR)
    - app/Services/Drawings/ManufacturerLogoResolver.php (Phase 21 P03 — `resolveSvg(?string): ?string` API used by current builder line 192)
    - .planning/phases/23-xten-av-style-renderer/23-RESEARCH.md §"Example 1" (lines 427-474 — stencil XML shape) + §"Example 5" (lines 581-595 — zone group XML shape)
    - .planning/phases/23-xten-av-style-renderer/23-CONTEXT.md D-04 lines 95-105 (Tier 1 auto-generic vs Tier 2 curated both render)
    - tests/Feature/Drawings/ZoneGrouperTest.php (just created — same fixture shape carries through)
  </read_first>
  <behavior>
    - `XtenAvLayoutEngine::placeDevices(array $zonedLines): array<int, array>` returns a flat ordered list of mxCell descriptors
    - For each zone group: emit a zone-container descriptor FIRST (kind=zone, id="zone-{slug}", value={zone-display-name}), then one device-cell descriptor per line (kind=device, parent="zone-{slug}")
    - Zone container coordinates: derived from union of children + 20px padding. Title placed top-left of the box.
    - Device cell coordinates: column-major within the zone (devices flow vertically in columns; column-width = stencil default_width + 30px gap; row-height = stencil default_height + 20px gap)
    - DRAW-42 stencil embedding: `style="shape=stencil({base64}); ..."` where `{base64}` = `base64_encode($stencil->mxgraph_xml)`. Same pattern as Phase 21 P03 line 297.
    - Each device cell's `value` attribute = `xml($line['name'] ?: $stencil->display_name ?: $stencil->part_number)` (escaped)
    - Each zone container's `value` attribute = `xml($zoneDisplayName)` where display = same as input zone string (D-04 — engineer-typed "RACK" appears as "RACK"; "Equipment Rack" as "Equipment Rack")
    - DETERMINISTIC: same `$zonedLines` input → same descriptor array twice (no randomness; no `now()`; no DB writes)
  </behavior>
  <action>
**Step 1 — TDD RED — write `tests/Feature/Drawings/XtenAvLayoutEngineTest.php`:**

```php
<?php

namespace Tests\Feature\Drawings;

use App\Services\Drawings\XtenAvLayoutEngine;
use Tests\TestCase;

/**
 * Phase 23 Plan 02 Task 2 — DRAW-42 device-card emission + DRAW-46 zone group containers.
 */
class XtenAvLayoutEngineTest extends TestCase
{
    private XtenAvLayoutEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = app(XtenAvLayoutEngine::class);
    }

    /**
     * Build a fake DeviceStencil-like object — Plan 02 tests don't need DB.
     */
    private function fakeStencil(string $partNumber, string $mxgraphXml = '<shape h="140" w="220"/>'): object
    {
        return new class($partNumber, $mxgraphXml)
        {
            public string $part_number;
            public string $mxgraph_xml;
            public string $manufacturer = 'Acme';
            public string $model = 'XYZ';
            public ?string $display_name = null;
            public int $default_width = 220;
            public int $default_height = 140;
            public string $source = 'engineer-curated';
            public function __construct(string $pn, string $xml)
            {
                $this->part_number = $pn;
                $this->mxgraph_xml = $xml;
            }
            public function isCurated(): bool { return true; }
        };
    }

    public function test_emits_zone_container_before_device_cells(): void
    {
        $zoned = [
            'RACK' => [
                ['part_number' => 'A', 'name' => 'Switch', 'stencil' => $this->fakeStencil('A')],
            ],
        ];
        $cells = $this->engine->placeDevices($zoned);

        $kinds = array_column($cells, 'kind');
        $rackZonePos = array_search('zone', $kinds, true);
        $deviceAPos = array_search('device', $kinds, true);

        $this->assertNotFalse($rackZonePos);
        $this->assertNotFalse($deviceAPos);
        $this->assertLessThan($deviceAPos, $rackZonePos, 'Zone container must precede its child device cells');
    }

    public function test_zone_emits_dashed_group_with_children(): void
    {
        $zoned = [
            'RACK' => [
                ['part_number' => 'A', 'name' => 'Switch', 'stencil' => $this->fakeStencil('A')],
                ['part_number' => 'B', 'name' => 'Amp',    'stencil' => $this->fakeStencil('B')],
            ],
        ];
        $cells = $this->engine->placeDevices($zoned);

        $zoneCell = collect($cells)->firstWhere('kind', 'zone');
        $this->assertNotNull($zoneCell);
        $this->assertStringContainsString('dashed=1', $zoneCell['style']);
        $this->assertStringContainsString('fillColor=none', $zoneCell['style']);
        $this->assertSame('RACK', $zoneCell['value']);

        $deviceCells = array_values(array_filter($cells, fn ($c) => $c['kind'] === 'device'));
        $this->assertCount(2, $deviceCells);
        foreach ($deviceCells as $dc) {
            $this->assertSame($zoneCell['id'], $dc['parent'], 'each device must point at the zone parent id');
        }
    }

    public function test_device_cell_style_contains_base64_stencil(): void
    {
        $stencil = $this->fakeStencil('NEAT-BAR-PRO', '<shape h="160" w="240"/>');
        $zoned = ['RACK' => [['part_number' => 'NEAT-BAR-PRO', 'name' => 'Neat Bar Pro', 'stencil' => $stencil]]];
        $cells = $this->engine->placeDevices($zoned);

        $device = collect($cells)->firstWhere('kind', 'device');
        $this->assertStringContainsString('shape=stencil(', $device['style']);
        $this->assertStringContainsString(base64_encode('<shape h="160" w="240"/>'), $device['style']);
    }

    public function test_curated_and_tier1_stencils_both_render(): void
    {
        // Phase 21 D-04 carry-forward — both render side by side
        $curated = $this->fakeStencil('NEAT-BAR-PRO', '<shape ...><connections>...</connections></shape>');
        $tier1   = $this->fakeStencil('UNCATALOGUED-001', '<shape ... />'); // no <connections>

        $zoned = [
            'RACK' => [
                ['part_number' => 'NEAT-BAR-PRO',    'name' => 'Neat',  'stencil' => $curated],
                ['part_number' => 'UNCATALOGUED-001','name' => 'Other', 'stencil' => $tier1],
            ],
        ];
        $cells = $this->engine->placeDevices($zoned);
        $devices = array_values(array_filter($cells, fn ($c) => $c['kind'] === 'device'));
        $this->assertCount(2, $devices);
    }

    public function test_zone_label_xss_escaped(): void
    {
        $stencil = $this->fakeStencil('A');
        $zoned = [
            '<script>alert(1)</script>' => [
                ['part_number' => 'A', 'name' => 'Switch', 'stencil' => $stencil],
            ],
        ];
        $cells = $this->engine->placeDevices($zoned);
        $zoneCell = collect($cells)->firstWhere('kind', 'zone');
        $this->assertStringNotContainsString('<script>', $zoneCell['value']);
        $this->assertStringContainsString('&lt;script&gt;', $zoneCell['value']);
    }

    public function test_device_name_xss_escaped(): void
    {
        $stencil = $this->fakeStencil('A');
        $zoned = ['RACK' => [['part_number' => 'A', 'name' => '<img onerror=x>', 'stencil' => $stencil]]];
        $cells = $this->engine->placeDevices($zoned);
        $device = collect($cells)->firstWhere('kind', 'device');
        $this->assertStringNotContainsString('<img', $device['value']);
        $this->assertStringContainsString('&lt;img', $device['value']);
    }

    public function test_emits_stable_ids_across_calls(): void
    {
        $stencil = $this->fakeStencil('A');
        $zoned = ['RACK' => [['part_number' => 'A', 'name' => 'Switch', 'stencil' => $stencil]]];
        $a = $this->engine->placeDevices($zoned);
        $b = $this->engine->placeDevices($zoned);
        $this->assertSame(
            array_column($a, 'id'),
            array_column($b, 'id'),
            'IDs must be deterministic across calls (D-LOCK-5/6)',
        );
    }

    public function test_empty_zoned_input_returns_empty_array(): void
    {
        $this->assertSame([], $this->engine->placeDevices([]));
    }
}
```

Commit RED: `git commit -am "test(23-02): RED — DRAW-42 + DRAW-46 layout engine"`

**Step 2 — Write `app/Services/Drawings/XtenAvLayoutEngine.php`:**

```php
<?php

namespace App\Services\Drawings;

/**
 * Phase 23 — XTEN-AV-style layout engine.
 *
 * Takes ZoneGrouper output (zone name → device lines) and emits a flat
 * ordered list of mxCell descriptors. The orchestrator (DrawIoBuilderService
 * Plan 05) serialises these into the final mxGraph XML.
 *
 * Descriptor shape:
 *   ['kind' => 'zone',   'id' => 'zone-rack', 'value' => 'RACK',
 *    'style' => 'rounded=0;dashed=1;...', 'parent' => '1',
 *    'x' => 60, 'y' => 60, 'w' => 500, 'h' => 320]
 *
 *   ['kind' => 'device', 'id' => 'dev-1', 'value' => 'Neat Bar Pro',
 *    'style' => 'shape=stencil(...);verticalLabelPosition=top;...',
 *    'parent' => 'zone-rack',
 *    'x' => 80, 'y' => 80, 'w' => 220, 'h' => 140,
 *    'part_number' => 'NEAT-BAR-PRO',   // carried through for Plan 03 CableRouter
 *    'stencil' => DeviceStencil|object]
 *
 * Layout strategy: within each zone, devices flow column-major. Default
 * cell size = 220×140 (matches DeviceStencil::default_width/height).
 * Gap: 30 px horizontal, 20 px vertical. Zone container = union of
 * children + 20 px padding, with the zone title in the top-left.
 *
 * Per CONTEXT D-04 (Tier 1 + Tier 2 both render), DRAW-42, DRAW-46.
 * Pure read function — NO Eloquent writes (D-LOCK-5/6 determinism).
 */
class XtenAvLayoutEngine
{
    private const ZONE_STYLE = 'rounded=0;dashed=1;dashPattern=5 5;fillColor=none;strokeColor=#888888;strokeWidth=1;fontSize=10;fontColor=#666666;verticalAlign=top;align=left;spacingTop=4;spacingLeft=8;';
    private const DEVICE_STYLE_PREFIX = 'shape=stencil(';
    private const DEVICE_STYLE_SUFFIX = ');whiteSpace=wrap;html=1;verticalLabelPosition=top;verticalAlign=bottom;fontSize=10;fontColor=#333333;';

    private const COLUMN_GAP = 30;
    private const ROW_GAP = 20;
    private const ZONE_PADDING = 20;
    private const ZONE_X_START = 60;
    private const ZONE_Y_START = 60;
    private const ZONE_SPACING = 40;     // horizontal gap between zones
    private const MAX_COLS_PER_ZONE = 4; // wraps to a new row after 4 devices

    /**
     * @param  array<string, array<int, array{part_number: string, name?: string, stencil: object}>>  $zonedLines
     * @return array<int, array<string, mixed>>
     */
    public function placeDevices(array $zonedLines): array
    {
        if ($zonedLines === []) {
            return [];
        }

        $cells = [];
        $zoneIndex = 0;
        $zoneX = self::ZONE_X_START;

        foreach ($zonedLines as $zoneName => $lines) {
            $zoneSlug = $this->slug($zoneName, $zoneIndex);
            $zoneCellId = 'zone-' . $zoneSlug;

            // First device cell descriptors for this zone (with relative coordinates).
            $deviceCells = [];
            $deviceIndex = 0;
            foreach ($lines as $line) {
                /** @var object $stencil */
                $stencil = $line['stencil'];
                $col = $deviceIndex % self::MAX_COLS_PER_ZONE;
                $row = intdiv($deviceIndex, self::MAX_COLS_PER_ZONE);
                $w = (int) ($stencil->default_width ?? 220);
                $h = (int) ($stencil->default_height ?? 140);
                $deviceCells[] = [
                    'kind'        => 'device',
                    'id'          => 'dev-' . $zoneSlug . '-' . $deviceIndex,
                    'value'       => $this->xml((string) ($line['name'] ?: ($stencil->display_name ?: $stencil->part_number))),
                    'style'       => self::DEVICE_STYLE_PREFIX . base64_encode((string) $stencil->mxgraph_xml) . self::DEVICE_STYLE_SUFFIX,
                    'parent'      => $zoneCellId,
                    'x'           => self::ZONE_PADDING + $col * ($w + self::COLUMN_GAP),
                    'y'           => self::ZONE_PADDING + $row * ($h + self::ROW_GAP) + 24, // +24 for zone title height
                    'w'           => $w,
                    'h'           => $h,
                    'part_number' => (string) $line['part_number'],
                    'stencil'     => $stencil,
                ];
                $deviceIndex++;
            }

            // Compute zone bounding box from device cells (union + padding).
            $maxX = 0;
            $maxY = 0;
            foreach ($deviceCells as $dc) {
                $maxX = max($maxX, $dc['x'] + $dc['w']);
                $maxY = max($maxY, $dc['y'] + $dc['h']);
            }
            $zoneW = $maxX + self::ZONE_PADDING;
            $zoneH = $maxY + self::ZONE_PADDING;

            // Emit zone container BEFORE its child devices.
            $cells[] = [
                'kind'   => 'zone',
                'id'     => $zoneCellId,
                'value'  => $this->xml((string) $zoneName),
                'style'  => self::ZONE_STYLE,
                'parent' => '1',
                'x'      => $zoneX,
                'y'      => self::ZONE_Y_START,
                'w'      => $zoneW,
                'h'      => $zoneH,
            ];
            foreach ($deviceCells as $dc) {
                $cells[] = $dc;
            }

            $zoneX += $zoneW + self::ZONE_SPACING;
            $zoneIndex++;
        }

        return $cells;
    }

    /**
     * Slug a zone name into a stable ID component. Falls back to the
     * zone index for unicode-heavy strings so IDs stay deterministic.
     */
    private function slug(string $zoneName, int $index): string
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($zoneName));
        $slug = trim((string) $slug, '-');
        return $slug !== '' ? $slug : 'unnamed-' . $index;
    }

    /**
     * XSS-safe XML escape — mirrors DrawIoBuilderService::xml() exactly
     * (T-23-02-A1 mitigation per CONTEXT pitfall 8).
     */
    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
```

**Step 3 — Run GREEN + invariants:**
```
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l app/Services/Drawings/XtenAvLayoutEngine.php
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l tests/Feature/Drawings/XtenAvLayoutEngineTest.php
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test --filter=XtenAvLayoutEngineTest --stop-on-failure
git diff --stat app/Services/Drawings/SchematicGeneratorService.php app/Services/Drawings/SchematicD2SourceBuilder.php app/Services/Drawings/DrawingDataResolverService.php app/Services/Drawings/BoundPdfBuilderService.php app/Services/Drawings/DrawingExportRendererService.php
git diff --stat app/Services/Drawings/DrawIoBuilderService.php
```

All 5 v1.3 files + DrawIoBuilderService must show empty diff.

**Step 4 — Commit GREEN:**
```
git add app/Services/Drawings/XtenAvLayoutEngine.php
git commit -m "feat(23-02): XtenAvLayoutEngine — DRAW-42 device cells + DRAW-46 zone groups"
```
  </action>
  <verify>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l app/Services/Drawings/XtenAvLayoutEngine.php</automated>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l tests/Feature/Drawings/XtenAvLayoutEngineTest.php</automated>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test --filter=XtenAvLayoutEngineTest --stop-on-failure</automated>
  </verify>
  <acceptance_criteria>
    - `app/Services/Drawings/XtenAvLayoutEngine.php` exists
    - `php artisan test --filter=XtenAvLayoutEngineTest` exits 0 (8 tests pass)
    - `grep -c "AIManager\|AICache\|AIUsage" app/Services/Drawings/XtenAvLayoutEngine.php` returns 0
    - `grep -c "->update\|->save\|::create\|DB::" app/Services/Drawings/XtenAvLayoutEngine.php` returns 0 (no Eloquent writes)
    - `grep -c "htmlspecialchars" app/Services/Drawings/XtenAvLayoutEngine.php` returns ≥1 (T-23-02-A1 mitigation)
    - `grep -c "shape=stencil(" app/Services/Drawings/XtenAvLayoutEngine.php` returns ≥1 (DRAW-42 base64 embed)
    - `grep -c "dashed=1" app/Services/Drawings/XtenAvLayoutEngine.php` returns ≥1 (DRAW-46 dashed zone)
    - `git diff --stat app/Services/Drawings/SchematicGeneratorService.php app/Services/Drawings/SchematicD2SourceBuilder.php app/Services/Drawings/DrawingDataResolverService.php app/Services/Drawings/BoundPdfBuilderService.php app/Services/Drawings/DrawingExportRendererService.php` returns empty
    - `git diff --stat app/Services/Drawings/DrawIoBuilderService.php` returns empty
    - `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l app/Services/Drawings/XtenAvLayoutEngine.php` prints "No syntax errors"
  </acceptance_criteria>
  <done>XtenAvLayoutEngine class + 8 green tests; orchestrator + v1.3 surfaces show empty diff.</done>
</task>

</tasks>

<verification>
- All tasks committed atomically (TDD RED→GREEN per task)
- `php artisan test --filter='ZoneGrouper|XtenAvLayoutEngine'` exits 0 (~17 tests)
- `git diff --stat` on the 5 v1.3 invariant files returns empty
- `git diff --stat app/Services/Drawings/DrawIoBuilderService.php` returns empty (orchestrator wiring is Plan 05's job)
- `grep -rE "AIManager|AICache|AIUsage|->update\(|->save\(|::create\(" app/Services/Drawings/ZoneGrouper.php app/Services/Drawings/XtenAvLayoutEngine.php` returns empty (D-LOCK-5/6)
- `grep -rE "htmlspecialchars\(.*ENT_XML1" app/Services/Drawings/ZoneGrouper.php app/Services/Drawings/XtenAvLayoutEngine.php` — XtenAvLayoutEngine matches (T-23-02-A1 + T-23-02-A2)
</verification>

<success_criteria>
Plan 05 (DrawIoBuilderService orchestrator rewire) can call:
1. `app(ZoneGrouper::class)->assign($lines)` and receive zone-grouped lines
2. `app(XtenAvLayoutEngine::class)->placeDevices($zoned)` and receive flat ordered mxCell descriptors with zone containers preceding their device children

Plan 03 (CableRouter) receives device cell IDs in the format `dev-{zone-slug}-{index}` and uses them as edge source/target.

Plan 04 (TitleBlockRenderer / SheetBorderRenderer) is independent of Plan 02 output (they emit per-page chrome, not device content) — runs parallel.
</success_criteria>

<output>
After completion, create `.planning/phases/23-xten-av-style-renderer/23-02-SUMMARY.md` documenting:
- ZoneGrouper precedence ladder verbatim (D-01/D-02/D-04)
- XtenAvLayoutEngine descriptor shape (kind/id/value/style/parent/x/y/w/h)
- DRAW-46 dashed-group style string verbatim
- DRAW-42 base64-stencil embed pattern verbatim
- Decision IDs implemented: D-01 (category map), D-02 (per-device override), D-04 (free-text + zone vocab), D-09 (generic naming verified — no rams_ prefix on class names)
- Test count + assertions
- T-23-02-A1 + T-23-02-A2 XSS mitigations verified

End with the 🚨 "Files to upload to live" section listing:
- `app/Services/Drawings/ZoneGrouper.php`
- `app/Services/Drawings/XtenAvLayoutEngine.php`
- Note: no migration/config changes in this plan; Plan 01 already added the config keys.
</output>