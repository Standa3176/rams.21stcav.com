<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 4 — Tier 1 asset register row.
 *
 * One installed unit per row. Lives alongside the JSON equipment_list on
 * project_packages — packages stay the source for "what was quoted",
 * devices is the source for "what was actually fitted, with serials,
 * IPs, and warranties to track over the asset's life".
 */
class Device extends Model
{
    protected $fillable = [
        'project_id',
        'room_name',
        'description',
        'model',
        'manufacturer',
        'part_no',
        'qty',
        'serial_number',
        'mac_address',
        'ip_address',
        'vlan',
        'port',
        'firmware_version',
        'asset_tag',
        'commissioning_date',
        'warranty_expiry',
    ];

    protected $casts = [
        'qty'                => 'integer',
        'commissioning_date' => 'date',
        'warranty_expiry'    => 'date',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function configBackups(): HasMany
    {
        return $this->hasMany(ConfigBackup::class);
    }
}
