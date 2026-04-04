<?php

namespace App\Services;

use App\Models\RamsDocument;
use Illuminate\Validation\ValidationException;

/**
 * Approves a RAMS document for generation.
 *
 * Responsibilities (in order):
 *   1. Validate the reviewed payload via RamsReviewValidatorService.
 *   2. Persist the validated data to reviewed_data.
 *   3. Record approval metadata (approved_by, approved_at).
 *   4. Advance status to STATUS_APPROVED.
 *
 * Generation is NOT dispatched here. The user must explicitly click
 * "Generate RAMS" on the index page to dispatch BuildRamsDocumentJob.
 *
 * @throws ValidationException  if the payload fails validation
 */
class ApproveRamsForGenerationService
{
    public function __construct(
        private readonly RamsReviewValidatorService $validator,
    ) {}

    /**
     * Validate and persist the reviewed data, advancing status to approved.
     *
     * @param  array        $payload  Reviewed data from the form (canonical review schema)
     * @param  RamsDocument $record   The RAMS document being approved
     *
     * @throws ValidationException
     */
    public function approve(array $payload, RamsDocument $record): void
    {
        // Step 1: Validate — throws ValidationException on failure.
        $this->validator->validate($payload);

        // Step 2: Persist reviewed data + approval metadata.
        // Status is set to approved. Generation is triggered separately
        // by the user clicking "Generate RAMS" on the index page.
        $record->update([
            'reviewed_data' => $payload,
            'approved_by'   => auth()->id(),
            'approved_at'   => now(),
            'error_message' => null,
            'status'        => RamsDocument::STATUS_APPROVED,
        ]);
    }
}
