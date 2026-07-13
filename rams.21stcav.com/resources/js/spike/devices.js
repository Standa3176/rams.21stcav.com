// ═══════════════════════════════════════════════════════════════
// Hardcoded device catalog — the 5 devices the spike ships with.
// ─────────────────────────────────────────────────────────────
// Shape mirrors the future `devices` + `device_ports` schema loosely
// so if we commit to the editor, porting to a DB-backed catalog is
// a rename job, not a rewrite:
//   { id, manufacturer, model, role, ports: [{ id, label, connector_type,
//     signal_type, direction, side }] }
//
// - role: 'source' | 'processor' | 'destination' — drives auto-arrange
// - connector_type: 'hdmi' | 'rj45' | 'trs' | 'rs232' | 'usb-c'
// - signal_type: 'video' | 'audio' | 'control' | 'network' | 'usb'
//   (must match keys in signalColours.js)
// - direction: 'in' | 'out' | 'bidi'
// - side: 'top' | 'right' | 'bottom' | 'left' — where the Handle
//   docks on the node card
// ═══════════════════════════════════════════════════════════════
export const DEVICES = [
    {
        id: 'sony-qm85',
        manufacturer: 'Sony',
        model: 'QM85 4K Display',
        role: 'destination',
        ports: [
            { id: 'hdmi-in-1', label: 'HDMI 1', connector_type: 'hdmi',  signal_type: 'video',   direction: 'in', side: 'left' },
            { id: 'hdmi-in-2', label: 'HDMI 2', connector_type: 'hdmi',  signal_type: 'video',   direction: 'in', side: 'left' },
            { id: 'rs232-in',  label: 'RS232',  connector_type: 'rs232', signal_type: 'control', direction: 'in', side: 'right' },
        ],
    },
    {
        id: 'cisco-room-bar-pro',
        manufacturer: 'Cisco',
        model: 'Room Bar Pro',
        role: 'processor',
        ports: [
            { id: 'hdmi-in',   label: 'HDMI In',  connector_type: 'hdmi',  signal_type: 'video',   direction: 'in',   side: 'left' },
            { id: 'hdmi-out',  label: 'HDMI Out', connector_type: 'hdmi',  signal_type: 'video',   direction: 'out',  side: 'right' },
            { id: 'usb-c-out', label: 'USB-C',    connector_type: 'usb-c', signal_type: 'usb',     direction: 'out',  side: 'right' },
            { id: 'rj45-poe',  label: 'RJ45 PoE', connector_type: 'rj45',  signal_type: 'network', direction: 'bidi', side: 'bottom' },
        ],
    },
    {
        id: 'shure-mxw',
        manufacturer: 'Shure',
        model: 'MXW Wireless Mic',
        role: 'source',
        ports: [
            // Dante audio-over-IP: connector is RJ45, but signal is audio.
            // The validator honours signal_type first (audio), then falls
            // back to connector aliases.
            { id: 'dante-out', label: 'Dante', connector_type: 'rj45', signal_type: 'audio', direction: 'out', side: 'right' },
        ],
    },
    {
        id: 'qsc-qsys-core',
        manufacturer: 'QSC',
        model: 'Q-Sys Core 110f DSP',
        role: 'processor',
        ports: [
            { id: 'dante-1',    label: 'Dante 1', connector_type: 'rj45', signal_type: 'network', direction: 'bidi', side: 'left' },
            { id: 'dante-2',    label: 'Dante 2', connector_type: 'rj45', signal_type: 'network', direction: 'bidi', side: 'left' },
            { id: 'dante-3',    label: 'Dante 3', connector_type: 'rj45', signal_type: 'network', direction: 'bidi', side: 'right' },
            { id: 'dante-4',    label: 'Dante 4', connector_type: 'rj45', signal_type: 'network', direction: 'bidi', side: 'right' },
            { id: 'line-out-1', label: 'Line 1',  connector_type: 'trs',  signal_type: 'audio',   direction: 'out',  side: 'right' },
            { id: 'line-out-2', label: 'Line 2',  connector_type: 'trs',  signal_type: 'audio',   direction: 'out',  side: 'right' },
        ],
    },
    {
        id: 'netgear-gs308epp',
        manufacturer: 'Netgear',
        model: 'GS308EPP PoE Switch',
        // "destination" for auto-arrange purposes — sits with sinks in
        // the right column of the schematic. Real-world it's a network
        // aggregation node, but for the 3-column layout that's the
        // cleanest place to park it.
        role: 'destination',
        ports: [
            { id: 'rj45-poe-1', label: 'Port 1', connector_type: 'rj45', signal_type: 'network', direction: 'bidi', side: 'left' },
            { id: 'rj45-poe-2', label: 'Port 2', connector_type: 'rj45', signal_type: 'network', direction: 'bidi', side: 'left' },
            { id: 'rj45-poe-3', label: 'Port 3', connector_type: 'rj45', signal_type: 'network', direction: 'bidi', side: 'left' },
            { id: 'rj45-poe-4', label: 'Port 4', connector_type: 'rj45', signal_type: 'network', direction: 'bidi', side: 'left' },
            { id: 'rj45-poe-5', label: 'Port 5', connector_type: 'rj45', signal_type: 'network', direction: 'bidi', side: 'right' },
            { id: 'rj45-poe-6', label: 'Port 6', connector_type: 'rj45', signal_type: 'network', direction: 'bidi', side: 'right' },
            { id: 'rj45-poe-7', label: 'Port 7', connector_type: 'rj45', signal_type: 'network', direction: 'bidi', side: 'right' },
            { id: 'rj45-poe-8', label: 'Port 8', connector_type: 'rj45', signal_type: 'network', direction: 'bidi', side: 'right' },
        ],
    },
];

/**
 * Look up a device by catalog id. Returns null on miss so the caller
 * decides how to handle stale localStorage IDs.
 */
export function findDevice(catalogId) {
    return DEVICES.find(d => d.id === catalogId) ?? null;
}

/**
 * Look up a port by (deviceCatalogId, portId). Used by the connection
 * validator to fetch the source/target port shape from the connection
 * event React Flow hands us.
 */
export function findPort(catalogId, portId) {
    const device = findDevice(catalogId);
    if (!device) return null;
    return device.ports.find(p => p.id === portId) ?? null;
}
