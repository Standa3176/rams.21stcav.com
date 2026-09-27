<?php

namespace Tests\Unit\Cockpit;

use App\Models\Project;
use App\Models\SiteSurvey;
use App\Support\Cockpit\CockpitDocumentFormPresenter;
use App\Support\Cockpit\CockpitModulePresenter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase 46.2, Plan 46.2-04, Task 2 — THE DC-04 PROOF.
 *
 * 46.2-CONTEXT.md D-03: "A field the generator ignores is a field that teaches
 * a PM to fill in noise." These tests are the EXECUTABLE form of that sentence.
 * Every field in `DOCUMENT_FIELD_MAP` names a file and a symbol, and
 * `test_every_mapped_field_symbol_is_found_in_its_named_generator()` greps the
 * file for the symbol. A refactor that renames a generator's field trips that
 * test instead of silently shipping a form that writes into nothing.
 *
 * No `RefreshDatabase`: every test below reads the const map, the route table or
 * an UNSAVED `Project`, so none of them touches the database.
 * NEVER run `artisan migrate:fresh --env=testing` (see gate-46.ps1).
 */
class CockpitDocumentFormPresenterTest extends TestCase
{
    /** @var array<string, string> path => contents, read once per path. */
    private array $fileCache = [];

    /**
     * Every field in the map, flattened, with a human-locatable label.
     *
     * @return array<int, array{document: string, legend: string, field: array<string, mixed>}>
     */
    private function allFields(): array
    {
        $flat = [];

        foreach (CockpitDocumentFormPresenter::documentFieldMap() as $documentKey => $document) {
            foreach ($document['groups'] as $group) {
                foreach ($group['fields'] as $field) {
                    $flat[] = [
                        'document' => $documentKey,
                        'legend'   => $group['legend'],
                        'field'    => $field,
                    ];
                }
            }
        }

        return $flat;
    }

    private function contents(string $path): string
    {
        return $this->fileCache[$path] ??= (string) file_get_contents(base_path($path));
    }

    public function test_every_mapped_field_names_a_file_that_exists(): void
    {
        foreach ($this->allFields() as $entry) {
            $field = $entry['field'];

            $this->assertArrayHasKey(
                'consumer',
                $field,
                "Field {$entry['document']}.{$field['key']} declares no consumer — DC-04 requires one.",
            );

            $this->assertFileExists(
                base_path($field['consumer']['file']),
                "Field {$entry['document']}.{$field['key']} names a consumer file that does not exist: {$field['consumer']['file']}",
            );
        }
    }

    /**
     * THE LOAD-BEARING TEST. A field may exist only because a real generator
     * reads it. If this goes red, the correct fix is to re-derive the field
     * against the generator — NOT to loosen the symbol until it matches.
     */
    public function test_every_mapped_field_symbol_is_found_in_its_named_generator(): void
    {
        foreach ($this->allFields() as $entry) {
            $field  = $entry['field'];
            $file   = $field['consumer']['file'];
            $symbol = $field['consumer']['symbol'];

            $this->assertStringContainsString(
                $symbol,
                $this->contents($file),
                "Field {$entry['document']}.{$field['key']} claims {$file} consumes `{$symbol}`, and it does not. "
                .'Either the generator renamed the field (re-derive it) or the field consumes nothing (remove it).',
            );
        }
    }

    /**
     * `also_target` GETS THE SAME GATE AS `target`. The site survey's `Visit
     * date` writes TWO columns, and the second one is no less a claim about a
     * generator than the first. Without this, a second target could be admitted
     * with no reader — exactly the D-03 failure the first target cannot have.
     */
    public function test_every_also_consumer_symbol_is_found_in_its_named_generator(): void
    {
        $checked = 0;

        foreach ($this->allFields() as $entry) {
            $field = $entry['field'];

            if (! array_key_exists('also_target', $field)) {
                $this->assertArrayNotHasKey(
                    'also_consumer',
                    $field,
                    "Field {$entry['document']}.{$field['key']} names a second consumer with no second target.",
                );

                continue;
            }

            $this->assertArrayHasKey(
                'also_consumer',
                $field,
                "Field {$entry['document']}.{$field['key']} writes a second target and declares no consumer for "
                .'it — DC-04 applies to both targets.',
            );

            $this->assertStringContainsString('.', $field['also_target'], 'A target is `prefix.leaf`.');

            $this->assertFileExists(base_path($field['also_consumer']['file']));

            $this->assertStringContainsString(
                $field['also_consumer']['symbol'],
                $this->contents($field['also_consumer']['file']),
                "Field {$entry['document']}.{$field['key']} claims {$field['also_consumer']['file']} consumes "
                ."`{$field['also_consumer']['symbol']}`, and it does not.",
            );

            $checked++;
        }

        $this->assertSame(
            1,
            $checked,
            'This proof checked '.$checked.' second target(s). Exactly one exists today: the site survey'
            ."'s `Visit date`, which also writes `survey.survey_date`. A second one must be a deliberate edit here.",
        );
    }

