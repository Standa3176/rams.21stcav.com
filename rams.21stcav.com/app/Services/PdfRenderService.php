<?php

namespace App\Services;

use Illuminate\Support\Facades\View;
use Spatie\Browsershot\Browsershot;

/**
 * Single Browsershot wrapper used by every PDF pipeline (RAMS, O&M,
 * Site Survey). Replaces the legacy dompdf calls that previously lived
 * inline inside PdfService and SurveyPdfService.
 *
 * On production (AlmaLinux 8) full Chrome 147 fails with crashpad, so we
 * point Browsershot at chrome-headless-shell via the /home/stcav/chrome
 * symlink. The path can be overridden in non-prod via CHROME_PATH.
 *
 * Chromium is invoked with --no-sandbox, --disable-dev-shm-usage, and
 * --disable-setuid-sandbox so it runs reliably under the stcav user
 * inside CWP without requiring root.
 */
class PdfRenderService
{
    /**
     * Render a Blade view to PDF.
     *
     * @param string      $view        Blade view name (e.g. 'pdf.rams').
     * @param array       $data        View data passed to view($view, $data).
     * @param string|null $writeToPath When provided, PDF is saved to this absolute
     *                                 path and the path is returned. When null,
     *                                 raw PDF bytes are returned instead.
     *
     * @return string Absolute file path (when $writeToPath is provided) or raw
     *                PDF bytes (when null).
     *
     * @throws \RuntimeException When the Blade view cannot be located.
     */
    public function fromBlade(string $view, array $data, ?string $writeToPath = null): string
    {
        if (! View::exists($view)) {
            throw new \RuntimeException(
                'PDF template missing: resources/views/' . str_replace('.', '/', $view) . '.blade.php'
            );
        }

        $html = view($view, $data)->render();

        $shot = Browsershot::html($html)
            ->setChromePath(env('CHROME_PATH', '/home/stcav/chrome'))
            ->noSandbox()
            ->addChromiumArguments([
                'disable-dev-shm-usage'  => null,
                'disable-setuid-sandbox' => null,
            ])
            ->format('A4')
            ->showBackground()
            ->emulateMedia('print')
            ->margins(10, 10, 10, 10);

        if ($writeToPath !== null) {
            $dir = dirname($writeToPath);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $shot->savePdf($writeToPath);
            return $writeToPath;
        }

        return $shot->pdf();
    }
}
