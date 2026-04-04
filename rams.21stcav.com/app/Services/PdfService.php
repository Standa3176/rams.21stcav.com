<?php

namespace App\Services;

use App\Models\OmManual;
use App\Models\RamsDocument;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Renders RAMS and O&M documents to PDF using DomPDF directly from
 * Blade HTML templates, bypassing the lossy PhpWord→DomPDF chain.
 */
class PdfService
{
    // ── Public methods ────────────────────────────────────────────────────────

    /**
     * Render a RAMS document to PDF and return its absolute path.
     */
    public function buildRams(RamsDocument $rams): string
    {
        if (! view()->exists('pdf.rams')) {
            throw new \RuntimeException('PDF template missing: resources/views/pdf/rams.blade.php');
        }

        $html = view('pdf.rams', [
            'rams' => $rams,
            'data' => $rams->generated_data ?? [],
        ])->render();

        return $this->renderToFile($html, 'rams', $rams->id, $rams->project_name);
    }

    /**
     * Render an O&M Manual to PDF and return its absolute path.
     */
    public function buildOmManual(OmManual $manual): string
    {
        $html = view('pdf.om-manual', [
            'manual' => $manual,
            'data'   => $manual->generated_data ?? [],
        ])->render();

        return $this->renderToFile($html, 'om-manuals', $manual->id, $manual->project_name);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function renderToFile(string $html, string $subfolder, int $id, string $projectName): string
    {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = implode('_', [
            $subfolder,
            $id,
            Str::slug($projectName),
            now()->format('Ymd'),
        ]) . '.pdf';

        $diskPath = "{$subfolder}/{$filename}";

        // Storage::disk('local')->put() creates parent directories automatically
        Storage::disk('local')->put($diskPath, $dompdf->output());

        return Storage::disk('local')->path($diskPath);
    }
}
