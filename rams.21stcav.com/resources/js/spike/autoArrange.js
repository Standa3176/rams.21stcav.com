// ═══════════════════════════════════════════════════════════════
// Auto-arrange — simple 3-column signal-flow layout.
// ─────────────────────────────────────────────────────────────
// sources (x=0)      processors (x=350)     destinations (x=700)
//
// Within each column, sort by dominant output signal type so
// like signals cluster (video → audio → control → network).
// Vertical spacing 200px, 40px top margin. No dagre — column
// layout is enough to answer "does auto-arrange feel useful".
// ═══════════════════════════════════════════════════════════════
const COLUMN_X = {
    source:      0,
    processor:   350,
    destination: 700,
};

// Dominant-signal priority — lower rank sorts higher in the column.
const SIGNAL_RANK = {
    video:   0,
    audio:   1,
    control: 2,
    usb:     3,
    network: 4,
    unknown: 5,
};

const VERTICAL_STEP = 200;
const TOP_MARGIN = 40;

function dominantSignalType(device) {
    if (!device?.ports?.length) return 'unknown';
    // Prefer output/bidi ports (they define what the device sends).
    const emit = device.ports.filter(p => p.direction === 'out' || p.direction === 'bidi');
    const pool = emit.length ? emit : device.ports;
    // Take the lowest-rank signal type in the pool.
    return pool
        .map(p => p.signal_type)
        .sort((a, b) => (SIGNAL_RANK[a] ?? 99) - (SIGNAL_RANK[b] ?? 99))[0]
        ?? 'unknown';
}

/**
 * Return a new nodes array with x/y positions recomputed by the
 * column-based layout. Does not mutate the input. Preserves all
 * other node fields (data, id, type, etc).
 */
export function autoArrange(nodes) {
    const buckets = { source: [], processor: [], destination: [] };
    nodes.forEach(node => {
        const role = node.data?.device?.role ?? 'processor';
        buckets[role]?.push(node) ?? buckets.processor.push(node);
    });

    // Sort each bucket by dominant signal type, then by node id for
    // deterministic order when signals tie.
    Object.keys(buckets).forEach(role => {
        buckets[role].sort((a, b) => {
            const rankA = SIGNAL_RANK[dominantSignalType(a.data?.device)] ?? 99;
            const rankB = SIGNAL_RANK[dominantSignalType(b.data?.device)] ?? 99;
            if (rankA !== rankB) return rankA - rankB;
            return a.id.localeCompare(b.id);
        });
    });

    const placed = [];
    Object.entries(buckets).forEach(([role, list]) => {
        const x = COLUMN_X[role] ?? 350;
        list.forEach((node, i) => {
            placed.push({
                ...node,
                position: { x, y: TOP_MARGIN + i * VERTICAL_STEP },
            });
        });
    });
    return placed;
}
