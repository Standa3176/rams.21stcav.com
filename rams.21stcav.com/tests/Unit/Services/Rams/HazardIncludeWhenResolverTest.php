<?php

namespace Tests\Unit\Services\Rams;

use App\Models\HazardTemplate;
use App\Services\Rams\HazardIncludeWhenResolver;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Phase 26 Plan 02 (HAZ-02) — proves HazardIncludeWhenResolver's tier
 * evaluation in isolation, independent of any DB schema or seeded data.
 *
 * No RefreshDatabase — the whole point of the resolver's design is that it
 * needs nothing but plain (unsaved) HazardTemplate instances and a $signals
 * array. Fixtures below never call ->save().
 *
 * Two fixture shapes are used rather than one shared 18-row library:
 *
 *   - alwaysAndSignalFixture(): the 4 tier-1 "always" rows + all 9 tier-2
 *     "signal:<key>" rows + 1 include_when=null row. Deliberately excludes
 *     tier-3 "confirm:<key>" rows, because tier 3 is unconditionally
 *     returned on every job (D-06 + the 2026-08-23 correction) — including
 *     it here would make Test 1's "returns exactly the 4 always rows"
 *     assertion false for reasons unrelated to what Test 1 is proving.
 *   - confirmFixture(): the 5 tier-3 "confirm:<key>" rows, used by Tests
 *     6 and 7 to prove the always-returned-always-flagged guarantee.
 *
 * @see app/Services/Rams/HazardIncludeWhenResolver.php
 * @see .planning/phases/26-hazard-library-structural-inversion/26-CONTEXT.md
 */
class HazardIncludeWhenResolverTest extends TestCase
{
    private function resolver(): HazardIncludeWhenResolver
    {
        return new HazardIncludeWhenResolver();
    }

    private function template(int $id, string $name, ?string $includeWhen): HazardTemplate
    {
        return new HazardTemplate([
            'id' => $id,
            'name' => $name,
            'include_when' => $includeWhen,
            'is_global' => true,
        ]);
    }

    /**
     * 4 always + 9 signal:<key> + 1 null row. No confirm:<key> rows — see
     * class docblock for why.
     */
    private function alwaysAndSignalFixture(): Collection
    {
        return collect([
            $this->template(1, 'Working at height', 'signal:mounting_above_reach'),
            $this->template(2, 'Manual handling', 'signal:display_mount_or_rack'),
            $this->template(3, 'Electrical', 'signal:mains_connection'),
            $this->template(4, 'Slips, trips and falls', 'always'),
            $this->template(5, 'Noise and vibration', 'signal:drilling_or_percussive'),
            $this->template(6, 'Restricted access and ceiling voids', 'signal:ceiling_void_access'),
            $this->template(7, 'Cable pulling and termination', 'signal:first_fix_cabling'),
            $this->template(8, 'Low voltage AV connections', 'always'),
            $this->template(9, 'Fixings into walls, ceilings and pillars', 'signal:any_penetration'),
            $this->template(10, 'Dust from drilling and cutting', 'signal:any_drilling'),
            $this->template(11, 'Fire and evacuation', 'always'),
            $this->template(12, 'COSHH substances', 'always'),
            $this->template(13, 'Decommissioning and WEEE', 'signal:strip_out_or_decommission'),
            $this->template(14, 'Custom user hazard', null),
        ]);
    }

    /** The 5 tier-3 confirm:<key> rows. */
    private function confirmFixture(): Collection
    {
        return collect([
            $this->template(21, 'Occupied premises', 'confirm:occupied_premises'),
            $this->template(22, 'Asbestos-containing materials', 'confirm:asbestos'),
            $this->template(23, 'Vehicle and plant movement', 'confirm:vehicle_plant'),
            $this->template(24, 'Lone and small-team working', 'confirm:lone_working'),
            $this->template(25, 'Occupational road risk', 'confirm:road_risk'),
        ]);
    }

    private function emptySignals(): array
    {
        return [
            'activities' => [],
            'drilling_required' => false,
            'scope_narrative' => '',
        ];
    }

    public function test_tier_1_always_rows_returned_unconditionally_with_empty_signals(): void
    {
        $result = $this->resolver()->resolve($this->alwaysAndSignalFixture(), $this->emptySignals());

        $this->assertSame(4, $result->count());
        $this->assertEqualsCanonicalizing(
            ['Slips, trips and falls', 'Low voltage AV connections', 'Fire and evacuation', 'COSHH substances'],
            $result->pluck('name')->all(),
        );

        foreach ($result as $hazard) {
            $this->assertFalse($hazard->needs_confirmation);
            $this->assertSame('always', $hazard->match_tier);
        }
    }

