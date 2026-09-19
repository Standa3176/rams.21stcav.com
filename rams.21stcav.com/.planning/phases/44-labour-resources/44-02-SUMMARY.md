---
phase: 44-labour-resources
plan: 02
subsystem: admin
tags: [laravel, blade, admin-crud, eloquent, feature-tests]

# Dependency graph
requires:
  - phase: 44-labour-resources
    plan: 01
    provides: "labour_resources table + LabourResource model (ROLES allow-list, is_active, toClientSafeArray())"
provides:
  - "Admin\\LabourResourceController: index/create/store/edit/update/toggleActive — no destroy()"
  - "6 named routes under the existing Route::middleware('admin') group (admin.labour-resources.*)"
  - "resources/views/admin/labour-resources/index.blade.php + form.blade.php"
  - "7 feature tests including the structural no-destroy-route tripwire"
affects: [44-03-pm-selector, 44-04-privacy-guard]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Admin CRUD without destroy(): controller carries a class docblock explaining why (D-02), and the test suite proves the absence via Route::has() rather than only testing what exists"
    - "Server-side roles allow-list enforced with in: validation against the model's own ROLES constant, never trusted from checkbox markup alone"

key-files:
  created:
    - app/Http/Controllers/Admin/LabourResourceController.php
    - resources/views/admin/labour-resources/index.blade.php
    - resources/views/admin/labour-resources/form.blade.php
    - tests/Feature/Admin/LabourResourceControllerTest.php
  modified:
    - routes/web.php

key-decisions:
  - "Contact details (email/phone) are shown on the admin index and edit form — this surface sits behind the real admin middleware, and D-04 explicitly sanctions admin/PM visibility. A doc comment at the top of index.blade.php flags this for Plan 44-04's privacy scan so it isn't mistaken for a leak."
  - "No 'cannot deactivate yourself' guard on toggleActive — a labour resource is never the acting admin, so UserController's self-suspend guard doesn't apply here."

patterns-established:
  - "Deactivate-never-delete admin resource: no destroy() method, no destroy route, and a test asserting Route::has(...) is false as the structural tripwire against a future dev adding one carelessly"

requirements-completed: [LR-02]

# Metrics
duration: 35min
completed: 2026-09-19
---

# Phase 44 Plan 02: Admin CRUD for Labour Resources Summary

**Admin-only `/admin/labour-resources` CRUD (index/create/edit/toggle-active, no destroy) following the `Admin\UserController` precedent exactly, with server-side roles allow-list enforcement and 7 feature tests proving the admin gate, index history-visibility, and the structural no-hard-delete tripwire.**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-09-19 (approx, after 44-01)
- **Completed:** 2026-09-19
- **Tasks:** 3/3 completed
- **Files modified:** 4 created, 1 modified

