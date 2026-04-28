<?php

namespace App\Services;

/**
 * Phase 3 of the Tier 1 O&M Manual upgrade — equipment normaliser & taxonomy.
 *
 * Two responsibilities:
 *
 *   1. Clean descriptions:
 *      - Strip part-code stacks (e.g. Unicol mount kits like
 *        "Goal Post Floor to Ceiling Mounts 2 X CP1 - 2 X FP1 - 4 X 2000C
 *        - 1 X FCS4 - FCGSH K") down to a human-readable label.
 *      - Convert "98in" to "98″"; title-case shouty all-caps lines.
 *      - Apply specific overrides for known products that ship with
 *        misleading descriptions (Shure BLX, Biamp TCM-X, etc.).
 *
 *   2. Classify each item with three machine-readable flags:
 *      - category      : 'display' | 'camera' | 'dsp' | 'microphone' |
 *                        'speaker' | 'network' | 'mount' | 'other'
 *      - is_networked  : bool — true when device occupies an IP/VLAN slot
 *      - is_wireless   : bool — true for RF / wireless products
 *
 * Known-error fixes baked in:
 *   - Shure BLX (analogue wireless) → is_networked = false
 *   - Biamp TCM-X (wired Tesira ceiling mic) → is_wireless = false
 *
 * Apply per-item via normalise(); list-level deduplication stays in the
 * existing OmManualGeneratorService::mergeEquipmentRows().
 */
class EquipmentNormaliserService
{
    /**
     * Specific high-confidence description overrides. Order matters — first
     * match wins. Keys are PCRE patterns matched case-insensitively against
     * the trimmed description.
     */
    private const DESCRIPTION_OVERRIDES = [
        '/^\s*98in\s+24\/7\s+iiyama\s+android\s+display\s*$/iu'
            => '98″ iiyama 24/7 Commercial Display',
        '/^\s*Goal\s+Post\s+Floor\s+to\s+Ceiling\s+Mounts.*$/iu'
            => 'Goal Post Floor-to-Ceiling Display Mount',
        '/^\s*Biamp\s+Parle.*TCM[-\s]?X\s+Ceiling\s+Microphone.*$/iu'
            => 'Biamp Parle TCM-X Ceiling Microphone (wired)',
        '/^\s*Shure\s+Dual\s+Channel\s+Wireless\s+System.*$/iu'
            => 'Shure BLX Dual-Channel Analogue Wireless Microphone System',
        '/^\s*Logitech\s+Rally\s+Bar\s*$/iu'
            => 'Logitech Rally Bar Video Conferencing System',
        '/^\s*Sony\s+85\D+FW85BZ30L\s+Commercial\s+Display\s*$/iu'
            => 'Sony 85″ FW85BZ30L Commercial Display',
        '/^\s*Motorised\s+Height\s+Adjustable\s+Flat\s+Screen\s+Trolley\s*$/iu'
            => 'Motorised Display Trolley (height-adjustable)',
        '/^\s*CAM550\s+4K\s+Dual\s+Lens\s+PTZ\s+Conferencing\s+Camera\s*$/iu'
            => 'CAM550 4K Dual-Lens PTZ Conferencing Camera',
        '/^\s*Logitech\s+Rally\s+Conference\s+Camera\s*$/iu'
            => 'Logitech Rally PTZ Conference Camera',
        '/^\s*Biamp\s+TesiraFORTÉ\s+X\s+800.*$/iu'
            => 'Biamp TesiraFORTÉ X 800 DSP',
        '/^\s*Biamp\s+PoE\s+AVB\/USB\s+expander\s*$/iu'
            => 'Biamp PoE AVB / USB Expander',
        // Phase 8 fix — keep the Cat Coupler row distinguishable so the two
        // Logitech 202 entries don't collapse into duplicate-looking rows.
        '/^\s*Logitech\s+Rally\s+Mic\s+Pod\s+Cat\s+Coupler\s*$/iu'
            => 'Logitech Rally Mic Pod Cable Coupler',
        '/^\s*Logitech\s+Rally\s+Mic\s+Pod\s*$/iu'
            => 'Logitech Rally Mic Pod',
    ];

