---
phase: 01-project-layer-data-foundation
plan: 03
subsystem: data-merge
tags: [service, data-merge, tdd, read-only, confidence-tracking]
dependency_graph:
  requires:
    - "ProjectPackage model (extracted_data, reviewed_data fields)"
    - "Project model (latestPackage, siteSurveys relations)"
    - "SiteSurvey model (status, room_data fields)"
  provides:
    - "ProjectDataService::resolve(Project): array — canonical 9-key dataset"
    - "ProjectDataService::isLowConfidence(float): bool — threshold check"
    - "ProjectDataService::CONFIDENCE_THRESHOLD — 0.7 constant"
  affects:
    - "All future document generators (consume resolve() output)"
    - "AppServiceProvider (singleton registration)"
tech_stack:
  added: []
  patterns:
    - "Anonymous class stubs extending Eloquent models for unit test isolation"
    - "Merge priority chain: reviewed_data > quotewerks_sql > extracted_data > defaults"
    - "Per-item data_source + confidence annotation on all collection items"
key_files:
  created:
    - "app/Core/Modules/Projects/ProjectDataService.php"
    - "tests/Unit/ProjectDataServiceTest.php"
  modified:
    - "app/Providers/AppServiceProvider.php"
decisions:
  - "Used string literal 'completed' instead of SiteSurvey::STATUS_COMPLETED (constant not defined on model)"
  - "Anonymous class stubs chosen over Mockery for Project mocking (avoids Eloquent __set/setAttribute conflict)"
  - "SiteSurvey import removed from service (no constant reference needed after fix)"
  - "Phase 1 survey-rooms merge is exact name match only; fuzzy merge deferred to Phase 3 (D-26)"
metrics:
  duration: "12 minutes"
  completed_date: "2026-04-10"
  tasks_completed: 2
  files_changed: 3
requirements:
  - DATA-01
  - DATA-02
  - DATA-04
  - DATA-05
---

# Phase 1 Plan 03: ProjectDataService Canonical Data Merge — Summary

**One-liner:** Read-only canonical data merge service with 4-tier priority chain (reviewed_data/manual/1.0 > quotewerks_sql/0.95 > extracted_data/pdf/0.85 > defaults/0.0) and per-item confidence annotation, backed by 8 passing unit tests.

## What Was Built

`ProjectDataService` is the central data merge point for the RAMS platform. Every downstream document generator will call `resolve(Project $project)` to get a unified, annotated dataset — eliminating direct access to raw `extracted_data`, `reviewed_data`, or survey tables from generator code.

### resolve() output shape

```php
[
  'project'    => ['id', 'name', 'client_name', 'site_address', 'quote_reference', 'status', 'created_at'],
  'equipment'  => [['name', ..., 'data_source', 'confidence'], ...],
  'rooms'      => [['name', ..., 'data_source', 'confidence'], ...],  // survey-enriched
  'activities' => [['description', ..., 'data_source', 'confidence'], ...],
  'risks'      => [['hazard', ..., 'data_source', 'confidence'], ...],
  'survey'     => ['has_survey', 'submitted_at', 'rooms'],
  'programme'  => [...],
  'cables'     => [...],
  'meta'       => ['data_source', 'has_survey', 'survey_complete', 'confidence'],
]
```

### Merge priority (DATA-05)

| Tier | Condition | data_source | confidence |
|------|-----------|-------------|------------|
| 1 | `reviewed_data !== null` | `manual` | `1.0` |
| 2 | `extracted_data.meta.source === 'quotewerks_sql'` | `quotewerks_sql` | `0.95` |
| 3 | `extracted_data` present (PDF) | `pdf` | `0.85` |
| 4 | No package | `defaults` | `0.0` |

Survey data enriches rooms only (exact name match, Phase 1 stub).

## Test Results

All 8 tests pass (34 assertions):

| # | Test | Status |
|---|------|--------|
| 1 | `test_resolve_returns_canonical_keys` | PASS |
| 2 | `test_resolve_uses_reviewed_data_over_extracted` | PASS |
| 3 | `test_resolve_falls_back_to_extracted_data` | PASS |
| 4 | `test_resolve_equipment_items_have_annotation` | PASS |
| 5 | `test_resolve_never_throws_without_package` | PASS |
| 6 | `test_resolve_never_writes_to_database` | PASS |
| 7 | `test_is_low_confidence_threshold` | PASS |
| 8 | `test_resolve_meta_has_survey_flag` | PASS |

Full suite: 7 pre-existing failures (in `MethodStatementFallbackTest` and `QuoteParserServiceTest`) are unrelated to this plan — confirmed present in main repo before this work.

## Commits

| Hash | Type | Description |
|------|------|-------------|
| `e16f9ed` | test | RED: 8 failing unit tests for ProjectDataService contract |
| `d8d1d83` | feat | GREEN: ProjectDataService implementation + AppServiceProvider singleton |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] SiteSurvey::STATUS_COMPLETED constant does not exist**
- **Found during:** GREEN phase (test run)
- **Issue:** The plan's implementation template used `SiteSurvey::STATUS_COMPLETED ?? 'completed'` but PHP's null coalesce operator does not catch undefined constants — this throws an `Error` at runtime
- **Fix:** Replaced with the string literal `'completed'` and removed the `SiteSurvey` use import (no longer needed)
- **Files modified:** `app/Core/Modules/Projects/ProjectDataService.php`
- **Commit:** `d8d1d83`

**2. [Rule 1 - Bug] Mockery mock of Eloquent model rejects property assignment**
- **Found during:** GREEN phase (test run)
- **Issue:** Setting `$project->latestPackage = $package` on a Mockery mock of an Eloquent model triggers `__set()` → `setAttribute()` which Mockery didn't expect, causing `BadMethodCallException`
- **Fix:** Replaced Mockery mocks with anonymous class stubs extending `Project`, overriding `__get()` and `relationLoaded()` to return pre-loaded test data without touching Eloquent internals
- **Files modified:** `tests/Unit/ProjectDataServiceTest.php`
- **Commit:** `d8d1d83`

## Known Stubs

**Survey-rooms fuzzy merge** — `mergeSurveyRooms()` in `ProjectDataService.php` uses exact name matching only. The plan notes this is a Phase 1 stub; Phase 3 will implement fuzzy matching (D-26). This does NOT prevent the plan's goal (DATA-01, DATA-02, DATA-04, DATA-05 all satisfied by exact-match).

## Threat Surface Scan

No new trust boundaries introduced beyond those documented in the plan's threat model. `resolve()` confirmed zero DB writes (test 6 verifies). No new network endpoints, auth paths, or schema changes.

## Self-Check

Files created/modified:
- `app/Core/Modules/Projects/ProjectDataService.php` — EXISTS
- `tests/Unit/ProjectDataServiceTest.php` — EXISTS
- `app/Providers/AppServiceProvider.php` — EXISTS (modified)

Commits:
- `e16f9ed` — EXISTS
- `d8d1d83` — EXISTS

## Self-Check: PASSED
