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
}
