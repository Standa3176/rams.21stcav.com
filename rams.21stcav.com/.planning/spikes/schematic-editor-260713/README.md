# Schematic Editor Discovery Spike (260713-sk1)

**Status:** shipped 2026-07-13 · **Review deadline:** 2026-07-27 · **Branch:** `feat/worksheet-classifier-universal`

React Flow discovery spike — a 2-week test of drag-to-connect + port-type validation + auto-arrange to see whether it can carry a real AV schematic editor that moves the platform toward XTEN-AV / Stardraw / D-Tools SI territory.

---

## Why

The current `/projects/{id}/drawings` surface is a **generation pipeline**: D2 auto-renders schematics to PDF/SVG/PNG on demand. It's a downloader, not a design tool. It cannot answer "what if the engineer wants to add a second display" or "move the DSP into the rack" without regenerating from source data. That closes off the design workflow AV pros actually use.

To pivot RAMS toward AV SaaS territory we need an **interactive editor** — an engineer drags devices, connects ports, sees signal flow, spots mistakes. This spike proves the smallest viable slice of that editor before we commit 6+ months to a full build.

Key questions we want to answer after 30 minutes of hands-on:

1. Does drag-to-connect feel natural for AV engineers?
2. Is port-type validation catching enough errors without being annoying?
3. Does auto-arrange produce a usable schematic starting point?
4. **Would you commit to a 6-month build on this foundation?**

If any answer is a firm "no", we delete the branch. If all four lean "yes", we commit to Milestone A (global device library + real editor v1 + DB persistence + export to PNG/PDF/SVG).

---

## Scope

**In scope (2 weeks, ~600 lines of JSX + 30 lines PHP):**

- 5 hardcoded devices covering the AV signal chain:
  - Sony QM85 4K Display (destination)
  - Cisco Room Bar Pro (processor)
  - Shure MXW Wireless Mic (source)
  - QSC Q-Sys Core 110f DSP (processor)
  - Netgear GS308EPP PoE Switch (destination / network aggregation)
- Custom React Flow nodes with one `<Handle>` per port. Ports carry
  `connector_type` (hdmi, rj45, trs, rs232, usb-c) + `signal_type`
  (video, audio, control, network, usb) + `direction` (in/out/bidi)
  + `side` (top/right/bottom/left).
- Drag-to-connect from the palette, HTML5 drag-and-drop.
- Port-type validation on `onConnect`:
  - direction check (source emits, target receives)
  - signal-type match, OR
  - connector compatibility alias fallback (HDMI↔DP, USB-C↔TB, RJ45↔SFP+, USB-C↔HDMI)
- Auto-arrange — simple 3-column layout (sources 0px · processors 350px · destinations 700px), sorted within each column by dominant output signal type.
- Toolbar: 🎯 Auto-arrange · ↶ Undo · ↷ Redo · 💾 Save · 🗑 Clear · 📋 Copy JSON.
- Undo/redo via `[past, present, future]` history stack, cap 50.
- localStorage persistence — debounced 500ms auto-save + explicit save + restore-on-mount. Refresh survives; cross-device does not.

**Non-goals (deliberately excluded):**

- Multi-sheet management (one canvas per session)
- DB persistence — localStorage only. No models, no controllers writing state.
- Real device library — 5 hardcoded devices in `resources/js/spike/devices.js`, not pulled from the `devices` table.
- Export to PNG/PDF/SVG — Milestone C concern.
- Multi-user collab, versioning, permissions beyond admin-gate.
- Room / rack / power sheets — separate concerns.

---

## Success Criteria

After the spike ships and gets 30 minutes of hands-on use, we can confidently answer the four questions above. If we can't answer them with confidence, the spike hasn't done its job.

**Non-success:** shipping the spike without touching it. That's why the 2-week deadline is hard.

---

## Kill-Switch

- Feature flag: `SPIKE_SCHEMATIC_ENABLED` env var — default **off**.
- When `false`: route `/spike/schematic-editor` returns 404, admin dropdown link is hidden.
- Set to `true` in `.env` post-deploy on any environment where we want to unlock the spike.
- All surface is namespaced:
  - route: `/spike/schematic-editor`
  - controller: `App\Http\Controllers\SpikeSchematicController`
  - view: `resources/views/spike/canvas.blade.php`
  - JS: `resources/js/spike/*`
  - Vite entry: `resources/js/spike/main.jsx` — isolated bundle, only fetched on the spike route
