---
phase: 46-visit-lifecycle
plan: 06
subsystem: visit-lifecycle
tags: [accept, send-back, scope-lock, vl-11, d-02, d-06, backfill-trap, no-javascript, csrf]
requires:
  - "Visit lifecycle columns + derived state machine (46-01)"
  - "ProjectCockpitActionController + the partially retired fence (46-04)"
  - "VisitReworkState + the office send-back banner (46-05)"
provides:
  - "POST projects/{project}/cockpit/visits/{visit}/accept (projects.cockpit.visits.accept)"
  - "POST projects/{project}/cockpit/visits/{visit}/send-back (projects.cockpit.visits.send-back)"
  - "ProjectActivityLog::ACTION_VISIT_ACCEPTED / ACTION_VISIT_SENT_BACK"
  - "the visit row's action area — two of VL-11's four controls, capped and state-gated"
  - "ProjectCockpitController::ACTIONS gains send-back, plus the ?visit= comparison state"
affects:
  - "Plan 46-07 (adds Add note + Raise a snag into the SAME action area; the cap test already iterates it)"
  - "Plan 46-05's banner — nothing wrote sent_back_at until this plan; it is now reachable in the product"
tech-stack:
  added: []
  patterns:
    - "A review surface gated on ! isClosed() as well as state(), because a derived state outranks a stored one"
    - "An id in the query string that is COMPARED against rendered rows, never looked up"
    - "A visible lock: a sentence, never a disabled control"
key-files:
  created:
    - tests/Feature/Cockpit/CockpitVisitActionsTest.php
  modified:
    - app/Http/Controllers/ProjectCockpitActionController.php
    - app/Http/Controllers/ProjectCockpitController.php
    - app/Models/ProjectActivityLog.php
    - routes/web.php
    - resources/views/components/cockpit/visit-row.blade.php
    - resources/views/components/cockpit/panel.blade.php
    - resources/views/projects/cockpit.blade.php
    - resources/css/cockpit.css
    - tests/Feature/Cockpit/CockpitPageTest.php
    - .planning/phases/46-visit-lifecycle/deferred-items.md
decisions:
  - "Acceptance is FINAL in this phase — no un-accept, because one would erase who said yes"
  - "Accept is allowed from RETURNED and SENT_BACK; send back only from RETURNED"
  - "A refused act is a 422 back with a reason, never a silent no-op — a double-click must be told which click counted"
  - "The PM's reason is NOT copied into the activity feed: one place to read the current ask from"
  - "Nobody is notified — 46-CONTEXT's in-scope list says reopen the link, not email the engineer"
  - "?visit= is cast to int and compared against rendered rows; it addresses no record"
metrics:
  duration: ~80 min
  tasks: 3
  commits: 6
  completed: 2026-09-20
---

# Phase 46 Plan 06: Accept, Send Back, and a Visible Scope Lock — Summary

**One-liner:** A PM can now say "yes, that's done" or "no, go back with a reason" from the visit's
own row — and neither act rewrites one byte of what the engineer captured, including the stored
`status` vocabulary and the `submitted_at` that 46-05's reopening compares against.

## The trap 46-01 set, and how it was defused

`Visit::state()` returns **`STATE_RETURNED`, not `STATE_CLOSED`**, for a backfilled visit whose
worksheet carries a sign-off. **24 such rows exist on live.** A review surface built on
`state() === STATE_RETURNED` would have greeted a PM, on day one, with two dozen phantom review
items for trips to site finished years ago.

The action area is therefore gated on **both** exclusions, in one derivation at the top of the row:

```php
$isReviewable = ! $isReconstructed && ! $visit->isClosed();
```

and the executable form of the warning is
`test_a_reconstructed_visit_offers_no_control_even_though_its_state_reads_returned()`, which builds
exactly the live shape (a backfilled `install` visit wrapping a signed worksheet, stored
`completed`), asserts the trap is still real, and then asserts the row offers nothing:

```php
$this->assertSame(Visit::STATE_RETURNED, $visit->state());
$this->assertTrue($visit->isClosed());
...
$this->assertSame(0, $this->countControls($rows[0]), 'A reconstructed visit must never ask a PM to ratify a guess.');
$this->assertStringNotContainsString('Accept', $rows[0]);
$this->assertStringNotContainsString('Scope locked', $rows[0]);
$this->assertStringContainsString('Reconstructed', $rows[0]);
```

