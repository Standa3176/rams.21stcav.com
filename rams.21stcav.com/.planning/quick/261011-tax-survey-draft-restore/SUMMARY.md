---
quick_id: 261011-tax
slug: survey-draft-restore
type: bugfix
status: complete
date: 2026-10-11
severity: data-loss
branch: feat/worksheet-classifier-universal
follows: 261011-n91
commits:
  - e0be0477  test(261011-tax): RED - the survey draft queue has no restore half
  - aaae5f71  fix(261011-tax): restore held survey drafts on load, drain on load, retry
files_modified:
  - resources/views/surveys/show.blade.php
  - tests/Feature/Surveys/SurveyDraftRestoreTest.php   (new)
---

# 261011-tax - an offline refresh looked like it lost the engineer's typing, and in one path really did

> *"in airplane mode, engineer can see the link if it was loaded before airplane mode, can fill in
> the fields but loses everything if they accidentally refresh"* - real airplane-mode walk, today.

The survey's offline draft queue was a **send** queue with no restore half. Its own docblock says it
was *"Ported from the worksheet's DraftStore ... BY SHAPE"*, and the half that was ported was
write-and-drain. Three things the worksheet's `_notesInit` does, this view did not: put a held draft
back into the field on load, drain on load, retry on a timer.

**Diff: 171 insertions, 0 deletions** in the view. Nothing deleted, no migration, no watch
registration touched, no pin moved, zero unescaped echoes before and after.

| | Was | Now |
|---|---|---|
| Restore a held draft into the model on load | missing | `_draftRestoreAll()` / `_draftApplyToRoom()`, first statement of `init()` |
| Drain on load | missing | a bare `this._draftDrain()` in `init()` |
| Drain on `online` | present | unchanged |
| Retry timer | missing | `setInterval(..., SV_DRAFT_RETRY_MS)` - 30 s, guarded on `navigator.onLine` |
| Engineer told their work is unsent | - | the page's existing `saveError` ribbon, existing sentence |

---

## The measurement that matters: real headless Chrome, before and after

Puppeteer 24.43.1 (already in `node_modules`), **390x844 DPR 3**, against **locally rendered** copies
of the engineer link served over `http://127.0.0.1:8931` by a throwaway Node server that answers the
step-save POST and counts the calls. **The live site was never loaded and no real survey was ever
POSTed to**: every request to a host other than `127.0.0.1` was aborted and recorded -
`blockedHosts` came back **empty**, because Alpine 3.15.8 was served from `node_modules` and the
Tailwind CDN tag was dropped, so the page asked for nothing external at all.

Both pages came out of **the real renderer**, via a disposable probe test deleted before the commit:
the "after" page from the shipped view, the "before" page from `git show e0be0477:` of the same view
(the RED commit), rendered through the same fixture. **Fixture: 3 rooms.** The "before" page is
therefore not a hand-edited approximation - it is the pre-fix view as Blade actually renders it.

"No signal" was simulated by aborting **only** the `/step-save` request and forcing
`navigator.onLine` to `false`, so the reload itself still served - which is exactly the reported
case (*"can see the link if it was loaded before airplane mode"*).

### 1. The reported bug - an offline refresh

| | BEFORE | AFTER |
|---|---|---|
| Typed into room 0 Quick Notes, chars | 54 | 54 |
| step-saves that reached the server while offline | 0 | 0 |
| Draft held in `localStorage` before the refresh, chars | 54 | 54 |
| **Field value after the refresh, chars** | **0** | **54** |
| Field value matches the typed sentence exactly | false | **true** |
| `saveError` ribbon after the refresh | present, empty, **hidden** | **"Needs a connection", visible** |

The typing was never lost in the first case - 54 characters were sitting in `localStorage` with
nothing reading them back. After the fix the sentence is in the field, byte for byte, and the
engineer is told in the page's own words that it has not reached the office yet.

### 2. The severe one - silent permanent loss, which is NOT what was reported

Type in two rooms offline, refresh offline, close the tab, reopen **later, already online**. A brand
new document: `navigator.onLine` true from the first byte, so **no `online` event ever fires**.

| | BEFORE | AFTER |
|---|---|---|
| Drafts queued while offline | `0:2`, `1:2` | `0:2`, `1:2` |
| Still queued after the offline refresh | `0:2`, `1:2` | `0:2`, `1:2` |
| `navigator.onLine` on reopen | true | true |
| **`online` events fired** | **0** | **0** |
| **step-saves received on the cold online load** | **0** | **2** |
| Which entries sent | - | `0:2`, `1:2` |
| The typed sentence reached the server | **false** | **true** |
| **Queue left behind afterwards** | **`0:2`, `1:2`** | **empty** |

