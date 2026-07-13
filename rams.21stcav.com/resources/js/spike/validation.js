// ═══════════════════════════════════════════════════════════════
// Port-type connection validator.
// ─────────────────────────────────────────────────────────────
// Rules — enforced in order, first failure wins the toast:
//   1. Direction — 'out' → 'in' only. 'bidi' will match either.
//   2. Signal type — must match (video/video, audio/audio, …).
//      Falls back to a connector compatibility alias if signal
//      types differ but connector pairing is legit (HDMI ↔ DP,
//      USB-C ↔ Thunderbolt, RJ45 ↔ SFP+, USB-C ↔ HDMI).
//
// Returns { valid: boolean, reason?: string }. The reason string
// is what the toast shows the engineer.
// ═══════════════════════════════════════════════════════════════
import { findAlias } from './compatibilityAliases.js';

/**
 * Validate a proposed edge between two ports.
 *
 * @param {{connector_type: string, signal_type: string, direction: 'in'|'out'|'bidi'}} sourcePort
 * @param {{connector_type: string, signal_type: string, direction: 'in'|'out'|'bidi'}} targetPort
 * @returns {{valid: boolean, reason?: string, note?: string}}
 */
export function isValidConnection(sourcePort, targetPort) {
    if (!sourcePort || !targetPort) {
        return { valid: false, reason: 'Missing port metadata — connection ignored.' };
    }

    // ── 1. Direction ─────────────────────────────────────────
    // Source must be able to emit, target must be able to receive.
    const sourceEmits = sourcePort.direction === 'out' || sourcePort.direction === 'bidi';
    const targetReceives = targetPort.direction === 'in' || targetPort.direction === 'bidi';
    if (!sourceEmits) {
        return {
            valid: false,
            reason: `Cannot start a connection from an input port (${labelPort(sourcePort)}). Drag from an output or bidi port.`,
        };
    }
    if (!targetReceives) {
        return {
            valid: false,
            reason: `Cannot end a connection at an output port (${labelPort(targetPort)}). Drop on an input or bidi port.`,
        };
    }

    // ── 2. Signal type ───────────────────────────────────────
    if (sourcePort.signal_type === targetPort.signal_type) {
        return { valid: true };
    }

    // ── 3. Connector alias fallback ──────────────────────────
    // Signal types differ, but if the connector pair is a known
    // adapter-compatible pairing we still let it through. Toast
    // shows the note so the engineer knows what's happening.
    const alias = findAlias(sourcePort.connector_type, targetPort.connector_type);
    if (alias) {
        return { valid: true, note: alias.note };
    }

    return {
        valid: false,
        reason: `Cannot connect ${labelPort(sourcePort)} to ${labelPort(targetPort)} — signal types differ (${sourcePort.signal_type} → ${targetPort.signal_type}) and no compatible adapter.`,
    };
}

function labelPort(port) {
    return `${port.signal_type}-${port.direction} (${port.connector_type.toUpperCase()})`;
}
