<?php

namespace Tests\Feature\Surveys;

use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\SiteSurveyRoom;
use App\Models\SurveyReportSection;
use App\Models\SurveyVariation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 46.6, Plan 02 — the save endpoint and the office review panel.
 *
 * D-03/D-08: the office writes four named report sections, append-only,
 * attributed, and the engineer's own captured record (room notes, survey
 * variations) is proven untouched by every save this panel makes.
 *
 * @see app/Http/Controllers/SiteSurveyController.php (saveReportSection)
 * @see resources/views/site-survey/edit.blade.php (Completed Report panel)
 * @see .planning/phases/46.6-survey-review-completed-report/46.6-CONTEXT.md (D-03, D-08)
 */
class SurveyReportSectionReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_a_report_section_creates_exactly_one_attributed_row(): void
    {
        $user = User::factory()->create();
        $survey = $this->makeSurvey();

        $response = $this->actingAs($user)->post(
            route('site-surveys.report-sections.store', $survey),
            ['section' => 'points_raised', 'body' => 'Loose trunking in the comms room.']
        );

        $response->assertRedirect(route('site-surveys.edit', $survey));

        $this->assertSame(1, SurveyReportSection::where('site_survey_id', $survey->id)->count());

        $row = SurveyReportSection::where('site_survey_id', $survey->id)->first();
        $this->assertSame('points_raised', $row->section);
        $this->assertSame('Loose trunking in the comms room.', $row->body);
        $this->assertSame($user->id, $row->user_id);
        $this->assertNotNull($row->created_at);
    }

    public function test_a_guest_is_bounced_to_login_exactly_as_the_sibling_download_routes_are(): void
    {
        $survey = $this->makeSurvey();

        // Same unauthenticated behaviour as the existing sibling endpoints
        // that share authorizeSurvey() — the `auth` middleware wrapping the
        // whole site-surveys route group bounces a guest to login before the
        // controller is ever reached.
        $clientReportResponse = $this->get(route('site-surveys.client-report', $survey));
        $variationsCsvResponse = $this->get(route('site-surveys.variations.csv', $survey));
        $reportSectionResponse = $this->post(
            route('site-surveys.report-sections.store', $survey),
            ['section' => 'points_raised', 'body' => 'Should never be written.']
        );

        $clientReportResponse->assertRedirect(route('login'));
        $variationsCsvResponse->assertRedirect(route('login'));
        $reportSectionResponse->assertRedirect(route('login'));

        $this->assertSame(0, SurveyReportSection::where('site_survey_id', $survey->id)->count());
    }

    public function test_an_empty_body_is_rejected_and_writes_no_row(): void
    {
        $user = User::factory()->create();
        $survey = $this->makeSurvey();

        $response = $this->actingAs($user)->post(
            route('site-surveys.report-sections.store', $survey),
            ['section' => 'items_agreed', 'body' => '']
        );

        $response->assertSessionHasErrors('body');
        $this->assertSame(0, SurveyReportSection::where('site_survey_id', $survey->id)->count());
    }

    public function test_two_saves_to_the_same_section_leave_both_rows_and_the_edit_page_shows_the_latest(): void
    {
        $user = User::factory()->create();
        $survey = $this->makeSurvey();

        $this->actingAs($user)->post(
            route('site-surveys.report-sections.store', $survey),
            ['section' => 'additional_comments', 'body' => 'First pass: client wants an evening install.']
        );
        $this->actingAs($user)->post(
            route('site-surveys.report-sections.store', $survey),
            ['section' => 'additional_comments', 'body' => 'Revised: client confirmed evening install, 6pm start.']
        );

        $this->assertSame(
            2,
            SurveyReportSection::where('site_survey_id', $survey->id)
                ->where('section', 'additional_comments')
                ->count()
        );

        $html = $this->actingAs($user)
            ->get(route('site-surveys.edit', $survey))
            ->getContent();

        $this->assertStringContainsString('Revised: client confirmed evening install, 6pm start.', $html);
        $this->assertStringNotContainsString('First pass: client wants an evening install.', $html);
    }

    public function test_the_edit_page_renders_engineer_capture_read_only_and_a_save_never_changes_it(): void
    {
        $user = User::factory()->create();
        $survey = $this->makeSurvey();

        $room = $survey->rooms()->create([
            'room_name'  => 'Comms Room',
            'sort_order' => 0,
            'notes'      => "Door won't lock, flagged to site contact on arrival.",
        ]);

        $variation = SurveyVariation::create([
            'site_survey_id' => $survey->id,
            'room_name'      => 'Comms Room',
            'type'           => 'access_issue',
            'description'    => 'No key held for the plant room door.',
        ]);

        $html = $this->actingAs($user)->get(route('site-surveys.edit', $survey))->getContent();

        $this->assertStringContainsString(
            "Door won&#039;t lock, flagged to site contact on arrival.",
            $html,
            'Engineer room notes must render read-only, escaped, for office context.'
        );
        $this->assertStringContainsString(
            'No key held for the plant room door.',
            $html,
            'Flagged access_issue/other variations must render read-only, for office context.'
        );

        // THE NON-OVERWRITE PROOF: posting a new report-section body must
        // never change the engineer's own room notes or variation record.
        $this->actingAs($user)->post(
            route('site-surveys.report-sections.store', $survey),
            ['section' => 'points_raised', 'body' => 'Office note about the comms room access issue.']
        );

        $this->assertSame(
            "Door won't lock, flagged to site contact on arrival.",
            $room->fresh()->notes,
            "The engineer's own room note must be byte-identical after an office save."
        );
        $this->assertSame(
            'No key held for the plant room door.',
            $variation->fresh()->description,
            "The engineer's own variation description must be byte-identical after an office save."
        );
    }

    /**
     * THE REASON THIS TABLE EXISTS: a survey with no Visit wrapping it
     * (VisitReworkState::forSurvey() returns null) must still accept and
     * return its sections — the edit page, the save endpoint, and
     * latestFor() all have to work with zero Visit rows anywhere.
     */
    public function test_a_survey_with_no_visit_wrapping_it_still_accepts_and_renders_report_sections(): void
    {
        $user = User::factory()->create();
        $survey = $this->makeSurvey();

        $this->assertNull(\App\Support\Visits\VisitReworkState::forSurvey($survey));
        $this->assertSame(0, \App\Models\Visit::where('source_id', $survey->id)->count());

        $response = $this->actingAs($user)->post(
            route('site-surveys.report-sections.store', $survey),
            ['section' => 'client_items_required', 'body' => 'Client to supply a 4-way extension lead.']
        );
        $response->assertRedirect(route('site-surveys.edit', $survey));

        $this->assertSame(1, SurveyReportSection::where('site_survey_id', $survey->id)->count());

        $html = $this->actingAs($user)->get(route('site-surveys.edit', $survey))->getContent();

        $this->assertStringContainsString('Client to supply a 4-way extension lead.', $html);
    }

    // Helpers ----------------------------------------------------------------

    private function makeSurvey(): SiteSurvey
    {
        $project = Project::factory()->create();

        return SiteSurvey::create([
            'user_id'      => $project->user_id ?? User::factory()->create()->id,
            'project_id'   => $project->id,
            'project_name' => $project->name,
            'survey_date'  => '2026-04-01',
        ]);
    }
}
