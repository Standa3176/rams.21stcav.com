// ═══════════════════════════════════════════════════════════════
// SchematicEditor — top-level canvas component for the spike.
// ─────────────────────────────────────────────────────────────
// Task 2 scope: mount React Flow, wire the palette drag-drop,
// render dropped devices with typed ports. Validation, auto-arrange,
// toolbar and localStorage all land in Task 3.
// ═══════════════════════════════════════════════════════════════
import React, { useCallback, useMemo, useRef, useState } from 'react';
import {
    ReactFlow,
    Background,
    Controls,
    MiniMap,
    ReactFlowProvider,
    useReactFlow,
    applyNodeChanges,
    applyEdgeChanges,
} from '@xyflow/react';
import '@xyflow/react/dist/style.css';

import DevicePalette, { DEVICE_DRAG_MIME } from './DevicePalette.jsx';
import DeviceNode from './DeviceNode.jsx';
import { findDevice } from '../devices.js';

// Registered once, referenced by node.type = 'device'.
const NODE_TYPES = { device: DeviceNode };

// Bump when we later add nodes we can't render from the current
// devices catalog — restore path should refuse an old version.
let nextNodeId = 1;
function makeNodeId() {
    return `n${nextNodeId++}`;
}

function SchematicEditorInner() {
    const [nodes, setNodes] = useState([]);
    const [edges, setEdges] = useState([]);
    const wrapperRef = useRef(null);
    const { screenToFlowPosition } = useReactFlow();

    const onNodesChange = useCallback(
        changes => setNodes(ns => applyNodeChanges(changes, ns)),
        [],
    );
    const onEdgesChange = useCallback(
        changes => setEdges(es => applyEdgeChanges(changes, es)),
        [],
    );

    // ── HTML5 drag-and-drop from palette ─────────────────────
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

        const position = screenToFlowPosition({
            x: e.clientX,
            y: e.clientY,
        });

        setNodes(ns => ns.concat({
            id: makeNodeId(),
            type: 'device',
            position,
            data: { device, catalogId },
        }));
    }, [screenToFlowPosition]);

    // Onconnect placeholder — Task 3 wires the port validator.
    const onConnect = useCallback(() => {
        // no-op in Task 2; Task 3 replaces this with validated onConnect
    }, []);

    const nodeTypes = useMemo(() => NODE_TYPES, []);

    return (
        <div style={{
            display: 'flex',
            height: '100%',
            width: '100%',
            fontFamily: 'system-ui, sans-serif',
        }}>
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
