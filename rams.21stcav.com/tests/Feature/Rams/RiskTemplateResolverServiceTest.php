<?php

namespace Tests\Feature\Rams;

use App\Models\User;
use App\Services\RiskTemplateResolverService;
use Database\Seeders\HazardTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 26 Plan 04 (HAZ-02) — RiskTemplateResolverService wired to the
 * tiered HazardIncludeWhenResolver, merged with explicit engineer picks.
 *
 * Behaviours under test (see 26-04-PLAN.md Task 2 <behavior> block):
 *   1. A genuinely blank signal set (no activities, no drilling, no explicit
 *      names, no scope narrative) resolves to exactly the 4 always-tier
 *      hazards — never a full/old baseline, never zero.
 *   2. Ceiling-related activity signals additionally pull in the tier-2
 *      hazards keyed to `ceiling_works` (Working at height, Manual
 *      handling, Restricted access and ceiling voids).
 *   3. An explicitly-named hazard (manual create form pick) is always
 *      present in the resolved set, merged with — not replacing — the
 *      always-tier hazards.
 *   4. Disabling `rams_tier1.hazard_tiering_enabled` degrades the resolver
 *      to manual-only: zero auto-population, and critically zero
 *      old-baseline titles — the reversibility guarantee (never
 *      resurrects the fixed 11).
 */
class RiskTemplateResolverServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HazardTemplateSeeder::class);
    }

    private function hazardNames(array $result): array
    {
        return array_map(
            static fn (array $row): string => $row['hazard'],
            $result['hazards'],
        );
    }

    public function test_blank_signal_set_returns_exactly_the_four_always_tier_hazards(): void
    {
        $resolver = app(RiskTemplateResolverService::class);

        $result = $resolver->resolve([], false, null, [], []);

        $names = $this->hazardNames($result);

        $this->assertCount(4, $names);
        $this->assertContains('Slips, trips and falls', $names);
        $this->assertContains('Low voltage AV connections', $names);
        $this->assertContains('Fire and evacuation', $names);
        $this->assertContains('COSHH substances', $names);
    }

    public function test_ceiling_works_activity_pulls_in_tier_two_ceiling_hazards(): void
    {
        $resolver = app(RiskTemplateResolverService::class);

        $result = $resolver->resolve(['ceiling_works'], false, null, [], []);

        $names = $this->hazardNames($result);

        $this->assertContains('Working at height', $names);
        $this->assertContains('Manual handling', $names);
        $this->assertContains('Restricted access and ceiling voids', $names);

        // Always-tier hazards are still present alongside the tier-2 matches.
        $this->assertContains('Slips, trips and falls', $names);
    }

    public function test_explicit_hazard_pick_is_merged_with_always_tier_not_replaced(): void
    {
        $user = User::factory()->create();
        $resolver = app(RiskTemplateResolverService::class);

        $result = $resolver->resolve([], false, $user->id, ['Manual handling'], []);

        $names = $this->hazardNames($result);

        $this->assertContains('Manual handling', $names, 'explicit pick must be present even with no matching tier condition');
        $this->assertContains('Slips, trips and falls', $names, 'always-tier hazards must still be present — merge, not replace');
        $this->assertContains('Low voltage AV connections', $names);
        $this->assertContains('Fire and evacuation', $names);
        $this->assertContains('COSHH substances', $names);
    }

    public function test_disabled_tiering_flag_degrades_to_explicit_picks_only(): void
    {
        config(['rams_tier1.hazard_tiering_enabled' => false]);

        $user = User::factory()->create();
        $resolver = app(RiskTemplateResolverService::class);

        $result = $resolver->resolve([], false, $user->id, ['Manual handling'], []);

        $names = $this->hazardNames($result);

        $this->assertSame(['Manual handling'], $names, 'flag off must return ONLY the explicit pick — zero auto-population');

        // Reversibility guarantee: never resurrects the old fixed 11-hazard
        // baseline titles that this phase removed.
        $oldBaselineTitles = [
            'Working at Height',
            'Manual Handling of AV Equipment',
            'Electrical Isolation',
        ];
        foreach ($oldBaselineTitles as $title) {
            $this->assertNotContains($title, $names);
        }
    }
}
