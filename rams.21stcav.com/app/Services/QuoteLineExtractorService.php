<?php

namespace App\Services;

/**
 * Extracts equipment line items from raw quote text.
 *
 * Filters lines that begin with a quantity (integer) followed by whitespace,
 * discarding address blocks, totals, page headers, and other noise.
 *
 * No AI usage. No external dependencies.
 */
class QuoteLineExtractorService
{
    /**
     * Return only lines that start with a quantity, e.g.:
     *   "1 Logitech Rally Bar Graphite"
     *   "2 Chief Mount LSM1U"
     *
     * @param  string   $text  Raw text from PdfTextExtractorService.
     * @return string[]        Array of matched line strings (re-indexed).
     */
    public function extractEquipmentLines(string $text): array
    {
        $lines   = explode("\n", $text);
        $matched = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if (! preg_match('/^\d+\s+\S.*/', $line)) {
                continue;
            }

            // Skip page-counter lines: "1 of 6", "2 of 6", "255 of 255", etc.
            if (preg_match('/^\d+\s+of\s+\d+\s*$/i', $line)) {
                continue;
            }

            // Skip delivery-address fragments that begin with a building/street number.
            // pdftotext -raw interleaves the delivery address with equipment lines:
            // "255 High Street, Guildford" → 255 looks like a qty, rest is "equipment".
            if (preg_match('/\b(?:street|road|avenue|lane|close|way|drive|place|court|'
                . 'terrace|gardens?|green|hill|park|square|row|walk|crescent|'
                . 'grove|mews|boulevard)\b/i', $line)) {
                continue;
            }

            // Skip lines containing a UK postcode pattern (e.g. "255 GU1 3BS Surrey").
            if (preg_match('/\b[A-Z]{1,2}[0-9][0-9A-Z]?\s+[0-9][A-Z]{2}\b/i', $line)) {
                continue;
            }

            $matched[] = $line;
        }

        return array_values($matched);
    }
}
