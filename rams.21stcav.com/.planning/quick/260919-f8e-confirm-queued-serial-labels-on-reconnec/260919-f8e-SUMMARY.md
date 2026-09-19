---
phase: quick
plan: 260919-f8e
subsystem: worksheets
tags: [offline-queue, label-capture, sessionStorage, blade]
requires: []
provides:
  - "sessionStorage-backed pending-label-confirm queue (wsPendingLabelConfirms_{worksheetId})"
  - "auto-prompt of the existing openLabelReview confirm/edit modal on queued label upload"
affects:
  - resources/views/worksheets/public-show.blade.php
tech-stack:
  added: []
  patterns:
    - "OfflineQueue.drain({ onSuccess }) callback bridges IndexedDB drain results to sessionStorage state"
    - "window.__lc* globals expose IIFE-private queue helpers across separate <script> tag scopes"
key-files:
  created:
    - tests/Feature/Worksheet/PublicWorksheetLabelConfirmOnReconnectTest.php
  modified:
    - resources/views/worksheets/public-show.blade.php
decisions:
  - "Exposed _lcQueueRead/_lcQueueShift as window.__lcQueueRead/__lcQueueShift rather than making them bare global functions, to keep the new IIFE's other internals private while still letting openLabelReview (a separate top-level scope in an earlier <script> tag) reach them"
metrics:
  duration: "~35m (Tasks 1-2)"
  completed: "2026-09-19"
---

# Quick Task 260919-f8e: Confirm queued serial labels on reconnect Summary

Wires OfflineQueue.drain()'s existing `onSuccess` callback to a new sessionStorage-backed
pending-label-confirm queue that auto-opens the existing AI-extraction confirm/edit modal
(`openLabelReview`) once an offline-queued serial label photo finishes uploading — closing the
gap where confirmLabelPhoto was never called and the AI-read values sat silently unconfirmed.

## What Was Built

**Task 1 — Queue + auto-prompt wiring** (`resources/views/worksheets/public-show.blade.php`):

- New trailing `<script>` IIFE (`260919-f8e`) defining a sessionStorage queue keyed
  `wsPendingLabelConfirms_{worksheetId}` (mirrors the existing `wsState_` pattern), with
  read/write/push (dedupe by photoId)/shift helpers, a `_lcModalOpen` one-at-a-time guard, and
  a `_lcMaybePrompt()` that opens `openLabelReview({..., queued: true, onOverlayRemoved})` for
  the head of the queue.
- `window.__lcHandleLabelUploadSuccess(row, json)` — the `onSuccess` callback passed into
  `drain()`; pushes `{photoId, token, photoUrl, extracted}` onto the queue for `kind === 'label'`
  rows only.
- `window.__lcMaybePrompt()` — public entry point; shows an informational toast (via the
  existing `__wsShowToast`) then calls the internal prompt function.
- One-shot check on `DOMContentLoaded` (or immediately if the document already finished
  loading) — this is what makes the prompt survive an unrelated page reload; no polling loop.
- `window.__lcQueueRead` / `window.__lcQueueShift` exposed so `openLabelReview`, which is a
  top-level function declared in an earlier, separate `<script>` tag, can advance the queue
  directly (classic scripts share the global object, but the new IIFE's other internals stay
  private).
- `openLabelReview` extended with optional `queued` / `onOverlayRemoved` params:
  - Cancel: removes overlay, calls `onOverlayRemoved`, and — only when `queued` — shifts the
    queue and re-prompts for the next item. When `queued` is falsy, behaviour is byte-for-byte
    identical to before (overlay removed, nothing else).
  - Confirm: on success, when `queued` — removes overlay, calls `onOverlayRemoved`, shifts the
    queue, then either re-prompts (queue non-empty, no reload — avoids an N-item reload flash)
    or reloads once (queue empty, same final refresh as today). When `queued` is falsy, calls
    the pre-existing `close()` exactly as before (reload on every confirm).
- All three `drain({})` call sites (`_autoDrain`, `#pending-retry-all`, delegated per-item
  `data-act="retry"`) now pass `onSuccess: window.__lcHandleLabelUploadSuccess` and call
  `window.__lcMaybePrompt()` after their existing toast logic. The fourth, unrelated
  `drain({})` call inside the sign-off flush-and-warn flow (`~:1624`, from `a03dc2e7`) was left
  untouched — it is not one of the three sites this plan targets and has no label-upload
  semantics.

