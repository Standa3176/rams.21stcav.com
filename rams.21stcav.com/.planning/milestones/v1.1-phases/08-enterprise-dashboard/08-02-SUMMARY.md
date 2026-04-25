---
phase: 08-enterprise-dashboard
plan: 02
subsystem: dashboard

tags: [laravel, blade, alpinejs, ui, dashboard]

# Dependency graph
requires:
  - phase: 08-01
    provides: DashboardController, ProjectHealthService, ProjectHealth DTO; view variables `$projects`, `$healthMap`, `$statusCounts`, plus existing stat + recent-RAMS payloads.
  - phase: 12-install-task-generation
    provides: InstallProgramme / InstallTask relations consumed by the programme widget.

provides:
  - enterprise-dashboard-view: Rewritten `resources/views/dashboard.blade.php` with health grid (all non-archived projects, no 6-cap), status filter strip with Alpine `x-show` + URL-hash bookmarkability, and install programme % widget.
  - health-badge-component: `<x-dashboard.health-badge :health="$health" />` pill that reuses `.dash-status-badge` / `.dash-status-badge__dot` classes from `status-badge.blade.php` with colour/label derived from `ProjectHealth::status` and an overdue indicator dot when `overdue` is true.

requirements_satisfied:
  - DASH-01b: All non-archived projects listed (no cap)
  - DASH-01f: Status filter strip filters the grid client-side with URL hash for bookmarkability
  - DASH-01g: Health badge renders green / amber / red per project with reason-text tooltip and overdue marker
  - DASH-01h: Install programme % widget shown only for installing / commissioning projects with an active programme

key-files:
  created:
    - resources/views/components/dashboard/health-badge.blade.php
  modified:
    - resources/views/dashboard.blade.php
---

## What was built

Full user-facing enterprise dashboard consuming Wave 1's controller payload. No new queries, no controller changes.

### Files created
- `resources/views/components/dashboard/health-badge.blade.php` — single-prop component (`$health`) rendering a coloured pill with label (`Healthy` / `Warning` / `Blocked`), reason in `title` tooltip, and a secondary dot when `$health->overdue` is true.

### Files modified
- `resources/views/dashboard.blade.php` — rewritten. Structure:
  1. Page header (retained unchanged)
  2. Stat cards (retained unchanged, 4 cards)
  3. Status filter strip + health grid, wrapped in one Alpine `x-data` block so the filter binding is shared
  4. Recent RAMS panel (retained full-width via `dash-panels--single`)
  5. Quick links strip (retained unchanged)

## Implementation notes

### Alpine filter wiring
Filter chips use static `class="dash-chip"` with Alpine `:class="{ 'dash-chip--active': filter === '{key}' }"` so the chip is styled from the first byte of HTML — no flash-of-unstyled-content before Alpine hydrates. URL hash is wired via `$watch('filter', v => window.location.hash = v || ' ')` and initialised from `window.location.hash` on mount.

### Programme widget
Only rendered when `$project->status` is `installing` or `commissioning` **and** `$project->activeInstallProgramme` is non-null. Percentage is `round(tasks_done / tasks_total * 100)`; `0` is shown explicitly when there are tasks but none complete, `—` is shown when the project is out of that stage range or no active programme exists.

### CSS scope
New classes are prefixed `.dash-` and live inside the view's `<style>` block. They re-use existing status-badge class tokens where possible to avoid duplicating colour rules. Responsive breakpoint at 900px collapses the grid header and reflows rows into a 2-column layout.

## Deviations from plan

1. **FOUC fix (post-checkpoint)** — plan specified `:class="filter === '' ? 'dash-chip dash-chip--active' : 'dash-chip'"`. This emits no `class` attribute on the server-rendered HTML, so chips appeared as unstyled browser buttons until Alpine hydrated. User caught this in visual verification. Changed to static `class="dash-chip"` baseline + Alpine `:class="{ 'dash-chip--active': ... }"` modifier. Functional behaviour identical; fixes the FOUC. Committed as `fix(08-02): ensure dash-chip baseline class renders before Alpine hydrates`.

2. **Recent RAMS panel layout** — plan called for making the previously two-panel row "single-panel" since the project list moved up to the health grid. Implemented via `dash-panels--single` class; no duplicate project column.

## key-files
  created:
    - resources/views/components/dashboard/health-badge.blade.php
  modified:
    - resources/views/dashboard.blade.php

## Verification

### Automated
- `php artisan test tests/Feature/DashboardControllerTest.php` — **4 passed (10 assertions)**
- `php artisan test` (full suite) — **583 passed, 0 failed** (10 warnings are pre-existing PHPUnit 12 doc-comment deprecation notices unrelated to this phase)

### Human checkpoint (DASH-01b/f/g/h visual verification)
Approved by user after merging Wave 2 Task 1 commit (`20d84a3`) onto `feat/worksheet-classifier-universal` and applying the FOUC fix (`04cf678`). Screenshot confirms:
- Stat cards row retained (Active/RAMS/Surveys/Imports)
- All 5 non-archived projects visible in the health grid (no 6-cap)
- Stage + Health badges both render per row (Blocked/Healthy observed)
- Status strip chips render with counts (archived excluded)
- Quick links strip retained at bottom

## Commits
- `20d84a3` — feat(08-02): add health-badge component and enterprise dashboard UI
- `04cf678` — fix(08-02): ensure dash-chip baseline class renders before Alpine hydrates

## Threat model outcome
All STRIDE entries in the plan were `accept` or `mitigate` via Wave 1 eager loading. No new server-side data paths, no new user input processing, no data-disclosure deltas beyond what DASH-01b explicitly requires (shared operations dashboard for authenticated internal staff).
