<?php

namespace Tests\Feature\Rams;

use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\User;
use App\Services\DocxBuilderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use Tests\TestCase;

/**
 * Sidebar fix 2026-05-14 — docx-builder-pdf-parity.
 *
 * Parity guard for DocxBuilderService against the PDF blade
 * (resources/views/pdf/rams.blade.php). The DOCX renderer had drifted
 * across 12 axes (D1-D12) — covered by 3 atomic commits:
 *
 *   Commit 1 — Data parity (D1, D2, D3, D4 + cover personnel rows, D5)
 *   Commit 2 — Structural fixes (D6, D7, D8, D9)
 *   Commit 3 — Missing sections (D10, D11, D12)
 *
 * Each commit's tests live in its own block below. Together they assert
 * the rendered word/document.xml contains/omits the expected fragments
 * for a deterministic fixture RAMS record (no AI, no Browsershot, no
 * external services — pure PhpWord rendering).
 *
 * See .planning/notes/2026-05-14-docx-builder-pdf-parity.md and
 * .planning/debug/docx-builder-pdf-parity.md.
 */
class DocxBuilderPdfParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // H-07 testing convention: fake the documents disk so DOCX output
        // stays out of storage/app/documents/ between runs.
        Storage::fake('documents');
        // Pin the wall clock so $record->created_at-driven date strings
        // are stable across renders.
        Carbon::setTestNow(Carbon::parse('2026-05-14 14:30:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Fixture builder ──────────────────────────────────────────────────────

    /**
     * Build a RamsDocument fixture with explicit overrides applied to its
     * generated_data['project'] block.
     *
     * @param array<string, mixed> $projectOverrides Keys merged into generated_data.project.
     * @param array<string, mixed> $generatedOverrides Top-level keys merged into generated_data.
     * @param array<string, mixed> $reviewedOverrides Top-level reviewed_data assignment.
     */
    private function makeRams(
        array $projectOverrides = [],
        array $generatedOverrides = [],
        array $reviewedOverrides = [],
    ): RamsDocument {
        $user = User::factory()->create(['name' => 'Sonny Tanda']);
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'name'    => 'Light Forms AV Refresh',
        ]);

        $generated = array_merge([
            'project' => array_merge([
                'name'            => 'Light Forms AV Refresh',
                'ref'             => '21CQ30451-01-OPS',
                'client'          => 'Light Forms Ltd',
                'site_address'    => '1 Test Street, London',
                'doc_author'      => 'Sonny',
                'revision'        => 'Rev 1.0',
                'document_status' => 'For Issue',
                'working_hours'   => 'Monday–Friday, 09:00–17:30',
            ], $projectOverrides),
            'team'    => [
                ['role' => 'Project Manager', 'name' => 'Sonny'],
            ],
            'hazards' => [],
            'method_statement' => ['phases' => []],
        ], $generatedOverrides);

        return RamsDocument::factory()->create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'project_name'   => 'Light Forms AV Refresh',
            'project_ref'    => '21CQ30451-01-OPS',
            'client_name'    => 'Light Forms Ltd',
            'site_address'   => '1 Test Street, London',
            'form_data'      => [],
            'generated_data' => $generated,
            'reviewed_data'  => $reviewedOverrides ?: null,
            'status'         => RamsDocument::STATUS_COMPLETED,
        ]);
    }

    /** Render the DOCX and return its document.xml contents. */
    private function renderDocumentXml(RamsDocument $record): string
    {
        $builder = app(DocxBuilderService::class);
        $path = $builder->build($record->generated_data ?? [], $record->fresh());

        $this->assertFileExists($path);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'Failed to open generated DOCX as zip.');
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        $this->assertIsString($xml);

        return $xml;
    }

    // ── Commit 1 — Data parity (D1, D2, D3, D4 + cover personnel rows, D5) ──

    public function test_doc_author_renders_owner_name_not_client_email(): void
    {
        // D1 — the RamsDisplayPatchService overwrites a doc_author that is
        // an email address with the project_manager / owner name. The DOCX
        // path now applies this patch at build() entry.
        $record = $this->makeRams([
            'doc_author'      => 'Marius@LIGHTFORMS.COM',
            'project_manager' => 'Sonny',
        ]);

        $xml = $this->renderDocumentXml($record);

        $this->assertStringNotContainsString('Marius@LIGHTFORMS.COM', $xml,
            'D1: doc_author leaked client email — patch service was not applied to DOCX build path.');
        $this->assertStringContainsString('Sonny', $xml);
    }

    public function test_date_renders_in_dd_mm_yyyy_from_created_at(): void
    {
        // D2 — DOCX must use $record->created_at->format('d/m/Y') for the
        // cover DATE row + Document Control row, matching the PDF.
        $record = $this->makeRams();

        $xml = $this->renderDocumentXml($record);

        // Carbon::setTestNow pinned to 2026-05-14
        $this->assertStringContainsString('14/05/2026', $xml,
            'D2: cover/doc-control date not in d/m/Y format from created_at.');
        // The legacy "F Y" placeholder ("May 2026") must NOT appear.
        $this->assertStringNotContainsString('May 2026', $xml,
            'D2: legacy "F Y" date placeholder still rendered on the cover.');
    }

    public function test_rooms_field_resolves_from_reviewed_data_room_overviews(): void
    {
        // D3 — DOCX must fall back to reviewed_data['room_overviews'] for the
        // ROOMS cover field when $project['rooms'] is empty (the package-124
        // failure mode where Claude-vision extractor populates room_overviews
        // but legacy rooms[] stays empty).
        $record = $this->makeRams(
            projectOverrides: ['rooms' => []],
            reviewedOverrides: [
                'room_overviews' => [
                    ['room' => 'Boardroom',   'overview' => 'AV refresh.'],
                    ['room' => 'Reception',   'overview' => 'Digital signage.'],
                ],
            ],
        );

        $xml = $this->renderDocumentXml($record);

        $this->assertStringContainsString('Boardroom', $xml,
            'D3: ROOMS field empty — reviewed_data.room_overviews chain not consulted.');
        $this->assertStringContainsString('Reception', $xml);
    }

    public function test_team_requirements_row_renders_pm_with_full_requirements(): void
    {
        // D4 — team row label must aggregate names ("Project Manager — Sonny")
        // and the requirements column must use the full PDF $reqMap, not the
        // stub two-entry map. The PDF's full PM requirements string starts
        // with "SMSTS or equivalent. CSCS Card."
        $record = $this->makeRams(
            generatedOverrides: [
                'team' => [
                    ['role' => 'Project Manager', 'name' => 'Sonny'],
                ],
            ],
        );

        $xml = $this->renderDocumentXml($record);

        $this->assertStringContainsString('Project Manager', $xml);
        $this->assertStringContainsString('Sonny', $xml);
        $this->assertStringContainsString('SMSTS or equivalent', $xml,
            'D4: §6.1 Team Requirements column missing the full PDF reqMap entry for Project Manager.');
        $this->assertStringContainsString('First Aid at Work', $xml,
            'D4: §6.1 Team Requirements column missing the full PDF reqMap entry for Project Manager.');
    }

    public function test_team_fallback_synthesises_from_project_personnel_when_team_empty(): void
    {
        // D4 — when generated_data['team'] is empty (the package-124 failure
        // mode), DOCX must synthesise the team from project_manager /
        // lead_engineer / additional_engineers / programmer strings on the
        // $project block (matches PDF rams.blade.php:1304-1324).
        $record = $this->makeRams(
            projectOverrides: [
                'project_manager'      => 'Sonny',
                'lead_engineer'        => 'Simon Pittaway',
                'additional_engineers' => 'Tom, Alex',
                'programmer'           => 'Dave',
            ],
            generatedOverrides: ['team' => []],
        );

        $xml = $this->renderDocumentXml($record);

        $this->assertStringContainsString('Project Manager', $xml);
        $this->assertStringContainsString('Lead Engineer', $xml);
        $this->assertStringContainsString('Programmer', $xml);
        // PM should still get its full reqMap entry from the synthesised team.
        $this->assertStringContainsString('SMSTS or equivalent', $xml);
        // The bare "Lead Engineer / 1 / CSCS Card, AV installation experience"
        // stub fallback must no longer fire.
        $this->assertStringNotContainsString(
            'CSCS Card, AV installation experience',
            $xml,
            'D4: stub fallback fired even though project personnel fields were populated.',
        );
    }

    public function test_cover_personnel_rows_render_project_manager_lead_engineer_etc(): void
    {
        // D4 expansion — cover gets a third table with PROJECT MANAGER /
        // LEAD ENGINEER / ENGINEERS / PROGRAMMER / VEHICLE REGS (matches
        // PDF rams.blade.php:586-611).
        $record = $this->makeRams(
            projectOverrides: [
                'doc_author'           => 'Sonny',
                'lead_engineer'        => 'Simon Pittaway',
                'additional_engineers' => 'Tom, Alex',
                'programmer'           => 'Dave',
                'site_vehicles'        => ['AB12 CDE', 'FG34 HIJ'],
            ],
        );

        $xml = $this->renderDocumentXml($record);

        $this->assertStringContainsString('PROJECT MANAGER', $xml);
        $this->assertStringContainsString('LEAD ENGINEER', $xml);
        $this->assertStringContainsString('ENGINEERS', $xml);
        $this->assertStringContainsString('PROGRAMMER', $xml);
        $this->assertStringContainsString('VEHICLE REGS', $xml);
        $this->assertStringContainsString('Simon Pittaway', $xml);
        $this->assertStringContainsString('Dave', $xml);
        $this->assertStringContainsString('AB12 CDE', $xml);
    }

    public function test_site_contact_falls_back_to_tbc_at_site_induction(): void
    {
        // D5 — Emergency Procedures Site Contact row must show "TBC at site
        // induction" when no client_contact or site_contact is configured
        // (matches PDF rams.blade.php:1835).
        $record = $this->makeRams(
            projectOverrides: [
                'site_contact'         => '',
                'client_contact_name'  => '',
                'client_contact_email' => '',
            ],
        );

        $xml = $this->renderDocumentXml($record);

        $this->assertStringContainsString('TBC at site induction', $xml,
            'D5: empty site_contact did not fall back to the "TBC at site induction" placeholder.');
    }

    public function test_site_contact_renders_client_contact_when_present(): void
    {
        // D5 — when client_contact_name is set, that becomes the Site Contact
        // value (concatenated with email if available, matching PDF behaviour).
        $record = $this->makeRams(
            projectOverrides: [
                'site_contact'         => '',
                'client_contact_name'  => 'Marius',
                'client_contact_email' => 'marius@lightforms.com',
            ],
        );

        $xml = $this->renderDocumentXml($record);

        $this->assertStringContainsString('Marius', $xml);
        $this->assertStringNotContainsString('TBC at site induction', $xml,
            'D5: TBC placeholder rendered even though client_contact_name is populated.');
    }

    // ── Commit 2 — Structural fixes (D6, D7, D8, D9) ────────────────────────

    public function test_risk_badge_returns_med_for_score_5_and_6(): void
    {
        // D7 — riskBadge() threshold must be `>= 5 => MED`, NOT the old
        // `>= 7 => MED` (which mis-labelled RA08/09/11 scores=6 as LOW).
        $builder = app(DocxBuilderService::class);
        $ref = new ReflectionClass($builder);
        $method = $ref->getMethod('riskBadge');
        $method->setAccessible(true);

        $this->assertSame('LOW',  $method->invoke($builder, 4), 'Score 4 must remain LOW.');
        $this->assertSame('MED',  $method->invoke($builder, 5), 'D7: score 5 must be MED, not LOW.');
        $this->assertSame('MED',  $method->invoke($builder, 6), 'D7: score 6 must be MED, not LOW.');
        $this->assertSame('MED',  $method->invoke($builder, 9), 'Score 9 must remain MED.');
        $this->assertSame('HIGH', $method->invoke($builder, 10), 'Score 10 must be HIGH.');
    }

    public function test_risk_colour_uses_three_band_palette(): void
    {
        // D6 — riskColour() must collapse to 3 bands matching the PDF
        // (green ≤4, amber 5-9, red 10+). The legacy 4-band palette with
        // RISK_ORANGE must no longer fire.
        $builder = app(DocxBuilderService::class);
        $ref = new ReflectionClass($builder);
        $method = $ref->getMethod('riskColour');
        $method->setAccessible(true);

        $green = 'D4EDDA';
        $amber = 'FFF3CD';
        $red   = 'FFDEDE';

        $this->assertSame($green, $method->invoke($builder, 4));
        $this->assertSame($amber, $method->invoke($builder, 5));
        $this->assertSame($amber, $method->invoke($builder, 9));
        $this->assertSame($red,   $method->invoke($builder, 10));
        $this->assertSame($red,   $method->invoke($builder, 25));
    }

    public function test_risk_legend_emits_five_by_five_grid_and_three_band_footer(): void
    {
        // D6 — buildRiskAssessment() must emit the 5×5 likelihood/severity
        // grid (header row "Severity 1..5" + row headers "Likelihood 1..5"
        // + 25 body cells) AND a 3-band footer ("LOW", "MEDIUM", "HIGH").
        $record = $this->makeRams(
            generatedOverrides: [
                'hazards' => [
                    ['hazard' => 'Test', 'pre_likelihood' => 2, 'pre_severity' => 3,
                     'post_likelihood' => 1, 'post_severity' => 2, 'persons_at_risk' => ['Engineers'],
                     'controls' => ['Control 1']],
                ],
            ],
        );

        $xml = $this->renderDocumentXml($record);

        // 5×5 grid axis headers
        $this->assertStringContainsString('Severity 1', $xml, 'D6: 5×5 grid missing "Severity 1" header.');
        $this->assertStringContainsString('Severity 5', $xml, 'D6: 5×5 grid missing "Severity 5" header.');
        $this->assertStringContainsString('Likelihood 1', $xml, 'D6: 5×5 grid missing "Likelihood 1" header.');
        $this->assertStringContainsString('Likelihood 5', $xml, 'D6: 5×5 grid missing "Likelihood 5" header.');
        $this->assertStringContainsString('Almost Certain', $xml, 'D6: 5×5 grid likelihood-label "Almost Certain" missing.');
        // 3-band footer
        $this->assertStringContainsString('LOW',    $xml);
        $this->assertStringContainsString('MEDIUM', $xml);
        $this->assertStringContainsString('HIGH',   $xml);
    }

    public function test_method_step_header_does_not_double_prefix_step_n(): void
    {
        // D8 — when the AI title is already "Step 1 — Foo", the step-strip
        // regex must remove that prefix BEFORE the renderer re-applies its
        // "Step N — " label, so the output is "Step 1 — Foo" — not the
        // duplicated "Step 1 — Step 1 — Foo".
        $record = $this->makeRams(
            generatedOverrides: [
                'method_statement' => [
                    'phases' => [
                        ['title' => 'Step 1 — Arrival & Site Induction', 'steps' => ['Sign in', 'Briefing']],
                        ['title' => 'Step 2 — Equipment Installation',  'steps' => ['Mount displays']],
                    ],
                ],
            ],
        );

        $xml = $this->renderDocumentXml($record);

        $this->assertStringContainsString('Arrival', $xml);
        $this->assertStringContainsString('Equipment Installation', $xml);
        // The double "Step 1 — Step 1" substring must not appear anywhere.
        $this->assertStringNotContainsString('Step 1 — Step 1', $xml,
            'D8: step header duplicated when AI title already contained a "Step N — " prefix.');
        $this->assertStringNotContainsString('Step 2 — Step 2', $xml,
            'D8: step header duplicated when AI title already contained a "Step N — " prefix.');
    }

    public function test_cdm_section_appears_after_method_statement_not_before_scope(): void
    {
        // D9 — buildCdmSection() must run AFTER buildMethodStatement(),
        // not between H&S Policy and Scope of Works.
        $record = $this->makeRams(
            generatedOverrides: [
                'cdm_duty_holders' => [
                    'client'               => 'Light Forms Ltd',
                    'principal_designer'   => '21st Century AV Ltd',
                    'principal_contractor' => '21st Century AV Ltd',
                ],
                'method_statement' => [
                    'phases' => [
                        ['title' => 'Install', 'steps' => ['Mount displays']],
                    ],
                ],
            ],
        );

        $xml = $this->renderDocumentXml($record);

        $cdmPos    = strpos($xml, 'CDM 2015');
        $methodPos = strpos($xml, 'METHOD STATEMENT');
        $scopePos  = strpos($xml, 'SCOPE OF WORKS');

        $this->assertNotFalse($cdmPos,    'CDM section missing entirely.');
        $this->assertNotFalse($methodPos, 'Method statement section missing.');
        $this->assertNotFalse($scopePos,  'Scope of Works section missing.');

        $this->assertGreaterThan($methodPos, $cdmPos,
            'D9: CDM section appears BEFORE Method Statement — must be after.');
        $this->assertGreaterThan($scopePos, $cdmPos,
            'D9: CDM section appears BEFORE Scope of Works — must be after Method Statement.');
    }

    // ── Commit 3 — Missing sections (D10, D11, D12) ─────────────────────────

    public function test_material_handling_section_renders_when_data_present(): void
    {
        $record = $this->makeRams(
            generatedOverrides: [
                'material_handling_derived' => [
                    'has_heavy_items' => true,
                    'items' => [
                        ['qty' => 1, 'item' => 'Sony 85" Display', 'handling_method' => 'Team lift, 4 persons.'],
                    ],
                    'statement' => 'Manual handling controls apply — team lift for items over 20 kg.',
                ],
            ],
        );

        $xml = $this->renderDocumentXml($record);

        $this->assertStringContainsString('Material Handling', $xml);
        $this->assertStringContainsString('Sony 85', $xml);
        $this->assertStringContainsString('Team lift', $xml);
    }

    public function test_permit_and_isolation_section_renders_when_data_present(): void
    {
        $record = $this->makeRams(
            generatedOverrides: [
                'permit_and_isolation' => [
                    'rules' => [
                        'No work in ceiling voids without a permit-to-work signed by the site manager.',
                        'All electrical isolations must be locked off and tagged.',
                    ],
                ],
            ],
        );

        $xml = $this->renderDocumentXml($record);

        $this->assertStringContainsString('Permit', $xml);
        $this->assertStringContainsString('Isolation', $xml);
        $this->assertStringContainsString('locked off', $xml);
    }

    public function test_fixings_control_section_renders_when_data_present(): void
    {
        $record = $this->makeRams(
            generatedOverrides: [
                'fixings_control' => [
                    'rules' => [
                        'All wall fixings to be torque-rated and pull-tested.',
                    ],
                ],
            ],
        );

        $xml = $this->renderDocumentXml($record);

        $this->assertStringContainsString('Fixings', $xml);
        $this->assertStringContainsString('torque-rated', $xml);
    }

    public function test_supervision_and_qa_section_renders_when_data_present(): void
    {
        $record = $this->makeRams(
            generatedOverrides: [
                'supervision_and_qa' => [
                    'responsibilities' => [
                        'Site supervisor present at all times during works.',
                    ],
                ],
            ],
        );

        $xml = $this->renderDocumentXml($record);

        $this->assertStringContainsString('Supervision', $xml);
        $this->assertStringContainsString('supervisor present', $xml);
    }

    public function test_permits_and_authorisations_section_renders(): void
    {
        $record = $this->makeRams();

        $xml = $this->renderDocumentXml($record);

        // Static prose section — always renders.
        $this->assertStringContainsString('Permits', $xml);
        $this->assertStringContainsString('Authorisations', $xml);
    }

    public function test_coshh_assessment_section_renders(): void
    {
        $record = $this->makeRams();

        $xml = $this->renderDocumentXml($record);

        $this->assertStringContainsString('COSHH', $xml);
        // Standard COSHH boilerplate substring.
        $this->assertStringContainsString('Control of Substances Hazardous', $xml);
    }

    public function test_environmental_management_section_renders(): void
    {
        $record = $this->makeRams();

        $xml = $this->renderDocumentXml($record);

        $this->assertStringContainsString('Environmental Management', $xml);
        // Both sub-sections present (Waste Disposal + Noise/Dust/Vibration).
        $this->assertStringContainsString('Waste Disposal', $xml);
        $this->assertStringContainsString('Noise', $xml);
    }

    public function test_welfare_arrangements_section_renders(): void
    {
        $record = $this->makeRams();

        $xml = $this->renderDocumentXml($record);

        $this->assertStringContainsString('Welfare', $xml);
        $this->assertStringContainsString('First Aid', $xml);
    }

    public function test_appendix_a_toolbox_talk_section_renders(): void
    {
        $record = $this->makeRams();

        $xml = $this->renderDocumentXml($record);

        $this->assertStringContainsString('Appendix A', $xml);
        $this->assertStringContainsString('Toolbox Talk', $xml);
    }
}
