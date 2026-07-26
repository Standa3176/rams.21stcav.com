<?php

namespace App\Support\Rams\Sections;

/**
 * Engineer Findings by Room — one narrative block per room describing
 * observed site conditions and the works summary for that space.
 *
 * Each entry: [ 'room' => 'Board Room', 'overview' => '...',
 *               'summary' => '...', 'solution_type' => 'MTR',
 *               'works_summary' => '...', 'room_type' => 'meeting_room' ]
 *
 * Populated by RamsDocumentComposer (Plan 02) from
 * SiteSurvey.rooms + solution_type + engineer_findings.
 */
final readonly class RoomOverviewsSectionDto
{
    /**
     * @param  array<int, array<string, string>>  $rooms  Ordered room narrative blocks.
     */
    public function __construct(
        public array $rooms = [],
    ) {}

    public static function fromArray(array $data): self
    {
        $rows = (array) ($data['rooms'] ?? []);

        $normalised = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $normalised[] = [
                'room'          => (string) ($row['room']          ?? ''),
                'overview'      => (string) ($row['overview']      ?? ''),
                'summary'       => (string) ($row['summary']       ?? ''),
                'solution_type' => (string) ($row['solution_type'] ?? ''),
                'works_summary' => (string) ($row['works_summary'] ?? ''),
                'room_type'     => (string) ($row['room_type']     ?? ''),
            ];
        }

        return new self(rooms: $normalised);
    }

    public function isEmpty(): bool
    {
        return $this->rooms === [];
    }
}
