---
quick_id: 261015-mcp
slug: survey-per-token-throttles
type: bugfix
status: ready
date: 2026-10-15
severity: live-outage
files_modified:
  - app/Providers/AppServiceProvider.php
  - routes/web.php
  - tests/Feature/Surveys/SurveyEngineerThrottleTest.php
---

# 261015-mcp — Engineers cannot use the survey link: per-IP throttles

## Reported

Engineers report they **could not use the survey engineer link at all**, 2026-10-14.

## Root cause

**The survey engineer routes are throttled per IP; the worksheet ones are not.** Laravel keys an
unauthenticated throttle by **client IP**, and these are public token routes with no auth — so every
engineer behind one address shares a single bucket. Site WiFi, or mobile carrier NAT where a whole
region shares an IP.

| Survey route | Now | Keyed by |
|---|---|---|
| `step-save` | `throttle:60,1` | **IP — shared** |
| `save` | `throttle:30,1` | **IP — shared** |
| `submit` | `throttle:10,1` | **IP — shared** |
| `rooms/{room}/photos` | `throttle:30,1` | **IP — shared** |
| `rooms/{room}/complete` | `throttle:60,1` | **IP — shared** |
| `rooms/{room}/uncomplete` | `throttle:60,1` | **IP — shared** |
| `photos/{photo}` (PATCH) | `throttle:60,1` | **IP — shared** |

⚠️ **This exact fix was already made in this codebase — for the worksheet only.** There are **seven**
named per-token limiters in `AppServiceProvider`, all `worksheet-*`, and **none** for the survey.
The existing comment states the rationale and the precedent:

> *"(proven pattern: service-contractor-creator's `pmv-engineer-*` limiters) gives every worksheet
> its own independent bucket. The `?: $request->ip()` fallback is load-bearing — without it a
> request that somehow reaches these routes with no token resolves to an empty string key, i.e. one
> shared unlimited bucket for every such request."*

That conversion followed a 2026-09-18 incident reported as *"signature didn't work"* — **the same
defect, on the other link.** The survey link was simply never converted.

## Why it surfaced now

⚠️ **Quick task `261011-lvf` (11 Oct) is the most likely trigger, and it was mine.** It repaired 81
dead `$watch` registrations, so the per-room debounced autosave — which had **never fired** — now
fires roughly every 600 ms of typing pause, per step. One engineer can generate 20-40 `step-save`
calls a minute alone; two on one IP exceed 60 and everything 429s.

Compounding it, `261011-tax` (also mine) added the draft queue, so a 429 now presents as a
persistent **"Needs a connection"** even on full signal — which reads to an engineer as *the link
does not work*.

⚠️ **The autosave is NOT the thing to roll back.** Saving while typing is what stops engineers
losing work on site, and throttling it back would re-create the data loss `261011-lvf` and
`261011-tax` were built to fix. **The limits are wrong, not the saving.**

## Decisions

- **D-01:** Add `survey-*` named limiters mirroring the existing `worksheet-*` block, in the same
  file, same style, same `->by((string) $request->route('token') ?: $request->ip())` key. **Follow
  the house pattern already in that file** — do not transplant SCC's verbatim.
- **D-02:** ⚠️ **The `?: $request->ip()` fallback is load-bearing** and the existing comment says
  why: without it a tokenless request keys on an empty string, i.e. **one shared unlimited bucket**.
  Keep it on every limiter.
- **D-03:** Budgets — justify each in a comment, as the existing block does:
  - `survey-step-save` → **240/min**. The autosave path. SCC's equivalent (`pmv-engineer-save`) runs
    240 for the same reason. This is the one that is actively failing.
  - `survey-save` → 60/min (full-form save, far rarer than autosave).
  - `survey-submit` → 30/min, matching `worksheet-sign`'s reasoning verbatim: per-token keying
    removes the cross-engineer collision, but a stuck submit button can still exhaust a 10/min
    budget alone and present identically.
  - `survey-photo-write` → 30/min, mirroring `worksheet-photo-write`.
  - `survey-status-write` → 60/min, mirroring `worksheet-status-write` (room complete / uncomplete /
    photo PATCH).
