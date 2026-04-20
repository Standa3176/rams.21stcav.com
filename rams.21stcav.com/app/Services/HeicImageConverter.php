<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageManagerInterface;
use RuntimeException;

/**
 * HeicImageConverter — converts HEIC / HEIF uploads to JPEG using Imagick.
 *
 * Design intent (PROJECT.md data-integrity rule + CONTEXT.md D-11):
 *   - **No silent fallback.** If php-imagick is missing, the constructor throws
 *     a RuntimeException with a clear remediation message. The upload request
 *     returns 500; Log::error in the global handler surfaces it.
 *   - JPEG / PNG / WebP pass through untouched (UploadedFile::move()), not
 *     re-encoded, to avoid quality loss and EXIF stripping for photos that
 *     don't need conversion.
 *   - iOS Safari sometimes reports `application/octet-stream` for HEIC; we
 *     detect MIME via finfo first, file extension second, client MIME third.
 *
 * @see TaskPhotoService — the primary consumer
 * @see Phase 16 (INST-05d) — will reuse this service for commissioning evidence
 */
class HeicImageConverter
{
    private const HEIC_MIMES = [
        'image/heic',
        'image/heif',
        'image/heic-sequence',
        'image/heif-sequence',
    ];

    private const JPEG_QUALITY = 85;

    private ?ImageManagerInterface $manager = null;

    /**
     * Constructor is intentionally empty. The php-imagick health check is
     * deferred to the HEIC conversion path only (see requireImagick()). This
     * keeps JPEG/PNG/WebP passthrough working on environments without the
     * imagick extension while still honouring D-11 "fail loudly when HEIC
     * cannot be decoded" for the HEIC path.
     */
    public function __construct() {}

    /**
     * Write the uploaded file to $destinationAbsPath as JPEG (if HEIC) or
     * as-is passthrough (if already JPEG/PNG/WebP).
     *
     * @throws RuntimeException if php-imagick is missing and input is HEIC (D-11)
     * @throws RuntimeException if HEIC decode fails (ImageMagick lacks libheif delegate)
     */
    public function writeAsJpeg(UploadedFile $file, string $destinationAbsPath): void
    {
        $destDir = dirname($destinationAbsPath);
        if (! is_dir($destDir) && ! @mkdir($destDir, 0755, true) && ! is_dir($destDir)) {
            throw new RuntimeException("HeicImageConverter: cannot create directory {$destDir}");
        }

        $mime = $this->detectMime($file);

        if ($this->isHeic($mime)) {
            $manager = $this->requireImagick();

            try {
                $manager
                    ->read($file->getRealPath())
                    ->toJpeg(quality: self::JPEG_QUALITY)
                    ->save($destinationAbsPath);
            } catch (\Throwable $e) {
                throw new RuntimeException(
                    'HeicImageConverter: HEIC conversion failed. This usually means ImageMagick '
                    .'was not compiled with the libheif delegate. '
                    .'Verify with: `php -r "print_r((new Imagick())->queryFormats(\'HEI*\'));"`. '
                    .'Original error: '.$e->getMessage(),
                    previous: $e,
                );
            }

            return;
        }

        // JPEG / PNG / WebP passthrough — copy bytes rather than move() so that
        // test fixtures (which point the UploadedFile at the committed sample
        // file) are not consumed by the first test run. In production this is
        // equivalent: PHP clears the tmp upload file at request end even if we
        // don't move_uploaded_file() it ourselves.
        if (! copy($file->getRealPath(), $destinationAbsPath)) {
            throw new RuntimeException(
                'HeicImageConverter: failed to copy uploaded file to '.$destinationAbsPath
            );
        }
    }

    /**
     * Lazy health check for the HEIC path (D-11 fail-loud).
     *
     * Called only when the input is actually HEIC/HEIF. JPEG/PNG/WebP
     * passthrough never touches this — so boxes without imagick can still
     * handle non-HEIC uploads without blowing up.
     */
    private function requireImagick(): ImageManagerInterface
    {
        if ($this->manager !== null) {
            return $this->manager;
        }

        if (! extension_loaded('imagick') || ! class_exists(\Imagick::class)) {
            throw new RuntimeException(
                'HeicImageConverter: the php-imagick PHP extension is required for HEIC conversion. '
                .'Install it: `sudo apt install php8.2-imagick libheif-dev` (Linux) or '
                .'enable `extension=imagick` in php.ini (Windows). '
                .'See .planning/phases/14-mobile-field-view/14-RESEARCH.md → Deployment note.'
            );
        }

        return $this->manager = ImageManager::imagick();
    }

    private function isHeic(string $mime): bool
    {
        return in_array(strtolower($mime), self::HEIC_MIMES, true);
    }

    /**
     * iOS Safari sometimes sends `application/octet-stream` for HEIC.
     * Check three sources and trust the most specific.
     */
    private function detectMime(UploadedFile $file): string
    {
        // 1. finfo (content-based, most reliable)
        $finfoMime = @mime_content_type($file->getRealPath());
        if ($finfoMime && $finfoMime !== 'application/octet-stream') {
            return $finfoMime;
        }

        // 2. file extension
        $ext = strtolower($file->getClientOriginalExtension());
        if (in_array($ext, ['heic', 'heif'], true)) {
            return 'image/heic';
        }

        // 3. client-provided MIME (least trustworthy)
        return $file->getMimeType() ?? 'application/octet-stream';
    }
}
