---
phase: 07-dynamic-site-survey
plan: "05"
subsystem: views
tags: [public-survey, internal-survey, pre-install-checks, alpine-js, ajax, wave-4]
dependency_graph:
  requires:
    - 07-02 (SiteSurveyRoomQuestion model with answer/other_text fields)
    - 07-04 (survey.question.answer + site-survey.question.answer AJAX endpoints)
  provides:
    - Pre-Install Checks panel in public survey form (show.blade.php)
    - Pre-Install Checks panel in internal admin form (_room-form.blade.php)
    - toggleChecks() / answerCheck() / saveOtherText() / updateCheckProgress() JS
    - answerCheckInternal() / saveOtherTextInternal() JS (edit.blade.php)
    - N+1 prevention — rooms.questions eager-loaded in both controllers
  affects:
    - Engineers completing site surveys can now answer pre-install check questions
tech_stack:
  added: []
  patterns:
    - Amber palette (#FFFBEB/#FCD34D) for Pre-Install panel — visually distinct from teal kit drawer
    - Alpine.js x-data/x-show/x-transition for internal form collapsible (no vanilla JS)
    - Vanilla JS toggleChecks()/answerCheck() for public form (no Alpine.js available there)
    - AJAX blur-save pattern for other_text textarea (silent catch, no user-facing spinner)
    - Server-rendered selected state on page load — JS updates state after AJAX saves
key_files:
  created: []
  modified:
    - resources/views/public-survey/show.blade.php
    - resources/views/site-survey/_room-form.blade.php
    - resources/views/site-survey/edit.blade.php
    - app/Http/Controllers/PublicSurveyController.php
    - app/Http/Controllers/SiteSurveyController.php
decisions:
  - "sr-only added as inline CSS in show.blade.php — no Tailwind in that file, class was missing"
  - "answerCheckInternal() placed in edit.blade.php @push(scripts) not _room-form.blade.php — keeps JS out of server-rendered partial, consistent with existing edit.blade.php pattern"
  - "SiteSurveyController::update() not modified — it redirects immediately without reloading a view, so rooms.questions eager load is not needed there"
requirements-completed: []

# Metrics
duration: 45min
completed: 2026-04-12
tasks_completed: 2
tasks_total: 2
files_created: 0
files_modified: 5
---

# Phase 07 Plan 05: Wave 4 — Pre-Install Checks Panel (Both Survey Forms) Summary

**One-liner:** Amber-palette Pre-Install Checks panel with AJAX Yes/No/Other answer buttons added to both public and internal survey forms, with N+1 eager-load fixes in both controllers.

## What Was Built

**Task 1 — Public survey form (show.blade.php + PublicSurveyController):**
- `resources/views/public-survey/show.blade.php`: Added `.checks-block` CSS block (amber palette — `#FFFBEB` bg, `#FCD34D` border) visually distinct from the teal kit drawer. Added `.sr-only` utility class (absent from that file). Added `toggleChecks()`, `answerCheck()`, `saveOtherText()`, `updateCheckProgress()` vanilla JS functions after the existing `toggleKit()`.
- Added Pre-Install Checks panel Blade HTML inside the room card loop, positioned after the kit-block `@endif` and before SECTION 1. Panel renders only when `$room->questions->isNotEmpty()` — absent otherwise (satisfies D-08). Collapsed by default (`max-height:0`). PRE-INSTALL badge (`#0B3C45` dark teal bg). Yes/No/Other buttons AJAX-save on click, other_text textarea saves on blur.
- `app/Http/Controllers/PublicSurveyController.php`: Changed `$survey->load('rooms.photos')` to `$survey->load('rooms.photos', 'rooms.questions')` in `show()` — prevents N+1 queries.

**Task 2 — Internal admin form (_room-form.blade.php + SiteSurveyController + edit.blade.php):**
- `resources/views/site-survey/_room-form.blade.php`: Added Alpine.js Pre-Install Checks panel (`x-data="{ checksOpen: false }"`, `x-show="checksOpen"`, `x-transition`) after the kit drawer `@endif`. Guard: `$isModel && $room->relationLoaded('questions') && $room->questions->isNotEmpty()`. Server-renders selected button colors from `$question07->answer` on load. `answerCheckInternal()` called onclick, `saveOtherTextInternal()` called onblur.
- `resources/views/site-survey/edit.blade.php`: Added `answerCheckInternal()` and `saveOtherTextInternal()` JS in the existing `@push('scripts')` block — identical AJAX logic to the public form, using `colorMap` object to update inline styles instead of CSS classes (internal form uses inline styles, not utility classes).
- `app/Http/Controllers/SiteSurveyController.php`: Changed `$siteSurvey->load('rooms.photos')` to `$siteSurvey->load('rooms.photos', 'rooms.questions')` in `edit()`.

## Test Results

- Full suite: 314 passed, 8 pre-existing failures
- Pre-existing failures confirmed identical before and after this plan's changes:
  - 6 Auth tests: `MissingAppKeyException` (worktree infrastructure — `.env` was missing, later copied from main project but some Auth tests remain environment-sensitive)
  - 2 `PublicSurveyControllerTest` (completion gate tests requiring Plan 06 — documented in 07-04 SUMMARY as pre-existing)
- No new test failures introduced by this plan

## Deviations from Plan

**1. [Rule 3 - Blocking] vendor/autoload.php missing in worktree**
- **Found during:** Task 1 test run
- **Issue:** Composer packages not installed in worktree; `php artisan test` failed immediately
- **Fix:** Ran `composer install --no-interaction` + `composer dump-autoload` using Herd PHP 8.4. 131 packages installed.
- **Files modified:** None (infrastructure only)

**2. [Rule 1 - Bug] update() in SiteSurveyController has no load() call — plan step incorrect**
- **Found during:** Task 2 implementation review
- **Issue:** Plan Step 1 for Task 2 said to change `$siteSurvey->load('rooms.photos')` in `update()` at line ~153. Actual `update()` does not call `load()` at all — it validates, delegates to service, and redirects. No view is rendered, so eager loading rooms.questions there is unnecessary.
- **Fix:** Only `edit()` was modified (correct). `update()` left unchanged.
- **Impact on done criteria:** Plan required 2 matches for `rooms.questions` in SiteSurveyController. Only 1 match exists (`edit()`). This is intentional — the second match in the plan was based on incorrect line reference.

## Known Stubs

None — all panels render live data from `$room->questions` (eager-loaded). No hardcoded placeholders or empty arrays flowing to UI.

## Threat Flags

No new threat surface beyond the plan's threat model. All 4 STRIDE threats mitigated:
- T-07-05-01: `{{ $question->question }}` — Blade auto-escapes AI-generated text. `{!! !!}` not used.
- T-07-05-02: `{{ $question->other_text }}` auto-escaped in textarea value. Server validates max:2000 (implemented in 07-04 endpoints).
- T-07-05-03: X-CSRF-TOKEN header included in all fetch() calls (answerCheck, answerCheckInternal, saveOtherText, saveOtherTextInternal).
- T-07-05-04: data-answer-url attribute accepted — non-sensitive, token-gated at server, same pattern as photo upload URLs.

## Commits

| Task | Hash    | Message |
|------|---------|---------|
| 1    | c4f4dda | feat(07-05): Pre-Install Checks panel — public survey form + eager load fix |
| 2    | 04d860f | feat(07-05): Pre-Install Checks panel — internal admin form + eager load fix |

## Self-Check: PASSED

Files confirmed to exist:
- `resources/views/public-survey/show.blade.php` — FOUND
- `resources/views/site-survey/_room-form.blade.php` — FOUND
- `resources/views/site-survey/edit.blade.php` — FOUND (modified)
- `app/Http/Controllers/PublicSurveyController.php` — FOUND
- `app/Http/Controllers/SiteSurveyController.php` — FOUND
- `.planning/phases/07-.../07-05-SUMMARY.md` — FOUND

Commits confirmed in git log:
- c4f4dda — FOUND
- 04d860f — FOUND
