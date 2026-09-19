---
phase: quick
plan: 260919-enb
type: execute
wave: 1
depends_on: []
files_modified:
  - resources/views/worksheets/public-show.blade.php
  - tests/Feature/Worksheet/PublicWorksheetOfflineSignoffTest.php
autonomous: false
requirements: [QUICK-enb]
must_haves:
  truths:
    - "With navigator.onLine === false, tapping Sign & Submit does not POST to the server and shows a clear message telling the engineer signing needs a connection and to move somewhere with signal — the drawn signature and the OfflineQueue contents are untouched"
    - "With navigator.onLine === true, tapping Sign & Submit attempts a best-effort queue drain, never blocks past a short bounded wait even if the drain hangs or fails, shows a 'N item(s) still uploading' toast when items remain, and always submits the sign-off form afterward"
    - "Photos, label photos, room state, notes and checks continue to queue via the existing OfflineQueue exactly as before — enqueue/count/drain/schema (DB_VERSION, object store shape) are untouched"
    - "The existing signOffBlocked soft-gate (data-signoff-blocked, unreviewed-rooms banner) and the two-checkbox / comments-required-when-outstanding validation in prepareSignoff run unchanged and before the new offline check"
  artifacts:
    - path: resources/views/worksheets/public-show.blade.php
      provides: "Offline-refusal + online flush-and-warn logic added to window.prepareSignoff, using the existing OfflineQueue.count()/drain() and window.__wsShowToast — no new IndexedDB code"
    - path: tests/Feature/Worksheet/PublicWorksheetOfflineSignoffTest.php
      provides: "Render-level regression assertions: offline-refusal marker present, online flush/timeout markers present, existing checkbox/comments/signOffBlocked markers still present"
  key_links:
    - from: "window.prepareSignoff"
      to: "navigator.onLine"
      via: "early alert()+return false when offline, checked AFTER the existing dirty/checkbox/comments validation"
      pattern: "navigator\\.onLine"
    - from: "window.prepareSignoff"
      to: "window.OfflineQueue.count / .drain"
      via: "best-effort flush raced against a fixed timeout before resubmitting"
      pattern: "OfflineQueue\\.(count|drain)"
    - from: "window.prepareSignoff"
      to: "form submission"
      via: "HTMLFormElement.prototype.submit.call(form) called once the flush attempt settles (success, failure, or timeout)"
      pattern: "prototype\\.submit\\.call"
---

<objective>
Implement the locked rule "the job can be done offline, the job cannot be closed offline" on
the public worksheet sign-off form: offline signing attempts are refused with a helpful
message (nothing queues, nothing is lost); online signing attempts with a non-empty
OfflineQueue trigger a best-effort flush, warn if items remain, and proceed regardless.

Purpose: today the sign-off form has no offline awareness — an offline submit either fails
silently or produces a confusing native error, and a signature that later syncs after the
client has walked away is not acceptable evidence on a handover document.

Output: `window.prepareSignoff` in `resources/views/worksheets/public-show.blade.php` gains
two new branches (offline-refuse, online-flush-then-submit) appended after its existing
validation, wired to the OfflineQueue and toast primitives that already exist in this file.
No IndexedDB schema change. A new PHPUnit feature test proves the markers are present and the
existing gates are unregressed at the render level.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md

<interfaces>
<!-- Read-only reference, resources/views/worksheets/public-show.blade.php.
     Executor should extend this in place — do not rename existing globals
     (window.prepareSignoff, window.clearSignature, window.refreshSignoffSubmitState,
     window.__signoffSignatureDrawn) or restructure the surrounding IIFE. -->

Current prepareSignoff (~:1566-1580), inside the signature-pad IIFE:

  window.prepareSignoff = function (form) {
      if (! dirty) {
          alert('Please draw your signature in the box before submitting.');
          return false;
      }
      const happy = !!document.getElementById('happy_with_work')?.checked;
      const outstanding = !!document.getElementById('signed_with_comments')?.checked;
      if (! happy && ! outstanding) {
          alert('Please tick "I am happy with the work carried out" or "Outstanding items" before signing.');
          return false;
      }
      if (outstanding && (document.getElementById('comments')?.value || '').trim() === '') {
          alert('Please list the outstanding items in the comments box.');
          return false;
      }
      document.getElementById('signature_image').value = canvas.toDataURL('image/png');
      return true;
  };

