<?php

namespace App\Services;

use App\Core\Modules\Projects\ProjectDataService;
use App\Models\CableSchedule;
use App\Models\CableScheduleItem;
use App\Models\Device;
use App\Models\DeviceCableRule;
use App\Services\Cable\StencilPortResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

    // ── T2-B-ext: central-room fallback for cross-room signal chains ──────────
    //
    // Substring-matched (case-insensitive) against strtolower(trim(room_name)).
    // A device whose room_name matches ANY of these keywords is treated as a
    // "central room" device. When a local room's buildSignalGraph bucket is
    // empty for a signal_type + role (source / processor / destination),
    // matching central-room devices slot in as the fallback endpoint. Local
    // ALWAYS wins — central devices never displace a local counterpart. When
    // a project has zero central-room matches, per-room graphs are
    // byte-for-byte identical to pre-T2-B-ext behaviour.
    private const CENTRAL_ROOM_KEYWORDS = [
        'comms room', 'comms', 'av rack', 'rack room', 'equipment room',
        'server room', 'central', 'plant room',
    ];

    // ── T1-E: retired 2026-07-12 (260712-euh) ─────────────────────────────────
    //
    // The hardcoded DISTANCE_WARNING_RULES + computeDistanceWarnings method
    // used to append '⚠' warnings after inferCableRun() picked a flat cable_type.
    // Length-aware behaviour is now data-driven via DeviceCableRule::length_tiers —
    // the tier picker in inferCableRun() both swaps the cable and appends the
    // appropriate '⚠' / '⚠⚠' warning as part of its return value. See docblock.

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

                $cableInfo = $this->inferCableRun($equipName, $length);
                $cableInfo = $this->applyQuotedCableOverride($cableInfo, $overrides);

                // 260712-euh: length-based tier swap + warning is inside
                // inferCableRun($equipName, $length) now — no post-hoc
                // computeDistanceWarnings() call needed.
                $notes = $cableInfo['notes'] . ($cableRouteDesc ? ' | Route: ' . $cableRouteDesc : '');

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

        // T3-C: decorate any PoE cable rows whose destination switch is
        // approaching or exceeding pse_budget_w. Runs AFTER all rows are
        // persisted so the decorator sees the complete row set. Null-metadata
        // groups are silently skipped inside checkPoeBudgets — soft opt-in.
        $this->checkPoeBudgets($schedule->id);

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

        // T2-B-ext: precompute the central-room device set once per generation.
        // A device is "central" when its room_name substring-matches ANY of
        // CENTRAL_ROOM_KEYWORDS. Empty collection when the project has no
        // central-room match — the byte-for-byte pre-extension contract.
        $centralDevices = $devices->filter(function (Device $d): bool {
            $name = strtolower(trim((string) ($d->room_name ?? '')));
            if ($name === '') {
                return false;
            }
            foreach (self::CENTRAL_ROOM_KEYWORDS as $kw) {
                if ($kw !== '' && str_contains($name, $kw)) {
                    return true;
                }
            }
            return false;
        })->values();

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

            // T2-B-ext: when the room IS the central room, pass an empty
            // central collection so its devices aren't double-counted. The
            // room's $localDevices already IS the central set for itself.
            $isCentralRoom = false;
            foreach (self::CENTRAL_ROOM_KEYWORDS as $kw) {
                if ($kw !== '' && $roomKey !== '' && str_contains($roomKey, $kw)) {
                    $isCentralRoom = true;
                    break;
                }
            }
            $centralForThisRoom = $isCentralRoom ? collect() : $centralDevices;

            $graph    = $this->buildSignalGraph($roomDevices, $centralForThisRoom);
            $created += $this->emitDagEdges(
                $schedule, $graph, $overrides, $lengthM, (string) $roomName, $sortOrder
            );
        }

        // T3-C: post-persist PoE budget check — see generate() for the same
        // pattern. Placed after every DAG + flat row is written so the
        // decorator sees the full row set.
        $this->checkPoeBudgets($schedule->id);

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

            $cableInfo = $this->inferCableRun($equipName, $lengthM);
            $cableInfo = $this->applyQuotedCableOverride($cableInfo, $overrides);

            $rackSuffix = ($device->is_rack_mounted && $device->u_height)
                ? sprintf(' (Rack, %sU)', rtrim(rtrim((string) $device->u_height, '0'), '.'))
                : '';

            $rolePrefix = $device->signal_role
                ? sprintf('[%s] ', $device->signal_role)
                : '';

            // 260712-euh: length tier + warning already inside $cableInfo['notes'].
            $notes = $rolePrefix . $cableInfo['notes'];

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
     * T2-B-ext: after the local walk fills its buckets, central-room devices
     * fall through into any (signal_type + role) bucket left empty by the
     * local walk. Local always wins — a room with a local audio source keeps
     * that source even if the central room has one too. When $centralDevices
     * is empty (project has no central-room match, or the current room IS
     * the central room), behaviour is byte-for-byte identical to pre-ext.
     *
     * @param  Collection<int, Device>  $localDevices    devices in the current room
     * @param  Collection<int, Device>  $centralDevices  devices in the project's central room (empty when N/A)
     * @return array<string, array{sources: list<Device>, processors: list<Device>, destinations: list<Device>}>
     */
    private function buildSignalGraph(Collection $localDevices, Collection $centralDevices): array
    {
        $graph = [];

        foreach ($localDevices as $device) {
            $equipName = trim((string) ($device->manufacturer ?? '') . ' ' . (string) ($device->model ?? ''));
            if ($equipName === '') {
                $equipName = (string) ($device->description ?? '');
            }
            if ($equipName === '') {
                continue;
            }

            // 260712-euh: buildSignalGraph only cares about signal_type for
            // bucketing — length doesn't affect signal_type, so pass null
            // explicitly to avoid appending spurious length warnings here.
            $cableInfo  = $this->inferCableRun($equipName, null);
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

        // T2-B-ext: snapshot which (signal_type + role) buckets the LOCAL walk
        // filled BEFORE walking centrals. This is the "local always wins" gate
        // — central devices only slot into buckets the local room left empty.
        // Taken after the local walk so all local devices count; taken before
        // the central walk so central additions don't self-block.
        $localHas = [];
        foreach ($graph as $type => $buckets) {
            $localHas[$type] = [
                'sources'      => ! empty($buckets['sources']),
                'processors'   => ! empty($buckets['processors']),
                'destinations' => ! empty($buckets['destinations']),
            ];
        }

        foreach ($centralDevices as $device) {
            $equipName = trim((string) ($device->manufacturer ?? '') . ' ' . (string) ($device->model ?? ''));
            if ($equipName === '') {
                $equipName = (string) ($device->description ?? '');
            }
            if ($equipName === '') {
                continue;
            }

            // 260712-euh: buildSignalGraph — length null (see local branch).
            $cableInfo  = $this->inferCableRun($equipName, null);
            $signalType = (string) ($cableInfo['signal_type'] ?? 'unknown');

            if (! isset($graph[$signalType])) {
                $graph[$signalType] = ['sources' => [], 'processors' => [], 'destinations' => []];
            }

            $hasLocalSource = (bool) ($localHas[$signalType]['sources']      ?? false);
            $hasLocalProc   = (bool) ($localHas[$signalType]['processors']   ?? false);
            $hasLocalDest   = (bool) ($localHas[$signalType]['destinations'] ?? false);

            if ($device->isSource() && ! $hasLocalSource) {
                $graph[$signalType]['sources'][] = $device;
            } elseif ($device->isDestination() && ! $hasLocalDest) {
                $graph[$signalType]['destinations'][] = $device;
            } elseif ($device->isProcessor() && ! $hasLocalProc) {
                $graph[$signalType]['processors'][] = $device;
            }
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

        $cableInfo = $this->inferCableRun($fromName, $roomLengthM);
        // Force the row's signal_type to match the DAG bucket so downstream
        // FK resolution stays consistent (fromDevice may be a Q-Sys processor
        // whose inferCableRun returns 'audio' even when we're walking the
        // video edge — trust the graph).
        $cableInfo['signal_type'] = $signalType;
        $cableInfo = $this->applyQuotedCableOverride($cableInfo, $overrides);

        $rolePrefix = $fromDevice->signal_role
            ? sprintf('[%s] ', $fromDevice->signal_role)
            : '';
        // 260712-euh: tier + length warning already merged into $cableInfo['notes'].
        $notes = $rolePrefix . $cableInfo['notes'];

        // T2-B-ext: cross-room prefix when the source device lives in a
        // different room than the target room (i.e. this edge was completed
        // via CENTRAL_ROOM_KEYWORDS fallback). Same-room rows early-out and
        // never receive the prefix. Uses ' | ' separator to match the T1-E
        // warning join convention.
        $fromRoom = trim((string) ($fromDevice->room_name ?? ''));
        if ($fromRoom !== '' && $fromRoom !== $roomName) {
            $notes = sprintf('Cross-room: %s → %s | ', $fromRoom, $roomName) . $notes;
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

        $primaryPayload = [
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
        ];

        CableScheduleItem::create($primaryPayload);
        $emitted = 1;

        // T3-B: emit paired '-R' redundant row when either endpoint is a
        // critical processor. The redundant row shares every column with the
        // primary EXCEPT cable_id (primary + '-R'), sort_order (primary+1),
        // notes (prefixed) and is_redundant=true. Global sort_order counter
        // increments TWICE per critical edge — never fractional.
        if ($this->isCriticalEdge($fromDevice, $toDevice)) {
            $sortOrder++;
            $redundantPayload = $primaryPayload;
            $redundantPayload['cable_id']     = $cableId . '-R';
            $redundantPayload['sort_order']   = $sortOrder;
            $redundantPayload['notes']        = '[REDUNDANT] Primary + backup path — diverse routing recommended | ' . $notes;
            $redundantPayload['is_redundant'] = true;

            CableScheduleItem::create($redundantPayload);
            $emitted++;
        }

        return $emitted;
    }

    /**
     * T3-B — return true when either endpoint of a DAG edge is a Device
     * flagged signal_role=processor AND is_critical=true. Belt-and-braces
     * "either endpoint" rule: a critical processor at EITHER end of the
     * edge triggers the paired redundant row so diverse-routing recommendations
     * surface consistently regardless of walk direction.
     *
     * is_critical strictly === true — null (pre-migration) and false both
     * short-circuit to false, keeping the feature soft opt-in.
     */
    private function isCriticalEdge(Device $fromDevice, ?Device $toDevice): bool
    {
        if ($fromDevice->isProcessor() && $fromDevice->is_critical === true) {
            return true;
        }
        if ($toDevice !== null && $toDevice->isProcessor() && $toDevice->is_critical === true) {
            return true;
        }
        return false;
    }

    // =========================================================================
    // T3-C — PoE BUDGET SOLVER (post-persist decorator)
    // =========================================================================

    /**
     * T3-C — walk every PoE cable row in the persisted schedule, group by
     * destination switch, and append a '⚠ PoE budget…' or '⚠⚠ OVER BUDGET…'
     * warning when the group's total pd_load_w meets/exceeds 80% / 100% of
     * the switch's pse_budget_w.
     *
     * Soft opt-in gates (each silently skips the group when tripped):
     *   - destination Device missing / not ROLE_DESTINATION
     *   - destination display name doesn't contain 'switch'
     *   - pse_budget_w null / <= 0
     *   - ANY source pd_load_w null in the group (all-or-nothing)
     *
     * Non-PoE rows never receive a warning — the group filter is scoped by
     * a case-insensitive '/poe/i' match on the row's cable_type BEFORE any
     * DB aggregate is computed. Bulk UPDATE per group keeps N (typical: 4-24
     * ports per switch) → 1 SQL statement per group instead of N.
     *
     * Driver-aware CONCAT expression: MySQL/MariaDB use CONCAT(...); sqlite
     * uses ||. Single-quote escape is standard SQL doubling ("''") so the
     * warning string (server-computed from numeric watts + hard-coded
     * literals + Device fields) survives both drivers.
     */
    private function checkPoeBudgets(int $scheduleId): void
    {
        // Load every candidate row on the schedule; PHP-side preg_match
        // filter avoids porting a MySQL LIKE collation to sqlite tests.
        $rows = CableScheduleItem::where('cable_schedule_id', $scheduleId)
            ->whereNotNull('dest_device_id')
            ->get(['id', 'cable_type', 'notes', 'dest_device_id', 'source_device_id']);

        $rows = $rows->filter(
            fn ($r) => $r->cable_type !== null && preg_match('/poe/i', (string) $r->cable_type) === 1
        );

        if ($rows->isEmpty()) {
            return;
        }

        $groups = $rows->groupBy('dest_device_id');

        foreach ($groups as $destId => $group) {
            $switch = Device::find($destId);
            if ($switch === null) {
                continue;
            }
            if ($switch->signal_role !== Device::ROLE_DESTINATION) {
                continue;
            }

            $displayName = trim(((string) ($switch->manufacturer ?? '')) . ' ' . ((string) ($switch->model ?? '')));
            if ($displayName === '') {
                $displayName = (string) ($switch->description ?? 'Switch');
            }

            $lowerHay = strtolower($displayName);
            if (! str_contains($lowerHay, 'switch')) {
                continue;
            }

            $budget = $switch->pse_budget_w;
            if ($budget === null || (float) $budget <= 0.0) {
                continue;
            }

            // Sum pd_load_w across every DISTINCT source in the group. Any
            // null bails the whole group — soft opt-in, no partial estimates.
            $sourceIds = $group->pluck('source_device_id')->filter()->unique()->values();
            if ($sourceIds->isEmpty()) {
                continue;
            }

            $sources = Device::whereIn('id', $sourceIds)->get(['id', 'pd_load_w']);
            if ($sources->contains(fn ($d) => $d->pd_load_w === null)) {
                continue;
            }

            $total = (float) $sources->sum('pd_load_w');
            $pct   = (int) round($total / (float) $budget * 100);

            if ($pct < 80) {
                continue;
            }

            $warning = $pct >= 100
                ? sprintf(
                    '⚠⚠ OVER BUDGET Switch %s PoE budget: %sW of %sW (%d%%)',
                    $displayName,
                    $this->fmtWatts($total),
                    $this->fmtWatts((float) $budget),
                    $pct
                )
                : sprintf(
                    '⚠ Switch %s PoE budget: %sW of %sW (%d%%)',
                    $displayName,
                    $this->fmtWatts($total),
                    $this->fmtWatts((float) $budget),
                    $pct
                );

            // Bulk UPDATE per group. Driver-aware CONCAT keeps prod (MySQL)
            // and tests (sqlite) both green without introducing an ORM per-
            // row overhead. Standard SQL '' escape doubles single quotes.
            $ids       = $group->pluck('id')->all();
            $driver    = DB::connection()->getDriverName();
            $prefix    = $warning . ' | ';
            $prefixEsc = str_replace("'", "''", $prefix);

            $expr = ($driver === 'mysql' || $driver === 'mariadb')
                ? DB::raw("CONCAT('" . $prefixEsc . "', COALESCE(notes, ''))")
                : DB::raw("'" . $prefixEsc . "' || COALESCE(notes, '')");

            CableScheduleItem::whereIn('id', $ids)->update(['notes' => $expr]);
        }
    }

    /**
     * Format a watts value for display: strip trailing zeros and any
     * dangling decimal point. 62.0 → '62', 62.5 → '62.5', 15.75 → '15.75'.
     */
    private function fmtWatts(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
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

            // 260712-euh: standalone quote flow has no survey → pass null
            // length. Length-tiered rules return their tier 1 (safest passive)
            // + a "Length not confirmed" warning appended to notes.
            $cableInfo = $this->inferCableRun($equipName, null);
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
     *
     * Quick task 260711-q7q — refactored from a 13-branch hardcoded cascade
     * into a data-driven walk over DeviceCableRule::forInference(). Rules
     * are ordered by priority ASC; the first rule whose keyword list
     * word-boundary-matches the equipment name wins. Missing / disabled
     * rules fall through to the TBC placeholder so the schedule still
     * generates cleanly. Admin CRUD lives at /admin/device-cable-rules.
     *
     * Quick task 260712-euh — length-tier picker. When the matched rule has
     * a non-empty `length_tiers` array, pickTier() walks it ascending on
     * `max_m` and returns the first tier whose max_m ≥ $lengthM; the
     * tier's cable_type / cores / to_endpoint / notes OVERRIDE the flat
     * row. Length null → first tier + '⚠ Length not confirmed' warning.
     * Length over-max → last tier + '⚠⚠ exceeds max range' warning.
     * signal_type stays from the flat rule (tier picker never touches it,
     * DAG walker + XLSX colouring depend on stable signal_type).
     * Null / empty tiers → flat row returned unchanged.
     */
    private function inferCableRun(string $equipName, ?float $lengthM = null): array
    {
        $lower = strtolower($equipName);

        foreach (DeviceCableRule::forInference() as $rule) {
            if (! $this->matchesAny($lower, (array) $rule->keywords)) {
                continue;
            }

            $flatRow = [
                'cable_type'  => (string) $rule->cable_type,
                'signal_type' => (string) $rule->signal_type,
                'cores'       => $rule->cores,
                'to'          => (string) $rule->to_endpoint,
                'notes'       => (string) ($rule->notes ?? ''),
            ];

            $tiers = $rule->length_tiers;
            if (! is_array($tiers) || $tiers === []) {
                return $flatRow;
            }

            return $this->pickTier($tiers, $lengthM, $flatRow);
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

    /**
     * 260712-euh — pick a length_tier entry.
     *
     * Contract:
     *   - $lengthM null              → tier[0] + '⚠ Length not confirmed…' notes
     *   - first tier where max_m ≥ L → that tier
     *   - $lengthM > every tier max  → last tier + '⚠⚠ exceeds max range…' notes
     *
     * Only cable_type / cores / to_endpoint / notes are overridden. signal_type
     * stays from the parent flat rule so the DAG walker + XLSX colouring
     * stay consistent (a fibre HDMI tier is still 'video', not 'network').
     *
     * @param  array<int, array<string, mixed>>  $tiers    ascending on max_m (sorted by FormRequest)
     * @param  array<string, mixed>              $flatRow  full inferCableRun() flat shape
     * @return array<string, mixed>
     */
    private function pickTier(array $tiers, ?float $lengthM, array $flatRow): array
    {
        if ($lengthM === null) {
            $chosen  = $tiers[0];
            $warning = '⚠ Length not confirmed on survey — assuming passive tier';
            return $this->mergeTierIntoFlat($chosen, $flatRow, $warning);
        }

        foreach ($tiers as $tier) {
            $maxM = $tier['max_m'] ?? null;
            if ($maxM === null || (float) $lengthM <= (float) $maxM) {
                return $this->mergeTierIntoFlat($tier, $flatRow, null);
            }
        }

        // Over-max: append the last tier + escalation warning.
        $chosen    = end($tiers) ?: $tiers[array_key_last($tiers)];
        $lengthTxt = rtrim(rtrim(number_format((float) $lengthM, 1, '.', ''), '0'), '.');
        $warning   = sprintf(
            '⚠⚠ Length %sm exceeds max range for this cable type — consider signal repeater / regen',
            $lengthTxt
        );

        return $this->mergeTierIntoFlat($chosen, $flatRow, $warning);
    }

    /**
     * 260712-euh — merge a tier row into the flat inferCableRun() shape.
     * Only overrides cable_type / cores / to / notes; signal_type is preserved.
     * The optional $warning is appended to notes via the ' | ' separator that
     * the rest of the service uses.
     *
     * @param  array<string, mixed>  $tier
     * @param  array<string, mixed>  $flatRow
     */
    private function mergeTierIntoFlat(array $tier, array $flatRow, ?string $warning): array
    {
        $tierNotes = (string) ($tier['notes'] ?? '');
        if ($warning !== null) {
            $tierNotes = $tierNotes === '' ? $warning : $tierNotes . ' | ' . $warning;
        }

        return [
            'cable_type'  => (string) ($tier['cable_type'] ?? $flatRow['cable_type']),
            'signal_type' => $flatRow['signal_type'],
            'cores'       => $tier['cores'] ?? $flatRow['cores'],
            'to'          => (string) ($tier['to_endpoint'] ?? $flatRow['to']),
            'notes'       => $tierNotes,
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
