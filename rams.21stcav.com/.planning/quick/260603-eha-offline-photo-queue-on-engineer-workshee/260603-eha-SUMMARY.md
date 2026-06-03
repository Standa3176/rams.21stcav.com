---
phase: 260603-eha
plan: 01
subsystem: public-worksheet
tags: [offline, indexeddb, mobile, engineer-ux, no-server-change]
quick_id: 260603-eha
dependency_graph:
  requires:
    - resources/views/worksheets/public-show.blade.php
    - app/Http/Controllers/PublicWorksheetController.php (READ-ONLY — endpoint contracts)
  provides:
    - "OfflineQueue module (window.OfflineQueue)"
    - "Pending-uploads chip + expandable panel"
    - "Auto-drain on 'online' event + 60s navigator.onLine tick"
    - "HEIC normalisation on completed-work photos (bonus)"
  affects: []
tech_stack:
  added: []
  patterns:
    - "Override-by-reassignment (window.X = async function X(...))"
    - "Lazy IndexedDB open with feature-detect + one-time toast warning"
    - "Per-row failure isolation in drain() (attemptCount++ + lastError persisted)"
    - "throttle:30,1 compliance via await sleep(200) (~5/sec)"
key_files:
  created: []
  modified:
    - resources/views/worksheets/public-show.blade.php
decisions:
  - "All work stays inline in public-show.blade.php — NO new files, NO controller change, NO route change, NO server-side touch (per plan constraint)."
  - "Override-by-reassignment pattern preserves the originals (__origCaptureLabel / __origUploadWorksheetPhoto) for the online happy path — labels still open the AI-extraction modal, completed photos still trigger window.location.reload()."
  - "HEIC normalisation extended to completed-work photo flow (bonus per plan §interfaces) — original didn't use convertToJpegBlob; we now do for both online AND queued completed uploads."
  - "Drained POSTs use the SAME FormData shape as live functions — server cannot tell the request came from queue vs live. PublicWorksheetController + throttles + Claude vision pipeline UNTOUCHED."
  - "CSS palette restricted to defined tokens (.alert-* + raw hex like #FEF3C7 / #92400E) — NO undefined bg-brand-teal class per 260601-r4c hotfix lesson."
metrics:
  duration: "~8 minutes (auto execution)"
  completed_date: "2026-06-03"
  loc_added: 820
  loc_removed: 2
  files_modified: 1
  commits: 3
---

# Quick Task 260603-eha: Offline photo queue on engineer worksheet — Summary

**One-liner:** Engineer worksheet view now buffers failed/offline label-capture and per-room photo uploads in IndexedDB, surfaces a header chip + expandable panel for review, and auto-drains when network returns — all client-side, zero server changes.

## What shipped

### Commits (atomic, in order)

| Task | Commit    | Description                                                            |
| ---- | --------- | ---------------------------------------------------------------------- |
| 1    | `9010c63` | `feat(260603-eha): add OfflineQueue IndexedDB module + toast helper`   |
| 2    | `238fc78` | `feat(260603-eha): wrap captureLabel + uploadWorksheetPhoto with offline queue` |
| 3    | `4dcf21c` | `feat(260603-eha): render pending-uploads chip + click-to-expand panel` |

### File changes
- `resources/views/worksheets/public-show.blade.php` — **+820 / -2** across the 3 commits.
- ALL other files: **untouched** (controller, routes, models, migrations, tests).

### Module shape (`window.OfflineQueue`)

