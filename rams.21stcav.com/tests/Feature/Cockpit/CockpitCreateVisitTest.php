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
 * Two halves, deliberately in one file because they were one feature: the POST
 * that creates a visit and issues its engineer link (Task 1), and the Quick
 * actions area that offered it (Task 2).
 *
 * ── TASK 2'S HALF IS UNSURFACED (46.2 D-02, Plan 46.2-03) ──────────────────
 * The Quick actions block is gone from the cockpit and its Blade component is
 * deleted. TASK 1'S HALF IS UNTOUCHED AND GREEN: the POST still creates the
 * visit, still issues the link the existing generator produced, still reuses a
 * live survey, still captures rooms and people, still logs one activity row,
 * still validates, still scopes to the project in the URL, still rejects a
 * guest and still 404s with the flag off. Sixteen route tests, unedited.
 *
 * Eleven render tests are retired BY NAME in the block where Task 2 used to
 * start, each with the reason and with where its property now lives. VL-11's cap
 * of four is a cap of ZERO, not an absent test.
 *
 * The write is a PLAIN FORM POST. There is no JSON endpoint and no JavaScript:
 * CockpitReadOnlyFenceTest::BANNED_HANDLER_ATTRIBUTES still bans all nine
 * handler attributes, and Plan 46-04 Task 3 rewrote that constant's docblock to
 * record that Phase 46 CONSIDERED retiring it and declined.
 *
 * VL-11 — "simple to use" — is asserted here as a CAP, not described in a
 * comment: see test_no_module_panel_renders_any_visit_control(), which is
 * 46-04's `test_no_module_panel_ever_renders_more_than_four_quick_actions()`
 * moved from four to zero.
 */
class CockpitCreateVisitTest extends TestCase
{
    use RefreshDatabase;

    // TWO MODULE LISTS RETIRED BY 46.2 D-02 / D-01 (Plan 46.2-03):
    //
    //   DOCUMENT_MODULES = ['rams', 'om', 'cable_schedule'] — the three that
    //     offered "Generate document". No module offers it now; Plan 46.2-05
    //     renders the document form in the PANEL for the four surviving rows.
    //     `cable_schedule` also lost its row entirely in 46.2-01 (D-01).
    //   SILENT_MODULES = ['install_programme', 'drawings', 'programming',
    //     'snagging'] — the four that offered nothing. All four are among the
    //     five rows 46.2-01 removed from the map (D-01), so they render no panel
    //     to be silent in, and EVERY surviving module is silent now.
    //
    // Neither is replaced by a list: the property they encoded is asserted over
    // `CockpitModulePresenter::moduleMap()` itself in
    // test_no_module_panel_renders_any_visit_control(), which cannot drift from
    // the rows actually rendered.

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

