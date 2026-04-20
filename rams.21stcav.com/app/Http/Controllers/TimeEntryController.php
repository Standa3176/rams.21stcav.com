<?php

namespace App\Http\Controllers;

use App\Exceptions\ClockInBlockedException;
use App\Models\InstallTask;
use App\Models\Project;
use App\Models\User;
use App\Services\TimeEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * TimeEntryController — clock in / clock out for the mobile field view.
 *
 * Phase 14 partial (INST-04g): minimal start/stop endpoints; category, heartbeat,
 * and stale-session handling live in Phase 15 (INST-04).
 *
 * Ownership rule: same as field() action — project owner, admin, or assigned
 * engineer. A non-owner, non-admin user must have at least one assigned task
 * on the project's active programme to clock in.
 *
 * Error translation:
 *   - ClockInBlockedException  → 422 JSON { message } (engineer-friendly copy; no internal IDs)
 *   - RuntimeException (stop)  → 422 JSON { message }
 *   - Ownership failure        → 403 via abort_if
 *
 * @see TimeEntryService — business logic
 * @see ClockInBlockedException — translated to 422 here
 */
class TimeEntryController extends Controller
{
    public function __construct(
        private readonly TimeEntryService $service,
    ) {}

    // =========================================================================
    // START
    // =========================================================================

    /**
     * POST /projects/{project}/time-entries/start
     * Response 200: { id, clocked_in_at } on success
     * Response 422: { message } on guard violation (already clocked in)
     *
     * @param  Project $project
     * @return JsonResponse
     */
    public function start(Project $project): JsonResponse
    {
        $user = auth()->user();
        $this->authoriseProjectAccess($project, $user);

        try {
            $entry = $this->service->start($project, $user);
        } catch (ClockInBlockedException $e) {
            // Keep the internal-ID message server-side only (for operator triage).
            // Client gets the engineer-friendly copy from 14-UI-SPEC.md.
            Log::warning('TimeEntryController: start blocked by guard', [
                'project_id'       => $project->id,
                'user_id'          => $user->id,
                'internal_message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => "You're already clocked into another session on this project. Clock out first.",
            ], 422);
        }

        return response()->json([
            'id'            => $entry->id,
            'clocked_in_at' => $entry->clocked_in_at->toIso8601String(),
        ]);
    }

    // =========================================================================
    // STOP
    // =========================================================================

    /**
     * POST /projects/{project}/time-entries/stop
     * Response 200: { id, clocked_in_at, clocked_out_at, duration_minutes } on success
     * Response 422: { message } if no open entry
     *
     * @param  Project $project
     * @return JsonResponse
     */
    public function stop(Project $project): JsonResponse
    {
        $user = auth()->user();
        $this->authoriseProjectAccess($project, $user);

        try {
            $entry = $this->service->stop($project, $user);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'id'               => $entry->id,
            'clocked_in_at'    => $entry->clocked_in_at->toIso8601String(),
            'clocked_out_at'   => $entry->clocked_out_at->toIso8601String(),
            'duration_minutes' => (int) $entry->clocked_in_at->diffInMinutes($entry->clocked_out_at),
        ]);
    }

    // =========================================================================
    // Ownership guard — owner / admin / assigned engineer on active programme
    // =========================================================================

    private function authoriseProjectAccess(Project $project, User $user): void
    {
        $isOwnerOrAdmin = $project->user_id === $user->id || $user->isAdmin();
        if ($isOwnerOrAdmin) {
            return;
        }

        $programme = $project->activeInstallProgramme;
        $hasAssigned = $programme
            && InstallTask::where('install_programme_id', $programme->id)
                ->where('assigned_to', $user->id)
                ->exists();

        abort_if(! $hasAssigned, 403);
    }
}
