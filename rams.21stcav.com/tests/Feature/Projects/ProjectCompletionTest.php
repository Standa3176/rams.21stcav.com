<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\ProjectDeliverable;
use App\Models\RamsDocument;
use App\Models\User;
use App\Services\ProjectDeliverablesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 260822-07 (D-14): "Completion warns, does not block."
 *
 * ProjectController::transition() gains a warn-then-confirm branch for
 * to_status=completed, mirroring DeviceStencilController::update()'s
 * confirm_regenerate guard shape (first submit warns without mutating,
 * re-submit with the confirm flag proceeds).
 */
class ProjectCompletionTest extends TestCase
{
    use RefreshDatabase;

    // ── Warns without confirmation, does not change status ──────────────────

    public function test_completing_with_outstanding_required_deliverable_warns_and_does_not_advance(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id, 'status' => Project::STATUS_HANDOVER]);

        app(ProjectDeliverablesService::class)->setState(
            $project,
            ProjectDeliverable::KEY_RAMS,
            ProjectDeliverable::STATE_REQUIRED,
            $user,
        );
        // No RamsDocument exists for this project — RAMS is Required but has
        // zero backing documents, so it must be listed as outstanding.

        $response = $this->actingAs($user)->post(route('projects.transition', $project), [
            'to_status' => Project::STATUS_COMPLETED,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('warning');
        $this->assertStringContainsString('RAMS', session('warning'));

        $project->refresh();
        $this->assertSame(Project::STATUS_HANDOVER, $project->status, 'status must NOT change on the first, unconfirmed submit');
    }

    // ── Proceeds exactly as before when confirm_incomplete=1 is sent ────────

    public function test_completing_with_confirm_incomplete_proceeds_despite_outstanding_deliverable(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id, 'status' => Project::STATUS_HANDOVER]);

        app(ProjectDeliverablesService::class)->setState(
            $project,
            ProjectDeliverable::KEY_RAMS,
            ProjectDeliverable::STATE_REQUIRED,
            $user,
        );

        $response = $this->actingAs($user)->post(route('projects.transition', $project), [
            'to_status'          => Project::STATUS_COMPLETED,
            'confirm_incomplete' => '1',
        ]);

        $response->assertRedirect(route('projects.show', $project));
        $response->assertSessionHas('success');

        $project->refresh();
        $this->assertSame(Project::STATUS_COMPLETED, $project->status);

        // The "completed anyway" decision is provable from the normal
        // activity log — ProjectService::transition() still ran unchanged
        // (T-260822-13).
        $this->assertDatabaseHas('project_activity_logs', [
            'project_id' => $project->id,
            'user_id'    => $user->id,
        ]);
    }

    // ── Unaffected when every required deliverable is satisfied ─────────────

    public function test_completing_with_all_required_deliverables_satisfied_proceeds_without_warning(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id, 'status' => Project::STATUS_HANDOVER]);

        app(ProjectDeliverablesService::class)->setState(
            $project,
            ProjectDeliverable::KEY_RAMS,
            ProjectDeliverable::STATE_REQUIRED,
            $user,
        );
        RamsDocument::factory()->create(['project_id' => $project->id, 'user_id' => $user->id]);

        $response = $this->actingAs($user)->post(route('projects.transition', $project), [
            'to_status' => Project::STATUS_COMPLETED,
        ]);

        $response->assertRedirect(route('projects.show', $project));
        $response->assertSessionHas('success');
        $response->assertSessionMissing('warning');

        $project->refresh();
        $this->assertSame(Project::STATUS_COMPLETED, $project->status);
    }

    // ── Unaffected when nothing is marked Required at all ───────────────────

    public function test_completing_with_nothing_required_proceeds_without_warning(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id, 'status' => Project::STATUS_HANDOVER]);
        // No deliverable rows written at all — deliverableState() defaults
        // every key to STATE_NOT_YET_DECIDED, never STATE_REQUIRED.

        $response = $this->actingAs($user)->post(route('projects.transition', $project), [
            'to_status' => Project::STATUS_COMPLETED,
        ]);

        $response->assertRedirect(route('projects.show', $project));
        $response->assertSessionHas('success');
        $response->assertSessionMissing('warning');

        $project->refresh();
        $this->assertSame(Project::STATUS_COMPLETED, $project->status);
    }

    // ── Regression: unaffected for every OTHER transition target ────────────

    public function test_advancing_to_a_non_completed_status_is_completely_unaffected_by_d14(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id, 'status' => Project::STATUS_ENGINEERING]);

        app(ProjectDeliverablesService::class)->setState(
            $project,
            ProjectDeliverable::KEY_RAMS,
            ProjectDeliverable::STATE_REQUIRED,
            $user,
        );
        // Still zero RamsDocuments — would be "outstanding" if the target
        // were completed, but engineering -> installing is a different
        // target status entirely, so D-14's branch must never fire here.

        $response = $this->actingAs($user)->post(route('projects.transition', $project), [
            'to_status' => Project::STATUS_INSTALLING,
        ]);

        $response->assertRedirect(route('projects.show', $project));
        $response->assertSessionHas('success');
        $response->assertSessionMissing('warning');

        $project->refresh();
        $this->assertSame(Project::STATUS_INSTALLING, $project->status);
    }

    // ── programming is excluded from the outstanding check ───────────────────

    public function test_required_programming_with_no_documents_never_blocks_or_warns_about_completion(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id, 'status' => Project::STATUS_HANDOVER]);

        // Programming has no document type/generator at all (D-05) — marking
        // it Required must never appear in the outstanding list, since there
        // is nothing that could ever satisfy it.
        app(ProjectDeliverablesService::class)->setState(
            $project,
            ProjectDeliverable::KEY_PROGRAMMING,
            ProjectDeliverable::STATE_REQUIRED,
            $user,
        );

        $response = $this->actingAs($user)->post(route('projects.transition', $project), [
            'to_status' => Project::STATUS_COMPLETED,
        ]);

        $response->assertRedirect(route('projects.show', $project));
        $response->assertSessionHas('success');
        $response->assertSessionMissing('warning');

        $project->refresh();
        $this->assertSame(Project::STATUS_COMPLETED, $project->status);
    }
}
