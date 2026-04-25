---
phase: 15-time-tracking
plan: 01
subsystem: database
tags: [time-tracking, schema-extension, audit-log, eloquent-models, laravel-migrations, phase-15]

# Dependency graph
requires:
  - phase: 14-mobile-field-view
    provides: time_entries baseline table with last_heartbeat_at, TimeEntry model, TimeEntryFactory, TimeEntryService start/stop guard
provides:
  - time_entries.category (nullable string enum) — accepts installation / commissioning / testing / other
  - time_entries.notes (nullable text) — clock-out note, ≤500 chars enforced at FormRequest in 15-02
  - time_entries.closure_reason (nullable string) — 'stale_auto_close' sentinel for Plan 15-03
  - Composite index (project_id, clocked_in_at) for the dashboard widget totals query
  - time_entry_audits append-only history table (cascadeOnDelete on entry, restrictOnDelete on editor)
  - TimeEntryAudit model with FIELDS = ['category', 'notes'], belongsTo TimeEntry + editor
  - TimeEntry::CATEGORIES array + 4 CATEGORY_* constants (single source of truth for enum validation)
  - TimeEntry::CLOSURE_REASON_STALE_AUTO_CLOSE constant
  - TimeEntry::audits() hasMany relation
  - User::timeEntries() hasMany relation
  - User::timeEntryAudits() hasMany via edited_by_user_id (non-default FK)
affects: 15-02-heartbeat-category-service, 15-03-stale-close-command, 15-04-retro-edit-ui, 15-05-dashboard-widget

# Tech tracking
tech-stack:
  added: []
  patterns:
    - Additive-only ALTER migration for safe schema evolution (CLAUDE.md safe-migration rule)
    - Enum-by-convention — PHP class constant array as single source of truth (service layer enforces, not DB CHECK) — SQLite/MySQL parity
    - Append-only history table — no update()/delete() code paths ship; retcon detection depends on permanence
    - FK restrictOnDelete on editor to preserve audit history across user churn

key-files:
  created:
    - database/migrations/2026_04_21_000001_extend_time_entries_for_phase_15.php
    - database/migrations/2026_04_21_000002_create_time_entry_audits_table.php
    - app/Models/TimeEntryAudit.php
    - tests/Unit/Migrations/TimeEntriesPhase15MigrationTest.php
    - tests/Unit/Models/TimeEntryAuditTest.php
    - tests/Unit/Models/TimeEntryTest.php
  modified:
    - app/Models/TimeEntry.php
    - app/Models/User.php
    - tests/Unit/Migrations/TimeEntriesSchemaTest.php

key-decisions:
  - "Category enum enforced at service layer, not DB CHECK — SQLite/MySQL parity (Rule beats CHECK)"
  - "Columns all nullable at DB level — Phase 14 rows backfill to null; Plan 15-02 treats null as 'other' at read time rather than bulk-mutating history"
  - "Audit FK on edited_by_user_id uses restrictOnDelete — history survives user suspension/deletion"
  - "notes stored as TEXT (not VARCHAR) — 500-char app-layer cap, 65535-byte DB ceiling as defence in depth"
  - "Composite index (project_id, clocked_in_at) sized for GROUP BY category dashboard query, not a generic timestamp index"

patterns-established:
  - "Enum-as-PHP-constants: CATEGORIES = [const list] — referenced from TimeEntryService validation (Plan 15-02), not a DB constraint"
  - "Append-only audit table: create()-only service methods; no update/delete paths; FIELDS constant enumerates what's retro-editable"

requirements-completed: [INST-04, INST-04a, INST-04f, INST-04i]

# Metrics
duration: 7min
completed: 2026-04-21
---

# Phase 15 Plan 01: Schema Foundation Summary

**Additive schema extension adds category / notes / closure_reason to time_entries, introduces an append-only time_entry_audits history table, and wires TimeEntry + User models with Phase 15 constants + relations — Phase 14 remains green.**

## Performance

- **Duration:** 7 min
- **Started:** 2026-04-21T14:01:26Z
- **Completed:** 2026-04-21T14:08:21Z
- **Tasks:** 3
- **Files modified:** 9 (6 created, 3 modified)

## Accomplishments

