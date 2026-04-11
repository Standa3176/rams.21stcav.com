---
phase: 03-survey-data-integration
plan: 03
subsystem: survey
tags: [enforcement, supersede, one-survey-per-project, controller, blade, unit-test]
dependency_graph:
  requires: [03-01]
  provides: [one-survey-per-project enforcement, supersede flow, confirmation UI]
  affects: [SurveyService, SiteSurveyController, create.blade.php, SurveyServiceTest]
tech_stack:
  added: []
  patterns: [DB::transaction atomic supersede, RuntimeException service guard, abort_if ownership check]
key_files:
  created:
    - tests/Unit/SurveyServiceTest.php
  modified:
    - app/Core/Modules/Survey/SurveyService.php
    - app/Http/Controllers/SiteSurveyController.php
    - resources/views/site-survey/create.blade.php
    - database/migrations/2026_04_10_000002_backfill_project_id_on_module_tables.php
decisions:
  - "RefreshDatabase used for SurveyServiceTest (not pure mocks) because the supersede mechanism requires real DB::transaction to confirm atomic behaviour"
  - "groupBy in backfill migration corrected to use projects.user_id instead of outer table alias for SQLite compatibility"
  - "createFromProject() returns Response wrapping a View when existing survey found, avoiding a new route for the supersede GET"
metrics:
  duration: ~25 minutes
  completed: 2026-04-10
  tasks_completed: 3
  files_modified: 5
---

# Phase 03 Plan 03: One-Survey Enforcement and Supersede UI Summary

One-survey-per-project enforcement via atomic supersede mechanism in SurveyService; SiteSurveyController surfaces confirmation UI; create.blade.php renders inline alert-warning block; two passing unit tests confirm enforcement.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Add one-survey enforcement to SurveyService | a40c6f5 | SurveyService.php, migration fix |
| 2 | Update SiteSurveyController and create.blade.php | e7fc9ce | SiteSurveyController.php, create.blade.php |
| 3 | Create SurveyServiceTest.php | 5184ca3 | SurveyServiceTest.php, migration groupBy fix |

## What Was Built

**SurveyService enforcement (Task 1):**
- Private `supersedeSurvey(SiteSurvey $survey): void` helper stamps `superseded_at = now()` and logs
- `create()` checks for existing active survey before `SiteSurvey::create()` — throws `RuntimeException` if duplicate and no supersede flag; calls `supersedeSurvey()` if `$data['supersede']` is truthy
- `createFromProject()` gets `bool $supersede = false` parameter with identical enforcement logic
- Both checks happen inside `DB::transaction` so old survey archive and new survey creation are atomic

**Controller flow (Task 2):**
- `create()` detects existing active survey when `project_id` query param is present and passes `$existingSurvey` to view
- `store()` adds ownership check (`abort_if` pattern) before supersede mutation — mitigates T-03-06
- `store()` extracts `$request->boolean('supersede')` into `$data['supersede']` and selects flash message accordingly
- `createFromProject()` checks for existing survey; if found returns create view with `$existingSurvey` set; if none proceeds with direct create

**Supersede confirmation UI (Task 2):**
- `@isset($existingSurvey)` block at top of form in create.blade.php
- `.alert.alert-warning` with exact copywriting from UI-SPEC
- `.btn-danger` submit button for "Archive existing and create new survey"
- `.btn-outline` anchor link to project for "Keep existing survey"

**SurveyServiceTest (Task 3):**
- `test_create_without_supersede_flag_throws_when_active_survey_exists` — asserts `RuntimeException` with message matching `/already has an active survey/i`
- `test_create_with_supersede_flag_sets_superseded_at_on_prior_survey` — asserts existing survey `superseded_at` is not null and new survey is created
- Both tests pass: 2 passed, 6 assertions

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Fixed backfill migration groupBy for SQLite compatibility**
- **Found during:** Task 3 (test run)
- **Issue:** `whereExists` subquery used `->groupBy($table . '.user_id')` referencing the outer table alias inside a subquery — valid in MySQL but rejected by SQLite (`no such column: rams_documents.user_id`)
- **Fix:** Changed to `->groupBy('projects.user_id')` to group on the inner subquery's own column
- **Files modified:** `database/migrations/2026_04_10_000002_backfill_project_id_on_module_tables.php`
- **Commit:** 5184ca3

**2. [Rule 1 - Bug] Added required fields to Project::create() in test setUp**
- **Found during:** Task 3 (test run — second attempt after migration fix)
- **Issue:** `projects.client_name` and `projects.site_address` are NOT NULL in schema; test setUp omitted them causing integrity constraint violation
- **Fix:** Added `'client_name' => 'Test Client Ltd'` and `'site_address' => '1 Test Street, London, EC1A 1BB'` to `Project::create()` in test setUp
- **Files modified:** `tests/Unit/SurveyServiceTest.php`
- **Commit:** 5184ca3

## Test Results

```
php artisan test tests/Unit/SurveyServiceTest.php
  PASS  Tests\Unit\SurveyServiceTest
  ✓ create without supersede flag throws when active survey exists   4.05s
  ✓ create with supersede flag sets superseded at on prior survey    0.55s
  Tests: 2 passed (6 assertions)
```

Full suite: 220 passed, 38 failed. The 38 failures are pre-existing in the codebase (main repo baseline: 148 failures). This plan reduced the failure count from 148 to 38 (relative to master) — no regressions introduced.

## Known Stubs

None. All supersede logic is fully wired: service enforces, controller detects and authorises, view renders confirmation block with correct copy and button classes.

## Threat Surface Scan

No new network endpoints, auth paths, or schema changes introduced beyond the plan's scope. Threat T-03-06 (foreign project_id supersede attack) is mitigated by the `abort_if` ownership check added in `store()`. Threat T-03-07 (CSRF replay) is covered by standard `@csrf` token already present in the form.

## Self-Check: PASSED

- `app/Core/Modules/Survey/SurveyService.php` — contains `supersedeSurvey(`, `superseded_at`, `bool $supersede = false`, `whereNull('superseded_at')` FOUND
- `app/Http/Controllers/SiteSurveyController.php` — contains `existingSurvey`, `whereNull('superseded_at')`, `abort_if` in store() FOUND
- `resources/views/site-survey/create.blade.php` — contains `alert-warning`, "This project already has an active survey", "Archive existing and create new survey", "Keep existing survey", `btn-danger`, `@isset($existingSurvey)` FOUND
- `tests/Unit/SurveyServiceTest.php` — contains both test methods, 2 passed FOUND
- Commits a40c6f5, e7fc9ce, 5184ca3 FOUND in git log
