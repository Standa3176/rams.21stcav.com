<?php

namespace App\Services;

use Smalot\PdfParser\Parser;

/**
 * Extracts plain text from a PDF file using local parsing only.
 *
 * Extraction order:
 *   1. smalot/pdfparser  — fast, accurate for selectable-text PDFs.
 *   2. Raw stream fallback — proper PDF literal-string parser over the raw byte
 *      stream, used when pdfparser yields fewer than 50 characters.
 *      Correctly handles: nested parentheses, all PDF escape sequences
 *      (\n \r \t \b \f \\ \( \) \ddd), and filters out binary / non-printable
 *      content before returning.
 *   3. PdfOcrExtractorService (Tesseract) — last resort for scanned/image PDFs.
 *
 * All three paths pass through cleanText() which strips PDF structural noise
 * (object headers, xref entries, structural keywords) before the text reaches
 * QuoteParserService.
 *
 * No AI usage. No external API calls.
 * Requires: composer require smalot/pdfparser
 * Optional: tesseract-ocr + poppler-utils installed on the server (for OCR fallback).
 */
class PdfTextExtractorService
{
    /** Below this length pdfparser result triggers the raw-stream fallback. */
    private const RAW_FALLBACK_THRESHOLD = 50;

    /** Below this length after raw fallback, Tesseract OCR is attempted. */
    private const OCR_FALLBACK_THRESHOLD = 200;

    public function __construct(
        private readonly Parser                 $parser,
        private readonly PdfOcrExtractorService $ocr,
    ) {}

    /**
     * Extract and clean all readable text from the given PDF file.
     *
     * @param  string $path  Absolute filesystem path to the PDF file.
     * @return string        Extracted, normalised plain text.
     *
     * @throws \Exception If the file cannot be parsed and all fallbacks fail.
     */
    public function extract(string $path): string
    {
        $text = $this->parseText($path);

        // Fallback 1 — raw stream extraction (no extra dependencies)
        if (strlen(trim($text)) < self::RAW_FALLBACK_THRESHOLD) {
            $text = $this->rawStreamText($path);
        }

        // Fallback 2 — Tesseract OCR (scanned / image-only PDFs)
        if (mb_strlen(trim($text)) < self::OCR_FALLBACK_THRESHOLD) {
            $text = $this->ocr->extract($path);
        }

        // Final shared pass — strip any remaining PDF structural noise regardless
        // of which extraction path was used.
        return $this->cleanText($text);
    }

    // ── Private — extraction ──────────────────────────────────────────────────

    /**
     * Parse selectable text from the PDF using smalot/pdfparser.
     *
     * Lines shorter than 3 characters or with no alphabetic content are dropped
     * here so that simple numeric fragments from font metrics / encoding tables
     * inside the PDF structure do not pollute the output.
     */
    private function parseText(string $path): string
    {
        try {
            $pdf  = $this->parser->parseFile($path);
            $raw  = $pdf->getText();
            $text = str_replace(["\r\n", "\r"], "\n", $raw);
            $text = preg_replace('/\n{3,}/', "\n\n", $text);

            $lines = array_map('trim', explode("\n", $text));
            $lines = array_filter(
                $lines,
                static fn (string $l): bool =>
                    mb_strlen($l) >= 3 && (bool) preg_match('/[a-zA-Z]/', $l),
            );

            return implode("\n", $lines);
        } catch (\Throwable $e) {
            // Smalot can throw "Secured pdf file are currently not supported."
            // Treat this as a non-fatal parse failure and fall back to raw/OCR.
            return '';
        }
    }

    /**
     * Raw stream fallback: walk the raw PDF bytes and extract all PDF literal
     * strings (sequences delimited by parentheses).
     *
     * Uses a proper state machine that correctly handles:
     *   - Nested balanced parentheses via depth tracking
     *   - All PDF escape sequences: \n \r \t \b \f \\ \( \) \ddd (octal)
     *   - Non-printable / binary content (stripped after decoding)
     *
     * Results are filtered: each extracted string must contain at least two
     * consecutive alphabetic characters to be included in the output.
     */
    private function rawStreamText(string $path): string
    {
        $raw     = (string) file_get_contents($path);
        $strings = $this->extractPdfLiteralStrings($raw);
        $usable  = [];

        foreach ($strings as $s) {
            // Strip non-printable characters; keep tab, LF, CR, printable ASCII
            $clean = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $s);
            $clean = trim($clean);

            if (strlen($clean) < 3) {
                continue;
            }

            // Must contain at least 2 consecutive alphabetic characters.
            // This filters binary fragments, pure-numeric strings, single-letter
            // PDF operator tokens, and other non-text content.
            if (! preg_match('/[a-zA-Z]{2,}/', $clean)) {
                continue;
            }

            // Skip PDF date/time strings: D:YYYYMMDDHHmmSS
            if (preg_match('/^D:\d/', $clean)) {
                continue;
            }

            // Skip strings that are entirely numeric / punctuation after removing spaces
            // (e.g. standalone prices, page numbers, version numbers)
            if (preg_match('/^[\d\s\.\-\+\,\/\:\(\)]+$/', $clean)) {
                continue;
            }

            $usable[] = $clean;
        }

