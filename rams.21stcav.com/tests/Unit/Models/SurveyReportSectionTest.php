<?php

namespace Tests\Unit\Models;

use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\SurveyReportSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 46.6, Plan 02 — the append-only doctrine test for
 * `SurveyReportSection`, mirroring `SnagTest` / `CockpitOfficeNoteAndSnagTest`
 * ::test_a_visit_note_has_no_update_path_and_no_delete_path_anywhere_in_app().
 *
 * @see app/Models/SurveyReportSection.php
 * @see database/migrations/2026_10_01_000000_create_survey_report_sections_table.php
 * @see .planning/phases/46.6-survey-review-completed-report/46.6-CONTEXT.md (D-03, D-08)
 */
class SurveyReportSectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sections_constant_is_exactly_the_four_named_sections(): void
    {
        $this->assertSame([
            'points_raised',
            'items_agreed',
            'client_items_required',
            'additional_comments',
        ], SurveyReportSection::SECTIONS);
    }

    public function test_the_table_has_the_expected_shape_and_no_updated_at(): void
    {
        $this->assertTrue(Schema::hasTable('survey_report_sections'));

        foreach (['id', 'site_survey_id', 'section', 'body', 'user_id', 'created_at'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('survey_report_sections', $column),
                "survey_report_sections is missing `{$column}`."
            );
        }

        $this->assertFalse(Schema::hasColumn('survey_report_sections', 'updated_at'));
        $this->assertNull(SurveyReportSection::UPDATED_AT);
    }

    public function test_a_second_save_to_the_same_section_adds_a_row_rather_than_updating_the_first(): void
    {
        $survey = $this->makeSurvey();
        $author = User::factory()->create();

        $first = SurveyReportSection::create([
            'site_survey_id' => $survey->id,
            'section'        => 'points_raised',
            'body'           => 'First draft of points raised.',
            'user_id'        => $author->id,
        ]);

        $second = SurveyReportSection::create([
            'site_survey_id' => $survey->id,
            'section'        => 'points_raised',
            'body'           => 'Revised points raised — adds the fire-door clearance note.',
            'user_id'        => $author->id,
        ]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, SurveyReportSection::where('site_survey_id', $survey->id)
            ->where('section', 'points_raised')
            ->count());

        // The first row is untouched — nothing was overwritten.
        $this->assertSame('First draft of points raised.', $first->fresh()->body);
    }

    public function test_latest_for_returns_the_most_recently_created_row_not_null(): void
    {
        $survey = $this->makeSurvey();

        $this->assertNull(SurveyReportSection::latestFor($survey->id, 'items_agreed'));

        SurveyReportSection::create([
            'site_survey_id' => $survey->id,
            'section'        => 'items_agreed',
            'body'           => 'Agreed: client to supply the rack.',
        ]);

        $latest = SurveyReportSection::create([
            'site_survey_id' => $survey->id,
            'section'        => 'items_agreed',
            'body'           => 'Agreed: client to supply the rack and two cable trays.',
        ]);

        $resolved = SurveyReportSection::latestFor($survey->id, 'items_agreed');

        $this->assertNotNull($resolved);
        $this->assertSame($latest->id, $resolved->id);
        $this->assertSame('Agreed: client to supply the rack and two cable trays.', $resolved->body);
    }

    public function test_a_section_resolves_its_survey_and_its_author(): void
    {
        $survey = $this->makeSurvey();
        $author = User::factory()->create(['name' => 'Priya Mistry']);

        $section = SurveyReportSection::create([
            'site_survey_id' => $survey->id,
            'section'        => 'additional_comments',
            'body'           => 'Client asked for an evening-only install window.',
            'user_id'        => $author->id,
        ]);

        $this->assertSame($survey->id, $section->survey->id);
        $this->assertSame('Priya Mistry', $section->author->name);
        $this->assertSame('Priya Mistry', $section->actor_name);
    }

    /**
     * Mirrors `VisitNote`'s own doctrine: the note outlives the account and
     * reads "System", same as the activity feed.
     */
    public function test_a_section_survives_its_authors_account_being_deleted(): void
    {
        $survey = $this->makeSurvey();
        $author = User::factory()->create();

        $section = SurveyReportSection::create([
            'site_survey_id' => $survey->id,
            'section'        => 'client_items_required',
            'body'           => 'Client to provide a 4-way extension lead for the plant room.',
            'user_id'        => $author->id,
        ]);

        $author->delete();

        $section->refresh();

        $this->assertNull($section->user_id);
        $this->assertSame('System', $section->actor_name);
        $this->assertSame('Client to provide a 4-way extension lead for the plant room.', $section->body);
    }

    /**
     * Mirrors `SurveyVariation`'s disposition (and D-08's framing: the
     * annotation has no meaning without its survey).
     */
    public function test_deleting_the_survey_cascades_to_its_report_sections(): void
    {
        $survey = $this->makeSurvey();

        $section = SurveyReportSection::create([
            'site_survey_id' => $survey->id,
            'section'        => 'points_raised',
            'body'           => 'Loose trunking in the comms room.',
        ]);

        $survey->forceDelete();

        $this->assertDatabaseMissing('survey_report_sections', ['id' => $section->id]);
    }

    /**
     * THE DOCTRINE ASSERTION — mirrors
     * CockpitOfficeNoteAndSnagTest::test_a_visit_note_has_no_update_path_and_no_delete_path_anywhere_in_app().
     * No `update()`/`delete()`/`forceDelete()` call against a
     * `SurveyReportSection`-shaped variable or query chain exists anywhere in
     * `app/`, and no route offers PUT/PATCH/DELETE for one.
     */
    public function test_a_report_section_has_no_update_path_and_no_delete_path_anywhere_in_app(): void
    {
        $this->assertNull(SurveyReportSection::UPDATED_AT);

        $offenders = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (! str_contains($source, 'SurveyReportSection')) {
                continue;
            }

            // A section-shaped variable being updated or deleted, and a
            // SurveyReportSection query chain ending in either — the two
            // shapes an "edit the section" feature would actually take.
            if (preg_match('/\$[A-Za-z_]*[Ss]ection[A-Za-z_]*\s*->\s*(update|delete|forceDelete)\s*\(/', $source)) {
                $offenders[] = $file->getPathname().' (a section variable is updated or deleted)';
            }

            if (preg_match('/SurveyReportSection::[^;]{0,400}->\s*(update|delete|forceDelete)\s*\(/s', $source)) {
                $offenders[] = $file->getPathname().' (a SurveyReportSection query is updated or deleted)';
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'survey_report_sections is append-only: nothing in app/ may edit or remove a section row.'
        );

        foreach (Route::getRoutes() as $route) {
            if (! str_contains($route->uri(), 'report-sections')) {
                continue;
            }

            $this->assertSame(
                [],
                array_values(array_intersect($route->methods(), ['PUT', 'PATCH', 'DELETE'])),
                "An edit or delete route exists for report sections: {$route->uri()}"
            );
        }
    }

    // Helpers ----------------------------------------------------------------

    /**
     * There is no SiteSurveyFactory in this repo; SnagTest builds one the
     * same way (tests/Unit/Models/SnagTest.php).
     */
    private function makeSurvey(): SiteSurvey
    {
        $project = Project::factory()->create();

        return SiteSurvey::create([
            'user_id'      => $project->user_id ?? User::factory()->create()->id,
            'project_id'   => $project->id,
            'project_name' => $project->name,
            'survey_date'  => '2026-04-01',
        ]);
    }
}
