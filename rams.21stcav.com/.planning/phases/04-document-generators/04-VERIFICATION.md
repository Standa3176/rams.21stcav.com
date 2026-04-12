---
phase: 04-document-generators
verified: 2026-04-11T00:00:00Z
status: human_needed
score: 12/12
overrides_applied: 0
re_verification: false
human_verification:
  - test: "Trigger worksheet generation from a project show page"
    expected: "POST succeeds, status flashes 'Worksheet generation queued.', spinner appears with Alpine.js polling"
    why_human: "Requires a running queue worker and real project data to observe end-to-end DOCX output"
  - test: "Trigger O&M generation from a project show page via 'Generate O&M Manual' button"
    expected: "buildContextFromProjectData() feeds extracted_data, BuildOmManualJob produces DOCX, status advances to draft"
    why_human: "Requires AI provider live call; cannot be verified by static code inspection alone"
  - test: "Trigger cable schedule generation from a project show page via 'Generate Cable Schedule' button"
    expected: "CableScheduleItem records created deterministically; XLSX written to disk; status advances to draft"
    why_human: "Requires queue worker and DB write; output depends on real ProjectDataService data"
  - test: "Spinner/polling: after generation queues, page shows spinner; on completion page reloads with Download button"
    expected: "Alpine.js polls /worksheets/{id}/status every 4 s; on draft status window.location.reload() fires; Download button replaces spinner"
    why_human: "Real-time browser behaviour cannot be verified by static analysis"
---

# Phase 4: Document Generators — Verification Report

**Phase Goal:** Users can generate Worksheets (DOCX), O&M Manuals (DOCX), and Cable Schedules (XLSX) from ProjectDataService canonical data, with queue-based async processing and status tracking.
**Verified:** 2026-04-11
**Status:** human_needed — all automated checks pass; 4 items require live browser/queue testing.
**Re-verification:** No — initial verification.

---

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | BuildWorksheetJob runs, calls AI per room, builds DOCX, sets status to draft | VERIFIED | `BuildWorksheetJob` implements `ShouldQueue`, `tries=2`, `timeout=300`; calls `generateContent()` then `docxService->build()`; sets `STATUS_DRAFT` on success |
| 2 | Worksheet show page renders room accordion with four sections per room | VERIFIED | `show.blade.php` loops `generated_data['rooms']`; sections A (Equipment), B (Install Steps), C (Cable Routes), D (Power & Network) all present with correct CSS classes |
| 3 | Unsurveyed rooms render with equipment and "Not surveyed" for null fields | VERIFIED | Section C falls back to "Not surveyed"; Section D field table renders "Not surveyed" for null values; `is_surveyed` flag correctly propagated |
| 4 | Worksheet index lists all worksheets with status badges and download links | VERIFIED | `index.blade.php` renders `data-table` with columns Project / Client / Status / Generated / Actions; download link conditional on `draft`/`final` status |
| 5 | OmManualController::generateFromProject() reads from ProjectDataService, not PDF extraction | VERIFIED | Method body calls `$this->generator->buildContextFromProjectData($project)` (line 160); no reference to `ProjectPackage`, `extractFromProjectPackage`, or `$package` |
| 6 | OmManualGeneratorService::buildContentContext() handles both 'rooms' key (new) and 'equipment' key (legacy) | VERIFIED | Lines 370-413: `isset($extractedData['rooms'])` branch uses rooms directly; else branch wraps `equipment` array in single General room |
| 7 | OmManualController::status() returns JSON with status, label, download_url, error | VERIFIED | Method at line 196 returns `response()->json([status, label, download_url, error])` with ownership check |
| 8 | CableSchedule::project_id and filename in $fillable; STATUS_* constants; project() BelongsTo | VERIFIED | `CableSchedule.php` `$fillable` includes `project_id` and `filename`; all 5 STATUS constants defined; `project()` BelongsTo present |
| 9 | CableScheduleGeneratorService::inferCableType() excludes cables/services and maps categories deterministically | VERIFIED | `SKIP_KEYWORDS` covers cables/consumables/services/mounts/racks/install/commission; display→HDMI 2.0, speaker→2-Core Speaker Cable, network→Cat6, etc. |
| 10 | BuildCableScheduleJob dispatched from CableScheduleController::generateFromProject() | VERIFIED | Controller creates `CableSchedule::create(...)`, calls `ensureRunning()`, dispatches `BuildCableScheduleJob::dispatch($schedule->id)` |
| 11 | Project show page placeholder "Coming in Phase 4." is gone; three-state generate button pattern present | VERIFIED | `grep "Coming in Phase 4"` returns 0 matches; generate_route, spinner + Alpine.js polling, Download/View buttons all wired; `@keyframes spin` at line 1102 |
| 12 | Project::worksheets() HasMany relationship exists; ProjectController::show() eager-loads worksheets | VERIFIED | `Project.php` has `worksheets(): HasMany { return $this->hasMany(Worksheet::class); }`; `show()` includes `'worksheets' => fn($q) => $q->latest()->limit(5)` in eager load |

