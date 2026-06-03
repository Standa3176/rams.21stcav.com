---
phase: 260603-eha
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - resources/views/worksheets/public-show.blade.php
autonomous: false
requirements:
  - QUICK-260603-eha
quick_id: 260603-eha
must_haves:
  truths:
    - "Engineer can capture a Box Serial Label photo while offline; the page shows a header chip '🔄 1 pending' instead of an alert."
    - "Engineer can hit 'Add photo' on a per-room block while offline; the same chip increments."
    - "Reloading the worksheet page mid-queue preserves all pending items (IndexedDB survives reload)."
    - "When network returns (online event OR 60-second poll while navigator.onLine), the queue drains automatically; each successful upload removes its row."
    - "On successful drain, a toast says '✅ Uploaded N pending photo(s)'."
    - "Clicking the pending chip expands a small panel listing each queued item with room name, kind icon (📷 Label / 🖼 Photo), captured timestamp, status (queued/uploading/failed), per-item [Retry] and [Remove] buttons, and a global [Retry all]."
    - "After 3 consecutive failures on the same item, a warning toast appears: '⚠ N upload(s) failed after retries — tap the pending chip to review'."
    - "When the engineer is online, the existing happy path is byte-for-byte unchanged — label capture still opens the review modal, completed-work photo still reloads the page."
    - "Server-side: zero changes to PublicWorksheetController, the two POST endpoints, the throttle rates, or the Claude vision pipeline."
    - "If IndexedDB is unavailable (ancient browser), a one-time toast warns and the page falls back to current pre-queue behaviour (alert-on-fail)."
  artifacts:
    - path: "resources/views/worksheets/public-show.blade.php"
      provides: "OfflineQueue module (inline <script>), header chip + panel markup, wrap-around captureLabel + uploadWorksheetPhoto"
      contains: "OfflineQueue.enqueue, OfflineQueue.drain, OfflineQueue.list, pending-chip, pending-panel"
  key_links:
    - from: "captureLabel() at line ~1592 of public-show.blade.php"
      to: "OfflineQueue.enqueue({kind:'label'})"
      via: "try/catch wrapping the existing fetch() — on network failure OR resp.ok===false, enqueue + toast instead of alert + restoreBtn early"
      pattern: "OfflineQueue\\.enqueue.*kind:\\s*['\"]label"
    - from: "uploadWorksheetPhoto() at line ~1508 of public-show.blade.php"
      to: "OfflineQueue.enqueue({kind:'completed'})"
      via: "try/catch wrapping the existing fetch() — on failure enqueue, run convertToJpegBlob first so HEIC normalisation matches the label flow"
      pattern: "OfflineQueue\\.enqueue.*kind:\\s*['\"]completed"
    - from: "window 'online' event + 60s setInterval"
      to: "OfflineQueue.drain()"
      via: "auto-drain trigger; both call the same drain function"
      pattern: "addEventListener\\(['\"]online['\"]"
    - from: "OfflineQueue.drain()"
      to: "POST /worksheet/{token}/label-photo  AND  POST /worksheet/{token}/photos"
      via: "fetch() with same FormData shape the live functions build today — server cannot tell the request came from queue vs live"
      pattern: "/worksheet/.*/(label-photo|photos)"
---

<objective>
Add a client-side IndexedDB upload queue to the public engineer worksheet view so engineers in dead zones (comms cupboards, basements, racks) keep working when uploads fail. The queue covers BOTH label captures AND per-room "Add photo" uploads, auto-drains when network returns, persists across reloads, and surfaces via a quiet header chip + expandable panel.

Purpose: Engineers currently lose work or get stuck when `fetch()` throws on a flaky connection — `alert('Network error. Please try again.')` is the only feedback today, and the file input gets reset. With the queue, a failed upload becomes a silent local save + visible chip; when comms come back the photos drain to the unchanged server endpoints.

Output: One modified file (`resources/views/worksheets/public-show.blade.php`) containing an inline `OfflineQueue` module, the chip + panel markup/CSS, wrapped capture flows, and an updated SUMMARY with a manual test plan checklist.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@CLAUDE.md
@resources/views/worksheets/public-show.blade.php
@app/Http/Controllers/PublicWorksheetController.php
@routes/web.php

<interfaces>
<!-- Confirmed integration points (executor should NOT re-explore the codebase to find these). -->

