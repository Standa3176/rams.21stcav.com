<?php

namespace Tests\Feature\Cockpit;

use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 46, Plan 46-07 — D-02's other two PM acts: ADD AN OFFICE NOTE and
 * RAISE A SNAG.
 *
 * THE ONE SENTENCE TASK 1 EXISTS TO PROVE (D-02, verbatim): "the PM annotates
 * the return without changing what the engineer said. The engineer's record
 * stays intact; the office view sits alongside it." So an office note goes to
 * its OWN append-only table and NEVER to `site_surveys.office_review_notes` —
 * a single overwritable, author-less field that lives ON the engineer's record
 * and has no worksheet equivalent.
 *
 * THE ONE SENTENCE THE SNAG TESTS EXIST TO PROVE (D-03): raising a snag is not
 * managing one. Three fields are accepted at the HTTP boundary and the five
 * Phase 47 field names are IGNORED, which complements 46-02's schema-level
 * scope fence rather than repeating it.
 *
 * THE CAP (VL-11): this plan REACHES four controls on a returned visit. Four
 * is the maximum, not a target — 46-06's
 * `test_no_visit_row_ever_renders_more_than_four_controls()` keeps its ceiling
 * and this file does not raise it.
 */
class CockpitOfficeNoteAndSnagTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cockpit.enabled' => true]);
    }

    private function project(): Project
    {
        return Project::factory()->create([
            'name'   => 'Office Note Job',
            'status' => Project::STATUS_INSTALLING,
        ]);
    }

    private function user(string $name = 'Priya Mistry'): User
    {
        return User::factory()->create(['name' => $name]);
    }

    /**
     * A survey an engineer HAS submitted, wrapped by a visit that was sent —
     * the same fixture shape 46-06 uses, because the D-02 comparison is only
     * meaningful against a record that actually holds engineer data.
     */
    private function returnedSurveyVisit(Project $project): Visit
    {
        $survey = SiteSurvey::create([
            'user_id'      => $this->user('Engineer Eve')->id,
            'project_id'   => $project->id,
            'project_name' => 'Office Note Fixture',
            'status'       => 'completed',
        ]);

        $survey->forceFill([
            'submitted_at'        => now()->subDays(2),
            'survey_data'         => ['comms_room' => 'Second floor, keyed access'],
            'office_review_notes' => 'A pre-existing office review note.',
        ])->save();

        return Visit::factory()->create([
            'project_id'  => $project->id,
            'type'        => Visit::TYPE_SITE_SURVEY,
            'status'      => Visit::STATUS_PLANNED,
            'sent_at'     => now()->subDays(3),
            'source_type' => Visit::SOURCE_SITE_SURVEY,
            'source_id'   => $survey->id,
        ]);
    }

    /** Everything an office act must never move, read RAW. */
    private function engineerBytes(SiteSurvey $survey): array
    {
        $survey->refresh();

        return [
            'submitted_at'        => (string) $survey->getRawOriginal('submitted_at'),
            'survey_data'         => (string) $survey->getRawOriginal('survey_data'),
            'office_review_notes' => (string) $survey->getRawOriginal('office_review_notes'),
            'access_token'        => (string) $survey->getRawOriginal('access_token'),
            'updated_at'          => (string) $survey->getRawOriginal('updated_at'),
        ];
    }

    // ── Task 1: visit_notes — append-only, authored, beside the record ───

    public function test_the_visit_notes_table_is_append_only_and_carries_its_author(): void
    {
        $this->assertTrue(Schema::hasTable('visit_notes'));

        foreach (['id', 'project_id', 'visit_id', 'user_id', 'body', 'created_at'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('visit_notes', $column),
                "visit_notes is missing `{$column}`."
            );
        }

        // NO updated_at. A note that can be edited is a note that can be made
        // to say something it did not say.
        $this->assertFalse(Schema::hasColumn('visit_notes', 'updated_at'));
        $this->assertNull(VisitNote::UPDATED_AT);
    }

    public function test_a_visit_note_resolves_its_visit_its_project_and_its_author(): void
    {
        $project = $this->project();
        $visit   = $this->returnedSurveyVisit($project);
        $author  = $this->user('Priya Mistry');

        $note = VisitNote::create([
            'project_id' => $project->id,
            'visit_id'   => $visit->id,
            'user_id'    => $author->id,
            'body'       => 'Cable route photo is missing for the second floor.',
        ]);

        $this->assertSame($project->id, $note->project->id);
        $this->assertSame($visit->id, $note->visit->id);
        $this->assertSame('Priya Mistry', $note->author->name);
        $this->assertSame('Priya Mistry', $note->actor_name);
        $this->assertNotNull($note->created_at);
    }

    public function test_a_note_whose_author_is_gone_reads_system_exactly_as_the_feed_does(): void
    {
        $project = $this->project();
        $visit   = $this->returnedSurveyVisit($project);

        $note = VisitNote::create([
            'project_id' => $project->id,
            'visit_id'   => $visit->id,
            'user_id'    => null,
            'body'       => 'Raised before the account was closed.',
        ]);

        $this->assertSame('System', $note->actor_name);
    }

    public function test_a_note_outlives_the_visit_it_annotates(): void
    {
        $project = $this->project();
        $visit   = $this->returnedSurveyVisit($project);

        $note = VisitNote::create([
            'project_id' => $project->id,
            'visit_id'   => $visit->id,
            'user_id'    => $this->user()->id,
            'body'       => 'The office said this, and the office still said it.',
        ]);

        $visit->delete();

        $note->refresh();

        // nullOnDelete, exactly as `snags.visit_id` is: what the office
        // observed does not stop having been observed because the visit row
        // went.
        $this->assertNull($note->visit_id);
        $this->assertSame('The office said this, and the office still said it.', $note->body);
        $this->assertSame($project->id, $note->project_id);
    }

    public function test_a_visit_note_has_no_update_path_and_no_delete_path_anywhere_in_app(): void
    {
        $this->assertNull(VisitNote::UPDATED_AT);

        $offenders = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (! str_contains($source, 'VisitNote')) {
                continue;
            }

            // A note-shaped variable being updated or deleted, and a
            // VisitNote query chain ending in either — the two shapes an
            // "edit the note" feature would actually take.
            if (preg_match('/\$[A-Za-z_]*[Nn]ote[A-Za-z_]*\s*->\s*(update|delete|forceDelete)\s*\(/', $source)) {
                $offenders[] = $file->getPathname().' (a note variable is updated or deleted)';
            }

            if (preg_match('/VisitNote::[^;]{0,400}->\s*(update|delete|forceDelete)\s*\(/s', $source)) {
                $offenders[] = $file->getPathname().' (a VisitNote query is updated or deleted)';
            }
        }

        $this->assertSame([], $offenders, 'visit_notes is append-only: nothing in app/ may edit or remove a note.');

        // Nor may a route offer one.
        foreach (Route::getRoutes() as $route) {
            if (! str_contains($route->uri(), 'notes')) {
                continue;
            }

            $this->assertSame(
                [],
                array_values(array_intersect($route->methods(), ['PUT', 'PATCH', 'DELETE'])),
                "An edit or delete route exists for notes: {$route->uri()}"
            );
        }
    }

    public function test_writing_a_note_moves_nothing_the_engineer_captured(): void
    {
        $project = $this->project();
        $visit   = $this->returnedSurveyVisit($project);

        /** @var SiteSurvey $survey */
        $survey = SiteSurvey::findOrFail($visit->source_id);

        $before = $this->engineerBytes($survey);
        $counts = [
            'site_surveys'       => DB::table('site_surveys')->count(),
            'worksheets'         => DB::table('worksheets')->count(),
            'worksheet_signoffs' => DB::table('worksheet_signoffs')->count(),
            'visits'             => DB::table('visits')->count(),
        ];

        VisitNote::create([
            'project_id' => $project->id,
            'visit_id'   => $visit->id,
            'user_id'    => $this->user()->id,
            'body'       => 'The office reading of this return.',
        ]);

        // THIS COMPARISON IS D-02, EXECUTABLE.
        $this->assertSame($before, $this->engineerBytes($survey));
        $this->assertSame('A pre-existing office review note.', $survey->refresh()->office_review_notes);

        foreach ($counts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "A note changed `{$table}`.");
        }
    }
}
