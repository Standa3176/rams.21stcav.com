---
phase: 47-cockpit-links-and-returns
plan: 05
subsystem: testing
tags: [laravel, phpunit, cockpit, end-to-end, requirement-ledger]

requires:
  - phase: 47-01
    provides: "The engineer link card and worksheet revoke"
  - phase: 47-02
    provides: "The room-complete leak fix"
  - phase: 47-03
    provides: "The Returned tab, evidence, the photo ZIP"
  - phase: 47-04
    provides: "Accept / Send back / Add note / Raise a snag beneath the evidence"
provides:
  - "CockpitLinksAndReturnsEndToEndTest — 14 tests walking every state this phase added through real HTTP"
  - "47-LEDGER.md — all 17 requirement IDs reconciled against named tests, the fence/count ledger, the deploy note"
  - "ROADMAP.md with 47-01..04 ticked"
affects:
  - "Phase 47.1 (Snagging) — depends on this phase's visit-management surface"

tech-stack:
  added: []
  patterns:
    - "A plan's own task prose, corrected against the real code it describes, documented as a deviation rather than asserted as written (Walk C)"

key-files:
  created:
    - tests/Feature/Cockpit/CockpitLinksAndReturnsEndToEndTest.php
    - .planning/phases/47-cockpit-links-and-returns/47-LEDGER.md
  modified:
    - .planning/ROADMAP.md

key-decisions:
  - "Walk C's test asserting 'Add note and Raise a snag do not render on a RETURNED visit' (47-05-PLAN.md Task 1 step 12's literal wording) was corrected after reading visit-row.blade.php's real gates: $canNote includes STATE_RETURNED and $canSnag mirrors $canSendBack, so a RETURNED visit legitimately offers all four controls, which IS the VL-11 cap (exactly four), not three. The test asserts the cap instead of the plan's inaccurate prose — asserting the prose literally would have been a false claim about already-tested (Plan 47-04) code."
  - "REQUIREMENTS.md was NOT edited — Task 2's file list is only 47-LEDGER.md and ROADMAP.md, and the ledger itself is where the reconciliation lives per the plan's own instruction."
  - "Task 3 (the blocking human checkpoint) was NOT executed, NOT self-approved, and none of its eight how-to-verify questions were answered on the user's behalf. This is deliberate, per the plan's own hard_stop and the orchestrator's instruction."

requirements-completed: []

duration: ~70min
completed: 2026-10-01
---

# Phase 47 Plan 05: The walk, the ledger, the deploy note — Tasks 1 and 2 only Summary

**Four HTTP walks (14 tests, 100 assertions) over every state Phase 47 added, every gate re-run for real (460/0/9164 in Cockpit, 159/0 baseline, three hashes byte-identical), and all 17 requirement IDs reconciled in a new ledger — Task 3, the blocking human checkpoint on RV-08's calm and the acceptance test, is OUTSTANDING and was neither executed nor self-approved.**

## THIS PLAN IS INCOMPLETE

Per 47-05-PLAN.md's own frontmatter (`autonomous: false`) and Task 3's
`gate="blocking"`, only Tasks 1 and 2 were executed in this run. **Task 3 — the
human checkpoint judging RV-08 ("is the tab calm?") and the acceptance test
("can a PM copy a link and send it?") — has NOT run.** Phase 47 is therefore
**NOT complete**, and `.planning/ROADMAP.md`'s top-level Phase 47 checkbox was
deliberately left unticked.

### Task 3's questions, quoted verbatim, awaiting the human

> 1. The acceptance test. Open a real project's cockpit, open its Site survey or Worksheet drawer.
>    Can you select and copy the engineer link with nothing but your mouse or keyboard, and would
>    you be comfortable pasting it into an email to an engineer right now? If not, say what is
>    missing.
>
> 2. The link's state. Does the one-line state sentence tell you what you would actually want to
>    know before sending it (issued / submitted / signed / expired), or is something missing?
>
> 3. The worksheet revoke. Press it on a real (or test) worksheet. Confirm the old link stops
>    working and a new one appears in its place.
>
> 4. The site survey's missing revoke. Read the sentence that says a survey link cannot be revoked.
>    Is that acceptable, or would you rather it could be?
>
> 5. RV-08 — the actual question. Open a visit's Returned tab. Is it calm? One hand-off link at the
>    top, one control area at the bottom, no lightbox, no per-photo chrome, nothing crowded. If any
>    part of it feels busy or "scary" (your own word from earlier in this milestone), say which
>    part and what you would take off it.
>
> 6. The four controls. On a returned visit, accept it. Confirm you land back on the Returned tab
>    you were reading, not bounced to Overview.
>
> 7. A reconstructed visit. Find (or seed) one of the 24 backfilled visits on live. Confirm its
>    Returned tab shows its evidence but offers you nothing to press.
>
> 8. Try to break it. Open one project's cockpit in one tab and a DIFFERENT project's in another.
>    Confirm nothing you do in one ever reads or writes the other's visit.
>
> Read the ledger's "For the checkpoint" column and answer each open question there too.

