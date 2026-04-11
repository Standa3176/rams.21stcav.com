<?php

namespace App\Services;

use App\Core\Modules\Projects\ProjectDataService;
use App\Models\CableSchedule;
use App\Models\CableScheduleItem;
use Illuminate\Support\Facades\Log;

/**
 * Deterministic cable schedule generator.
 *
 * Reads equipment per room from ProjectDataService::resolve() and creates
 * CableScheduleItem records for each hardware item. Category keyword matching
 * determines cable_type and to_location. Non-hardware categories (cables,
 * consumables, services, mounts, etc.) are skipped entirely.
 *
 * No AI is used. This service replaces CableScheduleService::generateFromQuote()
 * for project-linked cable schedules.
 *
 * @see CABLE-01, CABLE-02, D-12, D-13, D-14
 */
class CableScheduleGeneratorService
{
    // ── Skip keywords — item category contains any of these → skip ───────────
    private const SKIP_KEYWORDS = [
        'cable', 'cables', 'consumable', 'consumables',
        'service', 'services', 'option', 'options',
        'mount', 'mounting', 'bracket', 'rack',
        'infrastructure', 'install', 'installation',
        'commission', 'commissioning',
    ];

    public function __construct(
        private readonly ProjectDataService $projectDataService,
    ) {}

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Generate CableScheduleItem records from the project's canonical data.
     *
     * @param  CableSchedule $schedule  The schedule record (must have project_id set).
     * @return int                      Number of items created.
     */
    public function generate(CableSchedule $schedule): int
    {
        $project = $schedule->relationLoaded('project')
            ? $schedule->project
            : $schedule->project()->first();

        $data      = $this->projectDataService->resolve($project);
        $rooms     = $data['rooms'] ?? [];
        $sortOrder = 0;
        $created   = 0;

        foreach ($rooms as $room) {
            $roomName        = (string) ($room['room_name'] ?? $room['name'] ?? 'Unknown Room');
            $cableRouteDesc  = $room['cable_route_desc'] ?? null;
            $equipment       = $room['equipment'] ?? [];

            foreach ($equipment as $item) {
                $category = (string) ($item['category'] ?? '');
                $inferred = $this->inferCableType($category);

                if ($inferred === null) {
                    // Non-hardware category — skip
                    continue;
                }

                $equipName = (string) ($item['name'] ?? 'Unknown Equipment');
                $from      = $roomName . ' — ' . $equipName;
                $to        = $this->resolveToLocation($category);

                CableScheduleItem::create([
                    'cable_schedule_id' => $schedule->id,
                    'cable_id'          => null,
                    'from_location'     => $from,
                    'to_location'       => $to,
                    'cable_type'        => $inferred['cable_type'],
                    'cores'             => $inferred['cores'],
                    'approx_length_m'   => null,
                    'notes'             => $cableRouteDesc,
                    'sort_order'        => $sortOrder++,
                ]);

                $created++;
            }
        }

        Log::info('CableScheduleGeneratorService: items created', [
            'cable_schedule_id' => $schedule->id,
            'items_created'     => $created,
        ]);

        return $created;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Infer cable type and core count from an equipment category string.
     *
     * Returns null if the category represents a non-hardware line item
     * (cables, consumables, services, mounts, rack, infrastructure, etc.)
     * which should be excluded from the cable schedule entirely.
     *
     * @param  string $category  Raw category string from the equipment item.
     * @return array{cable_type: string, cores: string|null}|null
     */
    private function inferCableType(string $category): ?array
    {
        $lower = strtolower(trim($category));

        // ── Skip non-hardware categories ──────────────────────────────────────
        foreach (self::SKIP_KEYWORDS as $keyword) {
            if (str_contains($lower, $keyword)) {
                return null;
            }
        }

        // ── Display / projection ──────────────────────────────────────────────
        if ($this->containsAny($lower, ['display', 'screen', 'monitor', 'tv', 'television', 'projector'])) {
            return ['cable_type' => 'HDMI 2.0', 'cores' => null];
        }

        // ── Audio — speakers ─────────────────────────────────────────────────
        if ($this->containsAny($lower, ['speaker', 'loudspeaker'])) {
            return ['cable_type' => '2-Core Speaker Cable', 'cores' => '2'];
        }

        // ── Audio — signal processing ─────────────────────────────────────────
        if ($this->containsAny($lower, ['amplifier', 'amp', 'dsp', 'audio processor', 'audio'])) {
            return ['cable_type' => 'Audio Multicore', 'cores' => null];
        }

        // ── Video conferencing / cameras ──────────────────────────────────────
        if ($this->containsAny($lower, ['camera', 'vc', 'video conferencing', 'conferencing'])) {
            return ['cable_type' => 'Cat6', 'cores' => null];
        }

        // ── Networking ────────────────────────────────────────────────────────
        if ($this->containsAny($lower, ['switch', 'networking', 'network', 'access point', 'ap', 'wireless', 'wi-fi', 'wifi'])) {
            return ['cable_type' => 'Cat6', 'cores' => null];
        }

        // ── Control systems ───────────────────────────────────────────────────
        if ($this->containsAny($lower, ['control', 'controller', 'crestron', 'extron', 'amx', 'automation'])) {
            return ['cable_type' => 'Cat6', 'cores' => null];
        }

        // ── Fallback — unknown hardware ───────────────────────────────────────
        return ['cable_type' => 'Unknown', 'cores' => null];
    }

    /**
     * Resolve the destination (to_location) for a cable item based on category.
     *
     * @param  string $category  Raw category string.
     * @return string
     */
    private function resolveToLocation(string $category): string
    {
        $lower = strtolower(trim($category));

        if ($this->containsAny($lower, ['display', 'screen', 'monitor', 'tv', 'television', 'projector'])) {
            return 'Rack Unit / AV Matrix';
        }

        if ($this->containsAny($lower, ['speaker', 'loudspeaker'])) {
            return 'Amplifier / DSP Input';
        }

        if ($this->containsAny($lower, ['switch', 'networking', 'network', 'access point', 'ap', 'wireless', 'wi-fi', 'wifi'])) {
            return 'Network Switch';
        }

        if ($this->containsAny($lower, ['control', 'controller', 'crestron', 'extron', 'amx', 'automation'])) {
            return 'Control System Rack';
        }

        return 'Rack Unit';
    }

    /**
     * Return true if the haystack string contains any of the given needles.
     *
     * @param  string   $haystack
     * @param  string[] $needles
     * @return bool
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
