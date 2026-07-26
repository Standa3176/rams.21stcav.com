<?php

namespace App\Support\Rams\Sections;

/**
 * Section 4 — Scope of Works.
 *
 * activities        — plain-text summary bullets ("what we're doing")
 * per_room_scope    — map<room_name, string[]> of per-room activity bullets
 * new_install       — new equipment items to be installed
 * decommission      — legacy equipment being decommissioned
 * retained          — retained equipment that stays in place
 *
 * Each equipment item shape: [ 'item_name' => 'Poly X50', 'qty' => '2',
 *                              'room' => 'Board Room', 'notes' => '...' ]
 *
 * Populated by RamsDocumentComposer (Plan 02) from ProjectPackage
 * equipment_list + reviewed scope activities.
 */
final readonly class ScopeSectionDto
{
    /**
     * @param  array<int, string>                       $activities      Cross-project activity bullets.
     * @param  array<string, array<int, string>>        $perRoomScope    room => activities[]
     * @param  array<int, array<string, string>>        $newInstall      Equipment rows.
     * @param  array<int, array<string, string>>        $decommission    Equipment rows.
     * @param  array<int, array<string, string>>        $retained        Equipment rows.
     */
    public function __construct(
        public array $activities   = [],
        public array $perRoomScope = [],
        public array $newInstall   = [],
        public array $decommission = [],
        public array $retained     = [],
    ) {}

    public static function fromArray(array $data): self
    {
        $normaliseEquipmentRows = static function (mixed $rows): array {
            $rows = (array) $rows;
            $out = [];
            foreach ($rows as $row) {
                $row = (array) $row;
                $out[] = [
                    'item_name' => (string) ($row['item_name'] ?? ''),
                    'qty'       => (string) ($row['qty']       ?? ''),
                    'room'      => (string) ($row['room']      ?? ''),
                    'notes'     => (string) ($row['notes']     ?? ''),
                ];
            }
            return $out;
        };

        $perRoom = [];
        foreach ((array) ($data['per_room_scope'] ?? []) as $room => $items) {
            $perRoom[(string) $room] = array_values(array_map('strval', (array) $items));
        }

        return new self(
            activities:   array_values(array_map('strval', (array) ($data['activities'] ?? []))),
            perRoomScope: $perRoom,
            newInstall:   $normaliseEquipmentRows($data['new_install']   ?? []),
            decommission: $normaliseEquipmentRows($data['decommission'] ?? []),
            retained:     $normaliseEquipmentRows($data['retained']     ?? []),
        );
    }

    public function isEmpty(): bool
    {
        return $this->activities === []
            && $this->perRoomScope === []
            && $this->newInstall === []
            && $this->decommission === []
            && $this->retained === [];
    }
}
