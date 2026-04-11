<?php

namespace App\Jobs;

use App\Models\Worksheet;
use App\Services\WorksheetDocxService;
use App\Services\WorksheetGeneratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Async Worksheet generation job — mirrors BuildOmManualJob exactly.
 *
 * Dispatched by WorksheetController::generateFromProject() immediately after
 * the Worksheet record is created with status=generating.
 *
 * Responsibilities:
 *   1. Call WorksheetGeneratorService::generateContent() — per-room AI steps.
 *   2. Update generated_data on the model and set status=draft.
 *   3. Build the DOCX via WorksheetDocxService::build() (updates filename).
 *
 * The Worksheet record is already in status 'generating' when this job starts.
 *
 * @see WorksheetGeneratorService — ProjectDataService → rooms[] with AI steps
 * @see WorksheetDocxService      — builds .docx and updates model filename
 */
class BuildWorksheetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 300; // AI calls per room can be slow for large projects

    public function __construct(
        public readonly int $worksheetId,
    ) {}

    // =========================================================================
    // HANDLE
    // =========================================================================

    public function handle(
        WorksheetGeneratorService $generator,
        WorksheetDocxService      $docxService,
    ): void {
        $worksheet = Worksheet::find($this->worksheetId);

        if (! $worksheet) {
            // Record was deleted — discard silently (matches BuildOmManualJob pattern).
            return;
        }

        try {
            // Step 1: Generate per-room content (AI calls per room).
            $generatedData = $generator->generateContent($worksheet);

            // Step 2: Persist generated data and advance status to draft.
            $worksheet->update([
                'generated_data' => $generatedData,
                'status'         => Worksheet::STATUS_DRAFT,
                'error_message'  => null,
            ]);

            Log::info('BuildWorksheetJob: content generated', [
                'worksheet_id' => $this->worksheetId,
                'room_count'   => count($generatedData['rooms'] ?? []),
            ]);

            // Step 3: Build the .docx file (also updates worksheet.filename).
            $docxService->build($generatedData, $worksheet);

            Log::info('BuildWorksheetJob: completed', [
                'worksheet_id' => $this->worksheetId,
                'filename'     => $worksheet->filename,
            ]);
        } catch (\Throwable $e) {
            Log::error('BuildWorksheetJob: failed', [
                'worksheet_id' => $this->worksheetId,
                'error'        => $e->getMessage(),
                'file'         => $e->getFile(),
                'line'         => $e->getLine(),
                'attempt'      => $this->attempts(),
            ]);

            $worksheet->update([
                'status'        => Worksheet::STATUS_FAILED,
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
        Log::error('BuildWorksheetJob: all retries exhausted', [
            'worksheet_id' => $this->worksheetId,
            'error'        => $e->getMessage(),
        ]);

        Worksheet::find($this->worksheetId)
            ?->update([
                'status'        => Worksheet::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);
    }
}
