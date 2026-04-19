<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\SiteSurvey;
use App\Models\SiteSurveyRoom;
use App\Models\SiteSurveyRoomQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Token-gated downloadable Field Survey Form PDF.
 *
 * Contract:
 *   GET /survey/{token}/download-form
 *     - valid token               → 200 + application/pdf
 *     - unknown token             → 404 (matches existing token behaviour)
 *     - expired token             → 410 (matches existing token behaviour)
 *     - read-only: no DB mutation
 *
 * Also verifies the live survey page exposes the download link so engineers
 * can actually reach the PDF from the mobile wizard screen.
 */
class SurveyDownloadFormTest extends TestCase
{
    use RefreshDatabase;

    private function makeSurveyWithRoom(array $overrides = []): SiteSurvey
    {
        $user    = User::factory()->create();
        $project = Project::create([
            'user_id'      => $user->id,
            'name'         => 'Acme HQ Refresh',
            'client_name'  => 'Acme Ltd',
            'site_address' => '1 Example Way, London',
        ]);

        // Planned kit + works so the PDF can exercise the equipment/works branch.
        ProjectPackage::create([
            'user_id'           => $user->id,
            'project_id'        => $project->id,
            'status'            => ProjectPackage::STATUS_REVIEWED,
            'works_description' => 'Install 85" display + VC bar in Board Room.',
            'equipment_list'    => [
                ['quantity' => 1, 'description' => '85" 4K Display', 'manufacturer' => 'Samsung', 'model' => 'QM85'],
                ['quantity' => 1, 'description' => 'VC Bar',         'manufacturer' => 'Logitech', 'model' => 'Rally Bar'],
            ],
            'revision'          => 1,
        ]);

        $survey = SiteSurvey::create(array_merge([
            'user_id'       => $user->id,
            'project_id'    => $project->id,
            'project_name'  => 'Acme HQ Refresh',
            'project_ref'   => 'ACM-001',
            'client_name'   => 'Acme Ltd',
            'site_address'  => '1 Example Way, London',
            'surveyor_name' => 'J. Smith',
            'status'        => 'draft',
            'access_token'  => (string) Str::uuid(),
        ], $overrides));

        $room = SiteSurveyRoom::create([
            'site_survey_id'    => $survey->id,
            'room_name'         => 'Board Room',
            'space_type'        => 'general',
            'sort_order'        => 0,
            'av_requirements'   => 'Ceiling speakers + VC bar install; replace existing display.',
            'av_equipment_list' => "1x Samsung QM85 display\n1x Logitech Rally Bar\n4x JBL Control 26C",
        ]);

        // One checklist question for "guidance context" coverage.
        SiteSurveyRoomQuestion::create([
            'site_survey_room_id' => $room->id,
            'question'            => 'Is the ceiling accessible above the room?',
            'sort_order'          => 1,
            'answer'              => null,
        ]);

        return $survey->fresh();
    }

    public function test_valid_token_returns_pdf_with_correct_content_type(): void
    {
        $survey = $this->makeSurveyWithRoom();

        $response = $this->get(route('survey.download.form', ['token' => $survey->access_token]));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
    }

    public function test_unknown_token_returns_404(): void
    {
        $response = $this->get(route('survey.download.form', ['token' => 'this-token-does-not-exist']));

        $response->assertStatus(404);
    }

    public function test_expired_token_returns_410(): void
    {
        $survey = $this->makeSurveyWithRoom(['expires_at' => now()->subDay()]);

        $response = $this->get(route('survey.download.form', ['token' => $survey->access_token]));

        $response->assertStatus(410);
    }

    public function test_download_does_not_mutate_survey_row(): void
    {
        $survey = $this->makeSurveyWithRoom();
        // Compare raw DB attributes so updated_at / status / filename / survey_data
        // can be asserted as unchanged without hitting Carbon object identity.
        $before = $survey->fresh()->getRawOriginal();

        $this->get(route('survey.download.form', ['token' => $survey->access_token]))
            ->assertStatus(200);

        $after = $survey->fresh()->getRawOriginal();
        $this->assertSame($before, $after, 'Download path must not mutate the survey row.');
    }

    public function test_survey_show_page_contains_download_link(): void
    {
        $survey = $this->makeSurveyWithRoom();

        $response = $this->get(route('survey.show', ['token' => $survey->access_token]));

        $response->assertStatus(200);
        $response->assertSee(route('survey.download.form', ['token' => $survey->access_token]), escape: false);
        $response->assertSee('Download PDF Form');
    }

    /**
     * Regression: wizard must surface planned AV works, quote kit, and
     * checklist guidance per room so engineers see the scope context before
     * filling in the survey. These fields are injected into the Alpine room
     * objects under `_ctx` and rendered on the room list card.
     */
    public function test_survey_show_page_exposes_per_room_context(): void
    {
        $survey = $this->makeSurveyWithRoom();

        $response = $this->get(route('survey.show', ['token' => $survey->access_token]));

        $response->assertStatus(200);
        $body = $response->getContent();

        $this->assertStringContainsString('Ceiling speakers + VC bar install', $body, 'Planned AV works must appear per room');
        $this->assertStringContainsString('Samsung QM85 display',               $body, 'Quote kit must appear per room');
        $this->assertStringContainsString('Is the ceiling accessible above the room?', $body, 'Checklist guidance must appear per room');
        $this->assertStringContainsString('Pre-install checklist',              $body, 'Guidance label must appear');
        $this->assertStringContainsString('Planned AV works',                   $body, 'Works label must appear');
        $this->assertStringContainsString('Quote kit',                          $body, 'Kit label must appear');
    }

    /**
     * Regression: PDF must also include per-room planned works + quote kit
     * (not only the global summary) so the paper fallback matches the
     * wizard's per-room scope context.
     */
    public function test_pdf_includes_per_room_planned_works_and_kit(): void
    {
        $survey = $this->makeSurveyWithRoom();

        $pdfBytes = app(\App\Services\SurveyPdfService::class)->buildFieldFormContents($survey);

        // Dompdf encodes text in content streams — decode them via pdfparser.
        $parser = new \Smalot\PdfParser\Parser();
        $text   = $parser->parseContent($pdfBytes)->getText();

        $this->assertStringContainsString('Ceiling speakers + VC bar install', $text, 'PDF must include per-room planned AV works');
        $this->assertStringContainsString('Samsung QM85 display', $text, 'PDF must include per-room quote kit');
    }
}
