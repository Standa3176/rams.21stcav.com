<?php

namespace Tests\Unit\Cockpit;

use App\Models\CableSchedule;
use App\Models\InstallProgramme;
use App\Models\OmManual;
use App\Models\Project;
use App\Models\ProjectActivityLog;
use App\Models\ProjectDrawing;
use App\Models\RamsDocument;
use App\Models\SiteSurvey;
use App\Models\User;
use App\Models\Worksheet;
use App\Support\Cockpit\CockpitPanelPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase 45, Plan 45-12, Task 1 — the side panel's Files, Notes and activity.
 *
 * D-13 is the user's own example of what this page is for: "under docs a user
 * can see all created project docs in one place (ie click and view etc)". So
 * the assertions below are weighted towards COMPLETENESS of the file list —
 * a document that exists and is not listed is the failure this tab exists to
 * prevent, and it is a worse failure than a row that carries no link.
 */
class CockpitPanelPresenterTest extends TestCase
{
    use RefreshDatabase;

    /** The nine module keys the cockpit renders (D-11 as amended by D-16). */
    private const NINE_MODULES = [
        'site_survey',
        'worksheet',
        'install_programme',
        'rams',
        'drawings',
        'om',
        'cable_schedule',
        'programming',
        'snagging',
    ];

    /** The three modules with no document relation anywhere in this codebase. */
    private const MODULES_WITHOUT_DOCUMENTS = [
        'install_programme',
        'programming',
        'snagging',
    ];

    private function presenter(): CockpitPanelPresenter
    {
        return new CockpitPanelPresenter();
    }

    private function project(array $overrides = []): Project
    {
        return Project::factory()->create(array_merge([
            'name'   => 'Panel Test Job',
            'status' => Project::STATUS_INSTALLING,
        ], $overrides));
    }

    private function survey(Project $project, array $overrides = []): SiteSurvey
    {
        return SiteSurvey::create(array_merge([
            'user_id'      => User::factory()->create()->id,
            'project_id'   => $project->id,
            'project_name' => $project->name,
        ], $overrides));
    }

    private function drawing(Project $project, array $overrides = []): ProjectDrawing
    {
        return ProjectDrawing::create(array_merge([
            'project_id' => $project->id,
            'kind'       => ProjectDrawing::KIND_SCHEMATIC,
            'status'     => ProjectDrawing::STATUS_READY,
            'filename'   => 'schematic-v1.pdf',
        ], $overrides));
    }

    private function log(Project $project, array $overrides = []): ProjectActivityLog
    {
        $log = ProjectActivityLog::create(array_merge([
            'project_id'  => $project->id,
            'user_id'     => User::factory()->create(['name' => 'Alice Hartley'])->id,
            'action'      => ProjectActivityLog::ACTION_DOCUMENT_ADDED,
            'description' => 'added the RAMS document',
        ], $overrides));

        if (array_key_exists('created_at', $overrides)) {
            // created_at is not fillable on an append-only model.
            $log->forceFill(['created_at' => $overrides['created_at']])->saveQuietly();
        }

        return $log->refresh();
    }

    // ── files() ───────────────────────────────────────────────────────────

    public function test_files_lists_every_rams_document_the_project_holds(): void
    {
        $project = $this->project();

        RamsDocument::factory()->count(3)->create([
            'project_id' => $project->id,
            'filename'   => 'method-statement.docx',
        ]);

        $files = $this->presenter()->files($project->fresh(), 'rams');

        $this->assertCount(3, $files, 'Every RAMS document must reach the Files tab — D-13 is a library, not a sample.');
        $this->assertSame('method-statement.docx', $files->first()['name']);
        $this->assertNotNull($files->first()['produced_at']);
        $this->assertNotSame('', $files->first()['status']);
    }

    public function test_a_rams_document_carries_a_resolved_view_route(): void
    {
        $project = $this->project();
        $doc     = RamsDocument::factory()->create(['project_id' => $project->id]);

        $file = $this->presenter()->files($project->fresh(), 'rams')->first();

        $this->assertSame(route('rams.review', $doc), $file['route']);
    }

    public function test_every_document_module_lists_its_own_documents(): void
    {
        $project = $this->project();

        RamsDocument::factory()->create(['project_id' => $project->id]);
        OmManual::factory()->create(['project_id' => $project->id]);
        CableSchedule::factory()->create(['project_id' => $project->id]);
        Worksheet::factory()->create(['project_id' => $project->id]);
        $this->survey($project);
        $this->drawing($project);

        $presenter = $this->presenter();
        $project   = $project->fresh();

        foreach (['rams', 'om', 'cable_schedule', 'worksheet', 'site_survey', 'drawings'] as $module) {
            $this->assertCount(
                1,
                $presenter->files($project, $module),
                "The {$module} module holds one document and must list it."
            );
        }
    }

