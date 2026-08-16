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
 *   - DrawIoSpikeController constructor still injects DrawIoBuilderService +
 *     DrawingService (Phase 21 D-08; type-based check, not arity — see
 *     quick task 260816-t5c)
 *   - CableScheduleItem empty $with property (Phase 22 D-10)
 *   - config/cables.php signal_type_colours single source of truth (Phase 22)
 *
 * Phase 23 service classes ZoneGrouper / XtenAvLayoutEngine / CableRouter /
 * SheetPaginator / TitleBlockRenderer / SheetBorderRenderer / DrawIoBuilderService
 * must NOT be imported by the 5 v1.3 surface files (Phase 21 D-10 strict-additive
 * contract). This test additionally locks the determinism + no-AI invariants of
 * those new Phase 23 helpers (D-LOCK-5/6).
 *
 * Pinned fork base for any future git-diff style guards: 6f23f37 (origin/master
 * at the time Phase 23 branched). Documented here per Plan 07 checker warning
 * #7 — replace hardcoded HEAD~50 with the actual branch fork point so the
 * baseline is stable as Phase 23's plan commits accumulate.
 */
class V13SurfacesUntouchedTest extends TestCase
{
    /**
     * The 5 v1.3 surface files locked by Phase 21 D-10 + Phase 22 D-10.
     * Phase 23 is strictly additive — these files must keep rendering legacy
     * data without behavioural change.
     *
     * @var array<int, string>
     */
    private const V13_SURFACES = [
        'app/Services/Drawings/SchematicGeneratorService.php',
        'app/Services/Drawings/SchematicD2SourceBuilder.php',
        'app/Services/Drawings/DrawingDataResolverService.php',
        'app/Services/Drawings/BoundPdfBuilderService.php',
        'app/Services/Drawings/DrawingExportRendererService.php',
    ];

    /**
     * Phase 23 service classes that must not appear as imports inside any
     * v1.3 surface (the strict-additive contract). Also the targets for the
     * D-LOCK-5/6 determinism + no-AI assertions below.
     *
     * @var array<int, string>
     */
    private const PHASE_23_SERVICES = [
        'app/Services/Drawings/ZoneGrouper.php',
        'app/Services/Drawings/XtenAvLayoutEngine.php',
        'app/Services/Drawings/CableRouter.php',
        'app/Services/Drawings/SheetPaginator.php',
        'app/Services/Drawings/TitleBlockRenderer.php',
        'app/Services/Drawings/SheetBorderRenderer.php',
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
            $this->assertIsString($contents, "Failed reading v1.3 surface: {$path}");
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $contents,
                    "v1.3 surface {$path} imports Phase 23 class — D-10 invariant violated",
                );
            }
        }
    }

    public function test_cable_schedule_item_with_property_is_empty(): void
    {
        // Phase 22 D-10 — a class-level eager load would force LEFT JOINs across
        // XLSX export + bound-PDF + schematic generator read paths.
        $reflection = new ReflectionProperty(CableScheduleItem::class, 'with');
        $reflection->setAccessible(true);
        $instance = new CableScheduleItem();
        $this->assertSame([], $reflection->getValue($instance));
    }

    /**
     * Phase 21 D-08 — DrawIoBuilderService + DrawingService must both stay
     * injected into the spike controller.
     *
     * Quick task 260816-t5c: this used to assert the constructor had exactly
     * 2 parameters (checked positionally). Security batch `9a6837c`
     * (WR-03/4/5) legitimately added a third dependency
     * (`SvgSanitizerService`, for exportSvg's SVG sanitiser), which broke
     * both the count and the position-2 assumption even though D-08's actual
     * rule — that DrawIoBuilderService and DrawingService both survive — was
     * never violated. Assert by type membership instead of count/position.
     */
    public function test_draw_io_spike_controller_still_injects_builder_and_drawing_service(): void
    {
        $reflection = new ReflectionClass(DrawIoSpikeController::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);

        $types = array_map(
            fn ($p) => $p->getType()?->getName(),
            $constructor->getParameters()
        );

        $this->assertContains(DrawIoBuilderService::class, $types,
            'Constructor must inject DrawIoBuilderService (the new canonical builder)');
        $this->assertContains(DrawingService::class, $types,
            'Constructor must STILL inject DrawingService (used by saveXml / exportSvg)');
    }

    public function test_draw_io_spike_builder_service_shim_still_delegates(): void
    {
        // Phase 21 D-08 — DrawIoSpikeBuilderService preserved as a 10-line shim
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

        $this->assertSame('#C0392B', $colours['audio']   ?? null);
        $this->assertSame('#2980B9', $colours['video']   ?? null);
        $this->assertSame('#27AE60', $colours['control'] ?? null);
        $this->assertSame('#8E44AD', $colours['network'] ?? null);
        $this->assertSame('#E67E22', $colours['usb']     ?? null);
        $this->assertSame('#16A085', $colours['speaker'] ?? null);
        $this->assertSame('#7F8C8D', $colours['power']   ?? null);
        $this->assertSame('#000000', $colours['unknown'] ?? null);
    }

    public function test_no_phase_23_class_writes_to_database(): void
    {
        // D-LOCK-5/6 — Phase 23 renderer is deterministic and read-only.
        // (Cache-miss writes happen inside Project::devicesWithStencils(), NOT
        // inside any of these renderer classes — Phase 21 D-07 carry-forward.)
        $writeIndicators = [
            '->update(',
            '->save(',
            '->delete(',
            '::create(',
            '::firstOrCreate(',
            '::updateOrCreate(',
            'DB::insert(',
            'DB::update(',
        ];

        foreach (self::PHASE_23_SERVICES as $path) {
            $contents = file_get_contents(base_path($path));
            $this->assertIsString($contents, "Failed reading Phase 23 service: {$path}");
            foreach ($writeIndicators as $write) {
                $this->assertStringNotContainsString(
                    $write,
                    $contents,
                    "{$path} contains Eloquent write '{$write}' — D-LOCK-5/6 determinism contract violated",
                );
            }
        }
    }

    public function test_no_phase_23_class_calls_ai(): void
    {
        // D-LOCK-5 — CLAUDE.md "AI is ONLY for formatting and method statement
        // structuring — never for inventing scope, equipment, or design".
        // The Phase 23 renderer falls under "design output" and must remain
        // deterministic.
        $aiSymbols = ['AIManager', 'AICache', 'AIUsage'];

        $orchestratorAndHelpers = array_merge(
            self::PHASE_23_SERVICES,
            ['app/Services/Drawings/DrawIoBuilderService.php'],
        );

        foreach ($orchestratorAndHelpers as $path) {
            $contents = file_get_contents(base_path($path));
            $this->assertIsString($contents, "Failed reading Phase 23 service: {$path}");
            foreach ($aiSymbols as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $contents,
                    "{$path} references {$needle} — D-LOCK-5 CLAUDE.md AI-only-for-formatting constraint violated",
                );
            }
        }
    }
}
