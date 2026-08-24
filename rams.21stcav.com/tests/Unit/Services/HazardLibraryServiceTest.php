<?php

namespace Tests\Unit\Services;

use App\Core\Modules\KnowledgeLibrary\HazardLibraryService;
use App\Models\HazardTemplate;
use App\Models\User;
use App\Services\Rams\LegacyHazardNameFoldMap;
use Database\Seeders\HazardTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 26 Plan 08 (HAZ-02 gap closure, round 2) — proves
 * LegacyHazardNameFoldMap::canonicalName() reaches a REAL seeded
 * HazardTemplate through the full resolveFromSeeds()/fuzzyMatch() call
 * chain, not just the map lookup in isolation, and drift-guards the map
 * against the seeder's actual 18 names.
 *
 * @see app/Core/Modules/KnowledgeLibrary/HazardLibraryService.php
 */
class HazardLibraryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HazardTemplateSeeder::class);
    }

    /** Test 5: every fold-map value matches a real seeded global HazardTemplate name exactly. */
    public function test_every_fold_map_value_matches_a_real_seeded_hazard_template_name(): void
    {
        $seededNames = HazardTemplate::where('is_global', true)->pluck('name')->all();

        foreach (LegacyHazardNameFoldMap::all() as $legacy => $canonical) {
            $this->assertContains(
                $canonical,
                $seededNames,
                "fold map entry '{$legacy}' => '{$canonical}' does not match any seeded HazardTemplate name — map/seeder drift",
            );
        }
    }

    /** Test 6: the fold reaches a real template through resolveFromSeeds()/fuzzyMatch(). */
    public function test_confined_spaces_resolves_to_a_real_template_via_resolve_from_seeds(): void
    {
        $user = User::factory()->create();
        $service = app(HazardLibraryService::class);

        $resolved = $service->resolveFromSeeds($user->id, ['Confined Spaces'])->first();

        $this->assertNotNull($resolved);
        $this->assertNotNull($resolved->id);
        $this->assertSame('Restricted access and ceiling voids', $resolved->name);
    }

    /** Test 7: one of D-02's original 6 folds resolves through the same call chain. */
    public function test_struck_by_falling_objects_resolves_to_working_at_height(): void
    {
        $user = User::factory()->create();
        $service = app(HazardLibraryService::class);

        $resolved = $service->resolveFromSeeds($user->id, ['Struck by Falling Objects'])->first();

        $this->assertNotNull($resolved);
        $this->assertSame('Working at height', $resolved->name);
    }

    /** Test 8: an unmapped, unmatchable name is untouched (D-04 non-regression). */
    public function test_genuinely_custom_hazard_name_is_untouched(): void
    {
        $user = User::factory()->create();
        $service = app(HazardLibraryService::class);

        $resolved = $service->resolveFromSeeds($user->id, ['My Genuinely Custom Hazard'])->first();

        $this->assertNotNull($resolved);
        $this->assertNull($resolved->id);
        $this->assertSame('My Genuinely Custom Hazard', $resolved->name);
    }
}
