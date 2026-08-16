<?php

namespace Tests\Unit\Services\Drawings;

use App\Models\Device;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use App\Services\Drawings\DrawingDataResolverService;
use App\Services\Imports\EquipmentCategoryClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Phase 18 Plan 01 — locks the rackStackForProject() return shape that
 * Plan 18-03's editRack controller depends on:
 *
 *   1. Top-level shape  : ['palette' => array<row>]
 *   2. Per-row contract : exact key list (equipment_id, name, manufacturer,
 *                          part_no, qty, u_height, is_rack_mounted,
 *                          requires_ventilation_gap_above,
 *                          requires_ventilation_gap_below)
 *   3. Cable filtering  : line items in the cables/consumables/services
 *                          category are excluded by filterHardware()
 *   4. Ordering         : rack-mounted rows FIRST, others SECOND (DRAW-09
 *                          palette ordering — full AVIXA auto-place is v1.3.x)
 *   5. Type contract    : u_height is float-or-null, is_rack_mounted is
 *                          bool-or-null
 *
 * @see app/Services/Drawings/DrawingDataResolverService.php::rackStackForProject
 */
class RackStackForProjectTest extends TestCase
{
    use RefreshDatabase;

    private function makeProject(array $extracted): Project
    {
        $user = User::factory()->create();

        $project = Project::create([
            'user_id' => $user->id,
            'name' => 'Rack Stack Test Project',
            'ref' => 'RACK-STACK-001',
            'client_name' => 'Test Client Ltd',
            'site_address' => '1 Stack Street, London',
            'status' => 'quote_imported',
        ]);

        ProjectPackage::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'project_name' => $project->name,
            'project_ref' => $project->ref,
            'client_name' => $project->client_name,
            'site_address' => $project->site_address,
            'status' => 'approved',
            'extracted_data' => $extracted,
            'reviewed_data' => $extracted,
        ]);

        return $project->fresh();
    }

    /**
     * Build a project + Device rows representing the locked Plan 18-03
     * fixture: 1 rack-mounted item, 1 non-rack item, 1 cable line item
     * (filtered out).
     */
    private function buildFixtureProject(): Project
    {
        $project = $this->makeProject([
            'rooms' => [['id' => 1, 'name' => 'Boardroom']],
            'equipment_list' => [
                [
                    'id' => 'eq1',
                    'part_no' => 'AM-3200-GV',
                    'name' => 'Crestron AirMedia 3200',
                    'manufacturer' => 'Crestron',
                    'qty' => 1,
                    'area' => 'Boardroom',
                ],
                [
                    'id' => 'eq2',
                    'part_no' => 'FW-75BZ35L',
                    'name' => 'Sony FW-75BZ35L Display',
                    'manufacturer' => 'Sony',
                    'qty' => 2,
                    'area' => 'Boardroom',
                ],
                [
                    'id' => 'eq3',
                    'part_no' => 'HDMI-CAB-5M',
                    'name' => 'HDMI 2.0 cable 5m',
                    'manufacturer' => 'Generic',
                    'qty' => 4,
                    'category' => 'cables',
                    'area' => 'Boardroom',
                ],
            ],
        ]);

        // Device row 1 — rack-mounted (true, 1U)
        Device::create([
            'project_id' => $project->id,
            'description' => 'Crestron AirMedia 3200',
            'manufacturer' => 'Crestron',
            'part_no' => 'AM-3200-GV',
            'u_height' => 1.0,
            'is_rack_mounted' => true,
        ]);

        // Device row 2 — non-rack (false, no u_height)
        Device::create([
            'project_id' => $project->id,
            'description' => 'Sony FW-75BZ35L Display',
            'manufacturer' => 'Sony',
            'part_no' => 'FW-75BZ35L',
            'u_height' => null,
            'is_rack_mounted' => false,
        ]);

        // Device row 3 — the cable line item — must be filtered out by
        // filterHardware() before it reaches the palette regardless of
        // whether a Device row exists.
        Device::create([
            'project_id' => $project->id,
            'description' => 'HDMI 2.0 cable 5m',
            'manufacturer' => 'Generic',
            'part_no' => 'HDMI-CAB-5M',
            'u_height' => null,
            'is_rack_mounted' => false,
        ]);

        return $project;
    }

    public function test_top_level_shape_is_palette_array(): void
    {
        $project = $this->buildFixtureProject();
        $result = app(DrawingDataResolverService::class)->rackStackForProject($project);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('palette', $result);
        $this->assertIsArray($result['palette']);
        // Cable item filtered out — only the rack-mounted + non-rack rows remain.
        $this->assertCount(2, $result['palette'],
            'cable items must be filtered out by filterHardware()');
    }

    public function test_per_row_key_list_matches_plan_18_03_contract(): void
    {
        $project = $this->buildFixtureProject();
        $result = app(DrawingDataResolverService::class)->rackStackForProject($project);

        $expectedKeys = [
            'equipment_id',
            'name',
            'manufacturer',
            'part_no',
            'qty',
            'u_height',
            'is_rack_mounted',
            'requires_ventilation_gap_above',
            'requires_ventilation_gap_below',
        ];

        foreach ($result['palette'] as $row) {
            $this->assertSame(
                $expectedKeys,
                array_keys($row),
                'palette row keys must match Plan 18-03 contract exactly',
            );
        }
    }

    public function test_cable_line_items_are_excluded(): void
    {
        $project = $this->buildFixtureProject();
        $result = app(DrawingDataResolverService::class)->rackStackForProject($project);

        $partNos = array_column($result['palette'], 'part_no');
        $this->assertNotContains('HDMI-CAB-5M', $partNos,
            'category=cables must be excluded by filterHardware()');
    }

    public function test_rack_mounted_rows_come_before_non_rack_rows(): void
    {
        $project = $this->buildFixtureProject();
        $result = app(DrawingDataResolverService::class)->rackStackForProject($project);

        $this->assertSame('AM-3200-GV', $result['palette'][0]['part_no'],
            'rack-mounted row must come first (DRAW-09 palette ordering)');
        $this->assertTrue($result['palette'][0]['is_rack_mounted']);
        $this->assertSame('FW-75BZ35L', $result['palette'][1]['part_no'],
            'non-rack row must come second');
        $this->assertFalse($result['palette'][1]['is_rack_mounted']);
    }

    public function test_u_height_is_float_or_null_and_booleans_are_typed(): void
    {
        $project = $this->buildFixtureProject();
        $result = app(DrawingDataResolverService::class)->rackStackForProject($project);

        // Rack-mounted row: u_height is float, is_rack_mounted is bool true
        $this->assertSame(1.0, $result['palette'][0]['u_height']);
        $this->assertIsBool($result['palette'][0]['is_rack_mounted']);
        $this->assertTrue($result['palette'][0]['is_rack_mounted']);

        // Non-rack row: u_height is null, is_rack_mounted is bool false
        $this->assertNull($result['palette'][1]['u_height']);
        $this->assertIsBool($result['palette'][1]['is_rack_mounted']);
        $this->assertFalse($result['palette'][1]['is_rack_mounted']);
    }

    /**
     * Quick task 260816-uzh — table-drives one row per canonical category
     * (App\Services\Imports\EquipmentCategoryClassifier::CATEGORIES) through
     * the private filterHardware() method (invoked via reflection, mirroring
     * how the bug was originally proven) and asserts exactly which survive.
     *
     * Table-driven so the next category added to the vocabulary forces an
     * explicit decision here rather than silently defaulting to "renders as
     * a device node" — EXCLUDED_CATEGORIES is inclusive-by-default.
     *
     * `customer_supplied` documents CURRENT (surviving) behaviour only. This
     * is a deliberately open product question, not a locked contract: unlike
     * a warranty, client-supplied kit may legitimately be part of the system
     * topology (the client provides the display, 21CAV wires it into the
     * rack). The user has not made that call yet — see 260816-uzh
     * SUMMARY.md. If/when they do, this row's expectation should change
     * along with the production filter, not before.
     */
    public function test_filter_hardware_survives_or_excludes_by_canonical_category(): void
    {
        $rows = [
            'hardware' => ['category' => 'hardware', 'name' => 'Samsung 65in Display'],
            'hardware_supply_only' => ['category' => 'hardware_supply_only', 'name' => 'Client Owned Camera Rig'],
            'cables' => ['category' => 'cables', 'name' => 'HDMI 2.0 Lead 5m'],
            'consumables' => ['category' => 'consumables', 'name' => 'Velcro Roll'],
            'services' => ['category' => 'services', 'name' => 'Programming Day Rate'],
            // The bug this quick task fixes.
            'service_contracts' => ['category' => 'service_contracts', 'name' => '3 Year Extended Warranty'],
            // Pending product decision — see docblock above. Documents reality.
            'customer_supplied' => ['category' => 'customer_supplied', 'name' => 'Client Supplied Laptop'],
            'option' => ['category' => 'option', 'name' => 'Optional Extra Speaker'],
            'unknown' => ['category' => 'unknown', 'name' => 'Unclassified Line Item'],
        ];

        // Guard: fail loudly (not silently) if the canonical vocabulary grows
        // without this table being updated to make an explicit call on it.
        $this->assertSame(
            EquipmentCategoryClassifier::CATEGORIES,
            array_keys($rows),
            'canonical category vocabulary changed — update this table to make an explicit survives/excluded call on the new category',
        );

        $expectedSurvives = [
            'hardware' => true,
            'hardware_supply_only' => false,
            'cables' => false,
            'consumables' => false,
            'services' => false,
            'service_contracts' => false,
            'customer_supplied' => true,
            'option' => false,
            'unknown' => true,
        ];

        $service = app(DrawingDataResolverService::class);
        $reflection = new ReflectionMethod(DrawingDataResolverService::class, 'filterHardware');
        $reflection->setAccessible(true);

        /** @var array<int, array<string, mixed>> $result */
        $result = $reflection->invoke($service, array_values($rows));
        $survivingCategories = array_column($result, 'category');

        foreach ($expectedSurvives as $category => $shouldSurvive) {
            $this->assertSame(
                $shouldSurvive,
                in_array($category, $survivingCategories, true),
                $shouldSurvive
                    ? "{$category} row must survive filterHardware() — it is a real device"
                    : "{$category} row must NOT survive filterHardware() — it is not a physical device",
            );
        }
    }
}
