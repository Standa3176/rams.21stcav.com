<?php

namespace App\Services;

use RuntimeException;

/**
 * Extracts text from a PDF using Tesseract OCR as a fallback for scanned or
 * image-only PDFs that yield no selectable text.
 *
 * Requirements:
 *   - Tesseract 4+ installed and on $PATH  (apt install tesseract-ocr)
 *   - Poppler utils for PDF→image conversion (apt install poppler-utils)
 *
 * Usage:
 *   Injected automatically by PdfTextExtractorService when parsed text is
 *   too short to be useful (< 200 characters).
 */
class PdfOcrExtractorService
{
    /**
     * Run Tesseract OCR on the given PDF and return the extracted text.
     *
     * Tesseract is invoked as:
     *   tesseract <input.pdf> stdout pdf
     *
     * The `pdf` config flag tells Tesseract to accept PDF input directly
     * (requires the pdfimages/pdftotext pipeline via Leptonica + Poppler).
     *
     * @param  string $path  Absolute filesystem path to the PDF file.
     * @return string        OCR-extracted plain text, normalised.
     *
     * @throws RuntimeException If Tesseract is not available or returns an error.
     */
    public function extract(string $path): string
    {
        if (!is_file($path)) {
            throw new RuntimeException("OCR input file does not exist: {$path}");
        }

        $tesseract = $this->resolveBinary('tesseract', '/usr/bin/tesseract');
        $pdftoppm  = $this->resolveBinary('pdftoppm', '/usr/bin/pdftoppm');

        $tempRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        $workDir  = $tempRoot . DIRECTORY_SEPARATOR . 'rams_ocr_' . bin2hex(random_bytes(6));

        if (!@mkdir($workDir, 0700, true) && !is_dir($workDir)) {
            throw new RuntimeException("Unable to create OCR temp directory: {$workDir}");
        }

        try {
            $prefix = $workDir . DIRECTORY_SEPARATOR . 'page';
            $pdfArg = escapeshellarg($path);
            $preArg = escapeshellarg($prefix);

            [$ppmOut, $ppmErr, $ppmCode] = $this->runCommand("{$pdftoppm} -png -r 200 {$pdfArg} {$preArg}");

            if ($ppmCode !== 0) {
                $msg = trim($ppmErr !== '' ? $ppmErr : $ppmOut);
                throw new RuntimeException('pdftoppm failed: ' . ($msg !== '' ? $msg : 'no error output'));
            }

            $images = glob($workDir . DIRECTORY_SEPARATOR . 'page-*.png');
            if ($images === false || count($images) === 0) {
                throw new RuntimeException('pdftoppm produced no images for OCR.');
            }

            natsort($images);

            $chunks = [];
            foreach ($images as $imagePath) {
                $imgArg = escapeshellarg($imagePath);
                [$ocrOut, $ocrErr, $ocrCode] = $this->runCommand("{$tesseract} {$imgArg} stdout -l eng --psm 6");

                if ($ocrCode !== 0) {
                    $msg = trim($ocrErr !== '' ? $ocrErr : $ocrOut);
                    throw new RuntimeException('tesseract failed: ' . ($msg !== '' ? $msg : 'no error output'));
                }

                $ocrOut = trim($ocrOut);
                if ($ocrOut !== '') {
                    $chunks[] = $ocrOut;
                }
            }

            $raw = trim(implode("\n\n", $chunks));
            if ($raw === '') {
                throw new RuntimeException('OCR command produced no output.');
            }

            return $this->normalizeText($raw);
        } finally {
            $this->deleteDirectory($workDir);
        }
    }

    /**
     * @return array{0:string,1:string,2:int}
     */
    private function runCommand(string $command): array
    {
        if (\function_exists('proc_open')) {
            $spec = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $pipes = [];
            $proc  = @proc_open($command, $spec, $pipes);

            if (\is_resource($proc)) {
                fclose($pipes[0]);

                $stdout = stream_get_contents($pipes[1]) ?: '';
                $stderr = stream_get_contents($pipes[2]) ?: '';

                fclose($pipes[1]);
                fclose($pipes[2]);

                $code = proc_close($proc);

                return [$stdout, $stderr, (int) $code];
            }
        }

        if (\function_exists('shell_exec')) {
            $stdout = shell_exec($command . ' 2>&1');
            return [trim((string) $stdout), '', 0];
        }

        if (\function_exists('exec')) {
            $out  = [];
            $code = 0;
            exec($command . ' 2>&1', $out, $code);
            return [implode("\n", $out), '', (int) $code];
        }

        throw new RuntimeException('OCR shell functions are disabled (proc_open, shell_exec, and exec unavailable).');
    }

    private function resolveBinary(string $name, string $preferredPath): string
    {
        if (is_executable($preferredPath)) {
            return $preferredPath;
        }

        [$stdout, $stderr, $code] = $this->runCommand('command -v ' . escapeshellarg($name));
        if ($code !== 0) {
            $msg = trim($stderr !== '' ? $stderr : $stdout);
            throw new RuntimeException("Required binary not found: {$name}" . ($msg !== '' ? " ({$msg})" : ''));
        }

        $path = trim($stdout);
        if ($path === '' || !is_executable($path)) {
            throw new RuntimeException("Required binary is not executable: {$name} ({$path})");
        }

        return $path;
    }

    private function normalizeText(string $raw): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $raw);
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        $lines = array_map('trim', explode("\n", $text));
        $lines = array_values(array_filter(
            $lines,
            static fn (string $line): bool => mb_strlen($line) >= 2
        ));

        return implode("\n", $lines);
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($dir);
    }
}