Called from the form tag as `onsubmit="return prepareSignoff(this);"` (~:1400). The submit
button has `id="signoff-submit"` and `data-signoff-blocked="{{ $signOffBlocked ? '1' : '0' }}"`
(~:1447); `window.refreshSignoffSubmitState` already hard-disables the button whenever
`signoffBlocked === '1'`, so prepareSignoff is never invoked in that state — the new logic
does not need to re-check `$signOffBlocked`.

OfflineQueue primitives this plan reuses, unchanged (~:2126-2234), all already Promise-based
and already tolerant of `OfflineQueue.unavailable` / missing IndexedDB (resolve to `0` /
`{successCount:0, failureCount:0}` rather than throwing):

  OfflineQueue.count = function () { /* -> Promise<number> */ };
  OfflineQueue.drain = function (opts) { /* -> Promise<{successCount, failureCount, hitMaxRetry}> */ };

Toast helper already exposed globally by the later script block: `window.__wsShowToast(msg, variant, ttl)`
with variants `info | success | warning | error`. `DB_VERSION = 1` (~:1996) — this plan does
not touch it.
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Add offline-refuse / online-flush-and-warn branches to prepareSignoff</name>
  <files>resources/views/worksheets/public-show.blade.php</files>
  <action>
    Extend `window.prepareSignoff` (the signature-pad IIFE, ~:1566-1580) after its existing
    dirty/checkbox/comments checks and after the existing final line that snapshots
    `signature_image` from the canvas. Do not alter or reorder the existing checks — they must
    still run first and still `return false` with their existing `alert()` copy on failure.

    Add, per the locked rule:

    1. Offline refusal: if `navigator.onLine === false`, show a single `alert()` (matching the
       file's existing idiom for this form's blocking validation) stating that signing needs
       an internet connection and the engineer should move somewhere with signal, and that
       their photos and notes are already saved and will not be lost. Return `false`
       immediately — do not touch `window.OfflineQueue` at all on this path per constraint
       "the sign-off does NOT queue."

    2. Online flush-then-submit: when `navigator.onLine === true`, do not return `true`
       synchronously. Instead: disable `#signoff-submit` to prevent a double-tap, call
       `OfflineQueue.count()`, and only if it resolves greater than 0 call `OfflineQueue.drain({})`.
       Race the drain against a fixed timeout (e.g. `Promise.race` with an 8-second timer) so a
       hung fetch inside drain can never block the sign-off — rule 3 says "Do NOT block".
       Whichever settles first: if items remain outstanding (drain didn't fully empty the
       queue, drain rejected, or the timeout won the race), call
       `window.__wsShowToast('N item(s) still uploading — continuing with sign-off', 'warning', 5000)`
       using the actual outstanding count. If the queue was already empty, or the drain fully
       succeeded, skip the toast. In every case (success, partial, failure, timeout) end by
       calling `HTMLFormElement.prototype.submit.call(form)` to perform the real submission —
       calling the native `.submit()` method does not re-invoke the `onsubmit` handler, so this
       cannot recurse. Guard against the drain call itself throwing synchronously by wrapping
       the whole sequence in `.catch()` that also falls through to submitting the form.

       Always `return false` from `prepareSignoff` once past the offline check, since the real
       submission now happens asynchronously inside this new branch.

    Do not add any new IndexedDB code, do not change `DB_VERSION`, do not touch
    `OfflineQueue.enqueue`, and do not modify the `captureLabel` / `uploadWorksheetPhoto`
    wrappers or their existing offline-enqueue behaviour — those continue to queue exactly as
    today. Do not change `PublicWorksheetController::sign()` or any route.
  </action>
  <verify>
    <automated>cd "C:\Users\sonny.tanda\Documents\1 - Claude Projects\Rams2\rams.21stcav.com" &amp;&amp; C:\Users\sonny.tanda\.config\herd\bin\php84\php.exe artisan view:clear &amp;&amp; C:\Users\sonny.tanda\.config\herd\bin\php84\php.exe artisan test --filter=Worksheet</automated>
  </verify>
  <done>
    prepareSignoff renders and compiles (view:clear + existing Worksheet test suite still
    green — no Blade parse error), the offline branch never touches OfflineQueue, and the
    online branch always ends by calling the native form submit exactly once.
  </done>
