<?php

namespace Tests\Unit\Services\Rams;

use App\Services\Rams\DisplayLiftPolicy;
use Tests\TestCase;

/**
 * Phase 27 Plan 01 (RULE-02, RULE-03) — proves DisplayLiftPolicy's bands,
 * gate check, and shared sentences in isolation, no DB.
 *
 * Mirrors LegacyHazardNameFoldMapTest's structure: plain Tests\TestCase, no
 * RefreshDatabase, no factories, one focused test_* method per case, plus one
 * test iterating the introspection accessor (bands()).
 *
 * @see app/Services/Rams/DisplayLiftPolicy.php
 */
class DisplayLiftPolicyTest extends TestCase
{
    /** Test 1: the ≤14" scheduling/touch/control-panel exclusion returns null (no row at all). */
    public function test_small_control_panel_returns_no_row(): void
    {
        $this->assertNull(DisplayLiftPolicy::forSize(10.1, true));
        $this->assertNull(DisplayLiftPolicy::forSize(14.0, true));
    }

    /** Test 2: the ≤14" flag has no effect unless the inches value is also ≤14". */
    public function test_small_control_panel_flag_without_qualifying_size_is_not_excluded(): void
    {
        $result = DisplayLiftPolicy::forSize(43.0, true);
        $this->assertNotNull($result);
        $this->assertSame(1, $result['min_persons']);
    }

    /** Test 3: a value just under 55" is the reinstated single-operative band. */
    public function test_value_just_under_55_inches_is_one_operative(): void
    {
        $result = DisplayLiftPolicy::forSize(54.9);
        $this->assertSame(1, $result['min_persons']);
    }

    /** Test 4: exactly 43" (a plain mid-band value) is one operative. */
    public function test_43_inches_is_one_operative(): void
    {
        $result = DisplayLiftPolicy::forSize(43.0);
        $this->assertSame(1, $result['min_persons']);
    }

    /** Test 5: exactly 55" is the inclusive lower bound of the 2-operative band. */
    public function test_exactly_55_inches_is_two_operatives(): void
    {
        $result = DisplayLiftPolicy::forSize(55.0);
        $this->assertSame(2, $result['min_persons']);
    }

    /** Test 6: exactly 90" is the inclusive upper bound of the 2-operative band. */
    public function test_exactly_90_inches_is_two_operatives(): void
    {
        $result = DisplayLiftPolicy::forSize(90.0);
        $this->assertSame(2, $result['min_persons']);
    }

    /** Test 7: a value just over 90" is the 3-operative band. */
    public function test_value_just_over_90_inches_is_three_operatives(): void
    {
        $result = DisplayLiftPolicy::forSize(90.1);
        $this->assertSame(3, $result['min_persons']);
    }

    /** Test 8 (D-02): the >90" sentence never lets an aid discharge the third operative. */
    public function test_above_90_inches_sentence_does_not_let_an_aid_discharge_the_third_operative(): void
    {
        $result = DisplayLiftPolicy::forSize(95.0);
        $this->assertSame(3, $result['min_persons']);
        $this->assertStringNotContainsString('panel-lift trolley', $result['sentence']);
        $this->assertStringNotContainsString('Two persons may lift', $result['sentence']);
    }

    /** Test 9 (D-05): an unresolvable size silently takes the same 2-operative band/shape as 60". */
    public function test_unresolvable_size_returns_same_shape_as_60_inches(): void
    {
        $unresolvable = DisplayLiftPolicy::forSize(null);
        $sixty = DisplayLiftPolicy::forSize(60.0);

        $this->assertSame($sixty, $unresolvable);
        $this->assertSame(2, $unresolvable['min_persons']);
    }

    /** Test 10: violatesPolicy() flags 4 or more operatives at any size. */
    public function test_violates_policy_flags_four_or_more_operatives_at_any_size(): void
    {
        $this->assertTrue(DisplayLiftPolicy::violatesPolicy(4, 43.0));
        $this->assertTrue(DisplayLiftPolicy::violatesPolicy(4, null));
        $this->assertTrue(DisplayLiftPolicy::violatesPolicy(5, 95.0));
    }

