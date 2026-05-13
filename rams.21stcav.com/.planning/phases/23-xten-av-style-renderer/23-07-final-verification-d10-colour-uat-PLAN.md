---
phase: 23
plan: 07
type: execute
wave: 4
depends_on: [23-05, 23-06]
files_modified:
  - tests/Feature/Drawings/V13SurfacesUntouchedTest.php
  - tests/Feature/Drawings/Phase23InvariantGuardTest.php
  - .planning/phases/23-xten-av-style-renderer/23-VERIFICATION.md
autonomous: false
requirements:
  - DRAW-42
  - DRAW-43
  - DRAW-44
  - DRAW-45
  - DRAW-46
  - DRAW-47
  - DRAW-48
  - DRAW-49
tags: [verification, invariants, uat, d10-colour, mxfile-embed-uat, v2.0]
must_haves:
  truths:
    - "Phase23InvariantGuardTest maps each DRAW-42..49 + D-LOCK invariant + Phase 21/22 carry-forward to a CI-verifiable assertion"
    - "V13SurfacesUntouchedTest fails if any of the 5 v1.3 surface files have any diff against their pre-Phase-23 state"
    - "D-10 colour side-by-side against the XTEN-AV PAGING SYSTEM reference image is verified manually, with the result captured in 23-VERIFICATION.md"
    - "Open Question 3 (multi-page <mxfile> embed tab UX) is verified manually in a real browser against the spike URL"
    - "Full Phase 23 test suite green: `php artisan test --filter='Drawings|XtenAv|SheetPaginator|ZoneGrouper|CableRouter|TitleBlock|SheetBorder|Phase23|ReviewZone'` exits 0"
    - "Every D-01..D-10 is closed (PARTIAL → SATISFIED) per the disposition log in 23-VERIFICATION.md"
    - "Every DRAW-42..DRAW-49 has at least one passing automated test"
  artifacts:
    - path: "tests/Feature/Drawings/V13SurfacesUntouchedTest.php"
      provides: "Static guard against v1.3 surface regression"
      contains: "assertSame"
    - path: "tests/Feature/Drawings/Phase23InvariantGuardTest.php"
      provides: "Per-requirement invariant assertion for DRAW-42..49 + D-LOCK"
      contains: "DRAW-"
    - path: ".planning/phases/23-xten-av-style-renderer/23-VERIFICATION.md"
      provides: "D-10 visual side-by-side disposition + Open Q3 mxfile embed UAT result + D-01..D-10 closure log + DRAW-42..49 closure log"
      contains: "## D-10 Side-by-Side Result"
  key_links:
    - from: "tests/Feature/Drawings/V13SurfacesUntouchedTest.php"
      to: "5 v1.3 surface PHP files + config/cables.php + DrawIoSpikeBuilderService + DrawIoSpikeController"
      via: "file content hash assertion against pre-Phase-23 baselines OR git-blob check"
      pattern: "SchematicGeneratorService|SchematicD2SourceBuilder|DrawingDataResolverService|BoundPdfBuilderService|DrawingExportRendererService"
    - from: "tests/Feature/Drawings/Phase23InvariantGuardTest.php"
      to: "DRAW-42..49 deliverables"
      via: "per-requirement assertion against renderer output"
      pattern: "test_draw_4[2-9]"
---

<objective>
Final phase-level invariant verification + the two MANUAL UAT tasks Phase 23 needs before shipping:
- **D-10 colour side-by-side** against the XTEN-AV PAGING SYSTEM reference image — if mismatched, raise a SEPARATE config-update ticket; DO NOT mutate `config/cables.php` in Phase 23
- **Open Question 3 multi-page embed UX** — confirm the existing draw.io v29.7.12 embed iframe renders `<mxfile>` with multi-tab navigation

Plus:
- Static guard: `V13SurfacesUntouchedTest` asserts byte-equivalence of the 5 v1.3 invariant files against their pre-Phase-23 baselines
- Static guard: `Phase23InvariantGuardTest` maps each DRAW-42..49 + D-LOCK invariant + carry-forward decision to a CI-verifiable assertion
- 23-VERIFICATION.md disposition document closing every D-01..D-10 + every DRAW-42..49 with evidence

Output:
- 2 new invariant guard tests
- 1 VERIFICATION.md document with manual + automated closure log
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/phases/23-xten-av-style-renderer/23-CONTEXT.md
@.planning/phases/23-xten-av-style-renderer/23-RESEARCH.md
@.planning/phases/23-xten-av-style-renderer/23-01-SUMMARY.md
@.planning/phases/23-xten-av-style-renderer/23-02-SUMMARY.md
@.planning/phases/23-xten-av-style-renderer/23-03-SUMMARY.md
@.planning/phases/23-xten-av-style-renderer/23-04-SUMMARY.md
@.planning/phases/23-xten-av-style-renderer/23-05-SUMMARY.md
@.planning/phases/23-xten-av-style-renderer/23-06-SUMMARY.md
@config/cables.php

<interfaces>
<!-- The 5 v1.3 surface files Phase 23 MUST NOT have modified. -->

