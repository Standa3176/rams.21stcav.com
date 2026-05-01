<?php

namespace App\Services\Drawings;

use App\Core\Modules\Projects\ProjectDataService;
use App\Models\Device;
use App\Models\Project;
use Illuminate\Support\Facades\Log;

/**
 * READ-ONLY reshape of ProjectDataService::resolve() into drawing-shaped views.
 *
 * Generators MUST NOT touch extracted_data / reviewed_data / survey_data
 * directly — Phase 17's contract is "drawings only ever consume the canonical
 * dataset", and this service is the single seam that enforces it.
 *
 * Phase 17 Plan 01 established the SHAPE of adjacencyForProject(); Plan 02
 * fills the body — walks $data['rooms'], $data['equipment'], and
 * $data['cables']; enriches device rows with `signal_role` from the devices
 * table (joined by project_id + part_no) so SchematicD2SourceBuilder can
 * apply CRIT-05 direction rules.
 *
 * rackStackForProject() and floorPlanGlyphsForRoom() are stubbed for
 * build-order doctrine (per ARCHITECTURE.md §8) — Phase 18 / 19 implement
 * them later.
 *
 * @see DATA-03 — locked.
 * @see app/Core/Modules/Projects/ProjectDataService.php
 */
class DrawingDataResolverService
{
    public function __construct(
        private readonly ProjectDataService $projectDataService,
    ) {}

    /**
     * Per-room signal-flow adjacency. Consumed by SchematicGeneratorService
     * (Plan 02). Pulls everything from ProjectDataService::resolve() — never
     * from raw extracted_data / reviewed_data (DATA-03).
     *
     * Device rows are enriched with `signal_role` looked up from the devices
     * table where project_id + part_no matches. When no Device row exists,
     * signal_role falls through to null and the schematic builder will render
     * cables touching that device as undirected lines (CRIT-05 protection).
     *
     * Cables are bucketed per room when the canonical row carries a room hint
     * (`room_id` / `room_name` / `area`); when no hint is present, the cable
     * is included in EVERY room (the generator can dedupe — but this is a
     * legacy-data path; modern packages have area-tagged cables).
     *
     * @return array<int, array{
     *   room_id: int|null,
     *   room_name: string,
     *   devices: array<int, array{equipment_id: int|string, name: string, manufacturer: string|null, model: string|null, signal_role: string|null}>,
     *   cables: array<int, array{cable_id: string, source_equipment_id: int|string|null, source_port: string|null, dest_equipment_id: int|string|null, dest_port: string|null, signal_type: string|null}>,
     * }>
     */
    public function adjacencyForProject(Project $project): array
    {
        $data = $this->projectDataService->resolve($project);

        $rooms = (array) ($data['rooms'] ?? []);
        $equipment = (array) ($data['equipment'] ?? []);
        $cables = (array) ($data['cables'] ?? []);

        if (empty($cables)) {
            Log::info('DrawingDataResolverService: no cables for project', [
                'project_id' => $project->id,
            ]);
        }

        // Pre-load signal_role for every Device on this project, keyed by
        // normalised part_no (lowercase trim) so the per-room loop below
        // can lookup in O(1) without re-hitting the database.
        $signalRoleByPart = $this->loadSignalRolesForProject($project);

        // Empty-rooms fallback — if there is no canonical room list (legacy
        // packages / quote-only projects) but there IS equipment, return a
        // single synthetic room so the schematic still renders.
        if (empty($rooms) && ! empty($equipment)) {
            return [[
                'room_id' => null,
                'room_name' => 'Project schematic',
                'devices' => $this->reshapeDevices($equipment, $signalRoleByPart),
                'cables' => $this->reshapeCables($cables),
            ]];
        }

        $out = [];
        foreach ($rooms as $room) {
            $roomName = (string) ($room['room_name'] ?? $room['name'] ?? '');
            $roomId = isset($room['id']) ? (int) $room['id'] : null;

            $roomEquipment = $this->equipmentForRoom($equipment, $roomName, $room);
            $roomCables = $this->cablesForRoom($cables, $roomName, $roomId);

            $out[] = [
                'room_id' => $roomId,
                'room_name' => $roomName,
                'devices' => $this->reshapeDevices($roomEquipment, $signalRoleByPart),
                'cables' => $this->reshapeCables($roomCables),
            ];
        }

        return $out;
    }

    /**
     * Load per-part signal_role classification for every Device on this
     * project. Returned map is keyed on normalised part_no (lowercase trim);
     * value is the role string (`source` | `destination` | `processor`).
     *
     * Devices with no signal_role set are absent from the map (and therefore
     * fall through to null at lookup time, triggering CRIT-05 undirected
     * rendering).
     *
     * @return array<string, string>
     */
    private function loadSignalRolesForProject(Project $project): array
    {
        $rows = Device::query()
            ->where('project_id', $project->id)
            ->whereNotNull('signal_role')
            ->whereNotNull('part_no')
            ->get(['part_no', 'signal_role']);

        $map = [];
        foreach ($rows as $row) {
            $key = strtolower(trim((string) $row->part_no));
            if ($key === '') {
                continue;
            }
            $map[$key] = (string) $row->signal_role;
        }

        return $map;
    }

