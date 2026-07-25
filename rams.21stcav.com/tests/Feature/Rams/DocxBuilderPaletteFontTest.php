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
 * Quick task 260725-rd1 — RAMS DOCX palette + font shift.
 *
 * Guards the design-parity change from teal `#007B8A` → brand blue
 * `#2E74B5`, alt-row shading from light teal `#F0FBFC` → light blue
 * `#DEEBF7`, and body font Arial → Poppins.
 *
 * The reference for the shift is `21CQ29531-05-OPS Tilda RAMs Rev1.1.docx`
 * — see .planning/quick/260725-rd1-tier1-rams-design-and-content-parity/.
 */
class DocxBuilderPaletteFontTest extends TestCase
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

    /** Build a minimal RAMS fixture and return the rendered document.xml. */
    private function renderDocumentXml(): string
    {
        $user = User::factory()->create(['name' => 'Sonny Tanda']);
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'name'    => 'Palette Fixture',
        ]);

        $record = RamsDocument::factory()->create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'project_name'   => 'Palette Fixture',
            'project_ref'    => '21CQ00000-00-OPS',
            'client_name'    => 'Fixture Client Ltd',
            'site_address'   => '1 Test Street, London',
            'form_data'      => [],
            'generated_data' => [
                'project' => [
                    'name'         => 'Palette Fixture',
                    'ref'          => '21CQ00000-00-OPS',
                    'client'       => 'Fixture Client Ltd',
                    'site_address' => '1 Test Street, London',
                    'doc_author'   => 'Sonny',
                ],
                'team'    => [['role' => 'Project Manager', 'name' => 'Sonny']],
                'hazards' => [],
                'method_statement' => ['phases' => []],
            ],
            'reviewed_data' => null,
            'status'        => RamsDocument::STATUS_COMPLETED,
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

    public function test_palette_uses_brand_blue_not_legacy_teal(): void
    {
        $xml = $this->renderDocumentXml();

        // Legacy teal must be gone everywhere in the rendered document.
        $this->assertStringNotContainsStringIgnoringCase('007B8A', $xml,
            '260725-rd1: legacy teal 007B8A still present in the docx palette.');

        // Brand blue must appear at least once (in headings + accent borders).
        $this->assertStringContainsStringIgnoringCase('2E74B5', $xml,
            '260725-rd1: brand blue 2E74B5 not applied to headings/accents.');
    }

    public function test_alt_row_shading_uses_light_blue_not_light_teal(): void
    {
        $xml = $this->renderDocumentXml();

        // Old light-teal alt-row must be gone.
        $this->assertStringNotContainsStringIgnoringCase('F0FBFC', $xml,
            '260725-rd1: legacy light-teal alt-row F0FBFC still present in the docx.');

        // New light-blue alt-row must be present (baseline fixture uses several
        // alt-shaded tables: Company Information, Sign-Off, etc.).
        $this->assertStringContainsStringIgnoringCase('DEEBF7', $xml,
            '260725-rd1: light-blue alt-row DEEBF7 not applied to tables.');
    }

    public function test_body_font_is_poppins_not_arial(): void
    {
        $xml = $this->renderDocumentXml();

        // The default font is set on the PhpWord instance and propagates
        // to run properties; every rFonts w:ascii attribute in this document
        // should be "Poppins", not "Arial".
        $this->assertStringContainsString('Poppins', $xml,
            '260725-rd1: body font Poppins not applied to run properties.');
        $this->assertStringNotContainsString('w:ascii="Arial"', $xml,
            '260725-rd1: Arial font still present in run properties.');
    }
}
