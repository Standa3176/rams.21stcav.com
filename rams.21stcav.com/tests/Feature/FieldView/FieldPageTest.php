<?php

namespace Tests\Feature\FieldView;

use App\Models\InstallProgramme;
use App\Models\InstallTask;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers INST-03a (route exists, returns 200 for owner/admin/assigned engineer; 403 for others)
 * and INST-03b (engineer scope filter vs owner/admin all-tasks).
 */
class FieldPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_field_page(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $programme = InstallProgramme::factory()->create(['project_id' => $project->id]);
        InstallTask::factory()->count(3)->create(['install_programme_id' => $programme->id]);

        $response = $this->actingAs($owner)->get("/projects/{$project->id}/programme");

        $response->assertOk();
    }

    public function test_admin_can_view_field_page(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $project = Project::factory()->create(['user_id' => $owner->id]);
        InstallProgramme::factory()->create(['project_id' => $project->id]);

        $response = $this->actingAs($admin)->get("/projects/{$project->id}/programme");

        $response->assertOk();
    }

    public function test_engineer_with_assigned_task_can_view_field_page(): void
    {
        $owner = User::factory()->create();
        $engineer = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $programme = InstallProgramme::factory()->create(['project_id' => $project->id]);
        InstallTask::factory()->create([
            'install_programme_id' => $programme->id,
            'assigned_to'          => $engineer->id,
        ]);

        $response = $this->actingAs($engineer)->get("/projects/{$project->id}/programme");

        $response->assertOk();
    }

    public function test_any_authenticated_user_can_view_field_page(): void
    {
        // Shared workspace (260525-s8b): a non-owner, non-assigned user gets 200.
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);
        InstallProgramme::factory()->create(['project_id' => $project->id]);

        $response = $this->actingAs($stranger)->get("/projects/{$project->id}/programme");

        $response->assertOk();
    }

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $project = Project::factory()->create();

        $response = $this->get("/projects/{$project->id}/programme");

        $response->assertRedirect('/login');
    }

    public function test_any_authenticated_user_sees_all_tasks_by_default(): void
    {
        // Shared workspace (260525-s8b): the listing is un-scoped, so every
        // authenticated user defaults to scope=all and sees ALL tasks — including
        // those assigned to someone else. (Was the INST-03b/D-02 assigned-only filter.)
        $owner = User::factory()->create();
        $engineer = User::factory()->create();
        $otherEngineer = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $programme = InstallProgramme::factory()->create(['project_id' => $project->id]);

        InstallTask::factory()->create([
            'install_programme_id' => $programme->id,
            'assigned_to'          => $engineer->id,
            'title'                => 'My assigned task',
        ]);
        InstallTask::factory()->create([
            'install_programme_id' => $programme->id,
            'assigned_to'          => $otherEngineer->id,
            'title'                => 'Someone else task',
        ]);

        $response = $this->actingAs($engineer)->get("/projects/{$project->id}/programme");

        $response->assertOk();
        $response->assertSee('My assigned task');
        $response->assertSee('Someone else task');
    }

    public function test_owner_sees_all_tasks_including_unassigned(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $programme = InstallProgramme::factory()->create(['project_id' => $project->id]);
        InstallTask::factory()->create([
            'install_programme_id' => $programme->id,
            'title'                => 'Unassigned task xyz123',
        ]);

        $response = $this->actingAs($owner)->get("/projects/{$project->id}/programme");

        $response->assertOk();
        $response->assertSee('Unassigned task xyz123');
    }
}