46-01's own pinned assertion in `test_a_backfilled_worksheet_visit_with_a_signoff_is_locked()` was
not touched or weakened — `tests/Unit/Models` is green at 121 passed.

## What was built

### Task 1 — Accept (`8743a584`, RED at `7d67ddd5`)

`POST /projects/{project}/cockpit/visits/{visit}/accept` → `acceptVisit()` on the **same** action
controller 46-04 created. It sets `accepted_at` and `accepted_by_user_id`, logs one
`ACTION_VISIT_ACCEPTED` row, and redirects to the module drawer the PM had open.

| Behaviour | How it is proved |
|---|---|
| **The stored status is NOT rewritten** | `assertSame($before, $visit->status)` + `assertSame(STATUS_PLANNED, …)` + `assertTrue($visit->isClosed())` |
| Nothing the engineer captured moves | `submitted_at`, `survey_data`, `access_token` AND `updated_at` compared through `getRawOriginal()` before/after; four table row counts unchanged |
| A foreign visit id is a 404 | posts another project's visit, asserts 404 and that no column and no log row moved |
| A double-click is a 422 | second accept asserts the FIRST timestamp and actor survive and only one log row exists |
| The ring moves by exactly one | `1 of 2 visits completed` → `2 of 2 visits completed` in the rendered region |
| Acceptance is accountable | one activity row naming the actor, `metadata.visit_id` asserted |

**Acceptance is FINAL in this phase.** There is no un-accept: nothing in D-02 grants one, and an
un-accept that cleared `accepted_by_user_id` would erase the record of who said yes (T-46-06-03).
Recorded in the method docblock so the omission reads as a decision.

### Task 2 — Send back (`2919a8f4`, RED at `19b15240`)

`POST .../visits/{visit}/send-back` → `sendBackVisit()`. It sets `sent_back_at` and
`send_back_reason` **and nothing else**, from a `required|string|min:5|max:2000` reason.

**`submitted_at` IS NEVER CLEARED.** 46-05 derives the reopening as `sent_back_at > last
submission`, so a resubmission relocks with no flag to clear — and clearing the engineer's own
submission marker to make a form editable again is the exact D-02 violation the design exists to
avoid. Asserted directly:

```php
$this->assertSame($before['submitted_at'], (string) $survey->getRawOriginal('submitted_at'));
...
$this->assertNotNull($survey->submitted_at);
```

`test_only_the_latest_reason_is_kept_across_a_resubmission()` runs the whole loop — send back,
engineer resubmits (state returns to `RETURNED` with nothing cleared), send back again — and
asserts only the second reason is stored. **One reason, no history**: a reason history is a second
place to read the current ask from, which is how an engineer answers last month's question.

**Nobody is notified.** There is a mail path and a notification recipient resolver in this app;
using either would be inventing scope, since 46-CONTEXT.md's in-scope list says "reopens the
engineer link", not "emails the engineer". Said so in the docblock.

The PM's free text is also **not** copied into the activity feed (asserted): the feed records that
a visit went back and who sent it, and the ask itself lives in exactly one place.

### Task 3 — the visit row's action area (`bfe751d3`, RED at `99765845`)

| State | Controls | Sentence |
|---|---|---|
| planned | none | — |
| sent | none | "Awaiting the engineer" |
| returned | **Accept · Send back** | "Scope locked — returned {d M Y}" |
| sent_back | **Accept** (no second send-back) | "Sent back {d M Y} — awaiting the engineer" |
| accepted | none | "Accepted by {name} on {d M Y}" + the lock sentence |
| closed / reconstructed | none | — |

**The scope lock is a SENTENCE, not a greyed-out control** — `Scope locked — returned {d M Y}`,
rendered whenever `isLocked()` and the visit is not reconstructed. D-06 leaves nothing to disable
because there is no edit affordance at all; a PM who needs a change sends the visit back.
`assertStringNotContainsString('disabled', $row)` runs inside the cap loop, so nothing can ever
appear in a disabled state.

