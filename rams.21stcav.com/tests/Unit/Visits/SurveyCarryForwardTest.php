<?php

namespace Tests\Unit\Visits;

use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\User;
use App\Support\Visits\SurveyCarryForward;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Plan 46-03, Task 1 — the ten D-01 fields, resolved LIVE from the project's
 * current SiteSurvey.
 *
 * The test that matters here is test_a_survey_edit_is_reflected_on_the_next_call():
 * D-01 says the carry-forward READS the survey record and is never copied,
 * because a copy goes stale and then contradicts its source with no way to
 * tell which is true. That test is the proof, not the assertion.
 */
class SurveyCarryForwardTest extends TestCase
{
    use RefreshDatabase;

    private function project(): Project
    {
        $user = User::factory()->create();

        return Project::factory()->create(['user_id' => $user->id]);
    }

    private function survey(Project $project, array $attributes = []): SiteSurvey
    {
        return SiteSurvey::create(array_merge([
            'user_id'      => $project->user_id,
            'project_id'   => $project->id,
            'project_name' => 'Carry Forward Fixture',
            'client_name'  => 'Fixture Client',
            'status'       => 'completed',
        ], $attributes));
    }

    /** All ten D-01 fields, and nothing else. */
    public function test_fields_const_names_the_ten_d01_columns_in_render_order(): void
    {
        $this->assertSame([
            'parking_restraints',
            'site_access_notes',
            'access_constraints',
            'delivery_routes',
            'comms_room_access_status',
            'distance_from_base_miles',
            'site_risks',
            'h_and_s_notes',
            'general_notes',
        ], array_keys(SurveyCarryForward::FIELDS));
    }

    /**
     * T-46-03-01 — `office_review_notes` is the office's own commentary and
     * /worksheet/{token} is a page a CLIENT signs. It is excluded BY NAME.
     * This assertion reads the const as data so the exclusion cannot be
     * "completed" by a later agent without a red test.
     */
    public function test_office_review_notes_is_never_carried_forward(): void
    {
        $this->assertArrayNotHasKey('office_review_notes', SurveyCarryForward::FIELDS);

        $project = $this->project();
        $this->survey($project, [
            'office_review_notes' => 'OFFICE-ONLY-COMMENTARY',
            'site_risks'          => 'Live overhead cables',
        ]);

        $encoded = json_encode(SurveyCarryForward::forProject($project->fresh()));

        $this->assertStringNotContainsString('OFFICE-ONLY-COMMENTARY', $encoded);
        $this->assertStringContainsString('Live overhead cables', $encoded);
    }

    public function test_it_returns_all_ten_fields_labelled_in_render_order(): void
    {
        $project = $this->project();
        $this->survey($project, [
            'parking_restraints'       => 'SENTINEL-PARKING',
            'site_access_notes'        => 'SENTINEL-ACCESS-NOTES',
            'access_constraints'       => 'SENTINEL-CONSTRAINTS',
            'delivery_routes'          => 'SENTINEL-ROUTES',
            'comms_room_access_status' => 'yes',
            'comms_room_access_notes'  => 'SENTINEL-COMMS-NOTES',
            'distance_from_base_miles' => '42',
            'distance_from_base_notes' => 'SENTINEL-DISTANCE-NOTES',
            'site_risks'               => 'SENTINEL-RISKS',
            'h_and_s_notes'            => 'SENTINEL-HS',
            'general_notes'            => 'SENTINEL-GENERAL',
        ]);

        $rows = SurveyCarryForward::forProject($project->fresh());

        $this->assertSame([
            'parking_restraints',
            'site_access_notes',
            'access_constraints',
            'delivery_routes',
            'comms_room_access_status',
            'distance_from_base_miles',
            'site_risks',
            'h_and_s_notes',
            'general_notes',
        ], array_column($rows, 'key'));

        $this->assertSame([
            'Parking arrangements',
            'Site access notes',
            'Access constraints',
            'Delivery routes',
            'Comms room access',
            'Distance from depot',
            'Site risks',
            'Health and safety',
            "Surveyor's notes",
        ], array_column($rows, 'label'));

        $values = array_column($rows, 'value', 'key');
        $this->assertSame('SENTINEL-PARKING', $values['parking_restraints']);
        $this->assertSame('SENTINEL-ACCESS-NOTES', $values['site_access_notes']);
        $this->assertSame('SENTINEL-CONSTRAINTS', $values['access_constraints']);
        $this->assertSame('SENTINEL-ROUTES', $values['delivery_routes']);
        // Comms room status renders through the SAME label map the page used.
        $this->assertSame('Permission required — SENTINEL-COMMS-NOTES', $values['comms_room_access_status']);
        $this->assertSame('42 miles from depot — SENTINEL-DISTANCE-NOTES', $values['distance_from_base_miles']);
        $this->assertSame('SENTINEL-RISKS', $values['site_risks']);
        $this->assertSame('SENTINEL-HS', $values['h_and_s_notes']);
        $this->assertSame('SENTINEL-GENERAL', $values['general_notes']);
    }

