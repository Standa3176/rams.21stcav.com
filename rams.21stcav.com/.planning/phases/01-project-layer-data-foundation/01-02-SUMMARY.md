---
phase: 01
plan: 02
subsystem: project-ui
tags: [blade, controller, ui, filtering, linked-records]
dependency_graph:
  requires: [01-01]
  provides: [project-show-linked-records, project-index-client-filter]
  affects: [ProjectController, projects/show.blade.php, projects/index.blade.php]
tech_stack:
  added: []
  patterns: [linked-records-array, eager-load-with-limit, client-filter-dropdown]
key_files:
  created: []
  modified:
    - app/Http/Controllers/ProjectController.php
    - resources/views/projects/index.blade.php
    - resources/views/projects/show.blade.php
decisions:
  - Route names in $linkedRecords adapted to match existing routes (rams.review, om-manuals.edit, cable-schedules.edit) since rams.show/om-manuals.show/cable-schedules.show do not exist
  - O&M empty action uses POST form (om-manuals.generate-from-project) rather than GET link, consistent with existing show page pattern
  - RAMS empty action links to rams.upload.create with project_id since rams.create does not accept project context
metrics:
  duration: ~25min
  completed: "2026-04-10"
  tasks: 2
  files: 3
---

# Phase 01 Plan 02: Project Dashboard UI Summary

**One-liner:** Client name filter added to project listing; project show page gains breadcrumb, Linked Records card (5 document types, always visible), and expanded sidebar metadata with quote reference and updated timestamp.

## What Was Built

### Task 1: Projects index — client name filter

- `ProjectController::index()` reads `?client=` query param and applies `where('client_name', $client)` via Eloquent `->when()` — parameterized, no raw SQL.
- `$clients` collection (distinct, sorted, non-null client names) passed to view.
- `$client` variable passed through to view for select state preservation.
- `showDeleted` branch also passes empty `$clients`/`$client` to prevent undefined variable errors.
- `index.blade.php` filter bar gains a `<select name="client">` dropdown with "All clients" first option, placed between status tabs and search input. Auto-submits on change.
- Clear link now triggers when either `$search` OR `$client` is active.

### Task 2: Project show page — Linked Records card and sidebar metadata

**Controller (`ProjectController::show()`):**
- Eager-load relations now use `fn($q) => $q->latest()->limit(5)` on all four document types to constrain query size.
- Builds `$linkedRecords` array: 5 entries (RAMS, Survey, Worksheet, O&M, Cable Schedule), each with `type`, `badge_class`, `records` collection, `route_name`, `empty_action_label`, `empty_action_route`.
- Worksheet entry uses `collect()` placeholder and `null` action until Phase 4.
- Passes `$linkedRecords` to view alongside existing `$project` and `$nextStatus`.

**View (`resources/views/projects/show.blade.php`):**
- Breadcrumb `<nav aria-label="breadcrumb">` added before `.page-header` — "Projects › {Project Name}".
- Grid wrapper gains `proj-show-grid` CSS class for responsive targeting.
- Linked Records `.card.card-sm` inserted after Quote History card in left column:
  - Each document type always renders (D-06 compliance — no hidden rows).
  - Non-empty: renders `.data-table` inside `<x-dashboard.table-wrapper>` with Type badge, Reference/Name (truncated 50 chars), Status badge, Date, View action button.
  - Empty (non-Worksheet): inline `<p>` empty text + action button/form.
  - Worksheet empty: "Coming in Phase 4." text, no action button.
  - O&M empty action uses `<form method="POST">` (route requires POST).
- Sidebar "Project Details" card updated per D-08:
  - `client_name` and `site_address` remain.
  - `quote_reference ?? ref ?? '—'` added as "Quote ref" row.
  - `created_at->format('d M Y')` retained.
  - `updated_at->diffForHumans()` added as "Updated" row.
  - Status badge retained; `Ref` row removed (superseded by "Quote ref").
  - No data source badge (D-08 compliant).
- Responsive `<style>` block added: `@media (max-width: 900px) { .proj-show-grid { grid-template-columns: 1fr; } }`.
- Lifecycle progress bar markup is unchanged.
- No "Generate All" button anywhere on page (D-07 compliant).

## Deviations from Plan

### Auto-adapted Route Names

**1. [Rule 1 - Bug] Adapted $linkedRecords route names to match existing routes**
- **Found during:** Task 2 implementation
- **Issue:** Plan specified `rams.show`, `site-surveys.show`, `om-manuals.show`, `cable-schedules.show` and `rams.create`/`om-manuals.create`/`cable-schedules.create` with project params — none of these routes exist. RAMS resource only exposes `['index', 'create', 'store', 'destroy']`; O&M and Cable Schedule resources similarly lack `show`. RAMS `create` does not accept a `project` parameter.
- **Fix:** Used actual existing routes: `rams.review` (view), `site-surveys.show` (exists), `om-manuals.edit` (view), `cable-schedules.edit` (view). For empty action routes: `rams.upload.create?project_id=`, `site-surveys.from-project`, `om-manuals.generate-from-project` (POST form), `cable-schedules.create?project_id=`.
- **Files modified:** `app/Http/Controllers/ProjectController.php`
- **Commit:** pending

## Known Stubs

None. All 5 document type rows are wired to real data. Worksheet row uses `collect()` placeholder intentionally — this is documented as Phase 4 work, not a rendering stub (the empty state text explicitly says "Coming in Phase 4").

## Threat Flags

No new network endpoints, auth paths, or schema changes introduced. All threat mitigations from plan threat model are implemented:
- T-02-01: `?client=` passes through Eloquent `->when()->where()` — parameterized binding confirmed.
- T-02-04: `{{ $project->name }}` in breadcrumb — Blade auto-escapes, XSS safe.

## Self-Check

Files verified present:
- `resources/views/projects/show.blade.php` — contains "Linked Records" ✓
- `resources/views/projects/index.blade.php` — contains `<select name="client">` ✓
- `app/Http/Controllers/ProjectController.php` — contains `where('client_name', $client)` ✓

## Checkpoint

This plan ends at `checkpoint:human-verify`. Tasks 1 and 2 are complete and committed. Human verification required before proceeding to Plan 03.

### Verification Steps

1. Visit `/projects` — confirm client dropdown appears in filter bar between status tabs and search. Select a client to confirm filtering.
2. Visit a project show page (`/projects/{id}`):
   a. Breadcrumb "Projects › {Project Name}" appears above page header.
   b. Lifecycle progress bar is the first visible section in the left column (unchanged).
   c. Linked Records card below Quote History — shows RAMS, Survey, Worksheet, O&M, Cable Schedule rows.
   d. Empty rows show action buttons (except Worksheet — "Coming in Phase 4.").
   e. No "Generate All" button anywhere.
   f. Sidebar shows: client name, site address, quote reference, created date, updated (relative time). No data source badge.
3. Resize browser below 900px — two-column layout collapses to single column.
