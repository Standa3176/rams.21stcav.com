---
phase: 04-document-generators
plan: 01
subsystem: worksheet-generator
tags: [worksheet, docx, phpword, queue, ai, blade]
dependency_graph:
  requires: [ProjectDataService, AIManager, BuildOmManualJob pattern, OmManual model pattern]
  provides: [Worksheet model, WorksheetController, BuildWorksheetJob, WorksheetGeneratorService, WorksheetDocxService, WorksheetPrompt, worksheets views]
  affects: [User model (added worksheets() relation), routes/web.php]
tech_stack:
  added: []
  patterns: [queue-based async job, per-room AI calls, PHPWord programmatic DOCX, Storage::disk('local') path resolution, Alpine.js accordion]
key_files:
  created:
    - database/migrations/2026_04_11_000001_create_worksheets_table.php
    - app/Models/Worksheet.php
    - app/Http/Controllers/WorksheetController.php
    - app/Core/AI/Prompts/WorksheetPrompt.php
    - app/Services/WorksheetGeneratorService.php
    - app/Services/WorksheetDocxService.php
    - app/Jobs/BuildWorksheetJob.php
    - resources/views/worksheets/index.blade.php
    - resources/views/worksheets/show.blade.php
  modified:
    - app/Models/User.php
    - routes/web.php
decisions:
  - Sequential per-room AI calls chosen over parallel (simpler failure handling, fits tries=2 job pattern)
  - filterHardwareItems() uses category-first exclusion with keyword fallback for uncategorised items
  - WorksheetDocxService uses programmatic PHPWord build (no template) matching OmManualDocxService fallback pattern
  - Storage::disk('local')->path('worksheets/') for directory/path resolution to stay consistent with controller download path
metrics:
  duration_minutes: 6
  completed_date: 2026-04-11
  tasks_completed: 3
  tasks_total: 3
  files_created: 9
  files_modified: 2
---

# Phase 04 Plan 01: Worksheet Generator — Summary

**One-liner:** Complete Worksheet pipeline from project trigger to DOCX download — model, AI prompt, per-room generator service, PHPWord DOCX builder, async queue job, controller with ownership guards, and two Blade views.

---

## Tasks Completed

| Task | Name | Commit | Key Files |
|------|------|--------|-----------|
| 1 | Worksheet model, migration, controller skeleton | 3867324 | Worksheet.php, WorksheetController.php, migration, User.php |
| 2 | WorksheetPrompt, GeneratorService, DocxService, Job | b67d28c | WorksheetPrompt.php, WorksheetGeneratorService.php, WorksheetDocxService.php, BuildWorksheetJob.php |
| 3 | Blade views and routes | 2776754 | worksheets/index.blade.php, worksheets/show.blade.php, routes/web.php |

---

## What Was Built

### Worksheet Pipeline

- **Migration** (`2026_04_11_000001_create_worksheets_table.php`): worksheets table with user_id (FK cascade), project_id (FK nullable nullOnDelete), project_name, project_ref, client_name, site_address, status, error_message, generated_data (json), filename, timestamps, softDeletes.

- **Worksheet model** (`app/Models/Worksheet.php`): 5 status constants (pending, generating, draft, final, failed), fillable fields, generated_data cast to array, user()/project() BelongsTo relationships, isGenerated()/statusLabel()/statusBadgeClass() helpers.

- **WorksheetController** (`app/Http/Controllers/WorksheetController.php`): 6 methods — index (paginate 15, admin sees all), show, generateFromProject (creates record + dispatches job), status (JSON polling), download (Storage::disk('local') serve), destroy (soft-delete). All methods have abort_if ownership guard.

- **WorksheetPrompt** (`app/Core/AI/Prompts/WorksheetPrompt.php`): extends BasePrompt, static `forRoom(array $room, array $projectMeta): self`, builds prompt grounded in equipment list and survey fields. AI told not to invent scope. Response shape: `{ "install_steps": "..." }`.

- **WorksheetGeneratorService** (`app/Services/WorksheetGeneratorService.php`): reads exclusively from ProjectDataService::resolve(), calls AI per room sequentially, filterHardwareItems() excludes cables/consumables/services/option by category then keyword fallback. Unsurveyed rooms included with is_surveyed=false. AI exceptions per room caught and logged — job continues for remaining rooms.

- **WorksheetDocxService** (`app/Services/WorksheetDocxService.php`): creates PhpWord programmatically, cover header section, then one section per room with 4 subsections (Equipment table, Install Steps paragraph, Cable Routes paragraph, Power & Network field table). Saves to `Storage::disk('local')->path('worksheets/')`, updates worksheet.filename after save.

- **BuildWorksheetJob** (`app/Jobs/BuildWorksheetJob.php`): mirrors BuildOmManualJob exactly — tries=2, timeout=300, handle() catches and rethrows, failed() hook sets status=failed.

- **worksheets/index.blade.php**: table with Project/Client/Status/Generated/Actions columns or empty-state component. Download link only for draft/final status.

- **worksheets/show.blade.php**: breadcrumb, page header with download button, status bar, room accordion (Alpine.js x-data per room). Four sections per room using CSS copied verbatim from site-survey/show.blade.php. Unsurveyed fields render "Not surveyed" in var(--text-faint).

- **Routes** (`routes/web.php`): 6 routes added inside auth middleware group. `worksheets/generate-from-project/{project}` literal registered before `{worksheet}` wildcards.

---

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical Functionality] Added worksheets() HasMany to User model**
- **Found during:** Task 1 — WorksheetController::index() calls `auth()->user()->worksheets()` which requires the relationship.
- **Fix:** Added `public function worksheets(): HasMany` to `app/Models/User.php`.
- **Files modified:** `app/Models/User.php`
- **Commit:** 3867324

No other deviations — plan executed cleanly.

---

## Known Stubs

None. All data flows from ProjectDataService through the generator service to the DOCX. No hardcoded empty values or placeholder text in the pipeline.

---

## Threat Flags

| Flag | File | Description |
|------|------|-------------|
| None | — | No new network endpoints, auth paths, or trust boundary surfaces beyond those documented in plan threat model |

All threat mitigations from the plan's STRIDE register were implemented:
- T-04-01-01: abort_if ownership check in generateFromProject ✓
- T-04-01-02: abort_if ownership check in download + Storage path not user-controlled ✓
- T-04-01-03: abort_if ownership check in status() ✓
- T-04-01-04: install_steps rendered with `{{ }}` not `{!! !!}` in Blade ✓
- T-04-01-05: sequential AI calls, tries=2, timeout=300 — accepted risk ✓

---

## Self-Check

**Files exist:**
- `database/migrations/2026_04_11_000001_create_worksheets_table.php` — FOUND
- `app/Models/Worksheet.php` — FOUND
- `app/Http/Controllers/WorksheetController.php` — FOUND
- `app/Core/AI/Prompts/WorksheetPrompt.php` — FOUND
- `app/Services/WorksheetGeneratorService.php` — FOUND
- `app/Services/WorksheetDocxService.php` — FOUND
- `app/Jobs/BuildWorksheetJob.php` — FOUND
- `resources/views/worksheets/index.blade.php` — FOUND
- `resources/views/worksheets/show.blade.php` — FOUND

**Commits exist:**
- 3867324 — Task 1: model, migration, controller, User.php
- b67d28c — Task 2: prompt, generator, docx service, job
- 2776754 — Task 3: views + routes

## Self-Check: PASSED
