<?php

namespace Tests\Unit\Cockpit;

use App\Support\Cockpit\CockpitDocumentFormPresenter;
use App\Support\Cockpit\CockpitWizardPresenter;
use Tests\TestCase;

/**
 * Phase 46.5, Plan 46.5-01, Task 2 — THE STEP SPINE, PROVED BY ITERATION.
 *
 * 46.5-CONTEXT.md D-02 turns a long form into three short steps. The thing that
 * has made this page's last five content changes cheap is that its field set is
 * DATA and its Blade switches on field TYPE with no document name in it. So a
 * STEP IS A ROW ON THE MAP, and these tests are the executable form of that
 * sentence: they ITERATE `documentFieldMap()` rather than sampling a document,
 * so a group added later with no `step` key is a RED TEST and not a field that
 * silently stops being asked.
 *
 * ⚠️ AND THEY COUNT WHAT THEY MEASURED. The last defect the user found — an
 * open row that would not close — was missed by 387 tests because the assertion
 * that should have caught it only ever rendered the CLOSED page. Vacuous, not
 * wrong. A wizard has N states; a test that visits one proves nothing about the
 * others, so every loop below asserts HOW MANY states it visited.
 *
 * No `RefreshDatabase`: every test here reads the const map or the presenter's
 * own source. None of them touches the database.
 * NEVER run `artisan migrate:fresh --env=testing` (see gate-46.ps1).
 */
class CockpitWizardPresenterTest extends TestCase
{
    private const SITE_SURVEY = 'site_survey';

    private const COMMS_ROOM_LEGEND = 'Comms room';

    /**
     * EVERY GROUP THE WIZARD DELIBERATELY DOES NOT ASK, BY NAME. Two, not one,
     * since 2026-09-27: the user asked for `Delivery routes`, `Distance from
     * base` and the travel notes to leave the OFFICE CREATION FORM, and they
     * left it the same way Comms room did — `step => null` — because
     * `SurveyCarryForward` carries them to the INSTALLING engineer and the
     * survey PDF, the Word document and the engineer link all render them. The
     * surveyor is still asked; the office PM is not.
     *
     * NAMED RATHER THAN DERIVED FROM `step === null`, on purpose. A third group
     * quietly acquiring `step => null` — which is how a field silently stops
     * being asked — must land as a RED TEST here, and it does:
     * `test_exactly_these_groups_are_on_no_step()` asserts set equality.
     *
     * @var list<string>
     */
    private const STEPLESS_LEGENDS = [
        'Comms room',
        'For the install that follows',
    ];

    private function presenter(): CockpitWizardPresenter
    {
        return new CockpitWizardPresenter();
    }

    /**
     * Every group of one document, flattened with its legend.
     *
     * @return array<int, array<string, mixed>>
     */
    private function mapGroups(string $documentKey): array
    {
        return CockpitDocumentFormPresenter::documentFieldMap()[$documentKey]['groups'] ?? [];
    }

    // ── D-02: the site survey's three steps are the user's own ───────────

    public function test_the_site_survey_has_exactly_three_steps_in_order_each_with_a_title(): void
    {
        $presenter = $this->presenter();

        $this->assertSame([1, 2, 3], $presenter->stepsFor(self::SITE_SURVEY));
        $this->assertSame(3, $presenter->stepCount(self::SITE_SURVEY));

        $titled = 0;

        foreach ($presenter->stepsFor(self::SITE_SURVEY) as $step) {
            $title = $presenter->stepTitle(self::SITE_SURVEY, $step);

            $this->assertIsString($title, "Step {$step} of the site survey has no title.");
            $this->assertNotSame('', trim((string) $title), "Step {$step} of the site survey has a blank title.");
            $titled++;
        }

        $this->assertSame(
            3,
            $titled,
            'This proof titled '.$titled.' of the site survey\'s 3 steps. A wizard has N states and a '
            .'test that visits one proves nothing about the others.',
        );
    }

