<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SiteSurvey extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'user_id',
        'project_id',
        'project_name',
        'project_ref',
        'client_name',
        'site_address',
        'survey_date',
        'surveyor_name',
        'general_notes',
        'status',
        'filename',
    ];

    protected $casts = [
        'survey_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(SiteSurveyRoom::class)->orderBy('sort_order');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