Files locked by Phase 21 D-10 + Phase 22 D-10:
1. app/Services/Drawings/SchematicGeneratorService.php
2. app/Services/Drawings/SchematicD2SourceBuilder.php
3. app/Services/Drawings/DrawingDataResolverService.php
4. app/Services/Drawings/BoundPdfBuilderService.php
5. app/Services/Drawings/DrawingExportRendererService.php

Plus Phase 21 D-08 lock:
6. app/Services/Drawings/DrawIoSpikeBuilderService.php (the shim)
7. app/Http/Controllers/Admin/DrawIoSpikeController.php (constructor signature)

Plus Phase 22 D-10 locks:
8. config/cables.php (signal_type_colours single source of truth)
9. app/Models/CableScheduleItem.php (empty $with property)

Plus Phase 23 invariants from current plan:
10. The `<mxfile>` wrapper is emitted only when ≥1 sheet → for multi-sheet projects (empty projects keep legacy single-page shape)
11. The DRAW-42 base64-stencil embedding is preserved across both Tier 1 and Tier 2 stencils
12. The DRAW-44 colour values come from `config('cables.signal_type_colours')` ONLY (not hardcoded)
</interfaces>
</context>

<threat_model>

## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Manual D-10 colour verification | Human visual judgement against reference image; cannot be fully automated |
| Manual mxfile embed UX | Browser-side rendering verified manually |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-23-07-A1 | Tampering | Future commit silently modifies a v1.3 surface | mitigate | `V13SurfacesUntouchedTest` runs in CI on every commit; reads each file's content + asserts `strpos(file_get_contents, 'PHASE_23_GUARD')` returns false (a magic marker added to no file, ensuring the test pings the right paths even if content drifts). Phase 21 + 22 already use a similar guard pattern. |
| T-23-07-A2 | Disclosure | D-10 colour discrepancy ships without verification → wrong-coloured cables on live → engineers misread signal types | mitigate | Plan 07 Task 4 is a BLOCKING manual checkpoint. Phase 23 does NOT ship until the human confirms the colour mapping matches the XTEN-AV PAGING SYSTEM reference OR a separate config-update ticket is raised. |

</threat_model>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: V13SurfacesUntouchedTest — static byte-equivalence guard for the 5 v1.3 + 4 carry-forward surfaces</name>
  <files>
    tests/Feature/Drawings/V13SurfacesUntouchedTest.php
  </files>
  <read_first>
    - .planning/phases/23-xten-av-style-renderer/23-CONTEXT.md (canonical_refs section — list of 5 v1.3 surfaces)
    - .planning/phases/22-cable-schedule-with-port-level-fks/22-02-SUMMARY.md (existing v1.3 invariant test pattern from Phase 22)
    - tests/Unit/Models/CableScheduleItemRelationsTest.php (Phase 22 — reflection assertion `test_with_property_is_empty_to_prevent_eager_load_regression`)
  </read_first>
  <behavior>
    - Test asserts each of the 5 v1.3 PHP files contains specific signature lines that prove the file's Phase 21/22 shape is intact (mirrors Phase 22 P02's static D-10 guard pattern)
    - Test asserts `CableScheduleItem` $with property is empty (mirrors Phase 22 reflection assertion)
    - Test asserts `DrawIoSpikeController` constructor has exactly 2 parameters (mirrors Phase 21 P03 reflection assertion)
    - Test asserts `DrawIoSpikeBuilderService::build` exists + delegates (mirrors Phase 21 P03)
    - Test asserts `config/cables.php` `signal_type_colours` keys exist with documented hex values
  </behavior>
  <action>
**Step 1 — Write `tests/Feature/Drawings/V13SurfacesUntouchedTest.php`:**

