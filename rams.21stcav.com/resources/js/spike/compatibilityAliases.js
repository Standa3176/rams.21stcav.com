// ═══════════════════════════════════════════════════════════════
// Connector-type compatibility aliases
// ─────────────────────────────────────────────────────────────
// Hardcoded copy of config/cables.php compatibility_aliases. Used
// by the connection validator to allow "close-enough" pairings
// like HDMI ↔ DisplayPort (via active adapter) that engineers do
// legitimately wire in the field.
//
// Bidirectional — the validator checks (from, to) OR (to, from).
// ═══════════════════════════════════════════════════════════════
export const COMPATIBILITY_ALIASES = [
    { from: 'hdmi',  to: 'dp',          note: 'HDMI ↔ DisplayPort via active adapter' },
    { from: 'usb-c', to: 'thunderbolt', note: 'USB-C ↔ Thunderbolt 3/4 backwards-compatible' },
    { from: 'rj45',  to: 'sfp-plus',    note: 'RJ45 ↔ SFP+ via SFP module' },
    { from: 'usb-c', to: 'hdmi',        note: 'USB-C → HDMI via DisplayPort Alt Mode adapter' },
];

/**
 * Check if two connector types are compatible via any alias.
 * Returns the matching alias object (with note for the toast) or null.
 */
export function findAlias(fromConnector, toConnector) {
    return COMPATIBILITY_ALIASES.find(a =>
        (a.from === fromConnector && a.to === toConnector) ||
        (a.from === toConnector && a.to === fromConnector),
    ) ?? null;
}
