// ═══════════════════════════════════════════════════════════════
// SchematicEditor — top-level canvas component for the spike.
// ─────────────────────────────────────────────────────────────
// Task 3 wires:
//   - Port-type validated onConnect (rejects + red flash + toast)
//   - Undo/Redo via [past, present, future] history stack (cap 50)
//   - localStorage auto-save (debounced 500ms) + restore-on-mount
//   - Toolbar: auto-arrange, undo, redo, save, clear, copy JSON
//
// Naming — id::source and id::target come from DeviceNode. We split
// on '::' to recover the raw port id when validating.
// ═══════════════════════════════════════════════════════════════
import React, {
    useCallback, useEffect, useMemo, useRef, useState,
} from 'react';
import {
    ReactFlow,
    Background,
    Controls,
    MiniMap,
    ReactFlowProvider,
    useReactFlow,
    applyNodeChanges,
    applyEdgeChanges,
    addEdge,
} from '@xyflow/react';
import '@xyflow/react/dist/style.css';

import DevicePalette, { DEVICE_DRAG_MIME } from './DevicePalette.jsx';
import DeviceNode from './DeviceNode.jsx';
import Toolbar from './Toolbar.jsx';
import { findDevice, findPort } from '../devices.js';
import { isValidConnection } from '../validation.js';
import { autoArrange } from '../autoArrange.js';
import { signalColour } from '../signalColours.js';

const NODE_TYPES = { device: DeviceNode };

const STORAGE_KEY = 'spike-schematic-canvas';
const STORAGE_VERSION = 1;
const HISTORY_CAP = 50;
const AUTOSAVE_DEBOUNCE_MS = 500;

// ── ID counter — bumped when we spawn or restore nodes so we
//    never collide with an existing id.
let nextNodeId = 1;
function makeNodeId() {
    return `n${nextNodeId++}`;
}
function reserveIdSpaceFor(nodes) {
    nodes.forEach(n => {
        const m = /^n(\d+)$/.exec(n.id);
        if (m) nextNodeId = Math.max(nextNodeId, parseInt(m[1], 10) + 1);
    });
}

// ── Split a React Flow handle id back into raw port id ──────
function portIdFromHandle(handleId) {
    if (!handleId) return null;
    return handleId.split('::')[0];
}

// ── Inline CSS: red-flash class for invalid drop target ─────
const SPIKE_CSS = `
.spike-port-invalid {
    animation: spikePortFlash 600ms ease-out;
}
@keyframes spikePortFlash {
    0%   { box-shadow: 0 0 0 6px rgba(239, 68, 68, .7); background-color: #EF4444 !important; }
    100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0);   }
}
`;

