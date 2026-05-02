<?php

namespace App\Services\Drawings;

/**
 * Phase 18 Plan 01 — read-only reader over the hand-curated manufacturer JSON
 * pack at resources/data/device-port-catalog.json.
 *
 * The pack carries rack-relevant metadata (u_height / is_rack_mounted /
 * current_draw_a / weight_kg / btu_per_hour) for the top SKUs in 21CAV's
 * recent quote pipeline. Two consumers:
 *
 *   1. DeviceCatalogSeeder — upserts the metadata onto Device rows by
 *      part_no (idempotent; only touches rows whose part_no matches a pack
 *      entry — devices outside the pack stay with NULL u_height to honour
 *      CRIT-06 "never silent 1U guess").
 *
 *   2. Plan 18-03 rack editor palette — looks up per-part metadata to render
 *      U-height + power/heat figures next to each draggable equipment row.
 *
 * Lookup contract is case-insensitive trimmed (mirrors
 * DrawingDataResolverService::loadSignalRolesForProject so the same
 * normalisation rule applies everywhere a part_no is matched). Memoised
 * per-instance so the palette can hit the service dozens of times per
 * render without re-reading the file.
 *
 * @see resources/data/device-port-catalog.json
 * @see database/seeders/DeviceCatalogSeeder.php
 * @see CRIT-06 in .planning/research/PITFALLS.md
 */
class DeviceCatalogService
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $cache = null;

    private function path(): string
    {
        return resource_path('data/device-port-catalog.json');
    }

    /**
     * Return the entire catalog keyed on normalised part_no (lowercase trim).
     * Memoised so subsequent calls in the same request are O(1).
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $path = $this->path();
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("DeviceCatalogService: cannot read {$path}");
        }

        $rows = json_decode($raw, true);
        if (! is_array($rows)) {
            throw new \RuntimeException('DeviceCatalogService: JSON pack is not a list');
        }

        $this->cache = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = strtolower(trim((string) ($row['part_no'] ?? '')));
            if ($key === '') {
                continue;
            }
            $this->cache[$key] = $row;
        }

        return $this->cache;
    }

    /**
     * Look up a single catalog entry by part_no (case-insensitive trimmed).
     * Returns null when the part_no is empty/null OR not in the pack — the
     * caller decides how to treat unknowns (rack renderer surfaces a "U-height
     * unknown" warning rather than fabricating a placeholder).
     *
     * @return array<string, mixed>|null
     */
    public function lookupByPartNo(?string $partNo): ?array
    {
        if ($partNo === null || trim($partNo) === '') {
            return null;
        }

        $key = strtolower(trim($partNo));

        return $this->all()[$key] ?? null;
    }
}
