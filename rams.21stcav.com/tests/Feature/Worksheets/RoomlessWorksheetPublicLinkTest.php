<?php

namespace Tests\Feature\Worksheets;

use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\SiteSurveyRoom;
use App\Models\User;
use App\Models\Worksheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Defect D-46-05-01 — GET /worksheet/{token} 500s for a worksheet with no rooms.
 *
 * public-show.blade.php assigns BOTH `$signOffBlocked` and `$unreviewedRooms`
 * inside the `@else` arm of `@if(empty($rooms))`, but reads them AFTER that
 * `@endif` in the Client Sign-Off card (and in the top banner). With no rooms,
 * neither is ever defined → "Undefined variable" → 500 on an engineer's live
 * public link.
 *
 * REACHABILITY (this is not a theoretical state):
 *   1. `WorksheetController::generateFromProject` creates the row with
 *      `generated_data` NULL, and `Worksheet::boot::creating` mints the
 *      `access_token` at the same instant — so the public link is live and
 *      500ing for the whole window before BuildWorksheetJob finishes.
 *   2. `BuildWorksheetJob` THROWS when `roomsCount === 0` (or no substantive
 *      content), leaving `generated_data` NULL permanently → permanent 500.
 *   3. `WorksheetEditAdapter::applyRemoveRoom` has no last-room guard, so
 *      removing the final room writes `rooms: []` → permanent 500.
 *
 * `resolveWorksheet()` gates on token + expiry only — never on status or on
 * whether content exists — so all three states are served by this route.
 *
 * @see resources/views/worksheets/public-show.blade.php
 * @see App\Http\Controllers\PublicWorksheetController::show
 */
class RoomlessWorksheetPublicLinkTest extends TestCase
{
    use RefreshDatabase;

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function project(): Project
    {
        $user = User::factory()->create();

        return Project::factory()->create(['user_id' => $user->id]);
    }

    /**
     * @param  array<string,mixed>|null  $generatedData
     */
    private function worksheet(?array $generatedData, ?Project $project = null): Worksheet
    {
        $project ??= $this->project();

        // Worksheet::create (not ->update) so boot::creating mints access_token
        // by direct assignment — `access_token` is DELIBERATELY absent from
        // $fillable (security re-audit S-03) and must never be mass-assigned.
        return Worksheet::create([
            'user_id'        => $project->user_id,
            'project_id'     => $project->id,
            'project_name'   => 'Roomless Fixture',
            'project_ref'    => '21CQ00000-01-OPS',
            'client_name'    => 'Fixture Client',
            'site_address'   => '1 Fixture Way, Reading RG1 1AA',
            'status'         => Worksheet::STATUS_GENERATING,
            'generated_data' => $generatedData,
        ]);
    }

    private function get_link(Worksheet $worksheet)
    {
        return $this->get(route('public-worksheet.show', ['token' => $worksheet->access_token]));
    }

    // ── The defect ───────────────────────────────────────────────────────────

    /**
     * State 1 + 2 — generated_data NULL (queued, or generation failed).
     */
    public function test_public_link_renders_for_a_worksheet_with_null_generated_data(): void
    {
        $response = $this->get_link($this->worksheet(null));

        $response->assertOk();
        $this->assertGuest();
        $response->assertSee('No room data is available yet');
    }

    /**
     * State 3 — rooms[] emptied by a remove_room edit.
     */
    public function test_public_link_renders_for_a_worksheet_with_an_empty_rooms_array(): void
    {
        $response = $this->get_link($this->worksheet(['rooms' => []]));

        $response->assertOk();
        $response->assertSee('No room data is available yet');
    }

    /**
     * The decision, pinned. A roomless worksheet has zero unreviewed rooms, so
     * the survey-review gate is NOT engaged: `data-signoff-blocked="0"` and no
     * warning banner. See the commit message for the reasoning — this gate is
     * cosmetic (the server accepts the POST regardless), so blocking here would
     * only render an unclearable banner naming no rooms.
     */
    public function test_roomless_worksheet_does_not_engage_the_survey_review_gate(): void
    {
        $response = $this->get_link($this->worksheet(['rooms' => []]));

        $response->assertOk();
        $response->assertSee('Client Sign-Off');
        $response->assertSee('data-signoff-blocked="0"', false);
        $response->assertDontSee('Sign-off blocked');
        $response->assertDontSee('Review the survey reference for these rooms');
    }

    // ── Canary: the real gate still fires when there ARE rooms ───────────────

    /**
     * Regression guard on the fix itself. A worksheet WITH a room that has
     * survey data and has not been marked reviewed must still be blocked —
     * defaulting the variables must not disarm the live gate.
     */
    public function test_a_room_with_an_unreviewed_survey_is_still_blocked(): void
    {
        $project = $this->project();

        $survey = SiteSurvey::create([
            'user_id'      => $project->user_id,
            'project_id'   => $project->id,
            'project_name' => 'Roomless Fixture',
            'client_name'  => 'Fixture Client',
            'status'       => 'completed',
        ]);
        SiteSurveyRoom::create([
            'site_survey_id'   => $survey->id,
            'room_name'        => 'Main Hall',
            'mounting_heights' => [['item' => 'Display', 'height_m' => '1.2']],
        ]);

        $worksheet = $this->worksheet([
            'rooms' => [[
                'name'        => 'Main Hall',
                'is_surveyed' => true,
                'equipment'   => [],
            ]],
        ], $project);

        $response = $this->get_link($worksheet);

        $response->assertOk();
        $response->assertSee('data-signoff-blocked="1"', false);
        $response->assertSee('Sign-off blocked');
    }
}