- **time_entries schema extended** — three nullable columns (`category`, `notes`, `closure_reason`) added via one additive ALTER, plus a composite `(project_id, clocked_in_at)` index sized for the Plan 15-05 dashboard totals query. Reversible round-trip (migrate → rollback → migrate) verified.
- **time_entry_audits append-only history table created** — cascadeOnDelete on `time_entry_id` (audits follow their entry) and restrictOnDelete on `edited_by_user_id` (history survives user churn). Indexed on `(time_entry_id, edited_at)` for chronological retrieval.
- **TimeEntry model grew a canonical enum source** — `CATEGORIES = ['installation','commissioning','testing','other']` plus `CLOSURE_REASON_STALE_AUTO_CLOSE = 'stale_auto_close'`. The service layer in Plan 15-02 will validate against `TimeEntry::CATEGORIES` — no DB CHECK was added because SQLite/MySQL portability beats a half-enforced constraint.
- **Two new hasMany relations on User** — `timeEntries()` for "sessions I've clocked" and `timeEntryAudits()` (via non-default FK `edited_by_user_id`) for "edits I've made". The `editor()` belongsTo on TimeEntryAudit closes the loop.
- **28 tests passing** across the 5 test files touched — Phase 14 feature tests remain green (6/6 start/stop/guard paths unaffected).

## Task Commits

1. **Task 1: Extend time_entries schema — category, notes, closure_reason + indexes**
   - `9517944` (test) — failing migration test (RED)
   - `3338eb9` (feat) — migration + deviation fix to TimeEntriesSchemaTest (GREEN)
2. **Task 2: Create time_entry_audits migration + TimeEntryAudit model**
   - `23a6e82` (test) — failing model test (RED)
   - `1a9569d` (feat) — migration + TimeEntryAudit model (GREEN)
3. **Task 3: Extend TimeEntry model + User model with Phase 15 fields and relations**
   - `baf8ae0` (test) — failing TimeEntry/User model test (RED)
   - `bd65c50` (feat) — TimeEntry constants/fillable/audits() + User relations (GREEN)

Each TDD cycle was committed as a RED test commit followed by a GREEN implementation commit. No refactor step was needed — implementations landed minimal on first pass.

## Files Created/Modified

**Created (6):**
- `database/migrations/2026_04_21_000001_extend_time_entries_for_phase_15.php` — adds category/notes/closure_reason + composite index; reversible
- `database/migrations/2026_04_21_000002_create_time_entry_audits_table.php` — append-only audit history table
- `app/Models/TimeEntryAudit.php` — model with FIELD_CATEGORY/FIELD_NOTES constants, FIELDS array, timeEntry() + editor() belongsTo
- `tests/Unit/Migrations/TimeEntriesPhase15MigrationTest.php` — 7 tests covering column presence, Phase 14 preservation, sentinel insert
- `tests/Unit/Models/TimeEntryAuditTest.php` — 3 tests covering both belongsTo relations + FIELDS constant shape
- `tests/Unit/Models/TimeEntryTest.php` — 7 tests covering CATEGORIES array, constants, audits() relation, User relations, fillable round-trip

**Modified (3):**
- `app/Models/TimeEntry.php` — added CATEGORY_* constants, CATEGORIES array, CLOSURE_REASON_STALE_AUTO_CLOSE constant, expanded `$fillable`, added `audits()` HasMany, refreshed class PHPDoc to reflect Phase 15 additions
- `app/Models/User.php` — added `timeEntries()` hasMany + `timeEntryAudits()` hasMany via non-default FK
- `tests/Unit/Migrations/TimeEntriesSchemaTest.php` — replaced `test_does_not_have_category_column_yet` with `test_phase_14_baseline_excludes_category` (see Deviations)

## Decisions Made

- **Enum enforcement at service layer, not DB** — `CATEGORIES` is a PHP class constant; Plan 15-02 `TimeEntryService::start()` will validate against it. DB CHECK constraints aren't portable between SQLite (test) and MySQL (prod). Layered validation (FormRequest → service → fillable) provides defence in depth without the portability tax.
- **All three new columns nullable** — Phase 14 rows would otherwise need a backfill migration. Plan 15-02 will treat null-category reads as 'other' at presentation time rather than mutating history. `notes` and `closure_reason` are naturally nullable (optional + sentinel-only respectively).
- **`notes` stored as TEXT, not VARCHAR(500)** — D-06 caps at 500 chars but the FormRequest owns that cap. TEXT gives a 65535-byte hard ceiling as a belt-and-braces backstop; zero performance penalty at Phase 15 volumes.
- **restrictOnDelete on `edited_by_user_id`** — Ops reviews audits to detect retcon patterns. If a user is suspended/deleted, their edit history must survive. The restriction forces a deliberate choice (anonymise the FK or preserve the user) rather than silently losing history.
- **Composite index on `(project_id, clocked_in_at)`** — sized for Plan 15-05's "SUM(minutes) GROUP BY category WHERE project_id = ?" query. A lone `project_id` index already exists from Phase 14; the new one is the covering structure for the dashboard totals.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Updated Phase 14 regression test that asserted deferred state**

