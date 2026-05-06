---
phase: quick-260506-fh0
plan: 01
type: quick
tags: [site-survey, project-data, ux, surveyservice]
date_completed: 2026-05-06
duration_minutes: ~25
tasks_completed: 3
commits:
  - hash: (none — verification audit, no source changes)
    task: 1
    note: verify entry-point wiring
  - hash: 7f9d108
    task: 2
    title: "feat(survey-260506-fh0): seed general_notes from project works_description on createFromProject"
  - hash: 8e0a5da
    task: 3
    title: "feat(survey-260506-fh0): add Confirm Rooms review step between createFromProject and edit form"
files_created:
  - resources/views/site-survey/confirm-rooms.blade.php
files_modified:
  - app/Core/Modules/Survey/SurveyService.php
  - app/Http/Controllers/SiteSurveyController.php
  - routes/web.php
deviations: 0
---

# Quick Task 260506-fh0: Site Survey Auto-Populate from Project Data Summary

**One-liner:** Stops dumping engineers into a blank rooms form by mirroring `works_description` into the survey's `general_notes` and inserting a lightweight Confirm Rooms review screen between `createFromProject` and the heavy edit form.

## What Shipped

Three deliveries, in plan order:

1. **Verified entry-point** (Task 1, no commit) — confirmed `projects/show.blade.php` lines 438, 728, 826 already route the "+ New Survey" CTA through `site-surveys.from-project`. Confirmed `php artisan route:list --name=site-surveys.from-project` registers the route. Three remaining `route('site-surveys.create')` references (dashboard.blade.php:240, site-survey/index.blade.php:18+85) are the legitimate global "no project" CTAs the plan explicitly says to leave alone — they have no project context and serve the standalone-survey path. **No stragglers found, no source changes required.**

2. **`works_description` → `general_notes` seeding** (Task 2, commit `7f9d108`) — `SurveyService::createFromProject()` now inherits the project's works description (with fallback to the latest reviewed `ProjectPackage`'s works description) into the new survey's `general_notes`. Capped at 3000 chars to honour the survey edit form's `maxlength` constraint. Logs an audit-trail `Log::info('SurveyService: seeded general_notes from works_description', …)` on success with `source` ('project' or 'package') + `length`. Smoke-tested via tinker on project 1 → survey 6 created with 158 chars of general_notes mirrored from project's `works_description`.

3. **Confirm Rooms review screen** (Task 3, commit `8e0a5da`) — new GET/POST routes registered before the resource block; `SiteSurveyController::confirmRooms()` renders the verify view; `applyConfirmedRooms()` builds a `rooms[]` payload and reuses `SurveyService::update()` (which already handles qty>1 → numbered-copies expansion AND prunes rooms whose `id` is missing from the incoming list). `createFromProject` and `supersedeFromProject` now redirect to `site-surveys.confirm-rooms` instead of `site-surveys.edit`. New view `resources/views/site-survey/confirm-rooms.blade.php` (~129 lines) uses `.form-section` chrome + teal `#178A95` accents + `.btn btn-teal` — matches existing `edit.blade.php` style. The "Skip — go straight to edit" link bypasses the confirm step entirely; navigating away leaves the survey + auto-imported rooms untouched (soft step, not a transaction).

## Files Changed

| File                                                          | Status   | Lines Δ              |
| ------------------------------------------------------------- | -------- | -------------------- |
| `app/Core/Modules/Survey/SurveyService.php`                   | modified | +28 / -1             |
| `app/Http/Controllers/SiteSurveyController.php`               | modified | +79 / -2             |
| `routes/web.php`                                              | modified | +6                   |
| `resources/views/site-survey/confirm-rooms.blade.php`         | **new**  | +129 (whole file)    |

**Total:** 4 files, +240/-5 lines, 2 atomic commits (Task 1 had no source changes).

## Discoveries During Execution

