---
phase: 46-visit-lifecycle
plan: 07
subsystem: visit-lifecycle
tags: [office-note, snag, append-only, d-02, d-03, vl-11-cap, scope-fence, no-javascript, csrf]
requires:
  - "The minimal snag record (46-02)"
  - "ProjectCockpitActionController + the partially retired fence (46-04)"
  - "The visit row's action area and the four-control cap (46-06)"
provides:
  - "visit_notes — append-only, authored office annotation (migration + App\\Models\\VisitNote + factory)"
  - "POST projects/{project}/cockpit/visits/{visit}/notes (projects.cockpit.visits.notes)"
  - "POST projects/{project}/cockpit/visits/{visit}/snags (projects.cockpit.visits.snags)"
  - "ProjectActivityLog::ACTION_SNAG_RAISED"
  - "Visit::snags() and Visit::notes()"
  - "CockpitPanelPresenter office notes on the Notes tab, labelled and newest-first"
  - "the visit row at FOUR controls — the cap, REACHED"
affects:
  - "Phase 47 (finds one open snag per raise, with no lifecycle to unpick)"
  - "Plan 46-08 (end-to-end proof; the fence is unedited and the cap is at its ceiling)"
tech-stack:
  added: []
  patterns:
    - "An append-only table with created_at only, proven append-only by a source grep over app/ and the route table"
    - "A scope fence enforced TWICE — at the schema and at the HTTP boundary"
    - "Disclosure by query string where only one form can be open, because only one `action` fits in the URL"
    - "A count with no destination, rendered honestly rather than linked to a register that does not exist"
key-files:
  created:
    - database/migrations/2026_09_20_140000_create_visit_notes_table.php
    - app/Models/VisitNote.php
    - database/factories/VisitNoteFactory.php
    - tests/Feature/Cockpit/CockpitOfficeNoteAndSnagTest.php
  modified:
    - app/Http/Controllers/ProjectCockpitActionController.php
    - app/Http/Controllers/ProjectCockpitController.php
    - app/Models/ProjectActivityLog.php
    - app/Models/Visit.php
    - app/Support/Cockpit/CockpitPanelPresenter.php
    - routes/web.php
    - resources/views/components/cockpit/visit-row.blade.php
    - resources/views/components/cockpit/panel.blade.php
    - resources/css/cockpit.css
    - tests/Feature/Cockpit/CockpitVisitActionsTest.php
    - tests/Feature/Cockpit/CockpitPageTest.php
    - .planning/REQUIREMENTS.md
    - .planning/ROADMAP.md
decisions:
  - "The office note goes to a NEW append-only visit_notes table, NEVER to site_surveys.office_review_notes"
  - "A note is allowed on an ACCEPTED visit; a snag is not — a snag after acceptance is Phase 47's register"
  - "ACTION_NOTE_ADDED is REUSED for the note (a note is a note); ACTION_SNAG_RAISED is new"
  - "The note's own activity row is skipped in the Notes tab, so an office note is listed once, not twice"
  - "While a form is open the other triggers step aside — that is what keeps the row at four, not five"
  - "The snag count links nowhere: there is no register in Phase 46 and inventing one would be Phase 47"
metrics:
  duration: ~95 min
  tasks: 3
  commits: 6
  completed: 2026-09-20
---

# Phase 46 Plan 07: The Office Note, the Raised Snag, and the Cap Reached — Summary

**One-liner:** A PM can now annotate a return and raise a snag from the visit's own row — the note
lands in a new append-only `visit_notes` table that provably cannot touch a byte of what the
engineer captured, the snag is one `open` record with three fields and nothing Phase 47 owns, and
the visit row arrives at **exactly four controls**, which is the ceiling and not a target.

## The note went to `visit_notes`, NOT to `office_review_notes`

`site_surveys.office_review_notes` exists (quick task 260508-v7g) and reusing it would have been one
line. It was rejected for three reasons, written at the definition site of both the migration and
the model so the next agent does not "consolidate" them:

1. **Single-valued and overwritable** — the second note destroys the first.
2. **No author and no timestamp** — "who said that, and when" is unanswerable.
3. **It lives ON the engineer's record** — office text inside engineer-captured data is the exact
   shape D-02 forbids in its own words.

And a fourth, practical: a `worksheet` has no equivalent column at all, so reusing the survey's
would leave first-fix and install visits unannotatable.

