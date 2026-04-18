<?php

namespace App\Services\ProjectContext;

use App\Services\Equipment\EquipmentActivityMapper;

/**
 * SurveyToProjectContextMapper
 *
 * Transforms the canonical SiteSurvey JSON payload into a normalised
 * ProjectContext rooms structure consumed by downstream services
 * (RiskTemplateResolverService, CableScheduleBuilderService, RAMS generation).
 *
 * INPUT:  survey_data array from SiteSurvey model (cast from JSON column):
 * {
 *   "project_id": int,
 *   "rooms": [
 *     { "name", "type", "photos", "infrastructure", "equipment", "risks", "notes" }
 *   ]
 * }
 *
 * OUTPUT per room (7 keys — strict shape, no extras):
 * {
 *   "name":               string,
 *   "type":               string,
 *   "activities":         string[],   // controlled vocabulary — derived deterministically
 *   "infrastructure":     array,      // passed through from canonical payload
 *   "equipment":          array,      // normalised items: { type, status, location }
 *   "risks":              array,      // passed through from canonical payload
 *   "cable_requirements": array       // derived: { equipment_type, equipment_status,
 *                                    //             equipment_location, cable_type,
 *                                    //             estimated_distance }
 * }
 *
 * Activity controlled vocabulary (exhaustive):
 *   display_installation | audio_installation | vc_installation |
 *   control_installation | cable_installation | commissioning
 *
 * Notes field is intentionally excluded — it belongs to the survey record,
 * not to the ProjectContext consumed by technical output services.
 */
class SurveyToProjectContextMapper
{
    /**
     * Map the full canonical survey payload to the ProjectContext structure.
     *
     * @param  array  $surveyData  Contents of SiteSurvey::survey_data (already cast to array).
     * @return array  { project_id: int, rooms: array[] }
     */
    public static function map(array $surveyData): array
    {
        $projectId = (int) ($surveyData['project_id'] ?? 0);

        $rooms = array_values(array_map(
            [self::class, 'mapRoom'],
            (array) ($surveyData['rooms'] ?? [])
        ));

        return [
            'project_id' => $projectId,
            'rooms'      => $rooms,
        ];
    }

    // ── Private — room mapping ────────────────────────────────────────────────

    private static function mapRoom(array $room): array
    {
        $equipment      = self::normaliseEquipment((array) ($room['equipment']      ?? []));
        $infrastructure = (array) ($room['infrastructure'] ?? []);
        $risks          = (array) ($room['risks']          ?? []);

        return [
            'name'               => trim((string) ($room['name'] ?? '')),
            'type'               => trim((string) ($room['type'] ?? '')),
            'activities'         => self::deriveActivities($equipment, $infrastructure),
            'infrastructure'     => $infrastructure,
            'equipment'          => $equipment,
            'risks'              => $risks,
            'cable_requirements' => self::deriveCableRequirements($equipment, $infrastructure),
        ];
    }

    // ── Private — equipment normalisation ─────────────────────────────────────

    /**
     * Normalise each equipment item to exactly { type, status, location }.
     * Trims strings and lowercases type and status.
     */
    private static function normaliseEquipment(array $equipment): array
    {
        $normalised = [];

        foreach ($equipment as $item) {
            $type     = strtolower(trim((string) ($item['type']     ?? '')));
            $status   = strtolower(trim((string) ($item['status']   ?? 'new')));
            $location = trim((string) ($item['location'] ?? ''));

            if ($type === '') {
                continue; // skip malformed items with no type
            }

            $normalised[] = [
                'type'     => $type,
                'status'   => $status,
                'location' => $location,
            ];
        }

        return $normalised;
    }

    // ── Private — activity derivation ─────────────────────────────────────────

    /**
     * Derive activity keys from equipment types and infrastructure configuration.
     *
     * Rules:
     *   - Equipment present       → equipment-specific activities via EquipmentActivityMapper
     *   - Cable routes configured → cable_installation added
     *   - Always                  → commissioning added
     *
     * All values come from the controlled vocabulary:
     *   display_installation | audio_installation | vc_installation |
     *   control_installation | cable_installation | commissioning
     */
    private static function deriveActivities(array $equipment, array $infrastructure): array
    {
        $activities = [];

        if (! empty($equipment)) {
            $activities = array_merge($activities, EquipmentActivityMapper::map($equipment));
        }

        // Cable installation when route type or distance is configured
        $cableRoutes  = $infrastructure['cable_routes'] ?? [];
        $hasRouteType = trim((string) ($cableRoutes['route_type']          ?? '')) !== '';
        $hasDistance  = ((float) ($cableRoutes['estimated_distance'] ?? 0)) > 0;

        if ($hasRouteType || $hasDistance) {
            $activities[] = 'cable_installation';
        }

        // Commissioning is always required
        $activities[] = 'commissioning';

        return array_values(array_unique($activities));
    }

    // ── Private — cable requirement derivation ────────────────────────────────

    /**
     * Produce one cable requirement entry per equipment item that maps to a known
     * cable type. Uses the room's estimated distance from cable_routes (default 10 m).
     *
     * OUTPUT per item:
     * {
     *   equipment_type:     string,
     *   equipment_status:   string,
     *   equipment_location: string,
     *   cable_type:         string,
     *   estimated_distance: float,
     * }
     */
    private static function deriveCableRequirements(array $equipment, array $infrastructure): array
    {
        $cableRoutes = $infrastructure['cable_routes'] ?? [];
        $distance    = (float) ($cableRoutes['estimated_distance'] ?? 0);

        if ($distance <= 0) {
            $distance = 10.0; // default when not measured
        }

        $requirements = [];

        foreach ($equipment as $item) {
            $type      = $item['type'];     // already normalised (lowercase, trimmed)
            $status    = $item['status'];   // already normalised
            $location  = $item['location']; // already trimmed
            $cableType = self::cableTypeForEquipmentType($type);

            if ($cableType === null) {
                continue;
            }

            $requirements[] = [
                'equipment_type'     => $type,
                'equipment_status'   => $status,
                'equipment_location' => $location,
                'cable_type'         => $cableType,
                'estimated_distance' => $distance,
            ];
        }

        return $requirements;
    }

    /**
     * Map normalised equipment type → cable type string.
     * Returns null when no cabling is associated with the equipment type.
     *
     * Controlled cable types: HDMI | CAT6 | XLR
     */
    private static function cableTypeForEquipmentType(string $type): ?string
    {
        return match ($type) {
            'display', 'projector', 'switcher' => 'HDMI',
            'camera', 'vc', 'control'          => 'CAT6',
            'mic', 'dsp', 'speaker'            => 'XLR',
            default                            => null,
        };
    }
}
