<?php

namespace Tests\Feature\Drawings;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Drawings\Phase23FixtureFactory;
use Tests\TestCase;

/**
 * Phase 23 Plan 01 Task 3 — Wave 0 canary for the determinism contract.
 *
 * Plan 05 extends this with the real DrawIoBuilderService::build() byte-identity
 * assertion. Plan 01's job: PROVE the harness works by freezing time +
 * Auth context + fixture ordering and asserting the fixture factory returns
 * deterministic Projects (same equipment ordering / part_numbers / count).
 *
 * Per 23-RESEARCH.md Pattern 3 (lines 311-321) + Pitfall 3 (lines 367-371):
 *   - Carbon::setTestNow() freezes now()->format('Y-m-d') in TitleBlockRenderer.
 *   - Test does NOT call actingAs() — D-08 falls back to '—' for designed-by
 *     when Auth::user() is null. This is the documented stable state.
 *   - Fixture factory's deterministic ordering keeps part_numbers in the same
 *     sequence across calls (Pitfall 3 carryforward).
 */
class XtenAvDeterminismHarnessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Freeze time so TitleBlockRenderer::render's now()->format('Y-m-d')
        // produces stable bytes across calls.
        Carbon::setTestNow('2026-05-13 12:00:00');
        // Do NOT call actingAs() — designed-by falls back to '—' per D-08.
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_phase_23_config_keys_are_loaded(): void
    {
        $this->assertIsArray(config('drawings.zone_vocab'));
        $this->assertContains('RACK', config('drawings.zone_vocab'));
        $this->assertContains('OTHER', config('drawings.zone_vocab'));

        $this->assertIsArray(config('drawings.category_to_zone'));

        $this->assertSame(5, config('drawings.sub_sheet_thresholds.min_cables_per_signal'));
        $this->assertSame(3, config('drawings.sub_sheet_thresholds.min_devices_touching_signal'));

        $this->assertSame('AV-201', config('drawings.sheet_number_format.system_overview'));
        $this->assertSame('AV-205', config('drawings.sheet_number_format.network'));

        $this->assertSame(1600, config('drawings.page_dimensions.width'));
    }

    public function test_v1_3_signal_colours_key_untouched(): void
    {
        // Phase 22 locked config/cables.php as single source of truth for the
        // renderer; v1.3 config/drawings.php signal_colours stays for the D2
        // schematic generator (Phase 17). Plan 01 must NOT touch it.
        $this->assertSame('#C0392B', config('drawings.signal_colours.audio'));
        $this->assertSame('#8E44AD', config('drawings.signal_colours.network'));
    }

    public function test_fixture_factory_smallmtr_is_deterministic(): void
    {
        $a = Phase23FixtureFactory::smallMtr();
        $b = Phase23FixtureFactory::smallMtr();

        // Same equipment_list count
        $aLines = $a->fresh()->devicesWithStencils();
        $bLines = $b->fresh()->devicesWithStencils();

        $this->assertCount(count($aLines), $bLines);
        // Part numbers identical in order
        $this->assertSame(
            collect($aLines)->pluck('part_number')->all(),
            collect($bLines)->pluck('part_number')->all(),
        );
    }
}
