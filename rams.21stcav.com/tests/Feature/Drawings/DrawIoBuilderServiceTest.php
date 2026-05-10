<?php

namespace Tests\Feature\Drawings;

use App\Http\Controllers\Admin\DrawIoSpikeController;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use App\Services\Drawings\DrawingService;
use App\Services\Drawings\DrawIoBuilderService;
use App\Services\Drawings\DrawIoSpikeBuilderService;
use Database\Seeders\DeviceStencilSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * Phase 21 Plan 03 Task 2 — locks DrawIoBuilderService end-to-end behaviour
 * per CONTEXT.md D-08 (admin route + saveXml/exportSvg pipelines preserved)
 * and Nit 9 (shallow role inference scoped to manufacturer-logo placement +
 * 4-column grid; Phase 23 replaces with real layout engine).
 *
 * Asserts:
 *   - build() emits a valid mxGraphModel XML string starting with
 *     `<mxGraphModel`
 *   - Curated seed-pack stencils land as vertex cells
 *   - Auto-generic Tier 1 placeholders for unseen part_numbers also land
 *   - Cable / service category lines are filtered out (no spurious cells)
 *   - Empty package case still emits a valid empty mxGraphModel
 *   - Two builds of the same project produce byte-identical output
 *     (deterministic — Phase 22's port-FK migration depends on this)
 *   - Curated stencil's mxgraph_xml is base64-embedded via shape=stencil(...)
 *   - DrawIoSpikeBuilderService still exists as a thin shim delegating to
 *     DrawIoBuilderService
 *   - DrawIoSpikeController constructor still has 2 parameters per D-08 +
 *     Warning 2 (DrawingService dependency MUST NOT be dropped)
 *
 * @see app/Services/Drawings/DrawIoBuilderService.php
 * @see app/Services/Drawings/DrawIoSpikeBuilderService.php
 * @see app/Http/Controllers/Admin/DrawIoSpikeController.php
 * @see .planning/phases/21-device-port-catalog-stencil-cache/21-CONTEXT.md (D-08, D-14, Nit 9)
 */
class DrawIoBuilderServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Plan 21-02's seeder lays down the curated stencil pack, including
        // neat-bar-pro which one of the test projects below references.
        $this->artisan('db:seed', ['--class' => DeviceStencilSeeder::class])
            ->assertExitCode(0);
    }

    private function makeProjectWithEquipment(array $equipment, array $cableList = []): Project
    {
        $user = User::factory()->create();

        $project = Project::create([
            'user_id' => $user->id,
            'name' => 'DrawIo Builder Test',
            'ref' => 'DIB-'.fake()->numerify('###'),
            'client_name' => 'Test Client Ltd',
            'site_address' => '1 Builder Street, London',
            'status' => 'quote_imported',
        ]);

        ProjectPackage::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'quote_filename' => 'test-quote.pdf',
            'quote_path' => 'quotes/test-quote.pdf',
            'extracted_data' => ['equipment' => $equipment],
            'equipment_list' => $equipment,
            'cable_list' => $cableList,
            'works_description' => 'Test works',
            'revision' => 1,
            'status' => ProjectPackage::STATUS_EXTRACTED,
        ]);

        return $project->fresh();
    }

    public function test_builds_valid_mxgraph_xml_with_two_vertex_cells(): void
    {
        $project = $this->makeProjectWithEquipment([
            // Curated seed-pack hit (matches Plan 21-02's neat-bar-pro manifest)
            ['quantity' => 1, 'part_number' => 'NEAT-BAR-PRO', 'name' => 'Neat Bar Pro', 'manufacturer' => 'Neat', 'category' => 'hardware'],
            // Auto-generic Tier 1 (a brand-new part_number, builder must still land it)
            ['quantity' => 1, 'part_number' => 'NOT-IN-SEED-XYZ-001', 'name' => 'Mystery Device', 'manufacturer' => 'Acme Corp', 'category' => 'hardware'],
            // Empty part_number — must be tolerated (no cell, no error)
            ['quantity' => 1, 'part_number' => '', 'name' => 'Anonymous', 'category' => 'hardware'],
            // Cable category — must be filtered out
            ['quantity' => 30, 'part_number' => 'CAB-HDMI-3M', 'name' => 'HDMI cable', 'category' => 'cable'],
        ]);

        $builder = app(DrawIoBuilderService::class);
        $xml = $builder->build($project);

        $this->assertStringStartsWith('<mxGraphModel', $xml,
            'Output must be a well-formed mxGraphModel document');
        $this->assertStringContainsString('</mxGraphModel>', $xml);
        $this->assertStringContainsString('<root>', $xml);

        // Exactly 2 vertex cells: NEAT-BAR-PRO + NOT-IN-SEED-XYZ-001.
        $vertexCount = substr_count($xml, 'vertex="1"');
        $this->assertSame(2, $vertexCount,
            "Builder must emit exactly 2 vertex cells (curated + auto-generic); got {$vertexCount}");

        // Cable line must NOT appear as a vertex.
        $this->assertStringNotContainsString('CAB-HDMI-3M', $xml,
            'Cable-category lines must be filtered out before vertex emission');
    }

    public function test_curated_stencil_mxgraph_xml_is_base64_embedded(): void
    {
        $project = $this->makeProjectWithEquipment([
            ['quantity' => 1, 'part_number' => 'NEAT-BAR-PRO', 'name' => 'Neat Bar Pro', 'category' => 'hardware'],
        ]);

        $builder = app(DrawIoBuilderService::class);
        $xml = $builder->build($project);

        $this->assertMatchesRegularExpression(
            '/shape=stencil\([A-Za-z0-9+\/=]+\)/',
            $xml,
            'Curated stencil mxgraph_xml must be base64-embedded via shape=stencil(...)'
        );
    }

    public function test_empty_package_emits_valid_empty_graph(): void
    {
        $user = User::factory()->create();

        $project = Project::create([
            'user_id' => $user->id,
            'name' => 'Empty Package Project',
            'ref' => 'EMPTY-001',
            'client_name' => 'Test',
            'site_address' => '1 Empty Street',
            'status' => 'quote_imported',
        ]);

        $builder = app(DrawIoBuilderService::class);
        $xml = $builder->build($project);

        $this->assertStringStartsWith('<mxGraphModel', $xml);
        $this->assertStringContainsString('</mxGraphModel>', $xml);
        $this->assertStringNotContainsString('vertex="1"', $xml,
            'Empty package must emit zero vertex cells');
    }

    public function test_build_is_deterministic(): void
    {
        $project = $this->makeProjectWithEquipment([
            ['quantity' => 1, 'part_number' => 'NEAT-BAR-PRO', 'name' => 'Neat Bar Pro', 'category' => 'hardware'],
            ['quantity' => 1, 'part_number' => 'GS312TP', 'name' => 'Netgear PoE', 'category' => 'hardware'],
            ['quantity' => 1, 'part_number' => 'QM65C-T', 'name' => 'Samsung Display', 'category' => 'hardware'],
        ]);

        $builder = app(DrawIoBuilderService::class);
        $first = $builder->build($project);
        $second = $builder->build($project->fresh());

        $this->assertSame($first, $second,
            'Builder output must be byte-identical across calls (deterministic)');
    }

    /**
     * D-08 + Warning 2 enforcement — the spike controller's two-parameter
     * constructor MUST be preserved. Dropping `DrawingService $drawings`
     * silently breaks saveXml + exportSvg.
     */
    public function test_d08_spike_controller_constructor_has_two_parameters(): void
    {
        $rc = new ReflectionClass(DrawIoSpikeController::class);
        $ctor = $rc->getConstructor();

        $this->assertNotNull($ctor, 'DrawIoSpikeController must have an explicit constructor');
        $this->assertSame(2, $ctor->getNumberOfParameters(),
            'D-08 + Warning 2: DrawIoSpikeController constructor MUST keep BOTH parameters (builder + DrawingService)');

        $params = $ctor->getParameters();
        $types = array_map(
            fn ($p) => $p->getType()?->getName(),
            $params
        );

        $this->assertContains(DrawIoBuilderService::class, $types,
            'Constructor must inject DrawIoBuilderService (the new canonical builder)');
        $this->assertContains(DrawingService::class, $types,
            'Constructor must STILL inject DrawingService (used by saveXml / exportSvg)');
    }

    /**
     * Backwards compatibility — DrawIoSpikeBuilderService MUST still exist
     * as a class so any code path that still references it (or depends on
     * the type-hint via container) keeps working.
     */
    public function test_spike_builder_shim_still_exists_and_delegates(): void
    {
        $this->assertTrue(class_exists(DrawIoSpikeBuilderService::class),
            'DrawIoSpikeBuilderService must remain in the codebase (backwards compat shim per D-08)');

        $project = $this->makeProjectWithEquipment([
            ['quantity' => 1, 'part_number' => 'NEAT-BAR-PRO', 'name' => 'Neat Bar Pro', 'category' => 'hardware'],
        ]);

        $shimOutput = app(DrawIoSpikeBuilderService::class)->build($project);
        $newOutput = app(DrawIoBuilderService::class)->build($project);

        $this->assertSame($newOutput, $shimOutput,
            'Shim DrawIoSpikeBuilderService::build() MUST delegate to DrawIoBuilderService and return identical output');
    }
}
