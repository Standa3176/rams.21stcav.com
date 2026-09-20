---
phase: quick
plan: 260919-f8e
type: execute
wave: 1
depends_on: []
files_modified:
  - resources/views/worksheets/public-show.blade.php
  - tests/Feature/Worksheet/PublicWorksheetLabelConfirmOnReconnectTest.php
autonomous: false
requirements: [QUICK-f8e]
must_haves:
  truths:
    - "When an offline-queued label photo finishes uploading (via auto-drain on reconnect, 'Retry all', or a per-item retry), the engineer is automatically shown the same AI-extraction confirm/edit modal used for the online capture flow — they do not have to hunt through a collapsed Kit List drawer to find it"
    - "The pending-confirm prompt survives a page reload that happens for an unrelated reason (sign-off submit, mark-room-complete, etc.) — it reappears on the next page load until confirmed or dismissed, using sessionStorage only (no IndexedDB schema change)"
    - "Confirming from the reconnect prompt calls the existing POST /worksheet/{token}/label-photos/{photoId}/confirm endpoint unchanged, writing part/serial/MAC/model/manufacturer onto the Device row exactly as the online flow does today"
    - "Dismissing (Cancel) the reconnect prompt never blocks sign-off and never re-queues or deletes anything — the photo and unconfirmed Device row already exist, and the pre-existing per-room 'Review / Edit-Confirm' badge (unless($lp->confirmed)) remains as a durable, always-available fallback"
    - "The existing online capture-and-confirm flow, the existing manual reviewLabel flow, and the OfflineQueue enqueue/count/drain/schema (DB_VERSION=1) are all textually unregressed"
  artifacts:
    - path: resources/views/worksheets/public-show.blade.php
      provides: "A sessionStorage-backed pending-label-confirm queue, populated from OfflineQueue.drain()'s onSuccess callback for kind==='label' rows, auto-prompted via the existing openLabelReview() modal on drain completion and on page load"
    - path: tests/Feature/Worksheet/PublicWorksheetLabelConfirmOnReconnectTest.php
      provides: "Render-level regression assertions: new queue/prompt markers present and wired into all three drain() call sites; pre-existing label-capture, reviewLabel, and OfflineQueue markers unregressed"
  key_links:
    - from: "OfflineQueue.drain (auto-drain / Retry all / per-item retry)"
      to: "sessionStorage pending-confirm queue"
      via: "window.__lcHandleLabelUploadSuccess passed as drain's onSuccess option, pushes {photoId, token, photoUrl, extracted} for row.kind === 'label'"
      pattern: "__lcHandleLabelUploadSuccess"
    - from: "drain completion / DOMContentLoaded"
      to: "openLabelReview (existing AI-extraction modal)"
      via: "window.__lcMaybePrompt reads the sessionStorage queue and re-opens openLabelReview({..., queued: true}) for the first entry"
      pattern: "__lcMaybePrompt"
    - from: "openLabelReview confirm (queued path)"
      to: "PublicWorksheetController::confirmLabelPhoto"
      via: "unchanged fetch POST to /worksheet/{token}/label-photos/{photoId}/confirm"
      pattern: "label-photos/.+/confirm"
---

<objective>
Close the gap where an offline-captured serial label photo uploads on reconnect but is never
confirmed: implement the locked decision "prompt to confirm on reconnect" by reusing the
existing AI-extraction confirm/edit modal (openLabelReview), auto-triggered once a queued label
photo finishes uploading, chained across multiple queued labels, and durable across an
unrelated page reload via sessionStorage — with zero IndexedDB schema change and zero
controller change.

Purpose: today captureLabel's offline wrapper enqueues the photo, and on drain the photo
uploads and a Device row is created — but confirmLabelPhoto is never called. The AI-read
values sit unconfirmed (device_label_photos.confirmed = false) and the engineer walks away
believing the serial was captured. The only existing discovery path is the amber "Review"
badge buried inside a collapsed Kit List drawer, which nothing today draws attention to.

