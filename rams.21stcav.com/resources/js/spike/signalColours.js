// ═══════════════════════════════════════════════════════════════
// Signal-type colour map — hardcoded from config/cables.php
// ─────────────────────────────────────────────────────────────
// Spike simplicity: don't fetch from the server. If we commit
// to the editor after 2026-07-27 review, the real editor pulls
// this from a `/api/schematic/signal-types` endpoint or bakes it
// into the JS bundle at build time.
// ═══════════════════════════════════════════════════════════════
export const SIGNAL_COLOURS = {
    audio: '#C0392B',
    video: '#2980B9',
    control: '#27AE60',
    network: '#8E44AD',
    usb: '#E67E22',
    speaker: '#16A085',
    power: '#7F8C8D',
    unknown: '#000000',
};

/**
 * Resolve a signal type to its colour, falling back to the "unknown"
 * black when the key is missing (safer than transparent — the port
 * still renders visibly if we hit a typo).
 */
export function signalColour(signalType) {
    return SIGNAL_COLOURS[signalType] ?? SIGNAL_COLOURS.unknown;
}
