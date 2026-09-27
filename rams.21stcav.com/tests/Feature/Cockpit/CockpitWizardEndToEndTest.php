<?php

namespace Tests\Feature\Cockpit;

use App\Jobs\BuildWorksheetJob;
use App\Core\Modules\Projects\ProjectService;
use App\Models\LabourResource;
use App\Models\Project;
use App\Models\ProjectActivityLog;
use App\Models\ProjectDeliverable;
use App\Models\ProjectPackage;
use App\Models\RamsDocument;
use App\Models\SiteSurvey;
use App\Models\User;
use App\Models\Visit;
use App\Models\Worksheet;
use App\Models\WorksheetPhoto;
use App\Support\Visits\VisitLinkIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 46.5, Plan 46.5-07 — THE WALK, THROUGH HTTP, OVER EVERY STATE.
 *
 * ── WHY THIS FILE EXISTS ──────────────────────────────────────────────────
 *
 * The user found the last defect by CLICKING, not by reading a test. 387 tests
 * missed an open drawer row that would not close, because the assertion that
 * should have caught it only ever rendered the CLOSED page. Vacuous, not wrong.
 *
 * A wizard has N states, so this file walks REAL ROUTES with REAL REQUESTS —
 * never a presenter, never a slice of the map — and it COUNTS what it measured.
 * "The walk passed" is not a report.
 *
 * ── THE FIVE WALKS ────────────────────────────────────────────────────────
 *
 *   A. Site survey, happy path: masthead → drawer → step 1 → 2 → 3 → back → 3
 *      → create → the document, the visit and the engineer link, and the link
 *      is OPENED.
 *   B. The comms-room safety fence (GCW-04). Removing it is THE regression, so
 *      the surveyor's on-site form is rendered and asserted to still ask.
 *   C. Install: ONE creation → ONE worksheet → ONE build job, then the
 *      engineer link's THREE photo trays in capture order.
 *   D. RAMS, standalone: a document, ZERO visits, ZERO survey tokens, and the
 *      job summary reaching `form_data['works_description']`.
 *   E. Abandonment and failure: every step abandoned with six tables asserted
 *      unchanged, and a forced failure that names WHICH HALF happened.
 *
 * @see .planning/phases/46.5-guided-creation-wizard/46.5-LEDGER.md
 */
class CockpitWizardEndToEndTest extends TestCase
{
    use RefreshDatabase;

    /**
     * THE SIX TABLES AN ABANDONED WIZARD MUST LEAVE EXACTLY WHERE THEY WERE.
     *
     * Named individually, because a count of one table would pass while a
     * visit, a worksheet or an activity row was written behind it.
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

    /** The spaces the fixture puts on file, and therefore the spaces step 3 offers. */
    private const SPACES = ['Boardroom', 'Huddle 1'];

    /**
     * THE THREE PHOTO TRAY TITLES, IN CAPTURE ORDER (GCW-07).
     *
     * ⚠ The third is BYTE-IDENTICAL to the string
     * `2026_09_26_100000_add_bucket_to_worksheet_photos_table` quotes as its
     * written justification for backfilling every legacy photo to `completion`.
     * A rename here is a data migration that falsifies a recorded decision.
     *
     * @var array<int, array{bucket: string, title: string}>
     */
    private const TRAYS = [
        ['bucket' => 'start',      'title' => '📸 Before you start'],
        ['bucket' => 'during',     'title' => '🛠️ While the work is underway'],
        ['bucket' => 'completion', 'title' => '📷 Photos of completed work'],
    ];

    /** Armed by `forceLogFailure()`; read by the double. See that method. */
    public static bool $failing = false;

