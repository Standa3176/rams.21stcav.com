<?php

namespace Tests\Unit\Services\Survey;

use App\Models\SiteSurvey;
use App\Models\SiteSurveyPhoto;
use App\Models\SiteSurveyRoom;
use App\Models\SiteSurveyRoomQuestion;
use App\Models\User;
use App\Services\Survey\SiteSurveyTierOneReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SiteSurveyTierOneReadinessServiceTest extends TestCase
{
    use RefreshDatabase;

    private SiteSurveyTierOneReadinessService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new SiteSurveyTierOneReadinessService();
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function makeSurvey(): SiteSurvey
    {
        $user = User::factory()->create();
        return SiteSurvey::create([
            'user_id'      => $user->id,
            'project_name' => 'Tier1 Test Project',
            'status'       => 'draft',
            'access_token' => (string) Str::uuid(),
        ]);
    }

    /** Build a room with all Tier-1 fields filled in. */
    private function makeFullyReadyRoom(SiteSurvey $survey): SiteSurveyRoom
    {
        $room = $survey->rooms()->create([
            'room_name'               => 'Boardroom',
            'sort_order'              => 0,
            'room_width_m'            => 6.00,
            'room_depth_m'            => 4.00,
            'room_height_m'           => 3.00,
            'av_requirements'         => 'Samsung 75" display + Logitech Rally Bar',
            'has_power'               => true,
            'power_outlet_count'      => 4,
            'has_network'             => true,
            'network_port_count'      => 2,
            'engineer_confirmed'      => true,
            'engineer_signature_name' => 'Jane Engineer',
        ]);

        SiteSurveyPhoto::create([
            'site_survey_room_id' => $room->id,
            'filename'            => 'survey-photos/test.jpg',
            'original_name'       => 'test.jpg',
            'mime_type'           => 'image/jpeg',
            'sort_order'          => 0,
        ]);

        return $room->fresh(['photos', 'questions']);
    }

    // ─── Tests ───────────────────────────────────────────────────────────────

    public function test_fully_ready_room_returns_ready_true_and_percent_100(): void
    {
        $survey = $this->makeSurvey();
        $room   = $this->makeFullyReadyRoom($survey);

        $a = $this->svc->assessRoom($room);

        $this->assertTrue($a['ready']);
        $this->assertSame(100, $a['percent']);
        $this->assertSame([], $a['missing']);
        $this->assertSame($a['required_total'], $a['completed_required']);
        $this->assertSame(0, $a['total_checks']);
        $this->assertSame(0, $a['answered_checks']);
    }

    public function test_empty_room_reports_content_fields_as_missing(): void
    {
        // The `has_power` and `has_network` columns default to `false` at the
        // schema level (see create_site_surveys_table migration), so a freshly-
        // created room registers as having ANSWERED those questions. That is
        // the correct Tier-1 semantics: the engineer is expected to explicitly
        // toggle to true when services are present. Therefore the "empty room"
        // misses are the content-level fields only.
        $survey = $this->makeSurvey();
        $room = $survey->rooms()->create([
            'room_name'  => 'Empty Room',
            'sort_order' => 1,
        ]);
        $room = $room->fresh(['photos', 'questions']);

        $a = $this->svc->assessRoom($room);

        $this->assertFalse($a['ready']);
        $this->assertContains(SiteSurveyTierOneReadinessService::KEY_AV_SCOPE,          $a['missing']);
        $this->assertContains(SiteSurveyTierOneReadinessService::KEY_DIMENSIONS,        $a['missing']);
        $this->assertContains(SiteSurveyTierOneReadinessService::KEY_PHOTOS,            $a['missing']);
        $this->assertContains(SiteSurveyTierOneReadinessService::KEY_ENGINEER_SIGN_OFF, $a['missing']);

        // has_power / has_network defaulted to false by the migration → answered.
        $this->assertNotContains(SiteSurveyTierOneReadinessService::KEY_POWER_AVAILABILITY,   $a['missing']);
        $this->assertNotContains(SiteSurveyTierOneReadinessService::KEY_NETWORK_AVAILABILITY, $a['missing']);
        // Their conditional sub-checks stay dormant because the parent is false.
        $this->assertNotContains(SiteSurveyTierOneReadinessService::KEY_POWER_OUTLETS, $a['missing']);
        $this->assertNotContains(SiteSurveyTierOneReadinessService::KEY_NETWORK_PORTS, $a['missing']);
        // Pre-install checks: no questions exist, so the check passes vacuously.
        $this->assertNotContains(SiteSurveyTierOneReadinessService::KEY_PRE_INSTALL_CHECKS, $a['missing']);
    }

    public function test_has_power_true_but_zero_outlets_flags_power_outlets(): void
    {
        $survey = $this->makeSurvey();
        $room = $survey->rooms()->create([
            'room_name'          => 'No Outlets',
            'sort_order'         => 2,
            'has_power'          => true,
            'power_outlet_count' => 0,
        ]);
        $room = $room->fresh(['photos', 'questions']);

        $a = $this->svc->assessRoom($room);

        $this->assertContains(SiteSurveyTierOneReadinessService::KEY_POWER_OUTLETS, $a['missing']);
        // Not a parent-level miss — has_power was answered.
        $this->assertNotContains(SiteSurveyTierOneReadinessService::KEY_POWER_AVAILABILITY, $a['missing']);
    }

    public function test_has_network_false_does_not_require_ports(): void
    {
        $survey = $this->makeSurvey();
        $room = $survey->rooms()->create([
            'room_name'   => 'No Net',
            'sort_order'  => 3,
            'has_network' => false,
        ]);
        $room = $room->fresh(['photos', 'questions']);

        $a = $this->svc->assessRoom($room);

        // network_ports sub-check only fires when has_network === true.
        $this->assertNotContains(SiteSurveyTierOneReadinessService::KEY_NETWORK_PORTS, $a['missing']);
    }

    public function test_zero_questions_does_not_fail_pre_install_check(): void
    {
        $survey = $this->makeSurvey();
        $room   = $this->makeFullyReadyRoom($survey);

        $a = $this->svc->assessRoom($room);

        $this->assertSame(0, $a['total_checks']);
        $this->assertTrue($a['ready']);
        $this->assertNotContains(SiteSurveyTierOneReadinessService::KEY_PRE_INSTALL_CHECKS, $a['missing']);
    }

    public function test_partial_question_answers_trigger_pre_install_missing(): void
    {
        $survey = $this->makeSurvey();
        $room   = $this->makeFullyReadyRoom($survey);

        SiteSurveyRoomQuestion::create([
            'site_survey_room_id' => $room->id,
            'question'            => 'Is the ceiling accessible?',
            'sort_order'          => 1,
            'answer'              => 'yes',
        ]);
        SiteSurveyRoomQuestion::create([
            'site_survey_room_id' => $room->id,
            'question'            => 'Is the wall substrate confirmed?',
            'sort_order'          => 2,
            'answer'              => null,
        ]);

        $fresh = $room->fresh(['photos', 'questions']);
        $a = $this->svc->assessRoom($fresh);

        $this->assertContains(SiteSurveyTierOneReadinessService::KEY_PRE_INSTALL_CHECKS, $a['missing']);
        $this->assertSame(2, $a['total_checks']);
        $this->assertSame(1, $a['answered_checks']);
        $this->assertFalse($a['ready']);
    }

    public function test_engineer_confirmed_without_signature_name_still_missing(): void
    {
        $survey = $this->makeSurvey();
        $room   = $this->makeFullyReadyRoom($survey);
        $room->update(['engineer_confirmed' => true, 'engineer_signature_name' => '']);
        $fresh = $room->fresh(['photos', 'questions']);

        $a = $this->svc->assessRoom($fresh);

        $this->assertContains(SiteSurveyTierOneReadinessService::KEY_ENGINEER_SIGN_OFF, $a['missing']);
        $this->assertFalse($a['ready']);
    }

    public function test_percent_is_floor_of_ratio(): void
    {
        // Schema defaults has_power + has_network to false (counts as answered)
        // and zero-question pre-install check passes vacuously. With av scope
        // filled: 4 of 7 required pass → percent = floor(4/7 * 100) = 57.
        $survey = $this->makeSurvey();
        $room = $survey->rooms()->create([
            'room_name'       => 'Only AV scope filled',
            'sort_order'      => 4,
            'av_requirements' => 'Single display',
        ]);
        $room = $room->fresh(['photos', 'questions']);

        $a = $this->svc->assessRoom($room);

        $this->assertSame(7, $a['required_total']);
        $this->assertSame(4, $a['completed_required']);
        $this->assertSame(57, $a['percent']);
    }

    public function test_assess_survey_rolls_up_totals_correctly(): void
    {
        $survey = $this->makeSurvey();
        $roomA  = $this->makeFullyReadyRoom($survey);         // ready
        $roomB  = $survey->rooms()->create([
            'room_name'  => 'Partial',
            'sort_order' => 5,
            'av_requirements' => 'x',
        ])->fresh(['photos', 'questions']);                    // not ready
        $survey->load('rooms.photos', 'rooms.questions');

        $s = $this->svc->assessSurvey($survey);

        $this->assertSame(2, $s['summary']['total_rooms']);
        $this->assertSame(1, $s['summary']['ready_rooms']);
        $this->assertGreaterThan(0, $s['summary']['overall_percent']);
        $this->assertLessThan(100,   $s['summary']['overall_percent']);
        $this->assertGreaterThan(0,  $s['summary']['missing_items_total']);

        // Rooms dict is keyed by id.
        $this->assertArrayHasKey($roomA->id, $s['rooms']);
        $this->assertArrayHasKey($roomB->id, $s['rooms']);
        $this->assertTrue($s['rooms'][$roomA->id]['ready']);
        $this->assertFalse($s['rooms'][$roomB->id]['ready']);
    }

    public function test_empty_survey_produces_zero_totals_and_zero_percent(): void
    {
        $survey = $this->makeSurvey();
        $survey->load('rooms.photos', 'rooms.questions');

        $s = $this->svc->assessSurvey($survey);

        $this->assertSame(0, $s['summary']['total_rooms']);
        $this->assertSame(0, $s['summary']['ready_rooms']);
        $this->assertSame(0, $s['summary']['overall_percent']);
        $this->assertSame(0, $s['summary']['missing_items_total']);
        $this->assertSame([], $s['rooms']);
    }
}
