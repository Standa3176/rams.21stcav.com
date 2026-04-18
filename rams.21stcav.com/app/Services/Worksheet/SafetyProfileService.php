<?php

namespace App\Services\Worksheet;

/**
 * Per-room safety callout builder.
 *
 * Replaces the old project-wide keyword match that fired hazards in rooms
 * that didn't actually contain the hazardous kit. Each warning is triggered
 * by a deterministic rule against the room's OWN item list only.
 *
 * Metadata-first, keyword-fallback:
 *   If items carry structured tags (weight_kg, display_size_in,
 *   mounting_position, is_rack_chassis), those are authoritative. When a tag
 *   is missing, the rule falls back to keyword inference on name /
 *   description so legacy / unknown SKUs still produce a conservative warning.
 *
 * Rules are config-free; adding a new hazard means adding a method here and
 * a call inside profileRoom().
 */
class SafetyProfileService
{
    public const LARGE_DISPLAY_INCHES = 55;  // ≥ this → two-person lift
    public const HEAVY_ITEM_KG        = 25;  // ≥ this → heavy-item warning

    /**
     * Produce the list of safety warnings for one room.
     *
     * @param  array $room  Room dict (uses name + metadata)
     * @param  array $items Classified hardware items for the room
     * @return list<string> Human-readable warning strings
     */
    public function profileRoom(array $room, array $items): array
    {
        $warnings = [];

        if ($this->roomContainsLargeDisplay($items)) {
            $warnings['large_display'] = 'Large display detected — minimum 2-person lift required. Use screen-protection packaging and soft-edge grips during transit.';
        }

        if ($this->roomContainsRackChassis($items)) {
            $warnings['rack_team_lift'] = 'Rack chassis or ≥4U rack-mounted kit detected — team lift required. Secure the rack to floor/wall before loading.';
        }

        if ($this->roomContainsCeilingWork($items)) {
            $warnings['ceiling_work'] = 'Ceiling or high-level mounting detected — working-at-height controls apply. Use appropriate access equipment and a spotter.';
        }

        if ($this->roomContainsLiveServicesWork($items)) {
            $warnings['live_services'] = 'Partition sensor / motorised screen / projector lift detected — working near live services. Isolate/LOTO before first fix.';
        }

        if ($this->roomContainsHeavyItem($items)) {
            $warnings['heavy_item'] = 'Item ≥ ' . self::HEAVY_ITEM_KG . ' kg detected — mechanical aids or team lift required.';
        }

        return array_values($warnings);
    }

    // ─── Rule implementations ────────────────────────────────────────────────

    private function roomContainsLargeDisplay(array $items): bool
    {
        foreach ($items as $i) {
            if (! is_array($i)) continue;
            $sizeIn = (int) ($i['display_size_in'] ?? 0);
            if ($sizeIn >= self::LARGE_DISPLAY_INCHES) return true;

            // Keyword fallback: scan name for explicit size tokens.
            $name = strtolower((string) ($i['name'] ?? $i['description'] ?? ''));
            if (preg_match('/\b(55|65|70|75|85|86|98|100)\s*[\"”]/u', $name)) return true;
            if (preg_match('/\b(55|65|70|75|85|86|98|100)\s*inch\b/u', $name)) return true;
        }
        return false;
    }

    private function roomContainsRackChassis(array $items): bool
    {
        $rackUnits = 0;
        foreach ($items as $i) {
            if (! is_array($i)) continue;
            if (($i['is_rack_chassis'] ?? null) === true) return true;
            $name = strtolower((string) ($i['name'] ?? $i['description'] ?? ''));
            if (preg_match('/\b(24u|42u|45u|48u)\s*rack\b/u', $name)) return true;
            if (str_contains($name, 'equipment rack') || str_contains($name, 'server rack')) return true;
            if (preg_match('/\b(\d+)u\b/u', $name, $m)) {
                $rackUnits += (int) $m[1];
            }
        }
        return $rackUnits >= 4;
    }

    private function roomContainsCeilingWork(array $items): bool
    {
        foreach ($items as $i) {
            if (! is_array($i)) continue;
            if (($i['mounting_position'] ?? '') === 'ceiling') return true;
            $name = strtolower((string) ($i['name'] ?? $i['description'] ?? ''));
            if (str_contains($name, 'ceiling')) return true;
            if (str_contains($name, 'pendant') && str_contains($name, 'speaker')) return true;
            if (str_contains($name, 'ceiling projector') || str_contains($name, 'projector lift')) return true;
            if (preg_match('/\bceiling\s+(camera|array|microphone)\b/u', $name)) return true;
        }
        return false;
    }

    private function roomContainsLiveServicesWork(array $items): bool
    {
        foreach ($items as $i) {
            if (! is_array($i)) continue;
            if (($i['requires_live_services'] ?? null) === true) return true;
            $name = strtolower((string) ($i['name'] ?? $i['description'] ?? ''));
            if (str_contains($name, 'partition sensor') || str_contains($name, 'moveable wall sensor')) return true;
            if (str_contains($name, 'motorised screen') || str_contains($name, 'projector lift')) return true;
        }
        return false;
    }

    private function roomContainsHeavyItem(array $items): bool
    {
        foreach ($items as $i) {
            if (! is_array($i)) continue;
            $kg = (float) ($i['weight_kg'] ?? 0);
            if ($kg >= self::HEAVY_ITEM_KG) return true;
        }
        return false;
    }
}
