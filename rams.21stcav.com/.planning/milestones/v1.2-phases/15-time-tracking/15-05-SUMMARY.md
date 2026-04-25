---
phase: 15-time-tracking
plan: 05
subsystem: time-tracking-dashboard-ui
tags: [time-tracking, dashboard, blade, css, ownership-guard, defense-in-depth, phase-15]

# Dependency graph
requires:
  - phase: 15-time-tracking
    plan: 02
    provides: TimeEntryService::summaryForProject(Project) → { total_minutes, per_category: {installation, commissioning, testing, other} } aggregating CLOSED entries only
  - phase: 01-project-layer-and-data-foundation
    provides: Project.user_id ownership field + User::isAdmin() role check — the two inputs to the D-16 visibility gate
provides:
  - Actual Hours widget on /projects/{id} (total + 4-row horizontal bar breakdown)
  - Owner + admin only visibility gate (controller + view defense in depth)
  - Pure-CSS horizontal bar chart pattern (no chart library) in brand-teal palette
  - Empty-state ("No time tracked yet") for projects with no closed entries
  - 2px placeholder sliver pattern for zero-minute categories
affects: 16-commissioning-checklist (reuses the widget card + bar-chart CSS pattern for sign-off breakdowns if needed); any future project-dashboard widget following the "compute in controller, render-only in partial" pattern

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Controller-computed view vars gated behind a single boolean — `$canSeeActualHours` + `$actualHours`, both always present in compact(); `$actualHours = null` when gate fails so the include-condition stays uniform at the view layer"
    - "Defense in depth on PII-adjacent widgets — controller gate computes $canSeeActualHours; view re-checks via @if before @include; partial itself trusts the gate. Either ring alone blocks; both rings together mean a refactor that removes one does not leak data"
    - "Pure-CSS horizontal bar chart via @foreach + inline width% style attribute — no chart library dependency, no JS, no SVG; two divs per row (track + fill), transitions via CSS `transition: width`"
    - "Zero-minute 2px placeholder — bars for categories with 0 min render as 2% width slivers to preserve the 4-row visual rhythm; avoids the dead-row look of truly absent categories"
    - "Constructor-injected service alongside existing ones — `ProjectController::__construct()` expanded from 2 to 3 services via property promotion; preserves the thin-controller-plus-service pattern without adding middleware juggling"
    - "Blade @push('styles') colocation — widget-specific CSS lives in the partial that uses it, stacked onto the app-layout @stack('styles'). No orphan CSS in show.blade.php's 1000+ line `<style>` block"

key-files:
  created:
    - resources/views/projects/_actual-hours-widget.blade.php
    - tests/Feature/Projects/ActualHoursWidgetTest.php
  modified:
    - app/Http/Controllers/ProjectController.php
    - resources/views/projects/show.blade.php

key-decisions:
  - "Controller + view dual gate — the ownership check lives in two places intentionally (Rule 2 / T-15-05-01 mitigation). Controller sets $canSeeActualHours + $actualHours=null when gate fails; view has @if (! empty($canSeeActualHours) && $actualHours !== null) around the @include. Either ring alone is correct; both rings together survive a future refactor that accidentally drops one. Partial itself does not re-check — that would create three places to keep in sync."
  - "Widget position: between Project Overview card and lifecycle bar — D-13 specifies 'top summary row alongside progress bar and status pill'. Inserting between the existing `<x-summary-card title=\"Project Overview\">` close-tag and the `{{-- ── Lifecycle progress bar ─── --}}` comment honours that placement without reshuffling the existing right-column grid (Document counts / Linked Records). One @if block, one @include, five lines of change to show.blade.php."
  - "Pure CSS horizontal bars — D-15 says 'no chart library'. A `<ul>` of 4 rows, each with a `.actual-hours-bar-track` (8px tall, grey background) containing a `.actual-hours-bar` (100% height, coloured, width set inline). `style=\"width: {$pct}%; background: {$colour};\"` is the only dynamic style. Zero JS, zero SVG."
  - "2px placeholder bar for 0-min categories — `$pct = $mins > 0 ? max(2, round($mins/$total*100)) : 2`. Preserves the 4-row visual rhythm. A category with 0 minutes still renders a sliver at the track's left edge so the eye can scan 'Installation / Commissioning / Testing / Other' down the list without the zero rows collapsing visually."
  - "Duration format `Xh Ym` via inline Blade closure — `$fmt = fn($m) => intdiv($m,60).'h '.$m%60.'m'`. Keeps formatting in the partial (a view concern, as the plan explicitly specified). Not a controller helper, not a model accessor — just a closure scoped to the partial that produced it."
  - "Brand-teal colour ladder with Tailwind-grey for Other — #178A95 (installation = strongest brand colour = typically the largest category) → #21A8B5 (commissioning) → #4FB8C2 (testing) → #9CA3AF (other, intentionally grey to push the eye away from 'Other' as a miscellaneous bucket). Hex values hardcoded in the partial rather than `var(--teal)` CSS vars so the widget renders correctly even if/when CSS-var naming conventions drift in later refactors. CSS vars used for *text* colours only (`--text`, `--text-muted` with hex fallbacks) since those track the global theme."
  - "Constructor signature grows by one — `ProjectController::__construct(ProjectService, ProjectDataService, TimeEntryService)`. Property promotion keeps the diff tight (3 lines). No middleware registration to preserve (existing constructor had none)."
  - "`auth()->user()?->isAdmin()` with null-safe operator — covers the edge case where an unauthenticated user somehow reaches show() (shouldn't happen under `auth` middleware but is a cheap belt-and-braces given the widget exposes aggregated hours)."

