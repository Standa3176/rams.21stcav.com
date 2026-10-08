<?php

namespace Tests\Feature\Surveys;

use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\SiteSurveyPhoto;
use App\Models\SiteSurveyRoom;
use App\Models\SiteSurveyRoomQuestion;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 261008-gxt Task 1 — a superseded survey's engineer link must go read-only
 * EVERYWHERE, even for a never-submitted draft, and must never be reopenable
 * via the office "Send back" path.
 *
 * Test C is the non-vacuity guard for this task: a LIVE, not-superseded,
 * not-submitted survey must stay fully editable — proving the fix did not
 * over-reach and lock working surveys (the exact shape of the 2026-09-30
 * regression named in the plan).
 */
class SurveySupersededLockTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, string>  $rooms
     */
    private function makeSurvey(array $rooms = ['Board Room'], ?callable $tweak = null): SiteSurvey
    {
        $user    = User::factory()->create();
        $token   = (string) Str::uuid();
        $project = Project::factory()->create([
            'name'         => 'Gxt Job',
            'site_address' => '1 Test Street',
        ]);

        $survey = SiteSurvey::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => 'Gxt Job',
            'site_address' => '1 Test Street',
            'status'       => 'draft',
        ]);
        $survey->forceFill(['access_token' => $token])->save();

        foreach (array_values($rooms) as $i => $name) {
            $survey->rooms()->create([
                'room_name'  => $name,
                'space_type' => 'general',
                'sort_order' => $i,
            ]);
        }

        if ($tweak !== null) {
            $tweak($survey);
        }

        return $survey->fresh();
    }

    // ── Test A: superseded draft (never submitted) renders read-only ───────

    public function test_superseded_draft_never_submitted_renders_read_only_with_replaced_message(): void
    {
        $survey = $this->makeSurvey(['Board Room'], function (SiteSurvey $s): void {
            // Never submitted — submitted_at stays null — but superseded.
            $s->forceFill(['superseded_at' => now()])->save();
        });

        $this->assertNull($survey->submitted_at, 'Fixture sanity: this must be a never-submitted draft.');

        $response = $this->get(route('survey.show', ['token' => $survey->access_token]));

        $response->assertOk();
        $response->assertSee('This survey has been replaced by a newer survey. Please contact the office', false);
    }

    // ── Test B: all eight write endpoints 403 on a superseded survey ───────

    public function test_all_eight_write_endpoints_403_on_a_superseded_survey(): void
    {
        $survey = $this->makeSurvey(['Board Room'], function (SiteSurvey $s): void {
            $s->forceFill(['superseded_at' => now()])->save();
        });
        $room  = $survey->rooms()->first();
        $token = $survey->access_token;

        // 1. SurveyController::stepSave (JSON 403)
        $this->postJson(route('survey.step.save', ['token' => $token]), [
            'room_index' => 0,
            'step'       => 1,
            'data'       => ['name' => 'X', 'type' => 'board'],
        ])->assertStatus(403);

        // 2. PublicSurveyController::save
        $this->post(route('survey.save', ['token' => $token]), [])->assertStatus(403);

        // 3. PublicSurveyController::submit
        $this->post(route('survey.submit', ['token' => $token]), [
            'surveyor_name' => 'Jo Engineer',
        ])->assertStatus(403);

        // 4. PublicSurveyController::completeRoom
        $this->post(route('survey.room.complete', ['token' => $token, 'room' => $room->id]), [])
            ->assertStatus(403);

        // 5. PublicSurveyController::uncompleteRoom
        $this->post(route('survey.room.uncomplete', ['token' => $token, 'room' => $room->id]))
            ->assertStatus(403);

        // 6. PublicSurveyController::answerQuestion
        $question = SiteSurveyRoomQuestion::create([
            'site_survey_room_id' => $room->id,
            'question'             => 'Is there power?',
        ]);
        $this->post(route('survey.question.answer', [
            'token'    => $token,
            'room'     => $room->id,
            'question' => $question->id,
        ]), ['answer' => 'yes'])->assertStatus(403);

        // 7. PublicSurveyController::uploadPhoto
        $this->post(route('survey.photos.upload', ['token' => $token, 'room' => $room->id]), [])
            ->assertStatus(403);

        // 8. PublicSurveyController::updatePhoto
        $photo = SiteSurveyPhoto::create([
            'site_survey_room_id' => $room->id,
            'filename'             => 'x.jpg',
            'original_name'        => 'x.jpg',
            'mime_type'            => 'image/jpeg',
        ]);
        $this->patch(route('survey.photos.update', ['token' => $token, 'photo' => $photo->id]), [
            'caption' => 'new caption',
        ])->assertStatus(403);
    }

    // ── Test C (non-vacuity): a LIVE survey is completely unaffected ───────

    public function test_a_live_not_superseded_not_submitted_survey_stays_fully_editable(): void
    {
        $survey = $this->makeSurvey(['Board Room']);
        $room   = $survey->rooms()->first();
        $token  = $survey->access_token;

        $this->assertFalse($survey->isSuperseded());
        $this->assertFalse($survey->isSubmitted());

        $show = $this->get(route('survey.show', ['token' => $token]));
        $show->assertOk();
        $show->assertDontSee('This survey has been replaced by a newer survey', false);
        $show->assertDontSee('This survey has been submitted and is read-only', false);

        $this->postJson(route('survey.step.save', ['token' => $token]), [
            'room_index' => 0,
            'step'       => 1,
            'data'       => ['name' => 'Board Room', 'type' => 'board'],
        ])->assertOk();

        $this->post(route('survey.save', ['token' => $token]), [])
            ->assertRedirect(route('survey.show', ['token' => $token]));

        $this->post(route('survey.room.complete', ['token' => $token, 'room' => $room->id]), [])
            ->assertOk();
    }

    // ── Test D: superseded is immune to "Send back" reopening ──────────────

    public function test_superseded_survival_against_a_newer_send_back(): void
    {
        $survey = $this->makeSurvey(['Board Room']);

        // Visit::factory()->sentBack() forces the wrapped source's
        // submitted_at to now()->subDays(2) and sets sent_back_at to
        // now()->subDay() — AFTER the submission — which is exactly the one
        // path that makes VisitReworkState::isReopened() return true.
        Visit::factory()
            ->backfilledFromSurvey($survey)
            ->sentBack()
            ->create(['project_id' => $survey->project_id]);

        $survey = $survey->fresh();
        $this->assertNotNull($survey->submitted_at, 'Fixture sanity: the visit factory must have submitted it.');

        // Now supersede it — the state Task 1 must make unconditional.
        $survey->forceFill(['superseded_at' => now()])->save();
        $survey = $survey->fresh();

        $this->assertTrue(
            \App\Support\Visits\VisitReworkState::isReopened($survey),
            'Fixture sanity: the send-back must actually register as reopened.'
        );

        $this->assertTrue(
            $survey->isLockedForEngineer(),
            'A superseded survey must stay locked even though VisitReworkState reports reopened.'
        );
    }
}
