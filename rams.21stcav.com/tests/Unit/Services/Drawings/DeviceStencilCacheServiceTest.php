<?php

namespace Tests\Unit\Services\Drawings;

use App\Models\DeviceStencil;
use App\Services\Drawings\AutoGenericStencilGenerator;
use App\Services\Drawings\DeviceStencilCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Phase 21 Plan 01 Task 2 — locks DeviceStencilCacheService behaviour per
 * CONTEXT.md D-03 (cross-project caching contract). Asserts:
 *   - First call to resolveForPartNumber creates a Tier 1 auto-generated stencil
 *   - Second call returns the SAME row (no duplicate insert) — DB has 1 row
 *   - When a row is engineer-curated, the curated row is returned, NOT a new
 *     auto-generic (Phase 24 forward-compat — promotion survives subsequent
 *     reads via the firstOrCreate cache)
 *   - resolveMany handles empty part_numbers gracefully (stencil = null)
 *   - Lookup is case-insensitive trimmed (mirrors normalisePartNumber)
 *   - The auto-generic generator is only invoked on cache miss, never on hit
 *
 * @see app/Services/Drawings/DeviceStencilCacheService.php
 * @see .planning/phases/21-device-port-catalog-stencil-cache/21-CONTEXT.md (D-03 cache contract)
 */
class DeviceStencilCacheServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function realService(): DeviceStencilCacheService
    {
        return app(DeviceStencilCacheService::class);
    }

    public function test_first_resolve_creates_auto_generated_stencil(): void
    {
        $this->assertSame(0, DeviceStencil::count());

        $stencil = $this->realService()->resolveForPartNumber('NEAT-BAR-PRO', [
            'manufacturer' => 'NEAT',
            'model'        => 'Bar Pro',
            'name'         => 'Neat Bar Pro',
        ]);

        $this->assertInstanceOf(DeviceStencil::class, $stencil);
        $this->assertSame('neat-bar-pro', $stencil->part_number,
            'part_number must be normalised (lowercase trim) on insert');
        $this->assertSame(DeviceStencil::SOURCE_AUTO_GENERATED, $stencil->source);
        $this->assertSame(1, DeviceStencil::count());
    }

    public function test_second_resolve_returns_same_row_no_duplicate_insert(): void
    {
        $svc = $this->realService();

        $first  = $svc->resolveForPartNumber('NEAT-BAR-PRO', ['manufacturer' => 'NEAT']);
        $second = $svc->resolveForPartNumber('NEAT-BAR-PRO', ['manufacturer' => 'NEAT']);

        $this->assertSame($first->id, $second->id,
            'Repeated calls for the same part_number must return the same row');
        $this->assertSame(1, DeviceStencil::count(),
            'firstOrCreate must NOT insert a duplicate (D-03 cache contract)');
    }

    public function test_lookup_is_case_insensitive_and_trimmed(): void
    {
        $svc = $this->realService();

        $first = $svc->resolveForPartNumber('  NEAT-BAR-PRO  ', ['manufacturer' => 'NEAT']);
        $secondDifferentCasing = $svc->resolveForPartNumber('neat-bar-pro', ['manufacturer' => 'NEAT']);

        $this->assertSame($first->id, $secondDifferentCasing->id,
            'Whitespace + casing variants of the same part_number must hit the cache');
        $this->assertSame(1, DeviceStencil::count());
    }

    public function test_engineer_curated_row_is_returned_unchanged_on_subsequent_resolve(): void
    {
        // Simulate Phase 24 having promoted a stencil to engineer-curated.
        DeviceStencil::create([
            'part_number'   => 'curated-bar',
            'manufacturer'  => 'CuratedCo',
            'model'         => 'Pro',
            'display_name'  => 'CuratedCo Pro (engineer-built)',
            'mxgraph_xml'   => '<shape><foreground><text>Curated</text></foreground></shape>',
            'source'        => DeviceStencil::SOURCE_ENGINEER_CURATED,
        ]);

        $resolved = $this->realService()->resolveForPartNumber('CURATED-BAR', [
            'manufacturer' => 'WhateverCo',
            'model'        => 'Whatever',
        ]);

        $this->assertSame(DeviceStencil::SOURCE_ENGINEER_CURATED, $resolved->source,
            'Cache MUST return the curated row, NOT overwrite with auto-generic');
        $this->assertSame('CuratedCo Pro (engineer-built)', $resolved->display_name);
        $this->assertSame(1, DeviceStencil::count());
    }

    public function test_resolve_many_returns_enriched_lines_with_stencil(): void
    {
        $lines = [
            ['part_number' => 'PART-A', 'manufacturer' => 'A', 'model' => 'M1', 'name' => 'A M1', 'quantity' => 1, 'area' => 'Room 1'],
            ['part_number' => 'PART-B', 'manufacturer' => 'B', 'model' => 'M2', 'name' => 'B M2', 'quantity' => 2, 'area' => 'Room 2'],
        ];

        $result = $this->realService()->resolveMany($lines);

        $this->assertCount(2, $result);
        $this->assertSame('PART-A', $result[0]['part_number']);
        $this->assertInstanceOf(DeviceStencil::class, $result[0]['stencil']);
        $this->assertSame('part-a', $result[0]['stencil']->part_number);
        $this->assertInstanceOf(DeviceStencil::class, $result[1]['stencil']);
        $this->assertSame(2, DeviceStencil::count());
    }

    public function test_resolve_many_returns_null_stencil_for_empty_part_number(): void
    {
        $lines = [
            ['part_number' => 'PART-A', 'manufacturer' => 'A', 'model' => 'M1', 'name' => 'A M1', 'quantity' => 1, 'area' => null],
            ['part_number' => '',       'manufacturer' => 'X', 'model' => 'Y',  'name' => 'no part number', 'quantity' => 1, 'area' => null],
        ];

        $result = $this->realService()->resolveMany($lines);

        $this->assertCount(2, $result);
        $this->assertInstanceOf(DeviceStencil::class, $result[0]['stencil']);
        $this->assertNull($result[1]['stencil'],
            'Lines with empty part_number must be returned with stencil = null');
        $this->assertSame(1, DeviceStencil::count(),
            'Only the line with a part_number should produce a DeviceStencil row');
    }

    public function test_generator_is_not_invoked_on_cache_hit(): void
    {
        // Pre-seed the cache.
        DeviceStencil::create([
            'part_number'   => 'preseeded',
            'manufacturer'  => 'Pre',
            'model'         => 'Seeded',
            'mxgraph_xml'   => '<shape></shape>',
            'source'        => DeviceStencil::SOURCE_ENGINEER_CURATED,
        ]);

        $generator = Mockery::mock(AutoGenericStencilGenerator::class);
        $generator->shouldNotReceive('build');

        $svc = new DeviceStencilCacheService($generator);
        $stencil = $svc->resolveForPartNumber('PRESEEDED', ['manufacturer' => 'Pre']);

        $this->assertSame('preseeded', $stencil->part_number);
        $this->assertSame(DeviceStencil::SOURCE_ENGINEER_CURATED, $stencil->source);
    }

    public function test_generator_is_invoked_exactly_once_on_cache_miss(): void
    {
        $generator = Mockery::mock(AutoGenericStencilGenerator::class);
        $generator->shouldReceive('build')
            ->once()
            ->andReturn([
                'mxgraph_xml'    => '<shape><foreground></foreground></shape>',
                'default_width'  => 220,
                'default_height' => 140,
                'display_name'   => 'Mocked',
            ]);

        $svc = new DeviceStencilCacheService($generator);
        $svc->resolveForPartNumber('FRESH-PART', ['manufacturer' => 'Acme']);
        // Second call MUST NOT invoke build() — Mockery enforces ->once().
        $svc->resolveForPartNumber('FRESH-PART', ['manufacturer' => 'Acme']);

        $this->assertSame(1, DeviceStencil::count());
    }
}
