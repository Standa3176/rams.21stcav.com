<?php

namespace App\Services;

use App\Core\Modules\Projects\ProjectDataService;
use App\Models\InstallProgramme;
use App\Models\InstallTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * InstallTaskGeneratorService — generates InstallTask records from ProjectDataService data.
 *
 * Direct analogue to WorksheetGeneratorService but without AI calls.
 * Reads exclusively from ProjectDataService::resolve() — never accesses
 * extracted_data or reviewed_data directly.
 *
 * Generation flow:
 *   InstallProgrammeService::createForProject() → generate($programme) →
 *   resolveAndDistributeRooms($data) returns rooms with their equipment
 *   populated (via Strategy 1 area-tag grouping or Strategy 2 flat distribution),
 *   then one InstallTask is created per hardware item per room in a single DB transaction.
 *
 * Synchronous, no queue. Completes in < 1 second for any real-world project.
 *
 * @see ProjectDataService        — canonical data source (DATA-03)
 * @see InstallProgrammeService   — orchestration layer that calls generate()
 * @see WorksheetGeneratorService — source of the duplicated distribution helpers
 *                                  (resolveAndDistributeRooms, buildRoomsFromAreaTags,
 *                                  recoverRoomsFromEquipment). A shared
 *                                  App\Services\Rooms\RoomEquipmentDistributor
 *                                  extraction is deferred until a 3rd caller appears.
 */
class InstallTaskGeneratorService
{
    // ── Excluded categories (cables, consumables, line items — not field hardware)
    // Same exclusion lists as WorksheetGeneratorService — deliberate duplication.
    // A shared trait is deferred until both services are stable (per PITFALLS.md).
    private const EXCLUDED_CATEGORIES = [
        'cables',
        'consumables',
        'services',
        'option',
    ];

    // ── Keyword fragments for fallback filtering when category is absent ──────
    private const EXCLUDED_KEYWORDS = [
        'cable',
        'cat5',
        'cat6',
        'hdmi',
        'install',
        'commission',
        'project management',
    ];

    // ── Pseudo-rooms that must never become InstallTask.room_name ────────────
    // Mirrors WorksheetGeneratorService's $nonPhysical local array. These names
    // appear as `area`/`location` tags on quote line items but are not real
    // physical rooms — they're billing buckets (Professional Services, Cabling,
    // Licencing, etc.) that the engineer never visits with a screwdriver.
    private const NON_PHYSICAL_ROOMS = [
        'licencing',
        'licensing',
        'cabling',
        'cables',
        'professional services',
        'support services',
        'consumables',
        'services',
        'options',
        'delivery',
        'carriage',
    ];

    public function __construct(
        private readonly ProjectDataService $projectDataService,
    ) {}

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Generate all InstallTask records for the given programme in a single DB transaction.
     *
     * Reads exclusively from ProjectDataService::resolve() — never reads extracted_data directly.
     * No AI call. No job dispatched. Completes synchronously.
     *
     * @param  InstallProgramme $programme  Must have programme->project eager-loaded or resolvable
     * @return void
     *
     * @throws \RuntimeException  If programme has no linked project
     */
    public function generate(InstallProgramme $programme): void
    {
        $project = $programme->project ?? $programme->load('project')->project;

        if ($project === null) {
            throw new \RuntimeException(
                "InstallTaskGeneratorService: programme {$programme->id} has no linked project."
            );
        }

        $data  = $this->projectDataService->resolve($project);
        $rooms = $this->resolveAndDistributeRooms($data);

        DB::transaction(function () use ($programme, $rooms): void {
            foreach ($rooms as $roomIndex => $room) {
                $roomName  = $room['room_name'] ?? $room['name'] ?? 'Unknown Room';
                $roomRef   = $room['room_ref'] ?? null;
                $hardware  = $this->filterHardware($room['equipment'] ?? []);
                $worksDesc = $room['works_summary'] ?? $room['overview'] ?? null;

                foreach ($hardware as $itemIndex => $item) {
                    $equipmentName = $item['name'] ?? $item['description'] ?? 'Unknown Item';

                    InstallTask::create([
                        'install_programme_id' => $programme->id,
                        'room_name'            => $roomName,
                        'room_ref'             => $roomRef,
                        'equipment_name'       => $equipmentName,
                        'quantity'             => $item['quantity'] ?? 1,
                        'equipment_category'   => $item['category'] ?? 'hardware',
                        'task_type'            => InstallTask::TYPE_INSTALL,
                        'title'                => 'Install ' . $equipmentName,
                        'description'          => $worksDesc,
                        'status'               => InstallTask::STATUS_PENDING,
                        'sort_order'           => ($roomIndex * 100) + $itemIndex,
                    ]);
                }
            }
        });

        Log::info('InstallTaskGeneratorService: tasks generated', [
            'programme_id' => $programme->id,
            'project_id'   => $programme->project_id,
            'task_count'   => $programme->tasks()->count(),
        ]);
    }

