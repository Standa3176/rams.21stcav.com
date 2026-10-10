<?php

namespace Tests\Feature\SiteSurvey;

use App\Models\SiteSurvey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quick task 261010-cdf, Part 3 — proves the office survey review page
 * (site-surveys.show -> resources/views/site-survey/show.blade.php) flags:
 *   - any room recorded above 2m (2_to_4m, over_4m, and the legacy over_2m),
 *   - any room where working height was never recorded, BUT ONLY when the
 *     omission is meaningful (the room has other captured data, so the
 *     surveyor was plainly there and skipped this field) — per the
 *     2026-10-10 plan-check decision (a), gated on the SAME $hasData signal
 *     the room header already computes for its own badge/border.
 * ... and that under_2m and genuinely-unstarted rooms trigger NEITHER flag.
 *
 * $wizard = $survey->survey_data['rooms'][$loop->index] aligns by array
 * order with the SiteSurveyRoom rows persisted via $survey->rooms()->create()
 * (mirrors SurveyPdfModesTest::makeSurveyWithFullEngineerData()'s approach).
 *
 * @see resources/views/site-survey/show.blade.php:618-706 (per-room header loop)
 */
class SiteSurveyShowWorkingHeightFlagTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{user:User, survey:SiteSurvey} */
    private function makeSingleRoomSurvey(string $roomName, array $survey_data_room, array $roomAttrs = []): array
    {
        $user = User::factory()->create();

        $survey = SiteSurvey::create([
            'user_id'      => $user->id,
            'project_name' => 'Height Flag Test',
            'client_name'  => 'Acme Ltd',
            'status'       => 'draft',
            'survey_data'  => ['rooms' => [$survey_data_room]],
        ]);

        $survey->rooms()->create(array_merge([
            'room_name'  => $roomName,
            'sort_order' => 0,
        ], $roomAttrs));

        return compact('user', 'survey');
    }

    public function test_room_recorded_2_to_4m_shows_the_above_2m_flag(): void
    {
        ['user' => $user, 'survey' => $survey] = $this->makeSingleRoomSurvey(
            'High Room',
            ['risks' => [['working_height' => '2_to_4m']]],
            ['notes' => 'Surveyor was here.'],
        );

        $response = $this->actingAs($user)->get(route('site-surveys.show', $survey));

        $response->assertOk();
        $response->assertSee('Working height above 2m');
    }

    public function test_room_recorded_legacy_over_2m_shows_the_above_2m_flag(): void
    {
        ['user' => $user, 'survey' => $survey] = $this->makeSingleRoomSurvey(
            'Legacy Room',
            ['risks' => [['working_height' => 'over_2m']]],
            ['notes' => 'Surveyor was here.'],
        );

        $response = $this->actingAs($user)->get(route('site-surveys.show', $survey));

        $response->assertOk();
        $response->assertSee('Working height above 2m');
    }

    public function test_started_room_with_working_height_skipped_shows_the_not_recorded_flag(): void
    {
        // "Blank Room" — survey_data.rooms[0] has NO risks key at all, but the
        // room DOES carry other captured data (notes), so $hasData is true:
        // the surveyor was plainly there and skipped this field. This is the
        // case the whole flag exists to catch.
        ['user' => $user, 'survey' => $survey] = $this->makeSingleRoomSurvey(
            'Blank Room',
            [], // no 'risks' key at all
            ['notes' => 'Surveyor was here, just skipped working height.'],
        );

        $response = $this->actingAs($user)->get(route('site-surveys.show', $survey));

        $response->assertOk();
        $response->assertSee('Working height not recorded');
    }

    public function test_genuinely_unstarted_room_shows_neither_flag(): void
    {
        // No risks key AND no other room data at all ($hasData false) —
        // a room nobody has surveyed yet. The "not recorded" flag must NOT
        // fire here: a warning that fires on every un-surveyed room on a
        // half-done multi-room job is one a reviewer learns to scroll past.
        ['user' => $user, 'survey' => $survey] = $this->makeSingleRoomSurvey(
            'Not Started Room',
            [], // no 'risks' key at all
            [], // no av_requirements / notes / room_width_m / ceiling_type
        );

        $response = $this->actingAs($user)->get(route('site-surveys.show', $survey));

        $response->assertOk();
        $response->assertDontSee('Working height not recorded');
        $response->assertDontSee('Working height above 2m');
    }

    public function test_room_recorded_under_2m_shows_neither_flag(): void
    {
        // The recorded-and-low case must trigger neither flag: under_2m is
        // not "above 2m", and it IS recorded so it is not "missing" either.
        ['user' => $user, 'survey' => $survey] = $this->makeSingleRoomSurvey(
            'Low Room',
            ['risks' => [['working_height' => 'under_2m']]],
            ['notes' => 'Surveyor was here.'],
        );

        $response = $this->actingAs($user)->get(route('site-surveys.show', $survey));

        $response->assertOk();
        $response->assertDontSee('Working height above 2m');
        $response->assertDontSee('Working height not recorded');
    }
}
