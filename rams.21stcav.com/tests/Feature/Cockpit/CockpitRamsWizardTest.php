<?php

namespace Tests\Feature\Cockpit;

use App\Jobs\BuildRamsDocumentJob;
use App\Models\LabourResource;
use App\Models\Project;
use App\Models\ProjectDeliverable;
use App\Models\ProjectPackage;
use App\Models\RamsDocument;
use App\Models\User;
use App\Models\Visit;
use App\Support\Cockpit\CockpitDocumentFormPresenter;
use App\Support\Cockpit\CockpitModulePresenter;
use App\Support\Cockpit\CockpitWizardPresenter;
use App\Support\Visits\VisitLinkIssuer;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 46.5, Plan 46.5-05 — RAMS JOINS THE STEPPED FLOW.
 *
 * 46.5-CONTEXT D-04 and D-05, in the user's own words: *"RAMs is doc creation
 * only ie office user should be able to either gen independant of a visit or as
 * part of an install ie are RAMS req ? if ticked yes it follow a similar flow to
 * site survey ... then create rams based on project info , user entered visit
 * info and userer enter job summary"* — and, decisively, *"RAMs will use
 * existing RAM process."*
 *
 * So this file proves four things and writes no renderer:
 *
 *   1. THE JOB SUMMARY REACHES THE DOCUMENT the existing generator builds,
 *      through the controller's EXISTING `patchFormData()` — no controller edit,
 *      no new route, no new build job.
 *   2. RAMS HAS THREE STEPS and every one of its 19 fields reaches exactly one
 *      of them, asserted by SET EQUALITY against the map rather than by counting.
 *   3. THE TICK DRIVES THE FLOW, IT DOES NOT GATE THE PAGE. Every deliverable
 *      state renders, in every mode, and the COUNT of states rendered is itself
 *      asserted — a test that renders one state proves nothing about the others.
 *   4. RAMS ISSUES NO ENGINEER LINK AND CREATES NO VISIT. An install's link is
 *      the install's.
 *
 * ── NO SPEND, AND TWO BELTS ─────────────────────────────────────────────────
 *
 * `Bus::fake()` stops the queued RAMS builder and `Http::fake()` stops anything
 * that ever stops going through it. Neither the AI pipeline nor a byte of DOCX
 * is produced by this file — the same treatment `CockpitDocCreationEndToEndTest`
 * established.
 */
class CockpitRamsWizardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * THE THREE DELIVERABLE STATES, NAMED. `ProjectDeliverable::STATE_*` in
     * full, plus the fourth real-world case: NO ROW AT ALL, which
     * `deliverableState()` reports as `not_yet_decided` and which is what every
     * pre-260822 project actually looks like.
     *
     * @var array<int, string|null>
     */
    private const DELIVERABLE_STATES = [
        null, // no row — the pre-260822 project
        ProjectDeliverable::STATE_REQUIRED,
        ProjectDeliverable::STATE_NOT_REQUIRED,
        ProjectDeliverable::STATE_NOT_YET_DECIDED,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['cockpit.enabled' => true]);
        Bus::fake();
        Http::fake();
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function project(): Project
    {
        return Project::factory()->create([
            'name'         => 'Northbank RAMS Job',
            'ref'          => 'Q-4657',
            'client_name'  => 'Northbank Media',
            'site_address' => '12 Wharf Road, Leeds',
            'status'       => Project::STATUS_INSTALLING,
        ]);
    }

    private function user(string $name = 'Priya Mistry'): User
    {
        return User::factory()->create(['name' => $name]);
    }

    /** A reviewed package — what `rams.from-project` requires before it creates anything. */
    private function reviewedPackage(Project $project): ProjectPackage
    {
        return ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $this->user('Package Owner')->id,
            'quote_filename' => 'quote.pdf',
            'quote_path'     => 'packages/quote.pdf',
            'extracted_data' => [
                'overview'               => 'A prose overview the normaliser does not carry.',
                'method_statement_notes' => 'Strip out, install, commission.',
                'project'                => ['project_name' => 'Northbank RAMS Job'],
                'equipment'              => [['quantity' => 2, 'part_number' => 'SC-75', 'name' => '75in display']],
                'activities'             => [['key' => 'install', 'label' => 'Install and commission']],
                'ppe'                    => ['Gloves', 'Safety boots'],
            ],
            'status'         => ProjectPackage::STATUS_REVIEWED,
        ]);
    }

    /**
     * BOTH resource roles, deliberately. A `resource-list` field renders one
     * checkbox per ACTIVE resource in its role, so a fixture with no programmer
     * would render no `programmers` control at all — and the step-membership
     * assertion below would then have passed vacuously on an absence it should
     * have caught.
     */
    private function resources(): void
    {
        LabourResource::factory()->create([
            'name'  => 'Dev Chandra', 'email' => 'dev.chandra@example.test',
            'phone' => '07700 900111', 'roles' => [LabourResource::ROLE_ENGINEER], 'is_active' => true,
        ]);

        LabourResource::factory()->create([
            'name'  => 'Ana Ruiz', 'email' => 'ana.ruiz@example.test',
            'phone' => '07700 900222', 'roles' => [LabourResource::ROLE_PROGRAMMER], 'is_active' => true,
        ]);
    }

    /** Sets (or deliberately does not create) the project's `rams` deliverable row. */
    private function tick(Project $project, ?string $state): void
    {
        if ($state !== null) {
            ProjectDeliverable::create([
                'project_id'      => $project->id,
                'deliverable_key' => ProjectDeliverable::KEY_RAMS,
                'state'           => $state,
            ]);
        }

        $project->unsetRelation('deliverables');
    }

    private function wizard(): CockpitWizardPresenter
    {
        return app(CockpitWizardPresenter::class);
    }

    /** The full RAMS answer set — every one of the map's writable fields. */
    private function ramsPayload(): array
    {
        return [
            'module'                => ProjectDeliverable::KEY_RAMS,
            'format'                => 'word',
            'tab'                   => 'overview',
            'planned_start_date'    => '2026-10-05',
            'planned_end_date'      => '2026-10-09',
            'planned_start_time'    => '0730',
            'working_hours'         => 'Monday-Friday, 07:30-17:00',
            'project_manager_name'  => 'Priya Mistry',
            'project_manager_phone' => '0113 000 0000',
            'project_manager_email' => 'priya@example.test',
            'lead_engineer_name'    => 'Dev Chandra',
            'lead_engineer_phone'   => '07700 900111',
            'additional_engineers'  => [],
            'programmers'           => [],
            'contact_name'          => 'Sam Bright',
            'contact_phone'         => '07700 900444',
            'contact_email'         => 'sam.bright@example.test',
            'job_summary'           => 'Strip out two legacy displays and install two 75in screens in the boardroom.',
        ];
    }

    private function generate(Project $project, array $payload, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user())
            ->post(route('projects.cockpit.documents.store', $project), $payload);
    }

    private function stepUrl(Project $project, int $step): string
    {
        return route('projects.cockpit', [
            'project' => $project,
            'module'  => ProjectDeliverable::KEY_RAMS,
            'tab'     => 'overview',
            'action'  => 'generate',
            'step'    => $step,
        ]);
    }

    // ── 1. THE JOB SUMMARY REACHES THE EXISTING GENERATOR'S DOCUMENT ────────

    /**
     * THE LOAD-BEARING ONE. A field the generator ignores is a field that
     * teaches a PM to type noise (46.2 D-03), so this walks the value the whole
     * way: a PM's textarea → validation → the controller's EXISTING
     * `patchFormData()` → `RamsDocument::form_data['works_description']`, which
     * is the key `RamsBuilderService.php:119` reads.
     *
     * NO CONTROLLER EDIT. `patchFormData()` already loops every `form_data.*`
     * target on the map, which is why the job summary needed one map row and
     * nothing else.
     */
    public function test_a_typed_job_summary_reaches_the_documents_form_data(): void
    {
        $pm      = $this->user();
        $project = $this->project();
        $this->reviewedPackage($project);
        $this->resources();

        $this->generate($project, $this->ramsPayload(), $pm)->assertRedirect();

        $rams = RamsDocument::where('project_id', $project->id)->sole();

        $this->assertSame(
            'Strip out two legacy displays and install two 75in screens in the boardroom.',
            $rams->form_data['works_description'] ?? null,
            'The job summary a PM typed never reached the key RamsBuilderService reads.',
        );

        // The map's other `form_data` exception is undisturbed by the new one.
        $this->assertSame('Monday-Friday, 07:30-17:00', $rams->form_data['working_hours'] ?? null);

        // The EXISTING process, unspent: the existing job, the existing status.
        Bus::assertDispatched(BuildRamsDocumentJob::class);
        $this->assertSame(RamsDocument::STATUS_GENERATING, $rams->status);
    }

    /**
     * T-46.5-05-01. `patchFormData()` is scoped to the document THIS request
     * created (`id > $before`), so a second submission cannot rewrite the first
     * RAMS's summary.
     */
    public function test_a_second_submission_never_edits_the_first_rams_summary(): void
    {
        $pm      = $this->user();
        $project = $this->project();
        $this->reviewedPackage($project);
        $this->resources();

        $this->generate($project, $this->ramsPayload(), $pm)->assertRedirect();
        $first = RamsDocument::where('project_id', $project->id)->sole();

        $this->generate($project, ['job_summary' => 'A completely different job.'] + $this->ramsPayload(), $pm)
            ->assertRedirect();

        $this->assertSame(
            'Strip out two legacy displays and install two 75in screens in the boardroom.',
            $first->fresh()->form_data['works_description'] ?? null,
            'A re-submission edited an EARLIER RAMS document.',
        );
    }

    /** The map's own rule: 5000 characters is the ceiling, and it is enforced. */
    public function test_an_oversized_job_summary_is_a_validation_failure(): void
    {
        $pm      = $this->user();
        $project = $this->project();
        $this->reviewedPackage($project);
        $this->resources();

        $this->generate($project, ['job_summary' => str_repeat('a', 5001)] + $this->ramsPayload(), $pm)
            ->assertSessionHasErrors('job_summary');

        $this->assertSame(0, RamsDocument::where('project_id', $project->id)->count());
    }

    /**
     * D-04's *"or taken from project data"* — and it needed NO prefill
     * mechanism. `RamsBuilderService.php:714-718` already falls back to the
     * package's own method-statement notes and then to an equipment-derived
     * scope, so a PM who types nothing still gets a document and the app
     * invents no second source of the same sentence.
     *
     * ⚠ NOTE FOR A LATER READER, NOT A DEFECT THIS PLAN FIXES: that block's
     * comment says *"when works_description is blank"* while the code it heads
     * reads `$reviewedData['method_statement_notes']`. The fallback is real;
     * only the comment names the wrong key. Editing `RamsBuilderService` is
     * forbidden by D-04 (*"RAMs will use existing RAM process"*), so the
     * finding is recorded here rather than acted on.
     */
    public function test_a_blank_job_summary_still_creates_the_document(): void
    {
        $pm      = $this->user();
        $project = $this->project();
        $this->reviewedPackage($project);
        $this->resources();

        $payload = $this->ramsPayload();
        unset($payload['job_summary']);

        $this->generate($project, $payload, $pm)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, RamsDocument::where('project_id', $project->id)->count());
    }

    // -- 2. RAMS'S THREE STEPS -----------------------------------------------

    public function test_rams_has_exactly_three_steps_with_the_titles_the_map_names(): void
    {
        $this->assertSame([1, 2, 3], $this->wizard()->stepsFor(ProjectDeliverable::KEY_RAMS));

        $this->assertSame('Dates and hours', $this->wizard()->stepTitle(ProjectDeliverable::KEY_RAMS, 1));
        $this->assertSame('Team and site contact', $this->wizard()->stepTitle(ProjectDeliverable::KEY_RAMS, 2));
        $this->assertSame('Job summary and output', $this->wizard()->stepTitle(ProjectDeliverable::KEY_RAMS, 3));

        // A step with no title would render a wizard heading from nowhere.
        $this->assertNull($this->wizard()->stepTitle(ProjectDeliverable::KEY_RAMS, 4));
    }

    /**
     * SET EQUALITY, NOT A COUNT - the plan's own instruction. A count would pass
     * while one field moved onto a step as another fell off, and RAMS's existing
     * fields must ALL survive the regrouping: not one is added, removed,
     * renamed or reworded by the stepping.
     *
     * The measured total is 19 (When 4 - Who 6 - Programmers 1 - Site contact 3
     * - Job summary 1 - From the project 4). The plan said "17"; that was
     * planning arithmetic. The set equality below is what actually holds the
     * invariant, so the measured number is reported rather than the planned one
     * obeyed.
     */
    public function test_every_rams_field_reaches_exactly_one_step(): void
    {
        $map = CockpitDocumentFormPresenter::documentFieldMap()[ProjectDeliverable::KEY_RAMS];

        $onTheMap = [];

        foreach ($map['groups'] as $group) {
            $this->assertIsInt(
                $group['step'] ?? null,
                "RAMS group `{$group['legend']}` has no step. Every RAMS group is on the wizard now - "
                .'a `null` here would silently drop its fields off the form.',
            );

            foreach ($group['fields'] as $field) {
                $onTheMap[] = $field['key'];
            }
        }

        $onAStep = [];

        foreach ($this->wizard()->stepsFor(ProjectDeliverable::KEY_RAMS) as $step) {
            foreach ($this->wizard()->groupsForStep(ProjectDeliverable::KEY_RAMS, $step) as $group) {
                foreach ($group['fields'] as $field) {
                    $onAStep[] = $field['key'];
                }
            }
        }

        sort($onTheMap);
        sort($onAStep);

        $this->assertSame($onTheMap, $onAStep, 'A RAMS field reaches no step, or reaches two.');
        $this->assertSame(count($onTheMap), count(array_unique($onTheMap)), 'A RAMS field key is duplicated.');
        $this->assertCount(19, $onAStep, 'RAMS asks 19 questions across its three steps.');
    }

    /**
     * Step membership by SUBTREE, not by `assertSee`. With carry-forward every
     * step's answers are somewhere in the document, so a whole-page string
     * search cannot tell "a control the PM must answer" from "a value riding
     * along hidden" - and would pass with all 19 fields back on one screen.
     */
    public function test_each_rams_step_renders_its_own_controls_and_not_the_others(): void
    {
        $project = $this->project();
        $this->reviewedPackage($project);
        $this->resources();

        $rendered = 0;

        foreach ($this->wizard()->stepsFor(ProjectDeliverable::KEY_RAMS) as $step) {
            $html = $this->actingAs($this->user())->get($this->stepUrl($project, $step))
                ->assertOk()
                ->getContent();

            $controls = $this->splitControls($html);
            $expected = $this->fieldsByStep($step);

            $this->assertNotEmpty($expected['own'], "RAMS step {$step} asks nothing.");

            foreach ($expected['own'] as $key) {
                $this->assertContains($key, $controls['visible'], "RAMS step {$step} does not ask `{$key}`.");
            }

            foreach ($expected['others'] as $key) {
                $this->assertNotContains(
                    $key,
                    $controls['visible'],
                    "RAMS step {$step} renders `{$key}`, which belongs to another step.",
                );
            }

            $rendered++;
        }

        $this->assertSame(3, $rendered, 'Three RAMS steps were rendered and asserted.');
    }

    // -- 3. THE TICK DRIVES THE FLOW, IT DOES NOT GATE THE PAGE --------------

    /**
     * EVERY STATE, COUNTED. Two modes x four deliverable states, and the
     * standalone mode is rendered at every one of its three steps:
     *
     *   standalone       4 states x 3 steps = 12
     *   from an install  4 states x 1 panel =  4
     *                                        ---
     *                                         16
     *
     * The count is asserted because a test that renders ONE state proves nothing
     * about the others - two days before this plan the user found a defect 387
     * tests missed for exactly that reason.
     */
    public function test_every_rams_state_renders_in_every_mode_and_every_deliverable_state(): void
    {
        $rendered = 0;

        foreach (self::DELIVERABLE_STATES as $state) {
            $project = $this->project();
            $this->reviewedPackage($project);
            $this->resources();
            $this->tick($project, $state);

            $label = $state ?? 'no row';

            // MODE 1 - STANDALONE. "office user should be able to ... gen
            // independant of a visit". Every step, in every state: the tick
            // DRIVES the flow, it does not GATE the page, so a project whose
            // RAMS deliverable is `not_yet_decided` still creates a RAMS if a
            // PM asks for one.
            foreach ($this->wizard()->stepsFor(ProjectDeliverable::KEY_RAMS) as $step) {
                $this->actingAs($this->user())->get($this->stepUrl($project, $step))
                    ->assertOk()
                    ->assertSee('RAMS', false);

                $rendered++;
            }

            // MODE 2 - REACHED FROM AN INSTALL. The worksheet panel renders in
            // every state too; only its ROUTE to the RAMS is conditional.
            $this->actingAs($this->user())->get(route('projects.cockpit', [
                'project' => $project,
                'module'  => ProjectDeliverable::KEY_WORKSHEET,
                'tab'     => 'overview',
            ]))->assertOk();

            $rendered++;

            $this->assertSame(
                $state ?? ProjectDeliverable::STATE_NOT_YET_DECIDED,
                $project->fresh()->load('deliverables')->deliverableState(ProjectDeliverable::KEY_RAMS),
                "The guarded reader disagreed about the `{$label}` state.",
            );
        }

        $this->assertSame(16, $rendered, 'Sixteen RAMS states were rendered: 2 modes x 4 deliverable states.');
    }

    /**
     * D-05, and the ONLY thing the tick actually changes: "are RAMS req ? if
     * ticked yes it follow a similar flow to site survey". When the project's
     * RAMS deliverable reads `required`, the WORKSHEET panel says so in one
     * escaped sentence and links to the RAMS module's own wizard. It is the SAME
     * RAMS document, reached from the install - never a second creation path.
     */
    public function test_only_a_required_rams_deliverable_puts_the_route_on_the_install(): void
    {
        $sentence = 'RAMS are required for this project.';

        $link = static fn (Project $project): string => route('projects.cockpit', [
            'project' => $project,
            'module'  => ProjectDeliverable::KEY_RAMS,
            'tab'     => 'overview',
            'action'  => 'generate',
            'step'    => 1,
        ]);

        $judged = 0;

        foreach (self::DELIVERABLE_STATES as $state) {
            $project = $this->project();
            $this->resources();
            $this->tick($project, $state);

            $response = $this->actingAs($this->user())->get(route('projects.cockpit', [
                'project' => $project,
                'module'  => ProjectDeliverable::KEY_WORKSHEET,
                'tab'     => 'overview',
            ]))->assertOk();

            if ($state === ProjectDeliverable::STATE_REQUIRED) {
                $response->assertSee($sentence, false);
                $response->assertSee(e($link($project)), false);
            } else {
                $response->assertDontSee($sentence, false);
            }

            // The machine token never reaches the page, in ANY state.
            $response->assertDontSee(ProjectDeliverable::STATE_NOT_YET_DECIDED, false);
            $response->assertDontSee(ProjectDeliverable::STATE_NOT_REQUIRED, false);

            $judged++;
        }

        $this->assertSame(4, $judged, 'All four deliverable states were judged.');

        // THE STATE IS NEVER RENDERED AS THE RAW ENUM (T-46.5-05-02). The word
        // "required" is unavoidable in an English sentence about a requirement,
        // and `STATE_REQUIRED` happens to be spelt the same; what must never
        // reach a PM is a MACHINE TOKEN, so the two underscored values are the
        // ones asserted absent — from the sentence and from every page above.
        foreach ([ProjectDeliverable::STATE_NOT_YET_DECIDED, ProjectDeliverable::STATE_NOT_REQUIRED] as $enum) {
            $this->assertStringNotContainsString($enum, $sentence);
            $this->assertStringNotContainsString('_', $sentence);
        }
    }

    /**
     * `Project::deliverableState()` IS DELIBERATELY GUARDED - it returns null
     * when the `deliverables` relation is not loaded, so a caller cannot make it
     * fire a lazy query per module row. The prompt honours that guard: an
     * unloaded project reads null, offers nothing, and the page still renders.
     *
     * Asserted with lazy loading PREVENTED, so working around the guard with a
     * lazy query would be an exception rather than a silent N+1.
     */
    public function test_an_unloaded_deliverables_relation_reads_null_and_offers_nothing(): void
    {
        $project = $this->project();
        $this->tick($project, ProjectDeliverable::STATE_REQUIRED);

        $unloaded = Project::query()->findOrFail($project->id);

        $this->assertFalse($unloaded->relationLoaded('deliverables'));
        $this->assertNull(
            $unloaded->deliverableState(ProjectDeliverable::KEY_RAMS),
            'deliverableState() answered without the relation loaded - the guard was worked around.',
        );

        Project::preventLazyLoading();

        try {
            $this->assertNull(
                app(CockpitModulePresenter::class)->deliverablePrompt($unloaded, ProjectDeliverable::KEY_WORKSHEET),
                'The prompt answered from an unloaded relation.',
            );
        } finally {
            Project::preventLazyLoading(false);
        }

        // And the page - which DOES eager-load - is still 200 and still offers it.
        $this->actingAs($this->user())->get(route('projects.cockpit', [
            'project' => $project,
            'module'  => ProjectDeliverable::KEY_WORKSHEET,
            'tab'     => 'overview',
        ]))->assertOk()->assertSee('RAMS are required for this project.', false);
    }

    // -- 4. RAMS ISSUES NO ENGINEER LINK AND CREATES NO VISIT ----------------

    /**
     * D-04, asserted rather than trusted: "issues no engineer link of its own".
     * An install's link is the install's.
     *
     * `VisitLinkIssuer::VISIT_MODULES` has exactly two entries and RAMS is not
     * one of them, so the guarantee is DATA - and the wizard is walked
     * end-to-end anyway, because a new call to the issuer would not show up in
     * that constant.
     */
    public function test_the_rams_module_issues_no_engineer_link_and_creates_no_visit(): void
    {
        $this->assertSame([], VisitLinkIssuer::typesFor(ProjectDeliverable::KEY_RAMS));
        $this->assertSame(
            [ProjectDeliverable::KEY_SITE_SURVEY, ProjectDeliverable::KEY_WORKSHEET],
            array_keys(VisitLinkIssuer::VISIT_MODULES),
            'A module gained an engineer link. RAMS must not be one of them.',
        );

        $pm      = $this->user();
        $project = $this->project();
        $this->reviewedPackage($project);
        $this->resources();

        $visitsBefore  = DB::table('visits')->count();
        $surveysBefore = DB::table('site_surveys')->count();

        // The whole wizard, step by step, then the create.
        foreach ([1, 2] as $step) {
            $this->generate($project, ['intent' => 'next', 'step' => $step] + $this->ramsPayload(), $pm)
                ->assertRedirect($this->stepUrl($project, $step + 1));
        }

        $this->generate($project, $this->ramsPayload(), $pm)->assertRedirect();

        $this->assertSame(1, RamsDocument::where('project_id', $project->id)->count(), 'The RAMS itself was not created.');

        $this->assertSame($visitsBefore, DB::table('visits')->count(), 'The RAMS wizard created a visit.');
        $this->assertSame(0, Visit::where('project_id', $project->id)->count());
        $this->assertSame(
            $surveysBefore,
            DB::table('site_surveys')->count(),
            'The RAMS wizard created a survey - and every survey carries an engineer link.',
        );
    }

    /**
     * The copy, checked as a SUBSTRING against all 20 `DEFERRED_AFFORDANCES`
     * keys (21 before Plan 47-03 lifted "Download") and both
     * `FORBIDDEN_MARKUP` entries - before use, and now as an assertion so a
     * later copy edit cannot collide quietly.
     *
     * `Send a RAMS to the client` is the entry this plan's copy sits closest to,
     * and no line may contain it: this plan ships no client issue.
     */
    public function test_the_rams_copy_collides_with_no_fence_entry(): void
    {
        $copy = [
            'RAMS are required for this project.',
            'Open the RAMS step by step',
            'Job summary',
            'Dates and hours',
            'Team and site contact',
            'Job summary and output',
        ];

        $fence = new \ReflectionClass(CockpitReadOnlyFenceTest::class);

        $banned = array_merge(
            array_keys($fence->getConstant('DEFERRED_AFFORDANCES')),
            $fence->getConstant('FORBIDDEN_MARKUP'),
        );

        $this->assertCount(22, $banned, 'The fence is 20 deferred affordances (Plan 47-03 lifted "Download") and 2 forbidden markup entries.');

        foreach ($copy as $line) {
            foreach ($banned as $entry) {
                $this->assertStringNotContainsString(
                    $entry,
                    $line,
                    "RAMS wizard copy `{$line}` contains the fenced string `{$entry}`.",
                );
            }
        }

        $this->assertStringNotContainsString('Send a RAMS to the client', implode(' ', $copy));
    }

    /**
     * THE MULTI-SELECT CARRY, PROVEN WHERE IT WAS PREVIOUSLY ONLY ASSUMED.
     *
     * An `array`-ruled field is carried across a step as one hidden input PER
     * SELECTED VALUE (`doc-form.blade.php:361-364`), so an EMPTY one carries
     * nothing — an empty `name[]` would submit a phantom blank engineer. RAMS
     * is the first stepped document with a multi-select off-step, which is why
     * `CockpitWizardTest`'s blanket hidden-carry assertion now skips array
     * fields, and why the real behaviour is asserted HERE rather than dropped:
     * a SELECTED multi-select survives the advance and reaches the document.
     */
    public function test_a_selected_multi_select_is_carried_forward_and_reaches_the_document(): void
    {
        $pm      = $this->user();
        $project = $this->project();
        $this->reviewedPackage($project);
        $this->resources();

        // Step 2 is where the engineers and the programmers are chosen.
        $payload = ['intent' => 'next', 'step' => 2] + $this->ramsPayload();
        $payload['additional_engineers'] = ['Dev Chandra'];
        $payload['programmers']          = ['Ana Ruiz'];

        $this->generate($project, $payload, $pm)->assertRedirect($this->stepUrl($project, 3));

        // 1. Step 3 carries both selections as hidden inputs.
        $html = $this->actingAs($pm)->from($this->stepUrl($project, 2))
            ->get($this->stepUrl($project, 3))
            ->assertOk()
            ->getContent();

        $controls = $this->splitControls($html);

        $this->assertContains('additional_engineers', $controls['hidden'], 'The chosen engineers were dropped on the way to step 3.');
        $this->assertContains('programmers', $controls['hidden'], 'The chosen programmers were dropped on the way to step 3.');
        $this->assertNotContains('additional_engineers', $controls['visible'], 'Step 3 re-asks a step 2 question.');

        // 2. And a create carrying them reaches the document's own data.
        $create = $this->ramsPayload();
        $create['additional_engineers'] = ['Dev Chandra'];

        $this->generate($project, $create, $pm)->assertRedirect();

        $rams = RamsDocument::where('project_id', $project->id)->sole();

        $this->assertSame(
            ['Dev Chandra'],
            $rams->reviewed_data['programme']['additional_engineers'] ?? null,
            'A carried multi-select never reached the RAMS.',
        );
    }

    // -- Helpers -------------------------------------------------------------

    /**
     * @return array{visible: array<int, string>, hidden: array<int, string>}
     */
    private function splitControls(string $html): array
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $xpath   = new DOMXPath($dom);
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
     * @return array{own: array<int, string>, others: array<int, string>}
     */
    private function fieldsByStep(int $step): array
    {
        $own    = [];
        $others = [];

        foreach (CockpitDocumentFormPresenter::documentFieldMap()[ProjectDeliverable::KEY_RAMS]['groups'] as $group) {
            $groupStep = $group['step'] ?? null;

            if (! is_int($groupStep)) {
                continue;
            }

            foreach ($group['fields'] as $field) {
                // A display-only field is never a control anywhere.
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
}
