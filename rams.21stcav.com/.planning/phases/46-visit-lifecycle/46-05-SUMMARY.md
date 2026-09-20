---
phase: 46-visit-lifecycle
plan: 05
subsystem: visit-lifecycle
tags: [send-back, engineer-link, derived-over-stored, d-02, client-facing, xss, lr-04]
requires:
  - "Visit.sent_back_at / send_back_reason (46-01)"
  - "Visit::wasSentBack() / returnedAt() (46-01)"
  - "SiteSurvey.submitted_at"
  - "Worksheet::latestSignoff() / WorksheetSignoff (append-only)"
  - "the Site Logistics carry-forward drawer (46-03)"
provides:
  - "App\\Support\\Visits\\VisitReworkState — reopened/reason/at/rooms, derived"
  - "SiteSurvey::isLockedForEngineer()"
  - "resources/views/partials/_office-sendback-banner.blade.php"
  - "ten engineer gate sites moved off isSubmitted()"
affects:
  - "Plan 46-06 (the four PM actions — writes sent_back_at/send_back_reason this plan reads)"
tech-stack:
  added: []
  patterns:
    - "Derived over stored: a reopening is a comparison, never a flag (same doctrine as Visit::wasSentBack/returnedAt)"
    - "One partial, two audiences: the caller decides how much detail its reader may have"
    - "A client-facing partial is enumerated in the privacy guard in its creating commit"
key-files:
  created:
    - app/Support/Visits/VisitReworkState.php
    - resources/views/partials/_office-sendback-banner.blade.php
    - tests/Feature/Visits/SendBackReopensEngineerLinkTest.php
    - .planning/phases/46-visit-lifecycle/deferred-items.md
  modified:
    - app/Models/SiteSurvey.php
    - app/Http/Controllers/PublicSurveyController.php
    - app/Http/Controllers/SurveyController.php
    - app/Http/Controllers/PublicWorksheetController.php
    - resources/views/surveys/show.blade.php
    - resources/views/worksheets/public-show.blade.php
    - tests/Feature/Security/LabourResourceClientSurfacePrivacyTest.php
decisions:
  - "submitted_at is NEVER cleared — the reopening is sent_back_at > last submission, so resubmitting relocks with no flag to clear"
  - "TEN gate sites, not eleven: the plan's prose contradicted its own enumeration; code had not moved (corrected in b703e38a)"
  - "The 8 gates that 403 get the iterated negative test; the 2 readonly render flags get render assertions, not forced into the 403 loop"
  - "VisitReworkState delegates the comparison to Visit::wasSentBack() rather than re-deriving it"
  - "The client-signed worksheet link gets reason = null by design (T-46-05-02)"
  - "rooms_in_scope added to the state array so the worksheet link needs no second query"
  - "The empty-rooms 500 on the worksheet link is PRE-EXISTING and deliberately not fixed — deferred-items.md D-46-05-01"
metrics:
  duration: ~75 min
  tasks: 3
  commits: 4
  files_changed: 11
  completed: 2026-09-20
---

# Phase 46 Plan 05: Send Back Reopens the Engineer Link — Summary

**One-liner:** "Send back" now reopens the engineer's survey link for more information and says so
on both public links — and it does it by **comparing `sent_back_at` against the last submission**,
so not one byte of what the engineer captured is rewritten to make the form editable again.

## The count: TEN, not eleven — and why that is not code drift

The plan's prose said eleven `isSubmitted()` gate sites. A grep measured **ten**. Execution
**stopped before any edit** and reported, per the gate.

The distinguishing evidence: all ten sat at the *exact* line numbers the plan's `<interfaces>`
block enumerated (164, 227, 259, 305, 385, 414, 468, 509, 95, 113), with identical content. The
code had not moved — the plan's own bullet list totalled 8 + 2 = 10 while its prose said eleven.
`git log --since=2026-09-19` on both controllers returned nothing. Corrected in `b703e38a`, then
executed against ten.

The real anatomy of the ten, which matters because the negative test is shaped by it:

| Count | Kind | Sites |
|---|---|---|
| **7** | `abort_if(...)` → HTTP 403 | `save`, `submit`, `completeRoom`, `uncompleteRoom`, `answerQuestion`, `uploadPhoto`, `updatePhoto` |
| **1** | `if (...) return json 403` | `SurveyController::stepSave` |
| **2** | `'readonly' => ...` render flag | `PublicSurveyController:164`, `SurveyController:95` |