    public function test_tier_2_activity_signal_match_includes_hazard_unconfirmed(): void
    {
        $signals = array_merge($this->emptySignals(), ['activities' => ['ceiling_works']]);

        $result = $this->resolver()->resolve($this->alwaysAndSignalFixture(), $signals);
        $names = $result->pluck('name')->all();

        foreach (['Working at height', 'Manual handling', 'Restricted access and ceiling voids'] as $expectedName) {
            $this->assertContains($expectedName, $names);
        }

        $matched = $result->firstWhere('name', 'Working at height');
        $this->assertFalse($matched->needs_confirmation);
        $this->assertSame('deterministic', $matched->match_tier);
    }

    public function test_tier_2_drilling_required_signal_includes_all_drilling_hazards_unconfirmed(): void
    {
        $signals = array_merge($this->emptySignals(), ['drilling_required' => true]);

        $result = $this->resolver()->resolve($this->alwaysAndSignalFixture(), $signals);
        $names = $result->pluck('name')->all();

        foreach (['Noise and vibration', 'Fixings into walls, ceilings and pillars', 'Dust from drilling and cutting'] as $expectedName) {
            $this->assertContains($expectedName, $names);
        }

        foreach ($result->whereIn('name', [
            'Noise and vibration',
            'Fixings into walls, ceilings and pillars',
            'Dust from drilling and cutting',
        ]) as $hazard) {
            $this->assertFalse($hazard->needs_confirmation);
            $this->assertSame('deterministic', $hazard->match_tier);
        }
    }

    public function test_tier_2_keyword_signal_match_on_narrative_includes_hazard(): void
    {
        $signals = array_merge($this->emptySignals(), [
            'scope_narrative' => 'new mains isolation required before the rack is powered',
        ]);

        $result = $this->resolver()->resolve($this->alwaysAndSignalFixture(), $signals);

        $this->assertContains('Electrical', $result->pluck('name')->all());

        $matched = $result->firstWhere('name', 'Electrical');
        $this->assertFalse($matched->needs_confirmation);
        $this->assertSame('deterministic', $matched->match_tier);
    }

    public function test_tier_2_no_match_drops_hazard_entirely_not_flagged(): void
    {
        $result = $this->resolver()->resolve($this->alwaysAndSignalFixture(), $this->emptySignals());

        // Proves genuine absence (dropped), not merely a false needs_confirmation.
        $this->assertNotContains('Electrical', $result->pluck('name')->all());
    }

    public function test_tier_3_confirm_rows_always_included_and_flagged_with_empty_signals(): void
    {
        $result = $this->resolver()->resolve($this->confirmFixture(), $this->emptySignals());

        $this->assertSame(5, $result->count());

        foreach ($result as $hazard) {
            $this->assertTrue($hazard->needs_confirmation);
            $this->assertSame('confirm', $hazard->match_tier);
            $this->assertFalse($hazard->pre_ticked);
        }
    }

    public function test_tier_3_keyword_hit_pre_ticks_but_never_downgrades_confirmation(): void
    {
        $signals = array_merge($this->emptySignals(), [
            'scope_narrative' => 'warehouse installation, loading bay access required',
        ]);

        $result = $this->resolver()->resolve($this->confirmFixture(), $signals);

        $matched = $result->firstWhere('name', 'Vehicle and plant movement');

        $this->assertNotNull($matched);
        $this->assertTrue($matched->needs_confirmation, 'a keyword hit must never auto-confirm the hazard');
        $this->assertTrue($matched->pre_ticked);
        $this->assertSame('confirm', $matched->match_tier);

        // Every other tier-3 row is untouched by this narrative's keywords —
        // still included, still flagged, just not pre-ticked.
        $unmatched = $result->firstWhere('name', 'Asbestos-containing materials');
        $this->assertNotNull($unmatched);
        $this->assertTrue($unmatched->needs_confirmation);
        $this->assertFalse($unmatched->pre_ticked);
    }

    public function test_null_include_when_row_never_returned_regardless_of_signals(): void
    {
        $signals = [
            'activities' => ['ceiling_works', 'display_installation', 'av_rack', 'structured_cabling'],
            'drilling_required' => true,
            'scope_narrative' => 'mains isolation, ceiling void access, strip-out, warehouse, asbestos, lone work',
        ];

        $result = $this->resolver()->resolve($this->alwaysAndSignalFixture(), $signals);

        $this->assertNotContains('Custom user hazard', $result->pluck('name')->all());
    }
}