`visit_notes` is `created_at` only (no `updated_at`), `VisitNote::UPDATED_AT === null`, with
`project_id` cascading, `visit_id` and `user_id` nulling — the same dispositions `snags` uses and for
the same reasons. `test_a_visit_note_has_no_update_path_and_no_delete_path_anywhere_in_app()` greps
every `.php` under `app/` that mentions `VisitNote` for a note-shaped variable being updated or
deleted, and checks the route table for a PUT/PATCH/DELETE on a cockpit notes URI. There is no edit
path and no delete path, structurally.

### D-02, executable — twice

At the model layer and again at the HTTP boundary, every byte the engineer owns is compared through
`getRawOriginal()`:

```php
$before = $this->engineerBytes($survey);   // submitted_at, survey_data,
                                           // office_review_notes, access_token, updated_at
$this->note($project, $visit)->assertStatus(302);

// THIS COMPARISON IS D-02, EXECUTABLE — AT THE HTTP BOUNDARY.
$this->assertSame($before, $this->engineerBytes($survey));
$this->assertSame('A pre-existing office review note.', $survey->refresh()->office_review_notes);

$visit->refresh();
$this->assertSame($visitBefore['sent_at'], (string) $visit->getRawOriginal('sent_at'));
$this->assertSame($visitBefore['updated_at'], (string) $visit->getRawOriginal('updated_at'));
```

The fixture deliberately **pre-populates** `office_review_notes`, so the assertion proves the field
is not merely left null — it is left exactly as it was found.

## The snag: nothing Phase 47 owns was added

Raising a snag creates **one** `Snag` with `title`, `detail`, `room_name`, `visit_id`,
`raised_by_user_id` and `status = open`, plus one `ACTION_SNAG_RAISED` activity row. **No column, no
outcome, no parts relation, no follow-up chain and no register were added.** `Snag::STATUSES` still
has one entry. 46-02's `test_the_snags_table_carries_no_phase_47_column()` and
`test_the_snags_table_carries_exactly_the_nine_permitted_columns()` are untouched and green.

D-03 is now fenced **twice**. `test_the_phase_47_fields_are_ignored_when_a_snag_is_raised()` posts
`outcome`, `parts`, `parent_snag_id`, `assigned_to`, `cost`, `resolved_at` and a hostile `status`,
one at a time, and asserts the created row is unaffected by each and that `status` is still `open` —
then re-asserts the six columns are still absent from the schema. Only three fields are validated and
only three are passed to `create()`; `status` is set in the controller and never read from the
request.

## The cap is REACHED, not exceeded

| State | Controls | Count |
|---|---|---|
| planned | none | 0 |
| sent | none | 0 |
| **returned** | **Accept · Send back · Add note · Raise a snag** | **4 — the cap** |
| sent back | Accept · Add note · Raise a snag | 3 |
| accepted | Add note | 1 |
| closed / **reconstructed** | none | **0** |

**Maximum controls rendered per state: 4** (returned, no form open). **Reconstructed is still zero** —
`$hasContext` and `$canSnag` both exclude `$isReconstructed`, and
`test_a_reconstructed_visit_still_offers_nothing_at_all()` rebuilds the exact live shape (a
backfilled `install` visit wrapping a signed worksheet, stored `completed`), re-asserts the 46-01
trap is still real (`STATE_RETURNED` **and** `isClosed()`), and then asserts zero controls, no
`Add note` and no `Raise a snag`.

**How five was avoided.** A disclosed form adds a submit and a Cancel. Rather than widening the cap,
**the other triggers step aside while a form is open**:

```php
$formOpen = $sendBackOpen || $noteOpen || $snagOpen;
```

so an open form renders Accept + submit + Cancel = **3**, and the closed row's four ARE the ceiling.
Only one form can ever be open because only one `action` fits in the URL — the panel cannot become a
wall of open forms, which is the failure the user named when they rejected an earlier design as busy.

46-06's `test_no_visit_row_ever_renders_more_than_four_controls()` keeps its **ceiling of four
unchanged** and now iterates **7 states × 6 types × 9 drawers × FOUR URL states** (closed,
send-back, note, snag) with its vacuity floor and its `assertStringNotContainsString('disabled')`
intact — 2,158 assertions in that one test.

## Where the note shows, and where it does not

`CockpitPanelPresenter::notes()` keeps its signature and its existing sources; office notes are
added to the same collection, **newest first, ahead of the module's own notes**, each carrying
`office => true`, its author (`actor_name`, so a deleted login reads "System" exactly as the feed
does) and `14 Aug 2026, 16:11` — the time as well as the date, because two notes minutes apart must
be readable in order. The panel labels them **"Office note"** in words, not in colour alone.

