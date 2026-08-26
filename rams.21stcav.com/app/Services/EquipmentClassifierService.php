<?php

namespace App\Services;

/**
 * Classifies a list of AV equipment items into work activities using
 * local keyword matching — no AI required.
 *
 * Input:  array of ['qty' => int, 'description' => string, 'location' => string]
 * Output: [
 *   'activities'        => string[],   // activity keys detected
 *   'categories'        => array<string,int>,  // activity key → total qty
 *   'summary'           => string,     // human-readable summary
 *   'heavy_items'       => string[],   // descriptions of heavy equipment
 *   'drilling_required' => bool,       // whether drilling/fixing is needed
 * ]
 */
class EquipmentClassifierService
{
    // ── Activity keyword maps ─────────────────────────────────────────────────

    private const ACTIVITY_MAP = [
        'display_installation' => [
            'label'    => 'Display & Screen Installation',
            'keywords' => [
                'display', 'monitor', 'screen', 'television', 'tv', 'panel',
                'lcd', 'led', 'oled', 'qled', 'touchscreen', 'interactive flat',
                'avocor', 'clevertouch', 'newline', 'smart board', 'smartboard',
                'video wall', 'videowall', 'samsung', 'lg', 'sony', 'nec', 'sharp',
            ],
        ],
        'ceiling_works' => [
            'label'    => 'Ceiling Works & Overhead Mounting',
            'keywords' => [
                'ceiling', 'projector', 'ceiling speaker', 'ceiling mount',
                'recessed', 'pendant', 'in-ceiling', 'suspended', 'overhead',
                'flush mount', 'drop', 'canopy', 'hanging',
            ],
        ],
        'av_rack' => [
            'label'    => 'AV Rack Build & Equipment Installation',
            'keywords' => [
                'rack', 'amplifier', ' amp ', 'dsp', 'processor', 'switcher',
                'matrix', 'scaler', 'transmitter', 'receiver', 'extender',
                'splitter', 'hdbaset', 'avover', 'encoder', 'decoder',
                'blustream', 'atlona', 'kramer', 'wyrestorm', 'lightware',
                'patch panel', 'patch bay', 'power conditioner', 'ups',
                'biamp', 'qsc', 'q-sys', 'bose', 'crown',
            ],
        ],
        'audio_installation' => [
            'label'    => 'Audio System Installation',
            'keywords' => [
                'microphone', ' mic ', 'speaker', 'subwoofer', 'audio',
                'sound', 'pa system', 'public address', 'hearing loop',
                'induction loop', 'shure', 'sennheiser', 'audio-technica',
                'dante', 'sound bar', 'soundbar', 'tesira',
            ],
        ],
        'video_conferencing' => [
            'label'    => 'Video Conferencing Installation',
            'keywords' => [
                'camera', 'webcam', 'teams', 'zoom', 'codec', 'conferencing',
                'conference camera', 'ptz', 'logitech', 'yealink', 'poly',
                'cisco', 'jabra', 'rally bar', 'rally', 'tap', 'teams bar',
                'google meet', 'microsoft teams', 'room bar', 'neat',
            ],
        ],
        'control_systems' => [
            'label'    => 'Control System Installation & Programming',
            'keywords' => [
                'control system', 'crestron', 'extron', 'amx', 'control panel',
                'touch panel', 'button panel', 'keypad', 'control processor',
                'room control', 'automation', 'avia pro', 'vc-4',
            ],
        ],
        'cable_management' => [
            'label'    => 'Cable Management & Routing',
            'keywords' => [
                'cable', 'trunking', 'conduit', 'containment', 'cable tray',
                'duct', 'raceway', 'wire management', 'cable tidies',
                'floor box', 'dado', 'surface mount', 'floor trunking',
            ],
        ],
        'structured_cabling' => [
            'label'    => 'Structured Cabling',
            'keywords' => [
                'hdmi', 'cat5', 'cat6', 'cat7', 'cat8', 'fibre', 'fiber',
                'ethernet', 'network cable', 'patch', 'dp cable',
                'displayport', 'usb-c', 'optical cable', 'sdi',
            ],
        ],
    ];

    // ── Heavy item indicators ─────────────────────────────────────────────────

    private const HEAVY_KEYWORDS = [
        'video wall', 'videowall', '85"', '86"', '98"', '100"', '110"',
        'pa system', 'floor box', 'lectern', 'podium', 'kiosk', 'totem',
        '85 inch', '86 inch', '98 inch', 'large format', 'digital signage',
    ];

    // ── Drilling / fixing indicators ─────────────────────────────────────────

