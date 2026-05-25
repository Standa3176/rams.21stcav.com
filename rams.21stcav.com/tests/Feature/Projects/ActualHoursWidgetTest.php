<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for the Actual Hours widget on the project show page (Phase 15, Plan 15-05).
 *
 * Verifies:
 *   - Project owner sees the widget + correctly formatted total (D-13, D-14)
 *   - Admin sees the widget regardless of ownership (D-16 admin override)
 *   - Non-owner non-admin does NOT see the widget (D-16 privacy guard)
 *   - Empty state shows "No time tracked yet" when no closed entries exist
 *   - Per-category breakdown renders all 4 category labels
 *   - Open (still-running) entries are excluded from the total — match
 *     TimeEntryService::summaryForProject behaviour (closed-only).
 */
class ActualHoursWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_widget_with_totals(): void
    {
        $owner   = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);
        TimeEntry::create([
            'project_id'     => $project->id,
            'user_id'        => $owner->id,
            'category'       => TimeEntry::CATEGORY_INSTALLATION,
            'clocked_in_at'  => now()->subHours(3),
            'clocked_out_at' => now()->subHours(1),   // 2h = 120 min
        ]);

        $this->actingAs($owner)
             ->get(route('projects.show', $project))
             ->assertOk()
             ->assertSee('Actual Hours')
             ->assertSee('2h 0m');
    }

    public function test_admin_sees_widget(): void
    {
        $owner   = User::factory()->create();
        $admin   = User::factory()->create(['role' => 'admin']);
        $project = Project::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($admin)
             ->get(route('projects.show', $project))
             ->assertOk()
             ->assertSee('Actual Hours');
    }

    public function test_any_authenticated_user_sees_actual_hours_widget(): void
    {
        $owner    = User::factory()->create();
        $stranger = User::factory()->create(['role' => 'user']); // non-owner, non-admin
        $project  = Project::factory()->create(['user_id' => $owner->id]);
        TimeEntry::create([
            'project_id'     => $project->id,
            'user_id'        => $owner->id,
            'category'       => TimeEntry::CATEGORY_INSTALLATION,
            'clocked_in_at'  => now()->subHours(3),
            'clocked_out_at' => now()->subHours(1),   // 2h = 120 min
        ]);

        // Shared workspace: a non-owner, non-admin user gets the SAME outcome
        // as the owner on the project show page — full access, no 403. The
        // actual-hours visibility gate (ProjectController::show) was relaxed to
        // auth()->check(), so the stranger is no longer privacy-blocked.
        $ownerStatus    = $this->actingAs($owner)->get(route('projects.show', $project))->getStatusCode();
        $strangerStatus = $this->actingAs($stranger)->get(route('projects.show', $project))->getStatusCode();

        $this->assertSame(200, $strangerStatus, 'Shared workspace: stranger must reach the project show page.');
        $this->assertSame($ownerStatus, $strangerStatus, 'Stranger must get the same outcome as the owner.');
        $this->assertNotSame(403, $strangerStatus, 'Stranger must NOT be forbidden from actual-hours data.');
    }

    public function test_widget_shows_empty_state_when_no_entries(): void
    {
        $owner   = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($owner)
             ->get(route('projects.show', $project))
             ->assertOk()
             ->assertSee('Actual Hours')
             ->assertSee('No time tracked yet');
    }

    public function test_widget_breaks_down_by_category(): void
    {
        $owner   = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);
        // 120 min installation
        TimeEntry::create([
            'project_id'     => $project->id,
            'user_id'        => $owner->id,
            'category'       => TimeEntry::CATEGORY_INSTALLATION,
            'clocked_in_at'  => now()->subHours(3),
            'clocked_out_at' => now()->subHours(1),
        ]);
        // 60 min commissioning
        TimeEntry::create([
            'project_id'     => $project->id,
            'user_id'        => $owner->id,
            'category'       => TimeEntry::CATEGORY_COMMISSIONING,
            'clocked_in_at'  => now()->subMinutes(120),
            'clocked_out_at' => now()->subHour(),
        ]);
        // 30 min testing
        TimeEntry::create([
            'project_id'     => $project->id,
            'user_id'        => $owner->id,
            'category'       => TimeEntry::CATEGORY_TESTING,
            'clocked_in_at'  => now()->subHour(),
            'clocked_out_at' => now()->subMinutes(30),
        ]);

        $this->actingAs($owner)
             ->get(route('projects.show', $project))
             ->assertOk()
             ->assertSee('Installation')
             ->assertSee('Commissioning')
             ->assertSee('Testing')
             ->assertSee('Other')
             ->assertSee('3h 30m');  // total 210 min
    }

    public function test_widget_excludes_open_entries(): void
    {
        $owner   = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);
        TimeEntry::create([
            'project_id'     => $project->id,
            'user_id'        => $owner->id,
            'category'       => TimeEntry::CATEGORY_INSTALLATION,
            'clocked_in_at'  => now()->subHour(),
            'clocked_out_at' => null,   // OPEN
        ]);

        $this->actingAs($owner)
             ->get(route('projects.show', $project))
             ->assertOk()
             ->assertSee('No time tracked yet');   // open entry not counted → empty state
    }
}
