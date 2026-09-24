<?php

namespace Tests\Feature\Cockpit;

use App\Http\Controllers\ProjectCockpitController;
use App\Models\Project;
use App\Models\User;
use App\Models\Visit;
use App\Models\Worksheet;
use App\Models\WorksheetPhoto;
use App\Models\WorksheetSignoff;
use App\Support\Cockpit\CockpitModulePresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

        // ══ THREE ENTRIES RETURNED, 18 -> 21, BY 46.2 D-02 (Plan 46.2-03) ═══
        //
        // THE PRINCIPLE THAT BOUNDS THIS, STATED SO THE LIST CANNOT GROW WITHOUT
        // LIMIT: an entry returns to this list when, and ONLY when, it was once
        // LIFTED FROM IT and the affordance it named has since LEFT THE PAGE.
        // Both halves are required. That is why exactly three come back and not
        // seven: 'Accept', 'Send back' and 'Raise a snag' also left the page, but
        // they were never entries here — they arrived in 46-05/46-06/46-07
        // without ever having been deferred — so they do not return. (They could
        // not, either: the visit row still REPORTS "Sent back" and "Accepted by"
        // as status copy under 46.2 D-06, and banning those strings would ban the
        // row's own record.)
        //
        // THE OWNER STRING SAYS "Unsurfaced", NEVER "Phase NN". These three are
        // not deferred. They exist, they are tested, and they work TODAY at the
        // routes named. Writing a phase number would tell the next reader they
        // are unbuilt, which is the opposite of the truth.
        'Create visit' => 'Unsurfaced by 46.2 D-02 — lives at projects.cockpit.visits.store',
        'Add note'     => 'Unsurfaced by 46.2 D-02 — lives at projects.cockpit.visits.notes',
        'Download'     => 'Unsurfaced by 46.2 D-02 — the ZIP still lives at projects.cockpit.visits.photos-zip',

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
        // BOTH RETURNED ABOVE BY 46.2 D-02 (Plan 46.2-03), because the cockpit
        // stopped rendering them. They are still SHIPPED — this is the one case
        // where an entry on this list names something that exists and works.
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

        // GROWN 11 -> 13 BY PLAN 46.2-05, and these two are named for a specific
        // reason rather than added for completeness.
        //
        // The document form's DISCLOSURE — `?action=generate`, a GET — reads both.
        // `ProjectCockpitController::documentValues()` reads `project_packages`
        // (the RAMS reviewed payload, so a field the PM filled in last month comes
        // back filled in) and `resourceNames()` reads `labour_resources` (the
        // engineer and programmer NAMES a `resource-list` field offers, LR-04).
        //
        // THAT IS EXACTLY WHY THEY BELONG HERE. This is the first cockpit GET that
        // reads a table the cockpit also WRITES to on the neighbouring POST
        // (`project_packages`), so "opening the form changes nothing" stops being
        // obvious and starts needing proof. All three row-count tests below ITERATE
        // this constant, so they picked these two up the moment the names landed.
        'labour_resources',
        'project_packages',
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

            // THE `?action=generate` RENDER, REPLACING THE RETIRED
            // `?action=create-visit` ONE (Plan 46.2-05, as 46.2-03 said it would).
            //
            // 46-04 added a `create-visit` render here because the form was the
            // only place a banned control could appear; 46.2-03 retired it when
            // `ACTIONS` went empty, naming this plan as the one that would put it
            // back. THIS IS THE MOST INPUT-HEAVY SURFACE THIS FENCE HAS EVER
            // COVERED — the RAMS form alone discloses fourteen controls across five
            // fieldsets plus a format radio group — and covering it is the whole
            // point of the fence. A bare-page or closed-panel-only sweep would go
            // on passing while anything at all was added to the disclosed form.
            $regions[] = $this->cockpitRegion($this->render($project, [
                'module' => $moduleKey,
                'action' => 'generate',
            ]));
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
     * RETIRED AND REPLACED BY ITS EXACT INVERSE (46.2 D-02, Plan 46.2-03).
     *
     * WAS `test_the_returned_tab_is_among_the_regions_this_fence_judges()`. Plan
     * 46.1-04 wrote it to PAY FOR the `Download` lift: `everyRegion()` grew a
     * fourth tab automatically because it iterates TABS, and that growth is worth
     * nothing unless the tab really renders inside a judged region. So it
     * asserted `assertContains('returned', TABS)` and that some judged region
     * carried "Download all photos (ZIP)".
     *
     * `returned` has left TABS and the hand-off link has left the page, so both
     * assertions are impossible. `Download` RETURNED to DEFERRED_AFFORDANCES in
     * the same commit, which is the bookkeeping half — and this is the half that
     * matters:
     *
     * ── THE ONE ASSERTION THAT MAKES "UNSURFACED" MEAN ANYTHING ──────────────
     *
     * Removing a link is not removing a capability, and the difference has to be
     * PROVED or the next reader is entitled to assume the phase deleted the visit
     * workflow. So: every one of the five POSTs and both evidence GETs is driven
     * for real, and every judged region is checked to carry no link to any of
     * them. The routes answer; the page offers nothing.
     *
     * This is also the executable form of threat register entries T-46.2-06 and
     * T-46.2-07, both dispositioned `accept`: these routes were never protected
     * by the absence of a link, and their real guards — `auth`, `@csrf`,
     * per-route validation and route-bound `{project}` scoping — are untouched.
     */
    public function test_the_unsurfaced_write_routes_all_still_work_with_no_link_on_the_page(): void
    {
        Bus::fake();

        $this->assertNotContains('returned', ProjectCockpitController::TABS);

        // MOVED BY NAME, `[]` -> `['generate']` (Plan 46.2-05). `ACTIONS` is no
        // longer empty, because that plan re-surfaced the DOCUMENT form on exactly
        // the mechanism 46.2-03 kept dormant for it. The property this test cares
        // about is unchanged and is now asserted DIRECTLY rather than by emptiness:
        // NONE OF THE FOUR VISIT DISCLOSURES IS A LEGAL ACTION. An empty list said
        // that only by accident.
        $this->assertSame(['generate'], ProjectCockpitController::ACTIONS);

        foreach (['create-visit', 'send-back', 'note', 'snag'] as $retired) {
            $this->assertNotContains(
                $retired,
                ProjectCockpitController::ACTIONS,
                "`{$retired}` is unsurfaced by 46.2 D-02 and must not be a disclosable action."
            );
        }

        $project = $this->populatedProject();
        $pm      = User::factory()->create();

        // A visit that has come back from site AND carries a photo, so all five
        // POSTs reach their happy path rather than a 422 and both evidence GETs
        // have real bytes to serve. Built here rather than fished out of
        // populatedProject(): that fixture's sourced visits are a RECONSTRUCTED
        // one (closed, and deliberately not annotatable) and one whose source was
        // force-deleted, so neither exercises the five acts.
        $worksheet = Worksheet::factory()->create(['project_id' => $project->id]);

        // The photo GET streams real bytes, so the disk is faked and the file is
        // written. Without it the route is a correct 404 and this test would
        // "prove" the GET was gone when it was only empty.
        Storage::fake('local');
        Storage::disk('local')->put('worksheet-photos/fence-unsurfaced-01.jpg', 'not-really-a-jpeg');

        WorksheetPhoto::create([
            'worksheet_id'  => $worksheet->id,
            'room_name'     => 'Comms room',
            'filename'      => 'worksheet-photos/fence-unsurfaced-01.jpg',
            'original_name' => 'unsurfaced-01.jpg',
            'mime_type'     => 'image/jpeg',
            'sort_order'    => 0,
        ]);

        WorksheetSignoff::create([
            'worksheet_id'         => $worksheet->id,
            'client_name'          => 'A Client',
            'signature_png_base64' => 'iVBORw0KGgo=',
            'signed_with_comments' => false,
            'signed_at'            => now()->subHour(),
        ]);

        $visit = Visit::factory()->create([
            'project_id'     => $project->id,
            'type'           => Visit::TYPE_INSTALL,
            'title'          => 'Unsurfaced acts',
            'scheduled_date' => '2026-09-04',
            'status'         => Visit::STATUS_PLANNED,
            'sent_at'        => now()->subDays(2),
            'source_type'    => Visit::SOURCE_WORKSHEET,
            'source_id'      => $worksheet->id,
        ]);

        $this->assertSame(
            Visit::STATE_RETURNED,
            $visit->state(),
            'The fixture must be genuinely returned, or these POSTs would 422 and prove nothing.'
        );

        // ── 1. ALL FIVE POSTS ANSWER ────────────────────────────────────────
        // Each against its own visit where the state machine demands it: accept
        // closes the visit and send-back moves it out of RETURNED.
        $this->actingAs($pm)->post(route('projects.cockpit.visits.store', $project), [
            'module'     => 'worksheet',
            'visit_type' => Visit::TYPE_INSTALL,
        ])->assertRedirect();

        $this->actingAs($pm)->post(route('projects.cockpit.visits.notes', [
            'project' => $project, 'visit' => $visit,
        ]), ['body' => 'Unsurfaced, not deleted.'])->assertRedirect();

        $this->actingAs($pm)->post(route('projects.cockpit.visits.snags', [
            'project' => $project, 'visit' => $visit,
        ]), ['title' => 'Raised with no button'])->assertRedirect();

        $this->actingAs($pm)->post(route('projects.cockpit.visits.send-back', [
            'project' => $project, 'visit' => $visit,
        ]), ['reason' => 'Sent back with no button.'])->assertRedirect();

        $this->actingAs($pm)->post(route('projects.cockpit.visits.accept', [
            'project' => $project, 'visit' => $visit,
        ]))->assertRedirect();

        // ── 2. BOTH EVIDENCE GETS ANSWER ────────────────────────────────────
        $zip = route('projects.cockpit.visits.photos-zip', ['project' => $project, 'visit' => $visit]);

        $this->actingAs($pm)->get($zip)->assertOk();

        $photo = WorksheetPhoto::where('worksheet_id', $worksheet->id)->firstOrFail();

        $photoUrl = route('projects.cockpit.visits.photo', [
            'project' => $project,
            'visit'   => $visit,
            'kind'    => 'worksheet',
            'photo'   => $photo->id,
        ]);

        $this->actingAs($pm)->get($photoUrl)->assertOk();

        // ── 3. AND NOT ONE OF THEM IS LINKED FROM ANYWHERE ON THE PAGE ──────
        $urls = [
            route('projects.cockpit.visits.store', $project),
            route('projects.cockpit.visits.notes', ['project' => $project, 'visit' => $visit]),
            route('projects.cockpit.visits.snags', ['project' => $project, 'visit' => $visit]),
            route('projects.cockpit.visits.send-back', ['project' => $project, 'visit' => $visit]),
            route('projects.cockpit.visits.accept', ['project' => $project, 'visit' => $visit]),
            $zip,
            $photoUrl,
        ];

        $this->assertCount(7, $urls, 'Five POSTs and two evidence GETs — the whole unsurfaced set.');

        $judged = 0;

        foreach ($this->everyRegion($project) as $region) {
            $judged++;

            foreach ($urls as $url) {
                $this->assertStringNotContainsString(
                    $url,
                    $region,
                    "The cockpit links to `{$url}`. 46.2 D-02 unsurfaces these routes; a link here undoes it."
                );
            }

            // Belt and braces on the path shape, so a relative or re-signed href
            // could not slip past the absolute-URL comparison above.
            $this->assertStringNotContainsString('/cockpit/visits/', $region);
        }

        $this->assertGreaterThan(0, $judged, 'No region was judged — this test would pass vacuously.');
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
     *
     * ── THE ANTI-VACUITY FLOOR BECOMES AN EXACT ZERO (46.2 D-02, Plan 46.2-03) ─
     *
     * This test carried `assertGreaterThanOrEqual(5, $checked)`. That was the one
     * place in this file contrary to the repo's exact-count house rule, flagged as
     * finding F-7 by Plan 46.2-01 and deliberately left for this plan, which owns
     * the fence this wave. It is now exact.
     *
     * AND THE NUMBER IS ZERO, because 46.2 D-02 removed the last form from the
     * cockpit region. Stating that as `assertSame(0, $checked)` inverts the
     * assertion's purpose on purpose: while the count was five it existed to stop
     * the per-form loop passing over nothing; at zero it IS the claim — there is
     * no form on this page — and the per-form loop is kept, unedited, so that the
     * FIRST form to arrive is token-checked on the day it lands.
     *
     * PLAN 46.2-05 MOVES THIS NUMBER BY NAME when it ships the document form. It
     * moves to the exact number of forms that form renders — never back to a
     * `>=`, and never by deleting this assertion.
     */
    public function test_every_form_in_the_region_carries_a_csrf_token(): void
    {
        $project = $this->populatedProject();

        $checked = 0;

        foreach (array_keys(CockpitModulePresenter::moduleMap()) as $moduleKey) {
            // WAS `'action' => 'create-visit'` (46-04), then the bare panel while
            // `ACTIONS` was empty (46.2-03). NOW `'action' => 'generate'` — the
            // document form's disclosure, which is the only form on this page.
            $region = $this->cockpitRegion($this->render($project, [
                'module' => $moduleKey,
                'action' => 'generate',
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

        // ══ 0 -> 4, AN EXACT POSITIVE (Plan 46.2-05) ════════════════════════
        //
        // The history of this number is `>= 5` (46-04) -> `=== 0` (46.2-03,
        // finding F-7) -> `=== 4` (here). It has NEVER gone back to a floor and
        // must not: `assertGreaterThanOrEqual` was removed from this file by
        // 46.2-03 and is not to be reintroduced.
        //
        // FOUR IS ONE FORM PER MODULE, which is the design and not a coincidence:
        // the panel offers exactly one control per row, and opening it discloses
        // exactly one form. A FIFTH form inside the cockpit region is a red test
        // — and so is a THIRD, because a module that stopped disclosing its form
        // is a cockpit that has quietly stopped being able to generate that
        // document, which is precisely the state 46.2-03 left behind and this plan
        // exists to end.
        //
        // The literal is kept rather than derived from `moduleMap()` so this test
        // pins the ARITHMETIC (one form per row), with the module count asserted
        // next door so drift in either is loud.
        $this->assertSame(
            4,
            $checked,
            'Exactly one document form per module panel. Move this number BY NAME, never to a floor.'
        );

        $this->assertCount(
            4,
            CockpitModulePresenter::moduleMap(),
            'Four rows since 46.2-01 (D-01) — the other half of the four above.'
        );
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
        //
        // MOVED AGAIN, 18 -> 21, BY 46.2 D-02 (Plan 46.2-03). THIS IS THE FIRST
        // TIME THE NUMBER HAS GONE UP BY RETURN RATHER THAN BY DEFERRAL, so the
        // rule that licenses it is written into the list itself, beside the three
        // entries: AN ENTRY RETURNS WHEN, AND ONLY WHEN, IT WAS ONCE LIFTED FROM
        // THIS LIST AND THE AFFORDANCE IT NAMED HAS SINCE LEFT THE PAGE. Both
        // halves. 'Create visit' and 'Add note' were lifted by 46-04 and
        // 'Download' by 46.1-04; all three left the page in 46.2-03.
        //
        // Their owner strings read "Unsurfaced by 46.2 D-02 — lives at {route}",
        // never a phase number, because unlike every other entry here these three
        // ARE BUILT AND DO WORK. The history of this number is now
        // 15 -> 18 -> 19 -> 18 -> 21.
        $this->assertCount(
            21,
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
        //
        // ══ 46.2-03 RE-TAKES ALL THREE. NONE IS INHERITED ═══════════════════
        //
        // 2 -> 2: `<select` AND `<script` BOTH STAY, AND `<select` WAS GENUINELY
        // UP FOR RETIREMENT. Phase 46.2 ships the most input-heavy surface this
        // page has carried — a per-document form with a field set per document
        // type — and 46.2-CONTEXT's own code notes say so and invite the lift. It
        // stays because Plan 46.2-04's field map resolves to text, date, checkbox
        // and two-to-five-option radio groups and nothing else: not one field
        // needs a dropdown. If Plan 46.2-05 finds one that genuinely does, IT
        // LIFTS THE ENTRY BY NAME IN THE COMMIT THAT SHIPS THE CONTROL, exactly as
        // 46-04 and 46.1-04 did. Never by deletion.
        //
        // AND THE FOUR ENTRIES 46-04 LIFTED (`<form`, `<input`, `<button`,
        // `<textarea`) DO NOT RETURN, even though every form has left the page and
        // the DEFERRED_AFFORDANCES return principle would otherwise apply. Stated
        // because a reader who has just read that principle will ask: re-banning
        // `<form` here would make Plan 46.2-05 — the very next plan, already
        // planned — lift it again three commits later. An entry that would be
        // lifted again immediately is churn, not a fence. The forms' ABSENCE is
        // asserted instead, exactly and in three places:
        // test_every_form_in_the_region_carries_a_csrf_token() at 0,
        // CockpitCreateVisitTest::test_no_module_panel_renders_any_visit_control()
        // and CockpitVisitActionsTest::test_no_visit_row_in_any_state_renders_any_control().
        //
        // 9 -> 9: RE-TAKEN FOR THE FOURTH TIME. There is still no JavaScript on
        // this page, and there is still no pressure to add any: the document
        // form's disclosure is `?action=` query-string state, the same mechanism
        // the visit forms used, so the strongest argument for Alpine — a
        // progressive-enhancement need — has not appeared in four phases.
        //
        // 11 -> 11: UNCHANGED BY THIS PLAN, and said out loud so the next reader
        // knows the number was CONSIDERED rather than skipped. 46.2-03 removes
        // surfacing only; it reads no new table and writes none. PLAN 46.2-05
        // MOVES IT — generating a document writes rows this list does not yet
        // name.
        //
        // ══ 46.2-05 SHIPPED THE FORM. ALL THREE JUDGED AGAINST IT ═══════════
        //
        // 2 -> 2: `<select` STAYS, AND THIS IS THE COMMIT THAT COULD HAVE LIFTED
        // IT. 46.2-03 named the condition — "if Plan 46.2-05 finds a field that
        // genuinely needs a dropdown, it lifts the entry BY NAME in the commit
        // that ships the control". IT FOUND NONE. The disclosed forms resolve to
        // text, date, time, textarea, checkbox, radio (two to four options) and
        // `resource-list` (checkboxes for a list-valued field, radios for a
        // single-valued one) — seven types, closed set, not one dropdown. The
        // widest option set on the page is the comms-room access radio at FOUR,
        // and four radios read better than a four-item dropdown on a phone in a
        // plant room. So the ruling held against the hardest case it has faced.
        // `<script` stays too; there is none.
        //
        // AND THE FOUR ENTRIES 46-04 LIFTED (`<form`, `<input`, `<button`,
        // `<textarea`) DO NOT RETURN — which 46.2-03 predicted exactly: it kept
        // them off the list because re-banning them would mean lifting them again
        // three commits later. This is that commit, and all four are back on the
        // page. The prediction is recorded as CORRECT.
        //
        // 9 -> 9: RE-TAKEN FOR THE FIFTH TIME, on the most input-heavy surface
        // this page has carried. The document form ships with NO directive and NO
        // handler: its disclosure is `?action=generate` query state, its cancel is
        // an anchor, and its submit is a real form POST. Alpine is still loaded
        // globally and still available; the strongest argument for it — a
        // progressive-enhancement need — has now failed to appear in FIVE phases,
        // including the one that added a fourteen-field form. Retiring this list
        // still means deciding that the cockpit ships JavaScript, and that
        // decision still belongs to whoever writes the first line of it.
        //
        // 11 -> 13: MOVED, BY NAME, with the reason inline at the two new entries.
        //
        // 21 -> 21: `DEFERRED_AFFORDANCES` IS UNCHANGED, and the check was made
        // rather than skipped. The form's one piece of copy is `Generate document`
        // — the retired quick-actions' own word — and it was checked against every
        // entry on that list before use: it is on none of them, while every
        // neighbouring string a designer might have reached for IS banned
        // (`Add document`, `Upload files`, `Issue to client`, `Mark as sent`,
        // `Download`, `Export CSV`, `Open register`). Nothing was lifted, because
        // nothing this plan ships was ever deferred: the generate control is a
        // capability the page HAD and lost in 46.2-03, and a re-surfacing is not a
        // deferral coming due. Asserted for real over every judged region —
        // including the four `?action=generate` renders — by
        // test_none_of_the_deferred_affordances_appears().
        $this->assertCount(2, self::FORBIDDEN_MARKUP);
        $this->assertCount(13, self::WRITE_SURFACE_TABLES);
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
     *
     * WIDENED TO everyRegion() BY PLAN 46.1-04, AND THE BREAKAGE RITUAL IS
     * WHY. This test used to open each module on its DEFAULT tab only, so it
     * judged `?tab=overview` and nothing else. That was adequate while there
     * were three tabs and the drawers were the new markup; it stopped being
     * adequate the moment Plan 46.1-03 added a fourth. 46.1-04's third
     * breakage — `<button onclick="void 0">Upload files</button>` injected
     * into returned-tab.blade.php — expected TWO reds and produced ONE: the
     * deferred-affordance assertion caught `Upload files` because IT walks
     * everyRegion(), and this one missed the `onclick` entirely because it did
     * not. A handler could have been added to the Returned tab and the Alpine
     * ban would have gone on showing green.
     *
     * That is exactly the rot the ritual exists to find, found by running it
     * rather than by reading the file. The two assertions now judge the same
     * regions, which is what "the fence" ought to have meant all along.
     */
    public function test_rows_are_static_and_nothing_is_wired_to_a_handler(): void
    {
        $regions = $this->everyRegion($this->populatedProject());

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
