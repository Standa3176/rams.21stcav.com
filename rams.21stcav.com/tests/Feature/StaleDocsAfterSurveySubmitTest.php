<?php

namespace Tests\Feature;

use App\Events\SurveySubmitted;
use App\Models\CableSchedule;
use App\Models\OmManual;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\RamsDocument;
use App\Models\SiteSurvey;
use App\Models\User;
use App\Models\Worksheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Quick task 260726-fx4 — Task 1 coverage.
 *
 * When a public engineer submits a site survey (SurveyService::submitPublic),
 * ALL four downstream document types must surface as stale so PMs know the
 * underlying scope has moved on and regeneration may be required.
 *
 * These tests verify the model-level isStale() signal directly (Blade-side
 * banner rendering is already covered by WorksheetStaleBannerTest et al).
 *
 * Also verifies the SurveySubmitted event fires — future listeners (Slack /
 * digest emails / drawings regen) subscribe here.
 */
class StaleDocsAfterSurveySubmitTest extends TestCase
{
    use RefreshDatabase;

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function makeProjectWithFreshDocs(): array
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        // Package edited well BEFORE all doc snapshots — so package-based
        // staleness cannot fire. Any staleness in these tests must come
        // from the newly-submitted survey.
        $package = ProjectPackage::create([
            'project_id' => $project->id,
            'user_id'    => $user->id,
            'filename'   => 'fixture-' . uniqid() . '.pdf',
        ]);
        DB::table('project_packages')
            ->where('id', $package->id)
            ->update(['updated_at' => now()->subHours(2)]);

        $generatedAt = now()->subMinutes(30)->toIso8601String();

        $worksheet = Worksheet::factory()->create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'status'         => Worksheet::STATUS_DRAFT,
            'generated_data' => [
                'rooms'        => [],
                'generated_at' => $generatedAt,
            ],
        ]);

        $om = OmManual::create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'project_name'   => $project->name,
            'status'         => OmManual::STATUS_DRAFT,
            'extracted_data' => ['rooms' => []],
            'generated_data' => ['generated_at' => $generatedAt],
        ]);

        $rams = RamsDocument::factory()->create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'status'         => RamsDocument::STATUS_COMPLETED,
            'generated_data' => ['generated_at' => $generatedAt],
        ]);

        // Cable schedule uses completion_email_sent_at as its "generated_at" proxy.
        $cable = CableSchedule::factory()->create([
            'user_id'                    => $user->id,
            'project_id'                 => $project->id,
            'status'                     => CableSchedule::STATUS_DRAFT,
            'completion_email_sent_at'   => now()->subMinutes(30),
        ]);

        return compact('user', 'project', 'package', 'worksheet', 'om', 'rams', 'cable');
    }

    // ── Baseline: without a survey submission, docs are fresh ────────────────

    public function test_all_docs_are_fresh_when_no_survey_submitted(): void
    {
        [
            'worksheet' => $worksheet,
            'om'        => $om,
            'rams'      => $rams,
            'cable'     => $cable,
        ] = $this->makeProjectWithFreshDocs();

        $this->assertFalse($worksheet->refresh()->isStale(), 'Worksheet should be fresh with no survey');
        $this->assertFalse($om->refresh()->isStale(),        'OmManual should be fresh with no survey');
        $this->assertFalse($rams->refresh()->isStale(),      'RamsDocument should be fresh with no survey');
        $this->assertFalse($cable->refresh()->isStale(),     'CableSchedule should be fresh with no survey');
    }

    // ── Fresh survey (not yet submitted) does NOT flip docs to stale ─────────

    public function test_in_progress_survey_without_submitted_at_does_not_trigger_stale(): void
    {
        $ctx = $this->makeProjectWithFreshDocs();

        // Engineer is editing the survey but hasn't submitted yet.
        SiteSurvey::create([
            'user_id'      => $ctx['user']->id,
            'project_id'   => $ctx['project']->id,
            'project_name' => $ctx['project']->name,
            'status'       => 'draft',
            'access_token' => (string) Str::uuid(),
            'submitted_at' => null,
        ]);

        $this->assertFalse($ctx['worksheet']->refresh()->isStale());
        $this->assertFalse($ctx['om']->refresh()->isStale());
        $this->assertFalse($ctx['rams']->refresh()->isStale());
        $this->assertFalse($ctx['cable']->refresh()->isStale());
    }

    // ── Survey submitted AFTER doc generation → all 4 docs turn stale ────────

    public function test_worksheet_is_stale_after_survey_submitted(): void
    {
        $ctx = $this->makeProjectWithFreshDocs();
        $this->submitSurveyForProject($ctx['project'], $ctx['user']);

        $this->assertTrue($ctx['worksheet']->refresh()->isStale());
        $this->assertNotNull($ctx['worksheet']->staleSince());
    }

    public function test_om_manual_is_stale_after_survey_submitted(): void
    {
        $ctx = $this->makeProjectWithFreshDocs();
        $this->submitSurveyForProject($ctx['project'], $ctx['user']);

        $this->assertTrue($ctx['om']->refresh()->isStale());
        $this->assertNotNull($ctx['om']->staleSince());
    }

    public function test_rams_document_is_stale_after_survey_submitted(): void
    {
        $ctx = $this->makeProjectWithFreshDocs();
        $this->submitSurveyForProject($ctx['project'], $ctx['user']);

        $this->assertTrue($ctx['rams']->refresh()->isStale());
        $this->assertNotNull($ctx['rams']->staleSince());
    }

    public function test_cable_schedule_is_stale_after_survey_submitted(): void
    {
        $ctx = $this->makeProjectWithFreshDocs();
        $this->submitSurveyForProject($ctx['project'], $ctx['user']);

        $this->assertTrue($ctx['cable']->refresh()->isStale());
        $this->assertNotNull($ctx['cable']->staleSince());
    }

    // ── SurveySubmitted event dispatch ───────────────────────────────────────

    public function test_survey_submitted_event_is_dispatched_by_submit_public(): void
    {
        Event::fake([SurveySubmitted::class]);

        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $survey  = SiteSurvey::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => $project->name,
            'status'       => 'draft',
            'access_token' => (string) Str::uuid(),
        ]);

        app(\App\Core\Modules\Survey\SurveyService::class)->submitPublic($survey, []);

        Event::assertDispatched(
            SurveySubmitted::class,
            fn (SurveySubmitted $e) => $e->survey->id === $survey->id
        );
    }

    // ── Helper: submit a public survey via the service so the whole flow runs ─

    private function submitSurveyForProject(Project $project, User $user): SiteSurvey
    {
        $survey = SiteSurvey::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => $project->name,
            'status'       => 'draft',
            'access_token' => (string) Str::uuid(),
        ]);

        return app(\App\Core\Modules\Survey\SurveyService::class)->submitPublic($survey, []);
    }
}
