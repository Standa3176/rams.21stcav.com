<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\ProjectDeliverable;
use App\Models\User;
use App\Services\ProjectDeliverablesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for the lifecycle stepper's D-11 skip-state (260822-04,
 * fixing Pitfall 2: a false "done" tick for Survey Pending on a project that
 * skipped straight to Engineering because Site Survey was Not required).
 */
class ProjectStepperTest extends TestCase
{
    use RefreshDatabase;

    public function test_survey_pending_renders_skipped_not_done_when_survey_not_required(): void
    {
        $owner   = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $owner->id,
            'status'  => Project::STATUS_ENGINEERING,
        ]);

        app(ProjectDeliverablesService::class)->setState(
            $project,
            ProjectDeliverable::KEY_SITE_SURVEY,
            ProjectDeliverable::STATE_NOT_REQUIRED,
            $owner,
        );

        $response = $this->actingAs($owner)->get(route('projects.show', $project));

        $response->assertOk();
        $response->assertSee('ws-step--skipped', false);
        // The exact skipped-pill text — proves it renders against the Survey
        // Pending step specifically, not just somewhere on the page.
        $response->assertSee('Survey Pending (skipped — not required)', false);
    }

    public function test_survey_pending_renders_normal_done_tick_when_survey_required(): void
    {
        $owner   = User::factory()->create();
        $project = Project::factory()->create([
            'user_id'           => $owner->id,
            'status'             => Project::STATUS_ENGINEERING,
            'survey_started_at'  => now()->subDays(3),
        ]);

        app(ProjectDeliverablesService::class)->setState(
            $project,
            ProjectDeliverable::KEY_SITE_SURVEY,
            ProjectDeliverable::STATE_REQUIRED,
            $owner,
        );

        $response = $this->actingAs($owner)->get(route('projects.show', $project));

        $response->assertOk();
        // Unaffected by this plan: the normal done-tick renders, never the
        // skipped marker.
        $response->assertDontSee('ws-step--skipped', false);
        $response->assertDontSee('(skipped', false);
        $response->assertSee('Survey Pending', false);
    }
}