**The cap, asserted rather than asserted-about:**
`test_no_visit_row_ever_renders_more_than_four_controls()` iterates **7 factory states × 6
`Visit::TYPES` × 9 module drawers × 2 URL states (closed and disclosed)**, counts
`<button` + `<a ` inside each rendered `.cav-visit` node, and asserts `<= 4` plus a
`assertGreaterThan(20, $seen)` vacuity floor. Present maximum is **3** (Accept + Send back submit +
Cancel, with the reason field open); 46-07's two controls take it to four.

**No JavaScript.** Each act is its own small form POST with `@csrf`; the reason field is disclosed
by `&action=send-back&visit={id}` on the module's own URL and closed by an anchor back. All nine
banned handler attributes stay absent and no `<select>` was added — `CockpitReadOnlyFenceTest` is
green with **not one entry edited**.

**The stretched-link trap was respected:** no rule in the new CSS declares `position`, the controls
live inside the PANEL (never inside a `.cav-module` row), every class is `cav-`-prefixed and every
colour is a `--cav-*` token. All **254** compiled views lint clean under `php -l` after
`artisan view:clear`.

**`?visit=` is a comparison, never a lookup.** It is cast with `ctype_digit` to an int or null in
`ProjectCockpitController::resolveActionVisitId()` and compared against the visits the panel is
already rendering, so a hostile or foreign id opens nothing and is never echoed — asserted with
`<script>` payloads in `?action=`, `?visit=` and the visit `title`.

## Verification

```
CockpitVisitActionsTest:                     Tests:    32 passed (1146 assertions)
tests/Feature/Cockpit + tests/Unit/Cockpit:  Tests:    232 passed (4715 assertions)
tests/Feature/Visits (46-05):                Tests:    26 passed (133 assertions)
tests/Unit/Models:                           Tests:    121 passed (376 assertions)
D-06 baseline (gate-46.ps1 -Baseline):       Tests:    2 skipped, 159 passed (396 assertions)
compiled views linted (php -l):              254 linted, 0 failures
```

Baseline gate: **159 passed ≥ 159, 0 failed, 0 errors.** The two skips are the pre-existing
`ext-imagick` self-skips named in `45-BASELINE.md`. **Never compared against 161.**

Protected-file hashes (`gate-46.ps1 -Hashes`) — all three **match** `45-BASELINE.md` exactly:

| File | SHA256 |
|---|---|
| `resources/views/layouts/app.blade.php` | `9ED63C4C…0557` ✅ |
| `resources/css/app.css` | `EDAD1982…2133` ✅ |
| `tailwind.config.js` | `73BB8AD6…74BB` ✅ |

**Fence entries changed by this plan: NONE.** `CockpitReadOnlyFenceTest.php` and
`CockpitSpineTest.php` are byte-identical to their state at `96ee1d8e`
(`git diff 96ee1d8e..HEAD -- <both files>` is empty). `<select` and `<script` stay banned, all nine
handler attributes stay banned, `Upload files` and `Download` stay deferred, both `cockpitRegion()`
brackets and both GET row-count invariance tests are untouched.

## Deviations from Plan

**1. [Rule 3 — Blocking] `CockpitPageTest` pinned "exactly one cockpit write route"**
- **Found during:** Task 1, the moment the accept route was registered.
- **Issue:** 46-04's `test_the_cockpit_read_route_is_still_get_only_and_every_write_is_a_post_elsewhere()`
  ends with `assertSame(1, $writes, 'Plan 46-04 registers exactly one write route; 46-05 adds its own.')`.
  This plan legitimately registers two more. The file is outside the plan's `files_modified`, yet the
  plan's verification requires the whole cockpit suite green.
- **Fix:** the count was **raised to 3 and kept EXACT** — never relaxed to a floor — with the reason
  written at the assertion: "Plan 46-04 registers the create route; Plan 46-06 adds accept and
  send-back." A fourth write route appearing unannounced is still a red test. Everything the test
  asserts about each route (POST only, served by the action controller, one GET on the read
  controller) is unchanged.
- **Commit:** `8743a584`

**2. [Rule 3 — Blocking] `visit_notes` does not exist, so it could not be row-counted**
- The plan's Task 1 asks for a row-count assertion over `site_surveys`, `worksheets`,
  `worksheet_signoffs`, `snags` **and `visit_notes`**. `grep -rln visit_notes database/ app/` returns
  nothing: office notes are Plan 46-07's, and no such table or model exists yet.