    /**
     * The `cav-panel` subtree, or '' when no panel opened.
     *
     * WAS `quickActions()`, extracting `cav-qa` (46.2 D-02, Plan 46.2-03). That
     * subtree no longer exists on any module, so a helper returning it would make
     * every assertion built on it pass vacuously. Widened to the whole panel: the
     * question is now whether a visit control appears ANYWHERE in the drawer, not
     * whether one appears in a block that is gone.
     */
    private function panel(Project $project, string $module, array $query = []): string
    {
        return $this->subtree($this->region($project, ['module' => $module] + $query), 'cav-panel');
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

    // ══ TASK 2 WAS "THE QUICK ACTIONS AREA". THERE IS NO SUCH AREA ═════════
    //
    // 46.2 D-02, Plan 46.2-03. `resources/views/components/cockpit/quick-actions.blade.php`
    // was DELETED — a Blade component is surfacing, and this repo's own rule
    // (CockpitPageTest's ruling on the superseded accordion) is that a dead
    // drawer component left on disk invites a later agent to render one beside
    // the new design. `ProjectCockpitController::ACTIONS` is empty and
    // `resolveAction()`, `roomNames()` and `activePeople()` went with it.
    //
    // NOTHING THE FORM POSTED TO WAS DELETED. `projects.cockpit.visits.store`
    // is registered, `VisitLinkIssuer` is byte-unchanged, and the sixteen route
    // tests ABOVE this comment are all green and UNEDITED — the issue, the
    // reuse, the rooms and people captured on the visit, the activity row, the
    // validation, the scoping, the guest rejection and the flag-off 404.
    //
    // ELEVEN RENDER TESTS RETIRED HERE, BY NAME. Each asserted markup inside the
    // `.cav-qa` subtree, which no longer exists:
    //
    //   1. test_the_two_link_modules_offer_exactly_one_control_each()
    //      Site survey and Worksheet each carried one `cav-qa__control` reading
    //      "Create visit".
    //   2. test_the_three_document_modules_offer_exactly_one_generate_control()
    //      RAMS / O&M / Cable schedule each carried one "Generate document".
    //      ⚠ THE GENERATE CONTROL IS THE ONE THING HERE THAT COMES BACK: Plan
    //      46.2-05 renders it in the PANEL, from Plan 46.2-04's field map, for
    //      the four surviving rows. It is not gone, it is being rebuilt one
    //      plan later and better. (`cable_schedule` also lost its ROW in
    //      46.2-01 per D-01, which is why this test had two reasons to be red.)
    //   3. test_four_modules_render_no_quick_actions_block_at_all()  [was green]
    //   4. test_quick_actions_appear_on_overview_only()              [was green]
    //   5. test_the_quick_actions_area_carries_no_handler_attribute_and_no_script()  [was green]
    //      All three passed by asserting an EMPTY `.cav-qa` subtree, which every
    //      module now has. Retired as VACUOUS rather than as failing, and their
    //      combined property — no module panel offers a visit control, and
    //      nothing on the panel carries a handler — is asserted for real by
    //      test_no_module_panel_renders_any_visit_control() below and by
    //      CockpitReadOnlyFenceTest::test_rows_are_static_and_nothing_is_wired_to_a_handler().
    //   6. test_no_module_panel_ever_renders_more_than_four_quick_actions()  [was green]
    //      VL-11's cap of FOUR. RETIRED AND REPLACED, per this plan's own
    //      instruction, by a cap of ZERO — so a later plan that re-renders a
    //      visit control here trips something, rather than finding an absence
    //      of a test. Its `assertLessThanOrEqual` goes with it: at zero the
    //      exact-count house rule applies.
    //   7. test_the_form_is_disclosed_by_url_state_and_carries_a_csrf_token()
    //   8. test_the_open_form_offers_the_rooms_the_people_and_the_two_visit_types()
    //   9. test_the_survey_form_offers_no_visit_type_choice()          [was green]
    //  10. test_no_labour_resource_contact_detail_reaches_the_form()
    //      The disclosure, the option lists, the two radios, the `<select` ban
    //      and LR-04's no-contact-detail rule. THE LR-04 PROPERTY SURVIVES
    //      WITHOUT THIS TEST: `activePeople()` was the only place the cockpit
    //      read a LabourResource, and it is gone, so no contact detail can
    //      reach the page at all — asserted over every tab by
    //      CockpitReturnedTabTest::test_no_labour_resource_contact_detail_appears_on_any_tab().
    //      The `<select` ban is RE-TAKEN in the fence this wave, not inherited.
    //  11. test_the_generate_document_control_posts_to_the_existing_route()
    //      See 2 — Plan 46.2-05's territory, and D-04 still forbids writing a
    //      new generator when it gets there.
    //
    //  `test_the_copy_is_create_visit_and_never_the_banned_neighbours()` is
    //  RETIRED AND MERGED into test_no_module_panel_renders_any_visit_control()
    //  below: its six banned neighbours are asserted there, with 'Create visit'
    //  itself added to the list, because the copy that WAS this phase's word is
    //  now nobody's.
    //
    // ══════════════════════════════════════════════════════════════════════

    /**
     * VL-11'S CAP OF FOUR, AT ZERO (46.2 D-02, Plan 46.2-03).
     *
     * Replaces `test_no_module_panel_ever_renders_more_than_four_quick_actions()`
     * rather than removing it. Same coverage: every module key from the
     * presenter's own map, the disclosure query both closed and open, on a
     * project carrying visits in more than one state.
     *
     * `assertSame(0, ...)` not `assertLessThanOrEqual` — the inequality existed
     * only because the cap was four.
     */
    public function test_no_module_panel_renders_any_visit_control(): void
    {
        $project = $this->project();

        Visit::factory()->create(['project_id' => $project->id, 'type' => Visit::TYPE_INSTALL]);
        Visit::factory()->sent()->create(['project_id' => $project->id, 'type' => Visit::TYPE_SITE_SURVEY]);

        $judged = 0;

        foreach (array_keys(CockpitModulePresenter::moduleMap()) as $module) {
            foreach ([[], ['action' => 'create-visit']] as $query) {
                // The WHOLE panel, not the `.cav-qa` subtree — a subtree that no
                // longer exists would make every assertion below vacuous.
                $panel = $this->panel($project, $module, $query);

                $this->assertNotSame('', $panel, "The {$module} panel did not render — this test would pass vacuously.");
                $judged++;

                $this->assertSame(
                    0,
                    substr_count($panel, 'cav-qa'),
                    "{$module} renders a Quick actions block. 46.2 D-02: the cockpit offers no visit control."
                );

                // Every act's copy, plus the six neighbours that were always
                // another phase's word. 'Create visit' joins them because it is
                // now nobody's copy on this page — it lives at
                // projects.cockpit.visits.store.
                foreach ([
                    'Create visit', 'Generate document', 'Accept', 'Send back', 'Add note', 'Raise a snag',
                    'Book a visit', 'Prepare a visit', 'Book another survey', 'Add a snag', 'Edit visit', 'Edit details',
                ] as $banned) {
                    $this->assertStringNotContainsString($banned, $panel, "\"{$banned}\" must not appear in {$module}'s panel.");
                }

                // No form at all, in either URL state. Plan 46.2-05 ships the
                // DOCUMENT form here and moves this number by name.
                $this->assertSame(0, substr_count($panel, '<form'));
            }
        }

        $this->assertSame(
            count(CockpitModulePresenter::moduleMap()) * 2,
            $judged,
            'Every module was judged in both URL states.'
        );
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
            // WIDENED from the `cav-qa` subtree to the whole panel (46.2 D-02):
            // there is no Quick actions block, so the narrow check would be
            // vacuous. The panel must carry no form for ANY `?action=` value.
            $this->assertStringNotContainsString('<form', $this->panel($project, 'worksheet', ['action' => $payload]));
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

}
