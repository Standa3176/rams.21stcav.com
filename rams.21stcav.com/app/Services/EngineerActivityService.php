<?php

namespace App\Services;

use App\Models\DeviceLabelPhoto;
use App\Models\Worksheet;
use App\Models\WorksheetPhoto;
use App\Models\WorksheetSignoff;
use Illuminate\Support\Collection;

/**
 * EngineerActivityService — single source of truth for the on-site engineer
 * activity surface that feeds BOTH the admin worksheet show page and the
 * Engineer Report PDF (quick task 260602-rcd).
 *
 * Purpose: every "what did the engineer actually do on site" surface
 * (completed-work photos per room, equipment labels captured, room/survey
 * pre-install confirmations, client sign-offs, outstanding snag items) must
 * be aggregated identically on both surfaces. Building the same context once
 * means the View page and the PDF can never disagree.
 *
 * Shape returned by buildReportContext():
 *   [
 *     'rooms' => [
 *       [
 *         'name'                => string,
 *         'survey_reviewed_at'  => ?string (ISO8601),
 *         'room_completed_at'   => ?string (ISO8601),
 *         'completed_photos'    => Collection<WorksheetPhoto>,
 *         'label_photos'        => Collection<DeviceLabelPhoto>,
 *       ],
 *       ...
 *     ],
 *     'outstanding_items'      => string[] — flat aggregate snag list across every signoff
 *     'signoffs'               => Collection<WorksheetSignoff>
 *     'summary' => [
 *       'photo_count'          => int — completed-work photos across all rooms
 *       'label_count'          => int — equipment-label photos across all rooms
 *       'signoff_count'        => int
 *       'has_activity'         => bool — convenience mirror of Worksheet::hasEngineerActivity()
 *     ],
 *   ]
 *
 * @see Worksheet::hasEngineerActivity()
 * @see WorksheetController::show
 * @see WorksheetController::engineerReportPdf
 */
class EngineerActivityService
{
    /**
     * Build the engineer-activity context dictionary for one worksheet.
     */
    public function buildReportContext(Worksheet $worksheet): array
    {
        // ── Rooms — aggregate per-room photos + label photos + pre-install marks
        $rooms = [];
        $generatedRooms = $worksheet->generated_data['rooms'] ?? [];

        // Pre-fetch all photo rows once and group by room_name (case-insensitive
        // trimmed key) so each room iteration is O(1), not O(n) DB hits.
        $allCompletedPhotos = WorksheetPhoto::where('worksheet_id', $worksheet->id)
            ->orderBy('sort_order')->orderBy('id')->get();
        $completedByRoom = $this->groupByNormalisedRoomName($allCompletedPhotos);

        $allLabelPhotos = DeviceLabelPhoto::where('worksheet_id', $worksheet->id)
            ->with('device')->orderBy('created_at')->get();
        $labelByRoom = $this->groupByNormalisedRoomName($allLabelPhotos);

        foreach ($generatedRooms as $room) {
            $name = (string) ($room['name'] ?? 'Unknown Room');
            $key  = strtolower(trim($name));

            $rooms[] = [
                'name'               => $name,
                'survey_reviewed_at' => $worksheet->surveyReviewedAt($name),
                'room_completed_at'  => $worksheet->roomCompletedAt($name),
                'completed_photos'   => $completedByRoom[$key] ?? collect(),
                'label_photos'       => $labelByRoom[$key] ?? collect(),
            ];
        }

        // ── Sign-offs — newest first (matches HasMany ordering in Worksheet)
        $signoffs = $worksheet->signoffs()->get();

        // ── Outstanding items — flat list of every snag from every sign-off that
        // was recorded as "signed_with_comments". Each non-empty comments string
        // becomes one entry. Engineers typically enter one per line so we also
        // split on newlines for finer-grained surfacing.
        $outstanding = [];
        foreach ($signoffs as $so) {
            if (! $so->signed_with_comments || $so->comments === null) {
                continue;
            }
            foreach (preg_split('/\r\n|\r|\n/', (string) $so->comments) as $line) {
                $trimmed = trim($line);
                if ($trimmed !== '') {
                    $outstanding[] = $trimmed;
                }
            }
        }

        // ── Summary counts
        $hasActivity = $worksheet->hasEngineerActivity();

        return [
            'rooms'             => $rooms,
            'outstanding_items' => $outstanding,
            'signoffs'          => $signoffs,
            'summary'           => [
                'photo_count'   => $allCompletedPhotos->count(),
                'label_count'   => $allLabelPhotos->count(),
                'signoff_count' => $signoffs->count(),
                'has_activity'  => $hasActivity,
            ],
        ];
    }

    /**
     * Group a Collection of models with a `room_name` attribute by the
     * normalised (lowercased, trimmed) room name. Mirrors the same key
     * normalisation as Worksheet::photoCountsByRoom() so the View and the
     * PDF resolve rooms identically.
     *
     * @return array<string, Collection>
     */
    private function groupByNormalisedRoomName(Collection $items): array
    {
        $grouped = [];
        foreach ($items as $item) {
            $key = strtolower(trim((string) ($item->room_name ?? '')));
            if ($key === '') {
                continue;
            }
            $grouped[$key] ??= collect();
            $grouped[$key]->push($item);
        }
        return $grouped;
    }
}
