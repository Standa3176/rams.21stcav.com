<?php

namespace Tests\Feature\Surveys;

use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\SiteSurvey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 261008-gxt Task 2 — a room with no office-authored `works_summary` now
 * renders derived bullets (from whatever planned-works prose is available)
 * instead of falling through to the single-paragraph "Planned AV works"
 * panel. A sibling room WITH office bullets stays byte-for-byte unchanged
 * (the non-vacuity guard — proving the office-wins case was not disturbed).
 */
class SurveyPlannedActionsBulletsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: SiteSurvey, 1: array<string,mixed>}  [survey, decoded rooms JSON]
     */
    private function surveyWithPackage(array $roomOverviews): array
    {
        $user    = User::factory()->create();
        $token   = (string) Str::uuid();
        $project = Project::factory()->create([
            'name'         => 'Bullets Job',
            'site_address' => '2 Test Street',
        ]);

        ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $user->id,
            'quote_filename' => 'quote.pdf',
            'status'         => ProjectPackage::STATUS_EXTRACTED,
            'extracted_data' => [
                'room_overviews' => $roomOverviews,
            ],
        ]);

        $survey = SiteSurvey::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => 'Bullets Job',
            'site_address' => '2 Test Street',
            'status'       => 'draft',
        ]);
        $survey->forceFill(['access_token' => $token])->save();

        foreach (array_values($roomOverviews) as $i => $ro) {
            $survey->rooms()->create([
                'room_name'  => $ro['room'],
                'space_type' => 'general',
                'sort_order' => $i,
            ]);
        }

        $html = $this->get(route('survey.show', ['token' => $token]))
            ->assertOk()
            ->getContent();

        $rooms = $this->extractRoomsJson($html);
        $this->assertIsArray($rooms, 'rooms JSON blob did not decode.');

        return [$survey->fresh(), $rooms];
    }

    // ── Test A: no office works_summary, prose exists → derived bullets ────

    public function test_room_with_no_office_bullets_but_prose_renders_derived_bullets(): void
    {
        [, $rooms] = $this->surveyWithPackage([
            [
                'room'        => 'Reception',
                'description' => 'Install a 55 inch display on the north wall. Run HDMI and power to the rack. Mount a ceiling speaker above the desk.',
                // No works_summary — this is the case under test.
            ],
        ]);

        $ctx = $rooms[0]['_ctx'];

        $this->assertSame('derived', $ctx['planned_actions_source']);
        $this->assertNotEmpty($ctx['planned_actions'], 'Expected derived bullets, got none.');
        $this->assertGreaterThanOrEqual(2, count($ctx['planned_actions']));
    }

    // ── Test B (non-vacuity): a sibling room WITH office bullets is unchanged ──

    public function test_room_with_office_works_summary_is_byte_for_byte_unchanged(): void
    {
        $officeBullets = "Install 55in display\nRun HDMI and power to rack\nMount ceiling speaker";

        [, $rooms] = $this->surveyWithPackage([
            [
                'room'          => 'Reception',
                'description'   => 'Install a 55 inch display on the north wall. Run HDMI and power to the rack.',
                'works_summary' => $officeBullets,
            ],
            [
                'room'        => 'Boardroom',
                'description' => 'Fit a video bar and two displays. Cable to the credenza.',
                // No works_summary for this sibling room — proves Test A's
                // path and this office-wins path coexist in the same survey.
            ],
        ]);

        $office = $rooms[0]['_ctx'];
        $this->assertSame('office', $office['planned_actions_source']);
        $this->assertSame(
            ['Install 55in display', 'Run HDMI and power to rack', 'Mount ceiling speaker'],
            $office['planned_actions'],
            'Office-authored bullets must be byte-for-byte unchanged (today\'s newline-split behaviour).'
        );

        $derived = $rooms[1]['_ctx'];
        $this->assertSame('derived', $derived['planned_actions_source']);
        $this->assertNotEmpty($derived['planned_actions']);
    }

    // ── Rendered-page check: the heading qualifier only shows for derived ──

    public function test_the_rendered_page_carries_the_derived_qualifier_markup(): void
    {
        $html = $this->get(
            route('survey.show', ['token' => $this->surveyToken([
                ['room' => 'Reception', 'description' => 'Install a 55 inch display on the north wall. Run HDMI and power to the rack.'],
            ])])
        )->assertOk()->getContent();

        $this->assertStringContainsString("Install actions (from scope notes)", $html);
    }

    /**
     * Extract the `rooms: [...]` JSON array from the inline Alpine init
     * script by balanced-bracket scanning — a regex with a lazy `]` match
     * stops at the FIRST `]` it meets (e.g. an empty `photos: []` inside the
     * first room), so this walks the string counting bracket depth instead.
     *
     * @return array<int, array<string,mixed>>|null
     */
    private function extractRoomsJson(string $html): ?array
    {
        $needle    = 'rooms: ';
        $offset    = 0;
        $jsonStart = null;
        while (($start = strpos($html, $needle, $offset)) !== false) {
            $candidate = $start + strlen($needle);
            if (($html[$candidate] ?? '') === '[') {
                $jsonStart = $candidate;
                break;
            }
            $offset = $start + strlen($needle);
        }
        if ($jsonStart === null) {
            return null;
        }

        $depth = 0;
        $len   = strlen($html);
        for ($i = $jsonStart; $i < $len; $i++) {
            if ($html[$i] === '[') {
                $depth++;
            } elseif ($html[$i] === ']') {
                $depth--;
                if ($depth === 0) {
                    $json = substr($html, $jsonStart, $i - $jsonStart + 1);

                    return json_decode($json, true);
                }
            }
        }

        return null;
    }

    private function surveyToken(array $roomOverviews): string
    {
        [$survey] = $this->surveyWithPackage($roomOverviews);

        return $survey->access_token;
    }
}
