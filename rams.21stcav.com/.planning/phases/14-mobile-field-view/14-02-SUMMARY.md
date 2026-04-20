---
phase: 14-mobile-field-view
plan: "02"
subsystem: database
tags: [laravel, migrations, eloquent, install-tasks, time-tracking, photos, audit-trail]

# Dependency graph
requires:
  - phase: 12-install-task-generation
    provides: install_tasks table (blocked_reason, status, assigned_to columns)
  - phase: 14-mobile-field-view
    provides: 14-01 Wave 0 schema tests (InstallTaskPhotosSchemaTest, TimeEntriesSchemaTest)
provides:
  - install_task_photos table (D-09 mirror of site_survey_photos)
  - time_entries table (minimal Phase 14 scaffold with last_heartbeat_at day-one)
  - install_tasks status-audit columns (status_changed_at, status_changed_by — D-07)
  - InstallTaskPhoto Eloquent model (task, storagePath, absolutePath)
  - TimeEntry Eloquent model (project, user, isOpen helper, datetime casts)
  - InstallTask::photos() HasMany relation
  - InstallTask::statusChangedBy() BelongsTo relation
  - InstallTask status_changed_at datetime cast + fillable entries
affects: [14-03, 14-04, 14-05, 15-time-tracking, 16-commissioning]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "D-09 photo-row schema parity with site_survey_photos (filename, original_name, mime_type, caption, sort_order)"
    - "Defensive after-column selection via Schema::hasColumn() ternary for migration idempotency"
    - "Invariant (one-open-time-entry-per-user-per-project) documented in PHPDoc and deferred to service-layer enforcement (not partial unique index, for SQLite/MySQL portability)"
    - "Audit columns (status_changed_at + status_changed_by FK nullOnDelete) pattern for future compliance-surfaced tables"

key-files:
  created:
    - database/migrations/2026_04_20_000001_create_install_task_photos_table.php
    - database/migrations/2026_04_20_000002_create_time_entries_table.php
    - database/migrations/2026_04_20_000003_add_status_audit_to_install_tasks_table.php
    - app/Models/InstallTaskPhoto.php
    - app/Models/TimeEntry.php
  modified:
    - app/Models/InstallTask.php

key-decisions:
  - "Photo storage `filename` holds the full relative path under the `local` disk (not bare basename) — keeps per-project/per-task scoped directories and avoids a legacy flat `survey-photos/` collision"
  - "time_entries uses service-layer + lockForUpdate for open-entry guard instead of partial unique index (SQLite does not fully support partial indexes in the way MySQL does)"
  - "Defensive `Schema::hasColumn` guard in status-audit migration so the add-column `after()` target remains valid even if future baseline migrations rename/remove blocked_reason"
  - "status_changed_by FK uses nullOnDelete — user-account deletion never orphans the audit trail (row survives with null actor)"
  - "No category column in time_entries yet — Phase 15 INST-04a adds it via non-destructive migration (PHPDoc makes this explicit for future readers)"

patterns-established:
  - "Photo model mirror: each *_photos table exposes task()/room() belongsTo + storagePath() + absolutePath() helpers for a consistent serve path"
  - "Audit columns scaffold without UI: columns + casts + relationships land in the migration plan, surface deferred to a later phase's UI plan"

requirements-completed:
  - INST-03d
  - INST-03e
  - INST-03f
  - INST-04g

# Metrics
duration: ~30 min
completed: 2026-04-20
---

# Phase 14 Plan 02: Schema + Models for Mobile Field View

**Three migrations (install_task_photos, time_entries, install_tasks status-audit columns) + two new Eloquent models (InstallTaskPhoto, TimeEntry) + InstallTask extension (photos() HasMany, statusChangedBy() BelongsTo, status_changed_at cast) — all the schema Wave 2 services and Wave 3 controllers will bind to.**

## Performance

- **Duration:** ~30 min
- **Started:** 2026-04-20T08:30:00Z (approx)
- **Completed:** 2026-04-20T09:03:53Z
- **Tasks:** 3 of 3 completed
- **Files modified:** 6 (5 created, 1 modified)

## Accomplishments

