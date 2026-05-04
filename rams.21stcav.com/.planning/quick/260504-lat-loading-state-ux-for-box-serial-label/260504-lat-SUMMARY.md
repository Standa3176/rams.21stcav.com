---
task: 260504-lat
type: quick
title: Loading state UX for Box Serial Label capture
status: complete
date: 2026-05-03
files-modified:
  - resources/views/worksheets/public-show.blade.php
commits:
  - 6554bcc: feat(quick-260504-lat) visible loading state for Box Serial Label capture
metrics:
  files-changed: 1
  insertions: 59
  deletions: 3
---

# Quick Task 260504-lat: Loading State UX for Box Serial Label Capture Summary

Added pulsing spinner + 3-step progress text (Processing image -> Uploading -> Reading label) to the `captureLabel()` flow on the public worksheet so engineers see clear feedback during the multi-second image-conversion + upload + AI-extraction pipeline.

## What Changed

### CSS (resources/views/worksheets/public-show.blade.php, lines 390-403)

Added a small pulsing-dot spinner inside the existing inline `<style>` block, scoped to a `.label-cap-busy` modifier class:

- `.label-cap-busy::after` renders an 8px circular dot using `currentColor` (so it inherits the button's text color)
- `@keyframes lblPulse` animates opacity 0.3 -> 1.0 and scale 0.8 -> 1.1 over 1s, infinite
- No layout shift — the dot is appended after the button text via `::after`

### JS (captureLabel function, lines 1467-1587)

Refactored the loading UX into two helpers and three progress checkpoints:

**`setBusy(text)` helper:**
- Finds the first non-empty TEXT NODE inside the `<label>` button and rewrites it (preserves the `<input>` sibling — critical for re-capture)
- Falls back to a `.label-cap-text` `<span>` if no text node exists
- Adds `.label-cap-busy` class (turns on the pulsing dot)
- Sets opacity 0.75 + `pointer-events: none`

**`restoreBtn()` helper:**
- Removes `.label-cap-busy` class, clears inline opacity + pointer-events
- Restores original text into the same text node it mutated
- Strips any fallback `.label-cap-text` span
- Re-enables the file input

**Three progress checkpoints:**
1. Before `convertToJpegBlob()`: `⏳ Processing image...`
2. Before `fetch()`: `📤 Uploading...`
3. After fetch resolves, before `await resp.json()`: `🤖 Reading label...`

**All exit paths restore correctly:**
- Success path: `restoreBtn()` before `openLabelReview()` opens the modal
- Non-OK response: `restoreBtn()` after the alert, then `return`
- Network/exception catch: `restoreBtn()` after the alert
- File input is `.disabled = true` at start, re-enabled by `restoreBtn()`

## Why

Pre-existing UX showed only `btn.style.opacity = '.6'` — no progress indicator during a 5-15s pipeline (HEIC conversion + upload + Claude vision extraction). Engineers on flaky site Wi-Fi could not tell if the tap registered or if it was still working, leading to repeat taps and duplicate uploads.

## Defensive Decisions

1. **Mutate only the first text node, not `textContent`.** The `<label>` contains TEXT_NODE + `<input>` as siblings. Setting `btn.textContent = '...'` would wipe the `<input>` and break re-capture. The `Array.from(btn.childNodes).find(n => n.nodeType === Node.TEXT_NODE)` pattern preserves the input element.

2. **Disable input mid-flow.** `input.disabled = true` at the start prevents accidental retap from re-triggering captureLabel before the modal opens; `restoreBtn()` re-enables it on every exit.

3. **`pointer-events: none` on the busy button.** Defense-in-depth — even if the input weren't disabled, the parent label can't be clicked.

4. **`currentColor` for the dot.** Inherits the button's text color so it reads correctly on light/dark variants of `.btn-outline`.

## Deviations from Plan

None — implemented exactly as specified in the work-to-do.

## Verification

- `php artisan view:cache` -> "Blade templates cached successfully."
- `git diff --stat HEAD~1 HEAD` -> 1 file changed, 59 insertions(+), 3 deletions(-) (matches the EXACTLY-1-file constraint)
- Grep confirms 3 setBusy calls + 3 restoreBtn exit-path calls + CSS class definition

## Self-Check: PASSED

- File modified: resources/views/worksheets/public-show.blade.php — FOUND
- Commit 6554bcc — FOUND
- CSS class `.label-cap-busy` present at line 391 — FOUND
- `setBusy` helper at line 1492 — FOUND
- `restoreBtn` helper at line 1511 — FOUND
- 3 progress states wired (lines 1525, 1547, 1565) — FOUND
- `restoreBtn()` in all 3 exit paths (lines 1562, 1568, 1584) — FOUND
- view:cache compiled successfully — PASSED
