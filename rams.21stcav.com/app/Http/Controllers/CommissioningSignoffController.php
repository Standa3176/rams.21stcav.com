<?php

namespace App\Http\Controllers;

use App\Exceptions\CommissioningSignoffException;
use App\Http\Requests\FinaliseCommissioningSignoffRequest;
use App\Models\InstallProgramme;
use App\Services\CommissioningPdfService;
use App\Services\CommissioningService;
use App\Services\DocumentArtifactStorage;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * CommissioningSignoffController — preview + finalise + download for the
 * snagging PDF sign-off flow (D-10, D-16).
 *
 * Endpoints
 *   POST /install-programmes/{programme}/commissioning/signoff/preview
 *     → Generates a preview snagging PDF (no signature block). Not persisted
 *       beyond the engineer review session — the finalise endpoint regenerates
 *       the final PDF with the signature embedded.
 *   POST /install-programmes/{programme}/commissioning/signoff/finalise
 *     → Atomic D-16 transaction: signoff insert → final PDF → Project + Programme
 *       state advance. Any failure rolls back all four writes.
 *   GET  /install-programmes/{programme}/snagging
 *     → Streams the final snagging PDF. Ownership-guarded (T-16-06); 404 if no
 *       signoff / file missing.
 *
 * Ownership rule mirrors CommissioningController::show:
 *   - Owner / admin: always allowed
 *   - Engineer assigned to ANY install_task in the programme: allowed
 *   - Everyone else: 403
 *
 * @see CommissioningService — owns the D-16 transaction + state-machine gate
 * @see CommissioningPdfService — owns the DomPDF render + TYPE_SNAGGING write
 */
class CommissioningSignoffController extends Controller
{
    public function __construct(
        private readonly CommissioningService $service,
        private readonly CommissioningPdfService $pdfService,
        private readonly DocumentArtifactStorage $artifacts,
    ) {}

    /**
     * POST /install-programmes/{programme}/commissioning/signoff/preview
     *
     * Generate a preview snagging PDF (no signature block). Returns the
     * preview URL + filename. Preview files are disposable — not referenced
     * from the signoff row; the finalise endpoint regenerates the final PDF.
     */
    public function preview(InstallProgramme $programme): JsonResponse
    {
        $this->authorise($programme);

        $filename = $this->pdfService->buildPreview($programme);

        return response()->json([
            'preview_url' => route('commissioning.snagging.show', [
                'programme' => $programme,
                'v'         => 'preview',
                'file'      => $filename,
            ]),
            'filename'    => $filename,
        ]);
    }

    /**
     * POST /install-programmes/{programme}/commissioning/signoff/finalise
     *
     * Finalise: capture signature, embed in PDF, advance state (D-16).
     * CommissioningSignoffException → 422 with the exception message.
     */
    public function finalise(FinaliseCommissioningSignoffRequest $request, InstallProgramme $programme): JsonResponse
    {
        $this->authorise($programme);

        try {
            $signoff = $this->service->finalise($programme, $request->validated());
        } catch (CommissioningSignoffException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'final_pdf_url'    => route('commissioning.snagging.show', ['programme' => $programme]),
            'project_status'   => $programme->project->fresh()->status,
            'programme_status' => $programme->fresh()->status,
            'signoff_id'       => $signoff->id,
        ]);
    }

    /**
     * GET /install-programmes/{programme}/snagging
     *
     * Stream the final snagging PDF. Ownership-guarded (T-16-06). Returns 404
     * when no signoff row exists or the file is missing from the documents
     * disk (DocumentArtifactStorage::readPath returns null).
     */
    public function downloadSnagging(InstallProgramme $programme): BinaryFileResponse
    {
        $this->authorise($programme);

        $signoff = $programme->commissioningSignoff;
        abort_if($signoff === null, 404, 'No snagging PDF available — programme has not been signed off.');

        $path = $this->artifacts->readPath(DocumentArtifactStorage::TYPE_SNAGGING, $signoff->snagging_pdf_path);
        abort_if($path === null, 404, 'Snagging PDF file missing.');

        $downloadName = sprintf('snagging-%s.pdf', $programme->project->ref ?: $programme->project->id);

        return response()->download($path, $downloadName, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * T-16-06 ownership guard — project owner, admin, or engineer assigned to
     * at least one task on the programme. Mirrors CommissioningController::show
     * so the finalise surface uses the same rule as the checklist surface.
     */
    private function authorise(InstallProgramme $programme): void
    {
        $programme->loadMissing('project');
        $user = auth()->user();

        $isOwnerOrAdmin = $programme->project->user_id === $user->id
            || $user->isAdmin();

        $isAssignedEngineer = $programme->tasks()
            ->where('assigned_to', $user->id)
            ->exists();

        abort_if(! $isOwnerOrAdmin && ! $isAssignedEngineer, 403);
    }
}
