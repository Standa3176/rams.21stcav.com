<?php

namespace Tests\Unit\Services\Drawings;

use App\Jobs\BuildSchematicJob;
use App\Models\Project;
use App\Models\ProjectDrawing;
use App\Models\User;
use App\Services\Drawings\DrawingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Phase 18 Plan 01 — locks DrawingService::generateInitial(kind=rack) at the
 * service layer, independent of any controller path. Plan 18-03's editRack
 * controller depends on these guarantees:
 *
 *   1. NO async job dispatched (rack rendering is synchronous in Plan 18-03).
 *   2. Status stays DRAFT (engineer flips to READY when they save the rack).
 *   3. source_data.rack_meta seeded with 42U + 230V baseline.
 *   4. source_data.rack_items seeded as an empty array (Plan 18-03 reads it).
 *
 * Mirrors tests/Feature/Drawings/SchematicGeneratorServiceTest.php for fixture
 * shape (Project::create + direct ProjectDrawing::create — no factories yet
 * for ProjectDrawing/Device).
 *
 * @see app/Services/Drawings/DrawingService.php::generateInitialRack
 * @see CONTEXT.md "NO BuildRackElevationJob — there's no AI/D2/heavy work to defer."
 */
class DrawingServiceRackTest extends TestCase
{
    use RefreshDatabase;

    private function makeProject(): Project
    {
        $user = User::factory()->create();

        return Project::create([
            'user_id' => $user->id,
            'name' => 'Rack Service Test',
            'ref' => 'RACK-SVC-001',
            'client_name' => 'Test Client Ltd',
            'site_address' => '1 Rack Street, London',
            'status' => 'quote_imported',
        ]);
    }

    private function makeRackDrawing(Project $project): ProjectDrawing
    {
        return ProjectDrawing::create([
            'project_id' => $project->id,
            'site_survey_room_id' => null,
            'kind' => ProjectDrawing::KIND_RACK,
            'rack_label' => 'Rack 1',
            'version' => 1,
            'status' => ProjectDrawing::STATUS_DRAFT,
            'generated_by' => $project->user_id,
            'source_data' => [],
        ]);
    }

    public function test_generate_initial_for_rack_does_not_dispatch_any_job(): void
    {
        Bus::fake();

        $project = $this->makeProject();
        $drawing = $this->makeRackDrawing($project);

        app(DrawingService::class)->generateInitial($drawing, (int) $project->user_id);

        // CONTEXT.md D-13 — "NO BuildRackElevationJob — rack rendering is
        // synchronous in Plan 18-03's editor; nothing to defer."
        Bus::assertNothingDispatched();
        Bus::assertNotDispatched(BuildSchematicJob::class);
    }

    public function test_generate_initial_for_rack_keeps_status_draft(): void
    {
        $project = $this->makeProject();
        $drawing = $this->makeRackDrawing($project);

        $result = app(DrawingService::class)->generateInitial($drawing, (int) $project->user_id);

        $this->assertSame(ProjectDrawing::STATUS_DRAFT, $result->fresh()->status,
            'Rack flow stays in DRAFT — only flips to READY when Plan 18-03 editor saves rack canvas state.');
    }

    public function test_generate_initial_for_rack_seeds_42u_rack_meta(): void
    {
        $project = $this->makeProject();
        $drawing = $this->makeRackDrawing($project);

        $result = app(DrawingService::class)->generateInitial($drawing, (int) $project->user_id)->fresh();

        $rackMeta = $result->source_data['rack_meta'] ?? null;
        $this->assertIsArray($rackMeta, 'rack_meta must be seeded as an array');
        $this->assertSame(42, $rackMeta['rack_height_u'] ?? null,
            '42U baseline locked per CONTEXT.md (engineer overrides per rack on edit)');
        $this->assertSame(230, $rackMeta['nominal_voltage_v'] ?? null,
            '230V UK mains baseline locked per CONTEXT.md');
        $this->assertArrayHasKey('floor', $rackMeta, 'rack_meta.floor key must exist (even if null)');
        $this->assertNull($rackMeta['floor'],
            'floor unset by default (engineer fills in the editor)');
    }

    public function test_generate_initial_for_rack_seeds_empty_rack_items_array(): void
    {
        $project = $this->makeProject();
        $drawing = $this->makeRackDrawing($project);

        $result = app(DrawingService::class)->generateInitial($drawing, (int) $project->user_id)->fresh();

        $this->assertIsArray($result->source_data['rack_items'] ?? null,
            'rack_items must exist as an array so Plan 18-03 can read it without conditionals');
        $this->assertSame([], $result->source_data['rack_items'],
            'engineer always builds the rack manually — no auto-place population');
    }
}
