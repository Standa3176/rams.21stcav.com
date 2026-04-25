---
phase: 12-install-task-generation
plan: "01"
subsystem: data-layer
tags: [migrations, eloquent, install-programme, install-task, project-model]
dependency_graph:
  requires: []
  provides:
    - install_programmes table schema
    - install_tasks table schema
    - InstallProgramme Eloquent model
    - InstallTask Eloquent model
    - Project::installProgrammes() relationship
    - Project::activeInstallProgramme() relationship
  affects:
    - app/Models/Project.php
tech_stack:
  added: []
  patterns:
    - Laravel Eloquent SoftDeletes on both new models
    - STATUS_* constants pattern (matching Worksheet/OmManual convention)
    - HasMany ordered by sort_order (tasks) and created_at desc (programmes)
    - Denormalised room_name string (intentional — no FK to site_survey_rooms)
key_files:
  created:
    - database/migrations/2026_04_14_000001_create_install_programmes_table.php
    - database/migrations/2026_04_14_000002_create_install_tasks_table.php
    - app/Models/InstallProgramme.php
    - app/Models/InstallTask.php
  modified:
    - app/Models/Project.php
decisions:
  - "room_name denormalised (not FK) by design — ProjectDataService resolves from reviewed_data; IDs may not match survey room names exactly (T-12-01)"
  - "cascadeOnDelete on install_tasks.install_programme_id — deleting a programme removes all its tasks"
  - "activeInstallProgramme() uses latestOfMany() to return the most recently activated programme with STATUS_ACTIVE"
metrics:
  duration_minutes: 15
  completed_date: "2026-04-13"
  tasks_completed: 2
  tasks_total: 2
  files_created: 4
  files_modified: 1
---

# Phase 12 Plan 01: Install Programme & Task Data Layer Summary

Two migrations and two Eloquent models establishing the data foundation for the v1.2 Installation Programme module: `install_programmes` (one generation run per project) and `install_tasks` (room × equipment work items), with Project model wired to both.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Create install_programmes and install_tasks migrations | 85882fc | 2026_04_14_000001_create_install_programmes_table.php, 2026_04_14_000002_create_install_tasks_table.php |
| 2 | Create InstallProgramme and InstallTask models; add Project relationships | aaeb291 | app/Models/InstallProgramme.php, app/Models/InstallTask.php, app/Models/Project.php |

## What Was Built

### Migrations

**`install_programmes`** — tracks one generation run per project:
- `project_id` (nullable FK, nullOnDelete), `generated_by` (nullable FK to users, nullOnDelete)
- `status` (draft/active/complete/archived), `generated_at`, `activated_at`, `completed_at`
- `planned_start_date`, `planned_end_date`, `notes`
- Indexes on `project_id` and `status`; soft deletes

**`install_tasks`** — individual work items within a programme:
- `install_programme_id` (FK, cascadeOnDelete)
- `room_name` (denormalised string, 200), `room_ref` (nullable), `equipment_name` (300)
- `equipment_category` (default: hardware), `task_type` (install/configure/cable/test/commission)
- `title` (500), `description`, `status` (pending/in_progress/complete/blocked/skipped)
- `blocked_reason`, `sort_order`, `notes`
- `assigned_to` (nullable FK to users, nullOnDelete), `assigned_at`, `started_at`, `completed_at`
- `sign_off_required` (boolean, default true)
- Indexes on `install_programme_id`, `(room_name, sort_order)`, `status`, `assigned_to`; soft deletes

### Models

**`InstallProgramme`** — STATUS_DRAFT/ACTIVE/COMPLETE/ARCHIVED constants, fillable, datetime/date casts, relationships (project, generatedBy, tasks ordered by sort_order), statusLabel(), statusBadgeClass(), isDraft(), isActive() helpers.

**`InstallTask`** — STATUS_PENDING/IN_PROGRESS/COMPLETE/BLOCKED/SKIPPED + TYPE_INSTALL/CONFIGURE/CABLE/TEST/COMMISSION constants, fillable, datetime/boolean/integer casts, relationships (programme, assignedUser), statusLabel(), isPending(), isComplete() helpers.

**`Project`** — Two new relationships added after `worksheets()`:
- `installProgrammes()` — hasMany ordered by `created_at` desc
- `activeInstallProgramme()` — hasOne where status = active, latestOfMany()

## Verification

- `php artisan migrate --pretend` output confirmed both tables and all indexes without errors
- `php artisan migrate` ran cleanly (DONE in 29ms and 45ms respectively)
- Tinker confirmed: `InstallProgramme::STATUS_DRAFT` = `'draft'`, `InstallTask::STATUS_PENDING` = `'pending'`
- `InstallProgramme::count()` = 0 (no errors), Project::installProgrammes relationship method exists

## Deviations from Plan

None — plan executed exactly as written.

## Known Stubs

None — this plan delivers schema and model contracts only. No UI or service layer wired in this plan; those are delivered in Plans 02+.

## Threat Flags

None — no new network endpoints, auth paths, or file access patterns introduced. The T-12-02 mitigation (ownership scope in controller) is documented for Plan 02 to implement.

## Self-Check: PASSED

- database/migrations/2026_04_14_000001_create_install_programmes_table.php: FOUND
- database/migrations/2026_04_14_000002_create_install_tasks_table.php: FOUND
- app/Models/InstallProgramme.php: FOUND
- app/Models/InstallTask.php: FOUND
- app/Models/Project.php (modified): FOUND
- Commit 85882fc (Task 1): FOUND
- Commit aaeb291 (Task 2): FOUND
