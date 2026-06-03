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

    // =========================================================================
    // TASK 3 — End-to-end parse() with wire-in (priced Cicor fixture)
    // =========================================================================

    public function test_3_1_end_to_end_site_name_clean(): void
    {
        $r = $this->pricedResult();

        $site = (string) ($r['site_name'] ?? '');
        $this->assertStringContainsString('Cicor Hartlepool', $site);

        // No tag artefacts leak into the site name field.
        $this->assertStringNotContainsString('H1',  $site);
        $this->assertStringNotContainsString('H1E', $site);
        $this->assertStringNotContainsString('H4S', $site);
        $this->assertStringNotContainsString('H4E', $site);
        $this->assertStringNotContainsString('Jamie Powis', $site);
    }

    public function test_3_2_end_to_end_ship_contact(): void
    {
        $r = $this->pricedResult();

        // parseTagBased surfaces SHIPCONT as a top-level 'ship_contact' key
        // (added in 260602-mlt — engineer-worksheet header support).
        $this->assertStringContainsString('Jamie Powis', (string) ($r['ship_contact'] ?? ''));
    }

    public function test_3_3_end_to_end_ship_phone(): void
    {
        $r = $this->pricedResult();

        $this->assertStringContainsString('07741 627 320', (string) ($r['ship_phone'] ?? ''));
    }

    public function test_3_4_end_to_end_ref(): void
    {
        $r = $this->pricedResult();

        $this->assertStringContainsString('21CQ30167', (string) ($r['ref'] ?? ''));
    }

    public function test_3_5_end_to_end_room_overviews_contain_section_titles(): void
    {
        $r = $this->pricedResult();

        // Section titles flow into the overview text (joined per section) AND
        // into the `area` field of equipment rows. Asserting on the overview
        // text is the simpler / pipeline-shape-independent check.
        $overview = (string) ($r['overview'] ?? '');

        $this->assertStringContainsString('First Floor Training Room', $overview);
        $this->assertStringContainsString('Professional Services',     $overview);
        $this->assertStringContainsString('Support Services',          $overview);

        // Line-item part numbers MUST NOT appear as section/area names.
        // Equipment row areas should reference the three section titles
        // above, not "INSTALL"/"DELIVERY"/"CONSUMABLES" (those are part_numbers).
        $areas = array_unique(array_map(
            fn (array $item): string => (string) ($item['area'] ?? ''),
            (array) ($r['equipment'] ?? [])
        ));
        foreach (['INSTALL', 'DELIVERY', 'CONSUMABLES', 'PROJMANOFF'] as $banned) {
            foreach ($areas as $area) {
                $this->assertStringNotContainsStringIgnoringCase($banned, $area, "Equipment area '{$area}' was mis-attributed to a line-item part_number.");
            }
        }
    }

    public function test_3_6_end_to_end_equipment_extracts_priced_line_items(): void
    {
        $r = $this->pricedResult();

        $equipment = (array) ($r['equipment'] ?? []);
        $this->assertNotEmpty($equipment, 'Expected equipment rows from the priced Cicor fixture.');
        $this->assertGreaterThanOrEqual(15, count($equipment), 'Expected at least 15 of the 18+ Cicor line items.');

        // Each row carries the canonical shape.
        foreach ($equipment as $row) {
            $this->assertArrayHasKey('part_number', $row);
            $this->assertArrayHasKey('description', $row);
            $this->assertArrayHasKey('qty',         $row);
        }

        // Sample at least 5 expected part numbers.
        $parts = array_map(fn (array $row): string => (string) $row['part_number'], $equipment);
        $sample = ['FW-85BZ30L', 'XTM1U', 'CM20', 'CS10', 'UVC86', 'MCOREKIT-C5U-MS', 'RCH80', 'PA20', 'ROOMPANELPLUSE2', 'MVC-BYOD-EXTENDER'];
        $hits = 0;
        foreach ($sample as $expected) {
            if (in_array($expected, $parts, true)) {
                $hits++;
            }
        }
        $this->assertGreaterThanOrEqual(5, $hits, "Expected ≥5 of " . implode(',', $sample) . " in extracted parts: " . implode(',', $parts));
    }

    public function test_3_7_no_price_or_manufacturer_tag_leaks_in_equipment(): void
    {
        $r = $this->pricedResult();

        foreach ((array) ($r['equipment'] ?? []) as $row) {
            $payload = (string) ($row['description'] ?? '') . ' | ' . (string) ($row['part_number'] ?? '');
            $this->assertStringNotContainsString('£',   $payload, "Price symbol leaked into row: {$payload}");
            $this->assertStringNotContainsString('P4S', $payload, "P4S marker leaked into row: {$payload}");
            $this->assertStringNotContainsString('P5S', $payload, "P5S marker leaked into row: {$payload}");
            $this->assertStringNotContainsString('P5E', $payload, "P5E marker leaked into row: {$payload}");
        }
    }

    public function test_3_8_long_tag_path_untouched_by_wire_in(): void
    {
        // Sanity: the baseline unpriced fixture is still classified as long
        // (translator is skipped) AND parses to a non-empty result.
        $this->assertSame('long', $this->callPrivate('detectTagVariant', [$this->unpriced()]));

        $r = $this->parser->parse($this->unpriced());

        $this->assertNotEmpty($r['client'] ?? '', 'Long-tag parse produced empty client — wire-in regression.');
        $this->assertNotEmpty($r['equipment'] ?? [], 'Long-tag parse produced empty equipment — wire-in regression.');
    }
}
