<?php

namespace Tests\Feature\InstallProgramme;

use App\Core\Modules\Projects\ProjectDataService;
use App\Models\InstallProgramme;
use App\Models\InstallRecord;
use App\Models\Project;
use App\Models\User;
use App\Models\Visit;
use App\Services\InstallProgrammeService;
use App\Services\InstallTaskGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * VIS-09 / D-06 — the durability proof.
 *
 * The single genuinely new assertion the split needs: regenerating an install
 * programme mints a NEW `install_programmes` row under the SAME unchanged
 * `install_records` row, so a visit filed against the record is never
 * orphaned. Everything else in here is behaviour PRESERVATION — the archive
 * ordering, the draft status, the task generation — restated as executable
 * facts so a later refactor cannot quietly break them.
 *
 * The mocked ProjectDataService mirrors
 * `tests/Unit/InstallTaskGeneratorServiceTest.php`'s helper shape exactly.
 *
 * @see app/Services/InstallProgrammeService.php
 * @see .planning/phases/45-visit-model-read-only-cockpit/45-CONTEXT.md (D-06)
 */
class DurableInstallRecordTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ── Helpers (shape copied from InstallTaskGeneratorServiceTest) ──────────

    private function makeService(): InstallProgrammeService
    {
        $pds = Mockery::mock(ProjectDataService::class);
        $pds->shouldReceive('resolve')->andReturn([
            'project' => ['id' => 1, 'name' => 'Test Project'],
            'rooms'   => [
                ['room_name' => 'Server Room', 'equipment' => [['name' => 'Switch', 'category' => 'hardware']]],
            ],
        ]);

        return new InstallProgrammeService(new InstallTaskGeneratorService($pds));
    }

    // ── The D-06 proof ───────────────────────────────────────────────────────

    public function test_regenerating_creates_a_new_programme_under_the_same_unchanged_record(): void
    {
        $project = Project::factory()->create();
        $user    = User::factory()->create();
        $service = $this->makeService();

        $first  = $service->createForProject($project, $user);
        $record = InstallRecord::where('project_id', $project->id)->firstOrFail();

        $recordIdBefore        = $record->id;
        $recordCreatedAtBefore = $record->created_at->toISOString();

        $second = $service->createForProject($project->fresh(), $user);

        // A brand-new programme row...
        $this->assertNotSame($first->id, $second->id);

        // ...under the SAME durable record.
        $this->assertSame($record->id, $first->install_record_id);
        $this->assertSame($record->id, $second->install_record_id);

        // The record itself is untouched — not replaced, not re-created.
        $this->assertSame(1, InstallRecord::where('project_id', $project->id)->count());
        $recordAfter = InstallRecord::where('project_id', $project->id)->firstOrFail();
        $this->assertSame($recordIdBefore, $recordAfter->id);
        $this->assertSame($recordCreatedAtBefore, $recordAfter->created_at->toISOString());
    }

    public function test_a_visit_filed_against_the_record_still_resolves_after_a_regenerate(): void
    {
        $project = Project::factory()->create();
        $user    = User::factory()->create();
        $service = $this->makeService();

        $service->createForProject($project, $user);
        $record = InstallRecord::where('project_id', $project->id)->firstOrFail();

        $visit = Visit::factory()->create([
            'project_id'        => $project->id,
            'install_record_id' => $record->id,
        ]);

        $service->createForProject($project->fresh(), $user);

        $this->assertSame($record->id, $visit->fresh()->install_record_id);
        $this->assertSame([$visit->id], $record->fresh()->visits()->pluck('id')->all());
    }

    // ── Behaviour preservation ───────────────────────────────────────────────

    public function test_archive_existing_still_runs_first_so_the_previous_programme_is_archived(): void
    {
        $project = Project::factory()->create();
        $user    = User::factory()->create();
        $service = $this->makeService();

        $first = $service->createForProject($project, $user);
        $service->activate($first);
        $this->assertSame(InstallProgramme::STATUS_ACTIVE, $first->fresh()->status);

        $second = $service->createForProject($project->fresh(), $user);

        $this->assertSame(InstallProgramme::STATUS_ARCHIVED, $first->fresh()->status);
        $this->assertSame(InstallProgramme::STATUS_DRAFT, $second->status);
    }

    public function test_the_new_programme_is_still_a_draft_with_generated_tasks(): void
    {
        $project = Project::factory()->create();
        $user    = User::factory()->create();

        $programme = $this->makeService()->createForProject($project, $user);

        $this->assertSame(InstallProgramme::STATUS_DRAFT, $programme->status);
        $this->assertSame(1, $programme->tasks()->count());
        $this->assertSame($project->id, $programme->project_id);
        $this->assertSame($user->id, $programme->generated_by);
        $this->assertNotNull($programme->generated_at);
    }

    public function test_a_project_with_no_prior_programme_gets_a_record_on_first_generate(): void
    {
        $project = Project::factory()->create();
        $user    = User::factory()->create();

        $this->assertSame(0, InstallRecord::where('project_id', $project->id)->count());

        $programme = $this->makeService()->createForProject($project, $user);

        $this->assertSame(1, InstallRecord::where('project_id', $project->id)->count());
        $this->assertNotNull($programme->install_record_id);
    }

    public function test_a_project_that_already_has_a_record_reuses_it(): void
    {
        $project  = Project::factory()->create();
        $user     = User::factory()->create();
        $existing = InstallRecord::create(['project_id' => $project->id]);

        $programme = $this->makeService()->createForProject($project, $user);

        $this->assertSame($existing->id, $programme->install_record_id);
        $this->assertSame(1, InstallRecord::where('project_id', $project->id)->count());
    }

    // ── The concurrency fallback (the A5 fix) ────────────────────────────────

    public function test_a_duplicate_key_race_on_the_record_insert_never_leaves_the_project_programme_less(): void
    {
        $project = Project::factory()->create();
        $user    = User::factory()->create();
        $service = $this->makeService();

        // Simulate the losing side of a concurrent double-generate: the record
        // already exists (committed by the winner) but this request's own
        // firstOrCreate SELECT missed it, so its INSERT hits the unique index.
        // The unique index guarantees no DUPLICATE row; it does NOT guarantee
        // no FAILURE. createForProject() is not transaction-wrapped and
        // archiveExisting() has already run by this point, so an uncaught
        // throw would leave the project with no draft AND no active
        // programme. The catch is what prevents exactly that.
        $winner = InstallRecord::create(['project_id' => $project->id]);

        $programme = $service->createForProject($project, $user);

        $this->assertSame($winner->id, $programme->install_record_id);
        $this->assertSame(1, InstallRecord::where('project_id', $project->id)->count());
        $this->assertSame(InstallProgramme::STATUS_DRAFT, $programme->fresh()->status);
    }
}
