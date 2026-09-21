<?php

namespace Tests\Feature\Cockpit;

use App\Http\Controllers\ProjectCockpitController;
use App\Models\Project;
use App\Models\User;
use App\Models\Visit;
use App\Models\Worksheet;
use App\Models\WorksheetPhoto;
use App\Support\Cockpit\CockpitModulePresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 45, plan 45-07 — THE READ-ONLY FENCE.
 *
 * ROADMAP criteria 3 and 5 rest on this file. The sketch
 * (.planning/sketches/002-install-cockpit/cockpit-sections.html) draws fifteen
 * write affordances; Phase 45 renders NONE of them, and that has to be proven
 * by a test rather than by inspection, because inspection does not survive the
 * next agent.
 */
class CockpitReadOnlyFenceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The fence, enumerated as DATA.
     *
     * Two lists, deliberately inspectable. A future phase that legitimately
     * adds an affordance must DELETE an entry here on purpose, rather than
     * watch a vague assertion quietly stop covering anything — the same
     * anti-rot discipline as
     * LabourResourceClientSurfacePrivacyTest::test_every_enumerated_path_exists().
     *
     * @var array<int, string>
     */
    private const FORBIDDEN_MARKUP = [
        // RETIRED IN PART BY PLAN 46-04 - six entries became two, per entry,
        // each lifted because this phase ships the thing the entry banned:
        //
        //   '<form'     LIFTED (46-04, VL-01) - a write is a form POST. This is
        //               the mechanism the phase chose over JavaScript.
        //   '<input'    LIFTED (46-04, VL-01) - the CSRF hidden field, the date,
        //               the room and resource checkboxes, the two radios.
        //   '<button'   LIFTED (46-04, VL-01) - the submit control.
        //   '<textarea' LIFTED (46-05, VL-06/VL-07) - the office note and the
        //               send-back reason. Lifted HERE so 46-05 does not have to
        //               edit the fence a second time, and named as such so the
        //               entry is not lifted by a plan that does not use it.
        //
        // The two below are NOT leftovers. Each is a ruling:
        '<select',   // STAYS BANNED. Nothing in this phase needs one - rooms and
                     // resources are checkboxes and the visit type is two
                     // radios. A select would be a new interaction pattern the
                     // design does not draw.
        '<script',   // STAYS BANNED. The cockpit ships no JavaScript of its own.
                     // This is the phase's ruling, not an accident.
    ];

    /**
     * Every deferred write affordance, with the phase that owns it.
     *
     * Fifteen came from 45-UI-SPEC.md § Read-only Fence. Plan 45-13 added the
     * three that sketch 004 draws as a "Quick actions" block in the side panel
     * (D-15): Create visit, Add note and Upload files. They were never
     * rendered, not even disabled, but until now only CockpitPageTest and
     * CockpitPanelTest said so, each in its own private list. Naming them here
     * puts them behind the count assertion below, so a later phase that ships
     * one has to remove its entry deliberately.
     *
     * @var array<string, string>
     */
    private const DEFERRED_AFFORDANCES = [
        'Book another survey'      => 'Phase 46',
        'Prepare a visit'          => 'Phase 46 / 51',
        'Add a snag'               => 'Phase 47',
        'Book a visit'             => 'Phase 47',
        'Issue to client'          => 'Phase 48',
        'Send a RAMS to the client' => 'Phase 48',
        'Upload a drawing'         => 'Phase 48',
        'Add document'             => 'Phase 48',
        'Add anyway'               => 'Phase 48',
        'Edit details'             => 'Phase 49',
        'Re-import from QuoteWerks' => 'Phase 50',
        'Close this project'       => 'Phase 50',
        'Open register'            => 'Phase 48',
        'Export CSV'               => 'Phase 48',

        // 'Download' => 'Phase 48' WAS HERE. LIFTED BY PLAN 46.1-04, BY NAME,
        // for requirement RV-04 — because this phase SHIPS the thing the entry
        // banned: the Returned tab's per-visit photo archive, which D-03 calls
        // the Bitrix hand-off. The copy is "Download all photos (ZIP)" and it
        // is served by a GET on ProjectCockpitEvidenceController (Plan
        // 46.1-02). An entry is removed when the affordance arrives, in the
        // SAME commit as the affordance, and never to make a red test fit.
        //
        // EXACTLY ONE ENTRY LEFT. The boundary is named here rather than left
        // to be inferred, because the next reader's real question is not what
        // went but what stayed:
        //
        //   STILL PHASE 48, STILL BANNED — 'Upload files', 'Add document',
        //   'Mark as sent', 'Issue to client', 'Open register', 'Export CSV',
        //   'Add anyway', 'Upload a drawing', 'Send a RAMS to the client'.
        //   This phase reads evidence and hands it over; it uploads nothing,
        //   issues nothing and sends nothing to a client.
        //
        //   STILL PHASE 47 — 'Add a snag', 'Book a visit', 'Assign parts',
        //   'Close snag'. STILL PHASE 49 — 'Edit details'. STILL PHASE 50 —
        //   'Re-import from QuoteWerks', 'Close this project'. STILL PHASE 46 —
        //   'Book another survey', 'Prepare a visit'.
        //
        // The word is ALSO still banned on the FILES tab, by
        // CockpitPanelTest::test_the_files_tab_link_copy_is_view_and_never_download(),
        // which Plan 46.1-04 NARROWED AND RENAMED rather than deleted: a
        // document row must still say "View". This lift is the Returned tab's
        // and nothing else's.

        // Sketch 004's Quick actions block — added by Plan 45-13 (D-15).
        //
        // LIFTED BY PLAN 46-04, by name, because this plan ships them:
        //   'Create visit' => shipped by 46-04 itself (VL-01/VL-02/VL-03).
        //   'Add note'     => shipped by Plan 46-05 (VL-06). Lifted here for
        //                     the same reason as '<textarea', and named, so the
        //                     fence is not edited twice for one decision.
        //
        // 'Upload files' STAYS: it is Phase 48 and this phase deliberately does
        // not grow a second half (D-04).
        'Upload files'             => 'Phase 48',

        // ADDED BY PLAN 46-04 — three strings this phase must not ship, so the
        // fence keeps growing where the scope fence is. Phase 46 raises a snag;
        // it does not manage one (D-03), and it does not send anything to a
        // client (D-04).
        'Assign parts'             => 'Phase 47',
        'Close snag'               => 'Phase 47',
        'Mark as sent'             => 'Phase 48',
    ];

    /**
     * Handler attributes and template directives banned INSIDE the region.
     *
     * THE ALPINE RULING, ENFORCED RATHER THAN REMEMBERED. Alpine is loaded
     * globally by resources/views/layouts/app.blade.php and is therefore
     * AVAILABLE on this page — it is not absent, it is BANNED. Phase 45 ships
     * no JavaScript of its own: the side panel's open/closed and tab state is
     * URL state driven by plain <a href> GETs (Plan 45-11), which is why the
     * design's disclosure behaviour needed no directive. Plan 45-11 asserted
     * these strings inline inside CockpitPageTest; Plan 45-13 promotes them
     * here so the ruling lives in the fence.
     *
     * PHASE 46 CONSIDERED RETIRING THIS AND DECLINED. ALL NINE STAY BANNED.
     *
     * Plan 46-04 made the cockpit writable, which was the moment this ban was
     * up for retirement — and it was re-taken rather than lapsing. Alpine is
     * still loaded globally, so it was still available. It was ruled out again
     * because every write here is a REAL FORM POST and every piece of state is
     * server-rendered from the query string (`?module=`, `&tab=`,
     * `&action=create-visit`).
     *
     * What that keeps is exactly what the query-string pattern bought in Phase
     * 45: bookmarkable panel state, a working back button, and a page that
     * still works with JavaScript off — on a phone, in a plant room, which is
     * where a PM actually reads it. So a write phase STRENGTHENS the no-JS
     * ruling instead of quietly dropping it.
     *
     * The next phase inherits a DECISION, not an omission. Retiring this list
     * would mean deciding that the cockpit ships JavaScript, and that decision
     * belongs to whoever writes the first line of it.
     *
     * @var array<int, string>
     */
    private const BANNED_HANDLER_ATTRIBUTES = [
        'onclick',
        'wire:',
        'x-on:',
        '@click',
        'x-data',
        'x-show',
        'x-init',
        'x-if',
        'x-text',
    ];

    /**
     * VIS-06 / criterion 5, asserted DIRECTLY rather than by absence-of-buttons.
     *
     * @var array<int, string>
     */
    private const WRITE_SURFACE_TABLES = [
        'visits',
        'install_records',
        'install_programmes',
        'site_surveys',
        'worksheets',
        // GROWN 5 -> 7 BY PLAN 46-04. Both are now written by cockpit POSTs
        // (`visits.store` logs one activity row; 46-07 raises a snag), which
        // makes it MORE important, not less, that a GET leaves them exactly
        // where they were.
        'snags',
        'project_activity_logs',

        // GROWN 7 -> 11 BY PLAN 46.1-04. Phase 46.1 READS four new tables —
        // the Returned tab resolves them and the photo-archive GET streams
        // their bytes — and the whole point of this fence is that a GET moves
        // none of them. THE ZIP IS A GET: it builds an archive to a temp file
        // and deletes it after send; it inserts no row, not even an activity
        // log (T-46.1-09, ruled in ProjectCockpitEvidenceController's
        // docblock, precisely because `project_activity_logs` is held still
        // here).
        //
        // All three row-count tests below ITERATE this constant, so they
        // picked these four up the moment the names landed — which is why the
        // list is the thing that grows and the tests are not touched.
        'site_survey_photos',
        'worksheet_photos',
        'worksheet_signoffs',
        'device_label_photos',
    ];

    /**
     * SCOPE OF THIS FENCE — a plan-time decision, recorded here on purpose.
     *
     * Every assertion below is scoped to the `cav-brand cav-cockpit` subtree,
     * never to the whole response body. The cockpit page extends the shared app
     * layout deliberately (Plan 45-06), and resources/views/layouts/app.blade.php
     * contains, in the chrome of EVERY authenticated page in this application:
     * 2 <form> (including the logout form at :1356), 1 <input> (the
     * command-palette search box), 6 <button> and 6 <script> (@vite bundles and
     * Alpine). A whole-body assertion of "no <form" would therefore fail on its
     * very first run against entirely unmodified global chrome, and whoever met
     * that failure would have to decide unsupervised how much of the fence to
     * weaken.
     *
     * That decision was made at plan time instead: the layout's nav, logout
     * form, command-palette input and @vite/Alpine <script> tags are OUT OF
     * SCOPE of this fence. They are pre-existing, owned by no part of Phase 45,
     * present identically on the eleven-tab page, and covered instead by Plan
     * 45-08's sha256 assertion that layouts/app.blade.php is byte-identical to
     * its pre-phase state. What this fence exists to catch is a write affordance
     * appearing INSIDE the cockpit.
     *
     * THE VACUITY RISK THAT SCOPING INTRODUCES, AND HOW IT IS CLOSED.
     * If the extraction matched nothing, every "contains no X" assertion would
     * pass trivially and the fence would prove nothing while showing green —
     * worse than a fence that fails. So this helper brackets the region at BOTH
     * ends before returning: it asserts the region is non-empty, that it
     * contains the masthead's project name (TOP bracket), and that it contains
     * the "Open full project" link text (BOTTOM bracket — that link is the
     * last element in the page shell). The bracket string was RETARGETED by
     * Plan 45-11 when sketch 004 shortened the link's copy from "Open the full
     * project page"; it was re-proved in the same commit by deliberately
     * truncating the extraction and watching this assertion go red. The
     * bracket is not optional and neither end may be removed — retargeting a
     * bracket to follow the markup is maintenance, deleting one is the
     * vacuity this whole helper exists to prevent.
     *
     * Both brackets live INSIDE this helper, not in a sibling test. The reason
     * is specific: the masthead is the FIRST thing in the subtree, so an
     * extraction that truncated early would still be non-empty and still
     * contain the project name — passing the top check while leaving all nine
     * drawers unexamined and the fence green. With both brackets there is no
     * extraction that is simultaneously wrong and green: truncate and the
     * bottom check fails; over-capture and the no-<form / no-<script
     * assertions fail on the layout's chrome.
     */
    private function cockpitRegion(string $html): string
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $node = (new \DOMXPath($dom))
            ->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' cav-cockpit ')]")
            ->item(0);

        $this->assertNotNull($node, 'The cav-cockpit root element was not found — the fence would pass vacuously.');

        $region = html_entity_decode($dom->saveHTML($node), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $this->assertNotEmpty($region, 'The extracted cockpit region is empty — the fence would pass vacuously.');

        // TOP bracket — the masthead, the first thing in the subtree.
        $this->assertStringContainsString(
            self::PROJECT_NAME,
            $region,
            'The extracted region does not contain the masthead project name, so it is not the cockpit.'
        );

        // BOTTOM bracket — the last element in the page shell. Without this, a
        // truncated extraction would still satisfy the top bracket.
        $this->assertStringContainsString(
            'Open full project',
            $region,
            'The extracted region stops before the end of the page shell — it would leave the module rows unexamined.'
        );

        return $region;
    }

    private const PROJECT_NAME = 'Fence Test Job';

    /**
     * A project with a drawer of every shape: visit rows, a reconstructed row,
     * a superseded row, and document sections. The fence must hold over the
     * richest page this phase can render, not over an empty one.
     */
    private function populatedProject(): Project
    {
        $project = Project::factory()->create([
            'name'   => self::PROJECT_NAME,
            'status' => Project::STATUS_INSTALLING,
        ]);

        $signed = Worksheet::factory()->create(['project_id' => $project->id]);

        // ONE RETURNED PHOTO, ADDED BY PLAN 46.1-04, AND THE REASON MATTERS.
        //
        // The Returned tab's hand-off link renders ONLY for a visit that has
        // photos, so without this row the fence would walk `?tab=returned` on
        // every module and never once see the affordance it had just stopped
        // banning — the lift would be free and the entry could have stayed.
        // That is the vacuity this whole file exists to refuse. With the row,
        // the region really does carry "Download all photos (ZIP)" and the
        // lift is paid for by evidence rather than by assertion.
        WorksheetPhoto::create([
            'worksheet_id'  => $signed->id,
            'room_name'     => 'Boardroom',
            'filename'      => 'worksheet-photos/fence-install-01.jpg',
            'original_name' => 'install-01.jpg',
            'mime_type'     => 'image/jpeg',
            'sort_order'    => 0,
        ]);

        Visit::factory()->backfilledFromWorksheet($signed)->create([
            'project_id'     => $project->id,
            'title'          => 'Install day one',
            'scheduled_date' => '2026-09-02',
        ]);

        $gone   = Worksheet::factory()->create(['project_id' => $project->id]);
        $goneId = $gone->id;
        $gone->delete();

        Visit::factory()->create([
            'project_id'     => $project->id,
            'type'           => Visit::TYPE_INSTALL,
            'title'          => 'Install day two',
            'scheduled_date' => '2026-09-03',
            'source_type'    => Visit::SOURCE_WORKSHEET,
            'source_id'      => $goneId,
        ]);

        Visit::factory()->backfilledFromSurvey()->create([
            'project_id'     => $project->id,
            'title'          => 'Site survey',
            'scheduled_date' => '2026-08-11',
        ]);

        return $project;
    }

    /**
     * @param  array<string, string>  $query  `?module=` / `?tab=` — the page's
     *                                        only user-supplied input.
     */
    private function render(Project $project, array $query = []): string
    {
        config(['cockpit.enabled' => true]);

        $url = route('projects.cockpit', ['project' => $project] + $query);

        return $this->actingAs(User::factory()->create())
            ->get($url)
            ->assertOk()
            ->getContent();
    }

    // -- The fence ----------------------------------------------------------

    /**
     * WIDENED BY PLAN 46-04 to cover every OPEN PANEL, not just the bare page.
     *
     * Phase 45's write affordances were all absent, so the bare page was enough
     * to prove it. Phase 46 puts a form INSIDE the panel, so the panel is now
     * the only place a banned control could appear — a bare-page-only assertion
     * would have gone on passing while anything at all was added to a drawer.
     *
     * @return array<int, string> every region this fence judges
     */
    private function everyRegion(Project $project): array
    {
        $regions = [$this->cockpitRegion($this->render($project))];

        foreach (array_keys(CockpitModulePresenter::moduleMap()) as $moduleKey) {
            foreach (ProjectCockpitController::TABS as $tab) {
                $regions[] = $this->cockpitRegion($this->render($project, ['module' => $moduleKey, 'tab' => $tab]));
            }

            // The Quick actions form, disclosed.
            $regions[] = $this->cockpitRegion($this->render($project, ['module' => $moduleKey, 'action' => 'create-visit']));
        }

        return $regions;
    }

    public function test_the_cockpit_region_contains_no_form_control_and_no_script(): void
    {
        foreach ($this->everyRegion($this->populatedProject()) as $region) {
            foreach (self::FORBIDDEN_MARKUP as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $region,
                    "Read-only fence: {$forbidden} must not appear inside the cockpit. ".
                    'Four entries were lifted by Plan 46-04 BY NAME; these two are rulings.'
                );
            }
        }
    }

    public function test_none_of_the_deferred_affordances_appears(): void
    {
        foreach ($this->everyRegion($this->populatedProject()) as $region) {
            foreach (self::DEFERRED_AFFORDANCES as $copy => $owner) {
                $this->assertStringNotContainsString(
                    $copy,
                    $region,
                    "Read-only fence: \"{$copy}\" is deferred to {$owner} and must not appear yet."
                );
            }
        }
    }

    /**
     * GROWTH BY ITERATION, PROVED RATHER THAN ASSUMED (Plan 46.1-04).
     *
     * everyRegion() walks ProjectCockpitController::TABS, which Plan 46.1-03
     * grew from three entries to four — so the Returned tab joined this
     * fence's coverage the day it existed, without anybody editing this file.
     * That is worth exactly nothing unless the tab really does render inside a
     * judged region, which is the difference between a fence that grew and a
     * fence that merely looks as though it did.
     *
     * So this test says it out loud, and it says it about the ONE string this
     * plan lifted: the region the fence judges carries the hand-off link. If
     * the tab ever stops rendering there, the two assertions above would go on
     * passing over markup that no longer contains the thing they were widened
     * for, and this one goes red instead.
     */
    public function test_the_returned_tab_is_among_the_regions_this_fence_judges(): void
    {
        $this->assertContains('returned', ProjectCockpitController::TABS);

        $regions = $this->everyRegion($this->populatedProject());

        $judged = array_filter(
            $regions,
            fn (string $region): bool => str_contains($region, 'Download all photos (ZIP)')
        );

        $this->assertNotSame(
            [],
            $judged,
            'No judged region carried the hand-off link — the Returned tab is outside this fence '.
            'and the `Download` lift was therefore free.'
        );
    }

    // -- The write surface, fenced on its own terms (Plan 46-04) ----------

    /**
     * A WRITE IS A POST, AND A GET IS STILL INERT.
     *
     * The two GET row-count tests below were kept exactly as Phase 45 wrote
     * them. This one is their counterpart: it proves the POST moves the tables
     * it is supposed to move, and then proves that every GET on the page STILL
     * moves nothing afterwards. Without the second half, a write surface could
     * quietly make the read page write too.
     */
    public function test_a_write_is_a_post_and_a_get_is_still_inert(): void
    {
        Bus::fake();

        $project = $this->populatedProject();

        $before = [];

        foreach (self::WRITE_SURFACE_TABLES as $table) {
            $before[$table] = DB::table($table)->count();
        }

        $this->actingAs(User::factory()->create())
            ->post(route('projects.cockpit.visits.store', $project), [
                'module'     => 'worksheet',
                'visit_type' => Visit::TYPE_INSTALL,
            ])
            ->assertRedirect();

        $after = [];

        foreach (self::WRITE_SURFACE_TABLES as $table) {
            $after[$table] = DB::table($table)->count();
        }

        $this->assertSame($before['visits'] + 1, $after['visits']);
        $this->assertSame($before['worksheets'] + 1, $after['worksheets']);
        $this->assertSame($before['project_activity_logs'] + 1, $after['project_activity_logs']);

        // Untouched by a create, and named so the list cannot quietly grow.
        // GROWN BY PLAN 46.1-04 with the four evidence tables: creating a
        // visit resolves nothing from site, so none of them may move either.
        foreach ([
            'install_records', 'install_programmes', 'site_surveys', 'snags',
            'site_survey_photos', 'worksheet_photos', 'worksheet_signoffs', 'device_label_photos',
        ] as $table) {
            $this->assertSame($before[$table], $after[$table], "A create moved `{$table}`.");
        }

        // Now every GET again — including the one that discloses the form.
        $this->everyRegion($project);

        foreach (self::WRITE_SURFACE_TABLES as $table) {
            $this->assertSame(
                $after[$table],
                DB::table($table)->count(),
                "A GET moved `{$table}` after the write surface existed."
            );
        }
    }

    /**
     * EVERY FORM IN THE REGION CARRIES CSRF (T-46-04-01).
     *
     * A POST form without a token would 419 in production and pass a naive
     * render test — the control would be there, look right, and never work.
     * Asserted structurally in the DOM rather than by substring, so a `_token`
     * belonging to a neighbouring form cannot satisfy it.
     */
    public function test_every_form_in_the_region_carries_a_csrf_token(): void
    {
        $project = $this->populatedProject();

        $checked = 0;

        foreach (array_keys(CockpitModulePresenter::moduleMap()) as $moduleKey) {
            $region = $this->cockpitRegion($this->render($project, [
                'module' => $moduleKey,
                'action' => 'create-visit',
            ]));

            $dom = new \DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="utf-8" ?>'.$region);
            libxml_clear_errors();

            $xpath = new \DOMXPath($dom);

            foreach ($xpath->query('//form') as $form) {
                $checked++;

                $this->assertSame(
                    'POST',
                    strtoupper((string) $form->getAttribute('method')),
                    'Every form inside the cockpit is a POST; there is no GET form on this page.'
                );

                $this->assertSame(
                    1,
                    $xpath->query('.//input[@name="_token"]', $form)->length,
                    "A form in {$moduleKey} carries no CSRF token and would 419 in production."
                );
            }
        }

        // Five module keys offer a Quick action, and two of them disclose a
        // form under ?action=create-visit while three post their generator
        // directly — so the region is never form-free, and this test can never
        // pass vacuously.
        $this->assertGreaterThanOrEqual(5, $checked, 'No form was examined — this test would pass vacuously.');
    }

    public function test_the_fence_enumerates_the_whole_deferred_set(): void
    {
        // MOVED DELIBERATELY, 15 -> 18, by Plan 45-13 when sketch 004's three
        // Quick actions joined the list. MOVED AGAIN, 18 -> 19, by Plan 46-04:
        // two were LIFTED because it ships them ('Create visit', 'Add note')
        // and three were ADDED because it must not ship them ('Assign parts',
        // 'Close snag', 'Mark as sent'). MOVED AGAIN, 19 -> 18, by Plan
        // 46.1-04: exactly ONE entry was lifted ('Download') because that plan
        // ships the Returned tab's photo-archive hand-off (RV-04, D-03), and
        // NOTHING was added, because 46.1 renders no affordance a later phase
        // owns. This number is the anti-rot mechanism: it exists so that
        // dropping an affordance is an edit somebody has to make on purpose.
        // It is never to be deleted to make a change fit.
        $this->assertCount(
            18,
            self::DEFERRED_AFFORDANCES,
            'Every affordance drawn in either sketch is enumerated; nothing is dropped silently.'
        );

        foreach (self::DEFERRED_AFFORDANCES as $copy => $owner) {
            $this->assertNotSame('', trim($copy));
            $this->assertNotSame('', trim($owner));
        }

        // 6 -> 2 (Plan 46-04 lifted four by name), 5 -> 7 (two tables the
        // cockpit's POSTs now write), and 9 -> 9: the handler ban was
        // considered for retirement by the phase that could have retired it,
        // and kept.
        //
        // PLAN 46.1-04 MOVED EXACTLY ONE OF THESE THREE. 7 -> 11, because the
        // Returned tab and the photo-archive GET read four more tables and a
        // GET must still move none of them.
        //
        // 2 -> 2: `<select` and `<script` BOTH STAY. This phase adds no form
        // control (the hand-off is an anchor, not a control) and no
        // JavaScript.
        //
        // 9 -> 9: all nine handler attributes STAY, re-taken for the third
        // time. The contact sheet uses `loading="lazy"` and `decoding="async"`,
        // which are plain HTML attributes and are on no list here; they were
        // checked against this one before use. A review page that works with
        // JavaScript off is the page a PM reads in a plant room.
        $this->assertCount(2, self::FORBIDDEN_MARKUP);
        $this->assertCount(11, self::WRITE_SURFACE_TABLES);
        $this->assertCount(9, self::BANNED_HANDLER_ATTRIBUTES);
    }

    /**
     * Alpine is AVAILABLE here and nonetheless BANNED — see
     * BANNED_HANDLER_ATTRIBUTES for the ruling and for who may retire it.
     *
     * Run over the bare page AND over every open panel: the panel is the
     * newest markup on the page and the likeliest place for a directive to
     * appear, because it is the one part of the design that behaves like a
     * widget.
     */
    public function test_rows_are_static_and_nothing_is_wired_to_a_handler(): void
    {
        $project = $this->populatedProject();

        $regions = [$this->cockpitRegion($this->render($project))];

        foreach (array_keys(CockpitModulePresenter::moduleMap()) as $moduleKey) {
            $regions[] = $this->cockpitRegion($this->render($project, ['module' => $moduleKey]));
        }

        foreach ($regions as $region) {
            foreach (self::BANNED_HANDLER_ATTRIBUTES as $banned) {
                $this->assertStringNotContainsString(
                    $banned,
                    $region,
                    "Read-only fence: `{$banned}` must not appear inside the cockpit. ".
                    'Phase 45 ships no JavaScript; the panel is URL state.'
                );
            }

            $this->assertStringNotContainsString('cursor: pointer', $region);
            $this->assertStringNotContainsString('cursor:pointer', $region);
            $this->assertStringNotContainsString('tabindex', $region);

            // Nothing on this page is a disclosure widget, so nothing may
            // announce an expanded state it does not own.
            $this->assertStringNotContainsString('aria-expanded', $region);
        }
    }

    public function test_the_one_permitted_navigation_affordance_is_present(): void
    {
        $project = $this->populatedProject();
        $region  = $this->cockpitRegion($this->render($project));

        $this->assertStringContainsString('Open full project', $region);
        $this->assertStringContainsString(route('projects.show', $project), $region);
    }

    // -- VIS-06 / criterion 5, asserted directly ---------------------------

    /**
     * Absence of form controls proves the page OFFERS no way to write. This
     * proves that RENDERING it writes nothing — the stronger and more literal
     * reading of "this phase adds no new writes".
     */
    public function test_rendering_the_cockpit_changes_no_row_count(): void
    {
        $project = $this->populatedProject();

        $before = [];

        foreach (self::WRITE_SURFACE_TABLES as $table) {
            $before[$table] = DB::table($table)->count();
        }

        $this->cockpitRegion($this->render($project));

        foreach (self::WRITE_SURFACE_TABLES as $table) {
            $this->assertSame(
                $before[$table],
                DB::table($table)->count(),
                "Rendering the cockpit changed the row count of `{$table}` — Phase 45 adds no new writes."
            );
        }
    }

    /**
     * The bare page was already proved inert. `?module=` is NEW request
     * surface (Plan 45-11) and gets its own proof: opening every module the
     * presenter exposes, on every tab, must leave all five write-surface
     * tables exactly where they were.
     */
    public function test_opening_a_panel_writes_nothing(): void
    {
        $project = $this->populatedProject();

        $before = [];

        foreach (self::WRITE_SURFACE_TABLES as $table) {
            $before[$table] = DB::table($table)->count();
        }

        // Iterated from the presenter's OWN key list, so a tenth module is
        // covered the day it is added rather than the day someone remembers.
        foreach (array_keys(CockpitModulePresenter::moduleMap()) as $moduleKey) {
            foreach (ProjectCockpitController::TABS as $tab) {
                $this->cockpitRegion($this->render($project, ['module' => $moduleKey, 'tab' => $tab]));
            }
        }

        foreach (self::WRITE_SURFACE_TABLES as $table) {
            $this->assertSame(
                $before[$table],
                DB::table($table)->count(),
                "Opening a panel changed the row count of `{$table}` — a GET on this page writes nothing."
            );
        }
    }

    /**
     * An unrecognised `?module=` is a stale bookmark, not an error worth
     * showing a PM. It renders 200 with the panel closed, and the submitted
     * value is NEVER echoed — asserted on the RAW body rather than on the
     * entity-decoded subtree, so even an escaped reflection fails here.
     */
    public function test_a_hostile_module_value_is_not_reflected(): void
    {
        $project = $this->populatedProject();

        $payloads = [
            '<script>alert(1)</script>',
            str_repeat('a', 5000),
        ];

        foreach ($payloads as $payload) {
            $body = $this->render($project, ['module' => $payload]);

            $this->assertStringNotContainsString(
                $payload,
                $body,
                'A submitted ?module= value must never be reflected into the page.'
            );

            // Still bracket-valid: cockpitRegion() asserts both ends, so a
            // payload that broke the page would fail inside this call.
            $region = $this->cockpitRegion($body);

            $this->assertStringNotContainsString('cav-panel', $region, 'An unknown module opens nothing.');
        }
    }

    public function test_repeated_renders_still_change_no_row_count(): void
    {
        $project = $this->populatedProject();

        $before = [];

        foreach (self::WRITE_SURFACE_TABLES as $table) {
            $before[$table] = DB::table($table)->count();
        }

        foreach (range(1, 3) as $ignored) {
            $this->cockpitRegion($this->render($project));
        }

        foreach (self::WRITE_SURFACE_TABLES as $table) {
            $this->assertSame($before[$table], DB::table($table)->count(), "`{$table}` row count moved.");
        }
    }
}
