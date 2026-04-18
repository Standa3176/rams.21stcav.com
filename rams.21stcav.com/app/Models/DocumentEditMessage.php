<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentEditMessage extends Model
{
    protected $fillable = [
        'thread_id',
        'role',
        'content',
        'operations_json',
    ];

    protected $casts = [
        'operations_json' => 'array',
    ];

    public const ROLE_USER      = 'user';
    public const ROLE_ASSISTANT = 'assistant';
    public const ROLE_SYSTEM    = 'system';

    public function thread(): BelongsTo
    {
        return $this->belongsTo(DocumentEditThread::class, 'thread_id');
    }
}