    // ── DUPLICATION NOTE ─────────────────────────────────────────────────────
    // The three helpers below (resolveAndDistributeRooms, buildRoomsFromAreaTags,
    // recoverRoomsFromEquipment) are duplicated from
    // WorksheetGeneratorService::resolveAndDistributeRooms() per QUICK-260430-UM1.
    // The bug they fix: ProjectDataService::resolveRooms() returns bare-name
    // rooms with `equipment => []`; the actual equipment lives at
    // $data['_raw_equipment'] (with `area` tags) or $data['equipment']. The
    // pre-fix InstallTaskGeneratorService::generate() iterated $data['rooms']
    // directly, found empty equipment arrays, and produced zero tasks.
    //
    // A shared App\Services\Rooms\RoomEquipmentDistributor extraction is
    // deferred until a 3rd caller appears (per the trait-deferral pattern
    // documented for EXCLUDED_CATEGORIES above).
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Resolve and distribute rooms with their equipment populated.
     *
     * Two-strategy approach mirroring WorksheetGeneratorService:
     *
     *   Strategy 1 (preferred): Build rooms from raw equipment area tags.
     *     Used when ≥30% of items in $data['_raw_equipment'] (or $data['equipment'])
     *     have an `area`/`location` field. The area tag is the most reliable
     *     room attribution because it survives QuoteWerks → package import.
     *
     *   Strategy 2 (fallback): Distribute flat equipment into resolved rooms
     *     by location/area string match. Items without a location land in a
     *     synthetic "General" room. Pseudo-rooms (Professional Services etc.)
     *     are filtered before distribution.
     *
     * If the resolved rooms already have hardware in their `equipment` arrays
     * (e.g. survey-enriched rooms), those are kept as-is — no redistribution.
     *
     * @param  array $data  ProjectDataService::resolve() output
     * @return array        Rooms with `equipment` populated, ready for task generation
     */
    private function resolveAndDistributeRooms(array $data): array
    {
        $nonPhysical = self::NON_PHYSICAL_ROOMS;

        // ── Strategy 1: Build rooms directly from raw source equipment with area tags ──
        $rawEquipment   = $data['_raw_equipment'] ?? $data['equipment'] ?? [];
        $roomsFromAreas = $this->buildRoomsFromAreaTags($rawEquipment, $nonPhysical);

        if (! empty($roomsFromAreas)) {
            Log::info('InstallTaskGeneratorService: rooms distributed', [
                'strategy'    => 'area_tag',
                'room_count'  => count($roomsFromAreas),
                'total_items' => array_sum(array_map(fn ($r) => count($r['equipment'] ?? []), $roomsFromAreas)),
            ]);
            return $roomsFromAreas;
        }

        // ── Strategy 2: Use resolved rooms + distribute flat equipment ────────
        $resolvedRooms = $data['rooms'] ?? [];
        $allEquipment  = $this->filterHardware($data['equipment'] ?? []);

        // Filter pseudo-rooms (Professional Services, Cabling, etc.) before any distribution.
        $resolvedRooms = array_values(array_filter($resolvedRooms, function ($room) use ($nonPhysical) {
            $name = strtolower(trim($room['room_name'] ?? $room['name'] ?? ''));
            return ! in_array($name, $nonPhysical, true);
        }));

        if (empty($resolvedRooms) && ! empty($allEquipment)) {
            $resolvedRooms = $this->recoverRoomsFromEquipment($allEquipment);
        } elseif (! empty($resolvedRooms) && ! empty($allEquipment)) {
            // Critical: only redistribute when rooms have no equipment of their own.
            // Survey-enriched rooms (or any pre-populated path) must be preserved as-is.
            $totalRoomEquipment = 0;
            foreach ($resolvedRooms as $room) {
                $totalRoomEquipment += count($this->filterHardware($room['equipment'] ?? []));
            }

            if ($totalRoomEquipment === 0) {
                $roomIndex = [];
                foreach ($resolvedRooms as $i => $room) {
                    $roomIndex[strtolower(trim($room['room_name'] ?? $room['name'] ?? ''))] = $i;
                }
                $unmapped = [];
                foreach ($allEquipment as $eq) {
                    $loc    = strtolower(trim($eq['location'] ?? $eq['area'] ?? $eq['room'] ?? ''));
                    $mapped = false;
                    if ($loc !== '') {
                        foreach ($roomIndex as $rName => $rIdx) {
                            if ($loc === $rName || str_contains($rName, $loc) || str_contains($loc, $rName)) {
                                $resolvedRooms[$rIdx]['equipment'][] = $eq;
                                $mapped = true;
                                break;
                            }
                        }
                    }
                    if (! $mapped) $unmapped[] = $eq;
                }

                if (! empty($unmapped)) {
                    $resolvedRooms[] = [
                        'room_name'   => 'General',
                        'name'        => 'General',
                        'equipment'   => $unmapped,
                        'data_source' => 'unmapped',
                    ];
                }

                $resolvedRooms = array_values(array_filter($resolvedRooms, fn ($r) => ! empty($r['equipment'])));
            }
        }

        Log::info('InstallTaskGeneratorService: rooms distributed', [
            'strategy'    => empty($data['_raw_equipment']) ? 'flat' : 'pre_populated',
            'room_count'  => count($resolvedRooms),
            'total_items' => array_sum(array_map(fn ($r) => count($r['equipment'] ?? []), $resolvedRooms)),
        ]);

        return $resolvedRooms;
    }