**Score:** 12/12 truths verified

---

## Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `database/migrations/2026_04_11_000001_create_worksheets_table.php` | worksheets table with all columns | VERIFIED | id, user_id (FK cascadeDelete), project_id (FK nullable nullOnDelete), project_name, project_ref, client_name, site_address, status, error_message, generated_data (json), filename, timestamps, softDeletes |
| `app/Models/Worksheet.php` | 5 status constants, fillable, relationships, casts | VERIFIED | STATUS_PENDING/GENERATING/DRAFT/FINAL/FAILED defined; $fillable has all 10 fields; generated_data cast to 'array'; user() and project() BelongsTo |
| `app/Core/AI/Prompts/WorksheetPrompt.php` | Extends BasePrompt, forRoom() factory | VERIFIED | Extends BasePrompt; static `forRoom(array $room, array $projectMeta): static` factory; build(), systemMessage(), maxTokens(), temperature() all implemented |
| `app/Services/WorksheetGeneratorService.php` | ProjectDataService→per-room AI, filterHardwareItems | VERIFIED | Constructor injects ProjectDataService; `generateContent()` calls `resolve()`, loops rooms, calls `AIManager::run(WorksheetPrompt::forRoom(...))` per room; `filterHardwareItems()` excludes cables/consumables/services/option |
| `app/Services/WorksheetDocxService.php` | PHPWord DOCX, 4 sections per room, Storage::disk('local'), filename update | VERIFIED | Uses PhpWord; buildRoom() outputs sections A/B/C/D; directory set via `Storage::disk('local')->path('worksheets')`; `$worksheet->update(['filename' => $filename])` |
| `app/Jobs/BuildWorksheetJob.php` | ShouldQueue, tries=2, timeout=300, handle+failed | VERIFIED | Implements ShouldQueue; tries=2, timeout=300; handle() calls generator→update→docxService; failed() hook sets STATUS_FAILED |
| `app/Http/Controllers/WorksheetController.php` | generateFromProject, status, download, index, show, destroy | VERIFIED | All 6 methods present with correct return types; ownership checks on all actions; WorkerMonitorService injected |
| `resources/views/worksheets/index.blade.php` | Worksheet index table or empty state | VERIFIED | Empty state via `<x-dashboard.empty-state>`; non-empty path renders `data-table` with all 5 columns |
| `resources/views/worksheets/show.blade.php` | Room accordion, 4 sections, Alpine.js per room | VERIFIED | `x-data="{ open: false }"` per room card; sections A/B/C/D rendered; null-safe fallbacks for unsurveyed fields |
| `app/Http/Controllers/OmManualController.php` | generateFromProject() uses ProjectDataService; status() JSON method | VERIFIED | `buildContextFromProjectData()` called in generateFromProject(); `status()` returns JSON; no ProjectPackage reference in generateFromProject() |
| `app/Core/Modules/OMManual/OmManualGeneratorService.php` | buildContextFromProjectData(); dual-shape buildContentContext() | VERIFIED | `buildContextFromProjectData(Project)` public method adds; `buildContentContext()` reads rooms key first, falls back to equipment key |
| `app/Models/CableSchedule.php` | project_id + filename in fillable, STATUS_* constants, project() | VERIFIED | $fillable includes project_id and filename; all 5 STATUS constants; project() BelongsTo |
| `app/Services/CableScheduleGeneratorService.php` | generate() and inferCableType() methods | VERIFIED | `generate(CableSchedule): int` creates CableScheduleItem records; `inferCableType(string): ?array` returns null for skip categories |
| `app/Jobs/BuildCableScheduleJob.php` | tries=2, timeout=120, handle+failed | VERIFIED | tries=2; timeout=120; handle() calls generator→xlsxService→sets STATUS_DRAFT; failed() hook present |
| `app/Http/Controllers/CableScheduleController.php` | generateFromProject(), status(), download() | VERIFIED | All three methods present; generateFromProject() dispatches BuildCableScheduleJob; status() returns JSON; download() serves XLSX |
| `app/Models/Project.php` | worksheets() HasMany | VERIFIED | `worksheets(): HasMany` pointing to `Worksheet::class` |
| `resources/views/projects/show.blade.php` | "Coming in Phase 4." gone; three-state buttons; @csrf; @keyframes spin | VERIFIED | 0 occurrences of placeholder text; generate form with @csrf at line 311; spinner with x-init="startPolling()" at line 337; `@keyframes spin` at line 1102 |

