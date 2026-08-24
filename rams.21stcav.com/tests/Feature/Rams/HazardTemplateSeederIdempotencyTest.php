<?php

namespace Tests\Feature\Rams;

use App\Models\HazardTemplate;
use Database\Seeders\HazardTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 26 Plan 06 — belt-and-braces `HazardTemplateSeeder` idempotency
 * regression, distinct from Plan 26-01's inline coverage
 * (`tests/Feature/HazardTemplates/HazardTemplateSeederIncludeWhenTest.php`).
 *
 * That earlier test proves row count stability and D-03's user-row safety.
 * This one is narrower and specifically targets the upsert path's most
 * dangerous failure mode for Phase 26: silently nulling out `include_when`
 * on a re-run, which would resurrect D-04's "manual-only" behaviour for
 * every one of the 18 global hazards and quietly stop auto-population dead
 * — the exact regression that would NOT be caught by a row-count assertion
 * alone.
 *
 * @see database/seeders/HazardTemplateSeeder.php
 * @see tests/Feature/HazardTemplates/HazardTemplateSeederIncludeWhenTest.php
 */
class HazardTemplateSeederIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_twice_keeps_eighteen_global_rows_each_time(): void
    {
        $this->seed(HazardTemplateSeeder::class);
        $this->assertSame(18, HazardTemplate::where('is_global', true)->count(),
            'expected exactly 18 global hazards after the first seed run');

        $this->seed(HazardTemplateSeeder::class);
        $this->assertSame(18, HazardTemplate::where('is_global', true)->count(),
            'expected exactly 18 global hazards after the second seed run (idempotent — no duplicates)');
    }

    public function test_every_global_row_has_a_non_null_include_when_after_reseeding(): void
    {
        $this->seed(HazardTemplateSeeder::class);
        $this->seed(HazardTemplateSeeder::class);

        $rows = HazardTemplate::where('is_global', true)->get();

        $this->assertCount(18, $rows);

        foreach ($rows as $row) {
            $this->assertNotNull(
                $row->include_when,
                "the upsert path must not null out include_when on a re-run — global hazard '{$row->name}' has a null include_when after the second seed call",
            );
        }
    }
}
