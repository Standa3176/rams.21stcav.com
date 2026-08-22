<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\ProjectDeliverable;
use App\Models\ProjectPackage;
use App\Models\User;
use App\Services\ProjectDeliverablesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for the "Next Step" hero card's D-11 guards (260822-04,
 * fixing Pitfall 3: the hard-coded chain still prompting "Create Site
 * Survey" — and equivalents for RAMS/Worksheet/O&M — on a deliverable that
 * is explicitly marked Not required).
 */
class ProjectNextStepTest extends TestCase
{
    use RefreshDatabase;

    private function makeReviewedPackage(Project $project, User $user): ProjectPackage
    {
        return ProjectPackage::create([
            'project_id' => $project->id,
            'user_id'    => $user->id,
            'status'     => ProjectPackage::STATUS_REVIEWED,
        ]);
    }

    public function test_next_step_skips_create_site_survey_when_survey_not_required(): void
    {
        $owner   = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $this->makeReviewedPackage($project, $owner);

        app(ProjectDeliverablesService::class)->setState(
            $project,
            ProjectDeliverable::KEY_SITE_SURVEY,
            ProjectDeliverable::STATE_NOT_REQUIRED,
            $owner,
        );

        $response = $this->actingAs($owner)->get(route('projects.show', $project));

        $response->assertOk();
        $response->assertDontSee('Create Site Survey');
        // RAMS is not marked not_required (still "not yet decided" by
        // default) and there are zero RAMS documents — the chain falls
        // through to the next applicable branch.
        $response->assertSee('Generate RAMS Document');
    }

    public function test_no_next_step_card_when_all_four_chain_deliverables_not_required(): void
    {
        $owner   = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $this->makeReviewedPackage($project, $owner);

        $service = app(ProjectDeliverablesService::class);
        foreach ([
            ProjectDeliverable::KEY_SITE_SURVEY,
            ProjectDeliverable::KEY_RAMS,
            ProjectDeliverable::KEY_WORKSHEET,
            ProjectDeliverable::KEY_OM,
        ] as $key) {
            $service->setState($project, $key, ProjectDeliverable::STATE_NOT_REQUIRED, $owner);
        }

        $response = $this->actingAs($owner)->get(route('projects.show', $project));

        $response->assertOk();
        // Every branch in the 4-item chain is suppressed — no Next Step
        // card renders at all. "Next Step" text (the aria-label / eyebrow
        // label) only exists inside the @if($nextStep) block — this is a
        // reliable proxy for "$nextStep is null" (other page furniture,
        // e.g. the tab-strip's own "+ Generate Worksheet" action button,
        // legitimately contains overlapping words and is NOT gated on
        // $nextStep, so it is deliberately not asserted against here).
        $response->assertDontSee('Next Step');
        $response->assertDontSee('Create Site Survey');
    }
}
