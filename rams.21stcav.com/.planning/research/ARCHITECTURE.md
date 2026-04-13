# Architecture Patterns — v1.2 Installation Programme & Field Management

**Domain:** Field installation task management integrated into existing Laravel 12 AV operations platform
**Researched:** 2026-04-13
**Milestone:** v1.2 — INST-01 through INST-05, WORK-05, WORK-06

---

## How v1.2 Sits in the Existing Architecture

The platform already has a clean read-only canonical data layer:

```
ProjectDataService.resolve(Project) → array
  reviewed_data > quotewerks_sql > extracted_data > defaults
  survey data enriches 'rooms' key only
```

Every v1.0 generator (Worksheet, O&M, RAMS, Cable Schedule) reads from this service and never touches raw package data directly. v1.2 follows the same contract exactly: `InstallTaskGeneratorService` and `CommissioningService` are the two new consumers of `ProjectDataService`.

The v1.2 data is **persistent field state**, not generated documents. This is the key architectural distinction from v1.0:

| v1.0 Pattern | v1.2 Pattern |
|--------------|--------------|
| Generate a DOCX snapshot once | Persist live task state updated by engineers in the field |
| Status on the document model (pending to generating to draft to final) | Status on individual task records (pending to in_progress to complete to signed_off) |
| Read-only output; regenerate if data changes | Mutable state; engineers write to task/time/commissioning records |
| Queue job writes generated_data JSON | No queue job; task generation is synchronous (fast, no AI call) |

---

## New Data Models

### `install_programmes` (one per project)

Top-level container for a project's install plan. Analogous to the `Worksheet` model — one record per project, tracks generation status.

```
id
project_id          FK → projects
generated_by        FK → users (who triggered generation)
status              enum: draft | active | completed | archived
generated_at        timestamp
activated_at        timestamp (when moved from draft to active — tasks become editable)
completed_at        timestamp nullable
notes               text nullable
timestamps
softDeletes
```

Why a container model: allows regeneration (draft a new programme without losing active task state), gives a clear anchor for relationships, and mirrors the Worksheet/OmManual/RamsDocument pattern already in place.

### `install_tasks` (many per programme, organised by room then equipment)

The core task unit. Generated from `ProjectDataService.resolve()` rooms and equipment, one task per equipment item per room, plus configurable additional tasks (e.g. "Cable route installation", "Rack build").

```
id
install_programme_id   FK → install_programmes
room_name              string (denormalised from package data — no FK to survey room)
room_ref               string nullable
equipment_name         string
equipment_category     string (hardware, infrastructure, etc.)
task_type              enum: install | configure | cable | test | commission
title                  string (human-readable, auto-generated)
description            text nullable (from room overview / works_summary in reviewed_data)
sort_order             integer (room order x 100 + item order)
status                 enum: pending | assigned | in_progress | complete | blocked | skipped
blocked_reason         text nullable
assigned_to            FK → users nullable
assigned_at            timestamp nullable
started_at             timestamp nullable
completed_at           timestamp nullable
sign_off_required      boolean default true
timestamps
softDeletes
```

**Why denormalise room_name instead of FK to SiteSurveyRoom:** The task list is generated from ProjectDataService which resolves rooms from reviewed_data (package), not from the SiteSurveyRoom table. The room in reviewed_data may not perfectly match the SiteSurveyRoom.room_name. A string copy is the same pattern used throughout the existing generators (WorksheetGeneratorService copies room name as a string). If the survey room needs linking later, it can be added as a nullable `site_survey_room_id` FK in a later migration.

### `install_task_assignments` (optional pivot — defer unless multi-engineer needed)

For simple single-engineer assignment the `assigned_to` FK on `install_tasks` is sufficient. Add this pivot table only if multiple engineers per task is required. Evaluate during INST-02 (calendar view phase).

```
id
install_task_id   FK → install_tasks
user_id           FK → users
role              string nullable (lead | support)
assigned_at       timestamp
timestamps
```

### `time_entries` (many per project, per user)

Clock in/out records. Linked at project level with optional task FK for finer granularity. This matches the real-world pattern where engineers clock in to the project, not to a specific equipment task.