- Deletion = `git rm` those five paths + remove the vite input + remove the route + remove the nav link + remove the config key. One PR.

---

## Where the Code Lives

```
app/Http/Controllers/SpikeSchematicController.php   # 5-line thin controller
config/services.php                                 # feature-flag key
routes/web.php                                      # /spike/schematic-editor route (auth group)
resources/views/spike/canvas.blade.php              # Blade shell + walkthrough <details>
resources/views/layouts/navigation.blade.php        # admin dropdown link (flag-gated)
resources/js/spike/main.jsx                         # React root mount
resources/js/spike/devices.js                       # 5-device catalog
resources/js/spike/signalColours.js                 # colour map (from config/cables.php)
resources/js/spike/compatibilityAliases.js          # adapter aliases (from config/cables.php)
resources/js/spike/validation.js                    # isValidConnection()
resources/js/spike/autoArrange.js                   # 3-column layout
resources/js/spike/components/DeviceNode.jsx        # custom React Flow node
resources/js/spike/components/DevicePalette.jsx     # left sidebar with drag cards
resources/js/spike/components/Toolbar.jsx           # top action bar
resources/js/spike/components/SchematicEditor.jsx   # canvas wrapper
vite.config.js                                      # @vitejs/plugin-react + spike entry
package.json                                        # react, react-dom, @xyflow/react, @vitejs/plugin-react
```

---

## How to Try It

**Local:**

```bash
git checkout feat/worksheet-classifier-universal
npm ci
npm run build          # or `npm run dev` for HMR
# In .env:
SPIKE_SCHEMATIC_ENABLED=true
php artisan config:clear
```

Then hit `/spike/schematic-editor` while logged in as an admin.

**Deploy to live** (only if we're testing on the shared VPS):

```bash
git pull
npm ci
npm run build
php artisan config:cache
php artisan view:clear
```

Then set `SPIKE_SCHEMATIC_ENABLED=true` in live `.env` to unlock. Default off.

---

## Walkthrough (30 min hands-on)

The Blade shell shows a collapsible walkthrough at the top of the canvas that mirrors this section — you don't need to read the README to know what to try.

### Scenario 1 — Build the Tilda boardroom

- Drag QM85 + Room Bar Pro + Shure mic + Q-Sys DSP + Netgear switch onto the canvas.
- Connect HDMI: Room Bar → Display. Dante: Shure → Q-Sys via switch. Q-Sys → Room Bar via switch. PoE: switch → Room Bar.
- Hit 🎯 Auto-arrange.
- Verify: clean left-right signal flow.

### Scenario 2 — Try an invalid connection

- Drag from Display HDMI-in to Shure Dante-out. Reject + red flash + toast.
- Room Bar HDMI-out → Q-Sys Dante should also reject.
- Bonus valid path: Room Bar USB-C → Display HDMI. Passes via `usb-c ↔ hdmi` adapter alias, info toast shows the note.

### Scenario 3 — Persistence

- Refresh the page. Canvas restores from localStorage.
- 📋 Copy JSON — pastes canvas state as prettified JSON.
- 🗑 Clear then ↶ Undo — every device + edge should reappear.

---

## Decision by 2026-07-27

By the 2-week review date, decide:

- **(a) Commit to Milestone A** — global device library (DB-backed) + real editor v1 + DB persistence + export to PNG/PDF/SVG.
- **(b) Delete this branch** and rethink the drawing product.

Do NOT let this rot as half-finished production code. The whole point of the spike is a firm yes/no decision by that date. Half-shipped experiments block real work.

---

## Deploy Notes

- **NO migrations.** The spike is UI-only, no schema changes.
- **NO env var required by default** — the flag defaults to `false`, so `.env` can stay untouched until an admin explicitly wants to unlock the surface.
- **Build size:** the spike adds ~380 KB gzip'd JSX (React + React Flow + our JSX). The bundle is isolated to `main.jsx` and only fetched on `/spike/schematic-editor`. No impact on the existing Alpine/Vite payload.
- **Peer-dependency note:** `@vitejs/plugin-react@6.x` requires Vite ^8. This repo runs Vite ^7.0.7, so we pinned `@vitejs/plugin-react@^4.7`. Upgrade path is coupled — bump Vite + plugin-react together.
