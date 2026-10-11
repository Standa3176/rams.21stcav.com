---
quick_id: 261011-n91
slug: survey-room-panel-xif
type: bugfix
status: complete
date: 2026-10-11
branch: feat/worksheet-classifier-universal
follows: 261011-lvf
commits:
  - eb076ac0  test(261011-n91): RED - pin the survey room panel to a template x-if guard
  - 2a731d32  fix(261011-n91): guard the survey room panel with x-if, not x-show
files_modified:
  - resources/views/surveys/show.blade.php
  - tests/Feature/Surveys/SurveyRoomPanelGuardTest.php   (new)
---

# 261011-n91 - the survey engineer link threw 109 TypeErrors on every load

The whole room section hung off one container guarded with `x-show`. `x-show` only toggles
`display`: Alpine still created the subtree and still evaluated every binding inside it. On the
Site tab `currentRoomIdx` is `null`, so each of the **71** `rooms[currentRoomIdx]` bindings
evaluated `rooms[null]` -> `undefined` and then read a property off it. Alpine swallowed every
throw, so the page worked - the cost was a console deep enough to bury a real error.

The fix is four lines: that one container now sits inside a `<template x-if>` carrying the
**same** guard expression, so the subtree does not exist at all while the Site tab is active.
Optional chaining was never an option - 13 of the 71 bindings are `x-model`, which needs a
settable reference.

| | Was | Now |
|---|---|---|
| `:717` | `<div x-show="activeTab !== 'site' && currentRoomIdx !== null">` | `<template x-if=...>` + `<div>` |
| `:1921` (now `:1929`) | `</div>` | `</div>` + `</template>` |

Diff: **10 insertions, 1 deletion**. The body keeps its original indentation deliberately - a
1,200-line reindent would have buried the one change that matters. Zero unescaped echoes before
and after. No watch touched. No migration. Nothing deleted.

---

## The matching close: line 1921, established mechanically

The whole implementation risk was the closing tag. **It is line 1921** in the pre-edit file -
the line already commented `/room wizard, now flattened (46.6-03)` - which is **line 1929** after
the edit. Three independent mechanical checks, no eyeballing:

1. **Depth counting over masked markup.** A script blanked every region where a `div` token is
   not real markup (Blade comments, HTML comments, script and style bodies), preserving offsets,
   then counted open/close div depth forward from `:717`. Depth returns to zero at **1921**,
   having consumed **111 opens and 112 closes** in between. Whole-file balance is **0**, and
   there are **zero** self-closing divs (which would have broken the count).
2. **Blade conditional-branch audit.** A naive count is only sound if no if/else/foreach branch
   emits an unbalanced div. A second script walked every control block in 717-1921 and checked
   each branch's div balance independently: **no conditional branch contains an unbalanced div,
   and no control block is left unclosed**. So the count above cannot be fooled.
3. **Corroboration.** 1921 is at the **same 4-space indentation** as the opener, carries that
   room-wizard close comment, and is the last line before the closing main tag.

Re-run after the edit: open `:718` closes at `:1929`, immediately followed by the new closing
template tag; whole-file balance still 0. The compiled view lints clean - `php -l` over all
**257** compiled Blade views after a `view:cache` reported no syntax errors - and every Blade
comment in the file was grepped for the directive sigil: **there is no at-sign inside any comment
in the whole view**, so the bare-directive trap was never in play.

---

## RED first, quoted

`gate-46.ps1 -Filter SurveyRoomPanelGuardTest` **before** the view change:

```
Tests:    5 failed, 2 passed (9 assertions)
```
```
FAILED  ... > the room panel is wrapped in a template x if
  To contain: <template x-if="activeTab !== 'site' && currentRoomIdx !== null">
```
```
FAILED  ... > no x show still carries the room panel guard
  (tests\Feature\Surveys\SurveyRoomPanelGuardTest.php:197)
  'An x-show still carries the room-panel guard. x-show only toggles display:
   Alpine evaluates the whole subtree regardless, which is the defect.'
```
```
FAILED  ... > the guard template holds exactly one root element which closes inside it
  The guard template is not present in real markup - it may only exist inside a comment
  or a script body.
  Failed asserting that false is not false.
```
```
FAILED  ... > every room binding lives inside the guarded subtree
  The guard template is missing.
```
```
FAILED  ... > the page served to an engineer ships the guard
  To contain: <template x-if="activeTab !== 'site' && currentRoomIdx !== null">
```

