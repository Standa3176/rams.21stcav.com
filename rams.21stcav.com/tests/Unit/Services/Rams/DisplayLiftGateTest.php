<?php

namespace Tests\Unit\Services\Rams;

use App\Exceptions\RamsGenerationException;
use App\Services\Rams\RamsComplianceUpgradeService;
use Tests\TestCase;

/**
 * Phase 27 Plan 03 (GATE-09) — throw/no-throw boundary proof for
 * RamsComplianceUpgradeService::enforceDisplayLiftGate().
 *
 * Reflection is used to exercise the private static method directly with
 * hand-built `material_handling_derived` fixtures — mirrors the established
 * ProjectSpecificRisksGatedTest / RamsComplianceUpgradeServiceDisplayLiftTest
 * pattern. No need to run deriveMaterialHandling()'s own keyword scan for
 * this test — the array shape is constructed directly, matching Plan 27-02's
 * documented item shape (`item`, `min_persons`, `inches`).
 *
 * ── Non-vacuity proof (development-time only, not a committed assertion) ──
 *
 * ROADMAP success criterion 3 requires "reverting the fix on a fixture and
 * observing the gate fire, then restoring". Procedure followed during
 * development of this test file:
 *
 *   1. Temporarily edited enforceDisplayLiftGate() in
 *      app/Services/Rams/RamsComplianceUpgradeService.php so the call
 *      `DisplayLiftPolicy::violatesPolicy((int) $minPersons, ...)` was
 *      replaced with a hardcoded `false` (simulating the gate's check being
 *      silently disabled/broken).
 *   2. Re-ran `php artisan test --filter=DisplayLiftGateTest`.
 *   3. Result: the 4 throwing tests below
 *      (test_four_or_more_operatives_always_throws,
 *      test_two_operatives_above_90_inches_throws,
 *      test_one_operative_at_55_inches_throws,
 *      test_one_operative_above_55_inches_throws) ALL FAILED — each expected
 *      RamsGenerationException but none was thrown, proving these tests are
 *      not vacuously passing.
 *   4. Restored the real `DisplayLiftPolicy::violatesPolicy(...)` call.
 *   5. Re-ran the filter again: all tests passed, confirming the restore.
 *
 * @see app/Services/Rams/RamsComplianceUpgradeService.php
 * @see app/Services/Rams/DisplayLiftPolicy.php
 * @see .planning/phases/27-manual-handling-display-lift-house-rules/27-03-PLAN.md
 */
class DisplayLiftGateTest extends TestCase
{
    private function invokePrivateStatic(string $method, array $args = []): mixed
    {
        $m = new \ReflectionMethod(RamsComplianceUpgradeService::class, $method);
        $m->setAccessible(true);

        return $m->invoke(null, ...$args);
    }

    private function dataWithItem(array $item): array
    {
        return [
            'material_handling_derived' => [
                'items' => [
                    array_merge(['item' => 'Test display item'], $item),
                ],
            ],
        ];
    }

    // ── Throwing boundary conditions ─────────────────────────────────────────

    public function test_four_or_more_operatives_always_throws(): void
    {
        $this->expectException(RamsGenerationException::class);

        $this->invokePrivateStatic('enforceDisplayLiftGate', [
            $this->dataWithItem(['min_persons' => 4, 'inches' => 32.0]),
        ]);
    }

    public function test_two_operatives_above_90_inches_throws(): void
    {
        $this->expectException(RamsGenerationException::class);

        $this->invokePrivateStatic('enforceDisplayLiftGate', [
            $this->dataWithItem(['min_persons' => 2, 'inches' => 95.0]),
        ]);
    }

    public function test_one_operative_at_55_inches_throws(): void
    {
        $this->expectException(RamsGenerationException::class);

        $this->invokePrivateStatic('enforceDisplayLiftGate', [
            $this->dataWithItem(['min_persons' => 1, 'inches' => 55.0]),
        ]);
    }

    public function test_one_operative_above_55_inches_throws(): void
    {
        $this->expectException(RamsGenerationException::class);

        $this->invokePrivateStatic('enforceDisplayLiftGate', [
            $this->dataWithItem(['min_persons' => 1, 'inches' => 65.0]),
        ]);
    }

    // ── Non-throwing (correct output, not a defect) ──────────────────────────

