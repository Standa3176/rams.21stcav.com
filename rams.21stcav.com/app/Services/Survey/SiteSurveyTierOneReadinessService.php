<?php

namespace App\Services\Survey;

use App\Models\SiteSurvey;
use App\Models\SiteSurveyRoom;

/**
 * Tier 1 readiness scoring for site surveys.
 *
 * Deterministic. No DB writes, no schema dependencies beyond the existing
 * SiteSurveyRoom columns and its photos + questions relations.
 *
 * A room is Tier 1 ready when every required check passes. Rooms that are
 * missing data still return a partial percent so the UI can show progress
 * without needing a second pass to compute it.
 *
 * Checks (deterministic, in this order):
 *   1. AV scope captured         — av_requirements OR av_equipment_list non-empty
 *   2. Dimensions captured       — room_width_m, room_depth_m, room_height_m all > 0
 *   3. Power availability answered — has_power !== null
 *      3a. If has_power === true — power_outlet_count > 0
 *   4. Network availability answered — has_network !== null
 *      4a. If has_network === true — network_port_count > 0
 *   5. Pre-install checks answered — no questions OR every question has an answer != null
 *   6. At least one room photo exists
 *   7. Engineer sign-off captured — engineer_confirmed === true AND engineer_signature_name non-empty
 *
 * Conditional sub-checks (3a, 4a) only contribute to totals when their parent
 * check passes with a `true` value; otherwise they are skipped entirely.
 */
class SiteSurveyTierOneReadinessService
{
    // Stable keys used in the `missing` array so consumers (tests, UI) can
    // branch on them without parsing human-readable text.
    public const KEY_AV_SCOPE          = 'av_scope';
    public const KEY_DIMENSIONS        = 'dimensions';
    public const KEY_POWER_AVAILABILITY = 'power_availability';
    public const KEY_POWER_OUTLETS     = 'power_outlets';
    public const KEY_NETWORK_AVAILABILITY = 'network_availability';
    public const KEY_NETWORK_PORTS     = 'network_ports';
    public const KEY_PRE_INSTALL_CHECKS = 'pre_install_checks';
    public const KEY_PHOTOS            = 'photos';
    public const KEY_ENGINEER_SIGN_OFF = 'engineer_sign_off';

    /**
     * Assess a single room.
     *
     * @return array{
     *   room_id: int|null,
     *   room_name: string,
     *   ready: bool,
     *   percent: int,
     *   required_total: int,
     *   completed_required: int,
     *   missing: list<string>,
     *   total_checks: int,
     *   answered_checks: int
     * }
     */
    public function assessRoom(SiteSurveyRoom $room): array
    {
        $missing = [];
        $required = 0;
        $completed = 0;

        // 1. AV scope captured
        $avRequirements    = trim((string) ($room->av_requirements ?? ''));
        $avEquipmentList   = trim((string) ($room->av_equipment_list ?? ''));
        $required++;
        if ($avRequirements !== '' || $avEquipmentList !== '') {
            $completed++;
        } else {
            $missing[] = self::KEY_AV_SCOPE;
        }

        // 2. Dimensions captured (all three > 0)
        $required++;
        if (
            (float) $room->room_width_m  > 0
            && (float) $room->room_depth_m  > 0
            && (float) $room->room_height_m > 0
        ) {
            $completed++;
        } else {
            $missing[] = self::KEY_DIMENSIONS;
        }

        // 3. Power availability answered
        $required++;
        $hasPower = $room->has_power;
        if ($hasPower !== null) {
            $completed++;

            // 3a. Conditional sub-check — only counts when power is actually available.
            if ($hasPower === true) {
                $required++;
                if ((int) $room->power_outlet_count > 0) {
                    $completed++;
                } else {
                    $missing[] = self::KEY_POWER_OUTLETS;
                }
            }
        } else {
            $missing[] = self::KEY_POWER_AVAILABILITY;
        }

        // 4. Network availability answered
        $required++;
        $hasNetwork = $room->has_network;
        if ($hasNetwork !== null) {
            $completed++;

            // 4a. Conditional sub-check — only counts when network is available.
            if ($hasNetwork === true) {
                $required++;
                if ((int) $room->network_port_count > 0) {
                    $completed++;
                } else {
                    $missing[] = self::KEY_NETWORK_PORTS;
                }
            }
        } else {
            $missing[] = self::KEY_NETWORK_AVAILABILITY;
        }

        // 5. Pre-install checks answered
        $questions = $room->relationLoaded('questions')
            ? $room->questions
            : $room->questions()->get();
        $totalChecks   = $questions->count();
        $answeredChecks = $questions->filter(fn ($q) => $q->answer !== null)->count();

        $required++;
        if ($totalChecks === 0 || $answeredChecks === $totalChecks) {
            $completed++;
        } else {
            $missing[] = self::KEY_PRE_INSTALL_CHECKS;
        }

        // 6. At least one photo
        $photos = $room->relationLoaded('photos')
            ? $room->photos
            : $room->photos()->get();
        $required++;
        if ($photos->count() > 0) {
            $completed++;
        } else {
            $missing[] = self::KEY_PHOTOS;
        }

        // 7. Engineer sign-off captured
        $signatureName = trim((string) ($room->engineer_signature_name ?? ''));
        $required++;
        if ($room->engineer_confirmed === true && $signatureName !== '') {
            $completed++;
        } else {
            $missing[] = self::KEY_ENGINEER_SIGN_OFF;
        }

        $percent = $required > 0 ? (int) floor(($completed / $required) * 100) : 0;

        return [
            'room_id'            => $room->id,
            'room_name'          => (string) ($room->room_name ?? ''),
            'ready'              => $completed === $required,
            'percent'            => $percent,
            'required_total'     => $required,
            'completed_required' => $completed,
            'missing'            => array_values($missing),
            'total_checks'       => $totalChecks,
            'answered_checks'    => $answeredChecks,
        ];
    }

    /**
     * Assess every room on a survey + roll up a summary.
     *
     * @return array{
     *   summary: array{
     *     total_rooms: int,
     *     ready_rooms: int,
     *     overall_percent: int,
     *     missing_items_total: int
     *   },
     *   rooms: array<int, array>
     * }
     */
    public function assessSurvey(SiteSurvey $survey): array
    {
        $rooms = $survey->relationLoaded('rooms')
            ? $survey->rooms
            : $survey->rooms()->with(['photos', 'questions'])->get();

        $roomAssessments   = [];
        $totalRooms        = 0;
        $readyRooms        = 0;
        $requiredSum       = 0;
        $completedSum      = 0;
        $missingItemsTotal = 0;

        foreach ($rooms as $room) {
            $a = $this->assessRoom($room);
            $roomAssessments[$room->id] = $a;
            $totalRooms++;
            if ($a['ready']) $readyRooms++;
            $requiredSum       += $a['required_total'];
            $completedSum      += $a['completed_required'];
            $missingItemsTotal += count($a['missing']);
        }

        $overallPercent = $requiredSum > 0
            ? (int) floor(($completedSum / $requiredSum) * 100)
            : 0;

        return [
            'summary' => [
                'total_rooms'         => $totalRooms,
                'ready_rooms'         => $readyRooms,
                'overall_percent'     => $overallPercent,
                'missing_items_total' => $missingItemsTotal,
            ],
            'rooms'   => $roomAssessments,
        ];
    }
}
