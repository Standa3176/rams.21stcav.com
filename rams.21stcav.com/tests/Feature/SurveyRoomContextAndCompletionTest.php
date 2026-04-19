<?php

namespace Tests\Feature;

use App\Core\Modules\Survey\SurveyService;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\SiteSurvey;
use App\Models\SiteSurveyRoom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression locks for:
 *   (A) createFromProject() now seeds av_equipment_list per room from the
 *       quote's equipment list grouped by area.
 *   (B) live /survey/{token} renders the per-room Quote kit card so the
 *       seeded context actually reaches the engineer.
 *   (C) stepSave persists Step 7 constraints + Step 8 sign-off into the
 *       canonical survey_data payload and round-trips on reload.
 */
class SurveyRoomContextAndCompletionTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeProjectWithPackage(): Project
    {
        $user    = User::factory()->create();
        $project = Project::create([
            'user_id'      => $user->id,
            'name'         => 'Acme HQ Refresh',
            'client_name'  => 'Acme Ltd',
            'site_address' => '1 Example Way, London',
        ]);

        ProjectPackage::create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'status'         => ProjectPackage::STATUS_REVIEWED,
            'extracted_data' => [
                'room_overviews' => [
                    ['room' => 'Board Room',    'overview' => 'VC + 85" display install', 'summary' => ''],
                    ['room' => 'Training Room', 'overview' => 'Projector + PA',           'summary' => ''],
                ],
                'equipment' => [
                    ['name' => 'Samsung QM85 Display', 'quantity' => 1, 'area' => 'Board Room',    'category' => 'hardware'],
                    ['name' => 'Logitech Rally Bar',   'quantity' => 1, 'area' => 'Board Room',    'category' => 'hardware'],
                    ['name' => 'Epson Projector',      'quantity' => 1, 'area' => 'Training Room', 'category' => 'hardware'],
                ],
            ],
            'equipment_list' => [
                ['quantity' => 1, 'description' => 'Samsung QM85 Display'],
                ['quantity' => 1, 'description' => 'Logitech Rally Bar'],
                ['quantity' => 1, 'description' => 'Epson Projector'],
            ],
            'revision'       => 1,
        ]);

        return $project->fresh();
    }

    private function makeLiveSurveyFromProject(): array
    {
        $project = $this->makeProjectWithPackage();
        $user    = $project->owner ?? User::find($project->user_id);
        $survey  = app(SurveyService::class)->createFromProject($project, $user);
        return [$survey->fresh(), $project, $user];
    }

    // ─── A — createFromProject seeds per-room kit ────────────────────────────

    public function test_create_from_project_seeds_av_equipment_list_per_room(): void
    {
        [$survey] = $this->makeLiveSurveyFromProject();

        $rooms = $survey->rooms->keyBy('room_name');

        $this->assertNotEmpty($rooms['Board Room']->av_equipment_list,    'Board Room should be seeded');
        $this->assertNotEmpty($rooms['Training Room']->av_equipment_list, 'Training Room should be seeded');

        $board = $rooms['Board Room']->av_equipment_list;
        $this->assertStringContainsString('Samsung QM85 Display', $board);
        $this->assertStringContainsString('Logitech Rally Bar',   $board);
        $this->assertStringContainsString('× ', $board, 'Kit string uses "{qty} × {name}" format');

        $training = $rooms['Training Room']->av_equipment_list;
        $this->assertStringContainsString('Epson Projector', $training);
        $this->assertStringNotContainsString('Samsung',      $training, 'Per-room grouping must isolate items');
    }

    public function test_create_from_project_does_not_fail_when_no_equipment_for_room(): void
    {
        $user    = User::factory()->create();
        $project = Project::create([
            'user_id'      => $user->id,
            'name'         => 'No-kit Project',
            'client_name'  => 'Acme Ltd',
            'site_address' => '1 Example Way, London',
        ]);

        ProjectPackage::create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'status'         => ProjectPackage::STATUS_REVIEWED,
            'extracted_data' => [
                'room_overviews' => [
                    ['room' => 'Break Room', 'overview' => 'TBD', 'summary' => ''],
                ],
                'equipment' => [],
            ],
            'equipment_list' => [],
            'revision'       => 1,
        ]);

        $survey = app(SurveyService::class)->createFromProject($project->fresh(), $user);

        $this->assertCount(1, $survey->rooms);
        // av_equipment_list may be null when there's no matching kit — must
        // not throw, and must not clobber av_requirements.
        $this->assertNotNull($survey->rooms->first()->av_requirements);
    }

    // ─── B — live wizard shows per-room Quote kit from seeded rooms ──────────

    public function test_live_survey_page_shows_per_room_quote_kit_for_seeded_rooms(): void
    {
        [$survey] = $this->makeLiveSurveyFromProject();

        $response = $this->get(route('survey.show', ['token' => $survey->access_token]));

        $response->assertStatus(200);
        $body = $response->getContent();
        $this->assertStringContainsString('Quote kit',              $body);
        $this->assertStringContainsString('Samsung QM85 Display',   $body);
        $this->assertStringContainsString('Epson Projector',        $body);
    }

    // ─── C — stepSave persists Step 7 + Step 8 into canonical payload ───────

    public function test_step_save_persists_step_7_constraints_into_canonical_payload(): void
    {
        [$survey] = $this->makeLiveSurveyFromProject();

        // Prime survey_data by hitting show() first.
        $this->get(route('survey.show', ['token' => $survey->access_token]))->assertStatus(200);

        $this->postJson(route('survey.step.save', ['token' => $survey->access_token]), [
            'room_index' => 0,
            'step'       => 7,
            'data'       => [
                'constraints' => [
                    'obstructions'          => 'Columns down the middle of the room',
                    'noise_restrictions'    => 'No drilling 9-10am',
                    'client_constraints'    => 'Board meeting Tue',
                    'programme_constraints' => 'Must finish before 30 May',
                    'rogue_field'           => 'should be stripped',
                ],
            ],
        ])->assertStatus(200);

        $fresh = $survey->fresh()->survey_data;
        $room0 = $fresh['rooms'][0];

        $this->assertSame('Columns down the middle of the room', $room0['constraints']['obstructions']);
        $this->assertSame('No drilling 9-10am',                  $room0['constraints']['noise_restrictions']);
        $this->assertSame('Board meeting Tue',                   $room0['constraints']['client_constraints']);
        $this->assertSame('Must finish before 30 May',           $room0['constraints']['programme_constraints']);
        $this->assertArrayNotHasKey('rogue_field', $room0['constraints'], 'Unknown keys must be stripped.');
    }

    public function test_step_save_persists_step_8_signoff_with_server_timestamp(): void
    {
        [$survey] = $this->makeLiveSurveyFromProject();

        $this->get(route('survey.show', ['token' => $survey->access_token]))->assertStatus(200);

        $this->postJson(route('survey.step.save', ['token' => $survey->access_token]), [
            'room_index' => 0,
            'step'       => 8,
            'data'       => [
                'signoff' => [
                    'engineer_name'      => 'J. Smith',
                    'engineer_confirmed' => true,
                ],
            ],
        ])->assertStatus(200);

        $fresh = $survey->fresh()->survey_data;
        $room0 = $fresh['rooms'][0];

        $this->assertSame('J. Smith', $room0['signoff']['engineer_name']);
        $this->assertTrue((bool) $room0['signoff']['engineer_confirmed']);
        $this->assertNotNull($room0['signoff']['signed_at'], 'signed_at must be server-stamped on confirm.');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', (string) $room0['signoff']['signed_at']);
    }

    public function test_step_save_rejects_step_8_signoff_when_confirmed_without_engineer_name(): void
    {
        [$survey] = $this->makeLiveSurveyFromProject();

        $this->get(route('survey.show', ['token' => $survey->access_token]))->assertStatus(200);

        $response = $this->postJson(route('survey.step.save', ['token' => $survey->access_token]), [
            'room_index' => 0,
            'step'       => 8,
            'data'       => [
                'signoff' => [
                    'engineer_name'      => '',
                    'engineer_confirmed' => true,
                ],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_reload_after_step_7_and_8_restores_captured_data_into_ui_block(): void
    {
        [$survey] = $this->makeLiveSurveyFromProject();

        $this->get(route('survey.show', ['token' => $survey->access_token]))->assertStatus(200);
        $this->postJson(route('survey.step.save', ['token' => $survey->access_token]), [
            'room_index' => 0,
            'step'       => 7,
            'data'       => ['constraints' => ['obstructions' => 'Columns', 'noise_restrictions' => '', 'client_constraints' => '', 'programme_constraints' => '']],
        ])->assertStatus(200);
        $this->postJson(route('survey.step.save', ['token' => $survey->access_token]), [
            'room_index' => 0,
            'step'       => 8,
            'data'       => ['signoff' => ['engineer_name' => 'J. Smith', 'engineer_confirmed' => true]],
        ])->assertStatus(200);

        // Re-render — the blade embeds rooms as JSON via x-data, so the
        // engineer name and obstructions must appear in the rendered HTML.
        $body = $this->get(route('survey.show', ['token' => $survey->access_token]))->getContent();
        $this->assertStringContainsString('Columns',   $body);
        $this->assertStringContainsString('J. Smith',  $body);
    }
}
