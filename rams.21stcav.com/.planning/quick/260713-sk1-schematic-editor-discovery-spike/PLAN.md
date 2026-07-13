---
name: 260713-sk1-schematic-editor-discovery-spike
description: React Flow discovery spike — 2-week test of drag-to-connect + port-type validation + auto-arrange for AV schematic editor. Admin-only, feature-flagged, localStorage-only. Throwaway if it doesn't feel right.
status: in-progress
tasks: 4
---

# Schematic Editor Discovery Spike

## Why

The current `projects/{id}/drawings` surface is a **generation pipeline** (D2 auto-render → PDF/SVG/PNG downloads). It's not a design tool. To pivot RAMS toward XTEN-AV / Stardraw / D-Tools SI territory (AV SaaS), we need an **interactive editor** — engineer drags devices, connects ports, sees signal flow.

This spike proves the smallest viable slice of that editor:
- **5 hardcoded devices** (Sony QM85, Cisco Room Bar Pro, Shure MXW, QSC Q-Sys Core 110f, Netgear GS308EPP)
- **Drag-to-connect** with port-type validation (video-out → video-in only, respecting `config/cables.php` compatibility aliases)
- **One auto-arrange algorithm** (source column → processor column → destination column, sorted by signal type)
- **localStorage-only persistence** — no DB, no controllers

## Success Criteria

After the spike ships and gets 30 minutes of hands-on use, we can confidently answer:

1. Does drag-to-connect feel natural for AV engineers?
2. Is port-type validation catching enough errors without being annoying?
3. Does auto-arrange produce a usable schematic starting point?
4. **Would you commit to a 6-month build on this foundation?**

If any answer is a firm "no", we kill the branch. If all four lean "yes", we commit to Milestone A (global device library + real editor v1 + DB persistence + export).

## Non-Goals (Out of Scope)

