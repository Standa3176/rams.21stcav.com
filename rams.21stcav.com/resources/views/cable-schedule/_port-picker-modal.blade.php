{{--
    Phase 22 Plan 02 — Port Picker Modal (D-01..D-04)

    Single instance per page. Engineer opens it from a cable row via the 🔗
    chain-link icon. Listens to @port-picker:open.window for the row's current
    FK values, dispatches @port-picker:applied.window on Apply / Clear with the
    picked (or cleared) selection. The row-level vanilla-JS listener in
    edit.blade.php resolves the correct row by rowIndex and writes hidden inputs
    + overwrites from/to text + flips icon state.

    D-LOCKs honoured:
      D-01 — Modal picker per row (single modal instance, engineer-opened)
      D-02 — Side-by-side SOURCE / DESTINATION layout
      D-03 — Chain-link icon column trigger (rendered in edit.blade.php)
      D-04 — Picker overwrites From/To text with canonical labels on Apply

    DRAW-39 override workflow: when source + dest connector types don't match
    (per config/cables.php compatibility_aliases bidirectional allowlist), a
    yellow warning banner appears with a REQUIRED override-note textarea. Apply
    is disabled until the note has non-whitespace content.

    Open Question 2: a "Clear ports on this row" button writes NULL to all 5
    FK columns and toggles the icon back to its unset state.

    Pitfall 5 — EVERY <button> here carries explicit type="button". The picker
    is rendered inside the cable-schedule edit <form>, and a stray submit-type
    button would submit the form on Apply, losing other unsaved rows.

    T-22-A2 (XSS via override-note) — the textarea uses x-model only; values
    are never rendered into the DOM via {!! !!}. Default Blade {{ }} escaping
    protects the hidden inputs that re-display persisted values.
--}}

<div
    x-data="portPicker({
        devices: @js($devicesWithPorts ?? []),
        compatAliases: @js(config('cables.compatibility_aliases')),
    })"
    x-show="open"
    x-cloak
    @keydown.escape.window="cancel()"
    @port-picker:open.window="handleOpen($event.detail)"
    class="port-picker-backdrop"
    style="position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;display:flex;align-items:center;justify-content:center;"
>
    <div
        class="port-picker-modal card"
        style="max-width:780px;width:92vw;max-height:90vh;overflow:auto;background:#fff;padding:1.25rem;border-radius:8px;box-shadow:0 10px 40px rgba(0,0,0,.3);"
        @click.stop
    >
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
            <h2 style="font-size:1.15rem;margin:0;color:var(--accent-700);">
                Pick ports for row <span x-text="rowIndex + 1"></span>
            </h2>
            <button type="button" @click="cancel()" aria-label="Close picker"
                    style="background:none;border:none;font-size:1.4rem;cursor:pointer;line-height:1;color:#666;">×</button>
        </div>

        <div class="picker-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;">
            {{-- SOURCE column (D-02) --}}
            <section>
                <h3 style="font-size:.85rem;letter-spacing:.05em;color:var(--accent-700);margin:0 0 .5rem;text-transform:uppercase;">Source</h3>

                <label class="form-label" style="font-size:.8rem;">Device</label>
                <select class="form-control" x-model.number="sourceDeviceId" @change="sourcePortId = null">
                    <option :value="null">— pick device —</option>
                    <template x-for="d in devices" :key="d.id">
                        <option :value="d.id" x-text="d.label"></option>
                    </template>
                </select>

                <label class="form-label" style="margin-top:.75rem;font-size:.8rem;">Port</label>
                <select class="form-control" x-model.number="sourcePortId" :disabled="!sourceDeviceId">
                    <option :value="null">— pick port —</option>
                    <template x-for="p in portsForDevice(sourceDeviceId)" :key="p.id">
                        <option :value="p.id" x-text="p.label + ' (' + (p.connector_type || '—') + ')'"></option>
                    </template>
                </select>
            </section>

            {{-- DESTINATION column (D-02) --}}
            <section>
                <h3 style="font-size:.85rem;letter-spacing:.05em;color:var(--accent-700);margin:0 0 .5rem;text-transform:uppercase;">Destination</h3>

                <label class="form-label" style="font-size:.8rem;">Device</label>
                <select class="form-control" x-model.number="destDeviceId" @change="destPortId = null">
                    <option :value="null">— pick device —</option>
                    <template x-for="d in devices" :key="d.id">
                        <option :value="d.id" x-text="d.label"></option>
                    </template>
                </select>

                <label class="form-label" style="margin-top:.75rem;font-size:.8rem;">Port</label>
                <select class="form-control" x-model.number="destPortId" :disabled="!destDeviceId">
                    <option :value="null">— pick port —</option>
                    <template x-for="p in portsForDevice(destDeviceId)" :key="p.id">
                        <option :value="p.id" x-text="p.label + ' (' + (p.connector_type || '—') + ')'"></option>
                    </template>
                </select>
            </section>
        </div>

        {{-- Inline override warning + required note (DRAW-39) --}}
        <div x-show="warningReason()" x-cloak
             style="margin-top:1rem;padding:.75rem;background:#FFF8E1;border:1px solid #FBC02D;border-radius:6px;">
            <p style="margin:0 0 .5rem;font-weight:600;color:#5D4037;font-size:.875rem;">
                ⚠ <span x-text="warningReason()"></span>
            </p>
            <label class="form-label" for="picker-override-note" style="font-size:.8rem;">
                Override reason (required)
            </label>
            <textarea
                id="picker-override-note"
                class="form-control"
                x-model="overrideNote"
                maxlength="500"
                rows="2"
                placeholder="e.g. HDBaseT extender installed between source HDMI and dest RJ45 wall plate"
            ></textarea>
            <small style="color:#5D4037;font-size:.75rem;">
                <span x-text="500 - (overrideNote ? overrideNote.length : 0)"></span> characters remaining
            </small>
        </div>

        <div style="margin-top:1.25rem;display:flex;justify-content:space-between;gap:.5rem;align-items:center;flex-wrap:wrap;">
            <button type="button" class="btn btn-outline btn-sm" @click="clearAndApply()">
                Clear ports on this row
            </button>
            <div style="display:flex;gap:.5rem;">
                <button type="button" class="btn btn-outline" @click="cancel()">Cancel</button>
                <button type="button" class="btn btn-teal" :disabled="!canApply()" @click="apply()">
                    Apply
                </button>
            </div>
        </div>

        <p style="margin-top:.75rem;font-size:.75rem;color:#777;">
            Use the picker to keep text and ports in sync.
            For freeform text (e.g. "Mains via 13A spur"), leave the picker closed on that row.
        </p>
    </div>
