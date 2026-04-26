<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class WorksheetPhoto extends Model
{
    protected $fillable = [
        'worksheet_id',
        'room_name',
        'filename',
        'original_name',
        'mime_type',
        'caption',
        'sort_order',
    ];

    public function worksheet(): BelongsTo
    {
        return $this->belongsTo(Worksheet::class);
    }

    /** Full relative path on the local disk. */
    public function storagePath(): string
    {
        return $this->filename;
    }

    public function absolutePath(): string
    {
        return Storage::disk('local')->path($this->storagePath());
    }
}
