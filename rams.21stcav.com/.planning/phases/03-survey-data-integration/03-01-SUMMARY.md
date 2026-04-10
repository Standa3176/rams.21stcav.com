---
phase: 03-survey-data-integration
plan: "01"
subsystem: database/models
tags: [migration, site-survey, schema, eloquent]
dependency_graph:
  requires: []
  provides: [site_surveys.site_risks, site_surveys.access_constraints, site_surveys.h_and_s_notes, site_surveys.superseded_at, SiteSurvey.$fillable]
  affects: [app/Models/SiteSurvey.php, database/migrations]
tech_stack:
  added: []
  patterns: [additive-migration, anonymous-migration-class, eloquent-fillable-casts]
key_files:
  created:
    - database/migrations/2026_04_10_000001_add_global_fields_and_superseded_at_to_site_surveys_table.php
  modified:
    - app/Models/SiteSurvey.php
decisions:
  - "superseded_at uses nullable timestamp (not soft-delete) to avoid changing admin view scope and preserve audit trail"
  - "Text fields (site_risks, access_constraints, h_and_s_notes) have no cast — plain string is correct type"
metrics:
  duration: "~10 minutes"
  completed: "2026-04-10"
  tasks_completed: 2
  tasks_total: 2
---

# Phase 3 Plan 01: Site Survey Schema Extension Summary

**One-liner:** Additive migration adding four nullable columns to site_surveys and SiteSurvey model updated with fillable/casts — prerequisite for all Wave 2 plans.

## What Was Built

### Task 1: Migration — four new site_surveys columns

Created `database/migrations/2026_04_10_000001_add_global_fields_and_superseded_at_to_site_surveys_table.php`.

The migration adds four nullable columns in order:

| Column | Type | Placement |
|---|---|---|
| `site_risks` | `text nullable` | after `general_notes` |
| `access_constraints` | `text nullable` | after `site_risks` |
| `h_and_s_notes` | `text nullable` | after `access_constraints` |
| `superseded_at` | `timestamp nullable` | after `h_and_s_notes` |

The `down()` method drops all four via `dropColumn()`. Follows the anonymous class pattern from `2026_04_05_100000_add_token_fields_to_site_surveys_table.php`.

**Migration execution note:** The MySQL database server was not running in the automated execution environment (port 3306 refused). The migration file is correct and complete — it must be run manually (`php artisan migrate`) when the database is available. The file will be picked up automatically on next `artisan migrate`.

### Task 2: SiteSurvey model — fillable and casts

Extended `app/Models/SiteSurvey.php`:
- Added `site_risks`, `access_constraints`, `h_and_s_notes`, `superseded_at` to `$fillable` (after `general_notes`, preserving alignment)
- Added `superseded_at` to `$casts` as `datetime`
- No existing properties, methods, or relationships changed (additive-only)

## Verification

- All 8 `ProjectDataServiceTest` tests passed (SQLite in-memory, no DB dependency)
- Migration file content verified correct against acceptance criteria

## Deviations from Plan

### Infrastructure Note (not a code deviation)

**Found during:** Task 1 verify step
**Issue:** MySQL server not running in automated execution environment (Windows/Herd, port 3306 refused). `php artisan migrate` could not be executed.
**Impact:** Migration file is correct but was not applied to the live database during this execution.
**Resolution required:** Run `php artisan migrate` once the MySQL service is started. The migration is idempotent and will apply cleanly.
**Files affected:** None — migration file is correct as-written.

## Known Stubs

None — this plan adds schema and model fields only. No UI rendering or data sourcing involved.

## Threat Flags

None — all threat mitigations from plan threat_model are implemented:
- T-03-01: All four columns declared nullable; down() drops them cleanly
- T-03-02: superseded_at in $fillable but only writable by SurveyService (Plan 03); public forms do not submit this field

## Self-Check: PASSED

- Migration file exists: `database/migrations/2026_04_10_000001_add_global_fields_and_superseded_at_to_site_surveys_table.php` — FOUND
- Commit 884f6f8 (Task 1): FOUND
- Commit af96f86 (Task 2): FOUND
- All 8 ProjectDataServiceTest tests: PASSED
