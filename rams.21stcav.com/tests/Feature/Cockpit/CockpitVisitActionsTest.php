<?php

namespace Tests\Feature\Cockpit;

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
 * ACCEPT and SEND BACK, plus the visit row's action area that offers them.
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
 * for any visit type. 46-06 ships two of the four; 46-07 adds the others.
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
