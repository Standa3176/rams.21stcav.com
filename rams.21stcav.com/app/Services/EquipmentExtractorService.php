<?php

namespace App\Services;

/**
 * Scans extracted QuoteWerks text and identifies AV equipment items.
 * Returns a structured array used to enrich the Claude RAMS prompt.
 */
class EquipmentExtractorService
{
    // ── Category keyword patterns ─────────────────────────────────────────────
    // Each entry: 'Category Label' => [keywords that indicate this category]
    private const CATEGORIES = [
        'Display'          => ['display', 'monitor', 'screen', 'tv ', 'television', 'led panel',
                               'video wall', 'flat panel', 'commercial display', 'qb', 'qm', 'qe',
                               'ub', 'ur', 'bravia', 'bz', 'pro display'],
        'Projector'        => ['projector', 'projection', 'beamer', 'throw'],
        'Camera'           => ['camera', 'ptz', 'webcam', 'vision', 'rally cam', 'meetup',
                               'brio', 'hero', 'huddly', 'aver cam', 'yealink cam'],
        'Microphone'       => ['microphone', 'mic ', 'mxa', 'mxw', 'ceiling mic', 'table mic',
                               'boundary mic', 'gooseneck', 'lavalier', 'lapel'],
        'DSP / Amplifier'  => ['dsp', 'amplifier', 'amp ', 'tesira', 'voltera', 'nexia',
                               'qsc', 'biamp', 'crown', 'extron dmp', 'amps'],
        'Speaker'          => ['speaker', 'loudspeaker', 'subwoofer', 'ceiling speaker',
                               'pendant speaker', 'column speaker', 'evid', 'flexus'],
        'Video Conferencing' => ['video conferencing', 'vc unit', 'codec', 'rally bar',
                                  'rally plus', 'tap ip', 'meetup', 'yealink mvc', 'cisco room',
                                  'poly studio', 'logitech rally'],
        'Room Controller'  => ['touch panel', 'controller', 'control panel', 'room panel',
                               'booking panel', 'tap scheduler', 'scheduling', 'crestron',
                               'extron tlp', 'room touch', 'room control'],
        'Wireless Presentation' => ['wireless presentation', 'solstice', 'clickshare',
                                     'barco', 'mersive', 'airtame', 'wps'],
        'Switch / Network' => ['switch', 'poe switch', 'network switch', 'ethernet switch',
                                'cisco catalyst', 'unifi', 'managed switch', 'av over ip'],
        'HDBaseT / Extender' => ['hdbaset', 'extender', 'transmitter', 'receiver', 'tx ', 'rx ',
                                   'blustream', 'atlona', 'kramer', 'icron', 'kvx'],
        'Matrix / Switcher' => ['matrix', 'switcher', 'sw ', 'hdmi switch', 'av matrix',
                                  'video switcher', 'routing'],
        'Mount'            => ['mount', 'bracket', 'arm', 'trolley', 'stand', 'ceiling plate',
                               'floor stand', 'wall plate', 'tilting', 'fixed mount'],
        'Rack'             => ['rack', 'cabinet', 'enclosure', 'rack mount', '19"', 'rack unit',
                               '2u', '4u', '8u', '12u'],
        'Cable'            => ['cable', 'cabling', 'hdmi', 'cat6', 'cat5', 'dp cable',
                               'displayport', 'usb-c', 'fibre', 'speakon', 'xlr', 'multicore'],
    ];

    // ── Known AV brands for line-matching confidence ──────────────────────────
    private const BRANDS = [
        'sony', 'samsung', 'lg', 'nec', 'panasonic', 'sharp', 'philips',
        'logitech', 'poly', 'polycom', 'cisco', 'yealink', 'avocor',
        'shure', 'sennheiser', 'audio-technica', 'beyerdynamic',
        'biamp', 'qsc', 'crown', 'bss', 'symetrix', 'extron', 'crestron',
        'kramer', 'atlona', 'blustream', 'icron', 'lightware', 'wyrestorm',
        'mersive', 'barco', 'clickshare', 'airtame', 'solstice',
        'chief', 'peerless', 'vogels', 'unicol', 'avf', 'spectral',
        'blackmagic', 'aten', 'gefen', 'matrox', 'datapath', 'draper',
        'epson', 'optoma', 'benq', 'viewsonic', 'acer', 'maxell',
    ];

