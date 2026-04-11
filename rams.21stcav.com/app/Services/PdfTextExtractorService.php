<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;

/**
 * Extracts plain text from a PDF using layered local fallbacks.
 *
 * Order:
 *   0) pdftotext (Poppler) — primary for QuoteWerks PDFs with custom font encoding
 *   1) Smalot parser
 *   2) FlateDecode decompressed stream text (PHP gzinflate — no binary deps)
 *   3) Raw literal-string extraction from PDF streams (uncompressed only)
 *   4) OCR (Tesseract)
 *
 * Stage 0 is the primary fix for QuoteWerks PDFs: pdftotext resolves the
 * ToUnicode CMap tables that QuoteWerks uses for custom font encoding,
 * producing plain ASCII marker text (PARTSTART, PARTEND, etc.) that the
 * QuoteParserService can parse for 100% confidence extraction.
 *
 * Every stage is cleaned and quality-scored before it is accepted.
 */
class PdfTextExtractorService
{
    /** Below this length after Smalot, force decompressed-stream fallback. */
    private const RAW_FALLBACK_THRESHOLD = 50;

    /** Below this length after decompressed fallback, force OCR fallback. */
    private const OCR_FALLBACK_THRESHOLD = 200;

    /** Minimum trimmed length for a candidate to be considered usable. */
    private const MIN_USABLE_CHARS = 120;

    /** Max ratio of "special" characters in a single line during cleaning. */
    private const MAX_LINE_SPECIAL_RATIO = 0.10;

    /** Minimum average alphabetic run length for text to look human-readable. */
    private const MIN_ALPHA_RUN_AVG = 2.8;

    /** Maximum fraction of alphabetic runs that may be <= 2 chars. */
    private const MAX_SHORT_RUN_RATIO = 0.45;

    public function __construct(
        private readonly Parser                 $parser,
        private readonly PdfOcrExtractorService $ocr,
    ) {}

    /**
     * Extract and clean readable text from the given PDF file.
     */
    public function extract(string $path): string
    {
        // Stage 0: pdftotext (Poppler) — primary for QuoteWerks custom-font PDFs
        $poppler = $this->extractWithPdfToText($path);
        if ($poppler !== '') {
            Log::info('PdfTextExtractorService: using pdftotext output', [
                'path'        => basename($path),
                'length'      => strlen($poppler),
                'has_markers' => $this->hasQuoteWerksMarkers($poppler),
            ]);
            return $poppler;
        }

        // Stage 1: Smalot
        $smalot = $this->cleanText($this->parseText($path));
        Log::info('PdfTextExtractorService: smalot extracted', [
            'path'        => basename($path),
            'length'      => strlen($smalot),
            'has_markers' => $this->hasQuoteWerksMarkers($smalot),
            'preview'     => mb_substr($smalot, 0, 300),
        ]);
        if ($this->isUsableText($smalot)) {
            Log::info('PdfTextExtractorService: using Smalot output', [
                'path'   => basename($path),
                'length' => strlen($smalot),
            ]);
            return $smalot;
        }

        // Stage 2: FlateDecode decompressed streams (primary fix for QuoteWerks on Windows)
        // Decompresses FlateDecode content streams with gzinflate() and extracts
        // all literal strings — the QuoteWerks markers live here even when smalot
        // cannot read them due to custom font encoding.
        $decompressed = $this->cleanText($this->decompressedStreamText($path));
        Log::info('PdfTextExtractorService: decompressed stream extracted', [
            'path'        => basename($path),
            'length'      => strlen($decompressed),
            'has_markers' => $this->hasQuoteWerksMarkers($decompressed),
        ]);
        if ($this->isUsableText($decompressed)) {
            Log::info('PdfTextExtractorService: using decompressed-stream output', [
                'path'   => basename($path),
                'length' => strlen($decompressed),
            ]);
            return $decompressed;
        }

        // Stage 3: Raw literal-string fallback (uncompressed streams only)
        $raw = '';
        if (
            mb_strlen(trim($smalot)) < self::RAW_FALLBACK_THRESHOLD
            || ! $this->looksHumanReadable($smalot)
        ) {
            $raw = $this->cleanText($this->rawStreamText($path));
            if ($this->isUsableText($raw)) {
                Log::info('PdfTextExtractorService: using raw-stream fallback output', [
                    'path'   => basename($path),
                    'length' => strlen($raw),
                ]);
                return $raw;
            }
        }

        // Stage 4: OCR fallback
        $bestSoFar = $decompressed !== '' ? $decompressed : ($raw !== '' ? $raw : $smalot);
        $shouldTryOcr =
            mb_strlen(trim($bestSoFar)) < self::OCR_FALLBACK_THRESHOLD
            || ! $this->looksHumanReadable($bestSoFar);

        if ($shouldTryOcr) {
            try {
                $ocr = $this->cleanText($this->ocr->extract($path));
                if ($this->isUsableText($ocr)) {
                    Log::info('PdfTextExtractorService: using OCR fallback output', [
                        'path'   => basename($path),
                        'length' => strlen($ocr),
                    ]);
                    return $ocr;
                }
                if ($ocr !== '') {
                    Log::warning('PdfTextExtractorService: OCR output still low-quality; returning best OCR text', [
                        'path'   => basename($path),
                        'length' => strlen($ocr),
                    ]);
                    return $ocr;
                }
            } catch (\Throwable $e) {
                Log::warning('PdfTextExtractorService: OCR fallback failed', [
                    'error' => $e->getMessage(),
                    'path'  => basename($path),
                ]);
            }
        }

        Log::warning('PdfTextExtractorService: returning low-confidence extraction output', [
            'path'   => basename($path),
            'length' => strlen($bestSoFar),
        ]);

        return $bestSoFar;
    }

