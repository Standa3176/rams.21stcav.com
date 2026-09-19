---
phase: quick
plan: 260919-fq8
subsystem: infra
tags: [rate-limiting, throttle, laravel-rate-limiter, public-routes, worksheet]
requires: []
provides:
  - "Five named RateLimiter definitions in AppServiceProvider::boot() (worksheet-sign, worksheet-photo-write, worksheet-label-photo-upload, worksheet-status-write, worksheet-survey-photo-read), each keyed by (string) $request->route('token') ?: $request->ip()"
  - "Public worksheet route block in routes/web.php rewired from flat throttle:N,1 to the five named limiters"
  - "Bucket-isolation feature test proving two worksheet tokens never share a throttle bucket"
affects:
  - routes/web.php public worksheet block
  - app/Providers/AppServiceProvider.php
  - any future public-token route that needs the same per-token throttle pattern
tech-stack:
  added: []
  patterns:
    - "Named RateLimiter::for('name', fn (Request $r) => Limit::perMinute(N)->by((string) $r->route('token') ?: $r->ip())) — proven pattern ported from the SCC sibling app's pmv-engineer-* limiters"
key-files:
  created:
    - tests/Feature/Worksheet/PublicWorksheetThrottleKeyTest.php
  modified:
    - app/Providers/AppServiceProvider.php
    - routes/web.php
    - tests/Feature/Worksheet/PublicWorksheetSignoffTest.php
decisions:
  - "Raised worksheet-sign from 10/min to 30/min (was going to keep it unchanged, purely re-scoped to per-token). Per-token keying already removes the cross-engineer collision that caused the reported 2026-09-18 'signature didn't work' confusion, but an engineer alone could still exhaust a 10/min budget retrying a stuck submit button — a self-inflicted lockout that presents identically to the original symptom. Matched the SCC sibling app's equivalent pmv-engineer-submit limiter, which runs at 30/min for the same reason. Reasoning recorded as a code comment in AppServiceProvider.php, not just this summary."
  - "worksheet-label-photo-upload deliberately kept token-only (no IP composite) despite being the tightest-scrutinized route (AI-cost route) — a composite key would let IP-rotation multiply the AI-cost budget for one leaked token, which is worse for cost control than a token-only cap."
  - "Updated the pre-existing throttle:10,1 assertion in PublicWorksheetSignoffTest.php in the same commit as the route change (Rule 3 — leaving it would have broken the suite the moment the middleware string changed)."
requirements-completed: [QUICK-fq8]
metrics:
  duration: "~35m"
  completed: "2026-09-19"
---

# Quick Task 260919-fq8: Per-token throttle keys for public worksheet routes Summary

Replaced IP-keyed `throttle:N,1` on every public worksheet route with five named, per-token
`RateLimiter::for()` buckets (`worksheet-sign` at 30/min, `worksheet-photo-write` at 30/min,
`worksheet-label-photo-upload` at 15/min token-only, `worksheet-status-write` at 60/min,
`worksheet-survey-photo-read` at 120/min), so two engineers on the same office NAT no longer
share a rate-limit bucket — proven by a feature test that exhausts one token's bucket and
confirms a second token is unaffected from the same test-client IP.

## Performance

- **Duration:** ~35 min
- **Started:** 2026-09-19
- **Completed:** 2026-09-19
- **Tasks:** 2
- **Files modified:** 4 (1 created, 3 modified)

## Accomplishments

- Five named limiters registered in `AppServiceProvider::boot()`, each with the mandatory
  `?: $request->ip()` fallback, adapted from the proven SCC sibling app pattern.
- Public worksheet route block in `routes/web.php` fully rewired to reference the named
  limiters — no route in that block still uses a flat `throttle:N,1`.
- `worksheet-sign`'s cap explicitly reconsidered (not left unexamined) and raised from 10/min
  to 30/min, with the reasoning captured as an in-code comment.
- New `PublicWorksheetThrottleKeyTest` proves bucket isolation with a real HTTP request
  (not `route:list`), proves all five limiters resolve without a 500, and documents the
  token-vs-IP-fallback behaviour on an unknown token.
- Registration + route rewiring landed in a single commit (`5c4d6898`) — no intermediate state
  where a route referenced an unregistered limiter.

