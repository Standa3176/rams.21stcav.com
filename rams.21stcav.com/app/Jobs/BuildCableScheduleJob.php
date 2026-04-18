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
 * Status transitions:
 *   generating → draft   (success)
 *   generating → failed  (any exception, timeout, or retry exhaustion)
 *
 * No AI calls — cable type inference is deterministic keyword matching.
 *
 * NOTE: cable_schedules table does NOT have an error_message column.
 * Error context is logged only — never written to the model.
 */
class BuildCableScheduleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 120;

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
            Log::warning('BuildCableScheduleJob: record not found, discarding', [
                'cable_schedule_id' => $this->cableScheduleId,
            ]);
            return;
        }

        // ── Ensure status is generating ──────────────────────────────────────
        if ($schedule->status !== CableSchedule::STATUS_GENERATING) {
            $schedule->update(['status' => CableSchedule::STATUS_GENERATING]);
        }

        Log::info('BuildCableScheduleJob: starting', [
            'cable_schedule_id' => $this->cableScheduleId,
            'attempt'           => $this->attempts(),
            'status'            => 'generating',
        ]);

        $hasSpreadsheet = class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class);

        Log::info('BuildCableScheduleJob: dependency check', [
            'cable_schedule_id' => $this->cableScheduleId,
            'phpspreadsheet'    => $hasSpreadsheet ? 'available' : 'missing — will use CSV fallback',
        ]);

        try {
            // Step 1: Generate CableScheduleItem records from project data.
            $itemCount = $generator->generate($schedule);

            Log::info('BuildCableScheduleJob: items generated', [
                'cable_schedule_id' => $this->cableScheduleId,
                'items_created'     => $itemCount,
                'attempt'           => $this->attempts(),
            ]);

            // Step 2: Build output file.
            if ($hasSpreadsheet) {
                // XLSX path (preferred)
                $xlsxService->build($schedule);
                Log::info('BuildCableScheduleJob: XLSX built', [
                    'cable_schedule_id' => $this->cableScheduleId,
                    'output'            => 'xlsx',
                ]);
            } else {
                // CSV fallback when PhpSpreadsheet is not installed
                $this->buildCsvFallback($schedule);
                Log::info('BuildCableScheduleJob: CSV fallback built', [
                    'cable_schedule_id' => $this->cableScheduleId,
                    'output'            => 'csv',
                ]);
            }

            // Step 3: Set status to draft.
            $schedule->update([
                'status' => CableSchedule::STATUS_DRAFT,
            ]);

            Log::info('BuildCableScheduleJob: completed successfully', [
                'cable_schedule_id' => $this->cableScheduleId,
                'attempt'           => $this->attempts(),
                'filename'          => $schedule->filename,
                'status'            => 'draft',
                'output_format'     => $hasSpreadsheet ? 'xlsx' : 'csv',
            ]);
        } catch (\Throwable $e) {
            Log::error('BuildCableScheduleJob: failed', [
                'cable_schedule_id' => $this->cableScheduleId,
                'attempt'           => $this->attempts(),
                'exception_class'   => get_class($e),
                'error'             => $e->getMessage(),
                'file'              => $e->getFile(),
                'line'              => $e->getLine(),
            ]);

            // Guarantee status leaves "generating"
            // NOTE: no error_message column on cable_schedules — log only
            try {
                $schedule->update([
                    'status' => CableSchedule::STATUS_FAILED,
                ]);
            } catch (\Throwable $dbErr) {
                Log::critical('BuildCableScheduleJob: could not set failed status', [
                    'cable_schedule_id' => $this->cableScheduleId,
                    'db_error'          => $dbErr->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    // =========================================================================
    // CSV FALLBACK — when PhpSpreadsheet is not installed
    // =========================================================================

    /**
     * Build a CSV cable schedule as a deterministic fallback.
     * Same data rows as the XLSX builder, saved with .csv extension.
     */
    private function buildCsvFallback(CableSchedule $schedule): void
    {
        $directory = storage_path('app/private/cable-schedules');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $filename = 'cable_schedule_' . $schedule->id . '_' . now()->format('Ymd') . '.csv';
        $filePath = $directory . '/' . $filename;

        $fp = fopen($filePath, 'w');

        // Header info
        fputcsv($fp, ['21st Century AV Ltd — Cable Schedule']);
        fputcsv($fp, [implode('  |  ', array_filter([
            $schedule->project_name,
            $schedule->project_ref ? 'Ref: ' . $schedule->project_ref : null,
            $schedule->client_name ? 'Client: ' . $schedule->client_name : null,
            'Generated: ' . now()->format('d/m/Y'),
        ]))]);
        fputcsv($fp, []); // spacer

        // Column headers
        fputcsv($fp, ['Cable ID', 'From Location', 'To Location', 'Cable Type', 'Cores', 'Length (m)', 'Notes', 'Status']);

        // Data rows
        foreach ($schedule->items as $item) {
            fputcsv($fp, [
                $item->cable_id        ?? '',
                $item->from_location   ?? '',
                $item->to_location     ?? '',
                $item->cable_type      ?? '',
                $item->cores           ?? '',
                $item->approx_length_m ?? '',
                $item->notes           ?? '',
                '',
            ]);
        }

        fclose($fp);

        // Persist filename via source_filename (always exists on table)
        $schedule->update(['source_filename' => $filename]);
    }

    // =========================================================================
    // FAILURE HOOK — called by the queue after all retries are exhausted
    // =========================================================================

    public function failed(\Throwable $e): void
    {
        Log::error('BuildCableScheduleJob: all retries exhausted', [
            'cable_schedule_id' => $this->cableScheduleId,
            'exception_class'   => get_class($e),
            'error'             => $e->getMessage(),
        ]);

        try {
            CableSchedule::find($this->cableScheduleId)
                ?->update([
                    'status' => CableSchedule::STATUS_FAILED,
                ]);
        } catch (\Throwable $dbErr) {
            Log::critical('BuildCableScheduleJob::failed: could not set failed status', [
                'cable_schedule_id' => $this->cableScheduleId,
                'db_error'          => $dbErr->getMessage(),
            ]);
        }
    }
}