</task>

<task type="auto">
  <name>Task 2: Add render-level regression test for the offline sign-off rule</name>
  <files>tests/Feature/Worksheet/PublicWorksheetOfflineSignoffTest.php</files>
  <action>
    Create a new PHPUnit feature test class `Tests\Feature\Worksheet\PublicWorksheetOfflineSignoffTest`
    following the exact scaffolding pattern in
    `tests/Feature/Worksheet/PublicWorksheetSignaturePadResizeTest.php` (same `makeWorksheet()`
    helper shape building a `User` + `Project` + `Worksheet` with `generated_data.rooms`, same
    `RefreshDatabase` trait, same `$this->get(route('public-worksheet.show', ['token' =>
    $w->access_token]))` render call). Add a doc-comment on the class stating plainly that this
    file cannot simulate `navigator.onLine` or a real IndexedDB queue under PHPUnit — these are
    render-level assertions that the new code paths exist and the pre-existing gates are
    unregressed; genuine offline/online behaviour is covered only by the manual checklist in
    this plan's Task 3.

    Assert, against the rendered HTML:
    - The offline-refusal path exists: response contains `navigator.onLine` and does NOT
      contain any string implying the offline path enqueues (i.e. do not assert anything that
      would pass if a developer wired the offline branch into `OfflineQueue.enqueue` by
      mistake — assert the offline branch is textually distinct from, and does not call,
      `OfflineQueue.enqueue`).
    - The online flush path exists: response contains `OfflineQueue.count` and
      `OfflineQueue.drain` referenced from within the signature-pad script block (not just the
      pre-existing `_autoDrain` block), and contains the resubmission call
      (`prototype.submit.call` or equivalent literal your Task 1 implementation used).
    - Pre-existing gates are unregressed: response still contains `refreshSignoffSubmitState`,
      `signed_with_comments`, `data-signoff-blocked`, and the unreviewed-rooms banner text
      `Review the survey reference for these rooms before signing off`.
  </action>
  <verify>
    <automated>cd "C:\Users\sonny.tanda\Documents\1 - Claude Projects\Rams2\rams.21stcav.com" &amp;&amp; C:\Users\sonny.tanda\.config\herd\bin\php84\php.exe artisan test --filter=PublicWorksheetOfflineSignoffTest</automated>
  </verify>
  <done>
    New test file exists, all assertions pass, and running `php artisan test --filter=Rams`
    still reports the same 875-passing baseline plus the new test count with 0 failures.
  </done>
</task>

<task type="checkpoint:human-verify" gate="blocking">
  <what-built>
    Offline-refuse + online-flush-and-warn behaviour on the public worksheet sign-off form,
    backed by the existing OfflineQueue (no schema change), plus a render-level regression
    test. Full automated suite run to confirm no regression against the 875-passing baseline.
  </what-built>
  <how-to-verify>
    1. Run the full suite and compare to baseline: `php artisan test --filter=Rams` should show
       875 + N passing (N = new tests added), 0 failed. Record the before/after counts.
    2. On a phone or desktop browser, open a public worksheet link
       (`https://rams.21stcav.com/worksheet/{token}` or local equivalent). Draw a signature,
       tick a checkbox. In DevTools, set Network to "Offline" (or toggle airplane mode on
       phone). Tap "Sign & Submit". Confirm: (a) a clear message appears telling you signing
       needs a connection and to move somewhere with signal, (b) the form does NOT navigate
       away or show a server error, (c) the drawn signature is still on the canvas afterward,
       (d) nothing new appears in the offline pending-items chip/panel as a result of this
       attempt.
    3. Go back online (toggle Network back to "Online" / disable airplane mode). If you have
       any pending photos queued from earlier testing, confirm the pending chip count is
       non-zero before proceeding. Tap "Sign & Submit" again. Confirm: (a) if items were
       pending, a "N item(s) still uploading" toast appears, (b) the sign-off form still
       submits and you land on the success/confirmation state, (c) the queue continues
       draining or is empty once the page reloads.
    4. Confirm the pre-existing unreviewed-rooms soft-block still works: with a worksheet that
       has an unreviewed survey room, confirm Sign &amp; Submit is still disabled until Mark
       Reviewed is tapped (this plan must not have changed that gate).
  </how-to-verify>
  <resume-signal>Type "approved" or describe issues</resume-signal>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|--------------|
