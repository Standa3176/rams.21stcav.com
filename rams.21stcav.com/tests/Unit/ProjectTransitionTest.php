<?php

namespace Tests\Unit;

use App\Models\Project;
use App\Models\ProjectDeliverable;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Project::canTransitionTo() — bidirectional state machine.
 *
 * These tests do NOT require database access — they exercise the pure logic
 * of the transition constants and canTransitionTo() helper method.
 */
class ProjectTransitionTest extends TestCase
{
    // ── Forward transitions ───────────────────────────────────────────────────

    public function test_can_transition_forward_from_quote_imported(): void
    {
        $project = new Project(['status' => Project::STATUS_QUOTE_IMPORTED]);

        $this->assertTrue($project->canTransitionTo(Project::STATUS_SURVEY_PENDING));
    }

    public function test_can_transition_forward_from_survey_pending(): void
    {
        $project = new Project(['status' => Project::STATUS_SURVEY_PENDING]);

        $this->assertTrue($project->canTransitionTo(Project::STATUS_ENGINEERING));
    }

    public function test_can_transition_forward_through_full_lifecycle(): void
    {
        $lifecycle = [
            Project::STATUS_QUOTE_IMPORTED => Project::STATUS_SURVEY_PENDING,
            Project::STATUS_SURVEY_PENDING => Project::STATUS_ENGINEERING,
            Project::STATUS_ENGINEERING    => Project::STATUS_INSTALLING,
            Project::STATUS_INSTALLING     => Project::STATUS_COMMISSIONING,
            Project::STATUS_COMMISSIONING  => Project::STATUS_HANDOVER,
            Project::STATUS_HANDOVER       => Project::STATUS_COMPLETED,
        ];

        foreach ($lifecycle as $from => $to) {
            $project = new Project(['status' => $from]);
            $this->assertTrue(
                $project->canTransitionTo($to),
                "Expected transition from {$from} to {$to} to be allowed"
            );
        }
    }

    // ── Backward transitions ──────────────────────────────────────────────────

    public function test_can_transition_backward_from_engineering_to_survey_pending(): void
    {
        $project = new Project(['status' => Project::STATUS_ENGINEERING]);

        $this->assertTrue($project->canTransitionTo(Project::STATUS_SURVEY_PENDING));
    }

    public function test_can_transition_backward_from_installing_to_engineering(): void
    {
        $project = new Project(['status' => Project::STATUS_INSTALLING]);

        $this->assertTrue($project->canTransitionTo(Project::STATUS_ENGINEERING));
    }

    public function test_can_transition_backward_from_commissioning_to_installing(): void
    {
        $project = new Project(['status' => Project::STATUS_COMMISSIONING]);

        $this->assertTrue($project->canTransitionTo(Project::STATUS_INSTALLING));
    }

    public function test_can_transition_backward_from_survey_pending_to_quote_imported(): void
    {
        $project = new Project(['status' => Project::STATUS_SURVEY_PENDING]);

        $this->assertTrue($project->canTransitionTo(Project::STATUS_QUOTE_IMPORTED));
    }

    // ── Invalid transitions ───────────────────────────────────────────────────

    public function test_cannot_transition_to_invalid_state(): void
    {
        $project = new Project(['status' => Project::STATUS_QUOTE_IMPORTED]);

        $this->assertFalse($project->canTransitionTo('nonexistent_status'));
    }

    /**
     * D-11 (260822-esf) — quote_imported -> engineering is valid ONLY when
     * the project's Site Survey deliverable is explicitly Not required. This
     * REPLACES the old test_cannot_transition_to_completely_skipped_state,
     * which asserted this transition was unconditionally false — the exact
     * opposite of D-11. The first case below preserves that original
     * assertion (relation not loaded == safe default == still false) so a
     * regression that makes the guard fail OPEN is still caught.
     */
    public function test_engineering_skip_from_quote_imported_requires_explicit_survey_not_required(): void
    {
        // No `deliverables` relation loaded at all — safe default, matches
        // every other existing test in this file that never sets the
        // relation. Must stay false (this is the original pinned behaviour).
        $project = new Project(['status' => Project::STATUS_QUOTE_IMPORTED]);
        $this->assertFalse($project->canTransitionTo(Project::STATUS_ENGINEERING));
    }

    public function test_engineering_skip_allowed_when_survey_explicitly_not_required(): void
    {
        $project = new Project(['status' => Project::STATUS_QUOTE_IMPORTED]);
        $project->setRelation('deliverables', new Collection([
            new ProjectDeliverable([
                'deliverable_key' => ProjectDeliverable::KEY_SITE_SURVEY,
                'state'           => ProjectDeliverable::STATE_NOT_REQUIRED,
            ]),
        ]));

        $this->assertTrue($project->canTransitionTo(Project::STATUS_ENGINEERING));
    }