    /**
     * Passive accessories — share keywords with active devices but should
     * never be classified as networked or as cameras / displays / DSPs.
     */
    private const PASSIVE_PART_KEYWORDS = [
        'shelf', 'splitter', 'adapter', 'mount', 'joiner', 'coupler',
        'bracket', 'rail', 'plate', 'tilt', 'extension lead',
        'unistrut', 'lindapter', 'washer', 'spring nut', 'threaded rod',
        'mic pod', 'tv mount', 'cat coupler',
        // Phase 8 fix — passive display furniture, not a networked display.
        'trolley', 'cart', 'stand', 'flat screen trolley',
    ];

    // ── Public API ──────────────────────────────────────────────────────────

    /**
     * Normalise a single equipment item — clean description AND attach
     * classification metadata. Mutates and returns the array.
     *
     * @param array $item Equipment array as produced by OmManualGeneratorService::mapContextEquipmentItem.
     * @return array Same shape with cleaned 'description' / 'name' plus
     *               'category', 'is_networked', 'is_wireless' keys.
     */
    public function normalise(array $item): array
    {
        $rawDescription = (string) ($item['description'] ?? $item['name'] ?? '');
        $cleaned        = $this->cleanDescription($rawDescription);

        $item['description'] = $cleaned;
        if (empty($item['name'])) {
            $item['name'] = $cleaned;
        }

        $taxonomy = $this->classify($item);

        $item['category']     = $taxonomy['category'];
        $item['is_networked'] = $taxonomy['is_networked'];
        $item['is_wireless']  = $taxonomy['is_wireless'];

        return $item;
    }

    public function cleanDescription(string $raw): string
    {
        $desc = trim($raw);
        if ($desc === '') {
            return '';
        }

        // 1. High-confidence overrides — return verbatim if matched.
        foreach (self::DESCRIPTION_OVERRIDES as $pattern => $replacement) {
            if (preg_match($pattern, $desc) === 1) {
                return $replacement;
            }
        }

        // 2. Strip Unicol-style trailing kit code stacks ("X X X - Y Y Y - ...").
        $desc = preg_replace(
            '/\s+\d+\s*[Xx]\s*[A-Z0-9\-\/]+(\s*-\s*\d+\s*[Xx]\s*[A-Z0-9\-\/]+)+(\s+[A-Z]{1,4})?\s*$/u',
            '',
            $desc
        ) ?? $desc;

        // 3. Inch convention: "98in" → "98″".
        // Phase 8 fix — leading \b prevents matches inside model names like
        // "ANI4IN" (was getting transformed to "ANI4″"). Now only standalone
        // tokens like "98in" or "55 in" convert to inch glyph.
        $desc = preg_replace('/\b(\d+)\s*in\b/i', '$1″', $desc) ?? $desc;

        // 4. Title-case shouty all-caps lines (preserves common abbreviations).
        if (strlen($desc) > 8 && preg_match('/^[A-Z0-9\s\-\/\.\,\(\)\"\'″]+$/u', $desc) === 1) {
            $desc = ucwords(strtolower($desc));
            $abbreviations = [
                'av','rgb','usb','hdmi','ip','lan','cat','cm','dsp','ptz',
                'hd','4k','uhd','pa','nuc','tap','pasma','ipaf','dvi','sdi',
                'fp1','cp1','fcs4','pzx9','tcm','tcm-x','blx','cdm',
            ];
            $desc = preg_replace_callback(
                '/\b(' . implode('|', array_map('preg_quote', $abbreviations)) . ')\b/i',
                static fn ($m) => strtoupper($m[1]),
                $desc
            ) ?? $desc;
        }

        // 5. Collapse repeated whitespace, trim, normalise dashes.
        $desc = preg_replace('/\s+/u', ' ', trim($desc)) ?? $desc;

        return $desc;
    }

