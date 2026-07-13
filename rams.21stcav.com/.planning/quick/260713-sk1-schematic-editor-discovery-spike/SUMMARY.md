---
task: 260713-sk1
title: Schematic Editor Discovery Spike (React Flow, 2-week throwaway)
status: complete
type: spike
date: 2026-07-13
branch: feat/worksheet-classifier-universal
review_deadline: 2026-07-27
migrations: 0
commits: 4
tech_stack_added:
  - react ^19
  - react-dom ^19
  - "@xyflow/react ^12 (React Flow v12, MIT)"
  - "@vitejs/plugin-react ^4.7 (pinned — v6 requires Vite ^8, repo is Vite ^7.0.7)"
key_files_created:
  - app/Http/Controllers/SpikeSchematicController.php
  - resources/views/spike/canvas.blade.php
  - resources/js/spike/main.jsx
  - resources/js/spike/devices.js
  - resources/js/spike/signalColours.js
  - resources/js/spike/compatibilityAliases.js
  - resources/js/spike/validation.js
  - resources/js/spike/autoArrange.js
  - resources/js/spike/components/DeviceNode.jsx
  - resources/js/spike/components/DevicePalette.jsx
  - resources/js/spike/components/Toolbar.jsx
  - resources/js/spike/components/SchematicEditor.jsx
  - .planning/spikes/schematic-editor-260713/README.md
key_files_modified:
  - config/services.php
  - routes/web.php
  - resources/views/layouts/navigation.blade.php
  - vite.config.js
  - package.json
  - package-lock.json
  - .planning/STATE.md
---

# Phase Quick 260713-sk1: Schematic Editor Discovery Spike — Summary

React Flow discovery spike proving the smallest viable slice of an interactive AV schematic editor: drag-to-connect + port-type validation + auto-arrange + localStorage-only persistence, admin-gated and feature-flagged so it defaults off in prod. Throwaway by design — hard 2-week review deadline on 2026-07-27 to either commit to Milestone A (device library + editor v1 + DB persist + export) or delete the branch.

## What Shipped

### Task 1 — Infrastructure (5e06310)

- Installed `react`, `react-dom`, `@xyflow/react`, `@vitejs/plugin-react` (pinned to v4.7 — v6 wants Vite ^8, repo is on Vite ^7).
- Added `resources/js/spike/main.jsx` as a new Vite entry so the React Flow bundle is isolated to the spike route and never loaded on the rest of the app.
- New `SpikeSchematicController::show()` — 5 lines, both `abort_unless` gates inside the controller (feature-flag 404 + admin 403).
- Route `/spike/schematic-editor` in the `auth` group (not `admin` middleware) so the 404 fires cleanly for non-admins too.
- Nav dropdown: "🧪 Schematic Spike" link, gated by the same config flag.
- `config/services.php`: `spike_schematic_enabled = env('SPIKE_SCHEMATIC_ENABLED', false)` — default off, never touches `.env` by default.
- Blade shell is a bare React root; no Alpine/Blade content in the canvas area.

### Task 2 — Custom device nodes + palette (3d95969)

- `devices.js` — 5 hardcoded devices covering the AV signal chain:
  - Sony QM85 4K Display (destination) — 2× HDMI-in + RS232
  - Cisco Room Bar Pro (processor) — HDMI in/out + USB-C out + RJ45 PoE bidi
  - Shure MXW Wireless Mic (source) — Dante out (RJ45 audio)
  - QSC Q-Sys Core 110f DSP (processor) — 4× Dante bidi + 2× line-out TRS
  - Netgear GS308EPP switch (destination for auto-arrange) — 8× RJ45 PoE bidi
- `DeviceNode.jsx` custom React Flow node — manufacturer + model + role badge + one `<Handle>` per port docked on its declared side; bidi ports get twin source+target handles so React Flow accepts either direction. `data-port-id` custom attr on every handle for validation lookup.
- `DevicePalette.jsx` — 240px left sidebar with HTML5-draggable device cards. Custom MIME `application/schematic-device` + `text/plain` fallback (some browsers refuse drop with only a custom MIME).
- `signalColours.js` + `compatibilityAliases.js` — hardcoded from `config/cables.php`. Spike simplicity — no server round-trip. Real editor bakes at build time or fetches on mount.

### Task 3 — Validation + auto-arrange + toolbar + localStorage (3c5f311)

