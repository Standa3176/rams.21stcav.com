<?php

/*
 * Configuration for v1.3 drawings (Phases 17–20).
 *
 * Phase 17: schematic generator (D2 CLI) + signal-type colour coding.
 * Phase 18: rack elevations (custom Blade SVG — no D2).
 * Phase 19: floor plans (Konva — separate Vite entry).
 * Phase 20: drawing export pipeline (PDF/SVG/PNG/ZIP, O&M integration).
 */

return [
    // ── D2 CLI (Phase 17) ─────────────────────────────────────────────────
    // Production AlmaLinux: install via
    //   curl -fsSL https://d2lang.com/install.sh | sh -s -- --version v0.7.1
    // to /usr/local/bin/d2.
    // macOS local dev:    brew install d2
    // Windows local dev:  scoop install d2  (or download the binary from
    //                      https://github.com/terrastruct/d2/releases and
    //                      set D2_BINARY_PATH in .env to its absolute path).
    // See .planning/research/STACK.md §1.1 + .planning/research/SUMMARY.md
    // installation summary.
    'd2_binary_path' => env('D2_BINARY_PATH', '/usr/local/bin/d2'),
    'd2_layout' => env('D2_LAYOUT', 'elk'),    // elk | dagre | tala (paid) — elk is best for AV signal flow
    'd2_timeout' => 60,                        // seconds before Process aborts
    'd2_pinned_version' => '0.7.1',            // for runbook / version-drift checks

    // ── Symbol pack (Phase 17) ────────────────────────────────────────────
    // Resolved to absolute path at render time so D2 can `shape: image; icon: file://...`.
    'symbol_pack_path' => resource_path('svg/av-symbols'),

    // ── Signal-type colour map (DRAW-02) ──────────────────────────────────
    // AVIXA convention + FEATURES.md Phase 17 anatomy. Hex values chosen for
    // accessibility (WCAG AA on white background) — DO NOT pick brighter
    // alternatives unless reviewed against the same standard.
    'signal_colours' => [
        'audio' => '#C0392B',   // red — clear audio chain marker
        'video' => '#2980B9',   // blue
        'control' => '#27AE60', // green
        'network' => '#8E44AD', // purple — Dante / IP / Cat6
        'usb' => '#E67E22',     // orange
        'power' => '#7F8C8D',   // grey (dashed) — DC trigger / 12V
        'unknown' => '#000000', // black — undirected line for ambiguous cables
    ],

    // ── Schematic title block fields (DRAW-22) ────────────────────────────
    // Minimum set per CONTEXT.md "Claude's Discretion".
    // Phase 20 may extend with "Checked by" / "Approved by" once status workflow matures.
    'title_block_fields' => ['project_ref', 'client', 'drawn_by', 'date', 'revision', 'status'],

    // ── Phase 23 zone derivation (DRAW-46) ────────────────────────────────
    // Per CONTEXT D-01 + D-04. Vocab is the canonical enum; engineer can
    // type free-text in the review-form dropdown to create a separate
    // dashed group (D-04 escape hatch). The category_to_zone map shape
    // depends on Plan 01 Task 1's OQ-1 disposition — see
    // .planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-1-CATEGORIES.md.
    //
    // Per OQ-1 Path B disposition: real production category strings are the
    // 7 high-level keys from review.blade.php $categoryOptions. The map
    // below mirrors this — only `hardware` is renderer-relevant (other
    // categories are filtered out upstream by Project::devicesWithStencils()).
    // ZoneGrouper falls through to a name-keyword secondary derivation when
    // category lookup returns null OR 'OTHER'.
    //
    // Renderer resolution (Plan 02 ZoneGrouper):
    //   1. $line['zone'] if set (engineer override D-02, free-text D-04 supported)
    //   2. $config['category_to_zone'][$line['category']] if not null/OTHER
    //   3. name-keyword scan (ceiling/rack/display/etc — Plan 02 NAME_KEYWORD_TO_ZONE)
    //   4. fallback 'OTHER'
    'zone_vocab' => [
        'RACK', 'CEILING', 'WALL', 'TABLE',
        'RECEPTION', 'FLOOR', 'PAGING_STATION',
        'EXTERNAL', 'OTHER',
    ],
    'category_to_zone' => [
        // Per OQ-1 Path B (Plan 23-01 Task 1): real production keys map
        // through to the keyword derivation for hardware; everything else
        // resolves OTHER (and is filtered out before reaching the renderer).
        'hardware'          => null,          // fall through to name-keyword (Plan 02)
        'cables'            => 'OTHER',
        'consumables'       => 'OTHER',
        'services'          => 'OTHER',
        'service_contracts' => 'OTHER',
        'customer_supplied' => 'OTHER',
        'option'            => 'OTHER',
    ],

    // ── Phase 23 paginator threshold (DRAW-47, per D-06) ──────────────────
    // Sub-sheet emits when BOTH cable-count >= min_cables_per_signal
    // AND device-count touching that signal >= min_devices_touching_signal.
    // Engineer tinker override via Project.metadata.force_sheets = ['audio', ...]
    // (Phase 24 ships the proper UI per CONTEXT D-06 deferred line).
    'sub_sheet_thresholds' => [
        'min_cables_per_signal'       => 5,
        'min_devices_touching_signal' => 3,
    ],

    // ── Phase 23 sheet numbering (DRAW-47/48, per D-08) ───────────────────
    // Extends v1.3 Phase 20 AV-201..299 schematic range. The SheetPaginator
    // (Plan 23-04) maps emitted sheets to these strings; AV-201 always
    // emits (system overview); AV-202..205 are conditional per threshold.
    'sheet_number_format' => [
        'system_overview' => 'AV-201',
        'audio'           => 'AV-202',
        'video'           => 'AV-203',
        'control'         => 'AV-204',
        'network'         => 'AV-205',
    ],

    // ── Phase 24 device-type -> port-template vocabulary (D-06/D-07) ──────
    // Version-controlled so criterion 2's determinism guarantee ("the same
    // import always produces the same stub shape") is enforced by git, never
    // by trusting a mutable DB row. NEW device-type vocabulary — deliberately
    // distinct from BOTH EquipmentCategoryClassifier's 7 commercial categories
    // (hardware/cables/consumables/services/service_contracts/
    // customer_supplied/option) and Device::ROLE_* (source/destination/
    // processor, too coarse for ports). Read by CategoryPortTemplateResolver
    // only — never a DB table (D-06).
    //
    // Each template is an ORDERED list of port-row shapes using the exact
    // DevicePort fillable keys (label/side/connector_type/signal_type/
    // direction/sort_order) so CategoryPortTemplateResolver's output can be
    // bulk-inserted unmodified (port_id/x_pct/y_pct are populated by the
    // resolver itself, not here).
    'port_templates' => [
        'display' => [
            ['label' => 'HDMI In', 'side' => 'left', 'connector_type' => 'hdmi', 'signal_type' => 'video', 'direction' => 'in', 'sort_order' => 1],
        ],
        'switch' => [
            ['label' => 'RJ45 1', 'side' => 'left', 'connector_type' => 'rj45', 'signal_type' => 'network', 'direction' => 'io', 'sort_order' => 1],
            ['label' => 'RJ45 2', 'side' => 'left', 'connector_type' => 'rj45', 'signal_type' => 'network', 'direction' => 'io', 'sort_order' => 2],
            ['label' => 'RJ45 3', 'side' => 'left', 'connector_type' => 'rj45', 'signal_type' => 'network', 'direction' => 'io', 'sort_order' => 3],
            ['label' => 'RJ45 4', 'side' => 'left', 'connector_type' => 'rj45', 'signal_type' => 'network', 'direction' => 'io', 'sort_order' => 4],
        ],
        // Zero-port device types — mounting hardware has no signal ports.
        'bracket' => [],
        'mount' => [],
    ],

    // D-07 — explicit multi-keyword conflict resolution, evaluated BEFORE
    // single-keyword lookup. Each entry's `keywords` must ALL be present in
    // the haystack for `winner` to apply (e.g. "Samsung 65in Display Bracket"
    // matches both `display` and `bracket` — this list deterministically
    // resolves it to `bracket`, never a guess). `cable` beats everything and
    // is handled as a standalone short-circuit in
    // CategoryPortTemplateResolver::resolve() ahead of this list, not as a
    // pair rule here.
    'port_template_precedence' => [
        ['keywords' => ['bracket', 'display'], 'winner' => 'bracket'],
        ['keywords' => ['mount', 'screen'], 'winner' => 'mount'],
    ],

    // ── Phase 23 layout dimensions ────────────────────────────────────────
    // Page bounds for each emitted <diagram>. Matches the current builder's
    // implicit 1600x1000 landscape. Sheet border (DRAW-49) insets 20 px.
    'page_dimensions' => [
        'width'         => 1600,
        'height'        => 1000,
        'border_inset'  => 20,
        'title_block_y' => 940,   // y-coordinate where the title block row starts
    ],
];
