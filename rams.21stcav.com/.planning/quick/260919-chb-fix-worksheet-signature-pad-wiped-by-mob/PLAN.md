---
phase: quick
plan: 260919-chb
type: execute
wave: 1
depends_on: []
files_modified:
  - resources/views/worksheets/public-show.blade.php
  - tests/Feature/Worksheet/PublicWorksheetSignaturePadResizeTest.php
autonomous: false
requirements: [QUICK-chb]
must_haves:
  truths:
    - "A `resize` event caused only by the on-screen keyboard opening/closing (canvas CSS width unchanged) does not clear a drawn signature, the dirty flag, or the hidden signature_image input"
    - "A `resize` event that genuinely changes the canvas's CSS width (rotation, real layout change) rebuilds the canvas backing store WITHOUT losing the drawn strokes — the prior bitmap is redrawn scaled into the new canvas"
    - "The hidden signature_image input is populated at the end of every completed stroke (pointerup/touchend/pointerleave), not only at form submit, so an unrelated JS error or reset between last stroke and submit cannot silently empty it"
    - "The two-checkbox gate, the comments-required-when-outstanding rule, and PublicWorksheetController::sign() server-side validation are byte-for-byte unchanged"
  artifacts:
    - path: resources/views/worksheets/public-show.blade.php
      provides: "captureSignature() helper + width-gated resize handler with bitmap snapshot/redraw, replacing the unconditional reset-on-any-resize block"
    - path: tests/Feature/Worksheet/PublicWorksheetSignaturePadResizeTest.php
      provides: "Regression test rendering the public worksheet view and asserting (a) the new width-gate marker is present, (b) the old unconditional destructive-reset comment is gone, (c) captureSignature is wired to stroke-end listeners"
  key_links:
    - from: "window resize listener"
      to: "resizeCanvas() / captureSignature()"
      via: "widthChanged guard comparing getBoundingClientRect().width to lastWidth"
      pattern: "widthChanged"
    - from: "end(evt) stroke handler"
      to: "signature_image hidden input"
      via: "captureSignature() writing canvas.toDataURL('image/png')"
      pattern: "captureSignature"
---

<objective>
Stop the public worksheet signature pad from being silently wiped by mobile `resize` events
(on-screen keyboard open/close, iOS Safari URL-bar collapse) before the client ever gets to
submit — the exact failure that lost an engineer's signature on Worksheet 22
(`21CQ30674-03-OPS`, 2026-09-18).

Purpose: `resources/views/worksheets/public-show.blade.php` (~:1570-1577) currently treats
every `window resize` event as a real layout change and unconditionally clears the canvas,
`dirty`, `window.__signoffSignatureDrawn`, and the hidden `signature_image` input. On mobile,
`resize` fires for viewport-height-only changes (keyboard, URL-bar collapse) far more often
than for genuine width changes. The form's own field order (name → signature → checkboxes →
comments textarea) makes hitting this near-unavoidable: draw a signature, tick "outstanding
items", type in comments, keyboard opens, signature gone — with no error until submit is
blocked.

Output: a resize handler that only rebuilds/resets when the canvas's CSS *width* actually
changes (the only thing that makes the existing backing store misaligned), and — for that
genuine-resize case — snapshots the drawn bitmap and redraws it into the rebuilt canvas
instead of discarding it. A `captureSignature()` helper writes the PNG into the hidden input
at the end of every stroke, so no intervening reset (this one or a future one) can silently
empty the field submit relies on. Server-side `PublicWorksheetController::sign()` and the
existing checkbox/comments gates are untouched.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/STATE.md

<interfaces>
<!-- Read-only reference: current buggy block and the surrounding signature-pad IIFE,
resources/views/worksheets/public-show.blade.php, roughly :1460-1600. Executor should
edit in place — do not restructure the IIFE or rename existing globals
(window.clearSignature, window.prepareSignoff, window.refreshSignoffSubmitState,
window.__signoffSignatureDrawn) since other code in this file and the submit handler
depend on those exact names. -->

Current state (the bug), inside the signature-pad IIFE:
```js
let drawing = false;
let dirty   = false;
let lastX = 0, lastY = 0;

function resizeCanvas() {
    const ratio = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();
    canvas.width  = Math.max(1, Math.round(rect.width * ratio));
    canvas.height = Math.max(1, Math.round(rect.height * ratio));
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    ctx.fillStyle   = '#ffffff';
    ctx.fillRect(0, 0, rect.width, rect.height);
    ctx.lineWidth   = 2.2;
    ctx.lineCap     = 'round';
    ctx.lineJoin    = 'round';
    ctx.strokeStyle = '#0F172A';
}
```
```js
function end(evt) {
    if (drawing) evt.preventDefault();
    drawing = false;
}
```
```js
requestAnimationFrame(resizeCanvas);
window.addEventListener('resize', () => {
    // Reset on resize — drawing on resized canvas would be misaligned.
    resizeCanvas();
    dirty = false;
    window.__signoffSignatureDrawn = false;
    window.refreshSignoffSubmitState();
    document.getElementById('signature_image').value = '';
});
```

