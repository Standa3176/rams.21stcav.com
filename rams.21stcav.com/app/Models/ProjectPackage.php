<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectPackage extends Model
{
    const STATUS_PENDING    = 'pending';
    const STATUS_EXTRACTING = 'extracting';
    const STATUS_EXTRACTED  = 'extracted';
    const STATUS_REVIEWED   = 'reviewed';
    const STATUS_FAILED     = 'failed';

    protected $fillable = [
        'project_id',
        'user_id',
        'quote_filename',
        'quote_path',
        'extracted_data',
        'equipment_list',
        'cable_list',
        'works_description',
        'revision',
        'status',
        'notes',
    ];

    protected $casts = [
        'extracted_data' => 'array',
        'equipment_list' => 'array',
        'cable_list'     => 'array',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    /** Pull project name from extracted data, fallback to parent project. */
    public function getProjectNameAttribute(): string
    {
        return $this->extracted_data['project_name']
            ?? $this->project->name
            ?? 'Unknown';
    }

    /** Pull client name from extracted data, fallback to parent project. */
    public function getClientNameAttribute(): string
    {
        return $this->extracted_data['client_name']
            ?? $this->project->client_name
            ?? '';
    }
}
