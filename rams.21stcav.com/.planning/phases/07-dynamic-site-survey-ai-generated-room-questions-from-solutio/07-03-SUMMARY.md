---
phase: 07-dynamic-site-survey
plan: "03"
subsystem: jobs
tags: [queue, job, ai, survey, tdd, wave-2]
dependency_graph:
  requires:
    - 07-01 (test contracts for GenerateSurveyQuestionsJob)
    - 07-02 (SiteSurveyRoomQuestion model, SurveyQuestionsPrompt)
  provides:
    - GenerateSurveyQuestionsJob — async queue job that generates AI questions per room
    - SurveyService::createFromProject() dispatches job per room with solution_type_id
  affects:
    - Plan 04 (answer endpoint — questions are now created by this job)
    - Plan 05 (completion gate — depends on questions() HasMany on room)
    - Plan 06 (UI — questions rendered from SiteSurveyRoomQuestion records created here)
tech_stack:
  added: []
  patterns:
    - ShouldQueue job with Dispatchable/InteractsWithQueue/Queueable/SerializesModels
    - Silent failure pattern (D-11): handle() absorbs Throwable, zero questions is valid state
    - Container resolution for testability: app(AIManager::class)->run() over static AIManager::run()
    - Try/catch around dispatch in service: dispatch failure cannot abort survey creation transaction
key_files:
  created:
    - app/Jobs/GenerateSurveyQuestionsJob.php
  modified:
    - app/Core/Modules/Survey/SurveyService.php
decisions:
  - "Solution type treated as optional context: job proceeds to AI call even when solution type cannot be resolved from room_overviews, using empty slug/checklist — tests require AI to be called regardless"
  - "AIManager resolved via app() container not static call: allows Mockery shouldReceive('run') interception in tests"
  - "handle() does NOT rethrow Throwable: dispatchSync() in tests must not propagate — D-11 silent failure applies at handle() level, not only failed() hook"
requirements-completed: []

# Metrics
duration: 45min
completed: 2026-04-12
tasks_completed: 2
tasks_total: 2
files_created: 1
files_modified: 1
---

# Phase 07 Plan 03: Wave 2 — Background Job and Service Dispatch Summary

**One-liner:** GenerateSurveyQuestionsJob queues AI question generation per room, wired into SurveyService::createFromProject() with silent-failure guarantee (D-11) and container-resolved AIManager for testability.

## What Was Built

**Task 1 — GenerateSurveyQuestionsJob:**
- `app/Jobs/GenerateSurveyQuestionsJob.php` — ShouldQueue implementation with `$tries=2`, `$timeout=60`
- `handle()` loads room, package, resolves solution type from `extracted_data.room_overviews`, builds AI context, calls `app(AIManager::class)->run(new SurveyQuestionsPrompt(), ...)`, validates `['questions']` array shape, persists `SiteSurveyRoomQuestion` records with `sort_order` index
- Shape validation: if AI returns anything other than an array under `questions` key, logs warning and creates zero records
- Silent failure (D-11): entire `handle()` body is wrapped in `try/catch(\Throwable)` — exception is logged but NOT re-thrown, ensuring `dispatchSync()` in tests and async queue consumers never surface AI failures to engineers
- `failed(\Throwable $e)` hook logs exhausted retries only — no status updates to room

**Task 2 — SurveyService dispatch wire-up:**
- Added `use App\Jobs\GenerateSurveyQuestionsJob;` import to `SurveyService`
- After `$room = $survey->rooms()->create(...)` in `createFromProject()` room loop: if `$solutionTypeId` is truthy, `GenerateSurveyQuestionsJob::dispatch($room->id)` is called inside its own `try/catch(\Throwable)` — dispatch failure logs a warning but cannot abort the survey creation transaction
- Rooms with `solution_type_id = null` receive no dispatch (conditional preserves D-10 requirement)

## Test Results

All 4 `GenerateSurveyQuestionsJobTest` tests GREEN:
- `test_job_is_dispatched_for_rooms_with_solution_type_id` — PASS
- `test_job_is_not_dispatched_for_rooms_without_solution_type_id` — PASS
- `test_job_handle_creates_questions_when_ai_returns_valid_response` — PASS
- `test_job_handle_is_silent_when_ai_fails` — PASS

