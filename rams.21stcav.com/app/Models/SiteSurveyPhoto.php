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
        'caption',
        'sort_order',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(SiteSurveyRoom::class, 'site_survey_room_id');
    }

    public function url(): string
    {
        return Storage::disk('local')->url('survey-photos/' . $this->filename);
    }

    public function absolutePath(): string
    {
        return Storage::disk('local')->path('survey-photos/' . $this->filename);
    }
}
