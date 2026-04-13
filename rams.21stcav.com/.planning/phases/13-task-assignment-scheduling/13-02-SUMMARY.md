---
phase: 13-task-assignment-scheduling
plan: "02"
subsystem: install-programme
tags: [frappe-gantt, schedule, week-view, gantt, alpine, field-engineer]
dependency_graph:
  requires: [13-01]
  provides: [schedule-view, gantt-timeline, week-view-calendar]
  affects: [install-programmes, install-tasks]
tech_stack:
  added: [frappe-gantt ^1.2.2]
  patterns: [ownership-guard, iso-week-grouping, deterministic-colour-map, alpine-slide-over]
key_files:
  created:
    - resources/views/install-programmes/schedule.blade.php
  modified:
    - package.json
    - package-lock.json
    - resources/js/app.js
    - app/Http/Controllers/InstallProgrammeController.php
    - routes/web.php
decisions:
  - "frappe-gantt CSS imported via direct node_modules relative path — package exports map only exposes '.' not './dist/frappe-gantt.css' as a subpath specifier"
  - "Gantt condition uses programme-level planned_start/end dates, not task dates — programme dates give stable condition regardless of individual task scheduling"
  - "Field engineer access: abort 403 only if no assigned tasks AND not owner/admin — engineers with tasks can view their filtered schedule"
metrics:
  duration_minutes: 20
  completed_date: "2026-04-13"
  tasks_completed: 3
  files_modified: 5
---

# Phase 13 Plan 02: Schedule View — Week Calendar + Gantt Summary

**One-liner:** frappe-gantt installed + schedule() controller action with INST-02g task filter + week-view Blade calendar with conditional Gantt and Alpine.js task detail slide-over.

## What Was Built

### Task 1 — Install frappe-gantt and expose via app.js (commit 85842c9)

- `npm install frappe-gantt` — v1.2.2 added to `package.json` dependencies
- `resources/js/app.js` updated: imports frappe-gantt CSS via direct node_modules relative path and exposes `window.Gantt = Gantt` after `Alpine.start()`
- `npm run build` passes — 56 modules transformed, frappe-gantt bundled into `public/build/assets/app-eJoKRWxr.js`

**Deviation (Rule 1 — Bug fix):** The plan specified `import 'frappe-gantt/dist/frappe-gantt.css'` but frappe-gantt's `exports` map only exposes `"."` — the CSS subpath specifier is not exported. Vite resolves subpath imports via the exports map, so the import failed. Fixed by using `import '../../node_modules/frappe-gantt/dist/frappe-gantt.css'` — a direct relative path that bypasses the exports map restriction.

### Task 2 — InstallProgrammeController::schedule() + route (commit af55a9e)

- `schedule()` method added after `activate()`, before `destroyTask()` in `InstallProgrammeController`
- Ownership guard: `abort_if(!$isOwnerOrAdmin && !$hasTasks, 403)` — unrelated users denied
- INST-02g: field engineers (non-owner, non-admin) receive filtered collection — only their assigned tasks
- INST-02e: `$showGantt` set true when `Carbon::parse($programme->planned_end_date)->diffInDays(...)  > 4`
- Week grouping: `Carbon::parse()->format('o-W')` produces ISO year-week keys; `sortKeys()` for stable order
- Gantt tasks mapped: `id`, `name`, `start`, `end`, `progress` (0/50/100 from status constant)
- Deterministic engineer colour map (8 Tailwind pairs, modulo on user ID)
- `Carbon` import added to controller use block
- `GET install-programmes/{programme}/schedule` route registered as `install-programmes.schedule`

### Task 3 — schedule.blade.php (commit ffe7918)

