---
quick_id: 261015-mcp
slug: survey-per-token-throttles
type: bugfix
status: complete
date: 2026-10-15
severity: live-outage
branch: feat/worksheet-classifier-universal
commits:
  - 417d7cfa test(261015-mcp) RED
  - 064df007 fix(261015-mcp) GREEN
files_modified:
  - app/Providers/AppServiceProvider.php
  - routes/web.php
  - tests/Feature/Surveys/SurveyEngineerThrottleTest.php
---

# 261015-mcp -- Engineers cannot use the survey link: per-IP throttles

## What was wrong

The nine public survey engineer routes carried bare `throttle:N,1`. Laravel keys an
unauthenticated throttle on the **client IP**, and these are public token routes with
no auth, so every engineer behind one address shared one bucket -- site guest wifi, or
mobile carrier NAT where a whole region shares an IP.

The identical fix already existed in this codebase for the **worksheet** link (quick
task `260919-fq8`, after the 2026-09-18 "signature didn't work" incident), leaving
seven named `worksheet-*` per-token limiters in `AppServiceProvider`. The survey link
was never converted. **This completes that half-done job; it did not invent an
approach.**

It surfaced on 14 Oct because `261011-lvf` repaired 81 dead `$watch` registrations, so
the per-room debounced autosave -- which had never actually fired -- now fires every
~600 ms of typing pause. One engineer emits 20-40 `step-save` calls a minute; two on
one IP cleared 60 in seconds. `261011-tax` then made the 429 present as a persistent
**"Needs a connection"** on full signal, which reads as *the link is broken*.

**The autosave was not touched.** Saving while typing is what stops engineers losing a
day of capture on site. The limits were wrong, not the saving.

## RED -- proven before the fix

```
FAIL  Tests\Feature\Surveys\SurveyEngineerThrottleTest
  OK  the engineer autosave route really is reachable and throttled   23.27s
  X   one engineers autosave burst does not rate limit another survey on the same ip
  X   a request with no token still lands in a real bucket
  X   no survey engineer route carries an ip keyed bare throttle
Tests:  3 failed, 1 passed (9 assertions)
```

The one that matters:

```
FAILED ... > one engineers autosave burst does not rate limit another survey on the same ip
ENGINEER B WAS 429d BY ENGINEER A. Survey A exhausting its own autosave budget
after 60 calls closed the bucket on a DIFFERENT survey from the same IP.
Failed asserting that 429 is identical to 200.
```

And the route scan:

```
survey.step.save      => throttle:60,1
survey.save           => throttle:30,1
survey.submit         => throttle:10,1
survey.photos.upload  => throttle:30,1
survey.room.complete  => throttle:60,1
survey.room.uncomplete    => throttle:60,1
survey.question.answer    => throttle:120,1
survey.photos.update      => throttle:60,1
public-survey.files.serve => throttle:60,1
```

## Non-vacuity

The test deliberately does **not** assert "a limiter named survey-step-save exists" --
that would pass on a limiter keyed the wrong way and prove nothing. It asserts
behaviour:

1. **Bucket isolation** (the defect). Engineer B saves fine; engineer A then autosaves
   until A is 429d; B must still get 200 **from the same client IP**. No budget number
   and no limiter name appears in the assertion, so it survives future re-tuning.
2. **The burst really drained the budget the route declares.** After the fix a
   follow-up assertion compares the observed attempt count against the budget the
   route resolved limiter declares (241 === 240 + 1) and checks the resolved Limit key
   equals the token. This closed the risk that the test passed for the wrong reason --
   a stale or accidentally-shared smaller bucket.
3. **Tokenless fallback** (D-02) asserted on the **resolved Limit**, not on source
   text: key must not be empty, must equal the client IP, maxAttempts not infinite.
4. **No bare `throttle:N,1`** -- a scan of the live route table, not a checklist, so a
   route added tomorrow with a bare throttle fails on the day it is added.

## Budgets set -- every one justified

All seven carry the load-bearing key
`->by((string) $request->route('token') ?: $request->ip())`, and test 2 asserts the
resolved key behaviourally rather than by reading the source.

**`survey-step-save` -- 240/min.** The autosave path, the one actively failing. A
600 ms-debounced field is structurally capable of ~100 calls/min from one engineer,
with the offline drain retrying held drafts on top. SCC `pmv-engineer-save` runs 240
for the same reason. No AI call, no file I/O, one JSON column write -- so a leaked
token has no costly worst case to bound.

