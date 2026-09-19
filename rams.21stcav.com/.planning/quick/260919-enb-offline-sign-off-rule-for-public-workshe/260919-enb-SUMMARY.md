---
phase: quick
plan: 260919-enb
subsystem: public-worksheet-signoff
tags: [offline, service-worker-adjacent, javascript, blade, worksheet]
requires: []
provides:
  - "window.prepareSignoff offline-refuse / online-flush-and-warn branches"
affects:
  - resources/views/worksheets/public-show.blade.php
tech-stack:
  added: []
  patterns:
    - "Promise.race against a fixed timeout to bound a best-effort background flush before a synchronous-looking form action completes"
    - "Re-derive a user-facing count from a fresh read (OfflineQueue.count()) after an async race settles, rather than trusting the raced operation's own return value, when that operation has a documented reentrancy short-circuit"
key-files:
  created:
    - tests/Feature/Worksheet/PublicWorksheetOfflineSignoffTest.php
  modified:
    - resources/views/worksheets/public-show.blade.php
decisions:
  - "Re-check OfflineQueue.count() after the drain/timeout race settles instead of trusting drain()'s returned counts, because OfflineQueue.drain() has a `_draining` reentrancy guard that resolves {successCount:0,failureCount:0,skipped:true} on a concurrent call (e.g. the existing 'online' event auto-drain firing at the same moment) — trusting that shape would produce a false 'all clear' toast while a real drain is still running elsewhere."
  - "Offline refusal returns false immediately without touching window.OfflineQueue at all, per the locked rule that a refused offline sign-off must not queue anything."
  - "Every path through the new async branch ends in HTMLFormElement.prototype.submit.call(form) (success, partial, failure, timeout, or a synchronous throw caught by try/catch) so a signature button can never silently do nothing."
metrics:
  duration: "~35 minutes"
  completed: "2026-09-19"
---

# Quick Task 260919-enb: Offline sign-off rule for the public worksheet Summary

Public worksheet sign-off now enforces "the job can be done offline, the job cannot be closed offline": offline taps on Sign & Submit are refused with a plain-language message and touch nothing in `OfflineQueue`; online taps trigger a best-effort, timeout-bounded flush of any queued photos before the form always submits.

## What was built

**Task 1 — `resources/views/worksheets/public-show.blade.php`:** Extended `window.prepareSignoff` (the signature-pad IIFE, previously ending at the `canvas.toDataURL()` snapshot line) with two new branches appended after all existing validation (dirty-signature check, happy/outstanding checkbox check, comments-required-when-outstanding check) and after the existing `signature_image` snapshot line — none of that existing logic was reordered or altered.

1. **Offline refusal:** if `navigator.onLine === false`, show an `alert()` telling the engineer signing needs a connection, to move somewhere with signal, and that their photos/notes are already saved and safe. Returns `false` immediately. Does not reference `window.OfflineQueue` at all on this path.
2. **Online flush-then-submit:** if online, disables `#signoff-submit` (reusing the existing `submit` const already in scope in this IIFE), calls `window.OfflineQueue.count()`, and only if it resolves `> 0` calls `window.OfflineQueue.drain({})`, raced via `Promise.race` against an 8-second timer so a hung fetch inside drain can never block the sign-off. Whichever settles first, the code re-checks `window.OfflineQueue.count()` (not the drain result's counts) and shows a `window.__wsShowToast(...)` "N item(s) still uploading — continuing with sign-off" warning only if that fresh count is `> 0`. Every branch (empty queue, drain success, drain failure, timeout, missing `OfflineQueue`, or a synchronous throw caught by `try/catch`) ends by calling `HTMLFormElement.prototype.submit.call(form)` — calling the native method bypasses the `onsubmit` handler, so this cannot recurse. `prepareSignoff` always `return`s `false` once past the offline check, since real submission now happens asynchronously.

**Task 2 — `tests/Feature/Worksheet/PublicWorksheetOfflineSignoffTest.php`:** New PHPUnit feature test class following the `PublicWorksheetSignaturePadResizeTest` scaffolding pattern (same `makeWorksheet()` helper, same `RefreshDatabase` trait, same public-token render call). Class doc-comment states plainly that PHPUnit cannot simulate `navigator.onLine`, real network loss, or a real IndexedDB queue — these are render-level assertions only. Four tests:

- Offline-refusal path exists (`navigator.onLine` + the refusal alert copy present).
- Online flush-then-submit path exists (`OfflineQueue.count()`, `OfflineQueue.drain({})`, `prototype.submit.call`, "still uploading" all present in the signature-pad script block).
- Pre-existing gates unregressed (`refreshSignoffSubmitState`, `signed_with_comments`, `data-signoff-blocked` still present).
- The unreviewed-rooms soft-block banner still renders — this required building a `SiteSurvey` + `SiteSurveyRoom` (with `mounting_heights` engineer-feedback data) linked by `project_id`, since the banner's gate (`$roomsRequiringReview` / `$signOffBlocked` in the Blade view itself) is driven by a linked survey room carrying engineer-feedback data, not by the worksheet's own `is_surveyed` flag.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - blocking issue] `SiteSurvey::create()` required `project_name`**
- **Found during:** Task 2, writing the unreviewed-rooms banner test
- **Issue:** `site_surveys.project_name` is `NOT NULL`; the initial test setup omitted it and failed with a `QueryException` at test time, not at Blade-compile time.
- **Fix:** Added `'project_name' => $w->project_name` to the `SiteSurvey::create()` call.
- **Files modified:** `tests/Feature/Worksheet/PublicWorksheetOfflineSignoffTest.php`
- **Commit:** `24324b5e`