    /**
     * Extract a structured equipment list from raw quote text.
     *
     * @param  string  $text  Raw text extracted from the QuoteWerks PDF
     * @return array          Array of ['type', 'model', 'location'] items
     */
    public function extract(string $text): array
    {
        $lines     = $this->splitToLines($text);
        $equipment = [];
        $seen      = [];

        foreach ($lines as $line) {
            $lower = strtolower($line);

            // Skip lines that are clearly not equipment (headers, totals, etc.)
            if ($this->isNoise($lower)) {
                continue;
            }

            $category = $this->detectCategory($lower);
            if ($category === null) {
                continue;
            }

            // Clean up the model name
            $model = $this->cleanModel($line);
            if (strlen($model) < 4) {
                continue;
            }

            // Deduplicate by normalised model string
            $key = $category . '|' . strtolower($model);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $equipment[] = [
                'type'     => $category,
                'model'    => $model,
                'location' => $this->detectLocation($line, $text),
            ];
        }

        return $equipment;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function splitToLines(string $text): array
    {
        // Split on newlines; also split on common QuoteWerks column separators
        $lines = preg_split('/\r?\n/', $text);
        return array_filter(array_map('trim', $lines), fn($l) => strlen($l) > 3);
    }

    private function isNoise(string $lower): bool
    {
        $noisePatterns = [
            '/^\d+[\.,]\d+$/',          // pure numbers
            '/^(total|subtotal|vat|tax|discount|ex vat|inc vat|net|gross)/i',
            '/^(quote|reference|date|page|revision|prepared by|issued by)/i',
            '/^(terms|conditions|notes?|comments?|description)\b/i',
            '/^[£$€\d\s\.,\-\/]+$/',    // lines that are only prices/numbers
        ];

        foreach ($noisePatterns as $pattern) {
            if (preg_match($pattern, $lower)) {
                return true;
            }
        }

        return strlen($lower) > 300; // Skip very long paragraph-style lines
    }

    private function detectCategory(string $lower): ?string
    {
        foreach (self::CATEGORIES as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($lower, $keyword)) {
                    return $category;
                }
            }
        }

        // Also match if line contains a known brand + looks like a product line
        foreach (self::BRANDS as $brand) {
            if (str_contains($lower, $brand) && preg_match('/[a-z]{2,}\d|\d[a-z]{2,}/i', $lower)) {
                return 'AV Equipment'; // Generic fallback for branded but uncategorised items
            }
        }

        return null;
    }

    private function cleanModel(string $line): string
    {
        // Strip leading quantity patterns like "1x ", "2 x ", "qty 3 "
        $line = preg_replace('/^\d+\s*[xX×]\s*/', '', $line);
        $line = preg_replace('/^qty\s*\d+\s*/i', '', $line);

        // Strip trailing price patterns like "£1,234.00" or "1234.00"
        $line = preg_replace('/\s+[£$€]?\s*[\d,]+\.\d{2}\s*$/', '', $line);

        // Strip part numbers that are purely alphanumeric codes at start
        // e.g. "ABC123 Samsung 65 inch display" → keep from brand onwards
        return trim($line);
    }

    private function detectLocation(string $line, string $fullText): string
    {
        // Common room/area keywords to look for near the equipment line
        $roomKeywords = [
            'board', 'meeting', 'conference', 'training', 'lecture', 'classroom',
            'reception', 'lobby', 'canteen', 'breakout', 'office', 'suite',
            'room', 'hall', 'auditorium', 'theatre', 'studio', 'lab',
        ];

        $lower = strtolower($line);
        foreach ($roomKeywords as $kw) {
            if (str_contains($lower, $kw)) {
                // Extract a short location string around the keyword
                if (preg_match('/([a-z\s]*' . preg_quote($kw, '/') . '[a-z\s\d]*)/i', $line, $m)) {
                    $loc = trim($m[1]);
                    if (strlen($loc) < 60) {
                        return ucwords($loc);
                    }
                }
            }
        }

        return '';
    }
}
