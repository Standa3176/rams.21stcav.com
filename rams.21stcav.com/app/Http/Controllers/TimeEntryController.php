<?php

namespace App\Http\Controllers;

use App\Exceptions\ClockInBlockedException;
use App\Exceptions\TimeEntryEditException;
use App\Http\Requests\HeartbeatTimeEntryRequest;
use App\Http\Requests\StartTimeEntryRequest;
use App\Http\Requests\StopTimeEntryRequest;
use App\Http\Requests\UpdateTimeEntryRequest;
use App\Models\InstallTask;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\TimeEntryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * TimeEntryController — clock in / clock out / heartbeat / retro-edit for the
 * mobile field view.
 *
 * Phase 14 (INST-04g): start / stop with one-open-entry guard.
 * Phase 15 Plan 02 (INST-04b/c/d/g/h): adds category to start, optional note
 * to stop, heartbeat (204) and retro-edit (PATCH) endpoints.
 *
 * Ownership rule (start/stop): project owner, admin, or assigned engineer on
 * the active programme — enforced via {@see authoriseProjectAccess()}.
 *
 * Ownership rule (heartbeat/update): ENTRY ownership (not project access) —
 * delegated to TimeEntryService. The service rejects:
 *   - non-owner on heartbeat (strict — T-15-02-01)
 *   - non-owner AND non-admin on update (T-15-02-02)
 * Controller catches the AuthorizationException and returns a generic 403.
 *
 * Error translation:
 *   - ClockInBlockedException  → 422 JSON { message } (engineer-friendly copy)
 *   - InvalidArgumentException → 422 JSON { message } (defence-in-depth after FormRequest)
 *   - TimeEntryEditException   → 422 JSON { message }
 *   - AuthorizationException   → 403 JSON { message }
 *   - RuntimeException (stop)  → 422 JSON { message }
 *
 * @see TimeEntryService        — business logic
 * @see ClockInBlockedException — translated to 422
 * @see TimeEntryEditException  — translated to 422
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
     *
     * Body: { category: installation|commissioning|testing|other }
     * Response 200: { id, category, clocked_in_at }
     * Response 422: { message } on guard violation or invalid category
     */
    public function start(StartTimeEntryRequest $request, Project $project): JsonResponse
    {
        $user = auth()->user();
        $this->authoriseProjectAccess($project, $user);

        try {
            $entry = $this->service->start(
                $project,
                $user,
                $request->string('category')->toString(),
            );
        } catch (ClockInBlockedException $e) {
            Log::warning('TimeEntryController: start blocked by guard', [
                'project_id'       => $project->id,
                'user_id'          => $user->id,
                'internal_message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => "You're already clocked into another session on this project. Clock out first.",
            ], 422);
        } catch (InvalidArgumentException $e) {
            // Defence-in-depth — FormRequest should have caught this
            return response()->json(['message' => 'Invalid category.'], 422);
        }

        return response()->json([
            'id'            => $entry->id,
            'category'      => $entry->category,
            'clocked_in_at' => $entry->clocked_in_at->toIso8601String(),
        ]);
    }

    // =========================================================================
    // STOP
    // =========================================================================

    /**
     * POST /projects/{project}/time-entries/stop
     *
     * Body: { note?: string (<=500 chars) }
     * Response 200: { id, clocked_in_at, clocked_out_at, duration_minutes, notes }
     * Response 422: { message } if no open entry or oversize note
     */
    public function stop(StopTimeEntryRequest $request, Project $project): JsonResponse
    {
        $user = auth()->user();
        $this->authoriseProjectAccess($project, $user);

        try {
            $entry = $this->service->stop($project, $user, $request->input('note'));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'id'               => $entry->id,
            'clocked_in_at'    => $entry->clocked_in_at->toIso8601String(),
            'clocked_out_at'   => $entry->clocked_out_at->toIso8601String(),
            'duration_minutes' => (int) $entry->clocked_in_at->diffInMinutes($entry->clocked_out_at),
            'notes'            => $entry->notes,
        ]);
    }

    // =========================================================================
    // HEARTBEAT (INST-04d)
    // =========================================================================

    /**
     * POST /time-entries/{entry}/heartbeat
     *
     * Response 204 on success.
     * Response 403 if entry.user_id !== auth()->id() (strict owner-only).
     * Response 422 if entry already closed.
     *
     * Rate-limited to 10/min at the route layer (throttle:10,1).
     */
    public function heartbeat(HeartbeatTimeEntryRequest $request, TimeEntry $entry): JsonResponse
    {
        try {
            $this->service->recordHeartbeat($entry, auth()->user());
        } catch (AuthorizationException $e) {
            return response()->json(['message' => 'Forbidden'], 403);
        } catch (TimeEntryEditException $e) {
            // Per the TimeEntryEditException contract (see class docblock),
            // the exception message IS the payload shown to the user. Mirror
            // the update() path so both endpoints surface the factory copy
            // consistently (WR-02).
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(null, 204);
    }

    // =========================================================================
    // UPDATE (retro-edit — INST-04b/c)
    // =========================================================================

    /**
     * PATCH /time-entries/{entry}
     *
     * Body: { field: 'category'|'notes', value: string|null }
     * Owner or admin only.
     * Response 200: { id, field, old_value, new_value, edited_at }
     * Response 403 if not owner and not admin.
     * Response 422 if entry still open, invalid field, or invalid value.
     */
    public function update(UpdateTimeEntryRequest $request, TimeEntry $entry): JsonResponse
    {
        try {
            $fresh = $this->service->editEntry(
                $entry,
                auth()->user(),
                $request->string('field')->toString(),
                $request->input('value'),
            );
        } catch (AuthorizationException $e) {
            return response()->json(['message' => "You can't edit this time entry."], 403);
        } catch (TimeEntryEditException | InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Reload the audit row we just wrote to pull canonical edited_at
        $audit = $fresh->audits()->latest('edited_at')->first();

        return response()->json([
            'id'        => $fresh->id,
            'field'     => $request->string('field')->toString(),
            'old_value' => $audit?->old_value,
            'new_value' => $audit?->new_value,
            'edited_at' => $audit?->edited_at?->toIso8601String(),
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