The two that passed RED are the ones that **must**: non-vacuity (the view really does ship more
than 50 `rooms[currentRoomIdx]` bindings behind exactly one copy of the guard expression) and the
view starting with balanced div tags. After the fix: **7 passed (18 assertions)**.

**The assertions are structural, not literal.** The strongest one re-implements the matcher in
PHP: it masks comments and script bodies, finds the guard template, asserts the next thing is a
single root div, depth-counts to that div's matching close, and asserts the closing template tag
comes immediately after it. A wrap that landed on the wrong closing tag - the silent re-parenting
this change risked - fails that test even though the page would still render without error. A
second asserts **every** `rooms[currentRoomIdx]` occurrence in the file lies inside the guarded
offsets, and a third asserts whole-file div balance.

**The docblock states in its own words that this test cannot prove the console errors stopped** -
PHPUnit has no JavaScript runtime, no Alpine and no layout engine. That is Task 3's job.

---

## Browser proof - real headless Chrome, all four measurements

Puppeteer (already in `node_modules`), **390x844 DPR 3**, against a **locally rendered** copy of
the engineer link served over `http://127.0.0.1` by a throwaway Node server that answers the
step-save POST and counts the calls. **The live site was never loaded and no real survey was ever
POSTed to**: every request to a host other than `127.0.0.1`, the Alpine CDN and the Tailwind CDN
was aborted - `blockedHosts` came back **empty**, the page asked for nothing else.

The page came from the test renderer via a **disposable** probe test, **deleted before the
commit**. The pre-fix page was derived from the shipped page by putting the `x-show` container
back and dropping the template wrapper - a **verified two-hunk diff** (each substitution asserted
unique; a plain diff of the two pages shows exactly those two hunks), otherwise byte-identical.
Fixture: **3 rooms**.

| Measurement | RED (pre-fix page) | GREEN (as shipped) |
|---|---|---|
| `Cannot read properties of undefined` errors **on load** | **109** | **0** |
| ... of which reading 'name' / reading 'type' | 1 / 8 | 0 / 0 |
| All console + page errors on load | 110 | **1** (see below) |
| Same errors after a Site/room/room/Site tab walk | 327 cumulative | **0** |
| Room 0 to room 1: tagged node survived | true | **true** (no remount) |
| Room 0 to room 1: same node identity | true | **true** |
| On the Site tab: room section in the DOM | **true** (still there) | **false** (unmounted) |
| Site/room round trip: typed answer in the model | preserved | **preserved** |
| Site/room round trip: value back in the re-rendered field | n/a (never unmounted) | **preserved** |
| step-save while typing, **no blur** (261011-lvf) | 1 save, step 2, room 0 | **1 save, step 2, room 0** |
| Focus still in the field when the save fired | true | **true** |

**1. The 109.** On load the pre-fix page threw **109** undefined-property reads - the exact count
`261011-lvf` recorded on the same 3-room fixture, which is how the two runs tie out. The shipped
page throws **0**. The one remaining console line in GREEN is a `404 (Not Found)` for
`/favicon.ico`, which Chrome requests unprompted and the throwaway server does not serve; it is
present identically in both runs and is an artefact of the harness, not the page.

**Correction to the plan's framing, measured not assumed.** The plan called these "reading
name/type" errors. A histogram of the actual messages says that was a sample, not the breakdown:
`engineer_feedback` **39**, `_ui` **30**, `risks` **19**, `infrastructure` **12**, `type` **8**,
`name` **1** = **109**. Same single cause (`rooms[null]`), same fix, and the fix takes all 109 to
zero - but the figure to quote is "109 undefined-property reads", of which 9 are name/type.