---

## Key Link Verification

| From | To | Via | Status | Details |
|------|-----|-----|--------|---------|
| WorksheetController::generateFromProject() | BuildWorksheetJob | BuildWorksheetJob::dispatch($worksheet->id) | WIRED | Line 117 of WorksheetController.php |
| BuildWorksheetJob | WorksheetGeneratorService::generateContent() | constructor injection | WIRED | handle() injects WorksheetGeneratorService, calls `$generator->generateContent($worksheet)` |
| WorksheetGeneratorService | ProjectDataService::resolve() | constructor injection | WIRED | `$this->projectDataService->resolve($project)` in generateContent() |
| WorksheetGeneratorService | WorksheetPrompt::forRoom() | AIManager::run per room | WIRED | `WorksheetPrompt::forRoom($roomForPrompt, $projectMeta)` + `AIManager::run($prompt, ...)` |
| BuildWorksheetJob | WorksheetDocxService::build() | service injection | WIRED | handle() injects WorksheetDocxService, calls `$docxService->build($generatedData, $worksheet)` |
| OmManualController::generateFromProject() | ProjectDataService::resolve() | constructor-injected via OmManualGeneratorService | WIRED | Calls `$this->generator->buildContextFromProjectData($project)` which calls `$this->projectDataService->resolve($project)` |
| OmManualController::generateFromProject() | OmManualGeneratorService::buildContextFromProjectData() | direct call | WIRED | Line 160 of OmManualController.php |
| OmManualGeneratorService::buildContentContext() | manual->extracted_data['rooms'] | Pass 2 reads rooms from extracted_data | WIRED | Line 370: `if (isset($extractedData['rooms']) && is_array($extractedData['rooms']))` |
| Alpine.js polling | OmManualController::status() | GET /om-manuals/{omManual}/status | WIRED | Route registered at line 231 of web.php BEFORE resource wildcard |
| CableScheduleController::generateFromProject() | BuildCableScheduleJob | BuildCableScheduleJob::dispatch($schedule->id) | WIRED | Line 219 of CableScheduleController.php |
| BuildCableScheduleJob | CableScheduleGeneratorService::generate() | constructor injection | WIRED | handle() injects CableScheduleGeneratorService, calls `$generator->generate($schedule)` |
| CableScheduleGeneratorService | ProjectDataService::resolve() | constructor injection | WIRED | Line 54: `$this->projectDataService->resolve($project)` |
| BuildCableScheduleJob | CableScheduleXlsxService::build() | constructor injection | WIRED | handle() injects CableScheduleXlsxService, calls `$xlsxService->build($schedule)` |
| projects/show.blade.php generate button | worksheets.generate-from-project POST route | HTML form POST | WIRED | `action="{{ $entry['generate_route'] }}"` resolves to worksheets.generate-from-project via ProjectController $linkedRecords |
| Alpine.js polling (Worksheet) | worksheets.status GET endpoint | fetch() every 4 seconds | WIRED | `fetch('{{ route($entry['status_route_name'], $latestRecord) }}')` in startPolling() |
| projects/show.blade.php O&M generate button | om-manuals.generate-from-project POST route | HTML form POST | WIRED | $linkedRecords O&M entry has generate_route = route('om-manuals.generate-from-project', $project) |
| projects/show.blade.php Cable Schedule generate button | cable-schedules.generate-from-project POST route | HTML form POST | WIRED | $linkedRecords Cable Schedule entry has generate_route = route('cable-schedules.generate-from-project', $project) |