## Task Commits

Each task was committed atomically:

1. **Task 1: Register named per-token limiters and rewire the worksheet route block** -
   `5c4d6898` (feat) — includes the required update to
   `PublicWorksheetSignoffTest.php`'s throttle-string assertion in the same commit, since
   leaving it stale would have broken the suite the instant the middleware string changed.
2. **Task 2: Prove bucket isolation with a real request test and record the baseline** -
   `b3140d50` (test)

_No `docs: complete plan` metadata commit yet — will follow this SUMMARY + STATE.md update._

## Files Created/Modified

- `app/Providers/AppServiceProvider.php` - Added `Illuminate\Cache\RateLimiting\Limit`,
  `Illuminate\Http\Request`, `Illuminate\Support\Facades\RateLimiter` imports and five
  `RateLimiter::for()` registrations in `boot()`.
- `routes/web.php` - Public worksheet route block: all `throttle:N,1` strings replaced with
  the matching named limiter. `public-worksheet.show` and `public-worksheet.photos.serve`
  left untouched (still unthrottled, out of scope). The sibling `survey/*` block above the
  worksheet block was not touched.
- `tests/Feature/Worksheet/PublicWorksheetSignoffTest.php` - Renamed/rewrote
  `test_sign_route_is_throttled_to_10_per_minute` to
  `test_sign_route_is_throttled_via_named_worksheet_sign_limiter`, asserting
  `throttle:worksheet-sign` instead of the now-removed `throttle:10,1`.
- `tests/Feature/Worksheet/PublicWorksheetThrottleKeyTest.php` (new) - 3 tests: bucket
  isolation (hammers `worksheet-sign` 31x with tokenA, asserts 429, then confirms tokenB is
  not 429 from the same IP), registration smoke test (one real request per remaining
  limiter, asserts no 500), and unknown-token fallback documentation (asserts 404, not 500).

## Decisions Made

- **`worksheet-sign` raised to 30/min, not kept at 10/min.** The plan's own objective flagged
  this as a number to actively reconsider rather than carry over unexamined. Per-token keying
  already solves the cross-engineer collision (the actual root cause investigated on
  2026-09-18); it does nothing for a single engineer who is themselves the one retrying a
  stuck submit into the same bucket. Raising to 30/min (matching the SCC sibling app's
  equivalent `pmv-engineer-submit` limiter) removes that self-inflicted-lockout risk on the
  one route where a lockout looks exactly like "the signature doesn't work" — while still
  being the tightest write-limiter in this block (half of `worksheet-photo-write`'s 30/min
  budget headroom relative to `worksheet-status-write`'s 60/min, since a legitimate sign-off
  is a rare deliberate action, not a polling loop). Reasoning is captured as an in-code
  comment in `AppServiceProvider.php`, per the plan's requirement that the number not be
  changed (or kept) silently.
- **`worksheet-label-photo-upload` kept token-only, no IP composite**, per plan — a composite
  key would let IP-rotation multiply the AI-cost budget for a single leaked token, which is a
  worse cost-control failure mode than a token-only cap that bounds total spend regardless of
  how many IPs are used.
- **Isolation test hammers 31 requests, not the plan's example figure of 11**, because the
  cap this task actually shipped is 30/min (see decision above), not the original 10/min the
  plan text illustrated its example with. The test's `SIGN_LIMIT_PER_MINUTE` constant is
  pinned to 30 so it fails loudly if the limiter's cap ever drifts, rather than silently
  degrading into a false pass at the wrong number.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Updated pre-existing `throttle:10,1` assertion to prevent suite breakage**
- **Found during:** Task 1, before running verification
- **Issue:** `PublicWorksheetSignoffTest::test_sign_route_is_throttled_to_10_per_minute`
  asserted `gatherMiddleware()` contains the literal string `throttle:10,1`. Once the route
  was rewired to `throttle:worksheet-sign` in the same commit, that assertion would fail —
  breaking the very commit the non-negotiables required to be atomic and green.
- **Fix:** Renamed the test to `test_sign_route_is_throttled_via_named_worksheet_sign_limiter`
  and updated the assertion to `throttle:worksheet-sign`, with a comment pointing to
  `AppServiceProvider::boot()` and `PublicWorksheetThrottleKeyTest` for the fuller coverage.
