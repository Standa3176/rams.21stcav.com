<?php

namespace App\Http\Controllers;

use App\Models\CommissioningItem;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * CommissioningController — engineer-facing per-equipment checklist (INST-05c).
 *
 * Thin controller: ownership guard + view-data composition. All mutation
 * lives on CommissioningItemController (per-item AJAX) and the finalise
 * endpoint (Plan 04).
 *
 * Scope rules (mirror Phase 14 field view):
 *   - Owner / admin: always allowed
 *   - Engineer assigned to ANY install_task in the active programme: allowed
 *   - Everyone else: 403
 *
 * @see CommissioningItemController — sibling per-item AJAX endpoints
 * @see resources/views/commissioning/show.blade.php — Alpine factory view
 */
class CommissioningController extends Controller
{
    // =========================================================================
    // SHOW — GET /projects/{project}/commissioning
    // =========================================================================

    /**
     * Render the commissioning checklist page for a project's active programme.
     *
     * View-data shape (mirrors Phase 14 field() action):
     *   - $project            Project
     *   - $programme          InstallProgramme|null
     *   - $rooms              Collection<roomName, Collection<CommissioningItem>>
     *   - $signoff            CommissioningSignoff|null (triggers locked UI when present)
     *   - $counters           ['programme' => ['complete','total','unlocked'],
     *                          'byRoom'    => [roomName => ['complete','total']]]
     *   - $categoryLabels     array<string,string>
     *   - $isOwnerOrAdmin     bool
     *
     * @param  Request $request
     * @param  Project $project
     * @return View
     */
    public function show(Request $request, Project $project): View
    {
        $user = auth()->user();
        $isOwnerOrAdmin = $project->user_id === $user->id || $user->isAdmin();

        // Resolve active programme or fall back to latest (mirrors field() pattern).
        $programme = $project->activeInstallProgramme
            ?? $project->installProgrammes()->latest()->first();

        // Field engineer scope: must be assigned to at least one task on the
        // active programme. Mirrors InstallProgrammeController::field() exactly.
        $assignedToProgramme = false;
        if (! $isOwnerOrAdmin && $programme !== null) {
            $assignedToProgramme = $programme->tasks()
                ->where('assigned_to', $user->id)
                ->exists();
        }

        abort_if(
            ! $isOwnerOrAdmin && ! $assignedToProgramme,
            403,
        );

        // Pull items grouped by room → equipment → category (Claude's Discretion
        // item ordering per 16-CONTEXT.md). orderBy is stable because room_name
        // is the outer group in the view, and category is deterministic enum.
        $items = $programme
            ? $programme->commissioningItems()
                ->orderBy('room_name')
                ->orderBy('equipment_name')
                ->orderBy('category')
                ->get()
            : collect();

        $signoff = $programme?->commissioningSignoff;

        // Group by room_name; each group preserves the order returned from SQL
        // (equipment_name then category) so the UI reflects the DB ordering.
        $rooms = $items->groupBy('room_name');

        // ── Counters ───────────────────────────────────────────────────────
        $completeStatuses = [
            CommissioningItem::STATUS_PASS,
            CommissioningItem::STATUS_FAIL,
            CommissioningItem::STATUS_NA,
        ];

        $total    = $items->count();
        $complete = $items->whereIn('status', $completeStatuses)->count();

        // D-13: zero items unlocks the Complete Commissioning button.
        // Cable-only jobs + jobs where no equipment matched any AVIXA category
        // still need a way to advance the project lifecycle.
        $unlocked = $total === 0 || $complete === $total;

        $byRoom = [];
        foreach ($rooms as $roomName => $roomItems) {
            $rc = $roomItems->whereIn('status', $completeStatuses)->count();
            $byRoom[$roomName] = [
                'complete' => $rc,
                'total'    => $roomItems->count(),
            ];
        }

        return view('commissioning.show', [
            'project'        => $project,
            'programme'      => $programme,
            'rooms'          => $rooms,
            'signoff'        => $signoff,
            'counters'       => [
                'programme' => [
                    'complete' => $complete,
                    'total'    => $total,
                    'unlocked' => $unlocked,
                ],
                'byRoom' => $byRoom,
            ],
            'categoryLabels' => CommissioningItem::categoryLabels(),
            'isOwnerOrAdmin' => $isOwnerOrAdmin,
        ]);
    }
}