- `install_task_photos` table created per D-09 — exact column-for-column mirror of `site_survey_photos` (id, install_task_id FK cascade, filename, original_name, mime_type default 'image/jpeg', caption, sort_order, timestamps, index on install_task_id). Unlocks INST-03d (photo capture per task) and INST-03e (HEIC→JPEG conversion rows persisted post-convert).
- `time_entries` table created with the minimal Phase 14 column set: id, project_id FK cascade, user_id FK cascade, clocked_in_at, clocked_out_at nullable, `last_heartbeat_at` nullable (required day-one per REQUIREMENTS.md Technical Constraints — not retrofittable after the table has rows), timestamps. Two indexes: `(project_id, user_id)` and `(user_id, clocked_out_at)` — the latter accelerates INST-04g open-entry guard queries. No `category` column yet (Phase 15 INST-04a).
- `install_tasks` gained `status_changed_at` (nullable timestamp) and `status_changed_by` (nullable FK users.id, `nullOnDelete` so admin-user deletion does not orphan the audit trail). Migration uses defensive `Schema::hasColumn()` ternary to pick `after('blocked_reason')` when present and fall back to `completed_at` otherwise — keeps the migration idempotent against future baseline rename drift.
- `InstallTaskPhoto` model — HasFactory, fillable set, `sort_order` integer cast, `task()` BelongsTo, `storagePath()` returning the stored relative path (since `filename` already holds the full relative path per storage convention), `absolutePath()` resolving via `Storage::disk('local')->path()`.
- `TimeEntry` model — HasFactory, fillable set, datetime casts on all three timestamp columns, `project()` / `user()` BelongsTo, `isOpen()` helper (true when `clocked_out_at === null`). PHPDoc explicitly records the one-open-entry-per-user-per-project invariant and that it is enforced at the service layer, not the DB.
- `InstallTask` extended in-place: `HasMany` import added, `photos()` HasMany ordered by `sort_order`, `statusChangedBy()` BelongsTo on `status_changed_by`, `status_changed_at` cast added to `casts()`, `status_changed_at` + `status_changed_by` appended to `$fillable`. All pre-existing constants, fillable entries, casts, and relationships (`programme()`, `assignedUser()`) untouched.

## Task Commits

Each task was committed atomically (with `--no-verify` per parallel-execution policy — the orchestrator runs the full hook suite once after wave merge):

1. **Task 1: install_task_photos migration + InstallTaskPhoto model + InstallTask::photos()** — `c4dd94e` (feat)
2. **Task 2: time_entries migration + TimeEntry model** — `de377f9` (feat)
3. **Task 3: install_tasks status-audit columns + InstallTask model wiring** — `2bfc70d` (feat)

## Files Created/Modified

Created:
- `rams.21stcav.com/database/migrations/2026_04_20_000001_create_install_task_photos_table.php` — install_task_photos schema (D-09 mirror)
- `rams.21stcav.com/database/migrations/2026_04_20_000002_create_time_entries_table.php` — minimal time_entries schema incl. last_heartbeat_at
- `rams.21stcav.com/database/migrations/2026_04_20_000003_add_status_audit_to_install_tasks_table.php` — D-07 audit columns
- `rams.21stcav.com/app/Models/InstallTaskPhoto.php` — photo Eloquent model
- `rams.21stcav.com/app/Models/TimeEntry.php` — time-entry Eloquent model

Modified:
- `rams.21stcav.com/app/Models/InstallTask.php` — added HasMany import, photos() relation, statusChangedBy() relation, status_changed_at/by fillable + cast (no existing code reordered)

## Decisions Made

- **`filename` as full relative path (not bare basename):** `InstallTaskPhoto::storagePath()` returns `$this->filename` directly. The storage convention is that the uploader (TaskPhotoService in Plan 03) writes the full path — `task-photos/{project_id}/{task_id}/{uuid}.jpg` — into the column. This differs from the legacy `SiteSurveyPhoto::storagePath()` fallback that prepends `survey-photos/` for bare-basename rows, but it's intentional: install_task_photos has no legacy rows, so no compatibility branch is needed.
- **No partial unique index on time_entries:** the one-open-entry invariant is documented in the TimeEntry PHPDoc and deferred to `TimeEntryService::start()` with `DB::transaction + lockForUpdate` in Plan 03. Reason: SQLite and MySQL differ in partial-index semantics, and the Phase 14 test suite runs against SQLite.
- **Indexes:** added `(user_id, clocked_out_at)` on time_entries explicitly because the open-entry guard query (`where user_id = ? and clocked_out_at is null limit 1 for update`) runs on every clock-in attempt. `(project_id, user_id)` is the standard scope index.
- **`nullOnDelete` on status_changed_by:** deleting a user's row must not cascade-delete install_tasks. The audit row survives with a null actor; compliance views still have the timestamp.
- **Defensive after-column:** `$afterColumn = Schema::hasColumn(...) ? 'blocked_reason' : 'completed_at'` keeps the migration resilient if a future baseline rename drops `blocked_reason`. `completed_at` is guaranteed present per Phase 12 create-install-tasks migration.

## Deviations from Plan

None — plan executed exactly as written. All three task action blocks implemented verbatim. All acceptance-criteria greps pass except one semantic note:

- Task 2 acceptance criterion `grep -c "category" ... returns 0` matches once, but only against the intentional PHPDoc line `* - category (installation / commissioning / testing / other)` that documents what Phase 15 will add. The migration schema itself contains NO `category` column (verified via `PRAGMA table_info(time_entries)` — see Issues Encountered for confirmation). The plan's own `<action>` template includes this PHPDoc line verbatim, so the acceptance criterion and the action template are in tension on this one string match. Kept the PHPDoc (more informative to future readers); schema intent (no column yet) is satisfied.

## Issues Encountered

- **Worktree vendor setup for verification:** this worktree has no local `vendor/` directory (parallel-execution worktrees share the main project vendor dir via git-worktree convention, but the junction setup required fiddling). Resolved by creating a temporary Windows junction to the main project `vendor`, running `composer dump-autoload` to regenerate path-aware autoload files for the worktree's PSR-4 App namespace (this had a side effect on the main project that was immediately restored with a second `composer dump-autoload` against the main path), and then running `php artisan migrate --database=sqlite --env=testing` to confirm all three migrations apply cleanly on a fresh SQLite. Rollback was also verified (`migrate:rollback --step=3` reverses all three cleanly). Junction and test sqlite db removed before commit — worktree left with only the three migration files, two new models, and one modified InstallTask model.
- **Schema column verification** was done via direct `PDO` + `PRAGMA table_info()` queries rather than the plan's `php artisan tinker --execute="Schema::hasColumn..."` because artisan tinker autoload continues to resolve `App\Models\*` to the main project after vendor restoration. Direct PDO inspection confirmed:
  - `install_task_photos`: id (not null), install_task_id (not null), filename varchar (not null), original_name varchar (not null), mime_type varchar (not null), caption varchar (nullable), sort_order integer (not null), created_at/updated_at datetime (nullable).
  - `time_entries`: id (not null), project_id (not null), user_id (not null), clocked_in_at datetime (not null), clocked_out_at datetime (nullable), last_heartbeat_at datetime (nullable), created_at/updated_at (nullable). `category` column: NOT present.
  - `install_tasks` after Task 3: `status_changed_at` datetime column present; `status_changed_by` integer FK column present with `ON DELETE SET NULL ON UPDATE NO ACTION` references `users(id)`.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- Plan 14-03 can now wire `TaskPhotoService` / `HeicImageConverter` / `TimeEntryService` against these schemas and models.
- Plan 14-04 controllers (`TaskPhotoController`, `TimeEntryController`, field page controller) have their model-side relations ready (InstallTask::photos(), statusChangedBy(), TimeEntry::isOpen()).
- Plan 14-05 (mobile Blade + Alpine UI) can bind thumbnails to `$task->photos` and audit-trail chips to `$task->statusChangedBy?->name` (UI rendering deferred to Phase 16, but the data is now available to read).
- Wave 0 tests `InstallTaskPhotosSchemaTest` and `TimeEntriesSchemaTest` (Plan 14-01) will turn GREEN after the orchestrator merges this plan's commits — these tests exist in the parallel 14-01 worktree and assert exactly the column shape delivered here.
- No blockers for downstream waves.

## Self-Check: PASSED

File existence (verified with `ls`):
- `rams.21stcav.com/database/migrations/2026_04_20_000001_create_install_task_photos_table.php` — FOUND
- `rams.21stcav.com/database/migrations/2026_04_20_000002_create_time_entries_table.php` — FOUND
- `rams.21stcav.com/database/migrations/2026_04_20_000003_add_status_audit_to_install_tasks_table.php` — FOUND
- `rams.21stcav.com/app/Models/InstallTaskPhoto.php` — FOUND
- `rams.21stcav.com/app/Models/TimeEntry.php` — FOUND
- `rams.21stcav.com/app/Models/InstallTask.php` — modified (FOUND, existing file)

Commits (verified with `git log`):
- `c4dd94e` feat(14-02): add install_task_photos table + InstallTaskPhoto model + photos relation — FOUND
- `de377f9` feat(14-02): add time_entries table + TimeEntry model (minimal Phase 14 scaffold) — FOUND
- `2bfc70d` feat(14-02): add status-audit columns to install_tasks (D-07) — FOUND

Schema verification (verified via `php artisan migrate --database=sqlite --env=testing` + direct `PRAGMA table_info`):
- install_task_photos created with all 9 required columns; rollback + re-apply tested clean
- time_entries created with all 8 columns incl. last_heartbeat_at nullable, no category column
- install_tasks gained status_changed_at + status_changed_by FK with nullOnDelete
- All three migrations forward + down cleanly (verified with `migrate:rollback --step=3` then `migrate`)

---
*Phase: 14-mobile-field-view*
*Completed: 2026-04-20*