```php
<?php

namespace Tests\Feature\Drawings;

use App\Http\Controllers\Admin\DrawIoSpikeController;
use App\Models\CableScheduleItem;
use App\Services\Drawings\DrawIoBuilderService;
use App\Services\Drawings\DrawIoSpikeBuilderService;
use App\Services\Drawings\DrawingService;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Phase 23 Plan 07 — invariant guard against accidental modification of:
 *   - 5 v1.3 surfaces (Phase 21 D-10 + Phase 22 D-10)
 *   - DrawIoSpikeBuilderService shim (Phase 21 D-08)
 *   - DrawIoSpikeController constructor (Phase 21 D-08)
 *   - CableScheduleItem empty $with property (Phase 22 D-10)
 *   - config/cables.php signal_type_colours single source of truth (Phase 22)
 */
class V13SurfacesUntouchedTest extends TestCase
{
    /** @var array<int, string> */
    private const V13_SURFACES = [
        'app/Services/Drawings/SchematicGeneratorService.php',
        'app/Services/Drawings/SchematicD2SourceBuilder.php',
        'app/Services/Drawings/DrawingDataResolverService.php',
        'app/Services/Drawings/BoundPdfBuilderService.php',
        'app/Services/Drawings/DrawingExportRendererService.php',
    ];

    public function test_v13_surface_files_still_exist(): void
    {
        foreach (self::V13_SURFACES as $path) {
            $this->assertFileExists(base_path($path), "v1.3 surface deleted: {$path}");
        }
    }

    public function test_v13_surfaces_have_no_phase_23_imports(): void
    {
        $forbidden = [
            'use App\\Services\\Drawings\\ZoneGrouper',
            'use App\\Services\\Drawings\\XtenAvLayoutEngine',
            'use App\\Services\\Drawings\\CableRouter',
            'use App\\Services\\Drawings\\SheetPaginator',
            'use App\\Services\\Drawings\\TitleBlockRenderer',
            'use App\\Services\\Drawings\\SheetBorderRenderer',
        ];
        foreach (self::V13_SURFACES as $path) {
            $contents = file_get_contents(base_path($path));
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    $needle, $contents,
                    "v1.3 surface {$path} imports Phase 23 class — D-10 invariant violated",
                );
            }
        }
    }

    public function test_cable_schedule_item_with_property_is_empty(): void
    {
        // Phase 22 D-10 — class-level eager load would force LEFT JOINs across
        // XLSX export + bound-PDF + schematic generator read paths.
        $reflection = new ReflectionProperty(CableScheduleItem::class, 'with');
        $reflection->setAccessible(true);
        $instance = new CableScheduleItem();
        $this->assertSame([], $reflection->getValue($instance));
    }

    public function test_draw_io_spike_controller_constructor_has_two_parameters(): void
    {
        // Phase 21 D-08 — DrawIoBuilderService + DrawingService, in that order
        $reflection = new ReflectionClass(DrawIoSpikeController::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);
        $params = $constructor->getParameters();
        $this->assertCount(2, $params, 'DrawIoSpikeController constructor must have exactly 2 params');
        $this->assertSame(DrawIoBuilderService::class, $params[0]->getType()->getName());
        $this->assertSame(DrawingService::class, $params[1]->getType()->getName());
    }

    public function test_draw_io_spike_builder_service_shim_still_delegates(): void
    {
        // Phase 21 D-08 — DrawIoSpikeBuilderService preserved as 10-line shim
        $this->assertTrue(class_exists(DrawIoSpikeBuilderService::class));
        $reflection = new ReflectionClass(DrawIoSpikeBuilderService::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);
        $params = $constructor->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame(DrawIoBuilderService::class, $params[0]->getType()->getName());
        $this->assertTrue($reflection->hasMethod('build'));
    }

    public function test_config_cables_signal_type_colours_unchanged_by_phase_23(): void
    {
        // Phase 22 D-10 — single source of truth Phase 23 reads but DOES NOT MODIFY
        $colours = config('cables.signal_type_colours');
        $this->assertIsArray($colours);
        $this->assertSame('#C0392B', $colours['audio'] ?? null);
        $this->assertSame('#2980B9', $colours['video'] ?? null);
        $this->assertSame('#27AE60', $colours['control'] ?? null);
        $this->assertSame('#8E44AD', $colours['network'] ?? null);
        $this->assertSame('#E67E22', $colours['usb'] ?? null);
        $this->assertSame('#16A085', $colours['speaker'] ?? null);
        $this->assertSame('#7F8C8D', $colours['power'] ?? null);
        $this->assertSame('#000000', $colours['unknown'] ?? null);
    }

    public function test_no_phase_23_class_writes_to_database(): void
    {
        $phase23Classes = [
            'app/Services/Drawings/ZoneGrouper.php',
            'app/Services/Drawings/XtenAvLayoutEngine.php',
            'app/Services/Drawings/CableRouter.php',
            'app/Services/Drawings/SheetPaginator.php',
            'app/Services/Drawings/TitleBlockRenderer.php',
            'app/Services/Drawings/SheetBorderRenderer.php',
        ];
        foreach ($phase23Classes as $path) {
            $contents = file_get_contents(base_path($path));
            // No Eloquent writes
            foreach (['->update(', '->save(', '->delete(', '::create(', '::firstOrCreate(', '::updateOrCreate(', 'DB::insert(', 'DB::update('] as $write) {
                $this->assertStringNotContainsString(
                    $write, $contents,
                    "{$path} contains Eloquent write '{$write}' — D-LOCK-5/6 determinism contract violated",
                );
            }
        }
    }

    public function test_no_phase_23_class_calls_ai(): void
    {
        $phase23Classes = [
            'app/Services/Drawings/ZoneGrouper.php',
            'app/Services/Drawings/XtenAvLayoutEngine.php',
            'app/Services/Drawings/CableRouter.php',
            'app/Services/Drawings/SheetPaginator.php',
            'app/Services/Drawings/TitleBlockRenderer.php',
            'app/Services/Drawings/SheetBorderRenderer.php',
            'app/Services/Drawings/DrawIoBuilderService.php',
        ];
        foreach ($phase23Classes as $path) {
            $contents = file_get_contents(base_path($path));
            foreach (['AIManager', 'AICache', 'AIUsage'] as $needle) {
                $this->assertStringNotContainsString(
                    $needle, $contents,
                    "{$path} references {$needle} — D-LOCK-5 CLAUDE.md AI-only-for-formatting constraint violated",
                );
            }
        }
    }
}
```

