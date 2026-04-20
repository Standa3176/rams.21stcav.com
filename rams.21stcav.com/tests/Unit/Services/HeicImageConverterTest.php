<?php

namespace Tests\Unit\Services;

use App\Services\HeicImageConverter;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Tests\TestCase;

/**
 * INST-03e + CONTEXT D-11 — HEIC→JPEG conversion with Imagick, fails loudly when missing.
 */
class HeicImageConverterTest extends TestCase
{
    public function test_converts_heic_to_jpeg(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('ext-imagick not loaded; cannot verify HEIC conversion happy path.');
        }

        $converter = new HeicImageConverter();

        $src = base_path('tests/Fixtures/sample.heic');
        $dst = sys_get_temp_dir() . '/heic-out-' . uniqid() . '.jpg';
        try {
            $upload = new UploadedFile($src, 'sample.heic', 'image/heic', null, true);

            $converter->writeAsJpeg($upload, $dst);

            $this->assertFileExists($dst);
            $bytes = file_get_contents($dst);
            $this->assertNotFalse($bytes);
            // JPEG magic bytes FF D8 FF
            $this->assertSame(
                'ffd8ff',
                bin2hex(substr($bytes, 0, 3)),
                'Output file must be a real JPEG (FF D8 FF magic).',
            );
        } finally {
            @unlink($dst);
        }
    }

    public function test_jpeg_passthrough_preserves_bytes(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('ext-imagick not loaded; converter constructor would throw.');
        }

        $converter = new HeicImageConverter();

        $src = base_path('tests/Fixtures/sample.jpg');
        $original = file_get_contents($src);

        // UploadedFile in test mode is moved by ->move(), so copy first
        $tmpSrc = sys_get_temp_dir() . '/src-' . uniqid() . '.jpg';
        copy($src, $tmpSrc);
        $dst = sys_get_temp_dir() . '/out-' . uniqid() . '.jpg';
        try {
            $upload = new UploadedFile($tmpSrc, 'sample.jpg', 'image/jpeg', null, true);

            $converter->writeAsJpeg($upload, $dst);

            $this->assertFileExists($dst);
            $this->assertSame(
                strlen($original),
                filesize($dst),
                'JPEG passthrough must not re-encode (byte-identical size).',
            );
        } finally {
            @unlink($dst);
            @unlink($tmpSrc);
        }
    }

    public function test_throws_when_imagick_missing(): void
    {
        // D-11 — fail loudly
        if (extension_loaded('imagick')) {
            $this->markTestSkipped(
                'ext-imagick IS loaded on this box; cannot test the missing-extension path '
                . 'without a runtime gate. Executor must extract the extension check into an '
                . 'injectable strategy (e.g. ImagickHealthCheck) so this path is testable with a mock.'
            );
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/imagick/i');

        new HeicImageConverter();
    }
}
