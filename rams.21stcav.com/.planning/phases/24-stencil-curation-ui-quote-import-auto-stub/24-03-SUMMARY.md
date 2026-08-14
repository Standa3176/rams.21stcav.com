---
phase: 24-stencil-curation-ui-quote-import-auto-stub
plan: 03
subsystem: admin-ui
tags: [laravel, blade, admin, device-stencils, curation-ui]

# Dependency graph
requires:
  - phase: 24-01
    provides: needs_review (indexed) + logo_path columns, SOURCE_* constants, ports() relation
  - phase: 24-02
    provides: QuoteImportStencilStubber populating real needs_review=true rows from live imports
provides:
  - admin.device-stencils.index route + DeviceStencilController::index()
  - resources/views/admin/device-stencils/index.blade.php list view
  - Nav entry (layouts/navigation.blade.php)
affects: [24-04, 24-05, 24-06, 24-07]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Route::has() guard around a forward-referenced route name — safe way to link to an action that ships in a later same-controller plan without a RouteNotFoundException today"
    - "Scoped CSS override class (.stc-table th) layered on top of an existing shared primitive (.data-table) to hit a UI-SPEC typography requirement (13px/600/uppercase) the shared primitive doesn't provide by default"

key-files:
  created:
    - app/Http/Controllers/Admin/DeviceStencilController.php
    - resources/views/admin/device-stencils/index.blade.php
    - tests/Feature/Drawings/DeviceStencilListTest.php
  modified:
    - routes/web.php
    - resources/views/layouts/navigation.blade.php

key-decisions:
  - "needs_review filter applied only when the query key is present (Request::has), not merely non-empty — lets ?needs_review=0 explicitly filter to 'No' without being indistinguishable from 'not filtered at all'"
  - "source filter is allow-listed against DeviceStencil::SOURCE_* and silently ignored (not 422'd) when it doesn't match — matches the plan's explicit 'no crash on a garbage query string' instruction (T-24-05)"
  - "Edit link on each row guarded by Route::has('admin.device-stencils.edit') — that route doesn't exist until Plan 24-04 ships on this same controller class; guarding avoids a RouteNotFoundException on every row today and needs no further change when 24-04 lands"

patterns-established:
  - "DeviceStencilController is the shared controller class for the whole curation UI (index today; edit/update/promote/preview in 24-04 through 24-07) — this plan's index() is deliberately the only method, per the plan's own note that later waves extend this SAME file"

requirements-completed: [DRAW-50]

# Metrics
duration: ~30min
completed: 2026-08-14
---

# Phase 24 Plan 03: Admin Device-Stencil List View Summary

**`/admin/device-stencils` — the DRAW-50 curation queue: filterable (source/needs_review/manufacturer) and part_number-searchable list of every device_stencils row, built as a byte-identical sibling of the existing `admin/devices` filter-row and `admin/device-cable-rules` table chrome.**

## Performance

- **Duration:** ~30 min
- **Started:** 2026-08-14
- **Completed:** 2026-08-14
- **Tasks:** 2/2 completed
- **Files modified:** 5 (3 created, 2 modified)

## Accomplishments

