<?php

namespace App\Jobs;

use App\Models\CableSchedule;
use App\Services\CableScheduleGeneratorService;
use App\Services\CableScheduleXlsxService;
use App\Services\DocumentArtifactStorage;
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
                // Cable-schedule re-audit — `$schedule->filename` was a
                // reference to a column that doesn't exist on the
                // cable_schedules table, so the log line always emitted
                // `filename => null`. The actual filename lives in
                // source_filename (which is overwritten with the output
                // filename by the XLSX/CSV writer).
                'filename'          => $schedule->fresh()->source_filename,
                'status'            => 'draft',
                'output_format'     => $hasSpreadsheet ? 'xlsx' : 'csv',
            ]);

            // Phase 09 / NOTF-01 — completion notification (idempotent via completion_email_sent_at).
            $schedule->refresh();
            if ($schedule->status === CableSchedule::STATUS_DRAFT
                && $schedule->completion_email_sent_at === null) {

                // Set timestamp FIRST (RESEARCH 'Idempotency Pattern') so a retry sees it set.
                $schedule->update(['completion_email_sent_at' => now()]);

                try {
                    $resolver  = app(\App\Services\NotificationRecipientResolver::class);
                    $recipient = $resolver->resolveProjectRecipient($schedule->project);
                    if ($recipient?->email) {
                        $pending = \Illuminate\Support\Facades\Mail::to($recipient->email);
                        $bcc = config('rams.notifications.bcc');
                        if (is_string($bcc) && trim($bcc) !== '') {
                            $pending->bcc(trim($bcc));
                        }
                        $pending->send(new \App\Mail\CableScheduleReadyMail($schedule));
                        Log::info(
                            'BuildCableScheduleJob: completion email dispatched',
                            ['cable_schedule_id' => $schedule->id, 'recipient' => $recipient->email]
                        );
                    }
                } catch (\Throwable $mailErr) {
                    Log::warning(
                        'BuildCableScheduleJob: completion email send failed',
                        ['cable_schedule_id' => $schedule->id, 'error' => $mailErr->getMessage()]
                    );
                    // Do NOT clear completion_email_sent_at — D-14.
                }
            }
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
        // Cable-schedule re-audit — the M-09 pass missed the CSV fallback
        // too (only the XLSX writer got Ymd_His_u earlier). Bump to
        // microsecond precision so concurrent retries within the same
        // wall-clock second don't collide.
        $filename = 'cable_schedule_' . $schedule->id . '_' . now()->format('Ymd_His_u') . '.csv';
        $filePath = app(DocumentArtifactStorage::class)
            ->writePath(DocumentArtifactStorage::TYPE_CABLE, $filename);

        // Re-audit M-04 fix — take an exclusive lock before truncating +
        // writing. Two workers processing the same cable_schedule_id in
        // parallel (retry race) would otherwise interleave writes and
        // corrupt the CSV. The outer status-transition guard is a
        // best-effort race hedge — this closes the file-level window.
        $fp = fopen($filePath, 'w');
        if ($fp === false) {
            throw new \RuntimeException("BuildCableScheduleJob: failed to open {$filePath}");
        }
        if (! flock($fp, LOCK_EX)) {
            fclose($fp);
            throw new \RuntimeException("BuildCableScheduleJob: failed to acquire exclusive lock on {$filePath}");
        }

        // Cable-schedule re-audit — pass explicit $escape='' to every
        // fputcsv() call. PHP 8.4 deprecates the previously-implicit
        // default ("\") and PHP 8.5 will make it a fatal error. Passing
        // an empty string turns off backslash-escape processing entirely
        // — Excel / Google Sheets / LibreOffice all handle standard
        // double-quote CSV escaping without it.
        $csvArgs = [",", "\"", ""];

        // Header info
        fputcsv($fp, ['21st Century AV Ltd — Cable Schedule'], ...$csvArgs);
        fputcsv($fp, [implode('  |  ', array_filter([
            $schedule->project_name,
            $schedule->project_ref ? 'Ref: ' . $schedule->project_ref : null,
            $schedule->client_name ? 'Client: ' . $schedule->client_name : null,
            'Generated: ' . now()->format('d/m/Y'),
        ]))], ...$csvArgs);
        fputcsv($fp, [], ...$csvArgs); // spacer

        // Column headers
        fputcsv($fp, ['Cable ID', 'From Location', 'To Location', 'Cable Type', 'Cores', 'Length (m)', 'Notes', 'Status'], ...$csvArgs);

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
            ], ...$csvArgs);
        }

        // Release the exclusive lock before fclose so a concurrent
        // reader (rare — CSV downloads open a new handle) doesn't
        // observe the tail of a partial write.
        fflush($fp);
        flock($fp, LOCK_UN);
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

        // Phase 09 / NOTF-04 — admin failure alert (idempotent via failed_email_sent_at).
        // Cable uses $exception->getMessage() fallback when error_message column is null
        // (RESEARCH Pitfall 3). error_message column was added by plan 09-01.
        $record = CableSchedule::find($this->cableScheduleId);
        if ($record && $record->failed_email_sent_at === null) {
            $record->update(['failed_email_sent_at' => now()]);

            try {
                $resolver = app(\App\Services\NotificationRecipientResolver::class);
                $admins   = $resolver->resolveAdminRecipients();
                // Truncate error_message to 500 chars (NOTF-04c). Cable falls back to
                // $exception->getMessage() when the column is null (RESEARCH Pitfall 3).
                $rawError     = (string) ($record->error_message ?? $e?->getMessage() ?? '');
                // substr of error_message, 0, 500 — caps the body to NOTF-04c budget.
                $errorMessage = $rawError !== '' ? substr($rawError, 0, 500) : null;

                $bcc = config('rams.notifications.bcc');

                foreach ($admins as $admin) {
                    if (! $admin->email) {
                        continue;
                    }
                    $pending = \Illuminate\Support\Facades\Mail::to($admin->email);
                    if (is_string($bcc) && trim($bcc) !== '') {
                        $pending->bcc(trim($bcc));
                    }
                    $pending->send(new \App\Mail\DocumentGenerationFailedMail(
                        documentType: 'Cable Schedule',
                        projectRef:   $record->project_ref,
                        projectName:  (string) $record->project_name,
                        errorMessage: $errorMessage,
                        detailUrl:    route('cable-schedules.edit', $record),
                    ));
                }
            } catch (\Throwable $mailErr) {
                Log::warning(
                    'BuildCableScheduleJob: failure-alert email send failed',
                    ['cable_schedule_id' => $this->cableScheduleId, 'error' => $mailErr->getMessage()]
                );
            }
        }
    }
}
