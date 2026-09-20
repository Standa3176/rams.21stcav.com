<?php

namespace Tests\Feature\Cockpit;

use App\Jobs\BuildWorksheetJob;
use App\Models\LabourResource;
use App\Models\Project;
use App\Models\ProjectActivityLog;
use App\Models\SiteSurvey;
use App\Models\User;
use App\Models\Visit;
use App\Models\Worksheet;
use App\Support\Cockpit\CockpitModulePresenter;
use App\Support\Visits\VisitLinkIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 46, Plan 46-04 — the cockpit's FIRST WRITE.
 *
 * Two halves, deliberately in one file because they are one feature: the POST
 * that creates a visit and issues its engineer link (Task 1), and the Quick
 * actions area that offers it (Task 2).
 *
 * The write is a PLAIN FORM POST. There is no JSON endpoint and no JavaScript:
 * CockpitReadOnlyFenceTest::BANNED_HANDLER_ATTRIBUTES still bans all nine
 * handler attributes, and Plan 46-04 Task 3 rewrote that constant's docblock to
 * record that Phase 46 CONSIDERED retiring it and declined.
 *
 * VL-11 — "simple to use" — is asserted here as a CAP, not described in a
 * comment: see test_no_module_panel_ever_renders_more_than_four_quick_actions().
 */
class CockpitCreateVisitTest extends TestCase
{
    use RefreshDatabase;

    /** Modules that offer "Generate document" — the three with an existing generator route. */
    private const DOCUMENT_MODULES = ['rams', 'om', 'cable_schedule'];

