---
task: 260504-ktt
title: Fix Box Serial Label OCR — client-side HEIC→JPEG conversion + AI-failure UX hint
type: quick
date: 2026-05-03
duration: ~10m
files_changed: 1
insertions: 60
deletions: 5
key-files:
  modified:
    - resources/views/worksheets/public-show.blade.php
---

# Quick Task 260504-ktt: Fix Box Serial Label OCR Summary

Client-side HEIC→JPEG conversion in `captureLabel()` plus a yellow "AI couldn't read this label" banner in the confirm modal — fixes iOS uploads that Claude vision can't read and gives engineers clear feedback when extraction returns nothing usable.

## Problem

iOS Safari uploads photos as HEIC by default. Claude vision (and most server-side image tooling) can't decode HEIC, so the AI extraction silently failed for engineers using iPhones to photograph manufacturer stickers — empty fields appeared in the confirm modal with no explanation, leaving engineers unsure whether to retry, re-photograph, or just type the values manually.

## Fix

Two purely-client-side changes in `resources/views/worksheets/public-show.blade.php` — no controllers, routes, schema, services, or dependencies touched.

### Fix 1: `convertToJpegBlob()` helper + integration in `captureLabel()`

New helper (lines 1443–1465) draws the picked file onto a `<canvas>` and re-encodes as JPEG via `canvas.toBlob(..., 'image/jpeg', 0.85)`. Also downscales to `maxSide=1600` so we ship smaller payloads.

In `captureLabel()` (lines 1467–1530) the conversion runs BEFORE FormData is built. On any failure (very old browser, CORS, OOM, missing toBlob support) the catch block logs a console warning and falls through with the original raw file unchanged — so the fix never makes uploads worse than they were before.

The browser's native HEIC decoder on iOS will hand the canvas a decoded image, which then re-encodes as JPEG — solving the original problem at the source.

### Fix 2: AI-failure banner in `openLabelReview()`

Added an `aiFailed` boolean (lines 1560–1561) that returns true when EVERY one of `part_number`, `serial_number`, `mac_address`, `model`, `manufacturer` is empty/whitespace/`UNKNOWN`. When true, a yellow info banner renders at the top of the modal (lines 1569–1574) telling the engineer to type the values from the photo manually. The image still saves regardless.

## Verification

- `php artisan view:clear && php artisan view:cache` — both succeeded, no Blade compile errors.
- Compiled the view via `Blade::compileString()` and confirmed all three new tokens (`convertToJpegBlob`, `aiFailed`, "AI couldn't read") are present in the compiled output.
- Brace/paren/backtick balance check on the modified JS region: 36/36 braces, 110/110 parens, 8 backticks (even) — clean.
- `git diff --stat HEAD` — exactly 1 file changed (+60/-5), matches scope.

## Constraints Honored

- File footprint: 1 file (`resources/views/worksheets/public-show.blade.php`).
- No new dependencies (uses only stdlib `FileReader`, `Image`, `<canvas>`).
- No controller / route / schema / service changes.
- Defensive fallback to raw file on any conversion failure — preserves prior behavior for unsupported browsers.
- Existing `btn.style.opacity = '.6'` button-feedback pattern preserved (and now the conversion happens between the opacity change and the fetch, so the visual feedback covers conversion+upload).

## Skipped

- The "optional polish" of changing button text mid-conversion (`Processing... → Uploading...`) — the existing button-text-handling pattern uses a single `orig` variable but never actually mutates `firstChild.nodeValue` for transient states; introducing it just for this task would have added bloat and risked TextNode lookup edge cases. Left unchanged.

## Deviations from Plan

None. Plan executed exactly as written.
