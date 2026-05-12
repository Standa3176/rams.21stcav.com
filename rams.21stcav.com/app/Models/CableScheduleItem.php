<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 22 — cable schedule line with optional port-level FKs.
 *
 * Legacy rows (pre-Phase-22) have NULL FK columns and continue to render via
 * v1.3 surfaces (XLSX export, schematic generator, bound-PDF cable section)
 * unchanged. The 4 belongsTo relations resolve to null on NULL FKs without
 * firing a DB query — Eloquent short-circuits. D-10 invariant.
 *
 * D-04 (CONTEXT.md): when the picker is used, the canonical port labels
 * overwrite from_location / to_location text. Subsequent manual edits to the
 * text columns do NOT clear the FKs — Phase 23's renderer prefers FK over
 * text. Engineers wanting custom freeform text don't open the picker.
 *
 * D-10 guard (CRITICAL): NEVER add `protected $with = ['sourcePort', ...]` —
 * class-level eager loading would force 4 LEFT JOINs on every legacy NULL-FK
 * row across every read path (XLSX export job, bound-PDF section). Eager-load
 * AT THE CALL SITE only (the picker page: `$schedule->load('items.sourcePort')`).
 *
 * T-22-A1 mitigation: $fillable whitelist below is the mass-assignment guard.
 * The picker writes 5 new keys; any other items[N][*] key in the request is
 * silently dropped by Eloquent.
 *
 * @see app/Http/Controllers/CableScheduleController.php@update (Phase 22 Plan 02)
 * @see .planning/phases/22-cable-schedule-with-port-level-fks/22-CONTEXT.md D-04 D-10
 */
class CableScheduleItem extends Model
{
    protected $fillable = [
        'cable_schedule_id',
        'cable_id',
        'from_location',
        'to_location',
        'cable_type',
        'cores',
        'approx_length_m',
        'notes',
        'sort_order',
        // ── Phase 22 port-level additions (DRAW-37) ─────────────────────────
        'source_device_id',
        'source_port_id',
        'dest_device_id',
        'dest_port_id',
        'connector_override_note',
    ];

    protected $casts = [
        'approx_length_m' => 'float',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(CableSchedule::class, 'cable_schedule_id');
    }

    // ── Phase 22 port-level relations ────────────────────────────────────────
    // D-10 guard: do NOT add these to $with. Eager-load only at the call site
    // (CableScheduleController@edit). Legacy NULL-FK rows resolve to null
    // without a DB query — Eloquent short-circuits.

    public function sourceDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'source_device_id');
    }

    public function sourcePort(): BelongsTo
    {
        return $this->belongsTo(DevicePort::class, 'source_port_id');
    }

    public function destDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'dest_device_id');
    }

    public function destPort(): BelongsTo
    {
        return $this->belongsTo(DevicePort::class, 'dest_port_id');
    }
}
