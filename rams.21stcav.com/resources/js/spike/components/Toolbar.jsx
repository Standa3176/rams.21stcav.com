// ═══════════════════════════════════════════════════════════════
// Toolbar — top action bar sitting above the React Flow viewport.
// ─────────────────────────────────────────────────────────────
// Buttons: 🎯 Auto-arrange · ↶ Undo · ↷ Redo · 💾 Save · 🗑 Clear · 📋 Copy JSON
// ═══════════════════════════════════════════════════════════════
import React from 'react';

function ToolbarBtn({ onClick, disabled, children, title }) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            title={title}
            style={{
                padding: '6px 12px',
                borderRadius: 6,
                border: '1px solid #CBD5E1',
                background: disabled ? '#F1F5F9' : '#FFFFFF',
                color: disabled ? '#94A3B8' : '#0F172A',
                fontFamily: 'system-ui, sans-serif',
                fontSize: 13,
                fontWeight: 500,
                cursor: disabled ? 'not-allowed' : 'pointer',
                transition: 'background 150ms, border-color 150ms',
            }}
            onMouseEnter={e => {
                if (!disabled) e.currentTarget.style.background = '#F1F5F9';
            }}
            onMouseLeave={e => {
                if (!disabled) e.currentTarget.style.background = '#FFFFFF';
            }}
        >
            {children}
        </button>
    );
}

export default function Toolbar({
    onAutoArrange, onUndo, onRedo, onSave, onClear, onCopy,
    canUndo, canRedo, nodeCount, edgeCount, savedAt,
}) {
    return (
        <div style={{
            display: 'flex',
            alignItems: 'center',
            gap: 8,
            padding: '8px 12px',
            background: '#FFFFFF',
            borderBottom: '1px solid #E2E8F0',
            flexWrap: 'wrap',
            fontFamily: 'system-ui, sans-serif',
        }}>
            <ToolbarBtn onClick={onAutoArrange} title="Auto-arrange in 3 columns">
                🎯 Auto-arrange
            </ToolbarBtn>
            <ToolbarBtn onClick={onUndo} disabled={!canUndo} title="Undo (last change)">
                ↶ Undo
            </ToolbarBtn>
            <ToolbarBtn onClick={onRedo} disabled={!canRedo} title="Redo">
                ↷ Redo
            </ToolbarBtn>
            <ToolbarBtn onClick={onSave} title="Save now to localStorage (auto-saves too)">
                💾 Save
            </ToolbarBtn>
            <ToolbarBtn onClick={onClear} title="Clear the whole canvas">
                🗑 Clear
            </ToolbarBtn>
            <ToolbarBtn onClick={onCopy} title="Copy canvas JSON to clipboard">
                📋 Copy JSON
            </ToolbarBtn>
            <div style={{ marginLeft: 'auto', fontSize: 12, color: '#64748B' }}>
                {nodeCount} devices · {edgeCount} connections
                {savedAt && <> · saved {savedAt}</>}
            </div>
        </div>
    );
}
