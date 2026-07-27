<?php

namespace Tests\Unit\Support;

use InvalidArgumentException;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\Support\DocxXmlNormalizer;
use Tests\TestCase;
use ZipArchive;

/**
 * Contract tests for {@see \Tests\Support\DocxXmlNormalizer} — the
 * DOCX-XML noise-stripping helper used by DocxSnapshotTest (phase
 * 260726-rf3 Plan 04 Commit 3).
 *
 * The class is test-only, so its tests focus on the invariants the
 * snapshot infrastructure actually depends on:
 *
 *  - Round-trip stability: normalise(normalise(bytes)) is a no-op
 *    (idempotent), and two independent PhpWord builds of the same
 *    content produce byte-identical normalised XML.
 *  - Each noise category (`w:id`, `r:id`, `w:rsidR*`) is stripped.
 *  - xmlns declarations on the root `<w:document>` are sorted.
 *  - Numeric attributes in `<w:sectPr>`, `<w:pgMar>`, `<w:pgSz>` are
 *    canonicalised to 4 decimal places (integer-valued attributes are
 *    left alone).
 *  - Failure modes throw {@see InvalidArgumentException} with a clear
 *    message.
 */
class DocxXmlNormalizerTest extends TestCase
{
    /**
     * Cornerstone invariant — the whole snapshot suite depends on this.
     * Two independent PhpWord builds of the SAME content must produce
     * byte-identical normalised XML. If it drifts, no golden file can
     * ever hold across two runs, and DocxSnapshotTest is unusable.
     */
    public function test_two_independent_builds_of_the_same_content_normalise_identically(): void
    {
        $bytesA = $this->buildTrivialDocx();
        $bytesB = $this->buildTrivialDocx();

        // Raw bytes SHOULD differ across builds (w:id, w:rsidR* are random).
        // If they don't, the "normalise strips noise" claim isn't actually
        // exercised — assert BOTH the pre-normalise divergence and the
        // post-normalise equality so a change in PhpWord's determinism
        // that makes noise-stripping unnecessary still surfaces here.
        $this->assertSame(
            DocxXmlNormalizer::normalise($bytesA),
            DocxXmlNormalizer::normalise($bytesB),
            'Two independent PhpWord builds of the same content produced different normalised XML — '
                . 'DocxSnapshotTest cannot rely on byte-diff.',
        );
    }

