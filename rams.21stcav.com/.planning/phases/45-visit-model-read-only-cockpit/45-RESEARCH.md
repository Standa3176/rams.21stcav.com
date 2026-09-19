# Phase 45: Visit Model + Read-only Cockpit - Research

**Researched:** 2026-09-19
**Domain:** Laravel 12 / Blade / PHPUnit — additive schema modelling, idempotent console backfill, flag-gated read-only page, and a behaviour-preserving parent-record split of a live feature
**Confidence:** HIGH on the blast radius and all file:line evidence (every claim below was read from source in this session). MEDIUM on the D-06 recommendation (a design judgement grounded in verified constraints). Gaps are flagged inline as "unverified".

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

Copied verbatim from `45-CONTEXT.md` § Implementation Decisions.

- **D-01:** A visit is backfilled from **a `SiteSurvey` row, or a `Worksheet` row that has a
  `WorksheetSignoff`**. A `SiteSurvey` is an attendance by nature. A `Worksheet` is not — its own
  docblock calls it "one worksheet generation run per project", with a
  `pending → generating → draft → final` pipeline — so a worksheet only evidences a trip to site
  when a client signed on site. Unsigned worksheets stay documents and produce no visit.
  This deliberately narrows ROADMAP criterion 2. **Planner: treat D-01 as authoritative over the
  criterion's wording.**

- **D-02:** A backfilled worksheet visit is typed **`install`** and carries an explicit
  **backfilled marker**. No separate `legacy` type.

- **D-03:** Backfilled survey visits are typed **`site survey`** with no per-row discrimination.
  `SiteSurvey.survey_type` is a dead column and must not be used to derive a visit type.

- **D-04:** **A visit survives the record it wraps being superseded or soft-deleted**, and the
  cockpit shows it visibly marked as superseded. Do NOT scope visits away behind the wrapped
  record's soft-delete.

- **D-05:** The backfill is a **separate, idempotent console command**, not migration-embedded.
  Re-running it must skip rows that already have a visit. Follows the Phase 22 one-shot backfill
  precedent.

- **D-06:** **Phase 45 splits `InstallProgramme`** into a durable install record and a regenerable
  task list, and visits hang off the durable half. **Constraint on D-06 — this is the risk in the
  phase.** The split must be *behaviour-preserving*. The planner must treat "existing programme
  generate / activate / archive flow is unchanged, proven by tests against the current behaviour"
  as a hard gate on the split plan, not as a nice-to-have. If the split cannot be made
  behaviour-preserving, stop and raise it rather than relaxing criterion 4.

- **D-07:** The cockpit is built in **21CAV brand** — teal `#01889F`, gold `#D4AF37` accents,
  Verdana headings, Poppins body, angled `clip-path` masthead. **Planner: introduce these as
  reusable tokens, not page-local styles**, because phases 46-51 build on them. Existing pages are
  NOT retoned in this phase.

### Claude's Discretion

- Table and column naming, the visit type enum's storage (string column vs enum vs constants on
  the model), and how the backfilled marker is stored. D-02 requires only that it is explicit and
  queryable.
- How the durable-record / task-list split is shaped (new table vs new columns vs a status-scoped
  relation). D-06 fixes the requirement, not the mechanism.
- The flag's name and location.
- Route placement for the cockpit page.
- Which traffic-light derivations to surface first, within criterion 5's limit that they come from
  data that already exists.

### Deferred Ideas (OUT OF SCOPE)

- Retoning the existing eleven-tab pages to the brand palette.
- Letting the PM retype a backfilled visit (needs write capability — Phase 46 at the earliest).
- Visit costs — deferred for the whole milestone.
- Linking `captured_by` free-text engineer names to `LabourResource` rows.
</user_constraints>

---

<phase_requirements>
## Phase Requirements — proposed VIS-xx set

No VIS IDs exist yet. `.planning/REQUIREMENTS.md:18-20` confirms: *"Total requirements: 5 defined
so far (LR-01..LR-05, Phase 44). Phases 45-51 are outlined in the roadmap but their requirement
IDs are **not yet minted***." The planner mints these into `REQUIREMENTS.md` § Milestone v4.0 as a
new `### Group VIS — Visit model + read-only cockpit (Phase 45)` block.

| ID | Requirement | Maps to | Source |
|----|-------------|---------|--------|
| **VIS-01** | A `visits` table exists carrying a type (site survey / first fix / install / programming / snag / commissioning), its project, its scheduled date, its assigned labour resources, and its status. | Criterion 1 | ROADMAP:365-367 |
| **VIS-02** | Every existing `SiteSurvey` row, and every `Worksheet` row that has at least one `WorksheetSignoff`, is wrapped by a backfilled visit. Nothing is deleted and no existing row is rewritten. | Criterion 2, narrowed by D-01 | ROADMAP:368-370 + D-01 |
| **VIS-03** | Every live `/survey/{token}` and `/worksheet/{token}` link keeps working exactly as it does today. | Criterion 2 | ROADMAP:369-370 |
| **VIS-04** | The cockpit page renders read-only behind a feature flag, on its own route, showing the programme spine and its section drawers closed at rest. | Criterion 3 | ROADMAP:371-372 |
| **VIS-05** | The existing eleven-tab project page is untouched and remains the default. With the flag off the application behaves exactly as it does today — proven by a test, not by inspection. | Criterion 4 | ROADMAP:373-374 |
| **VIS-06** | Traffic lights on the cockpit derive from data that already exists. This phase adds no new engineer-facing capture and no new writes from any user-facing surface. | Criterion 5 | ROADMAP:375-376 |
| **VIS-07** | The backfill is a separate idempotent console command; re-running it creates no duplicate visits. | D-05 | 45-CONTEXT.md D-05 |
| **VIS-08** | A visit survives its wrapped record being superseded or soft-deleted, and the cockpit marks it as superseded rather than hiding it. | D-04 | 45-CONTEXT.md D-04 |
| **VIS-09** | A durable install record exists that is not replaced when the task list is regenerated, and the existing programme generate / activate / archive behaviour is unchanged. | D-06 | 45-CONTEXT.md D-06 |
| **VIS-10** | Brand tokens (teal `#01889F`, gold `#D4AF37`, Verdana headings, Poppins body) are introduced as reusable tokens consumable by phases 46-51, without retoning any existing page. | D-07 | 45-CONTEXT.md D-07 |
</phase_requirements>

---

## Summary

The phase has one genuinely risky element and three routine ones. The routine ones — the `visits`
table, the idempotent backfill command, and the flag-gated read-only page — all have clean,
verified precedents in this repo that can be followed almost mechanically:
`BackfillCablePortFksCommand` for the backfill (dry-run-by-default, per-row outcome categories,
already-set idempotency guard), and `SpikeSchematicController` + `layouts/navigation.blade.php`
for a flag that gates a route *and* a nav link.

The risky element is **D-06**. `InstallProgramme` is not a leaf: it is the parent of `install_tasks`
(cascade delete), `commissioning_items` (cascade delete, plus a second FK to `install_tasks`), and
`commissioning_signoffs` (cascade delete **plus a UNIQUE constraint** on `install_programme_id`).
It is read by 7 controllers, 7 services, 1 observer, 3 Blade views (including the project page's
Linked Records table and the dashboard), 4 factories, and **161 verified test methods** across 29
test files. The ROADMAP's warning about `CommissioningItemGenerator` is **CONFIRMED**, not merely
suspected: `app/Services/CommissioningItemGenerator.php:93` reads `$programme->tasks()` and its own
docblock at lines 17-18 states *"D-05 — data source = install_tasks, never project_packages"*.

The conclusion that matters for planning: **the split can be behaviour-preserving, but only in one
shape — an additive durable parent table ABOVE `install_programmes`, leaving `install_tasks` exactly
where they are.** Any shape that moves, re-parents or extracts the task list is not achievable
behaviour-preservingly inside this phase, because the commissioning subsystem is wired to
`install_tasks` by FK and by 161 tests, and Phase 51 explicitly owns re-sourcing it.

**Primary recommendation:** create a new `install_records` table (one durable row per project), add
a nullable `install_record_id` FK to `install_programmes`, and have `createForProject()`
resolve-or-create that record and stamp the FK. Nothing else changes: `archiveExisting()` is
untouched, all 20+ readers still read `install_programmes`, every existing FK and cascade is
untouched, and visits hang off `install_records`. Capture the 161-test baseline **before** writing
a line of it.

---

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| `visits` schema + `Visit` model | Database / Eloquent model | — | Pure persistence + typed constants; no HTTP surface in this phase |
| Backfill of survey/worksheet to visit | Artisan console command | Database | D-05 requires a deploy-independent, re-runnable decision, not a migration |
| `InstallProgramme` durable split | Database migration + `InstallProgrammeService` | — | The service is the single writer of programme rows (`app/Services/InstallProgrammeService.php:49`); confining the change there confines the blast radius |
| Cockpit page render | Controller + Blade view | — | Server-rendered Blade, no SPA (CLAUDE.md:226). Read-only, so no request-validation tier needed |
| Feature flag | `config/*.php` + `abort_unless` in controller | Blade `@if` for nav | Verified precedent: `SpikeSchematicController.php:21` + `navigation.blade.php:479` |
| Traffic lights | Existing `ProjectHealthService` (reuse) | Blade component | Criterion 5 forbids new capture; the service already returns green/amber/red |
| Brand tokens | Scoped CSS in a `@push('styles')` block or a cockpit-only Vite entry | — | The app's tokens live inline in `layouts/app.blade.php:33+`; editing that `:root` would retone every page, violating D-07 |

---

## THE D-06 BLAST RADIUS (the phase's primary deliverable)

### 1. Every caller of `InstallProgrammeService` methods

`InstallProgrammeService` is injected in exactly **one** place in application code.

| Caller | file:line | Method called |
|--------|-----------|---------------|
| `InstallProgrammeController` (constructor injection) | `app/Http/Controllers/InstallProgrammeController.php:39` | — |
| `InstallProgrammeController::generate()` | `app/Http/Controllers/InstallProgrammeController.php:61` | `createForProject($project, auth()->user())` |
| `InstallProgrammeController::activate()` | `app/Http/Controllers/InstallProgrammeController.php:106-115` | `activate($programme)` |
| Test — service constructed directly | `tests/Unit/InstallTaskGeneratorServiceTest.php:50-52` | `new InstallProgrammeService($generator)` |
| Test — `archiveExisting` | `tests/Unit/InstallTaskGeneratorServiceTest.php:346,357` | `archiveExisting($project)` |
| Test — `createForProject` | `tests/Unit/InstallTaskGeneratorServiceTest.php:521,533` | `createForProject($project, $user)` |
| Test — `activate` guard | `tests/Unit/InstallTaskGeneratorServiceTest.php:300+` | `activate()` |

**`archiveExisting()` has NO external caller.** It is `public` but is only invoked from
`createForProject()` at `app/Services/InstallProgrammeService.php:47` and from the unit test above.
This is good news for the split: the archive path is reachable only through one code path.

**False positives to ignore.** A naive `grep createForProject` returns
`app/Services/Drawings/DrawingService.php:46` and `app/Http/Controllers/ProjectDrawingController.php:125,172`.
Those are `DrawingService::createForProject()` — an unrelated method that *copies the
archive-prior pattern* (`app/Services/Drawings/DrawingService.php:15,31` explicitly cites
"Mirrors the InstallProgrammeService precedent (Phase 12)"). **It does not call it.** Do not
include drawings in the split's scope. It is, however, a second instance of the same anti-pattern,
which the planner may want to note as out-of-scope-but-known.

### 2. Every reader of the `InstallProgramme` model and `install_programmes` table

**Controllers**

| file:line | What it reads |
|-----------|---------------|
| `app/Http/Controllers/InstallProgrammeController.php:86-88` | `review()` — `$programme->load(['tasks','project'])` |
| `app/Http/Controllers/InstallProgrammeController.php:140-160` | `schedule()` — `load(['project','tasks.assignedUser'])`, reads `planned_start_date`/`planned_end_date` |
| `app/Http/Controllers/InstallProgrammeController.php:250-272` | `field()` — `$project->activeInstallProgramme()`, then `$programme->tasks` |
| `app/Http/Controllers/InstallProgrammeController.php:325` | `destroyTask(InstallTask $task)` |
| `app/Http/Controllers/CommissioningController.php:58-59` | `$project->activeInstallProgramme ?? $project->installProgrammes()->latest()->first()` — **the fallback-to-latest pattern D-06 exists to fix** |
| `app/Http/Controllers/CommissioningResyncController.php` | route-bound `{programme}` (`routes/web.php:808`) |
| `app/Http/Controllers/CommissioningSignoffController.php` | route-bound `{programme}` (`routes/web.php:781-789`) |
| `app/Http/Controllers/DashboardController.php:59` | eager-loads `'activeInstallProgramme.tasks'` |
| `app/Http/Controllers/TaskAssignmentController.php:50,97-99,142-144` | route-bound `{programme}`; `$task->load('programme.project')` |
| `app/Http/Controllers/TaskStatusController.php:41,102,144` | `$task->load('programme.project')`; computes programme-level counters |
| `app/Http/Controllers/ProjectController.php:134` | eager-loads `'installProgrammes' => fn($q) => $q->latest()->limit(3)` |
| `app/Http/Controllers/ProjectController.php:242` | Linked Records table — `'records' => $project->installProgrammes` |
| `app/Http/Controllers/ProjectController.php:544` | deliverable auto-flip count — `$project->installProgrammes()->count()` |

