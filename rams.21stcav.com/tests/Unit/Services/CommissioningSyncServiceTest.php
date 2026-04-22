<?php

namespace Tests\Unit\Services;

use App\Exceptions\CommissioningSignoffException;
use App\Models\CommissioningItem;
use App\Models\CommissioningSignoff;
use App\Models\InstallProgramme;
use App\Models\InstallTask;
use App\Models\Project;
use App\Models\User;
use App\Services\CommissioningSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D-04 — re-sync diff + status preservation + soft-delete.
 * Red until Plan 02 ships CommissioningSyncService::resync.
 */
class CommissioningSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_resync_returns_diff_counters(): void
    {
        [$programme] = $this->scaffoldProgrammeWithTasks(2);

        $diff = app(CommissioningSyncService::class)->resync($programme);

        $this->assertArrayHasKey('added', $diff);
        $this->assertArrayHasKey('removed', $diff);
        $this->assertArrayHasKey('unchanged', $diff);
        $this->assertArrayHasKey('restored', $diff);
    }

    public function test_resync_preserves_existing_pass_status(): void
    {
        [$programme] = $this->scaffoldProgrammeWithTasks(1);
        $task = $programme->tasks->first();

        // Seed item as pass from a previous sync
        $item = CommissioningItem::factory()->create([
            'install_programme_id' => $programme->id,
            'install_task_id'      => $task->id,
            'equipment_name'       => $task->equipment_name,
            'status'               => CommissioningItem::STATUS_PASS,
        ]);

        app(CommissioningSyncService::class)->resync($programme);

        $this->assertSame(CommissioningItem::STATUS_PASS, $item->fresh()->status);
    }

    public function test_resync_preserves_existing_fail_status_and_notes(): void
    {
        [$programme] = $this->scaffoldProgrammeWithTasks(1);
        $task = $programme->tasks->first();

        $item = CommissioningItem::factory()->create([
            'install_programme_id' => $programme->id,
            'install_task_id'      => $task->id,
            'equipment_name'       => $task->equipment_name,
            'status'               => CommissioningItem::STATUS_FAIL,
            'notes'                => 'audio feedback',
            'evidence_photo_path'  => 'commissioning-evidence/fail.jpg',
        ]);

        app(CommissioningSyncService::class)->resync($programme);

        $fresh = $item->fresh();
        $this->assertSame(CommissioningItem::STATUS_FAIL, $fresh->status);
        $this->assertSame('audio feedback', $fresh->notes);
        $this->assertSame('commissioning-evidence/fail.jpg', $fresh->evidence_photo_path);
    }

    public function test_resync_soft_deletes_without_hard_delete(): void
    {
        [$programme] = $this->scaffoldProgrammeWithTasks(1);
        $task = $programme->tasks->first();

        $item = CommissioningItem::factory()->create([
            'install_programme_id' => $programme->id,
            'install_task_id'      => $task->id,
            'equipment_name'       => $task->equipment_name,
        ]);

        $task->forceDelete();

        app(CommissioningSyncService::class)->resync($programme);

        $this->assertSoftDeleted('commissioning_items', ['id' => $item->id]);
        $this->assertSame(
            1,
            CommissioningItem::withTrashed()->where('id', $item->id)->count(),
            'Row must survive with deleted_at set — hard delete would lose audit trail.',
        );
    }

    public function test_resync_restores_soft_deleted_on_task_return(): void
    {
        [$programme] = $this->scaffoldProgrammeWithTasks(1);
        $task = $programme->tasks->first();

        $item = CommissioningItem::factory()->create([
            'install_programme_id' => $programme->id,
            'install_task_id'      => $task->id,
            'equipment_name'       => $task->equipment_name,
        ]);
        $item->delete();

        // The task is still there — sync should restore the item
        $diff = app(CommissioningSyncService::class)->resync($programme);

        $this->assertGreaterThanOrEqual(1, $diff['restored']);
        $this->assertNull($item->fresh()->deleted_at);
    }

    public function test_resync_does_not_fire_when_signoff_exists(): void
    {
        [$programme] = $this->scaffoldProgrammeWithTasks(1);

        CommissioningSignoff::factory()->create([
            'install_programme_id' => $programme->id,
        ]);

        $this->expectException(CommissioningSignoffException::class);

        app(CommissioningSyncService::class)->resync($programme);
    }

    /**
     * @return array{0: InstallProgramme}
     */
    private function scaffoldProgrammeWithTasks(int $count): array
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

        InstallTask::factory()->count($count)->create([
            'install_programme_id' => $programme->id,
            'equipment_name'       => 'LG 75 Display',
            'status'               => InstallTask::STATUS_COMPLETE,
        ]);

        return [$programme->fresh('tasks')];
    }
}