So **8 gates return 403** (the threat register's "eight `abort_if` gates" is right in substance),
and those 8 are what the negative test iterates. The 2 render flags got render assertions instead
— not forced into the 403 loop to make one number cover everything.

All eight abort message strings are **byte-identical** (`diff` of the extracted strings against the
pre-edit file: identical). The whole controller diff is 10 insertions / 10 deletions, every pair
differing only in the method name.

## What was built

### Task 1 — the reopening, derived and never stored (`7c89d44a`)

`app/Support/Visits/VisitReworkState.php` — a `final class` of static readers, no writers.
`forSource()` resolves the visit wrapping a record and returns
`['reopened' => bool, 'reason' => ?string, 'at' => ?Carbon, 'rooms' => array]`, or `null` when no
visit wraps it (the common case, which must behave exactly as before this plan).

`reopened` delegates to `Visit::wasSentBack()` rather than re-deriving the comparison — two copies
would disagree the first time one changed. The reason and date are returned **only while the
reopening is live**, so the banner goes away whole on resubmission rather than lingering as
"previously sent back" history.

`SiteSurvey::isLockedForEngineer()` = `isSubmitted() && ! reopened`. **`isSubmitted()` is
unchanged** and still means "was submitted" — the PDF generator, the submitted-notification path
and `ProjectHealthService` all mean that by it and none of them mean "closed to editing".

### Task 2 — the banner, two audiences (`2d37af92`)

`resources/views/partials/_office-sendback-banner.blade.php`, included by both public views.

- `/survey/{token}` — the **engineer's** link — passes the reason. They are the person being asked.
- `/worksheet/{token}` — the page a **client signs** — passes `reason = null`. Deliberate
  (T-46-05-02), recorded at both the include site and in the partial so a later agent does not
  "fix" it.
- `rooms_in_scope` renders on the worksheet link as information, never a gate.
- Inline styles only; no `cav-` cockpit tokens imported into these two non-cockpit pages.

### Task 3 — proving the other gates still hold (`2d37af92`, tests)

`test_a_survey_with_no_send_back_is_still_locked_after_submission()` iterates all **8** 403 write
routes against **both** no-send-back scenarios — a submitted survey with no visit, and one whose
visit was *not* sent back — asserting 403 each time with a label naming the gate that let go.

Its mirror, `test_every_edited_write_gate_reopens_together()`, asserts none of the 8 still 403s
*with* an active send-back. Without it, a gate accidentally hard-wired shut would pass the negative
test and nobody would notice the link never reopens.

## The D-02 proof

`submitted_at` is never cleared. The executable form:

```php
public function test_a_send_back_does_not_touch_the_engineers_record(): void
{
    $submittedAtBefore = $survey->fresh()->submitted_at;
    $countsBefore = [ /* site_surveys, worksheets, worksheet_signoffs, visits */ ];

    $this->sendBack($survey, $project);
    $this->get("/survey/{$token}")->assertStatus(200);   // the reopened render

    $this->assertSame($countsBefore['site_surveys'],       $countsAfter['site_surveys']);
    $this->assertSame($countsBefore['worksheets'],         $countsAfter['worksheets']);
    $this->assertSame($countsBefore['worksheet_signoffs'], $countsAfter['worksheet_signoffs']);
    $this->assertSame($countsBefore['visits'] + 1,         $countsAfter['visits']);

    $this->assertEquals(
        $submittedAtBefore->toDateTimeString(),
        $survey->fresh()->submitted_at?->toDateTimeString(),
        'submitted_at was altered by an office action — that is the D-02 violation this plan exists to avoid.'
    );
}
```

The `+1` on `visits` is the send-back itself; the **render** adds none. And
`test_resubmitting_relocks_it_with_no_flag_to_clear()` asserts `sent_back_at` survives the
relock — the record of a PM's act is not erased either.

## Security

- **Escaping (T-46-05-03):** the reason renders through an escaped echo.
  `test_a_hostile_reason_is_escaped_on_the_engineer_link()` sends
  `<script>alert(1)</script><img src=x onerror=alert(2)>`, asserts 200, asserts both raw forms
  **absent** from the body and the escaped form present.
  `test_the_banner_never_uses_unescaped_blade_output()` greps the partial for Blade's
  unescaped-echo token so it cannot be switched later.
- **Client surface (T-46-05-02):** a sentinel in `send_back_reason` is asserted **present** on the
  survey body and **absent** from the worksheet body.
- **LR-04:** the partial carries no actor name and names no labour-resource class; it is in
  `CLIENT_FACING_PATHS`. **One path added, none removed, no staff-auth surface added** (the cockpit
  stays out, per the file's `:55-61` docblock).
- **VL-03:** `test_rendering_the_banner_rotates_no_access_token()` — both tokens byte-identical
  across the render. The deliberate `$fillable` omissions on `SiteSurvey`/`Worksheet`
  (`access_token`, `access_token_expires_at`, `submitted_notification_sent_at`) are untouched; the
  test fixture sets the token via `forceFill()` for exactly that reason.

## Gate results (verbatim)

| Gate | Result |
|------|--------|
| `-Filter SendBackReopensEngineerLinkTest` | `Tests:    21 passed (94 assertions)` |
| `-Filter LabourResourceClientSurfacePrivacyTest` | `Tests:    8 passed (42 assertions)` |
| `-Path tests/Feature/Surveys` | `Tests:    6 passed (23 assertions)` |
| `-Path tests/Feature/Worksheets` | `Tests:    33 passed (123 assertions)` |
| `-Baseline` (D-06) | `Tests:    2 skipped, 159 passed (396 assertions)` |

Baseline gate: **159 passed ≥ 159, 0 failed** — the 2 skips are the pre-existing `ext-imagick`
self-skips named in `45-BASELINE.md`. Never compared against 161.

Protected-file hashes (`-Hashes`) — all three **match** `45-BASELINE.md` exactly:

| File | SHA256 |
|---|---|
| `resources/views/layouts/app.blade.php` | `9ED63C4C…0557` ✅ |
| `resources/css/app.css` | `EDAD1982…2133` ✅ |
| `tailwind.config.js` | `73BB8AD6…74BB` ✅ |

Blade trap check: `artisan view:clear` + `view:cache`, then `php -l` over all **313** compiled
views — **0 failures**.

## Deviations from Plan

**1. [Process] Stopped on the gate-site count before editing anything**
- The plan said eleven; grep measured ten. Halted and reported rather than proceeding, per the
  explicit stop rule. The coordinator verified independently, corrected the plan (`b703e38a`), and
  ruled "proceed against ten". No code was written before that ruling.

**2. [Rule 3 — Blocking] Test harness: throttling, payload shape, and a missing question row**
- The public survey routes carry per-route throttles (`submit` is `throttle:10,1`). Driving 8
  routes twice tripped the limiter and turned 403 assertions into 429s that said nothing about the
  gate. `setUp()` now disables **only** `ThrottleRequests` — the gates themselves stay fully
  exercised.
- `stepSave` step 1 requires `name` + `type`; the first payload returned 422 (which *did* prove the
  gate opened, but over-specified `assertStatus(200)` failed). Payload corrected.
- `answerQuestion` 403s on an unknown question id before reaching the gate, so the fixture now
  creates a real `SiteSurveyRoomQuestion`.

**3. [Rule 1 — Bug, self-inflicted] The partial tripped two of its own guards**
- The docblock originally wrote out Blade's unescaped-echo token while explaining that it is
  forbidden — which failed the grep guard. Reworded, with a note saying why the token is spelled
  out nowhere in the file.
- It also named the labour-resource class while explaining that it must never reference it — which
  failed the `CLIENT_FACING_PATHS` literal scan the same commit had just added it to. Reworded.
  Both failures were the guards working exactly as designed.

**4. [Scope] `PublicSurveyController::show` (:164) is unrouted dead code**
- `grep "PublicSurveyController::class, 'show'" routes/web.php` returns nothing;
  `GET /survey/{token}` routes to `SurveyController::show`. The site was still swapped for
  consistency (it is one of the ten), but it is asserted **statically** rather than pretending an
  HTTP test covers it. Recorded at the assertion.

## Findings for later plans

**Plan 46-06 writes what this plan reads.** `sent_back_at` and `send_back_reason` are still written
by nothing — 46-06 owns the PM "Send back" action. The moment it does, this banner and the
reopening light up with no further change here.

**Do not add a "clear send-back" control.** There is nothing to clear: the reopening ends when the
engineer resubmits, because that is what the comparison measures. A control to un-set
`sent_back_at` would delete the record of a PM's act for no behavioural gain.

## Deferred Issues

**D-46-05-01 — `/worksheet/{token}` 500s for a worksheet with no rooms.**
`Undefined variable $signOffBlocked`: assigned at `public-show.blade.php:773` inside the
populated-rooms branch, read at `:1359`/`:1441` outside it. **Verified pre-existing** — the file
was reverted to `HEAD` and the case re-run, producing the identical 500. The 46-05 banner include
sits inside the populated branch and is never reached in that case. Not fixed (out of scope; it is
view logic on a client-signed page this plan has no business restructuring). Full write-up and a
suggested fix in `deferred-items.md`.

The plan's three named pre-existing cases — no visit, no survey, legacy worksheet — are all
asserted green.

## Known Stubs

None. Every element this plan renders is driven by a real column read live from the visit; nothing
renders placeholder data. The banner is invisible until 46-06 writes a send-back, which is the
designed sequencing, not a stub.

## Threat Flags

None. No new route, no new auth path, no new file access pattern, no schema change. No package was
installed (`composer.json` / `package.json` untouched — T-46-05-SC).

## Self-Check: PASSED

- `app/Support/Visits/VisitReworkState.php` — FOUND
- `resources/views/partials/_office-sendback-banner.blade.php` — FOUND
- `tests/Feature/Visits/SendBackReopensEngineerLinkTest.php` — FOUND
- `.planning/phases/46-visit-lifecycle/deferred-items.md` — FOUND
- `app/Models/SiteSurvey.php` matches `isLockedForEngineer` — FOUND
- `isSubmitted` in the two survey controllers — 0 occurrences (expected 0)
- `isLockedForEngineer` in the two survey controllers — 10 occurrences (expected 10)
- `_office-sendback-banner` in `CLIENT_FACING_PATHS` — FOUND
- Commit `7c89d44a` — FOUND
- Commit `2d37af92` — FOUND
