<?php

namespace Tests\Feature\TimeEntries;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * INST-04g (Phase 14) — one open entry per user per project.
 * INST-04b/c (Phase 15 Plan 02) — category required on start, optional note on stop.
 *
 * Covers start/stop happy path, double-start guard, and the new
 * category/note payload requirements.
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
            ['category' => TimeEntry::CATEGORY_INSTALLATION],
        );

        $response->assertOk();
        $this->assertDatabaseHas('time_entries', [
            'project_id'     => $project->id,
            'user_id'        => $user->id,
            'category'       => TimeEntry::CATEGORY_INSTALLATION,
            'clocked_out_at' => null,
        ]);
    }

    public function test_start_then_stop_closes_entry(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->postJson(
            "/projects/{$project->id}/time-entries/start",
            ['category' => TimeEntry::CATEGORY_INSTALLATION],
        );
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

        $first = $this->actingAs($user)->postJson(
            "/projects/{$project->id}/time-entries/start",
            ['category' => TimeEntry::CATEGORY_INSTALLATION],
        );
        $first->assertOk();

        $second = $this->actingAs($user)->postJson(
            "/projects/{$project->id}/time-entries/start",
            ['category' => TimeEntry::CATEGORY_INSTALLATION],
        );
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

    public function test_any_authenticated_user_can_clock_in(): void
    {
        // Shared workspace (260525-s8b): a non-owner, non-assigned user succeeds.
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($stranger)->postJson(
            "/projects/{$project->id}/time-entries/start",
            ['category' => TimeEntry::CATEGORY_INSTALLATION],
        );

        $response->assertOk();
        $response->assertJsonStructure(['id', 'category', 'clocked_in_at']);
        $this->assertDatabaseHas('time_entries', [
            'project_id'     => $project->id,
            'user_id'        => $stranger->id,
            'category'       => TimeEntry::CATEGORY_INSTALLATION,
            'clocked_out_at' => null,
        ]);
    }

    public function test_one_user_can_open_entries_on_different_projects(): void
    {
        // Guard is per-(user, project), not per-user
        $user = User::factory()->create();
        $projectA = Project::factory()->create(['user_id' => $user->id]);
        $projectB = Project::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->postJson(
            "/projects/{$projectA->id}/time-entries/start",
            ['category' => TimeEntry::CATEGORY_INSTALLATION],
        )->assertOk();
        $this->actingAs($user)->postJson(
            "/projects/{$projectB->id}/time-entries/start",
            ['category' => TimeEntry::CATEGORY_COMMISSIONING],
        )->assertOk();

        $this->assertSame(2, TimeEntry::whereNull('clocked_out_at')->count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Phase 15 Plan 02 — category required, note optional
    // ─────────────────────────────────────────────────────────────────────────

    public function test_start_rejects_missing_category(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson(
            "/projects/{$project->id}/time-entries/start",
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['category']);
    }

    public function test_start_rejects_invalid_category(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson(
            "/projects/{$project->id}/time-entries/start",
            ['category' => 'nonsense'],
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['category']);
    }

    public function test_stop_persists_note(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->postJson(
            "/projects/{$project->id}/time-entries/start",
            ['category' => TimeEntry::CATEGORY_INSTALLATION],
        );
        $stop = $this->actingAs($user)->postJson(
            "/projects/{$project->id}/time-entries/stop",
            ['note' => 'rack finished'],
        );

        $stop->assertOk();
        $this->assertDatabaseHas('time_entries', [
            'project_id' => $project->id,
            'user_id'    => $user->id,
            'notes'      => 'rack finished',
        ]);
    }
}
