---
phase: 04-document-generators
plan: "04"
subsystem: project-ui
tags: [linked-records, generate-buttons, alpine-polling, worksheet, om-manual, cable-schedule]
dependency_graph:
  requires: [04-01, 04-02, 04-03]
  provides: [project-show-generate-ui]
  affects: [projects/show.blade.php, ProjectController, Project model]
tech_stack:
  added: []
  patterns: [three-state-generate-button, alpine-polling, csrf-post-form]
key_files:
  created: []
  modified:
    - app/Models/Project.php
    - app/Http/Controllers/ProjectController.php
    - resources/views/projects/show.blade.php
decisions:
  - "Three-state button (Generate → Generating spinner → Download) applied uniformly to all three generate-capable types via $entry['generate_route'] presence check"
  - "Legacy GET-link branch (@elseif empty_action_route) retained for RAMS and Survey — documented with explicit comments in the blade"
  - "Alpine.js polling uses window.location.reload() on draft/final/failed — simpler than DOM manipulation"
  - "Download button added to records table rows when $record->filename is set — serves existing records as well as newly generated ones"
metrics:
  duration_minutes: 15
  completed: "2026-04-11T08:49:57Z"
  tasks_completed: 2
  tasks_total: 2
  files_modified: 3
---

# Phase 04 Plan 04: Project Show Page Generate Buttons Summary

Wire all three document-type generate buttons on the project show page linked records card. Remove the "Coming in Phase 4." placeholder. Implement the three-state Alpine.js UX (Generate POST form → Generating spinner with polling → Download) per UI-SPEC.md Surface 1 for Worksheet, O&M Manual, and Cable Schedule.

## Tasks Completed

| # | Name | Commit | Files |
|---|------|--------|-------|
| 1 | Update ProjectController::show() and Project model | 532f9e2 | app/Models/Project.php, app/Http/Controllers/ProjectController.php |
| 2 | Wire three-state generate buttons in projects/show.blade.php | c0e6ebe | resources/views/projects/show.blade.php |

## What Was Built

### Task 1 — ProjectController::show() + Project model

- Added `worksheets(): HasMany` relationship to `app/Models/Project.php` pointing to `\App\Models\Worksheet::class`
- Added `'worksheets' => fn ($q) => $q->latest()->limit(5)` to the eager-load call in `ProjectController::show()`
- Replaced the Worksheet placeholder entry (collect(), null routes) with real routes: `worksheets.generate-from-project`, `worksheets.status`, `worksheets.download`, `worksheets.show`
- Updated O&M entry: replaced GET `empty_action_route` with `generate_route` POST pattern; added `status_route_name = 'om-manuals.status'`, `download_route_name = 'om-manuals.download'`
- Updated Cable Schedule entry: replaced GET `empty_action_route` with `generate_route` POST pattern; added `status_route_name = 'cable-schedules.status'`, `download_route_name = 'cable-schedules.download'`

### Task 2 — projects/show.blade.php

- Removed `@if($entry['type'] === 'Worksheet') Coming in Phase 4. @else ... @endif` block entirely
- Replaced the entire empty-state block with a unified three-state generate button pattern driven by `$entry['generate_route']`:
  - **State 1** — No record or `status = failed`: POST form with `.btn-teal.btn-sm` "Generate {type}" button, includes `@csrf`
  - **State 2** — `status = pending|generating`: Disabled spinner button with Alpine.js `x-init="startPolling()"` polling `status_route_name` every 4 seconds; reloads page when draft/final/failed
  - **State 3** — `status = draft|final`: "↓ Download" `.btn-teal.btn-sm` + "View" `.btn-outline.btn-sm`
- Legacy `@elseif(!empty($entry['empty_action_route']))` branch retained and documented for RAMS and Survey (GET links)
- Added download button to records table actions column for rows where `$record->filename` is set
- Added `@keyframes spin` to the existing `<style>` block at the bottom of the page

## Verification

- "Coming in Phase 4." — grep returns 0 matches
- `@keyframes spin` — present at line 1102
- `generate_route`, `download_route_name`, `Generating…` — all present in blade
- `worksheets()` HasMany — confirmed in Project model at line 172
- All eager-loads and $linkedRecords entries verified by grep

## Deviations from Plan

None — plan executed exactly as written.

## Known Stubs

None. All three generate routes reference real route names registered by Plans 04-01, 04-02, and 04-03. The polling fetch URLs resolve via `route()` helper at render time — no stubs or hardcoded values.

## Threat Flags

No new threat surface introduced. All generate forms include `@csrf` (T-04-04-01 mitigated). Status polling routes have ownership enforcement in Plans 04-01/02/03 (T-04-04-02 mitigated). Route model binding enforces project ownership (T-04-04-03 accepted per plan).

## Self-Check: PASSED

- `app/Models/Project.php` — worksheets() relationship present (line 172)
- `app/Http/Controllers/ProjectController.php` — worksheets eager-load and all three linked record entries updated
- `resources/views/projects/show.blade.php` — "Coming in Phase 4." gone, three-state buttons present, @keyframes spin defined
- Commits 532f9e2 and c0e6ebe both exist in git log