    /**
     * ONE DATE, TWO TARGETS — AND THE SECOND QUESTION IS GONE (2026-09-27,
     * item 1).
     *
     * The form asked `Survey date` AND `Visit date` side by side. For a creation
     * that makes both rows in one action they are the same day, and the user
     * could not tell them apart. `Visit date` survived, because it names the
     * real-world event the PM is arranging.
     *
     * BOTH HALVES ARE ASSERTED: the second question is gone AND the column it
     * fed is still written. Asserting only the first would pass just as well if
     * `survey.survey_date` had been abandoned, which would empty the Word
     * document's own date.
     */
    public function test_the_survey_date_question_is_gone_and_its_column_is_still_written(): void
    {
        $keys    = array_map(fn (array $e): string => $e['field']['key'], $this->allFields());
        $targets = array_map(fn (array $e): string => $e['field']['target'], $this->allFields());

        $this->assertNotContains(
            'survey_date',
            $keys,
            'The `Survey date` question is back. One date is asked; it writes both columns.',
        );

        $visitDate = null;

        foreach ($this->allFields() as $entry) {
            if ($entry['field']['key'] === 'visit_scheduled_date') {
                $visitDate = $entry['field'];
            }
        }

        $this->assertNotNull($visitDate, 'The one surviving date field is missing entirely.');
        $this->assertSame('Visit date', $visitDate['label'], 'The label that survived is `Visit date`.');
        $this->assertSame('visit.scheduled_date', $visitDate['target']);
        $this->assertSame(
            'survey.survey_date',
            $visitDate['also_target'],
            'The one date must STILL write `survey.survey_date`, or the Word document loses its date.',
        );

        // `survey.survey_date` reaches the map through the SECOND target and
        // through no primary one, so nothing writes it from two answers.
        $this->assertNotContains('survey.survey_date', $targets);
    }

    /**
     * SURVEYOR BECAME SURVEY ENGINEER (2026-09-27, item 3). A LABEL ONLY — the
     * key, the target and the consumer are untouched, so no stored value and no
     * generator moved.
     */
    public function test_the_surveyor_field_is_labelled_survey_engineer_and_nothing_else_moved(): void
    {
        $surveyor = null;

        foreach ($this->allFields() as $entry) {
            if ($entry['field']['key'] === 'surveyor_name') {
                $surveyor = $entry['field'];
            }
        }

        $this->assertNotNull($surveyor, 'The surveyor field left the map.');
        $this->assertSame('Survey Engineer', $surveyor['label']);
        $this->assertSame('survey.surveyor_name', $surveyor['target'], 'The COLUMN did not get renamed.');
        $this->assertSame('$survey->surveyor_name', $surveyor['consumer']['symbol']);
    }

