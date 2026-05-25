<?php

namespace App\Http\Controllers;

use App\Models\InstallTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * TaskStatusController — AJAX status + notes endpoints for the mobile field view.
 *
 * Shared workspace: any authenticated user may mutate task status/notes.
 * Both endpoints only require authentication (auth middleware + an explicit
 * auth()->check() guard) — the 3-person team shares all field-ops surfaces.
 *
 * @see InstallProgrammeController::field() — view layer
 * @see TaskPhotoController                 — sibling photo mutations
 */
class TaskStatusController extends Controller
{
    // =========================================================================
    // STATUS PATCH — tap-to-advance + blocked/skipped bottom-sheet (D-05, D-06)
    // =========================================================================

    /**
     * PATCH /install-tasks/{task}/status
     *
     * Body: { status: pending|in_progress|complete|blocked|skipped,
     *         blocked_reason?: string (required when status ∈ {blocked, skipped}) }
     *
     * Response 200: { id, status, blocked_reason, counters: {room: {complete,total}, programme: {complete,total}} }
     *
     * @param  Request     $request
     * @param  InstallTask $task
     * @return JsonResponse
     */
    public function update(Request $request, InstallTask $task): JsonResponse
    {
        $task->load('programme.project');

        $this->authoriseTaskMutation($task);

        $validated = $request->validate([
            'status' => ['required', Rule::in([
                InstallTask::STATUS_PENDING,
                InstallTask::STATUS_IN_PROGRESS,
                InstallTask::STATUS_COMPLETE,
                InstallTask::STATUS_BLOCKED,
                InstallTask::STATUS_SKIPPED,
            ])],
            'blocked_reason' => [
                'nullable', 'string', 'max:500',
                'required_if:status,'.InstallTask::STATUS_BLOCKED,
                'required_if:status,'.InstallTask::STATUS_SKIPPED,
            ],
        ]);

        $next = $validated['status'];

        $task->update([
            'status'            => $next,
            'blocked_reason'    => $validated['blocked_reason'] ?? null,
            'status_changed_at' => now(),
            'status_changed_by' => auth()->id(),
            'started_at'        => $task->started_at
                                    ?? ($next === InstallTask::STATUS_IN_PROGRESS ? now() : null),
            'completed_at'      => $next === InstallTask::STATUS_COMPLETE ? now() : null,
        ]);

        Log::info('TaskStatusController: status updated', [
            'task_id' => $task->id,
            'status'  => $next,
            'user_id' => auth()->id(),
        ]);

        return response()->json([
            'id'             => $task->id,
            'status'         => $task->status,
            'blocked_reason' => $task->blocked_reason,
            'counters'       => $this->counters($task),
        ]);
    }

    // =========================================================================
    // NOTES PATCH — INST-03f blur-saved notes
    // =========================================================================

    /**
     * PATCH /install-tasks/{task}/notes
     *
     * Body: { notes: string (max 5000, empty string allowed to clear) }
     * Response 200: { id, notes }
     *
     * @param  Request     $request
     * @param  InstallTask $task
     * @return JsonResponse
     */
    public function updateNotes(Request $request, InstallTask $task): JsonResponse
    {
        $task->load('programme.project');

        $this->authoriseTaskMutation($task);

        // `present` keeps the field required in the request; `nullable` allows
        // Laravel's ConvertEmptyStringsToNull middleware to coerce "" → null so
        // the empty-string clear-the-notes path round-trips cleanly. Persist as
        // empty string when cleared to match the test contract.
        $validated = $request->validate([
            'notes' => ['present', 'nullable', 'string', 'max:5000'],
        ]);

        $notes = $validated['notes'] ?? '';

        $task->update(['notes' => $notes]);

        Log::info('TaskStatusController: notes updated', [
            'task_id' => $task->id,
            'user_id' => auth()->id(),
            'length'  => strlen($notes),
        ]);

        return response()->json([
            'id'    => $task->id,
            'notes' => $task->notes,
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Shared workspace — any authenticated user may mutate task status/notes.
     */
    private function authoriseTaskMutation(InstallTask $task): void
    {
        // Shared workspace: any authenticated user has full access.
        abort_unless(auth()->check(), 403);
    }

    /**
     * Compute room + programme counters after the mutation. Two COUNT queries
     * scoped to the current task's room_name (denormalised string).
     */
    private function counters(InstallTask $task): array
    {
        $programmeId = $task->install_programme_id;

        $roomTotal = InstallTask::where('install_programme_id', $programmeId)
            ->where('room_name', $task->room_name)
            ->count();
        $roomDone = InstallTask::where('install_programme_id', $programmeId)
            ->where('room_name', $task->room_name)
            ->where('status', InstallTask::STATUS_COMPLETE)
            ->count();
        $progTotal = InstallTask::where('install_programme_id', $programmeId)->count();
        $progDone = InstallTask::where('install_programme_id', $programmeId)
            ->where('status', InstallTask::STATUS_COMPLETE)
            ->count();

        return [
            'room'      => ['complete' => $roomDone, 'total' => $roomTotal],
            'programme' => ['complete' => $progDone, 'total' => $progTotal],
        ];
    }
}
