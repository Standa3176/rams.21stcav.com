<?php

namespace Tests\Feature\Visits;

use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\SiteSurveyRoom;
use App\Models\User;
use App\Models\Visit;
use App\Models\Worksheet;
use App\Models\WorksheetSignoff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * VIS-03 / ROADMAP v4.0 criterion 2 — the public engineer and client links
 * must keep working EXACTLY as they do today after `visits:backfill` runs.
 *
 * This is an HTTP proof, not a route-table proof: every case actually GETs
 * `survey.show` and `public-worksheet.show` BEFORE the backfill, runs
 * `visits:backfill --apply`, then GETs the SAME urls again and compares the
 * status code and the same key page markers. A test that only asserted
 * `Route::has(...)` would pass while the pages 500'd.
 *
 * The specific hazard: both `SiteSurvey` and `Worksheet` carry a live public
 * `access_token` written by a `boot::creating()` hook, and both deliberately
 * omit that token from `$fillable` (security re-audit S-03). A backfill that
 * called `save()` or `update()` on either model could rotate a token and
 * silently break every link already in an engineer's inbox — so the tokens
 * are asserted byte-identical across the run.
 *
 * NOTE — this phase adds NO client-facing surface. The cockpit is a
 * staff-auth PM page, and `tests/Feature/Security/
 * LabourResourceClientSurfacePrivacyTest.php` (`:55-61`) explicitly forbids
 * adding staff-auth surfaces to its `CLIENT_FACING_PATHS` scan. That file is
 * deliberately NOT touched by Phase 45.
 *
 * @see app/Console/Commands/BackfillVisitsCommand.php
 */
class PublicTokenRoutesUnaffectedTest extends TestCase
{
    use RefreshDatabase;

    private const SURVEY_MARKER = 'Northbank Survey Marker';

    private const WORKSHEET_MARKER = 'Northbank Worksheet Marker';

    private const WORKSHEET_ROOM = 'Marker Boardroom';

    // -- Fixtures --

