// ═══════════════════════════════════════════════════════════════
// DeviceNode — custom React Flow node for AV devices.
// ─────────────────────────────────────────────────────────────
// Renders a device card: manufacturer + model + role tag at top,
// then one <Handle> per port docked on the port's declared side
// (top/right/bottom/left). Handle type maps from port.direction:
//   - 'out'   → source handle
//   - 'in'    → target handle
//   - 'bidi'  → rendered twice (source + target) so React Flow
//               will accept a connection in either direction
//
// Every handle gets `data-port-id` so the drag-connect handler
// can look the port back up in the catalog for validation.
// ═══════════════════════════════════════════════════════════════
import React from 'react';
import { Handle, Position } from '@xyflow/react';
import { signalColour } from '../signalColours.js';

const SIDE_TO_POSITION = {
    top: Position.Top,
    right: Position.Right,
    bottom: Position.Bottom,
    left: Position.Left,
};

// ── Roles get a coloured badge so the eye can pick source /
//    processor / destination across a crowded canvas.
const ROLE_STYLE = {
    source:      { bg: '#DBEAFE', fg: '#1E40AF' },
    processor:   { bg: '#EDE9FE', fg: '#5B21B6' },
    destination: { bg: '#D1FAE5', fg: '#065F46' },
};

/**
 * Distribute the ports on one side evenly along that edge. Returns
 * a percentage-based CSS position so nodes at any size render port
 * dots at the right physical spot.
 */
function portPosition(port, allPortsOnSide, index) {
    const total = allPortsOnSide.length;
    // Evenly spaced along the edge: (i + 1) / (total + 1) as a percentage.
    const pct = ((index + 1) / (total + 1)) * 100;
    if (port.side === 'top' || port.side === 'bottom') {
        return { left: `${pct}%` };
    }
    return { top: `${pct}%` };
}

function DirectionSymbol({ direction }) {
    // Filled = in, hollow = out, half = bidi — mirrors the plan.
    if (direction === 'in')  return '●';
    if (direction === 'out') return '○';
    return '◐';
}

export default function DeviceNode({ data }) {
    const { device } = data;
    const role = ROLE_STYLE[device.role] ?? ROLE_STYLE.processor;

    // Group ports by side so we can spread them along their edges.
    const bySide = { top: [], right: [], bottom: [], left: [] };
    device.ports.forEach(p => bySide[p.side]?.push(p));

    return (
        <div style={{
            background: '#FFFFFF',
            border: '1px solid #CBD5E1',
            borderRadius: 8,
            width: 220,
            minHeight: 110,
            padding: '10px 12px 14px',
            position: 'relative',
            fontFamily: 'system-ui, sans-serif',
            fontSize: 13,
            color: '#0F172A',
            boxShadow: '0 1px 2px rgb(15 23 42 / .05)',
        }}>
            {/* ── Header ───────────────────────────── */}
            <div style={{ fontSize: 11, color: '#64748B', marginBottom: 2 }}>
                {device.manufacturer}
            </div>
            <div style={{ fontWeight: 600, fontSize: 14, lineHeight: 1.25 }}>
                {device.model}
            </div>
            <div style={{
                display: 'inline-block',
                marginTop: 6,
                padding: '2px 8px',
                borderRadius: 999,
                background: role.bg,
                color: role.fg,
                fontSize: 10,
                fontWeight: 700,
                letterSpacing: '.05em',
                textTransform: 'uppercase',
            }}>
                {device.role}
            </div>

            {/* ── Port list (visual reference under the card) ── */}
            <div style={{ marginTop: 10, fontSize: 11, color: '#475569' }}>
                {device.ports.map(p => (
                    <div key={p.id} style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: 6,
                        marginTop: 3,
                    }}>
                        <span style={{
                            width: 10, height: 10,
                            borderRadius: '50%',
                            border: `2px solid ${signalColour(p.signal_type)}`,
                            background: p.direction === 'in'
                                ? signalColour(p.signal_type)
                                : p.direction === 'bidi'
                                    ? `linear-gradient(90deg, ${signalColour(p.signal_type)} 50%, transparent 50%)`
                                    : 'transparent',
                            flexShrink: 0,
                        }} />
                        <span style={{ flex: 1 }}>
                            {p.label} <span style={{ opacity: .55 }}>({p.connector_type})</span>
                        </span>
                        <span style={{ opacity: .55 }}>
                            <DirectionSymbol direction={p.direction} />
                        </span>
                    </div>
                ))}
            </div>

            {/* ── React Flow Handles — one per port, docked on side.
                 For 'bidi' we render TWO handles at the same spot
                 (source + target) so React Flow's connect handler
                 accepts either direction. ─────────────── */}
            {device.ports.map(p => {
                const sidePorts = bySide[p.side];
                const index = sidePorts.indexOf(p);
                const pos = portPosition(p, sidePorts, index);
                const colour = signalColour(p.signal_type);

                const handles = [];
                if (p.direction === 'out' || p.direction === 'bidi') {
                    handles.push(
                        <Handle
                            key={`${p.id}-src`}
                            id={`${p.id}::source`}
                            type="source"
                            position={SIDE_TO_POSITION[p.side]}
                            data-port-id={p.id}
                            data-signal-type={p.signal_type}
                            data-connector-type={p.connector_type}
                            data-direction={p.direction}
                            style={{
                                background: colour,
                                border: '2px solid #FFFFFF',
                                width: 12, height: 12,
                                ...pos,
                            }}
                        />,
                    );
                }
                if (p.direction === 'in' || p.direction === 'bidi') {
                    handles.push(
                        <Handle
                            key={`${p.id}-tgt`}
                            id={`${p.id}::target`}
                            type="target"
                            position={SIDE_TO_POSITION[p.side]}
                            data-port-id={p.id}
                            data-signal-type={p.signal_type}
                            data-connector-type={p.connector_type}
                            data-direction={p.direction}
                            style={{
                                background: colour,
                                border: '2px solid #FFFFFF',
                                width: 12, height: 12,
                                ...pos,
                            }}
                        />,
                    );
                }
                return handles;
            })}
        </div>
    );
}
