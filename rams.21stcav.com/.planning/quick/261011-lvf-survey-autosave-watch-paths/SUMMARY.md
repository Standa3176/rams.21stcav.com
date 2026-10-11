---
quick_id: 261011-lvf
slug: survey-autosave-watch-paths
type: bugfix
status: complete
date: 2026-10-11
branch: feat/worksheet-classifier-universal
commits:
  - 0a72dbf0  test(261011-lvf): RED - pin every survey watch path to valid JavaScript
  - f0ee4089  fix(261011-lvf): the per-room autosave watches now use bracket notation
files_modified:
  - resources/views/surveys/show.blade.php
  - tests/Feature/Surveys/SurveyAutosaveWatchPathsTest.php   (new)
---

# 261011-lvf - the survey engineer link's per-room autosave was dead

`rooms.${idx}._ui.${f}` interpolates to `rooms.0._ui.work_type`. A numeric property straight after
a dot is a **JavaScript SyntaxError**, so Alpine threw `Uncaught SyntaxError: Unexpected number`
once per registration and silently registered **none** of the per-room watches. Three changes, all
in `resources/views/surveys/show.blade.php`:

| Line | Was | Now |
|---|---|---|
| `:2076` | `rooms.${idx}._ui.${f}` | `rooms[${idx}]._ui.${f}` |
| `:2079` | `rooms.${idx}._ui.${f}` | `rooms[${idx}]._ui.${f}` |
| `:2100` | `rooms.${idx}.engineer_feedback` (deep) | `rooms[${idx}].engineer_feedback` |

`:2091` (`engineerFeedbackSite.${f}`) carries no numeric index, was always valid, and is
**untouched** - it is the contrast that proved the diagnosis. The diff is **3 insertions, 3
deletions**, line numbers unmoved, **no comment touched** (so the bare-Blade-directive trap was
never in play), and the view still has **zero** unescaped echoes.

**Cost of the defect:** the debounced save that fires *while* an engineer types never ran in
production, nor the deep watch on multi-select chips and cable-route rows. The per-section
focusout autosave still worked on all 9 sections, so leaving a field saved - what was missing was
the safety net for a phone that dies mid-note and for toggles that blur nothing. Pre-existing from
**46.8-05** (`704f571d`).

---

## RED first, quoted

`gate-46.ps1 -Filter SurveyAutosaveWatchPathsTest` **before** the view change:

```
Tests:    4 failed, 3 passed (17 assertions)
```
```
every watch path is valid javascript once an index is substituted
substituted. A numeric property after a dot throws SyntaxError: Unexpected number and
Offending: rooms.${idx}._ui.${f}  ->  rooms.0._ui.sampleField; rooms.${idx}._ui.${f}  ->
rooms.0._ui.sampleField; rooms.${idx}.engineer_feedback  ->  rooms.0.engineer_feedback
```
```
no watch path puts an index straight after a dot
A watch path reaches a numeric index with dot notation. That is a JavaScript syntax error, not a
style preference. Offending: rooms.${idx}._ui.${f}; rooms.${idx}._ui.${f};
rooms.${idx}.engineer_feedback
```
```
the repaired paths use bracket notation
Failed asserting that 0 is identical to 2.
```
```
the rendered page ships only valid watch paths
The page actually served to an engineer still ships an invalid watch path:
rooms.${idx}._ui.${f}; rooms.${idx}._ui.${f}; rooms.${idx}.engineer_feedback
```

The three that passed RED are the ones that must: non-vacuity (the extraction regex really finds
watches), the three debounced-autosave call sites still exist, and the valid site-level watch is
present exactly once.

**The assertions are general, not literal.** Each watch expression is extracted from the view (and
from the rendered page), its interpolations substituted - a numeric index variable becomes `0`,
anything else a field name - and the result checked against a valid JS member-path grammar
(identifier, then dotted identifiers or bracketed digits only). A future `rooms.${i}`,
`rooms.${index}` or a hard-coded `rooms.0` anywhere in this view fails it too. The docblock states
in its own words that **PHPUnit cannot prove the autosave fires** - no Alpine, no JS runtime, no
layout engine.

After the fix: **7 passed (18 assertions)**.

---

## The browser proof - because the test above cannot do it