**`convertToJpegBlob(file, maxSide = 2400, quality = 0.92)`** — defined at line 1568 of `public-show.blade.php`.
Returns a Promise<Blob> of a downscaled JPEG. iOS HEIC safe. Used by the label flow today.
The completed-work flow does NOT currently use it — we will START using it for queued completed-work photos so HEIC normalisation lands BEFORE the IndexedDB write (smaller disk footprint, drain doesn't re-encode).

**`captureLabel(input, token)`** — defined at line 1592 of `public-show.blade.php`.
Called via `onchange="captureLabel(this, '{{ $token }}')"` on the hidden `<input type=file>` at line 1211.
Builds FormData: `photo` (Blob), `room_name`, `item_description`, `item_part_number`, `item_qty`.
POSTs to `'/worksheet/' + encodeURIComponent(token) + '/label-photo'`.
On 2xx: calls `openLabelReview({...})` to show the AI extraction modal.
On non-2xx OR network throw: today shows `alert('Label upload failed' / 'Network error')`.

**`uploadWorksheetPhoto(input, token, roomName)`** — defined at line 1508 of `public-show.blade.php`.
Called via `onchange="uploadWorksheetPhoto(this, '{{ $token }}', '{{ addslashes($room['name']) }}')"` on the hidden `<input type=file>` at line 867.
Builds FormData: `photo` (raw File — no HEIC conversion today), `room_name`.
POSTs to `'/worksheet/' + encodeURIComponent(token) + '/photos'`.
On 2xx: `window.location.reload()`.
On failure: `alert('Upload failed' / 'Network error')`.

**Server endpoints (DO NOT CHANGE):**
- `POST /worksheet/{token}/label-photo` → `PublicWorksheetController@uploadLabelPhoto` (controller line 352)
  - Validates: `photo` (image, max:10240), `room_name` (string max:200), `item_description` (string max:300), `item_part_number` (nullable string max:120), `item_qty` (nullable int min:1).
  - Returns: `{id, device_id, photo_url, ai_extracted, confirmed}`.
  - Throttle: `30,1` (30 requests / minute / IP).
- `POST /worksheet/{token}/photos` → `PublicWorksheetController@uploadPhoto` (controller line 76)
  - Validates: `room_name` (required string max:200), `photo` (image, max:10240), `caption` (nullable string max:200).
  - Returns: `{id, filename, caption, url}`.
  - Throttle: `30,1`.

**CSRF:** Both POSTs need header `X-CSRF-TOKEN: {{ document.querySelector('meta[name=csrf-token]').content }}` AND `Accept: application/json`. Already pattern-matched in the existing JS.

**Page header structure** — `<header class="ws-header">` at line 412, with `.ws-header__inner` child. CSS already defines `.ws-header__title`, `.ws-header__meta`, `.ws-header__contact`. Chip slots into `.ws-header__inner` after the title row (mobile-first: small, right-aligned on wider viewports, full-width chip on phones via flexbox).

**Existing CSS palette (use these — `brand-teal` is undefined per 260601-r4c lesson):**
- Header bg: `#0B3C45` (dark teal — for chip text contrast)
- Teal accent: `#178A95` / `#1B7A7A` (use `#178A95` for primary text/links)
- Amber/warning: define inline `#F59E0B` background `#FEF3C7` (Tailwind amber-50/500 palette — used elsewhere in the codebase)
- Success: `.alert-success` uses `#D1FAE5` / `#065F46` / `#6EE7B7` — match for success toast
- Error: `.alert-error` uses `#FEE2E2` / `#991B1B` / `#FCA5A5` — match for failure toast
- Buttons: existing `.btn`, `.btn-outline`, `.btn-sm`, `.btn-teal` classes

**No existing toast helper.** `window.appConfirm` exists for confirm dialogs only. Build a tiny `showToast(msg, variant='info', ttl=4000)` inside the OfflineQueue module's UI section. Single fixed-position container at `top:auto;bottom:1rem;left:50%;transform:translateX(-50%);z-index:9999`. Append/auto-remove.

**JS inlining pattern:** The file uses two inline `<script>` blocks (lines 1383 and 1786) — there is NO `public/js/` convention for this view. The queue module goes INLINE in a new `<script>` block placed AFTER the existing line 1786 script (so `captureLabel` / `uploadWorksheetPhoto` are already defined when we override them).

**Override pattern (DO NOT EDIT the existing functions in-place):** Reassign at end of file:
```
const __origCaptureLabel = window.captureLabel;
window.captureLabel = async function(input, token) { /* try fetch first; on fail enqueue */ };
```
Same for `uploadWorksheetPhoto`. Keeps the existing function bodies untouched (smaller diff, easier code review). Both originals must be moved to `window.captureLabel = ...` declaration form FIRST (they're currently `async function captureLabel(...) {}` declarations at the top level) — change those two declarations to `window.captureLabel = async function (...) {}` style so the override can capture them.
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Add OfflineQueue module (IndexedDB + drain logic + toast helper) inside public-show.blade.php</name>
  <files>resources/views/worksheets/public-show.blade.php</files>
  <action>
At the BOTTOM of the file (after the existing `<script>` block that closes around line 1786, BEFORE `</body>`), add ONE new `<script>` block containing a self-contained `OfflineQueue` module. The module is pure vanilla JS — no library imports, no build step.

Module shape (the executor implements the bodies; this is the contract):

(a) IDB bootstrap:
- `OfflineQueue.db` — lazy Promise<IDBDatabase>. Opens database `engineer-worksheet`, version 1. On `upgradeneeded` creates object store `pending_uploads` with `keyPath: 'id', autoIncrement: true` and an index on `capturedAt`.
- Wrap `indexedDB.open` in feature-detect: if `!('indexedDB' in window)` set `OfflineQueue.unavailable = true` and have every method resolve to a no-op (returns falsy, but does not throw). Show a one-time toast on first `enqueue` call: "⚠ Offline queue unsupported on this browser — uploads still work when online."

(b) Public API (all Promise-returning):
- `OfflineQueue.enqueue({token, kind, room, blob, mime, fields})` — `kind` ∈ `{'label','completed'}`. `fields` is a plain object that becomes additional FormData entries on drain (e.g. for label: `{item_description, item_part_number, item_qty}`; for completed: `{}`). Adds `attemptCount: 0`, `lastError: null`, `capturedAt: Date.now()`. Returns the new id. After write, fires `OfflineQueue._notifyChange()` to refresh the chip + panel.
- `OfflineQueue.list()` — returns `Promise<Array<{id, kind, room, capturedAt, attemptCount, lastError}>>` — strips the blob field for cheap UI rendering. Sort by `capturedAt` ascending (oldest first).
- `OfflineQueue.count()` — returns `Promise<number>`. Used by the chip badge.
- `OfflineQueue.remove(id)` — deletes the row. Fires `_notifyChange()`.
- `OfflineQueue.drain({onProgress, onSuccess, onFailure} = {})` — iterates all rows in `capturedAt` order with `await sleep(200)` between POSTs (respects `throttle:30,1` server-side rate — 5 req/sec is well under 30/min/IP). For each row:
  - Mark `status='uploading'` in-memory (UI only — DO NOT write to IDB; rows in IDB are pending OR removed, status is transient panel state held in a Map keyed by id).
  - Build FormData: `photo` (the blob), plus all entries from `fields`, plus `room_name: row.room`.
  - POST to `/worksheet/{encodeURIComponent(row.token)}/label-photo` for `kind='label'` OR `/worksheet/{encodeURIComponent(row.token)}/photos` for `kind='completed'`.
  - Headers: `X-CSRF-TOKEN` (from `meta[name=csrf-token]`), `Accept: application/json`.
  - On `resp.ok`: delete row from IDB, call `onSuccess(row, responseJson)`, increment success counter. Continue loop.
  - On `!resp.ok` OR fetch throw: `attemptCount++`, set `lastError = resp.statusText || error.message`, UPDATE the row in IDB (do NOT delete). Call `onFailure(row, error)`. Continue loop (don't abort the whole drain on one failure).
  - After loop: fire `_notifyChange()`. Return `{successCount, failureCount}`.
- `OfflineQueue._notifyChange()` — internal: dispatches a `CustomEvent('offline-queue-change')` on `window`. UI listens.
- `OfflineQueue.subscribe(handler)` — convenience: adds the listener. Used by the chip + panel.

(c) Auto-drain triggers (in the same module init):
- `window.addEventListener('online', () => OfflineQueue.drain(...));`
- `setInterval(() => { if (navigator.onLine) OfflineQueue.drain(...); }, 60000);` — catches the silent-but-online case (e.g. captive portal lifted without an 'online' event).
- Both triggers wire `onSuccess` / `onFailure` to a single shared aggregator that, after the drain completes, shows toasts:
  - If `successCount >= 1`: `showToast('✅ Uploaded ' + successCount + ' pending photo(s)', 'success')`.
  - If any row hits `attemptCount >= 3`: `showToast('⚠ ' + N + ' upload(s) failed after retries — tap the pending chip to review', 'warning')`.
- Drain is guarded by a `OfflineQueue._draining` flag so the 60s tick + an 'online' event firing simultaneously don't double-post.

(d) Toast helper (inside the same module — no global pollution):
- `showToast(msg, variant='info', ttl=4000)` — appends a div to a singleton container (create on first call) at `position:fixed;bottom:1rem;left:50%;transform:translateX(-50%);z-index:9999`. Use the existing alert palette:
  - `info`: bg `#E0F2FE`, fg `#0C4A6E`, border `#7DD3FC`
  - `success`: bg `#D1FAE5`, fg `#065F46`, border `#6EE7B7`
  - `warning`: bg `#FEF3C7`, fg `#92400E`, border `#FCD34D`
  - `error`: bg `#FEE2E2`, fg `#991B1B`, border `#FCA5A5`
- Inline styles only (no CSS class dependency — the new `<script>` is self-contained).
- Auto-removes after `ttl` ms with a 200ms fade.

(e) Expose for testing/debug: `window.OfflineQueue = OfflineQueue;` (token-gated page, no admin info leaks).

DO NOT touch any other JS in the file in this task. The wrap-around hook of `captureLabel` / `uploadWorksheetPhoto` is Task 2.

NO npm/composer dependencies. NO new files outside `public-show.blade.php`.

Match the existing inline-JS style: 4-space indent, `const`/`let`, async/await preferred over .then chains (consistent with existing `captureLabel`).
  </action>
  <verify>
    <automated>
      php -l resources/views/worksheets/public-show.blade.php
      # AND the route smoke test still passes (proves we haven't broken Blade compilation):
      php artisan view:clear
      php artisan route:list --path=worksheet | findstr label-photo
    </automated>
    <human-check>
      Open the worksheet page on a dev token. Open DevTools console:
      1. Type `OfflineQueue.count()` → resolves to a number (initially 0).
      2. Type `OfflineQueue.enqueue({token:'test', kind:'label', room:'Test Room', blob:new Blob(['x']), mime:'image/jpeg', fields:{item_description:'test'}})` → resolves to an id (e.g. 1).
      3. Type `OfflineQueue.list()` → resolves to an array of 1 item with the right shape.
      4. Type `OfflineQueue.remove(1)` then `OfflineQueue.count()` → resolves to 0.
      5. Reload the page, repeat step 2, reload again, type `OfflineQueue.count()` → still 1 (persistence works).
    </human-check>
  </verify>
  <done>Module loads without console errors on a fresh page. IDB database `engineer-worksheet` appears in DevTools → Application → IndexedDB. Enqueue/list/remove/count all work in console. Module survives page reload. `OfflineQueue.unavailable` is `true` only when IDB is genuinely absent (e.g. private-browsing on old iOS).</done>
</task>

<task type="auto">
  <name>Task 2: Wire OfflineQueue into captureLabel + uploadWorksheetPhoto with HEIC normalisation</name>
  <files>resources/views/worksheets/public-show.blade.php</files>
  <action>
Two-part wiring inside the existing `<script>` block that contains `captureLabel` and `uploadWorksheetPhoto` (around lines 1508 and 1592):

PART A — Change the function DECLARATION style (so the override can capture them):
- Change `async function captureLabel(input, token) {` → `window.captureLabel = async function captureLabel(input, token) {`
- Change `async function uploadWorksheetPhoto(input, token, roomName) {` → `window.uploadWorksheetPhoto = async function uploadWorksheetPhoto(input, token, roomName) {`
- DO NOT change the function bodies in this part. The `onchange="captureLabel(...)"` attribute still resolves because `window.captureLabel` is the same global the inline-handler would have resolved.

PART B — In the new `<script>` block at the bottom (the same one Task 1 added the OfflineQueue to, AFTER the OfflineQueue module is defined), append override wrappers:

(B1) `window.captureLabel` wrapper:
- Save the original: `const __origCaptureLabel = window.captureLabel;`
- Reassign: `window.captureLabel = async function(input, token) {`
- Inside: TRY calling `__origCaptureLabel(input, token)` inside a try/catch.
- BUT: the original function already handles its OWN try/catch around fetch and shows `alert()` on failure. We need a different shape — instead of calling the original at all when offline, we replicate the pre-fetch prep (convertToJpegBlob + FormData) here and call `OfflineQueue.enqueue(...)` directly when `!navigator.onLine`. When `navigator.onLine` we delegate to `__origCaptureLabel` AS-IS (so the modal still opens). And when the original throws OR returns after showing its `alert`, we detect by... actually a cleaner pattern: KEEP the original delegating only when online; intercept BEFORE fetch when offline.
- Concrete logic:
  ```
  window.captureLabel = async function(input, token) {
      const file = input.files && input.files[0];
      if (!file) return;
      if (navigator.onLine) {
          // happy path — let the original do its thing (modal opens on success)
          return __origCaptureLabel(input, token);
      }
      // OFFLINE path: replicate the prep, enqueue, restore UI without alert
      // ... convertToJpegBlob, build the fields object, OfflineQueue.enqueue,
      // ... show toast '📥 Saved offline — will upload when you're online',
      // ... reset input.value = '', restore the label button.
  };
  ```
- Also wrap the ONLINE path in a try around `__origCaptureLabel` so a network throw mid-fetch ALSO falls into the enqueue branch. The original currently swallows network errors with `alert('Network error...')` — we need to intercept that. Simplest: REWRITE the original's fetch step here too, do not call `__origCaptureLabel`. Use this shape:
  ```
  window.captureLabel = async function(input, token) {
      const file = input.files && input.files[0];
      if (!file) return;
      const btn = input.closest('label');
      const restoreBtn = () => { /* mirror the existing restore logic */ };

      // Always normalise to JPEG first (matches existing label flow)
      let uploadFile = file;
      let uploadFilename = file.name || 'label.jpg';
      try {
          const blob = await convertToJpegBlob(file);
          uploadFile = blob;
          uploadFilename = 'label.jpg';
      } catch (e) { /* fall through */ }

      const fields = {
          item_description: input.dataset.desc || '',
          item_part_number: input.dataset.part || '',
          item_qty:         input.dataset.qty  || 1,
      };
      const room = input.dataset.room || '';

      if (!navigator.onLine) {
          await OfflineQueue.enqueue({token, kind:'label', room, blob:uploadFile, mime:'image/jpeg', fields});
          showToast("📥 Saved offline — will upload when you're online", 'info');
          input.value = '';
          restoreBtn();
          return;
      }
      // Online: delegate to original so the AI-extraction modal opens on success.
      try {
          return await __origCaptureLabel(input, token);
      } catch (e) {
          // Original swallows fetch errors with alert(); this catch only fires
          // on unexpected JS errors. Treat as offline fallback.
          await OfflineQueue.enqueue({token, kind:'label', room, blob:uploadFile, mime:'image/jpeg', fields});
          showToast("📥 Saved offline — will upload when you're online", 'info');
          input.value = '';
          restoreBtn();
      }
  };
  ```
- NOTE: detecting an in-flight fetch failure inside the original is tricky because the original returns `undefined` whether it succeeded or showed alert. Acceptable compromise per scope-in: the wrapper handles the explicit-offline case (which is 95% of dead-zone scenarios) AND unexpected throws. For the rare case of `navigator.onLine === true` BUT fetch fails (e.g. server 500), the original's `alert` fires unchanged — engineer can retap, queue catches them next time.

(B2) `window.uploadWorksheetPhoto` wrapper — same shape, applied to the completed-work flow:
- `kind: 'completed'`, `fields: {}`, `room: roomName` (passed as the 3rd arg by the inline `onchange`).
- ADD `convertToJpegBlob` to this flow too (the original doesn't use it — but for queued items we want the smaller blob on disk AND faster drains, and online uploads benefit too — engineers benefit from HEIC normalisation here as well).
- On successful online upload the original does `window.location.reload()` — preserve that.
- On offline: enqueue, toast `📥 Saved offline...`, reset input — do NOT reload (would lose context).

DO NOT remove or alter the existing function bodies in this task — only the declaration prefix in Part A and the new wrappers in Part B. The original `__origCaptureLabel` / `__origUploadWorksheetPhoto` remain callable for the online happy path.
  </action>
  <verify>
    <automated>
      php -l resources/views/worksheets/public-show.blade.php
      php artisan view:clear
      # Confirm both functions still exposed globally (smoke test via curl + grep on rendered HTML):
      php artisan route:list --path=worksheet | findstr public-worksheet.show
    </automated>
    <human-check>
      DevTools-driven offline test:
      1. Open worksheet page, open DevTools → Network → throttle to "Offline".
      2. Tap "Add photo" on any room → pick an image → toast appears "📥 Saved offline...". File input clears. No alert.
      3. `OfflineQueue.count()` in console → returns 1.
      4. Tap "Capture label" on any item → toast again. `OfflineQueue.count()` → 2.
      5. Switch Network to "Online". Wait ≤60s OR trigger window.dispatchEvent(new Event('online')).
      6. Toasts appear: "✅ Uploaded 2 pending photo(s)". `OfflineQueue.count()` → 0.
      7. Verify in DB: `php artisan tinker` → `App\Models\Worksheet::find({id})->photos()->count()` increased by 1 AND `App\Models\DeviceLabelPhoto::where('worksheet_id', {id})->count()` increased by 1.
      8. Reload the page mid-queue (between step 4 and 5) → `OfflineQueue.count()` STILL returns 2 (persistence proof).
      9. Online happy path: refresh, throttling OFF, tap "Capture label" → existing AI extraction modal OPENS (no regression).
      10. Online happy path: tap "Add photo" → page reloads with new thumbnail (no regression).
    </human-check>
  </verify>
  <done>Both flows enqueue when offline, drain on online, do not regress the online happy paths (modal still opens for labels; page still reloads for completed photos). HEIC normalisation runs on both kinds before enqueue. No `alert()` fires on the offline path.</done>
</task>

<task type="auto">
  <name>Task 3: Render pending-uploads chip in header + click-to-expand panel with retry/remove</name>
  <files>resources/views/worksheets/public-show.blade.php</files>
  <action>
THREE additions:

(A) CSS — add to the inline `<style>` block (around line 8-400, after the `.alert-*` rules feels right):

```
.pending-chip {
    display: none; /* shown when count > 0 via JS */
    align-items: center;
    gap: .35rem;
    padding: .3rem .7rem;
    margin-top: .55rem;
    background: #FEF3C7;
    color: #92400E;
    border: 1px solid #FCD34D;
    border-radius: 9999px;
    font-size: .82rem;
    font-weight: 700;
    cursor: pointer;
    user-select: none;
    -webkit-user-select: none;
}
.pending-chip:hover { background: #FDE68A; }
.pending-chip[aria-expanded="true"] { background: #FDE68A; }
.pending-panel {
    display: none;
    margin-top: .5rem;
    background: #fff;
    color: #1F2937;
    border-radius: 10px;
    border: 1px solid #E5E7EB;
    padding: .75rem .9rem;
    max-width: 480px;
    box-shadow: 0 4px 12px rgba(0,0,0,.12);
}
.pending-panel[data-open="1"] { display: block; }
.pending-panel__head {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: .55rem; padding-bottom: .45rem;
    border-bottom: 1px solid #F0F0F0;
    font-size: .85rem; font-weight: 700; color: #0B3C45;
}
.pending-item {
    display: flex; align-items: center; gap: .5rem;
    padding: .45rem 0; font-size: .82rem;
    border-bottom: 1px dashed #F0F0F0;
}
.pending-item:last-child { border-bottom: 0; }
.pending-item__meta { flex: 1; min-width: 0; }
.pending-item__room { font-weight: 600; color: #1F2937; }
.pending-item__sub { color: #6B7280; font-size: .75rem; }
.pending-item__status { font-size: .72rem; padding: .15rem .45rem; border-radius: 4px; }
.pending-item__status--queued   { background: #E5E7EB; color: #374151; }
.pending-item__status--uploading{ background: #DBEAFE; color: #1E40AF; }
.pending-item__status--failed   { background: #FEE2E2; color: #991B1B; }
.pending-item__btns { display: flex; gap: .3rem; }
.pending-item__btn {
    border: 1px solid #D1D5DB; background: #fff;
    padding: .2rem .5rem; border-radius: 4px;
    font-size: .72rem; cursor: pointer; color: #374151;
}
.pending-item__btn:hover { background: #F3F4F6; }
```

(B) Markup — inside `.ws-header__inner` (around line 449, just before the closing `</div>` of `.ws-header__inner`), add:

```
<button type="button"
        id="pending-chip"
        class="pending-chip"
        aria-expanded="false"
        aria-controls="pending-panel"
        title="Pending photo uploads">
    🔄 <span id="pending-chip-count">0</span> pending
</button>
<div id="pending-panel" class="pending-panel" role="region" aria-label="Pending uploads">
    <div class="pending-panel__head">
        <span>Pending uploads</span>
        <button type="button" id="pending-retry-all" class="pending-item__btn">↻ Retry all</button>
    </div>
    <div id="pending-list"></div>
</div>
```

(C) JS — inside the new `<script>` block at the bottom (after Task 2 wrappers), add the UI controller:

- `refreshChip()` — calls `OfflineQueue.count()` and `OfflineQueue.list()`, updates `#pending-chip-count` text, toggles `style.display` on `#pending-chip` (`'inline-flex'` when count > 0, `'none'` when 0), and re-renders `#pending-list`.
- `renderList(items)` — populates `#pending-list` with `.pending-item` rows. For each: kind icon (📷 / 🖼), room name, captured timestamp formatted as relative time ("3 min ago" — write a tiny helper or just `Math.round((Date.now()-capturedAt)/60000) + ' min ago'`), status badge derived from `attemptCount` (`queued` if 0, `uploading` if currently in the in-memory uploading set from drain, `failed` if `attemptCount >= 1 && lastError`), Retry button (`onclick=OfflineQueue.drain()`), Remove button (`onclick=async()=>{await OfflineQueue.remove(id); refreshChip();}`).
- Wire the chip click: toggles `aria-expanded` + `data-open` on the panel.
- Wire `#pending-retry-all` click → `OfflineQueue.drain()`.
- Subscribe to `window` event `offline-queue-change` → `refreshChip()`.
- Run `refreshChip()` once on DOMContentLoaded (in case the page loaded with rows from a previous session).
- Close panel on outside-click (engineer convenience): `document.addEventListener('click', e => { if (!#pending-chip.contains(e.target) && !#pending-panel.contains(e.target)) closePanel(); })`.

Mobile-first sanity: the chip is small enough to fit on a 320px viewport. The panel is `max-width: 480px` and positions in normal document flow (below the header), so on phones it spans the full content width. No fixed-positioning gymnastics needed.

NO admin info in the chip/panel — token-gated render only ever sees: room name (already engineer-visible), kind icon, timestamp, status, error string from the server (statusText only — never response body).
  </action>
  <verify>
    <automated>
      php -l resources/views/worksheets/public-show.blade.php
      php artisan view:clear
    </automated>
    <human-check>
      Continuing from Task 2 verification:
      1. Throttle to Offline. Capture one label + one completed photo → chip shows "🔄 2 pending".
      2. Click chip → panel expands, lists 2 rows with correct icons (📷 + 🖼), room names, "<1 min ago", status "queued".
      3. Click panel item Remove on one → count drops to 1, that row disappears.
      4. Click "↻ Retry all" while still offline → both fail (network throw), status flips to "failed", `attemptCount` shows in subtitle (e.g. "1 attempt — failed").
      5. Click "↻ Retry all" twice more → after 3rd failure on the same item, warning toast appears.
      6. Go online → chip drains, count drops to 0, chip hides itself.
      7. Mobile DevTools (iPhone SE 375px width) → chip + panel layout doesn't overflow.
      8. Outside-click → panel closes.
      9. Keyboard accessibility: Tab focuses chip, Enter expands.
    </human-check>
  </verify>
  <done>Chip renders only when count > 0, expands to a panel listing each queued item with kind icon, room, timestamp, status, retry/remove. Retry-all and per-item retry both trigger `OfflineQueue.drain()`. Outside-click closes panel. Mobile (320–414px) viewports render cleanly. No layout regression on the rest of the worksheet page.</done>
</task>

<task type="checkpoint:human-verify" gate="blocking">
  <name>Task 4: Manual test plan — full offline-online cycle on a real worksheet</name>
  <what-built>
The complete offline photo queue: IndexedDB persistence, header chip + panel, auto-drain on 'online' + 60s tick, label + completed-work coverage, HEIC normalisation, toast feedback, no regression on the online happy paths.
  </what-built>
  <how-to-verify>
Use a real worksheet on the dev environment (or staging) — pick a worksheet with at least one room and any item that has a label-capture row.

**Test 1 — Offline label capture + drain:**
1. Open the worksheet at `/worksheet/{token}` on a phone or DevTools-emulated mobile.
2. DevTools → Network → Offline.
3. Tap a "Capture label" button on any item → choose camera or a photo from library.
4. Verify: toast "📥 Saved offline — will upload when you're online" appears. File input clears. Button returns to normal state. NO `alert()` dialog. Header chip shows "🔄 1 pending".
5. DevTools → Application → IndexedDB → `engineer-worksheet` → `pending_uploads` → expand → 1 row with `kind: 'label'`, the room name, a Blob, and `attemptCount: 0`.
6. Reload the page. Chip still shows "🔄 1 pending". IDB row still present.
7. Network → Online (do NOT throttle back to "No throttling" until step 7 — keep "Slow 3G" or similar realistic profile).
8. Within 60 seconds (or sooner if you `window.dispatchEvent(new Event('online'))` in console): chip drains, toast "✅ Uploaded 1 pending photo(s)".
9. Verify DB: `php artisan tinker` → `App\Models\DeviceLabelPhoto::latest()->first()` shows the new row, `confirmed=false`, `worksheet_id` matches, `ai_extracted` may be populated (Claude vision runs server-side as normal).

**Test 2 — Offline completed-work photo + drain:**
1. Same worksheet, DevTools → Offline.
2. Tap "Add photo" on any room → pick a file.
3. Toast appears, chip increments. Same shape as Test 1.
4. Network → Online → chip drains → toast → page does NOT reload (drains are silent, no modal needed for completed photos).
5. Verify DB: `App\Models\WorksheetPhoto::latest()->first()` shows the new row attached to the right `room_name`.

**Test 3 — Persistence across reload:**
1. Offline → enqueue 3 mixed items (2 labels + 1 completed photo).
2. Force reload (Cmd+Shift+R / Ctrl+Shift+R).
3. Chip shows "🔄 3 pending". Panel lists all 3.
4. Online → all 3 drain → DB shows all 3.

**Test 4 — Repeated failure path:**
1. Offline → enqueue 1 item.
2. Click panel → click per-item Retry while still offline. `attemptCount` increments, status shows "failed" with the network-error reason.
3. Retry twice more (3 attempts total). Warning toast: "⚠ 1 upload(s) failed after retries — tap the pending chip to review".
4. Item remains in queue — NOT auto-deleted on failure (engineer keeps it for later or clicks Remove).
5. Click Remove → row gone, count = 0, chip hides.

**Test 5 — Online happy path regression (CRITICAL):**
1. Network online normally (no throttling).
2. Tap "Capture label" → existing AI-extraction modal OPENS with extracted fields (this is the SAME behaviour as before today's change).
3. Tap "Add photo" → page reloads with new thumbnail (SAME as before).
4. No chip appears (count = 0).

**Test 6 — Mobile viewport sanity:**
1. DevTools → Toggle device toolbar → iPhone SE (375 × 667).
2. With chip visible (queue has items), header + chip + panel all fit without horizontal scroll.
3. Tap targets all ≥ 44px (chip + panel buttons).

**Test 7 — Throttle compliance:**
1. Enqueue 10 items offline.
2. Go online → drain runs with `await sleep(200)` between POSTs → ~2 seconds total.
3. Server logs show 10 POSTs without `429 Too Many Requests` (throttle is `30,1` = 30/min, well under our 5/sec).

**Test 8 — No regression on rest of page:**
1. Browse the worksheet end-to-end while online: sign-off canvas, room-complete buttons, survey-reviewed buttons, photo lightbox, delete-photo, label-review modal confirm. All function identically to pre-change behaviour.

**Tick each test in the SUMMARY.md after running:**
- [ ] Test 1 — Offline label + drain
- [ ] Test 2 — Offline completed photo + drain
- [ ] Test 3 — Persistence across reload
- [ ] Test 4 — Repeated failure path
- [ ] Test 5 — Online happy path regression
- [ ] Test 6 — Mobile viewport sanity
- [ ] Test 7 — Throttle compliance
- [ ] Test 8 — No regression on rest of page
  </how-to-verify>
  <resume-signal>Type "approved" if all 8 tests pass, or describe failures (which test, what happened, console errors).</resume-signal>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| public engineer link → app | Untrusted token-gated user crosses here. Existing controllers already validate token + room ownership. |
| browser → IndexedDB | Same-origin storage; not cross-tab readable across origins. Photos in IDB are visible to anyone with device access. |
| queue.drain → server endpoints | Same controllers as live uploads — no new attack surface. |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-260603-eha-01 | Information disclosure | IndexedDB-resident photos | accept | Photos persist locally until drained; engineer's own device. Document in SUMMARY that engineers must avoid sharing devices. No PII beyond the photo content itself (already taken on the engineer's camera). No tokens stored beyond the per-row `token` field which is already in the URL. |
| T-260603-eha-02 | Tampering | Queue payload mutation in DevTools | accept | An engineer with DevTools could enqueue fake POSTs to the upload endpoints — but they already have the token in their URL and can curl the endpoints directly. Queue does NOT increase attack surface beyond what the live page already allows. Server-side validation (controller `$request->validate`) is the authoritative gate. |
| T-260603-eha-03 | Denial of service | Throttle 30/min/IP saturated by queue burst | mitigate | `await sleep(200)` between POSTs in `drain()` caps at 5/sec = 300/min, but only ONE drain runs at a time (`_draining` flag) and 30/min/IP is per-IP-per-route. Engineers won't typically queue >30 photos. If they do, server returns 429, row's `attemptCount` increments, next drain retries (auto-recovery). |
| T-260603-eha-04 | Repudiation | Engineer claims queue lost their photos | mitigate | Toast feedback on every enqueue + every drain; visible chip count; persistent IDB row with `capturedAt` timestamp; per-item failure log in `lastError`. Engineer has continuous in-app evidence. Failed-after-3-attempts items are NEVER auto-deleted — engineer must explicitly Remove. |
| T-260603-eha-05 | Elevation of privilege | Cross-token enqueue (engineer A's IDB drains to engineer B's worksheet) | mitigate | Each enqueued row carries its own `token` field. Drain POSTs to `/worksheet/{row.token}/...` not the current-page token. A single device used by multiple engineers across multiple tokens would correctly drain each row to its own worksheet. Cross-token leak impossible by construction (each row points at one URL). |
| T-260603-eha-SC | Tampering | npm/pip/cargo installs | mitigate | No new package installs in this plan — vanilla JS + native IndexedDB only. SC checkpoint not required. |
</threat_model>

<verification>
- `php -l resources/views/worksheets/public-show.blade.php` — clean syntax.
- `php artisan view:clear && php artisan view:cache` — Blade compiles.
- `php artisan route:list --path=worksheet` — no route changes (negative check: same 11 routes as before this plan).
- Manual: all 8 tests in Task 4 pass.
- `git diff --stat resources/views/worksheets/public-show.blade.php` — exactly ONE file modified.
- `grep -c "PublicWorksheetController" app/Http/Controllers/PublicWorksheetController.php` (before) === (after) — controller unchanged.
- Existing Pest tests for `PublicWorksheetController` continue to pass: `php artisan test --filter=PublicWorksheet` (the contract didn't change, so they MUST stay green).
</verification>

<success_criteria>
- Engineer can capture both label and completed-work photos while offline; no `alert()` fires; chip appears with correct count.
- Queue survives page reload (IndexedDB persistence proven manually).
- Queue auto-drains on `online` event AND on 60-second tick when `navigator.onLine`.
- Per-item retry, remove, and global retry-all all work from the panel.
- Online happy path (modal opens on label success; page reloads on completed-photo success) is byte-for-byte unchanged.
- Server endpoints + throttle rates + AI vision pipeline untouched.
- Mobile-first: chip + panel render correctly on 320px viewports.
- No new dependencies (no npm, no composer).
- D-12 RamsRenderRegression byte-equivalence — N/A (client-side only, no server rendering touched), but explicitly verified by running `php artisan test --filter=Drawings` and seeing zero new failures.
</success_criteria>

<output>
Create `.planning/quick/260603-eha-offline-photo-queue-on-engineer-workshee/260603-eha-SUMMARY.md` when done, including:
- One-line outcome
- The 8 manual-test-plan checkboxes (Task 4) — ticked or with explicit pass/fail notes
- Total LOC added (`git diff --stat` output)
- Any deviations + WHY
- Forward note for the next session: which Pest tests still cover the controller contract, and what (if anything) we'd need to add for true automated browser-level coverage (e.g. Playwright) — flagged as a v2 nice-to-have, NOT required to land this.
</output>
