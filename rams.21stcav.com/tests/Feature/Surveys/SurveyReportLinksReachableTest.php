<?php

namespace Tests\Feature\Surveys;

use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 46.6, Plan 06, Task 1b — added 2026-10-01 after 46.6-04 shipped.
 *
 * 46.6-04 created `site-surveys.completed-report` and
 * `site-surveys.completed-report.xlsx` but added no UI link to either — a
 * grep for "completed-report" across resources/views/ returned nothing. That
 * is exactly the condition 47-CONTEXT.md records about the pre-existing,
 * still-orphaned `site-surveys.client-report` ("the only client-facing
 * survey PDF, currently reachable from nothing. Surface it; never delete
 * it."). This test proves all THREE report links are reachable from the
 * real rendered edit page, not merely that their routes exist
 * (`Route::has(...)` was already true and is precisely what left them
 * unreachable).
 *
 * @see resources/views/site-survey/edit.blade.php (the three report links)
 * @see .planning/phases/46.6-survey-review-completed-report/46.6-06-PLAN.md (Task 1b)
 */
class SurveyReportLinksReachableTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_rendered_edit_page_carries_all_three_report_links(): void
    {
        $user = User::factory()->create();
        $survey = $this->makeSurveyWithRoom();

        $response = $this->actingAs($user)->get(route('site-surveys.edit', $survey));

        $response->assertOk();
        $html = $response->getContent();

        $pdfUrl = route('site-surveys.completed-report', $survey);
        $xlsxUrl = route('site-surveys.completed-report.xlsx', $survey);
        $clientReportUrl = route('site-surveys.client-report', $survey);

        $this->assertStringContainsString(
            $pdfUrl,
            $html,
            'The completed-report PDF URL must appear on the rendered edit page.'
        );
        $this->assertStringContainsString(
            $xlsxUrl,
            $html,
            'The completed-report .xlsx URL must appear on the rendered edit page.'
        );
        $this->assertStringContainsString(
            $clientReportUrl,
            $html,
            'The pre-existing, previously-orphaned client-report URL must now appear on the rendered edit page.'
        );
    }

    public function test_the_three_links_are_plain_get_anchors_not_nested_inside_survey_form(): void
    {
        $user = User::factory()->create();
        $survey = $this->makeSurveyWithRoom();

        $html = $this->actingAs($user)
            ->get(route('site-surveys.edit', $survey))
            ->getContent();

        // HTML forbids nested forms — survey-form must close before any of
        // the three report links appear.
        $formOpenPos = strpos($html, 'id="survey-form"');
        $formClosePos = strpos($html, '</form>', $formOpenPos);

        $pdfUrl = route('site-surveys.completed-report', $survey);
        $xlsxUrl = route('site-surveys.completed-report.xlsx', $survey);
        $clientReportUrl = route('site-surveys.client-report', $survey);

        $pdfPos = strpos($html, $pdfUrl);
        $xlsxPos = strpos($html, $xlsxUrl);
        $clientReportPos = strpos($html, $clientReportUrl);

        $this->assertNotFalse($formOpenPos);
        $this->assertNotFalse($formClosePos);
        $this->assertNotFalse($pdfPos);
        $this->assertNotFalse($xlsxPos);
        $this->assertNotFalse($clientReportPos);

        $this->assertGreaterThan($formClosePos, $pdfPos, 'The PDF link must sit after survey-form closes.');
        $this->assertGreaterThan($formClosePos, $xlsxPos, 'The .xlsx link must sit after survey-form closes.');
        $this->assertGreaterThan($formClosePos, $clientReportPos, 'The client-report link must sit after survey-form closes.');

        // These are GET downloads — plain anchors, never a <form method="POST">.
        $this->assertStringContainsString('<a ', substr($html, $pdfPos - 200, 200) . substr($html, $pdfPos, 50));
    }

    public function test_internal_and_client_facing_labels_are_unambiguous(): void
    {
        $user = User::factory()->create();
        $survey = $this->makeSurveyWithRoom();

        $html = $this->actingAs($user)
            ->get(route('site-surveys.edit', $survey))
            ->getContent();

        // D-05: the completed report is "typically for internal use";
        // client-report is the client-facing one. Getting them the wrong
        // way round would send an internal document to a client.
        $this->assertStringContainsString('Internal', $html);
        $this->assertStringContainsString('Client', $html);
    }

    public function test_following_the_completed_report_xlsx_link_returns_a_non_error_response(): void
    {
        // The .xlsx link is followed rather than the PDF link because its
        // generation path (PhpSpreadsheet only) has no Chromium/Browsershot
        // dependency on the test runner — the same reason
        // CompletedSurveyReportTest's own xlsx assertions don't invoke
        // Browsershot either. This proves a REAL non-error response, not
        // merely that the route resolves.
        $user = User::factory()->create();
        $survey = $this->makeSurveyWithRoom();

        $response = $this->actingAs($user)->get(route('site-surveys.completed-report.xlsx', $survey));

        $response->assertStatus(200);
        $this->assertStringContainsString(
            'spreadsheetml',
            (string) $response->headers->get('content-type'),
            'A successful .xlsx download must carry a spreadsheet content-type, not a redirect-back error.'
        );
    }

    // Helpers ------------------------------------------------------------------

    private function makeSurveyWithRoom(): SiteSurvey
    {
        $project = Project::factory()->create();

        $survey = SiteSurvey::create([
            'user_id'      => $project->user_id ?? User::factory()->create()->id,
            'project_id'   => $project->id,
            'project_name' => $project->name,
            'survey_date'  => '2026-04-01',
        ]);

        $survey->rooms()->create([
            'room_name'  => 'Comms Room',
            'sort_order' => 0,
            'work_type'  => 'new_install',
            'space_type' => 'general',
            'notes'      => 'Door needs a new lock before install day.',
        ]);

        return $survey;
    }
}
