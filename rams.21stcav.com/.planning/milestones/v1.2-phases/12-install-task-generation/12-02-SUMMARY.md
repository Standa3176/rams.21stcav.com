---
phase: 12-install-task-generation
plan: "02"
subsystem: service-layer
tags: [install-programme, task-generation, controller, routes, blade-view, tdd]
dependency_graph:
  requires:
    - install_programmes table schema (12-01)
    - install_tasks table schema (12-01)
    - InstallProgramme model (12-01)
    - InstallTask model (12-01)
    - Project::installProgrammes() relationship (12-01)
    - ProjectDataService::resolve() (existing)
  provides:
    - InstallTaskGeneratorService::generate() and filterHardware()
    - InstallProgrammeService::createForProject(), activate(), archiveExisting()
    - InstallProgrammeController (generate, review, activate, destroyTask)
    - 4 routes: install-programmes.generate/review/activate, install-tasks.destroy
    - resources/views/install-programmes/review.blade.php
    - ProjectController linked records entry for Install Programme
  affects:
    - app/Http/Controllers/ProjectController.php
    - routes/web.php
    - app/Models/Project.php (HasFactory added)
tech_stack:
  added: []
  patterns:
    - TDD red-green cycle for service layer
    - Synchronous task generation (no AI, no queue) — < 1s for any real project
    - Hardware filter pattern replicated from WorksheetGeneratorService (deliberate duplication)
    - DB::transaction wrapping all task inserts
    - abort_if ownership guard on every controller action (matching WorksheetController)
    - Transitive ownership check for destroyTask (task → programme → project)
    - LogicException guard in activate() when programme is not draft
key_files:
  created:
    - app/Services/InstallTaskGeneratorService.php
    - app/Services/InstallProgrammeService.php
    - app/Http/Controllers/InstallProgrammeController.php
    - resources/views/install-programmes/review.blade.php
    - tests/Unit/InstallTaskGeneratorServiceTest.php
    - database/factories/ProjectFactory.php
  modified:
    - routes/web.php
    - app/Http/Controllers/ProjectController.php
    - app/Models/Project.php
decisions:
  - "filterHardware() is public on InstallTaskGeneratorService (not private) — enables direct unit testing without DB"
  - "ProjectFactory created with HasFactory on Project model — needed for RefreshDatabase tests"
  - "review() loads programme->project before the abort_if check to resolve ownership correctly"
  - "installProgrammes eager-loaded in ProjectController::show() to prevent N+1 on project show page"
metrics:
  duration_minutes: 35
  completed_date: "2026-04-13"
  tasks_completed: 2
  tasks_total: 2
  files_created: 6
  files_modified: 3
---

# Phase 12 Plan 02: Task Generation Service, Controller, and Review UI Summary

Full INST-01a/b/e/f/g loop: synchronous task generation from ProjectDataService, draft review page with per-task delete, activate button, and re-generation archiving. No AI call, no queue — generates in under 1 second.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | InstallTaskGeneratorService and InstallProgrammeService | cb4d8e9 | InstallTaskGeneratorService.php, InstallProgrammeService.php, InstallTaskGeneratorServiceTest.php, ProjectFactory.php, Project.php |
| 2 | InstallProgrammeController, routes, and review UI | dfbd382 | InstallProgrammeController.php, web.php, review.blade.php, ProjectController.php |

## What Was Built

### InstallTaskGeneratorService

Direct analogue to WorksheetGeneratorService. Constructor-injects `ProjectDataService`. Reads exclusively from `projectDataService->resolve()` — never touches `extracted_data` directly.

- `filterHardware(array $items): array` — excludes by EXCLUDED_CATEGORIES (cables/consumables/services/option) and EXCLUDED_KEYWORDS fallback when category is blank
- `generate(InstallProgramme $programme): void` — wraps all task inserts in `DB::transaction()`, sets title as "Install {equipment_name}", sort_order as `($roomIndex * 100) + $itemIndex`

### InstallProgrammeService

