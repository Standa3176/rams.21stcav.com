<?php

namespace Tests\Feature\Commissioning;

use App\Models\CommissioningItem;
use App\Models\InstallProgramme;
use App\Models\InstallTask;
use App\Models\Project;
use App\Models\User;
use App\Services\CommissioningItemGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * INST-05b + D-03 — the InstallTaskObserver (Plan 02) must trigger
 * CommissioningItemGenerator::generate() the moment the LAST install_task in
 * a programme transitions to status=complete. Mid-flight completions must
 * not trigger. Generation must be idempotent.
 *
 * Red until Plan 02 ships Observer + Generator + Model.
 */
class GenerationTriggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_last_task_complete_triggers_item_generation(): void
    {
        [$programme] = $this->seedProgrammeWithTasks(2);

        // Flip both tasks to complete
        $programme->tasks->each(function (InstallTask $task) {
            $task->update(['status' => InstallTask::STATUS_COMPLETE]);
        });

        $this->assertGreaterThan(
            0,
            CommissioningItem::where('install_programme_id', $programme->id)->count(),
            'Commissioning items must be generated when the last install_task completes.',
        );
    }

    public function test_mid_flight_task_complete_does_not_trigger(): void
    {
        [$programme] = $this->seedProgrammeWithTasks(3);

        // Complete only 1 of 3
        $programme->tasks->first()->update(['status' => InstallTask::STATUS_COMPLETE]);

        $this->assertSame(
            0,
            CommissioningItem::where('install_programme_id', $programme->id)->count(),
            'Partial progress must not trigger generation — only the last task completion fires the observer.',
        );
    }

    public function test_generator_is_idempotent_on_duplicate_observer_fires(): void
    {
        [$programme] = $this->seedProgrammeWithTasks(2);

        $programme->tasks->each(fn (InstallTask $t) => $t->update(['status' => InstallTask::STATUS_COMPLETE]));

        $firstCount = CommissioningItem::where('install_programme_id', $programme->id)->count();

        // Re-save one of the already-complete tasks — observer should fire
        // again but generator must short-circuit (idempotency).
        $programme->tasks->first()->update(['notes' => 'nudge']);

        $this->assertSame(
            $firstCount,
            CommissioningItem::where('install_programme_id', $programme->id)->count(),
            'Duplicate observer fires must not create duplicate items.',
        );
    }

    public function test_generator_short_circuits_when_programme_has_zero_tasks(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $programme = InstallProgramme::factory()->create(['project_id' => $project->id]);

        // Pitfall 10 — generator directly invoked with zero tasks should
        // return 0 (no items), not crash.
        $service = app(CommissioningItemGenerator::class);
        $created = $service->generate($programme);

        $this->assertSame(0, $created);
        $this->assertSame(0, CommissioningItem::where('install_programme_id', $programme->id)->count());
    }

    /**
     * @return array{0: InstallProgramme}
     */
    private function seedProgrammeWithTasks(int $taskCount): array
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $owner->id,
            'status'  => Project::STATUS_INSTALLING,
        ]);
        $programme = InstallProgramme::factory()->create([
            'project_id' => $project->id,
            'status'     => InstallProgramme::STATUS_ACTIVE,
        ]);

        InstallTask::factory()->count($taskCount)->create([
            'install_programme_id' => $programme->id,
            'equipment_name'       => 'LG 75 Display',
            'status'               => InstallTask::STATUS_PENDING,
        ]);

        return [$programme->fresh('tasks')];
    }
}
