---
phase: 47-cockpit-links-and-returns
plan: 02
subsystem: security
tags: [laravel, blade, information-disclosure, worksheets, leak-test]

requires: []
provides:
  - "Public engineer worksheet link no longer echoes the room-complete audit stamp (ip:{addr}|actor:{hash}) at either of its two former render sites"
  - "PublicWorksheetRoomCompleteLeakTest — non-vacuous regression coverage for both a realistic and a legacy-shaped completed_by value"
affects: []

tech-stack:
  added: []
  patterns:
    - "Render-only leak fix: stop reading the leaking accessor in the view rather than mutating the model/accessor/write path"

key-files:
  created:
    - tests/Feature/Worksheets/PublicWorksheetRoomCompleteLeakTest.php
  modified:
    - resources/views/worksheets/public-show.blade.php

key-decisions:
  - "No migration written — markRoomComplete() has written the safe ip:/actor: shape since the M-06 fix in 2026-07; the stored value was never wrong, only the view's echo of it was. Per the plan's own interfaces/scope-fence, writing a migration here was explicitly out of scope and would have required a STOP; it was not needed."
  - "roomCompletedBy() and markRoomComplete() left untouched — the defect is render-only, removing the accessor would be a larger change than this finding calls for (threat T-47-02-02, disposition: accept)"

requirements-completed: [LNK-05]

duration: ~25min
completed: 2026-10-01
---

# Phase 47 Plan 02: Stop rendering the room-complete audit stamp Summary

**Removed the raw `ip:{addr}|actor:{hash}` audit stamp from both render sites on the unauthenticated public engineer worksheet link, while leaving the completion fact and its date intact — proven non-vacuously with a test observed failing before the fix.**

## Performance

- **Duration:** ~25 min
- **Tasks:** 2 completed (Task 1: fix + leak test; Task 2: full-suite regression proof)
- **Files modified:** 2 (1 view, 1 new test)

## Accomplishments

- `F-46.7-04-01` fixed: `resources/views/worksheets/public-show.blade.php` no longer reads `$worksheet->roomCompletedBy(...)` at all. The title attribute on the "✓ Complete" pill (was line 1328) had its `title="Completed by {{ $roomCompletedBy }} at {{ $roomCompletedDisplay }}"` removed entirely (a title attribute on a static badge adds nothing a screen reader needs beyond the badge text). The "Room Complete" banner (was line 1655) now reads `✓ Room Complete at {{ $roomCompletedDisplay }}` — the date stays, `by {{ $roomCompletedBy }}` is gone.
- Both the current `ip:{addr}|actor:{hash}` shape and a legacy bare-string shape are proven never to render — this is "never render this key," not "never render this shape."
- `tests/Feature/Worksheets` suite: 265 passed, 0 failed (was 263 before this plan's 2 new tests — no regression).
- Baseline gate: 159 passed, 0 failed (meets `>= 159 passed AND 0 failed`).
- The three protected files (`layouts/app.blade.php`, `resources/css/app.css`, `tailwind.config.js`) confirmed byte-identical to `4abd2b24` — untouched by this plan.

## Task Commits

1. **Task 1: Stop rendering the room-complete audit stamp** + **Task 2: Prove the existing worksheet-page suite still passes** — `853d17fb` (fix) — both tasks' artifacts (view fix + leak test) landed in a single commit; Task 2 was a verification-only task (no file change) and is recorded here via its measured pass count, not a separate commit.

_No plan metadata commit was made per this plan's constraints (STATE.md/ROADMAP.md are owned by the orchestrator / plan 47-05)._

## Files Created/Modified

- `resources/views/worksheets/public-show.blade.php` — stopped assigning/reading `$roomCompletedBy`; removed the `title` attribute at the "✓ Complete" pill; changed the "Room Complete" banner text to drop `by {{ $roomCompletedBy }}` while keeping the date. Added a one-line comment citing `F-46.7-04-01` / `RV-03`.
- `tests/Feature/Worksheets/PublicWorksheetRoomCompleteLeakTest.php` (new) — two tests: a realistic `ip:203.0.113.4|actor:a1b2c3d4e5f6` shape and a legacy bare `abc12345` shape. Each test asserts non-vacuously (confirms the raw value is present in the DB fixture via `$worksheet->roomCompletedBy(...)` before asserting its absence from the rendered page), checks both former render sites are clean, and confirms the completion pill ("Complete") and the formatted date ("15 Sep 2026") still render.

## Non-Vacuity Evidence

Ran the leak test against the UNFIXED view first. Both tests failed as expected:
- `Not to contain: 203.0.113.4` (realistic-shape test, `PublicWorksheetRoomCompleteLeakTest.php:84`)
- `Not to contain: abc12345` (legacy-shape test, `PublicWorksheetRoomCompleteLeakTest.php:111`)
- Pre-fix run: `2 failed (6 assertions)`.

After applying the fix, both tests passed: `2 passed (12 assertions)`.

## Pinned Literal Counts (public-show.blade.php)

| Literal | Before | After |
|---|---|---|
| `{!!` | 1 | 1 |
| ` x-data` | 1 | 1 |
| ` x-show` | 1 | 1 |
| ` x-model` | 2 | 2 |
| ` x-cloak` | 1 | 1 |

Unchanged — no pinned guard was touched.

## Deviations from Plan

None — plan executed exactly as written. No migration was needed (and none was written): `markRoomComplete()` has written the safe `ip:…|actor:…` shape since the M-06 fix in 2026-07, so the stored value was never wrong — only the Blade view's echo of it to an unauthenticated page was the defect. This was a render-only fix, exactly as the plan's `<interfaces>` section anticipated.

## Self-Check: PASSED

- `resources/views/worksheets/public-show.blade.php` — FOUND, modified as described.
- `tests/Feature/Worksheets/PublicWorksheetRoomCompleteLeakTest.php` — FOUND, created as described.
- Commit `853d17fb` — FOUND in `git log`.