    private function makeSurvey(Project $project, User $user): SiteSurvey
    {
        $survey = SiteSurvey::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => self::SURVEY_MARKER,
            'project_ref'  => 'Q-100001',
            'client_name'  => 'Acme Ltd',
            'site_address' => '1 Example Way, London',
            'status'       => 'draft',
        ]);

        SiteSurveyRoom::create([
            'site_survey_id' => $survey->id,
            'room_name'      => 'Boardroom',
            'sort_order'     => 0,
        ]);

        return $survey;
    }

    private function makeWorksheet(Project $project, User $user): Worksheet
    {
        return Worksheet::create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'project_name'   => self::WORKSHEET_MARKER,
            'project_ref'    => 'Q-100001',
            'client_name'    => 'Acme Ltd',
            'site_address'   => '1 Example Way, London',
            'status'         => Worksheet::STATUS_DRAFT,
            'generated_data' => [
                'project' => [
                    'name'            => self::WORKSHEET_MARKER,
                    'client_name'     => 'Acme Ltd',
                    'site_address'    => '1 Example Way, London',
                    'quote_reference' => 'Q-100001',
                ],
                'rooms' => [
                    [
                        'name'                      => self::WORKSHEET_ROOM,
                        'is_surveyed'               => true,
                        'install_steps'             => '1. Mount display',
                        'cable_route_desc'          => 'Rack to wall',
                        'power_outlet_count'        => 2,
                        'requires_additional_power' => false,
                        'network_port_count'        => 1,
                        'existing_cabling'          => 'Cat6 in floor box',
                        'equipment'                 => [
                            ['name' => 'Samsung QM75B', 'quantity' => 1, 'part_no' => 'QM75B'],
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function sign(Worksheet $worksheet): WorksheetSignoff
    {
        return WorksheetSignoff::create([
            'worksheet_id'         => $worksheet->id,
            'client_name'          => 'A Client',
            'signature_png_base64' => 'iVBORw0KGgo=',
            'signed_with_comments' => false,
            'signed_at'            => '2026-05-01 09:00:00',
        ]);
    }

    /**
     * `SurveyController::show` lazily seeds `survey_data` on the FIRST render
     * of a survey that has none (`:69-72`) — a write owned entirely by the
     * public route, not by the backfill. One warm-up GET settles it so the
     * before/after comparison measures the backfill and nothing else.
     */
    private function warmUp(SiteSurvey $survey): void
    {
        $this->get(route('survey.show', ['token' => $survey->access_token]))->assertOk();
    }

    // -- The proof --

    public function test_the_survey_token_route_behaves_identically_before_and_after_the_backfill(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $survey  = $this->makeSurvey($project, $user);

        $this->warmUp($survey);

        $token = $survey->fresh()->access_token;
        $url   = route('survey.show', ['token' => $token]);

        $before = $this->get($url);
        $before->assertStatus(200);
        $before->assertSee(self::SURVEY_MARKER, escape: false);
        $before->assertSee('Boardroom', escape: false);

        $this->artisan('visits:backfill', ['--apply' => true])->assertSuccessful();
        $this->assertGreaterThan(0, Visit::count(), 'The backfill must actually have run.');

        $after = $this->get($url);
        $after->assertStatus($before->getStatusCode());
        $after->assertSee(self::SURVEY_MARKER, escape: false);
        $after->assertSee('Boardroom', escape: false);

        $this->assertSame($token, $survey->fresh()->access_token, 'The survey access token must not rotate.');
    }

    public function test_the_worksheet_token_route_behaves_identically_before_and_after_the_backfill(): void
    {
        $user      = User::factory()->create();
        $project   = Project::factory()->create(['user_id' => $user->id]);
        $worksheet = $this->makeWorksheet($project, $user);
        $this->sign($worksheet);

        $token = $worksheet->fresh()->access_token;
        $url   = route('public-worksheet.show', ['token' => $token]);

        $before = $this->get($url);
        $before->assertStatus(200);
        $before->assertSee(self::WORKSHEET_MARKER, escape: false);
        $before->assertSee(self::WORKSHEET_ROOM, escape: false);

        $this->artisan('visits:backfill', ['--apply' => true])->assertSuccessful();
        $this->assertSame(1, Visit::where('source_type', Visit::SOURCE_WORKSHEET)->count());

        $after = $this->get($url);
        $after->assertStatus($before->getStatusCode());
        $after->assertSee(self::WORKSHEET_MARKER, escape: false);
        $after->assertSee(self::WORKSHEET_ROOM, escape: false);

        $this->assertSame($token, $worksheet->fresh()->access_token, 'The worksheet access token must not rotate.');
    }

    public function test_an_unsigned_worksheets_public_link_still_works_even_though_it_gets_no_visit(): void
    {
        $user      = User::factory()->create();
        $project   = Project::factory()->create(['user_id' => $user->id]);
        $worksheet = $this->makeWorksheet($project, $user);

        $url = route('public-worksheet.show', ['token' => $worksheet->access_token]);

        $this->get($url)->assertStatus(200)->assertSee(self::WORKSHEET_MARKER, escape: false);

        $this->artisan('visits:backfill', ['--apply' => true])->assertSuccessful();

        $this->assertSame(0, Visit::count(), 'D-01: an unsigned worksheet is a document, not an attendance.');
        $this->get($url)->assertStatus(200)->assertSee(self::WORKSHEET_MARKER, escape: false);
    }

    public function test_an_unknown_token_still_404s_after_the_backfill(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $survey  = $this->makeSurvey($project, $user);
        $this->sign($this->makeWorksheet($project, $user));

        $this->warmUp($survey);

        $this->artisan('visits:backfill', ['--apply' => true])->assertSuccessful();

        $this->get(route('survey.show', ['token' => 'not-a-real-token']))->assertStatus(404);
        $this->get(route('public-worksheet.show', ['token' => 'not-a-real-token']))->assertStatus(404);
    }

    public function test_a_second_backfill_run_leaves_the_public_pages_and_their_tokens_untouched(): void
    {
        $user      = User::factory()->create();
        $project   = Project::factory()->create(['user_id' => $user->id]);
        $survey    = $this->makeSurvey($project, $user);
        $worksheet = $this->makeWorksheet($project, $user);
        $this->sign($worksheet);

        $this->warmUp($survey);

        $surveyToken    = $survey->fresh()->access_token;
        $worksheetToken = $worksheet->fresh()->access_token;

        $this->artisan('visits:backfill', ['--apply' => true])->assertSuccessful();
        $this->artisan('visits:backfill', ['--apply' => true])->assertSuccessful();

        $this->assertSame(2, Visit::count(), 'A second --apply must not duplicate visits.');

        $this->get(route('survey.show', ['token' => $surveyToken]))
            ->assertStatus(200)
            ->assertSee(self::SURVEY_MARKER, escape: false);

        $this->get(route('public-worksheet.show', ['token' => $worksheetToken]))
            ->assertStatus(200)
            ->assertSee(self::WORKSHEET_MARKER, escape: false);

        $this->assertSame($surveyToken, $survey->fresh()->access_token);
        $this->assertSame($worksheetToken, $worksheet->fresh()->access_token);
    }
}