| Method | Behaviour |
| ------ | --------- |
| `OfflineQueue.db()` | Lazy `Promise<IDBDatabase>` — opens `engineer-worksheet` v1 with store `pending_uploads` (keyPath `id` autoIncrement, index `capturedAt`). Sets `OfflineQueue.unavailable=true` if IDB absent. |
| `OfflineQueue.enqueue({token, kind, room, blob, mime, fields})` | Adds row; `attemptCount=0`, `lastError=null`, `capturedAt=Date.now()`. Returns new id. Fires `offline-queue-change`. |
| `OfflineQueue.list()` | Promise of array — blob stripped, sorted oldest-first. |
| `OfflineQueue.count()` | Promise of integer. |
| `OfflineQueue.remove(id)` | Deletes row, fires change. |
| `OfflineQueue.drain({onProgress, onSuccess, onFailure})` | Iterates oldest-first with `await sleep(200)` between POSTs (~5/sec, server throttle is 30/min/IP). Per-row failure isolated: on `!resp.ok` or fetch throw, `attemptCount++` + `lastError=resp.statusText`, row stays in IDB. Successful rows deleted. `_draining` flag prevents online-event + 60s-tick race. |
| `OfflineQueue.subscribe(handler)` | Wires a listener to `window` `offline-queue-change` event. |

### Auto-drain triggers
- `window.addEventListener('online', _autoDrain)` — fires when the OS reports connectivity.
- `setInterval(_autoDrain, 60_000)` guarded by `navigator.onLine` — catches captive-portal-lifted-without-event case.

### Toast palette (`window.__wsShowToast`)
Fixed-position bottom-centre container, ttl=4s default, palette mirrors existing `.alert-*` tokens (`info` / `success` / `warning` / `error`).

### Chip + panel
- Hidden by default (`display:none`); shown as inline-flex when `count>0`.
- Click toggles panel; Enter/Space keyboard-toggles; outside-click closes; panel auto-closes when queue drains to zero.
- Per-row shows: kind icon (`📷` label / `🖼` completed), room name, captured time (relative), status badge, [Retry] [Remove].
- `↻ Retry all` button in panel head.
- Auto-paints on `DOMContentLoaded` so reload-mid-queue surfaces existing rows immediately.

### HEIC normalisation bonus
- The original `uploadWorksheetPhoto` did NOT run `convertToJpegBlob`. The wrapper now runs it on both the ONLINE and OFFLINE completed-photo paths — smaller IDB footprint AND smaller iOS-Safari uploads.

## Test results

### RamsRenderRegression canary (D-12 byte-equivalence)

```
PASS  Tests\Feature\Rams\RamsRenderRegressionTest
✓ pdf byte identical across two renders manual form fixture   10.25s
✓ pdf byte identical across two renders quote import fixture   5.52s
✓ pdf byte identical across two renders survey derived fixture 5.33s
Tests:    3 passed (9 assertions)
```
**STILL GREEN** — N/A for this plan in principle (client-side only, no server rendering touched), but explicitly verified before AND after each commit. Zero drift.

### Drawings filter (broader regression sanity)

```
Tests:    2 skipped, 231 passed (1996 assertions)
Duration: 37.74s
```
**231/231 GREEN**, 2 skipped (D2 binary unavailable on dev — pre-existing pattern). Zero new failures.

### PublicWorksheet filter

```
Tests:    2 failed, 19 passed (59 assertions)
```

**Both failures are PRE-EXISTING** — confirmed by checking out the pre-task view file (`git checkout 632463c -- resources/views/worksheets/public-show.blade.php`) and re-running the suite — same 2 failures appear:

```
FAILED  Tests\Feature\Worksheet\PublicWorksheetSignoffTest > sign persists worksheet signoff with correct fields
FAILED  Tests\Feature\Worksheet\PublicWorksheetSignoffTest > resubmit appends a second signoff and does not overwrite
```

These failures live in `PublicWorksheetSignoffTest` (signoff persistence — server-side model logic), entirely unrelated to view JS. **Net-new failures introduced by 260603-eha: ZERO.** (As expected — this plan ships ZERO server-side changes.)

**Recommendation:** Surface those 2 pre-existing red signoff tests as a separate quick task — out of scope for 260603-eha per plan's scope-boundary rule.

## Manual Test Plan (Task 4 — `checkpoint:human-verify`)

