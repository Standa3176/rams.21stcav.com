<?php

namespace Tests\Feature\TimeEntries;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * INST-04g partial — one open entry per user per project at a time.
 * Covers start/stop happy path + double-start guard.
 */
class TimeEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_creates_open_entry(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson(
            "/projects/{$project->id}/time-entries/start",
        );

        $response->assertOk();
        $this->assertDatabaseHas('time_entries', [
            'project_id'     => $project->id,
            'user_id'        => $user->id,
            'clocked_out_at' => null,
        ]);
    }

    public function test_start_then_stop_closes_entry(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->postJson("/projects/{$project->id}/time-entries/start");
        $stop = $this->actingAs($user)->postJson("/projects/{$project->id}/time-entries/stop");

        $stop->assertOk();
        $entry = TimeEntry::first();
        $this->assertNotNull($entry->clocked_out_at);
    }

    public function test_double_clock_in_rejected(): void
    {
        // INST-04g — second start must be rejected with 422
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $first = $this->actingAs($user)->postJson("/projects/{$project->id}/time-entries/start");
        $first->assertOk();

        $second = $this->actingAs($user)->postJson("/projects/{$project->id}/time-entries/start");
        $second->assertStatus(422);

        // Only ONE open entry must exist
        $this->assertSame(
            1,
            TimeEntry::where('project_id', $project->id)
                ->where('user_id', $user->id)
                ->whereNull('clocked_out_at')
                ->count(),
        );
    }

    public function test_stop_without_open_entry_returns_422(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson("/projects/{$project->id}/time-entries/stop");

        $response->assertStatus(422);
    }

    public function test_unrelated_user_cannot_clock_in(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($stranger)->postJson("/projects/{$project->id}/time-entries/start");

        $response->assertForbidden();
    }

    public function test_one_user_can_open_entries_on_different_projects(): void
    {
        // Guard is per-(user, project), not per-user
        $user = User::factory()->create();
        $projectA = Project::factory()->create(['user_id' => $user->id]);
        $projectB = Project::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->postJson("/projects/{$projectA->id}/time-entries/start")->assertOk();
        $this->actingAs($user)->postJson("/projects/{$projectB->id}/time-entries/start")->assertOk();

        $this->assertSame(2, TimeEntry::whereNull('clocked_out_at')->count());
    }
}
