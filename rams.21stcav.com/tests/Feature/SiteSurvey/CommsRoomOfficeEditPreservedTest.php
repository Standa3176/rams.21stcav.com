<?php

namespace Tests\Feature\SiteSurvey;

use App\Models\SiteSurvey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 46.8 Plan 04 — THE NON-VACUITY PROOF FOR D-08, closing the gap 46.8-03-SUMMARY
 * flagged explicitly: "no test in the repo currently exercises
 * `SiteSurveyController::update()` posting a comms-room-carrying survey through
 * the edit form and re-reading it."
 *
 * Proves BOTH halves together, as this phase's own convention requires
 * (`CockpitSurveyFeedbackFieldsTest`'s docblock): the control is genuinely gone
 * from the office edit screen, AND a POST that still carries the old field key
 * (e.g. a stale cached form, a hand-crafted request, a browser extension replay)
 * does not persist it — nor does it silently null the survey's EXISTING stored
 * value, which is 46.8-03's own data-safety fix in `SurveyService::update()`
 * (D-03: "stored values on past surveys must survive on disk").
 *
 * NEVER run `artisan migrate:fresh --env=testing` (see gate-46.ps1).
 */
class CommsRoomOfficeEditPreservedTest extends TestCase
{
    use RefreshDatabase;

    private function survey(): SiteSurvey
    {
        $user = User::factory()->create();

        return SiteSurvey::create([
            'user_id'                  => $user->id,
            'project_name'             => 'Comms Room Preservation Fixture',
            'status'                   => 'draft',
            'comms_room_access_status' => 'yes',
            'comms_room_access_notes'  => 'SENTINEL-EXISTING-COMMS-ROOM-VALUE',
        ]);
    }

    /** D-08's control-absence half, proven against the real rendered edit screen. */
    public function test_the_office_edit_screen_has_no_comms_room_access_control(): void
    {
        $survey = $this->survey();
        $user   = User::find($survey->user_id);

        $html = $this->actingAs($user)
            ->get(route('site-surveys.edit', $survey))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('comms_room_access_status', $html);
        $this->assertStringNotContainsString('comms_room_access_notes', $html);
    }

    /**
     * D-08's non-persistence half: a POST that still carries the old field
     * key is silently dropped by validation (the rule was removed), and
     * `SurveyService::update()`'s preserve-on-absent fix means the survey's
     * EXISTING stored value survives the save untouched — not nulled, and
     * not overwritten by the attacker-supplied/stale value either.
     */
    public function test_a_post_still_carrying_the_old_field_does_not_persist_it_and_preserves_the_existing_value(): void
    {
        $survey = $this->survey();
        $user   = User::find($survey->user_id);

        $this->actingAs($user)
            ->put(route('site-surveys.update', $survey), [
                'project_name'             => 'Comms Room Preservation Fixture',
                'general_notes'            => 'Edited through the office form.',
                // The two fields D-08 removed the control for. If validation
                // still accepted these, OR if the service defaulted an
                // absent key to null, either regression would be caught below.
                'comms_room_access_status' => 'outsourced',
                'comms_room_access_notes'  => 'ATTACKER-OR-STALE-FORM-VALUE',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('site-surveys.show', $survey));

        $fresh = $survey->fresh();

        $this->assertSame(
            'yes',
            $fresh->comms_room_access_status,
            'The existing stored comms_room_access_status was overwritten by a POST that should not have reached it.',
        );
        $this->assertSame(
            'SENTINEL-EXISTING-COMMS-ROOM-VALUE',
            $fresh->comms_room_access_notes,
            'The existing stored comms_room_access_notes was overwritten (or nulled) by this save — D-03 is broken.',
        );
        $this->assertSame('Edited through the office form.', $fresh->general_notes, 'Every OTHER field in the same request must still save normally.');
    }

    /**
     * THE OTHER DIRECTION, NON-VACUOUSLY: an ordinary save with NEITHER
     * comms-room field posted (what the real form always sends now) must
     * ALSO preserve the existing value — proving the preservation is the
     * absent-key default, not an artefact of this test posting the old keys.
     */
    public function test_an_ordinary_save_with_neither_comms_room_field_posted_also_preserves_the_existing_value(): void
    {
        $survey = $this->survey();
        $user   = User::find($survey->user_id);

        $this->actingAs($user)
            ->put(route('site-surveys.update', $survey), [
                'project_name' => 'Comms Room Preservation Fixture',
            ])
            ->assertSessionHasNoErrors();

        $fresh = $survey->fresh();

        $this->assertSame('yes', $fresh->comms_room_access_status);
        $this->assertSame('SENTINEL-EXISTING-COMMS-ROOM-VALUE', $fresh->comms_room_access_notes);
    }
}
