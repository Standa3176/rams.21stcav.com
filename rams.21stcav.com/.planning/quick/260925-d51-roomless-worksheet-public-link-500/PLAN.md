---
phase: quick
plan: 260925-d51
type: defect
defect: D-46-05-01
severity: live-on-production
subsystem: public-worksheet
autonomous: true
---

# Quick Task 260925-d51: Roomless worksheet public link returns 500 (D-46-05-01)

## Objective

`GET /worksheet/{token}` returns a **500** for any worksheet with no rooms. An
engineer opening their public link sees an error page. Live on the production
server at the time of writing.

## Root cause

`resources/views/worksheets/public-show.blade.php` computes the survey-review
sign-off gate inside the `@else` arm of `@if(empty($rooms))`, but reads it after
that `@endif`:

| Variable | Assigned | Read |
|---|---|---|
| `$signOffBlocked` | `:773` (inside `@else`) | `:798` (inside, fine), `:1359`, `:1441` (both AFTER `@endif` at `:1338`) |
| `$unreviewedRooms` | `:765` (inside `@else`) | `:802` (inside, fine), `:1362` (AFTER `@endif`) |

With `$rooms === []` the `@else` arm never runs, so neither variable is defined
when the Client Sign-Off card renders → `Undefined variable $signOffBlocked` →
500. `$unreviewedRooms` is masked only because `$signOffBlocked` is evaluated
first and throws; defaulting `$signOffBlocked` alone would surface a second 500.

These are the ONLY two variables with this shape. Every other variable assigned
in that branch (`$equipment`, `$roomKey`, `$firstIncompleteIdx`, `$carryForward`,
… 70+) is read only inside it. `$latestSignoff`, `$token` and `$worksheet` — the
other three variables used after the `@endif` — are all passed in by
`PublicWorksheetController::show`.

## Reachability

Reachable three ways. `resolveWorksheet()` gates on token + expiry only, never on
status or on whether content exists.

1. `WorksheetController::generateFromProject` inserts the row with
   `generated_data` NULL while `Worksheet::boot::creating` mints `access_token`
   in the same instant — the link is live and 500ing until `BuildWorksheetJob`
   finishes.
2. `BuildWorksheetJob` **throws** when `roomsCount === 0` or no room has
   equipment / steps / pre-install answers, leaving `generated_data` NULL
   permanently → permanent 500.
3. `WorksheetEditAdapter::applyRemoveRoom` has no last-room guard, so removing
   the final room writes `rooms: []` → permanent 500.

## Tasks

1. **[test]** `tests/Feature/Worksheets/RoomlessWorksheetPublicLinkTest.php` —
   RED first: assert `GET /worksheet/{token}` 200s for `generated_data` NULL and
   for `['rooms' => []]`, assert the sign-off affordance state, plus a canary
   that a room with an unreviewed survey is STILL blocked.
2. **[fix]** Declare `$signOffBlocked = false` and `$unreviewedRooms = []`
   unconditionally in the `@php` block above the branch, so the `@else` arm
   overwrites them with the real values when there are rooms.

## Decision: default to NOT blocked

Zero rooms means zero unreviewed rooms, so `! empty($unreviewedRooms)` over the
empty set is `false` — the defaults agree with the expression they stand in for.
Blocking instead would render a warning naming **no rooms**, on a page with no
room drawers to clear it, and the gate is cosmetic regardless (the sign POST is
accepted server-side either way). If an empty worksheet must not be signable,
that belongs in `PublicWorksheetController::sign`, not in a display flag.

## Out of scope (logged, not fixed)

- `applyRemoveRoom` has no last-room guard — it can legitimately empty `rooms[]`.
- The Client Sign-Off form renders for a roomless worksheet, so a client can sign
  off on an empty worksheet. `sign()` has no content precondition.

## Gates

- `tests/Feature/Worksheets` green
- Cockpit suite, 0 failed
- D-06 baseline `>= 159 passed AND 0 failed`
- Three sha256 pins unchanged
- Raw-echo count in `public-show.blade.php` still exactly 1 (T-46-03-02)
