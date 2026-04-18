<?php

namespace App\Jobs;

use App\Models\RamsDocument;
use App\Services\RamsBuilderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Phase B — Generation job.
 *
 * Dispatched by RamsController::retryGeneration() after the user has reviewed,
 * approved the extracted data, and explicitly clicked "Generate RAMS".
 *
 * Responsibilities:
 *   1. Require reviewed_data — fail safely if missing.
 *   2. Set status to STATUS_GENERATING.
 *   3. Call RamsBuilderService::buildFromReview() which:
 *        - Converts reviewed_data to pipeline service inputs
 *        - Calls MethodStatementGeneratorService (AI — the only AI call in the pipeline)
 *        - Assembles final generated_data
 *        - Renders the DOCX via RamsDocumentRendererService
 *   4. Set status to STATUS_COMPLETED.
 *
 * This job does NOT re-parse or re-classify the PDF. It uses ONLY
 * reviewed_data as the source-of-truth for all generation decisions.
 */
class BuildRamsDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 180;

    public function __construct(
        public readonly int $ramsDocumentId,
    ) {}

    // =========================================================================
    // HANDLE
    // =========================================================================

    public function handle(RamsBuilderService $builder): void
    {
        $record = RamsDocument::find($this->ramsDocumentId);
        if (! $record) {
            // Record was deleted — nothing to process, discard silently.
            return;
        }

        $isManualFormGeneration = $this->isManualFormGeneration($record);

        // ── Guard: reviewed_data must be present ──────────────────────────────
        if (! $isManualFormGeneration && empty($record->reviewed_data)) {
            $errorMessage = "Cannot generate RAMS without reviewed_data. " .
                "The document must be reviewed and approved before generation can proceed.";

            Log::error('BuildRamsDocumentJob: reviewed_data is missing — cannot generate', [
                'record_id' => $this->ramsDocumentId,
                'status'    => $record->status,
            ]);

            $record->update([
                'status'        => RamsDocument::STATUS_FAILED,
                'error_message' => $errorMessage,
            ]);

            $this->fail(new \RuntimeException($errorMessage));

            return;
        }

        // ── Guard: RAMS must be approved before generation ───────────────────
        if (
            ! $isManualFormGeneration &&
            ! $record->approved_at &&
            $record->status !== RamsDocument::STATUS_APPROVED_FOR_GENERATION
        ) {
            $errorMessage = "RAMS must be approved before generation. " .
                "Review the document and click Approve before dispatching generation.";

            Log::error('BuildRamsDocumentJob: document not approved — cannot generate', [
                'record_id'   => $this->ramsDocumentId,
                'status'      => $record->status,
                'approved_at' => null,
            ]);

            $record->update([
                'status'        => RamsDocument::STATUS_FAILED,
                'error_message' => $errorMessage,
            ]);

            $this->fail(new \RuntimeException($errorMessage));

            return;
        }

        try {
            $record->update([
                'status'        => RamsDocument::STATUS_GENERATING,
                'error_message' => null,
            ]);

            Log::info('BuildRamsDocumentJob: starting Phase B generation', [
                'record_id'        => $this->ramsDocumentId,
                'mode'             => $isManualFormGeneration ? 'manual_form' : 'reviewed_data',
                'activities'       => $isManualFormGeneration ? [] : array_column($record->reviewed_data['activities'] ?? [], 'key'),
                'equipment_count'  => $isManualFormGeneration ? 0 : count($record->reviewed_data['equipment'] ?? []),
                'hazard_count'     => $isManualFormGeneration ? 0 : count($record->reviewed_data['hazards'] ?? []),
            ]);

            if ($isManualFormGeneration) {
                $builder->buildFromForm($record->form_data ?? [], $record);
            } else {
                $builder->buildFromReview(
                    $record->reviewed_data,
                    $record->form_data ?? [],
                    $record,
                );
            }

            // Refresh to read any status the builder may have set internally.
            $record->refresh();

            // Only advance to COMPLETED if the builder didn't set a terminal status.
            if (
                $record->status !== RamsDocument::STATUS_FAILED &&
                $record->status !== RamsDocument::STATUS_COMPLETED
            ) {
                $record->update([
                    'status' => $isManualFormGeneration
                        ? RamsDocument::STATUS_FOR_REVIEW
                        : RamsDocument::STATUS_COMPLETED,
                ]);
            }

            Log::info('BuildRamsDocumentJob: completed successfully', [
                'record_id'    => $this->ramsDocumentId,
                'final_status' => $record->fresh()->status,
            ]);
        } catch (\Throwable $e) {
            Log::error('BuildRamsDocumentJob: Phase B generation failed', [
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

    private function isManualFormGeneration(RamsDocument $record): bool
    {
        return ($record->form_data['source'] ?? null) === 'manual_form';
    }

    // =========================================================================
    // FAILURE HOOK
    // =========================================================================

    public function failed(\Throwable $e): void
    {
        Log::error('BuildRamsDocumentJob: all retries exhausted', [
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
