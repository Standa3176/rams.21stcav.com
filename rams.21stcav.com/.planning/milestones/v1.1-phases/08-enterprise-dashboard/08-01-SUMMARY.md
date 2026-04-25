---
phase: 08-enterprise-dashboard
plan: 01
subsystem: dashboard

tags: [laravel, service-layer, dto, tdd, phpunit, dashboard]

# Dependency graph
requires:
  - phase: 01-05
    provides: Project model lifecycle, RamsDocument pipeline statuses, SiteSurvey submission state
  - phase: 12-install-task-generation
    provides: InstallProgramme / InstallTask relations used for widget data

provides:
  - "DashboardController@index replacing the legacy route closure"
  - "ProjectHealthService with first-match red/amber/green derivation"
  - "App\\DTO\\ProjectHealth readonly value object (status, reason, overdue)"
  - "Test scaffold for 08-02 view rewrite (projects, healthMap, statusCounts exposed to view)"

affects: [08-02-dashboard-view, 09-notifications, 10-ai-usage-admin, 11-bitrix24]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "DTO layer under app/DTO/ (first entry)"
    - "Service-layer health derivation: no DB calls inside assess()"

key-files:
  created:
    - app/DTO/ProjectHealth.php
    - app/Services/ProjectHealthService.php
    - app/Http/Controllers/DashboardController.php
    - tests/Unit/ProjectHealthServiceTest.php
    - tests/Feature/DashboardControllerTest.php
  modified:
    - routes/web.php

key-decisions:
  - "Health derivation lives in ProjectHealthService — controller stays thin, rules are unit-testable without the database"
  - "Overdue = Carbon::now()->diffInDays(stageStart, false) < -14 — uses signed diff direction as emitted by Carbon 2/3"
  - "Soft-deleted RAMS filtered in memory via ramsDocuments->filter(fn r => r->deleted_at === null) rather than relation scope, to keep assess() relation-agnostic"
  - "ProjectHealth is a readonly value object (PHP 8.2+), not an array — gives the view a typed contract for Wave 2"
  - "\$recentProjects retained alongside \$projects so the existing dashboard blade keeps rendering until 08-02 rewrites it (view-compat scaffold)"

patterns-established:
  - "App\\DTO\\ namespace for typed read-only value objects"
  - "Service::assess(Model) method signature for per-row health/score derivation"
  - "Unit tests extend Tests\\TestCase even when no DB is needed, so Eloquent models boot with the framework resolver"

requirements-completed:
  - DASH-01a
  - DASH-01b
  - DASH-01c
  - DASH-01d
  - DASH-01e
  - DASH-01h

# Metrics
duration: ~23 min
completed: 2026-04-19
---

# Phase 08 Plan 01: Dashboard Controller + Project Health Service Summary

**DashboardController replaces the legacy route closure and is backed by a pure-PHP `ProjectHealthService` that derives per-project green/amber/red status from already-loaded relations, with full RED-then-GREEN TDD coverage.**

## Performance

- **Duration:** ~23 min
- **Started:** 2026-04-19T09:35:00Z (approx, worktree init)
- **Completed:** 2026-04-19T10:01:12Z (final task commit)
- **Tasks:** 2 (TDD RED + GREEN)
- **Files created:** 5
- **Files modified:** 1

## Accomplishments

- `ProjectHealthService::assess(Project)` returns a `ProjectHealth` DTO with priority-ordered health rules (red > amber > green). No DB queries inside `assess()` — caller eager-loads.
- `DashboardController::index()` does a single `Project::with([...])->whereNotIn('status', [archived])->get()` call, builds a `$healthMap` keyed by project ID, and exposes `$projects`, `$healthMap`, `$statusCounts` to the view (DASH-01a / DASH-01b).
- `App\DTO\ProjectHealth` readonly value object (status / reason / overdue) — first tenant of a new `app/DTO/` directory.
- Dashboard route closure removed from `routes/web.php`; `Route::get('/dashboard', [DashboardController::class, 'index'])` registered inside the existing auth middleware group.
- 10 unit tests + 4 feature tests: all GREEN. Full project suite: 583 passed.

## Task Commits

Each task was committed atomically with `--no-verify` (parallel executor convention):

1. **Task 1 (RED) — failing tests + DTO scaffold** — `a2e1910` (test)
2. **Task 2 (GREEN) — ProjectHealthService, DashboardController, route swap** — `8037b2d` (feat)

## Files Created/Modified

- `app/DTO/ProjectHealth.php` — readonly value object `{ status, reason, overdue }`
- `app/Services/ProjectHealthService.php` — `assess(Project): ProjectHealth` with priority-ordered rule set
- `app/Http/Controllers/DashboardController.php` — thin controller, single eager-load, health map + status counts
- `tests/Unit/ProjectHealthServiceTest.php` — 10 unit tests (all health branches, overdue flag, soft-delete guard)
- `tests/Feature/DashboardControllerTest.php` — 4 feature tests (auth guard, 200 response, view data, archived exclusion)
- `routes/web.php` — replaced legacy dashboard closure with `DashboardController` reference

## Decisions Made

