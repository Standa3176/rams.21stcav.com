<?php

namespace Tests\Feature\Cockpit;

use App\Models\Project;
use App\Models\ProjectActivityLog;
use App\Models\SiteSurvey;
use App\Models\Snag;
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

        // Nor may a route offer one. Scoped to the COCKPIT's note routes:
        // `install-tasks/{task}/notes` and `commissioning-items/{item}/notes`
        // are pre-existing PATCH routes on entirely different records, and
        // this fence is about office notes on a visit.
        foreach (Route::getRoutes() as $route) {
            if (! str_contains($route->uri(), 'cockpit') || ! str_contains($route->uri(), 'notes')) {
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

    // ── Task 2: the two actions ──────────────────────────────────────────

    private function note(Project $project, Visit $visit, array $payload = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user())
            ->post(
                route('projects.cockpit.visits.notes', ['project' => $project, 'visit' => $visit]),
                $payload + ['body' => 'Cable route photo is missing for the second floor.'],
            );
    }

    private function snag(Project $project, Visit $visit, array $payload = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user())
            ->post(
                route('projects.cockpit.visits.snags', ['project' => $project, 'visit' => $visit]),
                $payload + ['title' => 'Trunking not made good in the comms room'],
            );
    }

    public function test_adding_a_note_writes_one_note_and_one_activity_row(): void
    {
        $project = $this->project();
        $visit   = $this->returnedSurveyVisit($project);
        $pm      = $this->user('Priya Mistry');

        $this->note($project, $visit, ['body' => 'Please confirm the comms room key holder.'], $pm)
            ->assertStatus(302);

        $this->assertSame(1, VisitNote::count());

        $note = VisitNote::first();

        $this->assertSame('Please confirm the comms room key holder.', $note->body);
        $this->assertSame($visit->id, $note->visit_id);
        $this->assertSame($project->id, $note->project_id);
        $this->assertSame($pm->id, $note->user_id);

        $logs = ProjectActivityLog::where('action', ProjectActivityLog::ACTION_NOTE_ADDED)->get();

        $this->assertCount(1, $logs);
        $this->assertSame($pm->id, $logs->first()->user_id);
        $this->assertSame($note->id, $logs->first()->metadata['visit_note_id'] ?? null);

        // The PM's own words are NOT copied into the feed — the note lives in
        // exactly one place, the same rule 46-06 applied to a send-back reason.
        $this->assertStringNotContainsString('comms room key holder', (string) $logs->first()->description);
    }

    public function test_adding_a_note_through_the_endpoint_moves_nothing_the_engineer_captured(): void
    {
        $project = $this->project();
        $visit   = $this->returnedSurveyVisit($project);

        /** @var SiteSurvey $survey */
        $survey = SiteSurvey::findOrFail($visit->source_id);

        $before      = $this->engineerBytes($survey);
        $visitBefore = [
            'sent_at'    => (string) $visit->getRawOriginal('sent_at'),
            'updated_at' => (string) $visit->getRawOriginal('updated_at'),
        ];

        $this->note($project, $visit)->assertStatus(302);

        // THIS COMPARISON IS D-02, EXECUTABLE — AT THE HTTP BOUNDARY.
        $this->assertSame($before, $this->engineerBytes($survey));
        $this->assertSame('A pre-existing office review note.', $survey->refresh()->office_review_notes);

        $visit->refresh();
        $this->assertSame($visitBefore['sent_at'], (string) $visit->getRawOriginal('sent_at'));
        $this->assertSame($visitBefore['updated_at'], (string) $visit->getRawOriginal('updated_at'));
    }

    public function test_a_note_body_is_required_and_bounded(): void
    {
        $project = $this->project();
        $visit   = $this->returnedSurveyVisit($project);

        $this->note($project, $visit, ['body' => ''])->assertSessionHasErrors('body');
        $this->note($project, $visit, ['body' => 'no'])->assertSessionHasErrors('body');
        $this->note($project, $visit, ['body' => str_repeat('a', 4001)])->assertSessionHasErrors('body');

        $this->assertSame(0, VisitNote::count());
    }

    public function test_a_note_lands_the_pm_on_the_notes_tab_of_the_module_they_had_open(): void
    {
        $project = $this->project();
        $visit   = $this->returnedSurveyVisit($project);

        $target = (string) $this->note($project, $visit)->headers->get('Location');

        $this->assertStringContainsString('module=site_survey', $target);
        $this->assertStringContainsString('tab=notes', $target);
    }

    public function test_an_accepted_visit_may_still_be_annotated(): void
    {
        $project = $this->project();
        $visit   = Visit::factory()->accepted()->create(['project_id' => $project->id, 'type' => Visit::TYPE_INSTALL]);

        $this->note($project, $visit)->assertStatus(302);

        $this->assertSame(1, VisitNote::count());
    }

    public function test_a_planned_visit_cannot_be_annotated(): void
    {
        $project = $this->project();
        $visit   = Visit::factory()->planned()->create(['project_id' => $project->id, 'type' => Visit::TYPE_INSTALL]);

        $this->note($project, $visit)->assertStatus(422);

        $this->assertSame(0, VisitNote::count());
    }

    public function test_a_note_on_another_projects_visit_is_a_404_and_writes_nothing(): void
    {
        $mine    = $this->project();
        $theirs  = $this->project();
        $foreign = $this->returnedSurveyVisit($theirs);

        $this->note($mine, $foreign)->assertNotFound();

        $this->assertSame(0, VisitNote::count());
        $this->assertSame(0, ProjectActivityLog::where('action', ProjectActivityLog::ACTION_NOTE_ADDED)->count());
    }

    public function test_an_anonymous_caller_can_neither_annotate_nor_raise(): void
    {
        $project = $this->project();
        $visit   = $this->returnedSurveyVisit($project);

        $this->post(route('projects.cockpit.visits.notes', ['project' => $project, 'visit' => $visit]), [
            'body' => 'Not mine to write.',
        ])->assertStatus(302);

        $this->post(route('projects.cockpit.visits.snags', ['project' => $project, 'visit' => $visit]), [
            'title' => 'Not mine to raise.',
        ])->assertStatus(302);

        $this->assertSame(0, VisitNote::count());
        $this->assertSame(0, Snag::count());
    }

    public function test_raising_a_snag_creates_exactly_one_open_snag_linked_to_its_visit(): void
    {
        $project = $this->project();
        $visit   = $this->returnedSurveyVisit($project);
        $pm      = $this->user('Priya Mistry');

        $this->snag($project, $visit, [
            'title'     => 'Trunking not made good in the comms room',
            'detail'    => 'Second floor riser, above the door.',
            'room_name' => 'Comms room',
        ], $pm)->assertStatus(302);

        $this->assertSame(1, Snag::count());

        $snag = Snag::first();

        $this->assertSame('Trunking not made good in the comms room', $snag->title);
        $this->assertSame('Second floor riser, above the door.', $snag->detail);
        $this->assertSame('Comms room', $snag->room_name);
        $this->assertSame(Snag::STATUS_OPEN, $snag->status);
        $this->assertSame($visit->id, $snag->visit_id);
        $this->assertSame($project->id, $snag->project_id);
        $this->assertSame($pm->id, $snag->raised_by_user_id);

        $logs = ProjectActivityLog::where('action', ProjectActivityLog::ACTION_SNAG_RAISED)->get();

        $this->assertCount(1, $logs);
        $this->assertSame($snag->id, $logs->first()->metadata['snag_id'] ?? null);
        $this->assertSame($visit->id, $logs->first()->metadata['visit_id'] ?? null);
    }

    /**
     * D-03 AT THE HTTP BOUNDARY. 46-02 fenced the SCHEMA against the five
     * Phase 47 fields; this fences the REQUEST, so a posted field is never
     * the thing that makes somebody add the column.
     */
    public function test_the_phase_47_fields_are_ignored_when_a_snag_is_raised(): void
    {
        $phase47 = [
            'outcome'        => 'fixed',
            'parts'          => 'One 2m length of trunking',
            'parent_snag_id' => 99,
            'assigned_to'    => 42,
            'cost'           => 250.00,
            'resolved_at'    => '2026-09-20 10:00:00',
            'status'         => 'closed',
        ];

        foreach ($phase47 as $field => $value) {
            $project = $this->project();
            $visit   = $this->returnedSurveyVisit($project);

            $this->snag($project, $visit, [$field => $value])->assertStatus(302);

            $snag       = Snag::where('project_id', $project->id)->firstOrFail();
            $attributes = $snag->getAttributes();

            // A raised snag is always open — posting `status` does not change it.
            $this->assertSame(Snag::STATUS_OPEN, $snag->status, "Posting `{$field}` changed the snag's status.");

            if ($field !== 'status') {
                $this->assertArrayNotHasKey(
                    $field,
                    $attributes,
                    "Posting `{$field}` reached the snag record — D-03 says Phase 47 owns it."
                );
            }
        }

        // And the schema fence 46-02 set is still exactly where it was.
        foreach (['outcome', 'parts', 'parent_snag_id', 'resolved_at', 'assigned_to', 'cost'] as $column) {
            $this->assertFalse(Schema::hasColumn('snags', $column), "A Phase 47 column `{$column}` appeared on snags.");
        }
    }

    public function test_a_snag_title_is_required_and_bounded_and_its_optional_fields_are_capped(): void
    {
        $project = $this->project();
        $visit   = $this->returnedSurveyVisit($project);

        $this->snag($project, $visit, ['title' => ''])->assertSessionHasErrors('title');
        $this->snag($project, $visit, ['title' => 'no'])->assertSessionHasErrors('title');
        $this->snag($project, $visit, ['title' => str_repeat('a', 201)])->assertSessionHasErrors('title');
        $this->snag($project, $visit, ['detail' => str_repeat('a', 4001)])->assertSessionHasErrors('detail');
        $this->snag($project, $visit, ['room_name' => str_repeat('a', 201)])->assertSessionHasErrors('room_name');

        $this->assertSame(0, Snag::count());
    }

    public function test_a_snag_cannot_be_raised_on_an_accepted_or_a_planned_visit(): void
    {
        $project  = $this->project();
        $accepted = Visit::factory()->accepted()->create(['project_id' => $project->id, 'type' => Visit::TYPE_INSTALL]);
        $planned  = Visit::factory()->planned()->create(['project_id' => $project->id, 'type' => Visit::TYPE_INSTALL]);

        // A snag found after acceptance is Phase 47's register, not a
        // retroactive edit to a closed visit.
        $this->snag($project, $accepted)->assertStatus(422);
        $this->snag($project, $planned)->assertStatus(422);

        $this->assertSame(0, Snag::count());
    }

    public function test_a_snag_on_another_projects_visit_is_a_404_and_writes_nothing(): void
    {
        $mine    = $this->project();
        $theirs  = $this->project();
        $foreign = $this->returnedSurveyVisit($theirs);

        $this->snag($mine, $foreign)->assertNotFound();

        $this->assertSame(0, Snag::count());
    }

    public function test_the_note_and_snag_routes_are_gone_when_the_cockpit_flag_is_off(): void
    {
        config(['cockpit.enabled' => false]);

        $project = $this->project();
        $visit   = $this->returnedSurveyVisit($project);

        $this->note($project, $visit)->assertNotFound();
        $this->snag($project, $visit)->assertNotFound();

        $this->assertSame(0, VisitNote::count());
        $this->assertSame(0, Snag::count());
    }

    // ── Task 2: where the note shows ─────────────────────────────────────

    /** The rendered cockpit region, entity-decoded. */
    private function region(Project $project, array $query = [], ?User $user = null): string
    {
        $body = $this->actingAs($user ?? $this->user())
            ->get(route('projects.cockpit', ['project' => $project] + $query))
            ->assertOk()
            ->getContent();

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$body);
        libxml_clear_errors();

        $node = (new \DOMXPath($dom))
            ->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' cav-cockpit ')]")
            ->item(0);

        return $node === null ? '' : html_entity_decode($dom->saveHTML($node), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function test_the_notes_tab_lists_office_notes_newest_first_with_author_and_time(): void
    {
        $project = $this->project();
        $visit   = $this->returnedSurveyVisit($project);
        $pm      = $this->user('Priya Mistry');

        $this->travelTo(now()->setDate(2026, 8, 14)->setTime(16, 11));
        $this->note($project, $visit, ['body' => 'The older office note.'], $pm);

        $this->travelTo(now()->addDay());
        $this->note($project, $visit, ['body' => 'The newer office note.'], $pm);

        $this->travelBack();

        $notes = $this->region($project, ['module' => 'site_survey', 'tab' => 'notes']);

        $this->assertStringContainsString('The newer office note.', $notes);
        $this->assertStringContainsString('The older office note.', $notes);
        $this->assertStringContainsString('Priya Mistry', $notes);
        $this->assertStringContainsString('14 Aug 2026, 16:11', $notes);

        // Newest first.
        $this->assertLessThan(
            strpos($notes, 'The older office note.'),
            strpos($notes, 'The newer office note.'),
            'Office notes must read newest first.'
        );

        // Visibly the OFFICE's, so a reader can tell it from an engineer's.
        $this->assertStringContainsString('Office note', $notes);
    }

    public function test_an_office_note_is_listed_once_not_twice(): void
    {
        $project = $this->project();
        $visit   = $this->returnedSurveyVisit($project);

        $this->note($project, $visit, ['body' => 'Exactly once, please.']);

        $notes = $this->region($project, ['module' => 'site_survey', 'tab' => 'notes']);

        $this->assertSame(1, substr_count($notes, 'Exactly once, please.'));
    }

    public function test_an_office_note_shows_under_the_module_its_visit_belongs_to_and_no_other(): void
    {
        $project = $this->project();
        $visit   = $this->returnedSurveyVisit($project);

        $this->note($project, $visit, ['body' => 'Survey drawer only.']);

        $this->assertStringContainsString(
            'Survey drawer only.',
            $this->region($project, ['module' => 'site_survey', 'tab' => 'notes'])
        );

        $this->assertStringNotContainsString(
            'Survey drawer only.',
            $this->region($project, ['module' => 'worksheet', 'tab' => 'notes'])
        );
    }

    /**
     * THE WORKSHEET AND SURVEY LINKS ARE PAGES A CLIENT SIGNS. Office text
     * does not belong on them — 46-05's send-back banner is the ONE piece of
     * office copy deliberately shown there, and neither a note nor a snag
     * joins it.
     */
    public function test_neither_a_note_nor_a_snag_reaches_the_engineer_link(): void
    {
        $project = $this->project();
        $visit   = $this->returnedSurveyVisit($project);

        /** @var SiteSurvey $survey */
        $survey = SiteSurvey::findOrFail($visit->source_id);

        $this->note($project, $visit, ['body' => 'OFFICE-ONLY-NOTE-MARKER']);
        $this->snag($project, $visit, ['title' => 'OFFICE-ONLY-SNAG-MARKER']);

        $body = $this->get('/survey/'.$survey->refresh()->access_token)->assertOk()->getContent();

        $this->assertStringNotContainsString('OFFICE-ONLY-NOTE-MARKER', $body);
        $this->assertStringNotContainsString('OFFICE-ONLY-SNAG-MARKER', $body);
    }
}
