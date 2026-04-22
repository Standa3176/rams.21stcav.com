<?php

namespace Tests\Feature\Commissioning;

use App\Models\CommissioningItem;
use App\Models\CommissioningSignoff;
use App\Models\InstallProgramme;
use App\Models\InstallTask;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D-04 — POST /install-programmes/{programme}/commissioning/resync
 *   returns JSON diff {added, removed, unchanged, restored}
 *   and preserves existing pass/fail/na statuses on unchanged items.
 *
 * Red until Plan 02 (sync service) + Plan 05 (controller + route) ship.
 */
class ResyncDiffTest extends TestCase
{
    use RefreshDatabase;

    public function test_resync_adds_items_for_new_tasks(): void
    {
        [$user, $programme] = $this->scaffoldProgrammeWithTasks(2);

        // Existing items for 2 tasks
        CommissioningItem::factory()->count(2)->create([
            'install_programme_id' => $programme->id,
            'equipment_name'       => 'LG 75 Display',
        ]);

        // Add a 3rd install_task
        InstallTask::factory()->create([
            'install_programme_id' => $programme->id,
            'equipment_name'       => 'Poly Studio X70',
            'status'               => InstallTask::STATUS_COMPLETE,
        ]);

        $response = $this->actingAs($user)
            ->postJson("/install-programmes/{$programme->id}/commissioning/resync");

        $response->assertOk();
        $diff = $response->json('diff');
        $this->assertGreaterThanOrEqual(1, $diff['added']);
        $this->assertSame(0, $diff['removed']);
    }

    public function test_resync_soft_deletes_removed(): void
    {
        [$user, $programme] = $this->scaffoldProgrammeWithTasks(2);

        $task = $programme->tasks->first();

        $item = CommissioningItem::factory()->create([
            'install_programme_id' => $programme->id,
            'install_task_id'      => $task->id,
            'equipment_name'       => $task->equipment_name,
        ]);

        // Hard-remove the backing install_task
        $task->forceDelete();

        $this->actingAs($user)
            ->postJson("/install-programmes/{$programme->id}/commissioning/resync")
            ->assertOk();

        $this->assertSoftDeleted('commissioning_items', ['id' => $item->id]);
    }

    public function test_resync_preserves_pass_fail_na(): void
    {
        [$user, $programme] = $this->scaffoldProgrammeWithTasks(3);

        $tasks = $programme->tasks;

        $pass = CommissioningItem::factory()->create([
            'install_programme_id' => $programme->id,
            'install_task_id'      => $tasks[0]->id,
            'equipment_name'       => $tasks[0]->equipment_name,
            'status'               => CommissioningItem::STATUS_PASS,
        ]);
        $na = CommissioningItem::factory()->create([
            'install_programme_id' => $programme->id,
            'install_task_id'      => $tasks[1]->id,
            'equipment_name'       => $tasks[1]->equipment_name,
            'status'               => CommissioningItem::STATUS_NA,
        ]);
        $pending = CommissioningItem::factory()->create([
            'install_programme_id' => $programme->id,
            'install_task_id'      => $tasks[2]->id,
            'equipment_name'       => $tasks[2]->equipment_name,
            'status'               => CommissioningItem::STATUS_PENDING,
        ]);

        // Add a new task so there's a real diff
        InstallTask::factory()->create([
            'install_programme_id' => $programme->id,
            'equipment_name'       => 'Crestron CP3',
            'status'               => InstallTask::STATUS_COMPLETE,
        ]);

        $this->actingAs($user)
            ->postJson("/install-programmes/{$programme->id}/commissioning/resync")
            ->assertOk();

        $this->assertSame(CommissioningItem::STATUS_PASS, $pass->fresh()->status);
        $this->assertSame(CommissioningItem::STATUS_NA, $na->fresh()->status);
        $this->assertSame(CommissioningItem::STATUS_PENDING, $pending->fresh()->status);
    }

    public function test_resync_restores_soft_deleted_when_equipment_returns(): void
    {
        [$user, $programme] = $this->scaffoldProgrammeWithTasks(1);

        $task = $programme->tasks->first();

        $item = CommissioningItem::factory()->create([
            'install_programme_id' => $programme->id,
            'install_task_id'      => $task->id,
            'equipment_name'       => $task->equipment_name,
        ]);

        // Soft-delete the item, then a fresh install_task with matching
        // equipment_name arrives — resync should restore the item.
        $item->delete();

        InstallTask::factory()->create([
            'install_programme_id' => $programme->id,
            'equipment_name'       => $task->equipment_name,
            'status'               => InstallTask::STATUS_COMPLETE,
        ]);

        $response = $this->actingAs($user)
            ->postJson("/install-programmes/{$programme->id}/commissioning/resync");

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, $response->json('diff.restored'));
    }

    /**
     * @return array{0: User, 1: InstallProgramme}
     */
    private function scaffoldProgrammeWithTasks(int $taskCount): array
    {
        $user = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'status'  => Project::STATUS_INSTALLING,
        ]);
        $programme = InstallProgramme::factory()->create([
            'project_id' => $project->id,
            'status'     => InstallProgramme::STATUS_ACTIVE,
        ]);

        InstallTask::factory()->count($taskCount)->create([
            'install_programme_id' => $programme->id,
            'equipment_name'       => 'LG 75 Display',
            'status'               => InstallTask::STATUS_COMPLETE,
        ]);

        return [$user, $programme->fresh('tasks')];
    }
}