    public function test_values_are_raw_and_never_pre_escaped(): void
    {
        $project = $this->project();
        $this->survey($project, ['site_risks' => 'Smith & Jones <contractor>']);

        $rows   = SurveyCarryForward::forProject($project->fresh());
        $values = array_column($rows, 'value', 'key');

        // Escaping belongs at the render site; pre-escaping here would show
        // an engineer `&amp;` in a note about a contractor's name.
        $this->assertSame('Smith & Jones <contractor>', $values['site_risks']);
    }

    public function test_empty_fields_are_omitted(): void
    {
        $project = $this->project();
        $this->survey($project, ['site_risks' => 'Only this one']);

        $rows = SurveyCarryForward::forProject($project->fresh());

        $this->assertSame(['site_risks'], array_column($rows, 'key'));
    }

    public function test_a_project_with_no_survey_returns_an_empty_array(): void
    {
        $this->assertSame([], SurveyCarryForward::forProject($this->project()));
    }

    public function test_a_survey_with_every_carried_field_empty_returns_an_empty_array(): void
    {
        $project = $this->project();
        $this->survey($project, ['office_review_notes' => 'office only']);

        $this->assertSame([], SurveyCarryForward::forProject($project->fresh()));
    }

    /**
     * D-01's whole point. NOT a copy: edit the survey and the next call shows
     * the new value, with the old one gone.
     */
    public function test_a_survey_edit_is_reflected_on_the_next_call(): void
    {
        $project = $this->project();
        $survey  = $this->survey($project, [
            'site_risks'         => 'OLD-RISK',
            'access_constraints' => 'OLD-CONSTRAINT',
        ]);

        $before = json_encode(SurveyCarryForward::forProject($project->fresh()));
        $this->assertStringContainsString('OLD-RISK', $before);
        $this->assertStringContainsString('OLD-CONSTRAINT', $before);

        $survey->update([
            'site_risks'         => 'NEW-RISK',
            'access_constraints' => 'NEW-CONSTRAINT',
        ]);

        $after = json_encode(SurveyCarryForward::forProject(Project::findOrFail($project->id)));

        $this->assertStringContainsString('NEW-RISK', $after);
        $this->assertStringContainsString('NEW-CONSTRAINT', $after);
        $this->assertStringNotContainsString('OLD-RISK', $after);
        $this->assertStringNotContainsString('OLD-CONSTRAINT', $after);
    }

    /** Survey selection is unchanged from the page: newest by id. */
    public function test_the_newest_survey_for_the_project_is_the_one_carried(): void
    {
        $project = $this->project();
        $this->survey($project, ['site_risks' => 'OLDER-SURVEY', 'superseded_at' => now()]);
        $this->survey($project, ['site_risks' => 'NEWER-SURVEY']);

        $encoded = json_encode(SurveyCarryForward::forProject($project->fresh()));

        $this->assertStringContainsString('NEWER-SURVEY', $encoded);
        $this->assertStringNotContainsString('OLDER-SURVEY', $encoded);
    }

    public function test_soft_deleted_surveys_are_excluded(): void
    {
        $project = $this->project();
        $this->survey($project, ['site_risks' => 'KEPT-SURVEY']);
        $deleted = $this->survey($project, ['site_risks' => 'DELETED-SURVEY']);
        $deleted->delete();

        $encoded = json_encode(SurveyCarryForward::forProject($project->fresh()));

        $this->assertStringContainsString('KEPT-SURVEY', $encoded);
        $this->assertStringNotContainsString('DELETED-SURVEY', $encoded);
    }

    /** T-46-03-04 / VL-03 — the method NEVER writes. */
    public function test_calling_it_writes_nothing(): void
    {
        $project = $this->project();
        $this->survey($project, ['site_risks' => 'Live overhead cables']);

        $before = [
            'site_surveys' => DB::table('site_surveys')->count(),
            'worksheets'   => DB::table('worksheets')->count(),
            'visits'       => DB::table('visits')->count(),
        ];

        SurveyCarryForward::forProject($project->fresh());

        $this->assertSame($before, [
            'site_surveys' => DB::table('site_surveys')->count(),
            'worksheets'   => DB::table('worksheets')->count(),
            'visits'       => DB::table('visits')->count(),
        ]);
    }

    /** T-46-03-03 / LR-04 — consumed by a client-facing view. */
    public function test_the_class_references_labour_resource_nowhere(): void
    {
        $source = file_get_contents(base_path('app/Support/Visits/SurveyCarryForward.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString('LabourResource', $source);
    }
}