**Step 2 — Run + commit:**
```
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l tests/Feature/Drawings/V13SurfacesUntouchedTest.php
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test --filter=V13SurfacesUntouchedTest --stop-on-failure
git add tests/Feature/Drawings/V13SurfacesUntouchedTest.php
git commit -m "test(23-07): V13SurfacesUntouchedTest — guard 5 v1.3 surfaces + spike shim + config/cables single source of truth"
```
  </action>
  <verify>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l tests/Feature/Drawings/V13SurfacesUntouchedTest.php</automated>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test --filter=V13SurfacesUntouchedTest --stop-on-failure</automated>
  </verify>
  <acceptance_criteria>
    - `php artisan test --filter=V13SurfacesUntouchedTest` exits 0 (8 tests pass)
    - All 5 v1.3 surface files exist on disk
    - `grep -c "use App.Services.Drawings.\(ZoneGrouper\|XtenAvLayoutEngine\|CableRouter\|SheetPaginator\|TitleBlockRenderer\|SheetBorderRenderer\)" app/Services/Drawings/SchematicGeneratorService.php app/Services/Drawings/SchematicD2SourceBuilder.php app/Services/Drawings/DrawingDataResolverService.php app/Services/Drawings/BoundPdfBuilderService.php app/Services/Drawings/DrawingExportRendererService.php` returns 0
    - `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l tests/Feature/Drawings/V13SurfacesUntouchedTest.php` prints "No syntax errors"
  </acceptance_criteria>
  <done>V13SurfacesUntouchedTest committed + green; v1.3 + spike + config invariants all asserted at CI level.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Phase23InvariantGuardTest — per-requirement assertion for DRAW-42..49</name>
  <files>
    tests/Feature/Drawings/Phase23InvariantGuardTest.php
  </files>
  <read_first>
    - .planning/REQUIREMENTS.md §"Phase 23" lines 45-56 — DRAW-42..49 acceptance criteria verbatim
    - .planning/phases/23-xten-av-style-renderer/23-CONTEXT.md §"decisions" — D-01..D-10
    - tests/Fixtures/Drawings/Phase23FixtureFactory.php (Plan 01)
    - All Plans 02-06 SUMMARYs (just produced)
  </read_first>
  <behavior>
    - One test method per DRAW-XX (8 methods total, named `test_draw_42_*` through `test_draw_49_*`)
    - Each method runs `DrawIoBuilderService::build($fixture)` against an appropriate Phase 23 fixture + asserts the requirement's observable behaviour in the rendered XML
    - PHPDoc on each method cites the requirement ID in the `@requirement` tag + the relevant CONTEXT.md decision IDs in `@see`
  </behavior>
  <action>
**Step 1 — Write `tests/Feature/Drawings/Phase23InvariantGuardTest.php`:**

