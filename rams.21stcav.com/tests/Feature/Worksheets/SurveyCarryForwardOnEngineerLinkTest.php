<?php

namespace Tests\Feature\Worksheets;

use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\User;
use App\Models\Worksheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plan 46-03, Task 2 — the proof an ENGINEER sees it.
 *
 * Every request here is UNAUTHENTICATED, against `GET /worksheet/{token}`. That
 * is the engineer's actual seat: a public token URL opened on a phone, on site.
 * A test that asserted on the support class alone would prove the derivation and
 * not the feature.
 *
 * The judging test is test_editing_the_survey_changes_what_the_link_shows():
 * D-01 says the link READS the survey record and never copies it, so an edit
 * must appear and the superseded value must be gone.
 */
class SurveyCarryForwardOnEngineerLinkTest extends TestCase
{
    use RefreshDatabase;

    /** The drawer only renders on a worksheet that has rooms to be about. */
    private const ROOMS = [
        'rooms' => [
            [
                'name'                      => 'Main Hall',
                'is_surveyed'               => true,
                'install_steps'             => '',
                'cable_route_desc'          => '',
                'power_outlet_count'        => 0,
                'requires_additional_power' => false,
                'network_port_count'        => 0,
                'existing_cabling'          => '',
                'equipment'                 => [],
            ],
        ],
    ];

    private function worksheetForProject(Project $project): Worksheet
    {
        return Worksheet::create([
            'user_id'        => $project->user_id,
            'project_id'     => $project->id,
            'project_name'   => 'Carry Forward Fixture',
            'project_ref'    => '21CQ00000-01-OPS',
            'client_name'    => 'Fixture Client',
            'site_address'   => '1 Fixture Way, Reading RG1 1AA',
            'status'         => Worksheet::STATUS_DRAFT,
            'generated_data' => self::ROOMS,
        ]);
    }

    private function project(): Project
    {
        $user = User::factory()->create();

        return Project::factory()->create(['user_id' => $user->id]);
    }

    private function survey(Project $project, array $attributes): SiteSurvey
    {
        return SiteSurvey::create(array_merge([
            'user_id'      => $project->user_id,
            'project_id'   => $project->id,
            'project_name' => 'Carry Forward Fixture',
            'client_name'  => 'Fixture Client',
            'status'       => 'completed',
        ], $attributes));
    }

    /** A distinct sentinel per field, so a failure names the missing field. */
    private function allTenFields(): array
    {
        return [
            'parking_restraints'       => 'SENTINEL-PARKING-RESTRAINTS',
            'site_access_notes'        => 'SENTINEL-SITE-ACCESS-NOTES',
            'access_constraints'       => 'SENTINEL-ACCESS-CONSTRAINTS',
            'delivery_routes'          => 'SENTINEL-DELIVERY-ROUTES',
            'comms_room_access_status' => 'yes',
            'comms_room_access_notes'  => 'SENTINEL-COMMS-ROOM-NOTES',
            'distance_from_base_miles' => '42',
            'distance_from_base_notes' => 'SENTINEL-DISTANCE-NOTES',
            'site_risks'               => 'SENTINEL-SITE-RISKS',
            'h_and_s_notes'            => 'SENTINEL-H-AND-S-NOTES',
            'general_notes'            => 'SENTINEL-GENERAL-NOTES',
        ];
    }

    private function get_link(Worksheet $worksheet)
    {
        return $this->get(route('public-worksheet.show', ['token' => $worksheet->access_token]));
    }

