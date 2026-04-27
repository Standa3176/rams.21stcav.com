<?php

namespace App\Services;

use App\Models\OmManual;
use App\Models\RamsDocument;
use Illuminate\Support\Str;

/**
 * Renders RAMS and O&M documents to PDF via Browsershot (chrome-headless-shell).
 *
 * Previously used Dompdf directly — that produced brittle output and
 * duplicated render code with SurveyPdfService. Now both pipelines share
 * one engine via PdfRenderService.
 *
 * Outputs land under storage/app/documents/{rams,om-manuals}/ via the
 * H-07 DocumentArtifactStorage convention so legacy/current path resolution
 * stays consistent with the DOCX writers.
 */
class PdfService
{
    public function __construct(
        private readonly PdfRenderService        $renderer,
        private readonly DocumentArtifactStorage $artifacts,
    ) {}

    // ── Public methods ────────────────────────────────────────────────────────

    /**
     * Render a RAMS document to PDF and return its absolute path.
     */
    public function buildRams(RamsDocument $rams): string
    {
        $filename = $this->filenameFor('rams', $rams->id, $rams->project_name ?? 'rams');
        $path     = $this->artifacts->writePath(DocumentArtifactStorage::TYPE_RAMS, $filename);

        return $this->renderer->fromBlade('pdf.rams', [
            'rams' => $rams,
            'data' => $rams->generated_data ?? [],
        ], $path);
    }

    /**
     * Render an O&M Manual to PDF and return its absolute path.
     */
    public function buildOmManual(OmManual $manual): string
    {
        $filename = $this->filenameFor('om-manuals', $manual->id, $manual->project_name ?? 'om-manual');
        $path     = $this->artifacts->writePath(DocumentArtifactStorage::TYPE_OM, $filename);

        return $this->renderer->fromBlade('pdf.om-manual', [
            'manual' => $manual,
            'data'   => $manual->generated_data ?? [],
        ], $path);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Build the legacy filename pattern ({subfolder}_{id}_{slug}_{ymd}.pdf) so
     * existing email/download flows that reference the filename keep working.
     */
    private function filenameFor(string $subfolder, int $id, string $projectName): string
    {
        return implode('_', [
            $subfolder,
            $id,
            Str::slug($projectName) ?: 'untitled',
            now()->format('Ymd'),
        ]) . '.pdf';
    }
}
