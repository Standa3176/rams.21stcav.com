<?php

namespace App\Services;

use App\Exceptions\ClockInBlockedException;
use App\Exceptions\TimeEntryEditException;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\TimeEntryAudit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * TimeEntryService — clock in / clock out / heartbeat / retro-edit for the mobile field view.
 *
 * Phase 14 (INST-04g partial): delivered start/stop + one-open-entry guard.
 * Phase 15 Plan 02 (INST-04b/c/d/g/h) extends with:
 *   - start() now takes a category (enum validated against TimeEntry::CATEGORIES)
 *   - stop() now takes an optional note (<= 500 chars)
 *   - recordHeartbeat() — engineer's tab pings last_heartbeat_at every ~60s (D-08)
 *   - editEntry() — owner or admin retro-edits category/notes on a closed entry;
 *                   writes an append-only TimeEntryAudit row (D-04, D-07)
 *   - summaryForProject() — total + per-category minutes across all closed entries
 *                           (drives Plan 15-05 dashboard widget)
 *   - closeStaleSessions() — scheduled sweep (Plan 15-03) closes sessions whose
 *                            last_heartbeat_at is older than staleAfterMinutes
 *                            with closure_reason = 'stale_auto_close' (D-11, D-12)
 *
 * Guard semantics (threat refs T-14-08 / T-15-02-01 / T-15-02-02):
 *   - start/stop remain wrapped in DB::transaction + lockForUpdate.
 *   - recordHeartbeat rejects non-owners with AuthorizationException (strict
 *     owner-only, not "owner or admin" — peer cannot keep another's session alive).
 *   - editEntry allows owner OR admin; every successful update writes a
 *     TimeEntryAudit row atomically inside the same DB::transaction.
 *   - closeStaleSessions locks one candidate at a time (never holds multi-row
 *     locks concurrently).
 *
 * Logging: Log::info for normal state transitions (start/stop/edit),
 * Log::warning for stale auto-close (ops-review surface per D-18).
 * No log entry per heartbeat — 60s cadence would flood logs.
 *
 * @see ClockInBlockedException      — one-open-entry guard (Phase 14)
 * @see TimeEntryEditException       — retro-edit + heartbeat state guards
 * @see TimeEntryController          — HTTP layer (translates domain exceptions)
 * @see TimeEntryAudit               — append-only history row written by editEntry()
 */
class TimeEntryService
{
    // =========================================================================
    // START
    // =========================================================================

    /**
     * Start a new time entry. Rejects if an open entry already exists for this
     * (project, user) pair, or if $category is not one of TimeEntry::CATEGORIES.
     *
     * @throws InvalidArgumentException  when $category is not a member of TimeEntry::CATEGORIES
     * @throws ClockInBlockedException   when a second open entry would be created
     */
    public function start(Project $project, User $user, string $category): TimeEntry
    {
        if (! in_array($category, TimeEntry::CATEGORIES, true)) {
            throw new InvalidArgumentException("Unknown category: {$category}");
        }

        return DB::transaction(function () use ($project, $user, $category) {
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
                'category'          => $category,
                'clocked_in_at'     => now(),
                'last_heartbeat_at' => now(),
            ]);

            Log::info('TimeEntryService: clocked in', [
                'entry_id'   => $entry->id,
                'project_id' => $project->id,
                'user_id'    => $user->id,
                'category'   => $category,
            ]);

            return $entry;
        });
    }

    // =========================================================================
    // STOP
    // =========================================================================

    /**
     * Close the open entry for this (project, user). Optionally persists a
     * <=500-char engineer note. closure_reason remains null (manual stop).
     *
     * @throws InvalidArgumentException when $note exceeds 500 chars
     * @throws RuntimeException         when no open entry exists (controller → 422)
     */
    public function stop(Project $project, User $user, ?string $note = null): TimeEntry
    {
        $note = $note !== null ? trim($note) : null;
        if ($note === '') {
            $note = null;
        }
        if ($note !== null && mb_strlen($note) > 500) {
            throw new InvalidArgumentException('Note exceeds 500 characters.');
        }

        return DB::transaction(function () use ($project, $user, $note) {
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

            $open->update([
                'clocked_out_at' => now(),
                'notes'          => $note,
            ]);

            Log::info('TimeEntryService: clocked out', [
                'entry_id'     => $open->id,
                'project_id'   => $project->id,
                'user_id'      => $user->id,
                'duration_min' => (int) $open->clocked_in_at->diffInMinutes($open->clocked_out_at),
                'note_length'  => $note !== null ? mb_strlen($note) : 0,
            ]);

            return $open->fresh();
        });
    }

    // =========================================================================
    // HEARTBEAT
    // =========================================================================

    /**
     * Record a liveness heartbeat for an OPEN time entry owned by $user.
     * Strictly owner-only (mitigates T-15-02-01: malicious peer keeping a
     * foreign session alive). No log entry — 60s cadence would flood logs.
     *
     * @throws AuthorizationException  when $entry->user_id !== $user->id
     * @throws TimeEntryEditException  when $entry is already closed
     */
    public function recordHeartbeat(TimeEntry $entry, User $user): void
    {
        if ($entry->user_id !== $user->id) {
            throw new AuthorizationException(
                "User {$user->id} does not own entry {$entry->id}.",
            );
        }

        if ($entry->clocked_out_at !== null) {
            throw TimeEntryEditException::alreadyClosed($entry->id);
        }

        $entry->update(['last_heartbeat_at' => now()]);
    }

    // =========================================================================
    // EDIT ENTRY (retro-edit with append-only audit)
    // =========================================================================

    /**
     * Retro-edit a CLOSED time entry's category or notes. Owner OR admin may
     * edit. Writes a TimeEntryAudit row atomically with the update
     * (mitigates T-15-02-02 — retcon of peer's timesheet / repudiation).
     *
     * @throws AuthorizationException  when $editor is neither owner nor admin
     * @throws TimeEntryEditException  for open entries, invalid field, invalid value
     */
    public function editEntry(
        TimeEntry $entry,
        User $editor,
        string $field,
        ?string $newValue,
    ): TimeEntry {
        // Ownership / role gate (owner OR admin)
        $isOwner = $entry->user_id === $editor->id;
        if (! $isOwner && ! $editor->isAdmin()) {
            throw new AuthorizationException(
                "User {$editor->id} cannot edit entry {$entry->id}.",
            );
        }

        // State gate — retro-edit is for CLOSED entries only (D-04)
        if ($entry->clocked_out_at === null) {
            throw TimeEntryEditException::entryStillOpen($entry->id);
        }

        // Field whitelist — only category + notes retro-editable (T-15-02-03)
        if (! in_array($field, TimeEntryAudit::FIELDS, true)) {
            throw TimeEntryEditException::invalidField($field);
        }

        // Value validation per field
        if ($field === TimeEntryAudit::FIELD_CATEGORY
            && ! in_array($newValue, TimeEntry::CATEGORIES, true)
        ) {
            throw TimeEntryEditException::invalidCategory((string) $newValue);
        }

        // IN-03: mirror stop()'s note normalisation — trim whitespace and coerce
        // empty strings to null so editEntry and stop store consistent data (and
        // the audit log doesn't retain empty-string retro-edits verbatim).
        if ($field === TimeEntryAudit::FIELD_NOTES) {
            $newValue = $newValue !== null ? trim($newValue) : null;
            if ($newValue === '') {
                $newValue = null;
            }
            if ($newValue !== null && mb_strlen($newValue) > 500) {
                throw TimeEntryEditException::noteTooLong(mb_strlen($newValue));
            }
        }

        return DB::transaction(function () use ($entry, $editor, $field, $newValue) {
            $oldValue = $entry->{$field};
            $oldValue = $oldValue === null ? null : (string) $oldValue;

            $entry->update([$field => $newValue]);

            TimeEntryAudit::create([
                'time_entry_id'     => $entry->id,
                'edited_by_user_id' => $editor->id,
                'field'             => $field,
                'old_value'         => $oldValue,
                'new_value'         => $newValue,
                'edited_at'         => now(),
            ]);

            Log::info('TimeEntryService: entry edited', [
                'entry_id'  => $entry->id,
                'editor_id' => $editor->id,
                'field'     => $field,
                'old_value' => $oldValue,
                'new_value' => $newValue,
            ]);

            return $entry->fresh();
        });
    }

    // =========================================================================
    // SUMMARY FOR PROJECT
    // =========================================================================

    /**
     * Aggregate total minutes + per-category minutes across ALL closed entries
     * for the project, for the "Actual Hours" widget (Plan 15-05, D-14).
     *
     * Only closed entries (clocked_out_at IS NOT NULL) contribute — open
     * sessions don't yet count towards actuals.
     *
     * Pre-Phase-15 entries may have NULL category — bucketed to 'other' at
     * read time (D-handling discretion; avoids a bulk history mutation).
     *
     * @return array{total_minutes:int,per_category:array<string,int>}
     */
    public function summaryForProject(Project $project): array
    {
        $rows = TimeEntry::where('project_id', $project->id)
            ->whereNotNull('clocked_out_at')
            ->get(['category', 'clocked_in_at', 'clocked_out_at']);

        $perCategory = array_fill_keys(TimeEntry::CATEGORIES, 0);

        foreach ($rows as $row) {
            $cat = $row->category ?? TimeEntry::CATEGORY_OTHER;
            if (! array_key_exists($cat, $perCategory)) {
                $cat = TimeEntry::CATEGORY_OTHER;
            }
            $perCategory[$cat] += (int) $row->clocked_in_at->diffInMinutes($row->clocked_out_at);
        }

        return [
            'total_minutes' => array_sum($perCategory),
            'per_category'  => $perCategory,
        ];
    }

    // =========================================================================
    // CLOSE STALE SESSIONS (scheduled sweep — Plan 15-03 command)
    // =========================================================================

    /**
     * Scan for open entries whose last_heartbeat_at is older than the cutoff
     * (or whose last_heartbeat_at is NULL and whose clocked_in_at predates it)
     * and close them with closure_reason = 'stale_auto_close'.
     *
     * D-11: clocked_out_at = last_heartbeat_at (no phantom hours from the gap
     * between heartbeat stop and this sweep). Falls back to clocked_in_at + 1min
     * when last_heartbeat_at is NULL (server never got a heartbeat at all).
     *
     * D-18: each closed entry emits Log::warning for ops review.
     *
     * Iterates candidates one at a time, each in its own DB::transaction +
     * lockForUpdate, to avoid holding multi-row locks (T-15-02-08).
     *
     * @return int count of entries closed in this sweep
     */
    public function closeStaleSessions(int $staleAfterMinutes = 120): int
    {
        $cutoff = now()->subMinutes($staleAfterMinutes);

        $candidates = TimeEntry::whereNull('clocked_out_at')
            ->where(function ($q) use ($cutoff) {
                $q->where('last_heartbeat_at', '<', $cutoff)
                    ->orWhere(function ($q2) use ($cutoff) {
                        $q2->whereNull('last_heartbeat_at')
                            ->where('clocked_in_at', '<', $cutoff);
                    });
            })
            ->get(['id']);

        $closed = 0;

        foreach ($candidates as $candidate) {
            DB::transaction(function () use ($candidate, $cutoff, &$closed) {
                $entry = TimeEntry::where('id', $candidate->id)
                    ->whereNull('clocked_out_at')
                    ->lockForUpdate()
                    ->first();

                if ($entry === null) {
                    // Lost the race — some other path closed it first
                    return;
                }

                // Re-verify staleness AFTER acquiring the lock (T-15-02-TOCTOU).
                // A late heartbeat may have arrived between the candidate scan
                // and acquiring the row lock — in that case the session is
                // live again and must NOT be auto-closed.
                $stillStale = ($entry->last_heartbeat_at !== null
                        && $entry->last_heartbeat_at < $cutoff)
                    || ($entry->last_heartbeat_at === null
                        && $entry->clocked_in_at < $cutoff);

                if (! $stillStale) {
                    return;
                }

                $closedAt = $entry->last_heartbeat_at
                    ?? $entry->clocked_in_at->copy()->addMinute();

                $entry->update([
                    'clocked_out_at' => $closedAt,
                    'closure_reason' => TimeEntry::CLOSURE_REASON_STALE_AUTO_CLOSE,
                ]);

                Log::warning('TimeEntryService: stale session auto-closed', [
                    'entry_id'          => $entry->id,
                    'user_id'           => $entry->user_id,
                    'project_id'        => $entry->project_id,
                    'last_heartbeat_at' => optional($entry->last_heartbeat_at)->toIso8601String(),
                    'closed_at'         => $closedAt->toIso8601String(),
                    'duration_minutes'  => (int) $entry->clocked_in_at->diffInMinutes($closedAt),
                ]);

                $closed++;
            });
        }

        return $closed;
    }
}
