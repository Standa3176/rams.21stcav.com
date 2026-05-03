<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single engineer-captured photo of an equipment label, linked to a device
 * row in the asset register and the worksheet visit on which it was taken.
 *
 * Distinct from room install photos (handled by Worksheet::photos) — these
 * are the source of truth for the device's serial / MAC / firmware fields.
 */
class DeviceLabelPhoto extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'device_id',
        'worksheet_id',
        'room_name',
        'photo_path',
        'ai_extracted',
        'confirmed',
        'captured_at',
        'captured_by',
    ];

    protected $casts = [
        'ai_extracted' => 'array',
        'confirmed'    => 'boolean',
        'captured_at'  => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function worksheet(): BelongsTo
    {
        return $this->belongsTo(Worksheet::class);
    }
}
