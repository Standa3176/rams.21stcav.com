<?php

namespace App\Services\Cable;

/**
 * CableScheduleBuilderService
 *
 * Aggregates cable requirements from a ProjectContext into a flat list
 * suitable for review, further processing, or passing to the RAMS builder.
 *
 * POSITIONING:
 *   This service sits BEFORE CableScheduleService and CableScheduleGeneratorService
 *   in the pipeline. It produces structured requirements only — it does NOT generate
 *   the final XLSX cable schedule. That responsibility belongs to CableScheduleService.
 *
 * INPUT:  ProjectContext array from ProjectContextBuilder::build()
 * {
 *   "rooms": [
 *     {
 *       "name":               string,
 *       "cable_requirements": [
 *         {
 *           "equipment_type":     string,
 *           "equipment_status":   string,
 *           "equipment_location": string,
 *           "cable_type":         string,   // HDMI | CAT6 | XLR
 *           "estimated_distance": float,
 *         }
 *       ]
 *     }
 *   ]
 * }
 *
 * OUTPUT:  Flat array of cable requirement rows, one per equipment item:
 * [
 *   {
 *     "room":               string,   // source room name
 *     "equipment_type":     string,
 *     "equipment_status":   string,
 *     "equipment_location": string,
 *     "cable_type":         string,
 *     "estimated_distance": float,
 *   }
 * ]
 *
 * This service has no AI calls, no database lookups, and no external dependencies.
 * All content is derived deterministically from the ProjectContext payload.
 */
class CableScheduleBuilderService
{
    /**
     * Aggregate cable requirements from all rooms in a ProjectContext.
     *
     * Flattens per-room cable_requirements into a single list, annotated
     * with the room name. Empty rooms or rooms without cable requirements
     * are silently skipped.
     *
     * @param  array  $context  Output of ProjectContextBuilder::build()
     * @return array            Flat list of cable requirement rows.
     */
    public static function buildRequirements(array $context): array
    {
        $rooms = (array) ($context['rooms'] ?? []);
        $rows  = [];

        foreach ($rooms as $room) {
            $roomName    = trim((string) ($room['name'] ?? ''));
            $requirements = (array) ($room['cable_requirements'] ?? []);

            foreach ($requirements as $req) {
                $type     = strtolower(trim((string) ($req['equipment_type']     ?? '')));
                $status   = strtolower(trim((string) ($req['equipment_status']   ?? 'new')));
                $location = trim((string) ($req['equipment_location'] ?? ''));
                $cable    = trim((string) ($req['cable_type']         ?? ''));
                $distance = max(0.0, (float) ($req['estimated_distance'] ?? 10.0));

                if ($type === '' || $cable === '') {
                    continue; // skip malformed entries
                }

                $rows[] = [
                    'room'               => $roomName,
                    'equipment_type'     => $type,
                    'equipment_status'   => $status,
                    'equipment_location' => $location,
                    'cable_type'         => $cable,
                    'estimated_distance' => $distance,
                ];
            }
        }

        return $rows;
    }
}
