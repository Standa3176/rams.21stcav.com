<?php

namespace App\Services\Equipment;

/**
 * EquipmentActivityMapper
 *
 * Maps a survey room's equipment list (structured type keys from the wizard)
 * into a deduplicated array of work-activity strings.
 *
 * INPUT:
 *   equipment[] from canonical survey_data.rooms[n].equipment
 *   Each item: { "type": string, "status": string, "location": string }
 *
 * OUTPUT:
 *   string[] — one or more of the controlled activity vocabulary:
 *     display_installation  — any screen, TV, or projector
 *     audio_installation    — microphones, DSPs, amplifiers, speakers
 *     vc_installation       — cameras, video-conferencing codecs
 *     control_installation  — control systems and touch panels
 *     cable_installation    — added by SurveyToProjectContextMapper when routes exist
 *     commissioning         — added always by SurveyToProjectContextMapper
 *
 * Rules are entirely deterministic — no AI, no database lookups.
 *
 * NOTE: This mapper emits only the activity types that equipment directly implies.
 * cable_installation and commissioning are appended by SurveyToProjectContextMapper,
 * not here, so that the cable-route check stays in one place.
 */
class EquipmentActivityMapper
{
    /**
     * Wizard type value → controlled activity key(s).
     *
     * Controlled vocabulary (exhaustive):
     *   display_installation | audio_installation | vc_installation | control_installation
     *
     * Order matters: more-specific types first so the map reads top-to-bottom clearly.
     */
    private const TYPE_ACTIVITY_MAP = [
        'display'   => ['display_installation'],
        'projector' => ['display_installation'],
        'camera'    => ['vc_installation'],
        'vc'        => ['vc_installation'],
        'mic'       => ['audio_installation'],
        'dsp'       => ['audio_installation'],
        'speaker'   => ['audio_installation'],
        'control'   => ['control_installation'],
        'switcher'  => ['control_installation'],
        'other'     => [],
    ];

    /**
     * Map a room's equipment array to a deduplicated list of activity keys.
     *
     * @param  array  $equipment  Canonical equipment items for one room.
     * @return string[]           Deduplicated activity keys, values from controlled vocabulary.
     */
    public static function map(array $equipment): array
    {
        $activities = [];

        foreach ($equipment as $item) {
            $type = strtolower(trim((string) ($item['type'] ?? '')));

            foreach ((self::TYPE_ACTIVITY_MAP[$type] ?? []) as $activity) {
                $activities[] = $activity;
            }
        }

        return array_values(array_unique($activities));
    }
}
