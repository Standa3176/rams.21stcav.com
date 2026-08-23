---
quick_id: 260823-cpv
slug: parked-stage-nudge
date: 2026-08-23
status: planned
---

# Quick Task 260823-cpv — a project parked in Survey Pending whose Survey is Not required

## Found by the user in live UI, 2026-08-23

On project 89 (21CQ29258-05), Site Survey was set to **Not required**. The tab strip
correctly showed the muted `NOT REQUIRED · Surveys · + Add anyway` group, but the
Project Workflow stepper still rendered **Survey Pending** as the active stage. The
page reads as contradictory: "no survey needed" beside "waiting for survey".

## Not a code bug — a gap in D-11

`resources/views/projects/show.blade.php:766-767`:

```php
$surveySkipped = deliverableState(KEY_SITE_SURVEY) === STATE_NOT_REQUIRED
    && $currentIdx > array_search(STATUS_SURVEY_PENDING, $lifecycle);
```

The `>` requires the project to be **already past** the stage. That branch exists to
stop a project which *jumped over* survey_pending at import from showing a false
"done" tick (a real bug research found). It was never meant to cover a project
**parked in** the stage when the deliverable is later marked not-required.

D-11 only ever covered the import-time `quote_imported → engineering` transition.
The back-catalogue was not considered — there are 76 projects with no survey, 4 of
them currently sitting in `survey_pending`. This is the same class of miss as
260823-bcm: a decision designed for new projects meeting existing ones.

## Decision (user, 2026-08-23): nudge, do not auto-advance

**Explicitly rejected — greying the pill.** The project's `status` column genuinely
is `survey_pending`; the status badge and the dashboard stage filter both say so.
Rendering the stepper pill as "skipped" while the badge says SURVEY PENDING would
trade one contradiction for a worse one — the UI disagreeing with itself about the
same fact. The honest position is that the project really is parked in a stage it no
longer needs, and that is something to act on, not hide.

**Explicitly rejected — auto-advance.** A checkbox silently changing project stage is
surprising, and inconsistent with this phase's posture everywhere else (D-02 soft
gate, D-14 warn-don't-block).

## Task 1 — surface the nudge

**File:** `resources/views/projects/show.blade.php` (stepper block, ~`:760-830`;
the existing Advance control is at `:718-736` — **read both before editing**, and note
line numbers have drifted repeatedly this session, so locate by content not number)

**Condition — all must hold:**
- `deliverableState(KEY_SITE_SURVEY) === STATE_NOT_REQUIRED`
- `$project->status === STATUS_SURVEY_PENDING` (i.e. parked *at* it, the case
  `$surveySkipped` deliberately excludes — do NOT widen `$surveySkipped` itself,
  that branch is correct as written and its false-done-tick test must keep passing)
- the project is not archived/completed

**Render:** a quiet inline hint adjacent to the stepper, wired to the **existing**
Advance action at `:718-736`. Do not add a second transition route or duplicate that
form's `data-confirm` behaviour — reuse it.

Wording should state the fact and offer the action, e.g.
*"Site Survey is not required for this project — advance to Engineering?"*

**Do not** imply the stage was completed. Nothing may render a done-tick for a stage
that never happened; that is the bug the `$surveySkipped` branch exists to prevent.

**Acceptance criteria:**
- Parked + not-required → hint renders, and its action targets the existing advance route
- Parked + Survey `required` or `not_yet_decided` → **no** hint
- Already past survey_pending + not-required → existing `$surveySkipped` behaviour
  unchanged, still no done-tick, still no hint
- Archived/completed project → no hint
- The stepper still shows `Survey Pending` as the active step — the status is real and
  must not be misrepresented
- No new route, no new controller action, no status written as a side effect of viewing

**Non-vacuity is mandatory:** prove the new condition is load-bearing by reverting it,
watching the test fail, and restoring.

## Constraints

- PHPUnit 11, **NOT Pest**. PHP: `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe"`
- **Blade must be verified with `app('blade.compiler')->compileString(...)`**, not just
  `php -l` — a JS comment silently broke a shared component here on 2026-08-17 while
  `php -l` passed clean.
- `ProjectHealthService` must keep issuing **zero queries**; if the hint needs
  deliverable state, it comes from the already-eager-loaded `deliverables` relation.
- Do not change tab `key` strings (localStorage is keyed by them).
- Additive and non-destructive. Do not tighten any policy. Do not touch `config/rams_tier1.php`.
- Suite baseline: **2222 passed, 1 failed** (`QueueRecoverCommandTest`, documented,
  passes in isolation). Any other failure is a regression. Never extend the known-failures list.
- Full-suite runs stall under load; targeted `--filter=` runs are fine, but say so plainly
  if one stalls rather than reporting an unverified pass.

## Out of scope

- Auto-advancing any project
- The 4 currently-parked projects — this is UI only; no data migration
- Any other stage in the lifecycle (only survey_pending has a not-required deliverable
  that gates it)
