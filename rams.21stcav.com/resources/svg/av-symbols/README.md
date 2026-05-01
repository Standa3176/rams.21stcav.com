# AV Symbol Pack — Phase 17 v1

In-house SVG symbol library used by `SchematicGeneratorService` (Phase 17) when emitting D2 source for system schematics. Each symbol references AVIXA D401.01 (Audio, Video and Control Architectural Drawing Symbols Standard) conventions but is drawn from scratch — we do not redistribute AVIXA artwork (see `.planning/research/SUMMARY.md` GAP-2 on AVIXA symbol licensing).

## Conventions

- Each SVG uses `viewBox="0 0 100 100"` so D2 / Browsershot can scale uniformly.
- Stroke uses `stroke="currentColor"` and fill is mostly `none` so the host page's CSS controls colour.
- No `<foreignObject>` (PITFALLS.md MIN-03 — Browsershot cannot reliably render arbitrary HTML inside SVG; staying with native SVG primitives keeps both the D2 native renderer and Browsershot happy).
- Each file ends with a comment line `<!-- AVIXA D401.01-aligned. Phase 17 v1. -->` so future audits can grep the pack.
- Filename convention: `{symbol-name}.svg` — kebab-case, all lowercase. The schematic builder maps device names / categories to filenames via an allowlist; unknown names fall back to `generic-source.svg` / `generic-destination.svg` / `switcher.svg` based on `Device::signal_role`.

## Catalogue

| # | Symbol | File | AVIXA D401.01 reference | Notes |
|---|--------|------|-------------------------|-------|
| 1 | Display | `display.svg` | §4 — Visual displays | Rectangle (16:9-ish) on a centred stand |
| 2 | Projector | `projector.svg` | §4 — Visual displays | Body trapezoid + lens cone |
| 3 | Speaker | `speaker.svg` | §3 — Audio output | Trapezoid + cone circle (AVIXA convention) |
| 4 | Microphone | `microphone.svg` | §3 — Audio input | Capsule + diaphragm slashes + stand |
| 5 | Camera | `camera.svg` | §4 — Video input | Body box + lens circle + indicator dot |
| 6 | Video / Audio Switcher | `switcher.svg` | §5 — Routing | Long rectangle with input/output port pips |
| 7 | DSP | `dsp.svg` | §3 — Signal processing | Rectangle with "DSP" label + waveform line |
| 8 | Amplifier | `amplifier.svg` | §3 — Signal amplification | Rectangle with chevron pattern |
| 9 | Codec / VC Codec | `codec.svg` | §6 — Conferencing | Rectangle with antenna marks |
| 10 | Control Processor | `control-processor.svg` | §7 — Control | Rectangle with "CTRL" + status LED dots |
| 11 | Touch Panel | `touch-panel.svg` | §7 — User interface | Rounded rectangle UI |
| 12 | BYOD Dongle | `byod-dongle.svg` | §6 — BYOD | Body + cable lead |
| 13 | ClickShare / Wireless Presentation | `clickshare.svg` | §6 — Wireless presentation | Round button with wireless arcs |
| 14 | Network Switch | `network-switch.svg` | §8 — Networking | Long rectangle with port grid |
| 15 | USB Hub | `usb-hub.svg` | §8 — Networking / connectivity | Body + branching ports |
| 16 | Source PC | `source-pc.svg` | §4 — Video source | Desktop tower outline |
| 17 | Laptop | `laptop.svg` | §4 — Video source | Clamshell laptop |
| 18 | HDMI Port | `hdmi-port.svg` | §11 — Connector symbols | Trapezoidal HDMI |
| 19 | USB Port | `usb-port.svg` | §11 — Connector symbols | Rectangular USB |
| 20 | Network Port (RJ45) | `network-port.svg` | §11 — Connector symbols | RJ45 outline |
| 21 | Generic Source | `generic-source.svg` | (fallback for unmapped sources) | Circle with arrow OUT |
| 22 | Generic Destination | `generic-destination.svg` | (fallback for unmapped destinations) | Circle with arrow IN |
| 23 | Blanking Panel | `blanking-panel.svg` | §10 — Rack accessories | Solid filler |
| 24 | PDU | `pdu.svg` | §10 — Rack accessories | Long rectangle with outlet pips |
| 25 | Equipment Rack | `equipment-rack.svg` | §10 — Rack | Vertical rectangle + U-numbered side rail |

## Pack stats

- **Symbol count:** 25 (matches Phase 17 CONTEXT.md "Claude's Discretion" v1)
- **Total size budget:** <100 KB (current pack ~13 KB)
- **All files:** XML prolog `<?xml version="1.0" encoding="UTF-8"?>` first line, no `<foreignObject>`, no external HTTP refs.

## Visual verification (Nit 11)

Task 3's feature tests cover the **D2 escape path + signal-flow output** — they do not assert visual fidelity per symbol. Per-symbol AVIXA visual fidelity is a **manual review item**:

1. Open each SVG in a browser (or VS Code preview) at 200% zoom.
2. Eyeball against the AVIXA D401.01 reference (or the AVIXA Standard Guide for AV Systems Design and Coordination).
3. Check the symbol off in this README during code review.

If a symbol drifts noticeably from convention, file an internal task — do not gate Phase 17 on cosmetic refinements; the schematic-generation pipeline ships first, symbols evolve organically through real project use (per `17-CONTEXT.md` "Symbol pack at v1 — start with the recommended top-25 ... grow organically through real project use.").

## How the schematic builder picks a symbol

`SchematicD2SourceBuilder::resolveSymbol()` walks an allowlist that maps fragments of the device's name/category to one of the 25 filenames. The fallback chain is:

1. Direct filename match against the allowlist (e.g. "Sony Bravia 65" display" → `display.svg`).
2. If `Device::signal_role === 'source'` → `generic-source.svg`.
3. If `Device::signal_role === 'destination'` → `generic-destination.svg`.
4. If `Device::signal_role === 'processor'` → `switcher.svg`.
5. Default → `generic-source.svg`.

Any allowlist drift is a deliberate code change (the symbol filename whitelist enforces T-17.02-05 — no user-controlled `file://` paths).

## License

Each SVG in this directory is original artwork drawn against AVIXA convention. No AVIXA-licensed artwork is included or redistributed. The `21st Century AV Ltd` repository licence applies.