    /**
     * THE MAP'S SECOND DELIBERATE OMISSION, ASSERTED BY NAME.
     *
     * On 2026-09-27 the user asked for a "time of visit" beside the visit date.
     * Three candidates were grepped and all three fail D-03:
     *   • `visits` has NO time column at all — `scheduled_date` is a DATE
     *     (`2026_09_19_140000_create_visits_table.php:70`, cast `'date'`).
     *   • `site_surveys.visit_time` EXISTS but its only readers are the legacy
     *     create/edit Blades that WRITE it. No DOCX builder, no survey PDF
     *     Blade, no engineer link and no `SurveyCarryForward` reads it.
     *   • `programme.planned_start_time` is mapped already, but it is the RAMS
     *     INSTALL programme's field, not this visit's.
     * A field here would teach a PM to type into something no output renders.
     * NOT ADMITTED — and admissible only once a consumer exists.
     */
    public function test_visit_time_is_not_a_field(): void
    {
        foreach ($this->allFields() as $entry) {
            $this->assertNotSame(
                'visit_time',
                $entry['field']['key'],
                'visit_time is excluded on purpose: no generator reads site_surveys.visit_time.',
            );
            $this->assertNotSame('survey.visit_time', $entry['field']['target']);
            $this->assertNotSame('visit.visit_time', $entry['field']['target']);
            $this->assertNotSame('survey.visit_time', $entry['field']['also_target'] ?? null);
        }

        // NON-VACUITY: the column really does exist, so the omission is a ruling
        // about a real candidate rather than a name that never existed.
        $this->assertContains(
            'visit_time',
            (new SiteSurvey())->getFillable(),
            'site_surveys.visit_time has gone, so this omission no longer describes anything.',
        );
    }

    /**
     * PARKING IS A CLOSED CHOICE, AND WHAT IT STORES IS WHAT FOUR READERS PRINT
     * (2026-09-27, item 5).
     *
     * `parking_restraints` is a STRING column and its four readers — the Word
     * document, the survey PDF, the engineer link and the carry-forward — render
     * it as prose with no code-to-label map anywhere. So the OPTION VALUE IS THE
     * SENTENCE. Storing `onsite` would print `onsite` to an engineer on site.
     */
    public function test_parking_is_three_radios_storing_the_sentence_they_show(): void
    {
        $parking = null;

        foreach ($this->allFields() as $entry) {
            if ($entry['field']['key'] === 'parking_restraints') {
                $parking = $entry['field'];
            }
        }

        $this->assertNotNull($parking, 'The parking field left the map.');
        $this->assertSame(CockpitDocumentFormPresenter::TYPE_RADIO, $parking['type'], 'Free text became RADIOS.');

        $this->assertSame(
            ['Parking onsite', 'No parking', 'Unknown'],
            array_keys($parking['options']),
            'The user asked for exactly these three, in this order.',
        );

        foreach ($parking['options'] as $value => $label) {
            $this->assertSame(
                $value,
                $label,
                "Parking option [{$value}] stores something other than the words it shows. All four readers "
                .'print this column raw, so a code would reach an engineer on site.',
            );
        }

        $this->assertContains(
            'in:Parking onsite,No parking,Unknown',
            $parking['rules'],
            'The stored set must be closed server-side too, or a hand-crafted POST writes free text.',
        );
    }

    public function test_the_document_keys_match_the_module_keys_exactly(): void
    {
        $documents = array_keys(CockpitDocumentFormPresenter::documentFieldMap());
        $modules   = array_keys(CockpitModulePresenter::moduleMap());

        sort($documents);
        sort($modules);

        // Set equality, both ways: a fifth module can never render a panel with
        // no fields, and a fifth field row can never exist with no module.
        $this->assertSame($modules, $documents);
        $this->assertCount(4, $documents);
    }

    /**
     * `CockpitReadOnlyFenceTest::FORBIDDEN_MARKUP` still bans `<select`, and
     * Plan 46.2-03 re-took that ruling, so the map has no `select` type.
     *
     * THE PROCEDURE, not the prohibition: if a document form genuinely needs a
     * dropdown, LIFT THE FENCE ENTRY BY NAME in the commit that ships it — as
     * 46-04 and 46.1-04 did. Never delete a fence entry to make a change fit,
     * and never decide it in the map alone.
     */
    public function test_no_field_declares_a_select(): void
    {
        foreach ($this->allFields() as $entry) {
            $this->assertNotSame(
                'select',
                $entry['field']['type'],
                "Field {$entry['document']}.{$entry['field']['key']} declares type `select`; the cockpit fence bans `<select`.",
            );
        }
    }

    public function test_every_field_declares_validation_rules(): void
    {
        foreach ($this->allFields() as $entry) {
            $field = $entry['field'];

            $this->assertArrayHasKey('rules', $field, "Field {$entry['document']}.{$field['key']} declares no rules.");
            $this->assertIsArray($field['rules']);
            $this->assertNotEmpty(
                $field['rules'],
                "Field {$entry['document']}.{$field['key']} has empty rules — Plan 46.2-05 would ship an unvalidated input by omission.",
            );
        }
    }

