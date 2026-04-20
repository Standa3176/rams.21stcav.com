<?php

namespace App\Services;

use App\Models\InstallTask;
use App\Models\InstallTaskPhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * TaskPhotoService — orchestrates per-task photo upload, storage, and lifecycle.
 *
 * Responsibilities:
 *   - UUID-based filename generation (never uses client-supplied filenames)
 *   - Path isolation per task: task-photos/{project_id}/{task_id}/{uuid}.jpg
 *   - Delegates HEIC→JPEG conversion to HeicImageConverter (D-11 fail-loud)
 *   - Inserts install_task_photos row; always sets mime_type to the
 *     POST-conversion value (never trusts client MIME)
 *   - Delete + caption update paths
 *
 * Security notes (threat ref: T-14-05, T-14-06, T-14-07):
 *   - `original_name` stored only for display; never used as a filesystem path
 *   - The ownership guard is enforced by the calling controller (Plan 04), NOT
 *     here — this service trusts the $task argument is already authorised.
 *
 * @see HeicImageConverter — single dependency for format conversion
 * @see InstallTaskPhoto — the Eloquent model written to
 */
class TaskPhotoService
{
    private const BASE_DIR = 'task-photos';

    public function __construct(
        private readonly HeicImageConverter $converter,
    ) {}

    /**
     * Store an uploaded photo against the given task.
     *
     * @param  InstallTask  $task  must already be authorised by the caller
     * @param  UploadedFile  $file  validated by the controller's FormRequest
     * @return InstallTaskPhoto freshly persisted model
     */
    public function store(InstallTask $task, UploadedFile $file): InstallTaskPhoto
    {
        $task->loadMissing('programme');
        $projectId = (int) $task->programme->project_id;
        $taskId = (int) $task->id;

        // Generate UUID filename; always write as .jpg extension. The converter
        // decides whether to transcode (HEIC) or passthrough (already JPEG/PNG/WebP).
        // For passthrough PNG/WebP, the destination extension is still .jpg which
        // is a deliberate convention — mime_type records the actual format.
        $uuid = (string) Str::uuid();
        $relativePath = sprintf(
            '%s/%d/%d/%s.jpg',
            self::BASE_DIR,
            $projectId,
            $taskId,
            $uuid,
        );
        $absolutePath = Storage::disk('local')->path($relativePath);

        // Delegate conversion / passthrough. HeicImageConverter mkdirs intermediate
        // directories and throws RuntimeException on any failure (D-11).
        $this->converter->writeAsJpeg($file, $absolutePath);

        // Post-conversion: detect the actual MIME on disk so we NEVER trust the
        // client-supplied $file->getMimeType().
        $finalMime = @mime_content_type($absolutePath) ?: 'image/jpeg';

        // Sanitise original_name: strip any directory separators / traversal chars
        // before persisting it as a display label. Cap at 200 chars.
        $originalName = $this->sanitiseOriginalName($file->getClientOriginalName());

        $photo = InstallTaskPhoto::create([
            'install_task_id' => $task->id,
            'filename'        => $relativePath,
            'original_name'   => $originalName,
            'mime_type'       => $finalMime,
            'caption'         => null,
            'sort_order'      => ($task->photos()->max('sort_order') ?? 0) + 1,
        ]);

        Log::info('TaskPhotoService: photo uploaded', [
            'task_id'    => $task->id,
            'photo_id'   => $photo->id,
            'project_id' => $projectId,
            'mime_type'  => $finalMime,
            'bytes'      => @filesize($absolutePath) ?: 0,
        ]);

        return $photo;
    }

    /**
     * Delete a photo (DB row + filesystem file). Idempotent.
     */
    public function delete(InstallTaskPhoto $photo): void
    {
        $path = $photo->storagePath();
        $photoId = $photo->id;
        $photo->delete();

        try {
            Storage::disk('local')->delete($path);
        } catch (\Throwable $e) {
            Log::warning('TaskPhotoService: file delete failed', [
                'photo_id' => $photoId,
                'path'     => $path,
                'error'    => $e->getMessage(),
            ]);
        }

        Log::info('TaskPhotoService: photo deleted', ['photo_id' => $photoId]);
    }

    /**
     * Update the optional caption (D-12 — blur-saved).
     */
    public function updateCaption(InstallTaskPhoto $photo, ?string $caption): InstallTaskPhoto
    {
        $photo->update([
            'caption' => $caption !== null ? mb_substr($caption, 0, 200) : null,
        ]);

        return $photo->fresh();
    }

    /**
     * Strip directory separators and traversal tokens; trim to 200 chars.
     * Result is used only for display, never as a filesystem path — this
     * is a defence-in-depth measure against log injection + hostile captions.
     */
    private function sanitiseOriginalName(string $name): string
    {
        // Strip everything up to and including the last / or \ (basename)
        $name = preg_replace('#^.*[\\\\/]#', '', $name) ?? $name;
        // Replace any remaining control / path characters
        $name = preg_replace('#[\x00-\x1F\x7F\\\\/]+#', '_', $name) ?? $name;

        return mb_substr(trim($name), 0, 200) ?: 'photo.jpg';
    }
}