- `validation.js` — `isValidConnection(sourcePort, targetPort)` checks direction (source emits, target receives) first, then signal-type match, then connector-alias fallback (HDMI↔DP, USB-C↔Thunderbolt, RJ45↔SFP+, USB-C↔HDMI). Returns `{ valid, reason, note }`.
- Invalid drops → discard edge + flash target port red for 600ms via a CSS keyframe + toast top-right (auto-dismiss 3s).
- Valid drops via an adapter alias → info toast surfaces the adapter note so the engineer knows an adapter is required.
- `autoArrange.js` — 3-column layout (sources 0px · processors 350px · destinations 700px). Within each column, sort by dominant output signal (video → audio → control → usb → network) then by node id. 200px vertical step, 40px top margin. No dagre.
- `Toolbar.jsx` — 🎯 Auto-arrange · ↶ Undo · ↷ Redo · 💾 Save · 🗑 Clear · 📋 Copy JSON with node/edge counts + last-save timestamp on the right.
- Undo/redo via `[past, present, future]` refs, cap 50. Snapshots taken at commit points only (drop, connect, remove, auto-arrange, clear) — not on every drag-position tick.
- localStorage — debounced 500ms auto-save + explicit save + restore-on-mount. Payload versioned (`version: 1`). Node shape stored as `{ id, position, catalogId }` — device data is re-hydrated from the local catalog on restore, so a device-schema change bumps the version key and cleanly refuses stale payloads. Id counter reserved above highest restored id.
- Edges styled by source signal type — video edges blue, audio red, control green, etc. Signal flow reads at a glance.

### Task 4 — README + walkthrough + STATE + SUMMARY

- Spike README at `.planning/spikes/schematic-editor-260713/README.md` — full context (why, scope, success criteria, non-goals, kill-switch, walkthrough, decision deadline, deploy notes).
- Inline collapsible `<details>` walkthrough on `resources/views/spike/canvas.blade.php` with the 3 test scenarios (Tilda boardroom build, invalid connection expectations, persistence check).
- STATE.md — Quick Tasks row + last_activity bumped.

## Gate Results (all pass)

| Gate | Result |
|------|--------|
| `php artisan route:list` shows the spike route | PASS — `spike/schematic-editor spike.schematic.editor › SpikeSchematicController@show` |
| `npm run build` succeeds after each task | PASS — 3 successful builds (Tasks 1, 2, 3) |
| `php -l` clean on all edited PHP files | PASS — Controller + `config/services.php` + `routes/web.php` |
| Feature flag defaults false | PASS — route returns 404 with `SPIKE_SCHEMATIC_ENABLED` unset |
| 4 commits landed on branch | PASS — 5e06310 (infra) + 3d95969 (devices) + 3c5f311 (validation) + docs commit |

## Deviations

**Rule 3 — Auto-fix blocking issues:** `npm install --save-dev @vitejs/plugin-react` (unpinned) tried to install v6, which requires Vite ^8. The repo is on Vite ^7.0.7. Pinned to `@vitejs/plugin-react@^4.7` — the current v4 line, MIT, Vite 5/6/7 supported. Documented in the Task 1 commit message and README so a future Vite 8 upgrade knows to bump both together.

Skipped installing `dagre` / `@dagrejs/dagre` — the plan said column-based layout was acceptable and it is. Saves ~30 KB gzip.

## Known Stubs

None. Everything the spike claims to do actually works end-to-end from the browser. localStorage is intentional (documented in scope), not a stub.

## Deploy Notes

- **NO migrations.**
- **NO env var required by default** — the flag defaults `false` via `env('SPIKE_SCHEMATIC_ENABLED', false)`. Live `.env` stays untouched until an admin wants to unlock the surface.
- Deploy: `git pull` → `npm ci` → `npm run build` → `php artisan config:cache` → `php artisan view:clear`.
- To unlock post-deploy: set `SPIKE_SCHEMATIC_ENABLED=true` in live `.env`, then `php artisan config:cache`.
- Bundle size: ~124 KB gzip'd, isolated to `main.jsx`, only fetched on `/spike/schematic-editor`. No impact on the existing Alpine payload.
- Route path: `/spike/schematic-editor`. Admin dropdown link appears in the top-nav when the flag is on.

**Executor recommendation:** the spike is **not** deployed to live. It stays on `feat/worksheet-classifier-universal` until the user has run through the 3 scenarios locally and made the yes/no decision.

## 2-Week Review Deadline: 2026-07-27

By 2026-07-27, decide:

- **(a) Commit to Milestone A** — global device library (DB-backed) + real editor v1 + DB persistence + export to PNG/PDF/SVG.
- **(b) Delete this branch** and rethink the drawing product.

Do NOT let this rot as half-finished production code.

## Deferred / Next (if greenlit)

- **Device library from DB** — move the 5-device catalog into `devices` + `device_ports` tables per the v2.0 plan.
- **Real editor v1** — extends this spike with DB persistence per `project_drawings` schema, versioned canvas state, per-sheet management.
- **Export** — PNG/PDF/SVG output via server-side render (Puppeteer) or client-side canvas export.
- **Keyboard-driven editing** — arrow-key node nudge, `Cmd+Z`/`Cmd+Shift+Z` for undo/redo (currently button-only in the spike toolbar).
- **Real connector-alias data flow** — the spike hardcodes `signal_type` on Dante ports (RJ45 physical connector, audio signal), which is correct for Dante but a broader alias system would need `connector_type` + `signal_type` to be independent everywhere.

## Self-Check: PASSED

All 12 spike source files present in tree. All 4 commits verified in git log (5e06310, 3d95969, 3c5f311, docs commit). See executor's final chat response for full verification output.
