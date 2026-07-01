<?php

namespace Tests\Unit\Services;

use App\Services\QuoteExtractorService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for QuoteExtractorService::normaliseNarrativeStrings().
 *
 * Pure PHP — no Laravel bootstrap, no HTTP mock. Locks the contract:
 * mid-sentence soft-wrap `\n` get collapsed to single spaces; real
 * paragraph breaks (double `\n`) survive intact.
 *
 * Bug trail: 2026-06-30 Tilda 21CQ29531-05-OPS survey 22 client PDF
 * rendered CINNAMON / SAFFRON Planned AV Works as 5 mid-sentence
 * bullets each. Root cause was upstream — Claude preserved the
 * source PDF's visual column wraps inside string values. This
 * helper cleans at extraction time so every downstream consumer
 * sees clean prose.
 */
class QuoteExtractorNormaliseNarrativeTest extends TestCase
{
    // =========================================================================
    // SOFT-WRAP COLLAPSE — top-level narrative fields
    // =========================================================================

    public function test_collapses_soft_wrap_newlines_in_works_description(): void
    {
        $decoded = [
            'works_description' => "Cinnamon and Saffron are now using the Crestron Flex integrator\nkit, which also offers full room control from a single Crestron\npanel, wireless BYOD via the Creston AirMedia platform.",
        ];

        $out = QuoteExtractorService::normaliseNarrativeStrings($decoded);

        $this->assertSame(
            'Cinnamon and Saffron are now using the Crestron Flex integrator kit, which also offers full room control from a single Crestron panel, wireless BYOD via the Creston AirMedia platform.',
            $out['works_description'],
            'Mid-sentence \n should become single spaces — output reads as one paragraph'
        );
    }

    public function test_preserves_paragraph_breaks_in_works_description(): void
    {
        // Double newlines = real paragraph break, should survive intact
        // (rendered as paragraph separator downstream).
        $decoded = [
            'works_description' => "First topic about the Boardroom installation.\n\nSecond topic about the Reception kit.",
        ];

        $out = QuoteExtractorService::normaliseNarrativeStrings($decoded);

        $this->assertSame(
            "First topic about the Boardroom installation.\n\nSecond topic about the Reception kit.",
            $out['works_description'],
            'Real paragraph breaks (\\n\\n) MUST survive'
        );
    }

    public function test_collapses_soft_wraps_inside_paragraphs_keeps_paragraph_break(): void
    {
        // Both shapes mixed: soft-wrap inside first paragraph, then a real
        // paragraph break, then soft-wrap inside second paragraph.
        $decoded = [
            'works_description' => "Cinnamon is now using the Crestron Flex\nintegrator kit.\n\nI have also added the Crestron Occupancy\nSensor into Cinnamon.",
        ];

        $out = QuoteExtractorService::normaliseNarrativeStrings($decoded);

        $this->assertSame(
            "Cinnamon is now using the Crestron Flex integrator kit.\n\nI have also added the Crestron Occupancy Sensor into Cinnamon.",
            $out['works_description'],
            'Soft-wraps collapsed within each paragraph, paragraph break preserved between'
        );
    }

    // =========================================================================
    // SOFT-WRAP COLLAPSE — room_summaries[].summary
    // =========================================================================

    public function test_collapses_soft_wraps_in_room_summaries_summary(): void
    {
        $decoded = [
            'room_summaries' => [
                [
                    'room'    => 'CINNAMON',
                    'summary' => "Cinnamon now has a Sony 98\" display chosen.\nCinnamon and Saffron are now using the Crestron Flex\nintegrator kit.",
                ],
                [
                    'room'    => 'OREGANO',
                    'summary' => "Already clean prose ending with a period.",
                ],
            ],
        ];

        $out = QuoteExtractorService::normaliseNarrativeStrings($decoded);

        $this->assertSame(
            "Cinnamon now has a Sony 98\" display chosen. Cinnamon and Saffron are now using the Crestron Flex integrator kit.",
            $out['room_summaries'][0]['summary']
        );
        $this->assertSame(
            'Already clean prose ending with a period.',
            $out['room_summaries'][1]['summary'],
            'Already-clean prose should pass through unchanged (idempotent)'
        );
    }

