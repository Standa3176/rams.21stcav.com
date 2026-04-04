<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents one uploaded quote PDF version linked to a Project.
 *
 * A project may accumulate multiple ProjectQuote records over time
 * (version_number increments per project). The parsed_snapshot column
 * preserves the QuoteParserService output at upload time for auditability.
 */
class ProjectQuote extends Model
{
    protected $fillable = [
        'project_id',
        'uploaded_by',
        'original_filename',
        'stored_filename',
        'quote_reference',
        'quote_date',
        'client_name',
        'site_name',
        'site_address',
        'parsed_snapshot',
        'version_number',
    ];

    protected $casts = [
        'parsed_snapshot' => 'array',
        'quote_date'      => 'date',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
