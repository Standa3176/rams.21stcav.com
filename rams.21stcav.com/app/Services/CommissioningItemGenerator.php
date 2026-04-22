<?php

namespace App\Services;

use App\Models\CommissioningItem;
use App\Models\InstallProgramme;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CommissioningItemGenerator — transforms install_tasks rows into
 * commissioning_items via the config/commissioning.php keyword map.
 *
 * Decision refs:
 *   D-01 — static PHP config drives mapping (not a DB table)
 *   D-02 — per-instance grain: three identical displays → three items × categories
 *   D-05 — data source = install_tasks, never project_packages (keeps the
 *          generator decoupled from the quote/survey pipeline)
 *   D-06 — case-insensitive substring match on equipment_name
 *   D-07 — unmatched equipment produces NO item (no default category)
 *
 * Idempotency: generate() is safe to call repeatedly on the same programme —
 * it short-circuits when commissioning_items already exist. This is important
 * because the D-03 observer fires on every install_task save, and the service
 * is also invoked manually from CommissioningSyncService::resync().
 */
class CommissioningItemGenerator
{
    /**
     * Generate commissioning_items for a programme. Idempotent.
     *
     * @return int number of items created (0 if already generated or if
     *             programme has no tasks — Pitfall 10)
     */
    public function generate(InstallProgramme $programme): int
    {
        // Idempotent — D-03 observer may fire multiple times for the same
        // programme (e.g. engineer un-completes and re-completes a task).
        if ($programme->commissioningItems()->exists()) {
            return 0;
        }

        // Pitfall 10 — a draft programme with zero tasks should not crash
        // the observer; log a warning and return 0. The UI can still hit the
        // "Re-sync from programme" button later once tasks are added.
        $taskCount = $programme->tasks()->count();
        if ($taskCount === 0) {
            Log::warning('CommissioningItemGenerator: programme has no tasks; skipping', [
                'programme_id' => $programme->id,
                'project_id'   => $programme->project_id,
            ]);
            return 0;
        }

        $expected = $this->expectedItems($programme);
        $created = 0;

        DB::transaction(function () use ($programme, $expected, &$created) {
            foreach ($expected as $row) {
                CommissioningItem::create([
                    'install_programme_id' => $programme->id,
                    'install_task_id'      => $row['install_task_id'],
                    'equipment_name'       => $row['equipment_name'],
                    'room_name'            => $row['room_name'],
                    'category'             => $row['category'],
                    'status'               => CommissioningItem::STATUS_PENDING,
                ]);
                $created++;
            }
        });

        Log::info('CommissioningItemGenerator: items generated', [
            'programme_id' => $programme->id,
            'project_id'   => $programme->project_id,
            'task_count'   => $taskCount,
            'item_count'   => $created,
        ]);

        return $created;
    }

    /**
     * Pure function: compute the list of commissioning_items that SHOULD
     * exist for a programme right now, without touching the DB. Reused by
     * CommissioningSyncService::resync() for diff computation.
     *
     * @return array<int, array{install_task_id: int, equipment_name: string, room_name: string, category: string}>
     */
    public function expectedItems(InstallProgramme $programme): array
    {
        $keywordMap = config('commissioning.keyword_map');
        $categories = array_keys($keywordMap);
        $tasks      = $programme->tasks()->orderBy('sort_order')->get();
        $out        = [];

        foreach ($tasks as $task) {
            $nameLower = mb_strtolower((string) $task->equipment_name);

            foreach ($categories as $category) {
                $keywords = $keywordMap[$category] ?? [];

                // D-06 — case-insensitive substring match. First keyword hit
                // wins; we do not count matches per category, a single hit
                // is sufficient to generate one item for (task, category).
                $matched = false;
                foreach ($keywords as $kw) {
                    if ($kw !== '' && str_contains($nameLower, mb_strtolower($kw))) {
                        $matched = true;
                        break;
                    }
                }

                if (! $matched) {
                    continue;   // D-07 — unmatched = skip, no item.
                }

                $out[] = [
                    'install_task_id' => $task->id,
                    'equipment_name'  => $task->equipment_name,
                    'room_name'       => $task->room_name,
                    'category'        => $category,
                ];
            }
        }

        return $out;
    }
}