Real headless Chrome (puppeteer, already in `node_modules`) at **390x844, DPR 3**, driven against
a **locally rendered** copy of the engineer link served over `http://127.0.0.1` by a throwaway Node
server that answers the step-save POST and counts the calls. **The live site was never loaded and
no real survey was ever POSTed to** - every request to a host other than `127.0.0.1`, the Alpine
CDN and the Tailwind CDN was aborted, and the page asked for nothing else (blockedHosts empty).

The page came from the test renderer via a disposable test, **deleted before the commit**. The
pre-fix page was derived from the shipped page by substituting `rooms[${idx}]` back to
`rooms.${idx}` in the served HTML - a verified 3-expression diff, otherwise byte-identical.
Fixture: **3 rooms**.

| Measurement | RED (pre-fix page) | GREEN (as shipped) |
|---|---|---|
| `Uncaught SyntaxError: Unexpected number` on load | **27** | **0** |
| Other page errors on load | 109 | **109 - identical, see below** |
| step-save requests on load + opening a room | 0 | **0** (no load-time storm) |
| Type into Quick Notes **without blurring**, wait 1.4 s | **0 saves** | **1 save - step 2, room 0** |
| Focus still on the field when the save fired | n/a | true (nothing blurred) |
| Six watched fields across 3 steps changed in one tick | **0 saves** | **3 saves, steps 1/4/2, max 2 in flight** |

**27, not 81, because this fixture has 3 rooms.** 9 watched paths per room (1 step-1 field, 7
step-2 fields, 1 deep step-4 watch) x 3 rooms = 27; the live survey with 9 rooms gives exactly the
81 observed. The count scales with rooms, which is itself confirmation of the mechanism.

**The save-on-typing row is the one that matters.** A zero console count only proves the error
stopped; 0 to 1 step-save with focus still in the textarea proves the feature that was dead is now
alive.

**D-02 verified by measurement, not trusted.** Six field changes across steps 1, 2 and 4 in a
single tick produced **3** saves, one per step, max **2** concurrent - the per-step timer collapses
rooms x fields exactly as predicted, no storm. Nothing fired on load or on opening a room.

### The 109 other page errors are pre-existing, and that claim is proved rather than asserted

`Cannot read properties of undefined (reading 'name')` and `(reading 'type')` - bindings such as
`rooms[currentRoomIdx].name` evaluated while `currentRoomIdx` is null on the Site tab. The count is
**109 in both runs**, on pages differing only in the three watch expressions, so this change
neither caused nor cured them. Out of scope here (a separate pre-existing defect on the same view),
deliberately not touched.

---

## Gates - re-measured in the foreground today, never inherited

| Gate | Result |
|---|---|
| `-Filter SurveyAutosaveWatchPathsTest` (RED) | 4 failed, 3 passed (17 assertions) |
| `-Filter SurveyAutosaveWatchPathsTest` (GREEN) | **7 passed (18 assertions)** |
| `-Path tests/Feature/Surveys` | **95 passed (735 assertions)** - was 88/717; +7 tests, +18 assertions, exactly this new file |
| `-Filter SurveyLinkBrandingTest` | **8 passed (159 assertions)** |
| `-Filter SurveyRoomCaptureRefinementsTest` | **9 passed (38 assertions)** |
| `-Baseline` | **159 passed, 0 failed, 2 skipped (396 assertions)** |
| `-Hashes` | all three **MATCH** |

**Zero failures anywhere.** Both sv-spine pins unmoved: `SurveyLinkBrandingTest:242` is still the
substr_count of `sv-spine` and `SurveyRoomCaptureRefinementsTest:42` is still
`PRE_EXISTING_SV_SPINE_COUNT = 15` with `:283` its assertion - both files untouched, both suites
green. The compiled view lints clean (no syntax errors).

**A `-Baseline` run stalled and had to be killed.** The first invocation stopped writing output
after `CommissioningSignoffTest` and sat dead for 18 minutes (file mtime 16:00:52, checked at
16:18:44); it was killed, stale php.exe cleared, and re-run clean in the foreground, where it
finished in **37.88 s**. The figures above are from that clean run. No test was changed to make
anything pass.

## Deviations

None. Three string changes as planned, `:2091` left alone, no migration, nothing deleted, no push,
no deploy.
