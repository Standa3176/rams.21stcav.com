<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentChangeSet extends Model
{
    protected $fillable = [
        'thread_id',
        'document_type',
        'document_id',
        'base_revision_id',
        'status',
        'operations_json',
        'validation_errors',
        'model_name',
    ];

    protected $casts = [
        'operations_json'   => 'array',
        'validation_errors' => 'array',
    ];

    public const STATUS_PROPOSED  = 'proposed';
    public const STATUS_VALIDATED = 'validated';
    public const STATUS_APPLIED   = 'applied';
    public const STATUS_REJECTED  = 'rejected';

    public function thread(): BelongsTo
    {
        return $this->belongsTo(DocumentEditThread::class, 'thread_id');
    }

    public function baseRevision(): BelongsTo
    {
        return $this->belongsTo(DocumentRevision::class, 'base_revision_id');
    }
}
