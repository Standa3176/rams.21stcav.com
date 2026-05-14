<?php

namespace App\Services\Drawings;

/**
 * Phase 23 — Sub-room zone derivation for the XTEN-AV-style renderer (DRAW-46).
 *
 * Assigns each device line to a zone per the D-01 / D-02 / D-04 + OQ-1 Path B
 * precedence ladder, returning a deterministic map from zone name → device
 * lines.
 *
 * Precedence (per .planning/phases/23-xten-av-style-renderer/23-CONTEXT.md
 * and .planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-1-CATEGORIES.md):
 *
 *   1. $line['zone']                — D-02 per-device override
 *                                     (D-04 free-text path: raw string used as
 *                                     the group key — engineer typing
 *                                     "Equipment Rack" creates a distinct
 *                                     dashed group from "RACK")
 *   2. config category map          — D-01 lookup. A non-null/non-OTHER
 *                                     value wins (covers any future
 *                                     sub-category vocab Phase 24 may add).
 *   3. NAME_KEYWORD_TO_ZONE scan    — OQ-1 Path B: real production data has
 *                                     only 7 high-level category strings,
 *                                     and `hardware` is mapped to null in
 *                                     config to trigger this fallback. The
 *                                     keyword scan runs on $line['name']
 *                                     (falling back to $line['model'] when
 *                                     name is empty) — that is where the
 *                                     human-readable kind information lives
 *                                     in real quote data.
 *                                     First-match-wins; keyword order is
 *                                     significant ("ceiling" before generic
 *                                     "rack" to avoid false matches like
 *                                     "Ceiling Camera Bracket").
 *   4. 'OTHER'                      — final fallback
 *
 * Ordering rules:
 *   - Zones in config('drawings.zone_vocab') come first, in vocab order
 *   - Free-text zones come after, sorted alphabetically (case-sensitive)
 *   - Devices within a zone preserve input order (stable)
 *
 * Pure read function: NO Eloquent writes, NO config writes, NO AI calls
 * (Phase 23 determinism — D-LOCK-5/6).
 *
 * @see .planning/phases/23-xten-av-style-renderer/23-CONTEXT.md (D-01, D-02, D-04)
 * @see .planning/phases/23-xten-av-style-renderer/23-DISCOVERY-OQ-1-CATEGORIES.md (Path B)
 */
class ZoneGrouper
{
    /**
     * Name-keyword → zone derivation table (OQ-1 Path B disposition).
     *
     * First-match-wins case-insensitive substring scan against the device
     * `name` field (falling back to `model` if `name` is empty). Order is
     * SIGNIFICANT — `ceiling` is evaluated before generic tokens like
     * `rack` / `switch` so "Ceiling Camera Bracket" resolves to CEILING,
     * not to whatever generic match might appear later.
     *
     * Mirrors the table in 23-DISCOVERY-OQ-1-CATEGORIES.md verbatim — any
     * change must be re-justified in the discovery file first.
     *
     * @var array<string, string>
     */
    protected const NAME_KEYWORD_TO_ZONE = [
        'ceiling'      => 'CEILING',
        'paging'       => 'PAGING_STATION',
        'call station' => 'PAGING_STATION',
        'intercom'     => 'RECEPTION',
        'door station' => 'RECEPTION',
        'reception'    => 'RECEPTION',
        // Rack-family — generic networking / DSP / processing lives here.
        'rack'         => 'RACK',
        'switch'       => 'RACK',
        'dsp'          => 'RACK',
        'amplifier'    => 'RACK',
        'amp'          => 'RACK',
        'matrix'       => 'RACK',
        'processor'    => 'RACK',
        // Wall-mounted display family.
        'display'      => 'WALL',
        'screen'       => 'WALL',
        'monitor'      => 'WALL',
        'projector'    => 'WALL',
        'signage'      => 'WALL',
        // Table-top.
        'touchpanel'   => 'TABLE',
        'touch panel'  => 'TABLE',
        'tabletop'     => 'TABLE',
        'table mic'    => 'TABLE',
        'desk mic'     => 'TABLE',
        'codec'        => 'TABLE',
        // Floor-mounted power.
        'ups'          => 'FLOOR',
        'pdu'          => 'FLOOR',
        'distribution' => 'FLOOR',
    ];

    /**
     * Assign each device line to a zone, returning a deterministic map
     * from zone name → ordered list of device lines belonging to it.
     *
     * @param  array<int, array{part_number: string, category?: string, name?: string, model?: string, zone?: string, stencil: mixed}>  $lines
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function assign(array $lines): array
    {
        $vocabOrder = (array) config('drawings.zone_vocab', []);
        $categoryMap = (array) config('drawings.category_to_zone', []);

        $grouped = [];
        foreach ($lines as $line) {
            // Exclude lines without a resolved stencil (empty part_number rows
            // from Project::devicesWithStencils()).
            if (($line['stencil'] ?? null) === null) {
                continue;
            }

            $zone = $this->resolveZone($line, $categoryMap);
            $grouped[$zone] ??= [];
            $grouped[$zone][] = $line;
        }

        return $this->sortByZoneOrder($grouped, $vocabOrder);
    }

    /**
     * Resolve the zone for a single device line per the D-01/D-02/D-04 +
     * OQ-1 Path B precedence ladder.
     *
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>  $categoryMap
     */
    private function resolveZone(array $line, array $categoryMap): string
    {
        // 1. Per-device override (D-02 / D-04 free-text).
        $override = isset($line['zone']) ? trim((string) $line['zone']) : '';
        if ($override !== '') {
            return $override;
        }

        // 2. Category-map lookup (D-01).
        // A non-null, non-OTHER value short-circuits to that zone. Null OR
        // 'OTHER' falls through to the OQ-1 Path B name-keyword scan —
        // `hardware` is mapped to null in config for exactly this reason.
        $category = isset($line['category']) ? strtolower(trim((string) $line['category'])) : '';
        $byCategory = $categoryMap[$category] ?? null;
        if ($byCategory !== null && $byCategory !== 'OTHER') {
            return (string) $byCategory;
        }

        // 3. Name-keyword secondary derivation (OQ-1 Path B).
        $name = strtolower((string) ($line['name'] ?? ''));
        if ($name === '') {
            $name = strtolower((string) ($line['model'] ?? ''));
        }
        if ($name !== '') {
            foreach (self::NAME_KEYWORD_TO_ZONE as $needle => $zone) {
                if (str_contains($name, $needle)) {
                    return $zone;
                }
            }
        }

        // 4. Final fallback.
        return 'OTHER';
    }

    /**
     * Vocab zones first (in vocab order), free-text zones alphabetical after.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $grouped
     * @param  array<int, string>                               $vocab
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function sortByZoneOrder(array $grouped, array $vocab): array
    {
        $sorted = [];

        // Vocab order first.
        foreach ($vocab as $zone) {
            if (isset($grouped[$zone])) {
                $sorted[$zone] = $grouped[$zone];
                unset($grouped[$zone]);
            }
        }

        // Remaining (free-text) zones sorted alphabetically, case-sensitive.
        $freeText = array_keys($grouped);
        sort($freeText, SORT_STRING);
        foreach ($freeText as $zone) {
            $sorted[$zone] = $grouped[$zone];
        }

        return $sorted;
    }
}