    /** Modules that offer NOTHING. Not an empty heading, not a disabled button. */
    private const SILENT_MODULES = ['install_programme', 'drawings', 'programming', 'snagging'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['cockpit.enabled' => true]);
    }

    private function project(): Project
    {
        return Project::factory()->create([
            'name'   => 'Quick Actions Job',
            'status' => Project::STATUS_INSTALLING,
        ]);
    }

    private function user(): User
    {
        return User::factory()->create(['name' => 'Priya Mistry']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createVisit(Project $project, array $payload, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user())
            ->post(route('projects.cockpit.visits.store', $project), $payload);
    }

    /** The rendered cockpit region, entity-decoded. */
    private function region(Project $project, array $query = [], ?User $user = null): string
    {
        $body = $this->actingAs($user ?? $this->user())
            ->get(route('projects.cockpit', ['project' => $project] + $query))
            ->assertOk()
            ->getContent();

        return $this->subtree($body, 'cav-cockpit');
    }

    private function subtree(string $html, string $class): string
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $node = (new \DOMXPath($dom))
            ->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]")
            ->item(0);

        if ($class === 'cav-cockpit') {
            $this->assertNotNull($node, 'The cav-cockpit root element was not found.');
        }

        return $node === null ? '' : html_entity_decode($dom->saveHTML($node), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** The `cav-qa` Quick actions block only, or '' when the module offers none. */
    private function quickActions(Project $project, string $module, array $query = []): string
    {
        return $this->subtree($this->region($project, ['module' => $module] + $query), 'cav-qa');
    }

    // ── Task 1: the write ────────────────────────────────────────────────

    public function test_creating_a_survey_visit_issues_the_link_the_existing_generator_produced(): void
    {
        Bus::fake();

        $project = $this->project();

        $this->createVisit($project, [
            'module'         => 'site_survey',
            'visit_type'     => Visit::TYPE_SITE_SURVEY,
            'scheduled_date' => '2026-10-12',
        ])->assertRedirect();

        $visit = Visit::where('project_id', $project->id)->sole();

        $this->assertSame(Visit::TYPE_SITE_SURVEY, $visit->type);
        $this->assertSame(Visit::STATUS_PLANNED, $visit->status, 'The stored vocabulary did not grow (46-01).');
        $this->assertFalse($visit->isBackfilled());
        $this->assertNotNull($visit->created_by_user_id);
        $this->assertNotNull($visit->sent_at, 'Issuing the link is what sets sent_at.');
        $this->assertNull($visit->accepted_at);
        $this->assertSame(Visit::STATE_SENT, $visit->state());

        $survey = SiteSurvey::where('project_id', $project->id)->sole();

        $this->assertSame(Visit::SOURCE_SITE_SURVEY, $visit->source_type);
        $this->assertSame($survey->id, $visit->source_id);
        $this->assertNotEmpty($survey->access_token);
    }

    public function test_creating_an_install_visit_runs_the_worksheet_generator_sequence(): void
    {
        Bus::fake();

        $project = $this->project();

        $this->createVisit($project, [
            'module'     => 'worksheet',
            'visit_type' => Visit::TYPE_FIRST_FIX,
        ])->assertRedirect();

        $visit     = Visit::where('project_id', $project->id)->sole();
        $worksheet = Worksheet::where('project_id', $project->id)->sole();

        $this->assertSame(Visit::TYPE_FIRST_FIX, $visit->type);
        $this->assertSame(Visit::SOURCE_WORKSHEET, $visit->source_type);
        $this->assertSame($worksheet->id, $visit->source_id);
        $this->assertSame(Worksheet::STATUS_GENERATING, $worksheet->status);
        $this->assertNotEmpty($worksheet->access_token);

        Bus::assertDispatched(BuildWorksheetJob::class);
    }

    public function test_the_redirect_returns_to_the_open_module_with_a_success_flash(): void
    {
        Bus::fake();

        $project = $this->project();

        $this->createVisit($project, ['module' => 'site_survey', 'visit_type' => Visit::TYPE_SITE_SURVEY])
            ->assertRedirect(route('projects.cockpit', ['project' => $project, 'module' => 'site_survey']))
            ->assertSessionHas('success');
    }

    public function test_a_second_survey_visit_adopts_the_live_survey_and_reuses_its_token(): void
    {
        Bus::fake();

        $project = $this->project();

        $this->createVisit($project, ['module' => 'site_survey', 'visit_type' => Visit::TYPE_SITE_SURVEY]);

        $survey = SiteSurvey::where('project_id', $project->id)->sole();
        $token  = $survey->access_token;

        $this->createVisit($project, ['module' => 'site_survey', 'visit_type' => Visit::TYPE_SITE_SURVEY]);

        $this->assertSame(1, SiteSurvey::where('project_id', $project->id)->count(), 'No second survey row.');
        $this->assertSame($token, $survey->fresh()->access_token, 'Superseding is a separate, deliberate act.');
    }

    public function test_a_backfilled_visit_already_wrapping_the_survey_is_reused_not_duplicated(): void
    {
        Bus::fake();

        $project = $this->project();

        $survey = SiteSurvey::create([
            'user_id'      => $this->user()->id,
            'project_id'   => $project->id,
            'project_name' => $project->name,
            'status'       => 'draft',
        ]);

        $backfilled = Visit::factory()->create([
            'project_id'    => $project->id,
            'type'          => Visit::TYPE_SITE_SURVEY,
            'status'        => Visit::STATUS_PLANNED,
            'is_backfilled' => true,
            'source_type'   => Visit::SOURCE_SITE_SURVEY,
            'source_id'     => $survey->id,
        ]);

        $this->createVisit($project, [
            'module'         => 'site_survey',
            'visit_type'     => Visit::TYPE_SITE_SURVEY,
            'scheduled_date' => '2026-11-02',
        ])->assertRedirect();

        // The (source_type, source_id) unique index is respected by REUSE.
        $this->assertSame(1, Visit::where('project_id', $project->id)->count());

        $reused = $backfilled->fresh();

        $this->assertNotNull($reused->sent_at);
        $this->assertSame('2026-11-02', $reused->scheduled_date->format('Y-m-d'));
    }

    public function test_the_rooms_and_the_people_are_captured_on_the_visit(): void
    {
        Bus::fake();

        $project  = $this->project();
        $engineer = LabourResource::factory()->create(['name' => 'Dan Okafor', 'is_active' => true]);

        $this->createVisit($project, [
            'module'              => 'worksheet',
            'visit_type'          => Visit::TYPE_INSTALL,
            'rooms'               => ['Boardroom', 'Huddle 2'],
            'labour_resource_ids' => [$engineer->id],
        ])->assertRedirect();

        $visit = Visit::where('project_id', $project->id)->sole();

        // D-05: captured, and rendered on the engineer link by 46-05. The
        // generators stay project-wide — VL-12 is NOT DELIVERED.
        $this->assertSame(['Boardroom', 'Huddle 2'], $visit->rooms_in_scope);
        $this->assertSame([$engineer->id], $visit->labour_resource_ids);
    }

    public function test_one_activity_row_records_the_create(): void
    {
        Bus::fake();

        $project = $this->project();

        $this->createVisit($project, ['module' => 'worksheet', 'visit_type' => Visit::TYPE_INSTALL]);

        $logs = ProjectActivityLog::where('project_id', $project->id)
            ->where('action', ProjectActivityLog::ACTION_VISIT_CREATED)
            ->get();

        $this->assertCount(1, $logs);
        $this->assertStringContainsString('install', strtolower((string) $logs->first()->description));
    }

    public function test_a_create_moves_only_the_tables_it_is_allowed_to_move(): void
    {
        Bus::fake();

        $project = $this->project();

        $untouched = ['install_records', 'install_programmes', 'snags', 'site_surveys'];
        $before    = [];

        foreach ($untouched as $table) {
            $before[$table] = DB::table($table)->count();
        }

        $this->createVisit($project, ['module' => 'worksheet', 'visit_type' => Visit::TYPE_INSTALL]);

        foreach ($untouched as $table) {
            $this->assertSame($before[$table], DB::table($table)->count(), "`{$table}` moved on a worksheet create.");
        }

        $this->assertSame(1, DB::table('visits')->count());
        $this->assertSame(1, DB::table('worksheets')->count());
    }

    // ── Task 1: the boundary ─────────────────────────────────────────────

    public function test_a_guest_cannot_create_a_visit(): void
    {
        $project = $this->project();

        $this->post(route('projects.cockpit.visits.store', $project), [
            'module'     => 'worksheet',
            'visit_type' => Visit::TYPE_INSTALL,
        ])->assertRedirect(route('login'));

        $this->assertSame(0, Visit::count());
    }

    public function test_the_write_route_404s_when_the_flag_is_off(): void
    {
        config(['cockpit.enabled' => false]);

        $this->createVisit($this->project(), ['module' => 'worksheet', 'visit_type' => Visit::TYPE_INSTALL])
            ->assertNotFound();

        $this->assertSame(0, Visit::count());
    }

    public function test_an_unknown_module_is_rejected_by_validation(): void
    {
        $project = $this->project();

        $this->createVisit($project, ['module' => 'drawings', 'visit_type' => Visit::TYPE_INSTALL])
            ->assertSessionHasErrors('module');

        $this->createVisit($project, ['module' => '../../etc/passwd', 'visit_type' => Visit::TYPE_INSTALL])
            ->assertSessionHasErrors('module');

        $this->assertSame(0, Visit::count());
    }

    public function test_a_visit_type_the_module_does_not_allow_is_rejected(): void
    {
        $project = $this->project();

        // TYPE_INSTALL is a worksheet type, never a survey one.
        $this->createVisit($project, ['module' => 'site_survey', 'visit_type' => Visit::TYPE_INSTALL])
            ->assertSessionHasErrors('visit_type');

        // TYPE_SNAG belongs to Phase 47 and reaches no module here.
        $this->createVisit($project, ['module' => 'worksheet', 'visit_type' => Visit::TYPE_SNAG])
            ->assertSessionHasErrors('visit_type');

        $this->assertSame(0, Visit::count());
    }

    public function test_a_forged_labour_resource_id_is_rejected(): void
    {
        $project = $this->project();

        $this->createVisit($project, [
            'module'              => 'worksheet',
            'visit_type'          => Visit::TYPE_INSTALL,
            'labour_resource_ids' => [9999],
        ])->assertSessionHasErrors('labour_resource_ids.0');

        $this->assertSame(0, Visit::count());
    }

    public function test_a_bad_date_is_rejected(): void
    {
        $project = $this->project();

        $this->createVisit($project, [
            'module'         => 'worksheet',
            'visit_type'     => Visit::TYPE_INSTALL,
            'scheduled_date' => 'not-a-date',
        ])->assertSessionHasErrors('scheduled_date');

        $this->assertSame(0, Visit::count());
    }

    public function test_the_visit_is_scoped_to_the_project_in_the_url(): void
    {
        Bus::fake();

        $mine   = $this->project();
        $theirs = Project::factory()->create(['name' => 'Someone Else']);

        // Every lookup is scoped to the bound {project}; the payload addresses
        // no id, so there is nothing to point at another project (T-46-04-02).
        $this->createVisit($mine, [
            'module'     => 'worksheet',
            'visit_type' => Visit::TYPE_INSTALL,
            'project_id' => $theirs->id,
        ])->assertRedirect();

        $this->assertSame(0, Visit::where('project_id', $theirs->id)->count());
        $this->assertSame(1, Visit::where('project_id', $mine->id)->count());
    }

    public function test_the_issuer_maps_exactly_two_modules(): void
    {
        // The rule is not arbitrary: these are the only two modules with an
        // engineer link. A visit on a module with no link would be a record
        // with nothing behind it.
        $this->assertSame(
            ['site_survey', 'worksheet'],
            array_keys(VisitLinkIssuer::VISIT_MODULES)
        );

        $this->assertSame([Visit::TYPE_SITE_SURVEY], VisitLinkIssuer::VISIT_MODULES['site_survey']);
        $this->assertSame([Visit::TYPE_FIRST_FIX, Visit::TYPE_INSTALL], VisitLinkIssuer::VISIT_MODULES['worksheet']);
    }

    // ── Task 2: the Quick actions area ───────────────────────────────────

    public function test_the_two_link_modules_offer_exactly_one_control_each(): void
    {
        $project = $this->project();

        foreach (['site_survey', 'worksheet'] as $module) {
            $qa = $this->quickActions($project, $module);

            $this->assertNotSame('', $qa, "{$module} must offer Create visit.");
            $this->assertStringContainsString('Create visit', $qa);
            $this->assertSame(1, substr_count($qa, 'cav-qa__control'), "{$module} offers more than one control.");
        }
    }

    public function test_the_three_document_modules_offer_exactly_one_generate_control(): void
    {
        $project = $this->project();

        foreach (self::DOCUMENT_MODULES as $module) {
            $qa = $this->quickActions($project, $module);

            $this->assertStringContainsString('Generate document', $qa, "{$module} must offer its generator.");
            $this->assertStringNotContainsString('Create visit', $qa, "{$module} has no engineer link.");
            $this->assertSame(1, substr_count($qa, 'cav-qa__control'), "{$module} offers more than one control.");
        }
    }

    public function test_four_modules_render_no_quick_actions_block_at_all(): void
    {
        $project = $this->project();

        foreach (self::SILENT_MODULES as $module) {
            $this->assertSame(
                '',
                $this->quickActions($project, $module),
                "{$module} renders a Quick actions block — a disabled control is still an offer."
            );
        }
    }

    /**
     * VL-11 AS AN EXECUTABLE CAP. Counted over every module key, with the
     * disclosure form both closed and open, on a project carrying visits in
     * more than one state. If a later plan needs a fifth control, it removes
     * something.
     */
    public function test_no_module_panel_ever_renders_more_than_four_quick_actions(): void
    {
        $project = $this->project();

        Visit::factory()->create(['project_id' => $project->id, 'type' => Visit::TYPE_INSTALL]);
        Visit::factory()->sent()->create(['project_id' => $project->id, 'type' => Visit::TYPE_SITE_SURVEY]);

        foreach (array_keys(CockpitModulePresenter::moduleMap()) as $module) {
            foreach ([[], ['action' => 'create-visit']] as $query) {
                $qa = $this->quickActions($project, $module, $query);

                if ($qa === '') {
                    continue;
                }

                $controls = substr_count($qa, '<button') + substr_count($qa, '<a class="cav-qa__');

                $this->assertLessThanOrEqual(
                    4,
                    $controls,
                    "{$module} renders {$controls} Quick actions controls — VL-11 caps it at four."
                );
            }
        }
    }

    public function test_quick_actions_appear_on_overview_only(): void
    {
        $project = $this->project();

        foreach (['files', 'notes'] as $tab) {
            $this->assertSame(
                '',
                $this->quickActions($project, 'worksheet', ['tab' => $tab]),
                "Quick actions leaked onto the {$tab} tab — one place to act, not three."
            );
        }
    }

    public function test_the_form_is_disclosed_by_url_state_and_carries_a_csrf_token(): void
    {
        $project = $this->project();

        $closed = $this->quickActions($project, 'worksheet');

        $this->assertStringNotContainsString('<form', $closed, 'Closed at rest means no form.');
        $this->assertStringContainsString('action=create-visit', $closed);

        $open = $this->quickActions($project, 'worksheet', ['action' => 'create-visit']);

        $this->assertStringContainsString('<form', $open);
        $this->assertStringContainsString('method="POST"', $open);
        $this->assertStringContainsString('name="_token"', $open);
        $this->assertStringContainsString(route('projects.cockpit.visits.store', $project), $open);
    }

    public function test_the_open_form_offers_the_rooms_the_people_and_the_two_visit_types(): void
    {
        $project = $this->project();

        $survey = SiteSurvey::create([
            'user_id'      => $this->user()->id,
            'project_id'   => $project->id,
            'project_name' => $project->name,
            'status'       => 'draft',
        ]);
        $survey->rooms()->create(['room_name' => 'Boardroom']);

        LabourResource::factory()->create(['name' => 'Dan Okafor', 'is_active' => true]);
        LabourResource::factory()->create(['name' => 'Retired Rita', 'is_active' => false]);

        $open = $this->quickActions($project, 'worksheet', ['action' => 'create-visit']);

        $this->assertStringContainsString('Boardroom', $open);
        $this->assertStringContainsString('Dan Okafor', $open);
        $this->assertStringNotContainsString('Retired Rita', $open, 'Only ACTIVE people are offered.');

        // Two radios, never a <select> — the fence still bans one.
        $this->assertStringContainsString('type="radio"', $open);
        $this->assertStringNotContainsString('<select', $open);
    }

    public function test_the_survey_form_offers_no_visit_type_choice(): void
    {
        $project = $this->project();

        $open = $this->quickActions($project, 'site_survey', ['action' => 'create-visit']);

        $this->assertStringNotContainsString('type="radio"', $open, 'Site survey has exactly one visit type.');
    }

    public function test_no_labour_resource_contact_detail_reaches_the_form(): void
    {
        $project = $this->project();

        LabourResource::factory()->create([
            'name'      => 'Dan Okafor',
            'email'     => 'dan@example.test',
            'phone'     => '07700 900123',
            'is_active' => true,
        ]);

        $open = $this->quickActions($project, 'worksheet', ['action' => 'create-visit']);

        $this->assertStringContainsString('Dan Okafor', $open);
        $this->assertStringNotContainsString('dan@example.test', $open);
        $this->assertStringNotContainsString('07700 900123', $open);
    }

    public function test_no_access_token_appears_in_any_form_field(): void
    {
        Bus::fake();

        $project = $this->project();

        $this->createVisit($project, ['module' => 'worksheet', 'visit_type' => Visit::TYPE_INSTALL]);

        $worksheet = Worksheet::where('project_id', $project->id)->sole();

        $region = $this->region($project, ['module' => 'worksheet', 'action' => 'create-visit']);

        // T-46-04-04: a token is minted in a model boot hook and never travels
        // through a form. It is not even rendered on the cockpit.
        $this->assertStringNotContainsString($worksheet->access_token, $region);
    }

    public function test_an_unknown_action_value_discloses_nothing_and_is_never_echoed(): void
    {
        $project = $this->project();

        foreach (['<script>alert(1)</script>', 'create-visit-please', str_repeat('a', 4000)] as $payload) {
            $body = $this->actingAs($this->user())
                ->get(route('projects.cockpit', ['project' => $project, 'module' => 'worksheet', 'action' => $payload]))
                ->assertOk()
                ->getContent();

            $this->assertStringNotContainsString($payload, $body, 'A submitted ?action= value is never reflected.');
            $this->assertStringNotContainsString('<form', $this->quickActions($project, 'worksheet', ['action' => $payload]));
        }
    }

    public function test_a_validation_failure_re_renders_the_form_with_the_message(): void
    {
        $project = $this->project();

        $from = route('projects.cockpit', [
            'project' => $project,
            'module'  => 'worksheet',
            'action'  => 'create-visit',
        ]);

        $this->from($from)
            ->createVisit($project, [
                'module'         => 'worksheet',
                'visit_type'     => Visit::TYPE_INSTALL,
                'scheduled_date' => 'not-a-date',
            ])
            ->assertRedirect($from)
            ->assertSessionHasErrors('scheduled_date');
    }

    public function test_the_generate_document_control_posts_to_the_existing_route(): void
    {
        $project = $this->project();

        $qa = $this->quickActions($project, 'rams');

        // D-04: the generator that ALREADY EXISTS. No new document generator.
        $this->assertStringContainsString(route('rams.from-project', $project), $qa);
        $this->assertStringContainsString('name="_token"', $qa);
        $this->assertStringNotContainsString('Add document', $qa);
        $this->assertStringNotContainsString('Issue to client', $qa);
        $this->assertStringNotContainsString('Download', $qa);
    }

    public function test_the_copy_is_create_visit_and_never_the_banned_neighbours(): void
    {
        $project = $this->project();

        foreach (['site_survey', 'worksheet'] as $module) {
            foreach ([[], ['action' => 'create-visit']] as $query) {
                $qa = $this->quickActions($project, $module, $query);

                foreach (['Book a visit', 'Prepare a visit', 'Book another survey', 'Add a snag', 'Edit visit', 'Edit details'] as $banned) {
                    $this->assertStringNotContainsString($banned, $qa, "\"{$banned}\" is another phase's word.");
                }
            }
        }
    }

    public function test_the_quick_actions_area_carries_no_handler_attribute_and_no_script(): void
    {
        $project = $this->project();

        foreach (array_keys(CockpitModulePresenter::moduleMap()) as $module) {
            foreach ([[], ['action' => 'create-visit']] as $query) {
                $qa = $this->quickActions($project, $module, $query);

                foreach (['onclick', 'wire:', 'x-on:', '@click', 'x-data', 'x-show', 'x-init', 'x-if', 'x-text', '<script'] as $banned) {
                    $this->assertStringNotContainsString($banned, $qa, "{$banned} inside {$module}'s Quick actions.");
                }
            }
        }
    }
}
