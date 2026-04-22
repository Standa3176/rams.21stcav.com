<?php

/*
|--------------------------------------------------------------------------
| Commissioning — AVIXA Categories + Keyword Map
|--------------------------------------------------------------------------
|
| Drives per-equipment commissioning item generation for Phase 16.
|
| Source references:
|   INST-05e — 7 locked AVIXA categories
|   D-01     — static PHP config (not a DB table) so generator runs fast
|              and config changes are code-reviewed
|   D-06     — case-insensitive substring match on install_tasks.equipment_name
|   D-07     — unmatched equipment is SKIPPED (no item generated). The
|              generator never invents a default category for a non-matching
|              name — silence on a miss is deliberate (no blind pass-throughs).
|
| To add vocabulary after a field-engineer retro: append keywords, deploy,
| engineer hits "Re-sync from programme" (D-04) — CommissioningSyncService
| will add new items for any task/category pairings that now match.
|
*/

return [

    // D-08 — exactly the 7 AVIXA categories from INST-05e. Never grow this
    // list without also growing the keyword_map below; the generator key-
    // loops over keyword_map so a missing key is a silent no-op.
    'categories' => [
        'power'   => 'Power On',
        'display' => 'Display Quality',
        'audio'   => 'Audio Level',
        'vtc'     => 'VTC Connectivity',
        'control' => 'Control System',
        'network' => 'Network',
        'cabling' => 'Cabling',
    ],

    // D-06 — case-insensitive substring match on install_tasks.equipment_name.
    // Keyword strings themselves are lowercased at match time by the
    // generator (mb_strtolower) so contributors do not have to remember
    // casing conventions when adding vocabulary.
    'keyword_map' => [
        'power'   => ['display', 'monitor', 'projector', 'videowall', 'videobar',
                      'amplifier', 'dsp', 'codec', 'pdu', 'ups', 'switch',
                      'processor', 'mixer', 'rack', 'pc', 'mini pc', 'nuc'],
        'display' => ['display', 'monitor', 'projector', 'videowall', 'screen',
                      'tv', 'oled', 'lcd', 'ledwall', 'confidence monitor'],
        'audio'   => ['microphone', 'mic', 'ceiling mic', 'mxa', 'speaker',
                      'soundbar', 'amplifier', 'amp', 'dsp', 'tesira', 'q-sys',
                      'biamp', 'shure', 'videobar', 'bose', 'mixer'],
        'vtc'     => ['codec', 'videobar', 'teams room', 'mtr', 'zoom room',
                      'logitech rally', 'poly studio', 'cisco room', 'vc bar',
                      'webex', 'bluejeans', 'neat'],
        'control' => ['crestron', 'extron', 'amx', 'control processor', 'cp3',
                      'cp4', 'touch panel', 'tsw', 'ipcp', 'keypad',
                      'occupancy sensor', 'button panel', 'control system'],
        'network' => ['switch', 'router', 'access point', 'wap', 'poe switch',
                      'netgear', 'cisco catalyst', 'unifi', 'meraki', 'firewall'],

        // D-07 — cables are excluded from install_tasks in Phase 12, so no
        // keywords target this category from the task side. The category
        // remains in the enum so future phases (e.g. a cable commissioning
        // module) can attach items without a schema change.
        'cabling' => [],
    ],

    // D-15 — certification wording shown above the signature canvas, and
    // snapshotted onto commissioning_signoffs.certification_text at sign time.
    // Edits here affect FUTURE signoffs only; historical rows keep their own
    // copy of the wording they signed.
    'certification_text' => 'I confirm the commissioning items above reflect the '
        .'system state handed over to 21st Century AV Ltd\'s client on the date '
        .'shown below. Outstanding items listed as "To Be Resolved" are acknowledged.',
];
