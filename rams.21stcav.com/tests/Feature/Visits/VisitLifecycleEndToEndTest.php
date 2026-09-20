<?php

namespace Tests\Feature\Visits;

use App\Jobs\BuildWorksheetJob;
use App\Models\LabourResource;
use App\Models\Project;
use App\Models\ProjectActivityLog;
use App\Models\SiteSurvey;
use App\Models\Snag;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitNote;
use App\Models\Worksheet;
use App\Models\WorksheetSignoff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 46, Plan 46-08 — THE WHOLE PHASE AS ONE WALK.
 *
 * Plans 46-01..46-07 each proved their own half. Nobody had yet walked a job
 * from "create the visit" to "accepted" and checked that the survey's findings
 * reached the install engineer on the way. This file is that walk, and it is
 * deliberately ONE narrative test rather than nine small ones: every step here
 * is already unit-proved somewhere else, and the only thing left to prove is
 * that the steps COMPOSE.
 *
 * IT CROSSES THE AUTH BOUNDARY IN BOTH DIRECTIONS, AS HTTP:
 *   • the PM is `actingAs` a staff user POSTing to the cockpit;
 *   • the engineer is UNAUTHENTICATED, on a token URL, exactly as they are on
 *     a phone on site. `assertGuest()` is asserted at each public step so a
 *     future change that quietly puts the engineer behind a login fails here.
 *
 * THE CLOSING ASSERTION IS THE ONE THAT MATTERS. D-02 says an office action
 * never changes what the engineer said. Five office acts happen after the
 * engineer's first return — create the install visit, send back, add a note,
 * raise a snag, accept — and step 9 compares the engineer's bytes through
 * `getRawOriginal()` to prove none of them moved a single one.
 *
 * ── TIME TRAVEL, AND WHY IT IS HERE ─────────────────────────────────────────
 * `Visit::wasSentBack()` is a STRICT `greaterThan` against second-resolution
 * timestamps (deferred defect D-46-06-01). A walk executed inside one clock
 * second would have `sent_back_at == submitted_at` and the reopening would
 * read false for reasons that have nothing to do with the feature. The walk
 * therefore advances the clock between acts, which is also what a real job
 * does. This is NOT a fix for D-46-06-01 — that defect stays logged and
 * carried forward.
 */
class VisitLifecycleEndToEndTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cockpit.enabled' => true]);

        // The public survey routes carry per-route throttles. This walk drives
        // submit twice and step-save once; disabling ONLY the limiter keeps
        // every gate under test genuinely exercised.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    /**
     * The ten D-01 fields, one distinct sentinel each, so a failure names the
     * field that did not carry rather than saying "a string was missing".
     *
     * @return array<string, string>
     */
    private function tenSurveyFields(): array
    {
        return [
            'parking_restraints'       => 'E2E-PARKING-LOADING-BAY-ONLY',
            'site_access_notes'        => 'E2E-SITE-ACCESS-REPORT-TO-GATEHOUSE',
            'access_constraints'       => 'E2E-CONSTRAINT-GOODS-LIFT-ONLY',
            'delivery_routes'          => 'E2E-DELIVERY-REAR-SERVICE-ROAD',
            'comms_room_access_status' => 'yes',
            'comms_room_access_notes'  => 'E2E-COMMS-KEY-WITH-FACILITIES',
            'distance_from_base_miles' => '42',
            'distance_from_base_notes' => 'E2E-DISTANCE-M4-JUNCTION-11',
            'site_risks'               => 'E2E-RISK-ASBESTOS-IN-CEILING-VOID',
            'h_and_s_notes'            => 'E2E-HS-HARD-HAT-AND-HI-VIS',
            'general_notes'            => 'E2E-SURVEYOR-NOTES-CLIENT-PREFERS-MORNINGS',
        ];
    }

    /** Everything the engineer owns on the survey, read RAW. */
    private function surveyBytes(SiteSurvey $survey): array
    {
        $survey->refresh();

        return [
            'submitted_at' => (string) $survey->getRawOriginal('submitted_at'),
            'survey_data'  => (string) $survey->getRawOriginal('survey_data'),
            'access_token' => (string) $survey->getRawOriginal('access_token'),
        ];
    }

    /**
     * The ENGINEER'S SEAT: unauthenticated, token only.
     *
     * `actingAs()` persists for the whole test, so a public request made after
     * a PM request would silently carry the PM's session — and every
     * `assertGuest()` in this walk would be asserting something the framework
     * arranged rather than something the route allows. Dropping the guard
     * first makes each engineer step genuinely anonymous.
     */
    private function asEngineer(): self
    {
        auth()->logout();
        $this->assertGuest();

        return $this;
    }

    /** The rendered cockpit region for a module, entity-decoded. */
    private function region(User $pm, Project $project, string $module): string
    {
        $body = $this->actingAs($pm)
            ->get(route('projects.cockpit', ['project' => $project, 'module' => $module]))
            ->assertOk()
            ->getContent();

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$body);
        libxml_clear_errors();

        $node = (new \DOMXPath($dom))
            ->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' cav-cockpit ')]")
            ->item(0);

        $this->assertNotNull($node, 'The cav-cockpit root was not found — an assertion on it would pass vacuously.');

        return html_entity_decode($dom->saveHTML($node), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * A PM delivers a job: create the survey visit, the engineer returns it,
     * the install link carries what the survey learned, the PM sends it back,
     * the engineer resubmits, the PM annotates, snags and accepts.
     */
    public function test_a_project_manager_walks_a_job_from_create_visit_to_accepted(): void
    {
        Bus::fake();

        $pm      = User::factory()->create(['name' => 'Priya Mistry']);
        $project = Project::factory()->create([
            'name'   => 'End To End Job',
            'status' => Project::STATUS_INSTALLING,
        ]);

        $dan = LabourResource::factory()->create(['name' => 'Dan Okafor', 'is_active' => true]);
        $mel = LabourResource::factory()->create(['name' => 'Mel Adeyemi', 'is_active' => true]);

        // ── 1. THE PM CREATES THE SURVEY VISIT ──────────────────────────────
        // From inside the Site survey drawer, with a date and two names. One
        // POST must produce three things: the visit, its engineer link, and an
        // accountable activity row.

        $this->actingAs($pm)
            ->post(route('projects.cockpit.visits.store', $project), [
                'module'              => 'site_survey',
                'visit_type'          => Visit::TYPE_SITE_SURVEY,
                'scheduled_date'      => '2026-10-12',
                'labour_resource_ids' => [$dan->id, $mel->id],
            ])
            ->assertRedirect(route('projects.cockpit', ['project' => $project, 'module' => 'site_survey']))
            ->assertSessionHas('success');

        $surveyVisit = Visit::where('project_id', $project->id)->sole();
        $survey      = SiteSurvey::where('project_id', $project->id)->sole();

        $this->assertSame(Visit::TYPE_SITE_SURVEY, $surveyVisit->type);
        $this->assertSame($pm->id, $surveyVisit->created_by_user_id);
        $this->assertSame([$dan->id, $mel->id], $surveyVisit->labour_resource_ids);
        $this->assertSame(Visit::SOURCE_SITE_SURVEY, $surveyVisit->source_type);
        $this->assertSame($survey->id, $surveyVisit->source_id);
        $this->assertNotEmpty($survey->access_token, 'The visit must come with an engineer link.');
        $this->assertSame(Visit::STATE_SENT, $surveyVisit->state());

        $this->assertSame(
            1,
            ProjectActivityLog::where('project_id', $project->id)
                ->where('action', ProjectActivityLog::ACTION_VISIT_CREATED)
                ->count(),
            'Creating a visit writes exactly one activity row.',
        );

        $surveyToken = $survey->access_token;

        // ── 2. THE ENGINEER RETURNS THE SURVEY — UNAUTHENTICATED, BY TOKEN ───
        // All ten D-01 fields are captured through the REAL public submit
        // endpoint. Nothing here is forceFilled into place except survey_data,
        // which is the wizard's own captured payload and has no flat-form
        // route to arrive by.

        $this->travel(3)->days();

        // The room the engineer walked. The wizard creates these as they go;
        // this walk is not about the wizard, so it is made directly — the same
        // shortcut 46-05's fixture takes.
        $survey->rooms()->create([
            'room_name'  => 'Board Room',
            'space_type' => 'general',
            'sort_order' => 0,
        ]);

        $this->asEngineer()->post("/survey/{$surveyToken}/submit", array_merge(
            $this->tenSurveyFields(),
            ['surveyor_name' => 'Dan Okafor'],
        ))->assertRedirect(route('survey.confirmation', ['token' => $surveyToken]));

        // The engineer is never logged in — their seat is the token URL.
        $this->assertGuest();

        $survey->refresh();
        $this->assertNotNull($survey->submitted_at);

        foreach ($this->tenSurveyFields() as $column => $value) {
            $this->assertSame(
                $value,
                (string) $survey->{$column},
                "The engineer's `{$column}` did not land on the survey record.",
            );
        }

        // The engineer's bytes AS SUBMITTED. Step 9 closes against these.
        $bytesAtFirstSubmission = $this->surveyBytes($survey);

        $this->assertSame(Visit::STATE_RETURNED, $surveyVisit->refresh()->state());

        // ── 3. THE PM CREATES THE INSTALL VISIT ─────────────────────────────

        $this->travel(1)->days();

        $this->actingAs($pm)
            ->post(route('projects.cockpit.visits.store', $project), [
                'module'              => 'worksheet',
                'visit_type'          => Visit::TYPE_INSTALL,
                'scheduled_date'      => '2026-11-02',
                'rooms'               => ['Main Hall'],
                'labour_resource_ids' => [$mel->id],
            ])
            ->assertRedirect(route('projects.cockpit', ['project' => $project, 'module' => 'worksheet']));

        $installVisit = Visit::where('project_id', $project->id)
            ->where('type', Visit::TYPE_INSTALL)
            ->sole();

        $worksheet = Worksheet::where('project_id', $project->id)->sole();

        $this->assertSame(Visit::SOURCE_WORKSHEET, $installVisit->source_type);
        $this->assertSame($worksheet->id, $installVisit->source_id);
        $this->assertNotEmpty($worksheet->access_token);
        Bus::assertDispatched(BuildWorksheetJob::class);

        // The build job is faked — this walk is about the lifecycle, not about
        // document generation (46-04's own tests do the same). The generated
        // rooms are set directly so the engineer's page has something to be
        // about.
        $worksheet->forceFill([
            'status'         => Worksheet::STATUS_DRAFT,
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
        ])->save();

        $worksheetToken      = $worksheet->access_token;
        $worksheetTokenBytes = (string) $worksheet->getRawOriginal('access_token');

        // ── 4. THE MOMENT THE PHASE EXISTS FOR ──────────────────────────────
        // The INSTALL engineer opens their own link, unauthenticated, and the
        // survey's findings are on it. Then the survey is edited and the link
        // shows the NEW wording with the old one gone — because D-01 reads the
        // survey record and never copies it.

        $engineerPage = $this->asEngineer()
            ->get(route('public-worksheet.show', ['token' => $worksheetToken]))
            ->assertOk();

        foreach ([
            'E2E-PARKING-LOADING-BAY-ONLY',
            'E2E-SITE-ACCESS-REPORT-TO-GATEHOUSE',
            'E2E-CONSTRAINT-GOODS-LIFT-ONLY',
            'E2E-DELIVERY-REAR-SERVICE-ROAD',
            'Permission required',
            'E2E-COMMS-KEY-WITH-FACILITIES',
            '42 miles from depot',
            'E2E-DISTANCE-M4-JUNCTION-11',
            'E2E-RISK-ASBESTOS-IN-CEILING-VOID',
            'E2E-HS-HARD-HAT-AND-HI-VIS',
            'E2E-SURVEYOR-NOTES-CLIENT-PREFERS-MORNINGS',
        ] as $carried) {
            $engineerPage->assertSee($carried, escape: false);
        }

        $survey->update([
            'site_risks'         => 'E2E-RISK-LIVE-OVERHEAD-CABLES',
            'access_constraints' => 'E2E-CONSTRAINT-SCAFFOLD-BLOCKS-MAIN-DOOR',
        ]);

        $afterEdit = $this->asEngineer()->get(route('public-worksheet.show', ['token' => $worksheetToken]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('E2E-RISK-LIVE-OVERHEAD-CABLES', $afterEdit);
        $this->assertStringContainsString('E2E-CONSTRAINT-SCAFFOLD-BLOCKS-MAIN-DOOR', $afterEdit);
        $this->assertStringNotContainsString('E2E-RISK-ASBESTOS-IN-CEILING-VOID', $afterEdit);
        $this->assertStringNotContainsString('E2E-CONSTRAINT-GOODS-LIFT-ONLY', $afterEdit);

        // Editing the survey is an OFFICE act on the survey's own record, not
        // on what the engineer submitted: submitted_at / survey_data /
        // access_token are untouched by it.
        $this->assertSame($bytesAtFirstSubmission, $this->surveyBytes($survey));

        // ── 5. THE PM SENDS THE SURVEY VISIT BACK, WITH A REASON ────────────

        $this->travel(1)->days();

        $reason = 'E2E-SENDBACK-PHOTOS-OF-THE-COMMS-ROOM-ARE-MISSING';

        $this->actingAs($pm)
            ->post(route('projects.cockpit.visits.send-back', ['project' => $project, 'visit' => $surveyVisit]), [
                'reason' => $reason,
            ])
            ->assertRedirect(route('projects.cockpit', ['project' => $project, 'module' => 'site_survey']))
            ->assertSessionHas('success');

        $surveyVisit->refresh();
        $this->assertSame(Visit::STATE_SENT_BACK, $surveyVisit->state());

        // (a) THE OFFICE ACT ITSELF CHANGED NOTHING THE ENGINEER OWNS. Asserted
        //     HERE, before the engineer touches their own record again at (b) —
        //     an engineer editing through a reopened link is the point of a
        //     send-back and is not an office write.
        $this->assertSame($bytesAtFirstSubmission, $this->surveyBytes($survey));

        // (b) the engineer's link accepts edits again — with nothing cleared.
        $this->asEngineer()->postJson("/survey/{$surveyToken}/step-save", [
            'room_index' => 0,
            'step'       => 1,
            'data'       => ['name' => 'Board Room', 'type' => 'general'],
        ])->assertStatus(200);

        $this->assertTrue($survey->fresh()->isSubmitted(), 'submitted_at is never cleared to reopen a link.');
        $this->assertFalse($survey->fresh()->isLockedForEngineer());

        // (c) the ENGINEER reads the reason on their own link.
        $this->asEngineer()
            ->get("/survey/{$surveyToken}")
            ->assertOk()
            ->assertSee($reason, escape: false);

        // (d) the CLIENT-SIGNED worksheet link does NOT carry it (T-46-05-02).
        $clientBody = $this->asEngineer()->get(route('public-worksheet.show', ['token' => $worksheetToken]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            $reason,
            $clientBody,
            "The office's wording about its own engineer must never reach the page a client signs.",
        );

        // (e) `submitted_at` and the token are STILL untouched, now that the
        //     engineer has edited through the reopened link. Only `survey_data`
        //     moved, and the engineer moved it.
        $this->assertSame(
            $bytesAtFirstSubmission['submitted_at'],
            $this->surveyBytes($survey)['submitted_at'],
            'Reopening a link must never clear the engineer submission marker.',
        );
        $this->assertSame(
            $bytesAtFirstSubmission['access_token'],
            $this->surveyBytes($survey)['access_token'],
        );

        // ── 6. THE ENGINEER RESUBMITS — AND THE SURVEY RELOCKS ──────────────
        // No flag is cleared by hand anywhere: the reopening was `sent_back_at
        // > the last submission`, so a newer submission ends it by arithmetic.

        $this->travel(1)->days();

        $this->asEngineer()->post("/survey/{$surveyToken}/submit", array_merge(
            $this->tenSurveyFields(),
            [
                'surveyor_name'      => 'Dan Okafor',
                'site_risks'         => 'E2E-RISK-LIVE-OVERHEAD-CABLES',
                'access_constraints' => 'E2E-CONSTRAINT-SCAFFOLD-BLOCKS-MAIN-DOOR',
            ],
        ))->assertRedirect(route('survey.confirmation', ['token' => $surveyToken]));

        $survey->refresh();
        $surveyVisit->refresh();

        $this->assertFalse($surveyVisit->wasSentBack(), 'A resubmission ends the reopening — no flag to clear.');
        $this->assertTrue($survey->isLockedForEngineer(), 'The survey relocked on its own.');
        $this->assertSame(Visit::STATE_RETURNED, $surveyVisit->state());

        $this->asEngineer()->postJson("/survey/{$surveyToken}/step-save", [
            'room_index' => 0,
            'step'       => 1,
            'data'       => ['name' => 'Board Room', 'type' => 'general'],
        ])->assertStatus(403);

        // The banner goes away WHOLE — no "previously sent back" history.
        $this->asEngineer()
            ->get("/survey/{$surveyToken}")
            ->assertOk()
            ->assertDontSee($reason, escape: false);

        // The engineer's bytes as they stand after THEIR last act. Every
        // office act from here must leave these alone.
        $bytesAfterResubmission = $this->surveyBytes($survey);
        $this->assertNotSame(
            $bytesAtFirstSubmission['submitted_at'],
            $bytesAfterResubmission['submitted_at'],
            'The ENGINEER may move their own submission marker — only the office may not.',
        );
        $this->assertSame($bytesAtFirstSubmission['access_token'], $bytesAfterResubmission['access_token']);
        // `survey_data` DID move between the two submissions — the engineer
        // edited their own record through the reopened link at step 5(a). That
        // is the whole point of a send-back and is not an office write.
        $this->assertNotSame(
            $bytesAtFirstSubmission['survey_data'],
            $bytesAfterResubmission['survey_data'],
            'The reopened link must actually have accepted the edit the engineer made.',
        );

        // ── 7. THE INSTALL ENGINEER RETURNS, AND THE PM ANNOTATES AND SNAGS ──
        // The install return lives on the WORKSHEET (an append-only signoff),
        // never on the visit — there is no returned_at column to write.

        $this->travel(1)->days();

        WorksheetSignoff::create([
            'worksheet_id'         => $worksheet->id,
            'client_name'          => 'A Client',
            'signature_png_base64' => 'iVBORw0KGgo=',
            'signed_with_comments' => false,
            'signed_at'            => now(),
        ]);

        $installVisit->refresh();
        $this->assertSame(Visit::STATE_RETURNED, $installVisit->state());

        $worksheetBefore = [
            'access_token'   => (string) $worksheet->fresh()->getRawOriginal('access_token'),
            'generated_data' => (string) $worksheet->fresh()->getRawOriginal('generated_data'),
            'updated_at'     => (string) $worksheet->fresh()->getRawOriginal('updated_at'),
        ];
        $signoffCountBefore = DB::table('worksheet_signoffs')->count();

        $this->travel(1)->days();

        $this->actingAs($pm)
            ->post(route('projects.cockpit.visits.notes', ['project' => $project, 'visit' => $installVisit]), [
                'body' => 'E2E-OFFICE-NOTE-CHASE-THE-CABLE-ROUTE-PHOTO',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($pm)
            ->post(route('projects.cockpit.visits.snags', ['project' => $project, 'visit' => $installVisit]), [
                'title'     => 'E2E-SNAG-SCREEN-BRACKET-MISSING',
                'detail'    => 'Bracket not supplied with the display.',
                'room_name' => 'Main Hall',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $note = VisitNote::where('visit_id', $installVisit->id)->sole();
        $this->assertSame($pm->id, $note->user_id);
        $this->assertSame('E2E-OFFICE-NOTE-CHASE-THE-CABLE-ROUTE-PHOTO', $note->body);

        $snag = Snag::where('visit_id', $installVisit->id)->sole();
        $this->assertSame(Snag::STATUS_OPEN, $snag->status);
        $this->assertSame($pm->id, $snag->raised_by_user_id);

        $this->assertSame(
            1,
            ProjectActivityLog::where('project_id', $project->id)
                ->where('action', ProjectActivityLog::ACTION_SNAG_RAISED)
                ->count(),
        );

        // THE WRAPPED WORKSHEET IS BYTE-IDENTICAL — updated_at included.
        $this->assertSame($worksheetBefore, [
            'access_token'   => (string) $worksheet->fresh()->getRawOriginal('access_token'),
            'generated_data' => (string) $worksheet->fresh()->getRawOriginal('generated_data'),
            'updated_at'     => (string) $worksheet->fresh()->getRawOriginal('updated_at'),
        ]);
        $this->assertSame($signoffCountBefore, DB::table('worksheet_signoffs')->count());

        // ── 8. THE PM ACCEPTS THE INSTALL VISIT ─────────────────────────────

        $this->assertStringContainsString(
            '0 of 1 visit completed',
            $this->region($pm, $project, 'worksheet'),
        );

        $this->travel(1)->days();

        $this->actingAs($pm)
            ->post(route('projects.cockpit.visits.accept', ['project' => $project, 'visit' => $installVisit]))
            ->assertRedirect(route('projects.cockpit', ['project' => $project, 'module' => 'worksheet']))
            ->assertSessionHas('success');

        $installVisit->refresh();
        $this->assertNotNull($installVisit->accepted_at);
        $this->assertSame($pm->id, $installVisit->accepted_by_user_id);
        $this->assertSame(Visit::STATE_ACCEPTED, $installVisit->state());
        $this->assertSame(Visit::STATUS_PLANNED, $installVisit->status, 'The stored vocabulary never grew.');

        $worksheetRegion = $this->region($pm, $project, 'worksheet');

        // The ring moved by exactly one, and the lock is a SENTENCE, not a
        // greyed-out control (D-06: a PM who needs a change sends it back).
        $this->assertStringContainsString('1 of 1 visit completed', $worksheetRegion);
        $this->assertStringContainsString('Scope locked', $worksheetRegion);
        $this->assertStringContainsString('Accepted by Priya Mistry', $worksheetRegion);

        // ── 9. THE CLOSING ASSERTION ────────────────────────────────────────
        // Five office acts happened after the engineer's first return: the
        // install visit was created, the survey visit was sent back, a note was
        // added, a snag was raised, and the install visit was accepted. NOT ONE
        // of them moved a byte the engineer owns.

        $this->assertSame(
            $bytesAfterResubmission,
            $this->surveyBytes($survey),
            "The office acted five times and rewrote something the engineer said.",
        );

        $this->assertSame(
            $worksheetTokenBytes,
            (string) $worksheet->fresh()->getRawOriginal('access_token'),
            'The engineer link was never rotated by an office act.',
        );

        $this->assertSame(
            $bytesAtFirstSubmission['access_token'],
            $this->surveyBytes($survey)['access_token'],
            'The survey token the engineer was given is the one they still hold.',
        );
    }
}
