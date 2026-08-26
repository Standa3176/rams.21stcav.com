<?php

namespace App\Services\Worksheet;

use App\Services\Rams\DisplayLiftPolicy;

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
    public const HEAVY_ITEM_KG = 25;  // ≥ this → heavy-item warning

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

        $displayWarning = $this->resolveDisplayLiftWarning($items);
        if ($displayWarning !== null) {
            $warnings['large_display'] = $displayWarning;
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

    /**
     * Resolve the worst-case (highest `min_persons`) display-lift band among
     * a room's items, via the single shared `DisplayLiftPolicy` (RULE-02/D-04)
     * — the same bands and the same general inch-parsing pattern
     * `RamsComplianceUpgradeService::suggestHandlingMethod()` uses, so the
     * worksheet and the RAMS never state different team sizes for the same
     * display. Returns `null` when no item in the room resolves to a display
     * band at all (either because nothing looks like a display, or because
     * every display present is a ≤14" scheduling/touch/control panel — the
     * pre-existing exclusion, mirrored here for consistency).
     */
    private function resolveDisplayLiftWarning(array $items): ?string
    {
        $worst = null;

        foreach ($items as $i) {
            if (! is_array($i)) continue;

            $name = strtolower((string) ($i['name'] ?? $i['description'] ?? ''));
            $paddedName = ' ' . $name . ' ';

            // Metadata-first.
            $inches = null;
            $hasSizeMeta = isset($i['display_size_in']) && is_numeric($i['display_size_in']);
            if ($hasSizeMeta) {
                $inches = (float) $i['display_size_in'];
            }

            // Keyword fallback: the SAME general inch-parsing regex
            // RamsComplianceUpgradeService::suggestHandlingMethod() uses —
            // "98″", "98\"", "98 inch", "98-inch", "10.1″" — not the old
            // fixed-size list (55|65|70|75|85|86|98|100).
            $sizeFromName = null;
            if ($inches === null && preg_match('/(\d+(?:\.\d+)?)\s*(?:″|"|\\\\"|\xE2\x80\xB3|inch|in\b|-inch)/u', $name, $m)) {
                $sizeFromName = (float) $m[1];
                $inches = $sizeFromName;
            }

            // Is this item display-shaped at all? Metadata tag, a resolved
            // size token in the name, or a display-ish keyword. A rack/amp/
            // speaker item with none of these is not a "display" and
            // produces no warning here — unchanged from today.
            $looksLikeDisplay = $hasSizeMeta
                || $sizeFromName !== null
                || str_contains($name, 'display')
                || str_contains($paddedName, ' tv ')
                || str_contains($name, 'television')
                || str_contains($name, 'screen')
                || str_contains($name, 'monitor')
                || str_contains($name, 'lcd');

            if (! $looksLikeDisplay) {
                continue;
            }

            // Same scheduling/touch/booking/control-panel keyword set
            // suggestHandlingMethod() uses, mirrored for consistency
            // (Open Question 1, resolved in favour of consistency).
            $isSmallControlPanel = $inches !== null && $inches <= 14
                && (str_contains($name, 'scheduling') || str_contains($name, 'touch panel')
                    || str_contains($name, 'booking panel') || str_contains($name, 'control panel'));

            $band = DisplayLiftPolicy::forSize($inches, $isSmallControlPanel);
            if ($band === null) {
                continue;
            }

            if ($worst === null || $band['min_persons'] > $worst['min_persons']) {
                $worst = $band;
            }
        }

        return $worst['sentence'] ?? null;
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