    /**
     * THE O&M HAS NO WIZARD, AND IT MUST STILL RENDER EXACTLY AS IT DOES TODAY.
     * A document with no steps is not a document with a broken wizard.
     */
    public function test_a_document_with_no_steps_keeps_every_group_it_has_today(): void
    {
        $presenter = $this->presenter();

        $this->assertSame([], $presenter->stepsFor('om'));
        $this->assertSame(0, $presenter->stepCount('om'));
        $this->assertSame([], $presenter->groupsForStep('om', 1));

        // Non-vacuity: the O&M really does have groups, so "no steps" cannot
        // pass because there was nothing to step through.
        $this->assertGreaterThan(0, count($this->mapGroups('om')));
        $this->assertSame(
            count($this->mapGroups('om')),
            count((new CockpitDocumentFormPresenter())->fieldsFor('om')),
            'The O&M lost a group to a wizard it does not have.',
        );
    }

    public function test_each_site_survey_step_returns_only_its_own_groups_in_map_order(): void
    {
        $presenter = $this->presenter();

        $expectedPerStep = [];

        foreach ($this->mapGroups(self::SITE_SURVEY) as $group) {
            if ($group['step'] === null) {
                continue;
            }

            $expectedPerStep[$group['step']][] = $group['legend'];
        }

        $visited      = 0;
        $groupsSeen   = 0;
        $stepOfLegend = [];

        foreach ($presenter->stepsFor(self::SITE_SURVEY) as $step) {
            $legends = array_map(
                static fn (array $group): string => $group['legend'],
                $presenter->groupsForStep(self::SITE_SURVEY, $step),
            );

            $this->assertSame(
                $expectedPerStep[$step] ?? [],
                $legends,
                "Step {$step} returned its groups out of map order, or returned a group belonging to another step.",
            );

            foreach ($legends as $legend) {
                $this->assertArrayNotHasKey(
                    $legend,
                    $stepOfLegend,
                    "Group `{$legend}` is returned by more than one step — a group belongs to exactly one.",
                );
                $stepOfLegend[$legend] = $step;
                $groupsSeen++;
            }

            $visited++;
        }

        $this->assertSame(
            3,
            $visited,
            'This proof rendered '.$visited.' of the site survey\'s 3 steps and counted '.$groupsSeen
            .' groups across them. Assert the count, so a future change that collapses the states goes '
            .'red instead of quiet.',
        );
        $this->assertSame(
            count($this->mapGroups(self::SITE_SURVEY)) - count(self::STEPLESS_LEGENDS),
            $groupsSeen,
            'Every site-survey group except the '.count(self::STEPLESS_LEGENDS).' on no step ('
            .implode(', ', self::STEPLESS_LEGENDS).') must be reachable from some step.',
        );
    }

    // ── D-03 / GCW-04: Comms room is on NO step, for ANY step number ─────

    public function test_the_comms_room_group_is_returned_by_no_step_at_all(): void
    {
        $presenter = $this->presenter();

        // Non-vacuity first: the group really is on the map, so its absence
        // from every step cannot pass because it was never there.
        $legends = array_map(
            static fn (array $group): string => $group['legend'],
            $this->mapGroups(self::SITE_SURVEY),
        );

        $this->assertContains(
            self::COMMS_ROOM_LEGEND,
            $legends,
            'Comms room left the MAP. D-03 takes it off the office form only — it is still captured on '
            .'site, still in the Word document, and still on the survey to install carry-forward.',
        );

        $probed = 0;

        // Probed far past the real step count on purpose: a hand-typed step
        // number must not be a way back to a group the decision removed.
        foreach (range(0, 9) as $step) {
            foreach ($presenter->groupsForStep(self::SITE_SURVEY, $step) as $group) {
                $this->assertNotSame(
                    self::COMMS_ROOM_LEGEND,
                    $group['legend'],
                    "Comms room was returned by step {$step}.",
                );
            }

            $probed++;
        }

        $this->assertSame(10, $probed, 'This proof probed '.$probed.' step numbers, 0 through 9.');
    }

