---
status: partial
phase: 17-system-schematics-shared-foundations
source: [17-VERIFICATION.md]
started: "2026-05-01T17:45:00Z"
updated: "2026-05-01T17:45:00Z"
---

## Current Test

[awaiting human testing]

## Tests

### 1. D2 binary end-to-end render on AlmaLinux production
expected: After `curl -fsSL https://d2lang.com/install.sh | sh -s -- --version v0.7.1` to `/usr/local/bin/d2`, `php artisan tinker` invocation of `app(SchematicGeneratorService::class)->generate(...)` produces a non-empty SVG file containing room name + cable IDs matching the cable schedule. Smoke-test command `php artisan pdf:smoke-test --drawings` returns success.
result: [pending] — local-dev D2 binary not installed on Windows (1 feature test skipped); production install + smoke-test required.

### 2. AVIXA visual fidelity review of 25-symbol SVG pack
expected: Each SVG in `resources/svg/av-symbols/` (display, projector, speaker, mic, camera, switcher, dsp, amp, codec, control-processor, touch-panel, byod-dongle, clickshare, network-switch, usb-hub, source-pc, hdmi-port, usb-port, network-port, generic-source, generic-destination, blanking-panel, pdu, equipment-rack-meta, room-edge-marker) visually corresponds to AVIXA D401.01 conventions when opened in a browser.
result: [pending] — engineer visual review against AVIXA D401.01 reference required.

### 3. End-to-end UI smoke
expected: 
- Drawings index page renders with status pill colour-coding (draft / for_review / approved / superseded)
- Status update flow via `DrawingEditAdapter::set_status` works inline (no full-form post)
- Regenerate-confirm modal triggers when user clicks Regenerate on a drawing with `canvas_state` populated; copy reads "Regenerate from project data? This will archive your edits as a prior version."
- Project show page "Drawings" link reaches the drawings index
result: [pending] — manual UI walkthrough on dev or staging required.

### 4. Browsershot + chrome-headless-shell PNG render on production
expected: After `php artisan pdf:smoke-test --drawings` runs on production AlmaLinux 8 with `chrome-headless-shell` symlinked at `/home/stcav/chrome` (per the 260427-qvr runbook), a probe PNG is generated for a sample schematic Blade view via `PdfRenderService::fromBladeAsPng()`. No "page protocol error" or "WaitForFunction timeout" surfaces.
result: [pending] — production deploy + smoke-test required (mirrors 260427-qvr runbook).

### 5. O&M Manual DOCX visual review with embedded drawings
expected: Generate an O&M Manual for a project with at least one approved schematic; open the DOCX in Word/LibreOffice; confirm the new "Drawings" section opens on its own page (via `$drawingsSection = $phpWord->addSection(...)`) with the schematic embedded as PNG (~600 px wide, centred), with a heading and one drawing per page. Existing Document Control / Asset Register / Maintenance Schedule sections still render correctly.
result: [pending] — engineer review of generated DOCX required.

## Summary

total: 5
passed: 0
issues: 0
pending: 5
skipped: 0
blocked: 0

## Gaps
