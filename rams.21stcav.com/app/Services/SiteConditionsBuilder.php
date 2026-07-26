<?php

namespace App\Services;

use App\Models\SiteSurvey;
use App\Models\SiteSurveyRoom;

/**
 * Builds a per-room `site_conditions` structure from a SiteSurvey for feeding
 * into AI prompts (MethodStatementPrompt for RAMS, OmManualPrompt for O&M).
 *
 * Introduced by quick task 260726-fx4 Tasks 5 & 6. The RAMS + O&M prompts
 * previously never saw the engineer_feedback fields the survey wizard
 * captures (mounting heights, wall construction, brackets, cable routes),
 * so app-generated docs couldn't match hand-authored reference-doc
 * specificity.
 *
 * Structure (keyed by room name):
 *   [
 *     'Boardroom' => [
 *       'mounting_heights' => ['display' => 1900, 'occupancy_sensor' => 2800],
 *       'wall_construction' => 'Plasterboard on metal stud',
 *       'wall_needs_reinforcement' => true,
 *       'wall_needs_conduit' => false,
 *       'brackets_required' => [['type' => 'Chief tilting wall mount', 'notes' => '...']],
 *       'cable_routes' => 'floor void → riser → false ceiling',
 *       'table_info' => 'circular meeting table, boxed floor grommet',
 *       'floor_box_info' => '2× 4-way sockets in floor box',
 *       'access_notes' => 'ceiling grid 600×600, no asbestos flag',
 *     ],
 *     ...
 *   ]
 *
 * Empty rooms are omitted entirely. Empty / null / default (false)
 * fields inside a room are omitted so the AI model doesn't get confused
 * by placeholder "wall_needs_reinforcement: false" noise when the
 * engineer didn't answer the question.
 *
 * The output is passed VERBATIM into the AI prompt context — do NOT
 * leak PII or sensitive fields. The SiteSurveyRoom columns consulted
 * here are technical AV metadata only.
 */
class SiteConditionsBuilder
{
    /**
     * Extract site_conditions from every room on the given survey.
     *
     * @return array<string, array<string, mixed>>  keyed by room name
     */
    public static function fromSurvey(?SiteSurvey $survey): array
    {
        if ($survey === null) {
            return [];
        }

        $out = [];

        // rooms is a HasMany relation — trigger it if unloaded so tests can
        // create rooms directly on the model without eager-loading.
        foreach ($survey->rooms as $room) {
            if (! $room instanceof SiteSurveyRoom) {
                continue;
            }
            $name = trim((string) $room->room_name);
            if ($name === '') {
                continue;
            }

            $conditions = self::fromRoom($room);
            if (! empty($conditions)) {
                $out[$name] = $conditions;
            }
        }

        return $out;
    }

    /**
     * Extract non-empty engineer_feedback fields from a single room.
     *
     * @return array<string, mixed>
     */
    public static function fromRoom(SiteSurveyRoom $room): array
    {
        $c = [];

        // ── JSON-array fields (mounting_heights, cable_routes, brackets etc.) ─
        foreach ([
            'mounting_heights',
            'cable_routes',
            'wall_construction',
            'brackets_required',
            'table_info',
            'floor_box_info',
            'work_at_height_methods',
        ] as $key) {
            $value = $room->{$key};
            if (self::isMeaningful($value)) {
                $c[$key] = $value;
            }
        }

        // ── Boolean flags — include only when true (false = default/unset noise) ──
        foreach ([
            'wall_needs_reinforcement',
            'wall_needs_chase_out',
            'wall_needs_conduit',
        ] as $key) {
            if ((bool) $room->{$key} === true) {
                $c[$key] = true;
            }
        }

        // ── Plain-text access notes / general notes ──
        foreach ([
            'access_notes',
            'cable_route_desc',
        ] as $key) {
            $value = trim((string) ($room->{$key} ?? ''));
            if ($value !== '') {
                $c[$key] = $value;
            }
        }

        return $c;
    }

    /**
     * True when the value is a non-empty array or a non-empty string.
     * Handles the array-cast columns (json → array) and plain-text columns
     * uniformly so callers don't have to type-check every field.
     */
    private static function isMeaningful(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_array($value)) {
            // Recursively drop empty entries so a [null, null] array doesn't
            // pretend to be data. Then check length.
            $filtered = array_filter($value, static function ($v) {
                if ($v === null) {
                    return false;
                }
                if (is_string($v)) {
                    return trim($v) !== '';
                }
                if (is_array($v)) {
                    return ! empty($v);
                }
                return true;
            });
            return ! empty($filtered);
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        return true;
    }
}
