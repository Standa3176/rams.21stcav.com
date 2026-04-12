<?php

namespace Tests\Feature;

use App\Models\SiteSurvey;
use App\Models\SiteSurveyRoom;
use App\Models\SiteSurveyRoomQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Completion gate contract tests for PublicSurveyController::completeRoom() (Plan 06).
 *
 * These tests are RED in Wave 0 — the completion gate logic does not yet exist.
 * Tests will fail (wrong response code/body) until Plan 06 adds the unanswered
 * questions guard to completeRoom().
 *
 * Contract (Plan 06):
 *   POST /survey/{token}/rooms/{room}/complete
 *   BEFORE existing save logic:
 *     $unanswered = $room->questions()->whereNull('answer')->count();
 *     if ($unanswered > 0): return 422 JSON { completed: false, blocked: true, message: "..." }
 *   WHEN all questions answered OR room has no questions: proceed as before (200).
 */
class PublicSurveyControllerTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeSurveyWithRoom(): array
    {
        $user  = User::factory()->create();
        $token = (string) Str::uuid();

        $survey = SiteSurvey::create([
            'user_id'      => $user->id,
            'project_name' => 'Test Project',
            'status'       => 'draft',
            'access_token' => $token,
        ]);

        $room = $survey->rooms()->create([
            'room_name'  => 'Board Room',
            'space_type' => 'general',
            'sort_order' => 0,
        ]);

        return compact('survey', 'room', 'token');
    }

    // ─── Test 1: completeRoom returns 422 when room has unanswered questions ──

    /**
     * If the room has at least one question with answer=null, completeRoom
     * must return 422 instead of 200.
     */
    public function test_complete_room_returns_422_when_room_has_unanswered_questions(): void
    {
        ['survey' => $survey, 'room' => $room, 'token' => $token] = $this->makeSurveyWithRoom();

        // Create an unanswered question.
        SiteSurveyRoomQuestion::create([
            'site_survey_room_id' => $room->id,
            'question'            => 'Is the ceiling accessible?',
            'sort_order'          => 1,
            'answer'              => null,
        ]);

        $response = $this->postJson(
            route('survey.room.complete', ['token' => $token, 'room' => $room->id]),
            ['rooms' => []]
        );

        $response->assertStatus(422);
    }

    // ─── Test 2: 422 response body contains blocked=true and message with 'pre-install' ──

    /**
     * The 422 response body must include blocked=true and a message containing
     * 'pre-install' (matching the gate contract).
     */
    public function test_complete_room_422_body_contains_blocked_true_and_pre_install_message(): void
    {
        ['survey' => $survey, 'room' => $room, 'token' => $token] = $this->makeSurveyWithRoom();

        SiteSurveyRoomQuestion::create([
            'site_survey_room_id' => $room->id,
            'question'            => 'Is there adequate power?',
            'sort_order'          => 1,
            'answer'              => null,
        ]);

        $response = $this->postJson(
            route('survey.room.complete', ['token' => $token, 'room' => $room->id]),
            ['rooms' => []]
        );

        $response->assertStatus(422);

        $body = $response->json();

        $this->assertArrayHasKey('blocked', $body);
        $this->assertTrue($body['blocked']);

        $this->assertArrayHasKey('message', $body);
        $this->assertStringContainsStringIgnoringCase('pre-install', $body['message']);
    }

    // ─── Test 3: completeRoom succeeds (200) when all questions answered ──────

    /**
     * When all questions have a non-null answer, completeRoom must return 200.
     */
    public function test_complete_room_succeeds_when_all_questions_answered(): void
    {
        ['survey' => $survey, 'room' => $room, 'token' => $token] = $this->makeSurveyWithRoom();

        // Create a question that has been answered.
        SiteSurveyRoomQuestion::create([
            'site_survey_room_id' => $room->id,
            'question'            => 'Is the ceiling accessible?',
            'sort_order'          => 1,
            'answer'              => 'yes',
        ]);

        $response = $this->postJson(
            route('survey.room.complete', ['token' => $token, 'room' => $room->id]),
            ['rooms' => []]
        );

        $response->assertStatus(200)
                 ->assertJson(['completed' => true]);
    }

    // ─── Test 4: completeRoom succeeds (200) when room has no questions ───────

    /**
     * When the room has zero questions, completeRoom must return 200
     * (existing behaviour is unaffected by the gate — D-06 constraint).
     */
    public function test_complete_room_succeeds_when_room_has_no_questions(): void
    {
        ['survey' => $survey, 'room' => $room, 'token' => $token] = $this->makeSurveyWithRoom();

        // No questions created for this room.

        $response = $this->postJson(
            route('survey.room.complete', ['token' => $token, 'room' => $room->id]),
            ['rooms' => []]
        );

        $response->assertStatus(200)
                 ->assertJson(['completed' => true]);
    }
}
