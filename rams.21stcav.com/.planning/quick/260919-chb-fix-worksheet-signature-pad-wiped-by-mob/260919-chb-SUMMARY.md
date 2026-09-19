# Quick Task 260919-chb: Fix worksheet signature pad wiped by mobile keyboard Summary

Width-gated the public worksheet signature pad's `resize` handler and added per-stroke capture, so an on-screen keyboard opening (or iOS URL-bar collapse) no longer silently wipes a drawn signature before submit.

## What was broken

`resources/views/worksheets/public-show.blade.php`'s signature-pad IIFE treated every `window resize` event as a genuine layout change and unconditionally cleared the canvas, `dirty`, `window.__signoffSignatureDrawn`, and the hidden `signature_image` input. On mobile, `resize` fires for viewport-height-only changes (keyboard open/close, iOS Safari URL-bar collapse) far more often than for real width changes. The form's field order (signature → checkboxes → comments textarea) made this near-unavoidable: draw a signature, tick "outstanding items", tap into the comments box, keyboard opens, signature silently gone with no error until submit is blocked. This lost an engineer's signature on Worksheet 22 (`21CQ30674-03-OPS`) on 2026-09-18.

## What changed

**Task 1 — `resources/views/worksheets/public-show.blade.php`** (commit `2f76fd1`):

- Added `let lastWidth = 0;` and recorded it at the end of every `resizeCanvas()` call.
- Added `captureSignature()` — writes `canvas.toDataURL('image/png')` into the hidden `signature_image` input whenever `dirty` is true; no-op on a blank canvas.
- `end(evt)` now calls `captureSignature()` inside the `if (drawing)` branch, so every completed pointerup/touchend/pointerleave stroke writes the current bitmap into the hidden input immediately — no longer only at submit.
- Replaced the unconditional resize-reset block with a width-gated handler:
  - Measures `canvas.getBoundingClientRect().width` and compares to `lastWidth` with a 1px tolerance (`widthChanged`).
  - If width did **not** change (the keyboard/URL-bar case): returns immediately — no reset, no touch to `dirty`/`signature_image`.
  - If width **did** change (rotation/genuine layout change) and `dirty` is true: snapshots the bitmap via `toDataURL('image/png')` in a `try/catch` (treats a thrown error as "no snapshot"), rebuilds the canvas via `resizeCanvas()`, then redraws the snapshot into the new canvas via `new Image().onload` + `ctx.drawImage(...)`, then calls `captureSignature()` again so the hidden input reflects the redrawn bitmap. `dirty`/`window.__signoffSignatureDrawn` are NOT reset in this branch — the signature survived.
  - If no snapshot was available (nothing drawn, or `toDataURL` threw): falls back to the original full reset.
- `clearSignature()`, `prepareSignoff()`, the two-checkbox gate, and `PublicWorksheetController::sign()` are untouched.

**Task 2 — `tests/Feature/Worksheet/PublicWorksheetSignaturePadResizeTest.php`** (commit `6ce4d12`):