    /**
     * EXACTLY TWO GROUPS ARE ON NO STEP, AND THEY ARE THESE TWO.
     *
     * `step => null` is the mechanism for "the office form does not ask this",
     * and it is also the mechanism by which a field SILENTLY STOPS BEING ASKED.
     * Set equality is therefore the assertion: a third group acquiring
     * `step => null` is red here, and so is one of these two acquiring a step.
     *
     * The second entry arrived on 2026-09-27 (the user's items 6 and 7), and it
     * arrived the same way the first did — off this form only. See
     * `CockpitSurveyFeedbackFieldsTest`, which proves the surveyor is still
     * asked and the carry-forward still carries.
     */
    public function test_exactly_these_groups_are_on_no_step(): void
    {
        $stepless = [];

        foreach ($this->mapGroups(self::SITE_SURVEY) as $group) {
            if ($group['step'] === null) {
                $stepless[] = $group['legend'];
            }
        }

        sort($stepless);

        $expected = self::STEPLESS_LEGENDS;
        sort($expected);

        $this->assertSame(
            $expected,
            $stepless,
            'The set of site-survey groups on NO step changed. Each one is a deliberate ruling with its reason '
            .'written beside it in the map — add or remove one only by editing STEPLESS_LEGENDS in the same commit.',
        );
    }

    // ── The whole map, iterated — never a sample ─────────────────────────

    public function test_every_group_in_the_whole_map_carries_a_step_key(): void
    {
        $groupsChecked    = 0;
        $documentsChecked = 0;

        foreach (CockpitDocumentFormPresenter::documentFieldMap() as $documentKey => $document) {
            foreach ($document['groups'] as $group) {
                $this->assertArrayHasKey(
                    'step',
                    $group,
                    "Group `{$group['legend']}` on {$documentKey} carries no `step` key. A step is a ROW on "
                    .'this map — an integer, or null with the reason written beside it.',
                );

                $this->assertTrue(
                    $group['step'] === null || (is_int($group['step']) && $group['step'] >= 1),
                    "Group `{$group['legend']}` on {$documentKey} has a `step` that is neither null nor a "
                    .'positive integer.',
                );

                $groupsChecked++;
            }

            $this->assertArrayHasKey(
                'step_titles',
                $document,
                "Document {$documentKey} carries no `step_titles` row.",
            );

            $documentsChecked++;
        }

        $this->assertSame(4, $documentsChecked, 'This proof iterated '.$documentsChecked.' documents.');
        $this->assertGreaterThan(
            9,
            $groupsChecked,
            'This proof iterated '.$groupsChecked.' groups — it must iterate the whole map, never a sample.',
        );
    }

    public function test_every_documents_step_titles_cover_exactly_its_steps(): void
    {
        $presenter = $this->presenter();
        $checked   = 0;

        foreach (CockpitDocumentFormPresenter::documentFieldMap() as $documentKey => $document) {
            $this->assertSame(
                $presenter->stepsFor($documentKey),
                array_values(array_keys($document['step_titles'])),
                "Document {$documentKey} titles a different set of steps than its groups declare.",
            );

            $checked++;
        }

        $this->assertSame(4, $checked, 'This proof checked '.$checked.' documents.');
    }

    /**
     * NO FIELD IS ORPHANED BY THE SPLIT. A field that reaches no step is a RED
     * TEST, not a field that silently stops being asked.
     */
    public function test_no_field_is_orphaned_by_the_step_split(): void
    {
        $presenter = $this->presenter();
        $checked   = 0;

        foreach (CockpitDocumentFormPresenter::documentFieldMap() as $documentKey => $document) {
            $steps = $presenter->stepsFor($documentKey);

            if ($steps === []) {
                continue;
            }

            $expected = [];

            foreach ($document['groups'] as $group) {
                // The map's deliberate omissions from the wizard are asserted
                // BY NAME here rather than remembered — see STEPLESS_LEGENDS.
                if (in_array($group['legend'], self::STEPLESS_LEGENDS, true)) {
                    continue;
                }

                foreach ($group['fields'] as $field) {
                    $expected[] = $field['key'];
                }
            }

            $reached = [];

            foreach ($steps as $step) {
                foreach ($presenter->groupsForStep($documentKey, $step) as $group) {
                    foreach ($group['fields'] as $field) {
                        $reached[] = $field['key'];
                    }
                }
            }

            sort($expected);
            sort($reached);

            $this->assertSame(
                $expected,
                $reached,
                "On {$documentKey}, the fields reachable from a step do not equal its full field set minus "
                .'the groups on no step. A field that reaches no step is a field that silently stops being asked.',
            );

            $this->assertNotEmpty($reached, "{$documentKey} has steps but no field on any of them.");

            $checked++;
        }

        $this->assertSame(
            3,
            $checked,
            'This proof checked '.$checked.' documents that HAVE steps (the site survey, the worksheet and '
            .'RAMS, which Plan 46.5-05 stepped). The O&M never gets a wizard.',
        );
    }

