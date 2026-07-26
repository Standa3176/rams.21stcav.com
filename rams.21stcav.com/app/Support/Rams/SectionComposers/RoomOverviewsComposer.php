<?php

namespace App\Support\Rams\SectionComposers;

use App\Models\RamsDocument;
use App\Support\Rams\Sections\RoomOverviewsSectionDto;

/**
 * Composes the Engineer Findings by Room section from
 * $rams->reviewed_data['room_overviews'][].
 *
 * Canonical 4-key shape (per Phase 22.1 Plan 07 normaliser):
 *   room, overview, works_summary, solution_type, room_type
 *
 * Legacy shape (pre-normaliser) used `summary` in place of `works_summary`.
 * Both keys are surfaced on the DTO — works_summary is preferred, summary
 * is retained for renderer fallback compatibility.
 */
final class RoomOverviewsComposer
{
    public function compose(RamsDocument $record): RoomOverviewsSectionDto
    {
        $rd    = $record->reviewed_data ?? [];
        $raw   = (array) ($rd['room_overviews'] ?? []);
        $rows  = [];

        foreach ($raw as $ro) {
            $ro = (array) $ro;
            $room = (string) ($ro['room'] ?? ($ro['room_name'] ?? ($ro['name'] ?? '')));
            if ($room === '') {
                continue;
            }
            // Prefer works_summary over legacy summary; keep both fields on
            // the DTO so PDF/DOCX can pick whichever matches their old ref.
            $worksSummary = (string) ($ro['works_summary'] ?? ($ro['summary'] ?? ''));
            $summary      = (string) ($ro['summary'] ?? '');

            $rows[] = [
                'room'          => $room,
                'overview'      => (string) ($ro['overview']      ?? ''),
                'summary'       => $summary,
                'solution_type' => (string) ($ro['solution_type'] ?? ''),
                'works_summary' => $worksSummary,
                'room_type'     => (string) ($ro['room_type']     ?? ''),
            ];
        }

        return RoomOverviewsSectionDto::fromArray(['rooms' => $rows]);
    }
}
