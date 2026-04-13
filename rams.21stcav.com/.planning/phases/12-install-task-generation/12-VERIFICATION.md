---
phase: 12-install-task-generation
verified: 2026-04-13T00:00:00Z
status: human_needed
score: 6/6 must-haves verified
overrides_applied: 0
re_verification:
  previous_status: gaps_found
  previous_score: 5/6
  gaps_closed:
    - "INST-01c: quantity column now present in migration 2026_04_14_000003_add_quantity_to_install_tasks_table.php, in InstallTask::$fillable and casts(), and passed as $item['quantity'] ?? 1 in InstallTaskGeneratorService::generate()"
  gaps_remaining: []
  regressions: []
human_verification:
  - test: "Generate a worksheet for a project that has a completed site survey with at least 2 rooms having answered pre-install questions. Download the DOCX file. Open in Word or LibreOffice."
    expected: "Each room section shows 'E. PRE-INSTALL CHECK ANSWERS' heading in teal. Rooms with answers show a two-column table (Question | Answer) with alternating row shading. The Answer cell shows Yes, No, or Other: {text}. Rooms with no answered questions show 'No pre-install checks recorded.' in italic grey."
    why_human: "DOCX file rendering cannot be verified programmatically without a running server and a project with survey data. The code path is wired correctly but the visual output in the opened document requires manual inspection."
---

# Phase 12: Install Task Generation Verification Report

**Phase Goal:** Auto-generate a structured install task list from ProjectDataService, persisted as install_programmes + install_tasks records. Engineers confirm the generated list before it becomes active. Also deliver WORK-05/06 worksheet enhancements (pre-install answers + dashboard trigger).
**Verified:** 2026-04-13
**Status:** human_needed
**Re-verification:** Yes — after gap closure (INST-01c quantity column)

## Goal Achievement

### Observable Truths (Roadmap Success Criteria)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | `InstallTaskGeneratorService::generate()` exists and creates task records grouped by room from ProjectDataService | VERIFIED | Service at `app/Services/InstallTaskGeneratorService.php`. Reads exclusively via `$this->projectDataService->resolve($project)`. 12 unit tests pass including task count per room, field values, and synchronous execution guard. |
| 2 | `install_programmes` and `install_tasks` tables exist with all columns | VERIFIED | Both create migrations ran (confirmed via `php artisan migrate:status`). INST-01c quantity gap closed: migration `2026_04_14_000003_add_quantity_to_install_tasks_table.php` adds `unsignedSmallInteger quantity default 1`. All INST-01c and INST-01d columns now present. |
| 3 | Generating tasks for a multi-room project produces >= 1 task record per room | VERIFIED | Unit test `generate_creates_one_task_per_hardware_item_per_room` confirms 2 rooms x 2 hardware items each = 4 tasks. Hardware filter correctly excludes cables category and keyword-matched items. |
| 4 | A confirm gate UI exists: generated tasks shown for PM review before programme is activated | VERIFIED | `resources/views/install-programmes/review.blade.php` exists. Tasks grouped by `$programme->tasks->groupBy('room_name')`. Activate button gated: only shown when `$programme->isDraft() && $programme->tasks->count() > 0`. Zero-task warning rendered when no tasks. Per-task Remove button uses DELETE form. |
| 5 | Worksheet DOCX includes pre-install check answers per room | VERIFIED | `WorksheetGeneratorService::generateContent()` fetches answers via `$project->siteSurveys()->latest()->first()`, maps by lowercase-trimmed room_name, passes to `buildRooms()` as 5th arg. `WorksheetDocxService::buildRoom()` calls `buildPreInstallAnswersTable()` for section E. Empty state renders "No pre-install checks recorded." |
| 6 | Worksheet generation button appears on project dashboard | VERIFIED | `worksheets.generate-from-project` route confirmed at line 256 of routes/web.php. `ProjectController::show()` line 155 includes `generate_route => route('worksheets.generate-from-project', $project)` in `$linkedRecords`. |