Zero `online` events in both runs is the point: before the fix there was nothing else that could
send, so the work sat in `localStorage` indefinitely with the engineer believing it was saved. This
path was never observed by the user and is the worst of the three.

### 3. No overwrite

After the offline refresh the engineer types again - the realistic next move.

| | BEFORE | AFTER |
|---|---|---|
| Draft after typing ` / RACK B` post-refresh | `" / RACK B"` - **9 chars** | **63 chars** |
| Starts with the original 54-char sentence | **false** | **true** |

Before the fix the re-type did not just fail to help, it **destroyed** the good draft: 54 characters
replaced by 9, because the queue is latest-wins per room+step and the field it was typed into was
empty. After the fix the restored value is still there, so the second burst appends to it.

### 4. No autosave storm on restore

With **exactly 2** drafts queued, the cold online load produced **exactly 2** step-saves - one per
queued entry, nothing extra. That is the D-03 ordering holding: the restore runs as the first
statement of `init()`, ahead of every watch registration, so writing the held values into the model
does not trigger the autosaves it is recovering from. Before the fix the same load produced **0**
saves, so the comparison that matters is 2 versus 2-plus-a-flood, and the flood is absent.

### Console errors, accounted for

4 (before) / 5 (after) in scenario A, 1 / 1 in scenario B - **every one a harness artefact**,
enumerated: `tailwind is not defined` (the CDN tag the harness deliberately dropped) and
`ERR_INTERNET_DISCONNECTED` (the step-save aborts the harness itself performs). The one extra line
in the "after" run is the new drain-on-load legitimately failing while still offline - after which
the draft is correctly still queued.

**`261011-n91` is not regressed, measured not assumed:** `Cannot read properties of undefined`
errors on load - the 109 that quick task took to zero - were **zero** in every run here.
**`261011-lvf` is not regressed, measured not assumed:** typing into Quick Notes **without
blurring** queued a draft at key `0:2` in all four runs, which can only happen if the step-2
per-room watch fired and the 600 ms debounce ran.

---

## RED first, quoted

`gate-46.ps1 -Filter SurveyDraftRestoreTest` against the pre-fix view - **re-measured with the
final test file**, not just the first draft of it:

