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
        // 260727-fx7: added 'clevertouch' + 'newline' + 'promethean' + 'smart'
        // (interactive/education displays); catches Clore Learning Space's
        // Clevertouch 75" (was previously dropped as Unclassified).
        ['manufacturer' => ['samsung', 'lg', 'sony', 'philips', 'nec', 'sharp', 'panasonic', 'hisense', 'iiyama', 'benq', 'viewsonic', 'clevertouch', 'newline', 'promethean', 'smart'],
         'keywords'     => ['display', 'screen', 'monitor', 'tv', 'uhd', '4k', 'oled', 'qled', 'qm', 'qn', 'qb', 'uh', 'um', 'android display', 'commercial display', 'commerical display', 'clevershare', 'cleverlive', 'interactive display'],
         'category'     => 'display'],
        ['manufacturer' => ['epson', 'benq', 'optoma', 'barco', 'christie', 'panasonic'],
         'keywords'     => ['projector', 'beamer', 'lcos', 'dlp'],
         'category'     => 'display'],

        // ── Mounts (inherit via mount_inherit_keywords below) ────────────────
        // 260727-fx7: hoisted to fire BEFORE the VC / audio / control rules so
        // "AVer DL10 Wall Mount" / "Crestron Multisurface Mount Kit" / etc.
        // resolve to mount_inherit (correct — accessory whose category
        // inherits from nearby items). Requires a mount keyword AS WELL AS
        // manufacturer match, so pure cameras (AVer PTZ, Crestron 1 Beyond)
        // fall through to their category rules cleanly.
        //
        // 260727-fx6: added 'multisurface mount' + 'mount kit' + 'flush mount'
        // for Crestron glass-wall mount hardware.
        // 260727-fx7: added 'aver' + 'btech' + b-tech SKUs (BT9910, BT8210).
        ['manufacturer' => ['chief', 'unicol', 'vogels', 'vogel\'s', 'peerless', 'b-tech', 'btech', 'crestron', 'aver'],
         'keywords'     => ['mount', 'bracket', 'stand', 'wall mount', 'pole', 'cart', 'trolley', 'floor stand', 'multisurface mount', 'mount kit', 'flush mount', 'ceiling mount', 'bt9910', 'bt8210', 'bt7863'],
         'category'     => 'mount_inherit'],

        // ── Video Conferencing ───────────────────────────────────────────────
        // 260727-fx7: added AVer DL-series keywords ('dl10','dl30','distance learning',
        // 'cam520') so Clore Learning Space's AVer PTZ cameras stop dropping.
        ['manufacturer' => ['cisco', 'poly', 'polycom', 'logitech', 'yealink', 'neat', 'huddly', 'aver'],
         'keywords'     => ['codec', 'room kit', 'rally', 'studio', 'bar', 'meetup', 'mx ', 'webex', 'x30', 'x50', 'x70', 'x90', 'tv mount for video', 'dl10', 'dl30', 'dl20', 'distance learning', 'cam520', 'cam530', 'cam540', 'cam550', 'ptz', 'tracking'],
         'category'     => 'video_conferencing'],

        // ── Audio: Microphones ───────────────────────────────────────────────
        ['manufacturer' => ['shure', 'sennheiser', 'audio-technica', 'audix', 'rode', 'clearone'],
         'keywords'     => ['microphone', 'mic', 'mxw', 'mxa', 'slx', 'slxd', 'qlxd', 'ulxd', 'axt', 'lavalier', 'bodypack', 'handheld', 'gooseneck', 'ceiling array'],
         'category'     => 'audio'],

        // ── Assistive listening / hearing loop ───────────────────────────────
        ['manufacturer' => ['ampetronic', 'univox', 'contacta'],
         'keywords'     => ['auri', 'hearing loop', 'induction loop', 'assistive listening', 'receiver', 'transmitter', 'neck loop'],
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

        // ── Crestron audio (260727-fx6) ──────────────────────────────────────
        // Must fire BEFORE the Crestron control rule below — first-match-wins
        // in tier 2. Catches Saros speakers + X-Series amplifiers which pre-fix
        // only survived via tier 3 keyword fallback (drift-flag warning).
        ['manufacturer' => ['crestron'],
         'keywords'     => ['saros', 'in-ceiling speaker', 'x-series amplifier', 'x-series amp'],
         'category'     => 'audio'],

        // ── Crestron VC (260727-fx6) ─────────────────────────────────────────
        // Must fire BEFORE the Crestron control rule below. Catches 1 Beyond
        // PTZ cameras, Automate VX camera-switching processor + AutoMeasure
        // Cubes calibration accessory, and Crestron SR tracking cameras
        // (IV-CAMERA-4K-PTZ SKU pattern).
        ['manufacturer' => ['crestron'],
         'keywords'     => ['1 beyond', '1beyond', 'automate vx', 'automeasure', 'ptz camera', '4k ptz', 'tracking camera', 'p20', 'p12', 'iv-camera', 'iv,camera', 'ptz,track'],
         'category'     => 'video_conferencing'],

        // ── Control & Automation ─────────────────────────────────────────────
        // 260727-fx6: extended Crestron keywords to catch scheduling touch
        // screens (TSW/TSS-1070 series), AirMedia wireless-BYOD receivers +
        // endpoints (AM-3100-WF / AM-TX3-100), and generic room-booking panels.
        ['manufacturer' => ['crestron', 'extron', 'amx', 'kramer', 'atlona', 'lightware'],
         'keywords'     => ['control', 'touch panel', 'touch screen', 'nvx', 'dm-', 'processor', 'scaler', 'matrix', 'dtp', 'sensor', 'occupancy', 'partition', 'scheduling', 'room scheduling', 'booking panel', 'tsw-', 'tss-', 'tsw1070', 'tss1070', 'airmedia', 'air media', 'am-3100', 'am-tx3', 'wireless presentation', 'byod'],
         'category'     => 'control'],

        // ── Rack & Infrastructure ────────────────────────────────────────────
        ['manufacturer' => ['middle atlantic', 'rackmount', 'pentair', 'penn elcom', 'apc', 'cyberpower'],
         'keywords'     => ['rack', 'patch panel', 'pdu', 'ups', 'shelf', 'keystone', 'cable management', 'blanking'],
         'category'     => 'rack'],

        // ── Power conditioning / surge protection (260727-fx6) ───────────────
        // SurgeX + TrippLite power conditioners belong in rack infrastructure.
        // Pre-fix these had no manufacturer rule at all and were dropped.
        ['manufacturer' => ['surgex', 'tripp lite', 'tripplite', 'furman'],
         'keywords'     => ['surge protector', 'power conditioner', 'sequencing', 'pdu', 'power sequencer', 'sequencing surge'],
         'category'     => 'rack'],

        // ── Blustream / HDBaseT signal distribution (260727-fx7) ─────────────
        // Blustream WMF wireless presentation switches + WMF USB-C/HDMI
        // dongles + UEX3C USB extender kits all belong in control
        // (source-selection + routing infrastructure). Pre-fix Blustream
        // had no manufacturer rule and was dropped from Clore Learning
        // Space's kit list.
        ['manufacturer' => ['blustream', 'kramer', 'atlona', 'lightware', 'extron'],
         'keywords'     => ['wmf', 'presentation switch', 'multi-format presentation', 'wireless dongle', 'usb-c dongle', 'hdmi dongle', 'hdbaset', 'usb extender', 'extender kit', 'presentation kit', 'byod dongle'],
         'category'     => 'control'],
    ],

    // ─── Tier 3: Description keyword rules ───────────────────────────────────
    // Last-resort keyword inference when no manufacturer matches.
    // Evaluated in the order listed; first hit wins.
    // 260727-fx7 tier-3 additions target descriptions that reach the
    // classifier WITHOUT a manufacturer prefix (QW import sometimes truncates
    // the leading brand token, e.g. "ProSAFE ..." instead of "Netgear ProSAFE
    // ..."). Also covers 'wireless multi-format presentation' where the fx6
    // 'wireless presentation' keyword doesn't match as a contiguous substring.
    'keyword_rules' => [
        'display'            => ['videowall', 'video wall', 'projector', 'projection screen', 'led wall', 'flat panel', 'interactive display', 'android display', 'commercial display', 'commerical display', 'flat screen', 'display trolley', 'display cart', 'display stand', 'clevershare', 'cleverlive'],
        'video_conferencing' => ['codec', 'conference camera', 'vc camera', 'ptz camera', 'huddle cam', 'scheduler panel', 'room navigator', 'camera switching', 'tracking camera', '1 beyond', 'panacast', 'automate vx', '4k ptz', 'iv,camera', 'iv-camera', 'ptz,track', 'dl10', 'dl30', 'dl20', 'cam520', 'cam530', 'cam540', 'distance learning', 'presenter tracking'],
        'audio'              => ['loudspeaker', 'pendant speaker', 'ceiling speaker', 'amplifier', 'dsp', 'soundbar', 'subwoofer', 'audio mixer', 'microphone', 'headphone', 'headset', 'hearing loop', 'induction loop', 'neck loop', 'auri', 'slxd', 'qlxd', 'ulxd', 'axt', 'mxw', 'mxa', 'parlé', 'beamtracking'],
        'control'            => ['control processor', 'touch panel', 'touch screen', 'button panel', 'keypad', 'partition sensor', 'occupancy sensor', 'relay module', 'dimmer', 'lighting control', 'room scheduling', 'booking panel', 'scheduling touch', 'airmedia', 'wireless presentation', 'presentation switch', 'multi-format presentation', 'wireless multi-format', 'wmf', 'hdbaset', 'usb extender', 'extender kit', 'hdmi extender', 'usb-c dongle', 'hdmi dongle', 'byod'],
        'rack'               => ['server rack', 'equipment rack', 'patch panel', 'power distribution', 'cable tray', 'cable management', 'rack shelf', 'blanking panel', 'floor plate', 'ceiling plate', 'floor to ceiling mount', 'goal post', 'span unit', 'truss', 'surge protector', 'power conditioner', 'sequencing surge'],
        'network'            => ['managed switch', 'poe switch', 'wireless access point', 'wifi ap', 'network switch', 'router', 'firewall appliance', 'prosafe', 'gigabit switch', 'sfp port', 'smart switch'],
    ],

    // ─── Tier 4: Context rules ───────────────────────────────────────────────

    // If an item is classified as `mount_inherit`, look at nearby items in the
    // same room to see what the mount is for, and inherit that category.
    // Keywords here detect which category the MOUNTED item belongs to.
    'mount_inherit_keywords' => [
        'display'            => ['display', 'screen', 'monitor', 'projector', 'tv', 'videowall', 'qled', 'oled', '4k', 'uhd', 'flat screen', 'flat panel'],
        'video_conferencing' => ['codec', 'camera', 'rally bar', 'room kit', 'studio x', 'meetup', 'ptz', 'webcam', 'video bar'],
        'audio'              => ['speaker', 'loudspeaker', 'microphone', 'subwoofer', 'soundbar', 'pendant', 'transmitter', 'receiver'],
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
