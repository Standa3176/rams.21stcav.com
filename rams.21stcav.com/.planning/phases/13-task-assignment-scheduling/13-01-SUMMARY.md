---
phase: 13-task-assignment-scheduling
plan: "01"
subsystem: install-programme
tags: [task-assignment, bulk-assignment, planned-dates, install-tasks]
dependency_graph:
  requires: [12-01, 12-02]
  provides: [task-assignment-api, bulk-assignment-api, per-task-planned-dates]
  affects: [install-tasks, install-programmes]
tech_stack:
  added: []
  patterns: [ownership-guard, constructor-injection, json-response, db-transaction]
key_files:
  created:
    - database/migrations/2026_04_14_000004_add_planned_dates_to_install_tasks_table.php
    - app/Services/TaskAssignmentService.php
    - app/Http/Controllers/TaskAssignmentController.php
  modified:
    - app/Models/InstallTask.php
    - routes/web.php
decisions:
  - "assigned_to column retained (not renamed to assigned_user_id) to preserve Phase 12 assignedUser() relationship"
  - "bulkAssignRoom uses per-model save() to fire Eloquent events; bulkAssignProgramme uses mass-update for performance"
  - "All assignment endpoints return JSON to allow Blade views to update without page reload"
metrics:
  duration_minutes: 15
  completed_date: "2026-04-13"
  tasks_completed: 3
  files_modified: 5
---

# Phase 13 Plan 01: Task Assignment Service and Routes Summary

**One-liner:** Per-task planned date migration + TaskAssignmentService (single/bulk) + TaskAssignmentController with JSON responses and ownership guards on 3 new POST routes.

## What Was Built

Added engineer assignment capability to install tasks across three layers:

1. **Migration** (`2026_04_14_000004_add_planned_dates_to_install_tasks_table.php`) — adds `planned_start_date` and `planned_end_date` (nullable `date`) columns to `install_tasks` after `sign_off_required`. Down method drops both columns.

2. **InstallTask model** — `planned_start_date` and `planned_end_date` added to `$fillable` and `casts()` array with `'date'` cast.

3. **TaskAssignmentService** (`app/Services/TaskAssignmentService.php`) — three public methods:
   - `assignTask(InstallTask, ?int, ?string, ?string)` — assigns a single task with optional planned dates
   - `bulkAssignRoom(InstallProgramme, string, ?int)` — bulk assigns all tasks in a room (by `room_name`) in a DB transaction; uses per-model `save()` to fire Eloquent events
   - `bulkAssignProgramme(InstallProgramme, ?int)` — bulk assigns all tasks in a programme using mass-update in a DB transaction

4. **TaskAssignmentController** (`app/Http/Controllers/TaskAssignmentController.php`) — three JSON-returning actions with ownership guards and `exists:users,id` validation:
   - `assign()` — single task assignment with optional planned dates
   - `assignRoom()` — bulk room assignment
   - `assignAll()` — bulk programme assignment

5. **Routes** (`routes/web.php`) — 3 new POST routes under `auth` middleware:
   - `POST install-tasks/{task}/assign` → `install-tasks.assign`
   - `POST install-programmes/{programme}/assign-room` → `install-programmes.assign-room`
   - `POST install-programmes/{programme}/assign-all` → `install-programmes.assign-all`

## Commits

| Task | Description | Commit |
|------|-------------|--------|
| 1 | Migration + model update (planned dates) | 9548bb9 |
| 2 | TaskAssignmentService | 9b07722 |
| 3 | TaskAssignmentController + 3 routes | eb4b0db |

## Decisions Made

1. **No column rename** — INST-02a spec uses `assigned_user_id` but Phase 12 created `assigned_to`. The existing column was retained to avoid breaking the `assignedUser()` relationship on `InstallTask`. This is documented as a known naming deviation per the plan objective.

2. **bulkAssignRoom uses per-model save()** vs mass-update — preserves Eloquent model events (timestamps, observers) for individual task rows. `bulkAssignProgramme` uses mass-update for larger-scale operations where event firing per row is less critical.

3. **JSON responses** — all three controller actions return `JsonResponse` so Blade views can update assignment state without full page reload.

## Security Coverage (Threat Model)

| Threat | Mitigation | Status |
|--------|-----------|--------|
| T-13-01 Spoofing assign endpoint | `abort_if` ownership guard via task→programme→project→user_id | Implemented |
| T-13-02 Tampering user_id | `exists:users,id` validation on all 3 endpoints | Implemented |
| T-13-03 Elevation via bulk assign | `abort_if` ownership guard on programme→project before bulk update | Implemented |
| T-13-04 Route model binding disclosure | All routes behind `auth` middleware, ownership guard blocks cross-project | Accepted |

## Deviations from Plan

None — plan executed exactly as written. The `assigned_to` column naming deviation was pre-documented in the plan objective and is not a deviation.

## Known Stubs

None. No data flows to UI rendering — this plan adds backend API only (no Blade views).

## Self-Check

| Item | Status |
|------|--------|
| `database/migrations/2026_04_14_000004_add_planned_dates_to_install_tasks_table.php` | FOUND |
| `app/Services/TaskAssignmentService.php` | FOUND |
| `app/Http/Controllers/TaskAssignmentController.php` | FOUND |
| Commit 9548bb9 | FOUND |
| Commit 9b07722 | FOUND |
| Commit eb4b0db | FOUND |
| `planned_start_date` in InstallTask $fillable | FOUND |
| `planned_start_date` in InstallTask casts | FOUND |
| 3 assignment routes in web.php | FOUND |

## Self-Check: PASSED
