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
    // Schematic-specific exclusion list. INTENTIONALLY broader than
    // InstallTaskGeneratorService / WorksheetGeneratorService:
    //   - Install programme / worksheet show brackets + caddies (real install
    //     tasks the engineer performs).
    //   - Signal-flow schematic does NOT — physical mounting hardware and
    //     storage accessories carry no signal, so showing them as device
    //     nodes is misleading.
    // Cables/consumables/services are line-item-type items in every list.
    private const EXCLUDED_CATEGORIES = [
        'cables',
        'consumables',
        'services',
        'option',
    ];

    // Keyword fallback. Matches against name OR description (lowercased).
    // Order doesn't matter — any match excludes the row from device nodes.
    private const EXCLUDED_KEYWORDS = [
        // Cabling-type line items
        'cable',
        'cat5',
        'cat6',
        'hdmi',
        'usb-a to usb-b',
        // Service-type line items
        'install',
        'commission',
        'project management',
        // Physical mounting hardware (NOT signal devices) — schematic-specific
        'mount',     // "Wall Mount Bracket", "Tilting Wall Mount", "Ceiling Mount", "VESA Mount"
        'bracket',   // "XL Tiling Wall Bracket", "Shelf Bracket"
        // Storage accessories (NOT signal devices) — schematic-specific
        'caddy',     // "Caddy for up to 4 buttons"
        'tray',      // "Button Tray", "ClickShare Tray"
    ];

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
            $devs = $this->reshapeDevices($this->filterHardware($equipment), $signalRoleByPart);

            return [[
                'room_id' => null,
                'room_name' => 'Project schematic',
                'devices' => $devs,
                'cables' => $cables ? $this->reshapeCables($cables) : $this->synthesiseEdgesFromRoles($devs),
            ]];
        }

        $out = [];
        foreach ($rooms as $room) {
            $roomName = (string) ($room['room_name'] ?? $room['name'] ?? '');
            $roomId = isset($room['id']) ? (int) $room['id'] : null;

            $roomEquipment = $this->equipmentForRoom($equipment, $roomName, $room);
            $roomCables = $this->cablesForRoom($cables, $roomName, $roomId);

            $devs = $this->reshapeDevices($this->filterHardware($roomEquipment), $signalRoleByPart);

            // When the cable schedule has no rows for this room (typical for
            // QuoteWerks-imported projects where cables are bundled into a
            // single labour line item), synthesise edges from signal_role
            // classification so the schematic shows useful flow instead of
            // disconnected icons. NOT persisted — render-time only.
            $cablesForRender = $roomCables
                ? $this->reshapeCables($roomCables)
                : $this->synthesiseEdgesFromRoles($devs);

            $out[] = [
                'room_id' => $roomId,
                'room_name' => $roomName,
                'devices' => $devs,
                'cables' => $cablesForRender,
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
     * Drop non-hardware line items (cables, consumables, services, options)
     * before they become device-nodes on the schematic. Cables flow into
     * the schematic as EDGES via $data['cables'] — they must not also appear
     * as nodes. Mirrors InstallTaskGeneratorService::filterHardware().
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function filterHardware(array $items): array
    {
        return array_values(array_filter($items, function (array $item): bool {
            $category = strtolower(trim((string) ($item['category'] ?? $item['item_type'] ?? '')));

            if ($category !== '' && in_array($category, self::EXCLUDED_CATEGORIES, true)) {
                return false;
            }

            // Keyword fallback when category is blank or doesn't match
            $needle = strtolower((string) ($item['name'] ?? $item['description'] ?? ''));
            foreach (self::EXCLUDED_KEYWORDS as $kw) {
                if ($needle !== '' && str_contains($needle, $kw)) {
                    return false;
                }
            }

            return true;
        }));
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
     * Synthesise an edge list from device signal_role classification when the
     * project's cable schedule has no rows for this room. NOT persisted —
     * render-time only. Heuristic, not invented data:
     *   - sources flow toward processors (or destinations if no processor)
     *   - processors flow toward all destinations
     *   - sources flow direct to destinations only when there is no processor
     *
     * Auto-derived edges are tagged signal_type='video' (the most common
     * signal in AV deliverables) and cable_id='AUTO-{n}' so they're visibly
     * distinguishable from real cable schedule rows.
     *
     * @param  array<int, array{equipment_id: int|string, name: string, signal_role: string|null, ...}>  $devices
     * @return array<int, array{cable_id: string, source_equipment_id: int|string, source_port: null, dest_equipment_id: int|string, dest_port: null, signal_type: string}>
     */
    private function synthesiseEdgesFromRoles(array $devices): array
    {
        $sources = [];
        $processors = [];
        $destinations = [];
        foreach ($devices as $d) {
            $role = $d['signal_role'] ?? null;
            if ($role === \App\Models\Device::ROLE_SOURCE) {
                $sources[] = $d;
            } elseif ($role === \App\Models\Device::ROLE_PROCESSOR) {
                $processors[] = $d;
            } elseif ($role === \App\Models\Device::ROLE_DESTINATION) {
                $destinations[] = $d;
            }
        }

        $edges = [];
        $n = 1;
        $emit = function (array $src, array $dst) use (&$edges, &$n): void {
            $edges[] = [
                'cable_id' => 'AUTO-'.$n,
                'source_equipment_id' => $src['equipment_id'],
                'source_port' => null,
                'dest_equipment_id' => $dst['equipment_id'],
                'dest_port' => null,
                'signal_type' => 'video',
            ];
            $n++;
        };

        if (! empty($processors)) {
            // sources → first processor (single hub assumption)
            foreach ($sources as $s) {
                $emit($s, $processors[0]);
            }
            // every processor → every destination (fan-out)
            foreach ($processors as $p) {
                foreach ($destinations as $d) {
                    $emit($p, $d);
                }
            }
        } else {
            // No processor: sources connect direct to destinations
            foreach ($sources as $s) {
                foreach ($destinations as $d) {
                    $emit($s, $d);
                }
            }
        }

        return $edges;
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
