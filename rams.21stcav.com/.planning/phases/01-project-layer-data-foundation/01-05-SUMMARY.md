---
phase: 01-project-layer-data-foundation
plan: 05
subsystem: project-controller
tags: [gap-closure, controller, blade, authorization, validation, search]
dependency_graph:
  requires: [01-01-PLAN.md, 01-02-PLAN.md]
  provides: [shared-project-visibility, quote_reference-create-form, similar-project-warning, site_address-search]
  affects: [ProjectController.php, create.blade.php]
tech_stack:
  added: []
  patterns: [abort_unless-auth-check, whereRaw-LOWER-comparison, conditional-flash-redirect]
key_files:
  created: []
  modified:
    - app/Http/Controllers/ProjectController.php
    - resources/views/projects/create.blade.php
decisions:
  - "D-15 enforced: shared project visibility — forUser() scope removed from index() and statusCounts"
  - "D-19 enforced: any authenticated user can trigger lifecycle transitions — authorizeProject() replaced in transition() and show()"
  - "D-14 enforced: similar-project warning implemented as non-blocking flash with LOWER() case-insensitive comparison"
  - "quote_reference replaces ref as required user-facing field on create form; ref column remains for internal auto-ref"
metrics:
  duration_minutes: 25
  completed_date: "2026-04-10"
  tasks_completed: 2
  tasks_total: 2
  files_changed: 2
---

# Phase 1 Plan 5: Controller & Form Gap Closure Summary

**One-liner:** Closed six controller/form gaps — shared project visibility via forUser() removal, auth-only guards on show()/transition(), quote_reference required field on create form, similar-project LOWER() warning, and site_address in search.

## What Was Built

### Task 1: ProjectController — 7 targeted changes

**Change 1 — index() main query:** Removed `->forUser(auth()->id())` from main `Project::with('latestPackage')` query. All authenticated users now see all projects (D-15).

**Change 2 — index() statusCounts:** Replaced `Project::forUser(auth()->id())` with `Project::query()->whereNull('deleted_at')` so status tab counts reflect all projects, not just the current user's.

**Change 3 — index() search:** Added `->orWhere('site_address', 'like', "%{$search}%")` to search closure. Search now covers name, client_name, site_address, and ref columns.

**Change 4 — store() validation:** Replaced `'ref' => ['nullable', 'string', 'max:50']` with `'quote_reference' => ['required', 'string', 'max:50']`. Quote reference is now a required field at project creation.

**Change 5 — store() similar-project warning:** Added `Project::whereRaw('LOWER(client_name) = ?', ...)->whereRaw('LOWER(site_address) = ?', ...)->whereNull('deleted_at')->exists()` check before `create()` call. Redirect uses `->with('warning', ...)` when a similar project exists, `->with('success', ...)` otherwise. Creation is never blocked (D-14).

**Change 6 — show() authorization:** Replaced `abort_if($project->user_id !== auth()->id() && auth()->user()?->role !== 'admin', 403)` with `abort_unless(auth()->check(), 403)`. Any authenticated user can view any project (D-15).

**Change 7 — transition() authorization:** Replaced `$this->authorizeProject($project)` with `abort_unless(auth()->check(), 403)`. Any authenticated user can trigger lifecycle transitions (D-19). `authorizeProject()` remains in `edit()`, `update()`, `archive()`, `reopen()`, and `destroy()` — those are owner/admin-only operations.

### Task 2: create.blade.php — 4 targeted changes

- **Header nav:** "← Back" → "← Back to Projects" (UI-SPEC copy contract)
- **Removed ref field:** Old "Project Ref" nullable input removed entirely (internal auto-ref, not user-facing)
- **Added quote_reference field:** Single-column input after Site Address, with `required`, `@error` block, `invalid-feedback` div, and `form-help` text
- **Footer cancel:** "Cancel" → "Back to Projects" (UI-SPEC descriptive navigation label)

Final form field order: Project Name (span 2) → Client (single) → Site Address (span 2) → Quote Reference (single) → Works Description (span 2) → Notes (span 2)

## Gaps Closed

| Gap | Description | Status |
|-----|-------------|--------|
| Gap 1 | quote_reference on create form + @error block | Closed |
| Gap 2 | Shared visibility — forUser() removed from index() and statusCounts | Closed |
| Gap 3 | show() and transition() use abort_unless(auth()->check(), 403) | Closed |
| Gap 5 | Similar-project warning in store() with LOWER() comparison | Closed |
| Gap 6 | quote_reference required in store() validation | Closed |
| Gap 7 | site_address in search orWhere chain | Closed |

Gap 4 (importFromData() method on QuoteImportService) remains open — addressed by Plan 01-06.

## Deviations from Plan

None — plan executed exactly as written. All 7 controller changes and 4 view changes applied precisely per specification.

## Known Stubs

None. No placeholder data, hardcoded empty values, or TODO markers introduced in this plan.

## Threat Surface Scan

No new network endpoints, auth paths, file access patterns, or schema changes introduced. The relaxation of show() and transition() to auth()->check() is explicitly accepted per threat register entries T-01-05-02 and T-01-05-03 (intranet tool, all users are internal employees).

## Self-Check

**Files modified:**
- FOUND: app/Http/Controllers/ProjectController.php
- FOUND: resources/views/projects/create.blade.php
- FOUND: .planning/phases/01-project-layer-data-foundation/01-05-SUMMARY.md

**Acceptance criteria verified via grep:**
- `forUser` — 0 matches in ProjectController (removed from index and statusCounts)
- `quote_reference` — present in store() validation (line 84) and similarExists query (lines 92, 101)
- `site_address.*like` — present in search closure (line 50)
- `similarExists` — present at lines 92 and 101
- `abort_unless(auth()->check(), 403)` — present in show() (line 115) and transition() (line 213)
- `authorizeProject()` — still called in edit(), update(), archive(), reopen(), destroy()
- create.blade.php: quote_reference input with id, name, required, @error block, form-help present
- create.blade.php: ref input field absent
- create.blade.php: "Back to Projects" in both header and footer

## Self-Check: PASSED
