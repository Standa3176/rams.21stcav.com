<?php

namespace App\Support\Filesystem;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

class WindowsSafeFilesystem extends Filesystem
{
    /**
     * Replace file contents atomically when possible.
     *
     * On Windows, rename() may fail with "Access is denied" when the target
     * file is temporarily locked. In that case, we retry and then degrade
     * safely: keep the existing compiled file instead of breaking requests.
     */
    public function replace($path, $content, $mode = null): void
    {
        clearstatcache(true, $path);

        $path = realpath($path) ?: $path;

        $directory = dirname($path);
        if (! is_dir($directory)) {
            $this->makeDirectory($directory, 0755, true, true);
        }

        $tempPath = tempnam($directory, basename($path));

        if ($tempPath === false) {
            throw new RuntimeException('Unable to create temporary file for replacement: '.$path);
        }

        if (! is_null($mode)) {
            @chmod($tempPath, $mode);
        } else {
            @chmod($tempPath, 0777 - umask());
        }

        file_put_contents($tempPath, $content);

        // Fast path.
        if (@rename($tempPath, $path)) {
            return;
        }

        // Retry rename for transient locks.
        for ($attempt = 1; $attempt <= 8; $attempt++) {
            usleep(20000 * $attempt);

            if (@rename($tempPath, $path)) {
                return;
            }
        }

        // Non-atomic fallback (may still fail if locked).
        if (@copy($tempPath, $path)) {
            @unlink($tempPath);
            return;
        }

        // Last-resort safety: if target already exists, keep it to avoid 500s.
        if (is_file($path)) {
            @unlink($tempPath);
            return;
        }

        $error = error_get_last()['message'] ?? 'Unknown filesystem replace error';
        @unlink($tempPath);

        throw new RuntimeException('Failed replacing file ['.$path.']: '.$error);
    }
}
