<?php

namespace Tests\Unit\Cockpit;

use App\Models\CableSchedule;
use App\Models\InstallProgramme;
use App\Models\InstallTask;
use App\Models\OmManual;
use App\Models\Project;
use App\Models\ProjectDeliverable;
use App\Models\RamsDocument;
use App\Models\Visit;
use App\Models\Worksheet;
use App\Support\Cockpit\CockpitModulePresenter;
use App\Support\Cockpit\CockpitSectionPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 45, Plan 45-10, Task 1 — the delivery cockpit's module rows.
 *
 * These tests exist to stop a plausible-looking number reaching a PM's screen.
 * Every assertion below pins a value to a source that already exists, or pins
 * its ABSENCE (Programming's count phrase) to the fact that no source exists.
 *
 * D-16 (user ruling, 2026-09-20): NINE module rows including Snagging. The
 * design image showed eight, but `Visit::TYPE_SNAG` exists, so with eight rows
 * a snag visit would sit in the database and appear on no screen — the exact
 * "collected but never turned into work" failure this milestone exists to fix.
 */
class CockpitModulePresenterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * D-11 as amended by D-16 — nine rows, in render order.
     *
     * @var array<int, string>
     */
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

    private function presenter(): CockpitModulePresenter
    {
        return new CockpitModulePresenter(new CockpitSectionPresenter());
    }

    private function project(array $overrides = []): Project
    {
        return Project::factory()->create(array_merge([
            'name'   => 'Module Row Test Job',
            'status' => Project::STATUS_INSTALLING,
        ], $overrides));
    }

    private function row(Project $project, string $key): array
    {
        return $this->presenter()->modules($project)->firstWhere('key', $key);
    }

    // -- Shape ---------------------------------------------------------------

    public function test_nine_modules_render_in_the_designed_order(): void
    {
        $keys = $this->presenter()->modules($this->project())->pluck('key')->all();

        $this->assertSame(self::NINE_MODULES, $keys);
    }

    public function test_the_nine_module_keys_are_the_canonical_deliverable_vocabulary(): void
    {
        $keys = $this->presenter()->modules($this->project())->pluck('key')->sort()->values()->all();
        $all  = collect(ProjectDeliverable::ALL_KEYS)->sort()->values()->all();

        $this->assertSame($all, $keys, 'The cockpit must name the same nine things the deliverables screen names.');
    }

    public function test_every_row_carries_the_design_keys(): void
    {
        foreach ($this->presenter()->modules($this->project()) as $row) {
            foreach (['key', 'title', 'description', 'icon', 'chip', 'count', 'section'] as $expected) {
                $this->assertArrayHasKey($expected, $row, "Row {$row['key']} is missing {$expected}.");
            }
        }
    }

    public function test_icons_are_keys_never_markup_or_colour(): void
    {
        foreach ($this->presenter()->modules($this->project()) as $row) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9-]*$/', $row['icon']);
        }
    }

    // -- The visit-type invariant (D-16's load-bearing argument) --------------

    public function test_every_visit_type_maps_to_exactly_one_module(): void
    {
        $modules = $this->presenter()->modules($this->project());

        foreach (Visit::TYPES as $type) {
            $owners = [];

            foreach (CockpitModulePresenter::moduleMap() as $key => $definition) {
                if (in_array($type, $definition['visit_types'], true)) {
                    $owners[] = $key;
                }
            }

            $this->assertCount(
                1,
                $owners,
                "Visit type '{$type}' must reach exactly one module row, got: ".implode(', ', $owners)
            );
            $this->assertNotNull(
                $modules->firstWhere('key', $owners[0]),
                "Visit type '{$type}' maps to module '{$owners[0]}', which is not rendered."
            );
        }
    }

    public function test_a_snag_visit_reaches_the_snagging_row(): void
    {
        $project = $this->project();
        Visit::factory()->create([
            'project_id' => $project->id,
            'type'       => Visit::TYPE_SNAG,
        ]);

        $row = $this->row($project->fresh(), 'snagging');

        $this->assertSame('1 visit', $row['count']);
    }

    // -- Chips are a pure translation ----------------------------------------

    public function test_chip_is_a_pure_translation_of_the_section_pip(): void
    {
        $translation = [
            'waiting'   => 'not-started',
            'attention' => 'in-progress',
            'done'      => 'on-file',
            ''          => 'not-started', // pip null — Programming has no derivation
        ];

        $project = $this->project();
        RamsDocument::factory()->create(['project_id' => $project->id]);

        foreach ($this->presenter()->modules($project->fresh()) as $row) {
            $pip = $row['section']['pip'] ?? '';
            $this->assertSame(
                $translation[$pip ?? ''],
                $row['chip'],
                "Row {$row['key']} re-derived its chip instead of translating its section pip."
            );
        }
    }

    public function test_chip_is_only_ever_one_of_the_three_design_states(): void
    {
        $project = $this->project();
        RamsDocument::factory()->create(['project_id' => $project->id]);

        foreach ($this->presenter()->modules($project->fresh()) as $row) {
            $this->assertContains($row['chip'], ['not-started', 'in-progress', 'on-file']);
        }
    }

    public function test_a_document_on_file_reports_the_on_file_chip(): void
    {
        $project = $this->project();
        RamsDocument::factory()->create(['project_id' => $project->id]);

        $this->assertSame('on-file', $this->row($project->fresh(), 'rams')['chip']);
    }

    // -- Counts --------------------------------------------------------------

    public function test_a_visit_module_with_no_visits_reads_zero_visits(): void
    {
        $this->assertSame('0 visits', $this->row($this->project(), 'site_survey')['count']);
    }

    public function test_a_visit_module_pluralises_its_count(): void
    {
        $project = $this->project();
        Visit::factory()->create(['project_id' => $project->id, 'type' => Visit::TYPE_SITE_SURVEY]);

        $this->assertSame('1 visit', $this->row($project->fresh(), 'site_survey')['count']);

        Visit::factory()->create(['project_id' => $project->id, 'type' => Visit::TYPE_SITE_SURVEY]);

        $this->assertSame('2 visits', $this->row($project->fresh(), 'site_survey')['count']);
    }

    // -- The at-rest disclosure, D-02 and D-04 -------------------------------

    /**
     * THE COUNT PHRASE IS THE PAGE'S AT-REST DISCLOSURE SLOT.
     *
     * Sketch 004 deleted the accordion, and with it the <summary> that used to
     * carry "3 visits · 2 reconstructed". With the panel closed a module row
     * now shows a chip and this phrase and nothing else, so if the phrase
     * omits the qualifier a PM who opens nothing is shown an INFERRED visit as
     * though it were recorded fact. That is the failure D-02 exists to
     * prevent, so these four tests are load-bearing, not cosmetic.
     */
    public function test_a_single_reconstructed_visit_is_disclosed_in_the_count_phrase(): void
    {
        $project   = $this->project();
        $worksheet = Worksheet::factory()->create(['project_id' => $project->id]);

        Visit::factory()->backfilledFromWorksheet($worksheet)->create([
            'project_id'     => $project->id,
            'scheduled_date' => '2026-09-02',
        ]);

        // Singular drops the numeral — a lone qualifier reads as a word, not a
        // sum, exactly as the section count has always phrased it.
        $this->assertSame('1 visit · reconstructed', $this->row($project->fresh(), 'worksheet')['count']);
    }

    public function test_several_reconstructed_visits_are_counted_in_the_count_phrase(): void
    {
        $project = $this->project();

        // A unique index on (source_type, source_id) means one source backs at
        // most one visit — reconstruct from two worksheets, not one twice.
        foreach (range(1, 2) as $n) {
            $worksheet = Worksheet::factory()->create(['project_id' => $project->id]);

            Visit::factory()->backfilledFromWorksheet($worksheet)->create([
                'project_id'     => $project->id,
                'scheduled_date' => '2026-09-0'.$n,
            ]);
        }

        Visit::factory()->create(['project_id' => $project->id, 'scheduled_date' => '2026-09-04']);

        $this->assertSame('3 visits · 2 reconstructed', $this->row($project->fresh(), 'worksheet')['count']);
    }

    public function test_a_superseded_visit_is_disclosed_in_the_count_phrase(): void
    {
        $project = $this->project();

        $this->supersededVisit($project, '2026-09-02');

        Visit::factory()->create(['project_id' => $project->id, 'scheduled_date' => '2026-09-03']);

        // Singular drops the numeral, plural carries it — the same rule the
        // reconstructed qualifier has always followed.
        $this->assertSame('2 visits · superseded', $this->row($project->fresh(), 'worksheet')['count']);

        $this->supersededVisit($project, '2026-09-04');

        $this->assertSame('3 visits · 2 superseded', $this->row($project->fresh(), 'worksheet')['count']);
    }

    public function test_a_module_carrying_both_qualifiers_discloses_both_reconstructed_first(): void
    {
        $project   = $this->project();
        $worksheet = Worksheet::factory()->create(['project_id' => $project->id]);
        $goneId    = $worksheet->id;
        $worksheet->delete();

        // One visit that is BOTH reconstructed and superseded: its source was
        // inferred from a worksheet that has since been soft-deleted.
        Visit::factory()->backfilledFromWorksheet()->create([
            'project_id'     => $project->id,
            'scheduled_date' => '2026-09-02',
            'source_id'      => $goneId,
        ]);

        $this->assertSame(
            '1 visit · reconstructed · superseded',
            $this->row($project->fresh(), 'worksheet')['count'],
            'Reconstructed is named first, as it is in the chip strip.'
        );
    }

    /**
     * The words are NOT written twice. The row and the section read the same
     * method, so the two can never drift into disagreeing about one project.
     */
    public function test_the_row_and_the_section_agree_about_the_same_visits(): void
    {
        $project   = $this->project();
        $worksheet = Worksheet::factory()->create(['project_id' => $project->id]);

        Visit::factory()->backfilledFromWorksheet($worksheet)->create([
            'project_id'     => $project->id,
            'scheduled_date' => '2026-09-02',
        ]);

        $fresh   = $project->fresh();
        $row     = $this->row($fresh, 'worksheet');
        $section = $row['section'];

        $this->assertSame($section['count'], $row['count']);
    }

    private function supersededVisit(Project $project, string $date): Visit
    {
        $worksheet = Worksheet::factory()->create(['project_id' => $project->id]);
        $goneId    = $worksheet->id;
        $worksheet->delete();

        return Visit::factory()->create([
            'project_id'     => $project->id,
            'type'           => Visit::TYPE_INSTALL,
            'scheduled_date' => $date,
            'source_type'    => Visit::SOURCE_WORKSHEET,
            'source_id'      => $goneId,
        ]);
    }

    public function test_first_fix_and_install_counts_both_of_its_visit_types(): void
    {
        $project = $this->project();
        Visit::factory()->create(['project_id' => $project->id, 'type' => Visit::TYPE_FIRST_FIX]);
        Visit::factory()->create(['project_id' => $project->id, 'type' => Visit::TYPE_INSTALL]);

        $this->assertSame('2 visits', $this->row($project->fresh(), 'worksheet')['count']);
    }

    public function test_document_modules_count_their_documents(): void
    {
        $project = $this->project();
        RamsDocument::factory()->count(2)->create(['project_id' => $project->id]);
        OmManual::factory()->create(['project_id' => $project->id]);
        CableSchedule::factory()->create(['project_id' => $project->id]);

        $fresh = $project->fresh();

        $this->assertSame('2 documents', $this->row($fresh, 'rams')['count']);
        $this->assertSame('1 document', $this->row($fresh, 'om')['count']);
        $this->assertSame('1 document', $this->row($fresh, 'cable_schedule')['count']);
        $this->assertSame('0 documents', $this->row($fresh, 'drawings')['count']);
    }

    public function test_programme_and_commissioning_counts_install_tasks(): void
    {
        $project = $this->project();

        $this->assertSame('0 tasks', $this->row($project, 'install_programme')['count']);

        $programme = InstallProgramme::factory()->create(['project_id' => $project->id]);
        InstallTask::factory()->count(3)->create(['install_programme_id' => $programme->id]);

        $this->assertSame('3 tasks', $this->row($project->fresh(), 'install_programme')['count']);
    }

    public function test_programming_reports_no_count_at_all(): void
    {
        $project = $this->project();
        Visit::factory()->create(['project_id' => $project->id, 'type' => Visit::TYPE_PROGRAMMING]);

        $row = $this->row($project->fresh(), 'programming');

        $this->assertSame('', $row['count'], 'There is no Programming store, so no count phrase may imply one.');
    }

    public function test_a_not_required_module_says_so_and_reads_not_started(): void
    {
        $project = $this->project();

        ProjectDeliverable::create([
            'project_id'      => $project->id,
            'deliverable_key' => ProjectDeliverable::KEY_CABLE_SCHEDULE,
            'state'           => ProjectDeliverable::STATE_NOT_REQUIRED,
        ]);

        // `Project::deliverableState()` returns null unless `deliverables` is
        // eager-loaded — its docblock at Project.php:481-484 makes that the
        // caller's job, and ProjectCockpitController::show() does it. The test
        // wires it the same way rather than making the presenter query.
        $fresh = $project->fresh();
        $fresh->loadMissing('deliverables');

        $row = $this->presenter()->modules($fresh)->firstWhere('key', 'cable_schedule');

        $this->assertSame('not-started', $row['chip']);
        $this->assertSame('Not required', $row['count']);
    }

    // -- Progress ------------------------------------------------------------

    public function test_progress_is_null_when_a_module_has_no_visits(): void
    {
        $project = $this->project();

        $this->assertNull($this->presenter()->progress($project, 'site_survey'));
        $this->assertNull($this->presenter()->progress($project, 'rams'));
    }

    public function test_progress_counts_completed_visits_against_all_visits(): void
    {
        $project = $this->project();
        Visit::factory()->create([
            'project_id' => $project->id,
            'type'       => Visit::TYPE_INSTALL,
            'status'     => Visit::STATUS_COMPLETED,
        ]);
        Visit::factory()->count(3)->create([
            'project_id' => $project->id,
            'type'       => Visit::TYPE_INSTALL,
            'status'     => Visit::STATUS_PLANNED,
        ]);

        $this->assertSame(
            ['completed' => 1, 'total' => 4, 'percent' => 25],
            $this->presenter()->progress($project->fresh(), 'worksheet')
        );
    }

    public function test_progress_is_null_for_an_unknown_module_key(): void
    {
        $this->assertNull($this->presenter()->progress($this->project(), 'quotes'));
    }

    // -- Read-only fence -----------------------------------------------------

    public function test_deriving_modules_writes_nothing(): void
    {
        $project = $this->project();
        Visit::factory()->create(['project_id' => $project->id, 'type' => Visit::TYPE_INSTALL]);
        RamsDocument::factory()->create(['project_id' => $project->id]);

        $tables = ['visits', 'site_surveys', 'worksheets', 'install_programmes', 'install_records', 'project_deliverables'];
        $before = collect($tables)->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()])->all();

        $presenter = $this->presenter();
        $presenter->modules($project->fresh());
        $presenter->progress($project->fresh(), 'worksheet');

        $after = collect($tables)->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()])->all();

        $this->assertSame($before, $after, 'Deriving a module row must write nothing.');
    }
}
