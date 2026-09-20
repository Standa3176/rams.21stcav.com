---
phase: 46-visit-lifecycle
plan: 08
subsystem: visit-lifecycle
tags: [end-to-end, read-only-fence, re-proof, traceability, human-checkpoint, d-01, d-02, vl-12-gap]
requires:
  - "the create-visit write surface (46-04)"
  - "the survey carry-forward (46-03)"
  - "send back reopening the engineer link (46-05)"
  - "Accept / Send back (46-06)"
  - "the office note and the raised snag (46-07)"
provides:
  - "tests/Feature/Visits/VisitLifecycleEndToEndTest — the phase's one end-to-end proof"
  - "the fence re-proved three ways, including the <button asymmetry"
  - "VL-01..VL-11 traced; VL-12 still reads GAP"
affects:
  - "Phase 47 (inherits one open snag per raise and a lifecycle nobody has half-built)"
  - "Phase 48 (Upload files / Download / Mark as sent are still banned by name)"
tech-stack:
  added: []
  patterns:
    - "One narrative test where the VALUE is the sequence, not the steps — each step is already unit-proved elsewhere"
    - "An engineer seat that drops the auth guard first, so assertGuest() proves the route and not the harness"
    - "A closing byte-identity assertion that spans five office acts"
key-files:
  created:
    - tests/Feature/Visits/VisitLifecycleEndToEndTest.php
  modified:
    - .planning/REQUIREMENTS.md
    - .planning/STATE.md
    - .planning/ROADMAP.md
decisions:
  - "The walk advances the clock between acts because D-46-06-01 makes a same-second walk fail for reasons unrelated to the feature — this is NOT a fix for it"
  - "survey_data is compared across OFFICE acts only: the engineer editing through a reopened link is the point of a send-back, not a violation"
  - "The office-act byte comparison at step 5 moved to BEFORE the engineer's own edit, so it proves the send-back and not the sequence"
  - "VL-11 recorded as code-complete but AWAITING THE HUMAN CHECK — not self-approved"
  - "VL-12 left as GAP; no row touched"
metrics:
  duration: ~85 min
  tasks: 2 of 3 (Task 3 is the human checkpoint)
  commits: 2 (+1 close-out)
  completed: 2026-09-21
---

# Phase 46 Plan 08: The End-to-End Walk, the Fence Re-Proof, and the Close-Out — Summary

**One-liner:** One test now walks a whole job — a PM creates a visit, the engineer returns it
unauthenticated by token, the install link carries what the survey learned, the PM sends it back,
the engineer resubmits, the PM annotates, snags and accepts — and it closes on a byte-identical
comparison proving five office acts moved nothing the engineer owns.

## Task 1 — one test, the whole job (`395ea0f5`)

`tests/Feature/Visits/VisitLifecycleEndToEndTest.php`, ONE narrative test method,
**115 assertions**. Every prior plan unit-proved its own step; the only thing left to prove was
that the steps compose, so this is deliberately not nine small tests.

