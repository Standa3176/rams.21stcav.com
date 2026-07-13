// ═══════════════════════════════════════════════════════════════
// Schematic Editor Spike — React root
// ─────────────────────────────────────────────────────────────
// Task 1: bare stub so we can prove the Vite/React pipeline
// compiles + mounts in the Blade shell. Task 2 replaces the
// stub with <SchematicEditor />.
// ═══════════════════════════════════════════════════════════════
import React from 'react';
import { createRoot } from 'react-dom/client';

function SpikeStub() {
    return (
        <div style={{
            padding: '2rem',
            fontFamily: 'system-ui, sans-serif',
            color: '#0F172A',
        }}>
            <h1 style={{ fontSize: '1.5rem', marginBottom: '.5rem' }}>
                🧪 Schematic Editor Spike (Task 1 stub)
            </h1>
            <p style={{ color: '#64748B' }}>
                React + React Flow scaffolding compiled. Awaiting Task 2 —
                custom device nodes + palette.
            </p>
        </div>
    );
}

const mount = document.getElementById('schematic-spike-root');
if (mount) {
    createRoot(mount).render(<SpikeStub />);
}
