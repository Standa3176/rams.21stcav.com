<?php

namespace App\Jobs;

use App\Models\CableSchedule;
use App\Services\CableScheduleGeneratorService;
use App\Services\CableScheduleXlsxService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Async cable schedule generation job.
 *
 * Dispatched by CableScheduleController::generateFromProject() immediately after
 * the CableSchedule record is created with status = 'generating'.
 *
 * Responsibilities:
 *   1. Run CableScheduleGeneratorService::generate() — creates CableScheduleItem records
 *      deterministically from ProjectDataService equipment data (no AI).
 *   2. Build the .xlsx: CableScheduleXlsxService::build() — reads existing items, saves file,
 *      and updates $schedule->filename on the model.
 *   3. Advance status to 'draft' on success, 'failed' on error.
 *
 * No AI calls are made — cable type inference is deterministic keyword matching.
 * timeout=120 is sufficient; no long-running external calls.
 *
 * @see CABLE-01, CABLE-03, D-14
 */
class BuildCableScheduleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 120; // No AI calls — fast deterministic generation

    public function __construct(
        public readonly int $cableScheduleId,
    ) {}

    // =========================================================================
    // HANDLE
    // =========================================================================

    public function handle(
        CableScheduleGeneratorService $generator,
        CableScheduleXlsxService      $xlsxService,
    ): void {
        $schedule = CableSchedule::find($this->cableScheduleId);

        if (! $schedule) {
            // Record was deleted — discard silently.
            return;
        }

        try {
            // Step 1: Generate CableScheduleItem records from project data.
            $itemCount = $generator->generate($schedule);

            Log::info('BuildCableScheduleJob: items generated', [
                'cable_schedule_id' => $this->cableScheduleId,
                'items_created'     => $itemCount,
            ]);

            // Step 2: Build the .xlsx file (reads the items just created, saves file,
            //         and calls $schedule->update(['filename' => ...]) internally).
            $xlsxService->build($schedule);

            // Step 3: Set status to draft.
            // CableScheduleXlsxService::build() updates filename but not status,
            // so we set it here explicitly.
            $schedule->update([
                'status'        => CableSchedule::STATUS_DRAFT,
                'error_message' => null,
            ]);

            Log::info('BuildCableScheduleJob: completed', [
                'cable_schedule_id' => $this->cableScheduleId,
                'filename'          => $schedule->filename,
            ]);
        } catch (\Throwable $e) {
            Log::error('BuildCableScheduleJob: failed', [
                'cable_schedule_id' => $this->cableScheduleId,
                'error'             => $e->getMessage(),
                'file'              => $e->getFile(),
                'line'              => $e->getLine(),
                'attempt'           => $this->attempts(),
            ]);

            $schedule->update([
                'status'        => CableSchedule::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    // =========================================================================
    // FAILURE HOOK — called by the queue after all retries are exhausted
    // =========================================================================

    public function failed(\Throwable $e): void
    {
        Log::error('BuildCableScheduleJob: all retries exhausted', [
            'cable_schedule_id' => $this->cableScheduleId,
            'error'             => $e->getMessage(),
        ]);

        CableSchedule::find($this->cableScheduleId)
            ?->update([
                'status'        => CableSchedule::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);
    }
}
