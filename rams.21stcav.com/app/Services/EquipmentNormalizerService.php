<?php

namespace App\Services;

/**
 * Normalizes equipment line strings before AI processing.
 *
 * Applies keyword-based manufacturer prefix rules so that variant spellings
 * of the same product resolve to a consistent canonical form, reducing AI
 * token waste and improving structured-extraction accuracy.
 *
 * No AI usage. No external dependencies.
 *
 * Example
 * -------
 * Input:  ["1 Rally Bar Graphite", "2 RallyBar", "1 rally bar mini"]
 * Output: ["1 Logitech Rally Bar Graphite", "2 Logitech Rally Bar", "1 Logitech Rally Bar Mini"]
 */
class EquipmentNormalizerService
{
    /**
     * Keyword → canonical manufacturer prefix mapping.
     *
     * Keys are lowercase substrings to match anywhere in the line.
     * Values are the canonical manufacturer name to prepend (if not already present).
     *
     * Rules are evaluated in order; the first match wins.
     */
    private const MANUFACTURER_RULES = [
        // Logitech
        'rally bar'         => 'Logitech',
        'rallybar'          => 'Logitech',
        'rally cam'         => 'Logitech',
        'meetup'            => 'Logitech',
        'logitech'          => 'Logitech',

        // Samsung
        'samsung'           => 'Samsung',
        'qb75'              => 'Samsung',
        'qb65'              => 'Samsung',
        'qb55'              => 'Samsung',
        'qm75'              => 'Samsung',
        'qm65'              => 'Samsung',

        // Cisco
        'cisco'             => 'Cisco',
        'webex'             => 'Cisco',
        'room kit'          => 'Cisco',
        'room bar'          => 'Cisco',

        // Shure
        'shure'             => 'Shure',
        'mxa'               => 'Shure',
        'mxcw'              => 'Shure',
        'microflex'         => 'Shure',

        // QSC
        'qsc'               => 'QSC',
        'q-sys'             => 'QSC',
        'qsys'              => 'QSC',
        'core 110'          => 'QSC',
        'core 510'          => 'QSC',

        // Biamp
        'biamp'             => 'Biamp',
        'tesira'            => 'Biamp',
        'devio'             => 'Biamp',

        // Chief / Legrand mounts
        'chief'             => 'Chief',
        'lsm'               => 'Chief',
        'kontour'           => 'Chief',

        // Extron
        'extron'            => 'Extron',
        'dtp'               => 'Extron',
        'sw'                => null,    // too ambiguous — skip

        // Crestron
        'crestron'          => 'Crestron',
        'dm-'               => 'Crestron',
        'dmps'              => 'Crestron',
        'tsw-'              => 'Crestron',

        // Sony
        'sony'              => 'Sony',
        'srg-'              => 'Sony',
        'bravia'            => 'Sony',

        // LG
        'lg '               => 'LG',

        // NEC / Sharp
        'nec'               => 'NEC',
        'sharp'             => 'Sharp',
        'pn-'               => 'Sharp',
    ];

    /**
     * Normalize an array of raw equipment line strings.
     *
     * Each line is expected to begin with a quantity, e.g.:
     *   "1 Rally Bar Graphite"
     *
     * The method:
     *   1. Separates the leading quantity from the description.
     *   2. Applies manufacturer prefix rules if the manufacturer is absent.
     *   3. Title-cases the description.
     *   4. Returns the cleaned line.
     *
     * Lines that do not start with a digit are passed through unchanged.
     *
     * @param  string[] $lines
     * @return string[]
     */
    public function normalize(array $lines): array
    {
        return array_map([$this, 'normalizeLine'], $lines);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function normalizeLine(string $line): string
    {
        $line = trim($line);

        // Only process lines that start with a quantity
        if (! preg_match('/^(\d+)\s+(.+)$/u', $line, $m)) {
            return $line;
        }

        [, $qty, $description] = $m;

        $description = $this->applyManufacturerPrefix($description);
        $description = $this->titleCase($description);

        return $qty . ' ' . $description;
    }

    /**
     * Prepend the canonical manufacturer name if a matching keyword is found
     * and the manufacturer is not already present at the start of the string.
     */
    private function applyManufacturerPrefix(string $description): string
    {
        $lower = mb_strtolower($description);

        foreach (self::MANUFACTURER_RULES as $keyword => $manufacturer) {
            if ($manufacturer === null) {
                continue;
            }

            if (str_contains($lower, $keyword)) {
                // Skip if the manufacturer name is already the first word
                if (stripos($description, $manufacturer) === 0) {
                    return $description;
                }

                return $manufacturer . ' ' . $description;
            }
        }

        return $description;
    }

    /**
     * Convert a string to title case, preserving known uppercase acronyms.
     */
    private function titleCase(string $text): string
    {
        $preserve = ['AV', 'HDMI', 'USB', 'CAT6', 'CAT5', 'SDI', 'UHD', 'HD', 'IP', 'PoE', 'SKU'];

        $titled = mb_convert_case($text, MB_CASE_TITLE, 'UTF-8');

        // Restore any preserved acronyms that title-case may have lowercased
        foreach ($preserve as $acronym) {
            $titled = preg_replace(
                '/\b' . preg_quote(mb_strtolower($acronym), '/') . '\b/iu',
                $acronym,
                $titled,
            );
        }

        return $titled;
    }
}
