<?php

namespace Tests\Feature\Cockpit;

use App\Models\LabourResource;
use App\Models\OmManual;
use App\Models\Project;
use App\Models\ProjectDeliverable;
use App\Models\ProjectPackage;
use App\Models\RamsDocument;
use App\Models\SiteSurvey;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\RamsReviewDataService;
use App\Support\Cockpit\CockpitCombinedCreator;
use App\Support\Cockpit\CockpitDocumentFormPresenter;
use App\Support\Cockpit\CockpitModulePresenter;
use App\Support\Cockpit\CockpitWizardPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Phase 46.2, Plan 46.2-05 — THE DOCUMENT FORM IN THE PANEL.
 *
 * Plan 46.2-03 removed the last way to generate anything from the cockpit when
 * it deleted `quick-actions.blade.php`; this is the plan that gives it back, and
 * this file is the proof. Four modules, one control each, one form each, one
 * write route — and every field asserted OVER `DOCUMENT_FIELD_MAP` rather than
 * over a hand-written list, so a field added to the map is covered the day it
 * lands and a field NOT in the map cannot appear without a red test.
 *
 * ── WHY EVERY FIELD ASSERTION ITERATES THE MAP ─────────────────────────────
 *
 * 46.2-04's map is the single source of the field set (its grep gate proves a
 * field cannot exist unless a generator reads it). If this file re-listed the
 * fields, there would be TWO sources and the second would drift. So the tests
 * below read the map and assert the RENDERED INPUT NAMES ARE EXACTLY THE SET
 * THE MAP IMPLIES — no more (an invented input) and no fewer (a dropped one).
 *
 * ── THE DISCLOSURE IS URL STATE AND THERE IS NO JAVASCRIPT ─────────────────
 *
 * Closed is the bare module URL; open is `?action=generate`. That is the
 * mechanism `ProjectCockpitController::ACTIONS` was kept dormant for by Plan
 * 46.2-03, and it is why `CockpitReadOnlyFenceTest::BANNED_HANDLER_ATTRIBUTES`
 * is re-taken at nine rather than retired for a fifth time.
 *
 * ── DISPLAY-ONLY FIELDS ARE NOT INPUTS ────────────────────────────────────
 *
 * A field the PROJECT already answers carries `rules => ['prohibited']` in the
 * map. It is rendered as TEXT, never as a `readonly` input — a readonly input
 * still SUBMITS its value, which `prohibited` would then reject, turning a
 * perfectly ordinary submission into a validation failure the PM cannot fix.
 * Asserted by name below.
 */
class CockpitDocumentFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cockpit.enabled' => true]);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function project(): Project
    {
        return Project::factory()->create([
            'name'         => 'Document Cockpit Job',
            'ref'          => 'Q-4625',
            'client_name'  => 'Northbank Media',
            'site_address' => '12 Wharf Road, Leeds',
            'status'       => Project::STATUS_INSTALLING,
        ]);
    }

    private function user(): User
    {
        return User::factory()->create(['name' => 'Priya Mistry']);
    }

    /** A reviewed package, which is what `rams.from-project` requires. */
    private function reviewedPackage(Project $project, array $extracted = []): ProjectPackage
    {
        return ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $this->user()->id,
            'quote_filename' => 'quote.pdf',
            'quote_path'     => 'packages/quote.pdf',
            // Generation-ready, because `RamsController::generateFromProject`
            // runs `RamsReviewValidatorService` before it creates anything — a
            // thinner payload would bounce to the review page and this fixture
            // would silently stop exercising the delegation.
            'extracted_data' => $extracted + [
                'overview'               => 'A prose overview the normaliser does not carry.',
                'method_statement_notes' => 'Strip out, install, commission.',
                'project'                => ['project_name' => 'Document Cockpit Job'],
                'equipment'              => [['quantity' => 2, 'part_number' => 'SC-75', 'name' => '75in display', 'area' => 'Boardroom']],
                // SPACES ON FILE (Plan 46.5-06). A fixture with NO rooms renders
                // no space checkbox at all, and the control-name assertion below
                // would then pass VACUOUSLY on an absence it exists to catch -
                // the same trap 46.5-05 hit with an empty resource list.
                'room_overviews'         => [
                    ['room' => 'Boardroom', 'overview' => 'Two 75in displays.', 'summary' => 'Boardroom'],
                    ['room' => 'Huddle 1',  'overview' => 'One soundbar.',      'summary' => 'Huddle 1'],
                ],
                'activities'             => [['key' => 'install', 'label' => 'Install and commission']],
                'ppe'                    => ['Gloves', 'Safety boots'],
            ],
            'status'         => ProjectPackage::STATUS_REVIEWED,
        ]);
    }

    /** Three named resources — the option source for the `resource-list` fields. */
    private function resources(): void
    {
        LabourResource::factory()->create([
            'name'      => 'Dev Chandra',
            'email'     => 'dev.chandra@example.test',
            'phone'     => '07700 900111',
            'roles'     => [LabourResource::ROLE_ENGINEER],
            'is_active' => true,
        ]);

        LabourResource::factory()->create([
            'name'      => 'Marie Okonkwo',
            'email'     => 'marie.okonkwo@example.test',
            'phone'     => '07700 900222',
            'roles'     => [LabourResource::ROLE_ENGINEER],
            'is_active' => true,
        ]);

        LabourResource::factory()->create([
            'name'      => 'Tomas Reyes',
            'email'     => 'tomas.reyes@example.test',
            'phone'     => '07700 900333',
            'roles'     => [LabourResource::ROLE_PROGRAMMER],
            'is_active' => true,
        ]);
    }

    // ── Rendering helpers ───────────────────────────────────────────────────

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

    /** Every `name` attribute on every control inside a fragment. */
    private function controlNames(string $html): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $names = [];

        foreach ((new \DOMXPath($dom))->query('//input[@name] | //textarea[@name] | //select[@name]') as $node) {
            $names[] = $node->getAttribute('name');
        }

        return array_values(array_unique($names));
    }

    private function wizard(): CockpitWizardPresenter
    {
        return app(CockpitWizardPresenter::class);
    }

    /**
     * EVERY STATE OF ONE DOCUMENT'S FORM, as a query fragment each (Plan
     * 46.5-04).
     *
     * A stepless document has exactly ONE state and yields one empty fragment;
     * a stepped one yields one per step, read off the document's own step list
     * so a fourth step is covered the day it lands. Written as a helper because
     * five tests in this file previously rendered ONE state and called it the
     * form — and a test that renders one state of an N-state control proves
     * nothing about the other N-1.
     *
     * @return array<int, array<string, int>>
     */
    private function stepQueries(string $module): array
    {
        $steps = $this->wizard()->stepsFor($module);

        return $steps === []
            ? [[]]
            : array_map(static fn (int $step): array => ['step' => $step], $steps);
    }

    /** The last step's query fragment — where Format and the submit live. */
    private function lastStepQuery(string $module): array
    {
        $steps = $this->wizard()->stepsFor($module);

        return $steps === [] ? [] : ['step' => $steps[count($steps) - 1]];
    }

    /**
     * The control names the MAP implies for a document ON ONE STEP — derived,
     * never listed.
     *
     * A display-only field (`prohibited`) contributes NOTHING: it is text on the
     * page, not a control, and it is never carried forward either. An
     * `array`-ruled field contributes `key[]`.
     *
     * STEP-AWARE SINCE PLAN 46.5-04, and the three rules are the wizard's:
     *   · the CURRENT step's fields render as visible controls;
     *   · every OTHER integer step's non-readonly fields ride as HIDDEN inputs,
     *     so the set of names is still the whole document — which is exactly
     *     what makes `intent=create` able to re-validate all of them;
     *   · a `step => null` group renders NOWHERE and carries NOWHERE. That is
     *     the comms-room ruling (46.5 D-03): off the office form, still in the
     *     Word document, the on-site capture and the carry-forward.
     * `format` is on the LAST step only; `step` itself rides every stepped form.
     */
    private function expectedControlNames(string $module, ?int $step = null): array
    {
        $steps   = $this->wizard()->stepsFor($module);
        $stepped = $steps !== [];

        // The three the form carries regardless of document: the CSRF token, the
        // document key (validated with `Rule::in` the map's keys before any
        // lookup) and the tab the generation was initiated from.
        $names = ['_token', 'module', 'tab'];

        // NO `format` CONTROL ON ANY STEP SINCE 2026-09-27 (the user's item 8).
        // The radios asked which file the PM wanted and the answer changed
        // nothing but a flash word — one creation already produces the document,
        // the visit, the engineer link, the Word file and the PDF. The last step
        // now NAMES those outputs instead of asking about them. The map's
        // `formats` are untouched and `format` is still validated on the
        // request, defaulted from the map when absent.

        if ($stepped) {
            $names[] = 'step';
        }

        foreach (CockpitDocumentFormPresenter::documentFieldMap()[$module]['groups'] as $group) {
            $groupStep = $group['step'] ?? null;

            if ($stepped && $groupStep === null) {
                continue;
            }

            foreach ($group['fields'] as $field) {
                if (in_array('prohibited', $field['rules'], true)) {
                    continue;
                }

                $isList = in_array('array', $field['rules'], true);

                // A carried LIST field renders one hidden input per SELECTED
                // value, so an unselected one contributes no name at all.
                if ($stepped && $groupStep !== $step && $isList) {
                    continue;
                }

                $names[] = $isList ? $field['key'].'[]' : $field['key'];
            }
        }

        return array_values(array_unique($names));
    }

    private function generate(Project $project, array $payload, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user())
            ->post(route('projects.cockpit.documents.store', $project), $payload);
    }

    // ── Closed at rest: ONE control, and its copy ───────────────────────────

    /**
     * RENAMED AND RE-EXPECTED BY PLAN 46.3-01, CITING D-05 (requirement DL-05).
     *
     * WAS `test_every_module_closed_offers_exactly_one_control_reading_generate_document()`,
     * asserting the copy `Generate document`. That copy reads like it PRODUCES
     * A FILE; it opens a form. The Word/PDF radios behind it have existed since
     * 46.2-05, and the user asked "can output be word and pdf" while looking at
     * a page that already did both — so the affordance, not the capability, was
     * the defect.
     *
     * The copy is now DERIVED from the module's own `$offered` format set, so
     * this test asserts the derivation rather than a string: the Worksheet must
     * read `Create document — Word` and must NOT promise the PDF that DC-07
     * says does not exist. Nothing about which formats are offered changed.
     *
     * The exactly-one-control, no-`<form`-when-closed and `action=generate`
     * assertions in this method are UNTOUCHED. Recorded as A-4 in
     * 46.3-COUNT-LEDGER.md; the fence check the old docblock described in prose
     * is now executable in
     * `test_the_closed_control_copy_collides_with_no_fence_entry()`.
     */
    public function test_every_module_closed_offers_exactly_one_control_that_reads_as_opening_a_form(): void
    {
        $project = $this->project();

        foreach (array_keys(CockpitModulePresenter::moduleMap()) as $module) {
            $block = $this->docForm($project, $module);

            $this->assertNotSame('', $block, "{$module} renders no document block — the cockpit would generate nothing.");

            $this->assertSame(
                1,
                substr_count($block, 'cav-qa__control'),
                "{$module} must offer exactly ONE control when closed."
            );

            // READS AS OPENING A FORM, NOT AS PRODUCING A FILE.
            $this->assertStringContainsString('Create document', $block);
            $this->assertStringNotContainsString(
                'Generate document',
                $block,
                "{$module}'s CLOSED control still reads as producing a file. The submit button on ".
                'the OPEN form keeps that copy, because it genuinely does generate.'
            );

            // AND IT NAMES ONLY FORMATS THE MODULE ACTUALLY OFFERS. Read off
            // `documentFieldMap()` — the same source the Blade reads — so the
            // copy and the offer cannot drift into disagreement.
            $formats = CockpitDocumentFormPresenter::documentFieldMap()[$module]['formats'] ?? [];
            $labels  = ['word' => 'Word', 'pdf' => 'PDF'];

            foreach ($labels as $key => $label) {
                if (($formats[$key] ?? null) !== null) {
                    $this->assertStringContainsString(
                        $label,
                        $block,
                        "{$module} offers {$label} and the closed control does not say so — which ".
                        'is the undiscoverable capability D-05 exists to fix.'
                    );

                    continue;
                }

                $this->assertStringNotContainsString(
                    $label,
                    $block,
                    "{$module} does NOT offer {$label}, and the closed control must not promise ".
                    'it. The Worksheet PDF does not exist (DC-07) and the open form SAYS so; the '.
                    'closed control must not contradict that.'
                );
            }

            // Closed is an ANCHOR to `?action=generate`, never a form: the form
            // is a URL away, not a widget away.
            $this->assertSame(0, substr_count($block, '<form'), "{$module} discloses a form before it was asked to.");
            $this->assertStringContainsString('action=generate', $block);
        }
    }

    /**
     * THE COPY-VS-FENCE CHECK, MADE EXECUTABLE (Plan 46.3-01, D-05 / DL-05).
     *
     * 46.2-05 made this check by hand before choosing `Generate document` and
     * wrote the result in a comment. A comment cannot fail, so the next copy
     * edit — this one — could have collided with a deferred affordance silently.
     * `CockpitReadOnlyFenceTest::test_none_of_the_deferred_affordances_appears()`
     * would have caught it, but only as an unexplained red in a different file;
     * here it fails where the copy lives, with the reason attached.
     *
     * THE RULE THIS ENCODES: if the chosen copy collides, CHOOSE DIFFERENT
     * COPY. A fence entry is never lifted for a label — an entry comes off that
     * list only when the phase ships the affordance the entry banned.
     *
     * The list is read out of the fence test by reflection rather than copied,
     * because a second copy of 21 strings is a second thing to drift.
     */
    public function test_the_closed_control_copy_collides_with_no_fence_entry(): void
    {
        $fence = new \ReflectionClass(CockpitReadOnlyFenceTest::class);

        /** @var array<string, string> $deferred */
        $deferred = $fence->getConstant('DEFERRED_AFFORDANCES');
        /** @var list<string> $markup */
        $markup = $fence->getConstant('FORBIDDEN_MARKUP');

        // The pins are re-taken here too: a check against a list that silently
        // shrank would be a check against nothing.
        $this->assertCount(20, $deferred, 'DEFERRED_AFFORDANCES is no longer 20 (Plan 47-03 lifted "Download" 21 -> 20) — see 46.3-COUNT-LEDGER.md C-2.');
        $this->assertCount(2, $markup, 'FORBIDDEN_MARKUP is no longer 2 — see 46.3-COUNT-LEDGER.md C-1.');

        $project = $this->project();
        $judged  = 0;

        foreach (array_keys(CockpitModulePresenter::moduleMap()) as $module) {
            $block = $this->docForm($project, $module);
            $judged++;

            foreach ($deferred as $copy => $owner) {
                $this->assertStringNotContainsString(
                    $copy,
                    $block,
                    "The closed control's copy on {$module} contains \"{$copy}\", which is deferred ".
                    "to {$owner}. CHOOSE DIFFERENT COPY — never lift a fence entry for a label."
                );
            }

            foreach ($markup as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $block, "The closed control block contains {$forbidden}.");
            }
        }

        $this->assertSame(count(CockpitModulePresenter::moduleMap()), $judged, 'Not every module was judged.');
    }

    /**
     * THE SAME CHECK, FOR THE WIZARD'S OWN COPY (Plan 46.5-04).
     *
     * `Next`, `Back`, `Step 1 of 3` and every `step_titles` entry are checked as
     * SUBSTRINGS against all 21 `DEFERRED_AFFORDANCES` keys and both
     * `FORBIDDEN_MARKUP` entries, on EVERY step of EVERY document — because the
     * open form is where those strings actually render and the closed-control
     * check above never sees them.
     *
     * The rule is unchanged: if the chosen copy collides, CHOOSE DIFFERENT COPY.
     * A FENCE ENTRY IS NEVER LIFTED FOR A LABEL. The list stays 21, and
     * `<select` stays banned — the wizard needed no dropdown.
     */
    public function test_the_wizard_copy_collides_with_no_fence_entry(): void
    {
        $fence = new \ReflectionClass(CockpitReadOnlyFenceTest::class);

        /** @var array<string, string> $deferred */
        $deferred = $fence->getConstant('DEFERRED_AFFORDANCES');
        /** @var list<string> $markup */
        $markup = $fence->getConstant('FORBIDDEN_MARKUP');

        $this->assertCount(20, $deferred, 'DEFERRED_AFFORDANCES is no longer 20 (Plan 47-03 lifted "Download" 21 -> 20) — see 46.3-COUNT-LEDGER.md C-2.');
        $this->assertCount(2, $markup, 'FORBIDDEN_MARKUP is no longer 2 — see 46.3-COUNT-LEDGER.md C-1.');

        $project = $this->project();
        $this->resources();

        $states = 0;

        foreach (array_keys(CockpitDocumentFormPresenter::documentFieldMap()) as $module) {
            foreach ($this->stepQueries($module) as $query) {
                $form = $this->docForm($project, $module, ['action' => 'generate'] + $query);
                $states++;

                foreach ($deferred as $copy => $owner) {
                    $this->assertStringNotContainsString(
                        $copy,
                        $form,
                        "The open wizard on {$module} contains \"{$copy}\", which is deferred to {$owner}. ".
                        'CHOOSE DIFFERENT COPY — never lift a fence entry for a label.'
                    );
                }

                foreach ($markup as $forbidden) {
                    $this->assertStringNotContainsString($forbidden, $form, "The open wizard contains {$forbidden}.");
                }
            }
        }

        $this->assertSame(8, $states, 'Every one of the eight form states was judged for copy.');
    }

    /**
     * THE STEPPING ITSELF, ASSERTED ON EVERY STATE (Plan 46.5-04, GCW-02).
     *
     * `Next` on every step but the last, `Back` on every step but the first,
     * `Generate document` on the last ONLY. A wizard whose Next button survived
     * onto the final screen, or whose submit appeared on step 1, would be a
     * creation a PM could trigger two screens early.
     */
    public function test_next_back_and_the_submit_appear_only_where_they_belong(): void
    {
        $project = $this->project();
        $this->resources();

        $module = ProjectDeliverable::KEY_SITE_SURVEY;
        $steps  = $this->wizard()->stepsFor($module);
        $states = 0;

        foreach ($steps as $position => $step) {
            $form   = $this->docForm($project, $module, ['action' => 'generate', 'step' => $step]);
            $states++;

            $isFirst = $position === 0;
            $isLast  = $position === count($steps) - 1;

            $this->assertSame($isLast ? 0 : 1, substr_count($form, 'value="next"'), "Step {$step}: Next.");
            $this->assertSame($isFirst ? 0 : 1, substr_count($form, 'value="back"'), "Step {$step}: Back.");
            $this->assertSame($isLast ? 1 : 0, substr_count($form, 'value="create"'), "Step {$step}: the submit.");
            $this->assertSame($isLast ? 1 : 0, substr_count($form, 'Generate document'), "Step {$step}: the submit copy.");

            // The progress line names the step the PM is on and the total.
            $this->assertStringContainsString(
                'Step '.($position + 1).' of '.count($steps),
                $form,
                "Step {$step}: the progress line."
            );

            // And the map's own title for it, never a second copy of the words.
            $this->assertStringContainsString(
                (string) $this->wizard()->stepTitle($module, $step),
                $form,
                "Step {$step}: the map's step title."
            );
        }

        $this->assertSame(3, $states, 'Three steps, every one rendered.');
    }

    public function test_the_control_is_absent_from_the_files_and_notes_tabs(): void
    {
        $project = $this->project();

        foreach (['files', 'notes'] as $tab) {
            $this->assertSame(
                '',
                $this->docForm($project, ProjectDeliverable::KEY_RAMS, ['tab' => $tab]),
                "The document form must not appear on the {$tab} tab."
            );
        }
    }

    // ── Opened: the map's fields, and nothing else ──────────────────────────

    public function test_the_open_form_renders_exactly_the_controls_the_map_implies(): void
    {
        $project = $this->project();
        $this->resources();
        $this->reviewedPackage($project);

        $judged = 0;
        $states = 0;

        foreach (array_keys(CockpitDocumentFormPresenter::documentFieldMap()) as $module) {
            $judged++;

            // EVERY STEP, NOT THE FIRST ONE (Plan 46.5-04). The visible controls
            // change per step and the rest ride as hidden carry inputs, so the
            // NAME SET is still the whole document on every state — which is
            // what lets `intent=create` re-validate all of them.
            foreach ($this->stepQueries($module) as $query) {
                $form = $this->docForm($project, $module, ['action' => 'generate'] + $query);
                $states++;

                $this->assertNotSame('', $form, "{$module} disclosed no form.");

                $this->assertSame(1, substr_count($form, '<form'), "{$module} must disclose exactly one form.");
                $this->assertStringContainsString('method="POST"', $form);

                $expected = $this->expectedControlNames($module, $query['step'] ?? null);
                $actual   = $this->controlNames($form);

                sort($expected);
                sort($actual);

                $this->assertSame(
                    $expected,
                    $actual,
                    "{$module} on step ".($query['step'] ?? 'none').
                    " does not render exactly the controls DOCUMENT_FIELD_MAP implies."
                );
            }
        }

        $this->assertSame(
            count(CockpitDocumentFormPresenter::documentFieldMap()),
            $judged,
            'Every document in the map was judged.'
        );

        // SIX STATES: site survey 3 + worksheet 1 + RAMS 1 + O&M 1. Asserted as
        // a COUNT so a change that collapses the wizard back to one screen goes
        // red here rather than passing quietly on a single render.
        $this->assertSame(8, $states, 'Eight form states exist across the four documents: 3 survey + 1 worksheet + 3 RAMS + 1 O&M.');
    }

    public function test_every_group_legend_in_the_map_is_rendered_as_a_fieldset_legend(): void
    {
        $project = $this->project();
        $this->resources();

        $seen = [];

        foreach (CockpitDocumentFormPresenter::documentFieldMap() as $module => $definition) {
            foreach ($this->stepQueries($module) as $query) {
                $form = $this->docForm($project, $module, ['action' => 'generate'] + $query);
                $step = $query['step'] ?? null;

                foreach ($definition['groups'] as $group) {
                    $groupStep = $group['step'] ?? null;

                    // A `step => null` GROUP BELONGS TO NO STEP AND RENDERS ON
                    // NONE. That is 46.5 D-03 in an assertion: the Comms room
                    // group left THIS FORM and nothing else — it is still
                    // captured on site, still in the Word document and still in
                    // the survey->install carry-forward, none of which this
                    // phase edits.
                    if ($step !== null && $groupStep === null) {
                        $this->assertStringNotContainsString(
                            $group['legend'],
                            $form,
                            "{$module} renders the {$group['legend']} group, which carries step => null."
                        );

                        continue;
                    }

                    if ($step !== null && $groupStep !== $step) {
                        continue;
                    }

                    $this->assertStringContainsString($group['legend'], $form, "{$module} lost the {$group['legend']} group.");
                    $seen[$module.':'.$group['legend']] = true;
                }

                // The intro is the document's, not the step's, so it is on every
                // state — a PM who lands on step 2 from a bookmark still reads
                // what the document is for.
                $this->assertStringContainsString($definition['intro'], $form, "{$module} lost its intro copy.");
            }
        }

        // FOURTEEN OF THE MAP'S FIFTEEN GROUPS RENDER. The fifteenth is Comms
        // room, and its absence is the decision rather than an omission.
        //
        // 13 -> 14 BECAUSE A GROUP ARRIVED, NOT BECAUSE A NUMBER WAS RELAXED:
        // Plan 46.5-05 added RAMS's `Job summary` group (D-04) and gave RAMS's
        // other five groups their steps. The comms-room exclusion is unchanged
        // and still the only one.
        //
        // 14 -> 16 BY PLAN 46.5-06, AND BOTH ARE NAMED: the site survey's
        // `The visit` (step 1, D-02's "dates ,site contact and engineer") and
        // `Spaces being surveyed` (step 3, D-02's "confirm space being surveys
        // (default all)"). Two groups arrived; nothing was relaxed.
        $this->assertCount(16, $seen, 'Every group with a step renders on the step it names.');
    }

    /**
     * D-03, enforced in the rendering: the form never asks a question the
     * project already answers, and it never turns the answer into an input a
     * `prohibited` rule would then reject.
     */
    public function test_a_display_only_field_is_rendered_as_text_and_never_as_an_input(): void
    {
        $project = $this->project();
        $this->resources();

        $printed = 0;

        foreach (CockpitDocumentFormPresenter::documentFieldMap() as $module => $definition) {
            // ON EVERY STEP, because "never a control" has to hold on all of
            // them: the carry-forward writes hidden inputs, and a display-only
            // field slipping into that loop would be a `prohibited` value
            // submitted by the page itself — a failure the PM could not fix.
            foreach ($this->stepQueries($module) as $query) {
                $form  = $this->docForm($project, $module, ['action' => 'generate'] + $query);
                $names = $this->controlNames($form);
                $step  = $query['step'] ?? null;

                foreach ($definition['groups'] as $group) {
                    foreach ($group['fields'] as $field) {
                        if (! in_array('prohibited', $field['rules'], true)) {
                            continue;
                        }

                        $this->assertNotContains(
                            $field['key'],
                            $names,
                            "{$module}.{$field['key']} is display-only and must not be a control."
                        );

                        // And it is PRINTED on the step its group names, so the
                        // assertion above is not passing over an absent field.
                        if ($step === null || ($group['step'] ?? null) === $step) {
                            $this->assertStringContainsString($field['label'], $form);
                            $printed++;
                        }
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $printed, 'A display-only field is printed somewhere.');

        // And the page's own values really do reach it, so the assertion above
        // is not passing over an empty block.
        // ON RAMS'S LAST STEP since Plan 46.5-05: the read-only project facts
        // sit with the Format radios and the submit, so a PM confirms what the
        // app already knows at the moment they commit.
        $form = $this->docForm(
            $project,
            ProjectDeliverable::KEY_RAMS,
            ['action' => 'generate'] + $this->lastStepQuery(ProjectDeliverable::KEY_RAMS),
        );
        $this->assertStringContainsString('Document Cockpit Job', $form);
        $this->assertStringContainsString('Northbank Media', $form);
    }

    // ── The worksheet: zero enterable fields, and the DC-07 sentence ────────

    public function test_the_worksheet_explains_itself_and_offers_no_pdf(): void
    {
        $project = $this->project();

        $form = $this->docForm($project, ProjectDeliverable::KEY_WORKSHEET, ['action' => 'generate']);

        // The map's own intro, rendered rather than replaced by invented inputs.
        $this->assertStringContainsString('there is nothing to fill in here', $form);

        // DC-07 is SAID, not papered over with a control that would fail.
        $this->assertStringContainsString('DC-07', $form);
        $this->assertStringContainsString('PDF is not available', $form);

        // NO FORMAT RADIO AT ALL SINCE 2026-09-27 (item 8) — and the worksheet
        // still names WORD and still refuses to name a PDF it does not have.
        $this->assertSame(0, substr_count($form, 'name="format"'), 'The format radios are back.');
        $this->assertStringContainsString('Generating creates the engineer link and Word.', $form);
        $this->assertStringNotContainsString('and PDF.', $form, 'The worksheet must not be promised a PDF.');

        // Every other document names BOTH files — ON ITS LAST STEP, which is
        // where the output line lives since Plan 46.5-04. RAMS and the O&M issue
        // no engineer link (D-04), so theirs names the two files only; the site
        // survey names the link as well.
        $expected = [
            ProjectDeliverable::KEY_RAMS        => 'Generating creates Word and PDF.',
            ProjectDeliverable::KEY_OM          => 'Generating creates Word and PDF.',
            ProjectDeliverable::KEY_SITE_SURVEY => 'Generating creates the engineer link, Word and PDF.',
        ];

        foreach ($expected as $module => $sentence) {
            $other = $this->docForm($project, $module, ['action' => 'generate'] + $this->lastStepQuery($module));

            $this->assertSame(0, substr_count($other, 'name="format"'), "{$module} still asks for a format.");
            $this->assertStringContainsString($sentence, $other, "{$module} does not name its outputs.");
            $this->assertStringNotContainsString('DC-07', $other);
        }
    }

    public function test_the_worksheet_cannot_be_asked_for_a_pdf_even_by_hand(): void
    {
        Bus::fake();

        $project = $this->project();

        $this->generate($project, ['module' => ProjectDeliverable::KEY_WORKSHEET, 'format' => 'pdf'])
            ->assertSessionHasErrors('format');

        $this->assertSame(0, Worksheet::count(), 'A rejected format must not generate anything.');
    }

    // ── LR-04: names only ───────────────────────────────────────────────────

    public function test_a_resource_option_renders_a_name_and_never_an_email_or_a_phone(): void
    {
        $project = $this->project();
        $this->resources();

        // EVERY STEP, NOT THE DEFAULT ONE. Since Plan 46.5-05 RAMS's resource
        // lists live on step 2, and LR-04's prohibition ("never an email,
        // never a phone") has to hold on ALL THREE screens — a private value
        // leaking onto a step this test never rendered is exactly the vacuous
        // pass the phase's count assertions exist to stop.
        $forms = [];

        foreach ($this->stepQueries(ProjectDeliverable::KEY_RAMS) as $query) {
            $forms[] = $this->docForm($project, ProjectDeliverable::KEY_RAMS, ['action' => 'generate'] + $query);
        }

        $this->assertCount(3, $forms, 'All three RAMS steps were judged for LR-04.');

        $form = implode("
", $forms);

        foreach (['Dev Chandra', 'Marie Okonkwo', 'Tomas Reyes'] as $name) {
            $this->assertStringContainsString($name, $form, 'The resource list supplies names.');
        }

        foreach (LabourResource::all() as $resource) {
            foreach ($forms as $index => $one) {
                $this->assertStringNotContainsString($resource->email, $one, "LR-04: never an email (step {$index}).");
                $this->assertStringNotContainsString($resource->phone, $one, "LR-04: never a phone (step {$index}).");
            }
        }

        // The selected NAME is the value, never an id — the list is a
        // convenience source, not a foreign key (T-46.2-11, accepted).
        $this->assertStringContainsString('value="Dev Chandra"', $form);
        $this->assertStringNotContainsString('labour_resource_id', $form);
    }

    // ── The write: persistence where the generator reads ────────────────────

    /**
     * THE ROUND TRIP, NOT AN ASSUMPTION. The submitted values are merged into
     * `extracted_data`, passed through `RamsReviewDataService::normalise()` and
     * saved — then RE-READ through `normalise()` again, which is what
     * `RamsController::generateFromProject` does. A key normalise() drops would
     * fail here rather than in a document nobody checked.
     */
    public function test_a_rams_submission_survives_normalisation_on_a_re_read(): void
    {
        Bus::fake();

        $project = $this->project();
        $package = $this->reviewedPackage($project);
        $this->resources();

        $this->generate($project, [
            'module'                 => ProjectDeliverable::KEY_RAMS,
            'format'                 => 'word',
            'planned_start_date'     => '2026-10-05',
            'planned_end_date'       => '2026-10-09',
            'planned_start_time'     => '0730',
            'working_hours'          => 'Monday-Friday, 07:30-17:00',
            'project_manager_name'   => 'Priya Mistry',
            'project_manager_phone'  => '0113 000 0000',
            'project_manager_email'  => 'priya@example.test',
            'lead_engineer_name'     => 'Dev Chandra',
            'lead_engineer_phone'    => '07700 900111',
            'additional_engineers'   => ['Marie Okonkwo'],
            'programmers'            => ['Tomas Reyes'],
            'contact_name'           => 'Sam Bright',
            'contact_phone'          => '07700 900444',
            'contact_email'          => 'sam.bright@example.test',
        ])->assertRedirect();

        // Re-read exactly as the generator does.
        $reviewed = app(RamsReviewDataService::class)->normalise($package->fresh()->extracted_data ?? []);

        $this->assertSame('2026-10-05', $reviewed['programme']['planned_start_date']);
        $this->assertSame('2026-10-09', $reviewed['programme']['planned_end_date']);
        $this->assertSame('0730', $reviewed['programme']['planned_start_time']);
        $this->assertSame('Priya Mistry', $reviewed['programme']['project_manager_name']);
        $this->assertSame('0113 000 0000', $reviewed['programme']['project_manager_phone']);
        $this->assertSame('priya@example.test', $reviewed['programme']['project_manager_email']);
        $this->assertSame('Dev Chandra', $reviewed['programme']['lead_engineer_name']);
        $this->assertSame('07700 900111', $reviewed['programme']['lead_engineer_phone']);
        $this->assertSame(['Marie Okonkwo'], $reviewed['programme']['additional_engineers']);
        $this->assertSame(['Tomas Reyes'], $reviewed['programme']['programmers']);
        $this->assertSame('Sam Bright', $reviewed['site_logistics']['contact_name']);
        $this->assertSame('07700 900444', $reviewed['site_logistics']['contact_phone']);
        $this->assertSame('sam.bright@example.test', $reviewed['site_logistics']['contact_email']);

        // NON-DESTRUCTIVE. `normalise()` returns a fixed key set, so writing its
        // output over `extracted_data` would DELETE every sibling key the review
        // form and the AI pipeline put there. The merge preserves them.
        $this->assertSame(
            'A prose overview the normaliser does not carry.',
            $package->fresh()->extracted_data['overview'] ?? null,
            'Persisting the form must not amputate extracted_data.'
        );

        // `form_data.working_hours` is the map's ONE documented exception to the
        // normalised-key rule, and its home only exists AFTER the generator
        // creates the document — so it is patched onto that row, not the package.
        $rams = RamsDocument::where('project_id', $project->id)->sole();
        $this->assertSame('Monday-Friday, 07:30-17:00', $rams->form_data['working_hours'] ?? null);
    }

    public function test_an_om_submission_persists_the_handover_date_and_carries_the_draft_flag(): void
    {
        Bus::fake();

        $project = $this->project();

        $this->generate($project, [
            'module'        => ProjectDeliverable::KEY_OM,
            'format'        => 'word',
            'handover_date' => '2026-11-20',
            'draft'         => '1',
        ]);

        $this->assertSame('2026-11-20', $project->fresh()->handover_date?->format('Y-m-d'));

        // The draft flag travels to the EXISTING controller, which persists it
        // inside extracted_data so a Retry re-dispatch keeps it.
        $manual = OmManual::where('project_id', $project->id)->first();

        if ($manual !== null) {
            $this->assertTrue((bool) ($manual->extracted_data['_draft_mode'] ?? false));
        }
    }

    public function test_a_site_survey_submission_writes_the_columns_the_pdf_reads(): void
    {
        $project = $this->project();

        $this->generate($project, [
            'module'                   => ProjectDeliverable::KEY_SITE_SURVEY,
            'format'                   => 'word',
            // ONE DATE, TWO COLUMNS (2026-09-27, item 1). `survey_date` is no
            // longer asked; the one visible date writes it through
            // `also_target`, and the assertion below proves the column filled.
            'visit_scheduled_date'     => '2026-10-01',
            'surveyor_name'            => 'Kit Farrow',
            'site_contact_name'        => 'Sam Bright',
            'site_contact_phone'       => '07700 900444',
            'general_notes'            => 'Lift access booked.',
            'site_access_notes'        => 'Report to the east gate.',
            // A CLOSED CHOICE NOW, and the stored value is the sentence the Word
            // document, the survey PDF, the engineer link and the carry-forward
            // all print.
            'parking_restraints'       => 'No parking',
            'delivery_routes'          => 'Goods lift only.',
            'distance_from_base_miles' => '42',
            'distance_from_base_notes' => 'M1 then A64.',
            'comms_room_access_status' => 'outsourced',
            'comms_room_access_notes'  => 'Facilities need 48 hours.',
            'office_review_notes'      => 'Client copy only.',
        ])->assertRedirect();

        $survey = SiteSurvey::where('project_id', $project->id)
            ->whereNull('superseded_at')
            ->sole();

        $this->assertSame('Kit Farrow', $survey->surveyor_name);
        $this->assertSame('Sam Bright', $survey->site_contact_name);
        $this->assertSame('Report to the east gate.', $survey->site_access_notes);
        // THE SECOND TARGET, PROVEN ON A REAL WRITE. The form asked ONE date and
        // `survey.survey_date` — which the Word document reads — is filled from
        // it. Without this, item 1 would have quietly emptied the document's own
        // date.
        $this->assertSame('2026-10-01', $survey->survey_date?->format('Y-m-d'));
        $this->assertSame('No parking', $survey->parking_restraints);
        $this->assertSame('outsourced', $survey->comms_room_access_status);
        $this->assertSame('42', (string) $survey->distance_from_base_miles);
        $this->assertSame('Client copy only.', $survey->office_review_notes);
    }

    // ── 2026-09-27: the eight pieces of live-wizard feedback, RENDERED ──────

    /**
     * PARKING IS THREE RADIOS, AND THE FREE-TEXT BOX IS GONE (item 5).
     *
     * RENDERED, not inferred from the map — the map is asserted separately in
     * `CockpitDocumentFormPresenterTest`. The step is read off the field's own
     * group so this cannot drift onto the wrong screen.
     */
    public function test_parking_renders_as_three_radios_and_no_free_text_box(): void
    {
        $project = $this->project();

        // EVERY STEP, so "the radios render" cannot be true on one screen and
        // false on the two the user also walks.
        $seenOn = [];

        foreach ($this->stepQueries(ProjectDeliverable::KEY_SITE_SURVEY) as $query) {
            $form = $this->docForm($project, ProjectDeliverable::KEY_SITE_SURVEY, ['action' => 'generate'] + $query);

            // THE FREE-TEXT BOX IS GONE FROM EVERY STATE.
            $this->assertDoesNotMatchRegularExpression(
                '/<textarea[^>]*name="parking_restraints"/s',
                $form,
                'Parking still renders a textarea on step '.($query['step'] ?? 'none').'.',
            );

            // AND NO DROPDOWN, ANYWHERE — FORBIDDEN_MARKUP entry 1 stands at 2.
            $this->assertStringNotContainsString('<select', $form);

            foreach (['Parking onsite', 'No parking', 'Unknown'] as $option) {
                if (str_contains($form, 'value="'.$option.'"')) {
                    $seenOn[$option][] = $query['step'] ?? 0;
                }
            }
        }

        foreach (['Parking onsite', 'No parking', 'Unknown'] as $option) {
            $this->assertArrayHasKey($option, $seenOn, "The parking option [{$option}] renders on no step at all.");
            $this->assertCount(
                1,
                $seenOn[$option],
                "The parking option [{$option}] renders on ".count($seenOn[$option]).' steps. A control belongs '
                .'to exactly one.',
            );
        }

        // On its own step it is a RADIO group, with the legend the map gives it.
        $step = $seenOn['No parking'][0];
        $form = $this->docForm($project, ProjectDeliverable::KEY_SITE_SURVEY, ['action' => 'generate', 'step' => $step]);

        $this->assertMatchesRegularExpression(
            '/<input\s+type="radio"\s+name="parking_restraints"\s+value="No parking"/',
            $form,
            'Parking is not a radio on the step that asks it.',
        );
        $this->assertStringContainsString('Parking arrangements', $form, 'The legend went with the control type.');
    }

    /**
     * THE ENGINEER EMPTY STATE READS AS THE CONTROL'S OWN REPLY (item 4).
     *
     * ⚠ RENDERED WITH NO `LabourResource` ROWS AT ALL, which is LIVE'S OWN STATE
     * and the one the user was looking at. Every other test in this file calls
     * `resources()` first, so this state was rendered by nothing — which is
     * exactly how it shipped looking like stray prose.
     *
     * IT IS NOT HIDDEN AND THE CONTROL IS NOT HIDDEN. Both halves asserted: the
     * fieldset and its legend still render, and the message carries the empty
     * class rather than the value class.
     */
    public function test_the_engineer_empty_state_is_the_controls_own_and_is_never_hidden(): void
    {
        $this->assertSame(0, LabourResource::count(), 'This proof needs live\'s own no-engineers state.');

        $project = $this->project();

        $form = $this->docForm($project, ProjectDeliverable::KEY_SITE_SURVEY, [
            'action' => 'generate',
            'step'   => 1,
        ]);

        // THE CONTROL IS STILL THERE — its legend and its fieldset.
        $this->assertStringContainsString('Engineer', $form, 'The engineer control was hidden instead of answered.');

        // AND THE MESSAGE IS THE CONTROL'S OWN EMPTY STATE, NOT AN ANSWER.
        $this->assertStringContainsString(
            '<span class="cav-qa__empty">Nobody active is on file for this. Add people under Labour resources.</span>',
            $form,
            'The empty state is not carrying its own class, so it renders as a value would.',
        );

        $this->assertStringNotContainsString(
            '<span class="cav-qa__value">Nobody active',
            $form,
            'The empty state still wears the VALUE class — that is the defect the user reported.',
        );

        // NON-VACUITY: with engineers on file the message is absent and the
        // checkboxes are present, so the assertion above is about the EMPTY
        // state rather than about a string that never renders.
        $this->resources();

        $filled = $this->docForm($project, ProjectDeliverable::KEY_SITE_SURVEY, ['action' => 'generate', 'step' => 1]);

        $this->assertStringNotContainsString('cav-qa__empty', $filled);
        $this->assertStringContainsString('name="visit_engineers[]"', $filled);
    }

    /**
     * GREEN IS ON THE GENERATE CONTROLS AND ON NOTHING ELSE (item 9).
     *
     * Both states of every document: the closed opener and the open form's
     * submit wear `cav-qa__go`; Next, Back and Cancel do not. Asserted as
     * MARKUP here and as a token pair in `CockpitVisualTest`, because a class
     * that resolves to no token fails silently in CSS.
     */
    public function test_only_the_generate_controls_are_green(): void
    {
        $project = $this->project();
        $this->resources();
        $judged = 0;

        foreach (array_keys(CockpitDocumentFormPresenter::documentFieldMap()) as $module) {
            // CLOSED: one control, and it is green.
            $closed = $this->docForm($project, $module);

            $this->assertSame(
                1,
                substr_count($closed, 'class="cav-qa__control cav-qa__go"'),
                "{$module}'s closed control is not the one green control."
            );

            foreach ($this->stepQueries($module) as $query) {
                $form   = $this->docForm($project, $module, ['action' => 'generate'] + $query);
                $isLast = $query === $this->lastStepQuery($module);

                // The submit is green on the last step; Next is NOT green on the
                // others, and Back is never green.
                $this->assertSame(
                    $isLast ? 1 : 0,
                    substr_count($form, 'class="cav-qa__control cav-qa__go" type="submit" name="intent" value="create"'),
                    "{$module} step ".($query['step'] ?? 'none').': the green submit is on the wrong step.'
                );

                foreach (['next', 'back'] as $intent) {
                    $this->assertStringNotContainsString(
                        'cav-qa__go" type="submit" name="intent" value="'.$intent.'"',
                        $form,
                        "{$module}: [{$intent}] is green, and it generates nothing."
                    );
                }

                $judged++;
            }
        }

        $this->assertSame(8, $judged, 'This proof rendered '.$judged.' open states across four documents.');
    }

    // ── T-46.2-12: the module key is never trusted ─────────────────────────

    public function test_an_unknown_or_hostile_module_is_a_validation_error_and_never_a_500(): void
    {
        Bus::fake();

        $project = $this->project();

        foreach ([
            '../../etc/passwd',
            'cable_schedule',
            'App\\Http\\Controllers\\RamsController',
            '<script>alert(1)</script>',
            str_repeat('a', 4000),
            '',
        ] as $hostile) {
            $this->generate($project, ['module' => $hostile, 'format' => 'word'])
                ->assertRedirect()
                ->assertSessionHasErrors('module');
        }

        $this->generate($project, ['format' => 'word'])->assertSessionHasErrors('module');

        $this->assertSame(0, RamsDocument::count());
        $this->assertSame(0, Worksheet::count());
        $this->assertSame(0, OmManual::count());
        $this->assertSame(0, SiteSurvey::count());
    }

    // ── T-46.2-13: every lookup is scoped to the route-bound project ───────

    public function test_a_survey_belonging_to_another_project_is_never_written(): void
    {
        $mine   = $this->project();
        $theirs = Project::factory()->create(['name' => 'Somebody Else']);

        $foreign = SiteSurvey::create([
            'project_id'    => $theirs->id,
            'user_id'       => $this->user()->id,
            'project_name'  => $theirs->name,
            'status'        => 'draft',
            'surveyor_name' => 'Untouched',
        ]);

        $this->post($mine, [
            'module'        => ProjectDeliverable::KEY_SITE_SURVEY,
            'format'        => 'word',
            'surveyor_name' => 'Kit Farrow',
            // An id in the payload must select NOTHING.
            'site_survey_id' => $foreign->id,
            'survey_id'      => $foreign->id,
            'id'             => $foreign->id,
        ]);

        $this->assertSame('Untouched', $foreign->fresh()->surveyor_name);
        $this->assertSame($theirs->id, $foreign->fresh()->project_id);
    }

    public function test_a_display_only_value_cannot_be_overwritten_by_hand(): void
    {
        Bus::fake();

        $project = $this->project();
        $this->reviewedPackage($project);

        $this->generate($project, [
            'module'       => ProjectDeliverable::KEY_RAMS,
            'format'       => 'word',
            'project_name' => 'Not the project name',
            'client_name'  => 'Not the client',
        ])->assertSessionHasErrors(['project_name', 'client_name']);

        $this->assertSame('Document Cockpit Job', $project->fresh()->name);
        $this->assertSame('Northbank Media', $project->fresh()->client_name);
    }

    // ── Auth and the flag ─────────────────────────────────────────────────

    public function test_a_guest_cannot_post_and_the_flag_off_is_a_404(): void
    {
        $project = $this->project();

        $this->post(route('projects.cockpit.documents.store', $project), [
            'module' => ProjectDeliverable::KEY_WORKSHEET,
            'format' => 'word',
        ])->assertRedirect(route('login'));

        config(['cockpit.enabled' => false]);

        $this->generate($project, ['module' => ProjectDeliverable::KEY_WORKSHEET, 'format' => 'word'])
            ->assertNotFound();

        $this->assertSame(0, Worksheet::count());
    }

    /** The response is a REDIRECT, never JSON — this page has no API. */
    public function test_the_write_answers_with_a_redirect_and_never_json(): void
    {
        Bus::fake();

        $project = $this->project();

        $response = $this->generate($project, [
            'module' => ProjectDeliverable::KEY_WORKSHEET,
            'format' => 'word',
        ]);

        $response->assertRedirect();
        $this->assertStringNotContainsString('application/json', (string) $response->headers->get('content-type'));
    }

    // ── T-46.2-14: CSRF ───────────────────────────────────────────────────

    public function test_the_disclosed_form_carries_a_csrf_token_on_every_document(): void
    {
        $project = $this->project();
        $this->resources();

        foreach (array_keys(CockpitDocumentFormPresenter::documentFieldMap()) as $module) {
            $form = $this->docForm($project, $module, ['action' => 'generate']);

            $this->assertStringContainsString('name="_token"', $form, "{$module}'s form would 419 in production.");
        }
    }

    /**
     * A TOKENLESS POST CANNOT BE DRIVEN FROM A TEST, SO THE MECHANISM IS ASSERTED
     * INSTEAD — and this is stated rather than quietly skipped.
     *
     * `Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::handle()` short-
     * circuits on `$this->runningUnitTests()`, so no feature test in this suite
     * can observe a 419; `withMiddleware()` does not change that, because the
     * bypass is inside the middleware and not in the stack. The repo's existing
     * answer is the STRUCTURAL one —
     * `CockpitReadOnlyFenceTest::test_every_form_in_the_region_carries_a_csrf_token()`
     * asserts a `_token` input per form in the DOM, which is exactly the thing
     * whose absence would 419 in production.
     *
     * So the two halves are asserted separately and both are real: the FORM
     * carries the token (above, and in the fence at an exact positive), and the
     * ROUTE is inside the group that checks it (here).
     */
    public function test_the_write_route_sits_inside_the_group_that_checks_the_token(): void
    {
        $route = collect(app('router')->getRoutes())
            ->first(fn ($route) => $route->getName() === 'projects.cockpit.documents.store');

        $this->assertNotNull($route);

        $middleware = $route->gatherMiddleware();

        $this->assertContains('web', $middleware, "The `web` group's VerifyCsrfToken is what makes @csrf load-bearing.");
        $this->assertContains('auth', $middleware, 'A document write is never anonymous.');
        $this->assertSame(['POST'], array_values(array_diff($route->methods(), ['OPTIONS'])));
    }

    // ── The tab travels, on the mechanism the visit acts already use ───────

    /**
     * ONE DEFINITION, NOW FIVE FORMS. `x-cockpit.tab-field` was written by Plan
     * 46.1-06 for the four visit acts; the document form carries the SAME
     * component rather than a second hidden field of its own, so the two cannot
     * drift about which tab is legal or how an unreal one is treated.
     *
     * The asymmetry between the two outcomes is deliberate and is stated in
     * `panelUrl()`: a FAILURE returns the PM to the tab the form was opened from,
     * because the form is there; a SUCCESS goes to Files, because that is where
     * the document appears.
     */
    public function test_the_form_carries_the_tab_it_was_opened_from(): void
    {
        $project = $this->project();

        $form = $this->docForm($project, ProjectDeliverable::KEY_RAMS, ['action' => 'generate']);

        $this->assertStringContainsString('<input type="hidden" name="tab" value="overview">', $form);
    }

    public function test_a_hostile_tab_is_dropped_rather_than_refusing_the_generation(): void
    {
        Bus::fake();

        $project = $this->project();

        $response = $this->generate($project, [
            'module' => ProjectDeliverable::KEY_WORKSHEET,
            'format' => 'word',
            'tab'    => '"><script>alert(1)</script>',
        ]);

        // A bad tab is not a reason to refuse a PM's generation — the ruling
        // CockpitTabPreservationTest already records for the four visit acts.
        $response->assertSessionHasNoErrors();
        $this->assertSame(1, Worksheet::where('project_id', $project->id)->count());

        foreach (['script', 'alert(1)'] as $fragment) {
            $this->assertStringNotContainsString($fragment, (string) $response->headers->get('location'));
        }
    }

    public function test_a_failure_returns_to_the_form_and_a_success_goes_to_the_files_tab(): void
    {
        Bus::fake();

        $project = $this->project();

        $this->generate($project, [
            'module' => ProjectDeliverable::KEY_WORKSHEET,
            'format' => 'word',
            'tab'    => 'overview',
        ])->assertRedirect(route('projects.cockpit', [
            'project' => $project,
            'module'  => ProjectDeliverable::KEY_WORKSHEET,
            'tab'     => 'files',
        ]));

        // No reviewed package: `RamsController::generateFromProject` reports it
        // itself, and its own redirect target is honoured rather than overridden.
        $this->generate($project, [
            'module' => ProjectDeliverable::KEY_RAMS,
            'format' => 'word',
            'tab'    => 'overview',
        ])->assertRedirect(route('projects.cockpit', [
            'project' => $project,
            'module'  => ProjectDeliverable::KEY_RAMS,
            'tab'     => 'overview',
            'action'  => 'generate',
        ]));

        $this->assertSame(0, RamsDocument::count(), 'A missing package must generate nothing.');
    }

    // ── The map-not-branches proof ────────────────────────────────────────

    /**
     * THE ASSERTION THIS WHOLE DESIGN EXISTS FOR.
     *
     * The component renders ANY document's field map by iterating `groups` and
     * switching on `type`. If it ever names a document, it has become four
     * hand-written panels wearing one filename, and the next change to this page
     * — its fifth — is a rewrite instead of a row edit.
     *
     * Blade comments are stripped first, so the file may still EXPLAIN itself in
     * prose; what it may not do is BRANCH.
     */
    public function test_the_component_names_no_document_and_switches_on_type_instead(): void
    {
        $path = resource_path('views/components/cockpit/doc-form.blade.php');

        $this->assertFileExists($path);

        $source = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents($path));

        foreach (array_keys(CockpitDocumentFormPresenter::documentFieldMap()) as $module) {
            foreach (["'{$module}'", "\"{$module}\""] as $literal) {
                $this->assertStringNotContainsString(
                    $literal,
                    $source,
                    "doc-form.blade.php names {$module}. Render from the map; never branch per document."
                );
            }
        }

        // It switches on the map's own closed type set instead.
        $this->assertStringContainsString('TYPE_', $source, 'The component resolves the map type constants.');
        $this->assertStringContainsString('@foreach', $source);

        // And the fence's two standing bans hold on the most input-heavy surface
        // this page has ever carried.
        $this->assertStringNotContainsString('<select', $source, 'FORBIDDEN_MARKUP still bans <select.');
        $this->assertStringNotContainsString('{!!', $source, 'Escaped output only (T-46.2-17).');
    }

    // ── Readiness: shown, never editable ─────────────────────────────────

    public function test_the_om_readiness_list_is_reported_and_never_editable(): void
    {
        $project = $this->project();

        $form = $this->docForm($project, ProjectDeliverable::KEY_OM, ['action' => 'generate']);

        $this->assertStringContainsString('cav-qa__ready', $form, 'The O&M renders its readiness list.');

        $readiness = $this->subtree($form, 'cav-qa__ready');

        $this->assertNotSame('', $readiness);
        $this->assertSame([], $this->controlNames($readiness), 'A readiness item is a thing to go and fix, never an input.');

        // The other three documents have no readiness source, so they render no
        // list rather than an empty one that would read as "everything is ready".
        foreach ([ProjectDeliverable::KEY_RAMS, ProjectDeliverable::KEY_WORKSHEET, ProjectDeliverable::KEY_SITE_SURVEY] as $module) {
            $this->assertStringNotContainsString(
                'cav-qa__ready',
                $this->docForm($project, $module, ['action' => 'generate'])
            );
        }
    }

    // ── Errors come back to the form, escaped ────────────────────────────

    public function test_a_validation_failure_re_renders_the_form_with_an_escaped_message(): void
    {
        $project = $this->project();
        $this->reviewedPackage($project);

        $from = route('projects.cockpit', [
            'project' => $project,
            'module'  => ProjectDeliverable::KEY_RAMS,
            'action'  => 'generate',
        ]);

        $this->from($from)
            ->generate($project, [
                'module'                => ProjectDeliverable::KEY_RAMS,
                'format'                => 'word',
                'planned_start_date'    => '<script>alert(1)</script>',
                'project_manager_email' => 'not-an-email',
            ])
            ->assertRedirect($from)
            ->assertSessionHasErrors(['planned_start_date', 'project_manager_email']);

        // ASSERTED ON THE RAW BYTES, NOT THE DECODED SUBTREE. `docForm()` runs
        // `html_entity_decode` so that class and copy assertions read naturally,
        // which would turn a correctly escaped `&lt;script&gt;` back into the
        // thing this test is looking for. Escaping has to be judged before the
        // decode or the assertion proves nothing.
        $raw = $this->actingAs($this->user())->get($from)->assertOk()->getContent();

        $this->assertStringContainsString('cav-qa__error', $raw);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $raw, 'A submitted value is never reflected raw.');
        $this->assertStringContainsString('&lt;script&gt;', $raw, 'It is reflected ESCAPED — so the absence above is escaping, not omission.');
    }

    public function test_an_unknown_action_value_discloses_no_form_and_is_never_echoed(): void
    {
        $project = $this->project();

        foreach (['generate-please', '<script>alert(1)</script>', str_repeat('z', 4000)] as $payload) {
            $body = $this->actingAs($this->user())
                ->get(route('projects.cockpit', [
                    'project' => $project,
                    'module'  => ProjectDeliverable::KEY_RAMS,
                    'action'  => $payload,
                ]))
                ->assertOk()
                ->getContent();

            $this->assertStringNotContainsString($payload, $body, 'A submitted ?action= value is never reflected.');
            $this->assertSame(
                0,
                substr_count($this->subtree($body, 'cav-qa'), '<form'),
                'Only the exact string `generate` discloses the form.'
            );
        }
    }

    // ── The format map is the inventory's, not a second opinion ──────────

    /**
     * THE SPACES STEP (Plan 46.5-06, D-02: "confirm space being surveys (default all)").
     *
     * DEFAULT-ALL IS A RENDER DECISION, NEVER A STORED ONE. Nothing is written
     * until the final step (GCW-03), so "all" cannot be a persisted default -
     * it is simply what the checkboxes show when `old()` is absent.
     */
    public function test_the_spaces_step_confirms_every_space_with_all_ticked_by_default(): void
    {
        $project = $this->project();
        $this->resources();
        $this->reviewedPackage($project);

        $form = $this->docForm($project, ProjectDeliverable::KEY_SITE_SURVEY, [
            'action' => 'generate',
            'step'   => 3,
        ]);

        $this->assertStringContainsString('Spaces being surveyed', $form, 'Step 3 does not confirm the spaces.');

        $spaces = array_column(
            app(\App\Services\ProjectContextResolver::class)->resolve($project->fresh())['rooms'],
            'room',
        );

        $this->assertNotSame([], $spaces, 'The fixture put no spaces on file, so this test would prove nothing.');

        $ticked = 0;

        foreach ($spaces as $space) {
            $this->assertMatchesRegularExpression(
                '/<input[^>]*name="visit_rooms\[\]"[^>]*value="'.preg_quote($space, '/').'"[^>]*checked/',
                $form,
                "The space [{$space}] is not offered TICKED on step 3. D-02 says default all.",
            );

            $ticked++;
        }

        $this->assertSame(count($spaces), $ticked, 'Every space on file was judged.');

        // A CHECKBOX, NOT A DROPDOWN. `<select` is still banned and was not
        // needed, so FORBIDDEN_MARKUP stays at 2.
        $this->assertStringNotContainsString('<select', $form);
    }

    public function test_a_project_with_no_spaces_says_so_rather_than_rendering_an_empty_fieldset(): void
    {
        $project = $this->project();
        $this->resources();

        $form = $this->docForm($project, ProjectDeliverable::KEY_SITE_SURVEY, [
            'action' => 'generate',
            'step'   => 3,
        ]);

        $this->assertStringContainsString('Spaces being surveyed', $form);
        $this->assertStringNotContainsString('name="visit_rooms[]"', $form);
        $this->assertStringContainsString('No spaces are on file for this project yet.', $form);
    }

    public function test_the_visit_fields_are_asked_on_step_one_and_the_spaces_on_step_three(): void
    {
        $project = $this->project();
        $this->resources();
        $this->reviewedPackage($project);

        $one   = $this->docForm($project, ProjectDeliverable::KEY_SITE_SURVEY, ['action' => 'generate', 'step' => 1]);
        $three = $this->docForm($project, ProjectDeliverable::KEY_SITE_SURVEY, ['action' => 'generate', 'step' => 3]);

        // D-02: "dates ,site contact and engineer" is ONE screen.
        $this->assertStringContainsString('name="visit_scheduled_date"', $one);
        $this->assertStringContainsString('name="visit_engineers[]"', $one);
        $this->assertStringNotContainsString('name="visit_rooms[]"', $one);

        $this->assertStringContainsString('name="visit_rooms[]"', $three);
    }

    public function test_every_space_list_field_names_a_source_rather_than_holding_a_query(): void
    {
        $seen = 0;

        foreach (CockpitDocumentFormPresenter::documentFieldMap() as $module => $definition) {
            foreach ($definition['groups'] as $group) {
                foreach ($group['fields'] as $field) {
                    if ($field['type'] !== CockpitDocumentFormPresenter::TYPE_SPACE_LIST) {
                        continue;
                    }

                    $seen++;

                    $this->assertSame(
                        CockpitDocumentFormPresenter::PREFILL_PROJECT_SPACES,
                        $field['prefill'],
                        "{$module}.{$field['key']} draws its spaces from an unnamed source.",
                    );

                    $this->assertNull($field['options'], 'A space list holds no options in the map - they are per project.');
                }
            }
        }

        $this->assertSame(1, $seen, 'Exactly one space list exists: the site survey.');
    }

    /**
     * EVERY OFFERED FORMAT STILL POINTS AT A REAL ROUTE, AND THE LAST STEP NOW
     * NAMES IT INSTEAD OF ASKING ABOUT IT.
     *
     * The route half is load-bearing and is UNCHANGED: a `formats` entry naming
     * a route that does not exist is a promise the panel cannot keep. The radios
     * went on 2026-09-27 (item 8) because their answer changed nothing; what
     * replaced them is a sentence built from the SAME map entry, so it can still
     * only name what exists. NO FORMAT WAS INVENTED.
     */
    public function test_every_offered_format_points_at_a_route_that_exists(): void
    {
        $project = $this->project();
        $judged  = 0;

        foreach (CockpitDocumentFormPresenter::documentFieldMap() as $module => $definition) {
            // The outcome line is on the LAST step (Plan 46.5-04).
            $form = $this->docForm($project, $module, ['action' => 'generate'] + $this->lastStepQuery($module));

            foreach ($definition['formats'] as $format => $routeName) {
                if ($routeName === null) {
                    continue;
                }

                $this->assertTrue(
                    \Illuminate\Support\Facades\Route::has($routeName),
                    "{$module}'s {$format} route {$routeName} does not exist — 46.2-02 proved it did."
                );
            }

            // NOT ONE FORMAT RADIO, on any document, on its last step.
            $this->assertStringNotContainsString('name="format"', $form, "{$module} still asks for a format.");

            // WHAT STANDS THERE INSTEAD, DERIVED FROM THE MAP RATHER THAN TYPED
            // OUT: the engineer link when this module issues one, then each
            // offered format's own word, in map order.
            $words = [];

            if (CockpitCombinedCreator::handles($module)) {
                $words[] = 'the engineer link';
            }

            foreach ($definition['formats'] as $format => $routeName) {
                if ($routeName !== null) {
                    $words[] = ['word' => 'Word', 'pdf' => 'PDF'][$format] ?? $format;
                }
            }

            $list = count($words) === 1
                ? $words[0]
                : implode(', ', array_slice($words, 0, -1)).' and '.$words[count($words) - 1];

            $this->assertStringContainsString(
                'Generating creates '.$list.'.',
                $form,
                "{$module}'s last step does not name what the creation produces."
            );

            $judged++;
        }

        $this->assertSame(4, $judged, 'This proof rendered the last step of '.$judged.' documents.');
    }

    // ── Quick task 260930-qcy, Task 2: the supersede form reaches its route ──

    /**
     * THE FORM REALLY REACHES `site-surveys.supersede-from-project`, DRIVEN,
     * NOT JUST RENDERED. Markup inspection proves the control exists; this
     * proves submitting it does what the label says: archives the current
     * survey and starts a fresh one.
     */
    public function test_the_supersede_form_reaches_its_route_and_archives_the_old_survey(): void
    {
        $project = $this->project();

        $existing = app(\App\Core\Modules\Survey\SurveyService::class)
            ->createFromProject($project, $this->user());

        // TOUCHED, so `SurveyService::createFromProject(supersede: true)` takes
        // the SUPERSEDE path rather than the discard-an-empty-husk path — an
        // untouched survey (no surveyor name, no room data) is DELETED instead
        // of archived, which would make this test prove nothing about the
        // `superseded_at` flip it exists to check.
        $existing->update(['surveyor_name' => 'Kit Farrow']);

        $form = $this->docForm($project, ProjectDeliverable::KEY_SITE_SURVEY, ['action' => 'generate']);

        $this->assertStringContainsString(
            route('site-surveys.supersede-from-project', $project),
            $form,
            'The supersede form is not on the open panel when a document already exists.'
        );

        $response = $this->actingAs($this->user())->post(
            route('site-surveys.supersede-from-project', $project),
        );

        $response->assertRedirect();

        $existing->refresh();

        $this->assertNotNull($existing->superseded_at, 'Superseding did not archive the old survey.');

        $this->assertSame(
            2,
            SiteSurvey::where('project_id', $project->id)->count(),
            'Superseding must leave the archived survey AND create exactly one fresh one.'
        );

        $fresh = SiteSurvey::where('project_id', $project->id)->whereNull('superseded_at')->sole();

        $this->assertNotSame($existing->id, $fresh->id);

        $response->assertRedirect(route('site-surveys.confirm-rooms', $fresh));
    }

    /** The form must not render on a module with no document yet — nothing to supersede. */
    public function test_the_supersede_form_does_not_render_with_no_document_yet(): void
    {
        $project = $this->project();

        $form = $this->docForm($project, ProjectDeliverable::KEY_SITE_SURVEY, ['action' => 'generate']);

        $this->assertStringNotContainsString(
            route('site-surveys.supersede-from-project', $project),
            $form,
            'The supersede form rendered with nothing to supersede.'
        );
    }
}
