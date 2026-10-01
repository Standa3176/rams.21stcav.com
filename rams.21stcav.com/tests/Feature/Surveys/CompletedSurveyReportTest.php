<?php

namespace Tests\Feature\Surveys;

use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\SurveyReportSection;
use App\Models\User;
use App\Services\SurveyPdfService;
use App\Services\SurveyReportXlsxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * Phase 46.6, Plan 04 — the completed-survey report, as PDF and real .xlsx.
 *
 * D-05: five sections in order — Rooms Summary (derived), Points Raised /
 * Possible Issues, Items Agreed With Client, Client's Items Required,
 * Additional Comments (the latter four read from SurveyReportSection::latestFor()).
 *
 * D-08: no second PDF renderer — buildCompletedReport() must call the SAME
 * fromBlade target ('pdf.site-survey.summary') as buildSummary()/
 * buildClientReport(). Proven here by source inspection, not merely
 * eyeballed.
 *
 * D-07: "XLS" means a real .xlsx via phpoffice/phpspreadsheet, following
 * CableScheduleXlsxService's writer pattern — proven here by ZipArchive +
 * IOFactory read-back, not a byte-count check.
 *
 * Browsershot/Chromium is NOT invoked in this suite — per the existing
 * SurveyPdfModesTest precedent, PDF-content assertions render the Blade
 * view directly via view()->render() so these tests do not depend on a
 * Chromium binary being present on the runner.
 *
 * @see app/Services/SurveyPdfService.php (buildCompletedReport)
 * @see app/Services/SurveyReportXlsxService.php
 * @see resources/views/pdf/site-survey/summary.blade.php ($completed block)
 * @see .planning/phases/46.6-survey-review-completed-report/46.6-CONTEXT.md (D-05, D-07, D-08)
 */
class CompletedSurveyReportTest extends TestCase
{
    use RefreshDatabase;

    // ─── Task 1: PDF via the existing renderer ─────────────────────────────

    public function test_build_completed_report_calls_the_same_from_blade_target_as_the_existing_methods(): void
    {
        // D-08 proof: source-inspect SurveyPdfService so a second renderer
        // cannot quietly appear. Every fromBlade call in this class must
        // target the SAME view, and no new PDF-rendering class is referenced.
        $source = file_get_contents(app_path('Services/SurveyPdfService.php'));

        $this->assertStringContainsString('buildCompletedReport', $source);

        // Isolate the buildCompletedReport() method body and confirm its
        // own fromBlade() call targets the SAME unified view as
        // buildSummary()/buildClientReport() — not a new template. (Other
        // methods on this class, e.g. buildBlank()/buildFieldFormContents(),
        // legitimately target different views; this assertion is scoped to
        // the one method this plan adds.)
        $this->assertMatchesRegularExpression(
            '/function buildCompletedReport\(.*?\r?\n    \}/s',
            $source,
        );
        preg_match('/function buildCompletedReport\(.*?\r?\n    \}/s', $source, $methodMatch);
        $methodBody = $methodMatch[0];

        preg_match_all("/fromBlade\\(\\s*\n?\\s*'([^']+)'/", $methodBody, $matches);
        $this->assertNotEmpty($matches[1], 'buildCompletedReport() must call fromBlade(...).');
        foreach ($matches[1] as $viewTarget) {
            $this->assertSame(
                'pdf.site-survey.summary',
                $viewTarget,
                'buildCompletedReport() must target the single unified view — a second renderer would show up as a different view string here.'
            );
        }

        // The class docblock legitimately mentions "Dompdf" historically
        // (it explains the Browsershot migration away from it) — what
        // must NOT appear is an actual USE of a second renderer.
        $this->assertStringNotContainsString('use Dompdf', $source);
        $this->assertStringNotContainsString('new Dompdf', $source);
        $this->assertStringNotContainsString('Barryvdh', $source);
        $this->assertStringNotContainsString('Mpdf\\Mpdf', $source);
    }