```
Tests:    10 failed, 4 passed (34 assertions)
```
```
FAILED  ... > init restores held drafts
  To contain: _draftRestoreAll()
```
```
FAILED  ... > the restore inverts build step payload explicitly per step
  The component method `_draftApplyToRoom(` is missing from surveys/show.blade.php.
```
```
FAILED  ... > a restore tells the engineer using the pages existing wording
  The component method `_draftRestoreAll() {` is missing from surveys/show.blade.php.
```
```
FAILED  ... > the queue drains on load not only on the online event
  init() has no bare drain-on-load. Without one, an engineer who refreshes offline, closes the
  tab and reopens ALREADY ONLINE fires no 'online' event, so the held draft never sends -
  silent permanent loss, and the severe half of this defect.
```

The **4 that pass RED are the ones that must**: the view really does ship the draft queue
(non-vacuity), the stored entry shape and key are the frozen ones, the queue opens no IndexedDB, and
today's two shipped fixes are still in place. After the fix: **14 passed (71 assertions)**.

**The docblock states in its own words that this test cannot prove an engineer keeps their typing** -
PHPUnit has no JavaScript runtime, no Alpine, no `localStorage`. The four measurements above, not
this file, are the proof.

The assertions are structural, not literal: the ordering test finds `init()`'s body by **brace
depth** and asserts the restore's offset is below the first watch registration's, so a future edit
that moves the restore after a watch fails even though the page would still work; and the
inverse-mapping test asserts **every** payload key `buildStepPayload` sends for steps 1, 2, 4, 5 and
6 is named on the way back in, so a key added to the payload without a matching restore fails too.

---

## D-01 and the frozen stores - guarded by tests, not by intent

The stored entry is still `{ room_index, step, data, queuedAt }` keyed `"<roomIndex>:<step>"` under
`svDraft_<SURVEY_ID>`; a test asserts both literals and asserts no `migrateDraft` /
`draftSchemaVersion` / `_draftMigrate` symbol exists anywhere in the view. Engineers may have real
queued drafts on their phones right now, and this change reads them as they are. A second test
asserts the view contains no `indexedDB.open`, `window.indexedDB`, `IDBKeyRange` or `openDatabase(`
- the schema-frozen offline-photo store is never opened by this queue, and the restore is
`localStorage` only like the rest of it.

---

## D-06 - the worksheet, checked and NOT fixed

**No other worksheet field group queues drafts without a restore.** Established mechanically rather
than by reading:

- `DraftStore` on the worksheet has exactly **one** producer - `DraftStore.put` is called in one
  place, `_notesOnInput` (`:5377`), for key `notes:<roomName>` - and that key **is** restored into
  the textarea at `:5527`. There is no second field group on it.
- The worksheet's only other `localStorage` writes are `wsDraft_` and `wsDraftProbe_`, both part of
  that same store.
- Its two `sessionStorage` queues both have a restore half: `wsState_` (scroll / open drawers /
  active tab) is restored on `DOMContentLoaded` at `:3308`, and `wsPendingLabelConfirms_` is read
  back and surfaced through the label-confirm modal at `:3119-3144`.

**One adjacent gap found on the SURVEY, reported and NOT fixed** - it needs its own RED proof and
its own task:

> `autosaveSite()` (`resources/views/surveys/show.blade.php`, the step-0 Site Logistics save) has
> **no write half at all**. Its `catch` is `// Soft failure - site logistics is captured once per
> visit; engineer can re-fill if connectivity drops mid-edit` and it queues **nothing**. So the four
> site-level free-text fields - `parking_restraints`, `site_access_notes`, `delivery_routes`,
> `general_notes` - are silently discarded on a failed save, with no draft to restore. That is a
> different defect from this one (missing write, not missing read), it is on the Site tab rather
> than in a room, and today's restore cannot help it because nothing is ever queued.

---

## Gates - re-measured in the foreground today, never inherited

| Gate | Result | Expected |
|---|---|---|
| `-Filter SurveyDraftRestoreTest` (RED) | 10 failed, 4 passed (34 assertions) | RED |
| `-Filter SurveyDraftRestoreTest` (GREEN) | **14 passed (71 assertions)** | GREEN |
| `-Path tests/Feature/Surveys` | **116 passed (824 assertions)** | 102/753 plus exactly this file's 14/71 |
| `-Filter SurveyAutosaveWatchPathsTest` | **7 passed (18 assertions)** | unmoved |
| `-Filter SurveyRoomPanelGuardTest` | **7 passed (18 assertions)** | unmoved |
| `-Filter SurveyLinkBrandingTest` | **8 passed (159 assertions)** | unmoved |
| `-Filter SurveyRoomCaptureRefinementsTest` | **9 passed (38 assertions)** | unmoved |
| `-Baseline` | **159 passed, 0 failed, 2 skipped (396 assertions)**, 36.90 s | at least 159 passed and 0 failed |
| `-Hashes` | all three **MATCH** `4abd2b24` | MATCH |

**Zero failures anywhere, and the suite arithmetic is exact:** 102 + 14 = 116 tests,
753 + 71 = 824 assertions - this plan added its own tests and moved nothing else.

**Both `sv-spine` pins unmoved.** `SurveyLinkBrandingTest:242` is still its
`substr_count($html, 'sv-spine')` line; `SurveyRoomCaptureRefinementsTest:42` is still
`private const PRE_EXISTING_SV_SPINE_COUNT = 15;` with `:283` still its `substr_count` assertion.
Both files untouched, both suites green, and the view's own `sv-spine` count is **15 before and 15
after** - this change adds only script, no markup.

**A `-Baseline` run stalled and had to be killed** - the seventh time today. It stopped writing
output after `CommissioningSignoffTest` (file last written 21:34, still dead at 21:43) and, exactly
as warned, **the killed run reported no usable result at all**. Stale `php.exe` processes were
cleared and it was re-run clean in the foreground, finishing in **36.90 s**; the figure above is
from that clean run. No test was changed to make anything pass.

**The bare-directive trap was never in play:** every new comment was written with **no at-sign
anywhere in it** (verified by grepping the added lines for the character - zero hits), there is no
at-sign inside any Blade comment in the whole view, and `php -l` over all **257** compiled Blade
views after a `view:cache` reported no syntax errors. The page then rendered HTTP 200 through the
real renderer and ran in real Chrome.

---

## Deviations

1. **The test file needed two harness corrections after the first RED run**, both disclosed in the
   implementation commit: the drain-on-load regex had to tolerate the view's **CRLF** line endings
   (`[ \t\r]*$`, not `[ \t]*$` - the carriage return sits between the semicolon and the `$`), and
   the brace-walk helper had to be handed the method **definition** signature rather than the first
   call site, which after the fix is the call inside `init()`. Both corrections also fail RED, so
   the RED figure was **re-measured with the final test file** against the pre-fix view and is the
   one quoted above (10 failed / 4 passed / 34 assertions - identical to the first run).
2. **One adjacent defect found and deliberately not fixed** - `autosaveSite()` queues nothing at
   all (see D-06 above). Reported for its own task.

Nothing else: no migration, nothing deleted, no watch registration touched, no `x-if` guard touched,
no pin adjusted, no token minted or rendered, no push, no deploy.
