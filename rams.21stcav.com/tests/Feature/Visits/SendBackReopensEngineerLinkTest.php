<?php

namespace Tests\Feature\Visits;

use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\SiteSurveyPhoto;
use App\Models\SiteSurveyRoom;
use App\Models\SiteSurveyRoomQuestion;
use App\Models\User;
use App\Models\Visit;
use App\Models\Worksheet;
use App\Models\WorksheetSignoff;
use App\Support\Visits\VisitReworkState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Plan 46-05 — send back reopens the engineer link, derived and never stored.
 *
 * ── THE COUNT, RECORDED SO IT IS NOT RE-LITIGATED ───────────────────────────
 *
 * 46-05-PLAN.md originally said "eleven" `isSubmitted()` gate sites in the two
 * survey controllers. A grep measured TEN, at the exact line numbers the plan
 * enumerated — so the code had not moved; the plan's prose contradicted its own
 * list (8 + 2 = 10). Corrected in `b703e38a`. The ten break down as:
 *
 *   8 WRITE GATES that return 403  -> iterated by the negative test below
 *       7x abort_if(...)  in PublicSurveyController
 *          save, submit, completeRoom, uncompleteRoom, answerQuestion,
 *          uploadPhoto, updatePhoto
 *       1x if (...) return json 403   in SurveyController::stepSave
 *   2 RENDER FLAGS ('readonly' => ...) -> given render assertions instead, NOT
 *       forced into the 403 loop just to make one number cover everything.
 *       (PublicSurveyController:164 is on an UNROUTED method — see the note on
 *        test_the_readonly_render_flag_follows_the_reopening.)
 *
 * ── THE RULING THIS FILE EXISTS TO ENFORCE (D-02) ───────────────────────────
 *
 * `submitted_at` IS NEVER CLEARED. The obvious "send back" implementation nulls
 * it so the form reopens; that rewrites engineer-captured data, which D-02
 * forbids in terms. test_a_send_back_does_not_touch_the_engineers_record() is
 * that prohibition made executable.
 */
class SendBackReopensEngineerLinkTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The public survey routes carry per-route throttles (submit is
     * `throttle:10,1`). This test drives eight of them twice over, which trips
     * the limiter and turns a 403 assertion into a 429 that says nothing about
     * the gate. Disabling ONLY the throttler keeps every gate under test
     * genuinely exercised.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    /**
     * A submitted survey on a real public token, with a room and a photo so
     * every one of the eight write routes has something to bind to.
     *
     * @return array{survey: SiteSurvey, room: SiteSurveyRoom, photo: SiteSurveyPhoto, question: SiteSurveyRoomQuestion, project: Project, token: string}
     */
    private function submittedSurvey(): array
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $token   = 'tok-' . \Illuminate\Support\Str::uuid()->toString();

        $survey = SiteSurvey::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => 'Send Back Fixture',
            'status'       => 'completed',
        ]);

        // Re-audit S-03 — `access_token` is deliberately NOT in $fillable, so a
        // known token must go through forceFill(). Never make it mass-assignable.
        $survey->forceFill([
            'access_token' => $token,
            'submitted_at' => now()->subDays(5),
        ])->save();

        $room = $survey->rooms()->create([
            'room_name'  => 'Board Room',
            'space_type' => 'general',
            'sort_order' => 0,
        ]);

        $photo = SiteSurveyPhoto::create([
            'site_survey_room_id' => $room->id,
            'filename'            => 'survey-photos/fixture.jpg',
            'original_name'       => 'fixture.jpg',
            'mime_type'           => 'image/jpeg',
            'sort_order'          => 1,
        ]);

        $question = $room->questions()->create([
            'question'   => 'Is the comms room accessible?',
            'sort_order' => 0,
        ]);

        return compact('survey', 'room', 'photo', 'question', 'project', 'token');
    }

    /** A visit wrapping that survey, sent back AFTER the submission. */
    private function sendBack(SiteSurvey $survey, Project $project, string $reason = 'Photos of the comms room are missing.'): Visit
    {
        return Visit::factory()->create([
            'project_id'       => $project->id,
            'type'             => Visit::TYPE_SITE_SURVEY,
            'source_type'      => Visit::SOURCE_SITE_SURVEY,
            'source_id'        => $survey->id,
            'sent_at'          => now()->subDays(6),
            'sent_back_at'     => now()->subDay(),
            'send_back_reason' => $reason,
        ]);
    }

    /**
     * The eight write routes whose gate was edited, as callables.
     *
     * Named so a failure says WHICH gate let go, rather than "a request
     * returned 200". Ten gates were touched; this list is the eight that 403.
     *
     * @return array<string, callable(string, SiteSurveyRoom, SiteSurveyPhoto, SiteSurveyRoomQuestion): \Illuminate\Testing\TestResponse>
     */
    private function writeGateRoutes(): array
    {
        return [
            'step-save (SurveyController::stepSave)' => fn (string $t, SiteSurveyRoom $r, SiteSurveyPhoto $p, SiteSurveyRoomQuestion $q) => $this->postJson("/survey/{$t}/step-save", [
                'room_index' => 0,
                'step'       => 1,
                'data'       => ['name' => 'Board Room', 'type' => 'general'],
            ]),
            'save (PublicSurveyController::save)' => fn (string $t, SiteSurveyRoom $r, SiteSurveyPhoto $p, SiteSurveyRoomQuestion $q) => $this->post("/survey/{$t}/save", [
                'rooms' => [],
            ]),
            'submit (PublicSurveyController::submit)' => fn (string $t, SiteSurveyRoom $r, SiteSurveyPhoto $p, SiteSurveyRoomQuestion $q) => $this->post("/survey/{$t}/submit", [
                'rooms' => [],
            ]),
            'room complete (PublicSurveyController::completeRoom)' => fn (string $t, SiteSurveyRoom $r, SiteSurveyPhoto $p, SiteSurveyRoomQuestion $q) => $this->postJson("/survey/{$t}/rooms/{$r->id}/complete"),
            'room uncomplete (PublicSurveyController::uncompleteRoom)' => fn (string $t, SiteSurveyRoom $r, SiteSurveyPhoto $p, SiteSurveyRoomQuestion $q) => $this->postJson("/survey/{$t}/rooms/{$r->id}/uncomplete"),
            'answer question (PublicSurveyController::answerQuestion)' => fn (string $t, SiteSurveyRoom $r, SiteSurveyPhoto $p, SiteSurveyRoomQuestion $q) => $this->postJson("/survey/{$t}/rooms/{$r->id}/questions/{$q->id}", [
                'answer' => true,
            ]),
            'photo upload (PublicSurveyController::uploadPhoto)' => fn (string $t, SiteSurveyRoom $r, SiteSurveyPhoto $p, SiteSurveyRoomQuestion $q) => $this->postJson("/survey/{$t}/rooms/{$r->id}/photos", []),
            'photo update (PublicSurveyController::updatePhoto)' => fn (string $t, SiteSurveyRoom $r, SiteSurveyPhoto $p, SiteSurveyRoomQuestion $q) => $this->patchJson("/survey/{$t}/photos/{$p->id}", [
                'caption' => 'x',
            ]),
        ];
    }

    // ─── Task 1: the reopening ───────────────────────────────────────────────

    /** @test */
    public function test_a_sent_back_survey_accepts_engineer_edits_again(): void
    {
        ['survey' => $survey, 'project' => $project, 'token' => $token] = $this->submittedSurvey();

        // Before the send-back: shut.
        $this->postJson("/survey/{$token}/step-save", [
            'room_index' => 0, 'step' => 1, 'data' => ['name' => 'Board Room', 'type' => 'general'],
        ])->assertStatus(403);

        $this->sendBack($survey, $project);

        // After: open again — and nothing was cleared to make it so.
        $this->postJson("/survey/{$token}/step-save", [
            'room_index' => 0, 'step' => 1, 'data' => ['name' => 'Board Room', 'type' => 'general'],
        ])->assertStatus(200);

        $this->assertTrue($survey->fresh()->isSubmitted(), 'isSubmitted() must still be true — the reopening is derived, not a cleared flag.');
        $this->assertFalse($survey->fresh()->isLockedForEngineer());
    }

    /**
     * THE D-02 PROOF. An office action must not alter what the engineer
     * captured. Nothing in this plan writes to any of the four tables, and
     * `submitted_at` survives a reopened render byte-for-byte.
     *
     * @test
     */
    public function test_a_send_back_does_not_touch_the_engineers_record(): void
    {
        ['survey' => $survey, 'project' => $project, 'token' => $token] = $this->submittedSurvey();

        $submittedAtBefore = $survey->fresh()->submitted_at;
        $this->assertNotNull($submittedAtBefore);

        $countsBefore = [
            'site_surveys'       => DB::table('site_surveys')->count(),
            'worksheets'         => DB::table('worksheets')->count(),
            'worksheet_signoffs' => DB::table('worksheet_signoffs')->count(),
            'visits'             => DB::table('visits')->count(),
        ];

        $this->sendBack($survey, $project);

        // The reopened render — the moment a naive implementation would write.
        $this->get("/survey/{$token}")->assertStatus(200);

        $countsAfter = [
            'site_surveys'       => DB::table('site_surveys')->count(),
            'worksheets'         => DB::table('worksheets')->count(),
            'worksheet_signoffs' => DB::table('worksheet_signoffs')->count(),
            // +1 for the visit the send-back itself created; the RENDER adds none.
            'visits'             => DB::table('visits')->count(),
        ];

        $this->assertSame($countsBefore['site_surveys'], $countsAfter['site_surveys']);
        $this->assertSame($countsBefore['worksheets'], $countsAfter['worksheets']);
        $this->assertSame($countsBefore['worksheet_signoffs'], $countsAfter['worksheet_signoffs']);
        $this->assertSame($countsBefore['visits'] + 1, $countsAfter['visits']);

        $this->assertEquals(
            $submittedAtBefore->toDateTimeString(),
            $survey->fresh()->submitted_at?->toDateTimeString(),
            'submitted_at was altered by an office action — that is the D-02 violation this plan exists to avoid.'
        );
    }

    /** @test */
    public function test_resubmitting_relocks_it_with_no_flag_to_clear(): void
    {
        ['survey' => $survey, 'project' => $project, 'token' => $token] = $this->submittedSurvey();

        $visit = $this->sendBack($survey, $project);
        $this->assertFalse($survey->fresh()->isLockedForEngineer());

        // The engineer answers: a NEW submission, later than the send-back.
        $survey->forceFill(['submitted_at' => now()])->save();

        // Nothing was un-set by hand. The comparison flipped on its own.
        $this->assertTrue($survey->fresh()->isLockedForEngineer());
        $this->assertNotNull($visit->fresh()->sent_back_at, 'sent_back_at must survive — it is the record of an act a PM performed.');

        $this->postJson("/survey/{$token}/step-save", [
            'room_index' => 0, 'step' => 1, 'data' => ['name' => 'Board Room', 'type' => 'general'],
        ])->assertStatus(403);
    }

    /** @test */
    public function test_a_survey_with_no_visit_behaves_exactly_as_today(): void
    {
        ['survey' => $survey, 'token' => $token] = $this->submittedSurvey();

        $this->assertNull(VisitReworkState::forSurvey($survey));
        $this->assertFalse(VisitReworkState::isReopened($survey));
        $this->assertTrue($survey->isLockedForEngineer());

        $this->postJson("/survey/{$token}/step-save", [
            'room_index' => 0, 'step' => 1, 'data' => ['name' => 'Board Room', 'type' => 'general'],
        ])->assertStatus(403);
    }

    /** @test */
    public function test_an_unsubmitted_survey_is_never_locked(): void
    {
        ['survey' => $survey] = $this->submittedSurvey();
        $survey->forceFill(['submitted_at' => null])->save();

        $this->assertFalse($survey->fresh()->isLockedForEngineer());
    }

    /** @test */
    public function test_rework_state_reads_reason_and_date_only_while_live(): void
    {
        ['survey' => $survey, 'project' => $project] = $this->submittedSurvey();
        $this->sendBack($survey, $project, 'Need the rack elevation photo.');

        $state = VisitReworkState::forSurvey($survey->fresh());
        $this->assertTrue($state['reopened']);
        $this->assertSame('Need the rack elevation photo.', $state['reason']);
        $this->assertNotNull($state['at']);

        // Answered -> the banner data goes away whole, no "previously sent back".
        $survey->forceFill(['submitted_at' => now()])->save();

        $state = VisitReworkState::forSurvey($survey->fresh());
        $this->assertFalse($state['reopened']);
        $this->assertNull($state['reason']);
        $this->assertNull($state['at']);
    }

    /** @test */
    public function test_a_worksheet_reopening_is_derived_from_its_latest_signoff(): void
    {
        $user      = User::factory()->create();
        $project   = Project::factory()->create(['user_id' => $user->id]);
        $worksheet = Worksheet::factory()->create(['project_id' => $project->id]);

        WorksheetSignoff::create([
            'worksheet_id'         => $worksheet->id,
            'client_name'          => 'A Client',
            'signature_png_base64' => 'iVBORw0KGgo=',
            'signed_with_comments' => false,
            'signed_at'            => now()->subDays(3),
        ]);

        Visit::factory()->create([
            'project_id'       => $project->id,
            'type'             => Visit::TYPE_INSTALL,
            'source_type'      => Visit::SOURCE_WORKSHEET,
            'source_id'        => $worksheet->id,
            'sent_back_at'     => now()->subDay(),
            'send_back_reason' => 'Label photos missing.',
        ]);

        $this->assertTrue(VisitReworkState::isReopened($worksheet->fresh()));

        // Sign-off is APPEND-ONLY: a re-signoff after remedials is a NEW row,
        // and the comparison follows it without anything being cleared.
        WorksheetSignoff::create([
            'worksheet_id'         => $worksheet->id,
            'client_name'          => 'A Client',
            'signature_png_base64' => 'iVBORw0KGgo=',
            'signed_with_comments' => false,
            'signed_at'            => now(),
        ]);

        $this->assertFalse(VisitReworkState::isReopened($worksheet->fresh()));
    }

    // ─── Task 3: the negative test — ten gates touched, these stay shut ──────

    /**
     * Eight security gates inside a public, unauthenticated controller were
     * edited. This is the test that proves they still hold shut for every case
     * that is NOT an active send-back.
     *
     * Iterates each edited write route against BOTH no-send-back scenarios, so
     * a missed site fails loudly rather than silently staying open.
     *
     * @test
     */
    public function test_a_survey_with_no_send_back_is_still_locked_after_submission(): void
    {
        $routes = $this->writeGateRoutes();
        $this->assertCount(8, $routes, 'The eight 403 write gates are the ones that were edited.');

        // (a) a submitted survey with NO visit at all.
        ['survey' => $s1, 'room' => $r1, 'photo' => $p1, 'question' => $q1, 'token' => $t1] = $this->submittedSurvey();
        $this->assertNull(VisitReworkState::forSurvey($s1));

        foreach ($routes as $label => $call) {
            $call($t1, $r1, $p1, $q1)->assertStatus(403, "GATE LET GO (no visit): {$label}");
        }

        // (b) a submitted survey whose visit exists but was NOT sent back.
        ['survey' => $s2, 'room' => $r2, 'photo' => $p2, 'question' => $q2, 'project' => $proj2, 'token' => $t2] = $this->submittedSurvey();
        Visit::factory()->create([
            'project_id'   => $proj2->id,
            'type'         => Visit::TYPE_SITE_SURVEY,
            'source_type'  => Visit::SOURCE_SITE_SURVEY,
            'source_id'    => $s2->id,
            'sent_at'      => now()->subDays(6),
            'sent_back_at' => null,
        ]);
        $this->assertFalse(VisitReworkState::isReopened($s2->fresh()));

        foreach ($routes as $label => $call) {
            $call($t2, $r2, $p2, $q2)->assertStatus(403, "GATE LET GO (visit not sent back): {$label}");
        }
    }

    /**
     * And the mirror: with an active send-back, none of the eight 403s.
     *
     * Without this, a gate accidentally hard-wired to `abort_if(true)` would
     * pass the negative test above and nobody would notice the link never
     * reopens.
     *
     * @test
     */
    public function test_every_edited_write_gate_reopens_together(): void
    {
        ['survey' => $survey, 'room' => $room, 'photo' => $photo, 'question' => $question, 'project' => $project, 'token' => $token] = $this->submittedSurvey();
        $this->sendBack($survey, $project);

        foreach ($this->writeGateRoutes() as $label => $call) {
            $this->assertNotSame(
                403,
                $call($token, $room, $photo, $question)->getStatusCode(),
                "GATE STAYED SHUT after a send-back: {$label}"
            );
        }
    }

    /**
     * The two `readonly` render flags are NOT 403 gates, so they get a render
     * assertion rather than being forced into the loop above.
     *
     * Only SurveyController:95 is exercisable: `GET /survey/{token}` routes to
     * SurveyController::show. PublicSurveyController::show (:164) is NOT bound
     * to any route — `grep "PublicSurveyController::class, 'show'"
     * routes/web.php` returns nothing — so it is unreachable dead code. It was
     * swapped for consistency; it is asserted statically below rather than
     * pretending an HTTP test covers it.
     *
     * @test
     */
    public function test_the_readonly_render_flag_follows_the_reopening(): void
    {
        ['survey' => $survey, 'project' => $project, 'token' => $token] = $this->submittedSurvey();

        $this->get("/survey/{$token}")->assertStatus(200)->assertViewHas('readonly', true);

        $this->sendBack($survey, $project);

        $this->get("/survey/{$token}")->assertStatus(200)->assertViewHas('readonly', false);

        // The unrouted twin, asserted as source so the swap cannot rot back.
        $this->assertStringContainsString(
            "'readonly'             => \$survey->isLockedForEngineer(),",
            file_get_contents(app_path('Http/Controllers/PublicSurveyController.php'))
        );
    }

    /**
     * Anti-rot: neither survey controller may reintroduce an `isSubmitted()`
     * engineer gate. `isSubmitted()` still exists on the model and still means
     * "was submitted" — it is simply no longer what decides editability.
     *
     * @test
     */
    public function test_no_engineer_gate_still_reads_is_submitted(): void
    {
        foreach (['PublicSurveyController', 'SurveyController'] as $controller) {
            $source = file_get_contents(app_path("Http/Controllers/{$controller}.php"));

            $this->assertStringNotContainsString('isSubmitted()', $source, "{$controller} still gates the engineer on isSubmitted().");
        }

        // ...and the model kept it, unchanged, for the PDF / notification paths.
        $this->assertTrue(method_exists(SiteSurvey::class, 'isSubmitted'));
    }

    // --- Task 2: the banner - two audiences, two amounts of detail ---------

    /**
     * A worksheet on a real public token, with rooms so the page renders its
     * body (the drawer and banner both live inside the populated branch).
     */
    private function worksheetLink(Project $project): Worksheet
    {
        return Worksheet::factory()->create([
            'project_id'     => $project->id,
            'generated_data' => ['rooms' => [[
                'name'                      => 'Main Hall',
                'is_surveyed'               => true,
                'install_steps'             => '',
                'cable_route_desc'          => '',
                'power_outlet_count'        => 0,
                'requires_additional_power' => false,
                'network_port_count'        => 0,
                'existing_cabling'          => '',
                'equipment'                 => [],
            ]]],
        ]);
    }

    /** @test */
    public function test_the_engineer_link_shows_the_office_reason(): void
    {
        ['survey' => $survey, 'project' => $project, 'token' => $token] = $this->submittedSurvey();
        $this->sendBack($survey, $project, 'SENTINEL-REASON-COMMS-ROOM-PHOTOS-MISSING');

        $body = $this->get("/survey/{$token}")->assertStatus(200)->getContent();

        $this->assertStringContainsString('The office has asked for more information', $body);
        $this->assertStringContainsString('SENTINEL-REASON-COMMS-ROOM-PHOTOS-MISSING', $body);
    }

    /**
     * T-46-05-02 - the client signs this page and must NOT read the office's
     * wording about its own engineer. Same sentinel, opposite expectation.
     *
     * @test
     */
    public function test_the_client_signed_worksheet_link_never_shows_the_office_reason(): void
    {
        $user      = User::factory()->create();
        $project   = Project::factory()->create(['user_id' => $user->id]);
        $worksheet = $this->worksheetLink($project);

        Visit::factory()->create([
            'project_id'       => $project->id,
            'type'             => Visit::TYPE_INSTALL,
            'source_type'      => Visit::SOURCE_WORKSHEET,
            'source_id'        => $worksheet->id,
            'sent_back_at'     => now()->subDay(),
            'send_back_reason' => 'SENTINEL-REASON-OFFICE-ONLY-WORDING',
        ]);

        $body = $this->get(route('public-worksheet.show', ['token' => $worksheet->access_token]))
            ->assertStatus(200)->getContent();

        // The client IS told the visit is not finished...
        $this->assertStringContainsString('The office has asked for more information', $body);
        // ...but never reads the office's opinion of the return.
        $this->assertStringNotContainsString('SENTINEL-REASON-OFFICE-ONLY-WORDING', $body);
    }

    /**
     * T-46-05-03 - send_back_reason is PM free text on an unauthenticated
     * page. Asserted on the RAW body: the payload must never appear
     * unescaped, and the page must still render 200.
     *
     * @test
     */
    public function test_a_hostile_reason_is_escaped_on_the_engineer_link(): void
    {
        ['survey' => $survey, 'project' => $project, 'token' => $token] = $this->submittedSurvey();
        $payload = '<script>alert(1)</script><img src=x onerror=alert(2)>';
        $this->sendBack($survey, $project, $payload);

        $body = $this->get("/survey/{$token}")->assertStatus(200)->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringNotContainsString('<img src=x onerror=alert(2)>', $body);
        // ...and it IS present, escaped, so the engineer still reads the ask.
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
    }

    /** @test */
    public function test_the_banner_never_uses_unescaped_blade_output(): void
    {
        $partial = file_get_contents(resource_path('views/partials/_office-sendback-banner.blade.php'));

        $this->assertStringNotContainsString('{!!', $partial, 'The banner renders PM free text on a client-signed page - unescaped output is forbidden.');
    }

    /** @test */
    public function test_no_banner_renders_when_nothing_was_sent_back(): void
    {
        ['token' => $token] = $this->submittedSurvey();
        $this->assertStringNotContainsString(
            'The office has asked for more information',
            $this->get("/survey/{$token}")->assertStatus(200)->getContent()
        );

        $user      = User::factory()->create();
        $project   = Project::factory()->create(['user_id' => $user->id]);
        $worksheet = $this->worksheetLink($project);
        $this->assertStringNotContainsString(
            'The office has asked for more information',
            $this->get(route('public-worksheet.show', ['token' => $worksheet->access_token]))->assertStatus(200)->getContent()
        );
    }

    /** @test */
    public function test_the_banner_disappears_once_the_engineer_resubmits(): void
    {
        ['survey' => $survey, 'project' => $project, 'token' => $token] = $this->submittedSurvey();
        $this->sendBack($survey, $project, 'SENTINEL-GONE-AFTER-RESUBMIT');

        $this->assertStringContainsString('SENTINEL-GONE-AFTER-RESUBMIT', $this->get("/survey/{$token}")->getContent());

        $survey->forceFill(['submitted_at' => now()])->save();

        $body = $this->get("/survey/{$token}")->assertStatus(200)->getContent();
        $this->assertStringNotContainsString('SENTINEL-GONE-AFTER-RESUBMIT', $body);
        $this->assertStringNotContainsString('The office has asked for more information', $body);
    }

    /** @test */
    public function test_rooms_in_scope_renders_on_the_worksheet_link(): void
    {
        $user      = User::factory()->create();
        $project   = Project::factory()->create(['user_id' => $user->id]);
        $worksheet = $this->worksheetLink($project);

        Visit::factory()->create([
            'project_id'     => $project->id,
            'type'           => Visit::TYPE_INSTALL,
            'source_type'    => Visit::SOURCE_WORKSHEET,
            'source_id'      => $worksheet->id,
            'sent_back_at'   => now()->subDay(),
            'rooms_in_scope' => ['Main Hall', 'Breakout 2'],
        ]);

        $body = $this->get(route('public-worksheet.show', ['token' => $worksheet->access_token]))
            ->assertStatus(200)->getContent();

        $this->assertStringContainsString('Rooms in scope for this visit', $body);
        $this->assertStringContainsString('Main Hall, Breakout 2', $body);
    }

    /**
     * Plan 46-03 widened the ONE "Site Logistics" drawer. The banner is a
     * separate element and must not have produced a second drawer or been
     * merged into it.
     *
     * @test
     */
    public function test_the_carry_forward_drawer_still_renders_exactly_once(): void
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        // The 46-03 drawer only renders when the project has a survey
        // carrying values - without one there would be nothing to count.
        $survey = SiteSurvey::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => 'Drawer Fixture',
            'status'       => 'completed',
        ]);
        $survey->forceFill([
            'site_risks'         => 'Live overhead cables in the plant room.',
            'access_constraints' => 'Goods lift only.',
        ])->save();

        $worksheet = $this->worksheetLink($project);

        Visit::factory()->create([
            'project_id'       => $project->id,
            'type'             => Visit::TYPE_INSTALL,
            'source_type'      => Visit::SOURCE_WORKSHEET,
            'source_id'        => $worksheet->id,
            'sent_back_at'     => now()->subDay(),
            'send_back_reason' => 'Anything.',
        ]);

        $body = $this->get(route('public-worksheet.show', ['token' => $worksheet->access_token]))
            ->assertStatus(200)->getContent();

        $this->assertSame(
            1,
            substr_count($body, 'Site Logistics &mdash; Arrival Info') + substr_count($body, 'Site Logistics — Arrival Info'),
            'The 46-03 carry-forward drawer must still render exactly once - the banner is a separate element, not a second drawer.'
        );
        $this->assertStringContainsString('The office has asked for more information', $body);
    }

    /**
     * VL-03 - rendering a banner rotates no token. Both engineer links must
     * still work after the office has asked for more information.
     *
     * @test
     */
    public function test_rendering_the_banner_rotates_no_access_token(): void
    {
        ['survey' => $survey, 'project' => $project, 'token' => $token] = $this->submittedSurvey();
        $this->sendBack($survey, $project);
        $surveyTokenBefore = $survey->fresh()->access_token;

        $this->get("/survey/{$token}")->assertStatus(200);
        $this->assertSame($surveyTokenBefore, $survey->fresh()->access_token);

        $worksheet = $this->worksheetLink($project);
        Visit::factory()->create([
            'project_id'   => $project->id,
            'type'         => Visit::TYPE_INSTALL,
            'source_type'  => Visit::SOURCE_WORKSHEET,
            'source_id'    => $worksheet->id,
            'sent_back_at' => now()->subDay(),
        ]);
        $wsTokenBefore = $worksheet->fresh()->access_token;

        $this->get(route('public-worksheet.show', ['token' => $worksheet->access_token]))->assertStatus(200);
        $this->assertSame($wsTokenBefore, $worksheet->fresh()->access_token);
    }

    /** @test */
    public function test_both_links_still_return_200_for_the_pre_existing_cases(): void
    {
        // No visit at all - a survey link and a legacy worksheet link.
        ['token' => $token] = $this->submittedSurvey();
        $this->get("/survey/{$token}")->assertStatus(200);

        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->get(route('public-worksheet.show', ['token' => $this->worksheetLink($project)->access_token]))
            ->assertStatus(200);

        // NOTE: a worksheet with NO rooms at all is deliberately NOT asserted
        // here. It 500s on `Undefined variable $signOffBlocked` - and it does
        // so identically on the unmodified pre-46-05 view (verified by
        // reverting the file to HEAD and re-running this case), so it is a
        // PRE-EXISTING defect in the empty-rooms branch, not a regression from
        // the banner. The banner include sits inside the populated branch and
        // is never reached in that case. Recorded in deferred-items.md.
    }
}
