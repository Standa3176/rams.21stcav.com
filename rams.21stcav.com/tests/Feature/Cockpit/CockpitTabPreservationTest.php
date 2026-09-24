<?php

namespace Tests\Feature\Cockpit;

use App\Http\Controllers\ProjectCockpitController;
use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 46.1, Plan 46.1-06 — AN ACT INITIATED FROM THE RETURNED TAB COMES
 * BACK TO THE RETURNED TAB.
 *
 * THE DEFECT THIS FILE CLOSES. Plan 46.1-05 moved D-02's four acts beneath the
 * evidence, which is the whole point of the phase — a PM reads what came back
 * from site and only then decides. But all five cockpit writes redirected to
 * the module's TABLESS URL, which `ProjectCockpitController::resolveTab()`
 * resolves to `TABS[0]` — Overview. So every act bounced the PM off the tab
 * they were working on, and the three `Cancel` links did the same. 46.1-05
 * logged it and correctly left it alone (out of its plan's file list); it is
 * fixed here because it breaks the workflow this phase exists to create.
 *
 * HOW THE TAB TRAVELS. There is no JavaScript anywhere in the cockpit region —
 * all nine handler attributes are still banned by `CockpitReadOnlyFenceTest`.
 * So the tab rides in a hidden field (`<x-cockpit.tab-field>`) on each of the
 * four forms, and in the `href` of each `Cancel`.
 *
 * AND IT IS VALIDATED, TWICE, AGAINST THE SAME CONSTANT. A submitted `tab` is
 * membership-resolved against `ProjectCockpitController::TABS` on the way in
 * (`ProjectCockpitActionController::withTab()`) and on the way out
 * (`tab-field.blade.php`). An unknown value is DROPPED and the redirect falls
 * back to exactly the behaviour these acts shipped with — it is
 * never echoed into a `Location` header, a URL or the page. That is the
 * difference between carrying a tab and reflecting user input.
 */
class CockpitTabPreservationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A value that must never reach a header or the page. If the fallback ever
     * becomes "echo whatever was submitted", this string is what proves it.
     */
    private const HOSTILE_TAB = '"><script>alert(1)</script>';

    protected function setUp(): void
    {
        parent::setUp();

        config(['cockpit.enabled' => true]);
    }

    private function pm(): User
    {
        return User::factory()->create(['name' => 'Priya Mistry']);
    }

    /**
     * A survey an engineer HAS submitted, wrapped by a visit that was sent —
     * the same fixture shape `CockpitVisitActionsTest` uses, so this file is
     * judging the same row those tests judge.
     */
    private function returnedVisit(Project $project): Visit
    {
        $survey = SiteSurvey::create([
            'user_id'      => User::factory()->create(['name' => 'Engineer Eve'])->id,
            'project_id'   => $project->id,
            'project_name' => 'Tab Preservation Fixture',
            'status'       => 'completed',
        ]);

        $survey->forceFill([
            'submitted_at' => now()->subDays(2),
            'survey_data'  => ['comms_room' => 'Second floor, keyed access'],
        ])->save();

        $survey->rooms()->create([
            'room_name'  => 'Board Room',
            'space_type' => 'general',
            'sort_order' => 0,
        ]);

        return Visit::factory()->create([
            'project_id'  => $project->id,
            'type'        => Visit::TYPE_SITE_SURVEY,
            'status'      => Visit::STATUS_PLANNED,
            'sent_at'     => now()->subDays(3),
            'source_type' => Visit::SOURCE_SITE_SURVEY,
            'source_id'   => $survey->id,
        ]);
    }

    private function project(): Project
    {
        return Project::factory()->create([
            'name'   => 'Tab Preservation Job',
            'status' => Project::STATUS_INSTALLING,
        ]);
    }

    /** The module the survey fixture's drawer lives in. */
    private const MODULE = 'site_survey';

    /**
     * WAS `returnedUrl()`, hard-coding `tab=returned`.
     *
     * 46.2 D-02 (Plan 46.2-03) took `returned` out of
     * ProjectCockpitController::TABS, so it can no longer be carried. Default
     * `files` rather than `notes`, ON PURPOSE: `storeNote()` hard-codes
     * `tab=notes`, and a submitted tab that happened to equal the hard-coded one
     * would make the "the submitted tab WINS" case below prove nothing.
     */
    private function tabUrl(Project $project, string $tab = 'files'): string
    {
        return route('projects.cockpit', [
            'project' => $project,
            'module'  => self::MODULE,
            'tab'     => $tab,
        ]);
    }

    /*
     * RETIRED BY NAME, 46.2 D-02, Plan 46.2-03 — unsurfaced, not deleted:
     *
     *   test_the_returned_tab_carries_its_own_tab_into_every_control()
     *     asserted `<input type="hidden" name="tab" value="returned">` on the
     *     closed Accept form and on each of the three disclosed forms, plus that
     *     each form's `Cancel` anchor went back to the Returned tab.
     *
     * There are no forms and no Cancel anchors on the cockpit any more, so this
     * is impossible rather than failing. It also carried an
     * `assertGreaterThanOrEqual(2, ...)` that this repo's exact-count rule would
     * not accept today.
     *
     * THE MECHANISM IT GUARDED IS FULLY KEPT, on the POST side where it actually
     * matters: `test_every_act_initiated_from_a_real_tab_returns_to_it()` below
     * proves all four acts round-trip a submitted tab,
     * `test_every_real_tab_is_carried_and_only_a_real_tab_is()` proves membership
     * resolution against TABS, `test_a_hostile_tab_value_is_never_reflected()`
     * proves the rejection, and `test_an_act_with_no_tab_redirects_exactly_as_it_did_before()`
     * proves the fallback. All four are green and unedited. What Plan 46.2-05
     * must do when it ships the document form is re-assert the hidden field for
     * THAT form — which is a new control, not this one restored.
     */

    /**
     * ALL FOUR ACTS, EACH INITIATED FROM A REAL TAB, LAND BACK ON IT.
     *
     * `returned` SWAPPED FOR `files` throughout (46.2 D-02, Plan 46.2-03). The
     * tab mechanism is NOT what changed — `returned` simply stopped being a legal
     * tab — so every case is kept and only the string moved.
     *
     * Each act runs against its OWN visit: accept closes the visit and send
     * back moves it out of RETURNED, so sharing one row would test the refusal
     * path rather than the redirect.
     */
    public function test_every_act_initiated_from_a_real_tab_returns_to_it(): void
    {
        $project = $this->project();
        $pm      = $this->pm();
        $back    = $this->tabUrl($project);

        $this->actingAs($pm)->post(
            route('projects.cockpit.visits.accept', ['project' => $project, 'visit' => $this->returnedVisit($project)]),
            ['tab' => 'files'],
        )->assertRedirect($back);

        $this->actingAs($pm)->post(
            route('projects.cockpit.visits.send-back', ['project' => $project, 'visit' => $this->returnedVisit($project)]),
            ['tab' => 'files', 'reason' => 'The comms room photo is missing.'],
        )->assertRedirect($back);

        // The note act is still the interesting one: it hard-codes `tab=notes`,
        // and the submitted tab must WIN. `files` is submitted precisely so that
        // this case still distinguishes the two.
        $this->actingAs($pm)->post(
            route('projects.cockpit.visits.notes', ['project' => $project, 'visit' => $this->returnedVisit($project)]),
            ['tab' => 'files', 'body' => 'Checked against the survey; content is complete.'],
        )->assertRedirect($back);

        $this->actingAs($pm)->post(
            route('projects.cockpit.visits.snags', ['project' => $project, 'visit' => $this->returnedVisit($project)]),
            ['tab' => 'files', 'title' => 'Trunking short by 400mm'],
        )->assertRedirect($back);
    }

    /**
     * NO TAB SUBMITTED — TODAY'S BEHAVIOUR, UNCHANGED.
     *
     * The fallback is not "some tab": it is the exact tabless URL these acts
     * redirected to before this plan, including `storeNote`'s `tab=notes`.
     */
    public function test_an_act_with_no_tab_redirects_exactly_as_it_did_before(): void
    {
        $project = $this->project();
        $pm      = $this->pm();

        $tabless = route('projects.cockpit', ['project' => $project, 'module' => self::MODULE]);

        $this->actingAs($pm)->post(
            route('projects.cockpit.visits.accept', ['project' => $project, 'visit' => $this->returnedVisit($project)]),
        )->assertRedirect($tabless);

        $this->actingAs($pm)->post(
            route('projects.cockpit.visits.notes', ['project' => $project, 'visit' => $this->returnedVisit($project)]),
            ['body' => 'An office note added from Overview.'],
        )->assertRedirect(route('projects.cockpit', [
            'project' => $project,
            'module'  => self::MODULE,
            'tab'     => 'notes',
        ]));
    }

    /**
     * A HOSTILE TAB IS REJECTED, NOT REFLECTED.
     *
     * Two properties, and the second is the one that matters: the act still
     * succeeds (a bad tab is not a reason to refuse a PM's acceptance), and the
     * submitted bytes appear NOWHERE — not in the `Location` header, not in the
     * rendered page that follows it.
     */
    public function test_a_hostile_tab_value_is_never_reflected(): void
    {
        $project = $this->project();
        $pm      = $this->pm();
        $visit   = $this->returnedVisit($project);

        $response = $this->actingAs($pm)->post(
            route('projects.cockpit.visits.accept', ['project' => $project, 'visit' => $visit]),
            ['tab' => self::HOSTILE_TAB],
        );

        $location = (string) $response->headers->get('Location');

        $response->assertRedirect(route('projects.cockpit', [
            'project' => $project,
            'module'  => self::MODULE,
        ]));

        foreach (['script', 'alert(1)', 'tab='] as $fragment) {
            $this->assertStringNotContainsString(
                $fragment,
                $location,
                'A tab that is not in ProjectCockpitController::TABS must not reach the Location header.',
            );
        }

        // The act itself still happened — the fallback is a redirect target,
        // not a refusal.
        $this->assertNotNull($visit->refresh()->accepted_at);

        // And the page it lands on carries nothing of what was submitted.
        $page = $this->actingAs($pm)->get($location)->assertOk()->getContent();
        $this->assertStringNotContainsString('alert(1)', $page);
    }

    /**
     * THE CARRIER IS THE CONSTANT, NOT A SECOND COPY OF THE LIST.
     *
     * Every tab the read controller will resolve is carried by the write
     * controller, and nothing else is. Asserted by ITERATING `TABS` rather than
     * by naming four strings, so a fifth tab added later is covered on the day
     * it is added — the same reason `CockpitPageTest` iterates it.
     */
    public function test_every_real_tab_is_carried_and_only_a_real_tab_is(): void
    {
        $project = $this->project();
        $pm      = $this->pm();

        foreach (ProjectCockpitController::TABS as $tab) {
            $this->actingAs($pm)->post(
                route('projects.cockpit.visits.accept', ['project' => $project, 'visit' => $this->returnedVisit($project)]),
                ['tab' => $tab],
            )->assertRedirect(route('projects.cockpit', [
                'project' => $project,
                'module'  => self::MODULE,
                'tab'     => $tab,
            ]));
        }

        // `'returned'` ADDED TO THIS LIST BY 46.2 D-02 (Plan 46.2-03). It was a
        // REAL tab when this list was written, which is why only `'returned"'`
        // appeared; it is a non-tab now, and asserting its rejection here is the
        // POST-side half of `returned` leaving
        // ProjectCockpitController::TABS.
        //
        // STILL NOT in this list: `'returned '`. The `TrimStrings` middleware
        // trims it before the controller sees it, so it would now be rejected for
        // the same reason as the bare string rather than for its own, and
        // asserting it would be asserting against the framework. Case, traversal,
        // emptiness and encoding ARE the rule's business, and each is here.
        foreach (['returned', 'RETURNED', 'overview/../files', '', '0', 'notes%00', 'returned"'] as $notATab) {
            $this->actingAs($pm)->post(
                route('projects.cockpit.visits.accept', ['project' => $project, 'visit' => $this->returnedVisit($project)]),
                ['tab' => $notATab],
            )->assertRedirect(route('projects.cockpit', [
                'project' => $project,
                'module'  => self::MODULE,
            ]));
        }
    }
}
