---
phase: quick
plan: 260919-fq8
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Providers/AppServiceProvider.php
  - routes/web.php
  - tests/Feature/Worksheet/PublicWorksheetThrottleKeyTest.php
autonomous: false
requirements: [QUICK-fq8]
must_haves:
  truths:
    - "Two engineers holding tokens for two different worksheets never share a rate-limit bucket on any public worksheet route — one engineer's upload burst cannot 429 the other"
    - "A single engineer retrying a failing sign-off is still capped (10/min) but that cap is scoped to their own worksheet token, not shared with every other visitor on the same office/site NAT"
    - "Every named limiter referenced by routes/web.php's public worksheet block is registered in AppServiceProvider::boot() — no route resolves to an unregistered limiter (which would 500 the public link for every engineer)"
    - "The label-photo upload route (triggers paid AI extraction) is rate-limited more tightly than the general photo-write routes, and its key does not let IP-rotation reset the per-token budget"
    - "If a request somehow reaches a throttled worksheet route with no {token} route parameter, the limiter falls back to IP-based keying rather than an unlimited empty-string bucket"
  artifacts:
    - path: app/Providers/AppServiceProvider.php
      provides: "Five named RateLimiter definitions (worksheet-sign, worksheet-photo-write, worksheet-label-photo-upload, worksheet-status-write, worksheet-survey-photo-read), each keyed by (string) $request->route('token') ?: $request->ip()"
      contains: "RateLimiter::for('worksheet-sign'"
    - path: routes/web.php
      provides: "Public worksheet routes reference the five named limiters instead of throttle:N,1"
      contains: "throttle:worksheet-sign"
    - path: tests/Feature/Worksheet/PublicWorksheetThrottleKeyTest.php
      provides: "Feature test proving two distinct worksheet tokens do not share a throttle bucket, plus a limit-registration smoke test"
      exports: []
  key_links:
    - from: "routes/web.php public-worksheet.sign / photos.upload / photos.delete / label-photo.* / room-complete / survey-reviewed / files.serve / survey-photos.serve"
      to: "app/Providers/AppServiceProvider.php RateLimiter::for(...) definitions"
      via: "throttle:{limiter-name} middleware string matching a registered named limiter"
      pattern: "throttle:worksheet-(sign|photo-write|label-photo-upload|status-write|survey-photo-read)"
---

<objective>
Move the public worksheet routes (`routes/web.php` worksheet block) from Laravel's per-IP
`throttle:N,1` defaults to named, per-token `RateLimiter::for()` buckets registered in
`AppServiceProvider`, following the proven pattern already shipped in the SCC sibling app
(`pmv-engineer-*` limiters). Sign-off, photo, and label-photo routes on worksheet A no longer
share a rate-limit bucket with worksheet B just because two engineers are behind the same
office NAT, site guest wifi, or mobile CGNAT.

Purpose: today every public worksheet route throttles by IP. One engineer uploading photos on
worksheet A can 429 a different engineer working worksheet B from the same network, and the
10/min sign cap can lock an engineer out mid-retry — which presents identically to "the
signature doesn't work" (the exact symptom reported 2026-09-18, though that specific incident's
root cause was unrelated). Per-token keying (with an IP fallback for the token-less case, and
a deliberately tighter/non-composite key on the AI-cost route) fixes the collision without
opening a worse abuse path.

Output: five named limiters in `AppServiceProvider::boot()`, the worksheet route block in
`routes/web.php` rewired to reference them, and a feature test that hammers a route past its
limit with two different tokens and asserts they don't interfere.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@CLAUDE.md

<interfaces>
Reference pattern from the SCC sibling app (`service-contractor-creator`), `app/Providers/AppServiceProvider.php` ~:130-144 — adapt the shape, do not copy the limiter names or numbers:

RateLimiter::for('pmv-engineer-save', fn (Request $request) => Limit::perMinute(240)->by((string) $request->route('token') ?: $request->ip()));

The `?: $request->ip()` fallback is load-bearing — keep it on every limiter in this plan.