- **No straggler CTAs.** Audit grep produced exactly 3 `site-surveys.create` references in views, all of which are the legitimate "no project context" path (dashboard quick-link, two index-page "+ New Survey" buttons). Plan was correct — Task 1 is purely an audit. No changes to `projects/show.blade.php`.
- **`update()` re-creates qty>1 rooms** — confirmed during Task 3 design that `SurveyService::update()` lines 285–297 fall through to `createRoom()` for any row with `qty>1`, even when an `id` is supplied. This is the desired behaviour: when the user says "I want THREE small rooms here" we want three fresh rooms with sequential names, not one room renamed three ways.
- **Hidden+checkbox boolean pattern works without JS.** The `<input type="hidden" name=…include value=0>` followed by `<input type="checkbox" name=…include value=1>` ensures the `include` field is always submitted (even when unticked) so Laravel's `boolean` validation rule resolves to `false` correctly for excluded rooms.
- **Render smoke test** on `site-survey.confirm-rooms` view via tinker emitted 98,851 bytes of HTML for a 1-room survey when `$errors` was provided as a `ViewErrorBag` (the `ShareErrorsFromSession` middleware injects it automatically in real HTTP requests).

## Deviations from Plan

**None.** Plan executed exactly as written. The PLAN-suggested `chore: verify` empty-bodied commit for Task 1 was skipped per the prompt constraints ("if it's a verification audit with no code changes, skip the commit and add a note in SUMMARY.md").

## Verification

- `php -l` passes on all 3 modified PHP files (PHP 8.4 from Herd).
- `php artisan route:list --name=site-surveys.confirm-rooms` shows BOTH GET and POST routes.
- `php artisan route:list --name=site-surveys.from-project` shows the entry-point route.
- Tinker probe on project 1 → `survey_id=6 general_notes_len=158 first120="Add that working height is at ground level…"` confirms Task 2 seeding works end-to-end.
- Blade compile-check on `confirm-rooms.blade.php` returns `BLADE_OK len=8090` (no syntax errors).
- View render with `$errors = new ViewErrorBag()` returns `RENDER_OK len=98851 rooms=1`.

## STATE.md Quick Tasks Entry

```
| 260506-fh0 | 2026-05-06 | Site Survey auto-populate fixes — `createFromProject` now seeds `general_notes` from project (or fallback package) `works_description` (3000-char cap + audit log); new lightweight Confirm Rooms review screen between create and edit (GET/POST `site-surveys.confirm-rooms`, reuses `SurveyService::update` for qty-expansion + prune; "Skip" link bypasses to edit). Audit confirmed all project-page "+ New Survey" CTAs already route through `site-surveys.from-project` (3 remaining `site-surveys.create` references are legitimate standalone path — left alone). 4 files, 2 commits, +240/-5 lines. Pure additive — no migrations, no new packages. | ⚠️ Needs Upload | 8e0a5da |
```

## Self-Check: PASSED

- `[x]` `app/Core/Modules/Survey/SurveyService.php` — modified, lint passes, tinker smoke passes
- `[x]` `app/Http/Controllers/SiteSurveyController.php` — modified, lint passes, route:list confirms registration
- `[x]` `routes/web.php` — modified, lint passes, route:list confirms BOTH new routes
- `[x]` `resources/views/site-survey/confirm-rooms.blade.php` — created, blade-compile passes, render passes
- `[x]` Commit `7f9d108` exists (Task 2)
- `[x]` Commit `8e0a5da` exists (Task 3)
- `[x]` Task 1 has no commit (verification audit, no source changes — per prompt constraint)

## Files to upload to live

> Local-edit-then-upload deployment workflow — these files must be uploaded to the live server.

- `C:\Users\sonny.tanda\Documents\1 - Claude Projects\Rams2\rams.21stcav.com\app\Core\Modules\Survey\SurveyService.php` (Task 2 — modified)
- `C:\Users\sonny.tanda\Documents\1 - Claude Projects\Rams2\rams.21stcav.com\app\Http\Controllers\SiteSurveyController.php` (Task 3 — modified)
- `C:\Users\sonny.tanda\Documents\1 - Claude Projects\Rams2\rams.21stcav.com\routes\web.php` (Task 3 — modified)
- `C:\Users\sonny.tanda\Documents\1 - Claude Projects\Rams2\rams.21stcav.com\resources\views\site-survey\confirm-rooms.blade.php` (Task 3 — **new file**, ensure target directory `resources/views/site-survey/` exists on the server)

After upload, optional cache-clear on live: `php artisan view:clear && php artisan route:clear` so the new Blade view + new routes are picked up immediately.