**Services**

| file:line | What it reads/writes |
|-----------|---------------------|
| `app/Services/InstallProgrammeService.php:49,86-88,108-116` | the only writer of `install_programmes` rows |
| `app/Services/InstallTaskGeneratorService.php:19,27` | `generate($programme)` — writes `install_tasks` under a programme |
| `app/Services/CommissioningItemGenerator.php:35,39,46,93` | reads `$programme->commissioningItems()`, `$programme->tasks()` |
| `app/Services/CommissioningSyncService.php:42-49,52,64,101` | `resync($programme)`; `$programme->commissioningItems()->withTrashed()`; writes `install_programme_id` |
| `app/Services/CommissioningService.php:53-57` | `InstallProgramme::where('id',...)->lockForUpdate()->firstOrFail()` |
| `app/Services/CommissioningService.php:118` | **writes** `$programme->update(['status' => STATUS_COMPLETE])` |
| `app/Services/CommissioningPdfService.php` | takes a programme (snagging PDF, `routes/web.php:789-801`) |
| `app/Services/TaskAssignmentService.php:69-74,107-112` | `InstallTask::where('install_programme_id', $programme->id)` bulk updates |
| `app/Services/Drawings/DrawingService.php:15,21,31` | **comment reference only** — no functional dependency |

**Models**

| file:line | Relation |
|-----------|----------|
| `app/Models/Project.php:363-365` | `installProgrammes(): HasMany` ordered `created_at desc` |
| `app/Models/Project.php:369-372` | `activeInstallProgramme(): HasOne` — `where status=active`, `latestOfMany()` |
| `app/Models/Project.php:433-441` | `snaggingSignoffs(): HasManyThrough` — **hops Project → InstallProgramme → CommissioningSignoff** |
| `app/Models/InstallProgramme.php:76-99` | `tasks()`, `commissioningItems()`, `commissioningSignoff()` |
| `app/Models/InstallTask.php:94` | `belongsTo(InstallProgramme::class, 'install_programme_id')` (relation named `programme`) |
| `app/Models/CommissioningItem.php:101` | `belongsTo(InstallProgramme::class, 'install_programme_id')` |
| `app/Models/CommissioningSignoff.php:45` | `belongsTo(InstallProgramme::class, 'install_programme_id')` |
| `app/Models/ProjectDeliverable.php:34` | docblock reference only |
| `app/Models/ProjectDrawing.php:22,28` | comment reference only |

**Observer**

| file:line | Behaviour |
|-----------|-----------|
| `app/Observers/InstallTaskObserver.php:26` | injects `CommissioningItemGenerator`; fires `generate()` on `InstallTask` save |
| `app/Providers/AppServiceProvider.php:143-147` | `InstallTask::observe(InstallTaskObserver::class)` |

**This observer matters for the split.** It fires on **every** `InstallTask` save. If the split
changes when or where tasks are created, commissioning-item generation timing changes.

**Blade views**

| file:line | What it renders |
|-----------|-----------------|
| `resources/views/projects/show.blade.php:427` | `$countInstall = $project->installProgrammes->count()` |
| `resources/views/projects/show.blade.php:1780` | `@if ($project->installProgrammes->isEmpty())` |
| `resources/views/projects/show.blade.php:1795` | `@foreach ($project->installProgrammes->sortByDesc('updated_at') as $ip)` |
| `resources/views/dashboard.blade.php:231` | `$programme = $project->activeInstallProgramme` |
| `resources/views.backup-260430/dashboard.blade.php` | dead backup directory — ignore |

Plus 8 programme-specific views under `resources/views/install-programmes/` (`field.blade.php`,
`review.blade.php`, `schedule.blade.php`, and 5 `_field-*` partials) and 5 under
`resources/views/commissioning/`.

**Routes (13)** — `routes/web.php:652-687`, `781-808`. All bind `{programme}` to
`InstallProgramme` by implicit route-model binding on `id`. **Important:** any shape that changes
what `{programme}` resolves to breaks 13 live URLs.

**Factories (4)**

- `database/factories/InstallProgrammeFactory.php:15-37` — default state is `STATUS_ACTIVE`; a `draft()` state exists
- `database/factories/InstallTaskFactory.php:27` — `'install_programme_id' => InstallProgramme::factory()`
- `database/factories/CommissioningItemFactory.php:39` — same
- `database/factories/CommissioningSignoffFactory.php:25` — same

If a new durable parent table is added, `InstallProgrammeFactory` must create-or-link a record, or
**every one of the 161 tests below that uses these factories starts failing on a NOT NULL
constraint.** Make the new FK **nullable** to avoid this entirely.

**Migration that reads the table (1)**

`database/migrations/2026_08_22_150000_backfill_project_deliverables_for_existing_projects.php:77,82`
— `DB::table('install_programmes')->...` then `whereIn('install_programme_id', $installProgrammeIds)`.
Already-run historical migration; do not modify, but `migrate:fresh` in CI replays it.

### 3. Every reader of `InstallTask` / `InstallTaskPhoto` and how they hang off the programme

**`install_tasks` to `install_programmes`:** `database/migrations/2026_04_14_000002_create_install_tasks_table.php:30-32`

```php
$table->foreignId('install_programme_id')
      ->constrained('install_programmes')
      ->cascadeOnDelete();
```

`install_tasks` also carries `softDeletes()` (line 68) and FK `assigned_to` to `users` nullOnDelete
(lines 56-59). `room_name` is **deliberately denormalised — NOT a FK** to `site_survey_rooms`
(lines 34-37, rationale in the migration docblock at lines 11-14). Relevant to the cockpit: a visit
cannot be joined to a task by room ID, only by string.

**`install_task_photos` to `install_tasks`:** `database/migrations/2026_04_20_000001_create_install_task_photos_table.php:23-25`
— `cascadeOnDelete()`. Model at `app/Models/InstallTaskPhoto.php:46`.

**Two-hop cascade chain, verified:** deleting an `install_programmes` row hard-deletes its
`install_tasks`, which hard-deletes their `install_task_photos`, and independently hard-deletes
`commissioning_items` and `commissioning_signoffs`. **The split must therefore never hard-delete a
programme row.** The good news: `archiveExisting()` does not — it is explicitly status-change-only
(`app/Services/InstallProgrammeService.php:101`: *"Archived programmes are retained (soft-deletes
not applied here — status change only)"*).

**Readers of `InstallTask`:** `TaskStatusController` (`:41,102,144`), `TaskAssignmentController`
(`:50`), `TaskAssignmentService` (`:74,112`), `InstallProgrammeController::field()` (`:272`) and
`::destroyTask()` (`:325`), `CommissioningItemGenerator::expectedItems()` (`:93-120`),
`InstallTaskObserver`, `DashboardController:59`, plus the 5 `_field-*` Blade partials.

### 4. `CommissioningItemGenerator` — ROADMAP warning CONFIRMED

The ROADMAP (`.planning/ROADMAP.md:295-297`) warns: *"`CommissioningItemGenerator` currently
derives items from programme tasks; Phase 51 must re-source them from the asset register before
this is safe."*

**CONFIRMED, with three independent pieces of evidence:**

1. `app/Services/CommissioningItemGenerator.php:17-18` — its own decision docblock:
   `D-05 — data source = install_tasks, never project_packages (keeps the generator decoupled from the quote/survey pipeline)`
2. `app/Services/CommissioningItemGenerator.php:93` — `$tasks = $programme->tasks()->orderBy('sort_order')->get();`
3. `app/Services/CommissioningItemGenerator.php:118` — every generated item carries
   `'install_task_id' => $task->id`, and `commissioning_items.install_task_id` is a real FK
   (`database/migrations/2026_04_22_000001_create_commissioning_items_table.php:34-37`, `nullOnDelete`).

It is also **task-save-triggered**: `app/Observers/InstallTaskObserver.php:13` — *"fires
`CommissioningItemGenerator::generate()` synchronously"* — registered at
`app/Providers/AppServiceProvider.php:147`. And `CommissioningSyncService::resync()`
(`app/Services/CommissioningSyncService.php:52`) re-derives from `expectedItems($programme)`, i.e.
from tasks again, guarded by an immutability check once a signoff exists (`:44-49`).

**Planning consequence, stated plainly:** the task list cannot be moved out from under
`install_programmes` in this phase. Doing so would re-point a live FK, change the observer's firing
context, and invalidate `ResyncDiffTest`, `ImmutabilityAfterSignoffTest`, `GenerationTriggerTest`
and `ZeroItemsTest`. Phase 51 owns re-sourcing. **The split must add a parent above, not extract a
child below.**

### 5. The behaviour-preservation test baseline

**161 test methods across 29 files** exercise the programme lifecycle or something wired to it.
Counted this session by `grep -cE "public function test_|#\[Test\]|\* @test"` per file.

| Count | File |
|-------|------|
| 17 | `tests/Unit/InstallTaskGeneratorServiceTest.php` — **the core lifecycle file**: `createForProject` (`:521`), `activate` guard (`:300+`), `archiveExisting` (`:346`) |
| 4 | `tests/Feature/InstallTasks/InstallTaskNotesTest.php` |
| 8 | `tests/Feature/InstallTasks/InstallTaskPhotoUploadTest.php` |
| 8 | `tests/Feature/InstallTasks/InstallTaskStatusUpdateTest.php` |
| 7 | `tests/Feature/FieldView/FieldPageTest.php` |
| 3 | `tests/Feature/FieldView/FieldViewResponsivenessTest.php` |
| 4 | `tests/Feature/Commissioning/CommissioningSchemaTest.php` |
| 4 | `tests/Feature/Commissioning/GenerationTriggerTest.php` |
| 4 | `tests/Feature/Commissioning/ImmutabilityAfterSignoffTest.php` |
| 3 | `tests/Feature/Commissioning/ItemNotesPatchTest.php` |
| 6 | `tests/Feature/Commissioning/ItemPhotoUploadTest.php` |
| 6 | `tests/Feature/Commissioning/ItemStatusPatchTest.php` |
| 3 | `tests/Feature/Commissioning/OwnershipGuardTest.php` |
| 4 | `tests/Feature/Commissioning/ResyncDiffTest.php` |
| 3 | `tests/Feature/Commissioning/SignoffFinaliseTest.php` |
| 2 | `tests/Feature/Commissioning/SignoffRaceTest.php` |
| 3 | `tests/Feature/Commissioning/SignoffSheetViewTest.php` |
| 2 | `tests/Feature/Commissioning/SignoffTransactionTest.php` |
| 6 | `tests/Feature/Commissioning/SnaggingPdfGenerationTest.php` |
| 4 | `tests/Feature/Commissioning/StateTransitionTest.php` |
| 2 | `tests/Feature/Commissioning/ZeroItemsTest.php` |
| 6 | `tests/Unit/Services/CommissioningItemGeneratorTest.php` |
| 5 | `tests/Unit/Services/CommissioningPdfServiceTest.php` |
| 3 | `tests/Unit/Services/CommissioningServiceTest.php` |
| 6 | `tests/Unit/Services/CommissioningSyncServiceTest.php` |
| 6 | `tests/Unit/Models/CommissioningItemTest.php` |
| 5 | `tests/Unit/Models/CommissioningSignoffTest.php` |
| 20 | `tests/Feature/Projects/ProjectDeliverableAutoFlipTest.php` — hits `install-programmes.generate` at `:361` (site 10, `:355`) |
| 7 | `tests/Feature/Authorization/SharedWorkspaceFieldOpsAccessTest.php` — hits `install-programmes.field` at `:95,208` |
| **161** | **TOTAL** |

#### How to run just that subset

`php` is **not** on the Bash tool's PATH on this machine — a piped `php ... | tail` exits 0 while
running nothing, faking a green gate. Use PowerShell with the verified Herd binary at
`C:\Users\sonny.tanda\.config\herd\bin\php84\php.exe` (confirmed present this session).

**PowerShell, from the repo root — the D-06 baseline command:**

