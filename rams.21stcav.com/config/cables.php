<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Connector Compatibility Matrix (Phase 22 — DRAW-39)
    |--------------------------------------------------------------------------
    | Exact match is the default. Listed aliases extend the allowlist with
    | named exceptions for known-interoperable pairs. Bidirectional by
    | convention (RESEARCH.md A4) — HDMI↔DP works the same as DP↔HDMI.
    |
    | The same data drives:
    |   - Picker modal client-side filter
    |     (resources/views/cable-schedule/_port-picker-modal.blade.php — Plan 22-02)
    |   - Server-side validation warning
    |     (App\Services\Cable\CableConnectorCompatibilityService)
    |   - Phase 23's signal-type colour coding (signal_type_colours below)
    |
    | Engineering adds new alias pairs by editing this file — no code change
    | required. Use lowercase short-form keys to match the DevicePort
    | connector_type metadata convention (e.g. 'hdmi', 'dp', 'usb-c').
    */
    'compatibility_aliases' => [
        ['from' => 'hdmi',  'to' => 'dp',          'note' => 'HDMI ↔ DisplayPort via active adapter'],
        ['from' => 'usb-c', 'to' => 'thunderbolt', 'note' => 'USB-C ↔ Thunderbolt 3/4 backwards-compatible'],
        ['from' => 'rj45',  'to' => 'sfp-plus',    'note' => 'RJ45 ↔ SFP+ via SFP module'],
        ['from' => 'usb-c', 'to' => 'hdmi',        'note' => 'USB-C → HDMI via DisplayPort Alt Mode adapter'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Signal-type colour map (Phase 23 — read here by the renderer)
    |--------------------------------------------------------------------------
    | AVIXA convention. Mirrors config/drawings.php signal_colours for the
    | v1.3 D2 schematic generator — Phase 23's port-to-port renderer reads
    | from THIS file so the two surfaces never drift.
    |
    | Hex values chosen for accessibility (WCAG AA on white background).
    */
    'signal_type_colours' => [
        'audio'   => '#C0392B',
        'video'   => '#2980B9',
        'control' => '#27AE60',
        'network' => '#8E44AD',
        'usb'     => '#E67E22',
        'speaker' => '#16A085',
        'power'   => '#7F8C8D',
        'unknown' => '#000000',
    ],
];
