// ═══════════════════════════════════════════════════════════════
// Schematic Editor Spike — React root mount.
// ─────────────────────────────────────────────────────────────
// Isolated JSX bundle, only loaded on /spike/schematic-editor.
// Mounts <SchematicEditor /> into #schematic-spike-root.
// ═══════════════════════════════════════════════════════════════
import React from 'react';
import { createRoot } from 'react-dom/client';
import SchematicEditor from './components/SchematicEditor.jsx';

const mount = document.getElementById('schematic-spike-root');
if (mount) {
    createRoot(mount).render(<SchematicEditor />);
}