`prepareSignoff(form)` (submit handler, unchanged by this plan) already does
`document.getElementById('signature_image').value = canvas.toDataURL('image/png');` as its
last step before `return true` — leave this line in place as a final safety net even though
per-stroke capture makes it redundant in the common case.

Design choice — width-comparison over `visualViewport`: `window.visualViewport` is not
reliably present across the older Android WebViews and iOS Safari versions field engineers
use, and it doesn't remove the need to measure the canvas anyway — the actual invariant that
matters is "did the canvas's rendered CSS width change", which is directly measurable from
`canvas.getBoundingClientRect().width` on any `resize` event regardless of what triggered it
(keyboard, URL-bar collapse, or a real viewport/orientation change). Comparing measured width
before/after is simpler, has no feature-detection branch, and covers all three trigger cases
with one guard.
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Width-gate the resize handler, preserve strokes on genuine resize, capture per stroke</name>
  <files>resources/views/worksheets/public-show.blade.php</files>
  <action>
Inside the signature-pad IIFE in `resources/views/worksheets/public-show.blade.php`:

1. Add `let lastWidth = 0;` alongside the existing `let drawing = false; let dirty = false;`
   declarations.

2. In `resizeCanvas()`, after the existing body (unchanged otherwise), add
   `lastWidth = rect.width;` as the final line so every call records the width it just
   rendered at.

3. Add a new `captureSignature()` function declared alongside the other helpers (before or
   after `pointerPos` — function declarations are hoisted, ordering only matters for
   readability): if `dirty` is true, write
   `document.getElementById('signature_image').value = canvas.toDataURL('image/png');`; if
   `dirty` is false, do nothing (no point capturing a blank canvas).

4. In `end(evt)`, call `captureSignature()` when a stroke actually completes (i.e. inside the
   `if (drawing)` branch, alongside `evt.preventDefault()`), so every pointerup/touchend/
   pointerleave that ends a real stroke writes the current bitmap into the hidden input
   immediately — per must-have 3 in this plan's frontmatter.

