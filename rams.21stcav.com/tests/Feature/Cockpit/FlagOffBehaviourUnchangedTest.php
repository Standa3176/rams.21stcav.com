<?php

namespace Tests\Feature\Cockpit;

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
 * Phase 45, plan 45-08, Task 1 — ROADMAP criterion 4 as an EXECUTABLE PROOF.
 *
 * Criterion 4 says "with the flag off the application behaves exactly as it
 * does today, proven by a test, not by inspection". This file is that test.
 *
 * WHAT ACTUALLY PROTECTS CRITERION 4 — read this before trusting any single
 * assertion here. The real teeth are:
 *
 *   1. The three sha256 comparisons below. `layouts/app.blade.php`,
 *      `resources/css/app.css` and `tailwind.config.js` are the only three
 *      files that could retone EVERY page in the app at once. They are
 *      pinned byte-for-byte against pre-phase HEAD `4abd2b24`.
 *   2. The measured D-06 baseline (45-BASELINE.md — 159 passed, 2 skipped,
 *      0 failed) re-run at end of phase by 45-08 Task 2.
 *
 * NOT the "the 404 body contains no cav- class" assertion. A 404 page
 * trivially contains no cockpit markup, so that assertion can never fail. It
 * is kept because it is cheap and it documents the intent, but a future
 * reader must NOT read it as coverage. It is recorded as knowingly vacuous in
 * 45-FLAG-OFF-PROOF.md.
 *
 * A HASH MISMATCH IS A STOP CONDITION, NOT A HASH TO UPDATE. If one of the
 * three assertions below fails, Phase 45 has retoned the whole application
 * and violated criterion 4 and D-07. Raise it. Do not "refresh" the constant.
 *
 * @see .planning/phases/45-visit-model-read-only-cockpit/45-BASELINE.md
 * @see .planning/phases/45-visit-model-read-only-cockpit/45-FLAG-OFF-PROOF.md
 */
class FlagOffBehaviourUnchangedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Pre-phase sha256 of the three shared-presentation files, recorded in
     * 45-BASELINE.md at HEAD `4abd2b24` — a tree with ZERO Phase 45 code.
     *
     * These are WORKING-TREE hashes taken on a CRLF Windows checkout with
     * `Get-FileHash -Algorithm SHA256`. `hash_file()` reads the same working
     * -tree bytes, so it matches. A `git hash-object` value, or a hash of an
     * LF-normalised copy, would NOT match and would read as a false positive.
     */
    private const PRE_PHASE_HASHES = [
        'resources/views/layouts/app.blade.php' => '9ED63C4C754F33832E12BB07A2C515AAC82AB656C5C9A1D35AED0174A7FF0557',
        'resources/css/app.css'                 => 'EDAD1982303B9FABF86A4B791BBF16436104251277CA79DBAEFB5603C8BE2133',
        'tailwind.config.js'                    => '73BB8AD6B7CDF4DC0B11C51661E3DBC7A274CABBCC226D1FF41B5E50938E74BB',
    ];

    private const SURVEY_MARKER = 'Flagoff Survey Marker';

    private const WORKSHEET_MARKER = 'Flagoff Worksheet Marker';

    private const WORKSHEET_ROOM = 'Flagoff Boardroom';

    /**
     * The shipped default. Asserted explicitly in every test rather than
     * relied on, so a stray config change elsewhere cannot make this file
     * silently pass by testing the flag-ON path.
     */
    protected function setUp(): void
    {
        parent::setUp();
        config(['cockpit.enabled' => false]);
    }

    // -- Fixtures ---------------------------------------------------------

    /**
     * DashboardController::index() redirects non-admins to projects.index
     * (`:45-47`), so the dashboard half of this proof needs an admin.
     */
    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function project(User $user): Project
    {
        return Project::factory()->create([
            'user_id'         => $user->id,
            'name'            => 'Flagoff Test Job',
            'quote_reference' => 'Q-458888',
            'site_address'    => '8 Example Street, Leeds',
        ]);
    }

    private function makeSurvey(Project $project, User $user): SiteSurvey
    {
        $survey = SiteSurvey::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => self::SURVEY_MARKER,
            'project_ref'  => 'Q-458888',
            'client_name'  => 'Acme Ltd',
            'site_address' => '8 Example Street, Leeds',
            'status'       => 'draft',
        ]);

        SiteSurveyRoom::create([
            'site_survey_id' => $survey->id,
            'room_name'      => 'Boardroom',
            'sort_order'     => 0,
        ]);

        return $survey;
    }

    private function makeSignedWorksheet(Project $project, User $user): Worksheet
    {
        $worksheet = Worksheet::create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'project_name'   => self::WORKSHEET_MARKER,
            'project_ref'    => 'Q-458888',
            'client_name'    => 'Acme Ltd',
            'site_address'   => '8 Example Street, Leeds',
            'status'         => Worksheet::STATUS_DRAFT,
            'generated_data' => [
                'project' => [
                    'name'            => self::WORKSHEET_MARKER,
                    'client_name'     => 'Acme Ltd',
                    'site_address'    => '8 Example Street, Leeds',
                    'quote_reference' => 'Q-458888',
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

        WorksheetSignoff::create([
            'worksheet_id'         => $worksheet->id,
            'client_name'          => 'A Client',
            'signature_png_base64' => 'iVBORw0KGgo=',
            'signed_with_comments' => false,
            'signed_at'            => '2026-05-01 09:00:00',
        ]);

        return $worksheet;
    }

    /**
     * Every marker the cockpit could possibly leak into a page it does not
     * own: the brand/page roots, the token prefix, and the Vite entry.
     */
    private function assertNoCockpitFootprint(string $html, string $where): void
    {
        $markers = ['cav-cockpit', 'cav-brand', 'cockpit.css', '--cav-', 'class="cav-'];

        foreach ($markers as $marker) {
            $this->assertStringNotContainsString(
                $marker,
                $html,
                $where.' must not carry the cockpit marker "'.$marker.'" while COCKPIT_ENABLED is off.'
            );
        }
    }

    // -- The route is gated in the controller, not in the route table -----

    public function test_the_cockpit_route_404s_with_the_flag_off(): void
    {
        $user    = User::factory()->create();
        $project = $this->project($user);

        $this->actingAs($user)
            ->get(route('projects.cockpit', $project))
            ->assertNotFound();
    }

    /**
     * KNOWINGLY VACUOUS — see the class docblock. A 404 body contains no
     * cockpit markup by construction, so this can never fail. Kept as intent
     * documentation only. Criterion 4's real protection is the three hashes.
     */
    public function test_the_404_body_emits_no_cockpit_markup(): void
    {
        $user    = User::factory()->create();
        $project = $this->project($user);

        $response = $this->actingAs($user)->get(route('projects.cockpit', $project));

        $response->assertNotFound();
        $this->assertNoCockpitFootprint($response->getContent(), 'The flag-off 404 response');
    }

    /**
     * The panel's query string is gated by the same 404 (Plan 45-13).
     *
     * `?module=` and `?tab=` arrived in Plan 45-11, AFTER this file was
     * written, so until now nothing proved that the flag gates them too. It
     * does — the gate is `abort_unless(config('cockpit.enabled'), 404)` at the
     * top of the one action, before any input is read — but a reader has to
     * take that on trust unless a test says so, and a query parameter is
     * exactly the kind of thing a later refactor resolves before the gate.
     */
    public function test_the_flag_off_404_holds_for_the_panel_query_string_too(): void
    {
        $user    = User::factory()->create();
        $project = $this->project($user);

        $urls = [
            route('projects.cockpit', ['project' => $project, 'module' => 'worksheet']),
            route('projects.cockpit', ['project' => $project, 'tab' => 'files']),
            route('projects.cockpit', ['project' => $project, 'module' => 'worksheet', 'tab' => 'notes']),
            route('projects.cockpit', ['project' => $project, 'module' => '<script>alert(1)</script>']),
        ];

        foreach ($urls as $url) {
            $response = $this->actingAs($user)->get($url);

            $response->assertNotFound();
            $this->assertNoCockpitFootprint($response->getContent(), 'The flag-off 404 for '.$url);
        }
    }

    /**
     * This assertion is what stops a later agent "tidying up" by wrapping
     * Route::get() in `if (config('cockpit.enabled'))`. Doing so would make
     * route('projects.cockpit') throw RouteNotFoundException whenever the
     * flag is off — worse than a 404, because it breaks callers rather than
     * hiding a page. See routes/web.php:245-253.
     */
    public function test_the_route_name_still_resolves_with_the_flag_off(): void
    {
        $user    = User::factory()->create();
        $project = $this->project($user);

        $url = route('projects.cockpit', $project);

        $this->assertStringContainsString('projects/'.$project->id.'/cockpit', $url);
    }

    // -- No style is pushed onto any existing page ------------------------

    public function test_the_eleven_tab_project_page_pushes_no_cockpit_style(): void
    {
        $user    = User::factory()->create();
        $project = $this->project($user);

        $response = $this->actingAs($user)->get(route('projects.show', $project));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('ws-tab', $html, 'The eleven-tab workspace strip must still render.');
        $this->assertNoCockpitFootprint($html, 'The eleven-tab project page');
    }

    public function test_the_dashboard_pushes_no_cockpit_style_and_keeps_its_health_grid(): void
    {
        $user = $this->admin();
        $this->project($user);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('dash-health-grid', $html, 'The dashboard health grid must still render.');
        $this->assertNoCockpitFootprint($html, 'The dashboard');
    }

    // -- The three shared-presentation files are byte-identical -----------

    /**
     * @dataProvider sharedPresentationFiles
     */
    public function test_the_shared_presentation_file_is_byte_identical_to_its_pre_phase_state(
        string $relativePath,
        string $expectedHash
    ): void {
        $absolute = base_path($relativePath);

        $this->assertFileExists($absolute, $relativePath.' is missing — criterion 4 cannot be evaluated.');

        $actual = strtoupper(hash_file('sha256', $absolute));

        $this->assertSame(
            strtoupper($expectedHash),
            $actual,
            'STOP — '.$relativePath.' differs from its pre-phase state (HEAD 4abd2b24, recorded in '
            ."45-BASELINE.md).\n"
            .'This file is shared by EVERY page in the application. Adding brand tokens to its :root, '
            .'or any other edit, retones the whole app the moment the code ships — with COCKPIT_ENABLED '
            .'still off. That violates ROADMAP criterion 4 ("with the flag off nothing changes") and '
            ."D-07 (cockpit tokens live in the cockpit's own scoped stylesheet).\n"
            .'THIS IS A STOP CONDITION, NOT A HASH TO UPDATE. Revert the file; do not edit this constant.'
        );
    }

    public static function sharedPresentationFiles(): array
    {
        $cases = [];

        foreach (self::PRE_PHASE_HASHES as $path => $hash) {
            $cases[$path] = [$path, $hash];
        }

        return $cases;
    }

    // -- VIS-03, re-asserted at the END of the phase ----------------------

    public function test_the_public_token_routes_are_unchanged_and_their_tokens_unrotated(): void
    {
        $user      = User::factory()->create();
        $project   = $this->project($user);
        $survey    = $this->makeSurvey($project, $user);
        $worksheet = $this->makeSignedWorksheet($project, $user);

        // SurveyController::show lazily seeds survey_data on first render
        // (:69-72) — a write owned by the public route, not by this phase.
        $this->get(route('survey.show', ['token' => $survey->access_token]))->assertOk();

        $surveyToken    = $survey->fresh()->access_token;
        $worksheetToken = $worksheet->fresh()->access_token;

        $surveyResponse = $this->get(route('survey.show', ['token' => $surveyToken]));
        $surveyResponse->assertStatus(200);
        $surveyResponse->assertSee(self::SURVEY_MARKER, escape: false);
        $surveyResponse->assertSee('Boardroom', escape: false);
        $this->assertNoCockpitFootprint($surveyResponse->getContent(), 'The public survey page');

        $worksheetResponse = $this->get(route('public-worksheet.show', ['token' => $worksheetToken]));
        $worksheetResponse->assertStatus(200);
        $worksheetResponse->assertSee(self::WORKSHEET_MARKER, escape: false);
        $worksheetResponse->assertSee(self::WORKSHEET_ROOM, escape: false);
        $this->assertNoCockpitFootprint($worksheetResponse->getContent(), 'The public worksheet page');

        $this->assertSame($surveyToken, $survey->fresh()->access_token, 'The survey access token must not rotate.');
        $this->assertSame($worksheetToken, $worksheet->fresh()->access_token, 'The worksheet access token must not rotate.');
    }

    // -- The new data is INERT with the flag off --------------------------

    /**
     * The point of the whole phase: `visits` rows may exist in production
     * after the backfill runs, and until someone flips the flag they must
     * change nothing a user can see.
     */
    public function test_seeded_visits_change_nothing_on_the_existing_surfaces(): void
    {
        $user    = $this->admin();
        $project = $this->project($user);

        // The shared edit-action-bar renders a Cancel link built from
        // url()->previous(), so an identical page differs byte-for-byte
        // depending on what was requested immediately before it. One warm-up
        // GET makes the previous-url identical for both snapshots, so the
        // comparison below measures the visits rows and nothing else.
        $this->actingAs($user)->get(route('projects.show', $project))->assertOk();
        $projectBefore = $this->actingAs($user)->get(route('projects.show', $project))->assertOk()->getContent();

        Visit::factory()->count(3)->create([
            'project_id'    => $project->id,
            'is_backfilled' => true,
        ]);
        $this->assertSame(3, Visit::where('project_id', $project->id)->count(), 'The visits must actually exist.');

        $projectAfter   = $this->actingAs($user)->get(route('projects.show', $project))->assertOk()->getContent();
        $dashboardAfter = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('dash-health-grid', $dashboardAfter);

        $this->assertStringContainsString('ws-tab', $projectAfter);
        $this->assertNoCockpitFootprint($projectAfter, 'The eleven-tab project page with visits present');
        $this->assertNoCockpitFootprint($dashboardAfter, 'The dashboard with visits present');

        $this->assertSame(
            $this->stabilise($projectBefore),
            $this->stabilise($projectAfter),
            'The eleven-tab project page must be unchanged by the presence of visits rows.'
        );
    }

    public function test_the_cockpit_still_404s_once_visits_exist(): void
    {
        $user    = User::factory()->create();
        $project = $this->project($user);

        Visit::factory()->create(['project_id' => $project->id, 'is_backfilled' => true]);

        $this->actingAs($user)
            ->get(route('projects.cockpit', $project))
            ->assertNotFound();
    }

    /**
     * Strip the per-request noise (CSRF token, Livewire/Alpine ids, relative
     * timestamps) so a before/after HTML comparison measures the visits rows
     * and nothing else.
     */
    private function stabilise(string $html): string
    {
        $html = preg_replace('/name="_token"\s+value="[^"]*"/', 'name="_token" value="TOKEN"', $html);
        $html = preg_replace('/csrf-token"\s+content="[^"]*"/', 'csrf-token" content="TOKEN"', $html);
        $html = preg_replace('/\b\d+ seconds? ago\b/', 'RELATIVE_TIME', $html);
        $html = preg_replace('/\bwire:id="[^"]*"/', 'wire:id="ID"', $html);

        return $html;
    }
}
