<?php

namespace App\Services;

use App\Exceptions\ClockInBlockedException;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * TimeEntryService — clock in / clock out for the mobile field view.
 *
 * Phase 14 (INST-04g partial): delivers the minimal start/stop API plus the
 * one-open-entry-per-user-per-project guard. Phase 15 extends with category,
 * notes, heartbeat, and close-stale-sessions.
 *
 * Guard semantics (threat ref T-14-08):
 *   - Wrapped in DB::transaction with SELECT ... FOR UPDATE on the open-entry
 *     query, so two parallel clock-in requests cannot both observe "no open
 *     entry" and both insert one. On SQLite (test env) lockForUpdate() is a
 *     no-op but the surrounding transaction still serialises.
 *
 * @see ClockInBlockedException — thrown by start() when a second open entry
 *                                would be created
 * @see TimeEntryController — HTTP layer (converts the exception to a 422)
 */
class TimeEntryService
{
    /**
     * Start a new time entry. Rejects if an open entry already exists for
     * this (project, user) pair.
     *
     * @throws ClockInBlockedException when a second open entry would be created
     */
    public function start(Project $project, User $user): TimeEntry
    {
        return DB::transaction(function () use ($project, $user) {
            $existing = TimeEntry::where('project_id', $project->id)
                ->where('user_id', $user->id)
                ->whereNull('clocked_out_at')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                Log::info('TimeEntryService: clock-in blocked (open entry exists)', [
                    'project_id'        => $project->id,
                    'user_id'           => $user->id,
                    'existing_entry_id' => $existing->id,
                ]);
                throw ClockInBlockedException::alreadyOpen($project->id, $user->id);
            }

            $entry = TimeEntry::create([
                'project_id'        => $project->id,
                'user_id'           => $user->id,
                'clocked_in_at'     => now(),
                'last_heartbeat_at' => now(),
            ]);

            Log::info('TimeEntryService: clocked in', [
                'entry_id'   => $entry->id,
                'project_id' => $project->id,
                'user_id'    => $user->id,
            ]);

            return $entry;
        });
    }

    /**
     * Close the open entry for this (project, user). Returns the closed entry.
     *
     * @throws RuntimeException when no open entry exists (controller translates to 422)
     */
    public function stop(Project $project, User $user): TimeEntry
    {
        return DB::transaction(function () use ($project, $user) {
            $open = TimeEntry::where('project_id', $project->id)
                ->where('user_id', $user->id)
                ->whereNull('clocked_out_at')
                ->lockForUpdate()
                ->first();

            if ($open === null) {
                Log::info('TimeEntryService: clock-out with no open entry', [
                    'project_id' => $project->id,
                    'user_id'    => $user->id,
                ]);
                throw new RuntimeException(
                    'No open time entry to close on this project.'
                );
            }

            $open->update(['clocked_out_at' => now()]);

            Log::info('TimeEntryService: clocked out', [
                'entry_id'     => $open->id,
                'project_id'   => $project->id,
                'user_id'      => $user->id,
                'duration_min' => (int) $open->clocked_in_at->diffInMinutes($open->clocked_out_at),
            ]);

            return $open->fresh();
        });
    }
}
