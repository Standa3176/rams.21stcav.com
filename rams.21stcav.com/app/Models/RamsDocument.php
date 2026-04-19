<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;

class RamsDocument extends Model
{
    use HasFactory, SoftDeletes;

    // ── Pipeline / upload statuses ────────────────────────────────────────────
    const STATUS_UPLOADED                = 'uploaded';
    const STATUS_AWAITING_REVIEW         = 'awaiting_review';
    const STATUS_APPROVED                = 'approved';
    const STATUS_APPROVED_FOR_GENERATION = 'approved_for_generation'; // legacy alias
    const STATUS_GENERATING              = 'generating';
    const STATUS_COMPLETED               = 'completed';
    const STATUS_FAILED                  = 'failed';

    // ── Legacy / workflow statuses (kept for backwards compatibility) ─────────
    const STATUS_DRAFT      = 'draft';
    const STATUS_FOR_REVIEW = 'for_review';
    const STATUS_SUPERSEDED = 'superseded';
    const STATUS_PENDING    = 'pending';
    const STATUS_COMPLETE   = 'complete';
    const STATUS_RENDERING  = 'rendering';

    protected $fillable = [
        'user_id',
        'project_id',
        'project_ref',
        'project_name',
        'client_name',
        'site_address',
        'ai_provider',
        'ai_model',
        'form_data',
        'extracted_data',
        'reviewed_data',
        'generated_data',
        'filename',
        'status',
        'error_message',
        'email_sent_at',
        'completion_email_sent_at',
        'failed_email_sent_at',
        'review_needed_email_sent_at',
        'approved_at',
        'approved_by',
        'superseded_by_id',
    ];

    protected $casts = [
        'form_data'                   => 'array',
        'extracted_data'              => 'array',
        'reviewed_data'               => 'array',
        'generated_data'              => 'array',
        'email_sent_at'               => 'datetime',
        'completion_email_sent_at'    => 'datetime',
        'failed_email_sent_at'        => 'datetime',
        'review_needed_email_sent_at' => 'datetime',
        'approved_at'                 => 'datetime',
        'deleted_at'                  => 'datetime',
    ];

    // ── Model events ──────────────────────────────────────────────────────────

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model): void {
            if (empty($model->project_id)) {
                Log::warning('RamsDocument: creating record without project_id — ' .
                    'upload path must always supply project_id.', [
                    'project_ref'  => $model->project_ref,
                    'project_name' => $model->project_name,
                    'user_id'      => $model->user_id,
                ]);
            }
        });
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function omManual(): HasOne
    {
        return $this->hasOne(OmManual::class);
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(RamsDocument::class, 'superseded_by_id');
    }

    // ── Status helpers ────────────────────────────────────────────────────────

    public function isSuperseded(): bool
    {
        return ! is_null($this->superseded_by_id);
    }

    /**
     * Whether this document can be approved for generation.
     * Requires reviewed_data and must not already be generating or completed.
     */
    public function canBeApproved(): bool
    {
        if (empty($this->reviewed_data)) {
            return false;
        }

        return ! in_array($this->status, [
            self::STATUS_APPROVED,
            self::STATUS_APPROVED_FOR_GENERATION,
            self::STATUS_GENERATING,
            self::STATUS_COMPLETED,
        ], true);
    }

    /**
     * Whether this document can proceed to DOCX generation.
     * Requires approved_at timestamp and reviewed_data.
     */
    public function canGenerate(): bool
    {
        return ! is_null($this->approved_at) && ! empty($this->reviewed_data);
    }

    public function isPipelineStatus(): bool
    {
        return in_array($this->status, [
            self::STATUS_UPLOADED,
            self::STATUS_AWAITING_REVIEW,
            self::STATUS_APPROVED,
            self::STATUS_APPROVED_FOR_GENERATION,
            self::STATUS_GENERATING,
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
        ], true);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_UPLOADED                => 'Uploaded',
            self::STATUS_AWAITING_REVIEW         => 'Awaiting Review',
            self::STATUS_APPROVED                => 'Approved',
            self::STATUS_APPROVED_FOR_GENERATION => 'Approved — Generating',
            self::STATUS_GENERATING              => 'Generating',
            self::STATUS_COMPLETED               => 'Completed',
            self::STATUS_FAILED                  => 'Failed',
            self::STATUS_DRAFT                   => 'Draft',
            self::STATUS_FOR_REVIEW              => 'For Review',
            self::STATUS_SUPERSEDED              => 'Superseded',
            self::STATUS_COMPLETE                => 'Complete',
            default                              => ucfirst(str_replace('_', ' ', $this->status)),
        };
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_AWAITING_REVIEW         => 'badge-warning',
            self::STATUS_APPROVED                => 'badge-blue',
            self::STATUS_APPROVED_FOR_GENERATION,
            self::STATUS_GENERATING              => 'badge-teal',
            self::STATUS_COMPLETED               => 'badge-green',
            self::STATUS_FAILED                  => 'badge-red',
            self::STATUS_UPLOADED                => 'badge-grey',
            default                              => 'badge-grey',
        };
    }
}
