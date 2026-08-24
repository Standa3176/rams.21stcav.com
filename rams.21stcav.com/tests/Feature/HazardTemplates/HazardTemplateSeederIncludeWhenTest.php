<?php

namespace Tests\Feature\HazardTemplates;

use App\Models\HazardTemplate;
use App\Models\User;
use Database\Seeders\HazardTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 26 Plan 01 — HazardTemplateSeeder regression coverage (HAZ-01, D-03, T-26-01).
 *
 * Live deploy sequence (informational only — NOT executed by this test; this
 * documents the human checkpoint Plan 26-06 runs against rams.21stcav.com):
 *   1. Deploy as `stcav` (never root).
 *   2. git pull
 *   3. php artisan migrate --force
 *   4. php artisan db:seed --class=HazardTemplateSeeder --force
 *
 * Three tests:
 *   1. After seeding, hazard_templates has exactly 18 is_global=true rows —
 *      the full 21cav-rams skill library.
 *   2. Re-running the seeder is idempotent: row count stays 18 (no
 *      duplicates), and an is_global=false (user-created) fixture row is
 *      byte-identical before/after both seed runs (D-03 — user rows are
 *      never touched, no truncate anywhere).
 *   3. HazardTemplateController::store(), hit as a real HTTP POST with a
 *      spoofed include_when in the payload, creates a row whose
 *      include_when is null — proving T-26-01's mitigation (the
 *      controller's literal-array construction excludes include_when from
 *      mass assignment) holds end-to-end through the route, not just by
 *      code inspection.
 */
class HazardTemplateSeederIncludeWhenTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_creates_exactly_eighteen_global_hazards(): void
    {
        $this->seed(HazardTemplateSeeder::class);

        $this->assertSame(18, HazardTemplate::where('is_global', true)->count());
    }

    public function test_reseeding_is_idempotent_and_leaves_user_rows_untouched(): void
    {
        $user = User::factory()->create();

        $userHazard = HazardTemplate::create([
            'user_id'         => $user->id,
            'name'            => 'My custom site hazard',
            'description'     => 'A user-authored hazard, not part of the seeded library.',
            'pre_likelihood'  => 2,
            'pre_severity'    => 2,
            'post_likelihood' => 1,
            'post_severity'   => 1,
            'controls'        => ['Do the custom control.'],
            'include_when'    => null,
            'is_global'       => false,
        ]);

        $this->seed(HazardTemplateSeeder::class);
        $afterFirst = HazardTemplate::where('is_global', true)->count();
        $userAfterFirst = $userHazard->fresh()->toArray();

        $this->seed(HazardTemplateSeeder::class);
        $afterSecond = HazardTemplate::where('is_global', true)->count();
        $userAfterSecond = $userHazard->fresh()->toArray();

        $this->assertSame(18, $afterFirst);
        $this->assertSame(18, $afterSecond, 'idempotent: no duplicate rows after re-running the seeder');

        $this->assertSame($userAfterFirst, $userAfterSecond,
            'user-created (is_global=false) row must be byte-identical before/after both seed runs');
        $this->assertSame(1, HazardTemplate::where('is_global', false)->count(),
            'seeder must never touch, duplicate, or delete is_global=false rows');
    }

    public function test_store_route_drops_spoofed_include_when_from_mass_assignment(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('hazard-templates.store'), [
            'name'            => 'Test',
            'pre_likelihood'  => 1,
            'pre_severity'    => 1,
            'post_likelihood' => 1,
            'post_severity'   => 1,
            'include_when'    => 'always',
        ]);

        $created = HazardTemplate::where('name', 'Test')->first();

        $this->assertNotNull($created, 'expected the store route to create the hazard template');
        $this->assertNull($created->include_when,
            'T-26-01: include_when must never be settable through HazardTemplateController mass assignment');
    }
}
