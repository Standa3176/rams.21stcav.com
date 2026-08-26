<?php

namespace Tests\Unit\Services\Rams;

use App\Services\Rams\RamsComplianceUpgradeService;
use Database\Seeders\HazardTemplateSeeder;
use Tests\TestCase;

/**
 * Phase 27 Plan 02 (RULE-02, RULE-03, RULE-12) — non-vacuity proof for:
 *
 *   - RULE-02: suggestHandlingMethod()'s display band ladder is now resolved
 *     through the single shared App\Services\Rams\DisplayLiftPolicy class
 *     (1 operative under 55", 2 from 55"-90" inclusive, 3 above 90") instead
 *     of the removed 4-person/3-person ladder (85"/65" thresholds).
 *   - RULE-12: the mount/bracket branch is now evaluated BEFORE the display
 *     branch, so a description containing both "mount" and "display" no
 *     longer inherits display handling text.
 *   - RULE-03: deriveMaterialHandling() now scans scope_items.decommission
 *     and appends DisplayLiftPolicy::wallMountRemovalStatement() for display
 *     items found there.
 *
 * Reflection is used to exercise the private static methods directly —
 * mirrors the established RamsComplianceUpgradeServiceCacheTest /
 * ProjectSpecificRisksGatedTest pattern.
 *
 * @see app/Services/Rams/RamsComplianceUpgradeService.php
 * @see app/Services/Rams/DisplayLiftPolicy.php
 * @see .planning/phases/27-manual-handling-display-lift-house-rules/27-02-PLAN.md
 */
class RamsComplianceUpgradeServiceDisplayLiftTest extends TestCase
{
    private function invokePrivateStatic(string $method, array $args = []): mixed
    {
        $m = new \ReflectionMethod(RamsComplianceUpgradeService::class, $method);
        $m->setAccessible(true);

        return $m->invoke(null, ...$args);
    }

    // -------------------------------------------------------------------
    // RULE-02 — the ladder is gone, DisplayLiftPolicy governs the bands.
    // -------------------------------------------------------------------

    public function test_deriveMaterialHandling_quote_line_item_98in_display_resolves_3_operatives(): void
    {
        $data = [
            'quote' => [
                'line_items' => [
                    ['description' => 'Samsung 98in Display', 'qty' => 1, 'category' => null],
                ],
            ],
        ];

        $result = $this->invokePrivateStatic('deriveMaterialHandling', [$data]);
        $items  = $result['material_handling_derived']['items'];

        $this->assertNotEmpty($items, 'A 98in display quote line must be detected as a heavy item.');
        $item = $items[0];

        $this->assertSame(3, $item['min_persons']);
        $this->assertSame(98.0, $item['inches']);
        $this->assertStringNotContainsString(
            'may lift if using',
            $item['handling_method'],
            'RULE-02/D-02: the >90" band must never let a mechanical aid discharge the required 3rd operative '
            . '(the removed aid-as-substitute construction).',
        );
        $this->assertStringNotContainsString('minimum 4', $item['handling_method']);
    }

    public function test_suggestHandlingMethod_boundary_54in_display_is_1_operative(): void
    {
        $resolved = $this->invokePrivateStatic('suggestHandlingMethod', ['54 inch display', 1]);

        $this->assertSame(1, $resolved['min_persons']);
    }

    public function test_suggestHandlingMethod_boundary_55in_display_is_2_operatives(): void
    {
        $resolved = $this->invokePrivateStatic('suggestHandlingMethod', ['55 inch display', 1]);

        $this->assertSame(2, $resolved['min_persons']);
    }

    public function test_suggestHandlingMethod_boundary_90in_display_is_2_operatives(): void
    {
        $resolved = $this->invokePrivateStatic('suggestHandlingMethod', ['90 inch display', 1]);

        $this->assertSame(2, $resolved['min_persons']);
    }

    public function test_suggestHandlingMethod_boundary_90_1in_display_is_3_operatives(): void
    {
        $resolved = $this->invokePrivateStatic('suggestHandlingMethod', ['90.1 inch display', 1]);

        $this->assertSame(
            3,
            $resolved['min_persons'],
            'RULE-02: these four boundary values (54/55/90/90.1) would have produced 2/2/2/2 under '
            . 'the pre-fix ladder (which only branched at 65"/85") — proving the band removal is non-vacuous.',
        );
    }

