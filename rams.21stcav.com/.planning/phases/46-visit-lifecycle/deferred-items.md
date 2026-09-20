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