patterns-established:
  - "Dashboard-widget partial shape — file `_foo-widget.blade.php` with a header @php block (extract + default view vars), a `<x-section-card title=\"...\">` wrapper, a conditional empty-state vs loaded-state body, and a @push('styles') block of widget-scoped CSS. Future project-show-page widgets should follow this template: Actual Hours → Actual Costs → SLA Health → etc."
  - "Controller-view dual ownership gate — for PII-adjacent widgets (hours, costs, client contacts, internal notes), the controller always sets `$canSeeX` and `$x`; the view always has `@if (! empty($canSeeX) && $x !== null) @include(...) @endif`; the partial trusts the gate. Any future widget that exposes anything more sensitive than public project metadata should follow this pattern."
  - "Pure-CSS horizontal bar chart — `ul > li > (label div + bar-track div > bar fill div)`. Widths via inline style attribute computed in the @foreach loop. Transition via `transition: width` on the fill. Re-usable for any 'N-row breakdown with proportional bars' display."

requirements-completed: [INST-04h]

# Metrics
duration: ~1h 15m (spanning Task 1 TDD RED→GREEN, Task 2 partial + include, Task 3 human-verify walkthrough)
completed: 2026-04-21
---

# Phase 15 Plan 05: Actual Hours Dashboard Widget Summary