```
id
project_id         FK → projects
user_id            FK → users
install_task_id    FK → install_tasks nullable (optional finer tracking)
category           enum: travel | installation | cabling | commissioning | survey | management | other
clocked_in_at      timestamp
clocked_out_at     timestamp nullable (null = currently clocked in)
duration_minutes   integer nullable (computed on clock-out, stored for query efficiency)
notes              text nullable
timestamps
softDeletes
```

**Clock-out computation:** Compute `duration_minutes = TIMESTAMPDIFF(MINUTE, clocked_in_at, clocked_out_at)` and store on clock-out. Storing avoids recalculation on every budget-vs-actual query.

**Single active clock-in guard:** Enforce at service level — `TimeTrackingService::clockIn()` checks for any open entry (clocked_out_at IS NULL) for the user before creating a new one.

### `commissioning_records` (one per install_task where sign_off_required)

Per-equipment sign-off record. Attached to the task on completion.

```
id
install_task_id       FK → install_tasks (unique index — one record per task)
completed_by          FK → users
completed_at          timestamp
photo_paths           json nullable (array of storage paths, same pattern as SiteSurveyPhoto)
notes                 text nullable
client_name           string nullable
client_signature_path string nullable (PNG stored in storage/app/private/commissioning/)
client_signed_at      timestamp nullable
timestamps
```

**Photo storage:** Use `storage/app/private/commissioning/{project_id}/{task_id}/` following the existing `storage/app/private/` convention. No public disk exposure — download routes serve via signed URLs or streamed file responses.

---

## Integration with ProjectDataService and reviewed_data

### Task Generation Flow

`InstallTaskGeneratorService` is a direct consumer of `ProjectDataService`, following the same pattern as `WorksheetGeneratorService`:

```php
// InstallTaskGeneratorService::generate(InstallProgramme): void
$data  = $this->projectDataService->resolve($programme->project);
$rooms = $data['rooms'];

foreach ($rooms as $sortIndex => $room) {
    $equipment = $this->filterHardware($room['equipment'] ?? []);
    foreach ($equipment as $itemIndex => $item) {
        InstallTask::create([
            'install_programme_id' => $programme->id,
            'room_name'            => $room['room_name'] ?? $room['name'],
            'equipment_name'       => $item['name'],
            'equipment_category'   => $item['category'] ?? 'hardware',
            'task_type'            => 'install',
            'title'                => 'Install ' . $item['name'],
            'description'          => $room['works_summary'] ?? $room['overview'] ?? null,
            'sort_order'           => ($sortIndex * 100) + $itemIndex,
            'status'               => InstallTask::STATUS_PENDING,
        ]);
    }
    // Cable task per room if cable_route_desc is set
    // Test task per room (always)
}
```

`reviewed_data['room_overviews'][].works_summary` flows through `ProjectDataService → rooms[].works_summary` already (confirmed in `ProjectContextResolver::resolveRooms()`). The task generator reads it from there, not directly from the package — this preserves the single canonical read path.

### Fields from ProjectDataService Used by Task Generation

| Field in resolved data | Used for |
|-----------------------------|---------|
| `rooms[].room_name` | `install_tasks.room_name` |
| `rooms[].equipment[]` (hardware only) | One task per item |
| `rooms[].equipment[].name` | `install_tasks.equipment_name` |
| `rooms[].equipment[].category` | `install_tasks.equipment_category` |
| `rooms[].works_summary` | `install_tasks.description` (pre-fill) |
| `rooms[].cable_route_desc` | Triggers cable-run task creation per room |
| `project.name`, `project.ref` | Programme header data |

No new fields are needed in `ProjectDataService` for v1.2. The existing resolved data is sufficient.

### reviewed_data is Not Modified

v1.2 does not write to `project_packages.reviewed_data`. Tasks are derived from it at generation time, then live independently in `install_tasks`. If the package data is updated and a new programme is generated, old task records are preserved (programme status → archived) and new ones are created under a new programme. This matches the existing worksheet regeneration model.

---

## Service Layer Design

### New Services

