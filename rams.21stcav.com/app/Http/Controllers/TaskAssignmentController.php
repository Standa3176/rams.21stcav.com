<?php

namespace App\Http\Controllers;

use App\Models\InstallProgramme;
use App\Models\InstallTask;
use App\Models\User;
use App\Services\TaskAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * TaskAssignmentController — HTTP layer for engineer assignment on install tasks.
 *
 * Three actions:
 *  - assign()      POST /install-tasks/{task}/assign                    — single task
 *  - assignRoom()  POST /install-programmes/{programme}/assign-room     — bulk by room
 *  - assignAll()   POST /install-programmes/{programme}/assign-all      — entire programme
 *
 * Auth: ownership guard on every action (project owner or admin).
 * Validation: user_id is validated against users table (exists:users,id) to
 * prevent assignment to non-existent users (T-13-02).
 * Returns JSON so Blade views can update assignment labels without a page reload.
 *
 * @see TaskAssignmentService — business logic layer
 */
class TaskAssignmentController extends Controller
{
    public function __construct(
        private readonly TaskAssignmentService $service,
    ) {}

    // =========================================================================
    // ASSIGN SINGLE TASK
    // =========================================================================

    /**
     * Assign (or unassign) a single task.
     *
     * Request:  { user_id: int|null, planned_start_date: string|null (YYYY-MM-DD), planned_end_date: string|null }
     * Response: 200 JSON { message: string, assigned_to_name: string|null }
     *
     * @param  Request     $request
     * @param  InstallTask $task
     * @return JsonResponse
     */
    public function assign(Request $request, InstallTask $task): JsonResponse
    {
        $task->load('programme.project');

        // T-13-01: ownership guard — task owner or admin only
        abort_if(
            $task->programme->project->user_id !== auth()->id() && ! auth()->user()->isAdmin(),
            403
        );

        $validated = $request->validate([
            'user_id'            => ['nullable', 'integer', 'exists:users,id'],
            'planned_start_date' => ['nullable', 'date'],
            'planned_end_date'   => ['nullable', 'date', 'after_or_equal:planned_start_date'],
        ]);

        $this->service->assignTask(
            $task,
            $validated['user_id'] ?? null,
            $validated['planned_start_date'] ?? null,
            $validated['planned_end_date'] ?? null,
        );

        $name = isset($validated['user_id'])
            ? User::find($validated['user_id'])?->name ?? 'Unknown'
            : null;

        Log::info('TaskAssignmentController: task assigned', [
            'task_id' => $task->id,
            'user_id' => $validated['user_id'] ?? null,
        ]);

        return response()->json([
            'message'          => 'Task assigned successfully.',
            'assigned_to_name' => $name,
        ]);
    }

    // =========================================================================
    // BULK ASSIGN ROOM
    // =========================================================================

    /**
     * Assign all tasks in a room (by room_name) to a user.
     *
     * Request:  { user_id: int|null, room_name: string }
     * Response: 200 JSON { message: string, updated_count: int }
     *
     * @param  Request          $request
     * @param  InstallProgramme $programme
     * @return JsonResponse
     */
    public function assignRoom(Request $request, InstallProgramme $programme): JsonResponse
    {
        $programme->load('project');

        // T-13-03: ownership guard — programme owner or admin only
        abort_if(
            $programme->project->user_id !== auth()->id() && ! auth()->user()->isAdmin(),
            403
        );

        $validated = $request->validate([
            'user_id'   => ['nullable', 'integer', 'exists:users,id'],
            'room_name' => ['required', 'string', 'max:200'],
        ]);

        $count = $this->service->bulkAssignRoom(
            $programme,
            $validated['room_name'],
            $validated['user_id'] ?? null,
        );

        Log::info('TaskAssignmentController: room assigned', [
            'programme_id' => $programme->id,
            'room_name'    => $validated['room_name'],
            'user_id'      => $validated['user_id'] ?? null,
            'count'        => $count,
        ]);

        return response()->json([
            'message'       => "Assigned {$count} task(s) in room.",
            'updated_count' => $count,
        ]);
    }

    // =========================================================================
    // BULK ASSIGN ENTIRE PROGRAMME
    // =========================================================================

    /**
     * Assign all tasks in the programme to a user.
     *
     * Request:  { user_id: int|null }
     * Response: 200 JSON { message: string, updated_count: int }
     *
     * @param  Request          $request
     * @param  InstallProgramme $programme
     * @return JsonResponse
     */
    public function assignAll(Request $request, InstallProgramme $programme): JsonResponse
    {
        $programme->load('project');

        // T-13-03: ownership guard — programme owner or admin only
        abort_if(
            $programme->project->user_id !== auth()->id() && ! auth()->user()->isAdmin(),
            403
        );

        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $count = $this->service->bulkAssignProgramme(
            $programme,
            $validated['user_id'] ?? null,
        );

        Log::info('TaskAssignmentController: programme bulk assigned', [
            'programme_id' => $programme->id,
            'user_id'      => $validated['user_id'] ?? null,
            'count'        => $count,
        ]);

        return response()->json([
            'message'       => "Assigned {$count} task(s) in programme.",
            'updated_count' => $count,
        ]);
    }
}