A note writes one `visit_notes` row **and** one `note_added` activity row, which would have listed
the same note twice under two sources. The presenter skips any `note_added` entry carrying
`metadata.visit_note_id`, because the note itself is already listed —
`test_an_office_note_is_listed_once_not_twice()`.

**Neither a note nor a snag reaches an engineer link.** Asserted directly with marker strings against
`/survey/{token}`:

```php
$this->assertStringNotContainsString('OFFICE-ONLY-NOTE-MARKER', $body);
$this->assertStringNotContainsString('OFFICE-ONLY-SNAG-MARKER', $body);
```

46-05's send-back banner remains the only office copy deliberately shown on a page a client signs.

## The fence

**Fence entries changed by this plan: NONE.** `git diff 96ee1d8e..HEAD -- CockpitReadOnlyFenceTest.php
CockpitSpineTest.php` is empty. `Add note` and `<textarea` were already lifted by 46-04 for exactly
this plan; this plan's snag copy is **`Raise a snag`**, which is not `Add a snag` (Phase 47, still
banned) and not `Book a visit` (Phase 47, still banned). `Upload files`, `Download`, `Add document`,
`Open register`, `Assign parts`, `Close snag` and `Mark as sent` all stay banned, `<select` and
`<script` stay banned, all nine handler attributes stay banned, both `cockpitRegion()` brackets and
both GET row-count invariance tests are untouched. A dedicated test re-asserts the eleven banned
strings against the region in all three URL states.

`WRITE_SURFACE_TABLES` was deliberately **not** grown to include `visit_notes`: that list carries an
`assertCount(7, ...)`, so adding to it would be a fence edit this plan does not need. 46-06's
suggestion was honoured in the place it belongs instead — `visit_notes` was added to
`CockpitVisitActionsTest::engineerTableCounts()`, so accept and send-back now prove they write no
office note either.

**No JavaScript, no new `position`.** Every write is a plain form POST with `@csrf`; every class is
`cav-`-prefixed; every colour is a `--cav-*` token; no hex reaches a Blade file; no rule added here
declares `position` and `.cav-module`'s own `position: relative` is untouched. All **254** compiled
views lint clean under `php -l` after `artisan view:clear`.

## Verification

```
CockpitOfficeNoteAndSnagTest:                Tests:    34 passed (248 assertions)
CockpitVisitActionsTest (46-06, cap loop):   Tests:    32 passed (2158 assertions)
tests/Feature/Cockpit + tests/Unit/Cockpit:  Tests:    266 passed (5979 assertions)
tests/Unit/Models (SnagTest, VisitTest):     Tests:    121 passed (376 assertions)
tests/Feature/Visits (46-05):                Tests:    26 passed (133 assertions)
D-06 baseline (gate-46.ps1 -Baseline):       Tests:    2 skipped, 159 passed (396 assertions)
compiled views linted (php -l):              254 linted, 0 failures
```

Baseline gate: **159 passed ≥ 159, 0 failed, 0 errors.** The two skips are the pre-existing
`ext-imagick` self-skips named in `45-BASELINE.md`. **Never compared against 161.**

Protected-file hashes (`gate-46.ps1 -Hashes`) — all three **match** `45-BASELINE.md`:

| File | SHA256 |
|---|---|
| `resources/views/layouts/app.blade.php` | `9ED63C4C…0557` ✅ |
| `resources/css/app.css` | `EDAD1982…2133` ✅ |
| `tailwind.config.js` | `73BB8AD6…74BB` ✅ |

## Deviations from Plan

**1. [Rule 3 — Blocking] `CockpitPageTest` pinned "exactly three cockpit write routes"**
- **Found during:** Task 2, the moment the notes route was registered.
- **Issue:** 46-06 raised that assertion to an EXACT 3. This plan legitimately registers two more.
- **Fix:** raised to **5 and kept EXACT**, never relaxed to a floor, with the reason at the
  assertion: "Plan 46-04 registers the create route; Plan 46-06 adds accept and send-back; Plan
  46-07 adds notes and snags." A sixth unannounced write route is still a red test.
- **Commit:** `fdc74636`

**2. [Rule 3 — Blocking] A Blade comment tripped `CockpitPanelTest`'s unescaped-output grep**
- A comment in `panel.blade.php` quoted the forbidden unescaped-output syntax verbatim while
  explaining that it is forbidden. The test greps the FILE, not the render, so the explanation
  failed the rule it was explaining.
