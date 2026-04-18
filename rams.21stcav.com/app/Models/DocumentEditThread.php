<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentEditThread extends Model
{
    protected $fillable = [
        'document_type',
        'document_id',
        'base_revision_id',
        'status',
        'created_by',
    ];

    public const STATUS_OPEN      = 'open';
    public const STATUS_APPLIED   = 'applied';
    public const STATUS_ABANDONED = 'abandoned';

    public function baseRevision(): BelongsTo
    {
        return $this->belongsTo(DocumentRevision::class, 'base_revision_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(DocumentEditMessage::class, 'thread_id');
    }

    public function changeSets(): HasMany
    {
        return $this->hasMany(DocumentChangeSet::class, 'thread_id');
    }
}
