<?php

namespace Tests\Feature\InstallTasks;

use App\Models\InstallProgramme;
use App\Models\InstallTask;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * INST-03c, INST-03g, D-05, D-06, D-07.
 * Status transitions: pending → in_progress → complete (tap); blocked/skipped
 * require reason; reopen (complete → in_progress) allowed; counters returned.
 */
class InstallTaskStatusUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_patch_pending_to_in_progress(): void
    {
        [$user, $task] = $this->scaffold();

        $response = $this->actingAs($user)->patchJson(
            "/install-tasks/{$task->id}/status",
            ['status' => InstallTask::STATUS_IN_PROGRESS],
        );

        $response->assertOk();
        $response->assertJsonPath('status', InstallTask::STATUS_IN_PROGRESS);
        $this->assertDatabaseHas('install_tasks', [
            'id'     => $task->id,
            'status' => InstallTask::STATUS_IN_PROGRESS,
        ]);
    }

    public function test_status_patch_in_progress_to_complete_sets_completed_at(): void
    {
        [$user, $task] = $this->scaffold([
            'status'     => InstallTask::STATUS_IN_PROGRESS,
            'started_at' => now(),
        ]);

        $response = $this->actingAs($user)->patchJson(
            "/install-tasks/{$task->id}/status",
            ['status' => InstallTask::STATUS_COMPLETE],
        );

        $response->assertOk();
        $this->assertNotNull($task->fresh()->completed_at);
    }

    public function test_status_blocked_requires_reason(): void
    {
        [$user, $task] = $this->scaffold();

        $response = $this->actingAs($user)->patchJson(
            "/install-tasks/{$task->id}/status",
            ['status' => InstallTask::STATUS_BLOCKED],
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['blocked_reason']);
    }

    public function test_status_blocked_accepts_with_reason(): void
    {
        [$user, $task] = $this->scaffold();

        $response = $this->actingAs($user)->patchJson(
            "/install-tasks/{$task->id}/status",
            [
                'status'         => InstallTask::STATUS_BLOCKED,
                'blocked_reason' => 'Waiting on cable delivery',
            ],
        );

        $response->assertOk();
        $this->assertSame('Waiting on cable delivery', $task->fresh()->blocked_reason);
    }

    public function test_reopen_complete_to_in_progress_allowed(): void
    {
        // D-07 regression allowed via overflow menu
        [$user, $task] = $this->scaffold([
            'status'       => InstallTask::STATUS_COMPLETE,
            'completed_at' => now(),
        ]);

        $response = $this->actingAs($user)->patchJson(
            "/install-tasks/{$task->id}/status",
            ['status' => InstallTask::STATUS_IN_PROGRESS],
        );

        $response->assertOk();
        $this->assertSame(InstallTask::STATUS_IN_PROGRESS, $task->fresh()->status);
    }

    public function test_response_includes_room_and_programme_counters(): void
    {
        // INST-03g — status save returns updated counters
        [$user, $task] = $this->scaffold();

        $response = $this->actingAs($user)->patchJson(
            "/install-tasks/{$task->id}/status",
            ['status' => InstallTask::STATUS_COMPLETE],
        );

        $response->assertOk();
        $response->assertJsonStructure([
            'id',
            'status',
            'counters' => [
                'room'      => ['complete', 'total'],
                'programme' => ['complete', 'total'],
            ],
        ]);
    }

    public function test_any_authenticated_user_can_update_status(): void
    {
        // Shared workspace (260525-s8b): a non-owner, non-assigned user succeeds.
        [, $task] = $this->scaffold();
        $stranger = User::factory()->create();

        $response = $this->actingAs($stranger)->patchJson(
            "/install-tasks/{$task->id}/status",
            ['status' => InstallTask::STATUS_IN_PROGRESS],
        );

        $response->assertOk();
        $response->assertJsonPath('status', InstallTask::STATUS_IN_PROGRESS);
        $this->assertDatabaseHas('install_tasks', [
            'id'     => $task->id,
            'status' => InstallTask::STATUS_IN_PROGRESS,
        ]);
    }

    public function test_status_rejects_invalid_value(): void
    {
        [$user, $task] = $this->scaffold();

        $response = $this->actingAs($user)->patchJson(
            "/install-tasks/{$task->id}/status",
            ['status' => 'garbage-status'],
        );

        $response->assertStatus(422);
    }

    private function scaffold(array $taskAttrs = []): array
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $programme = InstallProgramme::factory()->create(['project_id' => $project->id]);
        $task = InstallTask::factory()->create(array_merge([
            'install_programme_id' => $programme->id,
        ], $taskAttrs));

        return [$owner, $task];
    }
}
