<?php

namespace Tests\Unit\Rams;

use App\Services\QuoteParserService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for the short-tag (priced "ram" template) QuoteWerks variant.
 *
 * Three concentric test layers:
 *   - Tests 1.x  → detectTagVariant() — variant classifier.
 *   - Tests 2.x  → translateShortTagsToLong() — preprocessing translator.
 *   - Tests 3.x  → end-to-end parse() against the priced Cicor fixture.
 *
 * No Laravel bootstrapping required — QuoteParserService is pure PHP with
 * no dependencies on the container, database, or HTTP. Mirrors the existing
 * QuoteParserServiceTest convention (extends PHPUnit\Framework\TestCase
 * directly).
 */
class QuoteParserShortTagVariantTest extends TestCase
{
    private QuoteParserService $parser;

    /** Cached parse() result for the priced fixture to avoid re-parsing per test. */
    private ?array $cachedPricedResult = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new QuoteParserService();
    }

    // =========================================================================
    // FIXTURE HELPERS
    // =========================================================================

    private function priced(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../Fixtures/quotewerks/priced-cicor-21CQ30167.txt');
    }

    private function unpriced(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../Fixtures/quotewerks/unpriced-snapshot-baseline.txt');
    }

    private function pricedResult(): array
    {
        return $this->cachedPricedResult ??= $this->parser->parse($this->priced());
    }

    /** Invoke a private parser method via reflection (no behaviour change to the SUT). */
    private function callPrivate(string $method, array $args = []): mixed
    {
        $rm = new ReflectionMethod(QuoteParserService::class, $method);
        $rm->setAccessible(true);

        return $rm->invokeArgs($this->parser, $args);
    }

    // =========================================================================
    // TASK 1 — detectTagVariant()
    // =========================================================================

    public function test_1_1_priced_cicor_fixture_classified_as_short(): void
    {
        $this->assertSame('short', $this->callPrivate('detectTagVariant', [$this->priced()]));
    }

    public function test_1_2_unpriced_baseline_fixture_classified_as_long(): void
    {
        $this->assertSame('long', $this->callPrivate('detectTagVariant', [$this->unpriced()]));
    }

    public function test_1_3_empty_string_defaults_to_long(): void
    {
        $this->assertSame('long', $this->callPrivate('detectTagVariant', ['']));
    }

    public function test_1_4_mixed_input_long_wins(): void
    {
        // Long-tag PDF that happens to also contain short-tag-looking tokens
        // (e.g. a part-number "H1E" in a description). MUST stay on the
        // long-tag path so the working pipeline is never disturbed.
        $mixed = "SITENAMESTART Acme SITENAMEEND ... H1 Y H1E ... PARTSTART foo PARTEND";

        $this->assertSame('long', $this->callPrivate('detectTagVariant', [$mixed]));
    }

    public function test_1_5_single_short_tag_noise_does_not_flip_variant(): void
    {
        // Single accidental short-tag-looking word with no PARTSTART /
        // SITENAMESTART context. Below the >=2 threshold → defaults to long.
        $noise = "Some prose mentioning H1E as a part identifier and nothing else of note.";

        $this->assertSame('long', $this->callPrivate('detectTagVariant', [$noise]));
    }

    // =========================================================================
    // TASK 2 — translateShortTagsToLong()
    // =========================================================================

    private function translate(string $input): string
    {
        return (string) $this->callPrivate('translateShortTagsToLong', [$input]);
    }

    public function test_2_1_direct_h_substitution(): void
    {
        $out = $this->translate("H1 Acme Ltd H1E");

        $this->assertStringContainsString('SITENAMESTART Acme Ltd SITENAMEEND', $out);
        $this->assertStringNotContainsString(' H1 ', " {$out} ");
        $this->assertStringNotContainsString(' H1E ', " {$out} ");
    }

    public function test_2_2_d_pair_section_routing_title_then_text(): void
    {
        // First D-pair = title. Second D-pair = text. Routing resets at P1S.
        $input = "D1S First Floor Training Room D1E\n"
            . "D1S D1E Function room overview text.\n"
            . "P1S FOO P1E";

        $out = $this->translate($input);

        $this->assertStringContainsString('OVERVIEWTITLESTART First Floor Training Room OVERVIEWTITLEEND', $out);
        $this->assertStringContainsString('OVERVIEWTXTSTART', $out);
        $this->assertStringContainsString('Function room overview text.', $out);
        $this->assertStringContainsString('OVERVIEWTXTEND', $out);
        $this->assertStringContainsString('PARTSTART FOO PARTEND', $out);
        // Body of OVERVIEWTXT must contain the prose, not D1S/D1E.
        $this->assertStringNotContainsString(' D1S ', " {$out} ");
        $this->assertStringNotContainsString(' D1E ', " {$out} ");
    }

    public function test_2_3_h_column_split_repair(): void
    {
        $input = "H1 Cicor Hartlepool Ltd - Training Room H H4S Jamie Powis H4E 1E";

        $out = $this->translate($input);

        $this->assertStringContainsString('SITENAMESTART Cicor Hartlepool Ltd - Training Room SITENAMEEND', $out);
        $this->assertStringContainsString('SHIPCONTSTART Jamie Powis SHIPCONTEND', $out);
        // No orphan H/1E fragments leaking into the SITENAMESTART content.
        $this->assertDoesNotMatchRegularExpression('/SITENAMESTART[^\n]*\b(H|1E|H1E)\b/', $out);
    }

    public function test_2_4_d_column_split_first_letter_prefix(): void
    {
        // "F" prefix + "irst Floor Training Room" continuation. Pass A glues
        // them, then Pass D routes as a first-pair title (single D-pair in
        // this synthetic input — gets OVERVIEWTITLE treatment).
        $input = "D1S F D1E irst Floor Training Room\nP1S FOO P1E";

        $out = $this->translate($input);

        $this->assertStringContainsString('OVERVIEWTITLESTART First Floor Training Room OVERVIEWTITLEEND', $out);
    }

    public function test_2_5_d_column_split_multi_letter_prefix(): void
    {
        $input = "D1S Suppo D1E rt Services\nP1S FOO P1E";

        $out = $this->translate($input);

        $this->assertStringContainsString('OVERVIEWTITLESTART Support Services OVERVIEWTITLEEND', $out);
    }

    public function test_2_6_p4s_price_span_stripped(): void
    {
        $input = "before P4S £1,234.56 P4E after";

        $out = $this->translate($input);

        $this->assertStringNotContainsString('P4S',     $out);
        $this->assertStringNotContainsString('P4E',     $out);
        $this->assertStringNotContainsString('£1,234.56', $out);
        $this->assertStringContainsString('before',  $out);
        $this->assertStringContainsString('after',   $out);
    }

    public function test_2_7_p5s_manufacturer_span_stripped(): void
    {
        $input = "before P5S Yealink P5S after";

        $out = $this->translate($input);

        $this->assertStringNotContainsString('P5S',     $out);
        $this->assertStringNotContainsString('Yealink', $out);
        $this->assertStringContainsString('before', $out);
        $this->assertStringContainsString('after',  $out);
    }

    public function test_2_8_idempotency_on_long_tag_text(): void
    {
        $long = $this->unpriced();

        $this->assertSame($long, $this->translate($long));
    }

    public function test_2_9_end_to_end_translator_on_cicor_fixture(): void
    {
        $out = $this->translate($this->priced());

        // Long-tag markers expected after translation.
        $this->assertStringContainsString('SITENAMESTART',  $out);
        $this->assertStringContainsString('SHIPCONTSTART',  $out);
        $this->assertStringContainsString('SHIPPHONESTART', $out);
        $this->assertStringContainsString('SHIPEMAILSTART', $out);
        $this->assertStringContainsString('SHIPCOMPSTART',  $out);
        $this->assertStringContainsString('SHIPADDSTART',   $out);

        $this->assertGreaterThanOrEqual(3, substr_count($out, 'OVERVIEWTITLESTART'));
        $this->assertGreaterThanOrEqual(3, substr_count($out, 'OVERVIEWTXTSTART'));
        $this->assertGreaterThanOrEqual(15, substr_count($out, 'PARTSTART'));

        // Short tags MUST be fully translated.
        // Use \b boundaries so prose words like "P1E" inside a description
        // (none in the fixture, but guard against drift) don't false-positive.
        $this->assertDoesNotMatchRegularExpression('/\bH1E\b/', $out);
        $this->assertDoesNotMatchRegularExpression('/\bH4S\b/', $out);
        $this->assertDoesNotMatchRegularExpression('/\bD1S\b/', $out);
        $this->assertDoesNotMatchRegularExpression('/\bD1E\b/', $out);
        $this->assertDoesNotMatchRegularExpression('/\bP1S\b/', $out);
        $this->assertDoesNotMatchRegularExpression('/\bP4S\b/', $out);
        $this->assertDoesNotMatchRegularExpression('/\bP5S\b/', $out);
    }

    public function test_2_10_performance_on_large_synthetic_input(): void
    {
        $bulk  = str_repeat("D1S X D1E\nP1S FOO P1E\n", 10000);
        $start = microtime(true);

        $this->translate($bulk);

        $elapsed = microtime(true) - $start;
        $this->assertLessThan(0.5, $elapsed, "Translator took {$elapsed}s on 10k-row synthetic input — backtracking smell.");
    }
}