**I did not answer any of these on the user's behalf.** `47-LEDGER.md`'s "For
the checkpoint" column repeats the question against each requirement it maps
to (LNK-01..04, RV-05/RV-08) so the user can answer both in one pass.

---

## What Task 1 and Task 2 actually did

### Task 1 — the walk

`tests/Feature/Cockpit/CockpitLinksAndReturnsEndToEndTest.php` (new, 14 tests,
100 assertions, all through real HTTP, none from a presenter):

- **Walk A** (4 tests): the survey link renders as visible selectable text with
  its state sentence and the "no revoke exists" sentence; the worksheet link
  renders with a working revoke — pressed for real, the old token dies, a
  different new token shows, and the `worksheets` row count stays unchanged;
  RAMS and O&M render no link card at all; the issued survey URL genuinely
  opens, unauthenticated, 200.
- **Walk B** (4 tests): the worksheet-sourced Returned tab renders the calm
  order (ZIP link before serials, gallery before serials, serials before
  sign-off — asserted by `strpos` ordering) with the three RV-03 banned
  columns seeded to REALISTIC values first (`ip:203.0.113.9|actor:deadbeef`,
  `198.51.100.77`, an iPhone user-agent string) and proven absent from the
  render; the survey-sourced tab renders its room card, notes and the
  engineer's own "other" answer text; the ZIP link is followed for real, the
  downloaded bytes opened with a genuine `ZipArchive`, and a
  `Boardroom/(after|label)/` entry confirmed present while all 13
  `WRITE_SURFACE_TABLES` rows are unchanged before/after; RAMS's `?tab=returned`
  falls back to Overview with no fourth tab and no 500.
- **Walk C** (4 tests): a fresh RETURNED visit offers all four controls, capped
  at exactly 4 (see Deviations — this corrects the plan's own prose); a real
  Accept POST lands the PM back on the Returned tab with "Accepted by"
  rendering and Add note now offered; a reconstructed visit (real photo, real
  sign-off behind it) offers zero controls while its evidence still renders;
  a cross-project `?visit=` built on project A's own cockpit URL naming
  project B's visit is opened (200, since the URL build is a cast not a
  lookup) and then POSTed for real — refused 404 by the pre-existing ownership
  guard, with project B's visit provably unchanged.
- **Walk D** (1 test): the room-complete audit stamp, seeded non-vacuously on a
  THIRD fresh fixture (`assertSame` against `roomCompletedBy()` before the
  render), never appears on the unauthenticated public engineer link, while
  the completion fact and its date still render.
- One further test re-confirms `TABS` and `ACTIONS` by reading the live
  constants rather than trusting this plan's own prose about them.

**Counted, as the plan requires:** 4 walks, 9 distinct visit/module states
rendered through real GETs (survey-with-link, worksheet-with-link,
worksheet-no-document via rams/om, worksheet-returned, survey-returned,
rams-returned-fallback, returned-pre-accept, returned-post-accept,
reconstructed), 13 WRITE_SURFACE_TABLES rows snapshotted twice (once around the
ZIP follow, once implicitly around the cross-project write via the unchanged
assertion on the foreign visit), and 2 fence constants (`TABS`, `ACTIONS`)
re-confirmed by name.

### Task 2 — every gate, the ledger, the deploy note

All five gates run foreground, redirected, one suite per invocation (see
`47-LEDGER.md` for the full table):

| Gate | Result |
|---|---|
| `tests/Feature/Cockpit` | 460 passed, 0 failed, 9164 assertions |
| `tests/Unit/Cockpit` | 114 passed, 0 failed, 1203 assertions |
| `tests/Feature/Worksheets` | 265 passed, 0 failed, 2329 assertions |
| D-06 baseline | 159 passed, 2 pre-existing skips, 0 failed |
| Protected-file hashes | all three byte-identical to `4abd2b24` |

`.planning/phases/47-cockpit-links-and-returns/47-LEDGER.md` (new) reconciles
all 17 requirement IDs (LNK-01..05, RV-01..08, VL-05..08, VL-11) against named
test methods. **None recorded NOT DELIVERED.** RV-08 alone is recorded
**DELIVERED BUT UNSETTLED** — the structural half (calm order, one hand-off
link, controls only at the bottom, no lightbox) is asserted; whether it FEELS
calm is Task 3's question.