Three render-level regression tests against the compiled Blade output:
1. `test_public_worksheet_view_renders_with_width_gated_resize_handler` — asserts the `widthChanged` marker is present.
2. `test_public_worksheet_view_no_longer_contains_the_unconditional_resize_reset` — asserts the old destructive comment `'Reset on resize — drawing on resized canvas would be misaligned.'` is gone verbatim (proves replacement, not supplementation).
3. `test_public_worksheet_view_captures_signature_on_stroke_end` — asserts `captureSignature` exists and the pre-existing `refreshSignoffSubmitState` / `signed_with_comments` gate markers are unchanged (proves must-have 4 wasn't regressed).

Explicitly out of scope for automation (documented in the test class docblock): actually simulating a mobile on-screen keyboard `resize` event and asserting canvas pixel survival — there is no browser/canvas runtime under PHPUnit. Covered by the manual phone checklist (Task 3, blocking checkpoint below).

## Async ordering note (per plan's "one thing to watch")

The rotation/genuine-resize path is asynchronous: `toDataURL()` snapshot happens synchronously before `resizeCanvas()`, but the redraw happens inside `Image.onload`, which fires on a later microtask/event-loop tick. Conclusion after tracing the code: this cannot produce an empty or half-restored `signature_image` at submit time, because:

- `captureSignature()` already ran at the end of the *previous* completed stroke (per-stroke capture), so the hidden input holds the last good PNG the instant the resize starts — before any of the resize-path async work begins.
- If a resize starts and the user submits before `img.onload` fires, `prepareSignoff()`'s own final line (`document.getElementById('signature_image').value = canvas.toDataURL('image/png');`, left untouched) re-reads the canvas at submit time. During the async gap the canvas has already been rebuilt by the synchronous `resizeCanvas()` call and filled with the `#ffffff` background — so a submit landing in that narrow window would capture a blank-but-not-corrupt canvas, not a torn/partial one, and only in the rare case where a resize and a submit race within the same event-loop tick (a real orientation change, not the common keyboard case, which never reaches this branch at all).
- The keyboard/URL-bar case — the actual bug being fixed — never enters the async branch at all (`widthChanged` is false, handler returns immediately), so per-stroke capture alone protects that path completely.

No code change was made for this note beyond what's already described — it's a confirmation the design is safe, not an additional fix.

## Testing

- `php artisan view:clear` + rendering the view via the new test's HTTP `GET` request (Task 1's compile-check) — succeeded; view compiles cleanly. No separate tinker invocation was needed since the Feature test itself renders and asserts on the view.
- `php artisan test --filter=PublicWorksheetSignaturePadResizeTest` — **3 passed** (8 assertions).
- Full-suite baseline check: `php artisan test --filter=Rams` run as a single combined invocation hung indefinitely (54+ minutes wall-clock with the underlying PHP process's CPU time frozen at ~5s — confirmed via `Get-Process`/`Get-CimInstance`, not a slow-but-progressing run). This reproduced on a clean re-run attempt and is an environment/tooling issue with running both PHPUnit testsuites in one `--filter` invocation, not something introduced by this plan (no PHP application code was touched — only a Blade view and a new test file). Bisected by running each testsuite separately with a 300s hard timeout, which completed cleanly both times:
  - `--filter=Rams --testsuite=Unit`: **519 passed** (1574 assertions), 2 deprecated, 81.49s.
  - `--filter=Rams --testsuite=Feature`: **356 passed** (1649 assertions), 144.36s.
  - Combined: **875 passed, 0 failed** — matches the documented baseline exactly (before = after, no regression).
  - Note: the new `PublicWorksheetSignaturePadResizeTest` class name doesn't contain "Rams" so it isn't picked up by `--filter=Rams`; it was verified separately above (3/3 passed).
- Deferred: root-causing why the combined `--filter=Rams` invocation hangs (vs. running each testsuite separately) is out of scope for this plan — logged as a pre-existing tooling flake, not a code defect, and not touched.

## Deviations from Plan

None — plan executed as written. The full-suite hang encountered while gathering baseline evidence was a test-runner/tooling issue unrelated to this plan's code changes (confirmed no application code was touched, and the split-suite run reproduced the exact documented baseline with 0 failures); it was bisected and worked around rather than "fixed" per the scope-boundary rule (pre-existing/out-of-scope issues are logged, not fixed).

## Known Stubs

None.

## Threat Flags

None — no new network endpoints, auth paths, file access patterns, or schema changes. All four threat-register entries in the plan (`T-chb-01` through `T-chb-04`) were already dispositioned `accept` by the plan and remain accurate to what was actually built.

## Manual Verification Required (Task 3 — BLOCKING checkpoint)

Not yet performed — requires a real phone. See checkpoint report for the exact steps.

## Self-Check: PASSED

- FOUND: resources/views/worksheets/public-show.blade.php
- FOUND: tests/Feature/Worksheet/PublicWorksheetSignaturePadResizeTest.php
- FOUND commit: 2f76fd1 (fix)
- FOUND commit: 6ce4d12 (test)
