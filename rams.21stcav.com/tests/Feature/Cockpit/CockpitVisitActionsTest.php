<?php

namespace Tests\Feature\Cockpit;

use App\Http\Controllers\ProjectCockpitController;
use App\Models\Project;
use App\Models\ProjectActivityLog;
use App\Models\SiteSurvey;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 46, Plan 46-06 — the PM's first two actions on a returned visit:
 * ACCEPT and SEND BACK, plus the visit row's action area that USED to offer them.
 *
 * ── THE ROUTES ARE THE FILE; THE OFFERS ARE GONE (46.2 D-02, Plan 46.2-03) ──
 * The cockpit no longer renders a visit control of any kind. Both acts still
 * exist, still validate, still log and still 404 on a foreign project — proved by
 * the twenty-one route tests below, every one of them UNEDITED. What changed is
 * the render half: every "offers N controls" assertion is now "offers zero",
 * moved BY NAME with the test it was copied from, and VL-11's ceiling of four is
 * a ceiling of zero.
 *
 * THE ONE SENTENCE THIS WHOLE FILE EXISTS TO PROVE (D-02): AN OFFICE ACTION
 * NEVER CHANGES WHAT THE ENGINEER SAID. Accept records who and when; send back
 * records one reason. Neither rewrites the visit's stored `status`, and neither
 * writes a single byte to `site_surveys`, `worksheets` or `worksheet_signoffs`
 * — asserted by row count AND by comparing `submitted_at`, `survey_data` and
 * `access_token` before and after, rather than intended in a comment.
 *
 * THE BACKFILL TRAP (46-01's finding, pinned there and honoured here):
 * `Visit::state()` returns STATE_RETURNED — not STATE_CLOSED — for a
 * reconstructed visit whose worksheet carries a sign-off. There are 24 such
 * rows on live. A review surface built on `state() === STATE_RETURNED` would
 * greet a PM with two dozen phantom review items for work finished years ago,
 * so the action area is gated on `! isClosed()` as well, and
 * test_a_reconstructed_visit_offers_no_control_even_though_its_state_reads_returned()
 * is the executable form of that.
 *
 * VL-11 — the cap: no visit row renders more than FOUR controls in any state,
 * for any visit type. 46-06 shipped two of the four; 46-07 added the others;
 * 46.2-03 MOVED THE CAP TO ZERO — see
 * test_no_visit_row_in_any_state_renders_any_control(), which also drops the
 * `assertLessThanOrEqual` a ceiling of four required and this repo's exact-count
 * rule forbids once the ceiling is zero.
 */
class CockpitVisitActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cockpit.enabled' => true]);
    }

    private function project(): Project
    {
        return Project::factory()->create([
            'name'   => 'Visit Actions Job',
            'status' => Project::STATUS_INSTALLING,
        ]);
    }

    private function user(string $name = 'Priya Mistry'): User
    {
        return User::factory()->create(['name' => $name]);
    }

    /**
     * A survey that an engineer HAS submitted, wrapped by a visit that was
     * sent. The return lives on the SURVEY, never on the visit — there is no
     * `returned_at` column and inventing one in a fixture would model a shape
     * the schema deliberately refuses.
     */
    private function returnedSurveyVisit(Project $project): Visit
    {
        $survey = SiteSurvey::create([
            'user_id'      => $this->user('Engineer Eve')->id,
            'project_id'   => $project->id,
            'project_name' => 'Visit Actions Fixture',
            'status'       => 'completed',
        ]);

        $survey->forceFill([
            'submitted_at' => now()->subDays(2),
            'survey_data'  => ['comms_room' => 'Second floor, keyed access'],
        ])->save();

        return Visit::factory()->create([
            'project_id'  => $project->id,
            'type'        => Visit::TYPE_SITE_SURVEY,
            'status'      => Visit::STATUS_PLANNED,
            'sent_at'     => now()->subDays(3),
            'source_type' => Visit::SOURCE_SITE_SURVEY,
            'source_id'   => $survey->id,
        ]);
    }

    private function accept(Project $project, Visit $visit, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user())
            ->post(route('projects.cockpit.visits.accept', ['project' => $project, 'visit' => $visit]));
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

        if ($node === null) {
            return '';
        }

        return html_entity_decode($dom->saveHTML($node), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Row counts for every table an office action must never touch. */
    private function engineerTableCounts(): array
    {
        return [
            'site_surveys'       => DB::table('site_surveys')->count(),
            'worksheets'         => DB::table('worksheets')->count(),
            'worksheet_signoffs' => DB::table('worksheet_signoffs')->count(),
            'snags'              => DB::table('snags')->count(),
            // ADDED BY PLAN 46-07, which is the plan that created the table —
            // 46-06 recorded that it could not count a table that did not yet
            // exist. Accepting or sending back a visit writes no office note.
            'visit_notes'        => DB::table('visit_notes')->count(),
        ];
    }

    // ── Task 1: Accept ───────────────────────────────────────────────────

    public function test_accepting_a_returned_visit_records_who_and_when(): void
    {
        $project = $this->project();
        $visit   = $this->returnedSurveyVisit($project);
        $pm      = $this->user('Dan Rowe');

        $this->assertSame(Visit::STATE_RETURNED, $visit->state());

        $this->accept($project, $visit, $pm)
            ->assertRedirect(route('projects.cockpit', ['project' => $project, 'module' => 'site_survey']))
            ->assertSessionHas('success');

        $visit->refresh();

        $this->assertNotNull($visit->accepted_at);
        $this->assertSame($pm->id, $visit->accepted_by_user_id);
        $this->assertSame(Visit::STATE_ACCEPTED, $visit->state());
    }

    public function test_accepting_does_not_rewrite_the_stored_status_vocabulary(): void
    {
        $project = $this->project();
        $visit   = $this->returnedSurveyVisit($project);

        $before = $visit->status;

        $this->accept($project, $visit);

        $visit->refresh();

        // D-02 and the 24 backfilled rows on live both depend on this: the
        // stored vocabulary is `planned` / `completed` and an office action is
        // not a vocabulary change.
        $this->assertSame($before, $visit->status);
        $this->assertSame(Visit::STATUS_PLANNED, $visit->status);
        $this->assertTrue($visit->isClosed(), 'An accepted visit is closed for the progress ring.');
    }

    public function test_accepting_writes_not_one_byte_of_what_the_engineer_captured(): void
    {
        $project = $this->project();
        $visit   = $this->returnedSurveyVisit($project);

        $survey = SiteSurvey::findOrFail($visit->source_id);
        $before = [
            'submitted_at' => (string) $survey->getRawOriginal('submitted_at'),
            'survey_data'  => (string) $survey->getRawOriginal('survey_data'),
            'access_token' => (string) $survey->getRawOriginal('access_token'),
            'updated_at'   => (string) $survey->getRawOriginal('updated_at'),
        ];
        $counts = $this->engineerTableCounts();

        $this->accept($project, $visit);

        $survey->refresh();

        $this->assertSame($before['submitted_at'], (string) $survey->getRawOriginal('submitted_at'));
        $this->assertSame($before['survey_data'], (string) $survey->getRawOriginal('survey_data'));
        $this->assertSame($before['access_token'], (string) $survey->getRawOriginal('access_token'));
        $this->assertSame($before['updated_at'], (string) $survey->getRawOriginal('updated_at'));
        $this->assertSame($counts, $this->engineerTableCounts());
    }

    public function test_accepting_logs_exactly_one_visit_accepted_activity_row(): void
    {
        $project = $this->project();
        $visit   = $this->returnedSurveyVisit($project);

        $this->accept($project, $visit, $this->user('Dan Rowe'));

        $rows = ProjectActivityLog::where('project_id', $project->id)
            ->where('action', ProjectActivityLog::ACTION_VISIT_ACCEPTED)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertStringContainsString('Dan Rowe', $rows->first()->description);
        $this->assertSame($visit->id, $rows->first()->metadata['visit_id'] ?? null);
    }

    public function test_a_visit_from_another_project_is_a_404_and_writes_nothing(): void
    {
        $project = $this->project();
        $other   = $this->project();
        $visit   = $this->returnedSurveyVisit($other);

        $this->accept($project, $visit)->assertNotFound();

        $visit->refresh();

        $this->assertNull($visit->accepted_at);
        $this->assertNull($visit->accepted_by_user_id);
        $this->assertSame(0, ProjectActivityLog::where('action', ProjectActivityLog::ACTION_VISIT_ACCEPTED)->count());
    }

    public function test_accepting_a_visit_that_has_not_come_back_is_a_422(): void
    {
        $project = $this->project();
        $visit   = Visit::factory()->planned()->create(['project_id' => $project->id, 'type' => Visit::TYPE_INSTALL]);

        $this->accept($project, $visit)->assertStatus(422);

        $this->assertNull($visit->refresh()->accepted_at);
    }

    public function test_accepting_an_already_accepted_visit_is_a_422_not_a_silent_no_op(): void
    {
        $project = $this->project();
        $visit   = $this->returnedSurveyVisit($project);

        $this->accept($project, $visit)->assertRedirect();

        $acceptedAt = $visit->refresh()->accepted_at;
        $acceptedBy = $visit->accepted_by_user_id;

        $this->accept($project, $visit, $this->user('Second Clicker'))->assertStatus(422);

        $visit->refresh();

        // The FIRST click is the one that counted, and the second must not
        // quietly re-stamp the actor.
        $this->assertTrue($acceptedAt->equalTo($visit->accepted_at));
        $this->assertSame($acceptedBy, $visit->accepted_by_user_id);
        $this->assertSame(1, ProjectActivityLog::where('action', ProjectActivityLog::ACTION_VISIT_ACCEPTED)->count());
    }

    public function test_accepting_a_sent_back_visit_is_allowed(): void
    {
        $project = $this->project();
        $visit   = Visit::factory()->sentBack()->create(['project_id' => $project->id, 'type' => Visit::TYPE_INSTALL]);

        $this->assertSame(Visit::STATE_SENT_BACK, $visit->state());

        $this->accept($project, $visit)->assertRedirect();

        $this->assertNotNull($visit->refresh()->accepted_at);
    }

    public function test_accepting_moves_the_progress_ring_by_exactly_one(): void
    {
        $project = $this->project();

        // One already-complete install visit, and one out for review.
        Visit::factory()->create(['project_id' => $project->id, 'type' => Visit::TYPE_INSTALL]);
        $visit = Visit::factory()->returned()->create(['project_id' => $project->id, 'type' => Visit::TYPE_INSTALL]);

        $this->assertStringContainsString(
            '1 of 2 visits completed',
            $this->region($project, ['module' => 'worksheet']),
        );

        $this->accept($project, $visit);

        $this->assertStringContainsString(
            '2 of 2 visits completed',
            $this->region($project, ['module' => 'worksheet']),
        );
    }

    public function test_an_accepted_visit_says_who_accepted_it_and_that_scope_is_locked(): void
    {
        $project = $this->project();
        $visit   = $this->returnedSurveyVisit($project);

        $this->accept($project, $visit, $this->user('Dan Rowe'));

        $region = $this->region($project, ['module' => 'site_survey']);

        $this->assertStringContainsString('Accepted by Dan Rowe on '.now()->format('d M Y'), $region);
        $this->assertStringContainsString(
            'Scope locked — returned '.$visit->refresh()->returnedAt()->format('d M Y'),
            $region,
        );
    }

    public function test_an_anonymous_caller_cannot_accept(): void
    {
        $project = $this->project();
        $visit   = $this->returnedSurveyVisit($project);

        $this->post(route('projects.cockpit.visits.accept', ['project' => $project, 'visit' => $visit]))
            ->assertStatus(302);

        $this->assertNull($visit->refresh()->accepted_at);
    }

    // ── Task 2: Send back ────────────────────────────────────────────────

    private function sendBack(Project $project, Visit $visit, array $payload = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user())
            ->post(
                route('projects.cockpit.visits.send-back', ['project' => $project, 'visit' => $visit]),
                $payload + ['reason' => 'Photos of the comms room are missing.'],
            );
    }

    public function test_sending_back_records_the_reason_and_when(): void
    {
        $project = $this->project();
        $visit   = $this->returnedSurveyVisit($project);

        $this->sendBack($project, $visit, ['reason' => 'The second floor rooms were not surveyed.'])
            ->assertRedirect(route('projects.cockpit', ['project' => $project, 'module' => 'site_survey']))
            ->assertSessionHas('success');

        $visit->refresh();

        $this->assertNotNull($visit->sent_back_at);
        $this->assertSame('The second floor rooms were not surveyed.', $visit->send_back_reason);
        $this->assertSame(Visit::STATE_SENT_BACK, $visit->state());
    }

    public function test_sending_back_never_clears_the_engineers_submission(): void
    {
        $project = $this->project();
        $visit   = $this->returnedSurveyVisit($project);

        $survey = SiteSurvey::findOrFail($visit->source_id);
        $before = [
            'submitted_at' => (string) $survey->getRawOriginal('submitted_at'),
            'survey_data'  => (string) $survey->getRawOriginal('survey_data'),
            'access_token' => (string) $survey->getRawOriginal('access_token'),
            'updated_at'   => (string) $survey->getRawOriginal('updated_at'),
        ];
        $counts = $this->engineerTableCounts();

        $this->sendBack($project, $visit);

        $survey->refresh();

        // THE D-02 VIOLATION THE WHOLE DESIGN AVOIDS. 46-05 derives the
        // reopening as `sent_back_at > last submission`, so clearing
        // `submitted_at` here would rewrite the engineer's own record to
        // achieve something a comparison already achieves.
        $this->assertSame($before['submitted_at'], (string) $survey->getRawOriginal('submitted_at'));
        $this->assertSame($before['survey_data'], (string) $survey->getRawOriginal('survey_data'));
        $this->assertSame($before['access_token'], (string) $survey->getRawOriginal('access_token'));
        $this->assertSame($before['updated_at'], (string) $survey->getRawOriginal('updated_at'));
        $this->assertSame($counts, $this->engineerTableCounts());
        $this->assertNotNull($survey->submitted_at);
    }

    public function test_sending_back_does_not_rewrite_the_stored_status(): void
    {
        $project = $this->project();
        $visit   = $this->returnedSurveyVisit($project);

        $before = $visit->status;

        $this->sendBack($project, $visit);

        $this->assertSame($before, $visit->refresh()->status);
        $this->assertNull($visit->accepted_at);
    }

    public function test_a_reason_is_required_and_is_bounded(): void
    {
        $project = $this->project();
        $visit   = $this->returnedSurveyVisit($project);

        $this->sendBack($project, $visit, ['reason' => ''])->assertSessionHasErrors('reason');
        $this->assertNull($visit->refresh()->sent_back_at);

        $this->sendBack($project, $visit, ['reason' => 'no'])->assertSessionHasErrors('reason');
        $this->assertNull($visit->refresh()->sent_back_at);

        $this->sendBack($project, $visit, ['reason' => str_repeat('a', 2001)])->assertSessionHasErrors('reason');
        $this->assertNull($visit->refresh()->sent_back_at);
    }

    public function test_sending_back_logs_exactly_one_visit_sent_back_activity_row(): void
    {
        $project = $this->project();
        $visit   = $this->returnedSurveyVisit($project);

        $this->sendBack($project, $visit, [], $this->user('Dan Rowe'));

        $rows = ProjectActivityLog::where('project_id', $project->id)
            ->where('action', ProjectActivityLog::ACTION_VISIT_SENT_BACK)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertStringContainsString('Dan Rowe', $rows->first()->description);
        $this->assertSame($visit->id, $rows->first()->metadata['visit_id'] ?? null);

        // The PM's free text is NOT copied into the feed: it is a client-side
        // surface read by the engineer, and one place to read the current ask
        // from is the whole point of keeping one reason.
        $this->assertStringNotContainsString('comms room', $rows->first()->description);
    }

    public function test_only_a_returned_visit_can_be_sent_back(): void
    {
        $project = $this->project();

        $planned = Visit::factory()->planned()->create(['project_id' => $project->id, 'type' => Visit::TYPE_INSTALL]);
        $this->sendBack($project, $planned)->assertStatus(422);
        $this->assertNull($planned->refresh()->sent_back_at);

        $accepted = Visit::factory()->accepted()->create(['project_id' => $project->id, 'type' => Visit::TYPE_INSTALL]);
        $this->sendBack($project, $accepted)->assertStatus(422);
        $this->assertNull($accepted->refresh()->sent_back_at);

        // Already sent back: there is no second send-back to give.
        $sentBack = Visit::factory()->sentBack()->create(['project_id' => $project->id, 'type' => Visit::TYPE_INSTALL]);
        $reason   = $sentBack->send_back_reason;
        $this->sendBack($project, $sentBack)->assertStatus(422);
        $this->assertSame($reason, $sentBack->refresh()->send_back_reason);
    }

    public function test_a_send_back_on_another_projects_visit_is_a_404(): void
    {
        $project = $this->project();
        $visit   = $this->returnedSurveyVisit($this->project());

        $this->sendBack($project, $visit)->assertNotFound();

        $this->assertNull($visit->refresh()->sent_back_at);
    }

    public function test_only_the_latest_reason_is_kept_across_a_resubmission(): void
    {
        $project = $this->project();
        $visit   = $this->returnedSurveyVisit($project);
        $survey  = SiteSurvey::findOrFail($visit->source_id);

        $this->sendBack($project, $visit, ['reason' => 'The first ask: comms room photos.']);

        // The engineer resubmits — which relocks with NO flag to clear,
        // because the reopening is a comparison, never a stored boolean.
        $survey->forceFill(['submitted_at' => now()])->save();

        $this->assertSame(Visit::STATE_RETURNED, $visit->refresh()->state());

        // Two minutes pass. `wasSentBack()` is a strict `greaterThan` against
        // a timestamp stored to the SECOND, so a send-back issued inside the
        // same second as the resubmission would read as "not sent back" —
        // logged as D-46-06-01 rather than papered over by relaxing 46-01's
        // comparison, which would change what an equal timestamp means
        // everywhere.
        $this->travel(2)->minutes();

        $this->sendBack($project, $visit, ['reason' => 'The second ask: cable route photos.']);

        $visit->refresh();

        $this->assertSame('The second ask: cable route photos.', $visit->send_back_reason);
        $this->assertSame(Visit::STATE_SENT_BACK, $visit->state());
    }

    public function test_a_hostile_reason_is_stored_and_rendered_escaped(): void
    {
        $project = $this->project();
        $visit   = $this->returnedSurveyVisit($project);

        $this->sendBack($project, $visit, ['reason' => '<script>alert(1)</script> please redo']);

        $body = $this->actingAs($this->user())
            ->get(route('projects.cockpit', ['project' => $project, 'module' => 'site_survey']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
    }

    public function test_an_anonymous_caller_cannot_send_back(): void
    {
        $project = $this->project();
        $visit   = $this->returnedSurveyVisit($project);

        $this->post(route('projects.cockpit.visits.send-back', ['project' => $project, 'visit' => $visit]), [
            'reason' => 'Nope.',
        ])->assertStatus(302);

        $this->assertNull($visit->refresh()->sent_back_at);
    }

    // ── Task 3: the visit row's action area ──────────────────────────────

    /**
     * Every `.cav-visit` row in the open module, as raw HTML.
     *
     * DEFAULT MOVED `?tab=returned` -> `?tab=overview` BY 46.2 D-02, PLAN
     * 46.2-03 — AND THE OVERVIEW ROW IS NOW THE ONLY VISIT SURFACE.
     *
     * The history, so the next reader does not have to reconstruct it: Plan
     * 46.1-05 moved the four review controls OUT of Overview and beneath the
     * evidence on the Returned tab, in the user's own words — a PM should not be
     * able to accept a visit without having looked at it. 46.2 D-02 then took the
     * Returned tab off the cockpit altogether, so the four controls are offered
     * NOWHERE on this page. The row itself STAYS, by 46.2 D-06: it reports where
     * every visit stands and it offers nothing.
     *
     * Leaving the default at `returned` would have been quietly wrong rather than
     * red: `returned` is not in ProjectCockpitController::TABS any more, so every
     * call would have rendered OVERVIEW while every docblock and failure message
     * said Returned tab.
     *
     * The four acts are NOT deleted. They live at
     * projects.cockpit.visits.accept / .send-back / .notes / .snags, are proved
     * by the route half of this very file, and are asserted to be reachable with
     * no link on the page by
     * CockpitReadOnlyFenceTest::test_the_unsurfaced_write_routes_all_still_work_with_no_link_on_the_page().
     *
     * @return array<int, string>
     */
    private function visitRows(Project $project, string $module, array $query = []): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$this->region($project, ['module' => $module] + $query + ['tab' => 'overview']));
        libxml_clear_errors();

        $rows = [];

        foreach ((new \DOMXPath($dom))->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' cav-visit ')]") as $node) {
            $rows[] = html_entity_decode($dom->saveHTML($node), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $rows;
    }

    /** Controls, counted the way a PM counts them: things you can press. */
    private function countControls(string $row): int
    {
        return substr_count($row, '<button') + substr_count($row, '<a ');
    }

    /**
     * WAS `test_a_returned_visit_offers_exactly_the_four_pm_acts()`, asserting 4.
     *
     * RETIRED AND REPLACED, NOT REMOVED (46.2 D-02, Plan 46.2-03). Its history:
     * `..._and_nothing_else` asserting 2 when 46-06 shipped the first two of
     * D-02's four acts, raised to 4 by Plan 46-07 which shipped the other two and
     * REACHED the cap. 46.2 D-02 takes the cap to ZERO.
     *
     * A CAP OF ZERO, NOT AN ABSENCE OF A TEST. The count stays EXACT — never a
     * floor, never `assertLessThanOrEqual` — so a later plan that re-renders a
     * visit control on this page trips this test rather than finding nothing
     * here. The four strings are asserted ABSENT individually as well as by the
     * count, because a control could arrive with new copy.
     *
     * THE ACTS STILL WORK. The twenty-one route tests above this one POST to
     * accept and send-back and prove they write, log, validate and 404 exactly
     * as before. Nothing was deleted; the row stopped offering.
     */
    public function test_a_returned_visit_offers_no_pm_act_at_all(): void
    {
        $project = $this->project();
        $this->returnedSurveyVisit($project);

        $rows = $this->visitRows($project, 'site_survey');

        $this->assertCount(1, $rows, 'The row itself STAYS (46.2 D-06) — it reports, it does not offer.');
        $this->assertStringNotContainsString('Accept', $rows[0]);
        $this->assertStringNotContainsString('Send back', $rows[0]);
        $this->assertStringNotContainsString('Add note', $rows[0]);
        $this->assertStringNotContainsString('Raise a snag', $rows[0]);
        $this->assertSame(0, $this->countControls($rows[0]));
    }

    public function test_a_planned_visit_offers_nothing(): void
    {
        $project = $this->project();
        Visit::factory()->planned()->create(['project_id' => $project->id, 'type' => Visit::TYPE_INSTALL]);

        $rows = $this->visitRows($project, 'worksheet');

        $this->assertCount(1, $rows);
        $this->assertSame(0, $this->countControls($rows[0]));
        $this->assertStringNotContainsString('Accept', $rows[0]);
    }

    public function test_a_sent_visit_offers_nothing_and_says_it_is_with_the_engineer(): void
    {
        $project = $this->project();
        Visit::factory()->sent()->create(['project_id' => $project->id, 'type' => Visit::TYPE_INSTALL]);

        $rows = $this->visitRows($project, 'worksheet');

        $this->assertCount(1, $rows);
        $this->assertSame(0, $this->countControls($rows[0]));
        $this->assertStringContainsString('Awaiting the engineer', $rows[0]);
    }

    public function test_a_sent_back_visit_offers_no_accept_and_still_reports_the_send_back(): void
    {
        $project = $this->project();
        Visit::factory()->sentBack()->create(['project_id' => $project->id, 'type' => Visit::TYPE_INSTALL]);

        $rows = $this->visitRows($project, 'worksheet');

        $this->assertCount(1, $rows);
        // 1 -> 3 (Plan 46-07) -> 0 (46.2 D-02, Plan 46.2-03). The cap is exact at
        // every step: there is no second send-back AND no first one, because the
        // row offers nothing at all now.
        $this->assertSame(0, $this->countControls($rows[0]));
        $this->assertStringNotContainsString('Accept', $rows[0]);
        // THE STATUS COPY STAYS (46.2 D-06). "Sent back" here is the row
        // REPORTING where the visit stands, not a control — which is exactly the
        // distinction this plan turns on, so it is asserted rather than dropped.
        $this->assertStringContainsString('Sent back', $rows[0]);
        $this->assertStringNotContainsString('Send back<', $rows[0]);
    }

    /**
     * WAS `..._offers_nothing` asserting 0, then `..._offers_only_add_note`
     * asserting 1 when Plan 46-07 gave an accepted visit exactly one control.
     *
     * BACK TO 0 BY 46.2 D-02 (Plan 46.2-03), AND RENAMED TO SAY SO. The name
     * returns to what it was for a DIFFERENT reason than it first held: 46-07's
     * "a note after acceptance is the annotation D-02 describes" is still true,
     * and the note route still accepts it — `test_an_accepted_visit_may_still_be_annotated()`
     * in CockpitOfficeNoteAndSnagTest is green and unedited. It is the BUTTON
     * that is gone, not the ability.
     */
    public function test_an_accepted_visit_offers_nothing_while_the_note_route_still_takes_one(): void
    {
        $project = $this->project();
        Visit::factory()->accepted()->create(['project_id' => $project->id, 'type' => Visit::TYPE_INSTALL]);

        $rows = $this->visitRows($project, 'worksheet');

        $this->assertCount(1, $rows);
        $this->assertSame(0, $this->countControls($rows[0]));
        $this->assertStringNotContainsString('Add note', $rows[0]);
        $this->assertStringNotContainsString('Raise a snag', $rows[0]);
        // Status copy, not a control — it stays (46.2 D-06).
        $this->assertStringContainsString('Accepted by', $rows[0]);
    }

    public function test_a_reconstructed_visit_offers_no_control_even_though_its_state_reads_returned(): void
    {
        $project   = $this->project();
        $worksheet = \App\Models\Worksheet::factory()->create(['project_id' => $project->id]);

        \App\Models\WorksheetSignoff::create([
            'worksheet_id'         => $worksheet->id,
            'client_name'          => 'A Client',
            'signature_png_base64' => 'iVBORw0KGgo=',
            'signed_with_comments' => false,
            'signed_at'            => now()->subYear(),
        ]);

        $visit = Visit::factory()->backfilledFromWorksheet($worksheet)->create([
            'project_id' => $project->id,
            'status'     => Visit::STATUS_COMPLETED,
        ]);

        // 46-01's recorded finding, pinned here as well: the DERIVED state
        // reads RETURNED, and 24 rows on live look exactly like this one.
        $this->assertSame(Visit::STATE_RETURNED, $visit->state());
        $this->assertTrue($visit->isClosed());

        $rows = $this->visitRows($project, 'worksheet');

        $this->assertCount(1, $rows);
        $this->assertSame(0, $this->countControls($rows[0]), 'A reconstructed visit must never ask a PM to ratify a guess.');
        $this->assertStringNotContainsString('Accept', $rows[0]);
        $this->assertStringNotContainsString('Scope locked', $rows[0]);
        $this->assertStringContainsString('Reconstructed', $rows[0]);
    }

    /**
     * THE TEST THAT MAKES THE RELOCATION SAFE (Plan 46.1-05, T-46.1-21).
     *
     * A control moved to a tab a visit never gets is a control DELETED, and it
     * would be deleted silently: every other assertion in this file would stay
     * green, because every other assertion looks at one state at a time.
     *
     * ── MOVED TO A TABLE OF ZEROS BY 46.2 D-02 (Plan 46.2-03) ────────────────
     *
     * The table was `returned 4 / sentBack 3 / accepted 1 / planned 0 / sent 0 /
     * backfilledFromWorksheet 0 / default 0`, copied from the named tests above
     * rather than read back off the Blade. EVERY ENTRY IS NOW 0, and each of the
     * three that moved was moved BY NAME with the test it was copied from:
     *
     *   returned 4 -> 0  test_a_returned_visit_offers_no_pm_act_at_all()
     *   sentBack 3 -> 0  test_a_sent_back_visit_offers_no_accept_and_still_reports_the_send_back()
     *   accepted 1 -> 0  test_an_accepted_visit_offers_nothing_while_the_note_route_still_takes_one()
     *   the four already-zero rows are UNCHANGED, and they are why this table is
     *   kept rather than replaced: it still distinguishes "offers nothing" from
     *   "renders nothing".
     *
     * THE TABLE IS KEPT AT ZERO RATHER THAN DELETED, for its original reason
     * inverted. A control moved to a tab a visit never gets is a control deleted
     * silently; a control RE-ADDED to one visit state is just as silent, and
     * every other assertion in this file looks at one state at a time. So this
     * remains the file's cap, now a cap of zero over every state and every type.
     *
     * THE ROW MUST STILL EXIST — except for the three visit types 46.2 D-01 left
     * without a module row. `commissioning`, `programming` and `snag` render no
     * row anywhere by design, and that set is asserted to be exactly those three
     * by CockpitModulePresenterTest::test_the_visit_types_with_no_module_row_are_exactly_three_and_named().
     * They are named here rather than skipped silently, and they are asserted to
     * render ZERO rows — so a fourth type losing its row still fails loudly.
     */
    public function test_no_visit_state_reaches_any_control_at_all(): void
    {
        $expected = [
            'planned'                 => 0,
            'sent'                    => 0,
            'returned'                => 0,
            'sentBack'                => 0,
            'accepted'                => 0,
            'backfilledFromWorksheet' => 0,
            'default'                 => 0,
        ];

        // 46.2 D-01 removed the Programme and commissioning, Programming and
        // Snagging rows, so these three types reach no drawer. NOT a skip list:
        // each is asserted to render exactly zero rows below.
        $typesWithNoRow = [Visit::TYPE_COMMISSIONING, Visit::TYPE_PROGRAMMING, Visit::TYPE_SNAG];

        $modules = array_keys(\App\Support\Cockpit\CockpitModulePresenter::moduleMap());
        $seen    = 0;

        foreach (Visit::TYPES as $type) {
            foreach ($expected as $state => $count) {
                $project = $this->project();
                $factory = Visit::factory();

                $state === 'default'
                    ? $factory->create(['project_id' => $project->id, 'type' => $type])
                    : $factory->{$state}()->create(['project_id' => $project->id, 'type' => $type]);

                $rows = 0;

                foreach ($modules as $module) {
                    foreach ($this->visitRows($project, $module) as $row) {
                        $rows++;
                        $seen++;

                        $this->assertSame(
                            $count,
                            $this->countControls($row),
                            "A {$state} {$type} visit offered a control. 46.2 D-02: the cockpit offers none, in any state, on any row."
                        );
                    }
                }

                if (in_array($type, $typesWithNoRow, true)) {
                    $this->assertSame(
                        0,
                        $rows,
                        "A {$state} {$type} visit rendered a row, but 46.2 D-01 removed the row that owned this type."
                    );

                    continue;
                }

                $this->assertGreaterThanOrEqual(
                    1,
                    $rows,
                    "A {$state} {$type} visit renders no row at all - it was stranded rather than merely unoffered."
                );
            }
        }

        $this->assertGreaterThan(20, $seen, 'The no-control table must judge real rows, never pass vacuously.');
    }

    /**
     * OVERVIEW LOSES THE OFFERS AND KEEPS THE RECORD (Plan 46.1-05, T-46.1-24).
     *
     * D-02 moved what a PM can DO. It did not move what a PM can SEE. A PM
     * glancing at Overview must still be able to say where every visit stands
     * without opening anything, so each state sentence is asserted here by
     * name - a relocation that took them along would be a repudiation bug, not
     * a tidier page.
     */
    public function test_overview_offers_no_control_and_still_says_where_the_visit_stands(): void
    {
        $project = $this->project();
        $this->returnedSurveyVisit($project);

        $returned = $this->visitRows($project, 'site_survey', ['tab' => 'overview'])[0];

        $this->assertSame(0, $this->countControls($returned));
        $this->assertStringContainsString('Scope locked', $returned);
        $this->assertStringNotContainsString('Accept', $returned);

        $accepted = $this->project();
        Visit::factory()->accepted()->create(['project_id' => $accepted->id, 'type' => Visit::TYPE_INSTALL]);

        $acceptedRow = $this->visitRows($accepted, 'worksheet', ['tab' => 'overview'])[0];

        $this->assertSame(0, $this->countControls($acceptedRow));
        $this->assertStringContainsString('Accepted by', $acceptedRow);
        $this->assertStringNotContainsString('Add note', $acceptedRow);

        $sentBack = $this->project();
        Visit::factory()->sentBack()->create(['project_id' => $sentBack->id, 'type' => Visit::TYPE_INSTALL]);

        $sentBackRow = $this->visitRows($sentBack, 'worksheet', ['tab' => 'overview'])[0];

        $this->assertSame(0, $this->countControls($sentBackRow));
        $this->assertStringContainsString('Sent back', $sentBackRow);
        $this->assertStringContainsString('awaiting the engineer', $sentBackRow);

        $sent = $this->project();
        Visit::factory()->sent()->create(['project_id' => $sent->id, 'type' => Visit::TYPE_INSTALL]);

        $sentRow = $this->visitRows($sent, 'worksheet', ['tab' => 'overview'])[0];

        $this->assertSame(0, $this->countControls($sentRow));
        $this->assertStringContainsString('Awaiting the engineer', $sentRow);
    }

    /**
     * THE PRESENCE RULE STRANDS NOTHING (Plan 46.1-05, T-46.1-21).
     *
     * Plan 46.1-03 gives the Returned tab only to a drawer holding a visit with
     * a `source_type`. That is safe only if the set of visits that can OFFER a
     * control is a subset of the set that GETS the tab, so this asserts every
     * half of that:
     *
     *   1. a sourceless visit renders no control, in any factory state;
     *   2. it cannot REACH a control-offering state either - each of
     *      `$canAccept`, `$canSendBack`, `$canNote` and `$canSnag` requires
     *      `state()` to be RETURNED, SENT_BACK or ACCEPTED, and all three of
     *      those need either a return (which `returnedAt()` reads off the
     *      SOURCE) or a column only the two POST routes write;
     *   3. and those two routes refuse a visit that has not come back with a
     *      422 - so the app cannot manufacture the stranded shape either.
     *
     * Part 3 is the load-bearing one. Parts 1 and 2 describe fixtures; part 3
     * describes what production can actually produce.
     */
    public function test_a_sourceless_visit_could_never_have_offered_a_control(): void
    {
        $states  = ['planned', 'sent', 'returned', 'sentBack', 'accepted', 'backfilledFromWorksheet', 'default'];
        $modules = array_keys(\App\Support\Cockpit\CockpitModulePresenter::moduleMap());
        $seen    = 0;

        foreach ($states as $state) {
            $project = $this->project();
            $factory = Visit::factory();

            $visit = $state === 'default'
                ? $factory->create(['project_id' => $project->id, 'type' => Visit::TYPE_INSTALL])
                : $factory->{$state}()->create(['project_id' => $project->id, 'type' => Visit::TYPE_INSTALL]);

            // FORCED, because nothing in the app ever clears a source: this is
            // the shape the presence rule would strand if it could exist.
            $visit->forceFill(['source_type' => null, 'source_id' => null])->save();

            foreach ($modules as $module) {
                foreach ($this->visitRows($project, $module, ['tab' => 'overview']) as $row) {
                    $seen++;

                    $this->assertSame(
                        0,
                        $this->countControls($row),
                        "A sourceless {$state} visit offered a control, so the Returned tab's presence rule would strand it."
                    );
                }
            }

            if ($visit->refresh()->accepted_at === null && $visit->sent_back_at === null) {
                $this->assertNotContains(
                    $visit->state(),
                    [Visit::STATE_RETURNED, Visit::STATE_SENT_BACK, Visit::STATE_ACCEPTED],
                    "A sourceless {$state} visit derived a control-offering state with no return to derive it from."
                );
            }
        }

        $this->assertGreaterThan(5, $seen, 'The sourceless sweep must judge real rows, never pass vacuously.');

        // 3. The two routes that write `accepted_at` and `sent_back_at` both
        //    refuse a visit that has not come back - and a sourceless visit
        //    never has, because `returnedAt()` reads the source.
        $project = $this->project();
        $bare    = Visit::factory()->sent()->create(['project_id' => $project->id, 'type' => Visit::TYPE_INSTALL]);

        $this->accept($project, $bare)->assertStatus(422);
        $this->sendBack($project, $bare, ['reason' => 'The comms room photos are missing.'])->assertStatus(422);

        $bare->refresh();

        $this->assertNull($bare->accepted_at);
        $this->assertNull($bare->sent_back_at);
        $this->assertNull($bare->source_type);
    }

    /**
     * VL-11'S CEILING OF FOUR BECOMES A CEILING OF ZERO (46.2 D-02, Plan 46.2-03).
     *
     * History: VL-11 capped every visit row at FOUR controls, judged over 7
     * factory states x 6 visit types x every drawer x 4 URL states, and Plan
     * 46.1-05 rejudged it where the controls then lived. 46.2 D-02 takes the
     * cockpit's visit controls to zero, so the ceiling follows.
     *
     * THE `assertLessThanOrEqual` IS GONE, AND THAT IS THE POINT. A ceiling of
     * four had to be an inequality; a ceiling of zero can be — and under this
     * repo's exact-count rule MUST be — an equality. At four it would also now
     * pass vacuously on every one of these rows.
     *
     * COVERAGE IS UNCHANGED IN EVERY DIMENSION, deliberately: still every state,
     * every type, every surviving drawer, still all four `?action=` URL states
     * even though none of them discloses anything any more (that is exactly what
     * makes trying them worth it), still
     * `assertStringNotContainsString('disabled')`, still the vacuity floor of 20
     * rows. If a later plan re-renders one control in one state in one drawer,
     * this loop is what finds it.
     */
    public function test_no_visit_row_in_any_state_renders_any_control(): void
    {
        $states = ['planned', 'sent', 'returned', 'sentBack', 'accepted', 'backfilledFromWorksheet', 'default'];
        $seen   = 0;

        foreach (Visit::TYPES as $type) {
            foreach ($states as $state) {
                $project = $this->project();

                $factory = Visit::factory();
                $visit   = $state === 'default'
                    ? $factory->create(['project_id' => $project->id, 'type' => $type])
                    : $factory->{$state}()->create(['project_id' => $project->id, 'type' => $type]);

                foreach (array_keys(\App\Support\Cockpit\CockpitModulePresenter::moduleMap()) as $module) {
                    // URL STATES GROWN 2 -> 4 BY PLAN 46-07 (the ceiling is
                    // NOT raised): the note and the snag disclose their own
                    // forms, so the cap has to be judged with each of them
                    // open as well. KEPT AT FOUR BY 46.2-03 even though
                    // `ACTIONS` is now empty — a value that discloses nothing
                    // is the value most worth passing in.
                    $urlStates = [
                        [],
                        ['action' => 'send-back', 'visit' => $visit->id],
                        ['action' => 'note', 'visit' => $visit->id],
                        ['action' => 'snag', 'visit' => $visit->id],
                    ];

                    foreach ($urlStates as $query) {
                        foreach ($this->visitRows($project, $module, $query) as $row) {
                            $seen++;

                            // 4 -> 0, AND `assertLessThanOrEqual` -> `assertSame`
                            // (46.2 D-02, Plan 46.2-03).
                            $this->assertSame(
                                0,
                                $this->countControls($row),
                                "VL-11 at zero: a {$state} {$type} visit row rendered a control in the {$module} drawer. ".
                                'The cockpit offers no visit control; the acts live at their own routes.'
                            );

                            // Nothing renders disabled — a disabled control is
                            // still an offer, and one that never explains
                            // itself is how a PM decides the page is broken.
                            $this->assertStringNotContainsString('disabled', $row);
                        }
                    }
                }
            }
        }

        $this->assertGreaterThan(20, $seen, 'The cap test must judge real rows, never pass vacuously.');
    }

    /**
     * TWO DISCLOSURE TESTS RETIRED AND REPLACED BY ONE (46.2 D-02, Plan 46.2-03):
     *
     *   test_the_send_back_form_is_disclosed_by_the_url_and_closed_by_an_anchor()
     *     asserted that a closed row carried `action=send-back` and `visit={id}`,
     *     and that `?action=send-back&visit={id}` opened a row with a
     *     `<textarea`, a `_token`, the "The engineer will read this" hint and a
     *     Cancel anchor.
     *
     *   test_the_form_opens_only_on_the_named_visit()
     *     asserted exactly ONE of two rendered rows disclosed its reason field.
     *
     * Both are IMPOSSIBLE, not failing: `ProjectCockpitController::ACTIONS` is
     * empty and `resolveAction()` is gone, so no `?action=` value discloses
     * anything on this page. Retired here rather than deleted, and replaced below
     * by the assertion that carries the same weight now — the URL cannot summon a
     * form, no matter what it says.
     *
     * The send-back CAPABILITY is untouched. Every assertion the two tests made
     * about what the form POSTS is proved by the route half of this file:
     * test_sending_back_records_the_reason_and_when(),
     * test_a_reason_is_required_and_is_bounded(),
     * test_only_a_returned_visit_can_be_sent_back() and five more — all green,
     * all unedited by this plan.
     */
    public function test_no_url_state_can_disclose_a_visit_form_any_more(): void
    {
        $project = $this->project();
        $visit   = $this->returnedSurveyVisit($project);

        $closed = $this->visitRows($project, 'site_survey')[0];

        $this->assertStringNotContainsString('<textarea', $closed);
        $this->assertStringNotContainsString('action=send-back', $closed);

        // EVERY retired action string, tried against the real URL. The row must
        // come back byte-for-byte the same as the undisclosed one: nothing in
        // `?action=` is a legal value any more, so nothing opens.
        foreach (['send-back', 'note', 'snag', 'create-visit'] as $action) {
            $rows = $this->visitRows($project, 'site_survey', ['action' => $action, 'visit' => $visit->id]);

            $this->assertCount(1, $rows);
            $this->assertStringNotContainsString('<textarea', $rows[0]);
            $this->assertStringNotContainsString('_token', $rows[0]);
            $this->assertSame(
                0,
                $this->countControls($rows[0]),
                "?action={$action} disclosed a control. 46.2 D-02: none of these is a legal action."
            );
        }

        // MOVED BY NAME, `[]` -> `['generate']` (Plan 46.2-05), which re-surfaced
        // the DOCUMENT form on this mechanism. The four strings above are still
        // illegal and that is now asserted directly instead of resting on the list
        // being empty — a weaker claim that happened to hold for two commits.
        $this->assertSame(['generate'], ProjectCockpitController::ACTIONS);

        foreach (['send-back', 'note', 'snag', 'create-visit'] as $retired) {
            $this->assertNotContains($retired, ProjectCockpitController::ACTIONS);
        }
    }

    public function test_a_hostile_visit_title_and_a_hostile_query_are_never_echoed_raw(): void
    {
        $project = $this->project();

        Visit::factory()->returned()->create([
            'project_id' => $project->id,
            'type'       => Visit::TYPE_INSTALL,
            'title'      => '<script>alert(1)</script>',
        ]);

        $body = $this->actingAs($this->user())
            ->get(route('projects.cockpit', [
                'project' => $project,
                'module'  => 'worksheet',
                'action'  => '"><script>alert(2)</script>',
                'visit'   => '"><script>alert(3)</script>',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringNotContainsString('alert(2)', $body);
        $this->assertStringNotContainsString('alert(3)', $body);
    }

    public function test_the_action_routes_are_gone_when_the_cockpit_flag_is_off(): void
    {
        config(['cockpit.enabled' => false]);

        $project = $this->project();
        $visit   = $this->returnedSurveyVisit($project);

        $this->accept($project, $visit)->assertNotFound();
        $this->sendBack($project, $visit)->assertNotFound();

        $visit->refresh();

        $this->assertNull($visit->accepted_at);
        $this->assertNull($visit->sent_back_at);
    }
}
