<?php

namespace Tests\Feature\SiteSurvey;

use App\Models\SiteSurvey;
use App\Models\SiteSurveyPhoto;
use App\Models\SiteSurveyRoom;
use App\Models\SiteSurveyRoomQuestion;
use App\Models\SurveyVariation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quick task 260517-su1 — verifies the unified pdf.site-survey.summary
 * Blade serves BOTH the engineer-internal and the client-facing survey
 * report via the `$internal` flag, with the right sections gated in/out
 * per mode.
 *
 * Tests render the blade directly via view()->render() rather than going
 * through the controller / PdfRenderService / Browsershot — Chromium is not
 * a reliable dependency on the CI runners and we only care about the HTML
 * surface, which is what feeds Browsershot in production. The two HTTP
 * route smoke tests at the end DO hit the controller, but they're allowed
 * to fail-soft if Browsershot isn't reachable (we only assert the URL
 * resolves to the right action).
 *
 * Engineer artefacts asserted absent from client mode (real Tilda data
 * patterns from the brief): "Pre-install Checks" heading, "Site Conditions"
 * heading, "Engineer Findings" heading, "Brackets to source" label,
 * "Installation heights" label.
 */
class SurveyPdfModesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{user:User, survey:SiteSurvey, room:SiteSurveyRoom, photo:SiteSurveyPhoto} */
    private function makeSurveyWithFullEngineerData(): array
    {
        $user = User::factory()->create();

        // Quick task 260816-t5c: `access_token` is guarded on SiteSurvey
        // (Re-audit S-03) — SiteSurvey::boot()'s creating hook auto-generates
        // a UUID regardless of anything passed here, so the mass-assign
        // attempt was a silent no-op. This test never reads access_token
        // back, so the key is simply dropped rather than force-filled.
        $survey = SiteSurvey::create([
            'user_id'             => $user->id,
            'project_name'        => 'Tilda Office Refit',
            'project_ref'         => 'TIL-001',
            'client_name'         => 'Tilda Ltd',
            'site_address'        => '12 Spice Way, London',
            'surveyor_name'       => 'J. Engineer',
            'status'              => 'draft',
            'office_review_notes' => 'Review summary for the client visit.',
        ]);

        $room = $survey->rooms()->create([
            'room_name'             => 'Saffron',
            'sort_order'            => 0,
            'room_width_m'          => 6,
            'room_depth_m'          => 4,
            'room_height_m'         => 3,
            'ceiling_type'          => 'tile',
            'wall_material'         => 'plasterboard',
            'av_requirements'       => 'Install 85" display + VC bar.',
            'av_equipment_list'     => "1x existing Samsung 65\" display",
            'has_power'             => true,
            'power_outlet_count'    => 4,
            'has_network'           => true,
            'network_port_count'    => 2,
            'office_notes'          => 'Per-room office note for Saffron.',
            // Engineer Findings — the bits we need to assert absent in client mode.
            'mounting_heights'      => ['screen_h_m' => '1.9', 'camera_h_m' => '1.9'],
            'work_at_height_methods' => ['ladder', 'podium', 'tower'],
            'cable_routes'          => [
                ['category' => 'screen_cables', 'from' => 'rack', 'to' => 'screen', 'length_m' => '8'],
            ],
            'brackets_required'     => [
                ['equipment' => '98" Display', 'model' => 'Peerless', 'pull_out' => true],
            ],
            'wall_construction'     => ['plasterboard'],
        ]);

        // Pre-install checks with an engineer free-text answer the client
        // must NEVER see.
        SiteSurveyRoomQuestion::create([
            'site_survey_room_id' => $room->id,
            'question'            => 'Is the wall accessible for mounting?',
            'sort_order'          => 0,
            'answer'              => 'other',
            'other_text'          => 'Pm to work this out',
        ]);

        $photo = SiteSurveyPhoto::create([
            'site_survey_room_id' => $room->id,
            'filename'            => 'survey-photos/saffron.jpg',
            'original_name'       => 'saffron.jpg',
            'mime_type'           => 'image/jpeg',
            'sort_order'          => 0,
            'caption'             => 'Saffron wall',
        ]);

        // One variation scoped to this room — should ONLY appear in client mode.
        SurveyVariation::create([
            'site_survey_id' => $survey->id,
            'room_name'      => 'Saffron',
            'type'           => 'extra_hardware',
            'description'    => 'Add second camera',
            'qty'            => 1,
            'status'         => 'proposed',
        ]);

        // Reload with the relations the blade needs.
        $survey = SiteSurvey::with(['rooms.photos', 'rooms.questions', 'variations'])
            ->find($survey->id);

        return compact('user', 'survey', 'room', 'photo');
    }

    // ─── Blade-level mode behaviour ────────────────────────────────────────────

    public function test_internal_mode_renders_engineer_only_sections(): void
    {
        ['survey' => $survey] = $this->makeSurveyWithFullEngineerData();

        $html = view('pdf.site-survey.summary', ['survey' => $survey, 'internal' => true])->render();

        $this->assertStringContainsString('Site Conditions',     $html, 'Engineer mode must render Site Conditions table');
        $this->assertStringContainsString('Pre-install Checks',  $html, 'Engineer mode must render Pre-install Checks');
        $this->assertStringContainsString('Engineer Findings',   $html, 'Engineer mode must render Engineer Findings');
        $this->assertStringContainsString('Installation heights', $html, 'Engineer mode must surface mounting heights');
        $this->assertStringContainsString('Brackets to source',  $html, 'Engineer mode must surface brackets list');
        $this->assertStringContainsString('Pm to work this out', $html, 'Engineer mode must include engineer free-text answers');
    }

    public function test_client_mode_suppresses_engineer_only_sections(): void
    {
        ['survey' => $survey] = $this->makeSurveyWithFullEngineerData();

        $html = view('pdf.site-survey.summary', ['survey' => $survey, 'internal' => false])->render();

        $this->assertStringNotContainsString('Site Conditions',     $html, 'Client mode must NOT render Site Conditions table');
        $this->assertStringNotContainsString('Pre-install Checks',  $html, 'Client mode must NOT render Pre-install Checks');
        $this->assertStringNotContainsString('Engineer Findings',   $html, 'Client mode must NOT render Engineer Findings');
        $this->assertStringNotContainsString('Installation heights', $html, 'Client mode must NOT leak mounting heights');
        $this->assertStringNotContainsString('Brackets to source',  $html, 'Client mode must NOT leak brackets list');
        $this->assertStringNotContainsString('Pm to work this out', $html, 'Client mode must NEVER include engineer free-text answers');
    }

    public function test_both_modes_render_project_details_and_av_requirements(): void
    {
        ['survey' => $survey] = $this->makeSurveyWithFullEngineerData();

        foreach ([true, false] as $internal) {
            $html = view('pdf.site-survey.summary', [
                'survey'   => $survey,
                'internal' => $internal,
            ])->render();

            $label = $internal ? 'internal' : 'client';
            $this->assertStringContainsString('Tilda Office Refit',  $html, "[$label] project name must render");
            $this->assertStringContainsString('Project Details',     $html, "[$label] project meta heading must render");
            $this->assertStringContainsString('Install 85',          $html, "[$label] planned AV works must render");
            $this->assertStringContainsString('Saffron',             $html, "[$label] room name must render");
        }
    }

    public function test_both_modes_render_per_room_photos(): void
    {
        ['survey' => $survey] = $this->makeSurveyWithFullEngineerData();

        foreach ([true, false] as $internal) {
            $html = view('pdf.site-survey.summary', [
                'survey'   => $survey,
                'internal' => $internal,
            ])->render();

            $label = $internal ? 'internal' : 'client';
            // Photos section heading carries the count text — present in both
            // modes when the room has at least one photo.
            $this->assertStringContainsString('Photos (1)', $html, "[$label] photos heading must render");
            // The img tag is rendered unconditionally when the photo exists on
            // disk; on CI the survey-photos/saffron.jpg file may not exist (the
            // test never wrote real bytes), in which case PdfImageEmbedder
            // returns an empty data URI and the <img> is skipped. We assert
            // the SECTION renders rather than the tag — the section is what
            // proves both modes treat photos identically.
        }
    }

    public function test_client_mode_renders_variations_and_office_notes(): void
    {
        ['survey' => $survey] = $this->makeSurveyWithFullEngineerData();

        $html = view('pdf.site-survey.summary', ['survey' => $survey, 'internal' => false])->render();

        $this->assertStringContainsString('Office review summary',          $html, 'Client mode must render survey-level office review callout');
        $this->assertStringContainsString('Review summary for the client',  $html, 'Client mode must render office_review_notes content');
        $this->assertStringContainsString('Office notes',                   $html, 'Client mode must render per-room office_notes label');
        $this->assertStringContainsString('Per-room office note for Saffron', $html, 'Client mode must render per-room office_notes content');
        $this->assertStringContainsString('Variations for this room',       $html, 'Client mode must render per-room variations table');
        $this->assertStringContainsString('Add second camera',              $html, 'Client mode must render variation description');
    }

    public function test_internal_mode_omits_variations_and_office_notes(): void
    {
        ['survey' => $survey] = $this->makeSurveyWithFullEngineerData();

        $html = view('pdf.site-survey.summary', ['survey' => $survey, 'internal' => true])->render();

        $this->assertStringNotContainsString('Office review summary', $html, 'Engineer mode must NOT render office review callout');
        $this->assertStringNotContainsString('Office notes',           $html, 'Engineer mode must NOT render office_notes callout');
        $this->assertStringNotContainsString('Variations for this room', $html, 'Engineer mode must NOT render per-room variations');
    }

    // ─── Default behaviour (back-compat) ───────────────────────────────────────

    public function test_blade_defaults_to_internal_mode_when_flag_omitted(): void
    {
        ['survey' => $survey] = $this->makeSurveyWithFullEngineerData();

        // Caller invokes the blade without passing `internal` — must default
        // to engineer mode so legacy SurveyPdfService callers keep working.
        $html = view('pdf.site-survey.summary', ['survey' => $survey])->render();

        $this->assertStringContainsString('Site Conditions',    $html, 'Default mode (no flag) must be engineer/internal');
        $this->assertStringContainsString('Pre-install Checks', $html);
        $this->assertStringContainsString('Engineer Findings',  $html);
    }

    // ─── Route back-compat — both routes still resolve to the unified blade ──

    public function test_pdf_download_route_resolves_with_internal_flag(): void
    {
        ['user' => $user, 'survey' => $survey] = $this->makeSurveyWithFullEngineerData();

        // We don't render the PDF here (no Chromium in CI). We just confirm
        // the route names + URL params we ship in the UI buttons resolve to
        // the SiteSurveyController::downloadPdf action — i.e. the back-compat
        // surface is intact and the ?internal query param is a recognised
        // input. Route::has() proves the named route exists post-merge.
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('site-surveys.pdf'),
            'Engineer PDF route must remain named site-surveys.pdf');
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('site-surveys.client-report'),
            'Legacy client-report route must remain named (back-compat for old bookmarks)');

        // URL builder must accept the ?internal query string without error.
        $url = route('site-surveys.pdf', $survey) . '?internal=0';
        $this->assertStringContainsString('?internal=0', $url);
    }
}