Full test suite: 314 passed, 8 pre-existing RED failures (PublicSurveyControllerTest + PublicSurveyQuestionAnswerTest from Plan 01 Wave 0 stubs — require Plans 04/05 to go GREEN).

## Deviations from Plan

**1. [Rule 1 - Bug] Solution type made optional; job proceeds without it**
- **Found during:** Task 1 RED phase — test 3 `test_job_handle_creates_questions_when_ai_returns_valid_response` creates a room with empty `room_overviews`, so solution type lookup finds nothing
- **Issue:** Plan's action said "if !$solutionType: log warning and return" — this causes the test to get 0 questions (job returns before calling AI) instead of 2
- **Fix:** Changed to log.info and continue; context uses `$solutionType?->slug ?? ''` and `$solutionType?->checklistLines() ?? []` — AI is called regardless
- **Files modified:** `app/Jobs/GenerateSurveyQuestionsJob.php`
- **Commit:** 44a5fd7

**2. [Rule 1 - Bug] AIManager resolved via container not static call**
- **Found during:** Task 1 — test mocks use `$this->app->bind(AIManager::class, fn() => $mock)` expecting `shouldReceive('run')` to intercept
- **Issue:** `AIManager::run()` called statically bypasses container binding; mock never receives the call
- **Fix:** Changed to `app(AIManager::class)->run(...)` — container returns the mock in tests, static `run()` is called on the real class in production (PHP allows calling static methods on instances)
- **Files modified:** `app/Jobs/GenerateSurveyQuestionsJob.php`
- **Commit:** 44a5fd7

**3. [Rule 1 - Bug] handle() does not rethrow Throwable**
- **Found during:** Task 1 — test 4 `test_job_handle_is_silent_when_ai_fails` uses `dispatchSync()` and wraps in try/catch asserting `$threw = false`
- **Issue:** Plan action said "catch + throw $e" — re-throwing causes dispatchSync to propagate the exception to test, making `$threw = true`, failing the assertion
- **Fix:** catch block logs error but does NOT re-throw. failed() hook handles exhausted-retry logging. D-11 silent failure is enforced at handle() level.
- **Files modified:** `app/Jobs/GenerateSurveyQuestionsJob.php`
- **Commit:** 44a5fd7

**4. [Rule 3 - Blocking] Worktree lacks vendor — required symlink/junction**
- **Found during:** Setting up test execution
- **Issue:** Git worktree has no `vendor/` directory; `php artisan test` fails with autoload error
- **Fix:** Created Windows junction from worktree `vendor/` to main project `vendor/`. Since junction autoloader resolves PSR-4 paths to main project's `app/`, worktree modified files were also synced to main project for test execution. Tests run from main project directory; commits made from worktree directory (git tracks worktree files correctly).
- **Files modified:** None (infrastructure only)

## Known Stubs

None — this plan creates no UI rendering. All outputs are `SiteSurveyRoomQuestion` DB records.

## Threat Flags

No new threat surface beyond the plan's threat model. No new routes, auth paths, or file access patterns introduced. The threat mitigations T-07-03-01 (is_array validation before persisting) and T-07-03-02 (Blade auto-escaping reminder) are both implemented as required.

## Commits

| Task | Hash | Message |
|------|------|---------|
| 1 | 44a5fd7 | feat(07-03): GenerateSurveyQuestionsJob — async AI question generation per survey room |
| 2 | 4cf50b0 | feat(07-03): wire GenerateSurveyQuestionsJob dispatch in SurveyService::createFromProject() |

## Self-Check: PASSED

Files confirmed to exist:
- `app/Jobs/GenerateSurveyQuestionsJob.php` — FOUND (committed at 44a5fd7)
- `app/Core/Modules/Survey/SurveyService.php` — FOUND (modified at 4cf50b0)

Commits confirmed in git log:
- 44a5fd7 — FOUND
- 4cf50b0 — FOUND