5. Replace the `window.addEventListener('resize', ...)` block with a width-gated version:
   - Read `canvas.getBoundingClientRect().width` into a local `newWidth`.
   - Compute `const widthChanged = Math.abs(newWidth - lastWidth) > 1;` (1px tolerance for
     sub-pixel rounding noise).
   - If `! widthChanged`, `return` immediately — do not call `resizeCanvas()`, do not touch
     `dirty`, `window.__signoffSignatureDrawn`, or the hidden input. This is the fix for
     keyboard-open/close and iOS URL-bar collapse, which change viewport height but not the
     canvas's CSS width.
   - If `widthChanged` (a genuine layout change): if `dirty` is true, snapshot the current
     bitmap first with `canvas.toDataURL('image/png')` inside a `try { } catch { }` (guard
     against a canvas-tainted/security-error edge case — treat a thrown error as "no
     snapshot available"). Call `resizeCanvas()` to rebuild the backing store at the new
     size. If a snapshot was captured, load it into a `new Image()` and, in its `onload`,
     `ctx.drawImage(img, 0, 0, newWidth, <new rect height>)` to redraw the prior strokes
     scaled into the rebuilt canvas, then call `captureSignature()` again so the hidden
     input reflects the redrawn bitmap. Do NOT reset `dirty` / `window.__signoffSignatureDrawn`
     in this branch — the signature survived. If no snapshot was captured (nothing was
     drawn, or the toDataURL call threw), fall back to the original reset behaviour: `dirty
     = false; window.__signoffSignatureDrawn = false; window.refreshSignoffSubmitState();
     document.getElementById('signature_image').value = '';`.
   - Add a short comment above the guard citing 260919-chb and explaining that
     `resize` fires for height-only viewport changes on mobile (keyboard, iOS Safari URL-bar
     collapse) far more often than for real width changes, so gating on measured width is
     what prevents the false-positive wipe.

Do not touch: the two-checkbox gate, `prepareSignoff()`'s validation order or alert text,
`clearSignature()` (still does a full reset — that's the correct behaviour when the user
explicitly asks to clear), `PublicWorksheetController::sign()`, or any Blade `@php`/`@if`
control structures elsewhere in the file. This is a pure JS edit inside the existing
`<script>` block — no new Blade directives, so the documented `@php`/`?->`/glued-`@if` parse
trap does not apply here, but compile the view anyway per the verify step below since this
file has caused parse failures before.
  </action>
  <verify>
    <automated>cd "C:\Users\sonny.tanda\Documents\1 - Claude Projects\Rams2\rams.21stcav.com" && powershell -Command "php artisan view:clear" && powershell -Command "php artisan tinker --execute=\"echo view('worksheets.public-show', ['worksheet' => \\App\\Models\\Worksheet::factory()->make(['generated_data' => ['rooms' => []]]), 'token' => 'x', 'latestSignoff' => null, 'photoCounts' => []])->render() !== '' ? 'COMPILED_OK' : 'EMPTY';\""</automated>
  </verify>
  <done>Blade view compiles without a parse/render error; the resize listener body contains the width-gate guard and no longer unconditionally resets on every resize; captureSignature() is called from end(evt).</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Regression test + full-suite baseline check</name>
  <files>tests/Feature/Worksheet/PublicWorksheetSignaturePadResizeTest.php</files>
  <behavior>
    - Test 1 (`test_public_worksheet_view_renders_with_width_gated_resize_handler`): GET the
      public worksheet show route for a worksheet with a valid, non-expired `access_token`;
      assert 200 and that the response body contains a marker proving the width-gate is wired
      up (e.g. the string `widthChanged`) — a render-level proxy for "the destructive
      unconditional reset was replaced", since there is no JS test harness in this project to
      actually execute the handler.
    - Test 2 (`test_public_worksheet_view_no_longer_contains_the_unconditional_resize_reset`):
      same rendered body must NOT contain the old destructive comment
      `'Reset on resize — drawing on resized canvas would be misaligned.'` verbatim, proving
      the naive reset-on-every-resize block was actually replaced and not just supplemented.
    - Test 3 (`test_public_worksheet_view_captures_signature_on_stroke_end`): same rendered
      body contains `captureSignature` (proves the helper exists) and the existing
      `prepareSignoff` / two-checkbox-gate JS is still present unchanged (assert
      `refreshSignoffSubmitState` and `signed_with_comments` still appear), so this plan
      didn't accidentally regress must-have 4 (checkbox/comments gates untouched).
    - Explicitly out of scope for automation, documented as a comment at the top of the test
      class: actually simulating a mobile on-screen keyboard opening (a `resize` event with
      height change but no width change) and asserting canvas pixel content survives cannot
      be done under PHPUnit — there is no browser/canvas runtime here. That is covered by the
      manual checklist in Task 3.
  </behavior>
  <action>
Create `tests/Feature/Worksheet/PublicWorksheetSignaturePadResizeTest.php` following the
existing `PublicWorksheetSignoffTest` pattern in the same directory (namespace
`Tests\Feature\Worksheet`, `use RefreshDatabase`, build a `Worksheet` with a real
`access_token` via `Worksheet::create(...)` or factory, then `$this->get(route('public-worksheet.show', ['token' => $worksheet->access_token]))`). Implement the three behaviors above
as three test methods, each asserting on `$response->getContent()`. Then run the full
existing regression suite to confirm nothing else broke:

Record the baseline (already known: 875 passing / 0 failed for `--filter=Rams`) and the
post-change result for both `--filter=Rams` and this new test class in the plan's SUMMARY.
  </action>
  <verify>
    <automated>cd "C:\Users\sonny.tanda\Documents\1 - Claude Projects\Rams2\rams.21stcav.com" && powershell -Command "php artisan test --filter=PublicWorksheetSignaturePadResizeTest" && powershell -Command "php artisan test --filter=Rams"</automated>
  </verify>
  <done>New test file exists with three passing tests proving the width-gate marker is present, the old unconditional destructive-reset comment is gone, and captureSignature exists alongside untouched checkbox/comments gate markers. `php artisan test --filter=Rams` still passes at 875+ (baseline 875/0, must not decrease) plus the new test class, 0 failed.</done>
</task>

<task type="checkpoint:human-verify" gate="blocking">
  <what-built>Width-gated resize handling and per-stroke signature capture on the public worksheet sign-off pad, deployed to a phone-accessible environment (local dev tunnel or staging — automation cannot simulate a real mobile keyboard/URL-bar event).</what-built>
  <how-to-verify>
    1. Open a public worksheet link on a real phone (iOS Safari and/or Android Chrome) — use a
       test worksheet token, not a live client worksheet.
    2. Scroll to "Client Sign-Off", draw a signature in the pad.
    3. Tick "Outstanding items — list them in the comments below."
    4. Tap into the "Outstanding Items / Comments" textarea to open the on-screen keyboard.
    5. Confirm the signature is still visible in the pad (not wiped).
    6. Type a comment, dismiss the keyboard, and confirm the signature is still visible.
    7. Tap "Sign & Submit" and confirm it succeeds (redirects with the "Thank you" success
       flash) — this proves signature_image was non-empty at submit time.
    8. Repeat once more but rotate the device (portrait to landscape) mid-draw after drawing a
       partial signature — confirm the signature is still present (possibly rescaled) rather
       than blanked, proving the genuine-resize snapshot/redraw path works.
  </how-to-verify>
  <resume-signal>Type "approved" once both flows (keyboard-open and rotation) preserve the signature and submission succeeds, or describe what broke.</resume-signal>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Engineer's mobile browser -> `POST /worksheet/{token}/sign` | Untrusted client input (name, signature PNG, checkboxes, comments) crosses into the server; token-gated, no auth session. Unchanged by this plan. |
| Client-side JS internal state (canvas bitmap, hidden input) | Not a network boundary, but the exact thing this fix touches — a client-side reliability bug that produced application-layer denial (engineer unable to complete a required workflow step), not a security compromise. |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-chb-01 | Tampering | `signature_image` POST field | accept | Client fully controls the base64 PNG content sent; this was already true before this plan and is unchanged. Server only validates presence/type (`required, string`) — content trust is inherent to a signature-capture UX and out of scope for a client-reliability fix. |
| T-chb-02 | Denial of Service (client-side) | Per-stroke `canvas.toDataURL()` call in `captureSignature()` | accept | Bounded by human stroke count on a small (220px-tall) canvas; toDataURL on a canvas this size is sub-millisecond. No network call is made, so there is no server-side cost and no way to trigger it remotely at volume. |
| T-chb-03 | Tampering / Spoofing (self-XSS via data URI) | `new Image(); img.src = snapshot` in the resize-preserve branch | accept | `snapshot` is produced exclusively by this same script's own `canvas.toDataURL('image/png')` call one line earlier — never user-supplied text, never derived from URL/query/DOM input an attacker controls. No injection surface introduced. |
| T-chb-04 | Repudiation | Snapshot/redraw preserving a signature across a genuine resize | accept | The final bitmap submitted is still whatever the engineer's last completed stroke drew (redraw is a lossless raster copy, not a content edit); `signature_png_base64` persisted server-side is unchanged in meaning — still "the signature as drawn". |

</threat_model>

<verification>
1. `php artisan view:clear && php artisan tinker --execute="..."` (Task 1 verify) confirms the Blade file still compiles after the edit — this file has a documented history of parse failures from `@php`/`?->`/glued-`@if`, so compile-checking every edit is mandatory even though this change is pure JS.
2. `php artisan test --filter=PublicWorksheetSignaturePadResizeTest` — new regression tests pass, proving the destructive-reset text is gone and the new guard/capture markers are present in rendered output.
3. `php artisan test --filter=Rams` — full existing suite baseline (875 passing / 0 failed) is preserved or improved (876+/0), proving no regression to the two-checkbox gate, comments-required rule, or server-side sign() validation.
4. Manual phone checklist (Task 3, human-verify checkpoint) — the one thing automation cannot cover: a real on-screen keyboard event preserving a drawn signature, and a real orientation-change resize preserving it via snapshot/redraw.
</verification>

<success_criteria>
- Opening the comments textarea (or any keyboard-triggering focus) after drawing a signature no longer clears the canvas, `dirty`, or `signature_image` — confirmed both by the render-level regression test (no unconditional reset in the resize handler) and the manual phone checklist.
- A genuine width-changing resize (device rotation) preserves the drawn signature via snapshot + redraw rather than wiping it.
- `signature_image` is populated at the end of every stroke, not only at submit.
- `php artisan test --filter=Rams` remains at 0 failed.
- No changes to `PublicWorksheetController::sign()`, the two-checkbox gate, or the comments-required-when-outstanding rule.
</success_criteria>

<output>
Create `.planning/quick/260919-chb-fix-worksheet-signature-pad-wiped-by-mob/260919-chb-SUMMARY.md` when done, recording before/after `--filter=Rams` counts and the manual phone-test outcome.
</output>
