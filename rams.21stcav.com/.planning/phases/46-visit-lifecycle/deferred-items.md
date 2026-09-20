# Phase 46 — deferred items

Out-of-scope discoveries found during execution. Logged, deliberately NOT fixed:
each is pre-existing and unrelated to the task that surfaced it.

---

## D-46-05-01 — `/worksheet/{token}` 500s for a worksheet with no rooms

**Found during:** Plan 46-05, Task 3 (writing the "both links still return 200
for every pre-existing case" test).

**Symptom:** `GET /worksheet/{token}` returns **500** for a worksheet whose
`generated_data` has no `rooms` (e.g. `[]`):

```
ErrorException: Undefined variable $signOffBlocked
  (View: resources/views/worksheets/public-show.blade.php)
```

**Cause:** `$signOffBlocked` is assigned at `public-show.blade.php:773`, which
sits INSIDE the populated-rooms branch (`@if(empty($rooms)) ... @else` opens at
`:671`). It is then read at `:1359` and `:1441`, which are reached regardless of
that branch. With no rooms the assignment never runs and the reads explode.

**Why it is NOT fixed here:** verified pre-existing, not a 46-05 regression. The
file was reverted to `HEAD` (pre-banner) and the same case re-run — it produced
the identical `Undefined variable $signOffBlocked` 500. The Plan 46-05 banner
include sits inside the populated branch (`:684`) and is never evaluated in the
failing case. Fixing it would mean editing view logic this plan has no business
touching, inside a page a client signs.

**Scope note:** Plan 46-05's stated pre-existing cases were "no visit, no
survey, legacy worksheet" — all three are asserted green in
`SendBackReopensEngineerLinkTest::test_both_links_still_return_200_for_the_pre_existing_cases()`.
The no-rooms case was beyond that list; the test carries a comment recording
this finding at the point where the assertion would have gone.

**Suggested fix (for whoever picks it up):** hoist the `$signOffBlocked`
assignment (and any sibling in the same `@php` block that is read outside the
branch) above the `@if(empty($rooms))` split, defaulting to `false`. Needs its
own regression test for the empty-rooms render.

---

## D-46-06-01 — a send-back issued in the SAME SECOND as a resubmission reads as "not sent back"

**Found during:** Plan 46-06, Task 2 (the "send back, resubmit, send back again"
test).

**Symptom:** `Visit::wasSentBack()` is
`sent_back_at->greaterThan($returnedAt)` — a STRICT comparison against
timestamps stored to one-second resolution. If a PM sends a visit back inside
the same clock second as the engineer's submission, `sent_back_at ==
returnedAt`, the comparison is false, and the visit reads RETURNED forever: the
reason is stored but neither the cockpit's "Sent back …" line nor 46-05's
engineer banner ever appears.

**Why it is NOT fixed here:** the fix is one character in
`Visit::wasSentBack()` (`greaterThan` → `greaterThanOrEqualTo`), but that
comparison is Plan 46-01's recorded decision and is asserted by
`VisitLifecycleTest`. Relaxing it changes what an EQUAL timestamp means
everywhere — including for `VisitReworkState` on two public links — and that is
a 46-01 decision to revisit, not a 46-06 edit. The window is one second wide
and requires a PM to act before they could have read the return.

**Suggested fix (for whoever picks it up):** either store `sent_back_at` with
sub-second precision, or make the comparison inclusive and re-baseline the
46-01 assertions deliberately, with the tie-break rule written down.
