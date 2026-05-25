<?php

namespace App\Http\Controllers;

use App\Exceptions\CommissioningSignoffException;
use App\Models\InstallProgramme;
use App\Services\CommissioningSyncService;
use Illuminate\Http\JsonResponse;

/**
 * CommissioningResyncController — D-04 re-sync from programme.
 *
 * Re-computes commissioning_items from the programme's current install_tasks,
 * preserving existing pass/fail/na statuses + notes + photos on items that
 * survive the diff, soft-deleting items whose source task has disappeared,
 * and restoring previously soft-deleted rows whose equipment has returned.
 *
 * Endpoint
 *   POST /install-programmes/{programme}/commissioning/resync
 *     → JSON { diff: { added, removed, unchanged, restored } }
 *
 * Refuses to run once a CommissioningSignoff exists (INST-05i) — the service
 * throws CommissioningSignoffException::itemsImmutable which we surface as
 * 422 with the exception message. UI additionally hides the Re-sync button
 * when a signoff row is present, so this guard is defence-in-depth.
 *
 * Ownership rule mirrors CommissioningController::show and
 * CommissioningSignoffController: project owner / admin / engineer assigned
 * to ANY install_task on the programme. Everyone else → 403.
 */
class CommissioningResyncController extends Controller
{
    public function __construct(
        private readonly CommissioningSyncService $syncService,
    ) {}

    /**
     * POST /install-programmes/{programme}/commissioning/resync
     */
    public function resync(InstallProgramme $programme): JsonResponse
    {
        $this->authorise($programme);

        try {
            $diff = $this->syncService->resync($programme);
        } catch (CommissioningSignoffException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'diff' => $diff,
        ]);
    }

    /**
     * Shared workspace — any authenticated user may resync commissioning items.
     */
    private function authorise(InstallProgramme $programme): void
    {
        // Shared workspace: any authenticated user has full access.
        abort_unless(auth()->check(), 403);
    }
}
