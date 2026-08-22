<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectDeliverable;
use App\Models\ProjectDeliverableAudit;
use App\Models\ProjectPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 260822-08 (D-15/D-16): end-to-end HTTP proof that the deliverables
 * interstitial is a real, distinct step wired all the way through to the
 * D-11 skip decided by Plan 02's Hook 1 — not just a unit-level write.
 *
 * Walks the actual request chain a browser takes:
 *   review.blade.php's form → POST quote-import.deliverables-step (renders
 *   the interstitial) → its own form → POST quote-import.confirm.
 */
class QuoteImportDeliverablesStepTest extends TestCase
{
    use RefreshDatabase;

    private function makeProjectAndPackage(User $user): array
    {
        $project = Project::create([
            'user_id'      => $user->id,
            'name'         => 'Step Flow Project',
            'client_name'  => 'Client Flow',
            'site_address' => '1 Flow Street',
            'status'       => Project::STATUS_QUOTE_IMPORTED,
        ]);

        $package = ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $user->id,
            'quote_filename' => 'flow-test.pdf',
            'quote_path'     => 'quote-imports/flow-test.pdf',
            'extracted_data' => [],
            'equipment_list' => [],
            'cable_list'     => [],
            'revision'       => 1,
            'status'         => ProjectPackage::STATUS_EXTRACTED,
        ]);

        return [$project, $package];
    }

    private function baseFields(Project $project): array
    {
        return [
            'name'         => 'Step Flow Project',
            'client_name'  => 'Client Flow',
            'site_address' => '1 Flow Street',
            'project_id'   => $project->id,
        ];
    }

    private function allNotYetDecided(): array
    {
        $states = [];
        foreach (ProjectDeliverable::ALL_KEYS as $key) {
            $states[$key] = ProjectDeliverable::STATE_NOT_YET_DECIDED;
        }
        return $states;
    }

    // ── Proof the interstitial is a real, distinct page in the request chain ──

    public function test_full_review_to_deliverables_step_to_confirm_walk(): void
    {
        $user = User::factory()->create();
        [$project, $package] = $this->makeProjectAndPackage($user);

        // Step 3: review page is reachable and its form now targets the
        // interstitial route, not confirm directly.
        $reviewResponse = $this->actingAs($user)->get(route('quote-import.review', $package));
        $reviewResponse->assertOk();
        $reviewResponse->assertSee(route('quote-import.deliverables-step', $package), false);

        // Step 3 -> Step 3.5: review's form posts here; the interstitial
        // renders as its own distinct page (own route, own view).
        $stepResponse = $this->actingAs($user)->post(
            route('quote-import.deliverables-step', $package),
            $this->baseFields($project),
        );
        $stepResponse->assertOk();
        $stepResponse->assertViewIs('quote-import.deliverables');
        $stepResponse->assertSee(route('quote-import.confirm', $package), false);

        // Step 3.5 -> Step 4: the interstitial's own form posts to confirm.
        $states = $this->allNotYetDecided();
        $states[ProjectDeliverable::KEY_SITE_SURVEY] = ProjectDeliverable::STATE_NOT_REQUIRED;

        $confirmResponse = $this->actingAs($user)->post(
            route('quote-import.confirm', $package),
            array_merge($this->baseFields($project), ['deliverables' => $states]),
        );
        $confirmResponse->assertRedirect(route('projects.show', $project));

        $this->assertDatabaseHas('project_deliverables', [
            'project_id'      => $project->id,
            'deliverable_key' => ProjectDeliverable::KEY_SITE_SURVEY,
            'state'           => ProjectDeliverable::STATE_NOT_REQUIRED,
        ]);
    }

    // ── D-11 integration: not-required Survey submitted on THIS page skips
    //    straight to engineering in the SAME request. ──────────────────────

    public function test_not_required_survey_on_interstitial_skips_straight_to_engineering(): void
    {
        $user = User::factory()->create();
        [$project, $package] = $this->makeProjectAndPackage($user);

        $states = $this->allNotYetDecided();
        $states[ProjectDeliverable::KEY_SITE_SURVEY] = ProjectDeliverable::STATE_NOT_REQUIRED;

        $response = $this->actingAs($user)->post(
            route('quote-import.confirm', $package),
            array_merge($this->baseFields($project), ['deliverables' => $states]),
        );

        $response->assertRedirect(route('projects.show', $project));

        $fresh = $project->fresh();
        $this->assertEquals(Project::STATUS_ENGINEERING, $fresh->status);
        $this->assertNotNull($fresh->engineering_started_at);
        $this->assertNull($fresh->survey_started_at);

        $this->assertDatabaseHas('project_deliverables', [
            'project_id'      => $project->id,
            'deliverable_key' => ProjectDeliverable::KEY_SITE_SURVEY,
            'state'           => ProjectDeliverable::STATE_NOT_REQUIRED,
        ]);
    }

    // ── Any other Survey state: normal survey_pending, unchanged from today ──

    public function test_required_survey_on_interstitial_still_advances_to_survey_pending(): void
    {
        $user = User::factory()->create();
        [$project, $package] = $this->makeProjectAndPackage($user);

        $states = $this->allNotYetDecided();
        $states[ProjectDeliverable::KEY_SITE_SURVEY] = ProjectDeliverable::STATE_REQUIRED;

        $response = $this->actingAs($user)->post(
            route('quote-import.confirm', $package),
            array_merge($this->baseFields($project), ['deliverables' => $states]),
        );

        $response->assertRedirect(route('projects.show', $project));

        $fresh = $project->fresh();
        $this->assertEquals(Project::STATUS_SURVEY_PENDING, $fresh->status);
        $this->assertNotNull($fresh->survey_started_at);
    }

    public function test_not_yet_decided_survey_on_interstitial_still_advances_to_survey_pending(): void
    {
        $user = User::factory()->create();
        [$project, $package] = $this->makeProjectAndPackage($user);

        $response = $this->actingAs($user)->post(
            route('quote-import.confirm', $package),
            array_merge($this->baseFields($project), ['deliverables' => $this->allNotYetDecided()]),
        );

        $response->assertRedirect(route('projects.show', $project));
        $this->assertEquals(Project::STATUS_SURVEY_PENDING, $project->fresh()->status);
    }

    // ── Validation: unknown deliverable key rejected with 422 ───────────────

    public function test_confirm_rejects_unknown_deliverable_key_with_422(): void
    {
        $user = User::factory()->create();
        [$project, $package] = $this->makeProjectAndPackage($user);

        $response = $this->actingAs($user)->postJson(
            route('quote-import.confirm', $package),
            array_merge($this->baseFields($project), [
                'deliverables' => ['not_a_real_key' => ProjectDeliverable::STATE_REQUIRED],
            ]),
        );

        $response->assertStatus(422);
        $this->assertDatabaseCount('project_deliverables', 0);
    }

    // ── Validation: out-of-enum state value rejected with 422 ───────────────

    public function test_confirm_rejects_out_of_enum_state_with_422(): void
    {
        $user = User::factory()->create();
        [$project, $package] = $this->makeProjectAndPackage($user);

        $states = $this->allNotYetDecided();
        $states[ProjectDeliverable::KEY_SITE_SURVEY] = 'definitely_maybe';

        $response = $this->actingAs($user)->postJson(
            route('quote-import.confirm', $package),
            array_merge($this->baseFields($project), ['deliverables' => $states]),
        );

        $response->assertStatus(422);
        $this->assertDatabaseCount('project_deliverables', 0);
    }

    // ── Validation: missing deliverables payload entirely rejected with 422 ──

    public function test_confirm_rejects_missing_deliverables_payload_with_422(): void
    {
        $user = User::factory()->create();
        [$project, $package] = $this->makeProjectAndPackage($user);

        $response = $this->actingAs($user)->postJson(
            route('quote-import.confirm', $package),
            $this->baseFields($project),
        );

        $response->assertStatus(422);
    }

    // ── Every deliverables write from this flow is audited ──────────────────

    public function test_deliverables_writes_are_audited_with_import_default_action(): void
    {
        $user = User::factory()->create();
        [$project, $package] = $this->makeProjectAndPackage($user);

        $states = $this->allNotYetDecided();
        $states[ProjectDeliverable::KEY_SITE_SURVEY] = ProjectDeliverable::STATE_NOT_REQUIRED;
        $states[ProjectDeliverable::KEY_RAMS]        = ProjectDeliverable::STATE_REQUIRED;

        $this->actingAs($user)->post(
            route('quote-import.confirm', $package),
            array_merge($this->baseFields($project), ['deliverables' => $states]),
        );

        $surveyRow = ProjectDeliverable::where('project_id', $project->id)
            ->where('deliverable_key', ProjectDeliverable::KEY_SITE_SURVEY)
            ->firstOrFail();

        $this->assertDatabaseHas('project_deliverable_audits', [
            'project_deliverable_id' => $surveyRow->id,
            'user_id'                => $user->id,
            'action'                 => ProjectDeliverableAudit::ACTION_IMPORT_DEFAULT,
        ]);

        $ramsRow = ProjectDeliverable::where('project_id', $project->id)
            ->where('deliverable_key', ProjectDeliverable::KEY_RAMS)
            ->firstOrFail();

        $this->assertDatabaseHas('project_deliverable_audits', [
            'project_deliverable_id' => $ramsRow->id,
            'user_id'                => $user->id,
            'action'                 => ProjectDeliverableAudit::ACTION_IMPORT_DEFAULT,
        ]);
    }
}
