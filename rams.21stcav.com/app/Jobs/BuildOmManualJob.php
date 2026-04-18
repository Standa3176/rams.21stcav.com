<?php

namespace App\Jobs;

use App\Core\Modules\OMManual\OmManualGeneratorService;
use App\Models\OmManual;
use App\Models\User;
use App\Services\OmManualDocxService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Async O&M generation job.
 *
 * Status transitions:
 *   generating → draft   (success)
 *   generating → failed  (any exception, timeout, or retry exhaustion)
 *
 * The OmManual record is already in status 'generating' when this job starts.
 */
class BuildOmManualJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 300;

    public function __construct(
        public readonly int $omManualId,
    ) {}

    // =========================================================================
    // HANDLE
    // =========================================================================

    public function handle(
        OmManualGeneratorService $generator,
        OmManualDocxService      $docxService,
    ): void {
        $manual = OmManual::find($this->omManualId);

        if (! $manual) {
            Log::warning('BuildOmManualJob: record not found, discarding', [
                'om_manual_id' => $this->omManualId,
            ]);
            return;
        }

        // ── Ensure status is generating ──────────────────────────────────────
        if ($manual->status !== OmManual::STATUS_GENERATING) {
            $manual->update(['status' => OmManual::STATUS_GENERATING]);
        }

        Log::info('BuildOmManualJob: starting', [
            'om_manual_id' => $this->omManualId,
            'attempt'      => $this->attempts(),
            'status'       => 'generating',
        ]);

        try {
            // Pass 2: AI generates the full O&M content.
            $provider      = config('ai.default', 'claude');
            $user          = User::find($manual->user_id) ?? User::first();
            $generatedData = $generator->generateContent(
                manual:   $manual,
                user:     $user,
                provider: $provider,
            );

            Log::info('BuildOmManualJob: content generated, starting DOCX build', [
                'om_manual_id' => $this->omManualId,
                'attempt'      => $this->attempts(),
            ]);

            $manual->update([
                'generated_data' => $generatedData,
                'status'         => OmManual::STATUS_DRAFT,
                'error_message'  => null,
            ]);

            // Build the .docx file.
            $docxService->build($generatedData, $manual);

            Log::info('BuildOmManualJob: completed successfully', [
                'om_manual_id' => $this->omManualId,
                'attempt'      => $this->attempts(),
                'filename'     => $manual->filename,
                'status'       => 'draft',
            ]);
        } catch (\Throwable $e) {
            Log::error('BuildOmManualJob: failed', [
                'om_manual_id'    => $this->omManualId,
                'attempt'         => $this->attempts(),
                'exception_class' => get_class($e),
                'error'           => $e->getMessage(),
                'file'            => $e->getFile(),
                'line'            => $e->getLine(),
            ]);

            // Guarantee status leaves "generating"
            try {
                $manual->update([
                    'status'        => OmManual::STATUS_FAILED,
                    'error_message' => substr($e->getMessage(), 0, 500),
                ]);
            } catch (\Throwable $dbErr) {
                Log::critical('BuildOmManualJob: could not set failed status', [
                    'om_manual_id' => $this->omManualId,
                    'db_error'     => $dbErr->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    // =========================================================================
    // FAILURE HOOK — called by the queue after all retries are exhausted
    // =========================================================================

    public function failed(\Throwable $e): void
    {
        Log::error('BuildOmManualJob: all retries exhausted', [
            'om_manual_id'    => $this->omManualId,
            'exception_class' => get_class($e),
            'error'           => $e->getMessage(),
        ]);

        try {
            OmManual::find($this->omManualId)
                ?->update([
                    'status'        => OmManual::STATUS_FAILED,
                    'error_message' => 'All retries exhausted: ' . substr($e->getMessage(), 0, 400),
                ]);
        } catch (\Throwable $dbErr) {
            Log::critical('BuildOmManualJob::failed: could not set failed status', [
                'om_manual_id' => $this->omManualId,
                'db_error'     => $dbErr->getMessage(),
            ]);
        }
    }
}