The fence/count ledger re-confirms, by reading the live constants rather than
trusting a prior plan's summary: `FORBIDDEN_MARKUP` 2, `DEFERRED_AFFORDANCES`
19, `BANNED_HANDLER_ATTRIBUTES` 9, `WRITE_SURFACE_TABLES` 13, `TABS` 4,
`ACTIONS` 4, judged document-form count 6 — all unchanged across this plan
(nothing further was lifted; Plans 47-03/47-04 already lifted `'Download'` and
`'Add note'` before this plan started). No further hidden fence coupling was
found beyond the four sibling files (`CockpitDocumentFormTest`,
`CockpitRamsWizardTest`, `CockpitTabPreservationTest`, `CockpitVisitActionsTest`)
47-03/47-04 already corrected — confirmed by the green full-suite run, which
would show a failure, not a pass, if any pin had drifted.

**Deploy note:** `resources/css/cockpit.css` was NOT touched by any plan in
this phase (47-01: "No new CSS"; 47-03: reuses `.cav-returned__*`; 47-04:
reuses `visit-row.blade.php`'s existing `.cav-visit__actions` rules). **No
`npm run build` is required for this phase's changes on deploy.** No migration
was needed or written anywhere in this phase.

`.planning/ROADMAP.md`: ticked 47-01, 47-02, 47-03, 47-04 (each complete with
its own SUMMARY.md). 47-05's own line notes Tasks 1-2 done and Task 3
outstanding. The phase's top-level checkbox was left unticked.

## Performance

- **Duration:** ~70 min
- **Tasks:** 2 of 3 completed (Task 3 is the blocking checkpoint, not executed)
- **Files modified:** 3 (1 new test, 1 new ledger, 1 modified roadmap)

## Task Commits

1. **Task 1: The walk, through HTTP, over every state this phase added** — `566e52ec`
2. **Task 2: Every gate for real, the ledger, the deploy note, the ROADMAP tick** — `a62557e3`

**Task 3 was not executed and has no commit.** No plan-completion metadata
commit was made — this plan is not complete.

## Files Created/Modified

- `tests/Feature/Cockpit/CockpitLinksAndReturnsEndToEndTest.php` (new) — 14 tests, 100 assertions, the four walks.
- `.planning/phases/47-cockpit-links-and-returns/47-LEDGER.md` (new) — the requirement ledger, the fence/count ledger, the deploy note.
- `.planning/ROADMAP.md` — ticked 47-01..04; 47-05's line states its own incompleteness.

## Decisions Made

See `key-decisions` in the frontmatter above — the one substantive decision is
the Walk C correction against `visit-row.blade.php`'s real gates (documented
as a deviation below, not silently absorbed).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — the plan's own task prose contradicted by the code it describes] Walk C step 12's "Add note and Raise a snag do not render" on a RETURNED visit**

- **Found during:** Task 1, first run of the end-to-end test (1 failure).
- **Issue:** 47-05-PLAN.md Task 1 step 12 says a RETURNED visit shows "Accept
  and Send back render ... while Add note and Raise a snag do not
  (state-gated)". Reading `visit-row.blade.php`'s real gates (required by the
  non-vacuity instructions before writing the assertion) shows `$canNote`
  includes `STATE_RETURNED` alongside `SENT_BACK`/`ACCEPTED`, and `$canSnag`
  mirrors `$canSendBack` (both true for `RETURNED`). A fresh RETURNED visit
  therefore legitimately offers all four controls — which is exactly VL-11's
  cap of four, already proven by Plan 47-04's own
  `CockpitVisitActionsTest::test_the_returned_tabs_four_control_cap_holds_through_http_per_visit`.
  Asserting the plan's literal prose would have asserted something false about
  already-tested code and proven nothing about the cap.
- **Fix:** rewrote the test to assert the real, stronger claim: all four
  controls render on a fresh RETURNED visit, and the count is exactly 4 (the
  cap), never a 5th.
- **Files modified:** `tests/Feature/Cockpit/CockpitLinksAndReturnsEndToEndTest.php` (within this plan's own file).
- **Commit:** `566e52ec` (the correction was made before the first commit — no separate deviation commit was needed).

---

**Total deviations:** 1 auto-fixed (Rule 1 — plan prose vs. real code).
**Impact on plan:** The walk is stronger for the correction (it now proves the
VL-11 cap rather than a false negative), and the correction is documented
rather than silently absorbed, per this plan's own non-vacuity instructions.

## Issues Encountered

None beyond the deviation above. All five gates passed on their first run
after Task 1's test file was corrected.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

**NOT ready.** Phase 47.1 (Snagging) depends on this phase's visit-management
surface, and this phase is not marked complete until Task 3's human checkpoint
is answered. The next action is for the user to walk through Task 3's eight
questions (quoted above) against a real project's cockpit and either type
"approved" or describe what is wrong, screen by screen, per the plan's own
`resume-signal`.

---
*Phase: 47-cockpit-links-and-returns*
*Completed (Tasks 1-2 only): 2026-10-01*
