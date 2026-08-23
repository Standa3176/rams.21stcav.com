---
quick_id: 260823-cpv
slug: parked-stage-nudge
date: 2026-08-23
status: complete
commits: 81089c6
---

# Summary — nudge for a project parked at Survey Pending with a not-required Survey

Found by the user in live UI immediately after the phase deployed: project 89
(21CQ29258-05) had Site Survey set to **Not required** — the tab strip correctly
showed the muted `NOT REQUIRED · Surveys · + Add anyway` group, but the workflow
stepper still rendered Survey Pending as the active stage. The page read as
contradictory.

**Not a code bug.** `$surveySkipped` requires `$currentIdx > survey_pending index`
— deliberately only the *jumped over* case, which is the false-done-tick bug it was
built to prevent. A project *parked in* the stage was never in D-11's scope. Same
class of miss as 260823-bcm: a decision designed for new projects meeting the
back-catalogue (76 projects with no survey, 4 of them sitting in survey_pending).

## What changed (`81089c6`)

`resources/views/projects/show.blade.php` only — plus tests.

- `:717` — gave the existing Advance form `id="ws-advance-form"`. Action, hidden
  inputs and `data-confirm` untouched.
- `:766-780` — new **independent** `$surveyParkedNotRequired`. `$surveySkipped` was
  not modified. The two are mutually exclusive by construction: one requires already
  past the stage, the other requires currently at it.
- After the stepper loop — a quiet hint plus **Advance to Engineering →**, rendered as
  `<button type="submit" form="ws-advance-form">`. An externally-associated submit
  button, **not** a second form: same action, same hidden inputs, same CSRF token. The
  site-wide capture-phase submit listener (`layouts/app.blade.php:2044-2065`) matches on
  `e.target` (the form), not the triggering control, so the existing confirm dialog
  fires with no duplicated logic.

## Rejected alternatives (user decision, recorded)

**Greying the stepper pill.** The `status` column genuinely is `survey_pending`; the
status badge and dashboard stage filter both agree. Rendering it "skipped" would trade
one contradiction for a worse one — the UI disagreeing with itself about the same fact.

**Auto-advancing.** A checkbox silently changing project stage is surprising and
inconsistent with this phase's posture throughout (D-02 soft gate, D-14 warn-don't-block).

## Verification

- `php -l` clean; **`blade.compiler->compileString()` → `BLADE_COMPILE_OK`** (not just `php -l`)
- `--filter=ProjectStepperTest` → 6 passed (2 pre-existing untouched + 4 new)
- `--filter="Project|Health|Transition|Deliverable"` → **378 passed, 1 failed, 1 skipped**
  — the sole failure is the documented `QueueRecoverCommandTest`
- **Non-vacuity proven:** forced `$surveyParkedNotRequired = false` → hint test failed on
  the missing string → restored → 6/6 green
- **No new route or controller action:** `route:list | wc -l` was 280 both before
  (`git stash`) and after — identical. Diff touches only the Blade view and its test.

"Archived/completed → no hint" is satisfied structurally rather than by a test: the
condition requires `status === survey_pending` and status is single-valued, so the
combination is unreachable. A test for it would be vacuous.

## Files to upload to live

```
resources/views/projects/show.blade.php
```
Then `php artisan optimize:clear` (view cache). No migration. Test file is dev-only.

## Not done

The 4 currently-parked projects are untouched — this is UI only, no data migration.
Whether to advance them is a per-project human decision, which is the point.
