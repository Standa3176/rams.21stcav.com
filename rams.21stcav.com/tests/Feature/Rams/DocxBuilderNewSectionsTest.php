<?php

namespace Tests\Feature\Rams;

use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\User;
use App\Services\DocxBuilderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Quick task 260725-rd1 — RAMS DOCX structural parity with reference.
 *
 * Guards the 4 structural additions from the hand-crafted
 * "21CQ29531-05-OPS Tilda RAMs Rev1.1.docx" reference:
 *
 *   1. §6.11 Coordination with Other Trades — always renders
 *   2. §6.12 IT / Network Integration Safety — always renders
 *   3. Explicit "Exclusions" block under Scope of Works
 *   4. "Standards & Guidance Applicable to This Works" table under H&S Policy
 */
class DocxBuilderNewSectionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        Carbon::setTestNow(Carbon::parse('2026-07-25 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function renderDocumentXml(array $generatedOverrides = [], ?array $reviewedOverrides = null): string
    {
        $user = User::factory()->create(['name' => 'Sonny Tanda']);
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'name'    => 'Sections Fixture',
        ]);

        $generated = array_merge([
            'project' => [
                'name'         => 'Sections Fixture',
                'ref'          => '21CQ00000-00-OPS',
                'client'       => 'Fixture Client Ltd',
                'site_address' => '1 Test Street, London',
                'doc_author'   => 'Sonny',
            ],
            'team'    => [['role' => 'Project Manager', 'name' => 'Sonny']],
            'hazards' => [],
            'method_statement' => ['phases' => []],
        ], $generatedOverrides);

        $record = RamsDocument::factory()->create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'project_name'   => 'Sections Fixture',
            'project_ref'    => '21CQ00000-00-OPS',
            'client_name'    => 'Fixture Client Ltd',
            'site_address'   => '1 Test Street, London',
            'form_data'      => [],
            'generated_data' => $generated,
            'reviewed_data'  => $reviewedOverrides,
            'status'         => RamsDocument::STATUS_COMPLETED,
        ]);

        $builder = app(DocxBuilderService::class);
        $path = $builder->build($record->generated_data ?? [], $record->fresh());

        $this->assertFileExists($path);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'Failed to open generated DOCX.');
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        $this->assertIsString($xml);

        return $xml;
    }

    // ── §6.11 Coordination with Other Trades ─────────────────────────────────

    public function test_coordination_with_other_trades_section_renders(): void
    {
        $xml = $this->renderDocumentXml();

        // sectionHeading() uppercases; the "6.11" prefix is preserved.
        $this->assertStringContainsString('6.11 COORDINATION WITH OTHER TRADES', $xml,
            '260725-rd1: §6.11 heading missing.');
        // Ceiling grid and partition-wall touch-points must appear.
        $this->assertStringContainsString('Ceiling grid', $xml);
        $this->assertStringContainsString('Partition walls', $xml);
        $this->assertStringContainsString('Principal Contractor', $xml);
    }

    // ── §6.12 IT / Network Integration Safety ────────────────────────────────

    public function test_it_network_integration_safety_section_renders(): void
    {
        $xml = $this->renderDocumentXml();

        // "&" in the heading is XML-escaped as "&amp;"; but this heading has no
        // ampersand. sectionHeading() uppercases.
        $this->assertStringContainsString('6.12 IT / NETWORK INTEGRATION SAFETY', $xml,
            '260725-rd1: §6.12 heading missing.');
        // Key IT-integration principles must appear.
        $this->assertStringContainsString('VLAN', $xml);
        $this->assertStringContainsString('PoE', $xml);
        $this->assertStringContainsString('firewall rules', $xml);
    }

    // ── Explicit Exclusions block ────────────────────────────────────────────

    public function test_exclusions_block_renders_with_reviewed_data_exclusions(): void
    {
        $xml = $this->renderDocumentXml(
            reviewedOverrides: [
                'exclusions' => [
                    'No structural works',
                    'No decommissioning of Saffron room in this phase',
                ],
            ],
        );

        $this->assertStringContainsString('Exclusions', $xml,
            '260725-rd1: Exclusions block heading missing.');
        $this->assertStringContainsString('No structural works', $xml);
        $this->assertStringContainsString('Saffron', $xml);
        $this->assertStringNotContainsString('No exclusions declared for this project.', $xml,
            '260725-rd1: fallback text rendered even though exclusions were supplied.');
    }

    public function test_exclusions_block_fallback_text_when_empty(): void
    {
        $xml = $this->renderDocumentXml(
            reviewedOverrides: ['exclusions' => []],
        );

        $this->assertStringContainsString('Exclusions', $xml);
        $this->assertStringContainsString('No exclusions declared for this project.', $xml,
            '260725-rd1: fallback text missing when exclusions[] is empty.');
    }

    // ── Standards & Guidance table ───────────────────────────────────────────

    public function test_standards_and_guidance_table_renders(): void
    {
        $xml = $this->renderDocumentXml(
            generatedOverrides: [
                'standards_references' => [
                    [
                        'ref'        => 'BS 7671:2018+A2:2022',
                        'title'      => 'Requirements for Electrical Installations',
                        'applies_to' => 'Every fixed-cable termination on this project.',
                    ],
                    [
                        'ref'        => 'CDM 2015',
                        'title'      => 'Construction (Design and Management) Regulations 2015',
                        'applies_to' => 'Duty holder roles for this project.',
                    ],
                ],
            ],
        );

        // "&" in "Standards & Guidance" is XML-escaped to "&amp;".
        $this->assertStringContainsString('Standards &amp; Guidance Applicable to This Works', $xml,
            '260725-rd1: Standards & Guidance sub-heading missing.');
        $this->assertStringContainsString('BS 7671', $xml,
            '260725-rd1: standards table missing BS 7671 row.');
        $this->assertStringContainsString('CDM 2015', $xml,
            '260725-rd1: standards table missing CDM 2015 row.');
        $this->assertStringContainsString('Reference', $xml,
            '260725-rd1: standards table missing "Reference" column header.');
    }

    public function test_standards_table_tolerates_alternative_key_shapes(): void
    {
        // The plan flagged that if config shape were `reference`/`name`/`scope`
        // instead of `ref`/`title`/`applies_to`, the builder should adapt.
        // Actual config uses `ref`/`title`/`applies_to`, but the builder
        // gracefully falls through to the alt keys — regression guard.
        $xml = $this->renderDocumentXml(
            generatedOverrides: [
                'standards_references' => [
                    [
                        'reference' => 'ALT-1',
                        'name'      => 'Alternative Shape Title',
                        'scope'     => 'Any project that uses alt keys.',
                    ],
                ],
            ],
        );

        $this->assertStringContainsString('ALT-1', $xml);
        $this->assertStringContainsString('Alternative Shape Title', $xml);
    }
}
