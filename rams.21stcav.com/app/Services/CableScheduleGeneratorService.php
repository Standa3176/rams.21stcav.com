<?php

namespace App\Services;

use App\Core\Modules\Projects\ProjectDataService;
use App\Models\CableSchedule;
use App\Models\CableScheduleItem;
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

    public function __construct(
        private readonly ProjectDataService $projectDataService,
    ) {}

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    public function generate(CableSchedule $schedule): int
    {
        $project = $schedule->relationLoaded('project')
            ? $schedule->project
            : $schedule->project()->first();

        $data      = $this->projectDataService->resolve($project);
        $rooms     = $this->resolveRoomsWithEquipment($data);
        $sortOrder = 0;
        $created   = 0;

        foreach ($rooms as $room) {
            $roomName       = (string) ($room['room_name'] ?? $room['name'] ?? 'Unknown Room');
            $cableRouteDesc = $room['cable_route_desc'] ?? null;
            $allItems       = $room['equipment'] ?? [];

            // Classify every item
            $classified = $this->classifyItems($allItems);

            // Only install_hardware produces cable rows
            foreach ($classified['install_hardware'] as $item) {
                $equipName = (string) ($item['name'] ?? $item['description'] ?? '');
                if ($equipName === '') continue;

                $equipNameShort = Str::limit($equipName, 180, '…');

                $cableInfo = $this->inferCableRun($equipName);

                $sortOrder++;
                $cableId = 'C-' . str_pad((string) $sortOrder, 3, '0', STR_PAD_LEFT);

                CableScheduleItem::create([
                    'cable_schedule_id' => $schedule->id,
                    'cable_id'          => $cableId,
                    'from_location'     => $roomName . ' — ' . $equipNameShort,
                    'to_location'       => $cableInfo['to'],
                    'cable_type'        => $cableInfo['cable_type'],
                    'cores'             => $cableInfo['cores'],
                    'approx_length_m'   => null,
                    'notes'             => $cableInfo['notes'] . ($cableRouteDesc ? ' | Route: ' . $cableRouteDesc : ''),
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

        foreach ($classified['install_hardware'] as $item) {
            $equipName = (string) ($item['name'] ?? $item['description'] ?? '');
            if ($equipName === '') {
                continue;
            }

            $equipNameShort = Str::limit($equipName, 180, '…');

            $cableInfo = $this->inferCableRun($equipName);
            $sortOrder++;

            $rows[] = [
                'cable_id'        => 'C-' . str_pad((string) $sortOrder, 3, '0', STR_PAD_LEFT),
                'from_location'   => trim($sourceLabel) . ' — ' . $equipNameShort,
                'to_location'     => $cableInfo['to'],
                'cable_type'      => $cableInfo['cable_type'],
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
     * Returns cable_type based on what the equipment ACTUALLY needs, not a default.
     */
    private function inferCableRun(string $equipName): array
    {
        $lower = strtolower($equipName);

        // ── Display / projection → HDMI ──────────────────────────────────────
        if ($this->matchesAny($lower, ['display', 'screen', 'monitor', 'tv', 'samsung', 'lg',
            'sony', 'uhd', '4k', 'oled', 'qled', 'qm85', 'qe65', 'qe75', 'projector'])) {
            return [
                'cable_type' => 'HDMI 2.0',
                'cores'      => null,
                'to'         => 'AV Rack / Matrix Switcher',
                'notes'      => 'Signal: HDMI from source/matrix',
            ];
        }

        // ── HDBaseT extender → Cat6a ─────────────────────────────────────────
        if ($this->matchesAny($lower, ['hdbaset', 'extender', 'csc'])) {
            return [
                'cable_type' => 'Cat6a (shielded)',
                'cores'      => null,
                'to'         => 'Display / Receiver',
                'notes'      => 'HDBaseT link — max 70m Cat6a',
            ];
        }

        // ── Speaker → speaker cable ──────────────────────────────────────────
        if ($this->matchesAny($lower, ['speaker', 'pendant', 'loudspeaker'])) {
            return [
                'cable_type' => '2-core speaker cable (1.5mm LSZH)',
                'cores'      => '2',
                'to'         => 'Amplifier output',
                'notes'      => 'Speaker level from amplifier',
            ];
        }

        // ── Microphone → Cat6 (Shure) or XLR ────────────────────────────────
        if ($this->matchesAny($lower, ['microphone', 'mic', 'mxw', 'lavalier'])) {
            $isShure = $this->matchesAny($lower, ['shure', 'mxw', 'mx']);
            return [
                'cable_type' => $isShure ? 'Cat6 (Shure network)' : 'XLR',
                'cores'      => $isShure ? null : '3',
                'to'         => $isShure ? 'Shure access point / DSP' : 'DSP / Mixer input',
                'notes'      => $isShure ? 'Shure Microflex Wireless' : 'Analogue mic input',
            ];
        }

        // ── DSP / audio processor → Cat6 (Dante) ────────────────────────────
        if ($this->matchesAny($lower, ['dsp', 'q-sys', 'qsys', 'biamp', 'audio processor'])) {
            return [
                'cable_type' => 'Cat6 (Dante/AES67)',
                'cores'      => null,
                'to'         => 'Network switch (AV VLAN)',
                'notes'      => 'Dante audio network',
            ];
        }

        // ── Amplifier → Cat6 (Dante) or analogue ─────────────────────────────
        if ($this->matchesAny($lower, ['amplifier', 'amp', 'lea audio', 'lea '])) {
            $isDante = $this->matchesAny($lower, ['dante', 'lea']);
            return [
                'cable_type' => $isDante ? 'Cat6 (Dante)' : 'Audio Multicore',
                'cores'      => null,
                'to'         => $isDante ? 'Network switch (AV VLAN)' : 'DSP output',
                'notes'      => $isDante ? 'Dante amplifier — network audio' : 'Analogue from DSP',
            ];
        }

        // ── Cisco / VC codec → Cat6 PoE ─────────────────────────────────────
        if ($this->matchesAny($lower, ['cisco', 'room kit', 'codec', 'poly', 'logitech'])) {
            return [
                'cable_type' => 'Cat6 (PoE)',
                'cores'      => null,
                'to'         => 'Network switch (PoE)',
                'notes'      => 'VC codec — requires PoE+ or local PSU',
            ];
        }

        // ── Camera / PTZ → Cat6 PoE ──────────────────────────────────────────
        if ($this->matchesAny($lower, ['camera', 'ptz', 'quad cam', 'webcam'])) {
            return [
                'cable_type' => 'Cat6 (PoE)',
                'cores'      => null,
                'to'         => 'Codec / Network switch (PoE)',
                'notes'      => 'Camera — PoE powered',
            ];
        }

        // ── Touch panel / navigator → Cat6 PoE ──────────────────────────────
        if ($this->matchesAny($lower, ['navigator', 'touch panel', 'keypad', 'button panel'])) {
            return [
                'cable_type' => 'Cat6 (PoE)',
                'cores'      => null,
                'to'         => 'Network switch (PoE)',
                'notes'      => 'Control interface — PoE powered',
            ];
        }

        // ── Control / sensor → Cat6 ─────────────────────────────────────────
        if ($this->matchesAny($lower, ['control', 'crestron', 'extron', 'amx', 'sensor', 'partition'])) {
            return [
                'cable_type' => 'Cat6',
                'cores'      => null,
                'to'         => 'Control processor',
                'notes'      => 'Control signal',
            ];
        }

        // ── Network switch → Cat6 (uplink) ──────────────────────────────────
        if ($this->matchesAny($lower, ['switch', 'netgear', 'cisco switch'])) {
            return [
                'cable_type' => 'Cat6',
                'cores'      => null,
                'to'         => 'Building network / patch panel',
                'notes'      => 'Uplink to client network',
            ];
        }

        // ── Patch panel → Cat6 ──────────────────────────────────────────────
        if ($this->matchesAny($lower, ['patch panel', 'keystone'])) {
            return [
                'cable_type' => 'Cat6',
                'cores'      => null,
                'to'         => 'Network switch',
                'notes'      => 'Patch panel termination',
            ];
        }

        // ── Wireless mic access point → Cat6 PoE ────────────────────────────
        if ($this->matchesAny($lower, ['mxwapx', 'access point', 'wap'])) {
            return [
                'cable_type' => 'Cat6 (PoE)',
                'cores'      => null,
                'to'         => 'Network switch (PoE)',
                'notes'      => 'Wireless mic access point',
            ];
        }

        // ── Unknown hardware → TBC ──────────────────────────────────────────
        return [
            'cable_type' => 'TBC',
            'cores'      => null,
            'to'         => 'TBC — confirm on survey',
            'notes'      => 'Cable type to be confirmed during site survey',
        ];
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
            if ($kw !== '' && str_contains($haystack, $kw)) return true;
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