- **Fix:** reworded to describe the rule without quoting the token. The test was not touched.
- **Commit:** `fdc74636`

**3. [Rule 3 — Blocking] Three files outside `files_modified` had to change**
- `app/Models/Visit.php` — `snags()` and `notes()` hasMany relations, so the row's snag count is a
  relation read rather than a query built inside Blade.
- `app/Http/Controllers/ProjectCockpitController.php` — `ACTIONS` gains `note` and `snag` (the same
  membership mechanism 46-04 and 46-06 used; **adds no write**), and `visits.snags` is eager-loaded
  so the count is not an N+1 inside the panel. The GET row-count invariance tests still prove the
  read writes nothing.
- `tests/Feature/Cockpit/CockpitVisitActionsTest.php` — see deviation 4.
- **Commits:** `fdc74636`, `43f790b7`

**4. [Rule 3 — Blocking, by design] Two of 46-06's exact-count tests were raised, by name**
- `test_a_returned_visit_offers_accept_and_send_back_and_nothing_else()` asserted **2** and
  `test_an_accepted_visit_offers_nothing()` asserted **0**. Both names became false the moment this
  plan shipped, so each was renamed to what it now asserts
  (`test_a_returned_visit_offers_exactly_the_four_pm_acts()` → **4**,
  `test_an_accepted_visit_offers_only_add_note()` → **1**) with a docblock recording what it was and
  why it moved. The sent-back count went 1 → 3 in place. **Every count stayed EXACT** — none was
  relaxed to `assertLessThanOrEqual`. The cap loop's **ceiling was NOT raised**; only its URL states
  grew from two to four, which makes it stricter.
- **Commit:** `43f790b7`

**5. [Decision, not a fix] `visit_notes` was NOT added to the fence's `WRITE_SURFACE_TABLES`**
- 46-06 suggested it. That list carries `assertCount(7, ...)`, so adding an entry is a fence edit,
  and this plan's brief is that the fence needs none. The invariance it was meant to buy was added
  to `CockpitVisitActionsTest::engineerTableCounts()` instead, where it costs no fence edit.

## Requirements

- **VL-07 — complete.** The office note is append-only, authored, and provably changes nothing the
  engineer captured.
- **VL-08 — complete.** 46-02 shipped the schema half and left it Planned; 46-07 ships the entry
  point, so it is now closed across both plans.
- **VL-10 — complete.** All four PM acts write exactly one activity row each, and no PM free text is
  copied into the feed.
- **VL-11 — code-complete** (human-checked in 46-08). The cap is reached and asserted at its
  ceiling; nothing renders disabled; the cockpit still ships no JavaScript.
- **VL-12** — unchanged; remains the recorded GAP.

## Known Stubs

None. Every control rendered performs a real write, the snag count is a real count of real rows, and
no placeholder data reaches any view. The snag count deliberately links nowhere — that is not a stub
but the honest rendering of a record Phase 47 will give a home, and it is recorded as such at the
markup.

## Threat Flags

| Flag | File | Description |
|------|------|-------------|
| threat_flag: new-write-endpoint | `routes/web.php` | Two POSTs — `.../visits/{visit}/notes` and `.../visits/{visit}/snags`. Both inside `web` + `auth` (session CSRF), flag-gated, project-scoped with an explicit `project_id` assertion and a 404 otherwise, validated and bounded server-side, transactional, and POST-only (no PUT/PATCH/DELETE, asserted over the route table). Enumerated as T-46-07-01…06; no surface outside that register was added. |

## Self-Check: PASSED

- `database/migrations/2026_09_20_140000_create_visit_notes_table.php` — FOUND
- `app/Models/VisitNote.php` — FOUND
- `database/factories/VisitNoteFactory.php` — FOUND
- `tests/Feature/Cockpit/CockpitOfficeNoteAndSnagTest.php` — FOUND
- `ProjectCockpitActionController::storeNote` / `storeSnag` — FOUND
- `ProjectActivityLog::ACTION_SNAG_RAISED` — FOUND
- `route('projects.cockpit.visits.notes')` / `…snags` registered — FOUND
- `CockpitPanelPresenter::officeNotes` — FOUND
- Commit `d3d793d5` (RED, the append-only note) — FOUND
- Commit `d54f3dd5` (Task 1) — FOUND
- Commit `092882a9` (RED, the two actions) — FOUND
- Commit `fdc74636` (Task 2) — FOUND
- Commit `31cb0c7d` (RED, the fourth control) — FOUND
- Commit `43f790b7` (Task 3) — FOUND