**Task 2 — Regression test** (`tests/Feature/Worksheet/PublicWorksheetLabelConfirmOnReconnectTest.php`):

Render-level assertions (no browser/IndexedDB runtime under PHPUnit) proving: the new
queue/prompt markers exist; all three drain() call sites pass `onSuccess:
window.__lcHandleLabelUploadSuccess` (asserted via a `substr_count >= 3` check); `openLabelReview`
threads `queued`/`onOverlayRemoved`; and pre-existing `captureLabel`/`reviewLabel`/confirm-URL/
`OfflineQueue.enqueue`/`DB_VERSION` markers are textually unregressed.

## Highest-Risk Verification (existing openLabelReview callers)

Read both existing call sites directly (`resources/views/worksheets/public-show.blade.php:1869`
captureLabel's online success path, and `:1899` manual `reviewLabel`). Neither passes a `queued`
key, so `opts.queued` is `undefined` (falsy) for both. Traced both branches of the edited Cancel
and Confirm handlers: when `queued` is falsy, Cancel is unchanged (`overlay.remove()` only — the
new `onOverlayRemoved && onOverlayRemoved()` call is a no-op since neither caller passes it), and
Confirm falls through to the original `close()` call, reloading exactly as before. Confirmed this
is provably safe rather than assumed.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Scope mismatch: `_lcQueueShift`/`_lcQueueRead` unreachable from `openLabelReview`**
- **Found during:** Task 1, before running verification
- **Issue:** The plan's literal surgical-edit text calls `_lcQueueShift()` / `_lcQueueRead()`
  directly from inside `openLabelReview`. But `openLabelReview` is a top-level function
  declaration in an earlier, separate `<script>` tag (global scope, after the signature-pad
  IIFE at `~:1462-1684` closes), while the new queue helpers were declared inside the new
  trailing IIFE — i.e. genuinely private to that IIFE's closure, not reachable by bare name from
  a different script tag's scope. Calling them as written would throw `ReferenceError` at
  runtime the first time Cancel or Confirm ran in queued mode.
- **Fix:** Exposed the two needed helpers as `window.__lcQueueRead` / `window.__lcQueueShift`
  inside the new IIFE, and changed the two call sites inside `openLabelReview` to
  `window.__lcQueueShift && window.__lcQueueShift()` / `window.__lcQueueRead && window.__lcQueueRead()`
  (guarded, consistent with the existing `window.__lcMaybePrompt && window.__lcMaybePrompt()`
  style already used elsewhere in this plan). All other queue internals
  (`_lcQueueKey`, `_lcQueueWrite`, `_lcQueuePush`, `_lcModalOpen`, `_lcMaybePrompt`) remain
  private to the new IIFE, matching the plan's intent that most of this state stay
  module-scoped.
- **Files modified:** `resources/views/worksheets/public-show.blade.php`
- **Commit:** `21f829e4`

### None Other

No other deviations. Plan executed as written apart from the scope fix above.

## Test Results

- Baseline (read fresh before Task 1): `php artisan test --filter=Worksheet` → **239 passed, 0 failed**.
- After Task 1 (`view:clear` + full filter): **239 passed, 0 failed** — view compiles, no
  Blade parse error, no regression.
- After Task 2 new test alone: **5 passed** (17 assertions).
- After Task 2, full filter: **244 passed, 0 failed** (239 + 5 new tests). Matches plan's
  "239 + N passing, 0 failed" requirement.

## Known Stubs

None.

## Threat Flags

None — this plan reuses the existing `confirmLabelPhoto` endpoint and existing DOM/JS
constructs exactly as documented in the plan's own threat model (T-f8e-01 through T-f8e-04);
no new endpoint, auth path, or schema surface was introduced.

## Self-Check: PASSED

- FOUND: `resources/views/worksheets/public-show.blade.php` (modified, verified via `git log`/diff)
- FOUND: `tests/Feature/Worksheet/PublicWorksheetLabelConfirmOnReconnectTest.php`
- FOUND commit `21f829e4` (Task 1, `feat(260919-f8e): auto-prompt confirm for queued serial labels on reconnect`)
- FOUND commit `db98b2c6` (Task 2, `test(260919-f8e): add render-level regression coverage for reconnect confirm queue`)
