<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 4 — configuration backup record for a single device.
 *
 * Filename + storage location + free-text notes — no file storage here,
 * just a pointer so a future engineer knows the backup exists and where
 * to find it.
 */
class ConfigBackup extends Model
{
    protected $fillable = [
        'device_id',
        'filename',
        'storage_location',
        'notes',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