- **Fix:** the invariance helper counts the four tables that DO exist, and the byte-comparison was
  **strengthened** to compensate — `submitted_at`, `survey_data`, `access_token` **and `updated_at`**
  are all compared through `getRawOriginal()`, so a silent `touch()` on the engineer's record would
  fail even without a row-count delta. 46-07 should add `visit_notes` to `engineerTableCounts()` when
  it creates the table.

**3. [Rule 3 — Blocking] Two READ derivations in `ProjectCockpitController`, as 46-04 needed before**
- The plan's `files_modified` does not name the read controller, but the disclosure state has to be
  resolved somewhere, and Blade may not read the request on this page. `send-back` was added to the
  existing `ACTIONS` membership list and `?visit=` is resolved beside it as a private read-only
  helper. **Neither adds a write**, which the fence's seven-table GET row-count tests still prove.
  The `?action=create-visit` gate on the two Quick-actions option lists was tightened from
  `!== null` to `=== 'create-visit'` in the same edit, so `send-back` does not pay for queries it
  never reads.
- **Commit:** `bfe751d3`

**4. [Documented, not fixed] D-46-06-01 — a send-back inside the same clock SECOND as a resubmission**
- `Visit::wasSentBack()` is a strict `greaterThan` against timestamps stored to one-second
  resolution, so a send-back issued in the same second as a resubmission reads as "not sent back"
  permanently. Surfaced by the resubmission test, which now advances the clock two minutes with a
  comment naming the finding. **Not fixed here:** the comparison is 46-01's recorded decision,
  asserted by `VisitLifecycleTest`, and relaxing it changes what an equal timestamp means on two
  public links as well. Logged in `deferred-items.md` with a suggested fix.

## Requirements

- **VL-05** — **complete.** Accept records who and when, locks the scope, and the lock is a sentence
  on the page rather than a silent state.
- **VL-06** — **complete.** Send back stores one reason, the engineer's link reopens (46-05's derived
  banner is reachable in the product for the first time), and nothing the engineer captured is
  altered or deleted to achieve it.
- **VL-10** — **not** marked complete: it spans 46-04 + 46-06 + 46-07. Two of the four actions now
  write exactly one activity row each, asserted.
- **VL-11** — **not** marked complete: it spans 46-04 + 46-06 + 46-07 and is human-checked in 46-08.
  The visit-row four-control cap is delivered and asserted over every state × type × drawer.
- **VL-12** — unchanged; remains the recorded GAP.

## Known Stubs

None. Every control rendered performs a real write, every state with no act renders a sentence
instead of a disabled control, and no placeholder data reaches any view.

## Threat Flags

| Flag | File | Description |
|------|------|-------------|
| threat_flag: new-write-endpoint | `routes/web.php` | Two POSTs — `.../visits/{visit}/accept` and `.../visits/{visit}/send-back`. Both inside `web` + `auth` (session CSRF), flag-gated, project-scoped with an explicit `project_id` assertion and a 404 otherwise, validated server-side, transactional. Enumerated as T-46-06-01…05; no surface outside that register was added. |

## Self-Check: PASSED

- `tests/Feature/Cockpit/CockpitVisitActionsTest.php` — FOUND
- `ProjectActivityLog::ACTION_VISIT_ACCEPTED` — FOUND
- `ProjectActivityLog::ACTION_VISIT_SENT_BACK` — FOUND
- `ProjectCockpitActionController::acceptVisit` — FOUND
- `ProjectCockpitActionController::sendBackVisit` — FOUND
- `route('projects.cockpit.visits.accept')` / `…send-back` registered — FOUND
- `resources/views/components/cockpit/visit-row.blade.php` contains `cav-visit__actions` — FOUND
- Commit `7d67ddd5` (RED, accept) — FOUND
- Commit `8743a584` (Task 1) — FOUND
- Commit `19b15240` (RED, send back) — FOUND
- Commit `2919a8f4` (Task 2) — FOUND
- Commit `99765845` (RED, action area) — FOUND
- Commit `bfe751d3` (Task 3) — FOUND
