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
 * Async O&M generation job — matches the RAMS BuildRamsDocumentJob pattern.
 *
 * Dispatched by OmManualController::generateFromProject() immediately after
 * the OmManual record is created and Pass 1 extraction is complete.
 *
 * Responsibilities:
 *   1. Run Pass 2: OmManualGeneratorService::generateContent() (AI call).
 *   2. Build the .docx: OmManualDocxService::build().
 *   3. Advance status to 'draft' on success, 'failed' on error.
 *
 * The OmManual record is already in status 'generating' when this job starts.
 * Pass 1 (extractFromProjectPackage / extractFromPdf) is always synchronous
 * in the controller so the user gets immediate feedback that the job is queued.
 */
class BuildOmManualJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 300; // AI generation can be slow for large equipment lists

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
            // Record was deleted — discard silently (matches ExtractRamsDraftJob pattern).
            return;
        }

        try {
            // Pass 2: AI generates the full O&M content from reviewed extracted_data.
            $provider      = config('ai.default', 'claude');
            $user          = User::find($manual->user_id);
            $generatedData = $generator->generateContent(
                manual:   $manual,
                user:     $user,
                provider: $provider,
            );

            $manual->update([
                'generated_data' => $generatedData,
                'status'         => OmManual::STATUS_DRAFT,
                'error_message'  => null,
            ]);

            Log::info('BuildOmManualJob: content generated', [
                'om_manual_id' => $this->omManualId,
            ]);

            // Build the .docx file.
            $docxService->build($generatedData, $manual);

            Log::info('BuildOmManualJob: completed', [
                'om_manual_id' => $this->omManualId,
                'filename'     => $manual->filename,
            ]);
        } catch (\Throwable $e) {
            Log::error('BuildOmManualJob: failed', [
                'om_manual_id' => $this->omManualId,
                'error'        => $e->getMessage(),
                'file'         => $e->getFile(),
                'line'         => $e->getLine(),
                'attempt'      => $this->attempts(),
            ]);

            $manual->update([
                'status'        => OmManual::STATUS_FAILED,
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
        Log::error('BuildOmManualJob: all retries exhausted', [
            'om_manual_id' => $this->omManualId,
            'error'        => $e->getMessage(),
        ]);

        OmManual::find($this->omManualId)
            ?->update([
                'status'        => OmManual::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);
    }
}