    public function test_suggestHandlingMethod_small_control_panel_still_returns_null(): void
    {
        // Unchanged existing behaviour — the ≤14" small-panel exclusion must
        // still short-circuit before DisplayLiftPolicy is ever consulted.
        $resolved = $this->invokePrivateStatic('suggestHandlingMethod', ['10.1 inch scheduling touch panel', 1]);

        $this->assertNull($resolved);
    }

    // -------------------------------------------------------------------
    // RULE-12 — mount/bracket branch runs BEFORE the display branch.
    // -------------------------------------------------------------------

    public function test_mount_shadowed_by_display_keyword_resolves_as_mount_not_display(): void
    {
        // Under the pre-fix branch order (display checked first), this
        // description matched on the word "display" and returned the
        // display band's "Team lift" wording — the exact 21CQ30960 §6.7
        // defect. Written so it would fail if Task 1's reorder were
        // reverted (confirmed by local revert/restore — see plan SUMMARY).
        $resolved = $this->invokePrivateStatic(
            'suggestHandlingMethod',
            ['Double-arm wall mount for 65 inch display', 1],
        );

        $this->assertNotNull($resolved);
        $this->assertStringStartsNotWith(
            'Team lift',
            $resolved['sentence'],
            'RULE-12: a mount/bracket description must never resolve through the display band, '
            . 'even when its text contains the word "display".',
        );
        $this->assertTrue(
            str_contains($resolved['sentence'], 'wall mount') || str_contains($resolved['sentence'], 'bracket'),
            'The mount branch\'s own wording must be returned, not the display band\'s.',
        );
    }

    // -------------------------------------------------------------------
    // RULE-03 — scope_items.decommission is now scanned; display strip-outs
    // get the wall-mount-removal statement appended.
    // -------------------------------------------------------------------

    public function test_deriveMaterialHandling_decommission_display_item_gets_wall_mount_removal_statement(): void
    {
        $data = [
            'scope_items' => [
                'decommission' => [
                    ['item_name' => 'Remove existing 65in wall-mounted display', 'qty' => 1],
                ],
            ],
        ];

        $result = $this->invokePrivateStatic('deriveMaterialHandling', [$data]);
        $items  = $result['material_handling_derived']['items'];

        $this->assertNotEmpty($items, 'A decommissioned display must now produce a §6.7 row (previously zero rows).');
        $item = $items[0];

        $this->assertStringContainsString('lowest practicable height', $item['handling_method']);
        $this->assertStringContainsString('one operative each side', $item['handling_method']);
    }

    public function test_deriveMaterialHandling_decommission_non_display_item_has_no_wall_mount_removal_statement(): void
    {
        $data = [
            'scope_items' => [
                'decommission' => [
                    ['item_name' => 'Remove existing 19in equipment rack', 'qty' => 1],
                ],
            ],
        ];

        $result = $this->invokePrivateStatic('deriveMaterialHandling', [$data]);
        $items  = $result['material_handling_derived']['items'];

        $this->assertNotEmpty($items, 'A decommissioned rack must still produce a §6.7 row.');
        $item = $items[0];

        $this->assertStringNotContainsString(
            'one operative each side',
            $item['handling_method'],
            'RULE-03 is display-specific — a non-display strip-out item must not receive the '
            . 'wall-mount-removal statement (house-rules.md:18-19).',
        );
    }

    // -------------------------------------------------------------------
    // Seeder re-sourcing — the DB row and DisplayLiftPolicy can never
    // disagree because the seeder now reads its sentences from the class.
    // -------------------------------------------------------------------

    public function test_seeded_manual_handling_row_no_longer_states_stale_two_operative_wording(): void
    {
        $seeder = new HazardTemplateSeeder();
        $m = new \ReflectionMethod($seeder, 'standardHazards');
        $m->setAccessible(true);
        $hazards = $m->invoke($seeder);

        $manualHandling = null;
        foreach ($hazards as $hazard) {
            if (($hazard['name'] ?? null) === 'Manual handling') {
                $manualHandling = $hazard;
                break;
            }
        }

        $this->assertNotNull($manualHandling, 'The seeder must still define a "Manual handling" hazard row.');

        $controlsText = implode(' ', $manualHandling['controls']);

        $this->assertStringNotContainsString('for every panel size', $controlsText);
        $this->assertStringContainsString('one operative each side', $controlsText);
        $this->assertCount(
            7,
            $manualHandling['controls'],
            'Re-sourcing two bullets from DisplayLiftPolicy must not add or remove entries from the array.',
        );
    }
}