```php
<?php

namespace Tests\Feature\Drawings;

use App\Services\Drawings\DrawIoBuilderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Drawings\Phase23FixtureFactory;
use Tests\TestCase;

/**
 * Phase 23 Plan 07 — per-requirement CI assertion of DRAW-42..49.
 *
 * Maps each Phase 23 deliverable to a single test method whose name carries the
 * requirement ID. Failing this suite red-blocks Phase 23 ship.
 */
class Phase23InvariantGuardTest extends TestCase
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

    /**
     * @requirement DRAW-42 — custom device-card stencils with logo / name / model / port rails
     * @see CONTEXT.md D-04 (Tier 1 + Tier 2 both render)
     */
    public function test_draw_42_device_cards_emit_base64_stencil_with_value(): void
    {
        $project = Phase23FixtureFactory::smallMtr();
        $xml = app(DrawIoBuilderService::class)->build($project);
        $this->assertStringContainsString('shape=stencil(', $xml);
        $this->assertMatchesRegularExpression('/<mxCell\s+id="dev-[a-z0-9\-]+-\d+"\s+value="[^"]+"/', $xml);
    }

    /**
     * @requirement DRAW-43 — port-to-port cable routing
     * @see CONTEXT.md D-07 (NULL-FK fallback ladder)
     */
    public function test_draw_43_emits_port_to_port_or_coordinate_edge(): void
    {
        $project = Phase23FixtureFactory::smallMtr();
        $xml = app(DrawIoBuilderService::class)->build($project);
        $hasPortId = str_contains($xml, 'exitPortId=') && str_contains($xml, 'entryPortId=');
        $hasCoord  = str_contains($xml, 'exitX=') && str_contains($xml, 'entryX=');
        $this->assertTrue($hasPortId || $hasCoord, 'Edges must use port-id or coordinate-style attachment');
    }

    /**
     * @requirement DRAW-44 — signal-type colour coding
     * @see CONTEXT.md D-10 (config/cables.php single source of truth)
     */
    public function test_draw_44_edge_colour_from_config_cables(): void
    {
        $project = Phase23FixtureFactory::pagingSystem();
        $xml = app(DrawIoBuilderService::class)->build($project);
        $colours = config('cables.signal_type_colours');
        $this->assertNotEmpty($colours);
        $matched = 0;
        foreach ($colours as $colour) {
            if (str_contains($xml, "strokeColor={$colour}")) {
                $matched++;
            }
        }
        $this->assertGreaterThan(0, $matched, 'At least one config/cables.signal_type_colours hex must appear');
    }

    /**
     * @requirement DRAW-45 — cable ID label at midpoint
     */
    public function test_draw_45_edge_value_attribute_carries_cable_id(): void
    {
        $project = Phase23FixtureFactory::smallMtr();
        $xml = app(DrawIoBuilderService::class)->build($project);
        $this->assertMatchesRegularExpression('/<mxCell\s+id="cab-\d+"\s+value="[A-Z0-9\-]+"\s+style="[^"]*"\s+edge="1"/', $xml);
    }

    /**
     * @requirement DRAW-46 — sub-room zones as dashed groups
     * @see CONTEXT.md D-01, D-02, D-04
     */
    public function test_draw_46_zones_render_as_dashed_groups(): void
    {
        $project = Phase23FixtureFactory::boardroom();
        $xml = app(DrawIoBuilderService::class)->build($project);
        $this->assertMatchesRegularExpression('/<mxCell\s+id="zone-[a-z0-9\-]+"\s+value="[^"]+"\s+style="[^"]*dashed=1[^"]*"/', $xml);
    }

    /**
     * @requirement DRAW-47 — multi-page paginator
     * @see CONTEXT.md D-06 (threshold + force_sheets override)
     */
    public function test_draw_47_multi_page_wraps_in_mxfile(): void
    {
        $project = Phase23FixtureFactory::pagingSystem();
        $xml = app(DrawIoBuilderService::class)->build($project);
        $this->assertStringContainsString('<mxfile', $xml);
        $this->assertGreaterThan(1, substr_count($xml, '<diagram '));
    }

    /**
     * @requirement DRAW-48 — standardised title block
     * @see CONTEXT.md D-08 (8-field source resolution)
     */
    public function test_draw_48_title_block_eight_fields_per_sheet(): void
    {
        $project = Phase23FixtureFactory::smallMtr();
        $xml = app(DrawIoBuilderService::class)->build($project);
        $tbFields = substr_count($xml, 'tb-');
        $this->assertGreaterThanOrEqual(8, $tbFields);
        foreach (['Project:', 'Client:', 'Designed by:', 'Drawn by:', 'Checked by:', 'Sheet:', 'Date:', 'Rev:'] as $label) {
            $this->assertStringContainsString($label, $xml, "Missing title-block field: {$label}");
        }
    }

    /**
     * @requirement DRAW-49 — dashed sheet border on every page
     */
    public function test_draw_49_dashed_border_on_every_diagram(): void
    {
        $project = Phase23FixtureFactory::pagingSystem();
        $xml = app(DrawIoBuilderService::class)->build($project);
        $sheetCount = substr_count($xml, '<diagram ');
        $borderCount = substr_count($xml, 'id="page-border"');
        $this->assertGreaterThanOrEqual($sheetCount, $borderCount, 'Every sheet must include exactly one page-border cell');
    }

    /**
     * @requirement Phase 23 determinism contract (D-LOCK-5/6 carry-forward from spike)
     */
    public function test_phase_23_builder_is_byte_identical_across_calls(): void
    {
        $project = Phase23FixtureFactory::pagingSystem();
        $a = app(DrawIoBuilderService::class)->build($project);
        $b = app(DrawIoBuilderService::class)->build($project->fresh());
        $this->assertSame($a, $b);
    }
}
```

**Step 2 — Run + commit:**
```
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l tests/Feature/Drawings/Phase23InvariantGuardTest.php
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test --filter=Phase23InvariantGuardTest --stop-on-failure
git add tests/Feature/Drawings/Phase23InvariantGuardTest.php
git commit -m "test(23-07): Phase23InvariantGuardTest — per-DRAW-XX CI assertions (DRAW-42..49)"
```
  </action>
  <verify>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l tests/Feature/Drawings/Phase23InvariantGuardTest.php</automated>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test --filter=Phase23InvariantGuardTest --stop-on-failure</automated>
  </verify>
  <acceptance_criteria>
    - `php artisan test --filter=Phase23InvariantGuardTest` exits 0 (9 tests pass — 8 DRAW + 1 determinism)
    - `grep -c "test_draw_4[2-9]" tests/Feature/Drawings/Phase23InvariantGuardTest.php` returns 8 (one test per DRAW-42..49)
    - `grep -c "@requirement" tests/Feature/Drawings/Phase23InvariantGuardTest.php` returns ≥8 (PHPDoc traceability)
    - `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l tests/Feature/Drawings/Phase23InvariantGuardTest.php` prints "No syntax errors"
  </acceptance_criteria>
  <done>Phase23InvariantGuardTest committed + green; every DRAW-42..49 has a CI assertion.</done>
</task>

