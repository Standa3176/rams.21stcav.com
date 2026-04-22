<?php

namespace App\Observers;

use App\Models\InstallTask;
use App\Services\CommissioningItemGenerator;
use Illuminate\Support\Facades\Log;

/**
 * InstallTaskObserver — D-03 generation trigger.
 *
 * When the last install_task for a programme flips to STATUS_COMPLETE, fire
 * CommissioningItemGenerator::generate() synchronously. Idempotent via both
 * the wasChanged('status') guard (Pitfall 6) and the generator's own guard.
 *
 * Failures are logged but never rethrown — a commissioning generation error
 * must not block the task-complete save itself. Engineers can hit the
 * "Re-sync from programme" button (D-04, wired in Plan 05) to retry.
 *
 * @see CommissioningItemGenerator
 * @see InstallTask::STATUS_COMPLETE
 */
class InstallTaskObserver
{
    public function __construct(
        private readonly CommissioningItemGenerator $generator,
    ) {}

    public function saved(InstallTask $task): void
    {
        // Not complete — nothing to do.
        if ($task->status !== InstallTask::STATUS_COMPLETE) {
            return;
        }

        // Pitfall 6 — only fire on a genuine status flip. Saves that touch
        // other attributes (notes, assigned_to, etc.) on an already-complete
        // task must not re-trigger generation.
        if (! $task->wasChanged('status')) {
            return;
        }

        $programme = $task->programme;
        if ($programme === null) {
            // Defensive — a task without a programme is an integrity issue,
            // not a crash candidate. Log and return.
            Log::warning('InstallTaskObserver: task has no programme; skipping trigger', [
                'task_id' => $task->id,
            ]);
            return;
        }

        // Count tasks still in non-terminal states. STATUS_SKIPPED counts as
        // "engineer decided to move on" and does not block generation.
        $remaining = $programme->tasks()
            ->whereIn('status', [
                InstallTask::STATUS_PENDING,
                InstallTask::STATUS_IN_PROGRESS,
                InstallTask::STATUS_BLOCKED,
            ])
            ->count();

        if ($remaining > 0) {
            return;
        }

        try {
            $this->generator->generate($programme);
        } catch (\Throwable $e) {
            Log::error('InstallTaskObserver: commissioning generation failed', [
                'programme_id' => $programme->id,
                'task_id'      => $task->id,
                'error'        => $e->getMessage(),
            ]);
            // Swallow — do not fail the originating task save. The engineer
            // can trigger manual re-sync from the UI (D-04) to recover.
        }
    }
}