Current `routes/web.php` public worksheet block (exact throttle strings to replace, worksheet block only — do NOT touch the sibling `survey/*` block, which is out of scope):
- `public-worksheet.sign` — POST `worksheet/{token}/sign` — currently `throttle:10,1`
- `public-worksheet.photos.upload` — POST `worksheet/{token}/photos` — currently `throttle:30,1`
- `public-worksheet.photos.serve` — GET `worksheet/{token}/photos/{photo}` — currently UNTHROTTLED, leave unthrottled (out of scope)
- `public-worksheet.photos.delete` — DELETE `worksheet/{token}/photos/{photo}` — currently `throttle:30,1`
- `public-worksheet.survey-photos.serve` — GET `worksheet/{token}/survey-photos/{photo}` — currently `throttle:120,1`
- `public-worksheet.survey-reviewed` — POST `worksheet/{token}/rooms/{roomName}/survey-reviewed` — currently `throttle:60,1`
- `public-worksheet.room-complete` — POST `worksheet/{token}/rooms/{roomName}/complete` — currently `throttle:60,1`
- `public-worksheet.label-photo.upload` — POST `worksheet/{token}/label-photo` — currently `throttle:30,1` (runs AI extraction — see below)
- `public-worksheet.label-photo.confirm` — POST `worksheet/{token}/label-photos/{photo}/confirm` — currently `throttle:60,1`
- `public-worksheet.label-photo.delete` — DELETE `worksheet/{token}/label-photos/{photo}` — currently `throttle:30,1`
- `public-worksheet.files.serve` — GET `worksheet/{token}/files/{file}` — currently `throttle:60,1`
- `public-worksheet.show` — GET `worksheet/{token}` — currently UNTHROTTLED, leave unthrottled (out of scope)

`AppServiceProvider.php` currently registers Gate policies, singletons, and event listeners in `register()`/`boot()` but registers ZERO named rate limiters today — there is no existing `RateLimiter::for()` call to collide with or extend.
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Register named per-token limiters and rewire the worksheet route block</name>
  <files>app/Providers/AppServiceProvider.php, routes/web.php</files>
  <action>