**Owner/admin-only Actual Hours widget on the project show page — large teal total (Xh Ym), 4-row horizontal bar breakdown by category (Installation #178A95 / Commissioning #21A8B5 / Testing #4FB8C2 / Other #9CA3AF), empty-state for zero-data projects, defense-in-depth visibility gate (controller + view), pure CSS (no chart library), built on Plan 15-02's `TimeEntryService::summaryForProject()`.**

## Performance

- **Duration:** ~1 h 15 min end-to-end (TDD RED → GREEN → partial wiring → human-verify walkthrough)
- **Task 1 RED commit:** 2026-04-21T16:05:21+01:00 (`2e13423`)
- **Task 1 GREEN commit:** 2026-04-21T16:06:12+01:00 (`4df8ec4`)
- **Task 2 partial + include:** 2026-04-21T17:20:10+01:00 (`17d3bae`)
- **Task 3 human-verify approved:** 2026-04-21 (user walked all 8 steps and responded "approved")
- **Tasks:** 3 (1 auto TDD + 1 auto partial + 1 human-verify checkpoint)
- **Files created:** 2 (partial + test)
- **Files modified:** 2 (controller + show.blade.php)
- **Commits:** 3 (RED + GREEN + partial; Task 3 is a checkpoint with no code commit)

## Accomplishments

- **New Blade partial `resources/views/projects/_actual-hours-widget.blade.php`** (140 lines total, including `@push('styles')` block).
  - Empty state: "No time tracked yet." + "Engineers clock in from the mobile field page." hint — when `total_minutes === 0`.
  - Loaded state: `<x-section-card title="Actual Hours">` with a large teal total (`1.875rem`, `font-weight: 700`, brand-teal colour) + a 4-row `<ul class="actual-hours-breakdown">` where each row has a label-and-duration header and a track-plus-fill bar.
  - Bar colours hardcoded in the partial's `$categories` array for resilience against CSS-variable renames.
  - 2px placeholder slivers for 0-minute categories keep the 4-row visual rhythm intact.
  - Pure CSS transitions (`transition: width 300ms ease-out`) — no JS, no Alpine, no chart library.

- **`ProjectController::show()` extended** (+20 lines net):
  - Constructor widened by one service — `private readonly TimeEntryService $timeEntryService`.
  - `use App\Services\TimeEntryService;` added.
  - Computes `$canSeeActualHours = $project->user_id === auth()->id() || auth()->user()?->isAdmin()` per D-16.
  - Calls `$this->timeEntryService->summaryForProject($project)` only when gate passes; otherwise `$actualHours = null`.
  - Both vars always passed via `compact()` so the view's `@if` always sees both keys.

- **`resources/views/projects/show.blade.php` gained a 5-line @include block** between Project Overview card and lifecycle bar:
  ```blade
  {{-- ── Actual Hours widget (Phase 15 D-13) ────────────────── --}}
  @if (! empty($canSeeActualHours) && $actualHours !== null)
      @include('projects._actual-hours-widget')
  @endif
  ```
  Placement honours D-13 ("top summary row alongside progress bar and status pill") without reshuffling the existing right-column grid.

- **6 feature tests in `tests/Feature/Projects/ActualHoursWidgetTest.php`** (144 lines, all green at `17d3bae`):
  - `test_owner_sees_widget_with_totals` — asserts "Actual Hours" + "2h 0m" visible for owner after a 120-min closed entry.
  - `test_admin_sees_widget` — admin user (role='admin') sees widget on someone else's project.
  - `test_non_owner_non_admin_does_not_see_widget` — dual-path: if 200, assert absent; if 403, accept (respects existing phase-12/13 ownership ring).
  - `test_widget_shows_empty_state_when_no_entries` — empty project renders "No time tracked yet".
  - `test_widget_breaks_down_by_category` — 120+60+30 min across Installation/Commissioning/Testing → "Installation", "Commissioning", "Testing", "Other", "3h 30m" all visible.
  - `test_widget_excludes_open_entries` — an open (clocked_out_at=null) entry doesn't count → empty state still shows.

## Visibility Gate (Defense in Depth)

Two-ring gate on an information-disclosure surface. Per T-15-05-01 in the plan's threat register:

| Ring | Layer | Code | What it does |
|------|-------|------|--------------|
| Outer | Controller | `$canSeeActualHours = $project->user_id === auth()->id() || auth()->user()?->isAdmin()` | Computes the gate; sets `$actualHours = null` when false so no summary query runs and no data reaches the view. |
| Inner | show.blade.php | `@if (! empty($canSeeActualHours) && $actualHours !== null)` | Double-check at include time; even if a future refactor accidentally passes a truthy `$canSeeActualHours` or a stale `$actualHours`, this ring catches it. |
| Trust | Partial | No re-check | Partial trusts the include-guard; re-checking would create a third place to keep in sync without added safety. |

No per-engineer column in the summary result set (declined by CONTEXT.md §Deferred for privacy) — widget exposes aggregate-per-category only, never per-user.

## Widget Rendering Logic

### Data shape (from Plan 15-02 `TimeEntryService::summaryForProject`)

```php
[
    'total_minutes' => int,
    'per_category'  => [
        'installation'  => int,  // minutes
        'commissioning' => int,
        'testing'       => int,
        'other'         => int,
    ],
]
```

All 4 per-category keys always present (zero if no entries). Only CLOSED entries (`clocked_out_at IS NOT NULL`) contribute.

### Bar width formula

```php
$pct = $total > 0 ? max(2, (int) round($mins / $total * 100)) : 2;
```

Longest bar at 100% (the category with the most minutes). Minimum 2% sliver for any category > 0 min that would otherwise round to 0 or 1 (keeps the bar *visible* even for sub-1%-of-total categories). Zero-minute categories render the sliver too, preserving visual rhythm.

### Duration format

```php
$fmt = static fn (int $m): string => intdiv($m, 60) . 'h ' . ($m % 60) . 'm';
```

Renders as `0h 0m`, `2h 15m`, `12h 3m`, etc. Applied to the total and to every per-category row.

### Colour palette (D-15)

| Category | Hex | Role |
|----------|-----|------|
| Installation | `#178A95` | brand-teal (strongest, typically largest category) |
| Commissioning | `#21A8B5` | teal-hover |
| Testing | `#4FB8C2` | teal-mid |
| Other | `#9CA3AF` | Tailwind gray-400 (intentional — "Other" shouldn't pull visual weight) |

Hex values hardcoded in the partial's `$categories` array, not CSS variables, so the widget survives future CSS-var renames.

## Threat Mitigation Verification

| Threat | Disposition | Status |
|--------|-------------|--------|
| T-15-05-01 (non-owner/non-admin sees aggregate hours) | mitigate | Controller gate sets $canSeeActualHours + $actualHours=null when gate fails; view has @if before @include. Tests `test_non_owner_non_admin_does_not_see_widget` and `test_admin_sees_widget` both green. Defense in depth survives future partial-level refactors. |
| T-15-05-02 (per-engineer hours exposed) | mitigate | `summaryForProject()` result set has no per-user column. Per-engineer roll-up explicitly declined in CONTEXT.md §Deferred. Widget renders per-category only. |
| T-15-05-03 (attacker tampers with $actualHours via request) | accept | $actualHours is computed server-side from DB queries; never read from Request::. No attack surface. |
| T-15-05-04 (browser cache leaks widget HTML to next user) | accept | Widget only rendered when gate passes. Logged-out GET cannot reach the page. Browser cache scoped to the authenticated session — same exposure as every authenticated page. |
| T-15-05-05 (summaryForProject DOS) | accept | Query is WHERE project_id=? aggregating a bounded set of rows (dozens per project at most). Indexed on (project_id, clocked_in_at) per Plan 15-01. No cache layer needed in v1.2. |
| T-15-05-06 (widget visibility reveals admin role) | accept | Binary yes/no signal; same information already leaked by existing admin menu and role-gated admin pages. No net-new exposure. |

## Task Commits

1. **Task 1 RED:** `2e13423` — test(15-05): add failing tests for actual hours widget — 6 tests in 144 lines, 5/6 failing at this commit (per TDD protocol).
2. **Task 1 GREEN:** `4df8ec4` — feat(15-05): pass actualHours + canSeeActualHours to project show view — ProjectController constructor gains TimeEntryService; show() computes gate + summary; compact() grows by 2 keys.
3. **Task 2:** `17d3bae` — feat(15-05): add actual hours widget to project show page — 140-line partial + 5-line @include in show.blade.php; 6/6 tests now green; 68 tests / 140 assertions green across Project / TimeEntry / ProjectAutoAdvance suites with no regressions.
4. **Task 3 (checkpoint):** no commit — user walked through all 8 verification steps (owner sees, admin sees non-owner hidden, empty state, 375px mobile, bar widths) and responded "approved".

## Files Created / Modified

**Created (2):**
- `resources/views/projects/_actual-hours-widget.blade.php` — 140 lines (~30 lines Blade template, ~10 lines PHP helper block, ~55 lines @push('styles') CSS, comments + whitespace).
- `tests/Feature/Projects/ActualHoursWidgetTest.php` — 144 lines, 6 feature tests with `RefreshDatabase`.

**Modified (2):**
- `app/Http/Controllers/ProjectController.php` — +21 / -1. Added TimeEntryService import + constructor param; show() computes `$canSeeActualHours` + `$actualHours`; compact() expanded by 2 keys. No other actions touched.
- `resources/views/projects/show.blade.php` — +5 / -0. Single @if-guarded @include between Project Overview card and lifecycle bar.

## Decisions Made

See frontmatter `key-decisions` for the full list. Highlights:

- **Dual-ring visibility gate** — controller + view both check; partial trusts. Survives refactors that accidentally remove one ring.
- **Widget placement between Project Overview and lifecycle bar** — honours D-13's "top summary row" intent without reshuffling the existing right-column grid.
- **Pure CSS bars, no library** — D-15 mandate. ul > li > track > fill, inline width%, transition: width for the GPU-accelerated reveal.
- **2px placeholder for 0-min categories** — preserves 4-row visual rhythm.
- **Hex hardcoded colours in `$categories`** — survives CSS-variable drift.
- **Constructor promotion for new service** — no middleware juggling needed (existing constructor had none).
- **Null-safe `auth()->user()?->isAdmin()`** — belt-and-braces against unauthenticated edge cases.

## Deviations from Plan

None material. The plan executed exactly as written:

- Partial markup matches the plan's code block verbatim (including the 2px sliver formula, the inline `$fmt` closure, and the hardcoded hex colours).
- Controller extension (import, constructor param, show() additions, compact() expansion) matches the plan's stepwise instructions 1-5.
- show.blade.php insertion location (between the Overview card close-tag and the lifecycle comment) matches the plan's guidance.
- Test file matches the plan's 6-test scaffold verbatim.
- Threat model T-15-05-01 mitigation (controller + view dual gate) implemented as specified.

Minor implementation refinements (not deviations):

- CSS variable usage includes **fallback hex values** (e.g. `color: var(--teal, #178A95)` rather than bare `color: var(--teal)`). Rationale: a project show page viewed before the main `resources/css/app.css` is loaded (unlikely but possible during dev) still renders with correct colours. Pure defensive CSS; no behaviour change.
- `auth()->user()?->isAdmin()` uses null-safe operator rather than bare `auth()->user()->isAdmin()`. Rationale: if an unauthenticated user somehow reaches show() (shouldn't happen under `auth` middleware, but cheap to defend against), the method chain returns null rather than fatal-erroring. The `|| null` evaluates to falsy → gate fails → no widget. Strictly safer.

Neither refinement changes behaviour for the intended call paths; both improve resilience with zero runtime cost.

## Authentication Gates

None. All routing sits behind the existing `auth` middleware; the widget's internal gate is an ownership check, not an auth check. No new env vars, no new services to configure.

## Issues Encountered

None. The plan's prose correctly identified every line-level decision (constructor shape, compact() expansion, widget placement, colour hex, bar formula) such that the execution was mechanical.

The one "easy-to-miss" consideration — that the `<x-section-card>` component existed (vs `<x-summary-card>` which is used for the Project Overview) — was resolved by checking `resources/views/components/` and confirming both exist. The partial correctly uses `<x-section-card>` per the plan.

## Known Stubs

None. Every rendered path is wired to real data:

- `$actualHours` is computed from `TimeEntryService::summaryForProject()`, which queries real `time_entries` rows.
- Bar widths are computed from real per-category minute totals.
- Duration format is computed from real integer minute counts.
- Empty state triggers only on a real zero-total.
- No hardcoded mock data, no placeholder text, no "coming soon" labels.

## Self-Check: PASSED

**Files created (2):**
- `resources/views/projects/_actual-hours-widget.blade.php` — FOUND (140 lines)
- `tests/Feature/Projects/ActualHoursWidgetTest.php` — FOUND (144 lines)

**Files modified (2):**
- `app/Http/Controllers/ProjectController.php` — MODIFIED (constructor has `TimeEntryService $timeEntryService`; show() has `$canSeeActualHours` + `$actualHours` computation + compact() expansion)
- `resources/views/projects/show.blade.php` — MODIFIED (5-line @if-guarded @include between Overview card and lifecycle bar)

**Commits exist:**
- `2e13423` — FOUND (Task 1 RED)
- `4df8ec4` — FOUND (Task 1 GREEN)
- `17d3bae` — FOUND (Task 2 partial + include)

**Human-verify checkpoint (Task 3):**
- Approved by user after walking through all 8 verification steps (owner sees, admin sees, non-owner/non-admin hidden, empty state, 375px mobile, bar widths, D-13 placement, category breakdown).

**Tests green:** 6/6 `ActualHoursWidgetTest` (per `17d3bae` commit message: "68 tests, 140 assertions green" across Project / TimeEntry / ProjectAutoAdvance with no regressions).

## Next Phase Readiness

Phase 15 is complete at the per-plan level (5/5 plans shipped):
- 15-01: schema + models
- 15-02: TimeEntryService extensions + controller endpoints + routes
- 15-03: CloseStaleSessionsCommand + hourly scheduler
- 15-04: mobile field view extensions (category sheet, heartbeat, note sheet)
- 15-05: dashboard widget (this plan)

**Ready for:**
- Phase-level code review + regression gate (orchestrator responsibility)
- Phase verification walk-through (all 5 ROADMAP success criteria)
- Phase 16 (Commissioning Checklist & Sign-off) — depends on Phase 14's mobile UX pattern, not Phase 15's time-tracking data, so Phase 16 can begin any time.

**No blockers, no open questions, no carry-over items.**

---
*Phase: 15-time-tracking*
*Plan: 05*
*Completed: 2026-04-21*