    public function test_all_ten_fields_render_on_an_unauthenticated_engineer_link(): void
    {
        $project   = $this->project();
        $this->survey($project, $this->allTenFields());
        $worksheet = $this->worksheetForProject($project);

        $response = $this->get_link($worksheet);

        $response->assertOk();
        $this->assertGuest();

        // Ten labels.
        foreach ([
            'Parking arrangements',
            'Site access notes',
            'Access constraints',
            'Delivery routes',
            'Comms room access',
            'Distance from depot',
            'Site risks',
            'Health and safety',
            "Surveyor's notes",
        ] as $label) {
            $response->assertSee($label, escape: false);
        }

        // Ten values — the four that never carried before are the point.
        foreach ([
            'SENTINEL-PARKING-RESTRAINTS',
            'SENTINEL-SITE-ACCESS-NOTES',
            'SENTINEL-ACCESS-CONSTRAINTS',
            'SENTINEL-DELIVERY-ROUTES',
            'Permission required',
            'SENTINEL-COMMS-ROOM-NOTES',
            '42 miles from depot',
            'SENTINEL-DISTANCE-NOTES',
            'SENTINEL-SITE-RISKS',
            'SENTINEL-H-AND-S-NOTES',
            'SENTINEL-GENERAL-NOTES',
        ] as $value) {
            $response->assertSee($value, escape: false);
        }
    }

    /** ONE drawer, not two — the user asked for "simple and less scary". */
    public function test_all_ten_render_inside_a_single_drawer(): void
    {
        $project = $this->project();
        $this->survey($project, $this->allTenFields());

        $body = $this->get_link($this->worksheetForProject($project))->getContent();

        $this->assertSame(
            1,
            substr_count($body, 'Site Logistics — Arrival Info'),
            'The carry-forward must widen the existing drawer, never add a second one.'
        );
    }

    /**
     * THE READ-LIVE PROOF, AT HTTP LEVEL. This is the test that judges the
     * feature: nothing was copied, so an edit to the survey shows up on the
     * engineer's link and the old value is gone.
     */
    public function test_editing_the_survey_changes_what_the_link_shows(): void
    {
        $project = $this->project();
        $survey  = $this->survey($project, [
            'site_risks'         => 'OLD-RISK-ASBESTOS-IN-CEILING-VOID',
            'access_constraints' => 'OLD-CONSTRAINT-GOODS-LIFT-ONLY',
        ]);
        $worksheet = $this->worksheetForProject($project);

        $before = $this->get_link($worksheet);
        $before->assertOk();
        $before->assertSee('OLD-RISK-ASBESTOS-IN-CEILING-VOID', escape: false);
        $before->assertSee('OLD-CONSTRAINT-GOODS-LIFT-ONLY', escape: false);

        $survey->update([
            'site_risks'         => 'NEW-RISK-LIVE-OVERHEAD-CABLES',
            'access_constraints' => 'NEW-CONSTRAINT-SCAFFOLD-BLOCKS-MAIN-DOOR',
        ]);

        $after = $this->get_link($worksheet);
        $after->assertOk();

        $body = $after->getContent();
        $this->assertStringContainsString('NEW-RISK-LIVE-OVERHEAD-CABLES', $body);
        $this->assertStringContainsString('NEW-CONSTRAINT-SCAFFOLD-BLOCKS-MAIN-DOOR', $body);
        $this->assertStringNotContainsString('OLD-RISK-ASBESTOS-IN-CEILING-VOID', $body);
        $this->assertStringNotContainsString('OLD-CONSTRAINT-GOODS-LIFT-ONLY', $body);
    }

    /**
     * THE BEHAVIOUR CHANGE. Today a survey carrying only the four safety fields
     * renders nothing at all; the surveyor recorded the risks and the installing
     * engineer was never shown them.
     */
    public function test_a_survey_with_only_the_four_new_fields_now_renders_the_drawer(): void
    {
        $project = $this->project();
        $this->survey($project, [
            // All seven legacy site-logistics columns left null on purpose.
            'access_constraints' => 'ONLY-NEW-ACCESS-CONSTRAINTS',
            'site_risks'         => 'ONLY-NEW-SITE-RISKS',
            'h_and_s_notes'      => 'ONLY-NEW-H-AND-S',
            'general_notes'      => 'ONLY-NEW-GENERAL-NOTES',
        ]);

        $response = $this->get_link($this->worksheetForProject($project));

        $response->assertOk();
        $response->assertSee('Site Logistics — Arrival Info', escape: false);
        $response->assertSee('ONLY-NEW-ACCESS-CONSTRAINTS', escape: false);
        $response->assertSee('ONLY-NEW-SITE-RISKS', escape: false);
        $response->assertSee('ONLY-NEW-H-AND-S', escape: false);
        $response->assertSee('ONLY-NEW-GENERAL-NOTES', escape: false);
    }

