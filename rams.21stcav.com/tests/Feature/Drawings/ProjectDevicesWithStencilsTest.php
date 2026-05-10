<?php

namespace Tests\Feature\Drawings;

use App\Models\DeviceStencil;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 21 Plan 01 Task 3 — locks Project::devicesWithStencils() per
 * CONTEXT.md D-07. Asserts:
 *   - Returns enriched array with one entry per hardware line
 *   - Cable / service category lines are skipped (mirrors hardwarePartNumbers)
 *   - Lines with empty part_number are kept with stencil = null
 *   - First call AUTO-CREATES Tier 1 placeholders for uncatalogued part_numbers
 *   - Second call hits the cache (DB row count unchanged)
 *   - Engineer-curated rows are returned unchanged on subsequent reads
 *     (Phase 24 forward-compat — promotion survives via firstOrCreate)
 *   - Empty / missing package gracefully returns []
 *
 * @see app/Models/Project.php — devicesWithStencils() accessor
 * @see app/Services/Drawings/DeviceStencilCacheService.php
 * @see .planning/phases/21-device-port-catalog-stencil-cache/21-CONTEXT.md (D-07)
 */
class ProjectDevicesWithStencilsTest extends TestCase
{
    use RefreshDatabase;

    private function makeProjectWithEquipment(array $equipment): Project
    {
        $user = User::factory()->create();

        $project = Project::create([
            'user_id'      => $user->id,
            'name'         => 'Devices With Stencils Test',
            'ref'          => 'DWS-'.fake()->numerify('###'),
            'client_name'  => 'Test Client Ltd',
            'site_address' => '1 Devices Street, London',
            'status'       => 'quote_imported',
        ]);

        ProjectPackage::create([
            'project_id'        => $project->id,
            'user_id'           => $user->id,
            'quote_filename'    => 'test-quote.pdf',
            'quote_path'        => 'quotes/test-quote.pdf',
            'extracted_data'    => ['equipment' => $equipment],
            'equipment_list'    => $equipment,
            'cable_list'        => [],
            'works_description' => 'Test works',
            'revision'          => 1,
            'status'            => ProjectPackage::STATUS_EXTRACTED,
        ]);

        return $project->fresh();
    }

    public function test_returns_enriched_lines_for_hardware_equipment(): void
    {
        $project = $this->makeProjectWithEquipment([
            ['quantity' => 1, 'part_number' => 'NEAT-BAR-PRO', 'name' => 'Neat Bar Pro', 'manufacturer' => 'NEAT', 'model' => 'Bar Pro', 'area' => 'Boardroom', 'category' => 'hardware'],
            ['quantity' => 2, 'part_number' => 'GS312TP',      'name' => 'Netgear PoE switch', 'manufacturer' => 'Netgear', 'model' => 'GS312TP', 'area' => 'Comms', 'category' => 'hardware'],
        ]);

        $rows = $project->devicesWithStencils();

        $this->assertCount(2, $rows);
        $this->assertSame('NEAT-BAR-PRO', $rows[0]['part_number']);
        $this->assertInstanceOf(DeviceStencil::class, $rows[0]['stencil']);
        $this->assertSame('neat-bar-pro', $rows[0]['stencil']->part_number);
        $this->assertSame(DeviceStencil::SOURCE_AUTO_GENERATED, $rows[0]['stencil']->source);

        $this->assertSame('GS312TP', $rows[1]['part_number']);
        $this->assertInstanceOf(DeviceStencil::class, $rows[1]['stencil']);
        $this->assertSame('gs312tp', $rows[1]['stencil']->part_number);
    }

    public function test_filters_out_cable_and_service_categories(): void
    {
        $project = $this->makeProjectWithEquipment([
            ['quantity' => 1,  'part_number' => 'NEAT-BAR-PRO', 'name' => 'Neat Bar Pro', 'category' => 'hardware'],
            ['quantity' => 30, 'part_number' => 'CAB-HDMI-3M',  'name' => 'HDMI cable',   'category' => 'cable'],
            ['quantity' => 1,  'part_number' => 'INSTALL-DAY',  'name' => 'Engineer day', 'category' => 'service'],
        ]);

        $rows = $project->devicesWithStencils();

        $this->assertCount(1, $rows);
        $this->assertSame('NEAT-BAR-PRO', $rows[0]['part_number']);
    }