In `AppServiceProvider::boot()`, add a new commented block (mirroring the style of the existing
dated section comments in this file, e.g. "── Phase 16: ..." / "── Worker heartbeat ..."),
titled something like "── Quick task 260919-fq8: per-token throttle keys for public worksheet
routes ──", registering five named limiters via `RateLimiter::for()`. Add `use
Illuminate\Cache\RateLimiting\Limit;`, `use Illuminate\Support\Facades\RateLimiter;`, and `use
Illuminate\Http\Request;` imports (check each is not already imported before adding — `Request`
in particular may already be imported elsewhere in the file; verify with a check before adding
a duplicate `use`).

Every limiter's key closure resolves `(string) $request->route('token') ?: $request->ip()` —
this fallback is mandatory per the SCC reference pattern (see `<interfaces>`); do not drop it
on any limiter, even ones you judge "always has a token in practice."

Register exactly these five, with a one-line comment above each explaining the number:

1. `worksheet-sign` — `Limit::perMinute(10)` — unchanged numeric budget from today's IP-based
   default, now scoped per-token so one engineer's retry storm can't lock out another
   engineer's worksheet.
2. `worksheet-photo-write` — `Limit::perMinute(30)` — covers ordinary photo mutation routes
   (upload/delete); unchanged numeric budget, now per-token.
3. `worksheet-label-photo-upload` — `Limit::perMinute(15)` — HALVED from the blanket 30/min
   other photo-write routes get, because this route triggers paid AI extraction
   (`DeviceLabelPhotoService`/`uploadLabelPhoto` in `PublicWorksheetController`) — a
   compromised or leaked token now has a bounded worst-case AI spend per minute regardless of
   how many IPs the caller uses. Explicitly do NOT key this one by token+IP composite: a
   composite key would let an attacker rotating IPs get a fresh AI-cost budget on every new IP
   for the same token, which is worse for cost control than a token-only key that caps total
   spend no matter how many IPs are used. State this reasoning in the code comment, not just
   the plan.
4. `worksheet-status-write` — `Limit::perMinute(60)` — covers room-complete, survey-reviewed,
   label-photo confirm, and reference-file serve; unchanged numeric budget, now per-token.
5. `worksheet-survey-photo-read` — `Limit::perMinute(120)` — covers survey-photo serve;
   unchanged numeric budget, now per-token.

Then in `routes/web.php`, inside the "Public Worksheet Sign-Off Routes" block only, replace
each `->middleware('throttle:N,1')` call with `->middleware('throttle:{limiter-name}')` per the
mapping in `<interfaces>`. Leave `public-worksheet.show` and `public-worksheet.photos.serve`
untouched (they carry no throttle middleware today and this plan does not add one). Do not
touch the sibling `survey/*` (site-survey) routes above the worksheet block — those are out of
scope for this quick task.
  </action>
  <verify>
    <automated>cd "C:\Users\sonny.tanda\Documents\1 - Claude Projects\Rams2\rams.21stcav.com" && "C:\Users\sonny.tanda\.config\herd\bin\php84\php.exe" artisan route:list --path=worksheet</automated>
  </verify>
  <done>
`route:list --path=worksheet` prints every worksheet route with no error (an unregistered
named limiter throws at resolution time, which `route:list` alone does NOT catch — Task 2's
real HTTP request test is the actual proof). `php -l app/Providers/AppServiceProvider.php` and
`php -l routes/web.php` both report no syntax errors. Grepping `routes/web.php` for
`throttle:worksheet-` returns exactly the routes listed above; grepping for `throttle:\d+,1`
within the worksheet block (lines between the "Public Worksheet Sign-Off Routes" heading and
the next section heading) returns zero matches.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Prove bucket isolation with a real request test and record the baseline</name>
  <files>tests/Feature/Worksheet/PublicWorksheetThrottleKeyTest.php</files>
  <behavior>
    - Test 1 (isolation, the load-bearing one): create two `Worksheet` records (two distinct
      tokens) via the same `makeWorksheet()`-style helper pattern used in
      `PublicWorksheetSignoffTest.php`. Hammer `POST worksheet/{tokenA}/sign` past its 10/min
      cap (11 requests) asserting the 11th is HTTP 429. Then immediately issue ONE request to
      `POST worksheet/{tokenB}/sign` with a valid signing payload and assert it is NOT 429
      (200/302/422 — anything but 429 proves the bucket didn't leak across tokens). Do not rely
      on differing IPs to prove isolation — issue all requests from the test client's default
      IP so the only variable is the token, which is the actual thing this plan changes.
    - Test 2 (registration smoke test): assert every named limiter this plan registers
      resolves without throwing when hit via a real HTTP request — one request each to a route
      using `worksheet-photo-write`, `worksheet-label-photo-upload`, `worksheet-status-write`,
      and `worksheet-survey-photo-read` (in addition to `worksheet-sign` already covered by
      Test 1), asserting none returns 500. This is the test that would have caught "registered
      but never wired" or "wired but never registered" before it reached a real engineer.
    - Test 3 (fallback key, if cheaply achievable): a request to a throttled worksheet route
      with an invalid/expired token still resolves the limiter without a server error (the
      controller's existing `resolveWorksheet()` 404 gate fires before or independently of the
      throttle key resolution — confirm which, and assert the actual observed status, not an
      assumed one).
  </behavior>
  <action>
Model the test file on `tests/Feature/Worksheet/PublicWorksheetSignoffTest.php`'s
`makeWorksheet()` helper and `RefreshDatabase` usage. Before writing new tests, run
`php artisan test --filter=Worksheet` and record the exact pass/fail count as the baseline (task
prompt states 244/0; confirm this machine agrees before touching anything — a mismatch means
investigate before proceeding, not silently proceed). After Task 1 and this task's new test
file are both in place, re-run `php artisan test --filter=Worksheet` and confirm the count is
baseline + (number of new tests in this file), 0 failed. Also re-run the full suite
(`php artisan test`) once and confirm no unrelated regression versus the last known-good count
in `.planning/STATE.md` (2670 passed / 1 pre-existing unrelated failure) — a new failure
anywhere is this plan's problem until proven otherwise.
  </action>
  <verify>
    <automated>cd "C:\Users\sonny.tanda\Documents\1 - Claude Projects\Rams2\rams.21stcav.com" && "C:\Users\sonny.tanda\.config\herd\bin\php84\php.exe" artisan test --filter=Worksheet</automated>
  </verify>
  <done>
`php artisan test --filter=Worksheet` passes at baseline-count-plus-new-tests, 0 failed.
`php artisan test --filter=PublicWorksheetThrottleKeyTest` specifically shows the isolation
test passing (tokenB's request after tokenA's bucket is exhausted is not 429). A full
`php artisan test` run shows no new failures versus the 2670-passed/1-pre-existing-failure
baseline recorded in `.planning/STATE.md`.
  </done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|--------------|
| Public internet → worksheet routes | Unauthenticated; the UUID token in the URL IS the credential. No session, no CSRF-exempt concerns beyond what already exists. |
| Worksheet token holder → rate limiter | The limiter key is derived from attacker-controlled input (the token in the URL path) — an attacker cannot forge a bucket collision with another engineer's token without also holding that token (at which point they already have full route access anyway). |
| label-photo upload → AI extraction service | Every accepted upload consumes a paid AI call downstream in `DeviceLabelPhotoService`; this is the one route in the block with a direct per-request cost to the business, not just a DB/storage cost. |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-fq8-01 | Denial of Service | `throttle:worksheet-*` bucket keying | mitigate | Per-token keying (this plan) removes the cross-engineer collision that is the actual reported problem; documented `?: $request->ip()` fallback prevents an unlimited empty-string bucket if a route param is ever missing. |
| T-fq8-02 | Denial of Service | `worksheet-label-photo-upload` | mitigate | Limit lowered to 15/min (from the blanket 30/min) and deliberately kept token-only (no IP component) so IP-rotation cannot multiply the AI-cost budget for a single leaked token; accepted residual: a leaked token still gets up to 15 paid AI calls/min indefinitely — no daily/lifetime cap exists at the route layer today (out of scope for this quick task; would require a persistent counter, not just `RateLimiter`). |
| T-fq8-03 | Spoofing / Elevation | Named limiter referenced but unregistered | mitigate | Task 1 registers all five limiters in the same commit as the route changes referencing them; Task 2's real-HTTP-request test (not just `route:list`) proves each named limiter resolves without a 500, closing the "500s every public worksheet link" failure mode called out in the plan constraints. |
| T-fq8-04 | Information Disclosure | Rate-limit responses (429) | accept | Laravel's default 429 response carries no worksheet data; unchanged by this plan. |
</threat_model>

<verification>
1. `php -l` on both modified PHP files — no syntax errors.
2. `php artisan route:list --path=worksheet` resolves cleanly (no exception during route registration).
3. `php artisan test --filter=Worksheet` — baseline 244 passed / 0 failed before, baseline+N passed / 0 failed after, where N is the count of new tests in `PublicWorksheetThrottleKeyTest.php`.
4. Full `php artisan test` shows no new failures versus the `.planning/STATE.md`-recorded baseline (2670 passed / 1 pre-existing unrelated failure).
5. Manual grep: no `throttle:\d+,1` remaining inside the worksheet route block; five `RateLimiter::for('worksheet-...')` calls present in `AppServiceProvider.php`.
</verification>

<success_criteria>
- All five named limiters registered in `AppServiceProvider::boot()`, each keyed by token with an IP fallback.
- The public worksheet route block in `routes/web.php` references only the new named limiters (plus the two intentionally-untouched unthrottled routes).
- A real-HTTP-request feature test proves two different worksheet tokens do not share a throttle bucket.
- No route resolves to an unregistered limiter; no 500s introduced on any worksheet route.
- Full test suite shows zero new regressions versus the last recorded baseline.
</success_criteria>

<output>
Create `.planning/quick/260919-fq8-per-token-throttle-keys-for-public-works/260919-fq8-SUMMARY.md` when done
</output>