    /**
     * No empty drawer and no "not recorded" placeholder: an engineer tapping a
     * drawer to find nothing is worse than no drawer.
     */
    public function test_a_project_with_no_survey_renders_exactly_as_today(): void
    {
        $response = $this->get_link($this->worksheetForProject($this->project()));

        $response->assertOk();
        $response->assertDontSee('Site Logistics — Arrival Info', escape: false);
        $response->assertDontSee('Site risks', escape: false);
        $response->assertDontSee('not recorded', escape: false);
    }

    public function test_a_survey_with_every_carried_field_empty_renders_no_drawer(): void
    {
        $project = $this->project();
        $this->survey($project, ['office_review_notes' => 'office only']);

        $response = $this->get_link($this->worksheetForProject($project));

        $response->assertOk();
        $response->assertDontSee('Site Logistics — Arrival Info', escape: false);
    }

    /**
     * T-46-03-01 — the office's own commentary must never reach a page the
     * CLIENT signs.
     */
    public function test_office_review_notes_never_reaches_the_client_signed_page(): void
    {
        $project = $this->project();
        $this->survey($project, [
            'office_review_notes' => 'OFFICE-ONLY-DO-NOT-SHOW-THE-CLIENT',
            'site_risks'          => 'SENTINEL-SITE-RISKS',
        ]);

        $response = $this->get_link($this->worksheetForProject($project));

        $response->assertOk();
        $this->assertStringNotContainsString(
            'OFFICE-ONLY-DO-NOT-SHOW-THE-CLIENT',
            $response->getContent()
        );
    }

    /** T-46-03-02 — stored XSS. Asserted on the RAW response content. */
    public function test_hostile_survey_text_is_escaped(): void
    {
        $project = $this->project();
        $this->survey($project, [
            'site_risks'         => '<script>alert(1)</script>',
            'access_constraints' => '"><img src=x onerror=alert(2)>',
        ]);

        $response = $this->get_link($this->worksheetForProject($project));
        $body     = $response->getContent();

        $response->assertOk();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringNotContainsString('<img src=x onerror=alert(2)>', $body);
        // Escaped, and therefore still legible to the engineer.
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
    }

    /**
     * VL-03 / T-46-03-04 — rendering this page rotates no token. The engineer's
     * link in an email must still work after a PM opens it.
     */
    public function test_rendering_does_not_rotate_the_worksheet_access_token(): void
    {
        $project = $this->project();
        $this->survey($project, $this->allTenFields());
        $worksheet = $this->worksheetForProject($project);

        $before = $worksheet->access_token;

        $this->get_link($worksheet)->assertOk();

        $this->assertSame($before, $worksheet->fresh()->access_token);
    }

    public function test_the_link_still_returns_200_for_a_worksheet_with_no_project_survey(): void
    {
        $project   = $this->project();
        $worksheet = $this->worksheetForProject($project);

        $this->get_link($worksheet)->assertOk();
        $this->assertSame($worksheet->access_token, $worksheet->fresh()->access_token);
    }

    /**
     * T-46-03-02 — the blade file is client-facing, so `{!! !!}` is forbidden
     * in it for anything carrying user input.
     *
     * The file has exactly ONE raw echo and it predates this plan: the
     * `$skipRestoreAttr` literal on line ~893, which is a hard-coded
     * `data-skip-restore="1"` or the empty string and never touches survey
     * text. Pinning the COUNT at one means any new raw echo — including a
     * "just this once" one over a carry-forward value — fails here.
     */
    public function test_the_engineer_link_view_adds_no_unescaped_echo(): void
    {
        $source = file_get_contents(
            base_path('resources/views/worksheets/public-show.blade.php')
        );

        $this->assertIsString($source);
        $this->assertSame(1, substr_count($source, '{!!'));
        $this->assertStringContainsString('{!! $skipRestoreAttr !!}', $source);
    }
}