**2. Room-to-room does not remount - proved, not asserted.** On first mount the room section
(`#sv-step-1`) was tagged with a `data-n91` attribute Alpine never writes **and** held by direct
node reference. After switching room 0 to room 1 the tag survived **and the node identity was
unchanged**: the subtree was not destroyed. The contrast that makes this meaningful is the row
below it - on the Site tab the section is **gone from the DOM** (`roomSectionInDom: false`,
`tagStillInDom: false`), so the `x-if` is genuinely firing. Survival across rooms is therefore a
real property of the guard boundary, not an `x-if` that never triggers.

**3. The unmount is safe.** Typed "Ceiling tile loose above rack" into a room's Quick Notes, went
to the Site tab (field confirmed **gone** from the DOM), came back to the same room: the value is
in the Alpine model **and** back in the re-rendered textarea. Room answers live in the `rooms`
array, so the unmount cannot lose them, and `x-model` re-reads state on mount.

**4. `261011-lvf` is not regressed.** Typed into a watched per-room field **without blurring**,
waited past the 600 ms debounce: a `step-save` went out (step 2, room 0) with focus still in the
field (`blurredAtAnyPoint: false`) - the same 1 save as the pre-fix page. The autosave feature
repaired this morning still works through the new guard.

**Known cosmetic consequence, disclosed.** The one piece of state inside the subtree that is not
in the `rooms` array is the Reference-checklist disclosure's local `x-data="{ open: false }"`
(`:960`). Expand it, go to the Site tab and come back and it is collapsed again. No captured data
is involved. There are **no** file inputs, canvas, video or other DOM-state holders anywhere in
the region (checked), and **nothing imperative reaches into the subtree** - the only
`getElementById` / `querySelector` calls target the CSRF meta in the head and `submit-form-top`.

---

## Gates - re-measured in the foreground today, never inherited

| Gate | Result | Expected |
|---|---|---|
| `-Filter SurveyRoomPanelGuardTest` (RED) | 5 failed, 2 passed (9 assertions) | RED |
| `-Filter SurveyRoomPanelGuardTest` (GREEN) | **7 passed (18 assertions)** | GREEN |
| `-Path tests/Feature/Surveys` | **102 passed (753 assertions)** | 95/735 plus exactly this file's 7/18 |
| `-Filter SurveyLinkBrandingTest` | **8 passed (159 assertions)** | unmoved |
| `-Filter SurveyRoomCaptureRefinementsTest` | **9 passed (38 assertions)** | unmoved |
| `-Filter SurveyAutosaveWatchPathsTest` | **7 passed (18 assertions)** | unmoved |
| `-Baseline` | **159 passed, 0 failed, 2 skipped (396 assertions)** | at least 159 passed and 0 failed |
| `-Hashes` | all three **MATCH** `4abd2b24` | MATCH |

**Zero failures anywhere, and the suite arithmetic is exact:** 95 + 7 = 102 tests, 735 + 18 = 753
assertions, so this plan added its own tests and moved nothing else.

**Both sv-spine pins unmoved**, checked line by line before and after:
`SurveyLinkBrandingTest:242` is still its `substr_count($html, 'sv-spine')` line and
`SurveyRoomCaptureRefinementsTest:42` is still `private const PRE_EXISTING_SV_SPINE_COUNT = 15;`
with `:283` still its `substr_count($html, 'sv-spine'),` assertion. Both files are untouched,
both suites green, and the view's own sv-spine count is **15** before and after - `x-if` changes
what Alpine mounts at runtime, not what Blade renders.

**A `-Baseline` run stalled and had to be killed** - the fourth time today. It stopped writing
output after `CommissioningSignoffTest` (file mtime 17:09, still dead at 17:18) and, exactly as
warned, **the killed run reported exit 0**. Stale `php.exe` processes were cleared and it was
re-run clean in the foreground, finishing in **39.70 s**; the figures above are from that clean
run. No test was changed to make anything pass.

## Deviations

1. **The error breakdown differs from the plan's wording** - 109 undefined-property reads, of
   which 9 (not all) read name or type. Reported as measured; same cause, same fix, 109 to 0.
2. **The body of the wrapped block was not re-indented**, deliberately, so the change reads as 10
   insertions and 1 deletion instead of a 1,200-line reindent. A short comment on the new div says
   why (and contains no at-sign).

Nothing else: no migration, nothing deleted, no watch touched, no pin adjusted, no push, no
deploy.
