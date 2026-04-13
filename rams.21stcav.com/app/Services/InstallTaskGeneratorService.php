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
 *   creates one InstallTask per hardware equipment item per room in a single DB transaction.
 *
 * Synchronous, no queue. Completes in < 1 second for any real-world project.
 *
 * @see ProjectDataService        — canonical data source (DATA-03)
 * @see InstallProgrammeService   — orchestration layer that calls generate()
 * @see WorksheetGeneratorService — analogue for hardware filter pattern
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

        $data = $this->projectDataService->resolve($project);

        DB::transaction(function () use ($programme, $data): void {
            foreach ($data['rooms'] as $roomIndex => $room) {
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