    /**
     * Filter the canonical equipment list to a specific room. Mirrors the
     * area-tag distribution convention used by InstallTaskGeneratorService.
     *
     * @param  array<int, array<string, mixed>>  $equipment
     * @param  array<string, mixed>  $room
     * @return array<int, array<string, mixed>>
     */
    private function equipmentForRoom(array $equipment, string $roomName, array $room): array
    {
        if ($roomName === '') {
            return $equipment;
        }

        $needle = strtolower(trim($roomName));

        $matches = array_values(array_filter($equipment, function (array $item) use ($needle): bool {
            $candidates = [
                $item['area'] ?? null,
                $item['room'] ?? null,
                $item['room_name'] ?? null,
                $item['area_name'] ?? null,
                $item['group'] ?? null,
            ];

            foreach ($candidates as $cand) {
                if (! is_string($cand) || trim($cand) === '') {
                    continue;
                }
                if (strtolower(trim($cand)) === $needle) {
                    return true;
                }
            }

            return false;
        }));

        // Fallback: legacy packages embed equipment_list inside the room
        // entry itself — surface those when the area-tag scan finds none.
        if (empty($matches) && isset($room['equipment']) && is_array($room['equipment'])) {
            return array_values($room['equipment']);
        }

        return $matches;
    }

    /**
     * Filter cables to a specific room. Cables carry a `room_id` /
     * `room_name` / `area` hint when the canonical resolver knows the
     * area; otherwise we return ALL cables (legacy-data path).
     *
     * @param  array<int, array<string, mixed>>  $cables
     * @return array<int, array<string, mixed>>
     */
    private function cablesForRoom(array $cables, string $roomName, ?int $roomId): array
    {
        $needle = $roomName !== '' ? strtolower(trim($roomName)) : null;

        $tagged = array_values(array_filter($cables, function (array $cable) use ($needle, $roomId): bool {
            if ($roomId !== null && isset($cable['room_id']) && (int) $cable['room_id'] === $roomId) {
                return true;
            }

            if ($needle === null) {
                return false;
            }

            $candidates = [
                $cable['room'] ?? null,
                $cable['room_name'] ?? null,
                $cable['area'] ?? null,
                $cable['area_name'] ?? null,
            ];

            foreach ($candidates as $cand) {
                if (! is_string($cand) || trim($cand) === '') {
                    continue;
                }
                if (strtolower(trim($cand)) === $needle) {
                    return true;
                }
            }

            return false;
        }));

        // Legacy fallback: if NO cable carries a room hint, return all cables
        // for every room (avoids the schematic rendering only nodes when a
        // pre-area-tag package is the source).
        if (empty($tagged)) {
            $anyTagged = false;
            foreach ($cables as $cable) {
                if (
                    isset($cable['room_id']) || isset($cable['room']) ||
                    isset($cable['room_name']) || isset($cable['area']) ||
                    isset($cable['area_name'])
                ) {
                    $anyTagged = true;
                    break;
                }
            }
            if (! $anyTagged) {
                return $cables;
            }
        }

        return $tagged;
    }

    /**
     * Reshape canonical equipment rows into the documented device shape +
     * enrich with signal_role from the devices table.
     *
     * @param  array<int, array<string, mixed>>  $equipment
     * @param  array<string, string>  $signalRoleByPart
     * @return array<int, array{equipment_id: int|string, name: string, manufacturer: string|null, model: string|null, signal_role: string|null}>
     */
    private function reshapeDevices(array $equipment, array $signalRoleByPart): array
    {
        $out = [];
        foreach ($equipment as $idx => $item) {
            $partNo = isset($item['part_no']) ? (string) $item['part_no'] : '';
            $key = $partNo !== '' ? strtolower(trim($partNo)) : '';

            $out[] = [
                'equipment_id' => $item['id'] ?? $item['equipment_id'] ?? $partNo ?: ('eq-'.$idx),
                'name' => (string) ($item['name'] ?? $item['description'] ?? $item['part_description'] ?? ''),
                'manufacturer' => isset($item['manufacturer']) ? (string) $item['manufacturer'] : null,
                'model' => isset($item['model']) ? (string) $item['model'] : ($partNo ?: null),
                'signal_role' => $key !== '' ? ($signalRoleByPart[$key] ?? null) : null,
            ];
        }

        return $out;
    }

    /**
     * Reshape canonical cable rows into the documented cable shape.
     *
     * @param  array<int, array<string, mixed>>  $cables
     * @return array<int, array{cable_id: string, source_equipment_id: int|string|null, source_port: string|null, dest_equipment_id: int|string|null, dest_port: string|null, signal_type: string|null}>
     */
    private function reshapeCables(array $cables): array
    {
        $out = [];
        foreach ($cables as $cable) {
            $source = (array) ($cable['source'] ?? []);
            $destination = (array) ($cable['destination'] ?? []);

            $out[] = [
                'cable_id' => (string) ($cable['cable_id'] ?? $cable['id'] ?? ''),
                'source_equipment_id' => $source['equipment_id'] ?? $cable['source_equipment_id'] ?? $cable['from_device_id'] ?? null,
                'source_port' => isset($source['port']) ? (string) $source['port'] : (isset($cable['source_port']) ? (string) $cable['source_port'] : null),
                'dest_equipment_id' => $destination['equipment_id'] ?? $cable['dest_equipment_id'] ?? $cable['to_device_id'] ?? null,
                'dest_port' => isset($destination['port']) ? (string) $destination['port'] : (isset($cable['dest_port']) ? (string) $cable['dest_port'] : null),
                'signal_type' => isset($cable['signal_type']) ? (string) $cable['signal_type'] : null,
            ];
        }

        return $out;
    }

    /**
     * Stubbed for Phase 18. Implemented in Phase 18 Plan 01 (Rack Elevations).
     */
    public function rackStackForProject(Project $project): array
    {
        throw new \RuntimeException('rackStackForProject implemented in Phase 18');
    }

    /**
     * Stubbed for Phase 19. Implemented in Phase 19 Plan 01 (Floor Plans).
     */
    public function floorPlanGlyphsForRoom(Project $project, int $roomId): array
    {
        throw new \RuntimeException('floorPlanGlyphsForRoom implemented in Phase 19');
    }
}