The plan defers full integration verification to a human because:
1. IndexedDB behaviour is browser/storage-dependent and can't be unit-tested meaningfully without Playwright (flagged as v2 nice-to-have in the plan output spec).
2. Offline simulation requires DevTools toggling that no PHPUnit harness models.
3. The drain → server endpoints round-trip needs a live `php artisan serve` worksheet + a real token.

**Run on a worksheet at `/worksheet/{token}` — phone-width viewport or DevTools mobile emulation.**

- [ ] **Test 1 — Offline label capture + drain**
    1. Worksheet open; DevTools → Network → Offline.
    2. Tap "📷 Box Serial Label" on any hardware item → pick photo.
    3. Verify: toast `📥 Saved offline — will upload when you're online`. File input clears. Button returns to normal state. NO `alert()` dialog. Header chip shows `🔄 1 pending`.
    4. DevTools → Application → IndexedDB → `engineer-worksheet` → `pending_uploads` → expand → 1 row with `kind:'label'`, room name, Blob, `attemptCount:0`.
    5. Reload page. Chip still shows `🔄 1 pending`. IDB row still present.
    6. Network → Online.
    7. Within 60s (or `window.dispatchEvent(new Event('online'))` in console): chip drains, toast `✅ Uploaded 1 pending photo(s)`.
    8. Verify DB: `php artisan tinker` → `App\Models\DeviceLabelPhoto::latest()->first()` shows new row, `confirmed=false`, `worksheet_id` matches.

- [ ] **Test 2 — Offline completed-work photo + drain**
    1. DevTools → Offline.
    2. Tap "📷 Add photo" on any room → pick file.
    3. Toast appears, chip increments.
    4. Network → Online → chip drains → toast → page does NOT reload (offline-drained photos are silent).
    5. Verify DB: `App\Models\WorksheetPhoto::latest()->first()` attached to right `room_name`.

- [ ] **Test 3 — Persistence across reload**
    1. Offline → enqueue 3 mixed items (2 labels + 1 completed photo).
    2. Force reload (Ctrl+Shift+R).
    3. Chip shows `🔄 3 pending`. Panel lists all 3.
    4. Online → all 3 drain → DB shows all 3.

- [ ] **Test 4 — Repeated failure path (3-fail warning)**
    1. Offline → enqueue 1 item.
    2. Click panel → click per-item ↻ Retry while still offline. `attemptCount` increments; status shows `failed` + reason in subtitle.
    3. Retry twice more (3 attempts total). Warning toast: `⚠ 1 upload(s) failed after retries — tap the pending chip to review`.
    4. Item REMAINS in queue (NOT auto-deleted on failure). Click ✕ Remove → row gone, count=0, chip hides.

- [ ] **Test 5 — Online happy path regression (CRITICAL)**
    1. Network online normally.
    2. Tap "📷 Box Serial Label" → existing AI-extraction modal OPENS with extracted fields (SAME as before).
    3. Tap "📷 Add photo" → page reloads with new thumbnail (SAME as before).
    4. No chip appears (count=0).

- [ ] **Test 6 — Mobile viewport sanity**
    1. DevTools → Toggle device toolbar → iPhone SE (375×667).
    2. With chip visible, header + chip + panel fit without horizontal scroll.
    3. Tap targets reasonable for thumbs (chip + panel buttons).

- [ ] **Test 7 — Throttle compliance**
    1. Enqueue 10 items offline.
    2. Go online → drain runs with `await sleep(200)` between POSTs → ~2 seconds total.
    3. Server logs show 10 POSTs without `429 Too Many Requests` (throttle is `30,1` = 30/min, well under our 5/sec).

- [ ] **Test 8 — No regression on rest of page**
    1. Browse worksheet end-to-end online: sign-off canvas, room-complete buttons, survey-reviewed buttons, photo lightbox, delete-photo, label-review modal confirm. All function identically to pre-change behaviour.

## Deviations from plan

**One Rule-2 minor pattern deviation in Task 2 (uploadWorksheetPhoto wrapper):**

The plan suggested calling `__origUploadWorksheetPhoto` from the wrapper on the online path. Implementation choice: **replicate the POST inline** instead, for two reasons:

