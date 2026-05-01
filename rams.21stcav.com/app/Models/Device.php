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
    // ── Phase 17 signal-flow classification (CRIT-05) ─────────────────────────
    // Drives schematic arrow direction. The generator MUST consult these
    // classifiers — never infer direction from cable-row order. An unclassified
    // device renders cables as undirected lines + surfaces an engineer warning
    // (see hasUnknownSignalRole() below).
    public const ROLE_SOURCE = 'source';

    public const ROLE_DESTINATION = 'destination';

    public const ROLE_PROCESSOR = 'processor';

    protected $fillable = [
        'project_id',
        'room_name',
        'description',
        'model',
        'manufacturer',
        'part_no',
        'signal_role',
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
        'qty' => 'integer',
        'commissioning_date' => 'date',
        'warranty_expiry' => 'date',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function configBackups(): HasMany
    {
        return $this->hasMany(ConfigBackup::class);
    }

    public function labelPhotos(): HasMany
    {
        return $this->hasMany(DeviceLabelPhoto::class);
    }

    // ── Phase 17 signal-flow classifiers (CRIT-05) ────────────────────────────
    // The schematic generator (Plan 17-02) calls these per device when laying
    // out arrows. Never infer direction from cable-row order — that's how
    // mics ended up "feeding from" speakers in the proof-of-concept (see
    // PITFALLS.md CRIT-05 for the full incident analysis).

    public function isSource(): bool
    {
        return $this->signal_role === self::ROLE_SOURCE;
    }

    public function isDestination(): bool
    {
        return $this->signal_role === self::ROLE_DESTINATION;
    }

    public function isProcessor(): bool
    {
        return $this->signal_role === self::ROLE_PROCESSOR;
    }

    /**
     * Returns true when signal_role is not classified — schematic generator
     * must render cables touching this device as undirected lines and surface
     * a warning. Phase 17 CRIT-05 protection: never infer direction from
     * cable-row order.
     */
    public function hasUnknownSignalRole(): bool
    {
        return $this->signal_role === null;
    }
}