    public function test_collapses_whitespace_in_room_name(): void
    {
        // Room name should always be single-line; PDF extraction sometimes
        // splits a long room name across lines.
        $decoded = [
            'room_summaries' => [[
                'room'    => "ROOM BOOKING\nPANELS",
                'summary' => 'short summary.',
            ]],
        ];

        $out = QuoteExtractorService::normaliseNarrativeStrings($decoded);

        $this->assertSame(
            'ROOM BOOKING PANELS',
            $out['room_summaries'][0]['room']
        );
    }

    // =========================================================================
    // IDEMPOTENCY — already-clean input passes through unchanged
    // =========================================================================

    public function test_idempotent_on_already_clean_input(): void
    {
        $clean = [
            'works_description' => 'Clean single-paragraph prose ending with a period.',
            'room_summaries'    => [
                ['room' => 'Boardroom', 'summary' => 'Clean room summary.'],
            ],
        ];

        $once  = QuoteExtractorService::normaliseNarrativeStrings($clean);
        $twice = QuoteExtractorService::normaliseNarrativeStrings($once);

        $this->assertSame(
            $once,
            $twice,
            'Running the normaliser twice on the same input must produce identical output'
        );
        $this->assertSame($clean['works_description'], $once['works_description']);
        $this->assertSame($clean['room_summaries'], $once['room_summaries']);
    }

    public function test_empty_string_fields_pass_through(): void
    {
        $decoded = [
            'works_description' => '',
            'client_name'       => '',
            'site_address'      => '',
            'room_summaries'    => [
                ['room' => '', 'summary' => ''],
            ],
        ];

        $out = QuoteExtractorService::normaliseNarrativeStrings($decoded);

        $this->assertSame('', $out['works_description']);
        $this->assertSame('', $out['client_name']);
        $this->assertSame('', $out['site_address']);
        $this->assertSame('', $out['room_summaries'][0]['room']);
        $this->assertSame('', $out['room_summaries'][0]['summary']);
    }

    public function test_missing_keys_dont_error(): void
    {
        // Sparse decoded payload — the AI may omit fields if it can't find them.
        $decoded = [
            'qw_number' => 'QW-123',
            // No narrative keys at all.
        ];

        $out = QuoteExtractorService::normaliseNarrativeStrings($decoded);

        $this->assertSame($decoded, $out);
    }

    // =========================================================================
    // EXCLUDED FIELDS — line_items and bullet arrays must NOT be touched
    // =========================================================================

    public function test_line_items_descriptions_are_not_touched(): void
    {
        // Line item descriptions may legitimately contain newlines from
        // the source PDF (e.g. wrapped product titles). Preserve them
        // verbatim so engineers see the exact source text.
        $decoded = [
            'line_items' => [
                ['sku' => 'FW-85BZ40L', 'qty' => 1, 'description' => "Sony 85\"\n4K display"],
            ],
        ];

        $out = QuoteExtractorService::normaliseNarrativeStrings($decoded);

        $this->assertSame(
            "Sony 85\"\n4K display",
            $out['line_items'][0]['description']
        );
    }

    public function test_bullet_arrays_are_not_touched(): void
    {
        // hazards / ppe / persons_at_risk are short bullet strings;
        // newlines inside them would be a data quality issue, but the
        // normaliser shouldn't strip them — those arrays aren't in the
        // narrative allow-list.
        $decoded = [
            'hazards'        => ["Working at\nheight"],
            'ppe'            => ['Safety footwear'],
            'persons_at_risk' => ['AV Installers'],
        ];

        $out = QuoteExtractorService::normaliseNarrativeStrings($decoded);

        $this->assertSame("Working at\nheight", $out['hazards'][0]);
    }

    // =========================================================================
    // TRIPLE NEWLINES — collapse runs of \n to a single paragraph break
    // =========================================================================

    public function test_triple_newlines_collapse_to_single_paragraph_break(): void
    {
        // A messy source with 3+ newlines between paragraphs collapses to
        // a single \n\n so the output is consistent.
        $decoded = [
            'works_description' => "First paragraph.\n\n\n\nSecond paragraph.",
        ];

        $out = QuoteExtractorService::normaliseNarrativeStrings($decoded);

        $this->assertSame(
            "First paragraph.\n\nSecond paragraph.",
            $out['works_description']
        );
    }
}
