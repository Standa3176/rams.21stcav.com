<?php

namespace App\Services;

use App\Core\Modules\Projects\ProjectDataService;
use App\Models\CableSchedule;
use App\Models\CableScheduleItem;
use App\Models\Device;
use App\Services\Cable\StencilPortResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Deterministic cable schedule generator.
 *
 * Produces cable run rows from connectivity intent — not a flat equipment dump.
 * Each row represents an actual cable run between two endpoints.
 *
 * Classification pipeline:
 *   1. Classify every line item (install_hardware / cable_consumable / labour / existing)
 *   2. Only install_hardware produces cable rows
 *   3. Cable type inferred from equipment name (not blanket default)
 *   4. Endpoints resolved from subsystem context
 *
 * No AI. Deterministic keyword matching only.
 */
class CableScheduleGeneratorService
{
    // ── Items that should NEVER produce cable rows ────────────────────────────

    private const LABOUR_KEYWORDS = [
        'install', 'installation', 'commission', 'commissioning', 'programming',
        'configuration', 'project management', 'survey', 'travel', 'labour',
        'training', 'handover', 'design', 'engineering', 'support', 'delivery',
        'carriage', 'logistics', 'first fix', 'second fix', 'drawing',
        'document', 'additional', 'misc',
    ];

    private const CONSUMABLE_KEYWORDS = [
        'consumable', 'fixing', 'screw', 'bolt', 'anchor', 'tie',
        'velcro', 'tape', 'label', 'grommet', 'cleat', 'rawlplug',
    ];

    private const MOUNT_KEYWORDS = [
        'mount', 'mounting', 'bracket', 'stand', 'shelf', 'cradle',
        'tilt', 'swivel', 'arm', 'pole',
    ];

    private const NON_PHYSICAL_ROOMS = [
        'licencing', 'licensing', 'cabling', 'cables', 'professional services',
        'support services', 'consumables', 'services', 'options', 'delivery', 'carriage',
    ];

    // ── T1-D: quoted cable product → signal_type reclassification map ─────────
    //
    // Consumables classified by classifyItems() as 'cable_consumable' walk this
    // map to pin their signal_type. First matching bucket wins per consumable.
    // Special case (handled in code, not here): 'shure' + a 'network' bucket
    // hit reclassifies to 'audio' — Shure Microflex Wireless is Cat-over-
    // Ethernet audio, not general networking.
    private const QUOTED_CABLE_SIGNAL_KEYWORDS = [
        'video'   => ['hdmi', 'displayport', 'dp', 'sdi', 'coax'],
        'network' => ['cat5', 'cat6', 'cat6a', 'cat7', 'rj45', 'ethernet', 'network'],
        'audio'   => ['xlr', 'trs', 'balanced', 'unbalanced'],   // shure+cat handled in code
        'speaker' => ['speaker cable'],
        'usb'     => ['usb'],
        'power'   => ['iec', 'mains', 'power'],
    ];

    // ── T2-B: signal-path DAG processor ordering ──────────────────────────────
    //
    // Substring-matched against strtolower(manufacturer . ' ' . model). First
    // matching keyword wins per device; unmatched processors sort LAST in
    // Device.id order (see sortProcessors). Kept generous so common brand
    // names still resolve — 'q-sys' contains 'dsp' in the string 'core',
    // 'biamp tesira' hits nothing here so it lands last (engineer sanity-
    // check should catch that case).
    private const SIGNAL_PATH_ORDER = [
        'dsp', 'audio-processor', 'matrix', 'switcher', 'codec', 'amplifier',
    ];

    // ── T1-E: distance-warning matrix ─────────────────────────────────────────
    //
    // Applied after computing $cableInfo. Rules walked in declaration order;
    // matched warnings joined with ' | ' and appended to notes.
    //   - cable_type_regex : preg regex tested against the row's cable_type
    //   - threshold_m      : length > this in metres triggers the warning
    //   - requires_cores   : null = any; non-null = must string-match exactly
    private const DISTANCE_WARNING_RULES = [
        [
            'cable_type_regex' => '/\bHDMI\b/i',
            'threshold_m'      => 15,
            'requires_cores'   => null,
            'warning'          => '⚠ HDMI passive > 15m unreliable — recommend HDBaseT extender',
        ],
        [
            'cable_type_regex' => '/\bCat6a?\b.*PoE/i',
            'threshold_m'      => 100,
            'requires_cores'   => null,
            'warning'          => '⚠ Cat6 PoE > 100m — power delivery unreliable',
        ],
        [
            'cable_type_regex' => '/HDBaseT|Cat6a \(shielded\)/i',
            'threshold_m'      => 100,
            'requires_cores'   => null,
            'warning'          => '⚠ HDBaseT max range 100m Cat6a — split with fibre extender',
        ],
        [
            'cable_type_regex' => '/speaker cable/i',
            'threshold_m'      => 30,
            'requires_cores'   => '2',
            'warning'          => '⚠ Long speaker run — consider 4-core star quad or thicker gauge',
        ],
    ];