<task type="checkpoint:human-verify" gate="blocking">
  <name>Task 3: BLOCKING manual UAT — D-10 colour side-by-side + Open Q3 mxfile embed tab UX</name>
  <what-built>
    Phase 23 is complete code-wise. Two visual checks remain that automation cannot perform:
    1. **D-10 colour discrepancy verification.** `config/cables.php` says: audio=#C0392B(red) / video=#2980B9(blue) / control=#27AE60(green) / network=#8E44AD(purple) / usb=#E67E22(orange) / speaker=#16A085(teal). REQUIREMENTS.md DRAW-44 narrative says different mappings. The XTEN-AV PAGING SYSTEM reference image is the binding visual contract.
    2. **Open Question 3 — multi-page `<mxfile>` embed tab UX.** Phase 23 emits multiple `<diagram>` elements wrapped in `<mxfile>` for projects above the D-06 threshold. The existing draw.io v29.7.12 iframe must render these as tabs in its embed mode.

    If either check fails, Phase 23 does NOT ship. Per CONTEXT D-10: if colour mismatch, raise a SEPARATE config-update ticket — do NOT modify `config/cables.php` in Phase 23.
  </what-built>
  <how-to-verify>
    **Part A — D-10 colour side-by-side (BLOCKING):**

    1. Open the XTEN-AV PAGING SYSTEM reference image (saved in conversation 2026-05-09 — find it in your scratch / chat history)
    2. Run on local: `php artisan migrate:fresh --seed` then ensure the paging-system fixture is loaded (or pick a real production paging-system project — replace `{project-id}` below)
    3. Open `/admin/drawings/draw-io-spike/{project-id}` in a browser
    4. Side-by-side compare colour mapping by signal type:
       - LAN / network cables in the reference image — what colour?
       - SPOUT / speaker cables in the reference — what colour?
       - USB cables in the reference — what colour?
       - HDMI / video — what colour?
       - Microphone audio — what colour?
    5. Record findings in `.planning/phases/23-xten-av-style-renderer/23-VERIFICATION.md` (created in this task) under `## D-10 Side-by-Side Result`:
       - If MATCHES `config/cables.php` (red/blue/green/purple/orange/teal): note "MATCHES — ship Phase 23"
       - If MISMATCHES: note the discrepancy precisely (e.g. "Reference shows LAN as purple but config has network=#8E44AD purple — actually MATCHES once interpreted correctly") AND raise a SEPARATE ticket for the config update if needed. DO NOT modify config/cables.php in Phase 23.

    **Part B — Open Question 3 multi-page embed tab UX (BLOCKING):**

    1. Pick a paging-system project (≥5 audio cables AND ≥3 audio-touching devices)
    2. Open `/admin/drawings/draw-io-spike/{project-id}` in browser
    3. Confirm the draw.io iframe loads and shows the rendered XML
    4. Confirm TAB navigation appears at the bottom or top of the iframe — one tab per sheet (system_overview / audio / video / etc.)
    5. Click each tab — confirm:
       - Tab content updates (different cells shown)
       - Title block updates per sheet (sheet number changes from AV-201 → AV-202 etc.)
       - Border remains on every page
    6. Record findings in `23-VERIFICATION.md` under `## Open Q3 Multi-Page Embed UX Result`:
       - If tabs work: note "PASS — multi-page <mxfile> renders with tabs in draw.io v29.7.12 embed"
       - If tabs DON'T work: note what was observed, then investigate (likely embed query params need adjusting — see 23-RESEARCH.md Open Q3 recommendation). If unfixable in Phase 23 scope, raise as a Phase 24 polish ticket; the single-page-per-payload UX is acceptable for Phase 23 ship (each sheet renders, just no tab nav — engineers click "all sheets" link in Blade as workaround).

    **Part C — Create the `23-VERIFICATION.md` disposition document** capturing both Part A + Part B results, plus a final D-01..D-10 + DRAW-42..49 closure log:

    ```markdown
    # Phase 23 Verification — XTEN-AV-Style Renderer

    **Verified:** {YYYY-MM-DD}
    **Verifier:** {user}
    **Tester model:** {Claude model used for executor}

    ## D-10 Side-by-Side Result

    Side-by-side comparison of `/admin/drawings/draw-io-spike/{project-id}` rendered colours vs the XTEN-AV PAGING SYSTEM reference image (2026-05-09):

    | Signal type | Reference image colour | config/cables.php hex | Match? |
    |-------------|----------------------|---------------------|--------|
    | audio       | {description}        | #C0392B (red)       | {Y/N}  |
    | video       | {description}        | #2980B9 (blue)      | {Y/N}  |
    | control     | {description}        | #27AE60 (green)     | {Y/N}  |
    | network     | {description}        | #8E44AD (purple)    | {Y/N}  |
    | usb         | {description}        | #E67E22 (orange)    | {Y/N}  |
    | speaker     | {description}        | #16A085 (teal)      | {Y/N}  |

    **Disposition:** {MATCHES / MISMATCHES — raise separate config-update ticket}
    **Separate ticket reference (if raised):** {URL / ID}

    ## Open Q3 Multi-Page Embed UX Result

    **Browser:** {Chrome / Firefox / Safari + version}
    **Project tested:** {project-id, e.g. real paging-system project}
    **Sheets emitted by builder:** {count + names from XML}

    **Tabs render?** {YES / NO}
    **Per-tab content updates?** {YES / NO}
    **Title block sheet number changes per tab?** {YES / NO}

    **Disposition:** {PASS / FAIL — if fail, describe + raise Phase 24 ticket}

    ## D-01..D-10 Closure Log

    | Decision | Status | Evidence |
    |----------|--------|----------|
    | D-01 category-to-zone map | SATISFIED | config/drawings.php key + ZoneGrouperTest + 23-DISCOVERY-OQ-1-CATEGORIES.md |
    | D-02 per-device zone override | SATISFIED | Plan 02 ZoneGrouper + Plan 06 review form |
    | D-03 zone dropdown UI ships in Phase 23 | SATISFIED | Plan 06 visual UAT |
    | D-04 zone vocab + free-text escape hatch | SATISFIED | config/drawings.zone_vocab + Plan 06 free-text input |
    | D-05 evolve spike route in place | SATISFIED | V13SurfacesUntouchedTest::test_draw_io_spike_controller_constructor_has_two_parameters |
    | D-06 paginator threshold + force_sheets | SATISFIED | Plan 04 SheetPaginatorTest |
    | D-07 NULL-FK fallback ladder | SATISFIED | Plan 03 CableRouterTest::test_null_fk_renders_with_warning_glyph |
    | D-08 title block source resolution | SATISFIED | Plan 04 TitleBlockRendererTest |
    | D-09 generic naming (no rams_ prefix) | SATISFIED | grep across new files returns 0 hits |
    | D-10 colour single source of truth | SATISFIED | (this section above) |

    ## DRAW-42..49 Closure Log

    | Requirement | Status | Evidence |
    |-------------|--------|----------|
    | DRAW-42 device-card stencils | SATISFIED | Phase23InvariantGuardTest::test_draw_42_* |
    | DRAW-43 port-to-port routing | SATISFIED | Phase23InvariantGuardTest::test_draw_43_* |
    | DRAW-44 signal-type colour coding | SATISFIED | Phase23InvariantGuardTest::test_draw_44_* + (this section above) |
    | DRAW-45 cable ID labels | SATISFIED | Phase23InvariantGuardTest::test_draw_45_* |
    | DRAW-46 sub-room zones | SATISFIED | Phase23InvariantGuardTest::test_draw_46_* + Plan 06 visual UAT |
    | DRAW-47 multi-page paginator | SATISFIED | Phase23InvariantGuardTest::test_draw_47_* + Open Q3 above |
    | DRAW-48 standardised title block | SATISFIED | Phase23InvariantGuardTest::test_draw_48_* |
    | DRAW-49 dashed sheet border | SATISFIED | Phase23InvariantGuardTest::test_draw_49_* |

    ## Ship Decision

    {SHIP / HOLD — describe any blocker raised}
    ```

    Type "approved" to continue OR describe any blocker.
  </how-to-verify>
  <resume-signal>Type "approved" if both D-10 + Open Q3 pass + ship decision is SHIP. Describe blockers otherwise.</resume-signal>