- **Found during:** Task 1 (running the existing test suite post-migration)
- **Issue:** `tests/Unit/Migrations/TimeEntriesSchemaTest::test_does_not_have_category_column_yet` was explicitly asserting `Schema::hasColumn('time_entries', 'category')` returns `false` — a Phase 14 guard ensuring category was deferred. After Plan 15-01's migration lands, the assertion flips. Leaving the failing test would block all future `migrate:fresh` test runs on the feature branch.
- **Fix:** Replaced the test with `test_phase_14_baseline_excludes_category`, which asserts the same semantic (the Phase 14 baseline create-migration file itself doesn't define the column) but verifies it at the migration-source level instead of the live-schema level. The Phase 15 ALTER now legitimately owns the column.
- **Files modified:** `tests/Unit/Migrations/TimeEntriesSchemaTest.php`
- **Verification:** All 12 combined Phase 14/15 schema tests pass.
- **Committed in:** `3338eb9` (Task 1 GREEN commit)

---

**Total deviations:** 1 auto-fixed (1 blocking)
**Impact on plan:** None on scope. The Phase 14 test was a temporal guard that had served its purpose — replacing it with a migration-source assertion keeps the historical boundary documented without blocking the Phase 15 schema.

## Issues Encountered

None. All three TDD cycles ran clean (RED → GREEN, no refactor). Migration rolled back and re-ran cleanly; `migrate:fresh` executes the Phase 15 migrations in deterministic order after all Phase 14 migrations.

## Next Plan Readiness

- **Plan 15-02 (heartbeat + category + retro-edit service):** Can now rely on `TimeEntry::CATEGORIES` for enum validation, `TimeEntry::CLOSURE_REASON_STALE_AUTO_CLOSE` for the sentinel string, and `TimeEntryAudit::create()` as the only service-layer audit write path.
- **Plan 15-03 (stale-close command):** Has `closure_reason` column to stamp and `last_heartbeat_at` (Phase 14) to close against.
- **Plan 15-04 (retro-edit UI):** Has `TimeEntry::audits()` and `TimeEntryAudit::FIELDS` to render history and constrain the editable field set.
- **Plan 15-05 (dashboard widget):** Has the `(project_id, clocked_in_at)` index for the `SUM … GROUP BY category` query.

## Self-Check: PASSED

**Files exist (6 new):**
- `database/migrations/2026_04_21_000001_extend_time_entries_for_phase_15.php` — FOUND
- `database/migrations/2026_04_21_000002_create_time_entry_audits_table.php` — FOUND
- `app/Models/TimeEntryAudit.php` — FOUND
- `tests/Unit/Migrations/TimeEntriesPhase15MigrationTest.php` — FOUND
- `tests/Unit/Models/TimeEntryAuditTest.php` — FOUND
- `tests/Unit/Models/TimeEntryTest.php` — FOUND

**Files modified (3):**
- `app/Models/TimeEntry.php` — MODIFIED
- `app/Models/User.php` — MODIFIED
- `tests/Unit/Migrations/TimeEntriesSchemaTest.php` — MODIFIED

**Commits exist:**
- `9517944` FOUND (Task 1 RED)
- `3338eb9` FOUND (Task 1 GREEN)
- `23a6e82` FOUND (Task 2 RED)
- `1a9569d` FOUND (Task 2 GREEN)
- `baf8ae0` FOUND (Task 3 RED)
- `bd65c50` FOUND (Task 3 GREEN)

**Test suite status:** 28/28 tests pass across the 5 test files directly exercised by this plan; 6/6 Phase 14 feature tests remain green (no regression).

---
*Phase: 15-time-tracking*
*Plan: 01*
*Completed: 2026-04-21*
