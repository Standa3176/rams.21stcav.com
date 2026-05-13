<?php

namespace Tests\Feature\Rams;

use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\User;
use App\Services\PdfService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 22.1 D-12 invariant: existing reviewed_data records must render
 * BYTE-IDENTICAL PDFs before and after the Phase 22.1 cleanup waves.
 *
 * Phase 22.1 is an INPUT-side duplication cleanup — generated_data shape
 * stays identical, so already-delivered RAMS PDFs render unchanged.
 *
 * This test is the canary: any Wave 2-5 plan that mutates rendered output
 * for an unchanged reviewed_data payload either has a bug or has stepped
 * outside Phase 22.1 scope (which is Phase 22.2 territory).
 *
 * Hash convention (Phase 22 WR-02): hash_file('sha256', $path) — never
 * sha256_file(). Skip pattern: class_exists / is_file($binary) guards
 * mirroring CableScheduleXlsxRegressionTest + SchematicGeneratorServiceTest.
 *
 * AI bypass: each fixture pre-populates `generated_data` directly so the
 * render path never calls RamsBuilderService (and therefore never hits
 * MethodStatementService → AIManager). This keeps the test deterministic
 * AND fast — the only variability is Puppeteer/Chromium's own PDF encoder.
 *
 * Clock pin: Carbon::setTestNow() is used in setUp() because
 * resources/views/pdf/rams.blade.php:349 calls now()->format('d/m/Y')
 * when $rams->created_at is missing. Two renders inside the same test
 * already share a frozen clock by construction (RAMS->created_at is
 * persisted at fixture creation), but pinning Carbon::now() defends
 * against any future render-time clock read that would split a test
 * spanning a date boundary.
 *
 * @see tests/Feature/Cable/CableScheduleXlsxRegressionTest.php (template)
 * @see app/Services/PdfService.php (entry point under test)
 * @see .planning/phases/22.1-rams-scope-room-data-consolidation/22.1-CONTEXT.md D-12
 */
class RamsRenderRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // H-07 testing convention: any test that writes a fixture PDF
        // through DocumentArtifactStorage MUST fake the `documents` disk
        // so storage/app/documents/ stays clean between runs.
        Storage::fake('documents');

        // Pin the wall clock so render-time date calls (rams.blade.php:349)
        // can never produce a different string between the two renders.
        Carbon::setTestNow(Carbon::parse('2026-05-13 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Skip-guard ────────────────────────────────────────────────────────────

    /**
     * Skip cleanly when the PDF runtime stack is not available on the host.
     *
     * Two gates:
     *   1. Spatie\Browsershot must be installed (PdfRenderService::fromBlade
     *      instantiates it directly — class_exists is the cheapest probe).
     *   2. If CHROME_PATH is set, the binary it points at must exist (prod
     *      sets /home/stcav/chrome; dev falls back to puppeteer's bundled
     *      Chromium download cache — we don't probe that path because
     *      puppeteer's auto-resolve is opaque from PHP).
     *
     * When skipped, the test reports a single line containing the
     * substring "not installed" or "not present" so CI log scrapers can
     * distinguish "binary absent" from "regression failure".
     */
    private function skipIfPdfRuntimeUnavailable(): void
    {
        if (! class_exists(\Spatie\Browsershot\Browsershot::class)) {
            $this->markTestSkipped('Spatie\\Browsershot not installed in this environment; RAMS PDF byte-identity regression skipped.');
        }

        $chromePath = (string) env('CHROME_PATH', '');
        if ($chromePath !== '' && ! (is_file($chromePath) || is_executable($chromePath))) {
            $this->markTestSkipped("CHROME_PATH set to {$chromePath} but binary not present in this environment; RAMS PDF byte-identity regression skipped.");
        }
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_pdf_byte_identical_across_two_renders_manual_form_fixture(): void
    {
        $this->skipIfPdfRuntimeUnavailable();

        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $record = $this->makeManualFormFixture($user, $project);

        $svc = app(PdfService::class);

        $pathA = $svc->buildRams($record->fresh());
        $pathB = $svc->buildRams($record->fresh());

        $this->assertFileExists($pathA);
        $this->assertFileExists($pathB);

        $this->assertSame(
            hash_file('sha256', $pathA),
            hash_file('sha256', $pathB),
            'D-12 invariant violated: manual-form RAMS PDF byte-output differs across two identical renders. '
            . 'PDF generation is non-deterministic, which breaks the Phase 22.1 byte-equivalence canary.'
        );
    }

    public function test_pdf_byte_identical_across_two_renders_quote_import_fixture(): void
    {
        $this->skipIfPdfRuntimeUnavailable();

        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $record = $this->makeQuoteImportFixture($user, $project);

        $svc = app(PdfService::class);

        $pathA = $svc->buildRams($record->fresh());
        $pathB = $svc->buildRams($record->fresh());

        $this->assertFileExists($pathA);
        $this->assertFileExists($pathB);

        $this->assertSame(
            hash_file('sha256', $pathA),
            hash_file('sha256', $pathB),
            'D-12 invariant violated: quote-import RAMS PDF byte-output differs across two identical renders. '
            . 'PDF generation is non-deterministic, which breaks the Phase 22.1 byte-equivalence canary.'
        );
    }

    public function test_pdf_byte_identical_across_two_renders_survey_derived_fixture(): void
    {
        $this->skipIfPdfRuntimeUnavailable();

        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $record = $this->makeSurveyDerivedFixture($user, $project);

        $svc = app(PdfService::class);

        $pathA = $svc->buildRams($record->fresh());
        $pathB = $svc->buildRams($record->fresh());

        $this->assertFileExists($pathA);
        $this->assertFileExists($pathB);

        $this->assertSame(
            hash_file('sha256', $pathA),
            hash_file('sha256', $pathB),
            'D-12 invariant violated: survey-derived RAMS PDF byte-output differs across two identical renders. '
            . 'PDF generation is non-deterministic, which breaks the Phase 22.1 byte-equivalence canary.'
        );
    }

    // ── Fixture builders ──────────────────────────────────────────────────────

    /**
     * Manual-form pipeline: simulates `POST /rams` from the create form.
     *
     * Per Phase 22.1 D-12 the test bypasses RamsBuilderService and writes
     * `generated_data` directly — the AI bypass is intentional. The PDF
     * render only reads from $rams->generated_data, so a deterministic
     * generated_data is sufficient to prove byte-stability without an AI
     * call (cost + non-determinism risk).
     */
    private function makeManualFormFixture(User $user, Project $project): RamsDocument
    {
        $formData = [
            'project_name'      => 'Manual Form Test',
            'project_ref'       => 'MF-001',
            'client_name'       => 'Acme Co',
            'site_address'      => '1 Test Street',
            'works_description' => 'Installation of AV in Boardroom — wall display + audio.',
        ];

        return RamsDocument::factory()->create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'project_name'   => $formData['project_name'],
            'project_ref'    => $formData['project_ref'],
            'client_name'    => $formData['client_name'],
            'site_address'   => $formData['site_address'],
            'form_data'      => $formData,
            'reviewed_data'  => null,
            'generated_data' => $this->deterministicGeneratedData([
                'project_name' => $formData['project_name'],
                'project_ref'  => $formData['project_ref'],
                'client_name'  => $formData['client_name'],
                'site_address' => $formData['site_address'],
                'scope_text'   => $formData['works_description'],
                'rooms'        => ['Boardroom'],
            ]),
            'status'         => RamsDocument::STATUS_COMPLETED,
        ]);
    }

    /**
     * Quote-import pipeline: simulates the post-review approved state with
     * ALL legacy per-room narrative fields populated. This exercises the
     * fallback chain that Phase 22.1 simplifies in Wave 3/4.
     *
     * `reviewed_data` is populated with the legacy 4-field per-room shape
     * (overview / works_summary / summary / description) so the audit's
     * "this is the duplication" surface is materially present in the
     * canary's input. `generated_data` is written directly to a known
     * deterministic shape (no buildFromReview call → no AI → no flakiness).
     */
    private function makeQuoteImportFixture(User $user, Project $project): RamsDocument
    {
        $reviewedData = [
            'project' => [
                'project_name'  => 'Quote Test',
                'quote_ref'     => 'Q-001',
                'client_name'   => 'Acme Co',
                'site_name'     => 'HQ',
                'site_address'  => '1 Test Street',
                'site_contact'  => 'PM Name',
                'prepared_by'   => 'Engineer',
                // LEGACY field per D-09 — should round-trip unchanged today.
                'overview'      => 'LEGACY: project.overview round-trip — D-09 to be removed',
            ],
            'scope_of_works'          => 'AV installation across the Boardroom and Cinnamon meeting rooms.',
            'works_overview'          => 'Two-room AV installation: display, audio, control.',
            'method_statement_notes'  => 'PM instructions: contractor to coordinate with site security for after-hours access.',
            'room_overviews' => [
                [
                    'room'             => 'Boardroom',
                    'overview'         => 'PM prose narrative for Boardroom.',
                    'works_summary'    => "- Install 98\" display\n- Deploy Crestron Flex",
                    // LEGACY duplicates (D-07/D-01) — present today so the
                    // canary captures them before Wave 3/4 removes them.
                    'summary'          => '- LEGACY summary bullets — should be backfilled to works_summary by DATA-04',
                    'description'      => 'LEGACY AI prose — D-01 will drop this field.',
                    'solution_type_id' => null,
                ],
                [
                    'room'             => 'Cinnamon',
                    'overview'         => 'PM prose narrative for Cinnamon.',
                    'works_summary'    => "- Install ceiling mics\n- Configure Q-SYS",
                    'summary'          => '',
                    'description'      => '',
                    'solution_type_id' => null,
                ],
            ],
            'activities' => [
                ['key' => 'install_display', 'label' => 'Install Display'],
            ],
            'hazards' => [
                ['hazard' => 'Working at height', 'risk' => 'Medium', 'control_measures' => ['Use podium steps']],
            ],
            'ppe'        => ['Hard Hat', 'Safety Boots'],
            'access'     => ['ladders' => true, 'tower' => false, 'scissor_lift' => false],
            'programme'  => ['planned_start_date' => '2026-06-01'],
            'site_logistics' => [],
        ];

        return RamsDocument::factory()->create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'project_name'   => 'Quote Test',
            'project_ref'    => 'Q-001',
            'client_name'    => 'Acme Co',
            'site_address'   => '1 Test Street',
            'form_data'      => [],
            'reviewed_data'  => $reviewedData,
            'generated_data' => $this->deterministicGeneratedData([
                'project_name' => 'Quote Test',
                'project_ref'  => 'Q-001',
                'client_name'  => 'Acme Co',
                'site_address' => '1 Test Street',
                'scope_text'   => $reviewedData['scope_of_works'],
                'rooms'        => ['Boardroom', 'Cinnamon'],
            ]),
            'status'         => RamsDocument::STATUS_COMPLETED,
        ]);
    }

    /**
     * Survey-derived pipeline: all project-wide scope strings are blank;
     * every scope sentence lives in `room_overviews[*].works_summary`.
     * This proves the per-room render path is byte-stable independently
     * of the project-wide fields (which Phase 22.1 D-03 / D-05 consolidate).
     */
    private function makeSurveyDerivedFixture(User $user, Project $project): RamsDocument
    {
        $reviewedData = [
            'project' => [
                'project_name' => 'Survey Test',
                'quote_ref'    => 'S-001',
                'client_name'  => 'Acme Co',
                'site_name'    => 'HQ',
                'site_address' => '1 Test Street',
                'site_contact' => 'PM Name',
                'prepared_by'  => 'Engineer',
                'overview'     => '',
            ],
            // All project-wide fields blank — scope lives ONLY per-room.
            'scope_of_works'         => '',
            'works_overview'         => '',
            'method_statement_notes' => '',
            'room_overviews' => [
                [
                    'room'             => 'Reception',
                    'overview'         => '',
                    'works_summary'    => "- Reception desk display\n- Welcome signage",
                    'summary'          => '',
                    'description'      => '',
                    'solution_type_id' => null,
                ],
                [
                    'room'             => 'Café',
                    'overview'         => '',
                    'works_summary'    => "- Café screen mounting\n- BGM ceiling speakers",
                    'summary'          => '',
                    'description'      => '',
                    'solution_type_id' => null,
                ],
            ],
            'activities'    => [],
            'hazards'       => [],
            'ppe'           => [],
            'access'        => [],
            'programme'     => [],
            'site_logistics' => [],
        ];

        return RamsDocument::factory()->create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'project_name'   => 'Survey Test',
            'project_ref'    => 'S-001',
            'client_name'    => 'Acme Co',
            'site_address'   => '1 Test Street',
            'form_data'      => [],
            'reviewed_data'  => $reviewedData,
            'generated_data' => $this->deterministicGeneratedData([
                'project_name' => 'Survey Test',
                'project_ref'  => 'S-001',
                'client_name'  => 'Acme Co',
                'site_address' => '1 Test Street',
                'scope_text'   => '',
                'rooms'        => ['Reception', 'Café'],
            ]),
            'status'         => RamsDocument::STATUS_COMPLETED,
        ]);
    }

    /**
     * Build a minimal-but-render-safe `generated_data` array. This is what
     * `pdf.rams.blade.php` actually reads when buildRams() is called.
     * The shape mirrors RamsDataBuilderService::assemble() output — keys
     * the template touches — so the canary captures the same render path
     * production exercises, minus the AI call.
     *
     * KEEP THIS DETERMINISTIC: any non-deterministic input (random IDs,
     * unpinned timestamps) here will surface as hash drift in CI.
     */
    private function deterministicGeneratedData(array $opts): array
    {
        $rooms = $opts['rooms'] ?? [];
        $roomOverviews = array_map(
            fn (string $r) => [
                'room'          => $r,
                'overview'      => '',
                'works_summary' => "- Install AV in {$r}",
                'summary'       => '',
                'description'   => '',
            ],
            $rooms,
        );

        return [
            'project' => [
                'name'                => $opts['project_name'] ?? '',
                'ref'                 => $opts['project_ref']  ?? '',
                'client'              => $opts['client_name']  ?? '',
                'site_address'        => $opts['site_address'] ?? '',
                'project_manager'     => 'PM Name',
                'lead_engineer'       => 'Lead Engineer',
                'doc_author'          => 'PM Name',
                'revision'            => 'Rev 1.0',
                'document_status'     => 'For Issue',
                'working_hours'       => 'Monday–Friday, 09:00–17:30',
                'planned_start_date'  => '2026-06-01',
                'planned_end_date'    => '2026-06-05',
            ],
            'scope_of_works'         => $opts['scope_text']        ?? '',
            'works_overview'         => $opts['scope_text']        ?? '',
            'method_statement_notes' => '',
            'rooms'                  => $rooms,
            'room_overviews'         => $roomOverviews,
            'method_statement'       => [
                'phases' => [
                    ['title' => 'Mobilisation', 'steps' => ['Site induction', 'Tool check']],
                    ['title' => 'Installation', 'steps' => ['Mount displays', 'Run cabling']],
                    ['title' => 'Commissioning', 'steps' => ['Power up', 'Client sign-off']],
                ],
            ],
            'equipment'         => [],
            'hazards'           => [],
            'ppe'               => [],
            'access_equipment'  => ['Kick Stool'],
            'activities'        => [],
            'scope_items'       => ['decommission' => [], 'retained' => [], 'new_install' => []],
            'site_logistics'    => [],
            'team'              => [
                ['role' => 'Project Manager', 'name' => 'PM Name', 'mobile' => ''],
                ['role' => 'Lead Engineer',   'name' => 'Lead Engineer', 'mobile' => ''],
            ],
        ];
    }
}
