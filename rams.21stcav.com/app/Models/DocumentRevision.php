<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentRevision extends Model
{
    protected $fillable = [
        'document_type',
        'document_id',
        'parent_revision_id',
        'payload_snapshot',
        'artifact_filename',
        'change_summary',
        'source',
        'created_by',
    ];

    protected $casts = [
        'payload_snapshot' => 'array',
    ];

    public const SOURCE_BASE    = 'base';
    public const SOURCE_AI_CHAT = 'ai_chat';
    public const SOURCE_MANUAL  = 'manual';

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_revision_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
