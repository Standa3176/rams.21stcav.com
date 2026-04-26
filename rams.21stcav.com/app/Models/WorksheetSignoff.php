<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WorksheetSignoff — one row per client acceptance event captured via the
 * public worksheet sign-off page.
 *
 * Append-only: the table has no unique constraint on worksheet_id and no
 * softDeletes, so resignoffs (e.g. after remedials) preserve the prior row.
 * Use `Worksheet::latestSignoff()` to fetch the most recent acceptance.
 *
 * Storage convention mirrors CommissioningSignoff:
 *  - `signature_png_base64` stores RAW base64 (no `data:` prefix)
 *  - `signature_data_uri` accessor concatenates the prefix for `<img src>`
 *
 * @property string  $client_name
 * @property string  $signature_png_base64
 * @property bool    $signed_with_comments
 * @property ?string $comments
 * @property \Carbon\Carbon $signed_at
 */
class WorksheetSignoff extends Model
{
    use HasFactory;

    protected $fillable = [
        'worksheet_id',
        'client_name',
        'signature_png_base64',
        'signed_with_comments',
        'comments',
        'signed_at',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'signed_at'            => 'datetime',
        'signed_with_comments' => 'boolean',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function worksheet(): BelongsTo
    {
        return $this->belongsTo(Worksheet::class);
    }

    // ── Accessors ────────────────────────────────────────────────────────────

    /**
     * Convenience accessor for Blade templates: full data URI for `<img src>`.
     * Centralising the prefix here means consumers never concatenate it
     * themselves and storage convention can change in one place later.
     */
    public function getSignatureDataUriAttribute(): string
    {
        return 'data:image/png;base64,' . $this->signature_png_base64;
    }
}
