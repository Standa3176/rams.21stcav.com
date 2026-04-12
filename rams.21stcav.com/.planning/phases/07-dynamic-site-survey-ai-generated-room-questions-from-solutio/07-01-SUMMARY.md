---
phase: 07-dynamic-site-survey
plan: "01"
subsystem: testing
tags: [tdd, wave-0, red, contracts, site-survey, ai-questions]
dependency_graph:
  requires: []
  provides:
    - test-contracts for SiteSurveyRoomQuestion model
    - test-contracts for SurveyQuestionsPrompt prompt
    - test-contracts for GenerateSurveyQuestionsJob job
    - test-contracts for answerQuestion endpoint
    - test-contracts for completeRoom completion gate
  affects:
    - Plans 02-06 implementation (all must pass these tests)
tech_stack:
  added: []
  patterns:
    - PHPUnit RefreshDatabase for feature tests
    - Mockery container binding for AIManager mocking
    - Queue::fake() for job dispatch assertions
key_files:
  created:
    - tests/Unit/Models/SurveyRoomQuestionModelTest.php
    - tests/Unit/Prompts/SurveyQuestionsPromptTest.php
    - tests/Feature/Jobs/GenerateSurveyQuestionsJobTest.php
    - tests/Feature/PublicSurveyQuestionAnswerTest.php
    - tests/Feature/PublicSurveyControllerTest.php
  modified: []
decisions:
  - Tests reference production class names directly (not markTestIncomplete pattern) — class-not-found errors count as RED per plan instructions
  - PublicSurveyControllerTest created from scratch (no existing file)
  - dispatchSync() used for synchronous job execution in handle() tests
  - route('survey.question.answer') referenced in answer tests — will 404 until Plan 04 registers route
metrics:
  duration_minutes: 25
  completed_date: "2026-04-12"
  tasks_completed: 2
  tasks_total: 2
  files_created: 5
  files_modified: 0
---

# Phase 07 Plan 01: Wave 0 — Failing Test Stubs Summary

**One-liner:** TDD Wave 0 — 5 failing test files defining contracts for all Phase 7 production classes before any implementation exists.

## What Was Built

Five test stub files covering the full Phase 7 contract surface:

**Task 1 — Model, Prompt, and Job tests:**
- `SurveyRoomQuestionModelTest` (4 tests) — fillable fields, sort_order cast, belongsTo relationship, inline DB create
- `SurveyQuestionsPromptTest` (5 tests) — systemMessage content, maxTokens=1024, temperature=0.2, build() output shape
- `GenerateSurveyQuestionsJobTest` (4 tests) — job dispatch on solution_type_id, no dispatch without, handle creates questions, silent on AI failure

**Task 2 — Answer endpoint and completion gate tests:**
- `PublicSurveyQuestionAnswerTest` (6 tests) — yes/no/other answers, other_text, cross-room 403, other_text length, submitted survey 403
- `PublicSurveyControllerTest` (4 tests) — 422 on unanswered, blocked+pre-install in body, 200 all answered, 200 with no questions

## RED State Confirmed

All 22 new tests fail:
- Unit model and prompt tests: `Class "App\Models\SiteSurveyRoomQuestion" not found`
- Unit prompt tests: `Class "App\Core\AI\Prompts\SurveyQuestionsPrompt" not found`
- Job feature tests: `Class "App\Jobs\GenerateSurveyQuestionsJob" not found`
- Answer endpoint tests: `Class "App\Models\SiteSurveyRoomQuestion" not found` (in setUp)
- Completion gate tests: `Class "App\Models\SiteSurveyRoomQuestion" not found` / missing app key

Total: 22 failed, 1 warning, 0 passed — Wave 0 RED confirmed.

## Deviations from Plan

None — plan executed exactly as written.

## Commits

| Task | Hash | Message |
|------|------|---------|
| 1 | c65a5b5 | test(07-01): add RED contract stubs for model, prompt, and job |
| 2 | e182b0e | test(07-01): add RED contract stubs for answer endpoint and completion gate |

## Self-Check: PASSED

All 5 test files confirmed to exist at specified paths. All commits confirmed in git log.