```powershell
& "$env:USERPROFILE\.config\herd\bin\php84\php.exe" artisan test `
  tests/Unit/InstallTaskGeneratorServiceTest.php `
  tests/Feature/InstallTasks `
  tests/Feature/FieldView `
  tests/Feature/Commissioning `
  tests/Unit/Services/CommissioningItemGeneratorTest.php `
  tests/Unit/Services/CommissioningPdfServiceTest.php `
  tests/Unit/Services/CommissioningServiceTest.php `
  tests/Unit/Services/CommissioningSyncServiceTest.php `
  tests/Unit/Models/CommissioningItemTest.php `
  tests/Unit/Models/CommissioningSignoffTest.php `
  tests/Feature/Projects/ProjectDeliverableAutoFlipTest.php `
  tests/Feature/Authorization/SharedWorkspaceFieldOpsAccessTest.php
```

Shorter equivalent (broader, catches anything newly added):

```powershell
& "$env:USERPROFILE\.config\herd\bin\php84\php.exe" artisan test --filter="InstallTask|InstallProgramme|Commissioning|FieldView|ProjectDeliverableAutoFlip|SharedWorkspaceFieldOps"
```

**Procedure the plan must follow:** run this **before** any D-06 code, record the exact
`passed / assertions / duration` line in the plan's SUMMARY, then re-run after each task and assert
the same-or-greater pass count with zero failures. `phpunit.xml:37-38` uses sqlite `:memory:`, so
runs are self-contained; the `snapshot` group is excluded by default (`phpunit.xml:21-25`).

Known pre-existing failure, do not chase: `QueueRecoverCommandTest` fails in full-suite runs
(documented in `.planning/STATE.md`, described in the test's own comment as a memory-threshold
interaction). It is not in the subset above, so the subset should be 161/161 clean —
**unverified: I did not execute the suite this session, only enumerated it.**

### 6. FKs, unique constraints and cascade rules constraining the split's shape

| Constraint | file:line | Effect on the split |
|-----------|-----------|---------------------|
| `install_programmes.project_id` to `projects`, nullOnDelete | `...000001_create_install_programmes_table.php:29-32` | Programme survives project delete with a NULL project — a durable record keyed on `project_id` must tolerate this |
| `install_programmes.generated_by` to `users`, nullOnDelete | `:34-37` | — |
| `install_programmes` has `softDeletes()` | `:52` | Model uses `SoftDeletes` (`app/Models/InstallProgramme.php:28`) — a new parent relation must decide `withTrashed()` semantics |
| indexes on `project_id`, `status` | `:54-55` | The `activeInstallProgramme` query is already indexed |
| **no** unique constraint on `install_programmes` | `:26-56` | Multiple programmes per project are legal by design — this IS the D-06 problem |
| `install_tasks.install_programme_id` **cascadeOnDelete** | `...000002_create_install_tasks_table.php:30-32` | Never hard-delete a programme |
| `install_task_photos.install_task_id` **cascadeOnDelete** | `...2026_04_20_000001...:23-25` | Two-hop cascade |
| `commissioning_items.install_programme_id` **cascadeOnDelete** | `...2026_04_22_000001...:27-29` | Third cascade child |
| `commissioning_items.install_task_id` nullOnDelete | `:34-37` | The task-derivation FK confirmed in §4 |
| `commissioning_signoffs.install_programme_id` **UNIQUE + cascadeOnDelete** | `...2026_04_22_000002...:30-33` | **The hardest constraint.** At most one signoff per programme *row*. If the split moved signoffs to the durable record, this unique key's meaning changes from "one per generation" to "one per project" — a semantic change `SignoffRaceTest` and `SignoffTransactionTest` assert against. **Leave it alone.** |
| `worksheet_signoffs.worksheet_id` cascadeOnDelete, **NO unique** | `...2026_04_26_000002...:12,27-29` | Relevant to D-01: a worksheet may have N signoffs. The backfill must create **one visit per worksheet**, not one per signoff |

---

## D-06 RECOMMENDATION

### Verdict: **YES — behaviour-preserving, but only with conditions, and only in one shape.**

### The candidate shapes, evaluated

**Option A — New durable parent table `install_records` (RECOMMENDED)**

One row per project. `install_programmes` gains a **nullable** `install_record_id` FK.
`createForProject()` gains exactly one new step: resolve-or-create the project's `install_record`
and stamp the FK on the new programme row. Visits FK to `install_records`.

| Aspect | Assessment |
|--------|-----------|
| Solves D-06? | **Yes.** The durable row is never archived or replaced; regenerating the task list creates a new `install_programmes` row under the same unchanged parent, so visits are never orphaned |
| Reader churn | **Zero.** All 20+ readers keep reading `install_programmes` unchanged |
| Route churn | **Zero.** All 13 `{programme}` bindings unchanged |
| FK / cascade churn | **Zero.** Every existing FK, unique key and cascade rule untouched |
| Factory churn | **Zero, if the FK is nullable.** 161 tests keep passing without edits |
| `archiveExisting()` | **Untouched** |
| Migration risk | Additive only: one `CREATE TABLE`, one `ADD COLUMN` nullable FK. Reversible |
| Behaviour delta with flag off | One extra INSERT/SELECT inside `createForProject()`. Observable behaviour of generate/activate/archive is identical |

**Option B — New columns on `install_programmes`**

Rejected. It does not solve the problem at all. The failure mode D-06 names is that
`createForProject()` creates a *brand-new row* (`app/Services/InstallProgrammeService.php:49`).
Putting durable fields on a row that is itself replaced changes nothing — visits filed against
`install_programmes.id` are still orphaned on the next regenerate.

**Option C — Status-scoped relation (e.g. "the non-archived programme is the durable one")**

Rejected. This is what already exists: `Project::activeInstallProgramme()`
(`app/Models/Project.php:369-372`) and `CommissioningController.php:58-59`'s
`?? installProgrammes()->latest()->first()` fallback. Both resolve to a row whose `id` changes on
every regenerate. It is the current bug dressed as a design.

**Option D — Extract `install_tasks` into a new regenerable table, keep `install_programmes` durable**

Rejected as **not achievable behaviour-preservingly in this phase.** It would require re-pointing
`commissioning_items.install_task_id` (a live FK with `nullOnDelete`), changing what
`InstallTaskObserver` observes, re-deriving `CommissioningItemGenerator::expectedItems()`, and
renegotiating the `commissioning_signoffs.install_programme_id` UNIQUE constraint's meaning. That
is Phase 51's job per `.planning/ROADMAP.md:295-297, 313`. If the planner is tempted by this shape,
this is the finding CONTEXT.md D-06 asked to be raised: **don't.**

### Conditions the planner must treat as hard gates

1. **The new FK on `install_programmes` must be nullable.** A NOT NULL FK breaks
   `InstallProgrammeFactory` and cascades through 161 tests.
2. **`install_tasks` does not move.** No re-parenting, no new task table, no FK re-pointing.
3. **`archiveExisting()` is not edited.** Diff it to zero lines changed.
4. **No existing FK, unique constraint, cascade rule, index or `softDeletes()` is altered.**
   Especially `commissioning_signoffs.install_programme_id` UNIQUE.
5. **`createForProject()` gains at most one resolve-or-create step**, placed so that
   `archiveExisting()` still runs first — order matters for the existing
   `archiveExisting_archives_all_draft_and_active_programmes_for_project` assertion at
   `tests/Unit/InstallTaskGeneratorServiceTest.php:346`.
6. **A backfill pass links existing `install_programmes` rows** to one `install_records` row per
   `project_id`. This is a second concern from the visit backfill — the planner should decide
   whether it is one command with two modes or two commands. It must be idempotent either way.
   Handle `project_id IS NULL` programmes (legal per the nullOnDelete FK) explicitly — they get
   no record.
7. **The 161-test baseline is captured before any code**, and re-asserted after each task.
8. **A new test proves the durability property itself** — generate, regenerate, and assert the
   `install_record_id` is unchanged across both programme rows while a visit attached to the record
   still resolves. This is the only genuinely *new* assertion the split needs.

### Honest caveat

I did not execute the test suite this session (PHP is not on the Bash PATH and this was a
read-and-analyse task). The 161 figure is a **verified static count of test methods**, not a
verified pass count. The claim "Option A leaves all 161 passing" is a **reasoned prediction** from
the additive-nullable-FK argument, not a measured result. The plan must measure it.

---

## Backfill Feasibility (D-01 / D-02 / D-05)

### How `SiteSurvey`, `Worksheet` and `WorksheetSignoff` relate

```
Project --hasMany--> SiteSurvey           (belongsTo side: SiteSurvey.php:91-94)
        \-hasMany--> Worksheet            (belongsTo side: Worksheet.php:116-118)
                       \-hasMany--> WorksheetSignoff   (Worksheet.php:125-131, ordered signed_at desc, id desc)
```

- `Worksheet::signoffs()` — `app/Models/Worksheet.php:125-131`
- `Worksheet::isSigned(): bool` — `app/Models/Worksheet.php:177-181` — `return $this->signoffs()->exists();`
- `Worksheet::latestSignoff(): ?WorksheetSignoff` — `app/Models/Worksheet.php:169-172`

**The signed-worksheet query is straightforward** — the model already has the exact predicate D-01
needs. Recommended shape:

```php
Worksheet::query()->whereHas('signoffs')->orderBy('id')->get()
```

`whereHas('signoffs')` compiles to an EXISTS subquery; `worksheet_signoffs.worksheet_id` is indexed
(`...2026_04_26_000002...:52`). Alternatively iterate and call `isSigned()` — the Phase 22
precedent iterates a collection and makes a per-row decision, which reads better in report output.

**One visit per worksheet, not per signoff.** `worksheet_signoffs` has **no unique constraint on
`worksheet_id`** — `database/migrations/2026_04_26_000002_create_worksheet_signoffs_table.php:12`
states it verbatim: *"NO unique constraint on worksheet_id: clients can sign multiple times"*, and
`app/Models/WorksheetSignoff.php:13-15` calls the table append-only. A backfill that iterated
signoffs would mint duplicate visits for re-signed worksheets.

### What an idempotent guard should key on

The Phase 22 precedent's guard is a **presence check on the target row, before any work**
(`app/Console/Commands/BackfillCablePortFksCommand.php`, the `already-set` branch):

```php
if ($item->source_device_id !== null || $item->dest_device_id !== null) {
    $summary['already-set']++;
    $this->line("  #{$item->id} — already-set, skipped");
    continue;
}
```

For visits, the equivalent is a check on the **wrapped-record pointer**, which argues for storing it
as a queryable pair rather than free text. Recommendation (within Claude's Discretion per
CONTEXT.md):

- `visits.source_type` (string: `site_survey` | `worksheet`) + `visits.source_id` (unsigned big int),
  with a **unique composite index** on `(source_type, source_id)`.
- Guard: `Visit::where('source_type', ...)->where('source_id', ...)->exists()` then skip.
- The unique index makes double-insert impossible even under a concurrent re-run, which an
  `->exists()` check alone does not.

**Do not make `source_id` a real FK** if D-04 is to hold — see the soft-delete section below. A
polymorphic-style pair with no DB-level FK is the right call here, precisely because a visit must
outlive its wrapped record. Note this deviates from the repo's general "real FKs" habit; document
the reason inline so a later reviewer does not "fix" it.

### The Phase 22 command's shape (the D-05 precedent)

`app/Console/Commands/BackfillCablePortFksCommand.php` — read in full this session.

| Element | file:line | Detail |
|---------|-----------|--------|
| Signature | `:81-83` | `cables:backfill-port-fks {project? : ...} {--apply : ...}` — optional positional scope arg + `--apply` opt-in |
| Description | `:85` | one line, ends with *"Idempotent and dry-run by default."* |
| **Dry-run by default** | `:20-23, 99-105` | Explicitly flips the default vs `RamsRefreshComplianceCommand` because writes span N rows x M projects |
| Constructor DI | `:87-91` | Service injected, `parent::__construct()` called |
| Return | `:93, 120, 207` | `public function handle(): int`, returns `self::SUCCESS` |
| SQL-injection note | `:53-58, 95-97` | `(int)` cast on the arg, reasoned in the docblock |
| Tenant-scoping note | `:60-65` | Per-row project scoping, reasoned in the docblock |
| Empty-set early exit | `:118-121` | `if ($items->isEmpty()) { info(); return SUCCESS; }` |
| **Per-row outcome categories** | `:25-39, 123-129` | `matched` / `ambiguous` / `no-device-match` / `already-set` / `wrote` — a `$summary` array of counters |
| Per-row line output | `:164-169` | `$this->line(sprintf('  #%d — %s: %s', $id, $tag, $reason))` |
| Writes inside a transaction | `:176-184` | `DB::transaction(fn () => $item->update([...]))`, **only** when `$apply && $tag === 'matched'` |
| Summary block | `:189-198` | `$this->info('Summary:')` then one `sprintf` line of all counters |
| Structured log | `:200-204` | `Log::info('cables:backfill-port-fks completed', ['project_id'=>..., 'apply'=>..., 'summary'=>...])` |
| Docblock records decisions | `:13-78` | Cites CONTEXT.md D-LOCK, threat IDs, and what it deliberately does NOT do |

