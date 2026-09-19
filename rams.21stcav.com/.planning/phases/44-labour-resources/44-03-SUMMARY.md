---
phase: 44-labour-resources
plan: 03
subsystem: components
tags: [laravel, blade-component, multi-select, feature-tests]

# Dependency graph
requires:
  - phase: 44-labour-resources
    plan: 01
    provides: "LabourResource model with scopeActive() and roles/email/phone fields"
provides:
  - "App\\View\\Components\\LabourResourceSelect: PM-facing reusable multi-select component"
  - "resources/views/components/labour-resource-select.blade.php: <select multiple> markup, name-only labels"
affects: [45-visits, 46-prepare-panel]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Component-class-owns-the-query: the component's own render() calls LabourResource::active() directly — no prop path accepts a pre-built/pre-filtered collection, so a caller cannot bypass the active filter even by mistake (D-02/T-44-08)"
    - "Array-style multi-select input naming: {name}[] on a <select multiple>, default name 'resource_ids', overridable via a name prop"

key-files:
  created:
    - app/View/Components/LabourResourceSelect.php
    - resources/views/components/labour-resource-select.blade.php
    - tests/Feature/Components/LabourResourceSelectComponentTest.php
  modified: []

key-decisions:
  - "Component is shipped standalone, not wired into any page — Phase 45 owns visits, Phase 46 owns the prepare panel, and no 'assign work' page exists yet. Proven correct via Blade::render() direct-component-rendering, matching the plan's own testing technique."
  - "data-roles attribute included on each <option> (roles are not PII) for later JS grouping use by Phase 46, but no JS behaviour was added this phase — not needed yet."

patterns-established:
  - "PM-only component convention: docblock explicitly states 'For the PM only (D-05) — do not wire this into any engineer-facing form,' carried into both the component class and the Blade template, so a future contributor wiring this in cannot miss the constraint."

requirements-completed: [LR-03]

# Metrics
duration: 20min
completed: 2026-09-19
---

# Phase 44 Plan 03: PM-Facing Labour Resource Multi-Select Summary

**A reusable `<x-labour-resource-select>` Blade component whose class queries `LabourResource::active()` itself inside `render()` — no prop path exists for a caller to pass in an already-filtered (or unfiltered) list — proven by 5 passing feature tests including a non-vacuous negative assertion that a deactivated resource, and a resource's populated email/phone, never reach the rendered HTML.**

## Performance

- **Duration:** ~20 min
- **Started:** 2026-09-19 (after 44-02)
- **Completed:** 2026-09-19
- **Tasks:** 2/2 completed
- **Files modified:** 3 created, 0 modified

