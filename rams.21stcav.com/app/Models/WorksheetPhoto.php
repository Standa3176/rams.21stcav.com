<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class WorksheetPhoto extends Model
{
    // ── Buckets (Phase 46.4, 46.4-CONTEXT.md D-03) ───────────────────────────
    //
    // "Worksheet will need rooms start and completion pics" — the user,
    // verbatim, 2026-09-26.
    //
    // There are TWO buckets and there will not be a third. An `unknown` bucket
    // for pre-46.4 rows was considered and rejected: it would have to be
    // rendered somewhere forever and the office would have to learn what it
    // means. Legacy rows are stamped `completion` by explicit backfill,
    // because the tray they were captured through is titled, verbatim,
    // "📷 Photos of completed work".
    //
    // Stored as a plain string. No cast — a cast would imply a richer type
    // than a two-value vocabulary needs.

    public const BUCKET_START = 'start';

    public const BUCKET_COMPLETION = 'completion';

    public const BUCKETS = [
        self::BUCKET_START,
        self::BUCKET_COMPLETION,
    ];

    protected $fillable = [
        'worksheet_id',
        'room_name',
        'bucket',
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