    // ── Resolution is by MEMBERSHIP, and an unreal step is DROPPED ───────

    public function test_resolve_step_resolves_by_membership_and_drops_anything_unreal(): void
    {
        $presenter = $this->presenter();

        $cases = [
            // [document, submitted, expected, why]
            [self::SITE_SURVEY, '2', 2, 'a real step resolves'],
            [self::SITE_SURVEY, '1', 1, 'the first step resolves'],
            [self::SITE_SURVEY, '3', 3, 'the last step resolves'],
            [self::SITE_SURVEY, 2, 2, 'an integer resolves'],
            [self::SITE_SURVEY, '9', 1, 'an out-of-range step is DROPPED, not rejected'],
            [self::SITE_SURVEY, 'two', 1, 'a word is dropped'],
            [self::SITE_SURVEY, '02', 1, 'matching is exact — 02 is not helpfully corrected'],
            [self::SITE_SURVEY, '', 1, 'an empty string is dropped'],
            [self::SITE_SURVEY, null, 1, 'an absent step is step 1'],
            [self::SITE_SURVEY, ['1'], 1, 'an array is dropped'],
            [self::SITE_SURVEY, '../../etc/passwd', 1, 'a path is dropped — the step never builds a path'],
            ['om', '2', 1, 'a document with no wizard answers 1'],
            ['worksheet', '1', 1, 'the worksheet has one step'],
            ['worksheet', '2', 1, 'and nothing beyond it'],
        ];

        $resolved = 0;

        foreach ($cases as [$document, $submitted, $expected, $why]) {
            $this->assertSame(
                $expected,
                $presenter->resolveStep($document, $submitted),
                "resolveStep({$document}, …) — {$why}.",
            );

            $resolved++;
        }

        $this->assertSame(
            count($cases),
            $resolved,
            'This proof resolved '.$resolved.' submitted values across 4 documents.',
        );
    }

    /**
     * The same "this presenter never invents a module" contract
     * `CockpitModulePresenter::progress()` and
     * `CockpitDocumentFormPresenter::fieldsFor()` already keep.
     */
    public function test_an_unknown_document_key_returns_empty_and_never_throws(): void
    {
        $presenter = $this->presenter();

        $this->assertSame([], $presenter->stepsFor('not_a_document'));
        $this->assertSame(0, $presenter->stepCount('not_a_document'));
        $this->assertSame([], $presenter->groupsForStep('not_a_document', 1));
        $this->assertNull($presenter->stepTitle('not_a_document', 1));
        $this->assertSame(1, $presenter->resolveStep('not_a_document', '2'));

        // Case-sensitive, like `?module=`: SITE_SURVEY is not a document key.
        $this->assertSame([], $presenter->stepsFor('SITE_SURVEY'));
    }

    // ── The class itself: no document branch, and it writes nothing ──────

    private function source(): string
    {
        return (string) file_get_contents(base_path('app/Support/Cockpit/CockpitWizardPresenter.php'));
    }

    public function test_the_presenter_names_no_document_and_branches_on_no_document_key(): void
    {
        $source = $this->source();

        $this->assertNotSame('', $source, 'The presenter source was unreadable — this proof would be vacuous.');

        foreach (['site_survey', 'worksheet', 'rams', "'om'"] as $documentKey) {
            $this->assertStringNotContainsString(
                $documentKey,
                $source,
                "CockpitWizardPresenter names the document key {$documentKey}. A sixth change to this page "
                .'is a ROW EDIT — there is no branch on a document name in this class.',
            );
        }
    }

    public function test_the_presenter_touches_no_model(): void
    {
        $source = $this->source();

        foreach (['->save(', '::create(', '->update(', '->delete(', 'firstOrCreate', '->touch(', 'DB::'] as $write) {
            $this->assertStringNotContainsString(
                $write,
                $source,
                "CockpitWizardPresenter contains `{$write}`. This presenter is PURE READING — plan 46.5-01 "
                .'persists nothing at all.',
            );
        }
    }
}