| Step | What it walks | What it asserts |
|---|---|---|
| 1 | PM POSTs Create visit on `?module=site_survey`, date + two labour resources | one `visits` row with `created_by_user_id` and both resource ids, one engineer link (`access_token` non-empty, `source_type/source_id` bound to the survey), exactly one `visit_created` activity row, `state() === SENT` |
| 2 | The engineer submits **UNAUTHENTICATED** through the real `POST /survey/{token}/submit` | all ten D-01 fields land on the survey record, each with its own sentinel so a failure names the field; `submitted_at` set; the visit reads `RETURNED` |
| 3 | PM creates an INSTALL visit from `?module=worksheet` | the worksheet is created with its own token, `BuildWorksheetJob` dispatched (`Bus::fake()`), no adoption |
| 4 | **The moment the phase exists for.** The install engineer opens `/worksheet/{token}`, unauthenticated | all ten carried values on the page; then the survey is edited and the link is re-fetched — the NEW wording is present and the superseded value is **gone**; and `submitted_at` / `survey_data` / `access_token` are untouched by that office edit |
| 5 | PM sends the SURVEY visit back with a reason | (a) the send-back moved **no** engineer byte; (b) `step-save` returns **200** again with `isSubmitted()` still true and nothing cleared; (c) the reason is on the **engineer's** `/survey/{token}`; (d) the reason is **absent** from the client-signed `/worksheet/{token}`; (e) `submitted_at` and the token still identical |
| 6 | The engineer resubmits | `wasSentBack()` false, `isLockedForEngineer()` true, `step-save` **403** again, and the banner is gone **whole** — no "previously sent back" history. No flag was cleared by hand anywhere |
| 7 | The install engineer signs off; PM adds an office note and raises a snag | one `visit_notes` row with its author, one `snags` row at `status = open`, one `snag_raised` activity row, and the wrapped worksheet byte-identical (`access_token`, `generated_data` **and** `updated_at`), signoff count unmoved |
| 8 | PM accepts the install visit | `accepted_at` + `accepted_by_user_id` set, stored `status` still `planned`, the module sentence moves **`0 of 1 visit completed` → `1 of 1 visit completed`**, and `Scope locked` renders as a sentence alongside `Accepted by Priya Mistry` |
| 9 | **The closing assertion** | across five office acts after the engineer's return — create the install visit, send back, add a note, raise a snag, accept — the survey's `submitted_at`, `survey_data` and `access_token` and the worksheet's `access_token` are byte-identical through `getRawOriginal()` |

### Three things the walk forced into the open

1. **`assertGuest()` was proving nothing without help.** `actingAs()` persists for the whole test,
   so a public request made after a PM request silently carried the PM's session. The walk now goes
   through `asEngineer()`, which drops the guard and asserts guest BEFORE each public request — so
   each engineer step is genuinely anonymous rather than anonymous by arrangement.
2. **The clock has to move.** `Visit::wasSentBack()` is a strict `greaterThan` on second-resolution
   timestamps (**D-46-06-01**), so a walk executed inside one clock second fails for reasons that
   have nothing to do with the feature. The walk travels between acts, which is also what a real job
   does. **This is not a fix for D-46-06-01** — that defect is carried forward untouched.
3. **`survey_data` legitimately moves, and only the engineer may move it.** Step 5(b) writes it
   through the reopened link. The office-act comparison was therefore moved to sit BEFORE the
   engineer's own edit, so it proves the send-back rather than proving the ordering.

### TDD gate compliance

The plan carries `tdd="true"`. **There is no RED commit and that is deliberate, not a skipped
gate:** this plan writes no production code. Every behaviour it exercises shipped in 46-01..46-07,
so a test written to fail first would only have been failing against itself. The RED/GREEN sequence
for each behaviour lives in the plan that built it. Recorded here so the absence reads as a
decision.

## Task 2 — the fence re-proved, three ways, and the requirements closed (`5273632a`)

### The ritual — what went red, verbatim

Each breakage was applied, run, recorded, and reverted with `git checkout --`. The working tree is
clean of all three.

**1. `cockpitRegion()`'s XPath pointed at `cav-cockpit-THAT-DOES-NOT-EXIST` — the vacuity guard fired.**

```
FAILED  Tests\Feature\Cockpit\CockpitReadOnlyFenceTest > repeated renders still change no row count
  The cav-cockpit root element was not found — the fence would pass vacuously.
  Failed asserting that null is not null.
  at tests\Feature\Cockpit\CockpitReadOnlyFenceTest.php:235

Tests:    10 failed, 1 passed (71 assertions)
```

**2. The extracted region truncated to 2,000 characters — the BOTTOM bracket fired, and only the bottom one.**

```
FAILED  Tests\Feature\Cockpit\CockpitReadOnlyFenceTest
  ...<span class="cav-tile cav-hue--date cav-kpi__icon" aria-hidden="t
  To contain: Open full project
  at tests\Feature\Cockpit\CockpitReadOnlyFenceTest.php:250

Tests:    10 failed, 1 passed (101 assertions)
```

The TOP bracket (`Fence Test Job`, the masthead) still PASSED on the truncated region — which is
exactly why the helper carries two brackets and why neither may be removed.

**3. `<button onclick="alert(1)">Upload files</button>` injected into `cav-panel__body` — and the ASYMMETRY held.**