- `DeviceStencilController::index()` — allow-listed `source` filter (rejects garbage query values silently rather than crashing), `needs_review` filter backed by the real indexed column from Plan 24-01 (never a `metadata` JSON extract, per D-10), `manufacturer` exact-match filter, and `part_number`-only substring search (`q`) — all parameterised Eloquent `->where()` calls, no raw SQL concatenation (T-24-05).
- `admin.device-stencils.index` route registered inside the existing `Route::middleware('admin')->group()` block in `routes/web.php` — no new auth surface, inherits the pre-existing admin gate (T-24-06, `accept` disposition).
- List view (`resources/views/admin/device-stencils/index.blade.php`) renders the filter row (`.stc-filter-row`, byte-identical `12px 14px` padding to `.dv-filter-row` per UI-SPEC's deliberate reuse-as-is stance), the `Source` badge mapping (`.badge-grey`/`.badge-green`/`.badge-blue`), the separate `.badge-yellow` "Needs review" pill (rendered only when true — absence is the "no" state, no muted "No" pill), ports count with tabular-nums, a 28px logo thumbnail or em-dash, relative "Updated" timestamp, and both distinct empty-state copy blocks verbatim from the UI-SPEC Copywriting Contract table.
- Nav entry added beside `admin.devices.index` / `admin.device-cable-rules.index` in `layouts/navigation.blade.php`, with a fresh hand-rolled inline SVG glyph (not the shared Devices rectangle-grid icon) and the standard `request()->routeIs('admin.device-stencils.*') ? 'active' : ''` active-state pattern.
- `tests/Feature/Drawings/DeviceStencilListTest.php` — 8 tests: admin gate (non-admin gets 403), full-list render (all 3 fixture part_numbers visible), `source` filter isolation, combined `needs_review` + `manufacturer` filter, `q` search matching `part_number` substring only (explicitly asserts it does NOT match on manufacturer/model), and both empty-state copy blocks (unfiltered positive-tone vs. filtered-with-Clear-link).

## Task Commits

Each task was committed atomically:

1. **Task 1: Routes + nav entry + DeviceStencilController::index()** - `8844b24` (feat)
2. **Task 2: index.blade.php list view + feature test** - `bd62935` (feat)

_No separate test/refactor commits — this plan's tasks are `type="auto"`, not TDD-gated; each task commit bundles its implementation + tests together._

## Files Created/Modified

- `app/Http/Controllers/Admin/DeviceStencilController.php` - `index()` action: filtered/paginated stencil list, `withCount('ports')`, distinct-manufacturer dropdown source.
- `resources/views/admin/device-stencils/index.blade.php` - List view: filter row, data table, badges, thumbnails, two empty states, pagination.
- `tests/Feature/Drawings/DeviceStencilListTest.php` - 8 feature tests covering admin gate, filters, search, and both empty states.
- `routes/web.php` - `admin.device-stencils.index` route inside the existing admin middleware group; `DeviceStencilController` import.
- `resources/views/layouts/navigation.blade.php` - "Stencils" nav entry beside Devices / Cable Rules.

## Decisions Made

- **`needs_review` filter gated on `Request::has()`, not truthiness.** Using `$request->boolean('needs_review')` unconditionally would make `?needs_review=0` (explicit "No" filter) indistinguishable from "no needs_review filter applied at all" — both would evaluate the same boolean-cast value. Checking `$request->has('needs_review')` first, then applying `$request->boolean('needs_review')` as the filter value, lets "No" and "not filtered" stay distinct, matching the UI's `All / Yes / No` select semantics.
- **`Route::has()` guard on the per-row Edit link.** The plan's action text asserted referencing `route('admin.device-stencils.edit', $stencil)` today is "safe... since Blade `route()` calls resolve at request time" — that reasoning doesn't hold: Laravel's `route()` helper throws `RouteNotFoundException` immediately if the named route isn't registered, regardless of when the Blade template is rendered, and `.edit` doesn't exist until Plan 24-04. Verified this is a genuine bug by inspection (not by watching the test fail — the fix was applied before running the suite) and fixed under deviation Rule 1 (auto-fix bug, blocking): wrapped the link in `@if (Route::has('admin.device-stencils.edit'))`, falling back to a plain em-dash today. No behaviour change needed in 24-04 — the guard becomes a no-op once that route exists.
- **DRAW-50 marked complete.** The plan's critical-implementation-notes flagged "DRAW-50 is split across 24-02 and 24-03, so check before ticking" (mirroring 24-01's correct non-tick of DRAW-51). Checked `.planning/ROADMAP.md` (Phase 24 plan list, lines 154-162) before ticking: 24-02's own line reads `Requirements: none (fulfils unnumbered Success Criteria 1 + 2, not a DRAW-5x UI requirement)`; 24-03's line reads `Requirements: DRAW-50` with no other plan claiming it. `.planning/REQUIREMENTS.md`'s DRAW-50 text — "Admin route `/admin/device-stencils` — list view with filter by source... and search by part_number" — describes exactly what this plan alone delivers (24-02 populates the data the list displays, but does not touch the UI requirement's own scope). Ticked accordingly via `requirements.mark-complete DRAW-50`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Guarded the forward-referenced `admin.device-stencils.edit` route link**
- **Found during:** Task 2 (writing the list view's Actions column)
- **Issue:** The plan's action text instructs linking to `route('admin.device-stencils.edit', $stencil)` on every row, asserting this is safe because "Blade `route()` calls resolve at request time." That is incorrect — the named route must already be registered in the route collection when `route()` is called, or Laravel throws `RouteNotFoundException` immediately. That route ships in Plan 24-04, not this plan, so as written every row (and the whole page, once any stencil exists) would 500.
- **Fix:** Wrapped the link in `@if (Route::has('admin.device-stencils.edit')) ... @else <span class="stc-muted">—</span> @endif`. Once Plan 24-04 registers the route, the real Edit link appears automatically — no further change needed there.
- **Files modified:** `resources/views/admin/device-stencils/index.blade.php`
- **Commit:** `bd62935`

## Issues Encountered

None new. Did not re-run the broader `tests/Feature/Drawings` suite for this plan (scoped `--filter=DeviceStencilListTest` only, per the verification-gates instruction not to start a full-repo run) — the 2 pre-existing `DrawIoSpikeController` constructor-arity failures logged in `deferred-items.md` by Plan 24-01 are unrelated to any file this plan touches and were not re-checked.

## User Setup Required

None - no external service configuration required. Depends on Plan 24-01's migration already being live on any environment where this screen is opened (per 24-01/24-02's SUMMARY warnings) — this plan does not run migrations.

## Next Phase Readiness

- `DeviceStencilController` is now a real, routable class — Plan 24-04 extends it in place with `edit()`/`preview()` (per the plan header's explicit note that all curation-UI plans share this one controller file).
- The `Route::has()` guard on the Edit link means Plan 24-04 needs no follow-up change to this view to make the Edit link live — it activates automatically once `.edit` is registered.
- List view is fully wired to real data: any stencil created by Plan 24-02's `QuoteImportStencilStubber` (or Phase 21's lazy render-time fallback) is immediately visible and filterable here.
- No blockers for Wave 3 (Plan 24-04). D-17 (curated-stencil edit-destroys-artwork guard) remains unaffected — out of this plan's scope.

---

## 🚨 Files to upload to live

Per Phase 21 D-13 (local-edit-then-upload convention), the following files from this plan must be uploaded to the Hostinger VPS on next deploy:

- `app/Http/Controllers/Admin/DeviceStencilController.php`
- `resources/views/admin/device-stencils/index.blade.php`
- `routes/web.php`
- `resources/views/layouts/navigation.blade.php`

**No new migration in this plan** — depends entirely on Plan 24-01's `needs_review`/`logo_path`/`device_stencil_audits` migration already having been run on live (`php artisan migrate`), per that plan's SUMMARY warning. If that migration has not yet been applied on live, opening `/admin/device-stencils` there will hard-fail (missing `needs_review`/`logo_path` columns) — confirm Plan 24-01's live migration status before uploading this plan's files.

Test file (`tests/Feature/Drawings/DeviceStencilListTest.php`) is not required on live — exists for the local/CI test suite only.

## Self-Check: PASSED

All 5 files (3 created, 2 modified) verified present on disk. Both task commit hashes (`8844b24`, `bd62935`) verified present in `git log`.
