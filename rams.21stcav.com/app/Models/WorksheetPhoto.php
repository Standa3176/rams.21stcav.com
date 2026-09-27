<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class WorksheetPhoto extends Model
{
    // ── Buckets — THREE, in capture order (46.4 D-03, then 46.5 D-06) ────────
    //
    // "Worksheet will need rooms start and completion pics" — the user,
    // verbatim, 2026-09-26. Then: "room images start , during/ end etc" — the
    // same user, 2026-09-27.
    //
    // ⚠️ THIS COMMENT PREVIOUSLY SAID "There are TWO buckets and there will not
    // be a third." That sentence is gone because it became false, and the real
    // history is below — a comment a reader trusts over the code is how a good
    // decision gets undone.
    //
    //  1. 46.4 D-03 shipped TWO buckets and ruled a third OUT. The third it
    //     ruled out was an `unknown` bucket for pre-46.4 rows: it would have to
    //     be rendered somewhere forever and the office would have to learn what
    //     it means.
    //
    //  2. 46.5 D-06 adds a third for a DIFFERENT reason. `during` is a stage an
    //     engineer deliberately CAPTURES, with its own tray, its own label and
    //     its own capture control — not a label for rows nobody ever
    //     classified. THE 46.4 REJECTION THEREFORE STILL STANDS: there is no
    //     `unknown` bucket, and there will not be one.
    //
    //  3. `completion` IS NOT RENAMED — not to `end`, not to anything.
    //     2026_09_26_100000_add_bucket_to_worksheet_photos_table.php backfilled
    //     every pre-46.4 row to it by explicit UPDATE, and justified that
    //     ruling by quoting the tray's own title, "📷 Photos of completed
    //     work". Renaming it would falsify a written decision and cost a data
    //     migration for zero gain. The tray titles already read as start /
    //     during / end in plain English, so no label changed either.
    //
    // ORDER IS A DECISION: the array is in CAPTURE order, so anything reading a
    // bucket's position off it reads the order the work actually happens in.
    //
    // Stored as a plain string(20) with a default — no enum, no check
    // constraint — so a third VALUE needs no schema change and 46.5-03 wrote no
    // migration. No cast either: a cast would imply a richer type than a
    // three-value vocabulary needs.

    public const BUCKET_START = 'start';

    public const BUCKET_DURING = 'during';

    public const BUCKET_COMPLETION = 'completion';

    public const BUCKETS = [
        self::BUCKET_START,
        self::BUCKET_DURING,
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
