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
 * Originally guarded the design-parity change from teal `#007B8A` → brand
 * blue `#2E74B5` (reference: `21CQ29531-05-OPS Tilda RAMs Rev1.1.docx`, see
 * .planning/quick/260725-rd1-tier1-rams-design-and-content-parity/). That
 * shift to Word's stock "Blue, Accent 1" defaults was itself the defect
 * found by 29-UAT.md Gap 4/6 (2026-09-12): the DOCX no longer matched the
 * PDF's actual 21CAV brand palette. Plan 29-13 corrects the palette to
 * `#1B7A7A` teal / `#F4FBFB` pale-teal tint, so this test now guards those
 * corrected brand values instead. Body font (Arial → Poppins) is unrelated
 * to the colour defect and remains unchanged.
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

    public function test_palette_uses_brand_teal_not_word_default_blue(): void
    {
        $xml = $this->renderDocumentXml();

        // Word's stock "Blue, Accent 1" default must be gone everywhere in
        // the rendered document (29-UAT.md gap 4/6 — corrected 2026-09-12).
        $this->assertStringNotContainsStringIgnoringCase('2E74B5', $xml,
            '29-13: Word default blue 2E74B5 still present in the docx palette.');

        // 21CAV brand teal must appear at least once (in headings + accent borders).
        $this->assertStringContainsStringIgnoringCase('1B7A7A', $xml,
            '29-13: brand teal 1B7A7A not applied to headings/accents.');
    }

    public function test_alt_row_shading_uses_brand_pale_teal_not_word_default_blue(): void
    {
        $xml = $this->renderDocumentXml();

        // Word's stock light-blue alt-row must be gone (29-UAT.md gap 4/6).
        $this->assertStringNotContainsStringIgnoringCase('DEEBF7', $xml,
            '29-13: Word default light-blue alt-row DEEBF7 still present in the docx.');

        // 21CAV brand pale-teal tint must be present (baseline fixture uses
        // several alt-shaded tables: Company Information, Sign-Off, etc.).
        $this->assertStringContainsStringIgnoringCase('F4FBFB', $xml,
            '29-13: brand pale-teal tint F4FBFB not applied to tables.');
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