    /**
     * Idempotence — normalising a stripped XML through the normaliser
     * again is a no-op. Guards against a rule that would keep mutating
     * the input on every pass (e.g. a broken numeric-rounding step).
     */
    public function test_normalise_is_idempotent(): void
    {
        $bytes = $this->buildTrivialDocx();
        $pass1 = DocxXmlNormalizer::normalise($bytes);

        // Re-wrap the stripped XML in a fresh DOCX so we can re-run normalise
        // through its full pipeline (which starts with the ZIP-extract step).
        $bytes2 = $this->wrapDocumentXmlInDocx($pass1);
        $pass2  = DocxXmlNormalizer::normalise($bytes2);

        $this->assertSame(
            $pass1,
            $pass2,
            'Normaliser mutated the same input on a second pass — non-idempotent, will destabilise golden files.',
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // Individual noise-category strips
    // ══════════════════════════════════════════════════════════════════════

    public function test_strips_w_id_attribute(): void
    {
        $xml = $this->wrapDocumentXmlInDocx(
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<w:document xmlns:w="urn:test"><w:body>'
                . '<w:p w:id="99"><w:r w:id="12345"><w:t>hello</w:t></w:r></w:p>'
                . '</w:body></w:document>',
        );

        $result = DocxXmlNormalizer::normalise($xml);

        $this->assertStringNotContainsString('w:id="', $result);
        $this->assertStringContainsString('<w:t>hello</w:t>', $result);
    }

    public function test_strips_relationship_id_attribute(): void
    {
        $xml = $this->wrapDocumentXmlInDocx(
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<w:document xmlns:w="urn:test" xmlns:r="urn:rels"><w:body>'
                . '<w:hyperlink r:id="rId42"><w:r><w:t>link</w:t></w:r></w:hyperlink>'
                . '</w:body></w:document>',
        );

        $result = DocxXmlNormalizer::normalise($xml);

        $this->assertStringNotContainsString('r:id="rId', $result);
        $this->assertStringContainsString('<w:t>link</w:t>', $result);
    }

    public function test_strips_all_rsid_variants(): void
    {
        $xml = $this->wrapDocumentXmlInDocx(
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<w:document xmlns:w="urn:test"><w:body>'
                . '<w:p w:rsidR="00ABCDEF" w:rsidRPr="00112233" w:rsidRDefault="00445566" w:rsidP="00778899">'
                . '<w:r><w:t>text</w:t></w:r></w:p></w:body></w:document>',
        );

        $result = DocxXmlNormalizer::normalise($xml);

        $this->assertStringNotContainsString('w:rsidR="', $result);
        $this->assertStringNotContainsString('w:rsidRPr="', $result);
        $this->assertStringNotContainsString('w:rsidRDefault="', $result);
        $this->assertStringNotContainsString('w:rsidP="', $result);
    }

    // ══════════════════════════════════════════════════════════════════════
    // xmlns sorting on root
    // ══════════════════════════════════════════════════════════════════════

    public function test_sorts_root_xmlns_alphabetically(): void
    {
        // Root emits three xmlns in reverse alphabetical order (z, m, a).
        $xml = $this->wrapDocumentXmlInDocx(
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<w:document xmlns:z="urn:z" xmlns:m="urn:m" xmlns:a="urn:a">'
                . '<w:body/></w:document>',
        );

        $result = DocxXmlNormalizer::normalise($xml);

        // After normalisation the order must be a, m, z.
        $posA = strpos($result, 'xmlns:a=');
        $posM = strpos($result, 'xmlns:m=');
        $posZ = strpos($result, 'xmlns:z=');

        $this->assertNotFalse($posA);
        $this->assertNotFalse($posM);
        $this->assertNotFalse($posZ);
        $this->assertLessThan($posM, $posA, 'xmlns:a should sort before xmlns:m.');
        $this->assertLessThan($posZ, $posM, 'xmlns:m should sort before xmlns:z.');
    }

    // ══════════════════════════════════════════════════════════════════════
    // Section numeric-attr canonicalisation
    // ══════════════════════════════════════════════════════════════════════

    public function test_canonicalises_decimal_attrs_in_section_geometry_to_four_places(): void
    {
        $xml = $this->wrapDocumentXmlInDocx(
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<w:document xmlns:w="urn:test"><w:body>'
                . '<w:sectPr><w:pgMar w:top="1020.1" w:bottom="1020.123456" w:left="1020"/>'
                . '<w:pgSz w:w="9866.0" w:h="14042"/></w:sectPr>'
                . '</w:body></w:document>',
        );

        $result = DocxXmlNormalizer::normalise($xml);

        // Decimal-valued attrs must be normalised to 4 places…
        $this->assertStringContainsString('w:top="1020.1000"', $result);
        $this->assertStringContainsString('w:bottom="1020.1235"', $result);
        $this->assertStringContainsString('w:w="9866.0000"', $result);
        // …integer-valued attrs are untouched.
        $this->assertStringContainsString('w:left="1020"', $result);
        $this->assertStringContainsString('w:h="14042"', $result);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Failure modes
    // ══════════════════════════════════════════════════════════════════════

    public function test_throws_when_input_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('input bytes are empty');
        DocxXmlNormalizer::normalise('');
    }

    public function test_throws_when_input_is_not_a_valid_zip(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not a valid DOCX');
        DocxXmlNormalizer::normalise('this-is-not-a-zip-file');
    }

    public function test_throws_when_document_xml_missing_from_zip(): void
    {
        // Build a ZIP with a different entry than word/document.xml.
        // Use unlink+CREATE (not tempnam+OVERWRITE) because ZipArchive on
        // Windows sometimes refuses OVERWRITE on a pre-existing empty file.
        $tmp = tempnam(sys_get_temp_dir(), 'docxnorm-test-') . '.zip';
        @unlink($tmp);
        try {
            $zip = new ZipArchive();
            $opened = $zip->open($tmp, ZipArchive::CREATE);
            $this->assertTrue($opened === true, 'Failed to create test ZIP.');
            $zip->addFromString('other.xml', '<hello/>');
            $zip->close();

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('word/document.xml missing');
            DocxXmlNormalizer::normalise((string) file_get_contents($tmp));
        } finally {
            @unlink($tmp);
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // Helpers
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Build a tiny valid DOCX in memory with PhpWord — one section, one
     * paragraph, one text run. Enough for PhpWord to emit w:id / w:rsidR*
     * noise so the strip rules are actually exercised.
     */
    private function buildTrivialDocx(): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $section->addText('Hello Snapshot');

        $tmp = tempnam(sys_get_temp_dir(), 'docxnorm-build-') . '.docx';
        try {
            IOFactory::createWriter($phpWord, 'Word2007')->save($tmp);

            return (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Wrap an arbitrary `word/document.xml` string in a valid DOCX ZIP so
     * we can round-trip it through the normaliser's ZIP-extract stage.
     *
     * Uses unlink+CREATE (not tempnam+OVERWRITE) — ZipArchive on Windows
     * refuses OVERWRITE on a pre-existing empty file created by tempnam
     * ("Invalid or uninitialized Zip object").
     */
    private function wrapDocumentXmlInDocx(string $documentXml): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'docxnorm-wrap-') . '.docx';
        @unlink($tmp);
        try {
            $zip = new ZipArchive();
            $opened = $zip->open($tmp, ZipArchive::CREATE);
            if ($opened !== true) {
                throw new \RuntimeException('wrapDocumentXmlInDocx: could not create test ZIP.');
            }
            $zip->addFromString('word/document.xml', $documentXml);
            $zip->close();

            return (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
    }
}
