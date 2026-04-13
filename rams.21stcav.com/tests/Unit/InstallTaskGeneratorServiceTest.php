<?php

namespace Tests\Unit;

use App\Core\Modules\Projects\ProjectDataService;
use App\Models\InstallProgramme;
use App\Models\InstallTask;
use App\Models\Project;
use App\Services\InstallProgrammeService;
use App\Services\InstallTaskGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Unit tests for InstallTaskGeneratorService and InstallProgrammeService.
 *
 * Verifies:
 * - filterHardware() exclusion logic (by category and keyword)
 * - generate() task count and field mapping
 * - InstallProgrammeService::activate() guard
 * - InstallProgrammeService::archiveExisting() status changes
 * - InstallProgrammeService::createForProject() creates draft and calls generator
 */
class InstallTaskGeneratorServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeProjectDataService(array $resolvedData): ProjectDataService
    {
        $mock = Mockery::mock(ProjectDataService::class);
        $mock->shouldReceive('resolve')->andReturn($resolvedData);
        return $mock;
    }

    private function makeGenerator(ProjectDataService $pds): InstallTaskGeneratorService
    {
        return new InstallTaskGeneratorService($pds);
    }

    private function makeService(InstallTaskGeneratorService $generator): InstallProgrammeService
    {
        return new InstallProgrammeService($generator);
    }

    private function resolvedDataWithRooms(array $rooms): array
    {
        return [
            'project' => ['id' => 1, 'name' => 'Test Project'],
            'rooms'   => $rooms,
        ];
    }

    // ── filterHardware tests ──────────────────────────────────────────────────

    /** @test */
    public function filterHardware_returns_empty_array_for_empty_input(): void
    {
        $pds       = $this->makeProjectDataService([]);
        $generator = $this->makeGenerator($pds);

        $this->assertSame([], $generator->filterHardware([]));
    }

    /** @test */
    public function filterHardware_passes_hardware_category_item_through(): void
    {
        $pds       = $this->makeProjectDataService([]);
        $generator = $this->makeGenerator($pds);

        $item   = ['name' => 'Samsung Display', 'category' => 'hardware'];
        $result = $generator->filterHardware([$item]);

        $this->assertCount(1, $result);
        $this->assertSame('Samsung Display', $result[0]['name']);
    }

    /** @test */
    public function filterHardware_excludes_item_with_cables_category(): void
    {
        $pds       = $this->makeProjectDataService([]);
        $generator = $this->makeGenerator($pds);

        $item   = ['name' => 'HDMI Cable 2m', 'category' => 'cables'];
        $result = $generator->filterHardware([$item]);

        $this->assertSame([], $result);
    }

    /** @test */
    public function filterHardware_excludes_item_matching_excluded_keyword_when_no_category(): void
    {
        $pds       = $this->makeProjectDataService([]);
        $generator = $this->makeGenerator($pds);

        $item   = ['name' => 'project management', 'category' => ''];
        $result = $generator->filterHardware([$item]);

        $this->assertSame([], $result);
    }

    /** @test */
    public function filterHardware_passes_non_excluded_item_with_blank_category(): void
    {
        $pds       = $this->makeProjectDataService([]);
        $generator = $this->makeGenerator($pds);

        $item   = ['name' => 'Mount Bracket', 'category' => ''];
        $result = $generator->filterHardware([$item]);

        $this->assertCount(1, $result);
    }

    // ── generate() tests ──────────────────────────────────────────────────────

    /** @test */
    public function generate_creates_one_task_per_hardware_item_per_room(): void
    {
        $rooms = [
            [
                'room_name' => 'Boardroom',
                'equipment' => [
                    ['name' => 'Display A', 'category' => 'hardware'],
                    ['name' => 'Switcher B', 'category' => 'hardware'],
                    ['name' => 'Cable Pack', 'category' => 'cables'],    // excluded
                ],
            ],
            [
                'room_name' => 'Reception',
                'equipment' => [
                    ['name' => 'Display C', 'category' => 'hardware'],
                    ['name' => 'Speaker D', 'category' => 'hardware'],
                    ['name' => 'project management', 'category' => ''], // excluded
                ],
            ],
        ];

        $project = Project::factory()->create();
        $pds     = $this->makeProjectDataService($this->resolvedDataWithRooms($rooms));
        $generator = $this->makeGenerator($pds);

        $programme = InstallProgramme::create([
            'project_id'   => $project->id,
            'generated_by' => null,
            'status'       => InstallProgramme::STATUS_DRAFT,
            'generated_at' => now(),
        ]);

        $generator->generate($programme);

        // 2 rooms × 2 hardware items each = 4 tasks (cables/PM filtered out)
        $this->assertSame(4, $programme->tasks()->count());
    }

    /** @test */
    public function generate_sets_room_name_title_and_sort_order_correctly(): void
    {
        $rooms = [
            [
                'room_name' => 'Training Room',
                'equipment' => [
                    ['name' => 'Large Display', 'category' => 'hardware'],
                    ['name' => 'Document Camera', 'category' => 'hardware'],
                ],
            ],
        ];

        $project   = Project::factory()->create();
        $pds       = $this->makeProjectDataService($this->resolvedDataWithRooms($rooms));
        $generator = $this->makeGenerator($pds);

        $programme = InstallProgramme::create([
            'project_id'   => $project->id,
            'generated_by' => null,
            'status'       => InstallProgramme::STATUS_DRAFT,
            'generated_at' => now(),
        ]);

        $generator->generate($programme);

        $tasks = $programme->tasks()->orderBy('sort_order')->get();
        $this->assertCount(2, $tasks);

        // First task
        $this->assertSame('Training Room', $tasks[0]->room_name);
        $this->assertSame('Install Large Display', $tasks[0]->title);
        $this->assertSame(0, $tasks[0]->sort_order);    // (0 * 100) + 0

        // Second task
        $this->assertSame('Install Document Camera', $tasks[1]->title);
        $this->assertSame(1, $tasks[1]->sort_order);    // (0 * 100) + 1
    }

    /** @test */
    public function generate_does_not_dispatch_any_job(): void
    {
        // Guard: generator must be synchronous — no queue interaction.
        // We verify by checking the jobs table remains empty.
        $rooms = [
            ['room_name' => 'Room A', 'equipment' => [['name' => 'Screen', 'category' => 'hardware']]],
        ];

        $project   = Project::factory()->create();
        $pds       = $this->makeProjectDataService($this->resolvedDataWithRooms($rooms));
        $generator = $this->makeGenerator($pds);

        $programme = InstallProgramme::create([
            'project_id'   => $project->id,
            'generated_by' => null,
            'status'       => InstallProgramme::STATUS_DRAFT,
            'generated_at' => now(),
        ]);

        $generator->generate($programme);

        $this->assertDatabaseCount('jobs', 0);
    }

    // ── InstallProgrammeService::activate() tests ─────────────────────────────

    /** @test */
    public function activate_sets_status_active_and_activated_at(): void
    {
        $project   = Project::factory()->create();
        $pds       = $this->makeProjectDataService(['rooms' => []]);
        $generator = $this->makeGenerator($pds);
        $service   = $this->makeService($generator);

        $programme = InstallProgramme::create([
            'project_id'   => $project->id,
            'generated_by' => null,
            'status'       => InstallProgramme::STATUS_DRAFT,
            'generated_at' => now(),
        ]);

        $service->activate($programme);

        $programme->refresh();
        $this->assertSame(InstallProgramme::STATUS_ACTIVE, $programme->status);
        $this->assertNotNull($programme->activated_at);
    }

    /** @test */
    public function activate_throws_logic_exception_when_programme_is_not_draft(): void
    {
        $project   = Project::factory()->create();
        $pds       = $this->makeProjectDataService(['rooms' => []]);
        $generator = $this->makeGenerator($pds);
        $service   = $this->makeService($generator);

        $programme = InstallProgramme::create([
            'project_id'   => $project->id,
            'generated_by' => null,
            'status'       => InstallProgramme::STATUS_ACTIVE,
            'generated_at' => now(),
        ]);

        $this->expectException(\LogicException::class);
        $service->activate($programme);
    }

    // ── InstallProgrammeService::archiveExisting() tests ─────────────────────

    /** @test */
    public function archiveExisting_archives_all_draft_and_active_programmes_for_project(): void
    {
        $project   = Project::factory()->create();
        $pds       = $this->makeProjectDataService(['rooms' => []]);
        $generator = $this->makeGenerator($pds);
        $service   = $this->makeService($generator);

        $draft  = InstallProgramme::create(['project_id' => $project->id, 'status' => InstallProgramme::STATUS_DRAFT,    'generated_at' => now()]);
        $active = InstallProgramme::create(['project_id' => $project->id, 'status' => InstallProgramme::STATUS_ACTIVE,   'generated_at' => now()]);
        $comp   = InstallProgramme::create(['project_id' => $project->id, 'status' => InstallProgramme::STATUS_COMPLETE, 'generated_at' => now()]);

        $service->archiveExisting($project);

        $this->assertSame(InstallProgramme::STATUS_ARCHIVED, $draft->fresh()->status);
        $this->assertSame(InstallProgramme::STATUS_ARCHIVED, $active->fresh()->status);
        $this->assertSame(InstallProgramme::STATUS_COMPLETE, $comp->fresh()->status); // untouched
    }

    // ── InstallProgrammeService::createForProject() tests ────────────────────

    /** @test */
    public function createForProject_creates_draft_programme_with_tasks(): void
    {
        $rooms = [
            ['room_name' => 'Server Room', 'equipment' => [['name' => 'Switch', 'category' => 'hardware']]],
        ];

        $project = Project::factory()->create();
        $user    = \App\Models\User::factory()->create();
        $pds     = $this->makeProjectDataService($this->resolvedDataWithRooms($rooms));
        $generator = $this->makeGenerator($pds);
        $service   = $this->makeService($generator);

        $programme = $service->createForProject($project, $user);

        $this->assertSame(InstallProgramme::STATUS_DRAFT, $programme->status);
        $this->assertSame(1, $programme->tasks()->count());
        $this->assertSame($project->id, $programme->project_id);
        $this->assertSame($user->id, $programme->generated_by);
    }
}
