<?php

namespace App\Services;

use App\Exceptions\CommissioningSignoffException;
use App\Models\CommissioningItem;
use App\Models\InstallProgramme;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CommissioningSyncService — D-04 re-sync that preserves existing statuses.
 *
 * Algorithm (research Pattern 4):
 *   - unchanged: (install_task_id, category) in both existing + expected → preserve status
 *   - added:     in expected, not in existing → create status=pending
 *   - removed:   in existing, not in expected → soft-delete (audit trail)
 *   - restored:  soft-deleted but now expected → restore + reset status=pending
 *
 * INST-05i guard: if a CommissioningSignoff exists for this programme the
 * sync cannot run — items are immutable after sign-off. Thrown as
 * CommissioningSignoffException::itemsImmutable so controllers return 422.
 *
 * @see CommissioningItemGenerator::expectedItems() — pure diff computation
 */
class CommissioningSyncService
{
    public function __construct(
        private readonly CommissioningItemGenerator $generator,
    ) {}

    /**
     * Bring commissioning_items back in sync with the programme's current
     * install_tasks, preserving engineer-entered pass/fail/na + notes + photos
     * on items that survive the diff.
     *
     * @return array{added: int, removed: int, unchanged: int, restored: int}
     *
     * @throws CommissioningSignoffException when the programme has been
     *                                        signed off (INST-05i guard).
     */
    public function resync(InstallProgramme $programme): array
    {
        if ($programme->commissioningSignoff()->exists()) {
            // Use the programme id as the "item id" in the exception message
            // because at this point we have not yet identified a specific row;
            // the caller will surface this as a global "programme is signed
            // off" toast rather than a per-item error.
            throw CommissioningSignoffException::itemsImmutable($programme->id);
        }

        $expected = $this->generator->expectedItems($programme);

        // Index expected by (install_task_id, category) tuple — the natural
        // key pair that makes an item unique within a programme.
        $expectedIndex = [];
        foreach ($expected as $row) {
            $key = $row['install_task_id'] . ':' . $row['category'];
            $expectedIndex[$key] = $row;
        }

        // withTrashed() so restore() can bring back rows soft-deleted by a
        // previous re-sync whose source task has now reappeared.
        $existing = $programme->commissioningItems()->withTrashed()->get();

        $counters = ['added' => 0, 'removed' => 0, 'unchanged' => 0, 'restored' => 0];

        DB::transaction(function () use ($programme, $existing, $expectedIndex, &$counters) {
            $existingKeys = [];

            foreach ($existing as $item) {
                $key = $item->install_task_id . ':' . $item->category;
                $existingKeys[$key] = true;

                if (isset($expectedIndex[$key])) {
                    // Row is expected. If it was soft-deleted, restore and
                    // reset to pending (D-04: restored items start fresh so
                    // the engineer sees "this came back, please re-check").
                    if ($item->trashed()) {
                        $item->restore();
                        $item->update(['status' => CommissioningItem::STATUS_PENDING]);
                        $counters['restored']++;
                    } else {
                        // Unchanged — preserve current status, notes, photo.
                        $counters['unchanged']++;
                    }
                } else {
                    // Not expected anymore (task deleted or equipment renamed
                    // so no category matches). Soft-delete only if not
                    // already trashed — idempotency for repeated syncs.
                    if (! $item->trashed()) {
                        $item->delete();   // soft delete preserves audit trail
                        $counters['removed']++;
                    }
                }
            }

            foreach ($expectedIndex as $key => $row) {
                if (! isset($existingKeys[$key])) {
                    CommissioningItem::create([
                        'install_programme_id' => $programme->id,
                        'install_task_id'      => $row['install_task_id'],
                        'equipment_name'       => $row['equipment_name'],
                        'room_name'            => $row['room_name'],
                        'category'             => $row['category'],
                        'status'               => CommissioningItem::STATUS_PENDING,
                    ]);
                    $counters['added']++;
                }
            }
        });

        Log::info('CommissioningSyncService: resync complete', [
            'programme_id' => $programme->id,
            'counters'     => $counters,
        ]);

        return $counters;
    }
}
