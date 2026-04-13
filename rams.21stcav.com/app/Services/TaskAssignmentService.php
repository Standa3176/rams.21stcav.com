<?php

namespace App\Services;

use App\Models\InstallProgramme;
use App\Models\InstallTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TaskAssignmentService — assigns engineers to install tasks.
 *
 * Supports single-task and bulk assignment (by room or entire programme).
 * Assignment writes to install_tasks.assigned_to (FK to users.id) and
 * install_tasks.assigned_at. Planned dates are set via assignTask().
 *
 * The assigned_to column satisfies INST-02a (spec calls it assigned_user_id;
 * the existing column name from Phase 12 is assigned_to — no rename is performed
 * to avoid breaking the assignedUser() relationship on InstallTask).
 *
 * @see InstallProgramme        — parent programme whose tasks are assigned
 * @see TaskAssignmentController — HTTP layer calling these methods
 */
class TaskAssignmentService
{
    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Assign (or unassign) a single task and optionally set planned dates.
     *
     * @param  InstallTask  $task
     * @param  int|null     $userId     null = unassign
     * @param  string|null  $startDate  YYYY-MM-DD or null
     * @param  string|null  $endDate    YYYY-MM-DD or null
     * @return void
     */
    public function assignTask(
        InstallTask $task,
        ?int $userId,
        ?string $startDate = null,
        ?string $endDate = null
    ): void {
        $task->assigned_to        = $userId;
        $task->assigned_at        = $userId ? now() : null;
        $task->planned_start_date = $startDate;
        $task->planned_end_date   = $endDate;
        $task->save();

        Log::info('TaskAssignmentService: task assigned', [
            'task_id'    => $task->id,
            'user_id'    => $userId,
            'start_date' => $startDate,
            'end_date'   => $endDate,
        ]);
    }

    /**
     * Bulk assign all tasks in a room to a user.
     *
     * Matches tasks by room_name string (denormalised). All tasks in that room
     * within the programme are assigned. Wrapped in a DB transaction.
     *
     * @param  InstallProgramme  $programme
     * @param  string            $roomName   Exact room_name value (denormalised string)
     * @param  int|null          $userId     null = unassign all tasks in room
     * @return int               Number of tasks updated
     */
    public function bulkAssignRoom(
        InstallProgramme $programme,
        string $roomName,
        ?int $userId
    ): int {
        return DB::transaction(function () use ($programme, $roomName, $userId) {
            $tasks = InstallTask::where('install_programme_id', $programme->id)
                ->where('room_name', $roomName)
                ->whereNull('deleted_at')
                ->get();

            foreach ($tasks as $task) {
                $task->assigned_to = $userId;
                $task->assigned_at = $userId ? now() : null;
                $task->save();
            }

            Log::info('TaskAssignmentService: bulk room assignment', [
                'programme_id' => $programme->id,
                'room_name'    => $roomName,
                'user_id'      => $userId,
                'task_count'   => $tasks->count(),
            ]);

            return $tasks->count();
        });
    }

    /**
     * Bulk assign all tasks in the entire programme to a user.
     *
     * Uses a single UPDATE statement via Eloquent's mass-update. Wrapped in
     * a DB transaction. Planned dates are not touched in bulk assignment —
     * use assignTask() for per-task date control.
     *
     * @param  InstallProgramme  $programme
     * @param  int|null          $userId     null = unassign all
     * @return int               Number of tasks updated
     */
    public function bulkAssignProgramme(
        InstallProgramme $programme,
        ?int $userId
    ): int {
        return DB::transaction(function () use ($programme, $userId) {
            $count = InstallTask::where('install_programme_id', $programme->id)
                ->whereNull('deleted_at')
                ->update([
                    'assigned_to' => $userId,
                    'assigned_at' => $userId ? now() : null,
                ]);

            Log::info('TaskAssignmentService: bulk programme assignment', [
                'programme_id' => $programme->id,
                'user_id'      => $userId,
                'task_count'   => $count,
            ]);

            return $count;
        });
    }
}
