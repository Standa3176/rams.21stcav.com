<?php

namespace Tests\Unit\Services;

use App\Services\PdfOcrExtractorService;
use App\Services\PdfTextExtractorService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Smalot\PdfParser\Parser;

/**
 * Pure-unit tests for the short-tag detector and `-layout` re-extract helpers
 * added to {@see PdfTextExtractorService} in quick task 260604-p9u.
 *
 * Three concentric scopes:
 *
 *   1. Tests 1.x  → looksLikeShortTagQuoteWerks() — variant detector (this file).
 *   2. Tests 2.x  → reserved for fixture-driven detector verification.
 *   3. Tests 3.x  → extract()-level routing (lives in the sibling integration
 *                   test class which extends Laravel's TestCase for Log facade
 *                   support — see PdfTextExtractorRoutingIntegrationTest.php).
 *
 * No Laravel bootstrap — the detector is a pure function over a string.
 * Mirrors the QuoteParserShortTagVariantTest pattern.
 */
class PdfTextExtractorShortTagRoutingTest extends TestCase
{
    private PdfTextExtractorService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // The detector reads zero instance state; deps are satisfied with
        // a real Parser + a stand-in OCR service (never invoked here).
        $ocr = $this->createMock(PdfOcrExtractorService::class);
        $this->service = new PdfTextExtractorService(new Parser(), $ocr);
    }

    /** Invoke a private method via reflection. */
    private function callPrivate(string $method, array $args = []): mixed
    {
        $rm = new ReflectionMethod(PdfTextExtractorService::class, $method);
        $rm->setAccessible(true);

        return $rm->invokeArgs($this->service, $args);
    }

    private function detect(string $text): bool
    {
        return (bool) $this->callPrivate('looksLikeShortTagQuoteWerks', [$text]);
    }

    // =========================================================================
    // TASK 1 — looksLikeShortTagQuoteWerks() detector
    // =========================================================================

    public function test_1_1_empty_text_is_not_short_tag(): void
    {
        $this->assertFalse($this->detect(''));
    }

    public function test_1_2_three_short_tag_matches_classify_as_short(): void
    {
        // Three H-family matches — above the >=3 threshold.
        $this->assertTrue($this->detect('H1 X H1E H1 Y H1E H4S Z H4E'));
    }

    public function test_1_3_long_tag_site_name_marker_wins(): void
    {
        // Long-tag PDF that incidentally contains short-tag-looking tokens.
        // MUST stay on the long-tag path so the working pipeline is never
        // re-routed. Mirrors QuoteParserService::detectTagVariant Rule 1.
        $this->assertFalse(
            $this->detect('SITENAMESTART Acme SITENAMEEND H1E H1E H1E H4S')
        );
    }

    public function test_1_4_long_tag_partstart_marker_wins(): void
    {
        $this->assertFalse(
            $this->detect('PARTSTART foo PARTEND H1E H1E H1E')
        );
    }

    public function test_1_5_single_short_tag_below_threshold(): void
    {
        // Single accidental short-tag-looking token in free prose — below the
        // >=3 detector threshold (intentionally HIGHER than the QuoteParser's
        // >=2 threshold so the extractor re-extract is biased conservative).
        $this->assertFalse(
            $this->detect('Some prose with H1E mentioned once and nothing else.')
        );
    }

    public function test_1_6_priced_cicor_fixture_classified_as_short(): void
    {
        // Against the Task-2 fixture (real pdftotext -layout output for the
        // Cicor 21CQ30167 PDF). MUST exceed the >=3 threshold many times over.
        $fixture = (string) file_get_contents(
            __DIR__ . '/../../Fixtures/quotewerks/priced-cicor-21CQ30167.txt'
        );

        $this->assertTrue($this->detect($fixture));
    }

    public function test_1_7_unpriced_long_tag_baseline_classified_as_long(): void
    {
        $baseline = (string) file_get_contents(
            __DIR__ . '/../../Fixtures/quotewerks/unpriced-snapshot-baseline.txt'
        );

        $this->assertFalse($this->detect($baseline));
    }
}
