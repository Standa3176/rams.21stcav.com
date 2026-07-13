@extends('layouts.app')

@section('title', 'Schematic Editor Spike')

@section('content')
    {{-- ── Inline walkthrough — 3 scenarios, ~30 min hands-on ─────────
         Sits ABOVE the React canvas so someone hitting the page for the
         first time knows what to try. Collapsed by default so it doesn't
         eat vertical space once the engineer knows the drill. --}}
    <details style="margin: 8px 12px; padding: 10px 14px; background: #F0F5FF; border: 1px solid #DCE9FF; border-radius: 6px; font-family: system-ui, sans-serif; font-size: 13px; color: #0F172A;">
        <summary style="cursor: pointer; font-weight: 600;">
            🧪 Try These 3 Scenarios (30 min hands-on) — 2-week review deadline 2026-07-27
        </summary>
        <div style="margin-top: 8px; line-height: 1.55;">
            <p style="margin-bottom: 6px;"><strong>Scenario 1 — Build the Tilda boardroom</strong></p>
            <ul style="margin: 0 0 10px 20px; padding: 0;">
                <li>Drag QM85 display + Room Bar Pro + Shure mic + Q-Sys DSP + Netgear switch onto canvas.</li>
                <li>Connect: <em>HDMI</em>: Room Bar → Display · <em>Dante</em>: Shure → Q-Sys (via switch) · Q-Sys → Room Bar (via switch) · <em>PoE</em>: switch → Room Bar.</li>
                <li>Hit 🎯 Auto-arrange.</li>
                <li>Verify: clean left-right signal flow, sources on left, destinations on right.</li>
            </ul>

            <p style="margin-bottom: 6px;"><strong>Scenario 2 — Try an invalid connection</strong></p>
            <ul style="margin: 0 0 10px 20px; padding: 0;">
                <li>Drag from Display's HDMI-in to Shure's Dante network port.</li>
                <li>Expected: rejection + red port flash + toast "video-in cannot connect to network-out".</li>
                <li>Also try Room Bar HDMI-out → Q-Sys Dante — should reject.</li>
                <li>Bonus: connect Room Bar USB-C → Display HDMI — should PASS via the USB-C↔HDMI adapter alias, with an info toast.</li>
            </ul>

            <p style="margin-bottom: 6px;"><strong>Scenario 3 — Persistence</strong></p>
            <ul style="margin: 0 0 10px 20px; padding: 0;">
                <li>Refresh the page — canvas restores from browser localStorage.</li>
                <li>Click 📋 Copy JSON to see the serialised canvas state.</li>
                <li>Click 🗑 Clear then ↶ Undo — should restore all devices + edges.</li>
            </ul>

            <p style="margin-bottom: 6px;"><strong>What to answer after 30 minutes</strong></p>
            <ol style="margin: 0 0 0 20px; padding: 0;">
                <li>Does drag-to-connect feel natural for AV engineers?</li>
                <li>Is port-type validation catching enough errors without being annoying?</li>
                <li>Does auto-arrange produce a usable schematic starting point?</li>
                <li><strong>Would you commit to a 6-month build on this foundation?</strong></li>
            </ol>
            <p style="margin-top: 10px; color: #64748B; font-size: 12px;">
                Kill-switch: set <code>SPIKE_SCHEMATIC_ENABLED=false</code> to hide.
                All spike surface is under <code>spike/*</code> — one <code>rm -rf</code>
                deletes it.
            </p>
        </div>
    </details>

    <div id="schematic-spike-root" style="height: calc(100vh - 60px - 90px); width: 100%;"></div>
    @vite(['resources/js/spike/main.jsx'])
@endsection