- **D-04:** **Do not loosen anything that is not evidenced.** Every budget above either matches an
  existing worksheet sibling or has a stated reason. No blanket raise.
- **D-05:** **Do not reduce autosave frequency** (see above).

## Tasks

### Task 1 — Prove it RED

`tests/Feature/Surveys/SurveyEngineerThrottleTest.php`. Assert the **behaviour**, not the string:

1. **Two different tokens do not share a bucket.** Exhaust survey A's `step-save` budget, then show
   survey B still succeeds **from the same IP**. ⚠️ This is the actual defect — it must FAIL before
   the fix. A test that only asserts "a limiter named survey-step-save exists" would pass on a
   limiter keyed the wrong way and prove nothing.
2. **A tokenless / unmatched request still has a bucket** (D-02's fallback), not an unlimited one.
3. Every survey engineer route carries a **named** limiter, not a bare `throttle:N,1` — written
   generally, so a future route added with a bare throttle fails too.

Prove RED and **quote the real failure**.

### Task 2 — Implement

Limiters in `AppServiceProvider`, route middleware swapped in `routes/web.php`.

## Verification

Foreground, redirected to a file, **never piped**, **one suite per invocation**, **never
backgrounded** — a backgrounded gate silently hangs and the killed run **reports exit 0**. That bit
six times on 11 Oct. If it stalls: kill it, clear stale `php.exe`, re-run clean.

The gate's only parameters are `-Filter`, `-Path`, `-Baseline`, `-Hashes` — **there is no `-File`
parameter for naming a test.**

```
powershell -ExecutionPolicy Bypass -File .planning/phases/46-visit-lifecycle/gate-46.ps1 -Filter SurveyEngineerThrottleTest > "$env:TEMP/g.txt" 2>&1; Get-Content "$env:TEMP/g.txt" -Tail 12
```

Then each in its own invocation: `-Path tests/Feature/Surveys`, `-Path tests/Feature/Worksheets`
(the worksheet limiters share the file — prove they still work), `-Baseline`, `-Hashes`.

**Measured by the orchestrator in the foreground on 11 Oct — re-measure and compare, never inherit:**

| Gate | Expected |
|---|---|
| `tests/Feature/Surveys` | **116 passed, 824 assertions** |
| `tests/Feature/Worksheets` | **313 passed, 2616 assertions** |
| `-Baseline` | **159 passed, 0 failed, 2 skipped** — `>= 159 passed AND 0 failed`, never equality against 161 |
| `-Hashes` | all three **MATCH** `4abd2b24` |

**ZERO pre-existing failures. A failure you see is yours.**

## Constraints

- ⚠️ **Do not touch the seven existing `worksheet-*` limiters.** They are live and carry
  incident-derived reasoning. Adding beside them is the whole job.
- ⚠️ **Do not remove throttling from any route.** These are public, unauthenticated endpoints. A
  per-token bucket still bounds abuse of one token; the IP fallback covers tokenless requests.
- Do not touch the `$watch` paths (`261011-lvf`), the `x-if` guard (`261011-n91`) or the draft
  restore (`261011-tax`) — all live, all on the same view.
- Never render `device_label_photos.captured_by`, `worksheet_signoffs.ip_address`,
  `worksheet_signoffs.user_agent`, `pre_install_confirmations.room_complete.{room}.completed_by`.
  **LR-04:** a client is never given an engineer's phone or email.
- Preserve `SiteSurvey::$fillable`'s omissions; never mint, rotate, mass-assign or render a token.
- Three files stay **byte-identical to `4abd2b24`**. **A mismatch is a STOP, never a hash to refresh.**
- **Nothing is deleted. NO MIGRATION.** If you conclude one is needed, **STOP and report.**
- **NEVER** `php artisan migrate:fresh --env=testing`; **never** `gsd-sdk query state.*` (**do not
  touch STATE.md**); **never** `git stash`; `git clean` is prohibited.
- **Do NOT push and do NOT deploy.**

## Report

Also report, do not fix: whether any **worksheet** engineer route still carries a bare
`throttle:N,1` that the September conversion missed.

## Commits

Atomic per task. End commit messages with:

```
Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
```
