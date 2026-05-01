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
];
