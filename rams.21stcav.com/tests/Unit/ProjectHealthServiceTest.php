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
use Illuminate\Support\Facades\DB;
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

    /**
     * MUST NOT query — the class docblock states this contract explicitly.
     * 260823-bcm added a new D-13 code path (the `deliverables` relation
     * eager-loaded, iterated, and read for `undecided_since`), so this
     * proves — with an actual query-count assertion via DB::listen(), not
     * by reading the code — that path still never touches the database.
     * Exercises a representative sweep of assess() call shapes: deliverables
     * present/absent, every D-13 branch (skip-Programming, null
     * undecided_since, aged undecided_since, in-grace undecided_since), plus
     * every RED/AMBER/GREEN branch above it in priority order.
     */
    public function test_assess_never_issues_a_database_query(): void
    {
        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $projects = [
            $this->makeProject(Project::STATUS_QUOTE_IMPORTED),
            $this->withRams(
                $this->makeProject(Project::STATUS_ENGINEERING, ['engineering_started_at' => Carbon::now()->subDays(2)]),
                RamsDocument::STATUS_FAILED,
            ),
            $this->withDeliverables(
                $this->makeProject(Project::STATUS_QUOTE_IMPORTED),
                [$this->makeDeliverable(ProjectDeliverable::KEY_PROGRAMMING, ProjectDeliverable::STATE_NOT_YET_DECIDED, Carbon::now()->subDays(30), undecidedSince: Carbon::now()->subDays(30))],
            ),
            $this->withDeliverables(
                $this->makeProject(Project::STATUS_QUOTE_IMPORTED),
                [$this->makeDeliverable(ProjectDeliverable::KEY_WORKSHEET, ProjectDeliverable::STATE_NOT_YET_DECIDED, Carbon::now()->subDays(365), undecidedSince: null)],
            ),
            $this->withDeliverables(
                $this->makeProject(Project::STATUS_QUOTE_IMPORTED),
                [$this->makeDeliverable(ProjectDeliverable::KEY_WORKSHEET, ProjectDeliverable::STATE_NOT_YET_DECIDED, Carbon::now()->subDays(10))],
            ),
            $this->withDeliverables(
                $this->makeProject(Project::STATUS_QUOTE_IMPORTED),
                [$this->makeDeliverable(ProjectDeliverable::KEY_WORKSHEET, ProjectDeliverable::STATE_NOT_YET_DECIDED, Carbon::now()->subDays(2))],
            ),
        ];

        foreach ($projects as $project) {
            $this->service->assess($project);
        }

        $this->assertSame(0, $queryCount, 'ProjectHealthService::assess() must never issue a database query.');
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
    // 260823-bcm: the clock anchors to undecided_since (set only via a real
    // decision path), never created_at, and Programming is excluded entirely
    // (see the new tests below for those two behaviours specifically).

    public function test_amber_when_deliverable_undecided_past_grace_period(): void
    {
        // Nothing else wrong — quote_imported has no stage-duration milestone,
        // so this isolates the D-13 rule. Uses a non-Programming key since
        // Programming is permanently excluded from this rule (260823-bcm).
        $project = $this->makeProject(Project::STATUS_QUOTE_IMPORTED);
        $project->setRelation('deliverables', collect([
            $this->makeDeliverable(
                ProjectDeliverable::KEY_WORKSHEET,
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
                ProjectDeliverable::KEY_WORKSHEET,
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
                ProjectDeliverable::KEY_WORKSHEET,
                ProjectDeliverable::STATE_NOT_YET_DECIDED,
                Carbon::now()->subDays(10)
            ),
        ]));

        $result = $this->service->assess($project);

        $this->assertSame('red', $result->status);
        $this->assertSame('RAMS document failed', $result->reason);
    }

    // ── 260823-bcm: grandfathered (backfilled) rows never go amber ─────────────

    public function test_green_when_undecided_since_is_null_no_matter_how_old_created_at_is(): void
    {
        // Simulates a D-17 retrofit row: state=not_yet_decided,
        // undecided_since=null, but created_at is ancient (well past the
        // grace period). Before this fix, D-13 anchored to created_at and
        // this would go amber — that is exactly the "100% of the project
        // list goes amber on day 7" defect this quick task fixes.
        $project = $this->makeProject(Project::STATUS_QUOTE_IMPORTED);
        $project->setRelation('deliverables', collect([
            $this->makeDeliverable(
                ProjectDeliverable::KEY_WORKSHEET,
                ProjectDeliverable::STATE_NOT_YET_DECIDED,
                Carbon::now()->subDays(365),
                undecidedSince: null,
            ),
        ]));

        $result = $this->service->assess($project);

        $this->assertSame('green', $result->status);
    }

    // ── 260823-bcm: Programming is permanently excluded from D-13 ──────────────

    public function test_programming_never_triggers_amber_even_when_ancient_and_explicitly_undecided(): void
    {
        // Programming (KEY_PROGRAMMING) has no model/table/relation anywhere
        // (D-05) — no evidence can ever move it off not_yet_decided, so it
        // must never trip D-13, even with a genuine (non-null) undecided_since
        // far past the grace period — the strongest possible case for amber,
        // and it must still be green.
        $project = $this->makeProject(Project::STATUS_QUOTE_IMPORTED);
        $project->setRelation('deliverables', collect([
            $this->makeDeliverable(
                ProjectDeliverable::KEY_PROGRAMMING,
                ProjectDeliverable::STATE_NOT_YET_DECIDED,
                Carbon::now()->subDays(10),
                undecidedSince: Carbon::now()->subDays(10),
            ),
        ]));

        $result = $this->service->assess($project);

        $this->assertSame('green', $result->status);
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

    /**
     * @param  Carbon|false|null  $undecidedSince  false (default, not passed)
     *                                              means "anchor to $createdAt",
     *                                              matching this rule's normal
     *                                              real-world shape where a row's
     *                                              undecided_since and created_at
     *                                              line up. Pass an explicit
     *                                              `null` to simulate a
     *                                              grandfathered (backfilled)
     *                                              row, or an explicit Carbon to
     *                                              set a different clock anchor
     *                                              than created_at.
     */
    private function makeDeliverable(
        string $key,
        string $state,
        ?Carbon $createdAt = null,
        Carbon|false|null $undecidedSince = false,
    ): ProjectDeliverable {
        $deliverable = new ProjectDeliverable(['deliverable_key' => $key, 'state' => $state]);
        $deliverable->deliverable_key = $key;
        $deliverable->state           = $state;
        $deliverable->created_at      = $createdAt ?? Carbon::now();
        $deliverable->undecided_since = $undecidedSince === false ? $deliverable->created_at : $undecidedSince;

        return $deliverable;
    }

    private function withRams(Project $project, string $status): Project
    {
        $project->setRelation('ramsDocuments', collect([$this->makeRams($status)]));

        return $project;
    }

    /** @param  ProjectDeliverable[]  $deliverables */
    private function withDeliverables(Project $project, array $deliverables): Project
    {
        $project->setRelation('deliverables', collect($deliverables));

        return $project;
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
