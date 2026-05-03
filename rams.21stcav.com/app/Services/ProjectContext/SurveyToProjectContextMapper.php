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
        return self::mapWithModelRooms($surveyData, null);
    }

    /**
     * Model-aware mapper. Builds the canonical ProjectContext rooms[] from the
     * survey_data JSON blob, then merges per-room engineer-feedback blocks
     * sourced from SiteSurveyRoom MODEL ROWS (table columns added in quick task
     * 260503-rgg).
     *
     * Engineer-feedback fields live on SiteSurveyRoom rows (NOT in the
     * survey_data JSON), so the JSON-only map() path cannot see them. This
     * method bridges that gap while preserving the legacy contract for tests
     * that operate on raw payloads.
     *
     * Matching is by case-insensitive trimmed room name (room_name column),
     * with positional fallback when names are missing on either side.
     *
     * Each mapped room gains an additional 'engineer_feedback' key with the
     * shape documented inline below. When no model row is found (legacy
     * surveys, payload rooms not yet persisted), the key is set to an empty
     * array — never omitted, so downstream code only needs to test for
     * non-empty.
     *
     * @param  array  $surveyData  Contents of SiteSurvey::survey_data
     * @param  iterable|null  $modelRooms  Collection<SiteSurveyRoom> from $survey->rooms (or null)
     * @return array  { project_id: int, rooms: array[] }
     */
    public static function mapWithModelRooms(array $surveyData, $modelRooms = null): array
    {
        $projectId = (int) ($surveyData['project_id'] ?? 0);

        $rooms = array_values(array_map(
            [self::class, 'mapRoom'],
            (array) ($surveyData['rooms'] ?? [])
        ));

        // Build a lookup of model rows keyed by trimmed-lowercased room_name,
        // plus an indexed list for positional fallback.
        $modelByName  = [];
        $modelByIndex = [];
        if ($modelRooms !== null) {
            $idx = 0;
            foreach ($modelRooms as $modelRoom) {
                $modelByIndex[$idx++] = $modelRoom;
                $key = strtolower(trim((string) ($modelRoom->room_name ?? '')));
                if ($key !== '') {
                    $modelByName[$key] = $modelRoom;
                }
            }
        }

        $hasModelRooms = ! empty($modelByIndex);

        foreach ($rooms as $i => $room) {
            $modelRoom = null;
            if ($hasModelRooms) {
                $key = strtolower(trim((string) ($room['name'] ?? '')));
                if ($key !== '' && isset($modelByName[$key])) {
                    $modelRoom = $modelByName[$key];
                } elseif (isset($modelByIndex[$i])) {
                    $modelRoom = $modelByIndex[$i];
                }
            }

            $rooms[$i]['engineer_feedback'] = $modelRoom !== null
                ? self::buildEngineerFeedback($modelRoom)
                : [];
        }

        return [
            'project_id' => $projectId,
            'rooms'      => $rooms,
        ];
    }

    /**
     * Extract the engineer-feedback block from a SiteSurveyRoom row.
     *
     * Defensive: every column is nullable. When a column is empty/null the
     * matching key in the output is a safe default (empty array, false,
     * or null for max_mounting_height_m).
     */
    private static function buildEngineerFeedback($modelRoom): array
    {
        $mountingHeights = is_array($modelRoom->mounting_heights) ? $modelRoom->mounting_heights : [];

        return [
            'mounting_heights'         => $mountingHeights,
            'work_at_height_methods'   => is_array($modelRoom->work_at_height_methods) ? $modelRoom->work_at_height_methods : [],
            'cable_routes'             => is_array($modelRoom->cable_routes) ? $modelRoom->cable_routes : [],
            'wall_construction'        => is_array($modelRoom->wall_construction) ? $modelRoom->wall_construction : [],
            'wall_needs_reinforcement' => (bool) ($modelRoom->wall_needs_reinforcement ?? false),
            'wall_needs_chase_out'     => (bool) ($modelRoom->wall_needs_chase_out ?? false),
            'wall_needs_conduit'       => (bool) ($modelRoom->wall_needs_conduit ?? false),
            'table_info'               => is_array($modelRoom->table_info) ? $modelRoom->table_info : [],
            'floor_box_info'           => is_array($modelRoom->floor_box_info) ? $modelRoom->floor_box_info : [],
            'brackets_required'        => is_array($modelRoom->brackets_required) ? $modelRoom->brackets_required : [],
            'max_mounting_height_m'    => self::deriveMaxMountingHeight($mountingHeights),
        ];
    }

    /**
     * Derive the maximum mounting height (metres) across all height fields.
     * Returns null when no numeric height is captured.
     */
    private static function deriveMaxMountingHeight(array $mountingHeights): ?float
    {
        $candidates = [];
        foreach (['screen_h_m', 'camera_h_m', 'booking_panel_h_m', 'speaker_h_m'] as $key) {
            $val = $mountingHeights[$key] ?? null;
            if (is_numeric($val) && (float) $val > 0) {
                $candidates[] = (float) $val;
            }
        }
        foreach ((array) ($mountingHeights['other'] ?? []) as $other) {
            $val = is_array($other) ? ($other['h_m'] ?? null) : null;
            if (is_numeric($val) && (float) $val > 0) {
                $candidates[] = (float) $val;
            }
        }

        return empty($candidates) ? null : max($candidates);
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
