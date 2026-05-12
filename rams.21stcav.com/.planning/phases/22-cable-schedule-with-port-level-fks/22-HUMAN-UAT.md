---
status: partial
phase: 22-cable-schedule-with-port-level-fks
source: [22-VERIFICATION.md]
started: 2026-05-12T12:15:00Z
updated: 2026-05-12T12:15:00Z
---

## Current Test

[awaiting human testing]

## Tests

### 1. Open picker modal from a cable row
expected: Modal opens with SOURCE on the left, DESTINATION on the right; chain-link icon column visible between From and To (D-02 + D-03)
result: [pending]

### 2. Pick HDMI source port + RJ45 dest port (incompatible)
expected: Yellow warning banner appears with 'Connector mismatch: hdmi → rj45'; Apply button disabled until override note typed (DRAW-39 client gate)
result: [pending]

### 3. Apply picker selection on a row
expected: From/To text overwritten with 'Manufacturer Model (Port label)' canonical form; chain-link icon flips from faded grey (#bbb) to teal (#1B7A7A)
result: [pending]

### 4. Clear ports on this row button
expected: All 5 FKs nulled; icon flips back to faded grey; From/To text NOT overwritten (engineer's free-text survives)
result: [pending]

### 5. Submit form with picker-populated row (end-to-end persistence)
expected: Page reloads with 'Cable schedule saved.' flash; tinker confirms CableScheduleItem::find($id)->source_port_id and ->connector_override_note persist
result: [pending]

### 6. Live backfill smoke test
expected: php artisan cables:backfill-port-fks --apply on a project with catalogued devices populates matched rows; a second run reports already-set + wrote: 0 (idempotent)
result: [pending]

### 7. PhpSpreadsheet XLSX byte-identity on production
expected: Both runtime XLSX regression tests (test_xlsx_byte_identical_for_null_and_populated_fks + test_xlsx_export_query_log_does_not_touch_device_ports) PASS on live where PhpSpreadsheet is installed
result: [pending]

### 8. D2 binary schematic NULL-FK regression on production
expected: test_null_fk_cables_render_byte_identical_to_populated_fks_d10_invariant passes on live where D2 binary is installed
result: [pending]

## Summary

total: 8
passed: 0
issues: 0
pending: 8
skipped: 0
blocked: 0

## Gaps