    /** Test 11: violatesPolicy() flags 2 operatives above 90". */
    public function test_violates_policy_flags_two_operatives_above_90_inches(): void
    {
        $this->assertTrue(DisplayLiftPolicy::violatesPolicy(2, 95.0));
        $this->assertTrue(DisplayLiftPolicy::violatesPolicy(2, 90.1));
    }

    /** Test 12: violatesPolicy() flags 1 operative at 55" or larger. */
    public function test_violates_policy_flags_one_operative_at_55_inches_or_larger(): void
    {
        $this->assertTrue(DisplayLiftPolicy::violatesPolicy(1, 55.0));
        $this->assertTrue(DisplayLiftPolicy::violatesPolicy(1, 95.0));
    }

    /** Test 13: 1 operative below 55" is correct output, not a violation. */
    public function test_violates_policy_does_not_flag_one_operative_below_55_inches(): void
    {
        $this->assertFalse(DisplayLiftPolicy::violatesPolicy(1, 43.0));
        $this->assertFalse(DisplayLiftPolicy::violatesPolicy(1, 54.9));
    }

    /** Test 14 (D-05): an unresolvable size is never a gate violation, for 1, 2, or 3 stated persons. */
    public function test_violates_policy_never_flags_an_unresolvable_size(): void
    {
        $this->assertFalse(DisplayLiftPolicy::violatesPolicy(1, null));
        $this->assertFalse(DisplayLiftPolicy::violatesPolicy(2, null));
        $this->assertFalse(DisplayLiftPolicy::violatesPolicy(3, null));
    }

    /** Test 15: a compliant lift at every band is never flagged. */
    public function test_violates_policy_does_not_flag_compliant_lifts(): void
    {
        $this->assertFalse(DisplayLiftPolicy::violatesPolicy(2, 60.0));
        $this->assertFalse(DisplayLiftPolicy::violatesPolicy(2, 90.0));
        $this->assertFalse(DisplayLiftPolicy::violatesPolicy(3, 95.0));
    }

    /** Test 16 (RULE-03): wallMountRemovalStatement() carries the required sequence. */
    public function test_wall_mount_removal_statement_contains_required_sequence(): void
    {
        $statement = DisplayLiftPolicy::wallMountRemovalStatement();

        $this->assertStringContainsString('lowest practicable height', $statement);
        $this->assertStringContainsString('one operative each side', $statement);
    }

    /** Test 17: genericBandSummary() states all three numeric thresholds in prose. */
    public function test_generic_band_summary_contains_all_three_thresholds(): void
    {
        $summary = DisplayLiftPolicy::genericBandSummary();

        $this->assertStringContainsString('14', $summary);
        $this->assertStringContainsString('55', $summary);
        $this->assertStringContainsString('90', $summary);
    }

    /** Test 18: bands() returns exactly three ordered entries with no gaps. */
    public function test_bands_returns_three_ordered_entries_with_no_gaps(): void
    {
        $bands = DisplayLiftPolicy::bands();

        $this->assertCount(3, $bands);

        $this->assertNull($bands[0]['min_inches']);
        $this->assertSame(55.0, $bands[0]['max_inches']);
        $this->assertSame(1, $bands[0]['min_persons']);

        $this->assertSame(55.0, $bands[1]['min_inches']);
        $this->assertSame(90.0, $bands[1]['max_inches']);
        $this->assertSame(2, $bands[1]['min_persons']);

        $this->assertSame(90.0, $bands[2]['min_inches']);
        $this->assertNull($bands[2]['max_inches']);
        $this->assertSame(3, $bands[2]['min_persons']);
    }

    /** Test 19: the class is final, all-static, and has no constructor. */
    public function test_class_is_final_all_static_no_constructor(): void
    {
        $reflection = new \ReflectionClass(DisplayLiftPolicy::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertNull($reflection->getConstructor());

        foreach ($reflection->getMethods() as $method) {
            $this->assertTrue($method->isStatic(), "method {$method->getName()} must be static");
        }
    }
}
