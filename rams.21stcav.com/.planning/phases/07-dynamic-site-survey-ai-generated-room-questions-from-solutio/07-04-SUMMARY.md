---
phase: 07-dynamic-site-survey
plan: "04"
subsystem: http
tags: [routes, controller, answer-endpoint, token-gated, auth-gated, wave-3]
dependency_graph:
  requires:
    - 07-01 (PublicSurveyQuestionAnswerTest contract tests)
    - 07-02 (SiteSurveyRoomQuestion model with fillable answer/other_text)
  provides:
    - survey.question.answer — POST endpoint for public (token-gated) answer persistence
    - site-survey.question.answer — POST endpoint for internal (auth-gated) answer persistence
    - PublicSurveyController::answerQuestion() — room-scoped question answer save
    - SiteSurveyController::answerQuestion() — room-scoped question answer save (auth)
  affects:
    - Plan 05 (completion gate — can now check question answer state)
    - Plan 06 (UI — AJAX calls target these routes)
tech_stack:
  added: []
  patterns:
    - first()+abort_if(null,403) security pattern (not firstOrFail) — prevents leaking question ID existence
    - Nullable field partial-update pattern: only update fields present in request (blur-save flow)
    - Separate public (token) and auth (session) endpoints with identical business logic
key_files:
  created: []
  modified:
    - routes/web.php
    - app/Http/Controllers/PublicSurveyController.php
    - app/Http/Controllers/SiteSurveyController.php
decisions:
  - "first()+abort_if(null,403) used instead of firstOrFail() to return 403 (not 404) when question does not belong to room — prevents ID enumeration"
  - "Both endpoints use identical validation and response shape to simplify Plan 06 AJAX client code"
requirements-completed: []

# Metrics
duration: 45min
completed: 2026-04-12
tasks_completed: 2
tasks_total: 2
files_created: 0
files_modified: 3
---

# Phase 07 Plan 04: Wave 3 — Answer Persistence Endpoints Summary

**One-liner:** Two answer-persistence endpoints (token-gated public + auth-gated internal) with room-scoped security gates, partial-update blur-save support, and 403-not-404 ID-enumeration protection.

## What Was Built

**Task 1 — Public answer endpoint:**
- `routes/web.php`: Added `POST survey/{token}/rooms/{room}/questions/{question}` → `survey.question.answer` (throttle:120,1)
- `app/Http/Controllers/PublicSurveyController.php`: Added `use App\Models\SiteSurveyRoomQuestion` import
- Added `answerQuestion(Request, string $token, SiteSurveyRoom $room, int $question): JsonResponse`
- Security chain: `resolveSurvey($token)` → `abort_unless($room->site_survey_id === $survey->id, 403)` → `abort_if($survey->isSubmitted(), 403)` → `first()+abort_if(null,403)` room-scoped question lookup
- Partial-update logic: only updates fields present in validated request (supports answer-only save and other_text blur-save)
- Clears `other_text` when switching away from `answer=other`
- Returns `{ answered: bool, answer: string|null, other_text: string|null }`

**Task 2 — Auth-gated internal answer endpoint:**
- `routes/web.php`: Added `POST site-surveys/{siteSurvey}/rooms/{room}/questions/{question}` → `site-survey.question.answer` inside auth middleware group
- `app/Http/Controllers/SiteSurveyController.php`: Added `use App\Models\SiteSurveyRoomQuestion` import
- Added `answerQuestion(Request, SiteSurvey $siteSurvey, SiteSurveyRoom $room, int $question): JsonResponse`
- Identical business logic to public endpoint: same validation, same security scope check, same JSON response shape
- Auth gate provided by Laravel's `auth` middleware group on the route — no additional policy needed

## Test Results

All 6 `PublicSurveyQuestionAnswerTest` tests GREEN:
- `test_post_with_answer_yes_returns_200_answered_true` — PASS
- `test_post_with_answer_other_and_other_text_returns_200_with_other_text` — PASS
- `test_post_with_invalid_answer_returns_422` — PASS
- `test_post_to_question_belonging_to_different_room_returns_403` — PASS
- `test_post_with_other_text_exceeding_2000_chars_returns_422` — PASS
- `test_post_to_submitted_survey_returns_403` — PASS

Full suite: 320 passed, 2 pre-existing RED failures (`PublicSurveyControllerTest` — completion gate tests requiring Plan 05).

## Deviations from Plan

**1. [Rule 1 - Bug] firstOrFail() returns 404 but test expects 403**
- **Found during:** Task 1 test run — `test_post_to_question_belonging_to_different_room_returns_403` received 404 instead of 403
- **Issue:** Plan action specified `firstOrFail()` which throws `ModelNotFoundException` (rendered as 404). Test asserts 403 to prevent ID enumeration.
- **Fix:** Changed to `first()` + `abort_if($questionRecord === null, 403)`. Applied to both endpoints for consistency.
- **Files modified:** `app/Http/Controllers/PublicSurveyController.php`, `app/Http/Controllers/SiteSurveyController.php`
- **Commit:** cca60a8

**2. [Rule 3 - Blocking] Main project vendor/ was empty — composer install required**
- **Found during:** Task 1 test execution setup
- **Issue:** `vendor/autoload.php` missing in main project; `php artisan test` failed immediately
- **Fix:** Ran `composer install --no-interaction` using Herd's PHP 8.4 binary in the main project directory. All 131 packages installed successfully.
- **Files modified:** None (infrastructure only)

## Known Stubs

None — this plan creates no UI rendering. All outputs are JSON responses.

## Threat Flags

No new threat surface beyond the plan's threat model. All 6 STRIDE threats in the threat register are mitigated:
- T-07-04-01: resolveSurvey() validates token (404/410)
- T-07-04-02: first()+abort_if(null,403) room scope gate — prevents question ID guessing
- T-07-04-03: `in:yes,no,other` validation rule
- T-07-04-04: `max:2000` on other_text
- T-07-04-05: abort_if($survey->isSubmitted(), 403)
- T-07-04-06: auth middleware group gates internal route

## Commits

| Task | Hash | Message |
|------|------|---------|
| 1 | cca60a8 | feat(07-04): public answer endpoint — route + PublicSurveyController::answerQuestion() |
| 2 | 753e1fb | feat(07-04): auth-gated answer route and SiteSurveyController::answerQuestion() |

## Self-Check: PASSED

Files confirmed to exist:
- `routes/web.php` — FOUND (modified at cca60a8, 753e1fb)
- `app/Http/Controllers/PublicSurveyController.php` — FOUND (modified at cca60a8)
- `app/Http/Controllers/SiteSurveyController.php` — FOUND (modified at 753e1fb)

Routes confirmed registered:
- `survey.question.answer` — FOUND (verified via artisan route:list)
- `site-survey.question.answer` — FOUND (verified via artisan route:list)

Commits confirmed in git log:
- cca60a8 — FOUND
- 753e1fb — FOUND
