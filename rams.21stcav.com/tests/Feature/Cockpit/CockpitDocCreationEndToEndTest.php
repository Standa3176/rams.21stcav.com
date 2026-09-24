<?php

namespace Tests\Feature\Cockpit;

use App\Jobs\BuildOmManualJob;
use App\Jobs\BuildRamsDocumentJob;
use App\Jobs\BuildWorksheetJob;
use App\Models\LabourResource;
use App\Models\OmManual;
use App\Models\Project;
use App\Models\ProjectDeliverable;
use App\Models\ProjectPackage;
use App\Models\RamsDocument;
use App\Models\SiteSurvey;
use App\Models\Snag;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitNote;
use App\Models\Worksheet;
use App\Services\Rams\RamsDisplayPatchService;
use App\Services\RamsReviewDataService;
use App\Services\WorksheetDocxService;
use App\Support\Cockpit\CockpitModulePresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Phase 46.2, Plan 46.2-06 — THE PHASE AS ONE WALK, THROUGH HTTP.
 *
 * Plans 46.2-01..46.2-05 each proved a piece: four rows, the format inventory,
 * the unsurfacing, the field map, the form and the write. This file walks them
 * together through the real request surface, and it draws ONE line in public:
 *
 * ── THE SPEND BOUNDARY, AND WHY IT IS AN ASSERTION AND NOT A COMMENT ───────
 *
 * Three of the four documents build their CONTENT with a model call:
 * `BuildRamsDocumentJob`, `BuildWorksheetJob` (step 1, per-room AI) and
 * `BuildOmManualJob`. Running them costs the user real money on their Anthropic
 * account, and that spend was NOT authorised for this plan. `QUEUE_CONNECTION`
 * is `sync` in `phpunit.xml`, so an un-faked dispatch would RUN the job inline
 * and spend. Therefore:
 *
 *   • every test that reaches a generator calls `Bus::fake()` FIRST, and asserts
 *     the job was dispatched rather than letting it run — the dispatch IS the
 *     delegation, and the delegation is what this phase owns;
 *   • `Http::fake()` in `setUp()` is the second belt: nothing may leave the
 *     machine even if a future generator stops going through the bus;
 *   • exactly ONE artifact is produced and opened — the WORKSHEET .docx, whose
 *     writer (`WorksheetDocxService`) is pure PhpWord template assembly with no
 *     model call anywhere in it. The AI-authored CONTENT it renders is seeded
 *     here as a fixture, named as such, so the assembly-and-download half is
 *     proven honestly and the authoring half is not faked into looking proven.
 *
 * The RAMS/O&M/site-survey ARTIFACTS ARE THEREFORE NOT VERIFIED BY THIS FILE,
 * and `46.2-06-SUMMARY.md` records that by name with the reason. An honest gap
 * recorded beats a gap papered over (DC-07's own house rule, VL-12).
 *
 * ── WHAT IS PROVEN FOR THE THREE, WHICH IS EVERYTHING SHORT OF THE BYTES ───
 *
 * The POST validates; the values land in the columns the generator reads; the
 * delegation is reached (the job is dispatched / the row is created); the format
 * the PM chose resolves to a route that exists. For the RAMS the walk goes one
 * step FURTHER than 46.2-05 did, deterministically and for free: the typed start
 * date and engineer name are followed through
 * `RamsReviewDataService::normalise()` into `RamsDocument::reviewed_data` and
 * then through `RamsDisplayPatchService::patch()` — the service whose output IS
 * the cover's data — so "a value that round-trips through the payload but never
 * reaches the document" is caught without rendering anything.
 *
 * ── DC-09: UNSURFACED IS NOT DELETED ──────────────────────────────────────
 *
 * `test_the_visit_work_is_reachable_at_its_own_routes_on_this_commit()` drives
 * all five visit writes and the photo ZIP on this commit while asserting that no
 * anchor and no form on the cockpit points at any of them. That pair of facts,
 * on one commit, is the difference between "we took it off the page" and "we
 * deleted it", and it is the only form of that claim worth making.
 */
class CockpitDocCreationEndToEndTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cockpit.enabled' => true]);

        // The worksheet artifact is written through `DocumentArtifactStorage`,
        // which resolves `Storage::disk('local')->path()`. Faking the disk keeps
        // a real file on a real filesystem — the assertion needs bytes — while
        // landing it in the framework's temp root instead of the repo's.
        Storage::fake('local');
        Storage::fake('public');

        // SECOND BELT ON THE SPEND BOUNDARY. `Bus::fake()` stops the queued
        // builders; this stops anything that ever stopped going through them.
        Http::fake();
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function project(): Project
    {
        return Project::factory()->create([
            'name'         => 'Northbank Fitout',
            'ref'          => 'Q-4626',
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
                'project'                => ['project_name' => 'Northbank Fitout'],
                'equipment'              => [['quantity' => 2, 'part_number' => 'SC-75', 'name' => '75in display']],
                'activities'             => [['key' => 'install', 'label' => 'Install and commission']],
                'ppe'                    => ['Gloves', 'Safety boots'],
            ],
            'status'         => ProjectPackage::STATUS_REVIEWED,
        ]);
    }

    private function resources(): void
    {
        LabourResource::factory()->create([
            'name'  => 'Dev Chandra', 'email' => 'dev.chandra@example.test',
            'phone' => '07700 900111', 'roles' => [LabourResource::ROLE_ENGINEER], 'is_active' => true,
        ]);

        LabourResource::factory()->create([
            'name'  => 'Marie Okonkwo', 'email' => 'marie.okonkwo@example.test',
            'phone' => '07700 900222', 'roles' => [LabourResource::ROLE_ENGINEER], 'is_active' => true,
        ]);
    }

    // ── Request helpers ─────────────────────────────────────────────────────

    private function cockpit(Project $project, array $query = [], ?User $user = null): string
    {
        return $this->actingAs($user ?? $this->user())
            ->get(route('projects.cockpit', ['project' => $project] + $query))
            ->assertOk()
            ->getContent();
    }

    private function generate(Project $project, array $payload, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user())
            ->post(route('projects.cockpit.documents.store', $project), $payload);
    }

    /** Every `href` on the page, parsed — never grepped. */
    private function hrefs(string $html): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $out = [];

        foreach ((new \DOMXPath($dom))->query('//a[@href]') as $node) {
            $out[] = $node->getAttribute('href');
        }

        return $out;
    }

    /** One element's subtree by class, entity-decoded. */
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

    /** Every `action` on every form, parsed. */
    private function formActions(string $html): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $out = [];

        foreach ((new \DOMXPath($dom))->query('//form') as $node) {
            $out[] = $node->getAttribute('action');
        }

        return $out;
    }

    // ── The headline criterion ──────────────────────────────────────────────

    public function test_the_cockpit_renders_exactly_four_module_rows(): void
    {
        $project = $this->project();
        $body    = $this->cockpit($project);

        $this->assertSame(
            4,
            substr_count($body, 'cav-module__title'),
            'The cockpit is four document rows. Nine was Phase 45; 46.2 D-01 is four.'
        );

        $this->assertCount(4, CockpitModulePresenter::moduleMap());

        foreach (['Site survey', 'Worksheet', 'RAMS'] as $title) {
            $this->assertStringContainsString($title, $body, "The {$title} row must be on the page.");
        }

        // D-07: the row is the DOCUMENT, never a visit type.
        $this->assertStringNotContainsString('First fix and install', $body);

        // D-01: the five removed rows are gone, not greyed.
        foreach (['Cable schedule', 'Drawings', 'Programming', 'Snagging'] as $gone) {
            $this->assertStringNotContainsString($gone, $body, "{$gone} was removed from the cockpit by D-01.");
        }
    }

    // ── THE ONE ARTIFACT THIS PLAN MAY HONESTLY OPEN ────────────────────────

    /**
     * The Worksheet walk: form → POST → delegation → .docx → download → OPEN.
     *
     * The seam is named where it is: `BuildWorksheetJob` step 1 is the AI author
     * and it is NOT run here (unauthorised spend). Its OUTPUT is seeded as a
     * fixture and step 4 — `WorksheetDocxService::build()`, pure PhpWord — is
     * run for real, then the bytes are fetched through `worksheets.download` and
     * opened as a zip container. Non-zero length is necessary and not
     * sufficient, which is why `word/document.xml` and the project's own data
     * are both asserted inside it (T-46.2-20).
     */
    public function test_the_worksheet_walks_from_the_form_to_a_word_file_that_opens(): void
    {
        Bus::fake();

        $pm      = $this->user();
        $project = $this->project();

        // 1. The page, then the disclosure. Scoped to the `cav-qa` block, as
        //    `CockpitDocumentFormTest` scopes it: the application shell carries
        //    forms of its own (sign-out, search), so a page-wide `<form` count
        //    would measure the chrome and not the document control.
        $closed = $this->subtree(
            $this->cockpit($project, ['module' => ProjectDeliverable::KEY_WORKSHEET], $pm),
            'cav-qa',
        );

        $this->assertNotSame('', $closed, 'The worksheet row renders no document block.');
        $this->assertStringContainsString('Generate document', $closed);
        $this->assertSame(0, substr_count($closed, '<form'), 'Closed discloses no form.');

        $open = $this->subtree(
            $this->cockpit(
                $project,
                ['module' => ProjectDeliverable::KEY_WORKSHEET, 'action' => 'generate'],
                $pm,
            ),
            'cav-qa',
        );
        $this->assertStringContainsString('<form', $open);

        // DC-07 is stated on the page, not left as a missing button.
        $this->assertStringContainsString('PDF is not available for this document (DC-07).', $open);
        $this->assertStringNotContainsString('value="pdf"', $open);

        // 2. The submission, through the real kernel.
        $this->generate($project, [
            'module' => ProjectDeliverable::KEY_WORKSHEET,
            'format' => 'word',
            'tab'    => 'overview',
        ], $pm)->assertRedirect(route('projects.cockpit', [
            'project' => $project,
            'module'  => ProjectDeliverable::KEY_WORKSHEET,
            'tab'     => 'files',
        ]));

        $worksheet = Worksheet::where('project_id', $project->id)->sole();
        $this->assertSame(Worksheet::STATUS_GENERATING, $worksheet->status);

        // 3. THE DELEGATION WAS REACHED — and STOPPED HERE, unspent.
        Bus::assertDispatched(BuildWorksheetJob::class);

        // 4. The AI-authored content, SEEDED — this is the boundary, stated.
        $worksheet->update([
            'generated_data' => $this->seededWorksheetContent($project),
            'status'         => Worksheet::STATUS_DRAFT,
        ]);

        // 5. The real writer. No model call exists inside it.
        app(WorksheetDocxService::class)->build($worksheet->generated_data, $worksheet->fresh());

        $worksheet->refresh();
        $this->assertNotEmpty($worksheet->filename, 'The writer must name the artifact on the row.');

        // 6. The download, through HTTP.
        $response = $this->actingAs($pm)
            ->get(route('worksheets.download', $worksheet))
            ->assertOk();

        $path = $response->baseResponse->getFile()->getPathname();

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path), 'A zero-byte document is a failed generation (T-46.2-20).');

        // 7. IT OPENS. A .docx is a zip; a corrupt one fails right here.
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'The .docx is not a readable zip container.');

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        $this->assertContains('word/document.xml', $names, 'No document part — Word would refuse to open this.');

        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        // 8. AND THE PROJECT'S OWN DATA IS INSIDE IT.
        $this->assertStringContainsString('Northbank Fitout', $xml, 'The document does not carry the project name.');
        $this->assertStringContainsString('Boardroom', $xml, 'The document does not carry the room.');
    }

    /**
     * What `WorksheetGeneratorService::generateContent()` returns — SEEDED, not
     * generated. Shaped after `tests/Feature/DocumentEdits/WorksheetAdapterApplyTest`
     * so the writer receives the payload it receives in production.
     */
    private function seededWorksheetContent(Project $project): array
    {
        return [
            'project' => [
                'name'         => $project->name,
                'client'       => $project->client_name,
                'ref'          => $project->ref,
                'site_address' => $project->site_address,
            ],
            'rooms' => [
                [
                    'name'                   => 'Boardroom',
                    'is_surveyed'            => true,
                    'equipment'              => [],
                    'subsystems'             => ['Display' => [['name' => 'Samsung 75 inch display']]],
                    'tools'                  => ['Drill', 'Spirit level'],
                    // A NEWLINE-DELIMITED STRING, which is the shape
                    // `WorksheetGeneratorService` actually stores and the ONLY
                    // shape `WorksheetDocxService:305` can read — it does
                    // `trim((string) $room['install_steps'])`. The array shape
                    // that `WorksheetEditAdapter:249` writes raises "Array to
                    // string conversion" there. Found by building this document;
                    // raised as D-46.2-06-02 and NOT fixed here (a generator is
                    // not this phase's to change).
                    'install_steps'          => "1. Unpack kit
2. Mount display",
                    'category_summary'       => 'Display',
                    'room_works_description' => 'Install one display.',
                ],
            ],
            'blockers'       => [],
            'warnings_panel' => [],
            'generated_at'   => now()->toIso8601String(),
        ];
    }

    // ── The three that stop at the generator boundary ───────────────────────

    /**
     * RAMS, O&M and site survey: validated, persisted, delegated — and NOT
     * rendered. The artifact for each of these three is recorded NOT VERIFIED in
     * `46.2-06-SUMMARY.md`, because rendering them costs unauthorised spend.
     */
    public function test_the_rams_reaches_its_generator_and_stops_unspent(): void
    {
        Bus::fake();

        $pm      = $this->user();
        $project = $this->project();
        $package = $this->reviewedPackage($project);
        $this->resources();

        $open = $this->subtree(
            $this->cockpit(
                $project,
                ['module' => ProjectDeliverable::KEY_RAMS, 'action' => 'generate'],
                $pm,
            ),
            'cav-qa',
        );

        // The format radio points at routes that exist — both cells, asserted
        // here on the page the PM actually sees.
        $this->assertStringContainsString('value="word"', $open);
        $this->assertStringContainsString('value="pdf"', $open);
        $this->assertTrue(Route::has('rams.download'));
        $this->assertTrue(Route::has('rams.download-pdf'));

        $this->generate($project, $this->ramsPayload(), $pm)
            ->assertRedirect(route('projects.cockpit', [
                'project' => $project, 'module' => ProjectDeliverable::KEY_RAMS, 'tab' => 'files',
            ]));

        $rams = RamsDocument::where('project_id', $project->id)->sole();

        Bus::assertDispatched(BuildRamsDocumentJob::class);
        $this->assertSame(RamsDocument::STATUS_GENERATING, $rams->status);

        // The sibling key survives the merge (46.2-05 D-A), re-proved on the walk.
        $this->assertSame(
            'A prose overview the normaliser does not carry.',
            $package->fresh()->extracted_data['overview'] ?? null,
        );
    }

    /**
     * THE LOAD-BEARING ONE. A value that round-trips through the payload but
     * never reaches the document is the failure this test exists to catch — and
     * it is caught WITHOUT rendering, because
     * `RamsDisplayPatchService::patch()` is the deterministic service whose
     * output IS the cover's and the review form's data. No model, no bytes, no
     * spend.
     */
    public function test_a_typed_start_date_and_engineer_reach_the_documents_own_data(): void
    {
        Bus::fake();

        $pm      = $this->user();
        $project = $this->project();
        $package = $this->reviewedPackage($project);
        $this->resources();

        $this->generate($project, $this->ramsPayload(), $pm)->assertRedirect();

        // 1. Through the normaliser, exactly as the generator re-reads it.
        $reviewed = app(RamsReviewDataService::class)->normalise($package->fresh()->extracted_data ?? []);

        $this->assertSame('2026-10-05', $reviewed['programme']['planned_start_date']);
        $this->assertSame('Dev Chandra', $reviewed['programme']['lead_engineer_name']);

        // 2. Onto the document the generator created.
        $rams = RamsDocument::where('project_id', $project->id)->sole();

        $this->assertSame('2026-10-05', $rams->reviewed_data['programme']['planned_start_date'] ?? null);
        $this->assertSame('Dev Chandra', $rams->reviewed_data['programme']['lead_engineer_name'] ?? null);
        $this->assertSame('Monday-Friday, 07:30-17:00', $rams->form_data['working_hours'] ?? null);

        // 3. INTO THE DOCUMENT'S OWN DATA. This is the step 46.2-05 could not take.
        app(RamsDisplayPatchService::class)->patch($rams);

        $projectBlock = $rams->generated_data['project'] ?? [];

        $this->assertSame('Dev Chandra', $projectBlock['lead_engineer'] ?? null,
            'The engineer the PM typed never reached the document data.');
        $this->assertSame('2026-10-05', $projectBlock['planned_start_date'] ?? null,
            'The start date the PM typed never reached the document data.');
        $this->assertSame('Marie Okonkwo', $projectBlock['additional_engineers'] ?? null);
        $this->assertSame('Priya Mistry', $projectBlock['project_manager'] ?? null);
    }

    /** @return array<string, mixed> */
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
            'additional_engineers'  => ['Marie Okonkwo'],
            'contact_name'          => 'Sam Bright',
            'contact_phone'         => '07700 900444',
            'contact_email'         => 'sam.bright@example.test',
        ];
    }

    public function test_the_om_manual_reaches_its_generator_and_stops_unspent(): void
    {
        Bus::fake();

        $pm      = $this->user();
        $project = $this->project();

        $this->generate($project, [
            'module'        => ProjectDeliverable::KEY_OM,
            'format'        => 'pdf',
            'tab'           => 'overview',
            'handover_date' => '2026-11-20',
            'draft'         => '1',
        ], $pm)->assertRedirect();

        // OUR write landed where the generator reads it.
        $this->assertSame('2026-11-20', $project->fresh()->handover_date?->format('Y-m-d'));

        // THE DELEGATION WAS REACHED. Two honest outcomes, both named: either the
        // existing controller created the row and queued the build, or its OWN
        // pre-flight validator blocked it and said which fields are missing.
        // There is no third outcome, and neither is invented here.
        $manual = OmManual::where('project_id', $project->id)->first();

        if ($manual !== null) {
            Bus::assertDispatched(BuildOmManualJob::class);
            $this->assertSame(OmManual::STATUS_GENERATING, $manual->status);
            $this->assertTrue((bool) ($manual->extracted_data['_draft_mode'] ?? false));
        } else {
            $this->assertNotNull(
                session()->get('errors'),
                'The O&M delegation neither created a manual nor reported its own readiness gate.'
            );
        }

        $this->assertTrue(Route::has('om-manuals.download'));
        $this->assertTrue(Route::has('om-manuals.download-pdf'));
    }

    public function test_the_site_survey_reaches_its_creator_and_stops_unspent(): void
    {
        $pm      = $this->user();
        $project = $this->project();

        $this->generate($project, [
            'module'                   => ProjectDeliverable::KEY_SITE_SURVEY,
            'format'                   => 'word',
            'tab'                      => 'overview',
            'survey_date'              => '2026-10-01',
            'surveyor_name'            => 'Kit Farrow',
            'site_contact_name'        => 'Sam Bright',
            'site_contact_phone'       => '07700 900444',
            'general_notes'            => 'Lift access booked.',
            'site_access_notes'        => 'Report to the east gate.',
            'comms_room_access_status' => 'outsourced',
            'comms_room_access_notes'  => 'Facilities need 48 hours.',
        ], $pm)->assertRedirect();

        $survey = SiteSurvey::where('project_id', $project->id)->whereNull('superseded_at')->sole();

        $this->assertSame('Kit Farrow', $survey->surveyor_name);
        $this->assertSame('Report to the east gate.', $survey->site_access_notes);
        $this->assertSame('outsourced', $survey->comms_room_access_status);

        // DELIBERATE `$fillable` OMISSIONS (security re-audit): a token is never
        // mass-assignable, and this page cannot become the first surface that
        // changes that.
        $this->assertNotContains('access_token', (new SiteSurvey())->getFillable());
        $this->assertNotContains('access_token_expires_at', (new SiteSurvey())->getFillable());

        $this->assertTrue(Route::has('site-surveys.docx'));
        $this->assertTrue(Route::has('site-surveys.pdf'));
    }

    // ── DC-09: unsurfaced is not deleted ───────────────────────────────────

    /**
     * All five visit writes and the photo ZIP, driven on THIS commit, while the
     * cockpit links to none of them. Phase 46/46.1 behaviour, re-proved beside
     * the page that stopped offering it.
     */
    public function test_the_visit_work_is_reachable_at_its_own_routes_on_this_commit(): void
    {
        Bus::fake();

        $pm      = $this->user();
        $project = $this->project();

        // 1. CREATE — the write 46.2-03 unsurfaced first.
        $this->actingAs($pm)
            ->post(route('projects.cockpit.visits.store', $project), [
                'module'         => ProjectDeliverable::KEY_WORKSHEET,
                'visit_type'     => Visit::TYPE_INSTALL,
                'scheduled_date' => '2026-11-02',
                'rooms'          => ['Boardroom'],
            ])
            ->assertRedirect();

        $install   = Visit::where('project_id', $project->id)->sole();
        $worksheet = Worksheet::where('project_id', $project->id)->sole();

        $this->assertSame(Visit::SOURCE_WORKSHEET, $install->source_type);
        $this->assertNotEmpty($worksheet->access_token, 'The visit must still come with an engineer link.');
        Bus::assertDispatched(BuildWorksheetJob::class);

        // 2. A RETURNED survey visit — the state the four review acts need.
        $returned = $this->returnedSurveyVisit($project);

        // 3. NOTE.
        $this->actingAs($pm)->post(
            route('projects.cockpit.visits.notes', ['project' => $project, 'visit' => $returned]),
            ['body' => 'Cable route photo is missing for the second floor.'],
        )->assertRedirect();

        $this->assertSame(1, VisitNote::where('visit_id', $returned->id)->count());

        // 4. SNAG.
        $this->actingAs($pm)->post(
            route('projects.cockpit.visits.snags', ['project' => $project, 'visit' => $returned]),
            ['title' => 'Trunking not made good in the comms room'],
        )->assertRedirect();

        $this->assertSame(1, Snag::where('visit_id', $returned->id)->count());

        // 5. SEND BACK — reopens the engineer's link (46.1's own invariant).
        $this->actingAs($pm)->post(
            route('projects.cockpit.visits.send-back', ['project' => $project, 'visit' => $returned]),
            ['reason' => 'Please photograph the containment run.'],
        )->assertRedirect();

        $this->assertNotNull($returned->refresh()->sent_back_at);

        // 6. ACCEPT — on a second returned visit, so the two acts are not fighting
        //    over one row's state.
        $second = $this->returnedSurveyVisit($project);

        $this->actingAs($pm)->post(
            route('projects.cockpit.visits.accept', ['project' => $project, 'visit' => $second]),
        )->assertRedirect();

        $this->assertNotNull($second->refresh()->accepted_at);

        // 7. THE PHOTO ZIP still streams.
        $zipResponse = $this->actingAs($pm)
            ->get(route('projects.cockpit.visits.photos-zip', ['project' => $project, 'visit' => $second]))
            ->assertOk();

        $zipPath = $zipResponse->baseResponse->getFile()->getPathname();
        $zip     = new ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true, 'The evidence archive no longer opens.');
        $zip->close();

        // 8. AND NOTHING ON THE COCKPIT POINTS AT ANY OF IT — parsed, not grepped.
        foreach (['overview', 'files', 'notes'] as $tab) {
            $body = $this->cockpit($project, ['module' => ProjectDeliverable::KEY_WORKSHEET, 'tab' => $tab], $pm);

            foreach ($this->hrefs($body) as $href) {
                $this->assertStringNotContainsString('/cockpit/visits/', $href, "The cockpit links to `{$href}`.");
            }

            // AND NO FORM POSTS TO ONE EITHER — parsed over `<form action>`,
            // which is the claim that matters: an anchor reads, a form ACTS.
            //
            // NOT A RAW `assertStringNotContainsString('cockpit/visits', $body)`,
            // and that is a finding rather than a concession:
            // `resources/views/layouts/app.blade.php:858` carries the literal
            // `<x-edit-action-bar/>` INSIDE A CSS COMMENT, so Blade compiles and
            // renders that component into the `<style>` block of EVERY page in
            // the app, Cancel link included — and that link is
            // `url()->previous()`, which during this walk is the photo ZIP this
            // very test just downloaded. The string therefore appears on the page
            // without anything on the cockpit linking anywhere. The layout is one
            // of the three sha256-PINNED files, so it is reported, not touched
            // (see `46.2-06-SUMMARY.md`, D-46.2-06-01).
            foreach ($this->formActions($body) as $action) {
                $this->assertStringNotContainsString('/cockpit/visits', $action, "The cockpit posts to `{$action}`.");
            }

            // The copy bans are scoped to the COCKPIT REGION, which is the
            // fence's own scope. Asserted over the whole body they would fail on
            // the application shell (`Accept` occurs in the layout's own markup),
            // and a ban that fires on the chrome is a ban that teaches nothing.
            $region = $this->subtree($body, 'cav-cockpit');

            $this->assertNotSame('', $region, 'The cockpit region did not render — the ban would pass vacuously.');

            foreach (['Accept', 'Send back', 'Add note', 'Raise a snag', 'Download all photos (ZIP)'] as $act) {
                $this->assertStringNotContainsString($act, $region, "`{$act}` is back on the cockpit.");
            }
        }
    }

    private function returnedSurveyVisit(Project $project): Visit
    {
        $survey = SiteSurvey::create([
            'user_id'      => $this->user('Engineer Eve')->id,
            'project_id'   => $project->id,
            'project_name' => 'Doc Walk Fixture',
            'status'       => 'completed',
        ]);

        $survey->forceFill([
            'submitted_at' => now()->subDays(2),
            'survey_data'  => ['comms_room' => 'Second floor, keyed access'],
        ])->save();

        return Visit::factory()->create([
            'project_id'  => $project->id,
            'type'        => Visit::TYPE_SITE_SURVEY,
            'status'      => Visit::STATUS_PLANNED,
            'sent_at'     => now()->subDays(3),
            'source_type' => Visit::SOURCE_SITE_SURVEY,
            'source_id'   => $survey->id,
        ]);
    }

    /**
     * DELEGATE, NEVER DUPLICATE. The engineer links and the survey→install
     * carry-forward are proved by four files this phase never edited; a second
     * copy of their assertions here would drift from them. So this asserts they
     * still EXIST and are still named, and the gate RUNS them
     * (`-Path tests/Feature/Visits`, `-Path tests/Unit/Visits`).
     */
    public function test_the_engineer_links_and_the_carry_forward_are_unchanged(): void
    {
        foreach ([
            'tests/Feature/Visits/PublicTokenRoutesUnaffectedTest.php',
            'tests/Feature/Visits/SendBackReopensEngineerLinkTest.php',
            'tests/Feature/Visits/VisitLifecycleEndToEndTest.php',
            'tests/Unit/Visits/SurveyCarryForwardTest.php',
        ] as $file) {
            $this->assertFileExists(base_path($file), "{$file} was the proof. It must not be deleted.");
        }

        // The engineer's own routes, on this commit.
        foreach (['survey.show', 'public-worksheet.show'] as $name) {
            $this->assertTrue(
                Route::has($name),
                "The engineer link route `{$name}` must still resolve (D-02)."
            );
        }
    }
}
