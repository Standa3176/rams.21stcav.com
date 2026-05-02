---
status: partial
phase: 18-rack-elevations
source: [18-VERIFICATION.md]
started: "2026-05-02T22:30:00Z"
updated: "2026-05-02T22:30:00Z"
---

## Current Test

[awaiting human testing]

## Tests

### 1. Drag-into-U-slots editor end-to-end on dev/staging
expected: Engineer creates a rack via "+ Create Drawing" → "Rack Elevation"; empty 42U rack opens in editor; equipment palette on left filtered by `is_rack_mounted=true` first; engineer drags items into U-slots; per-item U-position lock toggle works; cursor-walk reflows unlocked items around locked ones; AJAX save persists canvas state; Save → Render → SVG appears within ~1s.
result: [pending] — manual UI walkthrough required.

### 2. Rendered SVG visual quality
expected: 42U rack frame with U-numbered side rail (1 at bottom, 42 at top); equipment rectangles sized correctly per `u_height`; manufacturer + model labels readable; ventilation gap visible where flagged; totals footer shows weight/current/BTU/U-utilisation with asterisk on incomplete metrics; "U-height unknown" warning row visible for devices outside the manufacturer pack (CRIT-06).
result: [pending] — engineer visual review of generated SVG required.

### 3. Browsershot PDF render of rack on production AlmaLinux
expected: After upload + `php artisan queue:restart`, click PDF download on a rack drawing → PDF renders without error; layout matches the SVG seen in the editor; title block correct; A4 portrait orientation; no font-fallback issues (Arial fallback already wired from Phase 17).
result: [pending] — production deploy + smoke-test required.

### 4. Manufacturer JSON pack accuracy
expected: 53 entries in `resources/data/device-port-catalog.json` accurately reflect manufacturer datasheet specs for U-height, weight, current draw, BTU. Engineer cross-references the entries against current 21CAV quote pipeline; flags any out-of-date or missing devices for substitution.
result: [pending] — engineer datasheet cross-check required.

### 5. Multi-rack visual workflow
expected: Engineer creates 2-3 racks for one project via the picker; each appears as a separate row in the Rack Elevations section with its own status pill; opening any rack opens its own editor with isolated state; each renders its own SVG; engineer can switch between racks without state contamination.
result: [pending] — engineer multi-rack walkthrough required.

## Summary

total: 5
passed: 0
issues: 0
pending: 5
skipped: 0
blocked: 0

## Gaps