    /**
     * The formats must match `46.2-FORMAT-INVENTORY.md` exactly, and the ONE
     * null cell must stay exactly `worksheet.pdf` (DC-07, NOT DELIVERED: there
     * is no worksheet PDF Blade, and `worksheets.engineer-report-pdf` is a
     * different document that 404s for a PM with no engineer activity).
     * Closing the gap later is therefore a deliberate edit HERE.
     */
    public function test_the_formats_match_the_format_inventory(): void
    {
        $nullCells = [];

        foreach (CockpitDocumentFormPresenter::documentFieldMap() as $documentKey => $document) {
            $this->assertSame(['word', 'pdf'], array_keys($document['formats']));

            foreach ($document['formats'] as $format => $routeName) {
                if ($routeName === null) {
                    $nullCells[] = "{$documentKey}.{$format}";

                    continue;
                }

                $this->assertTrue(
                    Route::has($routeName),
                    "{$documentKey}.{$format} names route `{$routeName}`, which is not registered.",
                );
            }

            $this->assertTrue(
                Route::has($document['generate_route']),
                "{$documentKey} names generate_route `{$document['generate_route']}`, which is not registered.",
            );
        }

        $this->assertSame(['worksheet.pdf'], $nullCells);
    }

    /**
     * DC-08 as a BUDGET, the sibling of RV-08's one-anchor budget. A number, so
     * "simple" is not re-litigated per reviewer. The plan's rule when a group
     * overflows: SPLIT the group, never drop a field.
     */
    public function test_no_group_asks_more_than_six_questions(): void
    {
        foreach (CockpitDocumentFormPresenter::documentFieldMap() as $documentKey => $document) {
            foreach ($document['groups'] as $group) {
                $this->assertLessThanOrEqual(
                    6,
                    count($group['fields']),
                    "Group `{$group['legend']}` on {$documentKey} asks ".count($group['fields'])
                    .' questions. Split it — do not drop a field.',
                );
            }
        }
    }

    /**
     * THE MAP'S ONE DELIBERATE OMISSION, ASSERTED BY NAME RATHER THAN
     * REMEMBERED.
     *
     * `programme.planned_end_time` is READ by
     * `app/Services/Rams/RamsDisplayPatchService.php:153` but is NOT EMITTED by
     * `RamsReviewDataService::normaliseProgramme()`
     * (`app/Services/RamsReviewDataService.php:270-296` returns a fresh array
     * and that key is absent), so the value is ALWAYS ''. A panel field for it
     * would write a value `normalise()` silently discards on the next pass.
     *
     * Logged as D-46.2-04-01 and DELIBERATELY LEFT UNFIXED — fixing the
     * normaliser is not Plan 46.2-04's call. If it is ever fixed, this test is
     * the place the omission is reconsidered.
     */
    public function test_planned_end_time_is_not_a_field(): void
    {
        foreach ($this->allFields() as $entry) {
            $this->assertNotSame(
                'programme.planned_end_time',
                $entry['field']['target'],
                'planned_end_time is excluded on purpose (D-46.2-04-01): normaliseProgramme() never emits it.',
            );
            $this->assertNotSame('planned_end_time', $entry['field']['key']);
        }

        // Belt and braces: the string must not appear as a target anywhere.
        $targets = array_map(fn (array $e): string => $e['field']['target'], $this->allFields());
        $this->assertNotContains('programme.planned_end_time', $targets);
    }