- Extends `layouts.app`, breadcrumb nav, page header with status badge
- **Section A — Unscheduled:** `@if($unscheduled->isNotEmpty())` — table with Task, Room, Engineer (colour badge), Status
- **Section B — Week-view:** `@foreach($byWeek as $isoWeekKey => $weekTasks)` — card per week with ISO week label and 6-column table (Task, Room, Engineer, Start, End, Status)
- **Section C — Gantt:** `@if($showGantt)` — frappe-gantt `#gantt-container` div with Alpine `x-data` + `x-init` initialising `new window.Gantt(...)` with `on_click` opening slide-over and `on_date_change: () => {}` (read-only, INST-02f)
- Alpine slide-over panel shows `activeTask.name`, `.start`, `.end`, `.progress` with close button and backdrop

## Commits

| Task | Description | Commit |
|------|-------------|--------|
| 1 | frappe-gantt install + window.Gantt exposed | 85842c9 |
| 2 | schedule() controller action + GET route | af55a9e |
| 3 | schedule.blade.php week-view + Gantt + slide-over | ffe7918 |

## Decisions Made

1. **frappe-gantt CSS direct import path** — The package's exports map does not expose `./dist/frappe-gantt.css` as a subpath. Using `../../node_modules/frappe-gantt/dist/frappe-gantt.css` (relative from `resources/js/`) resolves correctly with Vite.

2. **Programme-level dates for Gantt condition** — `$showGantt` is computed from `$programme->planned_start_date` / `planned_end_date`, not individual task dates. This gives a stable rendering decision that doesn't flip as individual tasks are scheduled.

3. **Field engineer 403 threshold** — Engineers with at least one assigned task can access the schedule (seeing only their tasks). Engineers with zero assigned tasks receive 403, not an empty page. This avoids confusion from a valid-looking but empty schedule.

## Security Coverage (Threat Model)

| Threat | Mitigation | Status |
|--------|-----------|--------|
| T-13-05 Field engineer sees all tasks | `where('assigned_to', auth()->id())` filter before view | Implemented |
| T-13-06 ganttTasks JSON in page source | Data filtered by INST-02g before Js::from() encoding | Accepted |
| T-13-07 Gantt on_date_change write-back | `on_date_change: () => {}` — empty, no AJAX | Implemented |
| T-13-08 Unrelated user accessing schedule | `abort_if(!$isOwnerOrAdmin && !$hasTasks, 403)` | Implemented |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] frappe-gantt CSS import path**
- **Found during:** Task 1 (npm run build)
- **Issue:** `import 'frappe-gantt/dist/frappe-gantt.css'` fails — Vite resolves subpath imports via the package `exports` map, which only defines `"."` and not `"./dist/frappe-gantt.css"` as an exported specifier.
- **Fix:** Changed to `import '../../node_modules/frappe-gantt/dist/frappe-gantt.css'` — direct file-system path bypasses exports map resolution.
- **Files modified:** `resources/js/app.js`
- **Commit:** 85842c9

## Known Stubs

None. All data flows from server-side collections to the view; no hardcoded empty values passed to rendering.

## Threat Flags

None. No new network endpoints, auth paths, or file access patterns introduced beyond the `GET` schedule route, which is covered by the threat model above.

## Self-Check

| Item | Status |
|------|--------|
| `resources/views/install-programmes/schedule.blade.php` | FOUND |
| `frappe-gantt` in `package.json` | FOUND |
| `window.Gantt` in `resources/js/app.js` | FOUND |
| `public/build/assets/app-eJoKRWxr.js` (built) | FOUND |
| `schedule()` in `InstallProgrammeController` | FOUND |
| `install-programmes.schedule` in `routes/web.php` | FOUND |
| `@if($showGantt)` in `schedule.blade.php` | FOUND |
| `on_date_change: () => {}` in `schedule.blade.php` | FOUND |
| `engineerColours[$task->assigned_to % 8]` in `schedule.blade.php` | FOUND |
| Commit 85842c9 | FOUND |
| Commit af55a9e | FOUND |
| Commit ffe7918 | FOUND |

## Self-Check: PASSED
