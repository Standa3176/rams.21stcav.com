<?php

namespace Tests\Feature\Cockpit;

use App\Models\LabourResource;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\ProjectDeliverable;
use App\Models\SiteSurvey;
use App\Models\User;
use App\Models\Visit;
use App\Support\Cockpit\CockpitDocumentFormPresenter;
use App\Support\Cockpit\CockpitWizardPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 46.5, Plan 46.5-04 — THE STEPPED CREATION WIZARD.
 *
 * A 17-field wall was called *"scary"* and the user asked for three short steps
 * in their own words: *"dates ,site contact and engineer then next button Then
 * notes (dont need comms room) . then next and confirm space being surveys"*.
 * This file is the proof, and it is deliberately split into three parts:
 *
 *   1. THE STEP ADVANCE WRITES NOTHING. `intent=next` is a POST that persists
 *      no row, and that is asserted as row counts across SIX NAMED TABLES
 *      before and after — because "a write route that writes nothing" is
 *      exactly the kind of thing a later change quietly turns into a write.
 *
 *   2. EVERY STEP IS RENDERED. Not one of them; all of them, by LOOPING the
 *      document's own step list, so a fourth step added to the map is covered
 *      the day it lands.
 *
 *   3. THE ABANDONED-WIZARD RULE (GCW-03). Nothing is persisted until the final
 *      step, so an engineer link can never be issued against a survey whose
 *      rooms were never confirmed — because until the final submit there is no
 *      survey.
 *
 * ── WHY PART 2 IS WRITTEN AS A LOOP AND NOT AS THREE METHODS ───────────────
 *
 * Two days before this plan the user found a defect — an open drawer row that
 * would not close — that 387 tests missed, because the assertion that should
 * have caught it only ever rendered the CLOSED page. Vacuous, not wrong. A
 * wizard has N states and a test that renders one of them proves nothing about
 * the others, so every step of every stepped document is rendered here and the
 * COUNT of states rendered is itself asserted.
 */
class CockpitWizardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * THE SIX TABLES A HALF-FINISHED WIZARD MUST NOT TOUCH.
     *
     * Named, every one of them, because a count-of-one-table assertion is the
     * vacuous kind: `site_surveys` alone would pass while a visit, a worksheet
     * or an activity row was written behind it.
     *
     * @var array<int, string>
     */
    private const UNTOUCHED_TABLES = [
        'site_surveys',
        'visits',
        'worksheets',
        'rams_documents',
        'project_activity_logs',
        'project_packages',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['cockpit.enabled' => true]);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function project(): Project
    {
        return Project::factory()->create([
            'name'         => 'Wizard Cockpit Job',
            'ref'          => 'Q-4655',
            'client_name'  => 'Northbank Media',
            'site_address' => '12 Wharf Road, Leeds',
            'status'       => Project::STATUS_INSTALLING,
        ]);
    }

    private function user(): User
    {
        return User::factory()->create(['name' => 'Priya Mistry']);
    }

    /**
     * BOTH resource roles, since RAMS joined the wizard (Plan 46.5-05).
     *
     * A `resource-list` field renders one checkbox per ACTIVE resource in its
     * role, so a fixture with no programmer renders no `programmers` control at
     * all — and the step-membership loop below would then pass vacuously on an
     * absence it exists to catch.
     */
    private function resources(): void
    {
        LabourResource::factory()->create([
            'name'      => 'Dev Chandra',
            'roles'     => [LabourResource::ROLE_ENGINEER],
            'is_active' => true,
        ]);

        LabourResource::factory()->create([
            'name'      => 'Ana Ruiz',
            'roles'     => [LabourResource::ROLE_PROGRAMMER],
            'is_active' => true,
        ]);
    }

    /**
     * SPACES ON FILE, for the same measured reason `resources()` creates a
     * programmer (Plan 46.5-05, deviation 5).
     *
     * A `space-list` field renders one checkbox per space the project has, so a
     * fixture with NO spaces renders no `visit_rooms` control at all - and the
     * step-membership loop would then pass VACUOUSLY on an absence it exists to
     * catch. The source is `ProjectContextResolver::resolve()['rooms']`, so a
     * reviewed package with `room_overviews` is what puts spaces on file.
     */
    private function spaces(Project $project): void
    {
        ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $this->user()->id,
            'quote_filename' => 'quote.pdf',
            'quote_path'     => 'packages/quote.pdf',
            'extracted_data' => [
                'room_overviews' => [
                    ['room' => 'Boardroom', 'overview' => 'Two 75in displays.', 'summary' => 'Boardroom'],
                    ['room' => 'Huddle 1',  'overview' => 'One soundbar.',      'summary' => 'Huddle 1'],
                ],
            ],
            'status'         => ProjectPackage::STATUS_REVIEWED,
        ]);
    }

    private function wizard(): CockpitWizardPresenter
    {
        return app(CockpitWizardPresenter::class);
    }

    /** @return array<string, int> */
    private function rowCounts(): array
    {
        $counts = [];

        foreach (self::UNTOUCHED_TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    private function submit(Project $project, array $payload, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user())
            ->post(route('projects.cockpit.documents.store', $project), $payload);
    }

    private function stepUrl(Project $project, string $module, int $step): string
    {
        return route('projects.cockpit', [
            'project' => $project,
            'module'  => $module,
            'tab'     => 'overview',
            'action'  => 'generate',
            'step'    => $step,
        ]);
    }

    /** The step-1 answers the user's own step 1 asks for. */
    private function stepOneValues(): array
    {
        return [
            // ONE DATE SINCE 2026-09-27 (item 1). `Visit date` is the label that
            // survived and `also_target` carries the same value to
            // `survey.survey_date`, so the Word document still has its date.
            'visit_scheduled_date' => '2026-10-14',
            'surveyor_name'        => 'Dev Chandra',
            'site_contact_name'    => 'Ruth Okafor',
            'site_contact_phone'   => '07700 900123',
        ];
    }

    // ── 1. THE STEP ADVANCE WRITES NOTHING ──────────────────────────────────

    public function test_a_next_from_step_one_lands_on_step_two_and_the_answers_survive(): void
    {
        $project = $this->project();

        $response = $this->submit($project, [
            'module' => ProjectDeliverable::KEY_SITE_SURVEY,
            'intent' => 'next',
            'step'   => 1,
            'tab'    => 'overview',
        ] + $this->stepOneValues());

        $response->assertRedirect($this->stepUrl($project, ProjectDeliverable::KEY_SITE_SURVEY, 2))
            ->assertSessionHasNoErrors();

        // `withInput()` and nothing else: the wizard lives in the session flash,
        // never in the database.
        $response->assertSessionHasInput('surveyor_name', 'Dev Chandra');
        $response->assertSessionHasInput('site_contact_name', 'Ruth Okafor');
    }

    /**
     * THE ASSERTION THE WHOLE PERSISTENCE RULE RESTS ON.
     *
     * Six tables, named individually, counted before and counted after. A step
     * advance dispatches nothing, logs nothing and writes nothing — so if a
     * later change gives `advance()` a model call, this goes red rather than
     * quietly leaving orphan rows behind every abandoned wizard.
     */
    public function test_a_step_advance_writes_no_row_in_any_of_the_six_tables(): void
    {
        $project = $this->project();

        $before = $this->rowCounts();

        $this->submit($project, [
            'module' => ProjectDeliverable::KEY_SITE_SURVEY,
            'intent' => 'next',
            'step'   => 1,
            'tab'    => 'overview',
        ] + $this->stepOneValues())->assertSessionHasNoErrors();

        $after = $this->rowCounts();

        foreach (self::UNTOUCHED_TABLES as $table) {
            $this->assertSame(
                $before[$table],
                $after[$table],
                "A step advance wrote to `{$table}`. Nothing is persisted until the final step."
            );
        }

        $this->assertCount(6, $before, 'Six tables are snapshotted, by name.');
    }

    public function test_a_failing_step_one_field_re_renders_step_one_and_never_step_two(): void
    {
        $project = $this->project();

        $this->submit($project, [
            'module'      => ProjectDeliverable::KEY_SITE_SURVEY,
            'intent'      => 'next',
            'step'        => 1,
            'tab'         => 'overview',
            // The ONE date field step 1 now asks for (item 1).
            'visit_scheduled_date' => 'not-a-date',
        ])
            ->assertSessionHasErrors('visit_scheduled_date')
            ->assertRedirect();

        // A PM must not reach step 3 to learn step 1 was wrong.
        $this->assertSame(0, SiteSurvey::count(), 'A failed advance writes nothing either.');
    }

    /**
     * ONLY THE CURRENT STEP IS VALIDATED, so a step-1 advance is not refused for
     * a step-2 or step-3 field the PM has not reached yet.
     */
    public function test_a_step_one_advance_is_not_refused_for_a_later_steps_fields(): void
    {
        $project = $this->project();

        $this->submit($project, [
            'module' => ProjectDeliverable::KEY_SITE_SURVEY,
            'intent' => 'next',
            'step'   => 1,
            'tab'    => 'overview',
        ])->assertSessionHasNoErrors();
    }

    public function test_an_unrecognised_intent_is_a_validation_failure_that_writes_nothing(): void
    {
        $project = $this->project();

        $before = $this->rowCounts();

        $response = $this->submit($project, [
            'module' => ProjectDeliverable::KEY_SITE_SURVEY,
            'intent' => 'rubbish',
            'format' => 'word',
            'tab'    => 'overview',
        ]);

        $response->assertSessionHasErrors('intent');

        foreach ($this->rowCounts() as $table => $count) {
            $this->assertSame($before[$table], $count, "An unrecognised intent wrote to `{$table}`.");
        }

        // The submitted value is never echoed.
        $this->assertStringNotContainsString('rubbish', (string) $response->headers->get('location'));
    }

    public function test_an_unreal_step_on_an_advance_falls_back_to_step_one_and_writes_nothing(): void
    {
        $project = $this->project();

        foreach (['9', 'abc', '0'] as $unreal) {
            $before = $this->rowCounts();

            $response = $this->submit($project, [
                'module' => ProjectDeliverable::KEY_SITE_SURVEY,
                'intent' => 'next',
                'step'   => $unreal,
                'tab'    => 'overview',
            ]);

            // Resolved to step 1, so `next` goes to step 2 — dropped, never
            // rejected, exactly as `?step=` is on the GET.
            $response->assertRedirect($this->stepUrl($project, ProjectDeliverable::KEY_SITE_SURVEY, 2))
                ->assertSessionHasNoErrors();

            foreach ($this->rowCounts() as $table => $count) {
                $this->assertSame($before[$table], $count, "An unreal step wrote to `{$table}`.");
            }
        }
    }

    public function test_a_back_from_step_two_returns_to_step_one(): void
    {
        $project = $this->project();

        $this->submit($project, [
            'module' => ProjectDeliverable::KEY_SITE_SURVEY,
            'intent' => 'back',
            'step'   => 2,
            'tab'    => 'overview',
        ])->assertRedirect($this->stepUrl($project, ProjectDeliverable::KEY_SITE_SURVEY, 1));
    }

    public function test_back_on_the_first_step_and_next_on_the_last_stay_where_they_are(): void
    {
        $project = $this->project();
        $steps   = $this->wizard()->stepsFor(ProjectDeliverable::KEY_SITE_SURVEY);
        $last    = $steps[count($steps) - 1];

        $this->submit($project, [
            'module' => ProjectDeliverable::KEY_SITE_SURVEY,
            'intent' => 'back',
            'step'   => 1,
            'tab'    => 'overview',
        ])->assertRedirect($this->stepUrl($project, ProjectDeliverable::KEY_SITE_SURVEY, 1));

        $this->submit($project, [
            'module' => ProjectDeliverable::KEY_SITE_SURVEY,
            'intent' => 'next',
            'step'   => $last,
            'tab'    => 'overview',
        ])->assertRedirect($this->stepUrl($project, ProjectDeliverable::KEY_SITE_SURVEY, $last));
    }

    /**
     * NO ROUTE WAS ADDED. The advance is the same POST the creation uses,
     * distinguished by `intent` — asserted here as well as in
     * `CockpitPageTest`'s exact 6/3 counts, because the temptation to add a
     * `wizard.step` route is exactly what this test exists to catch.
     */
    public function test_the_advance_adds_no_route(): void
    {
        $names = collect(app('router')->getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter()
            ->filter(fn (string $name) => str_starts_with($name, 'projects.cockpit'))
            ->values()
            ->all();

        foreach ($names as $name) {
            $this->assertStringNotContainsString('step', $name, 'The step advance is not a route of its own.');
            $this->assertStringNotContainsString('wizard', $name, 'The step advance is not a route of its own.');
        }
    }

    /**
     * `intent=create` is TODAY'S BEHAVIOUR, unchanged — the final submit still
     * validates in full, so a hand-crafted POST cannot skip a step's rules by
     * claiming to be on step 1 (T-46.5-04-02).
     */
    public function test_the_final_submit_revalidates_every_steps_rules_even_on_a_forged_step(): void
    {
        $project = $this->project();

        $this->submit($project, [
            'module'      => ProjectDeliverable::KEY_SITE_SURVEY,
            'intent'      => 'create',
            'format'      => 'word',
            'tab'         => 'overview',
            // A forged step, claiming the document is on step 1 so that step 2's
            // rules "do not apply".
            'step'        => 1,
            'visit_scheduled_date' => '2026-10-14',
            // A step-2 field, carried forward as a hidden input and therefore
            // attacker-controlled: it is RE-VALIDATED, never trusted.
            'general_notes' => str_repeat('z', 5001),
        ])->assertSessionHasErrors('general_notes');

        $this->assertSame(0, SiteSurvey::count(), 'A rejected final submit creates no survey.');
    }

    public function test_a_carried_display_only_field_is_still_prohibited_on_the_final_submit(): void
    {
        $project = $this->project();

        $this->submit($project, [
            'module'       => ProjectDeliverable::KEY_SITE_SURVEY,
            'intent'       => 'create',
            'format'       => 'word',
            'tab'          => 'overview',
            'step'         => 1,
            'project_name' => 'Something the PM typed behind the app is back',
        ])->assertSessionHasErrors('project_name');

        $this->assertSame(0, SiteSurvey::count());
    }

    /**
     * A POST WITH NO `intent` IS TODAY'S SUBMISSION. Every existing caller and
     * every existing test predates this plan and none of them sends one, so the
     * absence defaults to `create` — the same courtesy `tab` already gets.
     */
    public function test_a_post_with_no_intent_still_creates_exactly_as_it_did_before(): void
    {
        $project = $this->project();
        $this->resources();

        $this->submit($project, [
            'module' => ProjectDeliverable::KEY_SITE_SURVEY,
            'format' => 'word',
            'tab'    => 'overview',
        ] + $this->stepOneValues())->assertSessionHasNoErrors();

        $survey = SiteSurvey::where('project_id', $project->id)->sole();

        $this->assertSame('Dev Chandra', $survey->surveyor_name);
        $this->assertSame('Ruth Okafor', $survey->site_contact_name);
    }

    /**
     * `format` IS NO LONGER ASKED, AND IS STILL BOUNDED (2026-09-27, item 8).
     *
     * The radios went because their answer changed nothing but a flash word. The
     * RULE did not go: an ABSENT `format` is now defaulted from the document's
     * own `formats` map, and a PRESENT but unoffered one is still rejected. Both
     * halves are asserted here, because dropping the radios without the second
     * half would have turned `format` into a free string on a POST.
     */
    public function test_the_format_is_defaulted_from_the_map_and_a_bogus_one_is_still_refused(): void
    {
        $project = $this->project();

        // An advance carries no format and never did.
        $this->submit($project, [
            'module' => ProjectDeliverable::KEY_SITE_SURVEY,
            'intent' => 'next',
            'step'   => 1,
            'tab'    => 'overview',
        ])->assertSessionHasNoErrors();

        // A CREATION WITH NO FORMAT NOW SUCCEEDS, because the page no longer
        // asks. The default is the map's first offered key for this document.
        $this->submit($project, [
            'module' => ProjectDeliverable::KEY_SITE_SURVEY,
            'intent' => 'create',
            'tab'    => 'overview',
        ] + $this->stepOneValues())->assertSessionHasNoErrors();

        $this->assertSame(1, SiteSurvey::count(), 'The creation with no format did not happen.');

        // AND A FORMAT THIS DOCUMENT DOES NOT OFFER IS STILL A VALIDATION ERROR.
        // The worksheet has no PDF (DC-07), so this is the real boundary rather
        // than a value the page happened not to render.
        $this->submit($project, [
            'module' => ProjectDeliverable::KEY_WORKSHEET,
            'intent' => 'create',
            'format' => 'pdf',
            'tab'    => 'overview',
        ])->assertSessionHasErrors('format');
    }

    public function test_an_advance_on_an_unknown_module_is_a_validation_error_and_never_a_500(): void
    {
        $project = $this->project();

        $this->submit($project, [
            'module' => '../../etc/passwd',
            'intent' => 'next',
            'step'   => 1,
        ])->assertSessionHasErrors('module');
    }

    public function test_a_document_with_no_wizard_is_unchanged_by_this_plan(): void
    {
        // THE O&M, AND NOW ONLY THE O&M. RAMS carried `step => null` on every
        // group until Plan 46.5-05 minted its step set (D-04: "if ticked yes it
        // follow a similar flow to site survey"), so its assertion MOVED to
        // CockpitRamsWizardTest rather than being relaxed here. The O&M's
        // no-wizard state is PERMANENT (46.5-CONTEXT phase boundary: two real
        // inputs do not need three screens), which is what this test is for.
        $this->assertSame([], $this->wizard()->stepsFor(ProjectDeliverable::KEY_OM));
        $this->assertSame([1, 2, 3], $this->wizard()->stepsFor(ProjectDeliverable::KEY_RAMS));

        // And the step spine says so for the two that DO step.
        $this->assertSame([1, 2, 3], $this->wizard()->stepsFor(ProjectDeliverable::KEY_SITE_SURVEY));
        $this->assertSame([1], $this->wizard()->stepsFor(ProjectDeliverable::KEY_WORKSHEET));
    }

    // ── 2. EVERY STEP OF EVERY STEPPED DOCUMENT IS RENDERED ─────────────────

    /** The `cav-qa` subtree of the open panel, or '' when nothing disclosed. */
    private function docForm(Project $project, string $module, array $query = []): string
    {
        $body = $this->actingAs($this->user())
            ->get(route('projects.cockpit', ['project' => $project, 'module' => $module] + $query))
            ->assertOk()
            ->getContent();

        return $this->subtree($body, 'cav-qa');
    }

    private function subtree(string $html, string $class): string
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $node = (new \DOMXPath($dom))
            ->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]")
            ->item(0);

        return $node === null ? '' : html_entity_decode($dom->saveHTML($node), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Control names by XPath, split into VISIBLE and HIDDEN.
     *
     * The split is the whole point of using `DOMXPath` here rather than
     * `assertSee`: with carry-forward every step's fields are somewhere in the
     * document, so a whole-page string search cannot tell "rendered as a
     * control the PM must answer" from "riding along as a hidden value". A test
     * that could not tell them apart would pass with all 13 fields back on one
     * screen — the wall the user called scary.
     *
     * @return array{visible: array<int, string>, hidden: array<int, string>}
     */
    private function splitControls(string $html): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $xpath   = new \DOMXPath($dom);
        $visible = [];
        $hidden  = [];

        foreach ($xpath->query('//input[@name] | //textarea[@name]') as $node) {
            $name = rtrim($node->getAttribute('name'), '[]');

            if (strtolower($node->getAttribute('type')) === 'hidden') {
                $hidden[] = $name;

                continue;
            }

            $visible[] = $name;
        }

        return [
            'visible' => array_values(array_unique($visible)),
            'hidden'  => array_values(array_unique($hidden)),
        ];
    }


    /**
     * The `array`-ruled field keys of one document — the multi-selects, whose
     * carry-forward is per-VALUE rather than per-field. Derived from the map's
     * own rules, never listed.
     *
     * @return array<int, string>
     */
    private function arrayFieldsOf(string $module): array
    {
        $keys = [];

        foreach (CockpitDocumentFormPresenter::documentFieldMap()[$module]['groups'] as $group) {
            foreach ($group['fields'] as $field) {
                if (in_array('array', $field['rules'], true)) {
                    $keys[] = $field['key'];
                }
            }
        }

        return $keys;
    }

    /**
     * @return array{own: array<int, string>, others: array<int, string>}
     */
    private function fieldsByStep(string $module, int $step): array
    {
        $own    = [];
        $others = [];

        foreach (CockpitDocumentFormPresenter::documentFieldMap()[$module]['groups'] as $group) {
            $groupStep = $group['step'] ?? null;

            if (! is_int($groupStep)) {
                continue;
            }

            foreach ($group['fields'] as $field) {
                // A display-only field is never a control anywhere, so it takes
                // no part in this comparison.
                if (in_array('prohibited', $field['rules'], true)) {
                    continue;
                }

                if ($groupStep === $step) {
                    $own[] = $field['key'];

                    continue;
                }

                $others[] = $field['key'];
            }
        }

        return ['own' => $own, 'others' => $others];
    }

    /**
     * THE TEST THIS TASK EXISTS FOR.
     *
     * Every step of every STEPPED document, by looping the presenter's own step
     * list rather than hand-listing three numbers — so a fourth step added to
     * the map is covered the day it lands. Five things are asserted per step,
     * and the COUNT of states rendered is asserted at the end so a change that
     * collapses the wizard cannot pass by rendering one screen well.
     */
    public function test_every_step_of_every_stepped_document_renders_its_own_fields_and_only_those(): void
    {
        $project = $this->project();
        $this->resources();
        $this->spaces($project);

        $statesRendered = 0;
        $documents      = 0;

        foreach (array_keys(CockpitDocumentFormPresenter::documentFieldMap()) as $module) {
            $steps = $this->wizard()->stepsFor($module);

            if ($steps === []) {
                continue;
            }

            $documents++;

            foreach ($steps as $position => $step) {
                $form = $this->docForm($project, $module, ['action' => 'generate', 'step' => $step]);
                $statesRendered++;

                $this->assertNotSame('', $form, "{$module} step {$step} disclosed no form.");

                $split  = $this->splitControls($form);
                $fields = $this->fieldsByStep($module, $step);

                // 1. THE STEP'S OWN FIELDS ARE THERE, as controls to answer.
                foreach ($fields['own'] as $key) {
                    $this->assertContains(
                        $key,
                        $split['visible'],
                        "{$module} step {$step} does not ask for {$key}, which its own groups name."
                    );
                }

                // 2. EVERY OTHER STEP'S FIELDS ARE NOT — not as controls. They
                //    ARE present as hidden carry inputs, and that is asserted
                //    separately below, because the two are different sentences.
                foreach ($fields['others'] as $key) {
                    $this->assertNotContains(
                        $key,
                        $split['visible'],
                        "{$module} step {$step} renders {$key}, which belongs to another step. ".
                        'That is the 13-field wall coming back.'
                    );

                    // ⚠ AN EMPTY MULTI-SELECT CARRIES NOTHING, AND THAT IS THE
                    //   DESIGN RATHER THAN A DROP. An `array`-ruled field is
                    //   carried as one hidden input PER SELECTED VALUE
                    //   (doc-form.blade.php:361-364), so with nothing selected
                    //   there is nothing to carry — an empty `name[]` would
                    //   submit a phantom blank engineer. The site survey and
                    //   the worksheet have no array field off-step, which is
                    //   why this only surfaced when RAMS joined the wizard in
                    //   Plan 46.5-05.
                    //
                    //   THE CARRY IS NOT LEFT UNPROVEN: that a SELECTED
                    //   multi-select survives a step advance and reaches the
                    //   document is asserted end-to-end by
                    //   CockpitRamsWizardTest::test_a_selected_multi_select_is_carried_forward_and_reaches_the_document().
                    if (in_array($key, $this->arrayFieldsOf($module), true)) {
                        continue;
                    }

                    $this->assertContains(
                        $key,
                        $split['hidden'],
                        "{$module} step {$step} drops {$key} instead of carrying it forward."
                    );
                }

                $isFirst = $position === 0;
                $isLast  = $position === count($steps) - 1;

                // 3. The progress line names this step and the total.
                $this->assertStringContainsString(
                    'Step '.($position + 1).' of '.count($steps),
                    $form,
                    "{$module} step {$step} does not say where the PM is."
                );

                // 4. Next on every step but the last; the submit and the OUTCOME
                //    LINE on the LAST and nowhere else. (The Format radios stood
                //    where the outcome line now stands until 2026-09-27, item 8.)
                $this->assertSame($isLast ? 0 : 1, substr_count($form, 'value="next"'), "{$module} step {$step}: Next.");
                $this->assertSame(
                    $isLast ? 1 : 0,
                    substr_count($form, 'Generate document'),
                    "{$module} step {$step}: the submit belongs to the last step only."
                );
                $this->assertSame(
                    0,
                    substr_count($form, 'name="format"'),
                    "{$module} step {$step}: no step asks for a format any more."
                );
                $this->assertSame(
                    $isLast ? 1 : 0,
                    (int) str_contains($form, 'Generating creates '),
                    "{$module} step {$step}: the outcome line belongs to the last step only."
                );

                // 5. Back everywhere but the first step.
                $this->assertSame($isFirst ? 0 : 1, substr_count($form, 'value="back"'), "{$module} step {$step}: Back.");
            }
        }

        // SEVEN STATES ACROSS THREE STEPPED DOCUMENTS — the site survey's three,
        // the worksheet's one and RAMS's three (Plan 46.5-05). COUNTED, because
        // a test that renders one state of an N-state control proves nothing
        // about the other N-1. The numbers MOVED because a document joined the
        // wizard; they are never relaxed to make a red test fit.
        $this->assertSame(3, $documents, 'Three documents step: the site survey, the worksheet and RAMS.');
        $this->assertSame(7, $statesRendered, 'Seven wizard states were rendered and asserted.');
    }

    /**
     * COMMS ROOM IS ON NO STEP, AND THEREFORE ON NO SCREEN OF THIS FORM.
     *
     * 46.5 D-03, asserted where a PM would see it. The group is NOT deleted
     * from the map, NOT dropped from the Word document, NOT removed from the
     * on-site capture and NOT taken out of the survey->install carry-forward —
     * an installing engineer reads the surveyor's comms-room access notes, and
     * removing it from those is a safety regression. It simply is not asked for
     * on the office creation form any more.
     */
    public function test_comms_room_is_asked_for_on_no_step_of_the_office_form(): void
    {
        $project = $this->project();
        $module  = ProjectDeliverable::KEY_SITE_SURVEY;
        $states  = 0;

        foreach ($this->wizard()->stepsFor($module) as $step) {
            $form   = $this->docForm($project, $module, ['action' => 'generate', 'step' => $step]);
            $split  = $this->splitControls($form);
            $states++;

            foreach (['comms_room_access_status', 'comms_room_access_notes'] as $key) {
                $this->assertNotContains($key, $split['visible'], "Step {$step} asks for {$key}.");
                // Nor carried: a hidden empty value would OVERWRITE what the
                // engineer captured on site.
                $this->assertNotContains($key, $split['hidden'], "Step {$step} carries {$key}.");
            }

            $this->assertStringNotContainsString('Comms room', $form, "Step {$step} renders the Comms room group.");
        }

        $this->assertSame(3, $states, 'All three steps were checked for comms room.');

        // AND IT IS STILL IN THE MAP, on no step. Absent from the form is not
        // absent from the product.
        $legends = [];

        foreach (CockpitDocumentFormPresenter::documentFieldMap()[$module]['groups'] as $group) {
            $legends[$group['legend']] = $group['step'] ?? null;
        }

        $this->assertArrayHasKey('Comms room', $legends, 'The Comms room group was DELETED. It should be step => null.');
        $this->assertNull($legends['Comms room'], 'Comms room belongs to no step.');
    }

    /**
     * THE WALK, THROUGH HTTP, AS A PM DOES IT.
     *
     * Open, answer step 1, Next, see step 1's answers riding hidden on step 2,
     * Next again, then Back — and step 1's answers are still there. This is the
     * user's own acceptance test ("simple to use"): a PM finishes without
     * scrolling back, and nothing they typed is lost on the way.
     */
    public function test_the_walk_from_step_one_to_three_and_back_carries_every_answer(): void
    {
        $project = $this->project();
        $this->resources();
        $module  = ProjectDeliverable::KEY_SITE_SURVEY;
        $user    = $this->user();
        $visited = [];

        // Step 1, opened.
        $form = $this->docForm($project, $module, ['action' => 'generate']);
        $this->assertStringContainsString('Step 1 of 3', $form);
        $visited[] = 1;

        // Next, with step 1's answers.
        $response = $this->actingAs($user)->post(
            route('projects.cockpit.documents.store', $project),
            ['module' => $module, 'intent' => 'next', 'step' => 1, 'tab' => 'overview'] + $this->stepOneValues(),
        );

        $step2 = $this->subtree(
            $this->actingAs($user)->get((string) $response->headers->get('location'))->assertOk()->getContent(),
            'cav-qa',
        );
        $visited[] = 2;

        $this->assertStringContainsString('Step 2 of 3', $step2);

        // The submitted values, riding hidden — asserted as VALUES, not merely
        // as names, because a carry input with the wrong value loses the answer
        // just as completely as no input at all.
        foreach ($this->stepOneValues() as $key => $value) {
            $this->assertMatchesRegularExpression(
                '/<input type="hidden" name="'.preg_quote($key, '/').'" value="'.preg_quote($value, '/').'">/',
                $step2,
                "Step 2 lost step 1's {$key}."
            );
        }

        // Next again, carrying step 1 forward exactly as the form does.
        $response = $this->actingAs($user)->post(
            route('projects.cockpit.documents.store', $project),
            [
                'module'        => $module,
                'intent'        => 'next',
                'step'          => 2,
                'tab'           => 'overview',
                'general_notes' => 'Lift access booked.',
            ] + $this->stepOneValues(),
        );

        $step3 = $this->subtree(
            $this->actingAs($user)->get((string) $response->headers->get('location'))->assertOk()->getContent(),
            'cav-qa',
        );
        $visited[] = 3;

        $this->assertStringContainsString('Step 3 of 3', $step3);
        $this->assertStringContainsString('Generate document', $step3, 'The last step is where the document is asked for.');
        $this->assertStringContainsString('Lift access booked.', $step3, 'Step 3 lost step 2\'s notes.');

        // Back.
        $response = $this->actingAs($user)->post(
            route('projects.cockpit.documents.store', $project),
            [
                'module'        => $module,
                'intent'        => 'back',
                'step'          => 3,
                'tab'           => 'overview',
                'general_notes' => 'Lift access booked.',
            ] + $this->stepOneValues(),
        );

        $backTo2 = $this->subtree(
            $this->actingAs($user)->get((string) $response->headers->get('location'))->assertOk()->getContent(),
            'cav-qa',
        );
        $visited[] = 2;

        $this->assertStringContainsString('Step 2 of 3', $backTo2);
        $this->assertStringContainsString('Lift access booked.', $backTo2, 'Back lost the notes the PM typed.');
        $this->assertStringContainsString('value="Dev Chandra"', $backTo2, 'Back lost step 1.');

        $this->assertSame([1, 2, 3, 2], $visited, 'Four states were walked through, in order.');

        // AND THE WHOLE WALK WROTE NOTHING.
        foreach (self::UNTOUCHED_TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), "The walk wrote to `{$table}`.");
        }
    }

    // ── 3. THE ABANDONED-WIZARD RULE (GCW-03) ───────────────────────────────

    /**
     * WALK AWAY, THE WAY A PM ACTUALLY DOES: back to the bare module URL (the
     * form's own Cancel target) and then off the page entirely.
     */
    private function walkAway(Project $project, string $module, User $user): void
    {
        $this->actingAs($user)
            ->get(route('projects.cockpit', ['project' => $project, 'module' => $module]))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('projects.cockpit', $project))
            ->assertOk();
    }

    public function test_a_wizard_abandoned_after_step_one_persists_nothing(): void
    {
        $project = $this->project();
        $user    = $this->user();
        $module  = ProjectDeliverable::KEY_SITE_SURVEY;

        $before = $this->rowCounts();

        $this->actingAs($user)->post(
            route('projects.cockpit.documents.store', $project),
            ['module' => $module, 'intent' => 'next', 'step' => 1, 'tab' => 'overview'] + $this->stepOneValues(),
        )->assertSessionHasNoErrors();

        $this->walkAway($project, $module, $user);

        foreach ($this->rowCounts() as $table => $count) {
            $this->assertSame(
                $before[$table],
                $count,
                "A wizard abandoned after step one left a row in `{$table}`."
            );
        }

        $this->assertSame(0, Visit::where('project_id', $project->id)->count(), 'No visit exists for an abandoned wizard.');
        $this->assertCount(6, $before, 'Six tables, named individually, were snapshotted.');
    }

    public function test_a_wizard_abandoned_after_step_two_persists_nothing(): void
    {
        $project = $this->project();
        $user    = $this->user();
        $module  = ProjectDeliverable::KEY_SITE_SURVEY;

        $before = $this->rowCounts();

        foreach ([1, 2] as $step) {
            $this->actingAs($user)->post(
                route('projects.cockpit.documents.store', $project),
                [
                    'module'        => $module,
                    'intent'        => 'next',
                    'step'          => $step,
                    'tab'           => 'overview',
                    'general_notes' => 'Two steps in, then the phone rang.',
                ] + $this->stepOneValues(),
            )->assertSessionHasNoErrors();
        }

        $this->walkAway($project, $module, $user);

        foreach ($this->rowCounts() as $table => $count) {
            $this->assertSame(
                $before[$table],
                $count,
                "A wizard abandoned after step two left a row in `{$table}`."
            );
        }

        $this->assertSame(0, Visit::where('project_id', $project->id)->count(), 'No visit exists for an abandoned wizard.');
        $this->assertCount(6, $before, 'Six tables, named individually, were snapshotted.');
    }

    /**
     * D-02'S NAMED WORST CASE, AND IT GETS ITS OWN NAME.
     *
     * "Do not leave an engineer link issued against a survey whose rooms were
     * never confirmed." Every `SiteSurvey` is given an `access_token` on
     * creation — that token IS the engineer link (`SiteSurvey::publicUrl()`) —
     * so the only way to guarantee no link exists is for no survey to exist.
     * That is precisely what nothing-until-the-final-step buys, and this test
     * holds it: walk two steps of the wizard, abandon it, and there is no
     * survey, no token and therefore no link for an engineer to open.
     */
    public function test_no_engineer_link_exists_for_a_project_whose_wizard_was_abandoned(): void
    {
        $project = $this->project();
        $user    = $this->user();
        $module  = ProjectDeliverable::KEY_SITE_SURVEY;

        foreach ([1, 2] as $step) {
            $this->actingAs($user)->post(
                route('projects.cockpit.documents.store', $project),
                ['module' => $module, 'intent' => 'next', 'step' => $step, 'tab' => 'overview'] + $this->stepOneValues(),
            )->assertSessionHasNoErrors();
        }

        $this->walkAway($project, $module, $user);

        $this->assertSame(
            0,
            SiteSurvey::where('project_id', $project->id)->whereNotNull('access_token')->count(),
            'An engineer link exists for a survey whose spaces were never confirmed.'
        );

        $this->assertSame(0, SiteSurvey::where('project_id', $project->id)->count(), 'There is no survey at all — that is the point.');
        $this->assertSame(0, Visit::where('project_id', $project->id)->count());

        // THE MIRROR, so the assertions above are not vacuous: finish the wizard
        // and the survey — and its link — DO appear.
        $this->actingAs($user)->post(
            route('projects.cockpit.documents.store', $project),
            [
                'module' => $module,
                'intent' => 'create',
                'step'   => 3,
                'format' => 'word',
                'tab'    => 'overview',
            ] + $this->stepOneValues(),
        )->assertSessionHasNoErrors();

        $this->assertSame(
            1,
            SiteSurvey::where('project_id', $project->id)->whereNotNull('access_token')->count(),
            'The final submit DOES create the survey — so the absence above was the rule, not a broken form.'
        );
    }

    /**
     * The presenter is the only source of the step list, so every document in
     * the map is judged rather than the one this plan happened to design for.
     */
    public function test_every_document_in_the_map_is_covered_by_the_step_spine(): void
    {
        $judged = 0;

        foreach (array_keys(CockpitDocumentFormPresenter::documentFieldMap()) as $module) {
            $steps = $this->wizard()->stepsFor($module);

            $this->assertSame(array_values(array_unique($steps)), $steps, "{$module}'s steps repeat.");
            $judged++;
        }

        $this->assertSame(4, $judged, 'Four documents, every one judged.');
    }
}
