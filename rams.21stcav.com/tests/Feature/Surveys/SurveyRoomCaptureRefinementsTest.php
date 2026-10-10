<?php

namespace Tests\Feature\Surveys;

use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 46.8 Plan 05 (D-04/D-05/D-06) — the room page:
 *  - D-04: the Voice Note Transcript field is removed, markup AND persistence.
 *  - D-05: Access Issues reveals a NEW notes box next to its own toggle;
 *    Working at Height reveals the EXISTING canonical Working Height
 *    button-group a second time, next to its own toggle — same
 *    `risks[0].working_height` value as the pre-existing control in section 6.
 *    "Site requirements" was explicitly dropped by the user on 2026-10-10 and
 *    is NOT implemented anywhere in this plan.
 *  - D-06: the Cable Routes card gets its own photo-capture affordance,
 *    alongside its existing per-route Notes field, reusing the
 *    `category="cable_routes"` the step-3 Photos gallery already uses.
 *
 * Every assertion below is against REAL rendered output of `GET /survey/{token}`
 * (or a real POST through `survey.step.save` followed by a fresh GET), never
 * against a controller constant alone.
 *
 * NEVER run `artisan migrate:fresh --env=testing` (see gate-46.ps1).
 */
class SurveyRoomCaptureRefinementsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The pre-existing `sv-spine` count for a single-room fresh render,
     * measured directly against this file BEFORE this plan's edits (via a
     * disposable probe test, not re-derived here). Must not move — the spine
     * is structure, not status, and this plan adds no `sv-spine` class
     * anywhere.
     */
    private const PRE_EXISTING_SV_SPINE_COUNT = 15;

    private function surveyWithOneRoom(): array
    {
        $user    = User::factory()->create();
        $token   = (string) Str::uuid();
        $project = Project::factory()->create([
            'name'         => 'Capture Refinements Fixture',
            'site_address' => '1 Example Way, London',
        ]);

        $survey = SiteSurvey::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => 'Capture Refinements Fixture',
            'site_address' => '1 Example Way, London',
            'status'       => 'draft',
        ]);
        $survey->forceFill(['access_token' => $token])->save();
        $survey->rooms()->create(['room_name' => 'Room A', 'space_type' => 'general', 'sort_order' => 0]);

        return [$survey, $token];
    }

    private function render(string $token): string
    {
        return $this->get(route('survey.show', ['token' => $token]))
            ->assertOk()
            ->getContent();
    }

    /**
     * Merges `$uiState` into room 0's `ui_state`, directly on the stored
     * canonical payload — bypassing the controller's own write path, exactly
     * as stale pre-this-plan data (e.g. a saved voice_note) would already
     * exist on disk for an old survey. Requires one prior GET so
     * `initialPayload()` has already materialised the canonical shape.
     */
    private function seedRoomUiState(SiteSurvey $survey, array $uiState): void
    {
        $survey->refresh();
        $payload = $survey->survey_data;
        $payload['rooms'][0]['ui_state'] = array_merge($payload['rooms'][0]['ui_state'] ?? [], $uiState);
        $survey->update(['survey_data' => $payload]);
    }

    // ── D-04: VOICE NOTE IS GONE, MARKUP AND PERSISTENCE ────────────────────

    public function test_voice_note_transcript_is_absent_from_a_fresh_room(): void
    {
        [, $token] = $this->surveyWithOneRoom();

        $html = $this->render($token);

        $this->assertStringNotContainsString('Voice Note Transcript', $html);
        $this->assertStringNotContainsString('_ui.voice_note', $html);
    }

    /**
     * Proves removal is real even when a room ALREADY has a voice note
     * saved from before this plan — not merely "nothing captures one now."
     */
    public function test_a_stale_pre_existing_voice_note_does_not_leak_into_the_rendered_page(): void
    {
        [$survey, $token] = $this->surveyWithOneRoom();
        $this->render($token); // materialise initialPayload() first

        $this->seedRoomUiState($survey, ['voice_note' => 'STALE-VOICE-NOTE-FROM-BEFORE-REMOVAL']);

        $html = $this->render($token);

        $this->assertStringNotContainsString('STALE-VOICE-NOTE-FROM-BEFORE-REMOVAL', $html);
        $this->assertStringNotContainsString('Voice Note Transcript', $html);
    }

    // ── D-05a: ACCESS ISSUES REVEAL ──────────────────────────────────────────

    public function test_access_issues_notes_box_markup_exists_bound_to_the_toggle(): void
    {
        [, $token] = $this->surveyWithOneRoom();

        $html = $this->render($token);

        // The reveal div is always in the DOM (Alpine x-show, not a server
        // x-if), so assert the exact x-show expression string rather than a
        // resolved boolean this feature test cannot evaluate.
        $this->assertStringContainsString(
            'x-show="rooms[currentRoomIdx]?._ui?.access_issues"',
            $html
        );
        $this->assertStringContainsString(
            'x-model="rooms[currentRoomIdx]._ui.access_issues_notes"',
            $html
        );

        // Positioned after the Access Issues toggle, before Working at
        // Height's own toggle — i.e. next to its trigger, not lower down.
        // The toggle-field component injects the field name into its Alpine
        // @click/:class attributes (`['access_issues']`), not as a literal
        // `field="..."` HTML attribute — that PHP prop name is consumed
        // server-side and never echoed verbatim.
        $accessIssuesTogglePos = strpos($html, "['access_issues']");
        $notesBoxPos           = strpos($html, 'access_issues_notes');
        $workingAtHeightTogglePos = strpos($html, "['working_at_height']");

        $this->assertNotFalse($accessIssuesTogglePos);
        $this->assertNotFalse($notesBoxPos);
        $this->assertNotFalse($workingAtHeightTogglePos);
        $this->assertGreaterThan($accessIssuesTogglePos, $notesBoxPos, 'Notes box must come after the Access Issues toggle.');
        $this->assertLessThan($workingAtHeightTogglePos, $notesBoxPos, 'Notes box must come before the Working at Height toggle.');
    }

    public function test_access_issues_notes_renders_a_seeded_value_when_the_toggle_is_on(): void
    {
        [$survey, $token] = $this->surveyWithOneRoom();
        $this->render($token);

        $this->seedRoomUiState($survey, [
            'access_issues'       => true,
            'access_issues_notes' => 'SENTINEL-ACCESS-ISSUE-DETAIL',
        ]);

        $html = $this->render($token);

        $this->assertStringContainsString('SENTINEL-ACCESS-ISSUE-DETAIL', $html);
    }

    /**
     * THE TRAP THE NON_VACUITY SECTION NAMES EXPLICITLY: a stale note must
     * not survive a toggle-off save, even if the client still sends a
     * non-empty value for it in the SAME request — proven via a real POST
     * to `survey.step.save`, not a unit assertion on a private method.
     */
    public function test_toggling_access_issues_off_and_saving_clears_the_note_server_side_even_if_client_resends_it(): void
    {
        [$survey, $token] = $this->surveyWithOneRoom();
        $this->render($token);
        $this->seedRoomUiState($survey, [
            'access_issues'       => true,
            'access_issues_notes' => 'SHOULD-BE-CLEARED',
        ]);

        $this->post(route('survey.step.save', ['token' => $token]), [
            'room_index' => 0,
            'step'       => 2,
            'data'       => [
                'access_issues'       => false,
                // Client still sends a non-empty value — server must not trust it.
                'access_issues_notes' => 'ATTEMPTED-SURVIVAL-UNDER-FALSE-TOGGLE',
            ],
        ])->assertOk();

        $html = $this->render($token);

        $this->assertStringNotContainsString('ATTEMPTED-SURVIVAL-UNDER-FALSE-TOGGLE', $html);
        $this->assertStringNotContainsString('SHOULD-BE-CLEARED', $html);
    }

    // ── D-05b: WORKING AT HEIGHT REVEAL (DUPLICATE OF THE CANONICAL CONTROL) ─

    public function test_working_height_control_renders_twice_bound_to_the_same_canonical_field(): void
    {
        [$survey, $token] = $this->surveyWithOneRoom();
        $this->render($token);
        $this->seedRoomUiState($survey, ['working_at_height' => true]);

        $html = $this->render($token);

        $this->assertSame(
            2,
            substr_count($html, '>Working Height<'),
            'The Working Height card must render twice: once next to the toggle (new), once in its pre-existing section 6 position.'
        );

        // Both copies bind to the exact same canonical path — not a second
        // variable, not a UI-only mirror.
        $this->assertSame(
            6, // 3 value-buttons × 2 renders
            substr_count($html, "rooms[currentRoomIdx].risks[0].working_height = '"),
            'Both Working Height controls must write the same risks[0].working_height path.'
        );
        $this->assertSame(
            6,
            substr_count($html, "rooms[currentRoomIdx].risks[0]?.working_height === '"),
            'Both Working Height controls must read the same risks[0].working_height path.'
        );
    }

    public function test_working_height_selection_made_via_section_6_also_shows_selected_in_the_new_section_2_reveal(): void
    {
        [$survey, $token] = $this->surveyWithOneRoom();
        $this->render($token);
        $this->seedRoomUiState($survey, ['working_at_height' => true]);
        $survey->refresh();
        $payload = $survey->survey_data;
        $payload['rooms'][0]['risks'][0]['working_height'] = '2_to_4m';
        $survey->update(['survey_data' => $payload]);

        $html = $this->render($token);

        // The seeded Alpine data (@json($rooms)) carries the selected value
        // once per room, consumed identically by both rendered controls.
        $this->assertStringContainsString('"working_height":"2_to_4m"', $html);

        // Working Height's value list is copied VERBATIM — unchanged, both
        // places. The button label text sits on its own line inside the
        // compiled Blade `@foreach`, so match the label text itself rather
        // than an adjacent `>...<` substring, which the real whitespace
        // between the tag and the text would never satisfy.
        $this->assertSame(2, substr_count($html, 'Under 2m'));
        $this->assertSame(2, substr_count($html, '4m+'));
    }

    // ── D-06: CABLE ROUTES PHOTO CAPTURE ─────────────────────────────────────

    public function test_cable_routes_card_has_its_own_photo_capture_alongside_the_step3_gallery(): void
    {
        [, $token] = $this->surveyWithOneRoom();

        $html = $this->render($token);

        $this->assertSame(
            2,
            substr_count($html, 'data-category="cable_routes"'),
            'cable_routes photo-upload must render twice: the pre-existing step-3 gallery instance, and the new one inside the Cable Routes card.'
        );

        // The pre-existing per-route Notes field is untouched by this addition.
        $this->assertStringContainsString('row.notes', $html);
    }

    // ── sv-spine COUNT IS STRUCTURE, NOT MOVED BY THIS PLAN ──────────────────

    public function test_sv_spine_count_is_unchanged_from_before_this_plan(): void
    {
        [, $token] = $this->surveyWithOneRoom();

        $html = $this->render($token);

        $this->assertSame(
            self::PRE_EXISTING_SV_SPINE_COUNT,
            substr_count($html, 'sv-spine'),
            'sv-spine count moved — this plan must not add the class anywhere.'
        );
    }
}
