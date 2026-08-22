<?php

namespace Tests\Unit;

use App\DTO\ProjectHealth;
use App\Models\Project;
use App\Models\ProjectDeliverable;
use App\Models\RamsDocument;
use App\Models\SiteSurvey;
use App\Services\ProjectHealthService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Unit tests for ProjectHealthService — covers DASH-01c / DASH-01d / DASH-01e.
 *
 * These tests do NOT require the database. They assemble a Project instance
 * in memory, set relation collections via setRelation(), and verify that
 * assess() derives the expected health status purely from already-loaded data.
 *
 * The service must never issue additional DB queries.
 */
class ProjectHealthServiceTest extends TestCase
{
    private ProjectHealthService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProjectHealthService();
    }

    // ── DTO contract ──────────────────────────────────────────────────────────

    public function test_returns_project_health_instance(): void
    {
        $project = $this->makeProject(Project::STATUS_QUOTE_IMPORTED);

        $result = $this->service->assess($project);

        $this->assertInstanceOf(ProjectHealth::class, $result);
        $this->assertContains($result->status, ['green', 'amber', 'red']);
    }

    // ── RED branches ──────────────────────────────────────────────────────────

    public function test_red_when_rams_failed(): void
    {
        $project = $this->makeProject(Project::STATUS_ENGINEERING, [
            'engineering_started_at' => Carbon::now()->subDays(2),
        ]);
        $project->setRelation('ramsDocuments', collect([
            $this->makeRams(RamsDocument::STATUS_FAILED),
        ]));

        $result = $this->service->assess($project);

        $this->assertSame('red', $result->status);
        $this->assertSame('RAMS document failed', $result->reason);
    }

    public function test_red_when_engineering_no_approved_rams(): void
    {
        $project = $this->makeProject(Project::STATUS_ENGINEERING, [
            'engineering_started_at' => Carbon::now()->subDays(3),
        ]);
        $project->setRelation('ramsDocuments', collect([
            $this->makeRams(RamsDocument::STATUS_UPLOADED),
        ]));

        $result = $this->service->assess($project);

        $this->assertSame('red', $result->status);
        $this->assertSame('No approved RAMS in engineering', $result->reason);
    }

    public function test_red_when_survey_overdue_no_submission(): void
    {
        $project = $this->makeProject(Project::STATUS_SURVEY_PENDING, [
            'survey_started_at' => Carbon::now()->subDays(20),
        ]);
        // One unsubmitted survey — submitted_at stays null.
        $unsubmitted = $this->makeSurvey(submitted: false);
        $project->setRelation('siteSurveys', collect([$unsubmitted]));

        $result = $this->service->assess($project);

        $this->assertSame('red', $result->status);
        $this->assertSame('Survey overdue — no submission', $result->reason);
        $this->assertTrue($result->overdue);
    }

    // ── AMBER branches ────────────────────────────────────────────────────────

    public function test_amber_when_stage_duration_exceeds_7_days(): void
    {
        $project = $this->makeProject(Project::STATUS_ENGINEERING, [
            'engineering_started_at' => Carbon::now()->subDays(10),
        ]);
        // An approved RAMS so we don't hit the RED "no approved RAMS" rule.
        $project->setRelation('ramsDocuments', collect([
            $this->makeRams(RamsDocument::STATUS_APPROVED),
        ]));

        $result = $this->service->assess($project);

        $this->assertSame('amber', $result->status);
        $this->assertSame('Stage duration > 7 days', $result->reason);
    }

    public function test_amber_when_rams_awaiting_review(): void
    {
        // Use commissioning + recent milestone so the "stage > 7 days" rule doesn't
        // fire first; this isolates the "RAMS awaiting review" amber trigger.
        $project = $this->makeProject(Project::STATUS_COMMISSIONING, [
            'commissioning_started_at' => Carbon::now()->subDays(1),
        ]);
        $project->setRelation('ramsDocuments', collect([
            $this->makeRams(RamsDocument::STATUS_AWAITING_REVIEW),
        ]));

        $result = $this->service->assess($project);

        $this->assertSame('amber', $result->status);
        $this->assertSame('RAMS awaiting review', $result->reason);
    }

    // ── GREEN branches ────────────────────────────────────────────────────────

    public function test_green_when_all_clear(): void
    {
        $project = $this->makeProject(Project::STATUS_ENGINEERING, [
            'engineering_started_at' => Carbon::now()->subDays(2),
        ]);
        $project->setRelation('ramsDocuments', collect([
            $this->makeRams(RamsDocument::STATUS_COMPLETED),
        ]));

        $result = $this->service->assess($project);

        $this->assertSame('green', $result->status);
        $this->assertSame('On track', $result->reason);
        $this->assertFalse($result->overdue);
    }

    // ── Overdue & null guards ─────────────────────────────────────────────────

    public function test_overdue_false_for_quote_imported(): void
    {
        // quote_imported has no mapped milestone column — overdue must be false
        // regardless of how old the project is.
        $project = $this->makeProject(Project::STATUS_QUOTE_IMPORTED);

        $result = $this->service->assess($project);

        $this->assertFalse($result->overdue);
        $this->assertSame('green', $result->status);
    }

    public function test_overdue_true_when_stage_older_than_14_days(): void
    {
        $project = $this->makeProject(Project::STATUS_ENGINEERING, [
            'engineering_started_at' => Carbon::now()->subDays(20),
        ]);
        // Completed RAMS so no RED trigger fires — we're isolating overdue flag.
        $project->setRelation('ramsDocuments', collect([
            $this->makeRams(RamsDocument::STATUS_COMPLETED),
        ]));

        $result = $this->service->assess($project);

        $this->assertTrue($result->overdue);
    }

    // ── Soft-delete guard ─────────────────────────────────────────────────────

    public function test_soft_deleted_rams_excluded(): void
    {
        // A soft-deleted RAMS with status=failed MUST NOT trigger the red rule.
        $project = $this->makeProject(Project::STATUS_ENGINEERING, [
            'engineering_started_at' => Carbon::now()->subDays(2),
        ]);

        $failed = $this->makeRams(RamsDocument::STATUS_FAILED);
        $failed->deleted_at = Carbon::now()->subDay();

        $live = $this->makeRams(RamsDocument::STATUS_COMPLETED);

        $project->setRelation('ramsDocuments', collect([$failed, $live]));

        $result = $this->service->assess($project);

        $this->assertSame('green', $result->status);
    }

    // ── D-12: not-required deliverables drop out of health entirely ────────────

    public function test_green_when_rams_not_required_and_engineering_with_no_rams(): void
    {
        // Same fixture shape as test_red_when_engineering_no_approved_rams, but
        // with RAMS explicitly marked not_required — the RED rule must not fire.
        $project = $this->makeProject(Project::STATUS_ENGINEERING, [
            'engineering_started_at' => Carbon::now()->subDays(2),
        ]);
        $project->setRelation('deliverables', collect([
            $this->makeDeliverable(ProjectDeliverable::KEY_RAMS, ProjectDeliverable::STATE_NOT_REQUIRED),
        ]));

        $result = $this->service->assess($project);

        $this->assertSame('green', $result->status);
        $this->assertSame('On track', $result->reason);
    }

    public function test_green_when_survey_not_required_and_overdue(): void
    {
        // Same fixture shape as test_red_when_survey_overdue_no_submission
        // (20 days overdue, zero submissions), but Site Survey is explicitly
        // not_required — the RED "Survey overdue" rule must not fire.
        $project = $this->makeProject(Project::STATUS_SURVEY_PENDING, [
            'survey_started_at' => Carbon::now()->subDays(20),
        ]);
        $project->setRelation('deliverables', collect([
            $this->makeDeliverable(ProjectDeliverable::KEY_SITE_SURVEY, ProjectDeliverable::STATE_NOT_REQUIRED),
        ]));

        $result = $this->service->assess($project);

        $this->assertNotSame('red', $result->status);
        $this->assertNotSame('Survey overdue — no submission', $result->reason);
    }

    // ── D-13: "Not yet decided" goes amber after the grace period ──────────────

    public function test_amber_when_deliverable_undecided_past_grace_period(): void
    {
        // Nothing else wrong — quote_imported has no stage-duration milestone,
        // so this isolates the D-13 rule.
        $project = $this->makeProject(Project::STATUS_QUOTE_IMPORTED);
        $project->setRelation('deliverables', collect([
            $this->makeDeliverable(
                ProjectDeliverable::KEY_PROGRAMMING,
                ProjectDeliverable::STATE_NOT_YET_DECIDED,
                Carbon::now()->subDays(10)
            ),
        ]));

        $result = $this->service->assess($project);

        $this->assertSame('amber', $result->status);
        $this->assertStringContainsString('Not yet decided', $result->reason);
    }

    public function test_green_when_deliverable_undecided_within_grace_period(): void
    {
        $project = $this->makeProject(Project::STATUS_QUOTE_IMPORTED);
        $project->setRelation('deliverables', collect([
            $this->makeDeliverable(
                ProjectDeliverable::KEY_PROGRAMMING,
                ProjectDeliverable::STATE_NOT_YET_DECIDED,
                Carbon::now()->subDays(2)
            ),
        ]));

        $result = $this->service->assess($project);

        $this->assertSame('green', $result->status);
    }

    public function test_red_still_wins_over_amber_grace_period(): void
    {
        // RAMS failed (existing RED-triggering fixture) PLUS an aged
        // not-yet-decided deliverable — RED must still win; D-13's amber
        // is last in priority order and must never mask it.
        $project = $this->makeProject(Project::STATUS_ENGINEERING, [
            'engineering_started_at' => Carbon::now()->subDays(2),
        ]);
        $project->setRelation('ramsDocuments', collect([
            $this->makeRams(RamsDocument::STATUS_FAILED),
        ]));
        $project->setRelation('deliverables', collect([
            $this->makeDeliverable(
                ProjectDeliverable::KEY_PROGRAMMING,
                ProjectDeliverable::STATE_NOT_YET_DECIDED,
                Carbon::now()->subDays(10)
            ),
        ]));

        $result = $this->service->assess($project);

        $this->assertSame('red', $result->status);
        $this->assertSame('RAMS document failed', $result->reason);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build an in-memory Project with pre-populated empty relations so assess()
     * never tries to lazy-load from the database.
     */
    private function makeProject(string $status, array $attributes = []): Project
    {
        $project = new Project(array_merge(['status' => $status], $attributes));

        // Some milestone columns are not fillable — assign directly so casts apply.
        foreach ($attributes as $key => $value) {
            $project->{$key} = $value;
        }
        $project->status = $status;

        // Default empty relations — tests override when they need non-empty ones.
        $project->setRelation('ramsDocuments', new Collection());
        $project->setRelation('siteSurveys', new Collection());
        $project->setRelation('deliverables', new Collection());

        return $project;
    }

    private function makeDeliverable(string $key, string $state, ?Carbon $createdAt = null): ProjectDeliverable
    {
        $deliverable = new ProjectDeliverable(['deliverable_key' => $key, 'state' => $state]);
        $deliverable->deliverable_key = $key;
        $deliverable->state           = $state;
        $deliverable->created_at      = $createdAt ?? Carbon::now();

        return $deliverable;
    }

    private function makeRams(string $status): RamsDocument
    {
        $rams = new RamsDocument(['status' => $status]);
        $rams->status     = $status;
        $rams->deleted_at = null;

        return $rams;
    }

    private function makeSurvey(bool $submitted): SiteSurvey
    {
        $survey = new SiteSurvey();
        $survey->submitted_at = $submitted ? Carbon::now() : null;

        return $survey;
    }
}