</div>

@once
@push('scripts')
<script>
window.portPicker = function (cfg) {
    return {
        open: false,
        rowIndex: 0,
        devices: cfg.devices || [],
        compatAliases: cfg.compatAliases || [],
        sourceDeviceId: null,
        sourcePortId: null,
        destDeviceId: null,
        destPortId: null,
        overrideNote: '',

        handleOpen(detail) {
            this.rowIndex       = detail.rowIndex || 0;
            this.sourceDeviceId = detail.current?.sourceDeviceId || null;
            this.sourcePortId   = detail.current?.sourcePortId   || null;
            this.destDeviceId   = detail.current?.destDeviceId   || null;
            this.destPortId     = detail.current?.destPortId     || null;
            this.overrideNote   = detail.current?.overrideNote   || '';
            this.open = true;
        },

        cancel() {
            this.open = false;
        },

        portsForDevice(deviceId) {
            if (!deviceId) return [];
            const d = this.devices.find(x => x.id === deviceId);
            return d ? (d.ports || []) : [];
        },

        portById(deviceId, portId) {
            return this.portsForDevice(deviceId).find(p => p.id === portId) || null;
        },

        // Mirrors PHP CableConnectorCompatibilityService::check exactly so the
        // picker's warning matches what would happen on the server. See
        // app/Services/Cable/CableConnectorCompatibilityService.php
        isCompatible(srcConn, dstConn) {
            const a = (srcConn || '').toLowerCase().trim();
            const b = (dstConn || '').toLowerCase().trim();
            // Pitfall 4 — empty/unknown connector type treated as compatible
            // (Tier 1.5 stencils carry empty port metadata until Phase 24 curates).
            if (a === '' || b === '') return { compatible: true, reason: null };
            if (a === b) return { compatible: true, reason: null };
            for (const alias of this.compatAliases) {
                const from = (alias.from || '').toLowerCase().trim();
                const to   = (alias.to   || '').toLowerCase().trim();
                if ((from === a && to === b) || (from === b && to === a)) {
                    return { compatible: true, reason: alias.note || null };
                }
            }
            return { compatible: false, reason: 'Connector mismatch: ' + a + ' → ' + b };
        },

        warningReason() {
            const sp = this.portById(this.sourceDeviceId, this.sourcePortId);
            const dp = this.portById(this.destDeviceId,   this.destPortId);
            if (!sp || !dp) return null;
            const r = this.isCompatible(sp.connector_type, dp.connector_type);
            return r.compatible ? null : r.reason;
        },

        canApply() {
            if (!this.sourceDeviceId || !this.sourcePortId || !this.destDeviceId || !this.destPortId) {
                return false;
            }
            // Incompatible pair requires non-empty override note (DRAW-39 client gate).
            if (this.warningReason() && (this.overrideNote || '').trim() === '') {
                return false;
            }
            return true;
        },

        canonicalLabel(device, port) {
            const parts = [];
            if (device.manufacturer) parts.push(String(device.manufacturer).trim());
            if (device.model)        parts.push(String(device.model).trim());
            const head = parts.join(' ').trim() || device.label || 'Device';
            return head + ' (' + port.label + ')';
        },

        apply() {
            const sp = this.portById(this.sourceDeviceId, this.sourcePortId);
            const dp = this.portById(this.destDeviceId,   this.destPortId);
            const srcDev = this.devices.find(x => x.id === this.sourceDeviceId);
            const dstDev = this.devices.find(x => x.id === this.destDeviceId);
            if (!sp || !dp || !srcDev || !dstDev) return;

            window.dispatchEvent(new CustomEvent('port-picker:applied', { detail: {
                rowIndex: this.rowIndex,
                sourceDeviceId: this.sourceDeviceId,
                sourcePortId:   this.sourcePortId,
                destDeviceId:   this.destDeviceId,
                destPortId:     this.destPortId,
                overrideNote:   this.overrideNote,
                sourceLabel:    this.canonicalLabel(srcDev, sp),
                destLabel:      this.canonicalLabel(dstDev, dp),
                cleared:        false,
            }}));
            this.open = false;
        },

        clearAndApply() {
            window.dispatchEvent(new CustomEvent('port-picker:applied', { detail: {
                rowIndex: this.rowIndex,
                sourceDeviceId: null,
                sourcePortId:   null,
                destDeviceId:   null,
                destPortId:     null,
                overrideNote:   '',
                sourceLabel:    null,
                destLabel:      null,
                cleared:        true,
            }}));
            this.open = false;
        },
    };
};
</script>
@endpush
@endonce