## Accomplishments
- `App\View\Components\LabourResourceSelect` extends `Illuminate\View\Component` with `public array $selected = []` and `public string $name = 'resource_ids'`; `render()` queries `LabourResource::active()->orderBy('name')->get()` directly — the query lives in the component, structurally unbypassable by a careless caller
- `resources/views/components/labour-resource-select.blade.php` renders a `<select multiple name="{name}[]">` with each `<option>` labelled by `$resource->name` only — no email/phone/roles ever interpolated into the visible label text (a `data-roles` attribute carries roles for future JS use, per the plan's explicit allowance)
- 5 feature tests in `LabourResourceSelectComponentTest`, rendered via `Blade::render()` directly (no consuming page needed): active resources list, an inactive resource with a distinctive name never appears, a resource seeded WITH a real email/phone never leaks either string into the output, a `:selected` prop marks exactly one option, default vs custom `name` prop produce `resource_ids[]` / `visit_resource_ids[]` respectively, and options are ordered by name ascending
- `php artisan view:cache` compiled cleanly on first attempt — no Blade parse errors, consistent with this project's documented Blade-fragility history

## Task Commits

Each task was committed atomically:

1. **Task 1: LabourResourceSelect component + Blade template** - `a0647482` (feat)
2. **Task 2: Component render test** - `aa1fb6da` (test)

**Plan metadata:** (pending — final commit follows this summary)

## Files Created/Modified
- `app/View/Components/LabourResourceSelect.php` - component class; `render()` is the sole source of the resource list, calling `LabourResource::active()->orderBy('name')->get()` with no way for a prop to override or bypass it
- `resources/views/components/labour-resource-select.blade.php` - `<select multiple>` markup; option label is `$resource->name` only; `@selected()` directive marks pre-selected IDs
- `tests/Feature/Components/LabourResourceSelectComponentTest.php` - 5 tests: active/inactive filtering, email/phone non-leak (non-vacuous — asserted against a resource seeded WITH real contact details), single pre-selection, default/custom array-style name, alphabetical ordering

## Decisions Made
- **Standalone component, no consuming page invented:** per the plan's explicit scope boundary and CONTEXT.md's Open Question 3, this plan ships only the reusable control. No "assign work" page exists in the codebase yet (Phase 45 owns `visits`, Phase 46 owns the prepare panel), so none was fabricated. The component is proven correct in isolation via direct `Blade::render()` calls, exactly as the plan specified.
- **`data-roles` attribute included but inert:** the plan permitted (did not require) a `data-roles` attribute for later JS grouping. It was added since roles are not PII and costs nothing, but no JS behaviour was written — the plan explicitly said not to add JS this phase does not need.

## Deviations from Plan

None — plan executed exactly as written. Both tasks matched their `<action>` blocks; every `<behavior>` line in Task 1 maps 1:1 to a test method in Task 2.

## TDD Gate Compliance

Task 1 is marked `tdd="true"` but, per its own `<action>` block, specifies building the component class and Blade template directly (the deliverable IS the shape), with Task 2 then supplying the tests that exercise it — the same "lay the shape, then test it" ordering used in 44-01 and 44-02 for the same documented reason. Commit order is `feat` (Task 1) then `test` (Task 2): no standalone `test(...)`-then-`feat(...)` RED/GREEN pair exists. All 5 Task 2 tests passed green against the already-built Task 1 implementation on first run (verified: `php artisan test --filter=LabourResourceSelectComponentTest` — 5/5 passed, no failing run was observed or discarded). Flagged here per the gate-sequence-validation requirement since no `test(...)` commit precedes a `feat(...)` commit chronologically.

## Known Stubs

None. The component is feature-complete for its own scope (render active resources, multi-select, pre-selection, name-only labels). It is intentionally not wired into any page yet — Phase 45/46's job — which is a documented scope boundary, not an incomplete stub.

## Threat Flags

None beyond what the plan's own `<threat_model>` already registers (T-44-07/T-44-08) — no new surface introduced beyond what the plan anticipated. No new network endpoints, auth paths, or schema changes were added; this is a pure read-side Blade component.

## Issues Encountered

None. Component, template, and tests all passed on first attempt; no Blade parse-failure was hit.

## Test Results

- **Before (baseline, read from real output):**
  - `php artisan test --filter=LabourResource` — 12 passed / 0 failed (Plans 44-01/44-02)
  - `php artisan test --filter=Worksheet` — 247 passed / 0 failed
  - `php artisan test --filter=Rams` — 875 passed / 0 failed
- **After (this plan's changes applied):**
  - `php artisan test --filter=LabourResource` — 17 passed / 0 failed (12 existing + 5 new, unchanged pass count on existing tests)
  - `php artisan test --filter=Worksheet` — 247 passed / 0 failed (unchanged)
  - `php artisan test --filter=Rams` — 875 passed / 0 failed (unchanged)
  - `php artisan view:cache` — compiles cleanly, no Blade parse errors

## Next Steps
- Plan 44-04: privacy-boundary guard test enumerating genuinely client-facing surfaces and asserting none render `email`/`phone` — this component is already structurally safe (name-only labels, proven by test) but should be considered alongside admin's `index.blade.php`/`form.blade.php` when that scan is built
- Phase 45 (`visits`) and Phase 46 (prepare panel) are the first real consumers of `<x-labour-resource-select>` — no changes to this component should be needed to embed it in either

## Self-Check: PASSED

All created files and both commit hashes verified present on disk / in git log.