**Score: 6/6 truths verified**

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `database/migrations/2026_04_14_000001_create_install_programmes_table.php` | install_programmes schema | VERIFIED | All INST-01d columns: id, project_id (nullable FK), generated_by (nullable FK to users), status, generated_at, activated_at, completed_at, planned_start_date, planned_end_date, notes, timestamps, softDeletes, indexes |
| `database/migrations/2026_04_14_000002_create_install_tasks_table.php` | install_tasks schema (base) | VERIFIED | All INST-01c columns except quantity (handled by addColumn migration). Has: id, install_programme_id (FK cascadeOnDelete), room_name (denormalised string), room_ref, equipment_name, equipment_category, task_type, title, description, status, blocked_reason, sort_order, notes, assigned_to (nullable FK to users), assigned_at, started_at, completed_at, sign_off_required, timestamps, softDeletes, indexes. |
| `database/migrations/2026_04_14_000003_add_quantity_to_install_tasks_table.php` | quantity column addColumn migration | VERIFIED | `$table->unsignedSmallInteger('quantity')->default(1)->after('equipment_name')` — correct type, default, and placement. down() correctly dropColumn. |
| `app/Models/InstallProgramme.php` | InstallProgramme Eloquent model | VERIFIED | STATUS_DRAFT/ACTIVE/COMPLETE/ARCHIVED constants. Full $fillable. datetime/date casts. project(), generatedBy(), tasks() relationships. statusLabel(), statusBadgeClass(), isDraft(), isActive() helpers. SoftDeletes. |
| `app/Models/InstallTask.php` | InstallTask Eloquent model | VERIFIED | STATUS_PENDING/IN_PROGRESS/COMPLETE/BLOCKED/SKIPPED and TYPE_* constants. Full $fillable including quantity. casts() includes quantity cast to integer. programme(), assignedUser() relationships. statusLabel(), isPending(), isComplete() helpers. SoftDeletes. |
| `app/Services/InstallTaskGeneratorService.php` | Task generation from ProjectDataService | VERIFIED | EXCLUDED_CATEGORIES and EXCLUDED_KEYWORDS constants. filterHardware() public method. generate() wraps in DB::transaction, reads only from projectDataService->resolve(). Passes `'quantity' => $item['quantity'] ?? 1` to InstallTask::create(). No AI call, no queue dispatch. |
| `app/Services/InstallProgrammeService.php` | High-level programme orchestration | VERIFIED | createForProject() archives existing, creates draft, calls generator. activate() validates status=draft, throws LogicException if not. archiveExisting() sets STATUS_ARCHIVED on draft/active programmes. |
| `app/Http/Controllers/InstallProgrammeController.php` | HTTP layer for programme management | VERIFIED | generate(), review(), activate(), destroyTask() methods. abort_if ownership guard on every action. Transitive ownership check in destroyTask (task -> programme -> project). Log::info on every action. |
| `resources/views/install-programmes/review.blade.php` | Draft programme review UI | VERIFIED | Extends layouts.app. Tasks grouped by room_name. Activate button gated on isDraft() && count > 0. Per-task Delete form with @method('DELETE'). Zero-task warning. Status badge. Back link to project show. |
| `app/Services/WorksheetGeneratorService.php` | pre-install answers in generateContent() | VERIFIED | siteSurveys()->latest()->first() pattern. whereNotNull('answer') filter. strtolower(trim()) key. buildRooms() updated to 5-parameter signature with $preInstallAnswers. pre_install_answers key added to rooms[] output. |
| `app/Services/WorksheetDocxService.php` | Section E in DOCX per room | VERIFIED | buildRoom() calls addSectionHeading + buildPreInstallAnswersTable() after section D. buildPreInstallAnswersTable() renders two-column table (Question | Answer) or "No pre-install checks recorded." empty state. answer='other' formatted as "Other: {other_text}". |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `InstallProgrammeController::generate()` | `InstallProgrammeService::createForProject()` | direct synchronous call | WIRED | Line 59: `$this->service->createForProject($project, auth()->user())` |
| `InstallProgrammeService::createForProject()` | `InstallTaskGeneratorService::generate()` | method call within createForProject | WIRED | Line 57: `$this->generator->generate($programme)` after programme creation |
| `InstallTaskGeneratorService::generate()` | `ProjectDataService::resolve()` | constructor-injected dependency | WIRED | Line 78: `$data = $this->projectDataService->resolve($project)` — never touches extracted_data or reviewed_data directly |
| `InstallTaskGeneratorService::generate()` | `InstallTask::create()` quantity field | `$item['quantity'] ?? 1` passed in create() array | WIRED | Line 95: `'quantity' => $item['quantity'] ?? 1` — passes equipment quantity from ProjectDataService data |
| `WorksheetGeneratorService::generateContent()` | `SiteSurveyRoomQuestion` (via SiteSurveyRoom) | `$latestSurvey->rooms()->with('questions')->get()` | WIRED | Line 113: rooms loaded with questions, whereNotNull('answer') filter applied |
| `WorksheetDocxService::buildRoom()` | `room['pre_install_answers']` | new section E call | WIRED | Lines 202-203: `addSectionHeading('E. Pre-Install Check Answers')` + `buildPreInstallAnswersTable($section, $room['pre_install_answers'] ?? [])` |
| `ProjectController::show()` | `install-programmes.generate` route | $linkedRecords entry | WIRED | Line 191: `'generate_route' => route('install-programmes.generate', $project)` |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|--------------|--------|--------------------|--------|
| `review.blade.php` | `$programme->tasks->groupBy('room_name')` | `InstallProgramme::tasks()` hasMany -> DB query | Yes (DB query from install_tasks table, ordered by sort_order) | FLOWING |
| `WorksheetDocxService` section E | `$room['pre_install_answers']` | `SiteSurveyRoomQuestion` via survey rooms eager-loaded with `with('questions')` | Yes (DB query from site_survey_room_questions table, filtered to answered) | FLOWING |
| `install_tasks.quantity` | `$item['quantity'] ?? 1` in generate() | ProjectDataService::resolve() equipment array | Yes (reads from resolved project equipment data; defaults to 1 when absent) | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| 4 install routes registered | `php artisan route:list --name=install` | All 4 routes listed: generate, review, activate, install-tasks.destroy | PASS |
| WORK-06 route exists | `php artisan route:list --name=worksheets.generate` | `worksheets.generate-from-project` route confirmed | PASS |
| PHP syntax clean — all 5 modified/created services | `php -l` on all 5 files | "No syntax errors detected" for all | PASS |
| 12 unit tests pass | `php artisan test tests/Unit/InstallTaskGeneratorServiceTest.php` | 12 passed (24 assertions) in 2.41s | PASS |
| Both create migrations ran | `php artisan migrate:status` | Both 2026_04_14_000001 and 000002 migrations show "Ran" | PASS |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|------------|-------------|--------|----------|
| INST-01 | 12-01, 12-02 | Auto-generate structured task list from ProjectDataService | SATISFIED | InstallTaskGeneratorService.generate() exists and creates tasks from ProjectDataService::resolve() |
| INST-01a | 12-02 | InstallTaskGeneratorService reads only from ProjectDataService::resolve() | SATISFIED | Line 78 of service: `$this->projectDataService->resolve($project)`. No extracted_data or reviewed_data access. |
| INST-01b | 12-02 | Tasks generated per room x equipment item (one per hardware item per room) | SATISFIED | generate() loops rooms then hardware items, creates one InstallTask per item. Unit test confirms correct count. |
| INST-01c | 12-01 | install_tasks table with specified columns including quantity | SATISFIED | quantity column added via migration 2026_04_14_000003. InstallTask::$fillable includes quantity. casts() casts quantity to integer. InstallTaskGeneratorService::generate() passes $item['quantity'] ?? 1 to create(). Column named equipment_category (not category — acceptable rename). |
| INST-01d | 12-01 | install_programmes table with specified columns | SATISFIED | All required columns present: id, project_id, generated_at, status (draft/active/complete + bonus archived), planned_start_date, planned_end_date, created_at, updated_at. Additional columns (activated_at, completed_at, notes, generated_by) are additive. |
| INST-01e | 12-02 | Human confirm gate before programme activation | SATISFIED | review.blade.php shows tasks for PM review. Activate button requires isDraft() + task count > 0. Per-task Remove available during review. |
| INST-01f | 12-02 | Re-generation allowed; old programme archived | SATISFIED | InstallProgrammeService::archiveExisting() called at start of createForProject(). Archives all draft/active programmes before creating new one. Unit test confirms archiveExisting() leaves COMPLETE status untouched. |
| INST-01g | 12-02 | Task generation synchronous, < 1 second | SATISFIED | No queue dispatch. Unit test `generate_does_not_dispatch_any_job` confirms jobs table stays at 0. Pure DB inserts in single transaction. |
| INST-01h | 12-01 | Project model gains installProgrammes() and activeInstallProgramme() relationships | SATISFIED | Both relationships present in Project.php at lines 178-188. |
| WORK-05 | 12-03 | Worksheet DOCX includes pre-install check answers per room | SATISFIED | WorksheetGeneratorService loads answers from SiteSurveyRoomQuestion via latest survey. WorksheetDocxService renders section E per room with table or empty-state fallback. |
| WORK-06 | 12-03 | Worksheet generation triggered from project dashboard | SATISFIED | worksheets.generate-from-project route at routes/web.php line 256. ProjectController $linkedRecords generate_route confirmed at line 155. |

### Anti-Patterns Found

| File | Pattern | Severity | Impact |
|------|---------|----------|--------|
| None found | — | — | All services return real data from DB queries. No TODO/FIXME/placeholder patterns detected. No empty returns in primary code paths. |

### Human Verification Required

#### 1. Worksheet DOCX Section E rendering

**Test:** Generate a worksheet for a project that has a completed site survey with at least 2 rooms having answered pre-install questions. Download the DOCX file. Open in Word or LibreOffice.
**Expected:** Each room section shows "E. PRE-INSTALL CHECK ANSWERS" heading in teal. Rooms with answers show a two-column table (Question | Answer) with alternating row shading. The "Answer" cell shows "Yes", "No", or "Other: {text}" depending on the engineer's recorded answer. Rooms with no answered questions show "No pre-install checks recorded." in italic grey.
**Why human:** DOCX file rendering cannot be verified programmatically without a running server and a project with survey data. The code path is wired correctly but the visual output in the opened document requires manual inspection.

### Gaps Summary

No gaps remaining. INST-01c quantity column gap resolved.

---

_Verified: 2026-04-13 (re-verification after gap closure)_
_Verifier: Claude (gsd-verifier)_
