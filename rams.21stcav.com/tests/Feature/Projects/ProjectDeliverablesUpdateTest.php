<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\ProjectDeliverable;
use App\Models\ProjectDeliverableAudit;
use App\Models\RamsDocument;
use App\Models\User;
use App\Services\ProjectDeliverablesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 260822-07 (D-10): HTTP-layer proof for ProjectController::updateDeliverables()
 * — POST projects/{project}/deliverables, named projects.deliverables.update.
 *
 * Plan 01's ProjectDeliverablesServiceTest already proves the audit-on-write
 * logic itself; this file proves the HTTP wiring on top of it (validation,
 * redirect, auth gate, and the D-09 non-destruction guarantee at the
 * controller layer).
 *
 * Also proves the BLOCKING CONTRACT this plan exists to satisfy: Plan 04
 * (commit 9812c48) already ships a muted-tab "Add anyway" form posting a
 * flat `deliverable_key` + `state` pair (NOT the `deliverables[key]=state`
 * array shape the new edit form uses) to this exact route. The controller
 * normalizes that flat shape before validating so both callers share one
 * write path — see the "Add anyway" HTTP wiring test at the bottom.
 */
class ProjectDeliverablesUpdateTest extends TestCase
{
    use RefreshDatabase;

    // ── Valid update succeeds + redirects ───────────────────────────────────

    public function test_valid_deliverables_update_succeeds_and_redirects(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->post(route('projects.deliverables.update', $project), [
            'deliverables' => [
                ProjectDeliverable::KEY_RAMS => ProjectDeliverable::STATE_REQUIRED,
                ProjectDeliverable::KEY_OM   => ProjectDeliverable::STATE_NOT_REQUIRED,
            ],
            'reason' => 'Scope confirmed on site.',
        ]);

        $response->assertRedirect(route('projects.show', $project));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('project_deliverables', [
            'project_id'      => $project->id,
            'deliverable_key' => ProjectDeliverable::KEY_RAMS,
            'state'           => ProjectDeliverable::STATE_REQUIRED,
        ]);
        $this->assertDatabaseHas('project_deliverables', [
            'project_id'      => $project->id,
            'deliverable_key' => ProjectDeliverable::KEY_OM,
            'state'           => ProjectDeliverable::STATE_NOT_REQUIRED,
        ]);

        // Every write goes through ProjectDeliverablesService::setState() —
        // proven here by the audit rows it (and only it) writes, carrying
        // the reason and the acting user (D-03).
        $ramsRow = ProjectDeliverable::where('project_id', $project->id)
            ->where('deliverable_key', ProjectDeliverable::KEY_RAMS)
            ->firstOrFail();

        $this->assertDatabaseHas('project_deliverable_audits', [
            'project_deliverable_id' => $ramsRow->id,
            'user_id'                => $user->id,
            'action'                 => ProjectDeliverableAudit::ACTION_MANUAL_CHANGE,
            'reason'                 => 'Scope confirmed on site.',
        ]);
    }

    // ── Validation: unknown deliverable key rejected with 422 ───────────────

    public function test_unknown_deliverable_key_is_rejected_with_422(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson(route('projects.deliverables.update', $project), [
            'deliverables' => [
                'not_a_real_key' => ProjectDeliverable::STATE_REQUIRED,
            ],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('project_deliverables', 0);
    }

    // ── Validation: unknown state value rejected with 422 ───────────────────

    public function test_unknown_state_value_is_rejected_with_422(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson(route('projects.deliverables.update', $project), [
            'deliverables' => [
                ProjectDeliverable::KEY_RAMS => 'definitely_maybe',
            ],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('project_deliverables', 0);
    }

    // ── Unauthenticated request redirects to login ──────────────────────────

    public function test_unauthenticated_request_redirects_to_login(): void
    {
        $project = Project::factory()->create();

        $response = $this->post(route('projects.deliverables.update', $project), [
            'deliverables' => [ProjectDeliverable::KEY_RAMS => ProjectDeliverable::STATE_REQUIRED],
        ]);

        $response->assertRedirect(route('login'));
        $this->assertDatabaseCount('project_deliverables', 0);
    }

    // ── D-09: marking a populated deliverable not-required never touches its documents ──

    public function test_marking_populated_rams_not_required_does_not_delete_or_hide_the_document(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $rams    = RamsDocument::factory()->create(['project_id' => $project->id, 'user_id' => $user->id]);

        app(ProjectDeliverablesService::class)->setState(
            $project,
            ProjectDeliverable::KEY_RAMS,
            ProjectDeliverable::STATE_NOT_YET_DECIDED,
            $user,
        );

        $this->actingAs($user)->post(route('projects.deliverables.update', $project), [
            'deliverables' => [ProjectDeliverable::KEY_RAMS => ProjectDeliverable::STATE_NOT_REQUIRED],
        ]);

        // The flag flipped...
        $this->assertDatabaseHas('project_deliverables', [
            'project_id'      => $project->id,
            'deliverable_key' => ProjectDeliverable::KEY_RAMS,
            'state'           => ProjectDeliverable::STATE_NOT_REQUIRED,
        ]);

        // ...but the RAMS document itself is completely untouched — this
        // endpoint only ever writes to project_deliverables, never to
        // rams_documents.
        $this->assertDatabaseHas('rams_documents', [
            'id'         => $rams->id,
            'project_id' => $project->id,
            'deleted_at' => null,
        ]);
    }

    // ── Blocking contract: Plan 04's shipped "Add anyway" form reaches THIS route ──

    public function test_plan_04_add_anyway_flat_payload_reaches_the_live_route_and_flips_the_flag(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        app(ProjectDeliverablesService::class)->setState(
            $project,
            ProjectDeliverable::KEY_SNAGGING,
            ProjectDeliverable::STATE_NOT_REQUIRED,
            $user,
        );

        // Exact field shape Plan 04 shipped in show.blade.php's muted-tab
        // "Add anyway" form (deliverable_key + state, NOT deliverables[]) —
        // posted to the exact hardcoded path that form uses
        // (url('/projects/{id}/deliverables')), proving the route this plan
        // registers is reachable by that literal URL, not just by name.
        $response = $this->actingAs($user)->post('/projects/'.$project->id.'/deliverables', [
            'deliverable_key' => ProjectDeliverable::KEY_SNAGGING,
            'state'           => ProjectDeliverable::STATE_REQUIRED,
        ]);

        $response->assertRedirect(route('projects.show', $project));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('project_deliverables', [
            'project_id'      => $project->id,
            'deliverable_key' => ProjectDeliverable::KEY_SNAGGING,
            'state'           => ProjectDeliverable::STATE_REQUIRED,
        ]);
    }
}
