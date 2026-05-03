---
status: partial
phase: 20-drawing-export-pipeline-o-m-integration
source: [20-VERIFICATION.md]
started: "2026-05-03T14:30:00Z"
updated: "2026-05-03T14:30:00Z"
---

## Current Test

[awaiting human testing]

## Tests

### 1. End-to-end bound PDF download from a real project's drawings index
expected: Browser receives a multi-page PDF (cover + register + per-drawing pages); each drawing's title block shows the AV-XXX sheet number
result: [pending]

### 2. End-to-end ZIP bundle download
expected: Browser receives a ZIP containing bound-{id}-v{N}-{ulid}.pdf + per-drawing PDF/SVG/PNG + drawing-register.csv; opens in Windows Explorer / 7-Zip without warnings
result: [pending]

### 3. Regen-needed amber badge surfaces after a drawing edit
expected: After clicking Download Bound PDF, then editing any rack/schematic via Phase 18 editor, the drawings index page shows an amber 'Regen needed — drawing changed' pill next to the bound-PDF button
result: [pending]

### 4. Bound PDF completion email arrives with attachment
expected: After the async job completes, the project recipient (per NotificationRecipientResolver) receives a 'Project drawings ready' email with the bound PDF attached
result: [pending]

### 5. Failure isolation visible in cover sheet
expected: If one drawing's generated_svg is corrupted/empty, the bound PDF still completes; the cover sheet's drawing register highlights that row in red and prefixes the title with '[render failed]'
result: [pending]

### 6. pdf:smoke-test --drawings on production with chrome-headless-shell
expected: Command exits 0 with non-zero byte sizes for both schematic and rack outputs when run against the pinned chrome-headless-shell version
result: [pending]

## Summary

total: 6
passed: 0
issues: 0
pending: 6
skipped: 0
blocked: 0

## Gaps