```
FAILED  CockpitReadOnlyFenceTest > none of the deferred affordances appears
        <button onclick="alert(1)">Upload files</button>
  Not to contain: Upload files

FAILED  CockpitReadOnlyFenceTest > rows are static and nothing is wired to a handler
        <button onclick="alert(1)">Upload files</button>
  Not to contain: onclick

✓ the cockpit region contains no form control and no script

Tests:    2 failed, 9 passed (1039 assertions)
```

**Exactly two tests went red, and the third stayed green.** The handler ban fired on `onclick`; the
Phase 48 affordance ban fired on `Upload files`; and `the cockpit region contains no form control
and no script` — the test that used to ban `<button` — **PASSED**, because 46-04 lifted `<button`
by name so the cockpit could have a submit control. That is the proof the retirement was surgical:
the fence gave up one markup string and kept everything the phase does not own.

### Every gate, verbatim

| Gate | Result |
|---|---|
| `-Filter VisitLifecycleEndToEndTest` | `Tests:    1 passed (115 assertions)` |
| `-Path tests/Feature/Cockpit` | `Tests:    201 passed (5726 assertions)` |
| `-Path tests/Unit/Cockpit` | `Tests:    65 passed (253 assertions)` |
| **cockpit suite total** | **266** — the same number 46-07 closed on |
| `-Path tests/Feature/Surveys` | `Tests:    6 passed (23 assertions)` |
| `-Path tests/Feature/Worksheets` | `Tests:    33 passed (123 assertions)` |
| `-Path tests/Feature/Visits` | `Tests:    27 passed (248 assertions)` |
| `-Path tests/Unit/Models` | `Tests:    121 passed (376 assertions)` |
| `-Filter LabourResourceClientSurfacePrivacyTest` | `Tests:    8 passed (42 assertions)` |
| **`-Baseline` (D-06)** | **`Tests:    2 skipped, 159 passed (396 assertions)`** — `>= 159 passed AND 0 failed`. Never an equality against 161 |
| **FULL SUITE** (`-Path tests`) | **`Tests:    2 deprecated, 1 failed, 10 warnings, 6 skipped, 3118 passed (17088 assertions)`**, `Duration: 648.74s` |
| `npm run build` | `✓ built in 27.65s`, `public/build/assets/cockpit-BsLbbRID.css  24.86 kB` |

**The one full-suite failure, named and left alone:**
`Tests\Feature\Queue\QueueRecoverCommandTest > unhealthy queue runs restart and drain plan`. It is
the documented pre-existing full-suite-only memory-threshold interaction — the same single failure
STATE.md's Phase 30 close-out records against a 2670/2671 run. It is **not** absorbed silently and
it is **not** fixed here.

### The three sha256 pins — `-Hashes`, working-tree `Get-FileHash`

| File | Measured | Expected (45-BASELINE, `4abd2b24`) | Verdict |
|---|---|---|---|
| `resources/views/layouts/app.blade.php` | `9ED63C4C754F33832E12BB07A2C515AAC82AB656C5C9A1D35AED0174A7FF0557` | identical | **MATCH** |
| `resources/css/app.css` | `EDAD1982303B9FABF86A4B791BBF16436104251277CA79DBAEFB5603C8BE2133` | identical | **MATCH** |
| `tailwind.config.js` | `73BB8AD6B7CDF4DC0B11C51661E3DBC7A274CABBCC226D1FF41B5E50938E74BB` | identical | **MATCH** |

All three via the gate script's own `Get-FileHash` on working-tree bytes — a `git hash-object` or
any LF-normalised hash would not match on this CRLF checkout and would read as a false positive.

### The requirements

`VL-03` moved from **In progress** to **Complete** and `VL-04` from **Planned** to **Complete**,
both closed by the end-to-end walk. `VL-01`, `VL-02`, `VL-05`..`VL-10` were already traced by the
plans that delivered them and were left as they stood.

`VL-11` is recorded as **code-complete, AWAITING THE HUMAN CHECK** — not self-approved. The property
being judged is "simple to use" in the user's own words, and no assertion in this repo settles it.

