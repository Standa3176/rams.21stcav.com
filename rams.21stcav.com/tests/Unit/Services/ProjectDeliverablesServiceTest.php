<?php

namespace Tests\Unit\Services;

use App\Models\Project;
use App\Models\ProjectDeliverable;
use App\Models\ProjectDeliverableAudit;
use App\Models\User;
use App\Services\ProjectDeliverablesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for ProjectDeliverablesService (260822-CONTEXT.md D-01/D-02/D-03).
 *
 * Covers: setState() create-and-audit, setState() double-write audits every
 * explicit change (even a no-op), autoFlipIfNotRequired()'s soft-gate flip
 * and its negative (no audit on a non-flip), and setInitialStates()'s
 * single-transaction import-default seeding.
 */
class ProjectDeliverablesServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProjectDeliverablesService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProjectDeliverablesService();
    }

    // ── setState() ────────────────────────────────────────────────────────────

    public function test_set_state_creates_row_and_writes_manual_change_audit(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->create();

        $row = $this->service->setState(
            $project,
            ProjectDeliverable::KEY_SITE_SURVEY,
            ProjectDeliverable::STATE_REQUIRED,
            $user,
            'Services line found in quote',
        );

        $this->assertDatabaseCount('project_deliverables', 1);
        $this->assertSame(ProjectDeliverable::STATE_REQUIRED, $row->state);
        $this->assertSame($project->id, $row->project_id);
        $this->assertSame(ProjectDeliverable::KEY_SITE_SURVEY, $row->deliverable_key);

        $this->assertDatabaseCount('project_deliverable_audits', 1);

        $audit = ProjectDeliverableAudit::first();
        $this->assertSame($row->id, $audit->project_deliverable_id);
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame(ProjectDeliverableAudit::ACTION_MANUAL_CHANGE, $audit->action);
        $this->assertSame('Services line found in quote', $audit->reason);
        $this->assertSame(['state' => ProjectDeliverable::STATE_NOT_YET_DECIDED], $audit->before_snapshot);
        $this->assertSame(['state' => ProjectDeliverable::STATE_REQUIRED], $audit->after_snapshot);
    }

    public function test_set_state_called_twice_with_same_state_writes_second_audit_row(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->create();

        $this->service->setState($project, ProjectDeliverable::KEY_RAMS, ProjectDeliverable::STATE_REQUIRED, $user);
        $this->service->setState($project, ProjectDeliverable::KEY_RAMS, ProjectDeliverable::STATE_REQUIRED, $user);

        // Still exactly one deliverable row (unique project_id+deliverable_key).
        $this->assertDatabaseCount('project_deliverables', 1);

        // But every explicit write is audited — even a confirm-no-change call.
        $this->assertDatabaseCount('project_deliverable_audits', 2);
    }

    // ── autoFlipIfNotRequired() ──────────────────────────────────────────────

    public function test_auto_flip_flips_not_required_to_required_with_audit(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->create();

        $this->service->setState($project, ProjectDeliverable::KEY_OM, ProjectDeliverable::STATE_NOT_REQUIRED, $user);

        $this->service->autoFlipIfNotRequired($project, ProjectDeliverable::KEY_OM, $user);

        $row = ProjectDeliverable::where('project_id', $project->id)
            ->where('deliverable_key', ProjectDeliverable::KEY_OM)
            ->first();
        $this->assertSame(ProjectDeliverable::STATE_REQUIRED, $row->state);

        // 1 audit from setState + 1 from the auto-flip.
        $this->assertDatabaseCount('project_deliverable_audits', 2);

        $flipAudit = ProjectDeliverableAudit::where('action', ProjectDeliverableAudit::ACTION_AUTO_FLIP)->first();
        $this->assertNotNull($flipAudit);
        $this->assertNotNull($flipAudit->reason);
        $this->assertStringContainsString(ProjectDeliverable::KEY_OM, $flipAudit->reason);
    }

    public function test_auto_flip_on_required_deliverable_is_a_no_op(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->create();

        $this->service->setState($project, ProjectDeliverable::KEY_WORKSHEET, ProjectDeliverable::STATE_REQUIRED, $user);

        $this->service->autoFlipIfNotRequired($project, ProjectDeliverable::KEY_WORKSHEET, $user);

        $row = ProjectDeliverable::where('project_id', $project->id)
            ->where('deliverable_key', ProjectDeliverable::KEY_WORKSHEET)
            ->first();
        $this->assertSame(ProjectDeliverable::STATE_REQUIRED, $row->state);

        // Only the original setState audit — no auto-flip audit written.
        $this->assertDatabaseCount('project_deliverable_audits', 1);
        $this->assertDatabaseMissing('project_deliverable_audits', [
            'action' => ProjectDeliverableAudit::ACTION_AUTO_FLIP,
        ]);
    }

    public function test_auto_flip_on_not_yet_decided_deliverable_is_a_no_op(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->create();

        // No prior setState() call — deliverable has no row at all yet.
        $this->service->autoFlipIfNotRequired($project, ProjectDeliverable::KEY_DRAWINGS, $user);

        $this->assertDatabaseCount('project_deliverables', 0);
        $this->assertDatabaseCount('project_deliverable_audits', 0);
    }

    // ── setInitialStates() ────────────────────────────────────────────────────

    public function test_set_initial_states_writes_one_row_and_one_import_default_audit_per_key(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->create();

        $this->service->setInitialStates($project, [
            ProjectDeliverable::KEY_SITE_SURVEY => ProjectDeliverable::STATE_NOT_REQUIRED,
            ProjectDeliverable::KEY_RAMS => ProjectDeliverable::STATE_NOT_REQUIRED,
            ProjectDeliverable::KEY_WORKSHEET => ProjectDeliverable::STATE_NOT_REQUIRED,
        ], $user);

        $this->assertDatabaseCount('project_deliverables', 3);
        $this->assertDatabaseCount('project_deliverable_audits', 3);

        $this->assertDatabaseHas('project_deliverables', [
            'project_id' => $project->id,
            'deliverable_key' => ProjectDeliverable::KEY_SITE_SURVEY,
            'state' => ProjectDeliverable::STATE_NOT_REQUIRED,
        ]);

        $count = ProjectDeliverableAudit::where('action', ProjectDeliverableAudit::ACTION_IMPORT_DEFAULT)->count();
        $this->assertSame(3, $count);
    }
}