High-level orchestration:
- `createForProject(Project, User): InstallProgramme` — archives existing, creates draft, calls `generator->generate()`
- `activate(InstallProgramme): void` — validates status=draft (throws `\LogicException` if not), sets status=active + activated_at=now()
- `archiveExisting(Project): void` — sets status=archived on all draft/active programmes for the project

### InstallProgrammeController

Thin controller, WorksheetController pattern:
- `generate(Project)` — ownership guard → `service->createForProject()` → redirect to review
- `review(InstallProgramme)` — ownership guard → loads tasks+project → renders review view
- `activate(InstallProgramme)` — ownership guard → `service->activate()` → redirect to project show
- `destroyTask(InstallTask)` — transitive ownership guard (task→programme→project) → soft-delete → redirect back

### Routes (4 registered)

```
POST   projects/{project}/install-programme/generate  install-programmes.generate
GET    install-programmes/{programme}/review           install-programmes.review
POST   install-programmes/{programme}/activate         install-programmes.activate
DELETE install-tasks/{task}                            install-tasks.destroy
```

### review.blade.php

Static Blade view (no Alpine.js). Receives `$programme` with tasks and project eager-loaded. Tasks grouped by `room_name` via `$programme->tasks->groupBy('room_name')`. Each room rendered as a card with a table (Title | Equipment | Category | Status | Remove). Remove button is a DELETE form. Activate Programme button shown only when `$programme->isDraft()` and task count > 0. Zero-task warning shown when no tasks generated.

### ProjectController changes

- `installProgrammes` added to `$project->load([...])` eager load
- `Install Programme` entry added to `$linkedRecords` array after Cable Schedule, with `generate_route` and `generate_label` — renders "Generate Install Programme" POST button in the project show page linked records card

### Unit Tests (12 passing)

- 5 `filterHardware()` tests: empty input, hardware passes, cables excluded by category, keyword excluded by name, non-excluded blank category passes
- 3 `generate()` tests: correct task count (4 from 2 rooms × 3 items with 2 excluded each), room_name/title/sort_order correctness, no jobs dispatched
- 2 `activate()` tests: status+activated_at set, LogicException when not draft
- 1 `archiveExisting()` test: draft+active archived, complete untouched
- 1 `createForProject()` test: draft created with task

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Infrastructure] Added ProjectFactory and HasFactory to Project model**
- **Found during:** Task 1 — tests using `Project::factory()` failed with "Call to undefined method"
- **Issue:** Project model lacked `HasFactory` trait and no `ProjectFactory` existed
- **Fix:** Created `database/factories/ProjectFactory.php` with `user_id`, `name`, `client_name`, `site_address`, `quote_reference`, `status` fields. Added `use HasFactory` to `app/Models/Project.php`.
- **Files modified:** `database/factories/ProjectFactory.php` (new), `app/Models/Project.php`
- **Commit:** cb4d8e9

## Known Stubs

None — all data flows from ProjectDataService through to rendered tasks. The review view renders real task data, not placeholder content.

## Threat Flags

No new threat surface beyond what was planned in the plan's threat model. All 4 STRIDE mitigations (T-12-04 through T-12-08) implemented as specified:
- T-12-04: `abort_if` ownership guard on all 4 controller actions
- T-12-05: Transitive ownership check in `destroyTask` (task→programme→project)
- T-12-06: `archiveExisting()` called before each `createForProject()`
- T-12-07: All routes behind `auth` middleware
- T-12-08: `Log::info` with programme_id, user_id, activated_at in `activate()`

## Self-Check: PASSED

- app/Services/InstallTaskGeneratorService.php: FOUND
- app/Services/InstallProgrammeService.php: FOUND
- app/Http/Controllers/InstallProgrammeController.php: FOUND
- resources/views/install-programmes/review.blade.php: FOUND
- tests/Unit/InstallTaskGeneratorServiceTest.php: FOUND
- database/factories/ProjectFactory.php: FOUND
- Commit cb4d8e9 (Task 1): FOUND
- Commit dfbd382 (Task 2): FOUND
- 4 routes registered (php artisan route:list --name=install): CONFIRMED
- 12 unit tests passing: CONFIRMED
