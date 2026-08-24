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

    /**
     * NOTE on the expected count: the plan's Task 2 behavior text says this
     * scenario "returns exactly the 4 always-tier hazard rows". That is
     * imprecise given Plan 26-02's locked HazardIncludeWhenResolver design
     * (not modified by this plan) — CONTEXT.md's binding 2026-08-23 tier-3
     * correction is explicit that the 5 `confirm:<key>` hazards are "always
     * surfaced as candidates requiring human confirmation, on every job...
     * up to 5 confirmation rows per job. That is the accepted cost, not a
     * defect to engineer away." So a genuinely blank signal set correctly
     * resolves to the 4 always-tier hazards PLUS the 5 always-confirm
     * hazards (9 total), with only the 4 always-tier rows carrying
     * needs_confirmation=false. This test asserts that actual, locked
     * contract rather than the plan text's imprecise restatement of it.
     */
    public function test_blank_signal_set_returns_the_four_always_tier_plus_five_confirm_tier_hazards(): void
    {
        $resolver = app(RiskTemplateResolverService::class);

        $result = $resolver->resolve([], false, null, [], []);

        $names = $this->hazardNames($result);

        $this->assertCount(9, $names, 'expected 4 always-tier + 5 always-confirm-tier hazards');
        $this->assertContains('Slips, trips and falls', $names);
        $this->assertContains('Low voltage AV connections', $names);
        $this->assertContains('Fire and evacuation', $names);
        $this->assertContains('COSHH substances', $names);

        // The 5 confirm-tier hazards are always surfaced too (D-06 / the
        // tier-3 correction), each carrying needs_confirmation = true.
        $confirmRows = array_filter($result['hazards'], fn (array $row): bool => $row['needs_confirmation'] === true);
        $this->assertCount(5, $confirmRows);

        $alwaysRows = array_filter($result['hazards'], fn (array $row): bool => $row['needs_confirmation'] === false);
        $this->assertCount(4, $alwaysRows);
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

    // ══════════════════════════════════════════════════════════════════════════
    // Phase 26 Plan 07 (HAZ-02 gap closure) — tieredRowsNotAlreadyPresent(),
    // the reusable tier-1/3 fetch-and-dedup entry point for callers (namely
    // RamsBuilderService::reviewedToRisk()) that already hold a fully-formed
    // register built from reviewed engineer picks, not just a list of names
    // to resolve through resolveFromSeeds().
    // ══════════════════════════════════════════════════════════════════════════

    private function tieredNames(array $rows): array
    {
        return array_map(static fn (array $row): string => $row['hazard'], $rows);
    }

    public function test_tiered_rows_not_already_present_blank_input_returns_nine_rows(): void
    {
        $resolver = app(RiskTemplateResolverService::class);

        $rows = $resolver->tieredRowsNotAlreadyPresent([], [], false, '');

        $this->assertCount(9, $rows, 'expected 4 always-tier + 5 confirm-tier rows when nothing is already present');

        $names = $this->tieredNames($rows);
        $this->assertContains('Slips, trips and falls', $names);
        $this->assertContains('Low voltage AV connections', $names);
        $this->assertContains('Fire and evacuation', $names);
        $this->assertContains('COSHH substances', $names);

        $confirmRows = array_filter($rows, fn (array $row): bool => $row['needs_confirmation'] === true);
        $this->assertCount(5, $confirmRows);

        $alwaysRows = array_filter($rows, fn (array $row): bool => $row['needs_confirmation'] === false);
        $this->assertCount(4, $alwaysRows);
    }

    public function test_tiered_rows_not_already_present_excludes_named_always_tier_hazards(): void
    {
        $resolver = app(RiskTemplateResolverService::class);

        $rows = $resolver->tieredRowsNotAlreadyPresent(
            ['Slips, trips and falls', 'Low voltage AV connections', 'Fire and evacuation', 'COSHH substances'],
            [],
            false,
            '',
        );

        $this->assertCount(5, $rows, 'only the 5 confirm-tier rows should remain once the 4 always-tier names are already present');

        $names = $this->tieredNames($rows);
        $this->assertNotContains('Slips, trips and falls', $names);
        $this->assertNotContains('Low voltage AV connections', $names);
        $this->assertNotContains('Fire and evacuation', $names);
        $this->assertNotContains('COSHH substances', $names);
    }

    public function test_tiered_rows_not_already_present_dedup_is_case_insensitive_and_trims(): void
    {
        $resolver = app(RiskTemplateResolverService::class);

        $rows = $resolver->tieredRowsNotAlreadyPresent(
            ['  low voltage av connections  '],
            [],
            false,
            '',
        );

        $names = $this->tieredNames($rows);
        $this->assertNotContains('Low voltage AV connections', $names, 'dedup must be case-insensitive and trim whitespace');
        $this->assertCount(8, $rows, 'the other 3 always-tier + 5 confirm-tier rows are still returned');
    }

    public function test_tiered_rows_not_already_present_returns_empty_when_tiering_disabled(): void
    {
        config(['rams_tier1.hazard_tiering_enabled' => false]);

        $resolver = app(RiskTemplateResolverService::class);

        $rows = $resolver->tieredRowsNotAlreadyPresent(['Manual handling'], ['ceiling_works'], true, 'drilling into the ceiling');

        $this->assertSame([], $rows, 'flag off must return zero rows regardless of existing names or signals — the reversibility guarantee');
    }

    public function test_tiered_rows_not_already_present_matches_tier_two_activity_signal(): void
    {
        $resolver = app(RiskTemplateResolverService::class);

        $rows = $resolver->tieredRowsNotAlreadyPresent([], ['ceiling_works'], false, '');

        $names = $this->tieredNames($rows);
        $this->assertContains(
            'Restricted access and ceiling voids',
            $names,
            'tier-2 activity signal matching must flow through the same HazardIncludeWhenResolver call resolveHazards() uses',
        );
    }
}
