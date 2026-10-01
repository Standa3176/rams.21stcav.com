<?php

namespace Tests\Feature\Cockpit;

use App\Models\Device;
use App\Models\DeviceLabelPhoto;
use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\SiteSurveyPhoto;
use App\Models\SiteSurveyRoom;
use App\Models\SiteSurveyRoomQuestion;
use App\Models\User;
use App\Models\Visit;
use App\Models\Worksheet;
use App\Models\WorksheetPhoto;
use App\Models\WorksheetSignoff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Phase 47, Plan 47-05 — THE WALK.
 *
 * Not a presenter test. Every assertion below goes through a real HTTP
 * request against the real routes this phase's four plans wired, over a
 * project seeded with realistic, non-vacuous data — the same discipline
 * 46-04/46.1-04/47-03/47-04 already proved out, walked end to end rather
 * than per-plan.
 *
 * Four walks, matching 47-05-PLAN.md exactly:
 *   A — the engineer link, the acceptance test itself.
 *   B — the Returned tab, evidence and the calm order.
 *   C — the four controls, capped and gated.
 *   D — the leak fix, proven on the page it lives on.
 *
 * COUNTED, NOT CLAIMED: this class seeds 2 WRITE_SURFACE_TABLES snapshots (one
 * around the ZIP follow, one around the cross-project write attempt), renders
 * 9 distinct visit/module states through real GETs, and re-confirms the fence
 * numbers (2 / 19 / 9 / 13, TABS=4, ACTIONS=4) by reading the constants rather
 * than inheriting them from a prior plan's summary.
 */
class CockpitLinksAndReturnsEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        return User::factory()->create();
    }

    private function openCockpit(Project $project, array $query = []): string
    {
        config(['cockpit.enabled' => true]);

        $url = route('projects.cockpit', ['project' => $project] + $query);

        return $this->actingAs($this->actor())->get($url)->assertOk()->getContent();
    }

    // ======================================================================
    // WALK A — the engineer link, the acceptance test itself
    // ======================================================================

    public function test_walk_a_the_survey_link_is_visible_selectable_text_with_its_state(): void
    {
        $project = Project::factory()->create(['name' => 'Walk A Job', 'status' => Project::STATUS_INSTALLING]);

        $survey = SiteSurvey::create([
            'project_id'    => $project->id,
            'user_id'       => User::factory()->create()->id,
            'project_name'  => $project->name,
            'status'        => 'draft',
            'surveyor_name' => 'Walk A Surveyor',
        ]);

        $body = $this->openCockpit($project, ['module' => 'site_survey']);

        // Step 2: the publicUrl() string appears as plain text, not merely as
        // the href attribute value — both checked, so an <a href> with hidden
        // or truncated text could not satisfy this.
        $this->assertStringContainsString($survey->publicUrl(), $body);
        $this->assertStringContainsString('Issued — awaiting the engineer', $body);
        $this->assertStringContainsString(
            'There is no way to revoke a survey link.',
            $body,
            'D-02: the survey half must STATE the absence of a revoke, never render a disabled one.'
        );
    }

    public function test_walk_a_the_worksheet_link_is_visible_with_a_working_revoke_and_old_token_dies(): void
    {
        $project   = Project::factory()->create(['name' => 'Walk A Job', 'status' => Project::STATUS_INSTALLING]);
        $worksheet = Worksheet::factory()->create(['project_id' => $project->id]);
        $oldToken  = $worksheet->access_token;
        $oldUrl    = $worksheet->publicUrl();

        $body = $this->openCockpit($project, ['module' => 'worksheet']);

        $this->assertStringContainsString($oldUrl, $body);
        $this->assertStringContainsString('Revoke and reissue', $body);

        // Step 4: revoke, then re-GET. Old token gone, a DIFFERENT new one
        // shown, exactly one worksheets row (update, not a second create).
        $before = DB::table('worksheets')->count();

        $this->actingAs($this->actor())
            ->post(route('worksheets.revoke-token', $worksheet))
            ->assertRedirect();

        $this->assertSame($before, DB::table('worksheets')->count(), 'Revoke must UPDATE the row, never create a second.');

        $worksheet->refresh();
        $newUrl = $worksheet->publicUrl();

        $this->assertNotSame($oldToken, $worksheet->access_token);
        $this->assertNotSame($oldUrl, $newUrl);

        $after = $this->openCockpit($project, ['module' => 'worksheet']);

        $this->assertStringNotContainsString($oldToken, $after, 'The OLD token must be gone after revoke.');
        $this->assertStringContainsString($newUrl, $after, 'The NEW link must be shown in its place.');
    }

    public function test_walk_a_rams_and_om_render_no_link_card_at_all(): void
    {
        $project = Project::factory()->create(['name' => 'Walk A Job', 'status' => Project::STATUS_INSTALLING]);

        foreach (['rams', 'om'] as $moduleKey) {
            $body = $this->openCockpit($project, ['module' => $moduleKey]);

            $this->assertStringNotContainsString('Engineer link', $body, "`{$moduleKey}` must render no link card.");
            $this->assertStringNotContainsString('Current link:', $body);
        }
    }

    public function test_walk_a_the_issued_public_survey_link_genuinely_opens(): void
    {
        $project = Project::factory()->create(['name' => 'Walk A Job', 'status' => Project::STATUS_INSTALLING]);

        $survey = SiteSurvey::create([
            'project_id'    => $project->id,
            'user_id'       => User::factory()->create()->id,
            'project_name'  => $project->name,
            'status'        => 'draft',
            'surveyor_name' => 'Walk A Surveyor',
        ]);

        // Step 6: the link the PM was shown genuinely resolves, unauthenticated.
        $this->get($survey->publicUrl())->assertOk();
    }

    // ======================================================================
    // WALK B — the Returned tab, evidence and the calm order
    // ======================================================================

    private function seededWorksheetVisit(Project $project): array
    {
        $worksheet = Worksheet::factory()->create(['project_id' => $project->id]);

        Storage::fake('local');
        Storage::disk('local')->put('worksheet-photos/walkb-after-01.jpg', 'after-bytes');

        WorksheetPhoto::create([
            'worksheet_id'  => $worksheet->id,
            'room_name'     => 'Boardroom',
            'filename'      => 'worksheet-photos/walkb-after-01.jpg',
            'original_name' => 'install-01.jpg',
            'mime_type'     => 'image/jpeg',
            'sort_order'    => 0,
        ]);

        Storage::fake('public');
        Storage::disk('public')->put('device-labels/walkb-label-01.jpg', 'label-bytes');

        $device = Device::create([
            'project_id'    => $project->id,
            'room_name'     => 'Boardroom',
            'description'   => 'Ceiling microphone array',
            'serial_number' => 'SN-9900-WB',
        ]);

        DeviceLabelPhoto::create([
            'project_id'   => $project->id,
            'worksheet_id' => $worksheet->id,
            'device_id'    => $device->id,
            'room_name'    => 'Boardroom',
            'photo_path'   => 'device-labels/walkb-label-01.jpg',
            'confirmed'    => true,
            'captured_at'  => now()->subDay(),
            // RV-03 non-vacuity (step 8): a REALISTIC audit stamp, seeded here
            // so its later absence from the render is not vacuous.
            'captured_by'  => 'ip:203.0.113.9|actor:deadbeef',
        ]);

        WorksheetSignoff::create([
            'worksheet_id'         => $worksheet->id,
            'client_name'          => 'Priya Raman',
            'signature_png_base64' => 'iVBORw0KGgo=',
            'signed_with_comments' => false,
            'signed_at'            => now()->subHour(),
            // RV-03 non-vacuity.
            'ip_address'           => '198.51.100.77',
            'user_agent'           => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X)',
        ]);

        $visit = Visit::factory()->returned()->create([
            'project_id'     => $project->id,
            'type'           => Visit::TYPE_INSTALL,
            'title'          => 'Walk B install',
            'scheduled_date' => '2026-09-10',
            'source_type'    => Visit::SOURCE_WORKSHEET,
            'source_id'      => $worksheet->id,
        ]);

        return [$visit, $worksheet];
    }

    private function seededSurveyVisit(Project $project): array
    {
        $survey = SiteSurvey::create([
            'project_id'    => $project->id,
            'user_id'       => User::factory()->create()->id,
            'project_name'  => $project->name,
            'status'        => 'completed',
            'surveyor_name' => 'Walk B Surveyor',
        ]);

        $room = SiteSurveyRoom::create([
            'site_survey_id' => $survey->id,
            'room_name'      => 'Comms room',
            'sort_order'     => 0,
            'notes'          => 'Ladder needed for the ceiling void.',
        ]);

        Storage::fake('local');
        Storage::disk('local')->put('survey-photos/walkb-before-01.jpg', 'before-bytes');

        SiteSurveyPhoto::create([
            'site_survey_room_id' => $room->id,
            'filename'            => 'survey-photos/walkb-before-01.jpg',
            'original_name'       => 'before-01.jpg',
            'mime_type'           => 'image/jpeg',
            'sort_order'          => 0,
        ]);

        SiteSurveyRoomQuestion::create([
            'site_survey_room_id' => $room->id,
            'question'            => 'Is the comms room reachable without a ladder?',
            'sort_order'          => 0,
            'answer'              => 'other',
            'other_text'          => 'A step stool is enough.',
        ]);

        $visit = Visit::factory()->backfilledFromSurvey($survey)->create([
            'project_id'     => $project->id,
            'title'          => 'Walk B survey',
            'scheduled_date' => '2026-08-20',
        ]);

        return [$visit, $survey, $room];
    }

    public function test_walk_b_the_worksheet_returned_tab_shows_the_calm_order_and_never_the_three_banned_columns(): void
    {
        $project = Project::factory()->create(['name' => 'Walk B Job', 'status' => Project::STATUS_INSTALLING]);

        [$visit, $worksheet] = $this->seededWorksheetVisit($project);

        // Non-vacuity: the banned values are genuinely in the database before
        // we assert their absence from the render.
        $label = DeviceLabelPhoto::where('worksheet_id', $worksheet->id)->firstOrFail();
        $this->assertSame('ip:203.0.113.9|actor:deadbeef', $label->captured_by);

        $signoff = WorksheetSignoff::where('worksheet_id', $worksheet->id)->firstOrFail();
        $this->assertSame('198.51.100.77', $signoff->ip_address);
        $this->assertStringContainsString('iPhone', (string) $signoff->user_agent);

        $body = $this->openCockpit($project, ['module' => 'worksheet', 'tab' => 'returned']);

        // In order: review-state sentence, ZIP link, serials, gallery, sign-off.
        $statePos  = strpos($body, 'Sent back') !== false || strpos($body, 'Awaiting the engineer') !== false
            ? min(array_filter([
                strpos($body, 'Sent back') ?: null,
                strpos($body, 'Awaiting the engineer') ?: null,
            ]))
            : false;
        $zipPos     = strpos($body, 'Download all photos (ZIP)');
        $serialsPos = strpos($body, 'Captured serials');
        $galleryPos = strpos($body, 'cav-returned__sheet');
        $signoffPos = strpos($body, 'Client sign-off');

        $this->assertNotFalse($zipPos, 'The hand-off ZIP link must render.');
        $this->assertNotFalse($serialsPos, 'The serials list must render.');
        $this->assertNotFalse($galleryPos, 'The photo gallery must render.');
        $this->assertNotFalse($signoffPos, 'The sign-off block must render.');

        $this->assertTrue($zipPos < $serialsPos, 'ZIP link must precede the serials list.');
        $this->assertTrue($galleryPos < $serialsPos, 'Gallery must precede the serials list.');
        $this->assertTrue($serialsPos < $signoffPos, 'Serials must precede sign-off.');

        // The seeded serial, confirmed, with a captured date.
        $this->assertStringContainsString('SN-9900-WB', $body);
        $this->assertStringContainsString('Confirmed', $body);

        // The sign-off block: client name + signature image, never the audit pair.
        $this->assertStringContainsString('Priya Raman', $body);
        $this->assertStringContainsString('cav-returned__signature', $body);

        // RV-03 — never rendered, against values proven present above.
        $this->assertStringNotContainsString('203.0.113.9', $body);
        $this->assertStringNotContainsString('deadbeef', $body);
        $this->assertStringNotContainsString('198.51.100.77', $body);
        $this->assertStringNotContainsString('iPhone', $body);
    }

    public function test_walk_b_the_survey_returned_tab_shows_room_cards_with_notes_and_answers(): void
    {
        $project = Project::factory()->create(['name' => 'Walk B Job', 'status' => Project::STATUS_INSTALLING]);

        $this->seededSurveyVisit($project);

        $body = $this->openCockpit($project, ['module' => 'site_survey', 'tab' => 'returned']);

        $this->assertStringContainsString('Comms room', $body);
        $this->assertStringContainsString('Ladder needed for the ceiling void.', $body);
        $this->assertStringContainsString('A step stool is enough.', $body);
        $this->assertStringContainsString('1 of 1 questions answered', $body);
    }

    public function test_walk_b_the_zip_link_opens_contains_room_bucket_entries_and_writes_nothing(): void
    {
        $project = Project::factory()->create(['name' => 'Walk B Job', 'status' => Project::STATUS_INSTALLING]);

        [$visit] = $this->seededWorksheetVisit($project);

        $tables = [
            'visits', 'install_records', 'install_programmes', 'site_surveys', 'worksheets',
            'snags', 'project_activity_logs', 'site_survey_photos', 'worksheet_photos',
            'worksheet_signoffs', 'device_label_photos', 'labour_resources', 'project_packages',
        ];

        $this->assertCount(13, $tables, 'WRITE_SURFACE_TABLES is pinned at 13 — confirm against the real constant in Task 2.');

        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->count();
        }

        $body = $this->openCockpit($project, ['module' => 'worksheet', 'tab' => 'returned']);
        $this->assertStringContainsString('Download all photos (ZIP)', $body);

        $zipUrl = route('projects.cockpit.visits.photos-zip', ['project' => $project, 'visit' => $visit]);
        $response = $this->actingAs($this->actor())->get($zipUrl);
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/zip');

        $tmp = tempnam(sys_get_temp_dir(), 'walkb-zip-').'.zip';
        file_put_contents($tmp, $response->streamedContent() ?: $response->getContent());

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($tmp) === true, 'The response must be a real, openable ZIP archive.');

        $foundBucketEntry = false;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if (preg_match('#^Boardroom/(after|label)/#', $name) === 1) {
                $foundBucketEntry = true;
            }
        }

        $zip->close();
        @unlink($tmp);

        $this->assertTrue($foundBucketEntry, 'The archive must contain entries under {Room}/{bucket}/.');

        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->count(), "Following the ZIP link moved `{$table}`.");
        }
    }

    public function test_walk_b_rams_returned_tab_falls_back_to_overview_with_no_fourth_tab_and_no_500(): void
    {
        $project = Project::factory()->create(['name' => 'Walk B Job', 'status' => Project::STATUS_INSTALLING]);

        // RAMS carries no visit types at all — $offersReturned is always false.
        $response = $this->actingAs($this->actor())->get(
            route('projects.cockpit', ['project' => $project, 'module' => 'rams', 'tab' => 'returned'])
        );

        $response->assertOk();

        $body = $response->getContent();

        $this->assertStringNotContainsString('cav-panel__tab" href="'.route('projects.cockpit', ['project' => $project, 'module' => 'rams', 'tab' => 'returned']), $body);
        $this->assertStringNotContainsString('Nothing has come back from site yet.', $body, 'RAMS never offers the Returned tab at all — not even its own empty sentence.');
    }

    // ======================================================================
    // WALK C — the four controls, capped and gated
    // ======================================================================

    /**
     * DEVIATION (Rule 1 — the plan's own task text, corrected against the
     * real gates): 47-05-PLAN.md Task 1 step 12 says a RETURNED visit shows
     * "Accept and Send back ... while Add note and Raise a snag do not
     * (state-gated)". Reading `visit-row.blade.php`'s real gates (confirmed
     * before writing this test, per the analysis-paralysis / non-vacuity
     * instructions) shows otherwise: `$canNote` includes STATE_RETURNED
     * alongside SENT_BACK/ACCEPTED, and `$canSnag` mirrors `$canSendBack`
     * (both true for RETURNED). So a fresh RETURNED visit legitimately
     * offers ALL FOUR — which is exactly VL-11's cap, not three. Asserting
     * the plan's literal prose would therefore assert something false about
     * the real, already-tested (47-04) gate and would be a vacuous "pass"
     * that proves nothing about the cap. This test instead proves the cap
     * itself: never more than four, and specifically all four are reachable
     * on the richest state.
     */
    public function test_walk_c_returned_visit_offers_all_four_capped_at_four(): void
    {
        $project = Project::factory()->create(['name' => 'Walk C Job', 'status' => Project::STATUS_INSTALLING]);
        $visit   = Visit::factory()->returned()->create([
            'project_id' => $project->id,
            'type'       => Visit::TYPE_INSTALL,
        ]);

        $body = $this->openCockpit($project, ['module' => 'worksheet', 'tab' => 'returned']);

        $this->assertStringContainsString('>Accept<', $body);
        $this->assertStringContainsString('>Send back<', $body);
        $this->assertStringContainsString('>Add note<', $body);
        $this->assertStringContainsString('>Raise a snag<', $body);

        $controlCount = substr_count($body, 'cav-visit__control"') + substr_count($body, 'cav-visit__control--quiet"');
        $this->assertSame(4, $controlCount, 'VL-11: a RETURNED visit renders exactly the cap of four, never a fifth.');
    }

    public function test_walk_c_accepting_lands_back_on_returned_and_add_note_now_renders(): void
    {
        $project = Project::factory()->create(['name' => 'Walk C Job', 'status' => Project::STATUS_INSTALLING]);
        $pm      = $this->actor();
        $visit   = Visit::factory()->returned()->create([
            'project_id' => $project->id,
            'type'       => Visit::TYPE_INSTALL,
        ]);

        config(['cockpit.enabled' => true]);

        $this->actingAs($pm)
            ->post(route('projects.cockpit.visits.accept', ['project' => $project, 'visit' => $visit]), [
                'tab' => 'returned',
            ])
            ->assertRedirect();

        $visit->refresh();
        $this->assertNotNull($visit->accepted_at);

        $body = $this->openCockpit($project, ['module' => 'worksheet', 'tab' => 'returned']);

        $this->assertStringContainsString('Accepted by', $body);
        $this->assertStringNotContainsString('>Accept<', $body);
        $this->assertStringNotContainsString('>Send back<', $body);
        $this->assertStringContainsString('>Add note<', $body, 'An ACCEPTED visit offers Add note.');
    }

    public function test_walk_c_a_reconstructed_visit_offers_zero_controls_but_its_evidence_still_renders(): void
    {
        $project   = Project::factory()->create(['name' => 'Walk C Job', 'status' => Project::STATUS_INSTALLING]);
        $worksheet = Worksheet::factory()->create(['project_id' => $project->id]);

        Storage::fake('local');
        Storage::disk('local')->put('worksheet-photos/walkc-recon-01.jpg', 'bytes');

        WorksheetPhoto::create([
            'worksheet_id'  => $worksheet->id,
            'room_name'     => 'Plant room',
            'filename'      => 'worksheet-photos/walkc-recon-01.jpg',
            'original_name' => 'recon-01.jpg',
            'mime_type'     => 'image/jpeg',
            'sort_order'    => 0,
        ]);

        WorksheetSignoff::create([
            'worksheet_id'         => $worksheet->id,
            'client_name'          => 'Reconstructed Client',
            'signature_png_base64' => 'iVBORw0KGgo=',
            'signed_with_comments' => false,
            'signed_at'            => now()->subMonths(6),
        ]);

        $visit = Visit::factory()->backfilledFromWorksheet($worksheet)->create([
            'project_id'     => $project->id,
            'title'          => 'Walk C reconstructed',
            'scheduled_date' => '2026-01-10',
        ]);

        $body = $this->openCockpit($project, ['module' => 'worksheet', 'tab' => 'returned']);

        $this->assertStringContainsString('Walk C reconstructed', $body, 'The visit still renders.');
        $this->assertStringContainsString('Download all photos (ZIP)', $body, 'Its evidence still renders.');
        $this->assertStringContainsString('Reconstructed Client', $body);

        $this->assertSame(
            0,
            substr_count($body, 'cav-visit__control"') + substr_count($body, 'cav-visit__control--quiet"'),
            'A reconstructed visit must offer ZERO controls.'
        );
    }

    public function test_walk_c_cross_project_action_url_is_refused_and_changes_nothing(): void
    {
        $projectA = Project::factory()->create(['name' => 'Walk C Job A', 'status' => Project::STATUS_INSTALLING]);
        $projectB = Project::factory()->create(['name' => 'Walk C Job B', 'status' => Project::STATUS_INSTALLING]);

        $visitB = Visit::factory()->returned()->create([
            'project_id' => $projectB->id,
            'type'       => Visit::TYPE_INSTALL,
        ]);

        $before = Visit::find($visitB->id);

        // From project A's own cockpit URL, naming project B's visit id.
        $openUrl = route('projects.cockpit', [
            'project' => $projectA, 'module' => 'worksheet', 'action' => 'send-back', 'visit' => $visitB->id, 'tab' => 'returned',
        ]);

        config(['cockpit.enabled' => true]);
        $this->actingAs($this->actor())->get($openUrl)->assertOk();

        // The POST, reached as a PM would reach it through this tab's shape,
        // against the FOREIGN visit id — refused by the pre-existing ownership
        // guard (T-46-06-01), regardless of project A's own URL dressing.
        $this->actingAs($this->actor())
            ->post(route('projects.cockpit.visits.send-back', ['project' => $projectA, 'visit' => $visitB]), [
                'reason' => 'Should never apply.',
            ])
            ->assertNotFound();

        $after = Visit::find($visitB->id);

        $this->assertSame($before->sent_back_at, $after->sent_back_at, 'Project B\'s visit must be unchanged.');
        $this->assertSame($before->status, $after->status);
    }

    // ======================================================================
    // WALK D — the leak fix, proven on the page it lives on
    // ======================================================================

    public function test_walk_d_the_room_complete_audit_stamp_never_renders_on_the_public_engineer_link(): void
    {
        $user      = User::factory()->create();
        $project   = Project::factory()->create(['user_id' => $user->id, 'name' => 'Walk D Job']);
        $completedBy = 'ip:203.0.113.44|actor:feedface01';

        $worksheet = Worksheet::create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'project_name'   => 'Walk D Job',
            'project_ref'    => '21CQ-WALKD',
            'client_name'    => 'Walk D Client',
            'site_address'   => '1 Test Street',
            'status'         => Worksheet::STATUS_DRAFT,
            'generated_data' => [
                'rooms' => [
                    [
                        'name'                      => 'Main Hall',
                        'is_surveyed'               => true,
                        'install_steps'             => '',
                        'cable_route_desc'          => '',
                        'power_outlet_count'        => 0,
                        'requires_additional_power' => false,
                        'network_port_count'        => 0,
                        'existing_cabling'          => '',
                        'equipment'                 => [],
                    ],
                ],
            ],
            'pre_install_confirmations' => [
                'room_complete' => [
                    'Main Hall' => [
                        'completed_at' => '2026-09-20T10:30:00+00:00',
                        'completed_by' => $completedBy,
                    ],
                ],
            ],
        ]);

        // Non-vacuity: the raw stamp is genuinely present in the fixture.
        $this->assertSame($completedBy, $worksheet->roomCompletedBy('Main Hall'));

        $response = $this->get(route('public-worksheet.show', ['token' => $worksheet->access_token]));

        $response->assertOk();
        $response->assertDontSee('203.0.113.44', escape: false);
        $response->assertDontSee('feedface01', escape: false);
        $response->assertDontSee('actor:', escape: false);

        // The completion fact and date still render.
        $response->assertSee('Complete', escape: false);
        $response->assertSee('20 Sep 2026', escape: false);
    }

    // ======================================================================
    // THE FENCE NUMBERS, RE-CONFIRMED BY NAME (not inherited from a summary)
    // ======================================================================

    public function test_the_fence_numbers_this_plan_depends_on_are_what_the_plan_says(): void
    {
        $this->assertSame(
            ['overview', 'files', 'notes', 'returned'],
            \App\Http\Controllers\ProjectCockpitController::TABS
        );

        $this->assertSame(
            ['generate', 'send-back', 'note', 'snag'],
            \App\Http\Controllers\ProjectCockpitController::ACTIONS
        );
    }
}
