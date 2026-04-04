<?php

namespace App\Services;

use App\Models\OmManual;
use App\Models\RamsDocument;
use Mpdf\Mpdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Renders RAMS and O&M documents to PDF using mPDF directly from
 * Blade HTML templates. mPDF handles table row page-breaks correctly,
 * unlike DomPDF which expands rows to fill the remaining page height.
 */
class PdfService
{
    // ── Public methods ────────────────────────────────────────────────────────

    /**
     * Render a RAMS document to PDF and return its absolute path.
     */
    public function buildRams(RamsDocument $rams): string
    {
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
        // Ensure mPDF temp directory exists and is writable
        $tmpDir = storage_path('app/mpdf-tmp');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0775, true);
        }

        $mpdf = new Mpdf([
            'format'        => 'A4',
            'orientation'   => 'P',
            'margin_top'    => 18,
            'margin_bottom' => 20,
            'margin_left'   => 15,
            'margin_right'  => 15,
            'default_font'  => 'dejavusans',
            'tempDir'       => $tmpDir,
        ]);

        $mpdf->WriteHTML($html);

        $filename = implode('_', [
            $subfolder,
            $id,
            Str::slug($projectName),
            now()->format('Ymd'),
        ]) . '.pdf';

        $diskPath = "{$subfolder}/{$filename}";

        Storage::disk('local')->put($diskPath, $mpdf->Output('', 'S'));

        return Storage::disk('local')->path($diskPath);
    }
}