    public function test_engineering_skip_blocked_when_survey_is_required(): void
    {
        $project = new Project(['status' => Project::STATUS_QUOTE_IMPORTED]);
        $project->setRelation('deliverables', new Collection([
            new ProjectDeliverable([
                'deliverable_key' => ProjectDeliverable::KEY_SITE_SURVEY,
                'state'           => ProjectDeliverable::STATE_REQUIRED,
            ]),
        ]));

        // Wave-0-mandated guard: the skip must stay invalid when Survey IS
        // required — this is the case that would catch an "always allowed"
        // regression (e.g. folding the branch into TRANSITIONS unconditionally).
        $this->assertFalse($project->canTransitionTo(Project::STATUS_ENGINEERING));
    }

    public function test_engineering_skip_blocked_when_deliverables_loaded_but_empty(): void
    {
        // Relation IS loaded but has no row for site_survey — absence of an
        // explicit row is "not_yet_decided", not "not_required". Must not
        // accidentally legalise the skip for every project with no
        // deliverables record yet (e.g. everything pre-dating this phase).
        $project = new Project(['status' => Project::STATUS_QUOTE_IMPORTED]);
        $project->setRelation('deliverables', new Collection());

        $this->assertFalse($project->canTransitionTo(Project::STATUS_ENGINEERING));
    }

    public function test_archived_has_no_forward_transition(): void
    {
        $project = new Project(['status' => Project::STATUS_ARCHIVED]);

        $this->assertFalse($project->canTransitionTo(Project::STATUS_COMPLETED));
    }

    public function test_archived_cannot_transition_to_any_state(): void
    {
        $project = new Project(['status' => Project::STATUS_ARCHIVED]);

        $nonArchivedStates = [
            Project::STATUS_QUOTE_IMPORTED,
            Project::STATUS_SURVEY_PENDING,
            Project::STATUS_ENGINEERING,
            Project::STATUS_INSTALLING,
            Project::STATUS_COMMISSIONING,
            Project::STATUS_HANDOVER,
            Project::STATUS_COMPLETED,
        ];

        foreach ($nonArchivedStates as $state) {
            $this->assertFalse(
                $project->canTransitionTo($state),
                "Expected archived project to not be able to transition to {$state}"
            );
        }
    }

    public function test_archiving_is_always_available_from_non_archived_status(): void
    {
        $activeStates = [
            Project::STATUS_QUOTE_IMPORTED,
            Project::STATUS_SURVEY_PENDING,
            Project::STATUS_ENGINEERING,
            Project::STATUS_INSTALLING,
            Project::STATUS_COMMISSIONING,
            Project::STATUS_HANDOVER,
            Project::STATUS_COMPLETED,
        ];

        foreach ($activeStates as $state) {
            $project = new Project(['status' => $state]);
            $this->assertTrue(
                $project->canTransitionTo(Project::STATUS_ARCHIVED),
                "Expected project in {$state} to be able to archive"
            );
        }
    }

    // ── scopeNotArchived ──────────────────────────────────────────────────────

    public function test_status_labels_contains_all_statuses(): void
    {
        $allStatuses = Project::LIFECYCLE;

        foreach ($allStatuses as $status) {
            $this->assertArrayHasKey(
                $status,
                Project::STATUS_LABELS,
                "STATUS_LABELS is missing label for: {$status}"
            );
        }
    }

    public function test_transitions_backward_constant_is_defined(): void
    {
        $this->assertTrue(
            defined(Project::class . '::TRANSITIONS_BACKWARD'),
            'Project::TRANSITIONS_BACKWARD constant must be defined'
        );
    }

    public function test_transitions_backward_covers_expected_states(): void
    {
        $backward = Project::TRANSITIONS_BACKWARD;

        // Survey pending should allow going back to quote_imported
        $this->assertArrayHasKey(Project::STATUS_SURVEY_PENDING, $backward);
        $this->assertContains(Project::STATUS_QUOTE_IMPORTED, $backward[Project::STATUS_SURVEY_PENDING]);

        // Engineering should allow going back to survey_pending
        $this->assertArrayHasKey(Project::STATUS_ENGINEERING, $backward);
        $this->assertContains(Project::STATUS_SURVEY_PENDING, $backward[Project::STATUS_ENGINEERING]);
    }
}