**`survey-save` -- 60/min.** Deliberate full-form save, an order of magnitude rarer
than a keystroke, so the status-write band rather than the autosave band. The old 30
was an IP-shared number sized for a whole site.

**`survey-submit` -- 30/min.** worksheet-sign reasoning reused: per-token keying
removes the cross-engineer collision, but a stuck submit button can exhaust 10/min
alone and present identically. Still the tightest write limiter in the block.

**`survey-photo-write` -- 30/min.** Mirrors worksheet-photo-write exactly. Unchanged
numeric budget, re-keyed.

**`survey-status-write` -- 60/min.** Mirrors worksheet-status-write -- room complete,
uncomplete, photo PATCH. Unchanged numeric budget, re-keyed.

**`survey-question-write` -- 120/min.** Deviation, see below. Old budget carried over
unchanged, re-keyed only.

**`survey-file-read` -- 60/min.** Deviation, see below. Old budget carried over
unchanged, re-keyed only. Its worksheet counterpart serves files on the 60/min
worksheet-status-write.

## Deviation

The plan listed **seven** routes. The route table holds **nine** survey engineer
routes with a throttle: `survey.question.answer` (`throttle:120,1`) and
`public-survey.files.serve` (`throttle:60,1`) were not in the plan table but carry the
**same per-IP defect**, and test 3 is written as a general scan -- so leaving them
would have left the outage half-fixed on two routes and made the general test
impossible to satisfy honestly.

Both were converted with their **numeric budgets unchanged** -- a pure re-key, exactly
the treatment the September worksheet conversion gave its unchanged routes. **Nothing
was loosened**: every budget either matches a worksheet sibling, carries its own old
number, or has a stated reason.

No other deviation. No migration. Nothing deleted. No `worksheet-*` limiter touched.
No throttle removed from any route. The `$watch` paths, the `x-if` guard and the draft
restore were not touched.

## Also reported, not fixed -- the September conversion

**No worksheet engineer route still carries a bare `throttle:N,1`.** All 14 throttled
`worksheet/{token}` routes use named `worksheet-*` limiters. Two carry **no throttle
at all**, pre-existing and looking deliberate rather than a missed conversion --
flagging, not fixing:

- `GET worksheet/{token}` -- public-worksheet.show. Mirrors `GET survey/{token}`,
  which is also unthrottled.
- `GET worksheet/{token}/photos/{photo}` -- public-worksheet.photos.serve. Mirrors
  `GET survey/{token}/photos/{photo}`, also unthrottled. Note its sibling
  `worksheet/{token}/survey-photos/{photo}` **is** throttled at
  worksheet-survey-photo-read, so the pair is inconsistent.

## Gates -- all measured in the foreground, nothing inherited

| Gate | Expected | Measured | Verdict |
|---|---|---|---|
| `-Filter SurveyEngineerThrottleTest` | -- | 4 passed, 49 assertions, 6.41s | PASS |
| `-Path tests/Feature/Surveys` | 116 passed, 824 assertions | 120 passed, 873 assertions, 52.18s | PASS |
| `-Path tests/Feature/Worksheets` | 313 passed, 2616 assertions | 313 passed, 2616 assertions, 78.64s | PASS |
| `-Baseline` | >= 159 passed AND 0 failed | 159 passed, 0 failed, 2 skipped, 34.71s | PASS |
| `-Hashes` | all three MATCH 4abd2b24 | all three MATCH | PASS |

Surveys is +4 tests and +49 assertions against the 11 Oct figure -- exactly the new
file, nothing else moved. Worksheets is an exact match, which is the one that matters
most: the `worksheet-*` limiters live in the file that was edited.

Zero pre-existing failures; zero failures introduced.

### One process incident worth recording

The first `-Baseline` invocation was launched in the foreground but **exceeded the
600 s tool cap and was auto-backgrounded**, then stalled -- byte-identical output
after a further ~9 minutes. On being killed it **reported exit 0**, the documented
false-green that bit six times on 11 Oct. That run was discarded without quoting a
figure from it, the three stale php.exe processes were cleared, and the gate was
re-run clean in the foreground: **34.71 s**. The stall was process contention from
three back-to-back suites, not a defect -- the clean run is the figure in the table.

## Not done

Not pushed, not deployed. STATE.md untouched; no `gsd-sdk query state.*` was run.
