<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Photo attached to an InstallTask (Phase 14 INST-03d).
 *
 * Storage model:
 *   - `filename` = relative path under the `local` disk root, e.g.
 *     "task-photos/{project_id}/{task_id}/{uuid}.jpg"
 *   - Served via TaskPhotoController::show() (private, ownership-guarded)
 *
 * HEIC uploads are converted to JPEG before the row is persisted, so
 * `mime_type` on disk is always one of: image/jpeg, image/png, image/webp.
 *
 * @see SiteSurveyPhoto — sibling pattern this model mirrors (D-09)
 * @see InstallTask::photos() — parent relationship
 */
class InstallTaskPhoto extends Model
{
    use HasFactory;

    protected $fillable = [
        'install_task_id',
        'filename',
        'original_name',
        'mime_type',
        'caption',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(InstallTask::class, 'install_task_id');
    }

    /**
     * Relative path on the `local` disk. `filename` is already the full relative
     * path (per storage convention — see TaskPhotoService::store()); this helper
     * exists for symmetry with SiteSurveyPhoto::storagePath().
     */
    public function storagePath(): string
    {
        return $this->filename;
    }

    /**
     * Absolute filesystem path (use for `response()->file(...)` in the serve action).
     */
    public function absolutePath(): string
    {
        return Storage::disk('local')->path($this->storagePath());
    }
}
