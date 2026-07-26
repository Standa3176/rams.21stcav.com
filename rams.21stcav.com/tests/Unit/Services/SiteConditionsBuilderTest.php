<?php

namespace Tests\Unit\Services;

use App\Models\SiteSurvey;
use App\Models\SiteSurveyRoom;
use App\Models\User;
use App\Services\SiteConditionsBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Quick task 260726-fx4 Task 5 — SiteConditionsBuilder extracts engineer
 * feedback (mounting heights, wall construction, brackets, cable routes,
 * access notes) from SiteSurveyRoom into a compact per-room map suitable
 * for feeding into AI prompts.
 *
 * Empty / null / default (false) fields must be stripped so the AI model
 * doesn't get polluted with placeholder rows.
 */
class SiteConditionsBuilderTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeSurvey(): SiteSurvey
    {
        $user = User::factory()->create();
        return SiteSurvey::create([
            'user_id'      => $user->id,
            'project_name' => 'Site Conditions Test',
            'status'       => 'draft',
            'access_token' => (string) Str::uuid(),
        ]);
    }

    // ── Null / empty survey → empty map ─────────────────────────────────────

    public function test_null_survey_returns_empty_array(): void
    {
        $this->assertSame([], SiteConditionsBuilder::fromSurvey(null));
    }

    public function test_survey_with_no_rooms_returns_empty_array(): void
    {
        $survey = $this->makeSurvey();
        $this->assertSame([], SiteConditionsBuilder::fromSurvey($survey));
    }

    // ── Fully-populated room returns all engineer_feedback keys ─────────────

    public function test_fully_populated_room_returns_all_meaningful_keys(): void
    {
        $survey = $this->makeSurvey();
        $survey->rooms()->create([
            'room_name'                 => 'Boardroom',
            'sort_order'                => 0,
            'mounting_heights'          => ['display' => 1900, 'occupancy_sensor' => 2800],
            'wall_construction'         => ['type' => 'Plasterboard on metal stud'],
            'wall_needs_reinforcement'  => true,
            'wall_needs_chase_out'      => false,
            'wall_needs_conduit'        => false,
            'brackets_required'         => [['type' => 'Chief tilting wall mount', 'notes' => 'PPP-M2AS']],
            'cable_routes'              => ['floor void → riser → false ceiling'],
            'table_info'                => ['shape' => 'circular', 'grommet' => 'boxed floor grommet'],
            'floor_box_info'            => ['sockets' => '2× 4-way'],
            'access_notes'              => 'ceiling grid 600×600, no asbestos flag',
        ]);

        $out = SiteConditionsBuilder::fromSurvey($survey);

        $this->assertArrayHasKey('Boardroom', $out);
        $c = $out['Boardroom'];

        $this->assertSame(['display' => 1900, 'occupancy_sensor' => 2800], $c['mounting_heights']);
        $this->assertSame(['type' => 'Plasterboard on metal stud'], $c['wall_construction']);
        $this->assertTrue($c['wall_needs_reinforcement']);
        $this->assertArrayNotHasKey('wall_needs_chase_out', $c,
            'false booleans must be omitted so the AI model does not see noise');
        $this->assertArrayNotHasKey('wall_needs_conduit', $c);
        $this->assertSame([['type' => 'Chief tilting wall mount', 'notes' => 'PPP-M2AS']], $c['brackets_required']);
        $this->assertSame(['floor void → riser → false ceiling'], $c['cable_routes']);
        $this->assertSame(['shape' => 'circular', 'grommet' => 'boxed floor grommet'], $c['table_info']);
        $this->assertSame(['sockets' => '2× 4-way'], $c['floor_box_info']);
        $this->assertSame('ceiling grid 600×600, no asbestos flag', $c['access_notes']);
    }

    // ── Empty room contributes no key (rooms map is compact) ────────────────

    public function test_room_with_no_engineer_feedback_is_omitted_entirely(): void
    {
        $survey = $this->makeSurvey();
        $survey->rooms()->create([
            'room_name' => 'Empty Room',
            'sort_order' => 0,
        ]);

        $out = SiteConditionsBuilder::fromSurvey($survey);
        $this->assertArrayNotHasKey('Empty Room', $out);
        $this->assertSame([], $out);
    }

    // ── Partial population: only meaningful keys survive ────────────────────

    public function test_partially_populated_room_returns_only_meaningful_keys(): void
    {
        $survey = $this->makeSurvey();
        $survey->rooms()->create([
            'room_name'         => 'Huddle A',
            'sort_order'        => 0,
            'mounting_heights'  => ['display' => 1900],
            'access_notes'      => 'no special access',
            'wall_needs_conduit' => true,
            // All other engineer_feedback fields left null / default
        ]);

        $out = SiteConditionsBuilder::fromSurvey($survey);
        $this->assertArrayHasKey('Huddle A', $out);
        $c = $out['Huddle A'];

        $this->assertSame(['mounting_heights', 'wall_needs_conduit', 'access_notes'], array_keys($c));
    }

    // ── Multiple rooms keyed by name ────────────────────────────────────────

    public function test_multiple_rooms_produce_keyed_output(): void
    {
        $survey = $this->makeSurvey();
        $survey->rooms()->create([
            'room_name'  => 'Oregano',
            'sort_order' => 0,
            'access_notes' => 'access A',
        ]);
        $survey->rooms()->create([
            'room_name'  => 'Cinnamon',
            'sort_order' => 1,
            'access_notes' => 'access B',
        ]);

        $out = SiteConditionsBuilder::fromSurvey($survey);
        $this->assertSame(['Oregano', 'Cinnamon'], array_keys($out));
        $this->assertSame('access A', $out['Oregano']['access_notes']);
        $this->assertSame('access B', $out['Cinnamon']['access_notes']);
    }
}