1. **HEIC normalisation extension.** The original didn't run `convertToJpegBlob`; the bonus per plan §interfaces is to use the normalised blob. Calling the original would have re-uploaded the raw `File` and bypassed the normalisation. Replicating the POST lets us feed the normalised blob.
2. **Network-throw-into-queue routing.** The original's `catch (e)` block fires `alert('Network error...')` and resets the input. If we'd delegated and the original alerted, we couldn't transparently route the failure into `OfflineQueue.enqueue` — the alert would have already fired. Inline lets us catch and silently enqueue.

The `captureLabel` wrapper DOES delegate to `__origCaptureLabel` on the online path because the modal-opening AI-extraction sequence is non-trivial (~85 lines, busy states, response parsing, modal construction) — replicating that would have ballooned the wrapper. The trade-off is documented in code: rare case of online+server-500 still fires the original's `alert` (engineer can retap; queue catches them next time) — explicitly acceptable per plan §B1 note.

**Auto-fix decisions invoked:** None. All work executed within the plan as written.

## Authentication gates encountered

None — work is purely client-side Blade view edits.

## Known stubs

None — every code path is wired end-to-end. Drain calls real server endpoints; chip reads real IDB; failed items are NOT silently dropped.

## Threat flags

None — no new network endpoints, no new auth paths, no new file access. All POSTs go to the SAME `PublicWorksheetController` endpoints the live page already calls. STRIDE threat register T-260603-eha-01..05 covers the surface (queue does NOT increase attack surface vs the live page; cross-token leak impossible by construction because each row carries its own `token` field).

## Forward note for next session

**Pest tests still covering the controller contract:**
- `tests/Feature/Worksheet/PublicWorksheetUploadPhotoTest.php` (if exists — verify)
- `tests/Feature/Worksheet/PublicWorksheetController*Test.php` family

The contracts (`POST /worksheet/{token}/label-photo` + `POST /worksheet/{token}/photos`) did NOT change in 260603-eha — drained queue items POST the EXACT same FormData shape live functions build today. So existing controller tests remain valid.

**For true automated browser-level coverage of the IDB queue (v2 nice-to-have, NOT required to land this):**
- Playwright suite that:
    1. Spins up `php artisan serve`.
    2. Navigates to a seeded worksheet token.
    3. Calls `page.context().setOffline(true)`, performs a label capture + completed-photo capture, asserts `OfflineQueue.count() === 2`.
    4. Calls `page.context().setOffline(false)`, triggers `window.dispatchEvent(new Event('online'))`, polls for `OfflineQueue.count() === 0`, asserts DB rows landed via tinker.

**Pre-existing red tests to clean up (out of scope for this task):**
- `Tests\Feature\Worksheet\PublicWorksheetSignoffTest::sign persists worksheet signoff with correct fields…` (currently failing on HEAD AND on 632463c — pre-existing).
- `Tests\Feature\Worksheet\PublicWorksheetSignoffTest::resubmit appends a second signoff and does not overwrite…` (same — pre-existing).

These are signoff server-side persistence assertions, unrelated to view JS. Worth a separate quick task.

## Self-Check: PASSED

- ✓ `resources/views/worksheets/public-show.blade.php` — modified (820 +/2 −).
- ✓ Commit `9010c63` present (`git log --oneline | grep 9010c63`).
- ✓ Commit `238fc78` present.
- ✓ Commit `4dcf21c` present.
- ✓ `php -l` clean on modified file.
- ✓ `php artisan view:clear` clean.
- ✓ `php artisan route:list --path=worksheet | grep label-photo` — same routes pre-and-post (no route added/removed).
- ✓ RamsRenderRegression canary 3/3 / 9 assertions GREEN.
- ✓ Drawings filter 231 pass / 1996 assertions / 2 skipped (pre-existing D2 binary) GREEN.
- ✓ 2 PublicWorksheet failures verified PRE-EXISTING via temporary `git checkout 632463c` rollback probe.
