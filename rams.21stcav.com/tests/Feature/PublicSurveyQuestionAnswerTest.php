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
 * Contract tests for the answer endpoint (Plan 04).
 *
 * These tests are RED in Wave 0 — production route and controller method
 * do not yet exist. Tests will fail (404 route not found) until Plan 04
 * registers the route and implements answerQuestion().
 *
 * Contract (Plan 04):
 *   POST /survey/{token}/rooms/{room}/questions/{question}
 *   Route name: survey.question.answer
 *   Validates: answer in [yes, no, other], other_text nullable max:2000
 *   Returns: JSON { answered: bool, other_text: string|null }
 *   Token-gated: room must belong to the survey identified by token
 *   Question-gated: question must belong to the room
 */
class PublicSurveyQuestionAnswerTest extends TestCase
{
    use RefreshDatabase;

    private SiteSurvey $survey;
    private SiteSurveyRoom $room;
    private SiteSurveyRoomQuestion $question;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();

        $this->token = (string) Str::uuid();

        $this->survey = SiteSurvey::create([
            'user_id'      => $user->id,
            'project_name' => 'Test Project',
            'status'       => 'draft',
        ]);
        // Re-audit S-03 — access_token was dropped from $fillable, so
        // SiteSurvey::create() no longer accepts it. Set it via forceFill()
        // so the test can drive a known token through the public route.
        // The boot::creating hook has already set a random UUID; this
        // overrides it.
        $this->survey->forceFill(['access_token' => $this->token])->save();
        $this->assertNotNull($this->survey->access_token);

        $this->room = $this->survey->rooms()->create([
            'room_name'  => 'Board Room',
            'space_type' => 'general',
            'sort_order' => 0,
        ]);

        $this->question = SiteSurveyRoomQuestion::create([
            'site_survey_room_id' => $this->room->id,
            'question'            => 'Is the ceiling accessible for cable routing?',
            'sort_order'          => 1,
            'answer'              => null,
            'other_text'          => null,
        ]);
    }

    // ─── Test 1: answer=yes returns 200 JSON {answered:true} ──────────────────

    /**
     * Posting answer=yes to a valid question must return 200 with answered=true.
     */
    public function test_post_with_answer_yes_returns_200_answered_true(): void
    {
        $response = $this->postJson(
            route('survey.question.answer', [
                'token'    => $this->token,
                'room'     => $this->room->id,
                'question' => $this->question->id,
            ]),
            ['answer' => 'yes']
        );

        $response->assertStatus(200)
                 ->assertJson(['answered' => true]);
    }

    // ─── Test 2: answer=other with other_text returns 200 with other_text ─────

    /**
     * Posting answer=other with other_text must return 200 with other_text in response.
     */
    public function test_post_with_answer_other_and_other_text_returns_200_with_other_text(): void
    {
        $response = $this->postJson(
            route('survey.question.answer', [
                'token'    => $this->token,
                'room'     => $this->room->id,
                'question' => $this->question->id,
            ]),
            ['answer' => 'other', 'other_text' => 'reason for other']
        );

        $response->assertStatus(200)
                 ->assertJson(['answered' => true, 'other_text' => 'reason for other']);
    }

    // ─── Test 3: invalid answer value returns 422 ─────────────────────────────

    /**
     * Posting an invalid answer value (not yes/no/other) must return 422.
     */
    public function test_post_with_invalid_answer_returns_422(): void
    {
        $response = $this->postJson(
            route('survey.question.answer', [
                'token'    => $this->token,
                'room'     => $this->room->id,
                'question' => $this->question->id,
            ]),
            ['answer' => 'maybe']
        );

        $response->assertStatus(422);
    }

    // ─── Test 4: question belonging to different room returns 403 ─────────────

    /**
     * Posting to a question that belongs to a different room must return 403.
     */
    public function test_post_to_question_belonging_to_different_room_returns_403(): void
    {
        $user = User::factory()->create();

        // Create a second survey/room/question that should NOT be accessible via first token.
        $otherToken  = (string) Str::uuid();
        $otherSurvey = SiteSurvey::create([
            'user_id'      => $user->id,
            'project_name' => 'Other Project',
            'status'       => 'draft',
        ]);
        // Re-audit S-03 — access_token was dropped from $fillable; force it
        // through so this second survey's token is known and controllable.
        $otherSurvey->forceFill(['access_token' => $otherToken])->save();
        $this->assertNotNull($otherSurvey->access_token);

        $otherRoom = $otherSurvey->rooms()->create([
            'room_name'  => 'Other Room',
            'space_type' => 'general',
            'sort_order' => 0,
        ]);

        $otherQuestion = SiteSurveyRoomQuestion::create([
            'site_survey_room_id' => $otherRoom->id,
            'question'            => 'Question in other room',
            'sort_order'          => 1,
            'answer'              => null,
            'other_text'          => null,
        ]);

        // Use first token but reference question from other room.
        $response = $this->postJson(
            route('survey.question.answer', [
                'token'    => $this->token,
                'room'     => $this->room->id,
                'question' => $otherQuestion->id,
            ]),
            ['answer' => 'yes']
        );

        $response->assertStatus(403);
    }

    // ─── Test 5: other_text exceeding 2000 chars returns 422 ─────────────────

    /**
     * Posting other_text exceeding 2000 characters must return 422.
     */
    public function test_post_with_other_text_exceeding_2000_chars_returns_422(): void
    {
        $response = $this->postJson(
            route('survey.question.answer', [
                'token'    => $this->token,
                'room'     => $this->room->id,
                'question' => $this->question->id,
            ]),
            ['answer' => 'other', 'other_text' => str_repeat('x', 2001)]
        );

        $response->assertStatus(422);
    }

    // ─── Test 6: POST to submitted survey returns 403 ─────────────────────────

    /**
     * Posting to a question in a submitted survey must return 403.
     */
    public function test_post_to_submitted_survey_returns_403(): void
    {
        // Mark the survey as submitted.
        $this->survey->update(['status' => 'completed', 'submitted_at' => now()]);

        $response = $this->postJson(
            route('survey.question.answer', [
                'token'    => $this->token,
                'room'     => $this->room->id,
                'question' => $this->question->id,
            ]),
            ['answer' => 'yes']
        );

        $response->assertStatus(403);
    }
}
