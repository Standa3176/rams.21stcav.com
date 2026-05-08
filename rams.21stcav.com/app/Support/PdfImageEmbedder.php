<?php

namespace App\Support;

/**
 * Embeds an image file as a base64 data URI for inclusion in HTML rendered
 * by Browsershot (Mini O&M, Survey Client Report). Browsershot 5.x rejects
 * `file://` URIs (HtmlIsNotAllowedToContainFile guard), so PDFs that need
 * to include local files MUST inline them as data URIs.
 *
 * Why a helper instead of inline base64_encode(file_get_contents()):
 * raw phone photos are typically 4000–6000px wide and 4–12 MB. Inlining
 * them straight produces 500MB+ HTML payloads, which then time-out
 * Chromium's printToPDF AND blow the PHP memory limit. This helper
 * resizes to a sensible max dimension first via GD, falling through to
 * the raw bytes when GD can't process the file (HEIC, exotic formats,
 * GD extension missing, etc.) — so it's never strictly worse than the
 * naive approach.
 *
 * Output is always a `data:image/jpeg;base64,…` URI (or the original
 * MIME type if the file wasn't resized). Callers should drop the
 * returned string straight into an `<img src>`.
 */
final class PdfImageEmbedder
{
    /**
     * Convert an absolute filesystem path to a base64 data URI suitable
     * for `<img src>` inside Browsershot-rendered HTML.
     *
     * Returns an empty string when the file is missing or unreadable —
     * Chromium tolerates an empty src attribute, so callers don't need
     * to guard the result.
     *
     * @param  string|null  $absPath   Absolute filesystem path (or null/empty for graceful skip)
     * @param  int          $maxWidth  Resize so the longest edge is ≤ this many px (default 1600 — A4-print-quality)
     * @param  int          $quality   JPEG quality 0–100 (default 80 — visually-indistinguishable, ~5× smaller than 100)
     */
    public static function dataUri(?string $absPath, int $maxWidth = 1600, int $quality = 80): string
    {
        if (! $absPath || ! is_file($absPath)) {
            return '';
        }

        $info = @getimagesize($absPath);
        if (! $info) {
            // Unreadable / not an image — fall back to raw bytes. Better
            // than empty src in case the caller is sure it's an image but
            // GD's getimagesize disagrees (e.g. HEIC on a host without
            // the heic-image plugin).
            return self::rawDataUri($absPath, 'image/jpeg');
        }

        [$width, $height, $type] = $info;
        $mime = image_type_to_mime_type($type);

        $resizable = in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true);
        $largerThanMax = max($width, $height) > $maxWidth;

        // No resize needed (already small) OR can't resize this format —
        // emit the original bytes with the original MIME.
        if (! $resizable || ! $largerThanMax) {
            return self::rawDataUri($absPath, $mime);
        }

        // Proportional dimensions targeting longest-edge <= maxWidth.
        if ($width >= $height) {
            $newWidth  = $maxWidth;
            $newHeight = (int) round($height * ($maxWidth / $width));
        } else {
            $newHeight = $maxWidth;
            $newWidth  = (int) round($width * ($maxWidth / $height));
        }

        $src = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($absPath),
            IMAGETYPE_PNG  => @imagecreatefrompng($absPath),
            IMAGETYPE_WEBP => @imagecreatefromwebp($absPath),
            default        => null,
        };
        if (! $src) {
            return self::rawDataUri($absPath, $mime);
        }

        $dst = imagecreatetruecolor($newWidth, $newHeight);
        // Preserve transparency for PNG/WebP (if alpha-savable) before resample.
        if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        // Always re-encode to JPEG — it's smaller than PNG for photographs
        // and Chromium handles them identically. Loses transparency but
        // engineer photos are JPEG anyway.
        imagejpeg($dst, null, $quality);
        $bytes = ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        if (! is_string($bytes) || $bytes === '') {
            return self::rawDataUri($absPath, $mime);
        }

        return 'data:image/jpeg;base64,' . base64_encode($bytes);
    }

    /** Emit the file's raw bytes as a data URI without any resize. */
    private static function rawDataUri(string $absPath, string $mime): string
    {
        $bytes = @file_get_contents($absPath);
        if (! is_string($bytes) || $bytes === '') {
            return '';
        }
        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }
}
