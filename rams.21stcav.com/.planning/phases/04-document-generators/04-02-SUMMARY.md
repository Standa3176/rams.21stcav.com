---
phase: 04-document-generators
plan: 02
subsystem: om-manual
tags: [om-manual, project-data-service, refactor, json-endpoint]
dependency_graph:
  requires: [ProjectDataService, OmManualGeneratorService, BuildOmManualJob]
  provides: [buildContextFromProjectData, om-manuals.status]
  affects: [OmManualController, OmManualGeneratorService, routes/web.php]
tech_stack:
  added: []
  patterns: [dual-shape extracted_data reading, JSON polling endpoint, ProjectDataService feed]
key_files:
  modified:
    - app/Core/Modules/OMManual/OmManualGeneratorService.php
    - app/Http/Controllers/OmManualController.php
    - routes/web.php
decisions:
  - "D-07/D-08 implemented: generateFromProject() now calls buildContextFromProjectData() via ProjectDataService — no PDF extraction, no STATUS_REVIEWED gate"
  - "Backward-compatible dual-shape in buildContentContext(): 'rooms' key = new project-linked path; 'equipment' key = legacy PDF-upload fallback"
  - "status() endpoint uses abort_if ownership check before returning JSON — satisfies T-04-02-04"
metrics:
  duration: ~20 minutes
  completed: 2026-04-11
  tasks_completed: 2
  tasks_total: 2
  files_modified: 3
---

# Phase 4 Plan 2: O&M Pass 1 Replacement & Status Endpoint Summary

**One-liner:** ProjectDataService feed replaces PDF extraction as O&M Pass 1, with backward-compatible dual-shape reading and a JSON status polling endpoint.

## What Was Built

### Task 1 — OmManualGeneratorService

- **Injected** `ProjectDataService` into the constructor (alongside existing `ProjectService`).
- **Added** public method `buildContextFromProjectData(Project $project): array` that calls `$this->projectDataService->resolve($project)`, maps rooms through `filterHardwareItems()`, and produces the canonical `{ project_name, project_ref, client_name, site_address, notes, rooms[] }` shape that `buildContentContext()` and `OmManualPrompt::forContent()` expect.
- **Updated** `buildContentContext(OmManual $manual)` with backward-compatible dual-shape reading:
  - If `extracted_data` has a `'rooms'` key → use directly (new project-linked O&Ms via `buildContextFromProjectData`)
  - If `extracted_data` has an `'equipment'` key only → wrap in a single `'General'` room (legacy PDF-uploaded O&Ms)
- No other methods modified (`generateContent`, `extractFromPdf`, `extractFromProjectPackage`, `filterHardwareItems`, `sanitiseRooms`, etc. all unchanged).

### Task 2 — OmManualController + routes/web.php

- **Replaced** `generateFromProject()` body: removed `$package` lookup, `STATUS_REVIEWED` gate, and `extractFromProjectPackage()` call. Now calls `$this->generator->buildContextFromProjectData($project)`, creates `OmManual` record with `extracted_data` populated and `status = STATUS_GENERATING`, then dispatches `BuildOmManualJob`.
- **Added** `status(OmManual $omManual): JsonResponse` method returning `{ status, label, download_url, error }` with ownership check (`abort_if`). `download_url` is only populated for `draft` and `final` statuses.
- **Added** `use Illuminate\Http\JsonResponse` and `use Illuminate\Support\Facades\Log` imports.
- **Registered** `GET om-manuals/{omManual}/status` route named `om-manuals.status` immediately before the `Route::resource('om-manuals', ...)` wildcard to prevent routing conflicts.

## Verification Results

- PHP syntax valid for all three modified files.
- `php artisan route:list --name=om-manuals.generate-from-project` — route present.
- `php artisan route:list --name=om-manuals.status` — route present at `GET om-manuals/{omManual}/status`.
- `buildContentContext()` grep confirms both `$extractedData['rooms']` and `$extractedData['equipment']` paths present.
- `storeFromProject()` method untouched — PDF-upload review flow intact.
- `BuildOmManualJob`, `OmManualDocxService::build()`, and `generateContent()` unchanged.

## Deviations from Plan

None — plan executed exactly as written.

## Known Stubs

None. All data paths are wired to live sources (`ProjectDataService::resolve()`). Legacy path is functional for existing PDF-uploaded O&Ms.

## Threat Flags

No new network endpoints or auth paths beyond those described in the plan's threat model. The `status()` endpoint has ownership check (`abort_if user_id mismatch`) as required by T-04-02-04.

## Commits

| Hash | Message |
|------|---------|
| dae23ec | feat(04-02): replace O&M Pass 1 with ProjectDataService feed; add status() polling endpoint |

## Self-Check: PASSED

- FOUND: app/Core/Modules/OMManual/OmManualGeneratorService.php
- FOUND: app/Http/Controllers/OmManualController.php
- FOUND: commit dae23ec
