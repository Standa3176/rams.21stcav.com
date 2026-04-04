<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;

/**
 * Extracts and validates plain text from a PDF quote file.
 *
 * This is the first stage of the PDF-to-RAMS pipeline.
 * All extraction is performed locally — no AI, no external API calls.
 *
 * Extraction strategy (in order):
 *   1. PRIMARY  — Poppler pdftotext (shell_exec)
 *                 Best output for selectable-text PDFs from QuoteWerks.
 *   2. FALLBACK — Smalot\PdfParser
 *                 Used when Poppler is unavailable or returns invalid text.
 *   3. OCR      — Tesseract (tesseract-ocr)
 *                 Last resort for print-to-PDF / image-only PDFs where
 *                 no selectable text layer exists.
 *
 * Validation (all paths):
 *   - Text must be > 100 characters after trimming.
 *   - Text must contain at least one 4+ letter word ([A-Za-z]{4,}).
 *   - Non-printable / binary character ratio must not exceed 10 %.
 *
 * Output:
 *   - Truncated to 200,000 characters maximum.
 *   - Throws \Exception if all extractors fail or produce invalid text.
 */
class QuoteTextExtractorService
{
    /** Maximum characters returned (prevents oversized parser inputs). */
    private const MAX_CHARS = 200_000;

    /** Minimum characters required to consider extraction valid. */
    private const MIN_CHARS = 100;

    /** Maximum ratio of non-printable bytes before text is rejected as binary. */
    private const MAX_BINARY_RATIO = 0.10;

    public function __construct(
        private readonly Parser $parser,
    ) {}

    /**
     * Extract and validate readable text from the given PDF file.
     *
     * @param  string $path  Absolute filesystem path to the PDF file.
     * @return string        Validated, truncated plain text.
     *
     * @throws \Exception If the file is unreadable or all extractors fail.
     */
    public function extract(string $path): string
    {
        if (! file_exists($path) || ! is_readable($path)) {
            throw new \Exception("QuoteTextExtractorService: file not found or not readable: {$path}");
        }

        // ── Primary: Poppler pdftotext ────────────────────────────────────────
        $text = $this->extractWithPoppler($path);

        if ($this->isValid($text)) {
            Log::info('QuoteTextExtractorService: extracted via Poppler (pdftotext)', [
                'path'   => basename($path),
                'length' => strlen($text),
            ]);

            return $this->truncate($text);
        }

        // ── Fallback 1: Smalot PdfParser ──────────────────────────────────────
        Log::warning('QuoteTextExtractorService: Poppler unavailable or returned invalid text — falling back to Smalot', [
            'path'           => basename($path),
            'poppler_length' => strlen(trim($text)),
        ]);

        $text = $this->extractWithSmalot($path);

        if ($this->isValid($text)) {
            Log::info('QuoteTextExtractorService: extracted via Smalot (fallback 1)', [
                'path'   => basename($path),
                'length' => strlen($text),
            ]);

            return $this->truncate($text);
        }

        // ── Fallback 2: Tesseract OCR ─────────────────────────────────────────
        // Required for print-to-PDF files (Producer: Microsoft Print To PDF)
        // where all pages are rasterised images with no selectable text layer.
        Log::warning('QuoteTextExtractorService: Smalot returned invalid text — falling back to Tesseract OCR', [
            'path'          => basename($path),
            'smalot_length' => strlen(trim($text)),
        ]);

        $text = $this->extractWithTesseract($path);

        if ($this->isValid($text)) {
            Log::info('QuoteTextExtractorService: extracted via Tesseract OCR (fallback 2)', [
                'path'   => basename($path),
                'length' => strlen($text),
            ]);

            return $this->truncate($text);
        }

        // ── All extractors failed ─────────────────────────────────────────────
        Log::error('QuoteTextExtractorService: all extractors (Poppler, Smalot, Tesseract) failed', [
            'path'         => basename($path),
            'ocr_length'   => strlen(trim($text)),
        ]);

        throw new \Exception(
            "QuoteTextExtractorService: PDF extraction failed — Poppler, Smalot and Tesseract all returned invalid text for: {$path}"
        );
    }

    // ── Private — extraction ──────────────────────────────────────────────────