**`InstallTaskGeneratorService`** — generates tasks from ProjectDataService data
- `generate(InstallProgramme): void` — creates all task records in one DB transaction
- `filterHardware(array): array` — same exclusion logic as WorksheetGeneratorService (cables, consumables, services, options excluded)
- Called synchronously from controller — no queue needed (no AI call, fast DB inserts)

**`TimeTrackingService`** — clock in/out business logic
- `clockIn(User, Project, string $category, ?int $taskId): TimeEntry`
- `clockOut(User): TimeEntry`
- `activeEntry(User): ?TimeEntry` — returns open entry for clock-out UI state
- `budgetVsActual(Project): array` — aggregates `duration_minutes` by category

**`CommissioningService`** — task completion and sign-off
- `completeTask(InstallTask, User, array $photoFiles, string $notes): CommissioningRecord`
- `attachClientSignature(CommissioningRecord, string $signatureDataUri): void` — converts canvas data URI to PNG, stores to private disk
- `programmeProgress(InstallProgramme): array` — stats (total, complete, blocked, pending counts)

**`InstallProgrammeService`** — high-level orchestration (analogous to OmManualService)
- `createForProject(Project, User): InstallProgramme` — creates programme record, calls generator
- `activate(InstallProgramme): void` — transitions draft to active
- `checkCompletion(InstallProgramme): void` — transitions to completed when all tasks signed off, then advances project lifecycle

### Modified Components (Minimal)

**`Project` model** — add two new relationships:
```php
public function installProgrammes(): HasMany
{
    return $this->hasMany(InstallProgramme::class);
}

public function activeInstallProgramme()
{
    return $this->hasOne(InstallProgramme::class)
        ->where('status', InstallProgramme::STATUS_ACTIVE)
        ->latestOfMany();
}
```

No other existing services or models are modified. `ProjectDataService` remains read-only and unchanged.

---

## Controller and Route Pattern

Follow existing thin-controller convention:

```
InstallProgrammeController   — programme CRUD, generate action, field view
InstallTaskController        — task list, status updates, assignment
TimeEntryController          — clock in/out, time log view, budget summary
CommissioningController      — task completion, photo upload, signature capture
```

All routes under `auth` middleware. No public (token-only) routes needed for v1.2 — engineers use standard authenticated sessions on mobile browsers.

Route structure:
```
GET|POST  /projects/{project}/install-programme          → programme view and generate
GET       /projects/{project}/install-programme/field    → mobile field view
POST      /projects/{project}/time/clock-in              → TimeEntryController
POST      /projects/{project}/time/clock-out             → TimeEntryController
GET       /projects/{project}/time                       → time log and budget view
GET|POST  /tasks/{task}/commissioning                    → sign-off form
POST      /commissioning/{record}/signature              → client signature capture
```

---

## Mobile Field View Architecture

No native app. Responsive Blade + Tailwind + Alpine.js, matching the existing public survey pattern (`PublicSurveyController`, `survey.blade.php`).

**Key mobile UX patterns from existing survey code:**
- Full-width forms, large tap targets using Tailwind responsive classes
- Photo upload via `<input type="file" accept="image/*" capture="environment">` — triggers camera directly on mobile browsers
- Form state managed with Alpine.js `x-data`, same as existing survey room forms

**Signature capture:** Use `<canvas>` with Alpine.js `x-ref` to bind a signature pad. Canvas strokes convert to data URI on submit, POSTed as a hidden field, stored as PNG by `CommissioningService::attachClientSignature()`. This is a confirmed working pattern in the Laravel/Alpine.js ecosystem.

**Clock in/out UI:** Single sticky button at top of field view. Alpine.js tracks active state via a Blade variable seeded from `TimeTrackingService::activeEntry()`. Axios POST on tap, response updates button label and class without page reload. Same pattern as existing Axios usage in `resources/js/bootstrap.js`.

**Progressive enhancement:** The field view must function without JavaScript for core task checklist reading (accessibility and connectivity edge cases). Axios/Alpine.js enhance clock-in and photo preview only.

---

## Component Boundaries

