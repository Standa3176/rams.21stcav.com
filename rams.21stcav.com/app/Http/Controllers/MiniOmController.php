<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\DocumentArtifactStorage;
use App\Services\MiniOmBuilderService;
use App\Services\PdfRenderService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * MiniOmController — single-action controller for the on-demand Mini O&M
 * PDF (260506-qa9). Distinct from the heavyweight AI-assisted OmManual
 * pipeline: nothing is written to the `om_manuals` table; the artifact is
 * persisted to disk via DocumentArtifactStorage::TYPE_OM with a 'mini-om-'
 * filename prefix so it stays discoverable alongside full O&M outputs but
 * is filterable for downstream tooling.
 *
 * Pipeline:
 *   GET /projects/{project}/mini-om/pdf
 *     -> MiniOmBuilderService::build($project)        (pure aggregation)
 *     -> PdfRenderService::fromBlade('pdf.mini-om')   (Browsershot Chromium)
 *     -> response()->download(...)                    (BinaryFileResponse)
 *
 * Authorisation: ProjectPolicy::view (owner OR admin) — same gate as the
 * project show page, since this PDF is just a different rendering of the
 * same data the user can already see.
 */
class MiniOmController extends Controller
{
    public function __construct(
        private readonly MiniOmBuilderService $builder,
        private readonly PdfRenderService $renderer,
        private readonly DocumentArtifactStorage $artifacts,
    ) {}

    // ══════════════════════════════════════════════════════════════════════════
    // Generate Mini O&M PDF (D-LOCK-5 — fresh render each request, no DB cache)
    // ══════════════════════════════════════════════════════════════════════════

    public function generate(Project $project): BinaryFileResponse
    {
        $this->authorize('view', $project);

        $data = $this->builder->build($project);

        // ── Filename: deterministic + timestamped so engineers can re-download
        // a previous render later without losing it. The 'mini-om-' prefix lets
        // future tooling distinguish from the heavyweight pipeline (which uses
        // 'om-manuals_' / 'om-' prefixes).
        $filename = sprintf(
            'mini-om-%d-%s-%s.pdf',
            $project->id,
            Str::slug($project->client_name ?: 'unknown'),
            now()->format('Ymd-His'),
        );
        $absPath = $this->artifacts->writePath(DocumentArtifactStorage::TYPE_OM, $filename);

        // Tight margins — the running header/footer is intentionally absent
        // for v1 (the Blade's own footer line at the support page closes the
        // doc). marginTop=12mm gives breathing room without crowding the cover.
        $this->renderer->fromBlade('pdf.mini-om', $data, $absPath, [
            'marginTop'    => 12,
            'marginBottom' => 12,
            'marginLeft'   => 0,
            'marginRight'  => 0,
        ]);

        Log::info('MiniOmController: generated mini O&M PDF', [
            'project_id' => $project->id,
            'filename'   => $filename,
        ]);

        $downloadName = sprintf(
            '%s - %s - Mini O&M.pdf',
            $project->ref ?: ('PROJ-' . $project->id),
            $project->client_name ?: 'Mini O&M',
        );

        return response()->download($absPath, $downloadName, [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
