<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class SiteSurveyPhoto extends Model
{
    protected $fillable = [
        'site_survey_room_id',
        'filename',
        'original_name',
        'mime_type',
        'category',
        'caption',
        'sort_order',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(SiteSurveyRoom::class, 'site_survey_room_id');
    }

    /**
     * Returns the full relative path used to address this file on the local disk.
     *
     * Two filename formats are supported for backwards compatibility:
     *
     *   Legacy  — filename stores only the basename:  "uuid.jpg"
     *             → resolved as "survey-photos/uuid.jpg"
     *
     *   Current — filename stores the full relative path:
     *             "projects/51/surveys/23/uuid.jpg"
     *             → used as-is
     */
    public function storagePath(): string
    {
        // If the filename contains a path separator it already is a full path.
        if (str_contains($this->filename, '/')) {
            return $this->filename;
        }

        // Legacy: bare filename → prepend the original flat directory.
        return 'survey-photos/' . $this->filename;
    }

    /**
     * Absolute filesystem path (used by DOCX/PDF builders that embed images).
     */
    public function absolutePath(): string
    {
        return Storage::disk('local')->path($this->storagePath());
    }

    /**
     * Storage URL — typically not used directly; photos are served through
     * the authenticated SiteSurveyController::servePhoto() route or the
     * public PublicSurveyController::servePhoto() route.
     */
    public function url(): string
    {
        return Storage::disk('local')->url($this->storagePath());
    }
}
