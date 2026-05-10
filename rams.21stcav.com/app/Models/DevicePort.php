<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 21 Plan 01 — per-device port row owned by a DeviceStencil.
 *
 * Drives port-to-port cable routing in Phase 22 (cable_schedule_items will
 * gain source_port_id / dest_port_id FKs) and the renderer's port-rail glyphs
 * in Phase 23.
 *
 * Auto-generated Tier 1 stencils carry NO ports — engineers add them via
 * Phase 24's curation UI. The DeviceStencilCacheService never inserts
 * device_ports rows on cache miss.
 *
 * Port positioning (D-02):
 *   - side: which edge of the device card the port sits on. Left/right ports
 *     use y_pct (0..1 vertical position). Top/bottom ports use x_pct.
 *   - port_id: stable identifier used as the mxGraph constraint name when
 *     terminating cables in the renderer (e.g. "hdmi-1"). UNIQUE per stencil.
 *
 * Naming (D-09): generic — no rams_ / project_ prefix — so the table ports
 * to SCC after the planned RAMS+SCC merge.
 *
 * @see app/Models/DeviceStencil.php
 * @see .planning/phases/21-device-port-catalog-stencil-cache/21-CONTEXT.md (D-02)
 *
 * @property int $id
 * @property int $device_stencil_id
 * @property string $label e.g. "HDMI 1", "LAN POE+1"
 * @property string $side SIDE_* constant value
 * @property string $connector_type hdmi/usb-a/usb-b/usb-c/rj45/rj45-poe/rs232/3.5mm/xlr/phoenix/dp/etc.
 * @property string $signal_type audio/video/control/network/usb/power/speaker/dante/etc.
 * @property string $direction DIRECTION_* constant value
 * @property int $sort_order stable rendering order
 * @property string $port_id mxGraph constraint name, unique per stencil
 * @property ?string $y_pct decimal 0..1 (left/right ports)
 * @property ?string $x_pct decimal 0..1 (top/bottom ports)
 */
class DevicePort extends Model
{
    // ── Side enum (D-02) ─────────────────────────────────────────────────────

    public const SIDE_LEFT = 'left';

    public const SIDE_RIGHT = 'right';

    public const SIDE_TOP = 'top';

    public const SIDE_BOTTOM = 'bottom';

    // ── Direction enum (D-02) ────────────────────────────────────────────────

    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    public const DIRECTION_IO = 'io';

    protected $fillable = [
        'device_stencil_id',
        'label',
        'side',
        'connector_type',
        'signal_type',
        'direction',
        'sort_order',
        'port_id',
        'y_pct',
        'x_pct',
    ];

    protected $casts = [
        'y_pct'      => 'decimal:4',
        'x_pct'      => 'decimal:4',
        'sort_order' => 'integer',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function stencil(): BelongsTo
    {
        return $this->belongsTo(DeviceStencil::class);
    }
}