| Client browser (public token link) → PublicWorksheetController::sign() | Untrusted client; anyone with the token URL can POST. Client-side JS (this plan's entire scope) is advisory UX only — it is fully bypassable via DevTools/curl and enforces nothing the server doesn't already enforce. |
| Client browser → IndexedDB (OfflineQueue) | Local to the device; not a network boundary. This plan does not add any new reads/writes to it. |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|------------------|
| T-enb-01 | Tampering | window.prepareSignoff (client JS) | accept | A user could bypass the offline-refuse alert entirely (call `form.submit()` directly via console) and POST while genuinely offline — but that request simply cannot reach the server if there is no connection, so the worst case is identical to today's un-refused failed POST. No server-side trust is placed in this check; `PublicWorksheetController::sign()` is unmodified and remains the sole authority over what gets persisted. |
| T-enb-02 | Repudiation | Toast "N item(s) still uploading" | accept | An engineer could sign while claiming they didn't see the warning toast. The toast is a courtesy notice, not a consent record — the underlying photos still upload independently via the existing `drain()`/retry mechanism regardless of whether the toast was seen, so no evidence is lost either way. |
| T-enb-03 | Denial of Service (self-inflicted) | Online flush-then-submit branch | mitigate | Without a bounded timeout, a hung `fetch()` inside `OfflineQueue.drain()` (dead Wi-Fi with `navigator.onLine` still true, a captive portal, etc.) could stall the sign-off submission indefinitely, directly violating the locked "Do NOT block" requirement. Mitigated in Task 1 by racing `drain()` against a fixed 8-second timeout and unconditionally submitting the form once either settles. |
| T-enb-04 | Information Disclosure | Offline-refusal alert copy | accept | The message only states that a connection is required; it exposes no worksheet content, tokens, or credentials. Standard `alert()` idiom already used elsewhere on this same form. |
| T-enb-SC | Tampering (supply chain) | N/A | accept | No new npm/pip/cargo packages installed by this plan — pure Blade/vanilla-JS edit plus a PHPUnit test using only existing framework/test dependencies. Package Legitimacy Gate does not apply. |
</threat_model>

<verification>
Baseline: `php artisan test --filter=Rams` was 875 passing / 0 failed before this plan (record
the exact number again immediately before Task 1, since other quick tasks may have landed
since). After Task 2, the same filter must show 875 + (new test count) passing, 0 failed.

Automation cannot exercise `navigator.onLine`, real network loss, or a real IndexedDB queue —
PHPUnit has no browser runtime. Task 2's assertions are limited to proving the new code paths
are present in the compiled Blade output and the pre-existing gates are textually unregressed.
Genuine offline/online behaviour, the timeout-under-a-hung-drain case, and the toast copy are
verified only by the Task 3 manual checklist.
</verification>

<success_criteria>
- Offline attempt to sign: refused with a clear on-screen message, nothing queued, signature
  and existing pending items untouched.
- Online attempt to sign with a non-empty queue: best-effort drain attempted, bounded to a few
  seconds, a "still uploading" warning shown if anything remains, sign-off proceeds regardless.
- Online attempt to sign with an empty queue: no drain-related toast, sign-off proceeds as
  before this plan.
- No IndexedDB schema change (`DB_VERSION` unchanged, object store shape unchanged).
- `PublicWorksheetController::sign()` and all routes unchanged.
- Full `--filter=Rams` suite passes at 875 + N, 0 failed.
</success_criteria>

<output>
Create `.planning/quick/260919-enb-offline-sign-off-rule-for-public-workshe/260919-enb-SUMMARY.md` when done
</output>