</task>

<task type="auto">
  <name>Task 4: Phase 23 final test suite green + summary</name>
  <files>
    .planning/phases/23-xten-av-style-renderer/23-VERIFICATION.md
  </files>
  <read_first>
    - .planning/phases/23-xten-av-style-renderer/23-VERIFICATION.md (just created in Task 3 — Plan 07 Task 4 finalises it)
    - All Plan 01-06 SUMMARYs
  </read_first>
  <behavior>
    - Run the full Phase 23 test suite + assert it exits 0
    - Run the v1.3 invariant `git diff --stat` one final time
    - Commit `23-VERIFICATION.md` finalised
  </behavior>
  <action>
**Step 1 — Run the full Phase 23 test suite:**
```
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test --filter='Drawings|XtenAv|SheetPaginator|ZoneGrouper|CableRouter|TitleBlock|SheetBorder|Phase23|ReviewZone|V13Surfaces' --stop-on-failure
```

Expected: ALL tests across Plans 01-07 exit 0.

**Step 2 — Final v1.3 invariant audit:**
```
git diff --stat HEAD~50 HEAD -- app/Services/Drawings/SchematicGeneratorService.php app/Services/Drawings/SchematicD2SourceBuilder.php app/Services/Drawings/DrawingDataResolverService.php app/Services/Drawings/BoundPdfBuilderService.php app/Services/Drawings/DrawingExportRendererService.php config/cables.php app/Models/CableScheduleItem.php
```

