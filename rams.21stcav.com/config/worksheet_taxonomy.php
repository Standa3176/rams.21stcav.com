<?php

/**
 * Canonical worksheet taxonomy + classification rules.
 *
 * Used by App\Services\Worksheet\WorksheetClassifier. Tiered priority:
 *   T1 sku_map              — exact part_no / SKU → category
 *   T2 manufacturer_rules   — manufacturer + product-family keyword pair
 *   T3 keyword_rules        — description-keyword heuristics
 *   T4 context_rules        — warranty / mount / existing-reuse inheritance
 *   T5 (none)               — unclassified (never renders as "Other Hardware")
 *
 * Six canonical categories. If an internal value is added, also add its label
 * in `categories` and its position in `category_order`.
 */
return [

    // ─── Canonical taxonomy ──────────────────────────────────────────────────
    'categories' => [
        'display'             => 'Display',
        'video_conferencing'  => 'Video Conferencing',
        'audio'               => 'Audio',
        'control'             => 'Control & Automation',
        'rack'                => 'Rack & Infrastructure',
        'network'             => 'Network',
    ],

    // Fixed display order for room-summary strings and DOCX grouping.
    'category_order' => [
        'display',
        'video_conferencing',
        'audio',
        'control',
        'rack',
        'network',
    ],

    // Internal sentinels — never rendered as category labels.
    'sentinels' => [
        'unclassified'      => 'Unclassified',   // internal QA only — flagged to warnings panel
        'existing_unknown'  => 'Client-supplied existing equipment',
        'warranty_service'  => 'Service / warranty',
        'mount_accessory'   => 'Mounting accessory',
    ],

    // ─── Tier 1: Exact SKU map (part_no → category) ──────────────────────────
    // Highest-confidence. Add known SKUs here. Keys are case-insensitive —
    // the classifier upper-cases before lookup.
    'sku_map' => [
        // Display mounts (Chief, Unicol, Vogel's, Peerless)
        'CH-MTM1U'         => 'display',
        'XSM1U'            => 'display',

        // Extron control / sensors
        '60-1705-03'       => 'control',

        // Q-SYS audio DSP + accessories
        '16207'            => 'audio', // Q-SYS Core 8 Flex
        '12254'            => 'audio', // Q-SYS AD-P4TB pendant

        // Netgear managed switches
        'GSM4230PX'        => 'network',

        // Keystone rack infrastructure
        '12752'            => 'rack',  // 24-port patch panel

        // Shure wireless audio
        'MXWAPXD2UK'       => 'audio',
        'MXW2X/SM86'       => 'audio',
        'MXW1X/O'          => 'audio',
        'UL4B/C-MTQG-A'    => 'audio',

        // LEA amps
        '19351'            => 'audio', // LEA CS64
    ],

    // ─── Tier 2: Manufacturer + product-family rules ─────────────────────────
    // Array of rule objects. First match wins. Evaluated in declaration order.
    // Each rule: {manufacturer: [...], keywords: [...], category: '...'}
    'manufacturer_rules' => [
        // ── Displays (flat panel / projector OEMs) ───────────────────────────
        ['manufacturer' => ['samsung', 'lg', 'sony', 'philips', 'nec', 'sharp', 'panasonic', 'hisense'],
         'keywords'     => ['display', 'screen', 'monitor', 'tv', 'uhd', '4k', 'oled', 'qled', 'qm', 'qn', 'qb', 'uh', 'um'],
         'category'     => 'display'],
        ['manufacturer' => ['epson', 'benq', 'optoma', 'barco', 'christie', 'panasonic'],
         'keywords'     => ['projector', 'beamer', 'lcos', 'dlp'],
         'category'     => 'display'],

        // ── Video Conferencing ───────────────────────────────────────────────
        ['manufacturer' => ['cisco', 'poly', 'polycom', 'logitech', 'yealink', 'neat', 'huddly', 'aver'],
         'keywords'     => ['codec', 'room kit', 'rally', 'studio', 'bar', 'meetup', 'mx ', 'webex', 'x30', 'x50', 'x70', 'x90'],
         'category'     => 'video_conferencing'],

        // ── Audio: Microphones ───────────────────────────────────────────────
        ['manufacturer' => ['shure', 'sennheiser', 'audio-technica', 'audix', 'rode', 'clearone'],
         'keywords'     => ['microphone', 'mic', 'mxw', 'mxa', 'lavalier', 'bodypack', 'handheld', 'gooseneck', 'ceiling array'],
         'category'     => 'audio'],

        // ── Audio: DSP / Amplifiers / Loudspeakers ───────────────────────────
        ['manufacturer' => ['q-sys', 'qsys', 'qsc', 'biamp', 'lea', 'crown', 'bss', 'symetrix', 'bose', 'genelec', 'tannoy', 'jbl'],
         'keywords'     => ['core ', 'amp', 'dsp', 'amplifier', 'processor', 'speaker', 'loudspeaker', 'sub', 'woofer', 'tesira'],
         'category'     => 'audio'],

        // ── Network (managed switches / APs / firewalls) ─────────────────────
        ['manufacturer' => ['netgear', 'hp', 'hpe', 'juniper', 'aruba', 'unifi', 'ubiquiti', 'meraki', 'ruckus', 'fortinet'],
         'keywords'     => ['switch', 'router', 'firewall', 'poe', 'access point', 'ap '],
         'category'     => 'network'],

        // Cisco is split: Cisco VC codecs → VC (above); Cisco network gear → Network.
        // Evaluated here because the VC rule already consumed Cisco+codec keywords.
        ['manufacturer' => ['cisco'],
         'keywords'     => ['catalyst', 'nexus', 'meraki', 'switch', 'router', 'firewall'],
         'category'     => 'network'],

        // ── Control & Automation ─────────────────────────────────────────────
        ['manufacturer' => ['crestron', 'extron', 'amx', 'kramer', 'atlona', 'lightware'],
         'keywords'     => ['control', 'touch panel', 'nvx', 'dm-', 'processor', 'scaler', 'matrix', 'dtp', 'sensor', 'occupancy', 'partition'],
         'category'     => 'control'],

        // ── Mounts (inherit via mount_inherit_keywords below) ────────────────
        ['manufacturer' => ['chief', 'unicol', 'vogels', 'vogel\'s', 'peerless', 'b-tech'],
         'keywords'     => ['mount', 'bracket', 'stand', 'wall mount', 'pole', 'cart', 'trolley', 'floor stand'],
         'category'     => 'mount_inherit'],

        // ── Rack & Infrastructure ────────────────────────────────────────────
        ['manufacturer' => ['middle atlantic', 'rackmount', 'pentair', 'penn elcom', 'apc', 'cyberpower'],
         'keywords'     => ['rack', 'patch panel', 'pdu', 'ups', 'shelf', 'keystone', 'cable management', 'blanking'],
         'category'     => 'rack'],
    ],

    // ─── Tier 3: Description keyword rules ───────────────────────────────────
    // Last-resort keyword inference when no manufacturer matches.
    // Evaluated in the order listed; first hit wins.
    'keyword_rules' => [
        'display'            => ['videowall', 'video wall', 'projector', 'projection screen', 'led wall', 'flat panel', 'interactive display'],
        'video_conferencing' => ['codec', 'conference camera', 'vc camera', 'ptz camera', 'huddle cam', 'scheduler panel', 'room navigator'],
        'audio'              => ['loudspeaker', 'pendant speaker', 'ceiling speaker', 'amplifier', 'dsp', 'soundbar', 'subwoofer', 'audio mixer', 'microphone'],
        'control'            => ['control processor', 'touch panel', 'button panel', 'keypad', 'partition sensor', 'occupancy sensor', 'relay module', 'dimmer', 'lighting control'],
        'rack'               => ['server rack', 'equipment rack', 'patch panel', 'power distribution', 'cable tray', 'cable management', 'rack shelf', 'blanking panel'],
        'network'            => ['managed switch', 'poe switch', 'wireless access point', 'wifi ap', 'network switch', 'router', 'firewall appliance'],
    ],

    // ─── Tier 4: Context rules ───────────────────────────────────────────────

    // If an item is classified as `mount_inherit`, look at nearby items in the
    // same room to see what the mount is for, and inherit that category.
    // Keywords here detect which category the MOUNTED item belongs to.
    'mount_inherit_keywords' => [
        'display'            => ['display', 'screen', 'monitor', 'projector', 'tv', 'videowall', 'qled', 'oled', '4k', 'uhd'],
        'video_conferencing' => ['codec', 'camera', 'rally bar', 'room kit', 'studio x', 'meetup', 'ptz', 'webcam'],
        'audio'              => ['speaker', 'loudspeaker', 'microphone', 'subwoofer', 'soundbar', 'pendant'],
    ],

    // Patterns that indicate a line item is a warranty / service / smartnet.
    // These are classified against their own text first; if inconclusive,
    // inherit from the preceding classifiable line in the same room.
    'warranty_keywords' => [
        'warranty', 'smartnet', 'care plan', 'support plan', 'maintenance plan',
        'extended service', 'extended warranty', 'poly+', 'year warranty',
    ],

    // Patterns that indicate "utilise existing client equipment".
    'existing_keywords' => [
        'utilise existing', 'utilize existing', 'existing ', 'retained',
        'client supplied', 'client-supplied', 'reuse',
    ],

    // Patterns excluded from classification altogether — labour / services.
    // Used by the classifier to skip before Tier 1 evaluation.
    'exclude_keywords' => [
        'installation', 'commissioning', 'configuration service', 'project management',
        'site survey', 'engineering team', 'delivery', 'carriage', 'travel',
        'training', 'handover', 'labour', 'man day',
    ],
];
