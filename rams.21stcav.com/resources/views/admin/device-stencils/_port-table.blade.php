{{--
    admin/device-stencils/_port-table

    Alpine x-for reactive port-row repeater (D-01 — the table is the source
    of truth, explicitly NOT drag-on-canvas). Shares the parent
    stencilPortEditor() x-data scope from edit.blade.php (the `ports` array,
    add/removePort(), rowTintClass()).

    Reactive shape mirrored from resources/views/components/survey/
    repeater-equipment.blade.php's x-for/x-model pattern (NOT
    project-packages/review.blade.php's DOM-toggle equipmentSection() —
    confirmed poor fit by research, see 24-CONTEXT.md D-01/24-PATTERNS.md).
--}}
<style>
.stc-port-table-card { padding: 0; overflow: visible; }
.stc-port-table-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 22px 0 22px;
}
.stc-port-table-wrap { overflow-x: auto; }
.stc-port-table {
    width: 100%;
    border-collapse: collapse;
}
.stc-port-table th {
    font-size: var(--fs-small);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--text-muted);
    text-align: left;
    padding: 12px 16px;
    border-bottom: 1px solid var(--ink-100);
    white-space: nowrap;
}
.stc-port-table td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--ink-100);
    vertical-align: top;
}
.stc-port-table input,
.stc-port-table select {
    width: 100%;
    min-width: 90px;
    padding: 6px 8px;
    border: 1px solid var(--border-strong);
    border-radius: 6px;
    font-family: inherit;
    font-size: var(--fs-body);
    color: var(--ink-900);
    background: var(--surface);
}
.stc-port-table input.stc-port-id,
.stc-port-table input.stc-port-pos { font-family: var(--font-mono); min-width: 80px; }
.stc-port-table tr.stc-port-row--danger  { background: var(--danger-light); border-left: 3px solid var(--danger); }
.stc-port-table tr.stc-port-row--warning { background: var(--warning-light); border-left: 3px solid var(--warning); }
.stc-port-empty {
    padding: 24px 22px;
    color: var(--text-muted);
    font-size: var(--fs-small);
}
</style>

<div class="card stc-port-table-card">
    <div class="stc-port-table-header">
        <div class="section-heading" style="border-bottom:none;padding-bottom:0;margin-bottom:0;">Ports</div>
        <button type="button" class="btn btn-outline btn-sm" @click="addPort()">+ Add port</button>
    </div>

    <p x-show="!ports.length" class="stc-port-empty">No ports yet — use “+ Add port” to start, or Save with zero ports (structural save always allowed, D-01).</p>

    <div class="stc-port-table-wrap" x-show="ports.length">
        <table class="stc-port-table">
            <thead>
                <tr>
                    <th>Label</th>
                    <th>Side</th>
                    <th>Connector type</th>
                    <th>Signal type</th>
                    <th>Direction</th>
                    <th>Position</th>
                    <th>Port ID</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="(port, idx) in ports" :key="idx">
                    <tr :class="rowTintClass(port)">
                        {{-- Field names carry the CURRENT array index explicitly
                             (:name bound, not a static "ports[][x]") — plain
                             "ports[][x]" / "ports[][y]" pairs each independently
                             auto-increment their OWN counter in PHP's array
                             parser, so sibling fields for the SAME row would
                             silently land on DIFFERENT numeric indices and never
                             recombine into one port. Binding the live `idx` from
                             x-for keeps every field in a row aligned to the same
                             ports[N] slot, and stays correct across add/remove
                             since idx re-derives from array position every render. --}}
                        <td>
                            <input type="text" :name="'ports[' + idx + '][label]'" x-model="port.label" maxlength="100" placeholder="e.g. HDMI In">
                        </td>
                        <td>
                            <select :name="'ports[' + idx + '][side]'" x-model="port.side">
                                <option value="left">Left</option>
                                <option value="right">Right</option>
                                <option value="top">Top</option>
                                <option value="bottom">Bottom</option>
                            </select>
                        </td>
                        <td>
                            <input type="text" :name="'ports[' + idx + '][connector_type]'" x-model="port.connector_type" maxlength="50" placeholder="e.g. hdmi (free text — engineer-extensible)">
                        </td>
                        <td>
                            <select :name="'ports[' + idx + '][signal_type]'" x-model="port.signal_type">
                                <option value="">—</option>
                                <option value="audio">Audio</option>
                                <option value="video">Video</option>
                                <option value="control">Control</option>
                                <option value="network">Network</option>
                                <option value="usb">USB</option>
                                <option value="power">Power</option>
                                <option value="speaker">Speaker</option>
                                <option value="dante">Dante</option>
                                <option value="unclassified">Unclassified</option>
                            </select>
                        </td>
                        <td>
                            <select :name="'ports[' + idx + '][direction]'" x-model="port.direction">
                                <option value="in">In</option>
                                <option value="out">Out</option>
                                <option value="io">I/O</option>
                            </select>
                        </td>
                        <td>
                            <template x-if="port.side === 'left' || port.side === 'right'">
                                <input type="number" class="stc-port-pos" :name="'ports[' + idx + '][y_pct]'" x-model="port.y_pct" min="0" max="1" step="0.01" placeholder="auto">
                            </template>
                            <template x-if="port.side === 'top' || port.side === 'bottom'">
                                <input type="number" class="stc-port-pos" :name="'ports[' + idx + '][x_pct]'" x-model="port.x_pct" min="0" max="1" step="0.01" placeholder="auto">
                            </template>
                        </td>
                        <td>
                            <input type="text" class="stc-port-id" :name="'ports[' + idx + '][port_id]'" x-model="port.port_id" maxlength="50" placeholder="e.g. hdmi-1">
                        </td>
                        <td>
                            <button type="button" class="btn btn-ghost btn-sm" @click="removePort(idx)"
                                    :aria-label="'Remove port ' + (port.label || 'row ' + (idx + 1))">
                                &times;
                            </button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</div>