    /**
     * Build room entries by grouping equipment items using their 'area' field.
     *
     * Returns an empty array when fewer than 30% of items carry an area tag —
     * caller falls back to Strategy 2 in that case. Non-physical pseudo-rooms
     * are filtered out by name.
     *
     * Duplicated verbatim from WorksheetGeneratorService::buildRoomsFromAreaTags().
     *
     * @param  array $equipment    Raw equipment list (may contain `area`/`location` per item)
     * @param  array $nonPhysical  Lowercase pseudo-room names to exclude
     * @return array               Room entries keyed by area, each with `equipment` populated
     */
    private function buildRoomsFromAreaTags(array $equipment, array $nonPhysical): array
    {
        if (empty($equipment)) return [];

        // Count how many items have an area tag.
        $withArea = 0;
        foreach ($equipment as $item) {
            if (! is_array($item)) continue;
            $area = trim((string) ($item['area'] ?? $item['location'] ?? ''));
            if ($area !== '') $withArea++;
        }

        // Only use this strategy if a majority of items carry area tags.
        if ($withArea < count($equipment) * 0.3) {
            return [];
        }

        $grouped = [];
        foreach ($equipment as $item) {
            if (! is_array($item)) continue;
            $area = trim((string) ($item['area'] ?? $item['location'] ?? ''));
            if ($area === '') $area = 'General';
            $grouped[$area][] = $item;
        }

        // Filter non-physical and build room entries.
        $rooms = [];
        foreach ($grouped as $area => $items) {
            if (in_array(strtolower($area), $nonPhysical, true)) continue;
            $rooms[] = [
                'room_name'   => $area,
                'name'        => $area,
                'equipment'   => $items,
                'data_source' => 'area_tag',
                'confidence'  => 0.9,
            ];
        }

        return $rooms;
    }

    /**
     * Last-resort room recovery when no resolved rooms are present.
     *
     * Groups raw equipment by `location`/`area`/`room`, alphabetised.
     * Items with no room hint land in "General".
     *
     * Duplicated verbatim from WorksheetGeneratorService::recoverRoomsFromEquipment().
     *
     * @param  array $equipment  Equipment list (already hardware-filtered)
     * @return array             Synthetic rooms grouping the equipment
     */
    private function recoverRoomsFromEquipment(array $equipment): array
    {
        if (empty($equipment)) return [];

        $grouped = [];
        foreach ($equipment as $item) {
            $room = trim((string) ($item['location'] ?? $item['area'] ?? $item['room'] ?? ''));
            if ($room === '') $room = 'General';
            // [Rule 1 - Bug] Skip pseudo-rooms here too — otherwise an
            // equipment line tagged with a non-physical location (e.g.
            // 'Professional Services') becomes a recovered "room" and
            // generates a bogus InstallTask. The Strategy 2 caller already
            // filters $resolvedRooms; we need the same guard at recovery.
            if (in_array(strtolower($room), self::NON_PHYSICAL_ROOMS, true)) continue;
            $grouped[$room][] = $item;
        }

        ksort($grouped, SORT_NATURAL | SORT_FLAG_CASE);

        $rooms = [];
        foreach ($grouped as $name => $items) {
            $rooms[] = [
                'room_name'   => $name,
                'name'        => $name,
                'equipment'   => $items,
                'data_source' => 'equipment_recovery',
                'confidence'  => 0.8,
            ];
        }

        return $rooms;
    }

    /**
     * Filter an equipment array to hardware items only.
     *
     * Excludes line items by category (cables, consumables, services, option).
     * Falls back to keyword matching when category is absent.
     *
     * Same logic as WorksheetGeneratorService::filterHardwareItems() — method named
     * filterHardware here (no "Items" suffix) per INST-01 plan spec.
     *
     * @param  array $items  Raw equipment items from ProjectDataService
     * @return array         Hardware-only items (re-indexed)
     */
    public function filterHardware(array $items): array
    {
        return array_values(array_filter($items, function (array $item): bool {
            $category = strtolower(trim($item['category'] ?? ''));

            // ── Category exclusion ────────────────────────────────────────────
            if ($category !== '' && in_array($category, self::EXCLUDED_CATEGORIES, true)) {
                return false;
            }

            // ── Keyword fallback (when category is blank) ─────────────────────
            if ($category === '') {
                $name = strtolower($item['name'] ?? $item['description'] ?? '');
                foreach (self::EXCLUDED_KEYWORDS as $kw) {
                    if (str_contains($name, $kw)) {
                        return false;
                    }
                }
            }

            return true;
        }));
    }
}