        return empty($usable) ? '' : implode("\n", $usable);
    }

    /**
     * Walk the raw PDF byte stream and return all PDF literal strings.
     *
     * PDF literal strings are enclosed in parentheses and may contain:
     *   - Balanced nested parentheses: (Hello (World)) is a single string.
     *   - Escaped parentheses: \( and \) do not affect nesting depth.
     *   - Escape sequences decoded in-place:
     *       \n → LF      \r → CR       \t → HT
     *       \b → BS      \f → FF       \\ → backslash
     *       \( → (       \) → )        \ddd → octal character
     *
     * @return string[]  Raw (decoded) strings found in the PDF byte stream.
     */
    private function extractPdfLiteralStrings(string $raw): array
    {
        $strings = [];
        $len     = strlen($raw);
        $i       = 0;

        while ($i < $len) {
            if ($raw[$i] !== '(') {
                $i++;
                continue;
            }

            $i++;       // skip the opening (
            $str   = '';
            $depth = 1;

            while ($i < $len && $depth > 0) {
                $ch = $raw[$i];

                if ($ch === '\\' && ($i + 1) < $len) {
                    $next = $raw[$i + 1];

                    // Octal escape: \d, \dd, or \ddd  (1–3 octal digits)
                    if ($next >= '0' && $next <= '7') {
                        $oct = '';
                        $j   = $i + 1;
                        while ($j < $len && ($j - ($i + 1)) < 3 && $raw[$j] >= '0' && $raw[$j] <= '7') {
                            $oct .= $raw[$j++];
                        }
                        $str .= chr(octdec($oct));
                        $i    = $j;
                    } else {
                        $str .= match ($next) {
                            'n'     => "\n",
                            'r'     => "\r",
                            't'     => "\t",
                            'b'     => "\x08",
                            'f'     => "\x0C",
                            '('     => '(',
                            ')'     => ')',
                            '\\'    => '\\',
                            default => $next,  // unknown escape: pass through the escaped char
                        };
                        $i += 2;
                    }
                } elseif ($ch === '(') {
                    $depth++;
                    $str .= $ch;
                    $i++;
                } elseif ($ch === ')') {
                    $depth--;
                    if ($depth > 0) {
                        $str .= $ch;
                    }
                    $i++;
                } else {
                    $str .= $ch;
                    $i++;
                }
            }

            $strings[] = $str;
        }

        return $strings;
    }

    /**
     * Final cleaning pass applied to text from all three extraction paths.
     *
     * Removes:
     *   - Lines shorter than 3 characters.
     *   - Lines with no alphabetic content (pure numbers, symbols, binary).
     *   - PDF cross-reference table entries  (e.g. "0000000017 00000 n").
     *   - PDF object header/trailer lines    (e.g. "17 0 obj", "3 0 R").
     *   - PDF structural keywords on their own line
     *     (endobj, endstream, xref, startxref, trailer, stream).
     *
     * This is the last line of defence before the text is handed to
     * QuoteParserService, and is intentionally conservative — it only removes
     * patterns that are unambiguously PDF internal structure.
     */
    private function cleanText(string $text): string
    {
        $lines = preg_split('/\r?\n/', $text);
        $out   = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if (strlen($line) < 3) {
                continue;
            }

            // Must contain at least one alphabetic character
            if (! preg_match('/[a-zA-Z]/', $line)) {
                continue;
            }

            // Reject lines where more than 15% of characters are non-alphanumeric/
            // non-whitespace — these are binary PDF stream fragments that slipped
            // through the extraction filter (e.g. encoded font data, CMap tables).
            // Threshold is intentionally conservative: real prose never exceeds ~5%;
            // garbled binary stream data typically runs 20–45%.
            $specialCount = preg_match_all('/[^a-zA-Z0-9\s\.,\-\'\"\/\(\)\&\:\#\@\+\!\?\;\=]/', $line);
            if (strlen($line) > 0 && ($specialCount / strlen($line)) > 0.10) {
                continue;
            }

            // PDF cross-reference entries: "0000000017 00000 n" / "0000000017 00000 f"
            if (preg_match('/^\d{5,}\s+\d{5}\s+[fn]\s*$/i', $line)) {
                continue;
            }

            // PDF object header / trailer: "17 0 obj"  or  "3 0 R"
            if (preg_match('/^\d+\s+\d+\s+(?:obj|R)\s*$/i', $line)) {
                continue;
            }

            // PDF structural keywords standing alone on a line
            if (preg_match('/^(?:endobj|endstream|xref|startxref|trailer|stream)\s*$/i', $line)) {
                continue;
            }

            $out[] = $line;
        }

        return implode("\n", $out);
    }
}
