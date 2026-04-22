<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CommissioningSignoff — client signature + metadata captured at programme
 * completion. One row per install_programme (UNIQUE constraint enforced at
 * the DB level — Pitfall 7 race guard in the create migration).
 *
 * Permanent per INST-05i: no SoftDeletes trait, no updates to signature /
 * client / PDF columns once saved (guarded by the mutating HTTP endpoints
 * and CommissioningSyncService).
 *
 * @see \App\Services\CommissioningService        — creation + transaction
 * @see \App\Exceptions\CommissioningSignoffException — downstream guard
 */
class CommissioningSignoff extends Model
{
    use HasFactory;

    protected $fillable = [
        'install_programme_id',
        'client_name',
        'client_role',
        'client_company',
        'signature_png_base64',
        'certification_text',
        'snagging_pdf_path',
        'signed_at',
        'signed_off_engineer_id',
    ];

    protected $casts = [
        'signed_at' => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────────

    public function programme(): BelongsTo
    {
        return $this->belongsTo(InstallProgramme::class, 'install_programme_id');
    }

    public function engineer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_off_engineer_id');
    }

    /**
     * Convenience accessor for Blade templates: full data URI suitable for
     * `<img src="...">`. Centralising this prevents each consumer from
     * concatenating the prefix themselves, and gives us a single place to
     * change storage convention later (e.g. if we move to a file-backed
     * signature per D-11 revision).
     */
    public function getSignatureDataUriAttribute(): string
    {
        return 'data:image/png;base64,' . $this->signature_png_base64;
    }
}