---

## Route Ordering Verification

| Route | Position Relative to Wildcard | Status |
|-------|-------------------------------|--------|
| worksheets.generate-from-project (POST) | BEFORE {worksheet} wildcards | CORRECT — line 250, all {worksheet} routes follow |
| worksheets.status (GET) | BEFORE {worksheet} show/destroy | CORRECT — line 251 |
| worksheets.download (GET) | BEFORE {worksheet} show/destroy | CORRECT — line 252 |
| om-manuals.status (GET) | BEFORE Route::resource('om-manuals') | CORRECT — line 231, resource on line 232 |
| om-manuals.generate-from-project (POST) | AFTER Route::resource, but no conflict | SAFE — resource only registers index/create/store/destroy; POST om-manuals/generate-from-project/{project} does not match any resource route |
| cable-schedules.generate-from-project (POST) | BEFORE Route::resource('cable-schedules') | CORRECT — line 202, resource on line 206 |
| cable-schedules.status (GET) | BEFORE Route::resource | CORRECT — line 203 |
| cable-schedules.download (GET) | BEFORE Route::resource | CORRECT — line 204 |

---

## Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| WorksheetGeneratorService | $rooms | ProjectDataService::resolve()['rooms'] | Yes — resolve() reads from DB via ProjectPackage/SiteSurvey | FLOWING |
| WorksheetDocxService | $generatedData | BuildWorksheetJob passes result of generateContent() | Yes — rooms[] array with equipment and AI steps | FLOWING |
| worksheets/show.blade.php | generated_data['rooms'] | Worksheet model cast from JSON column | Yes — populated by BuildWorksheetJob after generation | FLOWING |
| OmManualGeneratorService::buildContextFromProjectData() | $data | ProjectDataService::resolve($project) | Yes — canonical data source | FLOWING |
| CableScheduleGeneratorService | rooms + equipment | ProjectDataService::resolve()['rooms'] | Yes — real equipment per room | FLOWING |

---

## Behavioral Spot-Checks

Step 7b: SKIPPED for static code checks (queue worker required for runtime execution). Human verification items cover runtime behaviour.

---

## Requirements Coverage

| Requirement | Source Plan | Status | Evidence |
|-------------|------------|--------|----------|
| WORK-01 — Worksheet model and migration | 04-01 | SATISFIED | Migration and model fully implemented with all columns, constants, casts, relationships |
| WORK-02 — WorksheetGeneratorService reads ProjectDataService | 04-01 | SATISFIED | `generateContent()` calls `projectDataService->resolve($project)` and loops rooms |
| WORK-03 — WorksheetDocxService builds 4-section DOCX | 04-01 | SATISFIED | buildRoom() writes sections A/B/C/D using PHPWord; Storage::disk('local') used for path |
| WORK-04 — BuildWorksheetJob queued from project show page | 04-01, 04-04 | SATISFIED | Job dispatched in WorksheetController::generateFromProject(); project show page wired via generate_route |
| OM-01 — generateFromProject() uses ProjectDataService not PDF | 04-02 | SATISFIED | generateFromProject() calls buildContextFromProjectData(); no PDF/ProjectPackage reference |
| OM-02 — buildContextFromProjectData() produces correct shape | 04-02 | SATISFIED | Returns {project_name, project_ref, client_name, site_address, notes, rooms[]} |
| OM-03 — buildContentContext() dual-shape backward compatibility | 04-02 | SATISFIED | Reads 'rooms' key first; falls back to 'equipment' key for legacy records |
| OM-04 — OmManualController::status() JSON endpoint | 04-02 | SATISFIED | Method returns {status, label, download_url, error}; route om-manuals.status registered before resource |
| CABLE-01 — CableSchedule model fillable fix | 04-03 | SATISFIED | project_id and filename both in $fillable; STATUS_* constants; project() BelongsTo |
| CABLE-02 — CableScheduleGeneratorService deterministic generation | 04-03 | SATISFIED | inferCableType() returns null for skip categories; maps display→HDMI 2.0, speaker→2-Core Speaker Cable etc. |
| CABLE-03 — BuildCableScheduleJob queued pipeline | 04-03 | SATISFIED | tries=2, timeout=120; generator→xlsxService→STATUS_DRAFT; failed() hook |
| CABLE-04 — generateFromProject() + status() + download() wired | 04-03, 04-04 | SATISFIED | All three methods in CableScheduleController; routes registered before resource wildcard |