    public function __construct(
        private readonly ProjectDataService $projectDataService,
        private readonly StencilPortResolver $stencilResolver,
    ) {}

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    public function generate(CableSchedule $schedule): int
    {
        $project = $schedule->relationLoaded('project')
            ? $schedule->project
            : $schedule->project()->first();

        // T1-B: prefer Device model when project has one. Devices know their
        // room, rack position, and signal role — higher fidelity than quote
        // lines. Zero-device projects fall through to the byte-for-byte
        // existing quote-line path below.
        $devices = Device::where('project_id', $project->id)->get();
        if ($devices->isNotEmpty()) {
            return $this->generateFromDevices($schedule, $devices);
        }

        $data      = $this->projectDataService->resolve($project);
        $rooms     = $this->resolveRoomsWithEquipment($data);
        $lengthMap = $this->buildRoomLengthMap($project);   // T1-E
        $sortOrder = 0;
        $created   = 0;

        foreach ($rooms as $room) {
            $roomName       = (string) ($room['room_name'] ?? $room['name'] ?? 'Unknown Room');
            $cableRouteDesc = $room['cable_route_desc'] ?? null;
            $allItems       = $room['equipment'] ?? [];

            // Classify every item
            $classified = $this->classifyItems($allItems);

            // T1-D: build quoted-cable override map from the room's consumables
            // so every install_hardware row whose inferred signal_type has a
            // matching consumable adopts that consumable's cable_type + a
            // "Quoted: <name>" notes prefix.
            $overrides = $this->buildQuotedCableOverrides($classified['cable_consumable']);

            // T1-E: resolve the approx length for THIS room from the survey
            // narrative map. Case-insensitive trim match. Null when no survey
            // narrative parseable.
            $length = $lengthMap[strtolower(trim($roomName))] ?? null;

            // Only install_hardware produces cable rows
            foreach ($classified['install_hardware'] as $item) {
                $equipName = (string) ($item['name'] ?? $item['description'] ?? '');
                if ($equipName === '') continue;

                $equipNameShort = Str::limit($equipName, 180, '…');

                $cableInfo = $this->inferCableRun($equipName);
                $cableInfo = $this->applyQuotedCableOverride($cableInfo, $overrides);

                // T1-E: append distance warnings when length + rule matches.
                $warnings = $this->computeDistanceWarnings($cableInfo['cable_type'], $cableInfo['cores'], $length);
                $notes    = $cableInfo['notes'] . ($cableRouteDesc ? ' | Route: ' . $cableRouteDesc : '');
                if (! empty($warnings)) {
                    $notes .= ' | ' . implode(' | ', $warnings);
                }

                $sortOrder++;
                $cableId = 'C-' . str_pad((string) $sortOrder, 3, '0', STR_PAD_LEFT);

                CableScheduleItem::create([
                    'cable_schedule_id' => $schedule->id,
                    'cable_id'          => $cableId,
                    'from_location'     => $roomName . ' — ' . $equipNameShort,
                    'to_location'       => $cableInfo['to'],
                    'cable_type'        => $cableInfo['cable_type'],
                    'signal_type'       => $cableInfo['signal_type'],
                    'cores'             => $cableInfo['cores'],
                    'approx_length_m'   => $length,
                    'notes'             => $notes,
                    'sort_order'        => $sortOrder,
                ]);

                $created++;
            }
        }

        Log::info('CableScheduleGeneratorService: generation complete', [
            'cable_schedule_id' => $schedule->id,
            'items_created'     => $created,
            'rooms_processed'   => count($rooms),
        ]);

        return $created;
    }

    /**
     * T1-B + T2-B: Device-preferred generation orchestrator.
     *
     * Per-room dispatcher between the T2-B DAG walker and the T1-B flat
     * fallback. Every room's classified device set is inspected: if EVERY
     * device is unclassified (hasUnknownSignalRole()), the room falls
     * through to generateFromDevicesFlat so the pre-T2-B row-per-device
     * behaviour still ships. Any classified device flips the room to the
     * DAG walker, which emits one row per (source, destination) edge along
     * the signal_type-scoped chain including intermediate processors.
     *
     * Every emitted row — DAG or flat — is decorated with T1-D quoted
     * overrides + T1-E length/warnings + T2-A port FKs. sort_order is a
     * single global counter threaded across all rooms so cable_id stays
     * monotonic (C-001, C-002, …).
     *
     * @param  Collection<int, Device>  $devices
     */
    private function generateFromDevices(CableSchedule $schedule, Collection $devices): int
    {
        // T1-D + T1-E + T2-A: preload project data + attach stencils once.
        $project           = $schedule->project()->first();
        $consumablesByRoom = $this->buildConsumablesByRoom($project);
        $lengthMap         = $this->buildRoomLengthMap($project);
        $this->stencilResolver->attachToDevices($devices);

        $devicesByRoom = $devices->groupBy(fn (Device $d) =>
            (trim((string) ($d->room_name ?? '')) ?: 'Unknown Room'));

        $sortOrder = 0;
        $created   = 0;

        foreach ($devicesByRoom as $roomName => $roomDevices) {
            $roomKey    = strtolower(trim((string) $roomName));
            $overrides  = $this->buildQuotedCableOverrides($consumablesByRoom[$roomKey] ?? []);
            $lengthM    = $lengthMap[$roomKey] ?? null;

            // Feature gate — if EVERY device in the room is unclassified,
            // fall through to the flat legacy path (row-per-device). One
            // classified device tips the room into DAG mode.
            $allUnknown = $roomDevices->every(fn (Device $d) => $d->hasUnknownSignalRole());

            if ($allUnknown) {
                $created += $this->generateFromDevicesFlat(
                    $schedule, $roomDevices, $overrides, $lengthM, (string) $roomName, $sortOrder
                );
                continue;
            }

            $graph    = $this->buildSignalGraph($roomDevices);
            $created += $this->emitDagEdges(
                $schedule, $graph, $overrides, $lengthM, (string) $roomName, $sortOrder
            );
        }

        Log::info('CableScheduleGeneratorService: generateFromDevices complete', [
            'cable_schedule_id' => $schedule->id,
            'items_created'     => $created,
            'devices_seen'      => $devices->count(),
        ]);

        return $created;
    }