**Console command conventions confirmed across the directory** (`app/Console/Commands/`, 15 files):
`PascalCase` + `Command` suffix (`BackfillCablePortFksCommand.php`,
`BackfillRoomOverviewSummaryCommand.php`), `namespace App\Console\Commands`,
`extends Illuminate\Console\Command`, `protected $signature` / `protected $description`,
colon-namespaced signature (`cables:`, `stencils:`, `rams:`, `ai:`).
Proposed name for this phase: **`visits:backfill`**.

**Reporting:** stdout per-row lines + a counter summary + one structured `Log::info`. No file
artefacts, no DB audit table.

D-05's "separate, not migration-embedded" is doubly justified by repo history: this codebase has 16
backfill *migrations* (`grep -rln backfill database/migrations`), and `.planning/STATE.md` records
real pain from migration-embedded backfills — Phase 29's backfill had to be deployed and run as a
distinct human step, and Phase 30's `30-MEASUREMENT.md` made a corpus regeneration a hard
precondition of arming a flag. A command that can be dry-run on the live VPS is the right tool.

---

## Soft-delete / superseded interaction (D-04)

### The mechanics, verified

| Model | Soft deletes | Superseded mechanism |
|-------|--------------|----------------------|
| `SiteSurvey` | `use SoftDeletes;` — `app/Models/SiteSurvey.php:13` | `superseded_at` — in `$fillable` at `:44`, cast `datetime` at `:67` |
| `Worksheet` | `use HasFactory, SoftDeletes;` — `app/Models/Worksheet.php:29` | none — worksheets are not superseded |
| `WorksheetSignoff` | **none** — `app/Models/WorksheetSignoff.php:27-29` uses only `HasFactory`; docblock at `:13-14`: *"no unique constraint on worksheet_id and no softDeletes"* | n/a |
| `InstallProgramme` | `use HasFactory, SoftDeletes;` — `app/Models/InstallProgramme.php:28` | `STATUS_ARCHIVED` |

### How existing queries treat them — the key finding

**`superseded_at` is NOT a global scope. It is filtered at every call site by hand.**
Verified by `grep -rn "addGlobalScope\|ScopedBy" app` returning **zero results anywhere in the
codebase**, plus these explicit per-query filters:

| file:line | Filter |
|-----------|--------|
| `app/Core/Modules/Projects/ProjectDataService.php:104,108` | `->where('status','completed')->whereNull('superseded_at')` |
| `app/Core/Modules/Survey/SurveyService.php:52` | one-survey-per-project guard — `whereNull('superseded_at')->whereIn('status',['draft','completed'])` |
| `app/Core/Modules/Survey/SurveyService.php:115` | same pattern |
| `app/Http/Controllers/SiteSurveyController.php:81,133` | `->whereNull('superseded_at')` |
| `app/Core/Modules/Survey/SurveyService.php:1012-1022` | `supersedeSurvey()` — `$survey->update(['superseded_at' => now()])` and logs it |