**`VL-12` still reads GAP and no row was touched.** Nothing in this phase scopes the RAMS or
worksheet generators to a visit's rooms.

## What Phase 46 did NOT deliver

| Not delivered | Why, and who owns it |
|---|---|
| **VL-12 — per-visit document scoping** | D-05. The visit captures `rooms_in_scope` and the engineer link renders it, but `RamsController::generateFromProject()` and `WorksheetController::generateFromProject()` still take a whole `Project`, and the RAMS is authored by an AI pipeline driven by the entire quote. Nobody has decided how a room-scoped RAMS should be written. **Wants its own phase.** |
| **An "Edit visit" control** | D-06, by decision. A fifth control on every row would break the four-control cap the user's own brief demands. A PM who needs a change **sends it back**, which reopens the engineer link — one workflow, not two with different consequences. |
| Snag outcomes, parts, follow-up chains | Phase 47. This phase raises a snag; it does not manage one. |
| Sending a document to a client, confirming sent, uploading files | Phase 48. `Upload files`, `Download`, `Mark as sent` and `Issue to client` are all still banned by name in the fence. |

## Deferred Issues — carried forward, deliberately NOT fixed

Both live in `.planning/phases/46-visit-lifecycle/deferred-items.md`.

**D-46-05-01 — `/worksheet/{token}` 500s for a worksheet with no rooms. THIS IS LIVE ON THE SERVER
NOW.** `Undefined variable $signOffBlocked`: assigned at `public-show.blade.php:773`, inside the
populated-rooms branch, and read at `:1359` and `:1441`, which are reached regardless. Proven
pre-existing by reverting the view to `HEAD` and re-running the same case. The fix is to hoist the
assignment above the `@if(empty($rooms))` split with a `false` default, plus a regression test for
the empty-rooms render. **Worth a quick task** — it is a client-facing page returning 500.

**D-46-06-01 — a send-back in the same clock second as a resubmission reads as "not sent back".**
`Visit::wasSentBack()` is a strict `greaterThan` on second-resolution timestamps, so the reason is
stored but neither the cockpit line nor the engineer banner ever appears. The one-character fix
changes what an EQUAL timestamp means on two public links, which is a 46-01 decision to revisit
deliberately, not a 46-08 edit. This plan worked around it by moving the clock and says so in the
test's own docblock.

## Deviations from Plan

**1. [Rule 3 - Blocking] `Worksheet::STATUS_READY` does not exist**
- **Found during:** Task 1. The model carries `pending / generating / draft / final / failed`.
- **Fix:** the walk sets `STATUS_DRAFT`, which is what the build job produces.

**2. [Rule 1 - Bug in the test] `assertGuest()` was passing vacuously**
- **Found during:** Task 1. `actingAs()` persists across a test, so every public request after the
  first PM request carried the PM's session.
- **Fix:** the `asEngineer()` helper drops the guard and asserts guest before each public request.
  Without it the walk would have proved the engineer's seat works *while logged in as a PM*.

**3. [Scope, deliberate] No RED commit for a `tdd="true"` plan** — see "TDD gate compliance" above.

**4. [Process] `state.advance-plan` / `state.update-progress` NOT run.** STATE.md was hand-edited,
per its own warning block. `roadmap.update-plan-progress 46` was run and is safe.

## Threat Flags

None. This plan added one test file and three planning documents; no route, controller, model,
migration or view was touched.

## The human checkpoint — NOT self-approved

Task 3 is a `checkpoint:human-verify` with `gate="blocking"`. Execution **stopped** there. The two
questions it exists for — "does this page still look simple and unscary" and "is anything missing
you expected to be able to do here" — are the user's judgement, and Phase 45's own human check
(45-14) exists for the same reason. The phase is not complete until it is answered.

## Self-Check: PASSED

- `tests/Feature/Visits/VisitLifecycleEndToEndTest.php` — FOUND
- `.planning/phases/46-visit-lifecycle/46-08-SUMMARY.md` — FOUND
- commit `395ea0f5` — FOUND
- commit `5273632a` — FOUND
- commit `6149fe1e` (close-out) — FOUND
- working tree clean of all three deliberate fence breakages
