<?php

namespace App\Services;

use App\Core\AI\AIManager;
use App\Core\AI\Prompts\WorksheetPrompt;
use App\Core\Modules\Projects\ProjectDataService;
use App\Models\Worksheet;
use Illuminate\Support\Facades\Log;

/**
 * WorksheetGeneratorService — builds per-room worksheet content for a project.
 *
 * Reads exclusively from ProjectDataService (canonical data source per DATA-03).
 * For each room, calls the AI sequentially to generate install steps, then
 * assembles the full rooms[] array including unsurveyed rooms.
 *
 * Return shape:
 * [
 *   'project'      => ['id', 'name', 'client_name', 'site_address', 'quote_reference'],
 *   'rooms'        => [[
 *     'name', 'is_surveyed', 'equipment', 'install_steps',
 *     'cable_route_desc', 'has_power', 'power_outlet_count',
 *     'requires_additional_power', 'network_port_count', 'existing_cabling',
 *   ]],
 *   'generated_at' => ISO 8601 string,
 * ]
 *
 * @see ProjectDataService — canonical data source
 * @see WorksheetPrompt   — AI prompt per room
 * @see BuildWorksheetJob — calls generateContent() in the queue
 */
class WorksheetGeneratorService
{
    // ── Excluded categories (cables, consumables, line items — not field hardware)
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

    public function __construct(
        private readonly ProjectDataService $projectDataService,
    ) {}

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Generate full worksheet content for the given worksheet record.
     *
     * Loads the project relationship if not already loaded, resolves canonical
     * data via ProjectDataService, runs AI per room sequentially, and returns
     * the complete structured data array.
     *
     * @param  Worksheet $worksheet  Worksheet model (project_id must be set)
     * @return array                 Structured worksheet data ready for generated_data
     *
     * @throws \RuntimeException  If project cannot be resolved
     */
    public function generateContent(Worksheet $worksheet): array
    {
        $project = $worksheet->project ?? $worksheet->load('project')->project;

        if ($project === null) {
            throw new \RuntimeException(
                "WorksheetGeneratorService: worksheet {$worksheet->id} has no linked project."
            );
        }

        $data    = $this->projectDataService->resolve($project);
        $rooms   = $this->buildRooms($data['rooms'], $data['project']);
        $provider = config('ai.default', 'claude');

        return [
            'project'      => $data['project'],
            'rooms'        => $rooms,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    // ── Room builder ──────────────────────────────────────────────────────────

    /**
     * Build the per-room data array, calling AI sequentially for each room.
     *
     * Unsurveyed rooms are included with is_surveyed=false and null survey fields.
     * AI failures per room are caught and logged — the rest of the job continues.
     *
     * @param  array $quoteRooms  Rooms from ProjectDataService (may include survey enrichment)
     * @param  array $projectMeta Project fields from ProjectDataService
     * @return array
     */
    private function buildRooms(array $quoteRooms, array $projectMeta): array
    {
        $rooms = [];

        foreach ($quoteRooms as $room) {
            $roomName   = $room['room_name'] ?? $room['name'] ?? 'Unknown Room';
            $isSurveyed = $this->isSurveyed($room);
            $equipment  = $this->filterHardwareItems($room['equipment'] ?? []);

            // ── AI install steps ──────────────────────────────────────────────
            $installSteps = null;
            try {
                $roomForPrompt            = $room;
                $roomForPrompt['equipment'] = $equipment;

                $prompt  = WorksheetPrompt::forRoom($roomForPrompt, $projectMeta);
                $result  = AIManager::run($prompt, [], config('ai.default', 'claude'));
                $installSteps = $result['install_steps'] ?? null;
            } catch (\Throwable $e) {
                Log::warning('WorksheetGeneratorService: AI call failed for room, continuing', [
                    'room'  => $roomName,
                    'error' => $e->getMessage(),
                ]);
            }

            $rooms[] = [
                'name'                      => $roomName,
                'floor'                     => $room['floor'] ?? null,
                'is_surveyed'               => $isSurveyed,
                'equipment'                 => $equipment,
                'install_steps'             => $installSteps,
                'cable_route_desc'          => $room['cable_route_desc'] ?? null,
                'has_power'                 => $room['has_power'] ?? null,
                'power_outlet_count'        => $room['power_outlet_count'] ?? null,
                'requires_additional_power' => $room['requires_additional_power'] ?? null,
                'network_port_count'        => $room['network_port_count'] ?? null,
                'existing_cabling'          => $room['existing_cabling'] ?? null,
            ];
        }

        return $rooms;
    }

    // ── Survey detection ──────────────────────────────────────────────────────

    /**
     * Returns true if the room has any survey data present.
     *
     * Survey enrichment populates ceiling_type, cable_route_desc, or has_power.
     * At least one non-null value indicates the room was surveyed.
     *
     * @param  array $room  Room entry from ProjectDataService
     * @return bool
     */
    private function isSurveyed(array $room): bool
    {
        return isset($room['ceiling_type'])
            || isset($room['cable_route_desc'])
            || isset($room['has_power'])
            || ($room['data_source'] ?? '') === 'survey';
    }

    // ── Hardware filter ───────────────────────────────────────────────────────

    /**
     * Filter an equipment array to hardware items only.
     *
     * Excludes line items by category (cables, consumables, services, option).
     * Falls back to keyword matching when category is absent.
     *
     * @param  array $items  Raw equipment items from ProjectDataService
     * @return array         Hardware-only items
     */
    private function filterHardwareItems(array $items): array
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