- Multi-sheet management (one canvas per session)
- DB persistence (localStorage only — refresh survives, cross-device does not)
- Real device library (5 hardcoded devices in JS, not pulled from `devices` table)
- Export to PNG/PDF/SVG (that's Milestone C)
- Multi-user collab, permissions, versioning
- Room/rack/power sheets (separate concerns)

## Kill-Switch

- `SPIKE_SCHEMATIC_ENABLED=false` in `.env` → route returns 404 + Admin dropdown link hidden.
- Default: **off** in production. Set to `true` post-deploy to unlock.
- Whole spike is namespaced under `spike/*` (routes, views, JS) — one `rm -rf` operation removes it entirely.

## 2-Week Review Deadline

By **2026-07-27**, decide:
- (a) Commit to Milestone A build (device library + editor v1 + DB persist + export)
- (b) Delete this branch + rethink the drawing product

Do NOT let this rot as half-finished production code. The whole point of the spike is a firm yes/no decision.

---

## Task Breakdown

### Task 1 — Infrastructure

Install React + React Flow, wire Vite for the spike JSX bundle, create admin-gated route + Blade shell.

**Files created:**
- `resources/views/spike/canvas.blade.php` — extends `layouts.app`, contains `<div id="schematic-spike-root"></div>` + `@vite('resources/js/spike/main.jsx')`
- `resources/js/spike/main.jsx` — React root mount, top-level `<SchematicEditor />` component (stub)
- `app/Http/Controllers/SpikeSchematicController.php` — single `show()` action, admin-gated + feature-flagged

**Files modified:**
- `package.json` — add `@xyflow/react` (React Flow v12, MIT), `react`, `react-dom`, `@vitejs/plugin-react`, `dagre`, `@dagrejs/dagre` (whichever the docs recommend)
- `vite.config.js` — add `@vitejs/plugin-react` plugin, add `resources/js/spike/main.jsx` to `input` array
- `routes/web.php` — add `Route::get('spike/schematic-editor', [SpikeSchematicController::class, 'show'])->middleware(['auth'])->name('spike.schematic.editor')` inside the auth group
- `resources/views/layouts/navigation.blade.php` — add "🧪 Schematic Spike" item to Admin dropdown, gated by `@if(config('services.spike_schematic_enabled'))`
- `config/services.php` — add `'spike_schematic_enabled' => env('SPIKE_SCHEMATIC_ENABLED', false)`

**Controller admin-gate:**
```php
public function show()
{
    abort_unless(config('services.spike_schematic_enabled'), 404);
    abort_unless(auth()->user()?->isAdmin(), 403);
    return view('spike.canvas');
}
```

**Gates:**
- `php artisan route:list | grep spike` shows the route
- `npm run build` succeeds without errors
- Loading `/spike/schematic-editor` in a browser with `SPIKE_SCHEMATIC_ENABLED=true` renders the blank React root (stub component "🧪 Schematic Editor Spike")
- Loading same route with flag OFF returns 404
- Non-admin user hitting the URL with flag ON returns 403

**Commit:** `spike(schematic): infra — React + React Flow + Vite plugin + admin-gated route (260713-sk1)`

---

### Task 2 — Custom device nodes + palette

Build the 5 hardcoded device catalog + custom React Flow node component with visible port strips.

**Files created:**
- `resources/js/spike/devices.js` — const array of 5 devices with role tag + ports array
- `resources/js/spike/components/DeviceNode.jsx` — custom React Flow node component rendering device card + port dots via `<Handle>` primitives
- `resources/js/spike/components/DevicePalette.jsx` — left-side sidebar showing 5 device cards, drag-to-canvas
- `resources/js/spike/components/SchematicEditor.jsx` — main canvas component, expands from Task 1 stub

**Device catalog (`devices.js`):**
Each device: `{ id, manufacturer, model, role: 'source'|'processor'|'destination', ports: [{ id, label, connector_type, signal_type: 'video'|'audio'|'control'|'network'|'usb', direction: 'in'|'out'|'bidi', side: 'top'|'right'|'bottom'|'left' }] }`

1. **Sony QM85 4K Display** — role: destination — ports: `hdmi-in-1` (video/in/left), `hdmi-in-2` (video/in/left), `rs232-in` (control/in/right)
2. **Cisco Room Bar Pro** — role: processor — ports: `hdmi-out` (video/out/right), `hdmi-in` (video/in/left), `usb-c-out` (usb/out/right), `rj45-poe` (network/bidi/bottom)
3. **Shure MXW Wireless Mic** — role: source — ports: `dante-out` (network/out/right, connector_type=rj45, signal_type=audio via dante)
4. **QSC Q-Sys Core 110f DSP** — role: processor — ports: `dante-1` through `dante-4` (network/bidi/left+right, rj45), `line-out-1`/`line-out-2` (audio/out/right, connector_type=trs)
5. **Netgear GS308EPP PoE Switch** — role: destination (for network aggregation) — ports: 8× `rj45-poe-N` (network/bidi/mixed sides)

**Port rendering:**
- Port dots labeled with `connector_type` (e.g. "HDMI", "RJ45", "TRS")
- Signal-type colour ring around port dot, using `signal_type_colours` from `config/cables.php`:
  - audio: `#C0392B`
  - video: `#2980B9`
  - control: `#27AE60`
  - network: `#8E44AD`
  - usb: `#E67E22`

  Hardcode these into `resources/js/spike/signalColours.js` for spike simplicity (don't fetch from server).
- `direction`: `in` = filled dot, `out` = ring only, `bidi` = half-filled

**Palette:**
Left sidebar 240px wide, list of 5 device cards. Each card: `<div draggable onDragStart={e => e.dataTransfer.setData('application/schematic-device', device.id)}>`. On canvas drop, spawn a new node.

**Gates:**
- Loading `/spike/schematic-editor` shows 5 devices in left palette
- Dragging a device onto canvas creates a node with all its ports visible
- Each port has correct colour ring + label

**Commit:** `spike(schematic): 5 devices + custom nodes with typed ports (260713-sk1)`

---

### Task 3 — Port-type validation + auto-arrange + toolbar + localStorage

Wire the drag-to-connect validation, auto-arrange algorithm, toolbar, and localStorage persistence.

**Files created:**
- `resources/js/spike/validation.js` — `isValidConnection(sourcePort, targetPort, aliases)` returning `{ valid: boolean, reason?: string }`
- `resources/js/spike/autoArrange.js` — takes `nodes[]` returns `nodes[]` with new x/y positions (column-based)
- `resources/js/spike/components/Toolbar.jsx` — the top toolbar with buttons
- `resources/js/spike/compatibilityAliases.js` — hardcoded copy of `config/cables.php` compatibility_aliases for spike (don't fetch from server — hardcoded is fine for spike)

**Validation rules (in `validation.js`):**
- Signal-type must match (`video → video`, `audio → audio`, `control → control`, `network → network`, `usb → usb`)
- OR the (from_connector, to_connector) pair is in `compatibility_aliases`
- Direction: `out → in` only, or `bidi ↔ bidi/in/out`
- On invalid: return `{ valid: false, reason: 'Cannot connect video-out (HDMI) to audio-in (XLR) — signal types differ' }`
- React Flow's `onConnect` handler calls `isValidConnection` — if valid, add edge; if not, flash target port red for 600ms + display an in-app toast (native `<div>` in Toolbar area, not Alpine).

**Auto-arrange algorithm (in `autoArrange.js`):**
Simple 3-column layout, no dagre needed (spike simplicity):
```
sources (x=0)      processors (x=350)     destinations (x=700)
  [Shure]           [Room Bar]              [QM85]
                    [Q-Sys DSP]             [Netgear]
```
Within each column, sort by dominant signal type of output ports: video first, audio second, control third, network fourth. Space vertically 200px apart.

If we want proper dagre-based layout instead — install `@dagrejs/dagre`, use it. Column-based is fine for the spike; the aim is "does auto-arrange feel useful," not "is it perfect."

**Toolbar buttons:**
`🎯 Auto-arrange · ↶ Undo · ↷ Redo · 💾 Save · 🗑 Clear canvas · 📋 Copy JSON`

- 🎯 Auto-arrange → runs `autoArrange(nodes)`, updates positions
- ↶ / ↷ → React Flow's built-in undo/redo via `useReactFlow()` history OR a simple stack in state (`[past, present, future]`)
- 💾 Save → writes `{ nodes, edges }` to `localStorage['spike-schematic-canvas']` (also runs automatically on every change, debounced 500ms)
- 🗑 Clear canvas → confirm dialog → clear nodes/edges
- 📋 Copy JSON → `navigator.clipboard.writeText(JSON.stringify({nodes, edges}, null, 2))`

**localStorage persistence:**
- On every `onNodesChange`/`onEdgesChange`, debounced 500ms, write current state to `localStorage['spike-schematic-canvas']`
- On mount, if `localStorage['spike-schematic-canvas']` exists, restore into React Flow state
- Version stamp: store `{ version: 1, nodes, edges }` — bump if we later change device schema

**Gates:**
- Drag from `Room Bar hdmi-out` to `QM85 hdmi-in-1` → connection created (green edge)
- Drag from `Room Bar hdmi-out` to `Shure dante-out` → rejected + red port flash + toast "Cannot connect video-out to network-out — direction mismatch"
- Click auto-arrange → devices snap into 3 columns
- Refresh page → canvas restores to previous state
- Copy JSON → valid JSON in clipboard

**Commit:** `spike(schematic): port-type validation + auto-arrange + toolbar + localStorage (260713-sk1)`

---

### Task 4 — Spike README + inline walkthrough + STATE.md update

Ship the walkthrough doc + inline scenarios so anyone opening the page for the first time knows what to try.

**Files created:**
- `.planning/spikes/schematic-editor-260713/README.md` — full spike doc (already contains most content, this task just finalizes it)
- Update `resources/views/spike/canvas.blade.php` — inline collapsible `<details>` block at top with the 3 test scenarios

**Files modified:**
- `.planning/STATE.md` — add row to Quick Tasks Completed table
- `.planning/quick/260713-sk1-schematic-editor-discovery-spike/SUMMARY.md` — final task summary (status: complete)

**Inline canvas.blade.php walkthrough (collapsible `<details>`):**

```
<details>
  <summary>🧪 Try These 3 Scenarios (30 min hands-on)</summary>

  Scenario 1 — Build the Tilda boardroom
  - Drag QM85 display + Room Bar Pro + Shure mic + Q-Sys DSP + Netgear switch onto canvas
  - Connect: HDMI: Room Bar → Display · Dante: Shure → Q-Sys (via switch) · Q-Sys → Room Bar (via switch) · PoE: switch → Room Bar
  - Hit 🎯 Auto-arrange
  - Verify: clean left-right signal flow

  Scenario 2 — Try an invalid connection
  - Drag from Display's HDMI-in to Shure's Dante network port
  - Expect: rejection + red port flash + toast "video-in cannot connect to network-out"

  Scenario 3 — Persistence
  - Refresh the page
  - Canvas restores from localStorage

  What to answer after 30 minutes:
  1. Does drag-to-connect feel natural?
  2. Is validation catching errors without being annoying?
  3. Does auto-arrange produce a usable schematic?
  4. Would you commit to a 6-month build on this foundation?
</details>
```

**STATE.md row:**
`| 260713-sk1 | Schematic editor discovery spike | 2026-07-13 | ✓ | React Flow spike shipped: 5 devices, port-type validation, auto-arrange, localStorage. Feature-flagged (SPIKE_SCHEMATIC_ENABLED). 2-week review deadline: 2026-07-27. |`

**Gates:**
- README.md complete + covers all spike scope
- STATE.md row present
- SUMMARY.md status=complete

**Commit:** `docs(spike-260713-sk1): README + walkthrough + STATE + SUMMARY`

---

## Constraints (Global — apply to all 4 tasks)

- **Spike, not production.** No PHPUnit tests. No feature tests. Smoke check only: route loads, 5 devices render.
- **Everything under `spike/*` namespace** — routes, views, JS. Easy to delete.
- **Kill-switch works.** `SPIKE_SCHEMATIC_ENABLED=false` → 404 + link hidden.
- **No changes to existing Alpine.js code.** Existing app.js untouched.
- **`php -l`** after every .php file edit.
- **`npm run build`** must succeed before every commit that touches JS/Vite.
- **Commit prefix:** `spike(schematic):` — signals throwaway status.
- **React Flow bundle isolated** — only loaded on `/spike/schematic-editor` route. No global bundle bloat.
- **CLAUDE.md compliance:** thin controller (SpikeSchematicController is 5 lines), no service layer needed for the spike, no DB schema.

## Deploy Notes (for SUMMARY.md)

- **NO migrations required.**
- Deploy: `git pull` → `npm ci` → `npm run build` → `php artisan config:cache` → `php artisan view:clear`
- Post-deploy: set `SPIKE_SCHEMATIC_ENABLED=true` in live `.env` to unlock. Default off.
- Access: `/spike/schematic-editor` — admin-only.
- **2-week review deadline: 2026-07-27** — commit or delete.