    protected function setUp(): void
    {
        parent::setUp();

        self::$failing = false;

        config(['cockpit.enabled' => true]);

        // `phpunit.xml` runs the `sync` connection, so a real dispatch would run
        // `BuildWorksheetJob` INLINE and make live AI calls from a test. Faking
        // it also lets Walk C COUNT the dispatches rather than infer them.
        Bus::fake();
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function user(): User
    {
        return User::factory()->create(['name' => 'Priya Mistry']);
    }

    private function project(): Project
    {
        $project = Project::factory()->create([
            'name'         => 'Walkthrough Job',
            'ref'          => 'Q-4677',
            'client_name'  => 'Northbank Media',
            'site_address' => '12 Wharf Road, Leeds',
            'status'       => Project::STATUS_INSTALLING,
        ]);

        ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $this->user()->id,
            'quote_filename' => 'quote.pdf',
            'quote_path'     => 'packages/quote.pdf',
            'extracted_data' => [
                'overview'               => 'A prose overview the normaliser does not carry.',
                'method_statement_notes' => 'Strip out, install, commission.',
                'project'                => ['project_name' => 'Walkthrough Job'],
                'room_overviews'         => [
                    ['room' => 'Boardroom', 'overview' => 'Two 75in displays.', 'summary' => 'Boardroom'],
                    ['room' => 'Huddle 1',  'overview' => 'One soundbar.',      'summary' => 'Huddle 1'],
                ],
                'equipment'              => [['quantity' => 2, 'part_number' => 'SC-75', 'name' => '75in display', 'area' => 'Boardroom']],
                'activities'             => [['key' => 'install', 'label' => 'Install and commission']],
                'ppe'                    => ['Gloves', 'Safety boots'],
            ],
            'status'         => ProjectPackage::STATUS_REVIEWED,
        ]);