    public function test_a_module_with_no_document_relation_returns_an_empty_collection(): void
    {
        $project = $this->project();

        foreach (self::MODULES_WITHOUT_DOCUMENTS as $module) {
            $this->assertTrue(
                $this->presenter()->files($project, $module)->isEmpty(),
                "{$module} has no document relation, so its file list is empty rather than invented."
            );
        }
    }

    public function test_an_unknown_module_key_returns_an_empty_collection_and_does_not_fatal(): void
    {
        $this->assertTrue($this->presenter()->files($this->project(), 'not_a_module')->isEmpty());
    }

    public function test_soft_deleted_documents_are_excluded(): void
    {
        $project = $this->project();

        RamsDocument::factory()->create(['project_id' => $project->id]);
        RamsDocument::factory()->create(['project_id' => $project->id])->delete();

        $this->assertCount(1, $this->presenter()->files($project->fresh(), 'rams'));
    }

    public function test_a_document_with_no_filename_falls_back_to_its_type_label(): void
    {
        $project = $this->project();

        CableSchedule::factory()->create([
            'project_id'      => $project->id,
            'source_filename' => null,
        ]);

        $this->assertSame('Cable schedule', $this->presenter()->files($project->fresh(), 'cable_schedule')->first()['name']);
    }

    public function test_a_failed_document_never_reads_as_an_error(): void
    {
        $project = $this->project();

        CableSchedule::factory()->create([
            'project_id' => $project->id,
            'status'     => CableSchedule::STATUS_FAILED,
        ]);

        $status = $this->presenter()->files($project->fresh(), 'cable_schedule')->first()['status'];

        $this->assertSame('Could not be produced', $status);
        $this->assertStringNotContainsStringIgnoringCase('error', $status);
    }

    /**
     * T-45-12-03. Every mapped route name must exist TODAY, so a rename is
     * caught here rather than by a PM meeting a blank page.
     */
    public function test_every_mapped_view_route_name_exists(): void
    {
        $names = CockpitPanelPresenter::viewRouteNames();

        $this->assertNotEmpty($names, 'The route map is empty — the Files tab would link to nothing.');

        foreach ($names as $module => $name) {
            $this->assertTrue(
                Route::has($name),
                "The Files tab maps {$module} to the route `{$name}`, which no longer exists."
            );
        }
    }

    /**
     * The degradation path, exercised rather than assumed: with the named
     * route gone, the row must still be LISTED, with a null route — never
     * omitted and never a RouteNotFoundException that blanks the page.
     */
    public function test_a_document_whose_route_is_gone_is_still_listed_without_a_link(): void
    {
        $project = $this->project();
        RamsDocument::factory()->create(['project_id' => $project->id]);
        $project = $project->fresh();

        Route::setRoutes(new RouteCollection());

        $files = $this->presenter()->files($project, 'rams');

        $this->assertCount(1, $files, 'A document with no route is listed, not dropped.');
        $this->assertNull($files->first()['route']);
        $this->assertNotSame('', $files->first()['name']);
    }

    // ── notes() ───────────────────────────────────────────────────────────

    public function test_notes_come_from_the_modules_own_note_field(): void
    {
        $project = $this->project();

        $schedule = CableSchedule::factory()->create(['project_id' => $project->id]);
        $schedule->forceFill(['notes' => 'Cores re-used from the old rack.'])->save();

        $notes = $this->presenter()->notes($project->fresh(), 'cable_schedule');

        $this->assertCount(1, $notes);
        $this->assertSame('Cores re-used from the old rack.', $notes->first()['text']);
    }

    public function test_the_install_programme_and_survey_note_fields_are_read(): void
    {
        $project = $this->project();

        InstallProgramme::factory()->create([
            'project_id' => $project->id,
            'notes'      => 'Two engineers for the first week.',
        ]);

        $this->survey($project, ['general_notes' => 'Lift booked for Tuesday.']);

        $project = $project->fresh();

        $this->assertSame(
            'Two engineers for the first week.',
            $this->presenter()->notes($project, 'install_programme')->first()['text']
        );

        $this->assertSame(
            'Lift booked for Tuesday.',
            $this->presenter()->notes($project, 'site_survey')->first()['text']
        );
    }

    /**
     * The failure this guards against: Project::notes is a PROJECT-level
     * field, so using it as a fallback would print the same paragraph under
     * all nine modules and read as nine notes where there is one.
     */
    public function test_notes_never_fall_back_to_the_project_level_field(): void
    {
        $project = $this->project(['notes' => 'PROJECT LEVEL NOTE TEXT']);

        foreach (self::NINE_MODULES as $module) {
            foreach ($this->presenter()->notes($project, $module) as $note) {
                $this->assertStringNotContainsString('PROJECT LEVEL NOTE TEXT', $note['text']);
            }
        }
    }

    public function test_note_added_activity_entries_are_notes(): void
    {
        $project = $this->project();

        $this->log($project, [
            'action'      => ProjectActivityLog::ACTION_NOTE_ADDED,
            'description' => 'Client wants the rack moved one bay left.',
        ]);

        $notes = $this->presenter()->notes($project->fresh(), 'rams');

        $this->assertCount(1, $notes);
        $this->assertSame('Client wants the rack moved one bay left.', $notes->first()['text']);
        $this->assertSame('Alice Hartley', $notes->first()['source']);
    }