```
Project (existing)
  └─ InstallProgramme  (new — active/draft/archived per project)
       └─ InstallTask[]  (new — many per programme, grouped by room)
            └─ CommissioningRecord  (new — one per task)

User (existing)
  ├─ TimeEntry[]  (new — many per user, linked to project + optionally task)
  └─ InstallTask.assigned_to  (new nullable FK)
```

`Project` has many `InstallProgramme` records (one active, others archived). `InstallProgramme` has many `InstallTask` records. `InstallTask` has one `CommissioningRecord`. `TimeEntry` belongs to `User` and `Project`, optionally to `InstallTask`.

---

## End-to-End Data Flow

```
1. PM triggers "Generate Install Programme" on project dashboard
   InstallProgrammeController::generate()
   InstallProgrammeService::createForProject()   → creates InstallProgramme (status=draft)
   InstallTaskGeneratorService::generate()
     ProjectDataService::resolve($project)        [READ ONLY — no write]
     Creates InstallTask records per room/equipment in one transaction
   Programme status → active
   Redirect to task list

2. Engineer opens field view on mobile (authenticated browser session)
   InstallProgrammeController::fieldView()        loads tasks grouped by room
   Blade renders checklist, clock in/out button seeded from TimeTrackingService::activeEntry()

3. Engineer clocks in
   POST /projects/{id}/time/clock-in
   TimeTrackingService::clockIn()                 validates no open entry, creates TimeEntry

4. Engineer completes task, captures photos
   POST /tasks/{id}/commissioning
   CommissioningService::completeTask()           stores photos, creates CommissioningRecord
   InstallTask.status → complete
   InstallProgrammeService::checkCompletion()     checks if all tasks done

5. Client signature
   POST /commissioning/{record}/signature
   CommissioningService::attachClientSignature()  stores PNG from canvas data URI
   CommissioningRecord.client_signed_at = now()

6. Engineer clocks out
   POST /projects/{id}/time/clock-out
   TimeTrackingService::clockOut()                sets clocked_out_at, computes duration_minutes

7. PM views time budget
   TimeEntryController::summary()
   TimeTrackingService::budgetVsActual($project)  aggregates duration_minutes by category
```

---

## Suggested Build Order

**Phase 12 — Programme and Task Model Foundation**
Everything in v1.2 depends on these models.
- Migrations: `install_programmes`, `install_tasks`
- `InstallProgramme` model, `InstallTask` model with status constants (follow Worksheet model pattern exactly)
- `Project` model additions: `installProgrammes()`, `activeInstallProgramme()`
- `InstallTaskGeneratorService` reading from `ProjectDataService`
- `InstallProgrammeService::createForProject()` and `activate()`
- `InstallProgrammeController` — generate, index, show (admin table view, desktop)
- Unit tests: task generation from mocked ProjectDataService output

**Phase 13 — Task Assignment and Calendar View**
Depends on Phase 12.
- Assignment UI: dropdown per task linked to users table
- `assigned_to` FK already in `install_tasks` from Phase 12
- Calendar/week view: Tailwind grid table, no JS calendar library needed at MVP
- `InstallTaskController::assign()` endpoint

**Phase 14 — Mobile Field View and Task Completion**
Depends on Phase 12. Can parallel-track with Phase 13.
- Migration: `commissioning_records`
- `CommissioningRecord` model
- `CommissioningService::completeTask()` with photo storage
- Responsive field view Blade template (mobile-first Tailwind)
- Photo upload: `<input capture="environment">` + server-side storage
- Task status updates (complete, blocked) via Axios
- `CommissioningController`

**Phase 15 — Time Tracking**
Depends on Phase 12 (project FK). Largely independent of Phases 13/14.
- Migration: `time_entries`
- `TimeEntry` model
- `TimeTrackingService` — clock in/out with open-entry guard
- `TimeEntryController` — clock in/out endpoints, time log view, budget summary
- Mobile clock in/out button added to Phase 14 field view

**Phase 16 — Client Signature and Programme Completion**
Depends on Phase 14 (CommissioningRecord model).
- Canvas signature pad (Alpine.js x-ref, data URI submit)
- `CommissioningService::attachClientSignature()`
- `InstallProgrammeService::checkCompletion()` → project lifecycle transition to STATUS_COMMISSIONING
- Commissioning completion view (summary of all sign-offs, client name, timestamps)