    /**
     * Classify an item into category + networked + wireless flags.
     *
     * @return array{category:string, is_networked:bool, is_wireless:bool}
     */
    public function classify(array $item): array
    {
        $text = strtolower(trim(
            ($item['description']  ?? '') . ' ' .
            ($item['name']         ?? '') . ' ' .
            ($item['model']        ?? '') . ' ' .
            ($item['part_no']      ?? '') . ' ' .
            ($item['manufacturer'] ?? '')
        ));

        $isPassive = $this->matchAny($text, self::PASSIVE_PART_KEYWORDS);

        // Known-error fix: Shure BLX = ANALOGUE wireless mic — not networked.
        if (preg_match('/\bblx\d*[a-z0-9\/\-]*\b/i', $text) === 1) {
            return [
                'category'     => 'microphone',
                'is_networked' => false,
                'is_wireless'  => true,
            ];
        }

        // Known-error fix: Biamp Parle TCM-X = WIRED Tesira ceiling mic.
        if ($this->matchAnyWord($text, ['tcm-x', 'parle'])) {
            return [
                'category'     => 'microphone',
                'is_networked' => true,
                'is_wireless'  => false,
            ];
        }

        if ($isPassive) {
            return [
                'category'     => 'mount',
                'is_networked' => false,
                'is_wireless'  => false,
            ];
        }

        if ($this->matchAnyWord($text, [
            'display', 'screen', 'monitor', 'iiyama', 'samsung', 'sony',
            'commercial display', 'qm', 'qe', 'fw85bz30l', 'lh9875uhs',
        ])) {
            return [
                'category'     => 'display',
                'is_networked' => true,                    // commercial displays are LAN-managed
                'is_wireless'  => false,
            ];
        }

        if ($this->matchAnyWord($text, [
            'codec', 'room kit', 'rally bar', 'rally camera', 'cam550',
            'ptz', 'navigator', 'camera',
        ])) {
            return [
                'category'     => 'camera',
                'is_networked' => true,
                'is_wireless'  => false,
            ];
        }

        if ($this->matchAnyWord($text, [
            'dsp', 'tesira', 'tesiraforte', 'q-sys', 'qsys', 'forte',
        ])) {
            return [
                'category'     => 'dsp',
                'is_networked' => true,
                'is_wireless'  => false,
            ];
        }

        if ($this->matchAnyWord($text, ['microphone', 'mic'])) {
            $wireless = $this->matchAnyWord($text, [
                'wireless', 'rf', 'transmitter', 'handheld', 'lapel', 'lavalier',
            ]);
            return [
                'category'     => 'microphone',
                'is_networked' => ! $wireless,             // analogue wireless is not networked
                'is_wireless'  => $wireless,
            ];
        }

        if ($this->matchAnyWord($text, ['amplifier', 'amp', 'speaker'])) {
            return [
                'category'     => 'speaker',
                'is_networked' => $this->matchAnyWord(
                    $text,
                    ['lea', 'tesira', 'q-sys', 'qsys', 'powersoft']
                ),
                'is_wireless'  => false,
            ];
        }

        if ($this->matchAnyWord($text, [
            'switch', 'netgear', 'network interface', 'audio network',
            'ani4in', 'shuani4in',
        ])) {
            return [
                'category'     => 'network',
                'is_networked' => true,
                'is_wireless'  => false,
            ];
        }

        return [
            'category'     => 'other',
            'is_networked' => false,
            'is_wireless'  => false,
        ];
    }

    // ── Internals ───────────────────────────────────────────────────────────

    private function matchAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $n) {
            if ($n !== '' && str_contains($haystack, $n)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Word-boundary match — avoids false positives like "lea" inside "lead".
     */
    private function matchAnyWord(string $haystack, array $needles): bool
    {
        foreach ($needles as $n) {
            if ($n === '') {
                continue;
            }
            if (preg_match('/\b' . preg_quote($n, '/') . '\b/iu', $haystack) === 1) {
                return true;
            }
        }
        return false;
    }
}