    public function test_one_operative_under_55_inches_does_not_throw(): void
    {
        $result = $this->invokePrivateStatic('enforceDisplayLiftGate', [
            $this->dataWithItem(['min_persons' => 1, 'inches' => 43.0]),
        ]);

        $this->assertSame(
            43.0,
            $result['material_handling_derived']['items'][0]['inches'],
            'enforceDisplayLiftGate() must return $data unchanged when no violation is found.',
        );
    }

    /** D-05: an unresolvable display size silently takes the 2-operative band — never a gate error. */
    public function test_two_operatives_with_null_inches_does_not_throw(): void
    {
        $result = $this->invokePrivateStatic('enforceDisplayLiftGate', [
            $this->dataWithItem(['min_persons' => 2, 'inches' => null]),
        ]);

        $this->assertNull($result['material_handling_derived']['items'][0]['inches']);
    }

    public function test_three_operatives_with_null_inches_does_not_throw(): void
    {
        $result = $this->invokePrivateStatic('enforceDisplayLiftGate', [
            $this->dataWithItem(['min_persons' => 3, 'inches' => null]),
        ]);

        $this->assertNull($result['material_handling_derived']['items'][0]['inches']);
    }

    // ── Non-display items (min_persons null) are skipped entirely ────────────

    public function test_null_min_persons_item_is_skipped_never_throws(): void
    {
        $result = $this->invokePrivateStatic('enforceDisplayLiftGate', [
            $this->dataWithItem(['min_persons' => null, 'inches' => null]),
        ]);

        $this->assertArrayHasKey('material_handling_derived', $result);
    }

    // ── Config default ────────────────────────────────────────────────────────

    public function test_display_lift_gate_enabled_defaults_true_when_env_unset(): void
    {
        $this->assertTrue(config('rams_tier1.display_lift_gate_enabled'));
    }

    // ── upgrade() wiring + config gate (public entry point) ──────────────────
    //
    // deriveMaterialHandling() is the SOLE producer of material_handling_derived
    // and always resolves display team size via the real DisplayLiftPolicy::
    // forSize(), which — by construction — can never disagree with
    // violatesPolicy() for the same inputs (D-03's single source of truth).
    // A quote/scope fixture parsed through the REAL deriveMaterialHandling()
    // can therefore never produce a genuinely violating item; proving
    // upgrade()'s OWN config-gated wiring (rather than the gate's internal
    // logic, already proven above) requires an isolated-process alias mock
    // of DisplayLiftPolicy so violatesPolicy() can be forced to disagree,
    // exactly as DisplayLiftDualPathTest does at the full entry-point level.
    // #[RunInSeparateProcess] is required because other tests in this suite
    // (including this file's own throw/no-throw tests above, when run in the
    // same process) already reference the real DisplayLiftPolicy class.

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_upgrade_calls_the_gate_and_throws_when_flag_enabled(): void
    {
        \Mockery::mock('alias:App\Services\Rams\DisplayLiftPolicy')
            ->shouldReceive('forSize')->andReturn(['min_persons' => 2, 'sentence' => 'Team lift — stub.'])
            ->shouldReceive('violatesPolicy')->andReturn(true);

        config(['rams_tier1.display_lift_gate_enabled' => true]);

        $this->expectException(RamsGenerationException::class);

        RamsComplianceUpgradeService::upgrade([
            'quote' => ['line_items' => [
                ['description' => '70 inch display', 'qty' => 1, 'category' => null],
            ]],
        ]);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_upgrade_never_calls_the_gate_when_flag_disabled(): void
    {
        \Mockery::mock('alias:App\Services\Rams\DisplayLiftPolicy')
            ->shouldReceive('forSize')->andReturn(['min_persons' => 2, 'sentence' => 'Team lift — stub.'])
            // violatesPolicy() is deliberately given NO expectation — if
            // upgrade() called it despite the flag being false, Mockery
            // would raise a "no matching handler" error, failing this test.
            ->shouldNotReceive('violatesPolicy');

        config(['rams_tier1.display_lift_gate_enabled' => false]);

        $result = RamsComplianceUpgradeService::upgrade([
            'quote' => ['line_items' => [
                ['description' => '70 inch display', 'qty' => 1, 'category' => null],
            ]],
        ]);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result['material_handling_derived']['items'] ?? []);
    }
}
