<?php

namespace Tests\Unit\Services;

use App\Services\QuoteExtractorService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for QuoteExtractorService::sanitiseRawJson().
 *
 * Pure PHP — no Laravel bootstrap, no Claude HTTP mock. Covers the three
 * byte-families that historically tripped JSON_ERROR_CTRL_CHAR on Claude
 * responses:
 *
 *   1. C0 controls + DEL (0x00-0x1F, 0x7F) inside string values
 *   2. High-bit Unicode line/paragraph separators (U+0085, U+2028, U+2029)
 *   3. Malformed UTF-8 multi-byte sequences
 *
 * Plus guards against regressions:
 *   - Properly-escaped `\n` / `\t` sequences in JSON must pass through
 *     intact (two chars: backslash + letter)
 *   - Inter-token whitespace stripping is acceptable side-effect (multi-
 *     space is valid JSON whitespace, so collapse doesn't break parses)
 *
 * Bug trail: 2026-05-16 Tilda 21CQ29531-05-OPS package 110 — three classes
 * of byte hit in sequence as Claude regenerated different responses on
 * retry. Each test below pins down one class.
 */
class QuoteExtractorSanitiseRawJsonTest extends TestCase
{
    // =========================================================================
    // CLASS 1: C0 CONTROLS + DEL (0x00-0x1F, 0x7F)
    // =========================================================================

    public function test_strips_literal_newline_byte_inside_string_value(): void
    {
        // Claude sometimes emits a real 0x0A inside a JSON string value
        // (paragraph break in works_description) instead of the proper
        // two-char `\n` escape. Without sanitisation, json_decode throws
        // JSON_ERROR_CTRL_CHAR.
        $raw = "{\"works_description\": \"First paragraph.\nSecond paragraph.\"}";

        $sanitised = QuoteExtractorService::sanitiseRawJson($raw);
        $decoded   = json_decode($sanitised, true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertIsArray($decoded);
        $this->assertSame(
            'First paragraph.Second paragraph.',
            $decoded['works_description'],
            'Literal newline byte should be stripped, leaving content joined into one line'
        );
    }

    public function test_strips_literal_tab_byte_inside_string_value(): void
    {
        $raw = "{\"client\": \"Acme\tLtd\"}";

        $sanitised = QuoteExtractorService::sanitiseRawJson($raw);
        $decoded   = json_decode($sanitised, true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertSame('AcmeLtd', $decoded['client']);
    }

    public function test_strips_literal_carriage_return_inside_string_value(): void
    {
        $raw = "{\"site\": \"Line1\rLine2\"}";

        $sanitised = QuoteExtractorService::sanitiseRawJson($raw);
        $decoded   = json_decode($sanitised, true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertSame('Line1Line2', $decoded['site']);
    }

    public function test_strips_del_byte_0x7f(): void
    {
        $raw = "{\"ref\": \"21CQ\x7F12345\"}";

        $sanitised = QuoteExtractorService::sanitiseRawJson($raw);
        $decoded   = json_decode($sanitised, true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertSame('21CQ12345', $decoded['ref']);
    }

    public function test_strips_all_c0_controls_0x00_through_0x1f(): void
    {
        // Build a string with every C0 control byte plus DEL stuffed into
        // a JSON value, prove they're all stripped.
        $junk = '';
        for ($i = 0x00; $i <= 0x1F; $i++) {
            $junk .= chr($i);
        }
        $junk .= chr(0x7F);

        $raw = '{"value": "before' . $junk . 'after"}';

        $sanitised = QuoteExtractorService::sanitiseRawJson($raw);
        $decoded   = json_decode($sanitised, true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertSame('beforeafter', $decoded['value']);
    }

    // =========================================================================
    // CLASS 2: HIGH-BIT UNICODE LINE/PARAGRAPH SEPARATORS
    // =========================================================================

    public function test_strips_u2028_line_separator(): void
    {
        // U+2028 LINE SEPARATOR — three bytes E2 80 A8 in UTF-8.
        // Recent PHP json_decode rejects it inside strings.
        $raw = "{\"text\": \"para1\u{2028}para2\"}";

        $sanitised = QuoteExtractorService::sanitiseRawJson($raw);
        $decoded   = json_decode($sanitised, true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertSame('para1para2', $decoded['text']);
    }

    public function test_strips_u2029_paragraph_separator(): void
    {
        $raw = "{\"text\": \"para1\u{2029}para2\"}";

        $sanitised = QuoteExtractorService::sanitiseRawJson($raw);
        $decoded   = json_decode($sanitised, true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertSame('para1para2', $decoded['text']);
    }

    public function test_strips_u0085_next_line(): void
    {
        // U+0085 NEXT LINE — two bytes C2 85 in UTF-8.
        $raw = "{\"text\": \"a\u{0085}b\"}";

        $sanitised = QuoteExtractorService::sanitiseRawJson($raw);
        $decoded   = json_decode($sanitised, true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertSame('ab', $decoded['text']);
    }

    // =========================================================================
    // CLASS 3: MALFORMED UTF-8 MULTI-BYTE SEQUENCES
    // =========================================================================

    public function test_normalises_invalid_utf8_continuation_byte(): void
    {
        // Stray byte 0x80 with no leading byte — invalid UTF-8.
        // mb_convert_encoding normalises it to U+FFFD (3 bytes in UTF-8),
        // which json_decode can then parse (or which JSON_INVALID_UTF8_IGNORE
        // skips at decode time). Either way the sanitised output should not
        // contain the original lone 0x80.
        $raw = "{\"value\": \"good\x80data\"}";

        $sanitised = QuoteExtractorService::sanitiseRawJson($raw);

        // The stray 0x80 should not survive sanitisation
        $this->assertStringNotContainsString(chr(0x80), $sanitised);

        // And the result should still parse (possibly with U+FFFD in the value)
        $decoded = json_decode($sanitised, true, 512, JSON_INVALID_UTF8_IGNORE);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertArrayHasKey('value', $decoded);
    }

    public function test_normalises_truncated_utf8_multi_byte_sequence(): void
    {
        // 0xE2 is a leading byte signalling a 3-byte sequence; here it's
        // standalone (no continuation), classic Claude truncation defect.
        $raw = "{\"value\": \"a\xE2b\"}";

        $sanitised = QuoteExtractorService::sanitiseRawJson($raw);
        $decoded   = json_decode($sanitised, true, 512, JSON_INVALID_UTF8_IGNORE);

        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertArrayHasKey('value', $decoded);
    }

    // =========================================================================
    // REGRESSION GUARDS — properly-escaped sequences must pass through intact
    // =========================================================================

    public function test_preserves_escaped_newline_sequence_in_string_value(): void
    {
        // "\\n" is two literal chars (backslash + n), which is the CORRECT
        // way to represent a newline inside a JSON string. The strip regex
        // operates on bytes, so backslash (0x5C) and n (0x6E) survive and
        // json_decode correctly turns them into a real newline at parse time.
        $raw = '{"text": "line1\\nline2"}';

        $sanitised = QuoteExtractorService::sanitiseRawJson($raw);
        $decoded   = json_decode($sanitised, true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertSame("line1\nline2", $decoded['text']);
    }

    public function test_preserves_escaped_tab_sequence_in_string_value(): void
    {
        $raw = '{"text": "col1\\tcol2"}';

        $sanitised = QuoteExtractorService::sanitiseRawJson($raw);
        $decoded   = json_decode($sanitised, true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertSame("col1\tcol2", $decoded['text']);
    }

    public function test_preserves_normal_ascii_and_high_unicode_content(): void
    {
        // £, €, ™, é — all normal printable Unicode. Must not be touched.
        $raw = '{"prose": "Cost £100 / €120 with 21st Century AV™. Café Sénégal."}';

        $sanitised = QuoteExtractorService::sanitiseRawJson($raw);
        $decoded   = json_decode($sanitised, true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertSame(
            'Cost £100 / €120 with 21st Century AV™. Café Sénégal.',
            $decoded['prose']
        );
    }

    public function test_pretty_printed_json_still_parses_after_strip(): void
    {
        // Pretty-printed JSON has \n / \t bytes between tokens. Sanitisation
        // strips them — but multi-space is valid JSON whitespace, so the
        // collapsed result is still parseable.
        $raw = "{\n  \"key\": \"value\",\n  \"num\": 42\n}";

        $sanitised = QuoteExtractorService::sanitiseRawJson($raw);
        $decoded   = json_decode($sanitised, true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertSame('value', $decoded['key']);
        $this->assertSame(42, $decoded['num']);
    }

    // =========================================================================
    // EDGE CASES
    // =========================================================================

    public function test_empty_input_returns_empty_string(): void
    {
        $this->assertSame('', QuoteExtractorService::sanitiseRawJson(''));
    }

    public function test_pure_ascii_input_passes_through_unchanged(): void
    {
        $raw = '{"a":"b","c":42,"d":true,"e":null}';
        $this->assertSame($raw, QuoteExtractorService::sanitiseRawJson($raw));
    }
}
