<?php

namespace Tests\Feature\Documents;

use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\SiteSurveyRoom;
use App\Models\User;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpWord\Settings;
use Tests\TestCase;
use ZipArchive;

/**
 * D-46.2-06-05 — SiteSurveyDocxService must enable PhpWord output escaping.
 *
 * PhpWord's output escaping default is FALSE
 * (vendor/phpoffice/phpword/src/PhpWord/Settings.php:169), so `&`, `<` and `>`
 * in text content are written into word/document.xml verbatim. The three
 * sibling writers all switch it on at the top of their build method
 * (DocxBuilderService:128, DocxBuilderServiceV2:102, OmManualDocxService:63,
 * WorksheetDocxService:44); SiteSurveyDocxService did not.
 *
 * That path had zero callers until plan 46.2-02 wired `site-surveys.docx`, and
 * 46.2-05 put hand-typed free text on the cockpit form, so a request that
 * builds ONLY a site survey escapes nothing.
 *
 * ── Why the setOutputEscapingEnabled(false) line below is here ──────────────
 * `Settings::$outputEscapingEnabled` is a PROCESS-GLOBAL STATIC and PHPUnit
 * shares one process across the suite. If any earlier test in the run has
 * already built a RAMS / O&M / worksheet document, that sibling's
 * `setOutputEscapingEnabled(true)` is STILL IN EFFECT when this test starts —
 * and this test would pass VACUOUSLY, measuring the sibling's call rather than
 * SiteSurveyDocxService's. Forcing it to false reproduces a cold request in
 * which the site survey is the only document built. Do NOT delete that line to
 * "clean up"; it is the entire reason this test can detect the defect.
 */
class SiteSurveyDocxOutputEscapingTest extends TestCase
{
    use RefreshDatabase;

    /** Free text a PM can type straight into the cockpit form. */
    private const HOSTILE = 'Ampersand & angle <brackets> "quoted"';

    public function test_site_survey_docx_escapes_xml_significant_characters_in_free_text(): void
    {
        $xml = $this->buildAndReadDocumentXml();

        // Narrow windows so a failure quotes the offending bytes rather
        // than dumping the whole of word/document.xml.
        $notesWindow = $this->window($xml, 'Ampersand');
        $roomWindow  = $this->window($xml, 'more');

        // general_notes (buildCover). The raw form must be absent...
        $this->assertStringNotContainsString(
            'angle <brackets>',
            $notesWindow,
            'word/document.xml contains RAW unescaped "<brackets>" from general_notes. '
            .'SiteSurveyDocxService must call Settings::setOutputEscapingEnabled(true) like its siblings.'
        );

        // ...and the escaped form present.
        $this->assertStringContainsString(
            'Ampersand &amp; angle &lt;brackets&gt;',
            $notesWindow,
            'general_notes must reach word/document.xml with &, < and > escaped.'
        );

        // A second, independently-typed field on a DIFFERENT code path
        // (buildRoomBlock, not buildCover) so this proves the writer as a
        // whole rather than one call site.
        $this->assertStringNotContainsString(
            'Room <notes>',
            $roomWindow,
            'word/document.xml contains RAW unescaped room access_notes.'
        );
        $this->assertStringContainsString(
            'Room &lt;notes&gt; &amp; more',
            $roomWindow,
            'Room-level free text must also be escaped.'
        );
    }

    /**
     * What UNESCAPED output actually does, measured rather than assumed.
     *
     * Observed on the red run: PhpWord does NOT throw and the .docx is a
     * perfectly readable ZIP — it just writes `<w:t xml:space="preserve">Ampersand
     * & angle <brackets> "quoted"</w:t>`, i.e. a bare `&` and a stray element,
     * producing word/document.xml that is not well-formed XML at all. The
     * failure is silent at generation time and only surfaces when Word opens
     * the file. This asserts well-formedness so that stays true.
     */
    public function test_site_survey_docx_document_xml_is_well_formed(): void
    {
        $xml = $this->buildAndReadDocumentXml();

        $prior = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $doc    = new DOMDocument();
        $parsed = $doc->loadXML($xml);
        $errors = array_map(
            static fn ($e) => trim($e->message).' (line '.$e->line.')',
            libxml_get_errors()
        );

        libxml_clear_errors();
        libxml_use_internal_errors($prior);

        $this->assertTrue(
            $parsed && $errors === [],
            "word/document.xml is not well-formed XML — Word will refuse to open it:\n  ".implode("\n  ", $errors)
        );
    }

    // -- Helpers --

    /**
     * Build the .docx through the real route, return word/document.xml, and
     * leave storage/app/site-surveys exactly as it was found.
     */
    private function buildAndReadDocumentXml(): string
    {
        // Vacuity defence — see the class docblock. Reproduce a cold request in
        // which no sibling writer has flipped the process-global static.
        Settings::setOutputEscapingEnabled(false);

        [$user, $survey] = $this->makeSurvey();

        $storageDir = storage_path('app/site-surveys');
        $before     = $this->docxCount($storageDir);

        $this->actingAs($user)->get(route('site-surveys.docx', $survey))->assertOk();

        $filename = $survey->fresh()->filename;
        $this->assertNotNull($filename, 'build() must record the generated filename on the survey.');

        $path = $storageDir.'/'.$filename;

        try {
            $this->assertFileExists($path);

            return $this->documentXml($path);
        } finally {
            // SiteSurveyDocxService writes to storage_path() directly, bypassing
            // DocumentArtifactStorage, so Storage::fake() does not contain it.
            if (is_file($path)) {
                unlink($path);
            }

            $this->assertSame(
                $before,
                $this->docxCount($storageDir),
                'This test must leave storage/app/site-surveys with exactly the file count it started with.'
            );
        }
    }

    private function documentXml(string $path): string
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true, "Generated .docx is not a readable ZIP: {$path}");

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        $this->assertIsString($xml, 'Generated .docx has no word/document.xml.');

        return $xml;
    }

    /** A readable slice of word/document.xml around the first hit for $needle. */
    private function window(string $xml, string $needle): string
    {
        $at = strpos($xml, $needle);
        $this->assertNotFalse($at, "word/document.xml never contains '{$needle}' at all — the field was not rendered.");

        return substr($xml, max(0, $at - 40), 180);
    }

    private function docxCount(string $dir): int
    {
        return is_dir($dir) ? count(glob($dir.'/*.docx') ?: []) : 0;
    }

    /** @return array{0: User, 1: SiteSurvey} */
    private function makeSurvey(): array
    {
        $user = User::factory()->create();

        $project = Project::create([
            'user_id'      => $user->id,
            'name'         => 'Escaping Project',
            'ref'          => 'ESC-'.fake()->numerify('###'),
            'client_name'  => 'Acme Ltd',
            'site_address' => '1 Example Way, London',
            'status'       => 'quote_imported',
        ]);

        $survey = SiteSurvey::create([
            'user_id'       => $user->id,
            'project_id'    => $project->id,
            'project_name'  => 'Escaping Survey',
            'project_ref'   => 'Q-462606',
            'client_name'   => 'Acme Ltd',
            'site_address'  => '1 Example Way, London',
            'status'        => 'draft',
            'general_notes' => self::HOSTILE,
        ]);

        SiteSurveyRoom::create([
            'site_survey_id' => $survey->id,
            'room_name'      => 'Boardroom',
            'sort_order'     => 0,
            'access_notes'   => 'Room <notes> & more',
        ]);

        return [$user, $survey->refresh()];
    }
}
