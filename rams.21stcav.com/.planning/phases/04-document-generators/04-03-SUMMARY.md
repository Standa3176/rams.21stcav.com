---
phase: 04-document-generators
plan: 03
subsystem: cable-schedule-generator
tags: [cable-schedule, generator, queue, xlsx, project-data]
dependency_graph:
  requires:
    - 01-project-layer-data-foundation (ProjectDataService)
    - 03-survey-data-integration (cable_route_desc in rooms)
  provides:
    - CableSchedule model with project_id, filename, status constants
    - CableScheduleGeneratorService (deterministic equipment → cable items)
    - BuildCableScheduleJob (async generator + xlsx pipeline)
    - cable-schedules.generate-from-project route
    - cable-schedules.status JSON polling endpoint
    - cable-schedules.download file download endpoint
  affects:
    - CableScheduleController (3 new methods)
    - routes/web.php (3 new routes before resource)
tech_stack:
  added: []
  patterns:
    - BuildOmManualJob pattern (tries=2, handle/failed hooks)
    - ProjectDataService canonical data consumer
    - Storage::disk('local') for file serving
    - route ordering: literal segments before Route::resource wildcard
key_files:
  created:
    - app/Services/CableScheduleGeneratorService.php
    - app/Jobs/BuildCableScheduleJob.php
  modified:
    - app/Models/CableSchedule.php
    - app/Http/Controllers/CableScheduleController.php
    - routes/web.php
decisions:
  - "CableScheduleXlsxService::build() updates filename on model but not status — BuildCableScheduleJob sets STATUS_DRAFT explicitly after build()"
  - "Cable type inference is deterministic keyword matching — no AI. SKIP_KEYWORDS list filters non-hardware line items."
  - "generate-from-project route registered as POST before Route::resource to prevent {cableSchedule} wildcard from capturing 'generate-from-project' literal"
  - "error_message added to CableSchedule::$fillable along with project_id and filename (Rule 2 — needed for failed() hook)"
metrics:
  duration: "~8 minutes"
  completed: "2026-04-11"
  tasks_completed: 2
  tasks_total: 2
  files_created: 2
  files_modified: 3
---

# Phase 04 Plan 03: Cable Schedule Generator Pipeline — Summary

**One-liner:** Deterministic ProjectDataService → CableScheduleItem pipeline with async BuildCableScheduleJob, XLSX export, and JSON status polling — no AI involved.

## What Was Built

### Task 1: Model fix + Generator + Job

**app/Models/CableSchedule.php** — Critical bug fix: `project_id` and `filename` were missing from `$fillable`, silently discarding those fields on `CableSchedule::create()`. Also added `error_message` to `$fillable` (required by the `failed()` hook). Added five STATUS_* constants (`pending`, `generating`, `draft`, `final`, `failed`) and a `project(): BelongsTo` relationship.

**app/Services/CableScheduleGeneratorService.php** — New service. Reads `ProjectDataService::resolve($project)` rooms and equipment, applies `inferCableType()` keyword matching, and creates `CableScheduleItem` records. Non-hardware categories (cables, consumables, services, mounts, rack, infrastructure, install, commission) return `null` from `inferCableType()` and are skipped. Hardware maps deterministically:

| Category keywords | cable_type | cores |
|-------------------|------------|-------|
| display, screen, monitor, tv, projector | HDMI 2.0 | null |
| speaker, loudspeaker | 2-Core Speaker Cable | 2 |
| amplifier, dsp, audio | Audio Multicore | null |
| camera, vc, video conferencing | Cat6 | null |
| switch, network, access point, wifi | Cat6 | null |
| control, controller, crestron, extron, amx | Cat6 | null |
| (no match) | Unknown | null |

`from_location` = `"Room Name — Equipment Name"`. `approx_length_m` = null (engineer fills in existing UI). `notes` = room's `cable_route_desc` from survey.

**app/Jobs/BuildCableScheduleJob.php** — Mirrors `BuildOmManualJob`. `tries=2`, `timeout=120`. `handle()` calls generator then `CableScheduleXlsxService::build()`, then explicitly sets `status=draft` (the xlsx service updates `filename` but not `status`). `failed()` hook sets `status=failed` + `error_message`.

### Task 2: Controller methods + routes

**app/Http/Controllers/CableScheduleController.php** — Added `WorkerMonitorService` to constructor. Added three methods:
- `generateFromProject(Project $project)` — ownership check, `CableSchedule::create()` with `STATUS_GENERATING`, `ensureRunning()`, `BuildCableScheduleJob::dispatch($schedule->id)`, redirect back with success flash.
- `status(CableSchedule $cableSchedule)` — ownership check, returns `{ status, download_url, error }` JSON.
- `download(CableSchedule $cableSchedule)` — ownership check, `Storage::disk('local')->download()` from `cable-schedules/{filename}`.

**routes/web.php** — Three routes inserted BEFORE `Route::resource('cable-schedules', ...)` to prevent the `{cableSchedule}` wildcard from capturing the `generate-from-project` literal segment.

## Commits

| Task | Commit | Files |
|------|--------|-------|
| 1: Model + Generator + Job | `7b1d64d` | CableSchedule.php, CableScheduleGeneratorService.php, BuildCableScheduleJob.php |
| 2: Controller + Routes | `a7eb1f1` | CableScheduleController.php, routes/web.php |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical field] Added error_message to CableSchedule::$fillable**
- **Found during:** Task 1, when writing `BuildCableScheduleJob::failed()` hook which calls `$schedule->update(['error_message' => ...])`
- **Issue:** `error_message` was not in `$fillable` — the update would have been silently ignored
- **Fix:** Added `error_message` to the `$fillable` array alongside `project_id` and `filename`
- **Files modified:** `app/Models/CableSchedule.php`
- **Commit:** `7b1d64d`

## Known Stubs

None — all data flows from `ProjectDataService` (real project/survey data). No placeholder values or hardcoded mock data.

## Threat Flags

No new network endpoints or auth paths beyond those defined in the plan's threat model. All three new controller methods implement the `abort_if` ownership check (T-04-03-01, T-04-03-02, T-04-03-03).

## Self-Check: PASSED

- `app/Services/CableScheduleGeneratorService.php` — FOUND
- `app/Jobs/BuildCableScheduleJob.php` — FOUND
- `app/Models/CableSchedule.php` — FOUND (modified)
- `app/Http/Controllers/CableScheduleController.php` — FOUND (modified)
- `routes/web.php` — FOUND (modified)
- Commit `7b1d64d` — FOUND
- Commit `a7eb1f1` — FOUND
- `php artisan route:list --name=cable-schedules` shows 11 routes including all 3 new routes
- `generate-from-project` appears BEFORE `{cableSchedule}` wildcard routes in route:list output