- **Service rather than model method for health derivation.** Keeps the rule set unit-testable without database coupling and matches the project's service-layer pattern (per RESEARCH.md Pattern 2).
- **Carbon signed diff direction.** `Carbon::now()->diffInDays($past, false)` returns a negative number — the plan's pseudocode implied positive values. Verified with a direct PHP check (`now->diffInDays(past) = -19.999…`) and flipped the threshold comparisons (`< -14`, `< -7`) accordingly.
- **Soft-deleted RAMS filter in memory.** `->filter(fn (r) => r->deleted_at === null)` on the already-loaded collection, rather than adding a `whereNull('deleted_at')` constraint on the relation — keeps `assess()` agnostic to how the caller loaded the relation.
- **Keep `$recentProjects` in the controller payload.** The existing dashboard blade still references it. Wave 2 (08-02) will replace the panel with the health grid; until then, `$recentProjects = $projects->take(6)` prevents the view from breaking.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] Added `$recentProjects` to controller payload**
- **Found during:** Task 2 (GREEN implementation)
- **Issue:** Existing `resources/views/dashboard.blade.php` references `$recentProjects` at lines 90/106. Dropping that variable (as the plan's controller example did) would cause `test_authenticated_gets_200` to fail with a 500 (undefined variable) before Wave 2's view rewrite ships.
- **Fix:** Added `$recentProjects = $projects->take(6);` in `DashboardController::index()` and passed it through `compact()`. The variable is a slice of the already-loaded `$projects` collection — no additional queries. Commented as "kept for compatibility; Wave 2 replaces this panel with the health grid driven by `$projects` + `$healthMap`".
- **Files modified:** `app/Http/Controllers/DashboardController.php`
- **Verification:** `test_authenticated_gets_200` now passes (response 200).
- **Committed in:** `8037b2d` (Task 2 commit)

**2. [Rule 3 — Blocking] Unit test base class change**
- **Found during:** Task 2 verification run
- **Issue:** Plan instructed `extends PHPUnit\Framework\TestCase`, but `new RamsDocument(['status' => ...])` triggers Eloquent's connection resolver and errors with "Call to a member function connection() on null" when the framework isn't booted.
- **Fix:** Switched `tests/Unit/ProjectHealthServiceTest.php` to `extends Tests\TestCase` so the Laravel kernel is booted (no DB access — phpunit.xml uses sqlite `:memory:`, never connected by our tests). The test still avoids the database entirely.
- **Files modified:** `tests/Unit/ProjectHealthServiceTest.php`
- **Verification:** 10/10 unit tests GREEN in 0.04s–0.54s each.
- **Committed in:** `8037b2d` (Task 2 commit)

---

**Total deviations:** 2 auto-fixed (2 × Rule 3 blocking).
**Impact on plan:** Neither deviation alters scope — both were required to make the plan's own verification pass. Wave 2 will remove the `$recentProjects` compat when it rewrites the view.

## Issues Encountered

- **Worktree had no `vendor/` or `public/build/`.** Git worktree creation copies tracked files only; `vendor/` and `public/build/` are gitignored. Initial test runs failed with autoload errors (missing `vendor/autoload.php`) and Vite manifest errors (for Auth tests that render the guest layout). Resolved by copying `vendor/` from the main project + linking `public/build/` as a Windows directory junction, then running `composer dump-autoload` locally. This is infrastructure setup for the worktree and not part of the plan's deliverables.
- **Worktree branch base drift.** The worktree branch was initially at an older commit than the feature branch HEAD (`6f23f37` vs expected `6afe20f`). Resolved via `git reset --hard 6afe20f…` per the worktree-branch-check protocol.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- **Wave 2 (08-02):** Ready to consume `$projects`, `$healthMap`, `$statusCounts` in the dashboard view rewrite. Alpine.js filter pattern and `STATUS_LABELS` / `STATUS_COLOURS` constants already verified by research — Wave 2 is a pure view/UI plan.
- **Contract surface for future phases:** `ProjectHealthService::assess()` is stable. The DTO's `reason` string is operational-only (no PII / secrets) per the plan's threat model — safe to expose anywhere an authenticated user can see projects.
- **Known scope boundary:** This plan intentionally does NOT cover the stat cards being recomputed from the eager-loaded collection (still uses small `count()` queries for total RAMS / surveys / imports — fine at current scale; documented as open question in research, left as-is).

## Self-Check: PASSED

Files verified on disk:
- FOUND: app/DTO/ProjectHealth.php
- FOUND: app/Services/ProjectHealthService.php
- FOUND: app/Http/Controllers/DashboardController.php
- FOUND: tests/Unit/ProjectHealthServiceTest.php
- FOUND: tests/Feature/DashboardControllerTest.php
- FOUND: routes/web.php (modified)
- FOUND: .planning/phases/08-enterprise-dashboard/08-01-SUMMARY.md

Commits verified in worktree branch history:
- FOUND: a2e1910 (Task 1 RED)
- FOUND: 8037b2d (Task 2 GREEN)

Tests: 14 passed (30 assertions) for plan files; full suite 583 passed after worktree infrastructure fix.

---
*Phase: 08-enterprise-dashboard*
*Completed: 2026-04-19*
