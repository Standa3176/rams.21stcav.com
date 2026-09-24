<?php

namespace Tests\Unit\Cockpit;

use App\Models\Project;
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