- **Files modified:** `tests/Feature/Worksheet/PublicWorksheetSignoffTest.php`
- **Verification:** `--filter=Worksheet` stayed at 244 passed / 0 failed after Task 1 (before
  the 3 new tests were added in Task 2).
- **Committed in:** `5c4d6898` (Task 1 commit — same commit as the route/limiter change,
  satisfying the atomicity non-negotiable).

---

**Total deviations:** 1 auto-fixed (1 blocking).
**Impact on plan:** Necessary to keep the suite green in the same commit as the route change;
the plan's `files_modified` list did not name this test file, but leaving its assertion stale
would have violated the plan's own atomicity requirement. No scope creep — no other file
outside the plan's `files_modified` (plus this one test-assertion update) was touched.

## Issues Encountered

None beyond the deviation above.

## Test Results

- **Baseline (confirmed fresh before any change):** `php artisan test --filter=Worksheet` →
  **244 passed, 0 failed** — matches the task's stated baseline exactly.
- **After Task 1** (limiters registered + routes rewired + existing assertion fixed):
  **244 passed, 0 failed** (no test count change yet — new tests land in Task 2).
- **After Task 2** (new `PublicWorksheetThrottleKeyTest` added, 3 tests):
  **247 passed, 0 failed** (244 + 3 new, exactly as required).
- **`--filter=PublicWorksheetThrottleKeyTest` in isolation:** 3 passed (8 assertions),
  including the load-bearing isolation test.
- **Full `php artisan test`:** **2691 passed**, 1 failed (`QueueRecoverCommandTest` — a
  pre-existing, documented, unrelated failure: the test file's own comments record it as a
  known memory-limit interaction with `QueueRecoverCommand`'s internal `queue:work` call in a
  long-lived PHPUnit process, explicitly called out as out of scope for prior quick tasks).
  No new regressions versus the `.planning/STATE.md`-recorded baseline of 2670 passed / 1
  pre-existing failure — the extra passed count over 2670 is fully accounted for by this
  task's 3 new tests plus other quick tasks merged since that baseline was last recorded.
- `php -l` clean on both modified PHP files.
- `php artisan route:list --path=worksheet` resolves all 21 worksheet-area routes with no
  exception — no route in the block references an unregistered limiter.

## Known Stubs

None.

## Threat Flags

None. This task only changes the throttle *keying* mechanism on routes that already existed;
it introduces no new endpoint, auth path, or schema surface. The plan's own threat model
(T-fq8-01 through T-fq8-04) already accounts for the residual risk on
`worksheet-label-photo-upload` (a leaked token still gets up to 15 paid AI calls/min
indefinitely, accepted per the plan as out of scope for this quick task).

## User Setup Required

None — no external service configuration required. No migration; this change is routes +
provider + tests only, so no `php artisan optimize:clear` cache-clear step is strictly
required for the throttle keying itself to take effect, but the deploying operator should
still run the app's normal `config:clear`/`route:clear` step used after any `routes/web.php`
change, per house convention.

## Next Phase Readiness

- The per-token limiter pattern is now established in this app's `AppServiceProvider.php` and
  can be reused for any future public-token route (mirrors the SCC sibling app precedent).
- No blockers. Nothing further required to close this quick task.

---

## Self-Check: PASSED

- FOUND: `app/Providers/AppServiceProvider.php` (modified, verified via git diff in this session)
- FOUND: `routes/web.php` (modified, verified via git diff in this session)
- FOUND: `tests/Feature/Worksheet/PublicWorksheetSignoffTest.php` (modified, verified via git diff in this session)
- FOUND: `tests/Feature/Worksheet/PublicWorksheetThrottleKeyTest.php` (created, verified via git status/log in this session)
- FOUND commit `5c4d6898` (Task 1, `feat(quick-260919-fq8): per-token throttle keys for public worksheet routes`)
- FOUND commit `b3140d50` (Task 2, `test(quick-260919-fq8): prove per-token throttle bucket isolation`)

---
*Phase: quick*
*Completed: 2026-09-19*
