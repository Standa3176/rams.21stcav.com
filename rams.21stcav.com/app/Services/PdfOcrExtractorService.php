<?php

namespace App\Services;

use RuntimeException;

/**
 * Extracts text from a PDF using Tesseract OCR as a fallback for scanned or
 * image-only PDFs that yield no selectable text.
 *
 * Requirements:
 *   - Tesseract 4+ installed and on $PATH  (apt install tesseract-ocr)
 *   - Poppler utils for PDF→image conversion (apt install poppler-utils)
 *
 * Usage:
 *   Injected automatically by PdfTextExtractorService when parsed text is
 *   too short to be useful (< 200 characters).
 */
class PdfOcrExtractorService
{
    /**
     * Run Tesseract OCR on the given PDF and return the extracted text.
     *
     * Tesseract is invoked as:
     *   tesseract <input.pdf> stdout pdf
     *
     * The `pdf` config flag tells Tesseract to accept PDF input directly
     * (requires the pdfimages/pdftotext pipeline via Leptonica + Poppler).
     *
     * @param  string $path  Absolute filesystem path to the PDF file.
     * @return string        OCR-extracted plain text, normalised.
     *
     * @throws RuntimeException If Tesseract is not available or returns an error.
     */
    public function extract(string $path): string
    {
        $escapedPath = escapeshellarg($path);

        $command = "tesseract {$escapedPath} stdout pdf 2>/dev/null";

        $output     = [];
        $returnCode = 0;

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new RuntimeException(
                "Tesseract OCR failed for file \"{$path}\" (exit code {$returnCode})."
            );
        }

        $raw = implode("\n", $output);

        // Normalise line endings
        $text = str_replace(["\r\n", "\r"], "\n", $raw);

        // Collapse runs of blank lines
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        // Trim each line; drop very short lines
        $lines = array_map('trim', explode("\n", $text));
        $lines = array_filter($lines, static fn (string $l): bool => mb_strlen($l) >= 3);

        return implode("\n", $lines);
    }
}
