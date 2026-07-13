// ═══════════════════════════════════════════════════════════════
// DevicePalette — left sidebar showing the 5 hardcoded devices.
// ─────────────────────────────────────────────────────────────
// Uses native HTML5 drag-and-drop, not React Flow's built-in
// drag. On dragstart we stash the device catalog id in a custom
// MIME so the canvas drop handler can look it up in devices.js.
// ═══════════════════════════════════════════════════════════════
import React from 'react';
import { DEVICES } from '../devices.js';

export const DEVICE_DRAG_MIME = 'application/schematic-device';

const ROLE_TINT = {
    source:      '#DBEAFE',
    processor:   '#EDE9FE',
    destination: '#D1FAE5',
};

export default function DevicePalette() {
    return (
        <aside style={{
            width: 240,
            flexShrink: 0,
            borderRight: '1px solid #E2E8F0',
            background: '#F7F9FC',
            padding: '14px 12px',
            overflowY: 'auto',
            fontFamily: 'system-ui, sans-serif',
        }}>
            <div style={{
                fontSize: 11,
                fontWeight: 700,
                letterSpacing: '.08em',
                color: '#64748B',
                textTransform: 'uppercase',
                marginBottom: 10,
            }}>
                Devices
            </div>
            <p style={{ fontSize: 12, color: '#64748B', marginBottom: 12, lineHeight: 1.4 }}>
                Drag any card onto the canvas to place a device.
            </p>
            {DEVICES.map(device => (
                <div
                    key={device.id}
                    draggable
                    onDragStart={e => {
                        e.dataTransfer.effectAllowed = 'copy';
                        e.dataTransfer.setData(DEVICE_DRAG_MIME, device.id);
                        // Also set text/plain as a fallback — some browsers
                        // will refuse a drop if only a custom MIME is set.
                        e.dataTransfer.setData('text/plain', device.id);
                    }}
                    style={{
                        background: '#FFFFFF',
                        border: '1px solid #CBD5E1',
                        borderRadius: 6,
                        padding: '8px 10px',
                        marginBottom: 8,
                        cursor: 'grab',
                        userSelect: 'none',
                        transition: 'border-color 150ms',
                    }}
                    onMouseEnter={e => (e.currentTarget.style.borderColor = '#94A3B8')}
                    onMouseLeave={e => (e.currentTarget.style.borderColor = '#CBD5E1')}
                >
                    <div style={{ fontSize: 10, color: '#64748B' }}>
                        {device.manufacturer}
                    </div>
                    <div style={{ fontSize: 13, fontWeight: 600, color: '#0F172A' }}>
                        {device.model}
                    </div>
                    <div style={{
                        display: 'inline-block',
                        marginTop: 4,
                        padding: '1px 6px',
                        borderRadius: 999,
                        background: ROLE_TINT[device.role] ?? '#F1F5F9',
                        fontSize: 9,
                        fontWeight: 700,
                        letterSpacing: '.05em',
                        textTransform: 'uppercase',
                        color: '#334155',
                    }}>
                        {device.role} · {device.ports.length} ports
                    </div>
                </div>
            ))}
        </aside>
    );
}