    /**
     * Run pdftotext (Poppler) via shell and return its stdout.
     * Returns an empty string if Poppler is not installed or returns nothing.
     */
    private function extractWithPoppler(string $path): string
    {
        $which = shell_exec('which pdftotext 2>/dev/null');
        if (empty(trim((string) $which))) {
            return '';
        }

        $escaped = escapeshellarg($path);
        $output  = shell_exec("pdftotext -raw {$escaped} - 2>/dev/null");

        Log::debug('QuoteTextExtractorService::extractWithPoppler', [
            'path'         => basename($path),
            'output_bytes' => is_string($output) ? strlen($output) : 'null',
            'first_200'    => is_string($output) ? substr(preg_replace('/\s+/', ' ', $output), 0, 200) : 'null',
        ]);

        return is_string($output) ? $output : '';
    }

    /**
     * Parse selectable text using Smalot\PdfParser.
     * Returns an empty string on any parse error.
     */
    private function extractWithSmalot(string $path): string
    {
        try {
            $pdf  = $this->parser->parseFile($path);
            $raw  = $pdf->getText();
            $text = str_replace(["\r\n", "\r"], "\n", $raw);
            $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

            $lines = array_map('trim', explode("\n", $text));
            $lines = array_filter(
                $lines,
                static fn (string $l): bool =>
                    mb_strlen($l) >= 3 && (bool) preg_match('/[a-zA-Z]/', $l),
            );

            return implode("\n", $lines);
        } catch (\Throwable $e) {
            Log::warning('QuoteTextExtractorService: Smalot parse exception', [
                'error' => $e->getMessage(),
                'path'  => basename($path),
            ]);

            return '';
        }
    }

    /**
     * OCR the PDF using Tesseract (plain-text output to stdout).
     *
     * Uses: tesseract <file> stdout -l eng
     *
     * Requires: tesseract-ocr + English language data installed.
     * For image-only PDFs Tesseract uses Leptonica to decode the embedded
     * raster images and OCR each page in turn.
     *
     * Returns an empty string if Tesseract is not installed or fails.
     */
    private function extractWithTesseract(string $path): string
    {
        $which = shell_exec('which tesseract 2>/dev/null');
        if (empty(trim((string) $which))) {
            Log::warning('QuoteTextExtractorService: Tesseract not found on PATH', [
                'path' => basename($path),
            ]);

            return '';
        }

        $escaped = escapeshellarg($path);
        $output  = shell_exec("tesseract {$escaped} stdout -l eng 2>/dev/null");

        if (! is_string($output)) {
            return '';
        }

        $text  = str_replace(["\r\n", "\r"], "\n", $output);
        $text  = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        $lines = array_map('trim', explode("\n", $text));
        $lines = array_filter(
            $lines,
            static fn (string $l): bool => mb_strlen($l) >= 3,
        );

        return implode("\n", $lines);
    }

    // ── Private — validation ──────────────────────────────────────────────────

    /**
     * Validate that the extracted text is readable and not binary garbage.
     *
     * Rules:
     *   1. Must be at least MIN_CHARS characters after trimming.
     *   2. Must contain at least one 4+ consecutive letter word.
     *   3. Non-printable character ratio must not exceed MAX_BINARY_RATIO.
     *      Allowed non-printable: \x09 (tab), \x0A (LF), \x0D (CR).
     */
    private function isValid(string $text): bool
    {
        $trimmed = trim($text);

        if (strlen($trimmed) < self::MIN_CHARS) {
            return false;
        }

        if (! preg_match('/[A-Za-z]{4,}/', $trimmed)) {
            return false;
        }

        $nonPrintable = (int) preg_match_all('/[^\x09\x0A\x0D\x20-\x7E]/', $trimmed);
        $ratio        = $nonPrintable / max(1, strlen($trimmed));

        if ($ratio > self::MAX_BINARY_RATIO) {
            return false;
        }

        return true;
    }

    // ── Private — output shaping ──────────────────────────────────────────────

    private function truncate(string $text): string
    {
        if (strlen($text) > self::MAX_CHARS) {
            $text = substr($text, 0, self::MAX_CHARS);
        }

        return $text;
    }
}
