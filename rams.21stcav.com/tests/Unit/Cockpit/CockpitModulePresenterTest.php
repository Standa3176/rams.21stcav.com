<?php

namespace Tests\Unit\Cockpit;

use App\Models\CableSchedule;
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
 * its ABSENCE to the fact that no source exists.
 *
 * 46.2 D-01 (user ruling, 2026-09-22), Plan 46.2-01: FOUR module rows — Site
 * survey, Worksheet, RAMS, O&M manual. The cockpit is a document-creation tool
 * and the other five rows are gone, not greyed and not emptied.
 *
 * THIS REVERSES D-16 (user ruling, 2026-09-20: nine rows including Snagging),
 * whose load-bearing argument was that every `Visit::TYPE_*` reaches exactly
 * one module row, so no visit could sit in the database and appear on no
 * screen. That invariant is NOW FALSE by decision, and
 * `test_every_visit_type_maps_to_exactly_one_module()` was RETIRED BY NAME
 * below — not deleted to make a red test pass. Its two surviving halves,
 * `test_no_visit_type_reaches_two_module_rows()` and
 * `test_the_visit_types_with_no_module_row_are_exactly_three_and_named()`,
 * keep everything D-01 did not actually license.
 */
class CockpitModulePresenterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 46.2 D-01 — four rows, in render order. NARROWED BY NAME from
     * `NINE_MODULES` (site_survey, worksheet, install_programme, rams,
     * drawings, om, cable_schedule, programming, snagging) by Plan 46.2-01:
     * the five absent keys are removed from the cockpit entirely, and their
     * absence is asserted by
     * `test_the_removed_module_keys_render_no_row_and_report_no_progress()`
     * rather than left unstated.
     *
     * @var array<int, string>
     */
    private const FOUR_MODULES = [
        'site_survey',
        'worksheet',
        'rams',
        'om',
    ];

    /**
     * The five keys 46.2 D-01 removed from the cockpit. They REMAIN in
     * `ProjectDeliverable::ALL_KEYS` — that is the deliverables screen's
     * vocabulary, it stays nine, and this phase does not touch that screen.
     *
     * @var array<int, string>
     */
    private const REMOVED_MODULES = [
        'install_programme',
        'drawings',
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

    /**
     * RENAMED from `test_nine_modules_render_in_the_designed_order` by Plan
     * 46.2-01: 46.2 D-01 made the render order four keys long. The order is
     * still asserted EXACTLY, against a named constant.
     */
    public function test_four_modules_render_in_the_designed_order(): void
    {
        $keys = $this->presenter()->modules($this->project())->pluck('key')->all();

        $this->assertSame(self::FOUR_MODULES, $keys);
    }

    /**
     * RENAMED AND NARROWED from
     * `test_the_nine_module_keys_are_the_canonical_deliverable_vocabulary` by
     * Plan 46.2-01, per 46.2 D-01.
     *
     * The old form asserted EQUALITY with `ProjectDeliverable::ALL_KEYS`. That
     * equality is gone because the cockpit now names four of the nine — but
     * `ALL_KEYS` ITSELF STAYS NINE: it is the DELIVERABLES SCREEN's vocabulary,
     * not the cockpit's, and this phase does not touch that screen. So the
     * MEMBERSHIP half is kept: every rendered key must still be one of the
     * canonical nine, which is what catches a typo'd or invented key.
     */
    public function test_the_four_module_keys_are_a_named_subset_of_the_canonical_deliverable_vocabulary(): void
    {
        $keys = $this->presenter()->modules($this->project())->pluck('key')->sort()->values()->all();

        $this->assertSame(['om', 'rams', 'site_survey', 'worksheet'], $keys);

        // ALL_KEYS is still nine, and every cockpit key is a member of it.
        $this->assertCount(9, ProjectDeliverable::ALL_KEYS);

        foreach ($keys as $key) {
            $this->assertContains(
                $key,
                ProjectDeliverable::ALL_KEYS,
                "Module key '{$key}' is not part of the canonical deliverable vocabulary."
            );
        }
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

    // -- The visit-type invariant, retired and halved -------------------------

    /**
     * RETIRED: `test_every_visit_type_maps_to_exactly_one_module()`.
     *
     * 46.2 D-01 (Plan 46.2-01) made it IMPOSSIBLE, not merely inconvenient:
     * with four rows, commissioning, programming and snag visits reach no
     * module row at all. It was retired BY NAME with that decision cited, and
     * NOT deleted to make a red test pass — which is why both halves of it
     * that D-01 did not license are still asserted below:
     *
     *   - `test_no_visit_type_reaches_two_module_rows()` — a type owned by two
     *     rows is still a bug. D-01 removed rows; it did not permit overlap.
     *   - `test_the_visit_types_with_no_module_row_are_exactly_three_and_named()`
     *     — the anti-rot half, and D-16's real argument. The zero-owner set is
     *     an EXACT named allow-list, so a SEVENTH visit type added later, or a
     *     fourth type quietly losing its row, still fails loudly instead of
     *     rendering nowhere.
     *
     * The old test's third assertion — that a type's owning row is actually
     * RENDERED — survives inside the first of those two, so a row present in
     * the map but missing from `modules()` is still caught.
     */
    public function test_no_visit_type_reaches_two_module_rows(): void
    {
        $modules = $this->presenter()->modules($this->project());

        foreach (Visit::TYPES as $type) {
            $owners = $this->ownersOf($type);

            $this->assertLessThanOrEqual(
                1,
                count($owners),
                "Visit type '{$type}' reaches more than one module row: ".implode(', ', $owners)
            );

            foreach ($owners as $owner) {
                $this->assertNotNull(
                    $modules->firstWhere('key', $owner),
                    "Visit type '{$type}' maps to module '{$owner}', which is not rendered."
                );
            }
        }
    }

    public function test_the_visit_types_with_no_module_row_are_exactly_three_and_named(): void
    {
        $orphans = [];

        foreach (Visit::TYPES as $type) {
            if ($this->ownersOf($type) === []) {
                $orphans[] = $type;
            }
        }

        sort($orphans);

        $expected = [Visit::TYPE_COMMISSIONING, Visit::TYPE_PROGRAMMING, Visit::TYPE_SNAG];
        sort($expected);

        $this->assertSame(
            $expected,
            $orphans,
            'Exactly three visit types may reach no module row (46.2 D-01). Any other type '
            .'rendering nowhere is the "collected but never turned into work" failure D-16 named.'
        );
    }

    /**
     * The owners of one visit type, read off the map as data rather than a
     * hand-written list, so `Visit::TYPES` remains the source of truth.
     *
     * @return array<int, string>
     */
    private function ownersOf(string $type): array
    {
        $owners = [];

        foreach (CockpitModulePresenter::moduleMap() as $key => $definition) {
            if (in_array($type, $definition['visit_types'], true)) {
                $owners[] = $key;
            }
        }

        return $owners;
    }

    /**
     * REPLACES `test_a_snag_visit_reaches_the_snagging_row()`, retired by name:
     * 46.2 D-01 removed the Snagging row from the cockpit, so there is no row
     * left for a snag visit to reach. Phase 47 owns snags; the visit itself is
     * NOT deleted (46.2 D-02) and still exists in the database.
     *
     * The assertion is inverted rather than dropped, because "a snag visit
     * silently inflates another row's count" would be a real bug.
     */
    public function test_a_snag_visit_reaches_no_module_row_by_design(): void
    {
        $project = $this->project();

        $before = $this->presenter()->modules($project)->pluck('count', 'key')->all();

        Visit::factory()->create([
            'project_id' => $project->id,
            'type'       => Visit::TYPE_SNAG,
        ]);

        $modules = $this->presenter()->modules($project->fresh());

        $this->assertNull($modules->firstWhere('key', 'snagging'), 'The snagging row is gone (46.2 D-01).');
        $this->assertSame(
            $before,
            $modules->pluck('count', 'key')->all(),
            'A snag visit must not change any surviving row\'s count phrase.'
        );
    }

    /**
     * The five keys 46.2 D-01 removed render NO row at all — not a greyed one
     * and not an empty one — and `progress()` reports null for each, through
     * the presenter's existing `?? null` guard rather than a second one added
     * for them.
     *
     * This test also carries what two retired tests protected:
     *   - RETIRED `test_programming_reports_no_count_at_all()` — its subject was
     *     that no Programming count phrase may imply a file store that does not
     *     exist. With the row gone the page makes no claim at all, which is the
     *     same property enforced more strongly.
     *   - RETIRED `test_programme_and_commissioning_counts_install_tasks()` —
     *     `COUNT_TASKS` is now dormant (its only row is gone). The constant is
     *     kept in the presenter, so this test pins the absence of the row, not
     *     the absence of the mode.
     */
    public function test_the_removed_module_keys_render_no_row_and_report_no_progress(): void
    {
        $project = $this->project();
        Visit::factory()->create(['project_id' => $project->id, 'type' => Visit::TYPE_PROGRAMMING]);
        Visit::factory()->create(['project_id' => $project->id, 'type' => Visit::TYPE_COMMISSIONING]);

        $modules = $this->presenter()->modules($project->fresh());

        foreach (self::REMOVED_MODULES as $key) {
            $this->assertNull($modules->firstWhere('key', $key), "Module '{$key}' must render no row (46.2 D-01).");
            $this->assertNull($this->presenter()->progress($project->fresh(), $key));

            // Still canonical on the deliverables screen; only absent here.
            $this->assertContains($key, ProjectDeliverable::ALL_KEYS);
        }
    }

    // -- Chips are a pure translation ----------------------------------------

    public function test_chip_is_a_pure_translation_of_the_section_pip(): void
    {
        $translation = [
            'waiting'   => 'not-started',
            'attention' => 'in-progress',
            'done'      => 'on-file',
            ''          => 'not-started', // a null pip — no derivation at all
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

    /**
     * RENAMED AND RE-EXPECTED BY PLAN 46.3-01, CITING D-04 (REVISED 2026-09-26).
     *
     * WAS `test_a_visit_module_with_no_visits_reads_zero_visits()`, expecting
     * `'0 visits'`. The user asked for that phrase to go: on an empty project
     * it is pure noise. It is the ONLY existing unit assertion in this file
     * that the ruling changes — every non-zero phrase below, suffixes
     * included, is byte-identical to what it has always been, and that is
     * deliberate (see `visitPhrase()`'s early return for why).
     *
     * The empty string, not a space and not a zero: `module-row.blade.php:62`
     * hides the element with `@if (filled($module['count']))`, which is the
     * one and only suppression mechanism.
     *
     * Recorded as A-3 in 46.3-COUNT-LEDGER.md.
     */
    public function test_a_visit_module_with_no_visits_reads_nothing_at_all(): void
    {
        $this->assertSame('', $this->row($this->project(), 'site_survey')['count']);
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

        // A cable schedule still EXISTS as a record; it simply has no cockpit
        // row to be counted on. Created here so the two surviving document
        // rows are shown not to absorb it.
        CableSchedule::factory()->create(['project_id' => $project->id]);

        $fresh = $project->fresh();

        // Both surviving document rows, enumerated rather than sampled.
        $this->assertSame('2 documents', $this->row($fresh, 'rams')['count']);
        $this->assertSame('1 document', $this->row($fresh, 'om')['count']);

        // RETIRED by Plan 46.2-01, 46.2 D-01: the `cable_schedule` ('1 document')
        // and `drawings` ('0 documents') assertions had no row left to read.
        // Their absence is asserted in
        // `test_the_removed_module_keys_render_no_row_and_report_no_progress()`.
    }

    // RETIRED by Plan 46.2-01, 46.2 D-01:
    // `test_programme_and_commissioning_counts_install_tasks()` and
    // `test_programming_reports_no_count_at_all()`. Both read rows that D-01
    // removed. What each protected is carried, in absence form, by
    // `test_the_removed_module_keys_render_no_row_and_report_no_progress()`
    // above; neither was deleted to make a red test pass. `COUNT_TASKS` and
    // `COUNT_NONE` remain in the presenter, documented there as dormant.

    public function test_a_not_required_module_says_so_and_reads_not_started(): void
    {
        $project = $this->project();

        ProjectDeliverable::create([
            'project_id'      => $project->id,
            // REPOINTED by Plan 46.2-01 from KEY_CABLE_SCHEDULE (row removed by
            // 46.2 D-01) to KEY_OM. The subject is the not-required TREATMENT,
            // never that one particular row can be marked not required, so it
            // is read off a surviving document row instead.
            'deliverable_key' => ProjectDeliverable::KEY_OM,
            'state'           => ProjectDeliverable::STATE_NOT_REQUIRED,
        ]);

        // `Project::deliverableState()` returns null unless `deliverables` is
        // eager-loaded — its docblock at Project.php:481-484 makes that the
        // caller's job, and ProjectCockpitController::show() does it. The test
        // wires it the same way rather than making the presenter query.
        $fresh = $project->fresh();
        $fresh->loadMissing('deliverables');

        $row = $this->presenter()->modules($fresh)->firstWhere('key', 'om');

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