    /**
     * D-04's JOB SUMMARY, AND IT IS ADMITTED ONLY BECAUSE A GENERATOR READS IT.
     *
     * The user asked for RAMS content to come from three places: project info,
     * the wizard's visit info, and *"userer enter job summary (if engered or
     * project data)"*. The map's rule (46.2 D-03) is that a field is
     * DISCOVERED, not invented, so the summary was grep-confirmed against the
     * working tree BEFORE the row was written:
     *
     *   app/Services/RamsBuilderService.php:119
     *       'works_summary' => $formData['works_description'] ?? '',
     *   app/Services/RamsBuilderService.php:898  — the same key, into the AI brief
     *   app/Services/RamsBuilderService.php:945  — $data['scope_of_works']
     *
     * The generic symbol gate above covers :119. THIS test pins the shape of
     * the row itself — the target especially, because `form_data.` vs
     * `reviewed_data.` is the difference between a value that reaches the
     * document and one `normalise()` discards on the next pass.
     */
    public function test_the_rams_job_summary_is_one_row_targeting_form_data_works_description(): void
    {
        $rams = CockpitDocumentFormPresenter::documentFieldMap()['rams'];

        $matches = [];

        foreach ($rams['groups'] as $group) {
            foreach ($group['fields'] as $field) {
                if ($field['key'] === 'job_summary') {
                    $matches[] = ['legend' => $group['legend'], 'field' => $field];
                }
            }
        }

        $this->assertCount(1, $matches, 'The job summary must be EXACTLY one row on the RAMS map.');

        $field = $matches[0]['field'];

        $this->assertSame('Job summary', $matches[0]['legend']);
        $this->assertSame(CockpitDocumentFormPresenter::TYPE_TEXTAREA, $field['type']);
        $this->assertSame(
            'form_data.works_description',
            $field['target'],
            'The job summary has no `reviewed_data` home: normaliseProject() is exactly eleven keys '
            .'and `works_description` is not one — the same documented exception `working_hours` already is.',
        );
        $this->assertSame('app/Services/RamsBuilderService.php', $field['consumer']['file']);
        $this->assertSame("\$formData['works_description']", $field['consumer']['symbol']);
        $this->assertSame(['nullable', 'string', 'max:5000'], $field['rules']);
    }

    /**
     * The job summary is ADDED, and nothing about the RAMS row is otherwise
     * moved. Its four non-group keys and its generate route are pinned here so
     * a later edit cannot quietly re-point RAMS at a new path — D-04's
     * *"RAMs will use existing RAM process"* as an assertion.
     */
    public function test_the_rams_row_still_names_the_existing_rams_process(): void
    {
        $rams = CockpitDocumentFormPresenter::documentFieldMap()['rams'];

        foreach (['generate_route', 'formats', 'intro', 'readiness'] as $key) {
            $this->assertArrayHasKey($key, $rams);
        }

        $this->assertSame('rams.from-project', $rams['generate_route']);
        $this->assertSame(['word' => 'rams.download', 'pdf' => 'rams.download-pdf'], $rams['formats']);
        $this->assertNull($rams['readiness']);
    }

    // -- Contract tests for the accessors ------------------------------------

    public function test_fields_for_an_unknown_document_is_empty_and_never_throws(): void
    {
        $presenter = new CockpitDocumentFormPresenter();

        $this->assertSame([], $presenter->fieldsFor('not_a_document'));
        $this->assertSame([], $presenter->fieldsFor(''));
        $this->assertNotSame([], $presenter->fieldsFor('rams'));
    }

    public function test_readiness_is_empty_for_every_document_without_a_readiness_source(): void
    {
        $presenter = new CockpitDocumentFormPresenter();
        $project   = new Project(); // unsaved — no database access on this path

        // Only the O&M delegates a readiness list; the other three, and an
        // unknown key, are empty.
        $this->assertSame([], $presenter->readiness($project, 'site_survey'));
        $this->assertSame([], $presenter->readiness($project, 'worksheet'));
        $this->assertSame([], $presenter->readiness($project, 'rams'));
        $this->assertSame([], $presenter->readiness($project, 'not_a_document'));
    }

    /**
     * LR-04, and T-46.2-10's mitigation at the map level: a `resource-list`
     * field draws NAMES from `LabourResource` and nothing else. No field in the
     * map targets or is keyed to a labour resource's email or phone.
     */
    public function test_resource_list_fields_name_a_labour_resource_role_and_nothing_private(): void
    {
        $roles = CockpitDocumentFormPresenter::resourceListRoles();

        foreach ($this->allFields() as $entry) {
            $field = $entry['field'];

            if ($field['type'] !== CockpitDocumentFormPresenter::TYPE_RESOURCE_LIST) {
                continue;
            }

            $this->assertArrayHasKey(
                $field['prefill'],
                $roles,
                "resource-list field {$entry['document']}.{$field['key']} names prefill `{$field['prefill']}`, which is not a known LabourResource role source.",
            );
        }

        $this->assertSame(['engineer', 'programmer'], array_values($roles));
    }
}