---

## Integration Risks and Pitfalls

**Room name matching between tasks and survey rooms:** Task generator uses room names from `ProjectDataService` (reviewed_data). Survey rooms are stored with their own `room_name` in `site_survey_rooms`. These may not match exactly. Do not attempt a FK join at generation time. If a link is needed later for displaying survey photos next to a task, add a nullable `site_survey_room_id` in a later migration and match by fuzzy string at that point.

**Regenerating tasks when project data changes:** If reviewed_data is updated after a programme is active, tasks are stale. Show a visual warning ("Package data has changed since programme was generated — regenerate?") rather than auto-regenerating, which would destroy assigned/in-progress task state.

**Orphaned clock-in entries:** If an engineer forgets to clock out, the open entry blocks future clock-ins. Add a scheduled Artisan command that auto-closes entries older than 24 hours with a `notes = 'auto-closed by system'` flag. `TimeTrackingService::clockIn()` should surface a clear error message if an open entry exists rather than silently failing.

**Photo storage path collisions:** Use `{project_id}/{task_id}/{uuid}.jpg` as path structure. Never use user-supplied filenames. Follow the existing pattern from `SiteSurveyPhoto` where photos are stored to private disk with UUID names.

**Mobile session persistence:** Engineers on mobile may lose session mid-task if the device sleeps. The clock-in state is a DB query (`TimeTrackingService::activeEntry()`), not session state — this is correct by design. The field view re-reads active entry state on each page load. No localStorage dependency.

**Project lifecycle transition on programme completion:** When all tasks are signed off, `InstallProgrammeService::checkCompletion()` must call `$project->canTransitionTo(Project::STATUS_COMMISSIONING)` before transitioning. The existing state machine on `Project::canTransitionTo()` must be respected — do not bypass it.

**Hardware filter duplication:** `WorksheetGeneratorService` and `InstallTaskGeneratorService` both need the same hardware exclusion logic (cables, consumables, services, options). In Phase 12 this can be duplicated deliberately. After both services exist, extract to a shared trait or static helper class in Phase 16 or as part of a cleanup pass — do not create a shared dependency upfront that couples the two services.

---

## Confidence Assessment

| Area | Confidence | Basis |
|------|------------|-------|
| Integration with ProjectDataService | HIGH | Read service code directly; rooms and equipment shape confirmed |
| Model structure and relationships | HIGH | Follows confirmed Worksheet, SiteSurveyRoom, SiteSurveyPhoto patterns |
| Task generation flow | HIGH | Direct analogue to WorksheetGeneratorService — same read path, no AI call |
| Mobile field view (Blade + Alpine.js) | HIGH | Existing public survey view confirms approach; signature pad pattern confirmed |
| Time tracking schema | MEDIUM | Standard pattern; clock-in guard logic is application-level, not framework |
| Gantt/calendar view (Phase 13) | MEDIUM | Simple Tailwind table is safe at MVP; interactive drag-drop Gantt needs JS library decision deferred |
| Client signature PNG from data URI | MEDIUM | Canvas data URI is confirmed; PHP GD base64_decode + imagecreatefromstring is standard |

---

## Sources

- Codebase: `app/Core/Modules/Projects/ProjectDataService.php` — 4-tier merge and rooms output shape confirmed
- Codebase: `app/Services/WorksheetGeneratorService.php` — task-generation analogue pattern confirmed
- Codebase: `app/Services/ProjectContextResolver.php` — room_overviews and works_summary shape confirmed
- Codebase: `app/Models/Project.php` — lifecycle state machine and transition guards confirmed
- Codebase: `app/Models/Worksheet.php`, `app/Models/SiteSurveyRoom.php` — model conventions confirmed
- [Signature Pad with Alpine.js — Salfade](https://salfade.com/tutorials/signature-pad-with-alpinejs)
- [Laravel Signature Pad Example — ItSolutionstuff](https://www.itsolutionstuff.com/post/laravel-signature-pad-example-tutorialexample.html)
- [Time Tracking Table Design Discussion — Laracasts](https://laracasts.com/discuss/channels/laravel/time-tracking-table-design)
