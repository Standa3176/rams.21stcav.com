<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class OmManual extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'om_manuals';

    // Workflow status constants (string values stored in DB)
    public const STATUS_EXTRACTED  = 'extracted';   // Pass 1 done — awaiting user review
    public const STATUS_GENERATING = 'generating';  // BuildOmManualJob running
    public const STATUS_DRAFT      = 'draft';       // Pass 2 done — .docx built
    public const STATUS_FINAL      = 'final';       // Approved final version
    public const STATUS_FAILED     = 'failed';      // Job failed

    protected $fillable = [
        'user_id',
        'project_id',
        'rams_document_id',
        'project_name',
        'project_ref',
        'client_name',
        'site_address',
        'source_filename',
        'source_path',
        'status',
        'error_message',
        'extracted_data',
        'generated_data',
        'filename',
    ];

    protected function casts(): array
    {
        return [
            'extracted_data' => 'array',
            'generated_data' => 'array',
        ];
    }

    // ── Relationships ───────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function ramsDocument(): BelongsTo
    {
        return $this->belongsTo(RamsDocument::class);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** Returns true once the .docx has been built (Pass 2 complete). */
    public function isGenerated(): bool
    {
        return $this->filename !== null && $this->generated_data !== null;
    }

    /** Human-readable status label. */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_EXTRACTED  => 'Awaiting Review',
            self::STATUS_GENERATING => 'Generating…',
            self::STATUS_DRAFT      => 'Draft',
            self::STATUS_FINAL      => 'Final',
            self::STATUS_FAILED     => 'Failed',
            default                 => ucfirst(str_replace('_', ' ', $this->status)),
        };
    }

    /** CSS badge class matching the RAMS document convention. */
    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_EXTRACTED  => 'badge-yellow',
            self::STATUS_GENERATING => 'badge-blue',
            self::STATUS_DRAFT      => 'badge-teal',
            self::STATUS_FINAL      => 'badge-green',
            self::STATUS_FAILED     => 'badge-red',
            default                 => 'badge-grey',
        };
    }
}