Output: resources/views/worksheets/public-show.blade.php gains a small sessionStorage-backed
queue and a soft, dismissible auto-prompt wired to the three existing OfflineQueue.drain() call
sites and to page load; openLabelReview gains an opt-in "queued" mode that chains to the next
pending confirm instead of reloading after every item. A new PHPUnit feature test proves the
wiring exists and nothing pre-existing regressed.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@CLAUDE.md

<interfaces>
Read-only reference, resources/views/worksheets/public-show.blade.php. Executor should extend
this file in place — do not rename existing globals (window.captureLabel, window.OfflineQueue,
window.__wsShowToast, openLabelReview, reviewLabel) or restructure the surrounding IIFEs.

openLabelReview (~:1913-1973) — the modal reused for this plan, called today from two places:
captureLabel's online success path (~:1869) and manual reviewLabel (~:1899). Current close()
unconditionally reloads on confirm; Cancel only removes the overlay (no reload). This plan must
NOT change behaviour for either existing caller — both call
openLabelReview({photoId, token, photoUrl, extracted}) with no "queued" key, so opts.queued
will be undefined (falsy) for them and the existing reload-on-confirm / remove-only-on-cancel
behaviour must be preserved exactly:

  function openLabelReview({ photoId, token, photoUrl, extracted, queued, onOverlayRemoved }) {
      // ...existing aiFailed detection + overlay markup unchanged...
      document.body.appendChild(overlay);
      const close = () => { overlay.remove(); window.location.reload(); };
      overlay.querySelector('#lblCancel').onclick = () => { overlay.remove(); /* + new branch */ };
      overlay.querySelector('#lblConfirm').onclick = async () => {
          // ...existing FormData build + fetch to /label-photos/{photoId}/confirm...
          if (!resp.ok) { alert('Confirm failed.'); return; }
          close(); // ...existing call, guarded/replaced when queued===true
      };
  }

OfflineQueue.drain (~:2232-2329) — already supports an opts.onSuccess(row, json) callback
(invoked once per successfully-uploaded row, json is the parsed response body from
/label-photo or /photos), but no current call site passes one:

  OfflineQueue.drain = function (opts) {
      const onSuccess = opts.onSuccess || function () {};
      // ...
      .then(function (json) { onSuccess(row, json); });
      // ...
  };

Three call sites currently call drain({}) with no onSuccess — this plan adds
"onSuccess: window.__lcHandleLabelUploadSuccess" to all three, then (after the promise
resolves) calls window.__lcMaybePrompt && window.__lcMaybePrompt():

  1. _autoDrain inside the OfflineQueue module IIFE (~:2379-2394), triggered by the 'online'
     event and a 60s setInterval tick (only when navigator.onLine).
  2. The #pending-retry-all click handler inside the pending-chip IIFE (~:2701-2713).
  3. The delegated per-item data-act="retry" handler inside the pending-chip IIFE
     (~:2724-2738).

uploadLabelPhoto's JSON response (unchanged, app/Http/Controllers/PublicWorksheetController.php:445-451)
already carries everything the modal needs:

  return response()->json([
      'id'           => $photo->id,
      'device_id'    => $device->id,
      'photo_url'    => Storage::url($photo->photo_path),
      'ai_extracted' => $photo->ai_extracted,
      'confirmed'    => $photo->confirmed,
  ]);

Existing per-room fallback (~:1292-1316) — server-rendered on every page load, already shows an
amber "Review" badge + "Edit / Confirm" button (reviewLabel(id, token)) for any
unless($lp->confirmed) label photo, regardless of how it was captured. This plan's auto-prompt
is a courtesy nudge on top of this pre-existing, always-correct fallback — it does not replace
it, and confirming via either path clears the same confirmed flag.

sessionStorage precedent (~:1983-1984) — the existing drawer/scroll-restore IIFE already keys
sessionStorage by worksheet id exactly like this plan should:

  var KEY = 'wsState_' + {{ (int) $worksheet->id }};