    /**
     * T1-B flat fallback — one row per device. Used when EVERY device in the
     * room has hasUnknownSignalRole() so we cannot walk a signal-aware DAG.
     * Decorates each row with T1-D + T1-E + T2-A (source-side only; dest_*
     * stays null because the flat path has no destination Device).
     *
     * @param  Collection<int, Device>  $roomDevices
     */
    private function generateFromDevicesFlat(
        CableSchedule $schedule,
        Collection $roomDevices,
        array $overrides,
        ?float $lengthM,
        string $roomName,
        int &$sortOrder,
    ): int {
        $created = 0;
        $displayRoom = $roomName ?: 'Unknown Room';

        foreach ($roomDevices as $device) {
            $mfr       = trim((string) ($device->manufacturer ?? ''));
            $mdl       = trim((string) ($device->model ?? ''));
            $equipName = trim($mfr . ' ' . $mdl);
            if ($equipName === '') {
                $equipName = trim((string) ($device->description ?? ''));
            }
            if ($equipName === '') {
                continue;
            }

            $equipNameShort = Str::limit($equipName, 180, '…');

            $cableInfo = $this->inferCableRun($equipName);
            $cableInfo = $this->applyQuotedCableOverride($cableInfo, $overrides);

            $rackSuffix = ($device->is_rack_mounted && $device->u_height)
                ? sprintf(' (Rack, %sU)', rtrim(rtrim((string) $device->u_height, '0'), '.'))
                : '';

            $rolePrefix = $device->signal_role
                ? sprintf('[%s] ', $device->signal_role)
                : '';

            $notes    = $rolePrefix . $cableInfo['notes'];
            $warnings = $this->computeDistanceWarnings($cableInfo['cable_type'], $cableInfo['cores'], $lengthM);
            if (! empty($warnings)) {
                $notes .= ' | ' . implode(' | ', $warnings);
            }

            $sortOrder++;
            $cableId = 'C-' . str_pad((string) $sortOrder, 3, '0', STR_PAD_LEFT);

            $srcPortId = $this->resolveSourcePortId($device, (string) ($cableInfo['signal_type'] ?? ''));

            CableScheduleItem::create([
                'cable_schedule_id' => $schedule->id,
                'cable_id'          => $cableId,
                'from_location'     => $displayRoom . ' — ' . $equipNameShort . $rackSuffix,
                'to_location'       => $cableInfo['to'],
                'cable_type'        => $cableInfo['cable_type'],
                'signal_type'       => $cableInfo['signal_type'],
                'cores'             => $cableInfo['cores'],
                'approx_length_m'   => $lengthM,
                'notes'             => $notes,
                'sort_order'        => $sortOrder,
                'source_device_id'  => $device->id,
                'source_port_id'    => $srcPortId,
                'dest_device_id'    => null,
                'dest_port_id'      => null,
            ]);

            $created++;
        }

        return $created;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // T2-B — signal-path DAG traversal
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Bucket a room's devices by inferred signal_type → signal_role. Sources
     * carry the outgoing signal, processors sit in the middle in
     * SIGNAL_PATH_ORDER, destinations sink it. Signal types with zero
     * classified devices in ANY bucket are omitted so emitDagEdges skips them.
     *
     * @param  Collection<int, Device>  $devicesInRoom
     * @return array<string, array{sources: list<Device>, processors: list<Device>, destinations: list<Device>}>
     */
    private function buildSignalGraph(Collection $devicesInRoom): array
    {
        $graph = [];

        foreach ($devicesInRoom as $device) {
            $equipName = trim((string) ($device->manufacturer ?? '') . ' ' . (string) ($device->model ?? ''));
            if ($equipName === '') {
                $equipName = (string) ($device->description ?? '');
            }
            if ($equipName === '') {
                continue;
            }

            $cableInfo  = $this->inferCableRun($equipName);
            $signalType = (string) ($cableInfo['signal_type'] ?? 'unknown');

            if (! isset($graph[$signalType])) {
                $graph[$signalType] = ['sources' => [], 'processors' => [], 'destinations' => []];
            }

            if ($device->isSource()) {
                $graph[$signalType]['sources'][] = $device;
            } elseif ($device->isDestination()) {
                $graph[$signalType]['destinations'][] = $device;
            } elseif ($device->isProcessor()) {
                $graph[$signalType]['processors'][] = $device;
            }
            // hasUnknownSignalRole() devices don't slot into any bucket — the
            // per-room feature gate in generateFromDevices catches the
            // all-unknown case and routes to the flat fallback.
        }

        // Sort processors within each signal_type bucket by SIGNAL_PATH_ORDER.
        foreach ($graph as $type => $buckets) {
            $graph[$type]['processors'] = $this->sortProcessors($buckets['processors']);
        }

        // Drop empty signal_type buckets to keep emitDagEdges tight.
        return array_filter(
            $graph,
            fn ($b) => ! empty($b['sources']) || ! empty($b['processors']) || ! empty($b['destinations'])
        );
    }

    /**
     * Rank processor devices by SIGNAL_PATH_ORDER (first-substring match wins);
     * unmatched processors sort last in Device.id order. Stable within ties.
     *
     * @param  array<int, Device>  $processors
     * @return list<Device>
     */
    private function sortProcessors(array $processors): array
    {
        $ranked = array_map(function (Device $d) {
            $haystack = strtolower(trim((string) ($d->manufacturer ?? '') . ' ' . (string) ($d->model ?? '')));
            $rank = count(self::SIGNAL_PATH_ORDER);
            foreach (self::SIGNAL_PATH_ORDER as $i => $keyword) {
                if ($haystack !== '' && str_contains($haystack, $keyword)) {
                    $rank = $i;
                    break;
                }
            }
            return ['rank' => $rank, 'device' => $d, 'id' => (int) ($d->id ?? PHP_INT_MAX)];
        }, $processors);

        usort($ranked, function ($a, $b) {
            return $a['rank'] <=> $b['rank']
                ?: $a['id'] <=> $b['id'];
        });

        return array_map(fn ($r) => $r['device'], $ranked);
    }

    /**
     * Walk each signal_type bucket in the graph and emit one CableScheduleItem
     * per edge in the signal chain:
     *   for each (source × destination) pair:
     *     emit source → processor[0] → processor[1] → … → destination
     * When sources exist but no destinations, emit ONE placeholder edge per
     * source with dest_device_id null + a Log::warning; the last processor
     * (if any) is emitted as an intermediate hop to itself.
     *
     * @param  array<string, array{sources: list<Device>, processors: list<Device>, destinations: list<Device>}>  $graph
     */
    private function emitDagEdges(
        CableSchedule $schedule,
        array $graph,
        array $overrides,
        ?float $roomLengthM,
        string $roomName,
        int &$sortOrder,
    ): int {
        $created = 0;

        foreach ($graph as $signalType => $bucket) {
            $sources      = $bucket['sources'];
            $processors   = $bucket['processors'];
            $destinations = $bucket['destinations'];

            // sinks-only (destinations without sources in this room) is a
            // no-op — the signal originates elsewhere; T2-B extension will
            // handle cross-room chains.
            if (empty($sources)) {
                continue;
            }

            if (empty($destinations)) {
                // sources without destinations: emit one TBC placeholder chain
                // per source (source → processors → TBC) and log for engineer.
                foreach ($sources as $srcDevice) {
                    $chain = array_merge([$srcDevice], $processors);
                    // Walk source → processor[0] → processor[1] … → last processor
                    for ($i = 0; $i < count($chain) - 1; $i++) {
                        $created += $this->createDagEdge(
                            $schedule, $chain[$i], $chain[$i + 1], $signalType,
                            $overrides, $roomLengthM, $roomName, $sortOrder
                        );
                    }
                    // Final hop to TBC — dest_device_id null.
                    $created += $this->createDagEdge(
                        $schedule, end($chain) ?: $srcDevice, null, $signalType,
                        $overrides, $roomLengthM, $roomName, $sortOrder
                    );
                    Log::warning('CableScheduleGeneratorService: signal without destination', [
                        'room_name'   => $roomName,
                        'device_id'   => $srcDevice->id,
                        'signal_type' => $signalType,
                    ]);
                }
                continue;
            }

            // source × destination Cartesian product; each pair walks the
            // full source → processors → destination chain.
            foreach ($sources as $srcDevice) {
                foreach ($destinations as $dstDevice) {
                    $chain = array_merge([$srcDevice], $processors, [$dstDevice]);
                    for ($i = 0; $i < count($chain) - 1; $i++) {
                        $created += $this->createDagEdge(
                            $schedule, $chain[$i], $chain[$i + 1], $signalType,
                            $overrides, $roomLengthM, $roomName, $sortOrder
                        );
                    }
                }
            }
        }

        return $created;
    }

    /**
     * Persist a single CableScheduleItem edge. from Device required; to
     * Device null on the "→ TBC" placeholder case.
     */
    private function createDagEdge(
        CableSchedule $schedule,
        Device $fromDevice,
        ?Device $toDevice,
        string $signalType,
        array $overrides,
        ?float $roomLengthM,
        string $roomName,
        int &$sortOrder,
    ): int {
        $fromName = trim((string) ($fromDevice->manufacturer ?? '') . ' ' . (string) ($fromDevice->model ?? ''));
        if ($fromName === '') {
            $fromName = (string) ($fromDevice->description ?? '');
        }
        if ($fromName === '') {
            return 0;
        }
        $fromShort = Str::limit($fromName, 180, '…');

        $cableInfo = $this->inferCableRun($fromName);
        // Force the row's signal_type to match the DAG bucket so downstream
        // FK resolution stays consistent (fromDevice may be a Q-Sys processor
        // whose inferCableRun returns 'audio' even when we're walking the
        // video edge — trust the graph).
        $cableInfo['signal_type'] = $signalType;
        $cableInfo = $this->applyQuotedCableOverride($cableInfo, $overrides);

        $rolePrefix = $fromDevice->signal_role
            ? sprintf('[%s] ', $fromDevice->signal_role)
            : '';
        $notes    = $rolePrefix . $cableInfo['notes'];
        $warnings = $this->computeDistanceWarnings($cableInfo['cable_type'], $cableInfo['cores'], $roomLengthM);
        if (! empty($warnings)) {
            $notes .= ' | ' . implode(' | ', $warnings);
        }

        $rackSuffixFrom = ($fromDevice->is_rack_mounted && $fromDevice->u_height)
            ? sprintf(' (Rack, %sU)', rtrim(rtrim((string) $fromDevice->u_height, '0'), '.'))
            : '';

        if ($toDevice !== null) {
            $toName = trim((string) ($toDevice->manufacturer ?? '') . ' ' . (string) ($toDevice->model ?? ''));
            if ($toName === '') {
                $toName = (string) ($toDevice->description ?? 'TBC');
            }
            $toShort = Str::limit($toName, 180, '…');
            $rackSuffixTo = ($toDevice->is_rack_mounted && $toDevice->u_height)
                ? sprintf(' (Rack, %sU)', rtrim(rtrim((string) $toDevice->u_height, '0'), '.'))
                : '';
            $toLocation = $roomName . ' — ' . $toShort . $rackSuffixTo;
        } else {
            $toLocation = 'TBC — no destination in room';
        }

        $sortOrder++;
        $cableId = 'C-' . str_pad((string) $sortOrder, 3, '0', STR_PAD_LEFT);

        CableScheduleItem::create([
            'cable_schedule_id' => $schedule->id,
            'cable_id'          => $cableId,
            'from_location'     => $roomName . ' — ' . $fromShort . $rackSuffixFrom,
            'to_location'       => $toLocation,
            'cable_type'        => $cableInfo['cable_type'],
            'signal_type'       => $cableInfo['signal_type'],
            'cores'             => $cableInfo['cores'],
            'approx_length_m'   => $roomLengthM,
            'notes'             => $notes,
            'sort_order'        => $sortOrder,
            'source_device_id'  => $fromDevice->id,
            'source_port_id'    => $this->resolveSourcePortId($fromDevice, $signalType),
            'dest_device_id'    => $toDevice?->id,
            'dest_port_id'      => $toDevice ? $this->resolveDestPortId($toDevice, $signalType) : null,
        ]);

        return 1;
    }

    /**
     * Build cable schedule rows from extracted quote equipment lines.
     *
     * This is used by the manual upload flow (CableScheduleController::store),
     * where we have text lines but no project context model.
     *
     * @param  array<int, string|array>  $lines
     * @return array<int, array<string, mixed>>
     */
    public function buildRowsFromEquipmentLines(array $lines, string $sourceLabel = 'Quote Line'): array
    {
        $items = [];

        foreach ($lines as $line) {
            $name = $this->extractLineName($line);
            if ($name === '') {
                continue;
            }

            $items[] = [
                'name'        => $name,
                'description' => $name,
                'category'    => is_array($line) ? strtolower(trim((string) ($line['category'] ?? ''))) : '',
                'status'      => is_array($line) ? strtolower(trim((string) ($line['status'] ?? $line['item_type'] ?? ''))) : '',
            ];
        }

        $classified = $this->classifyItems($items);
        $rows = [];
        $sortOrder = 0;

        // T1-D: buildRowsFromEquipmentLines has no room context, so all
        // consumables collapse into one global override map applied to every
        // install_hardware row.
        $overrides = $this->buildQuotedCableOverrides($classified['cable_consumable']);

        foreach ($classified['install_hardware'] as $item) {
            $equipName = (string) ($item['name'] ?? $item['description'] ?? '');
            if ($equipName === '') {
                continue;
            }

            $equipNameShort = Str::limit($equipName, 180, '…');

            $cableInfo = $this->inferCableRun($equipName);
            $cableInfo = $this->applyQuotedCableOverride($cableInfo, $overrides);
            $sortOrder++;

            $rows[] = [
                'cable_id'        => 'C-' . str_pad((string) $sortOrder, 3, '0', STR_PAD_LEFT),
                'from_location'   => trim($sourceLabel) . ' — ' . $equipNameShort,
                'to_location'     => $cableInfo['to'],
                'cable_type'      => $cableInfo['cable_type'],
                'signal_type'     => $cableInfo['signal_type'],
                'cores'           => $cableInfo['cores'],
                'approx_length_m' => null,
                'notes'           => $cableInfo['notes'],
                'sort_order'      => $sortOrder,
            ];
        }

        return $rows;
    }

    // =========================================================================
    // ROOM RESOLUTION (distribute flat equipment to rooms when needed)
    // =========================================================================

    private function resolveRoomsWithEquipment(array $data): array
    {
        $rooms     = $data['rooms'] ?? [];
        $equipment = $data['equipment'] ?? [];

        // Filter non-physical rooms
        $rooms = array_values(array_filter($rooms, function ($r) {
            $name = strtolower(trim($r['room_name'] ?? $r['name'] ?? ''));
            return ! in_array($name, self::NON_PHYSICAL_ROOMS, true);
        }));

        // Check room equipment counts
        $totalRoomEquipment = 0;
        foreach ($rooms as $room) {
            $totalRoomEquipment += count($room['equipment'] ?? []);
        }

        if ($totalRoomEquipment === 0 && ! empty($equipment)) {
            // Distribute flat equipment to first physical room (or create General)
            if (empty($rooms)) {
                $rooms = [['room_name' => 'General', 'name' => 'General', 'equipment' => []]];
            }
            $rooms[0]['equipment'] = $equipment;
        }

        return $rooms;
    }

    // =========================================================================
    // ITEM CLASSIFICATION
    // =========================================================================

    private function classifyItems(array $items): array
    {
        $result = [
            'install_hardware'   => [],
            'cable_consumable'   => [],
            'existing_reuse'     => [],
            'labour_or_document' => [],
        ];

        foreach ($items as $item) {
            if (! is_array($item)) continue;

            $name     = strtolower(trim($item['name'] ?? $item['description'] ?? ''));
            $category = strtolower(trim($item['category'] ?? ''));
            $status   = strtolower(trim($item['status'] ?? $item['item_type'] ?? ''));

            // Skip empty / junk
            if ($name === '' || $name === 'additional' || $name === 'misc') {
                $result['labour_or_document'][] = $item;
                continue;
            }

            // Labour / document / service
            if (in_array($category, ['services', 'option'], true) || $status === 'professional_service') {
                $result['labour_or_document'][] = $item;
                continue;
            }
            if ($this->matchesAny($name, self::LABOUR_KEYWORDS)) {
                $result['labour_or_document'][] = $item;
                continue;
            }

            // Actual cable/consumable items (the cables themselves, not equipment needing cables)
            if (in_array($category, ['cables', 'consumables'], true) || $status === 'consumable') {
                $result['cable_consumable'][] = $item;
                continue;
            }
            if ($this->matchesAny($name, self::CONSUMABLE_KEYWORDS)) {
                $result['cable_consumable'][] = $item;
                continue;
            }
            // Cable products (HDMI cable, Cat6 cable, patch cable, speaker cable etc.)
            if ($this->isCableProduct($name)) {
                $result['cable_consumable'][] = $item;
                continue;
            }

            // Mounts/brackets — not cable endpoints
            if ($this->matchesAny($name, self::MOUNT_KEYWORDS) && ! $this->isEquipmentWithMount($name)) {
                $result['labour_or_document'][] = $item;
                continue;
            }

            // Existing / retained
            if (str_contains($name, 'existing') || str_contains($name, 'exisiting')
                || str_contains($name, 'retained') || str_contains($name, 'utilise')
                || str_contains($status, 'existing') || str_contains($status, 'retain')) {
                $result['existing_reuse'][] = $item;
                continue;
            }

            // Default: install hardware (produces cable rows)
            $result['install_hardware'][] = $item;
        }

        return $result;
    }

    // =========================================================================
    // CABLE RUN INFERENCE — from equipment name, not blanket defaults
    // =========================================================================

    /**
     * Determine cable type, destination, and notes for a single equipment item.
     * Returns cable_type + signal_type based on what the equipment ACTUALLY needs,
     * not a default. signal_type is one of the 8 keys in
     * config('cables.signal_type_colours'); the XLSX layer uses it to tint the
     * Signal column at ~15% opacity.
     */
    private function inferCableRun(string $equipName): array
    {
        $lower = strtolower($equipName);

        // ── Display / projection → HDMI ──────────────────────────────────────
        if ($this->matchesAny($lower, ['display', 'screen', 'monitor', 'tv', 'samsung', 'lg',
            'sony', 'uhd', '4k', 'oled', 'qled', 'qm85', 'qe65', 'qe75', 'projector'])) {
            return [
                'cable_type'  => 'HDMI 2.0',
                'signal_type' => 'video',
                'cores'       => null,
                'to'          => 'AV Rack / Matrix Switcher',
                'notes'       => 'Signal: HDMI from source/matrix',
            ];
        }

        // ── HDBaseT extender → Cat6a ─────────────────────────────────────────
        if ($this->matchesAny($lower, ['hdbaset', 'extender', 'csc'])) {
            return [
                'cable_type'  => 'Cat6a (shielded)',
                'signal_type' => 'video',
                'cores'       => null,
                'to'          => 'Display / Receiver',
                'notes'       => 'HDBaseT link — max 70m Cat6a',
            ];
        }

        // ── Speaker → speaker cable ──────────────────────────────────────────
        if ($this->matchesAny($lower, ['speaker', 'pendant', 'loudspeaker'])) {
            return [
                'cable_type'  => '2-core speaker cable (1.5mm LSZH)',
                'signal_type' => 'speaker',
                'cores'       => '2',
                'to'          => 'Amplifier output',
                'notes'       => 'Speaker level from amplifier',
            ];
        }

        // ── Microphone → Cat6 (Shure) or XLR ────────────────────────────────
        if ($this->matchesAny($lower, ['microphone', 'mic', 'mxw', 'lavalier'])) {
            $isShure = $this->matchesAny($lower, ['shure', 'mxw', 'mx']);
            return [
                'cable_type'  => $isShure ? 'Cat6 (Shure network)' : 'XLR',
                'signal_type' => 'audio',
                'cores'       => $isShure ? null : '3',
                'to'          => $isShure ? 'Shure access point / DSP' : 'DSP / Mixer input',
                'notes'       => $isShure ? 'Shure Microflex Wireless' : 'Analogue mic input',
            ];
        }

        // ── DSP / audio processor → Cat6 (Dante) ────────────────────────────
        if ($this->matchesAny($lower, ['dsp', 'q-sys', 'qsys', 'biamp', 'audio processor'])) {
            return [
                'cable_type'  => 'Cat6 (Dante/AES67)',
                'signal_type' => 'audio',
                'cores'       => null,
                'to'          => 'Network switch (AV VLAN)',
                'notes'       => 'Dante audio network',
            ];
        }

        // ── Amplifier → Cat6 (Dante) or analogue ─────────────────────────────
        if ($this->matchesAny($lower, ['amplifier', 'amp', 'lea audio', 'lea '])) {
            $isDante = $this->matchesAny($lower, ['dante', 'lea']);
            return [
                'cable_type'  => $isDante ? 'Cat6 (Dante)' : 'Audio Multicore',
                'signal_type' => 'audio',
                'cores'       => null,
                'to'          => $isDante ? 'Network switch (AV VLAN)' : 'DSP output',
                'notes'       => $isDante ? 'Dante amplifier — network audio' : 'Analogue from DSP',
            ];
        }

        // ── Cisco / VC codec → Cat6 PoE ─────────────────────────────────────
        if ($this->matchesAny($lower, ['cisco', 'room kit', 'codec', 'poly', 'logitech'])) {
            return [
                'cable_type'  => 'Cat6 (PoE)',
                'signal_type' => 'video',
                'cores'       => null,
                'to'          => 'Network switch (PoE)',
                'notes'       => 'VC codec — requires PoE+ or local PSU',
            ];
        }

        // ── Camera / PTZ → Cat6 PoE ──────────────────────────────────────────
        if ($this->matchesAny($lower, ['camera', 'ptz', 'quad cam', 'webcam'])) {
            return [
                'cable_type'  => 'Cat6 (PoE)',
                'signal_type' => 'video',
                'cores'       => null,
                'to'          => 'Codec / Network switch (PoE)',
                'notes'       => 'Camera — PoE powered',
            ];
        }

        // ── Touch panel / navigator → Cat6 PoE ──────────────────────────────
        if ($this->matchesAny($lower, ['navigator', 'touch panel', 'keypad', 'button panel'])) {
            return [
                'cable_type'  => 'Cat6 (PoE)',
                'signal_type' => 'control',
                'cores'       => null,
                'to'          => 'Network switch (PoE)',
                'notes'       => 'Control interface — PoE powered',
            ];
        }

        // ── Control / sensor → Cat6 ─────────────────────────────────────────
        if ($this->matchesAny($lower, ['control', 'crestron', 'extron', 'amx', 'sensor', 'partition'])) {
            return [
                'cable_type'  => 'Cat6',
                'signal_type' => 'control',
                'cores'       => null,
                'to'          => 'Control processor',
                'notes'       => 'Control signal',
            ];
        }

        // ── Network switch → Cat6 (uplink) ──────────────────────────────────
        if ($this->matchesAny($lower, ['switch', 'netgear', 'cisco switch'])) {
            return [
                'cable_type'  => 'Cat6',
                'signal_type' => 'network',
                'cores'       => null,
                'to'          => 'Building network / patch panel',
                'notes'       => 'Uplink to client network',
            ];
        }

        // ── Patch panel → Cat6 ──────────────────────────────────────────────
        if ($this->matchesAny($lower, ['patch panel', 'keystone'])) {
            return [
                'cable_type'  => 'Cat6',
                'signal_type' => 'network',
                'cores'       => null,
                'to'          => 'Network switch',
                'notes'       => 'Patch panel termination',
            ];
        }

        // ── Wireless mic access point → Cat6 PoE ────────────────────────────
        if ($this->matchesAny($lower, ['mxwapx', 'access point', 'wap'])) {
            return [
                'cable_type'  => 'Cat6 (PoE)',
                'signal_type' => 'audio',
                'cores'       => null,
                'to'          => 'Network switch (PoE)',
                'notes'       => 'Wireless mic access point',
            ];
        }

        // ── Unknown hardware → TBC ──────────────────────────────────────────
        return [
            'cable_type'  => 'TBC',
            'signal_type' => 'unknown',
            'cores'       => null,
            'to'          => 'TBC — confirm on survey',
            'notes'       => 'Cable type to be confirmed during site survey',
        ];
    }

    // =========================================================================
    // T2-A — PORT-LEVEL FK RESOLUTION
    // =========================================================================

    /**
     * Pick the source-side port on a Device by signal_type. Prefers
     * direction='out'; also accepts unset direction with side='right' (the
     * legacy Tier-1 convention where "right" == outbound). Sorted by
     * DevicePort::$sort_order ASC; first pick wins. Returns null when the
     * device has no stencil, no ports, or no matching port.
     */
    private function resolveSourcePortId(Device $device, string $signalType): ?int
    {
        $stencil = $device->getRelation('stencil') ?? null;
        if ($stencil === null || $stencil->ports === null || $stencil->ports->isEmpty()) {
            return null;
        }

        $ports = $stencil->ports
            ->filter(function ($p) use ($signalType) {
                if ($p->signal_type !== $signalType) {
                    return false;
                }
                if ($p->direction === 'out') {
                    return true;
                }
                if ($p->direction === null && $p->side === 'right') {
                    return true;
                }
                return false;
            })
            ->sortBy('sort_order')
            ->values();

        return $ports->first()?->id;
    }

    /**
     * Symmetric destination-side pick. Prefers direction='in'; falls back to
     * direction null + side='left'. See resolveSourcePortId docblock.
     */
    private function resolveDestPortId(Device $device, string $signalType): ?int
    {
        $stencil = $device->getRelation('stencil') ?? null;
        if ($stencil === null || $stencil->ports === null || $stencil->ports->isEmpty()) {
            return null;
        }

        $ports = $stencil->ports
            ->filter(function ($p) use ($signalType) {
                if ($p->signal_type !== $signalType) {
                    return false;
                }
                if ($p->direction === 'in') {
                    return true;
                }
                if ($p->direction === null && $p->side === 'left') {
                    return true;
                }
                return false;
            })
            ->sortBy('sort_order')
            ->values();

        return $ports->first()?->id;
    }

    // =========================================================================
    // T1-D — QUOTED CABLE OVERRIDES
    // =========================================================================

    /**
     * Walk the room's cable_consumable bucket and produce a per-signal_type
     * override map. First matching bucket wins per consumable. Shure + Cat
     * network gear is reclassified as audio (Shure Microflex Wireless is
     * Cat-over-Ethernet AUDIO, not general networking).
     *
     * Multiple consumables of the same signal_type join with ' / ' in the
     * input array order — deterministic. Empty input returns an empty map.
     *
     * @param  array<int, array<string, mixed>>  $consumables classifyItems() 'cable_consumable' bucket
     * @return array<string, string>  signal_type => concatenated cable_type display name
     */
    private function buildQuotedCableOverrides(array $consumables): array
    {
        $byType = [];

        foreach ($consumables as $c) {
            $rawName = (string) ($c['name'] ?? $c['description'] ?? '');
            if ($rawName === '') {
                continue;
            }

            $lower = strtolower($rawName);
            $matched = null;

            foreach (self::QUOTED_CABLE_SIGNAL_KEYWORDS as $type => $keywords) {
                if ($this->matchesAny($lower, $keywords)) {
                    $matched = $type;
                    break;
                }
            }

            if ($matched === null) {
                continue;
            }

            // Shure Cat-over-Ethernet special case: 'shure' + 'network'
            // reclassifies to 'audio' because Shure Microflex Wireless is
            // Cat-audio, not general networking.
            if ($matched === 'network' && $this->matchesAny($lower, ['shure'])) {
                $matched = 'audio';
            }

            if (! isset($byType[$matched])) {
                $byType[$matched] = [];
            }
            $byType[$matched][] = trim($rawName);
        }

        // Join same-signal_type consumables with ' / ' preserving array order.
        return array_map(fn (array $names) => implode(' / ', $names), $byType);
    }

    /**
     * Apply the per-signal_type overrides to a single inferCableRun() shape.
     * Only touches cable_type + notes; signal_type, cores, to unchanged.
     *
     * @param  array<string, mixed>   $cableInfo output of inferCableRun()
     * @param  array<string, string>  $overrides output of buildQuotedCableOverrides()
     * @return array<string, mixed>
     */
    private function applyQuotedCableOverride(array $cableInfo, array $overrides): array
    {
        $signalType = (string) ($cableInfo['signal_type'] ?? '');
        if ($signalType === '' || ! isset($overrides[$signalType])) {
            return $cableInfo;
        }

        $override = $overrides[$signalType];
        $cableInfo['cable_type'] = $override;
        $cableInfo['notes']      = 'Quoted: ' . $override . ' | ' . ($cableInfo['notes'] ?? '');

        return $cableInfo;
    }

    /**
     * Per-room consumable bucket for device-preferred generation path. Walks
     * ProjectDataService::resolve()'s room set, filters non-physical rooms,
     * and classifies each room's equipment so downstream code can build
     * quoted-cable overrides + apply them per device row.
     *
     * Returned map is keyed by strtolower(trim(room_name)) so device lookups
     * (which also lowercase-trim) match reliably. Null project → empty map.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function buildConsumablesByRoom(?object $project): array
    {
        if ($project === null) {
            return [];
        }

        $data  = $this->projectDataService->resolve($project);
        $rooms = $data['rooms'] ?? [];
        $out   = [];

        foreach ($rooms as $room) {
            $name = strtolower(trim((string) ($room['room_name'] ?? $room['name'] ?? '')));
            if ($name === '' || in_array($name, self::NON_PHYSICAL_ROOMS, true)) {
                continue;
            }

            $classified = $this->classifyItems($room['equipment'] ?? []);
            if (! empty($classified['cable_consumable'])) {
                $out[$name] = $classified['cable_consumable'];
            }
        }

        return $out;
    }

    // =========================================================================
    // T1-E — SURVEY NARRATIVE LENGTH + DISTANCE WARNINGS
    // =========================================================================

    /**
     * Extract the LAST numeric metre measurement from a narrative string. The
     * regex is deliberately greedy on m/metre/meter variants so engineer
     * copy-paste from different sources normalises. Returns null on empty
     * text or no match.
     *
     * Examples:
     *   'Route drops 3m at wall then extends 45m to rack' → 45.0
     *   '12.5 metres to comms room'                       → 12.5
     *   'no numeric measurement'                          → null
     */
    private function parseLengthFromNarrative(?string $text): ?float
    {
        if ($text === null) {
            return null;
        }
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        if (preg_match_all('/(\d+(?:\.\d+)?)\s*(?:m|metres?|meters?)\b/i', $text, $m) === false) {
            return null;
        }

        if (empty($m[1])) {
            return null;
        }

        return (float) end($m[1]);
    }

    /**
     * Priority chain for the room's cable-route narrative:
     *   1. cable_routes (JSON array of {category,from,to,length_m,notes}) —
     *      concatenate notes + synthetic "X m" markers for each length_m so
     *      the regex naturally picks up the last measurement.
     *   2. engineer_feedback['cable_routes'] — legacy JSON payload holder;
     *      no-op on the current schema but kept for forward compatibility.
     *   3. cable_route_desc — legacy single-row text column.
     *
     * First non-empty (after trim) wins. Returns null on empty/missing rooms.
     */
    private function extractRoomNarrative(\App\Models\SiteSurveyRoom $room): ?string
    {
        // Priority 1: JSON cable_routes array (post-260503-rgg schema). Cast
        // is 'array' on the model so we always get an array or null. Build
        // a synthetic narrative: join non-empty notes and append "<length> m"
        // markers per entry so parseLengthFromNarrative picks up the last.
        $routes = $room->cable_routes;
        if (is_array($routes) && ! empty($routes)) {
            $parts = [];
            foreach ($routes as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $note = trim((string) ($entry['notes'] ?? ''));
                if ($note !== '') {
                    $parts[] = $note;
                }
                $len = $entry['length_m'] ?? null;
                if ($len !== null && $len !== '') {
                    $parts[] = ((string) $len) . ' m';
                }
            }
            $joined = trim(implode(' | ', $parts));
            if ($joined !== '') {
                return $joined;
            }
        }

        // Priority 2: engineer_feedback JSON blob's cable_routes key (future
        // holder — no such column on the current site_survey_rooms schema,
        // so data_get returns null and this branch is a no-op).
        $ef = data_get($room, 'engineer_feedback.cable_routes');
        if (is_string($ef) && trim($ef) !== '') {
            return trim($ef);
        }

        // Priority 3: legacy cable_route_desc text column.
        $legacy = trim((string) ($room->cable_route_desc ?? ''));
        if ($legacy !== '') {
            return $legacy;
        }

        return null;
    }

    /**
     * Walk DISTANCE_WARNING_RULES and return the list of matched warnings for
     * a single row. Empty when length is unknown or no rule matched.
     *
     * @return array<int, string>
     */
    private function computeDistanceWarnings(string $cableType, ?string $cores, ?float $lengthM): array
    {
        if ($lengthM === null) {
            return [];
        }

        $warnings = [];
        foreach (self::DISTANCE_WARNING_RULES as $rule) {
            if (! preg_match($rule['cable_type_regex'], $cableType)) {
                continue;
            }
            if ($lengthM <= $rule['threshold_m']) {
                continue;
            }
            if ($rule['requires_cores'] !== null && (string) $cores !== $rule['requires_cores']) {
                continue;
            }
            $warnings[] = $rule['warning'];
        }

        return $warnings;
    }

    /**
     * Build a per-room approx_length map keyed by strtolower(trim(room_name))
     * from the project's LATEST site survey. Rooms whose narrative parses to
     * no numeric length are omitted. Null project or no survey → empty map.
     *
     * @return array<string, float>
     */
    private function buildRoomLengthMap(?object $project): array
    {
        if ($project === null) {
            return [];
        }

        // Project has siteSurveys() HasMany. Use the latest by created_at so
        // repeat surveys don't confuse the length lookup.
        try {
            $survey = method_exists($project, 'siteSurveys')
                ? $project->siteSurveys()->latest()->first()
                : null;
        } catch (\Throwable $e) {
            return [];
        }

        if ($survey === null) {
            return [];
        }

        $out = [];
        foreach ($survey->rooms as $room) {
            $roomName = strtolower(trim((string) ($room->room_name ?? '')));
            if ($roomName === '') {
                continue;
            }
            $length = $this->parseLengthFromNarrative($this->extractRoomNarrative($room));
            if ($length !== null) {
                $out[$roomName] = $length;
            }
        }

        return $out;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Check if a name represents a cable product (not equipment that needs cables).
     */
    private function isCableProduct(string $name): bool
    {
        // Must contain a cable-type keyword AND a cable-product indicator
        $cableTypes = ['hdmi', 'cat5', 'cat6', 'cat6a', 'displayport', 'usb', 'sdi', 'rg6', 'ethernet', 'speaker cable', 'patch cable'];
        $productIndicators = ['cable', 'lead', 'patch', '305m', '100m', '50m', 'reel', 'drum', 'shielded'];

        $hasCableType = $this->matchesAny($name, $cableTypes);
        $hasProduct   = $this->matchesAny($name, $productIndicators);

        return $hasCableType && $hasProduct;
    }

    /**
     * Check if a name is equipment that happens to include "mount" (e.g. "ceiling mount camera")
     * vs a standalone mount/bracket accessory.
     */
    private function isEquipmentWithMount(string $name): bool
    {
        $equipKeywords = ['camera', 'projector', 'speaker', 'display', 'codec', 'sensor'];
        return $this->matchesAny($name, $equipKeywords);
    }

    private function matchesAny(string $haystack, array $keywords): bool
    {
        foreach ($keywords as $kw) {
            if ($kw === '') continue;
            // T1-C: word-boundary match kills false positives — "amp" in
            // "lamp", "mic" in "Microsoft", "csc" in "Cisco". \b handles
            // alpha/digit boundaries. Multi-word keywords like "patch
            // panel" still match because \b is between-token, not per-char.
            // The `i` flag is defensive — all current call sites lowercase
            // the haystack first, but a future consumer might not.
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/i', $haystack) === 1) {
                return true;
            }
        }
        return false;
    }

    private function extractLineName(string|array $line): string
    {
        if (is_string($line)) {
            return trim($line);
        }

        if (! is_array($line)) {
            return '';
        }

        return trim((string) ($line['name'] ?? $line['description'] ?? $line['line'] ?? ''));
    }
}
