<?php

namespace Tests\Unit\Models;

use App\Models\SiteSurveyRoomQuestion;
use App\Models\SiteSurveyRoom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contract tests for SiteSurveyRoomQuestion model.
 *
 * These tests are RED in Wave 0 — production class does not yet exist.
 * Tests will error (class not found) until Plan 02 creates the model.
 *
 * Contract:
 *   - fillable: [site_survey_room_id, question, sort_order, answer, other_text]
 *   - casts: sort_order => integer, answer => nullable string
 *   - belongsTo SiteSurveyRoom via site_survey_room_id
 */
class SurveyRoomQuestionModelTest extends TestCase
{
    use RefreshDatabase;

    // ─── Test 1: fillable fields ───────────────────────────────────────────────

    /**
     * Model must declare exactly these fillable fields.
     */
    public function test_model_has_correct_fillable_fields(): void
    {
        $model = new SiteSurveyRoomQuestion();

        $this->assertEquals(
            ['site_survey_room_id', 'question', 'sort_order', 'answer', 'other_text'],
            $model->getFillable()
        );
    }

    // ─── Test 2: sort_order cast ───────────────────────────────────────────────

    /**
     * sort_order must be cast to integer.
     */
    public function test_model_casts_sort_order_as_integer(): void
    {
        $casts = (new SiteSurveyRoomQuestion())->getCasts();

        $this->assertArrayHasKey('sort_order', $casts);
        $this->assertEquals('integer', $casts['sort_order']);
    }

    // ─── Test 3: belongsTo SiteSurveyRoom ─────────────────────────────────────

    /**
     * The model must define a belongsTo relationship to SiteSurveyRoom.
     */
    public function test_belongs_to_site_survey_room_relationship_exists(): void
    {
        $model = new SiteSurveyRoomQuestion();

        $relation = $model->room();

        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Relations\BelongsTo::class,
            $relation
        );

        $this->assertInstanceOf(SiteSurveyRoom::class, $relation->getRelated());
    }

    // ─── Test 4: inline create creates valid record ────────────────────────────

    /**
     * Creating a record inline (no factory) must persist to the database.
     *
     * This test depends on having a site_survey_rooms record — it will fail
     * until Plan 02 creates the table migration.
     */
    public function test_inline_create_creates_valid_record(): void
    {
        // We need a parent room to satisfy the foreign key.
        // SiteSurveyRoom requires a SiteSurvey parent.
        $survey = \App\Models\SiteSurvey::create([
            'user_id'       => \App\Models\User::factory()->create()->id,
            'project_name'  => 'Test Project',
            'status'        => 'draft',
            'access_token'  => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $room = $survey->rooms()->create([
            'room_name'  => 'Board Room',
            'space_type' => 'general',
            'sort_order' => 0,
        ]);

        $question = SiteSurveyRoomQuestion::create([
            'site_survey_room_id' => $room->id,
            'question'            => 'Is the ceiling accessible for cable routing?',
            'sort_order'          => 1,
            'answer'              => null,
            'other_text'          => null,
        ]);

        $this->assertDatabaseHas('site_survey_room_questions', [
            'id'                  => $question->id,
            'site_survey_room_id' => $room->id,
            'question'            => 'Is the ceiling accessible for cable routing?',
        ]);
    }
}