**2. [Rule 3 - blocking issue] Unreviewed-rooms banner not driven by `is_surveyed`**
- **Found during:** Task 2, writing the unreviewed-rooms banner test
- **Issue:** The plan's Task 2 action described the assertion but the initial approach (flipping `generated_data.rooms[0].is_surveyed` to `false`) does not trigger the banner — reading the view's own `@php` block (~:762-779) showed the gate is actually driven by `$roomsRequiringReview`, which is populated only when a linked `SiteSurvey` has a room with engineer-feedback data (mounting heights, cable routes, etc.) AND `$worksheet->surveyReviewedAt($roomName)` is `null`.
- **Fix:** Built a real `SiteSurvey` + `SiteSurveyRoom` (with non-empty `mounting_heights`) linked to the worksheet's `project_id`, matching the room name, and left it unreviewed (the default state) so the banner's true gate condition is satisfied.
- **Files modified:** `tests/Feature/Worksheet/PublicWorksheetOfflineSignoffTest.php`
- **Commit:** `24324b5e`

### Plan warning handled

The plan-checker warning about `OfflineQueue.drain()`'s reentrancy guard was handled directly in Task 1's implementation, not deferred: the toast's outstanding-item count comes from a fresh `window.OfflineQueue.count()` call made after the drain/timeout race settles, never from the (possibly `{skipped:true}`) return value of the raced `drain()` call itself.

## Testing

- `php artisan view:clear` — no Blade parse error (this file has a documented history of `@php`/`?->`/glued-`@if` parse failures; the view was proven to compile by rendering it, not by reading it).
- `php artisan test --filter=Worksheet`: **235 → 239 passing** (4 new tests added), **0 failed**, both before and after Task 2.
- `php artisan test --filter=Rams`: **875 passing, 0 failed**, unchanged before and after this plan. Note: the new `PublicWorksheetOfflineSignoffTest` class name does not contain the substring "Rams", so PHPUnit's `--filter=Rams` (a plain substring filter — there is no `Rams` testsuite defined in `phpunit.xml`) does not pick it up. The plan's stated success criterion ("`--filter=Rams` suite passes at 875 + N") is therefore reported honestly as **875 + 0 under that specific filter**, with the 4 new tests verified separately and unambiguously via `--filter=PublicWorksheetOfflineSignoffTest` and `--filter=Worksheet` (both green, 0 failures, counts above).

## Non-negotiables verified

- Every path through `prepareSignoff`'s new branches ends in either the offline `alert()` + `return false`, or an eventual `HTMLFormElement.prototype.submit.call(form)` — including the `try/catch` fallback for a synchronous throw.
- Commit `2f76fd1` (width-gated resize + per-stroke `captureSignature()`) untouched; `signature_image` is still set from `canvas.toDataURL()` immediately before the new offline check, exactly as before this plan.
- Existing guards (signature-drawn, two checkboxes, comments-required-when-outstanding) run first, unmodified, before any new logic — verified by re-reading the diff and by the passing pre-existing Worksheet test suite.
- `DB_VERSION` (still `1`), `OfflineQueue.enqueue`, and the object-store shape are untouched — no IndexedDB schema changes were made.
- `PublicWorksheetController::sign()` and all routes are unmodified — this plan is Blade + tests only.

## Known Stubs

None.

## Threat Flags

None — this plan's implementation matches the STRIDE register in the plan exactly (T-enb-01 through T-enb-04, T-enb-SC), no new network endpoints, auth paths, or trust-boundary surface was introduced.

## Self-Check

- `resources/views/worksheets/public-show.blade.php` — FOUND, modified.
- `tests/Feature/Worksheet/PublicWorksheetOfflineSignoffTest.php` — FOUND, created.
- Commit `a03dc2e7` — FOUND in `git log`.
- Commit `24324b5e` — FOUND in `git log`.

## Self-Check: PASSED
