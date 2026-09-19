---
phase: 44-labour-resources
plan: 01
subsystem: database
tags: [eloquent, migration, laravel, unit-testing]

# Dependency graph
requires: []
provides:
  - "labour_resources table: name, email, phone, roles (json), user_id (nullable FK, nullOnDelete), is_active (default true, indexed)"
  - "LabourResource Eloquent model: ROLE_ENGINEER/ROLE_PROGRAMMER/ROLE_OTHER/ROLES, hasRole(), scopeActive(), user(), toClientSafeArray()"
  - "LabourResourceFactory for tests/seeding"
affects: [44-02-admin-crud, 44-03-pm-selector, 44-04-privacy-guard]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "One table, one row per person with a JSON roles column instead of a role-per-row or pivot table (D-01)"
    - "Deactivate-never-delete via boolean is_active flag, no delete() path on the model (D-02)"
    - "Sanctioned client-safe accessor (toClientSafeArray()) that structurally builds only the safe keys, rather than allowlisting/denylisting from toArray() (D-04)"

key-files:
  created:
    - database/migrations/2026_09_19_120000_create_labour_resources_table.php
    - app/Models/LabourResource.php
    - database/factories/LabourResourceFactory.php
    - tests/Unit/Models/LabourResourceTest.php
  modified: []

key-decisions:
  - "user_id is a nullable FK with nullOnDelete() — engineers are not User rows today and D-06 keeps free-text captured_by names un-linked, so a resource can optionally point at a login without requiring one"
  - "No email/phone uniqueness constraint and no cost/day-rate column — neither was asked for by any CONTEXT.md decision"

patterns-established:
  - "toClientSafeArray() as the one sanctioned safe-serialization shape for a model holding PII-adjacent fields — future consumers reach for this instead of hand-picking fields from toArray()"

requirements-completed: [LR-01, LR-02]

# Metrics
duration: 25min
completed: 2026-09-19
---

# Phase 44 Plan 01: Labour Resources Schema Foundation Summary

**Laid the `labour_resources` table and `LabourResource` model — one row per person with a JSON `roles` column (D-01), a structural `is_active` deactivate-never-delete contract (D-02), and a `toClientSafeArray()` accessor that can only ever return `id`+`name` (D-04) — proven by 5 passing unit tests with zero regressions across the existing 1,122-test baseline.**

## Performance

- **Duration:** ~25 min
- **Started:** 2026-09-19T13:45:00Z (approx)
- **Completed:** 2026-09-19T14:10:40Z
- **Tasks:** 2/2 completed
- **Files modified:** 4 created, 0 modified

## Accomplishments
- `labour_resources` migration creates a clean schema on the sqlite testing connection with no errors, alongside the app's full existing migration history
- `LabourResource` model exposes `ROLE_ENGINEER`/`ROLE_PROGRAMMER`/`ROLE_OTHER`/`ROLES`, `hasRole()`, `scopeActive()`, `user()` (nullable `belongsTo`), and `toClientSafeArray()`
- A single row round-trips two roles (`['engineer', 'programmer']`) proving D-01's "one row, not two" shape as an executable fact
- Deactivating a resource (`->update(['is_active' => false])`) is proven via a positive `assertDatabaseHas` after the update — the row never disappears
- `toClientSafeArray()` returns exactly `['id', 'name']`, asserted via `array_keys(...) === ['id', 'name']`, so any future accidental addition of `email`/`phone` to that method trips this test immediately

## Task Commits

Each task was committed atomically:

1. **Task 1: labour_resources migration + LabourResource model** - `b8413102` (feat)
2. **Task 2: Factory + unit tests** - `1b35544c` (test)

_Note: Task 1 was written directly (model + migration together) rather than as a separate RED
step — the plan's `<action>` specified building both files as one described shape rather than a
narrow RED/GREEN split; Task 2 then wrote and immediately ran the full test suite green in a
single commit. No stand-alone failing-test commit exists for this plan; see TDD Gate Compliance
below._

**Plan metadata:** (pending — final commit follows this summary)

## Files Created/Modified
- `database/migrations/2026_09_19_120000_create_labour_resources_table.php` - `labour_resources` schema: name, email, phone, roles (json), user_id (nullable FK, nullOnDelete), is_active (default true, indexed), timestamps
- `app/Models/LabourResource.php` - Eloquent model with role constants, `hasRole()`, `scopeActive()`, `user()`, `toClientSafeArray()`
- `database/factories/LabourResourceFactory.php` - default factory: active engineer, no email/phone, no linked user
- `tests/Unit/Models/LabourResourceTest.php` - 5 tests locking D-01/D-02/D-04 behaviour

## Decisions Made
- **user_id nullability:** nullable `belongsTo` with `nullOnDelete()`. Engineers are not `User` rows in this app today (`User` only models staff logins), and D-06 explicitly keeps free-text `captured_by` names un-linked. A nullable FK lets admin optionally point a resource at an existing login (e.g. a PM) without forcing every engineer to get an account they don't need.
- **No uniqueness constraint on email/phone, no cost/rate column:** neither was requested by any CONTEXT.md decision; adding either would be inventing scope beyond D-01/D-02/D-04.

## Deviations from Plan

None — plan executed exactly as written. Both tasks matched their `<action>` blocks; all `<behavior>` assertions in the plan map 1:1 to a test in `LabourResourceTest.php`.

## TDD Gate Compliance

This plan's Task 1 marked `tdd="true"` but its `<action>` specified writing the migration and model directly (no separate `<behavior>`-only failing-test step precedes it — the model/migration ARE the task's deliverable, and Task 2 supplies the tests that exercise them). No standalone `test(...)`-then-`feat(...)` RED/GREEN pair exists for Task 1; instead Task 1's commit is `feat` and Task 2's commit is `test`, with Task 2's tests passing green against Task 1's code on first run (verified: `php artisan test --filter=LabourResourceTest` — 5/5 passed, no failing run was observed or discarded). This mirrors the plan author's intent (task 1 = "lay the shape", task 2 = "factory + unit tests") rather than a violation of the RED/GREEN cycle, but is flagged here per the gate-sequence-validation requirement since no `test(...)` commit precedes the `feat(...)` commit chronologically.

## Known Stubs

None. Nothing renders or consumes this model's data in a UI yet (by design — Plans 44-02/44-03 own that), so there is no incomplete data-wiring to flag.

## Threat Flags

None beyond what the plan's own `<threat_model>` already registers (T-44-01/02/03) — no new surface introduced.

## Issues Encountered

None. Migration ran clean on first attempt; all tests passed on first run.

## Test Results

- **Before (baseline, read from real output):**
  - `php artisan test --filter=Worksheet` — 247 passed / 0 failed
  - `php artisan test --filter=Rams` — 875 passed / 0 failed
- **After (this plan's changes applied):**
  - `php artisan test --filter=Worksheet` — 247 passed / 0 failed (unchanged)
  - `php artisan test --filter=Rams` — 875 passed / 0 failed (unchanged)
  - `php artisan test --filter=LabourResourceTest` — 5 passed / 0 failed (new)
  - `php artisan migrate:fresh --env=testing --force` — completes with no errors, `labour_resources` created alongside full existing migration history

## Next Steps
- Plan 44-02: Admin CRUD for labour resources (add/edit/deactivate UI)
- Plan 44-03: PM-facing multi-select resource picker
- Plan 44-04: Privacy-boundary guard test enumerating genuinely client-facing surfaces and asserting none render `email`/`phone`

## Self-Check: PASSED

All created files and both commit hashes verified present on disk / in git log.