    public function test_keeps_lines_with_empty_part_number_with_null_stencil(): void
    {
        $project = $this->makeProjectWithEquipment([
            ['quantity' => 1, 'part_number' => 'NEAT-BAR-PRO', 'name' => 'Neat Bar Pro', 'category' => 'hardware'],
            ['quantity' => 1, 'part_number' => '',             'name' => 'Mystery item', 'category' => 'hardware'],
        ]);

        $rows = $project->devicesWithStencils();

        $this->assertCount(2, $rows);
        $this->assertNotNull($rows[0]['stencil']);
        $this->assertNull($rows[1]['stencil']);
        $this->assertSame(1, DeviceStencil::count(),
            'Only the line with a part_number should have produced a DeviceStencil row');
    }

    public function test_second_call_hits_cache_no_duplicate_inserts(): void
    {
        $project = $this->makeProjectWithEquipment([
            ['quantity' => 1, 'part_number' => 'NEAT-BAR-PRO', 'name' => 'Neat Bar Pro', 'manufacturer' => 'NEAT', 'category' => 'hardware'],
            ['quantity' => 1, 'part_number' => 'GS312TP',      'name' => 'Netgear',      'category' => 'hardware'],
        ]);

        $project->devicesWithStencils();
        $this->assertSame(2, DeviceStencil::count());

        // Second call MUST NOT insert duplicates.
        $project->devicesWithStencils();
        $this->assertSame(2, DeviceStencil::count(),
            'Second devicesWithStencils() call must hit the firstOrCreate cache (D-03)');
    }

    public function test_engineer_curated_row_survives_subsequent_reads(): void
    {
        $project = $this->makeProjectWithEquipment([
            ['quantity' => 1, 'part_number' => 'NEAT-BAR-PRO', 'name' => 'Neat Bar Pro', 'category' => 'hardware'],
        ]);

        // First call auto-creates as Tier 1.
        $project->devicesWithStencils();
        $auto = DeviceStencil::where('part_number', 'neat-bar-pro')->firstOrFail();
        $this->assertSame(DeviceStencil::SOURCE_AUTO_GENERATED, $auto->source);

        // Simulate Phase 24 promoting the stencil to engineer-curated in-place.
        $auto->update([
            'source'       => DeviceStencil::SOURCE_ENGINEER_CURATED,
            'display_name' => 'Neat Bar Pro (engineer-built)',
            'mxgraph_xml'  => '<shape><foreground><text str="curated"/></foreground></shape>',
        ]);

        // Subsequent read returns the curated row, not a fresh auto-generic.
        $rows = $project->devicesWithStencils();
        $this->assertCount(1, $rows);
        $this->assertSame(DeviceStencil::SOURCE_ENGINEER_CURATED, $rows[0]['stencil']->source);
        $this->assertSame('Neat Bar Pro (engineer-built)', $rows[0]['stencil']->display_name);
        $this->assertSame(1, DeviceStencil::count(),
            'Engineer-curated row must NOT be duplicated on subsequent read');
    }

    public function test_empty_package_returns_empty_array(): void
    {
        $user = User::factory()->create();

        $project = Project::create([
            'user_id'      => $user->id,
            'name'         => 'Project With No Package',
            'ref'          => 'NO-PKG-001',
            'client_name'  => 'Test',
            'site_address' => '1 Empty Street',
            'status'       => 'quote_imported',
        ]);

        $rows = $project->devicesWithStencils();

        $this->assertSame([], $rows);
        $this->assertSame(0, DeviceStencil::count());
    }

    public function test_falls_back_to_equipment_list_when_extracted_data_lacks_equipment(): void
    {
        $user = User::factory()->create();

        $project = Project::create([
            'user_id'      => $user->id,
            'name'         => 'Fallback Test',
            'ref'          => 'FALLBACK-001',
            'client_name'  => 'Test',
            'site_address' => '1 Fallback Street',
            'status'       => 'quote_imported',
        ]);

        // Older project shape — equipment_list populated, extracted_data null/no equipment key.
        ProjectPackage::create([
            'project_id'        => $project->id,
            'user_id'           => $user->id,
            'quote_filename'    => 'legacy.pdf',
            'quote_path'        => 'quotes/legacy.pdf',
            'extracted_data'    => ['project_name' => 'Legacy'],
            'equipment_list'    => [
                ['quantity' => 1, 'part_number' => 'LEGACY-001', 'name' => 'Legacy Device', 'category' => 'hardware'],
            ],
            'cable_list'        => [],
            'works_description' => 'Legacy works',
            'revision'          => 1,
            'status'            => ProjectPackage::STATUS_EXTRACTED,
        ]);

        $rows = $project->fresh()->devicesWithStencils();

        $this->assertCount(1, $rows);
        $this->assertSame('LEGACY-001', $rows[0]['part_number']);
        $this->assertInstanceOf(DeviceStencil::class, $rows[0]['stencil']);
    }
}