    private const MOUNT_KEYWORDS = [
        'mount', 'bracket', 'fixing', 'wall plate', 'back box', 'floor box',
        'recessed', 'surface box', 'wall mount', 'ceiling mount', 'tilt mount',
        'articulating', 'chief', 'peerless', 'vogels', 'sanus',
    ];

    // =========================================================================
    // PUBLIC ENTRY POINT
    // =========================================================================

    /**
     * Classify equipment items into work activities.
     *
     * @param  array  $items  Each item: ['qty' => int, 'description' => string, ...]
     * @return array
     */
    public function classify(array $items): array
    {
        $matched    = [];
        $categories = [];
        $heavyItems = [];
        $drilling   = false;

        foreach ($items as $item) {
            $desc  = strtolower((string) ($item['description'] ?? ''));
            $qty   = max(1, (int) ($item['qty'] ?? 1));
            $raw   = (string) ($item['description'] ?? '');

            // Match activities
            foreach (self::ACTIVITY_MAP as $key => $activity) {
                foreach ($activity['keywords'] as $kw) {
                    if (str_contains($desc, $kw)) {
                        $matched[$key]      = true;
                        $categories[$key]   = ($categories[$key] ?? 0) + $qty;
                        break;
                    }
                }
            }

            // Flag heavy items
            foreach (self::HEAVY_KEYWORDS as $kw) {
                if (str_contains($desc, $kw)) {
                    $heavyItems[] = $raw;
                    break;
                }
            }

            // Flag drilling required
            if (! $drilling) {
                foreach (self::MOUNT_KEYWORDS as $kw) {
                    if (str_contains($desc, $kw)) {
                        $drilling = true;
                        break;
                    }
                }
            }
        }

        // Commissioning is always required when any AV activity is detected
        if (! empty($matched)) {
            $matched['commissioning'] = true;
        }

        $activities = array_keys($matched);

        return [
            'activities'        => $activities,
            'categories'        => $categories,
            'summary'           => $this->buildSummary($categories),
            'heavy_items'       => array_values(array_unique($heavyItems)),
            'drilling_required' => $drilling,
        ];
    }

    // =========================================================================
    // PUBLIC HELPERS
    // =========================================================================

    /**
     * Return the human-readable label for a known activity key.
     * Falls back to a title-cased version of the key when unknown.
     */
    public function activityLabel(string $key): string
    {
        return self::ACTIVITY_MAP[$key]['label']
            ?? ucwords(str_replace('_', ' ', $key));
    }

    /**
     * Phase 26 Plan 07 (HAZ-02 gap closure): whether the given free-text
     * narrative indicates drilling/fixing work, reading the SAME mount/fixing
     * keyword constant classify()'s per-item drilling loop already
     * matches — a single source of truth, not a second divergent keyword
     * list. Used by RamsBuilderService::runFromReview() to derive a real
     * drilling signal from the reviewed scope narrative (works summary,
     * scope of works, works overview, equipment descriptions), instead of
     * the hardcoded `false` that path used to forward.
     *
     * Fail-safe by construction: no keyword hit never becomes a positive.
     */
    public function textIndicatesDrilling(string $text): bool
    {
        $lower = strtolower($text);

        foreach (self::MOUNT_KEYWORDS as $kw) {
            if (str_contains($lower, $kw)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Phase 27 Plan 02 (RULE-03 decommission-scope scan): whether the given
     * free-text narrative matches the SAME display/screen keyword vocabulary
     * classify() already uses for its 'display_installation' activity — a
     * single source of truth, mirroring textIndicatesDrilling()'s established
     * shape for MOUNT_KEYWORDS rather than a second, divergent keyword list.
     * Used by RamsComplianceUpgradeService::deriveMaterialHandling() to
     * detect a display item within the scope_items.decommission bucket, so
     * the RULE-03 wall-mount-removal statement is appended only for displays
     * (house-rules.md:18-19 — non-display strip-out items keep their own
     * handling rules).
     *
     * Fail-safe by construction: no keyword hit never becomes a positive.
     */
    public function textIndicatesDisplay(string $text): bool
    {
        $lower = strtolower($text);

        foreach (self::ACTIVITY_MAP['display_installation']['keywords'] as $kw) {
            if (str_contains($lower, $kw)) {
                return true;
            }
        }

        return false;
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function buildSummary(array $categories): string
    {
        if (empty($categories)) {
            return 'AV installation works';
        }

        $parts = [];
        foreach ($categories as $key => $count) {
            $label   = self::ACTIVITY_MAP[$key]['label'] ?? ucwords(str_replace('_', ' ', $key));
            $parts[] = $count . 'x ' . $label;
        }

        return implode('; ', $parts);
    }
}
