<?php

namespace Tests\Feature\Drawings;

use App\Jobs\BuildSchematicJob;
use App\Models\Project;
use App\Models\ProjectDrawing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Phase 18 Plan 01 Task 3 — picker controller behaviour:
 *
 *   1. POST kind=rack creates a ProjectDrawing row with status=draft, default
 *      Rack 1 label, source_data.rack_meta.rack_height_u=42, source_data.rack_items=[].
 *      Redirects to projects.drawings.show.
 *   2. POST kind=floor_plan returns redirect with session 'kind' validation
 *      error (Phase 19 deferred to v2.0).
 *   3. POST kind=schematic + auto_generate=yes dispatches BuildSchematicJob
 *      (Phase 17 path preserved through the picker).
 *   4. Subsequent rack creations auto-increment the label (Rack 1, Rack 2).
 *
 * @see app/Http/Controllers/ProjectDrawingController.php::picker
 * @see app/Http/Controllers/ProjectDrawingController.php::createRack
 */
class DrawingPickerTest extends TestCase
{
    use RefreshDatabase;

    private function makeProject(User $user): Project
    {
        return Project::create([
            'user_id' => $user->id,
            'name' => 'Picker Test Project',
            'ref' => 'PICKER-TEST-001',
            'client_name' => 'Test Client Ltd',
            'site_address' => '1 Picker Street, London',
            'status' => 'quote_imported',
        ]);
    }

    public function test_picker_creates_rack_drawing_with_default_label_and_42u(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $response = $this->actingAs($user)
            ->post(route('projects.drawings.picker', $project), [
                'kind' => 'rack',
            ]);

        $rack = ProjectDrawing::where('project_id', $project->id)
            ->where('kind', 'rack')
            ->first();

        $this->assertNotNull($rack);
        $this->assertSame('rack', $rack->kind);
        $this->assertSame('draft', $rack->status,
            'sync flow — no job dispatched, status stays DRAFT (Plan 18-03 editor flips to READY on save)');
        $this->assertSame('Rack 1', $rack->rack_label);
        $this->assertSame(42, $rack->source_data['rack_meta']['rack_height_u'] ?? null);
        $this->assertSame(230, $rack->source_data['rack_meta']['nominal_voltage_v'] ?? null);
        $this->assertIsArray($rack->source_data['rack_items'] ?? null);
        $this->assertSame([], $rack->source_data['rack_items']);
        $response->assertRedirect(route('projects.drawings.show', [$project, $rack]));
    }

    public function test_picker_rejects_floor_plan_kind(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $response = $this->actingAs($user)
            ->from(route('projects.drawings.index', $project))
            ->post(route('projects.drawings.picker', $project), [
                'kind' => 'floor_plan',
            ]);

        $response->assertSessionHasErrors('kind');
        $this->assertSame(0, ProjectDrawing::where('kind', 'floor_plan')->count(),
            'no floor_plan rows must be created — Phase 19 is deferred to v2.0');
    }

    public function test_picker_rejects_unknown_kind(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $response = $this->actingAs($user)
            ->from(route('projects.drawings.index', $project))
            ->post(route('projects.drawings.picker', $project), [
                'kind' => 'something-else',
            ]);

        $response->assertSessionHasErrors('kind');
    }

    public function test_picker_dispatches_to_create_schematic_for_kind_schematic(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $this->actingAs($user)
            ->post(route('projects.drawings.picker', $project), [
                'kind' => 'schematic',
                'auto_generate' => 'yes',
            ]);

        $schem = ProjectDrawing::where('project_id', $project->id)
            ->where('kind', 'schematic')
            ->first();

        $this->assertNotNull($schem, 'schematic row must be created via the picker');
        $this->assertSame('generating', $schem->status,
            'Phase 17 schematic flow preserved — flips to GENERATING and dispatches the job');
        Bus::assertDispatched(BuildSchematicJob::class);
    }

    public function test_creating_a_second_rack_increments_label_to_rack_2(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $this->actingAs($user)
            ->post(route('projects.drawings.picker', $project), ['kind' => 'rack']);
        $this->actingAs($user)
            ->post(route('projects.drawings.picker', $project), ['kind' => 'rack']);

        $racks = ProjectDrawing::where('project_id', $project->id)
            ->where('kind', 'rack')
            ->orderBy('id')
            ->pluck('rack_label');
        $this->assertSame(['Rack 1', 'Rack 2'], $racks->toArray());
    }
}