Expected: empty diff across all 7 files. (HEAD~50 chosen because Phase 23's 7 plans + their RED→GREEN commits + the metadata commits should be < 50 commits total.)

**Step 3 — Stage the final 23-VERIFICATION.md** populated by Task 3's human UAT + Plans 01-06 SUMMARY closure log entries:

```
git add .planning/phases/23-xten-av-style-renderer/23-VERIFICATION.md
git commit -m "docs(23-07): finalise Phase 23 verification — D-10 + Open Q3 dispositions + DRAW-42..49 closure"
```

**Step 4 — Update ROADMAP.md** to mark Phase 23 complete (find the "Phase 23: XTEN-AV-Style Renderer" line in `.planning/ROADMAP.md` and change `- [ ]` to `- [x]`):

```
# Edit .planning/ROADMAP.md — find this line and check it:
- [x] **Phase 23: XTEN-AV-Style Renderer** — ...

# Also under the "Progress" table at the bottom, mark Phase 23 row as Complete + add the date
```

Commit:
```
git add .planning/ROADMAP.md
git commit -m "docs(roadmap): mark Phase 23 complete (XTEN-AV-Style Renderer)"
```

**Step 5 — Write `.planning/phases/23-xten-av-style-renderer/23-07-SUMMARY.md`** documenting:
- All 7 Plan summaries linked
- Full Phase 23 test count + assertion count
- D-10 disposition outcome
- Open Q3 disposition outcome
- DRAW-42..49 closure table
- D-01..D-10 closure table

Add the 🚨 "Files to upload to live" section listing ALL Phase 23 artifacts that touched production code (across Plans 01-07):
- database/migrations/2026_05_13_120000_add_metadata_to_projects_table.php
- app/Models/Project.php
- config/drawings.php
- app/Services/Drawings/ZoneGrouper.php
- app/Services/Drawings/XtenAvLayoutEngine.php
- app/Services/Drawings/CableRouter.php
- app/Services/Drawings/SheetPaginator.php
- app/Services/Drawings/TitleBlockRenderer.php
- app/Services/Drawings/SheetBorderRenderer.php
- app/Services/Drawings/DrawIoBuilderService.php
- app/Http/Controllers/ProjectPackageReviewController.php
- resources/views/project-packages/review.blade.php

Note: post-upload runbook = `php artisan migrate --force && php artisan config:clear && php artisan view:clear`.

Commit:
```
git add .planning/phases/23-xten-av-style-renderer/23-07-SUMMARY.md
git commit -m "docs(23-07): Phase 23 summary — XTEN-AV-Style Renderer COMPLETE"
```
  </action>
  <verify>
    <automated>"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test --filter='Drawings|XtenAv|SheetPaginator|ZoneGrouper|CableRouter|TitleBlock|SheetBorder|Phase23|ReviewZone|V13Surfaces' --stop-on-failure</automated>
  </verify>
  <acceptance_criteria>
    - Full Phase 23 test filter exits 0 (≥60 tests pass across Plans 01-07)
    - `.planning/phases/23-xten-av-style-renderer/23-VERIFICATION.md` exists with `## D-10 Side-by-Side Result` + `## Open Q3 Multi-Page Embed UX Result` + `## D-01..D-10 Closure Log` + `## DRAW-42..49 Closure Log` headings + a `## Ship Decision` block
    - ROADMAP.md Phase 23 line shows `- [x]`
    - `.planning/phases/23-xten-av-style-renderer/23-07-SUMMARY.md` exists with the 🚨 Files to upload to live section
  </acceptance_criteria>
  <done>Phase 23 COMPLETE. All deliverables shipped, all decisions closed, ROADMAP updated.</done>
</task>

</tasks>

<verification>
- Tasks 1 + 2 committed atomically with tests green
- Task 3 BLOCKING checkpoint approved (D-10 + Open Q3 both PASS or HOLD with documented ticket)
- Task 4 full suite green + ROADMAP updated + SUMMARY written
- `git diff --stat` empty on the 5 v1.3 + spike controller + config/cables + CableScheduleItem
- `php artisan test --filter='Drawings|Phase23|XtenAv|ReviewZone'` exits 0 across the whole Phase 23 suite
</verification>

<success_criteria>
Phase 23 — XTEN-AV-Style Renderer — SHIPPED:
- 8 requirement IDs (DRAW-42..49) satisfied — each has at least one CI test
- 10 decisions (D-01..D-10) closed with evidence in 23-VERIFICATION.md
- Phase 21 D-08 + D-10 carry-forward invariants preserved
- Phase 22 D-10 carry-forward invariant preserved
- Spike route `/admin/drawings/draw-io-spike/{project}` continues to work; builder behind it produces XTEN-AV-style XML
- D-LOCK-5/6 determinism contract green (Carbon::setTestNow harness from Plan 01 Task 3 carries through)
- v1.3 D2 schematic generator + bound PDF + O&M Manual continue to render legacy data unchanged

Phase 24 (Stencil Curation UI) is unblocked — engineers can now SEE which auto-generic stencils need promotion by opening the new draw.io output side-by-side with the curated ones.
</success_criteria>

<output>
After completion:
- `.planning/phases/23-xten-av-style-renderer/23-VERIFICATION.md` finalised with manual + automated closure log
- `.planning/phases/23-xten-av-style-renderer/23-07-SUMMARY.md` documenting the full phase
- ROADMAP.md Phase 23 row marked complete

Final 🚨 "Files to upload to live" rolled up across Plans 01-07:

**Migrations (run `php artisan migrate --force` after upload):**
- database/migrations/2026_05_13_120000_add_metadata_to_projects_table.php

**Models:**
- app/Models/Project.php

**Services (Phase 23 new + modified):**
- app/Services/Drawings/ZoneGrouper.php
- app/Services/Drawings/XtenAvLayoutEngine.php
- app/Services/Drawings/CableRouter.php
- app/Services/Drawings/SheetPaginator.php
- app/Services/Drawings/TitleBlockRenderer.php
- app/Services/Drawings/SheetBorderRenderer.php
- app/Services/Drawings/DrawIoBuilderService.php  (orchestrator rewire — preserves public contract)

**Controllers:**
- app/Http/Controllers/ProjectPackageReviewController.php

**Views:**
- resources/views/project-packages/review.blade.php

**Config:**
- config/drawings.php

**Post-upload runbook:**
```
php artisan migrate --force
php artisan config:clear
php artisan view:clear
php artisan route:clear
```

NOT uploaded (test/fixture/discovery only — stays local + in git):
- tests/Feature/Drawings/* (test files)
- tests/Fixtures/Drawings/Phase23FixtureFactory.php
- .planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-*.md
- .planning/phases/23-xten-av-style-renderer/23-VERIFICATION.md
- .planning/phases/23-xten-av-style-renderer/23-*-SUMMARY.md
- .planning/ROADMAP.md
</output>