        return $project;
    }

    /**
     * BOTH resource roles. A `resource-list` renders one checkbox per ACTIVE
     * resource in its role, so a fixture with no programmer renders no
     * `programmers` control at all — and Walk D's step assertions would then
     * pass vacuously on an absence they exist to catch.
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

    /** @return array<string, int> */
    private function rowCounts(): array
    {
        $counts = [];

        foreach (self::UNTOUCHED_TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    private function cockpitUrl(Project $project, array $extra = []): string
    {
        return route('projects.cockpit', ['project' => $project] + $extra);
    }

    private function stepUrl(Project $project, string $module, int $step): string
    {
        return $this->cockpitUrl($project, [
            'module' => $module,
            'tab'    => 'overview',
            'action' => 'generate',
            'step'   => $step,
        ]);
    }

    private function open(Project $project, User $user, array $extra = []): string
    {
        return $this->actingAs($user)
            ->get($this->cockpitUrl($project, $extra))
            ->assertOk()
            ->getContent();
    }

    /**
     * THE WIZARD'S OWN FORM, AS A SUBTREE — and this is load-bearing, not
     * tidiness. The cockpit page also renders the OFFICE NOTE form, which has
     * its own `general_notes`-shaped controls, so a whole-page
     * `assertStringNotContainsString` for a step-2 field fails against markup
     * that has nothing to do with the wizard. A subtree is the only honest way
     * to ask "is this control on THIS step".
     */
    private function docForm(string $html): string
    {
        $start = strpos($html, '<form class="cav-qa__form"');

        $this->assertNotFalse($start, 'The wizard form did not render at all.');

        $end = strpos($html, '</form>', $start);

        $this->assertNotFalse($end, 'The wizard form is unterminated.');

        return substr($html, $start, $end - $start);
    }

    /**
     * THE FORM WITH ITS HIDDEN CARRY INPUTS REMOVED.
     *
     * A step renders the controls it ASKS plus a hidden input for every answer
     * riding along from another step — so a bare `name="general_notes"` search
     * cannot tell "the PM is asked this here" from "this is being carried".
     * Stripping the hidden inputs is the difference, and every "leaked onto the
     * wrong step" assertion below is made against the stripped copy.
     */
    private function visibleControls(string $form): string
    {
        return (string) preg_replace('/<input type="hidden"[^>]*>/', '', $form);
    }

    private function submit(Project $project, User $user, array $payload)
    {
        return $this->actingAs($user)
            ->post(route('projects.cockpit.documents.store', $project), $payload);
    }

    /**
     * A step advance, FOLLOWED. The POST redirects and flashes `withInput()`,
     * so the GET that renders the next step has to be a second real request —
     * which is exactly what a browser does, and the only way the carry-forward
     * is proven to survive the round trip.
     *
     * @return array{0: \Illuminate\Testing\TestResponse, 1: string}
     */
    private function advance(Project $project, User $user, array $payload): array
    {
        $post = $this->submit($project, $user, $payload)->assertSessionHasNoErrors();

        $target = (string) $post->headers->get('Location');

        $html = $this->actingAs($user)->get($target)->assertOk()->getContent();

        return [$post, $html];
    }

    /** The step-1 answers, in the user's own words: dates, site contact and engineer. */
    private function stepOne(): array
    {
        return [
            // ONE DATE SINCE 2026-09-27 (item 1). `survey_date` is no longer
            // asked; `visit_scheduled_date` below writes BOTH columns.
            'surveyor_name'        => 'Dev Chandra',
            'site_contact_name'    => 'Ruth Okafor',
            'site_contact_phone'   => '07700 900123',
            'visit_scheduled_date' => '2026-10-14',
            'visit_engineers'      => ['Dev Chandra'],
        ];
    }

    private function base(Project $project, int $step, string $intent, string $module = ProjectDeliverable::KEY_SITE_SURVEY): array
    {
        return [
            'module' => $module,
            'intent' => $intent,
            'step'   => $step,
            'tab'    => 'overview',
        ];
    }

    // ══ WALK A — SITE SURVEY, THE HAPPY PATH, ELEVEN STEPS ══════════════════

    /**
     * ONE test, deliberately. The eleven steps are one JOURNEY: splitting them
     * into eleven methods would let step 7 pass against a fixture step 5 never
     * produced, which is the vacuity this whole file exists to refuse.
     */
    public function test_walk_a_the_site_survey_from_the_masthead_to_the_opened_engineer_link(): void
    {
        $project = $this->project();
        $user    = $this->user();
        $this->resources();

        $rendered = 0;

        // ── 1. THE MASTHEAD NAMES THE CLIENT (GCW-01) ───────────────────────
        $cockpit = $this->open($project, $user);
        $rendered++;

        $this->assertStringContainsString('data-mast-client', $cockpit, 'The masthead carries no client hook.');
        $this->assertStringContainsString('Northbank Media', $cockpit, 'The client company name is not on the masthead.');

        // ── 2. THE DRAWER OPENS, AND THE ROW IS STILL A STRETCHED LINK ──────
        $drawer = $this->open($project, $user, ['module' => ProjectDeliverable::KEY_SITE_SURVEY]);
        $rendered++;

        $this->assertStringContainsString('cav-module__open', $drawer, 'The stretched-link anchor is gone.');
        $this->assertStringContainsString('aria-label="Close Site survey"', $drawer, 'The open row does not offer to close.');
        $this->assertStringContainsString('cav-module--active', $drawer, 'The row does not own the drawer beneath it.');

        // ── 3. STEP 1 OF 3 ──────────────────────────────────────────────────
        $one = $this->open($project, $user, [
            'module' => ProjectDeliverable::KEY_SITE_SURVEY,
            'action' => 'generate',
        ]);
        $rendered++;

        $one = $this->docForm($one);

        $this->assertStringContainsString('Step 1 of 3 · Dates, contact and engineer', $one);

        foreach (['surveyor_name', 'site_contact_name', 'site_contact_phone', 'visit_scheduled_date'] as $key) {
            $this->assertStringContainsString('name="'.$key.'"', $one, "Step 1 does not ask for [{$key}].");
        }

        $this->assertStringContainsString('name="visit_engineers[]"', $one, 'Step 1 does not offer the engineer.');
        $this->assertStringContainsString('value="next"', $one, 'Step 1 offers no Next.');
        $this->assertStringNotContainsString('value="back"', $one, 'Step 1 offers a Back to nowhere.');
        $this->assertStringNotContainsString('name="format"', $one, 'No step asks for a format (item 8).');
        $this->assertStringNotContainsString('name="survey_date"', $one, 'The second date question is back (item 1).');
        $this->assertStringNotContainsString('Generate document', $one, 'Step 1 offers the submit.');
        // A CONTROL THE PM MUST ANSWER, TOLD APART FROM A VALUE RIDING ALONG.
        // `general_notes` IS present on step 1 — as a hidden CARRY input, which
        // is the mechanism, not a leak. The honest question is whether it is
        // ASKED here, so the hidden inputs are stripped before asking it.
        $oneVisible = $this->visibleControls($one);

        $this->assertStringContainsString(
            '<input type="hidden" name="general_notes"',
            $one,
            'The step 2 answer is not carried at all, so a Back from step 2 would lose it.',
        );
        $this->assertStringNotContainsString('name="general_notes"', $oneVisible, 'Step 2 is ASKED on step 1.');
        $this->assertStringNotContainsString('name="visit_rooms[]"', $oneVisible, 'Step 3 is ASKED on step 1.');
        $this->assertStringContainsString('name="visit_scheduled_date"', $oneVisible, 'Step 1 asks nothing at all — the strip was too greedy.');
        // THE LABEL THAT SURVIVED, RENDERED. One date, and it is the visit's.
        $this->assertStringContainsString('Visit date', $oneVisible);
        $this->assertStringNotContainsString('Survey date', $oneVisible);
        // AND THE LABEL CHANGE (item 3), on the state that renders it.
        $this->assertStringContainsString('Survey Engineer', $oneVisible);

        // ── 4. NO COMMS ROOM FIELD IS ON THIS FORM (GCW-04) ─────────────────
        foreach (['comms_room_access_status', 'comms_room_access_notes'] as $key) {
            $this->assertStringNotContainsString($key, $one, "The office form still asks for [{$key}].");
        }

        // ── 5. NEXT → STEP 2, CARRYING STEP 1 ───────────────────────────────
        [$post, $two] = $this->advance($project, $user, $this->base($project, 1, 'next') + $this->stepOne());
        $rendered++;

        $post->assertRedirect($this->stepUrl($project, ProjectDeliverable::KEY_SITE_SURVEY, 2));

        $two = $this->docForm($two);

        $this->assertStringContainsString('Step 2 of 3 · Notes', $two);
        $this->assertStringContainsString('name="general_notes"', $two, 'Step 2 does not ask for the notes.');
        $this->assertStringContainsString('<input type="hidden" name="visit_scheduled_date" value="2026-10-14">', $two);
        $this->assertStringContainsString('<input type="hidden" name="surveyor_name" value="Dev Chandra">', $two);
        $this->assertStringContainsString('<input type="hidden" name="site_contact_name" value="Ruth Okafor">', $two);
        $this->assertStringContainsString('<input type="hidden" name="visit_engineers[]" value="Dev Chandra">', $two);
        $this->assertStringContainsString('value="back"', $two, 'Step 2 offers no Back.');
        $this->assertStringNotContainsString('name="format"', $two, 'No step asks for a format (item 8).');

        foreach (['comms_room_access_status', 'comms_room_access_notes'] as $key) {
            $this->assertStringNotContainsString($key, $two, "Comms room is on step 2, which D-03 forbids: [{$key}].");
        }

        // ── 6. NEXT → STEP 3: THE SPACES, EVERY ONE TICKED, AND THE OUTPUT ──
        [, $three] = $this->advance(
            $project,
            $user,
            $this->base($project, 2, 'next') + ['general_notes' => 'Two spaces, one riser.'] + $this->stepOne(),
        );
        $rendered++;

        $three = $this->docForm($three);

        $this->assertStringContainsString('Step 3 of 3 · Spaces and output', $three);

        foreach (self::SPACES as $space) {
            $this->assertMatchesRegularExpression(
                '/<input[^>]*name="visit_rooms\[\]"[^>]*value="'.preg_quote($space, '/').'"[^>]*checked/',
                $three,
                "The space [{$space}] is not offered TICKED on step 3. D-02 says default all.",
            );
        }

        // THE OUTPUTS, NAMED ON THE STEP THAT PRODUCES THEM — in the user's own
        // three words (item 8). No format radio on any step.
        $this->assertStringContainsString(
            'Generating creates the engineer link, Word and PDF.',
            $three,
            'Step 3 does not name what generating produces.',
        );
        $this->assertStringNotContainsString('name="format"', $three, 'The format question is back.');
        $this->assertStringContainsString('Generate document', $three, 'Step 3 offers no submit.');
        $this->assertStringNotContainsString('value="next"', $three, 'The last step offers a Next.');

        // ── 7. BACK → STEP 2, STILL CARRYING STEP 1. THEN FORWARD AGAIN ─────
        [$backPost, $backTwo] = $this->advance(
            $project,
            $user,
            $this->base($project, 3, 'back') + ['general_notes' => 'Two spaces, one riser.'] + $this->stepOne(),
        );
        $rendered++;

        $backPost->assertRedirect($this->stepUrl($project, ProjectDeliverable::KEY_SITE_SURVEY, 2));

        $backTwo = $this->docForm($backTwo);

        $this->assertStringContainsString('Step 2 of 3 · Notes', $backTwo);
        $this->assertStringContainsString('<input type="hidden" name="visit_scheduled_date" value="2026-10-14">', $backTwo);

        [, $threeAgain] = $this->advance(
            $project,
            $user,
            $this->base($project, 2, 'next') + ['general_notes' => 'Two spaces, one riser.'] + $this->stepOne(),
        );
        $rendered++;

        $this->assertStringContainsString('Step 3 of 3 · Spaces and output', $this->docForm($threeAgain));

        // Nothing has been written by any of the six requests above.
        $this->assertSame(0, SiteSurvey::where('project_id', $project->id)->count(), 'A step advance created a survey.');
        $this->assertSame(0, Visit::where('project_id', $project->id)->count(), 'A step advance created a visit.');

        // ── 8. UNTICK ONE SPACE, THEN CREATE ────────────────────────────────
        $create = $this->submit($project, $user, $this->base($project, 3, 'create') + [
            'format'        => 'word',
            'general_notes' => 'Two spaces, one riser.',
            'visit_rooms'   => ['Boardroom'],
        ] + $this->stepOne());

        $create->assertRedirect()->assertSessionHasNoErrors();

        // ── 9. ONE SURVEY, ONE VISIT, ONE ACTIVITY ROW, THE TICKED SPACES ───
        $surveys = SiteSurvey::where('project_id', $project->id)->get();
        $visits  = Visit::where('project_id', $project->id)->get();

        $this->assertCount(1, $surveys, 'One survey, never two.');
        $this->assertCount(1, $visits, 'One visit.');

        $survey = $surveys->first();
        $visit  = $visits->first();

        $this->assertNotNull($visit->sent_at, 'The engineer link was never issued: `sent_at` is null.');
        $this->assertSame(Visit::SOURCE_SITE_SURVEY, $visit->source_type);
        $this->assertSame($survey->id, $visit->source_id, 'The visit points at a different survey.');
        $this->assertSame(['Boardroom'], $visit->rooms_in_scope, 'The unticked space was stored anyway.');

        $this->assertSame(
            1,
            ProjectActivityLog::where('project_id', $project->id)
                ->where('action', ProjectActivityLog::ACTION_VISIT_CREATED)
                ->count(),
            'One creation logged more than one visit.',
        );

        // ── 10. THE FLASH NAMES ALL THREE AND CLAIMS NO FILE ────────────────
        $success = (string) session('success');

        $this->assertStringContainsString('queued', $success, 'The flash claims a file rather than a queued build.');
        $this->assertStringContainsString('visit', $success);
        $this->assertStringContainsString('engineer link', $success);

        // ── 11. THE LINK THE PM WAS PROMISED ACTUALLY OPENS ─────────────────
        $this->assertNotNull($survey->access_token, 'The survey carries no token, so there is no link.');

        $this->get($survey->publicUrl())->assertOk();
        $rendered++;

        $this->assertSame(
            8,
            $rendered,
            'Eight pages were rendered on Walk A: the cockpit, the open drawer, steps 1/2/3, '
            .'the Back to step 2, step 3 again, and the public engineer link.',
        );
    }

    // ══ WALK B — THE COMMS-ROOM SAFETY FENCE (GCW-04) ═══════════════════════

    /**
     * REMOVING IT IS THE REGRESSION. D-03 rules that *"dont need comms room"*
     * is about the OFFICE form and nothing else: the surveyor is still asked on
     * site, the Word document still renders it, and the survey→install
     * carry-forward still carries it. Walk A asserted the office half. This
     * asserts the half that must NOT have moved.
     */
    public function test_walk_b_the_surveyor_is_still_asked_for_the_comms_room_on_site(): void
    {
        $project = $this->project();
        $user    = $this->user();
        $this->resources();

        $this->submit($project, $user, $this->base($project, 3, 'create') + [
            'format'      => 'word',
            'visit_rooms' => self::SPACES,
        ] + $this->stepOne())->assertRedirect()->assertSessionHasNoErrors();

        $survey = SiteSurvey::where('project_id', $project->id)->firstOrFail();

        $onSite = $this->get($survey->publicUrl())->assertOk()->getContent();

        foreach (['comms_room_access_status', 'comms_room_access_notes'] as $key) {
            $this->assertStringContainsString(
                $key,
                $onSite,
                "The surveyor is no longer asked for [{$key}] on site. That is a SAFETY REGRESSION, not a simplification.",
            );
        }
    }

    /**
     * THE OTHER TWO SURFACES ARE PROVEN BY NAMED TESTS THAT ALREADY EXIST, and
     * naming them here is what stops a later rename quietly orphaning the
     * evidence. Both run in gates this plan executes:
     *
     *   · the Word document  — `tests/Feature/Documents/SiteSurveyDocxSiteLogisticsTest.php`
     *     and `tests/Feature/Documents/SiteSurveyPdfCommsAccessVocabularyTest.php`
     *   · the carry-forward  — `tests/Feature/Worksheets/SurveyCarryForwardOnEngineerLinkTest.php`
     *     (`SENTINEL-COMMS-ROOM-NOTES`) and `tests/Unit/Visits/SurveyCarryForwardTest.php`
     */
    public function test_walk_b_the_named_comms_room_evidence_files_still_exist_and_still_assert_it(): void
    {
        $evidence = [
            'tests/Feature/Documents/SiteSurveyDocxSiteLogisticsTest.php'         => 'comms_room',
            'tests/Feature/Documents/SiteSurveyPdfCommsAccessVocabularyTest.php'  => 'comms_room',
            'tests/Feature/Worksheets/SurveyCarryForwardOnEngineerLinkTest.php'   => 'SENTINEL-COMMS-ROOM-NOTES',
            'tests/Unit/Visits/SurveyCarryForwardTest.php'                        => 'comms_room',
        ];

        foreach ($evidence as $path => $needle) {
            $full = base_path($path);

            $this->assertFileExists($full, "The named comms-room evidence [{$path}] is gone.");
            $this->assertStringContainsString(
                $needle,
                (string) file_get_contents($full),
                "[{$path}] no longer asserts [{$needle}] — the evidence was renamed out from under GCW-04.",
            );
        }

        $this->assertCount(4, $evidence, 'Four named evidence files. Shrinking the list is not a fix.');
    }

    // ══ WALK C — INSTALL: ONE WORKSHEET, ONE BUILD, THREE TRAYS ═════════════

    public function test_walk_c_one_install_creation_yields_one_worksheet_and_three_photo_trays(): void
    {
        $project = $this->project();
        $user    = $this->user();
        $this->resources();

        // The worksheet is a ONE-STEP wizard ("Confirm and create"), so its
        // whole flow is: render the step, press the submit.
        $form = $this->docForm($this->open($project, $user, [
            'module' => ProjectDeliverable::KEY_WORKSHEET,
            'action' => 'generate',
        ]));

        $this->assertStringContainsString('Generate document', $form, 'The worksheet step offers no submit.');
        $this->assertStringNotContainsString('value="next"', $form, 'A one-step wizard offers a Next.');

        $this->submit($project, $user, [
            'module' => ProjectDeliverable::KEY_WORKSHEET,
            'intent' => 'create',
            'tab'    => 'overview',
            'format' => 'word',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $worksheets = Worksheet::where('project_id', $project->id)->get();

        $this->assertCount(
            1,
            $worksheets,
            'ONE creation produced more than one worksheet. `worksheetFor()` has no adoption, '
            .'so the generator must NOT be called as well as the issuer.',
        );

        Bus::assertDispatchedTimes(BuildWorksheetJob::class, 1);

        $worksheet = $worksheets->first();

        $this->assertSame(1, Visit::where('project_id', $project->id)->count(), 'One visit.');
        $this->assertNotNull($worksheet->access_token, 'The worksheet carries no token, so there is no link.');

        // THE LINK OPENS, on the worksheet this creation produced.
        $this->get($worksheet->publicUrl())->assertOk();

        // THE TRAYS ARE ASSERTED ON THIS WORKSHEET, with its rooms filled the
        // way its QUEUED build would fill them. `Bus::fake()` is armed (a real
        // dispatch would make live AI calls from a test), so the rooms are
        // written here rather than waited for — the page under test is still
        // the real public page, rendered through a real GET.
        $worksheet->forceFill([
            'generated_data' => ['rooms' => array_map(
                static fn (string $name): array => ['name' => $name],
                self::SPACES,
            )],
            'status'         => Worksheet::STATUS_FINAL,
        ])->save();

        $link = $this->get($worksheet->publicUrl())->assertOk()->getContent();

        // THREE TRAYS PER ROOM, IN CAPTURE ORDER — start → during → completion.
        preg_match_all('/data-bucket="([a-z]+)"/', $link, $matches);

        $expected = [];

        foreach (self::SPACES as $ignored) {
            foreach (self::TRAYS as $tray) {
                $expected[] = $tray['bucket'];
            }
        }

        $this->assertSame(
            $expected,
            $matches[1],
            'The engineer link does not render three trays per room in capture order (GCW-07).',
        );

        foreach (self::TRAYS as $tray) {
            $this->assertStringContainsString(
                $tray['title'],
                $link,
                "The [{$tray['bucket']}] tray title is missing or reworded.",
            );
        }

        // THE COMPLETION TITLE IS BYTE-IDENTICAL. `2026_09_26_100000` quotes it
        // as the written justification for backfilling every legacy photo, so a
        // rename falsifies a recorded decision and costs a data migration.
        $this->assertSame('📷 Photos of completed work', self::TRAYS[2]['title']);
        $this->assertSame(
            [WorksheetPhoto::BUCKET_START, WorksheetPhoto::BUCKET_DURING, WorksheetPhoto::BUCKET_COMPLETION],
            WorksheetPhoto::BUCKETS,
            'The bucket vocabulary is no longer three values in capture order.',
        );
        $this->assertSame('completion', WorksheetPhoto::BUCKET_COMPLETION, '`completion` was renamed.');
    }

    // ══ WALK D — RAMS, STANDALONE: A DOCUMENT AND NOTHING ELSE ══════════════

    public function test_walk_d_a_standalone_rams_creates_a_document_no_visit_and_no_token(): void
    {
        $project = $this->project();
        $user    = $this->user();
        $this->resources();

        $rendered = 0;

        foreach ([1, 2, 3] as $step) {
            $html = $this->docForm($this->open($project, $user, [
                'module' => ProjectDeliverable::KEY_RAMS,
                'action' => 'generate',
                'step'   => $step,
            ]));
            $rendered++;

            $this->assertStringContainsString('Step '.$step.' of 3', $html, "RAMS step {$step} did not render.");
        }

        $this->assertSame(3, $rendered, 'Three RAMS steps were rendered through HTTP.');

        $summary = 'Strip out two legacy displays and install two 75in screens in the boardroom.';

        $this->submit($project, $user, [
            'module'                => ProjectDeliverable::KEY_RAMS,
            'intent'                => 'create',
            'step'                  => 3,
            'tab'                   => 'overview',
            'format'                => 'word',
            'planned_start_date'    => '2026-10-05',
            'planned_end_date'      => '2026-10-09',
            'planned_start_time'    => '0730',
            'working_hours'         => 'Monday-Friday, 07:30-17:00',
            'project_manager_name'  => 'Priya Mistry',
            'project_manager_phone' => '0113 000 0000',
            'project_manager_email' => 'priya@example.test',
            'lead_engineer_name'    => 'Dev Chandra',
            'lead_engineer_phone'   => '07700 900111',
            'contact_name'          => 'Sam Bright',
            'contact_phone'         => '07700 900444',
            'contact_email'         => 'sam.bright@example.test',
            'job_summary'           => $summary,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $rams = RamsDocument::where('project_id', $project->id)->get();

        $this->assertCount(1, $rams, 'One RAMS document.');

        $this->assertSame(
            $summary,
            $rams->first()->form_data['works_description'] ?? null,
            'The job summary a PM typed never reached the key `RamsBuilderService` reads.',
        );

        // NO VISIT, NO LINK (D-04). An install's link is the install's.
        $this->assertSame(0, Visit::where('project_id', $project->id)->count(), 'A RAMS created a visit.');
        $this->assertSame(0, SiteSurvey::where('project_id', $project->id)->count(), 'A RAMS created a survey, and every survey carries a token.');
        $this->assertSame([], VisitLinkIssuer::typesFor(ProjectDeliverable::KEY_RAMS), 'RAMS gained an engineer link.');
    }

    // ══ WALK E — ABANDONMENT AND FAILURE ════════════════════════════════════

    public function test_walk_e_abandoning_at_any_step_leaves_six_tables_exactly_as_they_were(): void
    {
        $project = $this->project();
        $user    = $this->user();
        $this->resources();

        $before    = $this->rowCounts();
        $abandoned = 0;

        foreach ([1, 2] as $step) {
            // Walk TO the step, then walk away — first to the form's own Cancel
            // target, then to the cockpit page, which is what closing a tab and
            // coming back looks like.
            $this->advance($project, $user, $this->base($project, $step, 'next') + $this->stepOne());

            $this->actingAs($user)->get($this->cockpitUrl($project, ['module' => ProjectDeliverable::KEY_SITE_SURVEY]))->assertOk();
            $this->actingAs($user)->get($this->cockpitUrl($project))->assertOk();

            $abandoned++;

            foreach ($this->rowCounts() as $table => $count) {
                $this->assertSame(
                    $before[$table],
                    $count,
                    "A wizard abandoned after step {$step} wrote to `{$table}`. Nothing persists until the final step.",
                );
            }
        }

        $this->assertCount(6, $before, 'Six tables are snapshotted, by name.');
        $this->assertSame(2, $abandoned, 'Both non-final steps were abandoned and judged.');

        // THE ONLY GUARANTEE THAT MEANS "NO LINK": every survey is given its
        // token on creation, so no survey is the whole of it.
        $this->assertSame(0, SiteSurvey::where('project_id', $project->id)->count());
        $this->assertSame(0, Visit::where('project_id', $project->id)->count());

        // THE MIRROR — finish it, and all three DO appear. Without this the
        // zeroes above would pass on a form that never worked at all.
        $this->submit($project, $user, $this->base($project, 3, 'create') + [
            'format'      => 'word',
            'visit_rooms' => self::SPACES,
        ] + $this->stepOne())->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, SiteSurvey::where('project_id', $project->id)->count());
        $this->assertSame(1, Visit::where('project_id', $project->id)->count());
    }

    /**
     * FORCE THE FAILURE AT THE LATEST POSSIBLE POINT INSIDE THE TRANSACTION.
     *
     * ⚠ `VisitLinkIssuer` is `final`, so it can be neither subclassed nor
     * mocked, and the phase's scope fence forbids editing it. The throw comes
     * instead from `ProjectService::log()` — the LAST collaborator inside the
     * transaction — which is STRICTLY STRONGER: by the time it fires, the
     * document, the visit AND the engineer link have all already succeeded.
     */
    private function forceLogFailure(): void
    {
        $double = new class extends ProjectService {
            public function __construct()
            {
            }

            public function log(
                Project $project,
                User $user,
                string $action,
                string $description,
                ?string $fromStatus = null,
                ?string $toStatus = null,
                ?array $metadata = null,
            ): ProjectActivityLog {
                if (CockpitWizardEndToEndTest::$failing) {
                    throw new RuntimeException('The creation could not be completed.');
                }

                return parent::log($project, $user, $action, $description, $fromStatus, $toStatus, $metadata);
            }
        };

        $this->app->instance(ProjectService::class, $double);

        self::$failing = true;
    }

    public function test_walk_e_a_failed_creation_names_which_half_happened_and_flashes_no_success(): void
    {
        $project = $this->project();
        $user    = $this->user();
        $this->resources();

        $before = $this->rowCounts();

        $this->forceLogFailure();

        $response = $this->submit($project, $user, $this->base($project, 3, 'create') + [
            'format'      => 'word',
            'visit_rooms' => self::SPACES,
        ] + $this->stepOne());

        $response->assertRedirect();

        // NO SUCCESS FOR A HALF-RUN. `successMessage()` returns null whenever
        // the outcome is incomplete, so there is nothing to flash rather than a
        // sentence a caller forgot to suppress.
        $this->assertNull(session('success'), 'A failed creation flashed success.');

        // THE PM IS TOLD THROUGH THE FORM'S OWN ERROR BAG — `back()
        // ->withInput()->withErrors(['module' => ...])` — which is where the
        // doc-form already renders messages, so the sentence lands ON the
        // wizard rather than on a page the PM has navigated away from.
        $errors = session('errors');

        $this->assertNotNull($errors, 'A failed creation told the PM nothing at all.');

        $told = (string) ($errors->get('module')[0] ?? '');

        $this->assertNotSame('', $told, 'A failed creation told the PM nothing at all.');
        $this->assertStringContainsString(
            'Nothing was created',
            $told,
            'The message does not name WHICH HALF happened.',
        );
        $this->assertStringContainsString('try again', $told, 'The message does not say a retry is safe.');

        // NO EXCEPTION TEXT REACHES THE SCREEN.
        $this->assertStringNotContainsString('RuntimeException', $told);
        $this->assertStringNotContainsString('could not be completed', $told);

        foreach ($this->rowCounts() as $table => $count) {
            $this->assertSame($before[$table], $count, "A failed creation left a row behind in `{$table}`.");
        }

        // AND THE RETRY WORKS — a rollback that cannot be re-entered is not a
        // rollback, it is a dead end.
        self::$failing = false;

        $this->submit($project, $user, $this->base($project, 3, 'create') + [
            'format'      => 'word',
            'visit_rooms' => self::SPACES,
        ] + $this->stepOne())->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, SiteSurvey::where('project_id', $project->id)->count(), 'The retry did not produce exactly one survey.');
        $this->assertSame(1, Visit::where('project_id', $project->id)->count(), 'The retry did not produce exactly one visit.');
    }
}