**Soft deletes ARE a global scope** (Laravel's built-in `SoftDeletingScope`), which is the real D-04
hazard. Note even existing code works around it defensively:
`app/Services/ProjectHealthService.php:43` —
`$project->ramsDocuments->filter(fn ($r) => $r->deleted_at === null)` with the comment *"The
ramsDocuments() relation can include deleted records when the model uses SoftDeletes."*

### What a visit needs so it can outlive its wrapped record

1. **No DB-level FK from `visits` to `site_surveys` / `worksheets`.** Every existing FK in this
   schema is `cascadeOnDelete` or `nullOnDelete`. A `cascadeOnDelete` FK would *hard-delete the
   visit* if the survey were force-deleted; `nullOnDelete` would silently detach it. Use the
   `(source_type, source_id)` pair with a unique index and no `constrained()`.
2. **Every read of the wrapped record from the cockpit must use `withTrashed()`.** Otherwise a
   soft-deleted survey resolves to `null` and the visit renders blank. Precedent exists:
   `app/Services/CommissioningSyncService.php:64` — `$programme->commissioningItems()->withTrashed()->get()`.
3. **Denormalise enough onto the visit** that it renders standalone. Both wrapped models already
   denormalise `project_name`, `project_ref`, `client_name`, `site_address`
   (`SiteSurvey.php:18-21`, `Worksheet.php:44-47`) — following that habit for the visit's own
   date/title means the cockpit line survives even a hard-deleted source.
4. **The superseded marker is derived, not stored.** Read `superseded_at` / `deleted_at` off the
   `withTrashed()` source at render time. Storing a copy would go stale the moment someone
   supersedes a survey, and D-04 asks the cockpit to *show* the state, not to own it.
5. **`visits` should almost certainly NOT itself use `SoftDeletes` in this phase** — there is no
   delete path (no writes from any user surface per CONTEXT.md domain). Adding the trait invites a
   global scope the backfill's idempotency check would then have to reason about. Unverified as a
   requirement either way; flagging as a decision for the planner.

---

## Feature-flag mechanics

### The verified route-and-view-gating precedent

`spike_schematic_enabled` is the exact pattern the cockpit needs — it gates a **route** and a
**nav link**, not just service logic.

**1. Config key** — `config/services.php:40-43`:
```php
// /spike/schematic-editor. Default off — set SPIKE_SCHEMATIC_ENABLED=true
'spike_schematic_enabled' => env('SPIKE_SCHEMATIC_ENABLED', false),
```

**2. Route is ALWAYS registered** — `routes/web.php:191-197`:
```php
Route::get('/spike/schematic-editor', [SpikeSchematicController::class, 'show'])
    ->name('spike.schematic.editor');
```
**Important for criterion 4.** The route is registered unconditionally; the flag is enforced in the
controller. This matters because `route('...')` name resolution must work for tests and for the
`@if`-guarded nav link. Conditionally *registering* the route would make `route()` throw when the
flag is off — a worse shape.

**3. Controller enforces it** — `app/Http/Controllers/SpikeSchematicController.php:19-25`:
```php
public function show(): View
{
    abort_unless(config('services.spike_schematic_enabled'), 404);
    abort_unless(auth()->user()?->isAdmin(), 403);

    return view('spike.canvas');
}
```
Note **404, not 403** — the surface does not exist when disabled. That is the right choice for the
cockpit too.

**4. Nav link is `@if`-guarded** — `resources/views/layouts/navigation.blade.php:475-489`:
```blade
{{-- Only shown while SPIKE_SCHEMATIC_ENABLED=true so the link vanishes ... --}}
@if(config('services.spike_schematic_enabled'))
    <a href="{{ route('spike.schematic.editor') }}" ...>
```

**5. Even the view documents its own kill-switch** — `resources/views/spike/canvas.blade.php:46`.

### The other flag convention (`config/rams_tier1.php`)

Long block comments documenting *why* a default was chosen, plus a deliberate doctrine about
defaults. `config/rams_tier1.php:119-134` (read this session):

> *"UNLIKE the two precedents above, this flag's default is FALSE — a deliberate divergence ...
> 'Copy-pasting the `env('RAMS_..._GATE', true)` pattern ... verbatim ... would arm the gate by
> default on next deploy, before the CDM backfill has run — reproducing the exact "content gate
> defaulting ON is a deploy-order trap" failure Phase 28's own retrospective explicitly warns
> against.'"* — `'cdm_ae_gate_enabled' => env('RAMS_CDM_AE_GATE', false),` (`:134`)

**Applies directly here.** The cockpit flag must default `false`, and the deploy order is: ship code
(flag false), run `visits:backfill` dry-run, run `visits:backfill --apply`, then flip the flag as a
separate one-line `.env` change. Do not arm it in the same deploy as the backfill.

### Recommendation

- New file `config/cockpit.php` with `'enabled' => env('COCKPIT_ENABLED', false)`, or a key in
  `config/services.php` alongside the spike flag. A dedicated file is cleaner given phases 46-51
  will add sibling keys (lifecycle, snagging) and the repo already has domain config files
  (`config/rams_tier1.php`, `config/commissioning.php`, `config/rams_theme.php`).
- Global env flag, matching every precedent — CONTEXT.md already names this the default.
- Route registered unconditionally; `abort_unless(config('cockpit.enabled'), 404)` as the first
  line of the controller, then `abort_unless(auth()->check(), 403)` per the shared-workspace
  convention.

---

## Traffic lights — what `ProjectHealthService` already gives you

**File:** `app/Services/ProjectHealthService.php` (201 lines). **DTO:** `app/DTO/ProjectHealth.php`.

### Shape returned

`app/DTO/ProjectHealth.php:17-24` — a `readonly class` with three public properties:

```php
readonly class ProjectHealth {
    public function __construct(
        public string $status,   // 'green' | 'amber' | 'red'
        public string $reason,   // human-readable explanation
        public bool   $overdue,  // true when current stage active > 14 days
    ) {}
}
```

### What it already derives

Entry point `assess(Project $project): ProjectHealth` at `:38`. First-match-wins, RED then AMBER
then GREEN.

| Tier | Rule | file:line |
|------|------|-----------|
| RED | any RAMS `STATUS_FAILED` | `:52-54` |
| RED | status ENGINEERING and no approved-or-beyond RAMS (unless deliverable explicitly not_required) | `:56-60` |
| RED | status SURVEY_PENDING, no submitted survey, stage age > 14d | `:62-68` |
| AMBER | stage duration > 7 days | `:72-74` |
| AMBER | any RAMS `STATUS_AWAITING_REVIEW` | `:76-78` |
| AMBER | ENGINEERING with RAMS stuck `UPLOADED` / `AWAITING_REVIEW` | `:80-86` |
| AMBER | a deliverable on `not_yet_decided` past a 7-day grace (D-13) | `:110-135` |
| GREEN | `'On track'` | `:137` |

Supporting helpers: `stageStartTimestamp()` (`:169-189`) maps project status to its `*_started_at`
milestone column; `approvedOrBeyond()` (`:191-200`); `isExplicitlyNotRequired()` (`:159-162`).

### The hard contract the cockpit must honour

`app/Services/ProjectHealthService.php:13-15`:

> *"Derives a per-project health status (green/amber/red) from already-loaded Eloquent relations.
> **MUST NOT** call `$project->relation()->get()` or issue any additional DB queries — caller is
> responsible for eager-loading."*

Required eager-loads, per `:32-36`: `ramsDocuments`, `siteSurveys`, `deliverables`. The canonical
caller does exactly that — `app/Http/Controllers/DashboardController.php:28` injects the service and
`:59` shows the eager-load list including `activeInstallProgramme.tasks`.

There is also an existing render component:
`resources/views/components/dashboard/health-badge.blade.php:4` — *"Maps ProjectHealth DTO status
values to pill colours."*

### What the cockpit can reuse unchanged, and what it cannot

**Reuse unchanged:** the whole service, for the **project-level** light. Inject
`ProjectHealthService`, eager-load the three relations, call `assess()`, render the pip. Zero new
capture, zero new writes — criterion 5 satisfied trivially.

**What it does NOT give you:** a **per-section / per-visit** light. The sketch
(`cockpit-sections.html:45-46`) needs three pip states per drawer — `.pip--done` (teal),
`.pip--attn` (gold), `.pip--wait` (grey). `ProjectHealthService` returns one status for the whole
project. Per-section pips must be derived from existing data by other means, e.g.:

- Survey section: `SiteSurvey.status` (`isDraft()` / `isCompleted()` at `SiteSurvey.php:112-120`) +
  `submitted_at` + `superseded_at`
- Worksheet/install section: `Worksheet::isSigned()` (`Worksheet.php:177`), `statusBadgeClass()`
  (`:242-250`), `isStale()` (`:287`), `hasEngineerActivity()` (`:375`), `isReadyForSignoff()` (`:420`)
- Programme section: `InstallProgramme::statusLabel()` / `statusBadgeClass()` / `isDraft()` /
  `isActive()` (`InstallProgramme.php:106-145`)
- Commissioning: `$programme->commissioningSignoff()->exists()` (`InstallProgramme.php:96-99`)
- Deliverable-level: `Project::deliverableState($key)` (`Project.php:447+`) — the three-state model

**Recommendation:** use `ProjectHealthService` unchanged for the masthead / attention line, and
build per-section pips from the existing model helpers above — do **not** extend
`ProjectHealthService`, because doing so risks changing what the dashboard renders, which is a
flag-off behaviour change and therefore a criterion-4 violation.

Also note the sketch's own design rule (`README.md` § Design decisions): *"Programming carries a
box, not a light — nothing can evidence it."* This is corroborated in code:
`ProjectHealthService.php:105-107` — *"Programming (KEY_PROGRAMMING) is skipped entirely: no model,
table, or relation exists for it anywhere in this codebase (D-05)."* The sketch and the service
independently reached the same conclusion. Honour it.

---

## Blade / view conventions and the asset pipeline (D-07)

### How CSS is organised — the finding that shapes the D-07 plan

**Pipeline:** Vite + Tailwind + PostCSS. `vite.config.js` inputs are `resources/css/app.css`,
`resources/js/app.js`, `resources/js/rack-editor.js`, `resources/js/spike/main.jsx`. The
rack-editor and spike entries prove **per-page bundles are an established pattern in this repo** — a
cockpit-only CSS entry would be idiomatic.

**`resources/css/app.css` is only 56 lines.** It self-hosts Inter
(`@import "@fontsource-variable/inter"` at `:6`), pulls in `@tailwind base/components/utilities`
(`:8-10`), and adds a small `@layer base` block plus three utilities. It contains **no design
tokens**.

**`tailwind.config.js`** holds the Tailwind theme: `fontFamily.sans` = Inter (`:23-26`), and a
colour set described in its own docblock as *"Jetbuilt-clean (2026-07-09) — Navy primary + electric
blue accent"*. `brand.700` / `accent.700` = `#1E5FE0`. Note `brand.teal` is mapped to `#2E7BFF`
(a blue) at `:69-77` with a comment explaining it was added so 60+ `text-brand-teal` classes in
`surveys/show.blade.php` would resolve. **Do not reuse the name `teal` for the 21CAV teal** — it is
already taken and means blue.

**THE CRITICAL FINDING: there is no token file. The tokens live inline in the layout.**
`resources/views/layouts/app.blade.php` is **2,154 lines**, and its `:root` block starts at **line
33** with `--ink-900`, `--paper`, `--nav-*`, `--accent-*`, and a large set of legacy aliases
(`--teal-900: var(--nav-900)` at `:65`, `--brand-*` at `:73-78`). Hundreds of lines of component CSS
follow in the same inline `<style>` (e.g. the command palette at `:1493-1515`).

**Therefore: editing `layouts/app.blade.php`'s `:root` to add 21CAV tokens would retone every page
in the app**, directly violating D-07 (*"Existing pages are NOT retoned in this phase"*).

### Where reusable tokens should live so phases 46-51 inherit them without duplication

The layout provides exactly the right seam: **`@stack('styles')` at
`resources/views/layouts/app.blade.php:1322`** — i.e. *after* the `:root` block, so a pushed style
block wins on source order at equal specificity.

Recommended shape (Claude's Discretion territory, but this is the shape the evidence supports):

1. Create **one** partial, e.g. `resources/views/components/cockpit/_brand-tokens.blade.php`, or a
   dedicated CSS file `resources/css/cockpit.css` added as a new `vite.config.js` input (mirroring
   the `rack-editor.js` precedent).
2. **Scope the tokens, do not put them on `:root`.** Declare them on a wrapper class so nothing
   outside the cockpit tree can inherit them:
   ```css
   .cockpit {
     --c-teal:#01889F; --c-teal-dark:#016E82; --c-teal-soft:#E6F4F7;
     --c-gold:#D4AF37;  --c-gold-soft:#FAF4E4;
     --c-head:'Verdana Pro',Verdana,'Trebuchet MS',sans-serif;
     --c-body:'Poppins','Segoe UI',Arial,sans-serif;
   }
   ```
   A `c-` (or `cav-`) prefix avoids collision with the existing `--teal-*` aliases, which mean blue.
3. Phases 46-51 `@include` the same partial (or import the same CSS entry) and nest under
   `.cockpit`. One file to change, no duplication, zero reach into existing pages.

### Verified brand values

`.planning/reference/21cav-rams-skill/scripts/brand.js:7-28` —
*"---- Brand tokens (21cav-brand skill) ----"*: `TEAL=01889F`, `TEAL_DARK=016E82`,
`TEAL_LIGHT=E6F4F7`, `GOLD=D4AF37`, `GOLD_LIGHT=F5EDD6`, `TEXT=1A1A1A`, `TEXT_MID=444444`,
`TEXT_LIGHT=767676`, `BG_LIGHT=F8F8F8`, `BORDER=E0E0E0`, `HEAD_FONT="Verdana"`,
`BODY_FONT="Poppins"`.

These match the sketch's own `:root` at
`.planning/sketches/002-install-cockpit/cockpit-sections.html:11-18`. One discrepancy: the skill has
`GOLD_LIGHT=F5EDD6`, the sketch has `--gold-soft:#faf4e4`. The sketch is the visual contract per
`REQUIREMENTS.md:16-17` (*"where an implementation and a sketch disagree, the sketch wins"*), so use
`#FAF4E4`.

**Unverified:** CONTEXT.md canonical_refs names "The `21cav-brand` skill". No `SKILL.md` for it
exists in this repo or in `~/.claude/skills` (checked both). The only in-repo artefact is
`.planning/reference/21cav-rams-skill/` (which has a `SKILL.md`) and its `scripts/brand.js` token
block above. Treat `brand.js` as the citable source; the "21cav-brand skill" itself is not present.

### Poppins is not currently loaded

The app self-hosts **Inter** only (`resources/css/app.css:6`). The sketch loads Poppins from Google
Fonts (`cockpit-sections.html:7-9`). Planner decision needed: a Google Fonts `<link>` is a new
external dependency on a page in an internal app, whereas the rest of the app deliberately
self-hosts ("audit-clean self-host", `app.css:1`). Recommend `npm i @fontsource/poppins` and import
from the cockpit CSS entry, matching the existing self-host doctrine. **Unverified** whether
`@fontsource/poppins` is already in `node_modules`.

### Blade conventions (from CLAUDE.md, verified against the tree)

- Views kebab-case; partials prefixed `_` — `CLAUDE.md:169`, confirmed by
  `resources/views/install-programmes/_field-room.blade.php` etc.
- Server-rendered Blade, no SPA — `CLAUDE.md:226`. Alpine.js for interactivity
  (`resources/js/app.js`). The sketch's drawers use native `<details>` / `<summary>`
  (`cockpit-sections.html:39-44`), which needs no JS at all — ideal for a read-only page.
- Controllers thin, `abort_unless(auth()->check(), 403)` — *"shared workspace: any authenticated
  user has full access"* (`app/Http/Controllers/CommissioningController.php:50-51`). Do not add
  role-based auth beyond `EnsureUserIsAdmin`.
- Logging prefixed with class name — `CLAUDE.md:200`, e.g.
  `Log::info('InstallProgrammeController: programme generated', [...])` at
  `InstallProgrammeController.php:65`.

---

## The Phase 44 privacy guard — how to extend it

**File:** `tests/Feature/Security/LabourResourceClientSurfacePrivacyTest.php` (406 lines, 7 test
methods). Read in full this session.

### How it works

Two halves.

**Half 1 — static source scan.** A `private const CLIENT_FACING_PATHS` array at `:95-124` lists 20
paths relative to `base_path()`. `test_no_client_facing_file_references_labour_resource()` at
`:128-152` loops them, `file_get_contents`, and asserts none contains the literal
`private const MARKER = 'LabourResource'` (`:88`).

**Half 2 — real HTTP render.** Four tests (`:196-268`) seed a `LabourResource` with a distinctive
email/phone and GET `route('survey.show', ...)` and `route('public-worksheet.show', ...)`, asserting
the response body contains neither. Plus `test_to_client_safe_array_returns_only_id_and_name()` at
`:270`.

**Two anti-rot guards:**
- `test_every_enumerated_path_exists()` at `:154-163` — `assertFileExists` on every listed path, so
  a renamed file fails loudly rather than silently reducing coverage. Its message: *"the list has
  drifted from the real repo and this test is silently covering less than it claims to."*
- `test_guard_would_fail_if_a_client_facing_view_referenced_labour_resource()` at `:172-192` — a
  non-vacuity check that appends a simulated regression **in memory only** and asserts the marker
  logic would catch it.

### Exactly how a new view gets added

**One edit: append the path to the `CLIENT_FACING_PATHS` array at
`tests/Feature/Security/LabourResourceClientSurfacePrivacyTest.php:95-124`.** Both Half-1 tests pick
it up automatically. Nothing else is required for the static half.

**But read the class docblock's own warning first — `:91-93`:**
> *"Every genuinely client-facing file in scope, relative to `base_path()`. Re-derived from a live
> grep/find of the repo at plan execution time (see class docblock) — do not hand-copy without
> re-checking."*

And `:51-61`, which explicitly forbids adding a staff-auth surface to the list:
> *"`app/Http/Controllers/WorksheetController.php`'s `worksheets/{worksheet}/engineer-report.pdf`
> route (and its `resources/views/pdf/engineer-report.blade.php` view) is DELIBERATELY EXCLUDED from
> both halves. It is a staff-auth route living OUTSIDE the `survey/{token}` / `worksheet/{token}`
> prefix blocks ... so it is not client-facing at all ... Do not "fix" this by adding it to the
> scan; that would misclassify a staff-only surface as client-facing."*

### The nuance the planner must resolve

**The Phase 45 cockpit is NOT client-facing.** It is an authenticated staff page
(`abort_unless(auth()->check(), 403)`), directly analogous to the deliberately-excluded
engineer-report PDF. By the test's own stated criterion — "does an unauthenticated client with a
token reach it?" — the cockpit does **not** belong in `CLIENT_FACING_PATHS`.

However, the CONTEXT.md scout note says the test *"will need the new cockpit and visit views added
to its file list"*. These are in tension.

**Recommendation:** two options; the planner picks and documents the reasoning inline (the test's
own docblock culture demands it):

- **Option 1 (faithful to the test's criterion):** do **not** add the cockpit; instead add an
  explicit exclusion note in the class docblock, modelled on the engineer-report note at `:51-61`,
  recording *why* the cockpit is staff-only. This keeps the test's semantics honest and prevents a
  future agent from wrongly concluding the list is stale.
- **Option 2 (belt-and-braces, satisfies the scout note literally):** add the cockpit view to the
  list anyway. Harmless today (the cockpit is read-only and has no reason to touch
  `LabourResource`), but it **will** need removing in Phase 46 when visits gain real
  labour-resource assignment display — at which point the guard would block legitimate work. If
  taken, document the expiry.

Either way: **any genuinely client-facing view this phase adds (there should be none) must go in the
list**, and LR-04's coverage claim must be re-checked by running
`grep -n "survey/{token}\|worksheet/{token}" routes/web.php`, which is the derivation the test
docblock itself prescribes at `:67-71`.

---

## Don't Hand-Roll

| Problem | Don't build | Use instead | Why |
|---------|-------------|-------------|-----|
| green/amber/red project status | A new derivation in the cockpit controller | `app/Services/ProjectHealthService.php::assess()` + `app/DTO/ProjectHealth.php` | Already encodes 7 prioritised rules with real production-data fixes baked in (`:96-110` records an 89-project measurement that overturned a naive design). Criterion 5 also forbids new derivation sources |
| green/amber/red pill markup | New CSS | `resources/views/components/dashboard/health-badge.blade.php` | Already maps DTO status to pill colour |
| "is this worksheet signed / stale / ready" | New queries | `Worksheet::isSigned()` `:177`, `isStale()` `:287`, `hasEngineerActivity()` `:375`, `isReadyForSignoff()` `:420`, `statusBadgeClass()` `:242` | Six read-only derivations already exist and are already trusted by the worksheet UI |
| programme status label / badge | New match block | `InstallProgramme::statusLabel()` `:106`, `statusBadgeClass()` `:120`, `isDraft()` `:134`, `isActive()` `:142` | Same |
| "does this project have snagging" | A new join | `Project::snaggingSignoffs()` `:433-441` | Its docblock at `:418-432` explicitly says *"do not look for (or add) a `Project::snagging()` shortcut or a standalone Snagging model; neither exists"* |
| deliverable state per key | Reading the relation directly | `Project::deliverableState($key)` `:447+` | Deliberately safe to call without a query — checks `relationLoaded()` first |
| backfill command scaffolding | From scratch | Copy `BackfillCablePortFksCommand` structure wholesale | Dry-run default, outcome categories, already-set guard, transaction-wrapped writes, summary + structured log all solved |
| accordion/drawer JS | Alpine `x-show` state | native `<details>` / `<summary>` per `cockpit-sections.html:39-44` | Zero JS, keyboard-accessible, works with the read-only constraint |
| document path building | `storage_path('app/...')` | `App\Services\DocumentArtifactStorage` | `CLAUDE.md:127-131` (H-07) — hard convention. Not obviously needed this phase, but flagged |

**Key insight:** this phase is overwhelmingly a *reading* phase. Almost every fact the cockpit needs
to render already has a named, tested accessor. The failure mode to guard against is re-deriving one
of them slightly differently and producing a cockpit that disagrees with the eleven-tab page.

---

## Common Pitfalls

### Pitfall 1: A NOT NULL FK on `install_programmes` detonates 161 tests
**What goes wrong:** the new durable-record FK is added `NOT NULL`; `InstallProgrammeFactory`
(`database/factories/InstallProgrammeFactory.php:17-29`) does not set it; every test using
`InstallProgramme::factory()` — directly or transitively via `InstallTaskFactory:27`,
`CommissioningItemFactory:39`, `CommissioningSignoffFactory:25` — fails on a constraint violation.
**How to avoid:** make it nullable. Backfill existing rows in a separate pass.
**Warning sign:** a single migration causing hundreds of failures in unrelated suites.

### Pitfall 2: Hard-deleting a programme row triggers a three-table cascade
**What goes wrong:** any cleanup that force-deletes `install_programmes` wipes `install_tasks` then
`install_task_photos`, plus `commissioning_items` and `commissioning_signoffs`. All four FKs are
`cascadeOnDelete`.
**How to avoid:** never hard-delete. `archiveExisting()` already only changes status
(`InstallProgrammeService.php:101,116-117`) — preserve that.

### Pitfall 3: A FK from `visits` to `site_surveys` / `worksheets` breaks D-04
**What goes wrong:** `cascadeOnDelete` destroys the visit when the source is force-deleted;
`nullOnDelete` silently detaches it. Either way the trip to site is un-happened, which D-04 forbids.
**How to avoid:** `(source_type, source_id)` pair, unique composite index, **no `constrained()`**.
Document the deviation from the repo's FK habit inline.

### Pitfall 4: Soft-delete global scope blanks the cockpit
**What goes wrong:** the cockpit resolves a visit's source without `withTrashed()`; a soft-deleted
survey returns `null`; the line renders empty or throws on `?->`.
**How to avoid:** `withTrashed()` on every source lookup, plus enough denormalised fields on the
visit to render standalone. Precedent: `CommissioningSyncService.php:64`,
`ProjectHealthService.php:43`.

### Pitfall 5: `superseded_at` is not a scope, so it is easy to *over*-filter
**What goes wrong:** copying `->whereNull('superseded_at')` from `SiteSurveyController.php:81` into
the cockpit's visit query hides exactly the superseded visits D-04 requires be shown.
**How to avoid:** the cockpit query must NOT filter `superseded_at`; it reads it to set a marker.

### Pitfall 6: One visit per signoff instead of per worksheet
**What goes wrong:** iterating `WorksheetSignoff` rows. `worksheet_signoffs` has no unique
constraint on `worksheet_id` (migration `:12`), so a re-signed worksheet yields two visits.
**How to avoid:** iterate `Worksheet::whereHas('signoffs')`, one visit per worksheet.

### Pitfall 7: Deriving visit type from `SiteSurvey.survey_type`
**What goes wrong:** the column is dead (D-03) — superseded by room-level `space_type` and
defaulting to `general`. Using it produces meaningless types.
**How to avoid:** hardcode `site survey` for all backfilled survey visits, per D-03.

### Pitfall 8: Retoning the whole app by editing the layout's `:root`
**What goes wrong:** brand tokens added to `resources/views/layouts/app.blade.php:33+` change every
page. Violates D-07 and criterion 4.
**How to avoid:** scoped `.cockpit { ... }` tokens via `@stack('styles')` (`app.blade.php:1322`) or a
separate Vite entry.
**Warning sign:** any diff touching `layouts/app.blade.php` lines 33-120 or `tailwind.config.js`.

### Pitfall 9: Naming a 21CAV-teal token `--teal-*` or `brand.teal`
**What goes wrong:** `--teal-700` already exists and aliases to `#1E5FE0` blue
(`app.blade.php:65-71`); `tailwind.config.js:69-77`'s `brand.teal` is `#2E7BFF` blue, load-bearing
for 60+ classes in `surveys/show.blade.php`. Redefining either breaks live pages.
**How to avoid:** prefix new tokens distinctly (`--c-teal`, `--cav-teal`).

### Pitfall 10: Blade `@php` short-form with `?->` / `??`, and glued `@if`
**What goes wrong:** repo-wide known trap. Compiled Blade becomes syntactically invalid PHP; the
error surfaces far from the cause.
**How to avoid:** avoid `@php(...)` short-form entirely in new views; always whitespace-separate
`@if` from adjacent output. Lint compiled views with `php -l` **via PowerShell**, not Bash:
```powershell
Get-ChildItem storage/framework/views/*.php |
  ForEach-Object { & "$env:USERPROFILE\.config\herd\bin\php84\php.exe" -l $_.FullName }
```

### Pitfall 11: Running PHP through the Bash tool
**What goes wrong:** `php` is not on the Bash tool's PATH; `php artisan test | tail` exits 0 having
run nothing — a fake green gate. Verified this session: `php.exe` exists only at
`C:\Users\sonny.tanda\.config\herd\bin\php84\php.exe`.
**How to avoid:** every PHP invocation goes through PowerShell with the explicit Herd path.

### Pitfall 12: Re-adding a deliberately-omitted `$fillable` key
See Project Constraints below. Any `SiteSurvey` / `Worksheet` touch risks a well-meaning agent
"completing" the `$fillable` array.

### Pitfall 13: Conditionally registering the cockpit route
**What goes wrong:** wrapping `Route::get` in `if (config(...))` makes `route('cockpit.show')` throw
when the flag is off, breaking nav rendering and flag-off tests.
**How to avoid:** always register; enforce in the controller with `abort_unless(..., 404)`, per
`routes/web.php:196` + `SpikeSchematicController.php:21`.

### Pitfall 14: Arming the flag in the same deploy as the backfill
**What goes wrong:** the cockpit renders against an empty `visits` table and looks broken; or worse,
the "content gate defaulting ON is a deploy-order trap" failure this repo has already suffered
(`config/rams_tier1.php:119-132`).
**How to avoid:** flag defaults `false`; deploy, dry-run, apply, then a separate one-line `.env` flip.

---

## Project Constraints (from CLAUDE.md)

Directives extracted from `./CLAUDE.md` that bear on this phase. Treat with the same authority as
locked decisions.

| Directive | CLAUDE.md:line |
|-----------|----------------|
| **Must not break the existing pipeline** — RAMS pipeline, extracted/reviewed/generated data flow, queue-based generation | `:15` |
| Architecture: service-based, **thin controllers**, shared data services, **safe migrations**, queue-compatible | `:16` |
| Data integrity: all document content must trace back to quote / survey / reviewed inputs | `:14` |
| PHP ^8.2; PHPUnit ^11.5.3 | `:22, :36` |
| Server-rendered Blade, **no SPA** | `:226` |
| Models PascalCase singular; Services `*Service`; Controllers `*Controller`; Blade kebab-case, partials `_`-prefixed | `:165-169` |
| Boolean methods prefixed `is` / `has` | Naming section |
| Model status constants `STATUS_UPPERCASE_SNAKE` | Naming section |
| PSR-12, Laravel Pint, 4-space indent; aligned array keys | Code Style section |
| ASCII-art comment dividers (`// -- Label --`) in services/controllers | Code Style section |
| `abort_if()` / `abort_unless()` for inline checks | Error Handling section |
| Log messages prefixed with class name: `'ClassName: message'` | `:200` |
| Generated documents **must** go through `DocumentArtifactStorage` (H-07) — never hand-built paths | `:127-131` |
| Tests fake the disk with `Storage::fake('documents')` | `:131` |

### Additional repo-specific constraints (not from CLAUDE.md)

**Deliberate `$fillable` omissions — MUST be preserved.** Quoted verbatim so the planner sees them:

`app/Models/SiteSurvey.php:47-57`:
```
// Re-audit S-03 — `access_token` dropped from $fillable so no
// `$survey->update([...])` payload can rotate the public engineer
// link. The only writer is boot::creating() (line 72) which uses
// direct property assignment and bypasses $fillable.
...
// Re-audit S-02 — `submitted_notification_sent_at` dropped from
// $fillable so a client can't fake "office notified {N min ago}"
// via a validated payload. The one legitimate writer
// (SurveyService::submitPublic) uses ->forceFill() which bypasses
// $fillable, so behaviour is unchanged.
```

`app/Models/Worksheet.php:52-58`:
```
// Re-audit S-03 — `access_token` and `access_token_expires_at` are
// dropped from $fillable so no `$w->update([...])` payload can
// silently rotate a live public sign-off token. The two
// legitimate writers use direct property assignment:
//   - boot::creating() (line 71) sets initial UUID
//   - WorksheetController::revokeToken via regenerateAccessToken() (line 192)
// Both bypass $fillable, so behaviour is unchanged.
```

`app/Models/Worksheet.php:98-107`:
```
// -- Mass-assignment safety (quick task 260726-fx4 Task 3) --------------
//
// signed_notification_sent_at is deliberately NOT added to $fillable.
// Per the 260709 audit pattern for auth-tokened flags, mail-loop timestamps
// must only be written via forceFill() by the controller AFTER Mail::send
// returns cleanly. A `$worksheet->update(['signed_notification_sent_at'
// => ...])` payload could otherwise be triggered from the public sign-off
// form and silently mark a worksheet as "notified" when no mail was
// actually sent. Direct property write + save() is the only permitted
// path (PublicWorksheetController::sign line ~370).
```

**Public token routes must keep working unchanged.** 14 `survey/{token}` routes at
`routes/web.php:80-99` and 10 `worksheet/{token}` routes at `routes/web.php:112-160`. Both models
auto-generate `access_token` in `boot::creating()` (`SiteSurvey.php:77-81`, `Worksheet.php:75-79`).
The backfill must be read-only against these models — it must not `save()` a survey or worksheet,
because `boot` hooks and observers fire on save. **The backfill writes ONLY to `visits`.**

---

## Code Examples

### Flag-gated read-only controller (adapted from the verified spike precedent)

```php
// Pattern source: app/Http/Controllers/SpikeSchematicController.php:19-25
//                 + app/Http/Controllers/CommissioningController.php:50-51
public function show(Project $project): View
{
    abort_unless(config('cockpit.enabled'), 404);
    // Shared workspace: any authenticated user has full access.
    abort_unless(auth()->check(), 403);

    // ProjectHealthService MUST NOT query — eager-load first.
    // Source: app/Services/ProjectHealthService.php:13-15, :32-36
    $project->load(['ramsDocuments', 'siteSurveys', 'deliverables']);
    $health = $this->health->assess($project);

    return view('projects.cockpit', compact('project', 'health'));
}
```

### Flag key with a documented default (verified convention)

```php
// Pattern source: config/services.php:40-43 and the doctrine comment at
// config/rams_tier1.php:119-134 — a surface gate must default FALSE so it
// cannot arm itself on deploy before its backfill has run.
return [
    'enabled' => env('COCKPIT_ENABLED', false),
];
```

### Idempotent backfill skeleton (structure lifted from the Phase 22 precedent)

```php
// Pattern source: app/Console/Commands/BackfillCablePortFksCommand.php:81-85, 99-105, 118-129, 176-204
protected $signature = 'visits:backfill
                        {project? : Project ID to scope the backfill (default: all projects)}
                        {--apply : Actually create visits (default: dry-run reports only)}';

protected $description = 'Create wrapping Visit rows for existing site surveys and signed worksheets. Idempotent and dry-run by default.';

public function handle(): int
{
    $projectId = $this->argument('project') !== null ? (int) $this->argument('project') : null;
    $apply     = (bool) $this->option('apply');

    $this->info($apply ? 'visits:backfill — APPLYING writes.'
                       : '[DRY RUN] visits:backfill — pass --apply to persist.');

    $summary = ['survey' => 0, 'worksheet-signed' => 0,
                'worksheet-unsigned-skipped' => 0, 'already-wrapped' => 0, 'wrote' => 0];

    // ... per-row: already-wrapped guard FIRST (never re-check the source),
    //     then DB::transaction(fn () => Visit::create([...])) only when $apply.

    $this->newLine();
    $this->info('Summary:');
    $this->line(sprintf('  survey: %d | worksheet-signed: %d | unsigned-skipped: %d | already-wrapped: %d | wrote: %d',
        ...array_values($summary)));

    Log::info('visits:backfill completed', ['project_id' => $projectId, 'apply' => $apply, 'summary' => $summary]);

    return self::SUCCESS;
}
```

### Signed-worksheet predicate (D-01)

```php
// Source: app/Models/Worksheet.php:125-131 (signoffs relation), :177-181 (isSigned)
// One visit per WORKSHEET, never per signoff — worksheet_signoffs has no unique
// constraint on worksheet_id (migration 2026_04_26_000002...:12).
Worksheet::query()->whereHas('signoffs')->orderBy('id')->get();
```

### Scoped brand tokens (D-07) — must NOT go on `:root`

```blade
{{-- Pushed into resources/views/layouts/app.blade.php's @stack('styles') (line 1322),
     which sits AFTER the app's own :root block. Tokens are scoped to .cockpit so no
     existing page is retoned (D-07). The `c-` prefix avoids the existing --teal-*
     aliases, which map to BLUE (#1E5FE0) at app.blade.php:65-71. --}}
@push('styles')
<style>
.cockpit{
  --c-teal:#01889F; --c-teal-dark:#016E82; --c-teal-soft:#E6F4F7;
  --c-gold:#D4AF37;  --c-gold-soft:#FAF4E4;
  --c-head:'Verdana Pro',Verdana,'Trebuchet MS',sans-serif;
  --c-body:'Poppins','Segoe UI',Arial,sans-serif;
}
/* Values: .planning/reference/21cav-rams-skill/scripts/brand.js:8-28 and
   .planning/sketches/002-install-cockpit/cockpit-sections.html:11-18 */
</style>
@endpush
```

---

## Runtime State Inventory

This phase is additive rather than a rename, but it **does** migrate live data (the visit backfill
and the programme-record link), so the inventory applies.

| Category | Items found | Action required |
|----------|-------------|-----------------|
| Stored data | `site_surveys` and `worksheets` on the live VPS MySQL hold the rows the backfill reads. `install_programmes` holds rows needing an `install_record_id`. `.planning/STATE.md` records a comparable live backfill touching 46/54 rows, i.e. production row counts here are in the tens, not millions. **Unverified: I did not query production.** | Two data migrations, both via idempotent console commands, both run as separate human steps after deploy |
| Live service config | None. No external service (n8n, Datadog, Cloudflare) is referenced by this phase. Verified: no such integration in `config/services.php` beyond mail/Slack providers | None |
| OS-registered state | None. This repo has no Windows Task Scheduler / pm2 / systemd registration referencing anything this phase touches. Nothing is being renamed | None |
| Secrets / env vars | **One new env var** — `COCKPIT_ENABLED` (name at planner's discretion). Must be absent or `false` at deploy. `.env` is not in git; the flip is a separate human one-line edit. Laravel `config:cache` means a `.env` edit needs `php artisan config:clear` / `config:cache` to take effect — this repo's own `composer test` script runs `artisan config:clear` first (`composer.json:63-66`) | Add to `.env.example`; document the deploy-order flip |
| Build artifacts | If a new Vite entry (`resources/css/cockpit.css`) is added to `vite.config.js`, `npm run build` must run on deploy or the page ships unstyled. Precedent: `rack-editor.js` and `spike/main.jsx` are already separate entries | `npm run build` in the deploy step |

**Canonical question — after every file in the repo is updated, what runtime systems still hold
stale state?** Answer: the production database (no `visits` rows until the command is run, no
`install_record_id` until its backfill runs), the production `.env` (flag absent), and the built
asset bundle. All three are explicit deploy steps; none are silent.

---

## Environment Availability

| Dependency | Required by | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP CLI | migrations, artisan, tests | yes | `php.exe` present at `C:\Users\sonny.tanda\.config\herd\bin\php84\php.exe` (PHP 8.4 by path name; **exact version unverified** — not executed) | none needed |
| PHPUnit | test baseline | yes | `vendor/bin/phpunit` + `phpunit.bat` present | `artisan test` wrapper |
| Composer deps | everything | yes | `vendor/` present | — |
| Node / npm | Vite build if a new CSS entry is added | yes | `node_modules/` and `package-lock.json` present. **node/npm binary versions unverified**; `CLAUDE.md` notes "version not pinned, no `.nvmrc`" | Inline `@push('styles')` needs no build step — a genuine fallback and an argument for that shape |
| SQLite (in-memory) | test DB | yes | `phpunit.xml:37-38` `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` | — |
| `@fontsource/poppins` | self-hosted Poppins (D-07) | **unverified** | — | Google Fonts `<link>` as the sketch does, or the `'Segoe UI',Arial` fallback chain the sketch already declares |
| MySQL (production) | live backfill | n/a locally | — | Dry-run mode makes the live run safe to stage |

**Missing with no fallback:** none.
**Missing with fallback:** Poppins self-hosting.

---

## Validation Architecture

`.planning/config.json` has `workflow.nyquist_validation: true`, so this section is required.

### Test framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit ^11.5.3 (`CLAUDE.md:36`) |
| Config file | `phpunit.xml` (suites `Unit` to `tests/Unit`, `Feature` to `tests/Feature`; `snapshot` group excluded by default at `:21-25`; sqlite `:memory:` at `:37-38`) |
| Quick run command | `& "$env:USERPROFILE\.config\herd\bin\php84\php.exe" artisan test --filter="<Name>"` |
| Full suite command | `& "$env:USERPROFILE\.config\herd\bin\php84\php.exe" artisan test` (~2,670 tests, ~600s per `.planning/STATE.md`) |
| Warning | **Never invoke PHP through the Bash tool** — see Pitfall 11 |

Conventions observed: `tests/Feature/<Domain>/<Thing>Test.php`, `tests/Unit/Services/...`,
`tests/Unit/Models/...`, `tests/Feature/Security/...`; `use RefreshDatabase;`;
`public function test_*(): void`; self-contained fixture helpers at the bottom of the class under a
`// -- Fixtures --` divider (see `LabourResourceClientSurfacePrivacyTest.php:285-405`, which
explicitly says the helpers were *"deliberately not shared via a trait, per the plan's
'self-contained test file' instruction"*).

### Phase requirements to test map

| Req | Behaviour | Type | Automated command | File exists? |
|-----|-----------|------|-------------------|-------------|
| VIS-01 | `visits` table + model: columns, casts, type constants, relations | unit | `artisan test --filter=VisitTest` | NO — Wave 0: `tests/Unit/Models/VisitTest.php` |
| VIS-02 | Backfill creates one visit per survey and per *signed* worksheet; none for unsigned | feature | `artisan test --filter=VisitBackfillCommandTest` | NO — Wave 0: `tests/Feature/Visits/VisitBackfillCommandTest.php` |
| VIS-03 | `/survey/{token}` and `/worksheet/{token}` still 200 after the migration + backfill | feature | `artisan test --filter=PublicTokenRoutesUnchangedTest` | NO — Wave 0 (partly covered already by `LabourResourceClientSurfacePrivacyTest:207,244`, which GETs both routes and asserts `assertOk()`) |
| VIS-04 | Cockpit route 200s with flag on; renders spine + closed drawers | feature | `artisan test --filter=CockpitPageTest` | NO — Wave 0: `tests/Feature/Cockpit/CockpitPageTest.php` |
| VIS-05 | Flag off means cockpit 404s; `projects.show` unchanged assertions pass | feature | `artisan test --filter=CockpitFlagOffTest` | NO — Wave 0: `tests/Feature/Cockpit/CockpitFlagOffTest.php` |
| VIS-06 | Cockpit render issues no INSERT/UPDATE (assert row counts unchanged across a GET) | feature | `artisan test --filter=CockpitReadOnlyTest` | NO — Wave 0 |
| VIS-07 | Running the command twice creates no second visit | feature | same file as VIS-02 | NO — Wave 0 |
| VIS-08 | A visit whose survey is soft-deleted and/or superseded still resolves and is marked | feature | `artisan test --filter=VisitSurvivesSupersededSourceTest` | NO — Wave 0 |
| VIS-09 | **Behaviour preservation:** the full 161-test baseline stays green; **plus** a new test that regenerating a programme leaves `install_record_id` stable and the visit attached | unit + feature | the baseline command in §5 above, **plus** `artisan test --filter=InstallRecordDurabilityTest` | baseline **exists** (161 methods, 29 files); the durability test is Wave 0 |
| VIS-10 | Brand tokens present in the cockpit render and **absent** from any other page's render | feature | `artisan test --filter=CockpitBrandTokenScopeTest` | NO — Wave 0 |

### Sampling rate

- **Per task commit:** the filter for that task's own test file, plus
  `--filter="InstallProgramme|InstallTask"` on any task touching the split.
- **Per wave merge:** the full 161-test D-06 baseline command (§5) — non-negotiable for any wave
  containing a D-06 task.
- **Phase gate:** full `artisan test` green before `/gsd-verify-work`. Expect the known pre-existing
  `QueueRecoverCommandTest` failure; document it rather than chasing it.

### Wave 0 gaps

- [ ] `tests/Unit/Models/VisitTest.php` — VIS-01
- [ ] `tests/Feature/Visits/VisitBackfillCommandTest.php` — VIS-02, VIS-07
- [ ] `tests/Feature/Visits/VisitSurvivesSupersededSourceTest.php` — VIS-08
- [ ] `tests/Feature/Cockpit/CockpitPageTest.php` — VIS-04
- [ ] `tests/Feature/Cockpit/CockpitFlagOffTest.php` — VIS-05
- [ ] `tests/Feature/Cockpit/CockpitReadOnlyTest.php` — VIS-06
- [ ] `tests/Feature/Cockpit/CockpitBrandTokenScopeTest.php` — VIS-10
- [ ] `tests/Unit/Services/InstallRecordDurabilityTest.php` — VIS-09 (the new assertion)
- [ ] `database/factories/VisitFactory.php` — shared fixture for all of the above
- [ ] Decision + edit on `tests/Feature/Security/LabourResourceClientSurfacePrivacyTest.php:95-124`
      (see the privacy-guard section — Option 1 or Option 2)
- [ ] Framework install: **none needed** — PHPUnit, factories and `RefreshDatabase` all present

---

## Security Domain

`security_enforcement` is not set to `false` in `.planning/config.json`, so this section applies.

### Applicable ASVS categories

| ASVS category | Applies | Standard control in this repo |
|---------------|---------|-------------------------------|
| V2 Authentication | yes | Laravel Breeze + Eloquent provider. Cockpit uses `abort_unless(auth()->check(), 403)` — the shared-workspace convention (`CommissioningController.php:50-51`) |
| V3 Session management | no change | Framework-managed; this phase adds no session state |
| V4 Access control | yes | Flag gate `abort_unless(config(...), 404)` + auth gate. Public token routes are the one unauthenticated surface and must be left byte-identical |
| V5 Input validation | minimal | The only input is the command's `{project?}` arg. Follow the verified `(int)` cast precedent — `BackfillCablePortFksCommand.php:53-58, 95-97` documents that `"5; DROP TABLE devices;"` casts to `5`, and Eloquent binds via PDO |
| V6 Cryptography | no | No new tokens, no new secrets. Must NOT touch `access_token` generation on either model |
| V7 Error handling / logging | yes | `Log::info('ClassName: message', [...])` convention (`CLAUDE.md:200`). Never log an `access_token` |
| V8 Data protection | yes | **LR-04 still binds.** No client-facing visit surface may render a `LabourResource` email or phone. The cockpit is staff-only, satisfying this by construction, but the guard-list decision must be made explicitly |

### Known threat patterns for this stack

| Pattern | STRIDE | Mitigation (verified precedent) |
|---------|--------|----------------------------------|
| Mass-assignment re-opening a token field | Tampering | Preserve the `$fillable` omissions quoted above. The backfill writes only to `visits`, never `save()`s a survey or worksheet |
| SQL injection via the command's project arg | Tampering | `(int)` cast + Eloquent binding — `BackfillCablePortFksCommand.php:95-97` |
| Cross-tenant write (a visit attached to the wrong project) | Tampering | Per-row `project_id` taken from the source record, never from input — the T-22-A6 pattern at `BackfillCablePortFksCommand.php:60-65` |
| Leaking engineer contact to a client | Information disclosure | LR-04 / `LabourResourceClientSurfacePrivacyTest` |
| Token enumeration on public routes | Information disclosure | Existing throttles on every token route (`routes/web.php:83-92`, `:113`) — **do not modify** |
| Non-idempotent backfill creating duplicate history | Tampering / integrity | Unique composite index on `(source_type, source_id)` plus the `already-wrapped` guard |
| Surface armed before its data exists | Availability / trust | Flag defaults `false`; deploy-order doctrine at `config/rams_tier1.php:119-132` |

---

## State of the Art

| Old approach | Current approach | Where |
|--------------|------------------|-------|
| `archiveExisting()` + `create()` as "regenerate" | Still current, and the thing D-06 fixes for install. Note it was **copied** into drawings (`DrawingService.php:15,31` — "Mirrors the InstallProgrammeService precedent (Phase 12)"), so the anti-pattern has spread | `app/Services/InstallProgrammeService.php:45-66`; `app/Services/Drawings/DrawingService.php:46` |
| `protected $casts = [...]` array | Newer models use `protected function casts(): array` (Laravel 11+) | `Worksheet.php:84-96`, `InstallProgramme.php:53-62` use the method; `SiteSurvey.php:62-69` still uses the array |
| `SiteSurvey.survey_type` | Dead — superseded by room-level `space_type` (`database/migrations/2026_04_05_210000_add_space_type_to_site_survey_rooms.php`) | D-03 |
| Tailwind `brand.*` blue palette | `accent.*` semantic set preferred for new code (`tailwind.config.js:79-86`: *"Explicit accent + nav sets for new code that wants the semantic name rather than the historical `brand.*` slot"*) | — |
| Feature-flag defaults `true` (GATE-06/07/09) | Defaults **`false`** for anything whose data has not been measured clean (`config/rams_tier1.php:119-134`) | — |

**Deprecated / do not imitate:**
- `resources/views.backup-260430/` — dead backup tree; grep hits there are noise.
- `resources/views/pdf/om-manual/create.blade2703.php` and similar date-suffixed files —
  `CLAUDE.md:170` marks the pattern *"NOT recommended for new code"*.
- A giant inline `<style>` in `layouts/app.blade.php` — the app's actual state, but not something
  the cockpit should extend. Use `@push('styles')` or a Vite entry.

---

## Assumptions Log

| # | Claim | Section | Risk if wrong |
|---|-------|---------|---------------|
| A1 | The 161-test baseline currently passes 161/161 | §5, Validation | **Medium.** If some already fail, "behaviour-preserving" has no clean reference point. **Mitigation: the plan's first task must run the baseline and record the real number.** |
| A2 | A nullable `install_record_id` on `install_programmes` requires no factory edits | D-06 recommendation | Low — trivially falsified by running the baseline after the migration |
| A3 | Herd PHP at that path is 8.4 and satisfies `^8.2` | Environment | Low; verified the binary exists, not its `--version` |
| A4 | Production `site_surveys` / `worksheets` row counts are in the tens-to-hundreds, so an unbatched backfill loop is fine | Runtime State | Low-Medium. If there are thousands, chunk the query. Verify with a dry-run on the VPS before `--apply` |
| A5 | `@fontsource/poppins` is not currently installed | Environment, D-07 | Low — one `ls node_modules/@fontsource` check resolves it |
| A6 | `@push('styles')` at `app.blade.php:1322` beats the inline `:root` for equal-specificity declarations | D-07 | Low. It is later in source order, so yes for equal specificity — but `.cockpit`-scoped tokens (0-1-0) beat `:root` regardless, which is why scoping is the recommendation rather than relying on order |
| A7 | The cockpit needs no new Alpine JS because `<details>` suffices | Architecture | Low — the sketch itself uses `<details>` (`cockpit-sections.html:39-44`) |
| A8 | The "21cav-brand skill" referenced by CONTEXT.md is not present in this repo or `~/.claude/skills`; `brand.js` is the citable source | D-07 | Low — checked both locations this session; if the user has it elsewhere, its values should be reconciled against `brand.js` |
| A9 | No global Eloquent scopes exist anywhere (so `superseded_at` filtering is always explicit) | D-04 | Low — `grep -rn "addGlobalScope\|ScopedBy" app` returned zero. Laravel's own `SoftDeletingScope` is of course still active on models using the trait |

---

## Open Questions

1. **Does the cockpit belong in `CLIENT_FACING_PATHS`?**
   - Known: the list's stated criterion is "reachable by an unauthenticated client with a token"
     (`LabourResourceClientSurfacePrivacyTest.php:36-41`), and the test explicitly refuses to
     include a staff-auth PDF route on exactly that basis (`:51-61`). The cockpit is staff-auth.
   - Unclear: CONTEXT.md's scout note says it *will* need adding.
   - Recommendation: Option 1 (document the exclusion in the docblock, mirroring the
     engineer-report note) is more faithful to the test's semantics. Ask the user at discuss/plan
     time; either way the *reasoning* must be written into the file, per that file's own culture.

2. **Does the visit backfill and the `install_records` backfill share one command?**
   - Known: D-05 mandates one idempotent command for the visit backfill. The programme-record link
     is a second, unrelated data migration implied by D-06.
   - Recommendation: two commands (`visits:backfill`, `install-records:backfill`). Mixing them
     couples two independent deploy decisions, which is the exact thing D-05 exists to avoid.

3. **Does a visit FK to `install_records`, to `projects`, or both?**
   - Known: CONTEXT.md D-06 says *"visits hang off the durable half"*. But a backfilled *survey*
     visit predates any install programme — many projects will have no `install_records` row.
   - Recommendation: `visits.project_id` NOT NULL (the real spine), `visits.install_record_id`
     nullable. This is the only shape that lets a survey visit exist on a project that never had an
     install programme. Flagging because a literal reading of D-06 would make the install record the
     sole parent, which does not work.

4. **What are the six visit-type enum values' exact stored strings?**
   - Known: ROADMAP:365-366 lists "site survey / first fix / install / programming / snag /
     commissioning". Repo convention is `STATUS_UPPERCASE_SNAKE` constants holding lowercase snake
     strings (`Worksheet.php:33-37`, `InstallProgramme.php:32-35`).
   - Recommendation: `TYPE_SITE_SURVEY = 'site_survey'`, `TYPE_FIRST_FIX = 'first_fix'`,
     `TYPE_INSTALL = 'install'`, `TYPE_PROGRAMMING = 'programming'`, `TYPE_SNAG = 'snag'`,
     `TYPE_COMMISSIONING = 'commissioning'`, stored in a `string(30)` column with an index —
     mirroring `install_programmes.status` (`...000001...:39`) and `install_tasks.task_type`
     (`...000002...:43`). Not a DB enum: every status column in this schema is a string with a
     comment.

5. **Should `visits` use `SoftDeletes`?**
   - Known: there is no delete path in this phase. Adding the trait adds a global scope the
     idempotency guard must then reason about.
   - Recommendation: no, not in Phase 45. Phase 46 can add it if the lifecycle needs it.
     Unverified whether Phase 46's design requires it; a later `softDeletes()` migration is cheap.

6. **Per-section pip derivations — which three ship first?**
   - Known: `ProjectHealthService` gives one project-level light; per-section pips must come from
     the model helpers enumerated in the traffic-lights section.
   - Recommendation: Claude's Discretion per CONTEXT.md. Start with Survey (from `SiteSurvey.status`
     + `submitted_at`), Install (from `Worksheet::isSigned()`), Commissioning (from
     `commissioningSignoff()->exists()`) — the three with unambiguous existing evidence — and render
     Programming as the sketch's tick-box, never a light.

---

## Sources

### Primary (HIGH confidence — read directly from this repo this session)

**Planning artefacts**
- `.planning/phases/45-visit-model-read-only-cockpit/45-CONTEXT.md` — D-01..D-07 (full file)
- `.planning/ROADMAP.md:272-392` — v4.0 section, out-of-scope list, Phase 44 + 45 detail
- `.planning/REQUIREMENTS.md:1-62` — milestone v4.0 header, LR group, out-of-scope
- `.planning/STATE.md` — deploy/backfill history, known pre-existing test failure
- `.planning/config.json` — `nyquist_validation: true`, `commit_docs: true`
- `.planning/sketches/002-install-cockpit/README.md` (full) and `cockpit-sections.html:1-90`
- `.planning/reference/21cav-rams-skill/scripts/brand.js:1-40`
- `./CLAUDE.md` — lines 1-30, 100-200

**Application source**
- `app/Services/InstallProgrammeService.php` (full, 125 lines)
- `app/Models/InstallProgramme.php` (full, 146 lines)
- `app/Services/CommissioningItemGenerator.php:1-120`
- `app/Services/ProjectHealthService.php` (full, 201 lines) and `app/DTO/ProjectHealth.php` (full)
- `app/Models/SiteSurvey.php:1-120`, `app/Models/Worksheet.php:1-190`,
  `app/Models/WorksheetSignoff.php` (full)
- `app/Http/Controllers/SpikeSchematicController.php:1-40`
- `app/Http/Controllers/InstallProgrammeController.php` (method/reference map)
- `app/Models/Project.php:355-450`
- `app/Core/Modules/Survey/SurveyService.php:30-60`, `:1012-1022`
- `app/Core/Modules/Projects/ProjectDataService.php:95-115`
- `app/Console/Commands/BackfillCablePortFksCommand.php` (full, 224 lines)
- `app/Providers/AppServiceProvider.php:143-147`, `app/Observers/InstallTaskObserver.php`
- `app/Services/CommissioningService.php:50-125`, `app/Services/CommissioningSyncService.php`,
  `app/Services/TaskAssignmentService.php`, `app/Http/Controllers/TaskStatusController.php`,
  `app/Http/Controllers/TaskAssignmentController.php`,
  `app/Http/Controllers/CommissioningController.php:50-70`

**Schema**
- `database/migrations/2026_04_14_000001_create_install_programmes_table.php` (full)
- `database/migrations/2026_04_14_000002_create_install_tasks_table.php` (full)
- `database/migrations/2026_04_20_000001_create_install_task_photos_table.php` (FK block)
- `database/migrations/2026_04_22_000001_create_commissioning_items_table.php` (FK/index block)
- `database/migrations/2026_04_22_000002_create_commissioning_signoffs_table.php` (FK/unique block)
- `database/migrations/2026_04_26_000002_create_worksheet_signoffs_table.php` (FK block + docblock)
- `database/factories/{InstallProgramme,InstallTask,CommissioningItem,CommissioningSignoff}Factory.php`

**Views / config / build**
- `resources/views/layouts/app.blade.php:33-78`, `:1322`, `:1493-1515` (2,154 lines total)
- `resources/views/layouts/navigation.blade.php:470-492`
- `resources/views/projects/show.blade.php:427, 1780, 1795` (2,293 lines total)
- `resources/views/dashboard.blade.php:231`
- `config/services.php:40-43`, `config/rams_tier1.php:105-140`
- `resources/css/app.css` (full, 56 lines), `tailwind.config.js` (full), `vite.config.js` (full)
- `routes/web.php:80-99, 112-160, 191-197, 233-258, 560-625, 652-687, 781-808`
- `phpunit.xml` (full), `composer.json` scripts block

**Tests**
- `tests/Feature/Security/LabourResourceClientSurfacePrivacyTest.php` (full, 406 lines)
- `tests/Unit/InstallTaskGeneratorServiceTest.php` (reference map)
- `tests/Feature/Projects/ProjectDeliverableAutoFlipTest.php` (method list)
- `tests/Feature/Authorization/SharedWorkspaceFieldOpsAccessTest.php` (method list)
- 25 further test files counted by grep (table in §5)

### Secondary (MEDIUM confidence)

- Negative results from repo-wide grep, which are only as good as the pattern:
  - `grep -rn "addGlobalScope\|ScopedBy" app` — zero hits (no custom global scopes)
  - `find . -name SKILL.md -not -path ./vendor/* -not -path ./node_modules/*` — only
    `.planning/reference/21cav-rams-skill/SKILL.md` (no `21cav-brand` skill)
  - `ls ~/.claude/skills | grep -i "brand\|cav"` — zero hits
- User memory notes `php-not-on-bash-path`, `scc-mobilisation-feature` (Blade traps),
  `rams-validate-on-live` (flag-gate-risky-changes doctrine) — corroborated by repo evidence in each
  case (Herd path verified; `config/rams_tier1.php` doctrine comment verified).

### Tertiary (LOW confidence — flagged, not relied on)

- No WebSearch or Context7 lookups were performed. This phase introduces **no new third-party
  packages**, so the Package Legitimacy Audit is intentionally omitted (see below) and no external
  documentation was needed. Everything above is repo-internal and directly verified.

**Package Legitimacy Audit: NOT APPLICABLE.** This phase installs no external packages. The one
possible exception is `@fontsource/poppins` for self-hosted Poppins (A5) — if the planner takes that
route, run the legitimacy gate then. Note `@fontsource-variable/inter` is already a trusted in-use
dependency from the same Fontsource family (`resources/css/app.css:6`).

---

## Metadata

**Confidence breakdown:**
- **D-06 blast radius: HIGH** — every file:line was read from source in this session; the
  `CommissioningItemGenerator` claim has three independent confirmations; every FK/cascade/unique
  constraint was read from its migration.
- **Test baseline: HIGH on enumeration, UNVERIFIED on pass state** — 161 is a measured static count
  of test methods across 29 files. It was not executed (PHP unavailable from the Bash tool, and this
  was a read-only task). A1 in the Assumptions Log.
- **D-06 recommendation: MEDIUM** — a design judgement, but every constraint it rests on is HIGH.
  The rejections of Options B/C/D are stronger than the endorsement of A: B and C provably do not
  solve the stated problem, and D provably collides with live FKs and Phase 51's charter.
- **Backfill feasibility: HIGH** — the precedent command was read in full; `isSigned()` /
  `whereHas('signoffs')` verified; the no-unique-on-`worksheet_id` trap read from the migration.
- **Soft-delete / superseded: HIGH** — the "not a global scope, filtered per call site" finding
  rests on a zero-hit grep plus six verified call sites.
- **Feature flags: HIGH** — the spike flag was traced end-to-end through config, route, controller,
  nav and view.
- **Traffic lights: HIGH** on what the service returns; **MEDIUM** on the per-section pip
  recommendation (a design choice inside Claude's Discretion).
- **Brand / asset pipeline: HIGH** — the "tokens live inline in a 2,154-line layout" finding is the
  single most plan-shaping discovery in this section, and was read directly.
- **Privacy guard: HIGH** on mechanics; **the add-or-not question is genuinely open** and is raised
  rather than decided.

**Research date:** 2026-09-19
**Valid until:** 2026-10-19 (30 days — no fast-moving external dependencies; all findings are
repo-internal and will only drift if the repo changes)