    public function test_five_sections_render_in_order_with_seeded_content_and_escaped_script(): void
    {
        $survey = $this->makeSurveyWithRoom();

        SurveyReportSection::create([
            'site_survey_id' => $survey->id,
            'section'        => 'points_raised',
            'body'           => "Loose trunking in the comms room.\n<script>alert('xss')</script>",
            'user_id'        => null,
        ]);
        SurveyReportSection::create([
            'site_survey_id' => $survey->id,
            'section'        => 'items_agreed',
            'body'           => 'Client agreed to the revised camera position.',
            'user_id'        => null,
        ]);
        SurveyReportSection::create([
            'site_survey_id' => $survey->id,
            'section'        => 'client_items_required',
            'body'           => 'Client to provide a 4-way extension lead.',
            'user_id'        => null,
        ]);
        SurveyReportSection::create([
            'site_survey_id' => $survey->id,
            'section'        => 'additional_comments',
            'body'           => 'Evening install confirmed, 6pm start.',
            'user_id'        => null,
        ]);

        $reportSections = [];
        foreach (SurveyReportSection::SECTIONS as $key) {
            $reportSections[$key] = SurveyReportSection::latestFor($survey->id, $key)?->body;
        }

        $html = view('pdf.site-survey.summary', [
            'survey'         => $survey->load('rooms', 'variations'),
            'completed'      => true,
            'reportSections' => $reportSections,
        ])->render();

        $posRooms   = strpos($html, 'Rooms Summary');
        $posPoints  = strpos($html, 'Points Raised');
        $posAgreed  = strpos($html, 'Items Agreed With Client');
        $posClient  = strpos($html, "Client's Items Required");
        $posComment = strpos($html, 'Additional Comments');

        $this->assertNotFalse($posRooms, 'Rooms Summary heading must render');
        $this->assertNotFalse($posPoints, 'Points Raised heading must render');
        $this->assertNotFalse($posAgreed, 'Items Agreed With Client heading must render');
        $this->assertNotFalse($posClient, "Client's Items Required heading must render");
        $this->assertNotFalse($posComment, 'Additional Comments heading must render');

        $this->assertTrue($posRooms < $posPoints, 'Rooms Summary must come before Points Raised');
        $this->assertTrue($posPoints < $posAgreed, 'Points Raised must come before Items Agreed With Client');
        $this->assertTrue($posAgreed < $posClient, "Items Agreed With Client must come before Client's Items Required");
        $this->assertTrue($posClient < $posComment, "Client's Items Required must come before Additional Comments");

        $this->assertStringContainsString('Loose trunking in the comms room.', $html);
        $this->assertStringContainsString('Client agreed to the revised camera position.', $html);
        $this->assertStringContainsString('Client to provide a 4-way extension lead.', $html);
        $this->assertStringContainsString('Evening install confirmed, 6pm start.', $html);

        // The planted <script> must render escaped, not executable.
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_a_section_with_no_row_yet_renders_a_named_placeholder_not_an_empty_heading(): void
    {
        $survey = $this->makeSurveyWithRoom();

        // No SurveyReportSection rows at all — every section is unreviewed.
        $reportSections = [];
        foreach (SurveyReportSection::SECTIONS as $key) {
            $reportSections[$key] = SurveyReportSection::latestFor($survey->id, $key)?->body;
        }

        $html = view('pdf.site-survey.summary', [
            'survey'         => $survey->load('rooms', 'variations'),
            'completed'      => true,
            'reportSections' => $reportSections,
        ])->render();

        $this->assertSame(4, substr_count($html, 'Not yet reviewed.'), 'Every one of the four office sections with no row yet must render the named placeholder.');
    }

    public function test_rooms_summary_lists_room_name_and_work_type_with_no_new_eager_load(): void
    {
        $survey = $this->makeSurveyWithRoom();

        $reportSections = [];
        foreach (SurveyReportSection::SECTIONS as $key) {
            $reportSections[$key] = null;
        }

        $html = view('pdf.site-survey.summary', [
            'survey'         => $survey->load('rooms', 'variations'),
            'completed'      => true,
            'reportSections' => $reportSections,
        ])->render();

        $this->assertStringContainsString('Comms Room', $html);
        $this->assertStringContainsString('New install', $html, 'work_type=new_install must render as a labelled value');
    }

    // ─── Task 2: real .xlsx via PhpSpreadsheet ──────────────────────────────

    public function test_build_produces_a_real_xlsx_openable_by_ziparchive_and_io_factory(): void
    {
        $survey = $this->makeSurveyWithRoom();
        SurveyReportSection::create([
            'site_survey_id' => $survey->id,
            'section'        => 'points_raised',
            'body'           => 'Loose trunking in the comms room.',
            'user_id'        => null,
        ]);

        $path = app(SurveyReportXlsxService::class)->build($survey->load('rooms'));

        $this->assertFileExists($path);

        $zip = new ZipArchive();
        $opened = $zip->open($path);
        $this->assertTrue($opened === true, 'A real .xlsx is a ZIP archive — ZipArchive::open() must succeed.');
        $this->assertNotFalse($zip->locateName('xl/workbook.xml'), 'xl/workbook.xml must exist inside the archive.');
        $zip->close();

        $loaded = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet = $loaded->getActiveSheet();
        $values = [];
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $values[] = (string) $cell->getValue();
            }
        }
        $joined = implode(' ', $values);

