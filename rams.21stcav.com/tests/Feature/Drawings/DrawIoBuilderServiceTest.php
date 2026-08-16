<?php

namespace Tests\Feature\Drawings;

use App\Http\Controllers\Admin\DrawIoSpikeController;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use App\Services\Drawings\DrawingService;
use App\Services\Drawings\DrawIoBuilderService;
use App\Services\Drawings\DrawIoSpikeBuilderService;
use Carbon\Carbon;
use Database\Seeders\DeviceStencilSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * Phase 21 Plan 03 Task 2 — locks DrawIoBuilderService end-to-end behaviour.
 * Phase 23 Plan 05 — assertions evolved for the new `<mxfile>` multi-sheet
 * wrapper while Phase 21 P03 invariants (empty-graph shape, base64 stencil
 * embed, determinism, spike controller signature, shim delegation) all stay
 * green.
 *
 * Asserts:
 *   - Non-empty projects emit `<mxfile>` wrapper (Phase 23 new contract)
 *   - Curated seed-pack stencils + auto-generic Tier 1 placeholders both
 *     land as device vertex cells (Phase 21 D-04 carry-forward)
 *   - Cable / service category lines are filtered out (Phase 21 P03)
 *   - Empty package case still emits the legacy single `<mxGraphModel>`
 *     shape — backwards-compat per Phase 21 P03
 *   - Two builds of the same project produce byte-identical output (D-LOCK-5/6)
 *   - Curated stencil's mxgraph_xml is base64-embedded via shape=stencil(...)
 *   - DrawIoSpikeBuilderService still exists as a thin shim delegating to
 *     DrawIoBuilderService (Phase 21 D-08)
 *   - DrawIoSpikeController constructor still injects DrawIoBuilderService +
 *     DrawingService per D-08 + Warning 2 (type-based check, not arity —
 *     see quick task 260816-t5c)
 *
 * @see app/Services/Drawings/DrawIoBuilderService.php
 * @see app/Services/Drawings/DrawIoSpikeBuilderService.php
 * @see app/Http/Controllers/Admin/DrawIoSpikeController.php
 * @see .planning/phases/21-device-port-catalog-stencil-cache/21-CONTEXT.md (D-08, D-14, Nit 9)
 * @see .planning/phases/23-xten-av-style-renderer/23-05-drawio-builder-orchestrator-rewire-PLAN.md
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

        // Phase 23 Plan 05 — title block now interpolates now()->format('Y-m-d'),
        // so the determinism test needs a frozen clock. Same value used by the
        // multi-sheet harness (DrawIoBuilderServiceMultiSheetTest::setUp).
        Carbon::setTestNow('2026-05-14 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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

        // Phase 23 Plan 05 — non-empty projects are now wrapped in `<mxfile>`
        // for multi-sheet draw.io format support. Per-sheet `<mxGraphModel>`
        // lives inside each `<diagram>` child.
        $this->assertStringStartsWith('<mxfile', $xml,
            'Non-empty project output must be wrapped in `<mxfile>` (Phase 23 multi-sheet format)');
        $this->assertStringContainsString('<mxGraphModel', $xml,
            'Each diagram still contains an mxGraphModel');
        $this->assertStringContainsString('</mxfile>', $xml);
        $this->assertStringContainsString('<root>', $xml);

        // Devices land as device vertex cells (kind=device). The new layout
        // engine also emits zone container vertex cells, page border, and
        // 8 title-block field cells per sheet — so simply count that BOTH
        // hardware device labels are present.
        $this->assertStringContainsString('Neat Bar Pro', $xml,
            'Curated stencil device label must appear in output');
        $this->assertStringContainsString('Mystery Device', $xml,
            'Auto-generic Tier 1 device label must appear in output');

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
     * D-08 + Warning 2 enforcement — the spike controller's constructor MUST
     * keep injecting BOTH DrawIoBuilderService and DrawingService. Dropping
     * `DrawingService $drawings` silently breaks saveXml + exportSvg.
     *
     * Quick task 260816-t5c: this used to assert the constructor had exactly
     * 2 parameters. Security batch `9a6837c` (WR-03/4/5) legitimately added a
     * third dependency (`SvgSanitizerService`, for exportSvg's SVG sanitiser),
     * which broke the arity count even though D-08's actual rule — that
     * DrawingService survives — was never violated. An arity check is a bad
     * proxy for that rule anyway: a 2-parameter constructor could still be
     * broken. Assert the required types are present instead, regardless of
     * how many other dependencies the constructor grows.
     */
    public function test_d08_spike_controller_still_injects_drawing_service(): void
    {
        $rc = new ReflectionClass(DrawIoSpikeController::class);
        $ctor = $rc->getConstructor();

        $this->assertNotNull($ctor, 'DrawIoSpikeController must have an explicit constructor');

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