## Accomplishments
- `Admin\LabourResourceController` with index/create/store/edit/update/toggleActive, all behind the existing `Route::middleware('admin')` group alongside `UserController`/`DeviceController`/`DeviceStencilController` — no new middleware mechanism invented
- Six explicit named routes (`admin.labour-resources.*`) added directly after the Device routes block in `routes/web.php`; deliberately no `destroy` route
- `store()`/`update()` validate `roles.*` against `in:` + `LabourResource::ROLES`, rejecting any value outside the allow-list server-side regardless of what a modified client submits
- `store()` always creates with `is_active => true` — there is no "create inactive" path, matching D-02's framing that "add" always starts active
- Admin index shows both active and inactive resources (deactivation doesn't hide history from admin), with role badges, a Contact column, and an Inactive badge relabelled from "Suspended" per D-02's language
- Shared create/edit form: name, optional email/phone, role checkboxes, optional linked-login select — no cost/day-rate field, per explicit out-of-scope
- 7 feature tests: non-admin 403 on all six named routes, index renders both active+inactive, store forces `is_active=true` and rejects an out-of-allow-list role (422, nothing persisted), update persists a roles change without touching `is_active`, `toggleActive` flips both directions with `assertDatabaseHas` (never `assertDatabaseMissing`) proving the row survives, and `Route::has('admin.labour-resources.destroy')` is `false`

## Task Commits

Each task was committed atomically:

1. **Task 1: LabourResourceController + admin routes** - `3af710aa` (feat)
2. **Task 2: Admin views (index + form)** - `6682c89a` (feat)
3. **Task 3: Feature tests** - `83b906bb` (test)

**Plan metadata:** (pending — final commit follows this summary)

## Files Created/Modified
- `app/Http/Controllers/Admin/LabourResourceController.php` - index/create/store/edit/update/toggleActive; class docblock explains why no destroy() exists (D-02); shared `validated()` helper enforces the roles allow-list
- `resources/views/admin/labour-resources/index.blade.php` - list view with role badges, Contact column (email/phone), Active/Inactive status, Edit + Deactivate/Reactivate actions, no delete button; top-of-file comment excludes it from Plan 44-04's client-facing privacy scan
- `resources/views/admin/labour-resources/form.blade.php` - shared create/edit form: name, email, phone, role checkboxes (against `LabourResource::ROLES`), optional linked-`User` select
- `tests/Feature/Admin/LabourResourceControllerTest.php` - 7 feature tests covering the admin gate, index history-visibility, roles allow-list enforcement, update behaviour, toggleActive round-trip, and the no-destroy-route tripwire
- `routes/web.php` - added `LabourResourceController` import and 6 named routes inside the existing admin middleware group

## Decisions Made
- **Contact details visible on this admin screen:** email/phone render in both the index and form. This is the admin-gated surface D-04 sanctions ("visible to the PM and admin only"); flagged with an explicit comment so Plan 44-04's privacy-boundary scan doesn't misread it as a leak.
- **No self-deactivation guard:** unlike `UserController::toggleActive()`, there's no "cannot suspend yourself" check — a labour resource is a record, never the acting admin user, so the guard has no applicable case here.
- **Roles validated via `in:` against `LabourResource::ROLES` directly** (not a separate validation rule object) — keeps the allow-list single-sourced from the model constant, matching the plan's `<action>` spec exactly.

## Deviations from Plan

None — plan executed exactly as written. All three tasks matched their `<action>` blocks; all `<behavior>` lines in Task 3 map 1:1 to a test method.

## TDD Gate Compliance

Task 3 is marked `tdd="true"`, but per the plan's own task ordering, Task 1 (controller) and Task 2 (views) were built first as the plan's `<action>` specifies, then Task 3 supplied the tests — the same "lay the shape, then test it" ordering 44-01 used and flagged for the same reason. No standalone `test(...)`-then-`feat(...)` RED/GREEN pair exists: this plan's commit order is `feat` (Task 1), `feat` (Task 2), `test` (Task 3), with all 7 Task 3 tests passing green against the already-built implementation on first run — no failing run was observed or discarded. This mirrors the plan author's intent rather than a violation of the RED/GREEN cycle, but is flagged here per the gate-sequence-validation requirement since no `test(...)` commit precedes a `feat(...)` commit chronologically.

## Known Stubs

None. No day-rate/cost field, no create-inactive path, no destroy path — all deliberate exclusions per D-02 and the phase's explicit deferrals, not incomplete wiring.

## Threat Flags

None beyond what the plan's own `<threat_model>` already registers (T-44-04/05/06) — no new surface introduced beyond what the plan anticipated.

## Issues Encountered

None. Routes, views, and tests all passed on first attempt.

## Test Results

- **Before (baseline, read from real output):**
  - `php artisan test --filter=LabourResource` — 5 passed / 0 failed (Plan 44-01)
  - `php artisan test --filter=Worksheet` — 247 passed / 0 failed
  - `php artisan test --filter=Rams` — 875 passed / 0 failed
- **After (this plan's changes applied):**
  - `php artisan test --filter=LabourResource` — 12 passed / 0 failed (5 unit + 7 feature, new)
  - `php artisan test --filter=Worksheet` — 247 passed / 0 failed (unchanged)
  - `php artisan test --filter=Rams` — 875 passed / 0 failed (unchanged)
  - `php artisan route:list --name=admin.labour-resources` — exactly 6 routes, all under the `admin` middleware group, no destroy route

## Next Steps
- Plan 44-03: PM-facing multi-select resource picker (reads `LabourResource::active()`, presumably scoped to non-deactivated resources)
- Plan 44-04: privacy-boundary guard test enumerating genuinely client-facing surfaces — this plan's index/form views should be explicitly excluded from that scan (see the doc comment at the top of `index.blade.php`)

## Self-Check: PASSED

All created files and all three commit hashes verified present on disk / in git log.
