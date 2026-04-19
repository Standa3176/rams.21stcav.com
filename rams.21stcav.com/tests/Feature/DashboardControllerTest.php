<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for DashboardController — covers DASH-01a / DASH-01b.
 *
 * Confirms that:
 *   - GET /dashboard is behind the auth middleware
 *   - The controller (not the legacy closure) serves the route
 *   - The view receives the expected variables for Wave 2 to consume
 *   - Archived projects are excluded from the health grid
 */
class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_redirected_to_login(): void
    {
        $response = $this->get('/dashboard');

        $response->assertRedirect('/login');
    }

    public function test_authenticated_gets_200(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/dashboard');

        $response->assertOk();
    }

    public function test_view_receives_required_variables(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/dashboard');

        $response->assertOk();
        $response->assertViewHasAll(['projects', 'healthMap', 'statusCounts']);
    }

    public function test_all_projects_shown_excludes_archived(): void
    {
        $user = User::factory()->create();

        $active = Project::factory()->create([
            'status'  => Project::STATUS_ENGINEERING,
            'user_id' => $user->id,
        ]);

        $archived = Project::factory()->create([
            'status'  => Project::STATUS_ARCHIVED,
            'user_id' => $user->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->get('/dashboard');

        $response->assertOk();

        $projects = $response->viewData('projects');
        $ids      = collect($projects)->pluck('id')->all();

        $this->assertContains($active->id, $ids, 'Active project must appear on the dashboard');
        $this->assertNotContains($archived->id, $ids, 'Archived projects must be excluded');
    }
}