function SchematicEditorInner() {
    // ── Present state ───────────────────────────────────────
    const [nodes, setNodes] = useState([]);
    const [edges, setEdges] = useState([]);

    // ── History stacks — snapshot { nodes, edges } ─────────
    const pastRef = useRef([]);   // undo stack
    const futureRef = useRef([]); // redo stack
    const [, forceHistoryTick] = useState(0); // re-render toolbar button-disabled state
    const bumpHistoryTick = useCallback(() => forceHistoryTick(t => t + 1), []);

    const [toast, setToast] = useState(null); // { message, tone }
    const [savedAt, setSavedAt] = useState(null);

    const wrapperRef = useRef(null);
    const { screenToFlowPosition } = useReactFlow();

    // Snapshot the current present state onto the undo stack, clearing redo.
    // Only called at commit points (drop, connect, delete, auto-arrange, clear).
    const pushHistory = useCallback((current) => {
        pastRef.current.push(current);
        if (pastRef.current.length > HISTORY_CAP) {
            pastRef.current.shift();
        }
        futureRef.current = [];
        bumpHistoryTick();
    }, [bumpHistoryTick]);

    // ── Node / edge changes ─────────────────────────────────
    // React Flow fires onNodesChange constantly (drag positions,
    // selection). We DON'T snapshot every keystroke — only on
    // remove events, which are user-visible edits.
    const onNodesChange = useCallback(changes => {
        setNodes(ns => {
            const removes = changes.filter(c => c.type === 'remove');
            if (removes.length > 0) {
                pushHistory({ nodes: ns, edges });
            }
            return applyNodeChanges(changes, ns);
        });
    }, [edges, pushHistory]);

    const onEdgesChange = useCallback(changes => {
        setEdges(es => {
            const removes = changes.filter(c => c.type === 'remove');
            if (removes.length > 0) {
                pushHistory({ nodes, edges: es });
            }
            return applyEdgeChanges(changes, es);
        });
    }, [nodes, pushHistory]);

    // ── Validated connect ───────────────────────────────────
    const flashPortRed = useCallback((handleId) => {
        // Handles carry data-port-id (raw port id) on the DOM element.
        // We flash every handle in the canvas that matches the raw port id
        // so both source+target twin handles for bidi flash together.
        const portId = portIdFromHandle(handleId);
        if (!portId) return;
        const els = document.querySelectorAll(`[data-port-id="${portId}"]`);
        els.forEach(el => {
            el.classList.remove('spike-port-invalid');
            // Trigger reflow so the animation restarts cleanly on rapid retries.
            void el.offsetWidth;
            el.classList.add('spike-port-invalid');
            setTimeout(() => el.classList.remove('spike-port-invalid'), 700);
        });
    }, []);

    const showToast = useCallback((message, tone = 'error') => {
        setToast({ message, tone });
        setTimeout(() => setToast(t => (t?.message === message ? null : t)), 3000);
    }, []);

    const onConnect = useCallback(params => {
        // Look up both port shapes from the catalog via node.data.catalogId.
        const sourceNode = nodes.find(n => n.id === params.source);
        const targetNode = nodes.find(n => n.id === params.target);
        const sourcePortId = portIdFromHandle(params.sourceHandle);
        const targetPortId = portIdFromHandle(params.targetHandle);
        const sourcePort = sourceNode && findPort(sourceNode.data.catalogId, sourcePortId);
        const targetPort = targetNode && findPort(targetNode.data.catalogId, targetPortId);

        const result = isValidConnection(sourcePort, targetPort);
        if (!result.valid) {
            flashPortRed(params.targetHandle);
            showToast(result.reason, 'error');
            return;
        }

        // Style the edge by the source signal type so signal flow reads
        // at a glance (video edges blue, audio red, etc).
        const stroke = signalColour(sourcePort.signal_type);
        const newEdge = {
            ...params,
            id: `e-${params.source}-${params.sourceHandle}-${params.target}-${params.targetHandle}`,
            animated: true,
            style: { stroke, strokeWidth: 2 },
            data: {
                signal_type: sourcePort.signal_type,
                note: result.note ?? null,
            },
        };

        pushHistory({ nodes, edges });
        setEdges(es => addEdge(newEdge, es));

        if (result.note) {
            showToast(`Adapter connection: ${result.note}`, 'info');
        }
    }, [nodes, edges, pushHistory, flashPortRed, showToast]);

    // ── HTML5 drag-and-drop from palette ────────────────────
    const onDragOver = useCallback(e => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'copy';
    }, []);

    const onDrop = useCallback(e => {
        e.preventDefault();
        const catalogId = e.dataTransfer.getData(DEVICE_DRAG_MIME)
            || e.dataTransfer.getData('text/plain');
        if (!catalogId) return;
        const device = findDevice(catalogId);
        if (!device) return;

        const position = screenToFlowPosition({ x: e.clientX, y: e.clientY });
        pushHistory({ nodes, edges });
        setNodes(ns => ns.concat({
            id: makeNodeId(),
            type: 'device',
            position,
            data: { device, catalogId },
        }));
    }, [screenToFlowPosition, pushHistory, nodes, edges]);

    // ── Toolbar handlers ────────────────────────────────────
    const handleAutoArrange = useCallback(() => {
        if (nodes.length === 0) return;
        pushHistory({ nodes, edges });
        setNodes(ns => autoArrange(ns));
    }, [nodes, edges, pushHistory]);

    const handleUndo = useCallback(() => {
        const prev = pastRef.current.pop();
        if (!prev) return;
        futureRef.current.push({ nodes, edges });
        setNodes(prev.nodes);
        setEdges(prev.edges);
        bumpHistoryTick();
    }, [nodes, edges, bumpHistoryTick]);

    const handleRedo = useCallback(() => {
        const next = futureRef.current.pop();
        if (!next) return;
        pastRef.current.push({ nodes, edges });
        setNodes(next.nodes);
        setEdges(next.edges);
        bumpHistoryTick();
    }, [nodes, edges, bumpHistoryTick]);

    const handleClear = useCallback(() => {
        if (nodes.length === 0 && edges.length === 0) return;
        // eslint-disable-next-line no-alert
        if (!window.confirm('Clear the whole canvas? This can be undone.')) return;
        pushHistory({ nodes, edges });
        setNodes([]);
        setEdges([]);
    }, [nodes, edges, pushHistory]);

    const persist = useCallback((n, e) => {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify({
                version: STORAGE_VERSION,
                nodes: n.map(({ id, position, data }) => ({
                    id,
                    position,
                    catalogId: data.catalogId,
                })),
                edges: e,
                savedAt: new Date().toISOString(),
            }));
            const stamp = new Date().toLocaleTimeString();
            setSavedAt(stamp);
            return true;
        } catch (err) {
            // eslint-disable-next-line no-console
            console.warn('SchematicSpike: localStorage save failed', err);
            return false;
        }
    }, []);

    const handleSave = useCallback(() => {
        if (persist(nodes, edges)) {
            showToast('Saved to browser storage', 'info');
        } else {
            showToast('Save failed — see console', 'error');
        }
    }, [nodes, edges, persist, showToast]);

    const handleCopy = useCallback(async () => {
        const payload = {
            version: STORAGE_VERSION,
            nodes: nodes.map(({ id, position, data }) => ({
                id,
                position,
                catalogId: data.catalogId,
            })),
            edges,
        };
        try {
            await navigator.clipboard.writeText(JSON.stringify(payload, null, 2));
            showToast('Canvas JSON copied to clipboard', 'info');
        } catch (err) {
            // eslint-disable-next-line no-console
            console.warn('SchematicSpike: clipboard write failed', err);
            showToast('Clipboard copy failed — see console', 'error');
        }
    }, [nodes, edges, showToast]);

    // ── Debounced auto-save ─────────────────────────────────
    useEffect(() => {
        const timer = setTimeout(() => persist(nodes, edges),
            AUTOSAVE_DEBOUNCE_MS);
        return () => clearTimeout(timer);
    }, [nodes, edges, persist]);

    // ── Restore on mount ────────────────────────────────────
    useEffect(() => {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) return;
            const parsed = JSON.parse(raw);
            if (parsed.version !== STORAGE_VERSION) return;
            const restoredNodes = (parsed.nodes ?? [])
                .map(saved => {
                    const device = findDevice(saved.catalogId);
                    if (!device) return null;
                    return {
                        id: saved.id,
                        type: 'device',
                        position: saved.position,
                        data: { device, catalogId: saved.catalogId },
                    };
                })
                .filter(Boolean);
            reserveIdSpaceFor(restoredNodes);
            setNodes(restoredNodes);
            setEdges(parsed.edges ?? []);
        } catch (err) {
            // eslint-disable-next-line no-console
            console.warn('SchematicSpike: restore failed', err);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const nodeTypes = useMemo(() => NODE_TYPES, []);

    return (
        <div style={{
            display: 'flex',
            flexDirection: 'column',
            height: '100%',
            width: '100%',
            fontFamily: 'system-ui, sans-serif',
        }}>
            <style>{SPIKE_CSS}</style>
            <Toolbar
                onAutoArrange={handleAutoArrange}
                onUndo={handleUndo}
                onRedo={handleRedo}
                onSave={handleSave}
                onClear={handleClear}
                onCopy={handleCopy}
                canUndo={pastRef.current.length > 0}
                canRedo={futureRef.current.length > 0}
                nodeCount={nodes.length}
                edgeCount={edges.length}
                savedAt={savedAt}
            />
            <div style={{ display: 'flex', flex: 1, minHeight: 0 }}>
                <DevicePalette />
                <div
                    ref={wrapperRef}
                    style={{ flex: 1, minWidth: 0, position: 'relative' }}
                    onDrop={onDrop}
                    onDragOver={onDragOver}
                >
                    <ReactFlow
                        nodes={nodes}
                        edges={edges}
                        onNodesChange={onNodesChange}
                        onEdgesChange={onEdgesChange}
                        onConnect={onConnect}
                        nodeTypes={nodeTypes}
                        fitView
                        minZoom={0.25}
                        maxZoom={2}
                        deleteKeyCode={['Backspace', 'Delete']}
                    >
                        <Background gap={16} size={1} color="#E2E8F0" />
                        <Controls position="bottom-right" showInteractive={false} />
                        <MiniMap
                            pannable
                            zoomable
                            maskColor="rgba(15, 23, 42, .06)"
                            nodeColor={n => {
                                const d = n.data?.device;
                                if (d?.role === 'source')      return '#DBEAFE';
                                if (d?.role === 'processor')   return '#EDE9FE';
                                if (d?.role === 'destination') return '#D1FAE5';
                                return '#F1F5F9';
                            }}
                        />
                    </ReactFlow>
                    {nodes.length === 0 && (
                        <div style={{
                            position: 'absolute',
                            inset: 0,
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            pointerEvents: 'none',
                            color: '#94A3B8',
                            fontSize: 14,
                        }}>
                            Drag a device from the left panel to start.
                        </div>
                    )}
                    {toast && (
                        <div style={{
                            position: 'absolute',
                            top: 12, right: 12,
                            maxWidth: 380,
                            padding: '10px 14px',
                            borderRadius: 6,
                            fontSize: 13,
                            lineHeight: 1.4,
                            color: '#FFFFFF',
                            background: toast.tone === 'error' ? '#B91C1C' : '#0F172A',
                            boxShadow: '0 4px 12px rgba(15, 23, 42, .18)',
                            zIndex: 20,
                        }}>
                            {toast.message}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

export default function SchematicEditor() {
    return (
        <ReactFlowProvider>
            <SchematicEditorInner />
        </ReactFlowProvider>
    );
}