    // ── Private — extraction ──────────────────────────────────────────────────

    /**
     * Run pdftotext (Poppler) and return its stdout as plain text.
     *
     * This is the primary extractor for QuoteWerks PDFs because it resolves
     * the ToUnicode CMap tables that QuoteWerks uses for custom font encoding,
     * producing plain ASCII marker text (PARTSTART, PARTEND, etc.) that PHP
     * alone cannot decode from compressed stream bytes.
     *
     * Detects the binary cross-platform: `where` on Windows, `which` on Unix.
     * Returns an empty string when pdftotext is unavailable or produces nothing.
     */
    private function extractWithPdfToText(string $path): string
    {
        // Hardcoded known Windows path (Git for Windows ships pdftotext.exe here).
        // Also try PATH-based detection as fallback.
        $candidates = [
            'C:\\Program Files\\Git\\mingw64\\bin\\pdftotext.exe',
            'C:\\Program Files (x86)\\Git\\mingw64\\bin\\pdftotext.exe',
            'C:\\poppler\\bin\\pdftotext.exe',
            'C:\\Program Files\\poppler\\bin\\pdftotext.exe',
        ];

        $binary = '';
        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                $binary = $candidate;
                break;
            }
        }

        // PATH-based fallback: `where` on Windows, `which` on Unix
        if ($binary === '') {
            $whereOutput = shell_exec('where pdftotext 2>NUL');
            if (is_string($whereOutput) && trim($whereOutput) !== '') {
                $binary = trim(explode("\n", $whereOutput)[0]);
            }
        }

        if ($binary === '') {
            $whichOutput = shell_exec('which pdftotext 2>/dev/null');
            if (is_string($whichOutput) && trim($whichOutput) !== '') {
                $binary = trim($whichOutput);
            }
        }

        Log::info('PdfTextExtractorService: pdftotext detection', [
            'binary'     => $binary ?: 'NOT FOUND',
            'os_family'  => PHP_OS_FAMILY,
            'path'       => basename($path),
        ]);

        if ($binary === '') {
            return '';
        }

        $escaped = escapeshellarg($path);
        $cmd     = escapeshellarg($binary) . " -raw -enc UTF-8 {$escaped} -";

        $output = shell_exec($cmd);

        Log::info('PdfTextExtractorService: pdftotext output', [
            'cmd'         => $cmd,
            'output_len'  => is_string($output) ? strlen($output) : 'null',
            'has_markers' => is_string($output) ? $this->hasQuoteWerksMarkers($output) : false,
            'preview'     => is_string($output) ? mb_substr($output, 0, 300) : 'null',
        ]);

        if (! is_string($output) || trim($output) === '') {
            return '';
        }

        // pdftotext output is already clean ASCII — just normalise line endings.
        // Do NOT run cleanText() here: it would strip single-digit quantity lines
        // (e.g. "1" between QTYSTART/QTYEND) that QuoteParserService needs.
        $text = str_replace(["\r\n", "\r"], "\n", $output);

        return (string) preg_replace('/\n{3,}/', "\n\n", $text);
    }

    /**
     * Parse selectable text from the PDF using smalot/pdfparser.
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
                    mb_strlen($l) >= 3 && (
                        (bool) preg_match('/[a-zA-Z]/', $l) ||
                        (bool) preg_match('/^[\d\.\-\/]{2,30}$/', $l)
                    ),
            );

            return implode("\n", $lines);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Decompress all FlateDecode content streams in the PDF and extract
     * literal strings from them using gzinflate() (no external binaries).
     *
     * QuoteWerks PDFs encode the marker text (PARTSTART, PARTDESCSTART, etc.)
     * inside FlateDecode-compressed content streams. Smalot reads these streams
     * but can misrender the text due to custom font encoding. This method
     * bypasses font encoding entirely by working with the raw decompressed
     * operator bytes and extracting every parenthesis-delimited string — the
     * same approach as rawStreamText() but applied to decompressed content.
     */
    private function decompressedStreamText(string $path): string
    {
        $raw = (string) file_get_contents($path);
        $allDecompressed = '';

        // Find every stream...endstream block in the raw PDF bytes.
        // We try gzinflate() on each — FlateDecode streams will decompress
        // successfully; non-FlateDecode streams will return false and be skipped.
        // This avoids parsing the stream dictionary (which can span multiple lines
        // and contain nested angle brackets that break simple regex).
        preg_match_all('/stream\r?\n(.*?)endstream/s', $raw, $matches);

        foreach ($matches[1] as $compressed) {
            // PDF FlateDecode uses raw deflate — gzinflate() handles this.
            $content = @gzinflate($compressed);

            // If raw deflate fails, try with zlib header (some generators add it).
            if ($content === false) {
                $content = @gzuncompress($compressed);
            }

            if ($content === false || $content === '') {
                continue;
            }

            $allDecompressed .= $content . "\n";
        }

        if ($allDecompressed === '') {
            return '';
        }

        // Extract all parenthesis-delimited literal strings from the decompressed
        // operator stream. This is identical to extractPdfLiteralStrings() but
        // applied to the decompressed bytes rather than the raw PDF file.
        $strings = $this->extractPdfLiteralStrings($allDecompressed);
        $usable  = [];

        foreach ($strings as $s) {
            // Strip non-printable bytes introduced by font encoding.
            $clean = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $s);
            $clean = trim($clean);

            if (strlen($clean) < 2) {
                continue;
            }

            // Keep QuoteWerks markers even if they contain no regular words.
            if ($this->hasQuoteWerksMarkers($clean)) {
                $usable[] = $clean;
                continue;
            }

            if (! preg_match('/[a-zA-Z]{2,}/', $clean)) {
                continue;
            }

            // Reject date stamps and pure-numeric strings.
            if (preg_match('/^D:\d/', $clean)) {
                continue;
            }
            if (preg_match('/^[\d\s\.\-\+\,\/\:\(\)]+$/', $clean)) {
                continue;
            }

            $usable[] = $clean;
        }

        return empty($usable) ? '' : implode("\n", $usable);
    }

    /**
     * Raw-stream fallback over PDF literal strings (uncompressed streams only).
     */
    private function rawStreamText(string $path): string
    {
        $raw     = (string) file_get_contents($path);
        $strings = $this->extractPdfLiteralStrings($raw);
        $usable  = [];

        foreach ($strings as $s) {
            $clean = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $s);
            $clean = trim($clean);

            if (strlen($clean) < 3) {
                continue;
            }

            if (! preg_match('/[a-zA-Z]{2,}/', $clean)) {
                continue;
            }

            if (preg_match('/^D:\d/', $clean)) {
                continue;
            }

            if (preg_match('/^[\d\s\.\-\+\,\/\:\(\)]+$/', $clean)) {
                continue;
            }

            $usable[] = $clean;
        }

        return empty($usable) ? '' : implode("\n", $usable);
    }

    /**
     * Extract all decoded PDF literal strings from raw bytes.
     *
     * @return string[]
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

            $i++;
            $str   = '';
            $depth = 1;

            while ($i < $len && $depth > 0) {
                $ch = $raw[$i];

                if ($ch === '\\' && ($i + 1) < $len) {
                    $next = $raw[$i + 1];

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
                            default => $next,
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
     * Shared final line-cleaning pass.
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

            if (! preg_match('/[a-zA-Z]/', $line) && ! preg_match('/^[\d\.\-\/]{2,30}$/', $line)) {
                continue;
            }

            // PDF structural noise that often leaks when OCR/smalot output is poor.
            if (preg_match('/(?:\/Type\s*\/Page|\/Parent\s+\d+\s+\d+\s+R|\/MediaBox|\/Contents\s*\[|\/Resources|\/Length|\/Filter|FlateDecode|DeviceRGB|Transparency|startxref|endobj|endstream|xref|trailer|\bstream\b)/i', $line)) {
                continue;
            }
            if (preg_match('/^\d+\s+\d+\s+(?:obj|R)\s*$/i', $line)) {
                continue;
            }
            if (preg_match('/^\d{5,}\s+\d{5}\s+[fn]\s*$/i', $line)) {
                continue;
            }

            $specialCount = preg_match_all('/[^a-zA-Z0-9\s\.,\-\'\"\/\(\)\&\:\#\@\+\!\?\;\=]/', $line);
            if (strlen($line) > 0 && ($specialCount / strlen($line)) > self::MAX_LINE_SPECIAL_RATIO) {
                continue;
            }

            // Reject lines that look like symbol-separated binary gibberish.
            preg_match_all('/[a-zA-Z]+/', $line, $alphaRunMatches);
            $runs = $alphaRunMatches[0];
            if (! empty($runs)) {
                $avgRunLen = array_sum(array_map('strlen', $runs)) / count($runs);
                if (strlen($line) >= 30 && $avgRunLen < self::MIN_ALPHA_RUN_AVG) {
                    continue;
                }
            }

            $out[] = $line;
        }

        return implode("\n", $out);
    }

    // ── Private — quality scoring ─────────────────────────────────────────────

    /**
     * True when text is usable for downstream quote parsing.
     */
    private function isUsableText(string $text): bool
    {
        $trimmed = trim($text);
        if (mb_strlen($trimmed) < self::MIN_USABLE_CHARS) {
            return false;
        }

        // QuoteWerks structured PDFs embed tag markers in the text stream.
        // The readability heuristic rejects them (many short alpha runs from
        // part numbers and tag names). Accept immediately when markers present.
        if ($this->hasQuoteWerksMarkers($trimmed)) {
            return true;
        }

        if (! preg_match('/[A-Za-z]{4,}/', $trimmed)) {
            return false;
        }

        if (! $this->looksHumanReadable($trimmed)) {
            return false;
        }

        return true;
    }

    /**
     * True when text contains QuoteWerks structured tag markers.
     */
    private function hasQuoteWerksMarkers(string $text): bool
    {
        return (bool) preg_match('/(?:PARTSTART|OVERVIEWTITLESTART|SHIPCONTSTART|QUOTENUMSTART)/i', $text);
    }

    /**
     * Heuristic readability gate to reject encoded/PDF-structure garbage.
     */
    private function looksHumanReadable(string $text): bool
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return false;
        }

        if ($this->hasPdfStructuralDominance($trimmed)) {
            return false;
        }

        // Fast fail for obvious PDF internals that should never dominate real quote text.
        $pdfNoiseHits = preg_match_all(
            '/(?:\/Type\s*\/Page|\/Parent\s+\d+\s+\d+\s+R|\/MediaBox|\/Contents\s*\[|FlateDecode|startxref|endobj|endstream|xref|trailer|\d+\s+\d+\s+obj)/i',
            $trimmed
        );
        if ($pdfNoiseHits >= 3) {
            return false;
        }

        preg_match_all('/[a-zA-Z]+/', $trimmed, $alphaRunMatches);
        $runs = $alphaRunMatches[0] ?? [];
        if (count($runs) < 20) {
            return false;
        }

        $runLengths = array_map('strlen', $runs);
        $avgRunLen  = array_sum($runLengths) / count($runLengths);
        if ($avgRunLen < self::MIN_ALPHA_RUN_AVG) {
            return false;
        }

        $shortRunCount = count(array_filter($runLengths, static fn (int $len): bool => $len <= 2));
        if (($shortRunCount / count($runLengths)) > self::MAX_SHORT_RUN_RATIO) {
            return false;
        }

        return true;
    }

    /**
     * Detect when extracted text is dominated by PDF object/structure tokens.
     */
    private function hasPdfStructuralDominance(string $text): bool
    {
        $hits = (int) preg_match_all(
            '/(?:\/Type|\/Page|\/Resources|\/Parent|\/Contents|\/MediaBox|\/Length|\/Filter|FlateDecode|DeviceRGB|Transparency|startxref|endobj|endstream|\bxref\b|\btrailer\b|\bstream\b|\bobj\b)/i',
            $text
        );
        $words = (int) preg_match_all('/[A-Za-z]{3,}/', $text);

        if ($hits >= 8) {
            return true;
        }

        if ($words > 0 && ($hits / $words) > 0.12) {
            return true;
        }

        return false;
    }
}
