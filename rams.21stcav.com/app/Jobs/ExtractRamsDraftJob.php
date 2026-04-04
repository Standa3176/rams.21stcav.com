<?php

namespace App\Jobs;

use App\Models\RamsDocument;
use App\Services\QuoteTextExtractorService;
use App\Services\RamsExtractionDraftBuilderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Phase A — Extraction job.
 *
 * Dispatched by QuoteUploadController after the PDF is stored and the
 * placeholder RamsDocument record is created. Also dispatched by
 * RamsController::retryExtraction() when the user retries a failed extraction.
 *
 * Responsibilities:
 *   1. Validate the stored PDF path exists on disk (read from $rams->filename).
 *   2. Extract text from the PDF (QuoteTextExtractorService).
 *   3. Parse, classify, and resolve risks locally (no AI).
 *   4. Save the structured result to extracted_data.
 *   5. Advance status to STATUS_AWAITING_REVIEW.
 *
 * File path source of truth: $rams->filename — the relative path written by
 * QuoteUploadController at record creation. Storage::path() is used to
 * resolve it to an absolute filesystem path. form_data is NOT used for the
 * file path under any circumstances.
 *
 * This job does NOT call any AI services and does NOT render a DOCX.
 * Phase B (BuildRamsDocumentJob) handles generation after the user
 * reviews and approves the extracted data, then clicks "Generate RAMS".
 */
class ExtractRamsDraftJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 600; // OCR of large image-based PDFs (print-to-PDF) can take several minutes

    public function __construct(
        public readonly int $ramsDocumentId,
    ) {}

    // =========================================================================
    // HANDLE
    // =========================================================================

    public function handle(
        QuoteTextExtractorService         $extractor,
        RamsExtractionDraftBuilderService $draftBuilder,
    ): void {
        $record = RamsDocument::find($this->ramsDocumentId);
        if (! $record) {
            // Record was deleted — nothing to process, discard silently.
            return;
        }

        // ── Resolve PDF path from the record's filename column ────────────────
        // QuoteUploadController stores the relative path in filename.
        // Storage::path() resolves it to the absolute filesystem path.
        // form_data is never used for the file path.
        $filePath = Storage::disk('local')->path($record->filename);

        // ── File path guard ───────────────────────────────────────────────────
        if (! $record->filename || ! file_exists($filePath)) {
            $errorMessage = "Stored PDF not found or unreadable: {$filePath}";

            Log::error('ExtractRamsDraftJob: stored PDF not found', [
                'record_id' => $this->ramsDocumentId,
                'pdf_path'  => $filePath,
            ]);

            $record->update([
                'status'        => RamsDocument::STATUS_FAILED,
                'error_message' => $errorMessage,
            ]);

            $this->fail(new \RuntimeException($errorMessage));

            return;
        }

        try {
            // Stage 1: Extract raw text from PDF (local, no AI).
            $extractedText = $extractor->extract($filePath);

            Log::info('ExtractRamsDraftJob: text extracted', [
                'record_id'   => $this->ramsDocumentId,
                'text_length' => strlen($extractedText),
            ]);

            // Stage 2: Parse / classify / risk → canonical review schema (no AI).
            // form_data is passed for user-supplied overrides (client name, ref, etc.)
            // but is NOT used for the file path.
            $formData      = $record->form_data ?? [];
            $extractedData = $draftBuilder->build($extractedText, $formData);

            Log::info('ExtractRamsDraftJob: draft built', [
                'record_id'       => $this->ramsDocumentId,
                'equipment_count' => count($extractedData['equipment']  ?? []),
                'activity_count'  => count($extractedData['activities'] ?? []),
                'hazard_count'    => count($extractedData['hazards']    ?? []),
                'confidence'      => $extractedData['meta']['parser_confidence'] ?? null,
            ]);

            // Stage 3: Persist extracted data and advance to awaiting review.
            $record->update([
                'extracted_data' => $extractedData,
                'error_message'  => null,
                'status'         => RamsDocument::STATUS_AWAITING_REVIEW,
            ]);

            Log::info('ExtractRamsDraftJob: completed — awaiting review', [
                'record_id' => $this->ramsDocumentId,
            ]);
        } catch (\Throwable $e) {
            Log::error('ExtractRamsDraftJob: failed', [
                'record_id' => $this->ramsDocumentId,
                'error'     => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'attempt'   => $this->attempts(),
            ]);

            $record->update([
                'status'        => RamsDocument::STATUS_FAILED,
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
        Log::error('ExtractRamsDraftJob: all retries exhausted', [
            'record_id' => $this->ramsDocumentId,
            'error'     => $e->getMessage(),
        ]);

        RamsDocument::find($this->ramsDocumentId)
            ?->update([
                'status'        => RamsDocument::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);
    }
}