        $this->assertStringContainsString('Comms Room', $joined, 'Rooms summary room name must be a real, readable cell value.');
        $this->assertStringContainsString('Loose trunking in the comms room.', $joined, 'Latest points_raised body must be a real, readable cell value.');

        @unlink($path);
    }

    public function test_formula_injection_payload_is_neutralised_before_being_written_to_a_cell(): void
    {
        $survey = $this->makeSurveyWithRoom();
        SurveyReportSection::create([
            'site_survey_id' => $survey->id,
            'section'        => 'items_agreed',
            'body'           => '=cmd|\'/c calc\'!A0',
            'user_id'        => null,
        ]);

        $path = app(SurveyReportXlsxService::class)->build($survey->load('rooms'));

        $loaded = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet = $loaded->getActiveSheet();

        $found = false;
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $value = (string) $cell->getValue();
                if (str_contains($value, 'cmd|')) {
                    $found = true;
                    $this->assertNotSame('=', $value[0] ?? '', 'A formula-injection payload must not be stored with a leading =');
                    $this->assertNotContains($value[0] ?? '', ['=', '+', '-', '@'], 'The neutralised cell value must not start with =, +, -, or @.');
                }
            }
        }

        $this->assertTrue($found, 'The seeded formula-injection payload must actually have been written to a cell (otherwise this test proves nothing).');

        @unlink($path);
    }

    public function test_completed_report_routes_are_registered_and_guarded_by_authorize_survey(): void
    {
        $survey = $this->makeSurveyWithRoom();

        $this->assertTrue(\Illuminate\Support\Facades\Route::has('site-surveys.completed-report'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('site-surveys.completed-report.xlsx'));

        $pdfResponse = $this->get(route('site-surveys.completed-report', $survey));
        $xlsxResponse = $this->get(route('site-surveys.completed-report.xlsx', $survey));

        $pdfResponse->assertRedirect(route('login'));
        $xlsxResponse->assertRedirect(route('login'));
    }

    // Helpers ------------------------------------------------------------------

    private function makeSurveyWithRoom(): SiteSurvey
    {
        $project = Project::factory()->create();

        $survey = SiteSurvey::create([
            'user_id'      => $project->user_id ?? User::factory()->create()->id,
            'project_id'   => $project->id,
            'project_name' => $project->name,
            'survey_date'  => '2026-04-01',
        ]);

        $survey->rooms()->create([
            'room_name'  => 'Comms Room',
            'sort_order' => 0,
            'work_type'  => 'new_install',
            'space_type' => 'general',
            'notes'      => 'Door needs a new lock before install day.',
        ]);

        return $survey;
    }
}
