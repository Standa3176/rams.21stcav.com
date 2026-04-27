<?php

namespace App\Console\Commands;

use App\Services\PdfRenderService;
use Illuminate\Console\Command;

/**
 * Quickest possible "is the PDF pipeline alive?" check. Renders a tiny
 * Blade view to a PDF in storage/app/pdf-smoke.pdf and reports the file
 * size. Runs in CI and on production for post-deploy verification.
 *
 * Exit codes: 0 = OK, 1 = render failed or zero-byte output.
 */
class PdfSmokeTestCommand extends Command
{
    protected $signature = 'pdf:smoke-test {--out= : Override output path (defaults to storage/app/pdf-smoke.pdf)}';

    protected $description = 'Render a hello-world Blade view to PDF via Browsershot to verify the pipeline.';

    public function handle(PdfRenderService $renderer): int
    {
        $out = (string) ($this->option('out') ?: storage_path('app/pdf-smoke.pdf'));

        try {
            $renderer->fromBlade('pdf._smoke', [], $out);
        } catch (\Throwable $e) {
            $this->error('PDF smoke test FAILED: ' . $e->getMessage());
            return self::FAILURE;
        }

        $size = is_file($out) ? filesize($out) : 0;
        if ($size <= 0) {
            $this->error('PDF smoke test FAILED: zero-byte output at ' . $out);
            return self::FAILURE;
        }

        $this->info(sprintf('PDF smoke test OK — wrote %d bytes to %s', $size, $out));
        return self::SUCCESS;
    }
}
