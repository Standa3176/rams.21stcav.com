<?php

namespace Tests\Feature\InstallTasks;

use App\Models\InstallProgramme;
use App\Models\InstallTask;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * INST-03f — per-task notes, AJAX save on blur.
 */
class InstallTaskNotesTest extends TestCase
{
    use RefreshDatabase;

    public function test_notes_patch_persists(): void
    {
        [$user, $task] = $this->scaffold();

        $response = $this->actingAs($user)->patchJson(
            "/install-tasks/{$task->id}/notes",
            ['notes' => 'Found a spare DisplayPort cable in the rack.'],
        );

        $response->assertOk();
        $this->assertSame(
            'Found a spare DisplayPort cable in the rack.',
            $task->fresh()->notes,
        );
    }

    public function test_notes_length_capped(): void
    {
        [$user, $task] = $this->scaffold();

        $response = $this->actingAs($user)->patchJson(
            "/install-tasks/{$task->id}/notes",
            ['notes' => str_repeat('x', 5001)],
        );

        $response->assertStatus(422);
    }

    public function test_notes_empty_string_clears_existing(): void
    {
        [$user, $task] = $this->scaffold(['notes' => 'old note']);

        $response = $this->actingAs($user)->patchJson(
            "/install-tasks/{$task->id}/notes",
            ['notes' => ''],
        );

        $response->assertOk();
        $this->assertSame('', (string) $task->fresh()->notes);
    }

    public function test_any_authenticated_user_can_save_notes(): void
    {
        // Shared workspace (260525-s8b): a non-owner, non-assigned user succeeds.
        [, $task] = $this->scaffold();
        $stranger = User::factory()->create();

        $response = $this->actingAs($stranger)->patchJson(
            "/install-tasks/{$task->id}/notes",
            ['notes' => 'shared workspace note'],
        );

        $response->assertOk();
        $this->assertSame('shared workspace note', $task->fresh()->notes);
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