---

## Anti-Patterns Found

No blockers or warnings detected. Scanned key files:

- `app/Models/Worksheet.php` — no TODOs, no stubs, no empty implementations
- `app/Services/WorksheetGeneratorService.php` — no stubs; AI failure is caught and logged gracefully (non-fatal per room)
- `app/Services/WorksheetDocxService.php` — no stubs; full 4-section implementation
- `app/Jobs/BuildWorksheetJob.php` — no stubs
- `app/Http/Controllers/WorksheetController.php` — no stubs
- `app/Services/CableScheduleGeneratorService.php` — no stubs; deterministic logic fully implemented
- `app/Jobs/BuildCableScheduleJob.php` — no stubs
- `resources/views/projects/show.blade.php` — "Coming in Phase 4." removed (0 matches)

One notable implementation choice: the `WorksheetGeneratorService::filterHardwareItems()` keyword fallback only applies when `category === ''`. If a category is set but is not in `EXCLUDED_CATEGORIES`, the item passes through regardless of its name. This is correct per the plan specification — keyword matching is a fallback for uncategorised items only.

---

## Human Verification Required

### 1. Worksheet end-to-end generation

**Test:** On a project with at least one room and equipment, click "Generate Worksheet". Wait for queue worker to process the job.
**Expected:** Status flashes success; spinner appears on project show page; after job completes the page auto-reloads showing "Draft" status badge and "↓ Download" button. Clicking Download streams a DOCX with one section per room.
**Why human:** Requires live queue worker, AI provider call, and PHPWord DOCX write to disk.

### 2. O&M Manual generation via ProjectDataService

**Test:** Click "Generate O&M Manual" on the project show page.
**Expected:** OmManual record created with extracted_data containing rooms[]; BuildOmManualJob runs AI pass 2; status advances to draft; Download button appears.
**Why human:** Requires live AI provider; Pass 2 content generation cannot be verified statically.

### 3. Cable Schedule generation

**Test:** Click "Generate Cable Schedule" on a project with surveyed rooms.
**Expected:** CableScheduleItem records created (check DB: from_location format "Room — Equipment", cable_type set per category, approx_length_m null, notes from cable_route_desc); XLSX built; status advances to draft.
**Why human:** Requires DB write verification and XLSX file inspection.

### 4. Alpine.js polling lifecycle

**Test:** After triggering any generation, observe the browser network tab.
**Expected:** Every 4 seconds a GET request fires to /worksheets/{id}/status (or om-manuals/cable-schedules equivalent). On `draft` response, `window.location.reload()` fires and the Download button replaces the spinner.
**Why human:** Real-time browser behaviour requires manual observation.

---

## Gaps Summary

No gaps found. All 12 observable truths are verified across all automated checks. The four items above require human testing of runtime behaviour (queue execution, AI provider calls, DOCX/XLSX file output, and Alpine.js polling lifecycle) that cannot be confirmed by static code analysis.

---

_Verified: 2026-04-11_
_Verifier: Claude (gsd-verifier)_
