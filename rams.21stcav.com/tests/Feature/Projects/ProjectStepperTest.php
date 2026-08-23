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

    /**
     * 260823-cpv: a project PARKED AT Survey Pending (status column really
     * is survey_pending, never past it) whose Site Survey is later marked
     * Not required. Nudge, don't auto-advance, don't grey the pill.
     */
    public function test_parked_survey_pending_not_required_shows_advance_hint(): void
    {
        $owner   = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $owner->id,
            'status'  => Project::STATUS_SURVEY_PENDING,
        ]);

        app(ProjectDeliverablesService::class)->setState(
            $project,
            ProjectDeliverable::KEY_SITE_SURVEY,
            ProjectDeliverable::STATE_NOT_REQUIRED,
            $owner,
        );

        $response = $this->actingAs($owner)->get(route('projects.show', $project));

        $response->assertOk();
        // The stepper must still show Survey Pending as the ACTIVE step —
        // the status column really is that; never misrepresented as
        // skipped or done alongside the nudge.
        $response->assertDontSee('ws-step--skipped', false);
        $response->assertDontSee('(skipped', false);
        // The nudge itself, wired to the EXISTING Advance form by id — no
        // new route, no duplicate data-confirm form.
        $response->assertSee('Site Survey is not required for this project.', false);
        $response->assertSee('form="ws-advance-form"', false);
        $response->assertSee('id="ws-advance-form"', false);
    }

    public function test_parked_survey_pending_required_shows_no_hint(): void
    {
        $owner   = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $owner->id,
            'status'  => Project::STATUS_SURVEY_PENDING,
        ]);

        app(ProjectDeliverablesService::class)->setState(
            $project,
            ProjectDeliverable::KEY_SITE_SURVEY,
            ProjectDeliverable::STATE_REQUIRED,
            $owner,
        );

        $response = $this->actingAs($owner)->get(route('projects.show', $project));

        $response->assertOk();
        $response->assertDontSee('Site Survey is not required for this project.', false);
    }

    public function test_parked_survey_pending_not_yet_decided_shows_no_hint(): void
    {
        $owner   = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $owner->id,
            'status'  => Project::STATUS_SURVEY_PENDING,
        ]);

        // No setState() call — deliverableState() defaults to
        // STATE_NOT_YET_DECIDED for a project with no deliverable row.

        $response = $this->actingAs($owner)->get(route('projects.show', $project));

        $response->assertOk();
        $response->assertDontSee('Site Survey is not required for this project.', false);
    }

    /**
     * The $surveySkipped case (already past Survey Pending) must never also
     * show the parked-hint — the two conditions are mutually exclusive by
     * construction ($surveyParkedNotRequired requires status ===
     * survey_pending; $surveySkipped requires currentIdx to already be past
     * it), but this proves it at the render layer, not just by inspection.
     * Does not modify or re-assert the existing skipped-pill test above.
     */
    public function test_survey_already_skipped_past_shows_no_parked_hint(): void
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
        $response->assertDontSee('Site Survey is not required for this project.', false);
    }
}