Mirror this for the new queue key, e.g. 'wsPendingLabelConfirms_' + {{ (int) $worksheet->id }}.
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Wire drain-success label uploads into a sessionStorage confirm queue and auto-prompt via the existing modal</name>
  <files>resources/views/worksheets/public-show.blade.php</files>
  <action>
    Add one new trailing script IIFE block immediately after the pending-chip IIFE's closing
    script tag, before the closing body tag (i.e. as the last script block in the file). Inside
    it, define (module-scoped, strict mode):

    - _lcQueueKey() returning 'wsPendingLabelConfirms_' + {{ (int) $worksheet->id }} (mirror the
      wsState_ sessionStorage key pattern at ~:1984 exactly).
    - _lcQueueRead() / _lcQueueWrite(arr) — JSON parse/stringify through sessionStorage, wrapped
      in try/catch returning [] / no-op on any failure (mirror the wsState guard pattern at
      ~:1988-1997 and ~:2003-2006).
    - _lcQueuePush(entry) — read, skip if an entry with the same photoId already exists (dedupe
      against duplicate onSuccess firings), else push and write.
    - _lcQueueShift() — read, remove the first entry, write the remainder back.
    - A module-scoped "let _lcModalOpen = false;" guard.
    - _lcMaybePrompt() — if _lcModalOpen is true, return immediately (no stacking modals). Else
      read the queue; if empty, return. Else set _lcModalOpen = true and call the existing
      openLabelReview (already global in this file) with:
      { photoId: entry.photoId, token: entry.token, photoUrl: entry.photoUrl,
      extracted: entry.extracted || {}, queued: true, onOverlayRemoved: () => { _lcModalOpen =
      false; } }.
    - Expose window.__lcHandleLabelUploadSuccess = function (row, json) { if (row && row.kind
      === 'label' && json && json.id) { _lcQueuePush({ photoId: json.id, token: row.token,
      photoUrl: json.photo_url, extracted: json.ai_extracted || {} }); } }; — this is the
      onSuccess callback passed into drain().
    - Expose window.__lcMaybePrompt = function () { const before = _lcQueueRead().length; if
      (before && window.__wsShowToast && !_lcModalOpen) { window.__wsShowToast('Label photo(s)
      uploaded — confirm the serial reading (' + before + ')', 'info', 6000); } _lcMaybePrompt();
      }; (the toast only fires the instant before a fresh modal opens, giving the engineer
      context for why a modal just appeared without them tapping anything).
    - On DOMContentLoaded (or immediately if document.readyState is already past loading,
      mirroring the pattern at ~:2751-2755), call window.__lcMaybePrompt(); once — this is what
      makes the prompt survive an unrelated reload. This is a single one-shot check, not a
      polling loop.

    Then make these four surgical edits to existing code:

    1. In openLabelReview, thread the two new optional destructured params "queued" and
       "onOverlayRemoved" through. Change the Cancel handler from
       "overlay.querySelector('#lblCancel').onclick = () => overlay.remove();" to remove the
       overlay, call "onOverlayRemoved && onOverlayRemoved();", and — only when queued is true
       — call "_lcQueueShift(); window.__lcMaybePrompt();" to advance to the next queued item
       without a reload. When queued is falsy, behaviour is unchanged (overlay removed, nothing
       else).
    2. In the Confirm handler, replace the single "close();" call on success with: if queued is
       true, "overlay.remove(); onOverlayRemoved && onOverlayRemoved(); _lcQueueShift();" then,
       if the queue still has entries, call "window.__lcMaybePrompt();" (no reload — avoid an
       N-item reload flash), else "window.location.reload();" (final reload once the whole
       batch is confirmed, refreshing the Kit List badges same as today). When queued is falsy,
       call the existing close() exactly as today (unchanged reload-on-every-confirm behaviour
       for the two pre-existing callers).
    3. In _autoDrain (~:2379-2394), change "OfflineQueue.drain({})" to
       "OfflineQueue.drain({ onSuccess: window.__lcHandleLabelUploadSuccess })", and after the
       existing showToast(...) calls inside ".then(function (result) { ... })", add
       "window.__lcMaybePrompt && window.__lcMaybePrompt();" before the "return result;".
    4. In both the #pending-retry-all handler (~:2701-2713) and the delegated per-item
       data-act="retry" handler (~:2724-2738), change "window.OfflineQueue.drain({})" to
       "window.OfflineQueue.drain({ onSuccess: window.__lcHandleLabelUploadSuccess })", and add
       "window.__lcMaybePrompt && window.__lcMaybePrompt();" inside the existing
       ".then(function (result) { ... })" callback, after the existing toast calls.

    Do not add any new IndexedDB code, do not change DB_VERSION, do not touch
    OfflineQueue.enqueue/.count/.list/.remove, do not modify captureLabel,
    uploadWorksheetPhoto, reviewLabel, or prepareSignoff, and do not change
    PublicWorksheetController or any route — the confirm/upload endpoints and their JSON shapes
    are reused exactly as-is.
  </action>
  <verify>
    <automated>cd "C:\Users\sonny.tanda\Documents\1 - Claude Projects\Rams2\rams.21stcav.com" &amp;&amp; C:\Users\sonny.tanda\.config\herd\bin\php84\php.exe artisan view:clear &amp;&amp; C:\Users\sonny.tanda\.config\herd\bin\php84\php.exe artisan test --filter=Worksheet</automated>
  </verify>
  <done>
    View compiles with no Blade parse error (view:clear succeeds, full Worksheet test filter
    still green at 239 passing / 0 failed), openLabelReview's two pre-existing callers are
    behaviourally unchanged (no "queued" key passed, so reload-on-confirm / remove-on-cancel is
    identical to before), and all three drain() call sites now pass
    window.__lcHandleLabelUploadSuccess as onSuccess and call window.__lcMaybePrompt() after
    resolving.
  </done>
</task>

<task type="auto">
  <name>Task 2: Add render-level regression test for the reconnect confirm prompt</name>
  <files>tests/Feature/Worksheet/PublicWorksheetLabelConfirmOnReconnectTest.php</files>
  <action>
    Create a new PHPUnit feature test class
    Tests\Feature\Worksheet\PublicWorksheetLabelConfirmOnReconnectTest, following the exact
    scaffolding pattern in tests/Feature/Worksheet/PublicWorksheetSignaturePadResizeTest.php
    (same makeWorksheet() helper shape building a User + Project + Worksheet with
    generated_data.rooms, same RefreshDatabase trait, same
    $this->get(route('public-worksheet.show', ['token' => $w->access_token])) render call). Add
    a doc-comment on the class stating plainly that PHPUnit has no browser/IndexedDB runtime, so
    it cannot simulate an actual offline capture, a real drain(), sessionStorage persistence
    across a real reload, or the modal's visual chaining — these are render-level assertions
    that the new code paths exist, are wired into all three drain() call sites, and that the
    pre-existing capture/confirm/reviewLabel code is textually unregressed; genuine
    offline-to-reconnect-to-confirm behaviour is covered only by the manual checklist in this
    plan's Task 3.

    Assert, against the rendered HTML:
    - The new queue/prompt markers exist: __lcHandleLabelUploadSuccess, __lcMaybePrompt,
      wsPendingLabelConfirms_ (the sessionStorage key prefix).
    - All three drain() call sites are wired: the response contains at least three occurrences
      of "onSuccess: window.__lcHandleLabelUploadSuccess" (or your Task 1 implementation's exact
      literal — assert the literal your code uses, not a paraphrase), matching _autoDrain,
      Retry all, and per-item retry.
    - openLabelReview accepts and threads the new "queued" parameter: response contains
      "queued" within the openLabelReview function's parameter destructuring, and contains
      "onOverlayRemoved".
    - Pre-existing markers are unregressed: response still contains captureLabel,
      reviewLabel, label-photos/' + photoId + '/confirm (or the exact literal used), the
      "Edit / Confirm" button text, and OfflineQueue.enqueue (still present and untouched
      elsewhere in the file).
    - DB_VERSION is unchanged: response still contains "DB_VERSION    = 1" (or the exact
      current literal spacing — read it fresh from the file rather than assuming).
  </action>
  <verify>
    <automated>cd "C:\Users\sonny.tanda\Documents\1 - Claude Projects\Rams2\rams.21stcav.com" &amp;&amp; C:\Users\sonny.tanda\.config\herd\bin\php84\php.exe artisan test --filter=PublicWorksheetLabelConfirmOnReconnectTest</automated>
  </verify>
  <done>
    New test file exists, all assertions pass, and running
    "php artisan test --filter=Worksheet" reports 239 + N passing (N = new tests added), 0
    failed.
  </done>
</task>

<task type="checkpoint:human-verify" gate="blocking">
  <what-built>
    A sessionStorage-backed pending-label-confirm queue that auto-prompts the existing
    AI-extraction confirm/edit modal once an offline-queued serial label photo finishes
    uploading (on reconnect auto-drain, "Retry all", or per-item retry), chains across multiple
    queued labels, and survives an unrelated page reload — with no IndexedDB schema change and
    no controller change. Full automated Worksheet suite run to confirm no regression.
  </what-built>
  <how-to-verify>
    1. Run the full filter and compare to baseline: "php artisan test --filter=Worksheet" should
       show 239 + N passing (N = new tests added), 0 failed. Record the before/after counts.
    2. On a phone or desktop browser, open a public worksheet link with at least one Kit List
       item that has a "Box Serial Label" capture button. In DevTools set Network to "Offline"
       (or airplane mode on a phone). Tap "Box Serial Label" and choose/take a photo. Confirm:
       (a) a toast says it was saved offline, (b) the pending-items chip count increases, (c) no
       confirm modal appears yet (correct — nothing has uploaded).
    3. Go back online (toggle Network back to "Online" / disable airplane mode) and wait for the
       auto-drain (up to ~60s, or trigger it immediately by tapping the pending chip's
       "Retry all"). Confirm: (a) a toast announces the photo(s) uploaded, (b) shortly after, the
       AI-extraction confirm/edit modal opens automatically for the just-uploaded label without
       you tapping anything in the Kit List, (c) the modal shows the photo and any AI-read
       values (or the "AI couldn't read this label clearly" manual-entry prompt if extraction
       failed).
    4. Tap Cancel on that modal. Confirm: (a) nothing crashes, (b) the label still shows an amber
       "Review" badge with an "Edit / Confirm" link in its Kit List row, proving the durable
       fallback still works, (c) reload the page — the auto-prompt reappears for the same label
       (since it is still unconfirmed and was queued), proving sessionStorage persistence across
       reload.
    5. This time tap Confirm (editing a field first if you want). Confirm: (a) the modal closes,
       (b) the page reloads, (c) the label's Kit List badge now reads "✓ Confirmed" with no
       Edit/Confirm link, (d) reloading again does NOT re-trigger the auto-prompt for that label.
    6. Repeat steps 2-3 with two label photos queued offline at once before reconnecting.
       Confirm the modal appears for the first, and after Confirm (or Cancel), the modal for the
       second appears immediately without an intermediate full page reload, with only one
       reload at the very end after the last one is processed.
    7. Confirm the pre-existing online happy path is unregressed: with the device online, tap
       "Box Serial Label" directly (not via the offline queue) — the modal should open exactly
       as it did before this plan, and Confirm should reload the page exactly once as before.
  </how-to-verify>
  <resume-signal>Type "approved" or describe issues</resume-signal>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|--------------|
| Client browser (public token link) → PublicWorksheetController::confirmLabelPhoto | Untrusted client; anyone with the token URL can POST. This plan changes only when/how the existing confirm request is triggered from the client — it adds no new endpoint and no new field is sent that the server did not already accept. |
| Client browser → sessionStorage (pending-confirm queue) | Local to the tab/device; not a network boundary and not shared across devices. Cleared on tab close; not a source of truth (the server-rendered "Review" badge, driven by device_label_photos.confirmed, is). |
| Client browser → IndexedDB (OfflineQueue) | Unmodified by this plan — read-only consumption of drain()'s existing onSuccess hook. |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|------------------|
| T-f8e-01 | Tampering | sessionStorage pending-confirm queue | accept | A user could edit sessionStorage via DevTools to inject a fabricated {photoId, token} entry. Worst case: openLabelReview opens for a photoId the client made up, and confirmLabelPhoto's existing `where('id', $photoId)->where('worksheet_id', $worksheet->id)->firstOrFail()` (unchanged) 404s or, if it happens to match a real unconfirmed photo on the same worksheet, only re-confirms a photo the same token already has full write access to via the existing confirm endpoint. No new privilege is created — the token already grants confirm access to every label photo on this worksheet. |
| T-f8e-02 | Denial of Service (self-inflicted) | Repeated auto-prompt on every drain/page-load | mitigate | Without the `_lcModalOpen` guard and the `_lcQueueShift` dedupe-by-photoId, a fast retry loop or duplicate onSuccess firing could stack multiple modals or re-prompt the same photo endlessly, effectively stranding the engineer behind an unstoppable prompt — the exact failure mode the locked "never strand the engineer" principle forbids. Mitigated by the `_lcModalOpen` guard (one modal at a time), Cancel always advancing the queue (never re-showing the same item automatically in the same prompt cycle), and the durable "Review" badge remaining the always-available manual path if the engineer dismisses every auto-prompt. |
| T-f8e-03 | Repudiation | Toast announcing "label photo(s) uploaded" before the modal opens | accept | Purely informational; carries no worksheet content beyond a count, and the underlying confirm/unconfirm state is always readable from the Kit List regardless of whether the toast was seen. |
| T-f8e-04 | Information Disclosure | photoUrl / extracted values passed through sessionStorage | accept | Same photo URL and AI-extracted values already rendered server-side in the Kit List's label-thumb markup on every page load for any unconfirmed photo on this worksheet — sessionStorage adds no new disclosure beyond what the page already shows. |
| T-f8e-SC | Tampering (supply chain) | N/A | accept | No new npm/pip/cargo packages installed by this plan — pure Blade/vanilla-JS edit plus a PHPUnit test using only existing framework/test dependencies. Package Legitimacy Gate does not apply. |
</threat_model>

<verification>
Baseline: `php artisan test --filter=Worksheet` was 239 passing / 0 failed before this plan
(confirmed 2026-09-19; re-check immediately before Task 1 in case other quick tasks landed
since). After Task 2, the same filter must show 239 + (new test count) passing, 0 failed.

Automation cannot exercise a real offline capture, a real IndexedDB drain, sessionStorage
persistence across an actual browser reload, or the visual modal-chaining behaviour — PHPUnit
has no browser runtime. Task 2's assertions are limited to proving the new code paths exist,
are wired into all three drain() call sites, and that pre-existing capture/confirm/reviewLabel
markers are textually unregressed. Genuine offline-to-reconnect-to-confirm behaviour, the
multi-label chaining, and the Cancel-then-reload-shows-prompt-again persistence are verified
only by the Task 3 manual checklist.
</verification>

<success_criteria>
- An offline-queued label photo that uploads on reconnect (auto-drain, Retry all, or per-item
  retry) automatically surfaces the existing AI-extraction confirm/edit modal — the engineer is
  not required to find the Kit List's "Review" badge unaided.
- Confirming from this prompt writes to the Device row via the existing, unmodified
  confirmLabelPhoto endpoint.
- Cancelling never blocks sign-off, never deletes anything, and leaves the durable per-room
  "Review / Edit-Confirm" fallback intact and reachable.
- Multiple queued labels are prompted one at a time, chained without an intermediate reload,
  with a single reload once the batch is exhausted.
- The prompt persists across an unrelated page reload via sessionStorage and stops once a label
  is actually confirmed.
- No IndexedDB schema change (DB_VERSION unchanged, object store shape unchanged) and no
  controller/route change.
- Full `--filter=Worksheet` suite passes at 239 + N, 0 failed.
</success_criteria>

<output>
Create `.planning/quick/260919-f8e-confirm-queued-serial-labels-on-reconnec/260919-f8e-SUMMARY.md` when done
</output>