    public function test_a_non_note_activity_entry_is_not_a_note(): void
    {
        $project = $this->project();

        $this->log($project, [
            'action'      => ProjectActivityLog::ACTION_STATUS_CHANGED,
            'description' => 'moved the project to Installing',
        ]);

        $this->assertTrue($this->presenter()->notes($project->fresh(), 'rams')->isEmpty());
    }

    public function test_a_module_with_no_notes_returns_an_empty_collection(): void
    {
        $project = $this->project();

        foreach (self::NINE_MODULES as $module) {
            $this->assertTrue($this->presenter()->notes($project, $module)->isEmpty());
        }
    }

    // ── activity() ────────────────────────────────────────────────────────

    public function test_activity_returns_initials_actor_phrase_and_time_newest_first(): void
    {
        $project = $this->project();

        $this->log($project, ['description' => 'older entry', 'created_at' => now()->subDays(2)]);
        $this->log($project, ['description' => 'newer entry', 'created_at' => now()->subHour()]);

        $feed = $this->presenter()->activity($project->fresh());

        $this->assertCount(2, $feed);
        $this->assertSame('newer entry', $feed->first()['phrase']);
        $this->assertSame('Alice Hartley', $feed->first()['actor']);
        $this->assertSame('AH', $feed->first()['initials']);
        $this->assertNotNull($feed->first()['at']);
    }

    public function test_a_deleted_user_reads_as_system_rather_than_blank(): void
    {
        $project = $this->project();

        $this->log($project, ['user_id' => null]);

        $entry = $this->presenter()->activity($project->fresh())->first();

        $this->assertSame('System', $entry['actor']);
        $this->assertSame('S', $entry['initials']);
    }

    public function test_an_entry_with_no_description_falls_back_to_its_humanised_action(): void
    {
        $project = $this->project();

        $this->log($project, [
            'action'      => ProjectActivityLog::ACTION_PACKAGE_IMPORTED,
            'description' => '',
        ]);

        $this->assertSame('imported a package', $this->presenter()->activity($project->fresh())->first()['phrase']);
    }

    /**
     * An unrecognised action must surface AS ITSELF. Falling back to a generic
     * "updated" would quietly mislabel every action a later phase adds.
     */
    public function test_an_unknown_action_is_humanised_and_never_becomes_updated(): void
    {
        $project = $this->project();

        $this->log($project, ['action' => 'engineer_link_issued', 'description' => '']);

        $phrase = $this->presenter()->activity($project->fresh())->first()['phrase'];

        $this->assertSame('engineer link issued', $phrase);
        $this->assertNotSame('updated', $phrase);
    }

    public function test_activity_respects_its_limit(): void
    {
        $project = $this->project();

        foreach (range(1, 9) as $i) {
            $this->log($project, ['description' => "entry {$i}", 'created_at' => now()->subMinutes(10 - $i)]);
        }

        $this->assertCount(6, $this->presenter()->activity($project->fresh()));
        $this->assertCount(3, $this->presenter()->activity($project->fresh(), 3));
    }

    /**
     * RECORDED DECISION, not an oversight: `ProjectActivityLog` has no module
     * column, and guessing a module from `metadata` keys would silently drop
     * every entry that carries none. The feed is therefore project-wide, and
     * the proof at this level is structural — `activity()` takes NO module
     * parameter, so no module filtering is even expressible. The rendered
     * proof (the same feed under every `?module=`) lives in CockpitPanelTest.
     */
    public function test_the_feed_takes_no_module_parameter_so_it_cannot_be_filtered(): void
    {
        $method = new \ReflectionMethod(CockpitPanelPresenter::class, 'activity');

        $names = array_map(fn (\ReflectionParameter $p): string => $p->getName(), $method->getParameters());

        $this->assertSame(['project', 'limit'], $names, 'activity() is project-wide by design — it takes no module.');
    }

    public function test_an_empty_project_has_an_empty_feed(): void
    {
        $this->assertTrue($this->presenter()->activity($this->project())->isEmpty());
    }

    // ── Read-only ─────────────────────────────────────────────────────────

    public function test_calling_the_three_methods_writes_nothing(): void
    {
        $project = $this->project();

        RamsDocument::factory()->create(['project_id' => $project->id]);
        CableSchedule::factory()->create(['project_id' => $project->id]);
        $this->survey($project);
        $this->drawing($project);
        $this->log($project);

        $tables = ['rams_documents', 'cable_schedules', 'site_surveys', 'project_drawings', 'project_activity_logs', 'projects'];

        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->count();
        }

        $presenter = $this->presenter();
        $project   = $project->fresh();

        foreach (self::NINE_MODULES as $module) {
            $presenter->files($project, $module);
            $presenter->notes($project, $module);
        }
        $presenter->activity($project);

        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->count(), "Rendering the panel changed `{$table}`.");
        }
    }
}
