# Phase 08: Enterprise Dashboard — Research

**Researched:** 2026-04-14
**Domain:** Laravel controller refactor + service layer health derivation + Alpine.js client-side filtering
**Confidence:** HIGH

---

## Summary

Phase 08 replaces the existing `/dashboard` route closure with a `DashboardController@index` action backed by a new `ProjectHealthService`. The service evaluates each non-archived project against a priority-ordered rule set (red → amber → green) using only data that is already on the model or its eagerly-loaded relations — no new columns or migrations are required.

The existing codebase already supplies everything needed: `Project::STATUS_*` constants, `STATUS_LABELS`, `STATUS_COLOURS`, all six milestone timestamp columns, `ramsDocuments()` / `siteSurveys()` / `activeInstallProgramme()` relationships, and `InstallTask::STATUS_COMPLETE` for completion counting. Alpine.js is already bundled and used in `schedule.blade.php` and `projects/show.blade.php` for show/hide patterns, confirming the filter tab approach is consistent with the codebase.

The dashboard view already uses the `<x-dashboard.*>` component library (stat-card, status-badge, table-wrapper, page-header, empty-state). New health badge and filter-chip components fit the same convention.

**Primary recommendation:** Build `ProjectHealthService` as a plain PHP class (no Eloquent dependency), have `DashboardController` do one eager-load query for all non-archived projects with all required relations, pass a `$projects` collection and a `$healthMap` array (keyed by project ID) to the view. Keep all filtering client-side via Alpine.js `x-show`.

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| DASH-01a | `DashboardController@index` replaces route closure | Route closure identified at `routes/web.php:69-79`; no `DashboardController` exists yet — must be created |
| DASH-01b | All non-archived projects, not capped at 6; eager-loads owner + ramsDocuments + siteSurveys + installProgrammes | `Project::scopeNotArchived()` exists; `Project::with([...])` relationships all confirmed present |
| DASH-01c | `ProjectHealthService::assess(Project): ProjectHealth` value object | No `ProjectHealthService` exists yet; `ProjectHealth` DTO must be created |
| DASH-01d | Health derivation rules (red/amber/green, first-match-wins) | All model status constants verified; milestone timestamp columns verified |
| DASH-01e | Overdue indicator from existing milestone timestamp columns (>14 days) | `milestoneColumn()` map in `ProjectService` confirms all six timestamp columns; columns already cast to datetime in `Project::$casts` |
| DASH-01f | Status summary strip: counts per lifecycle stage, using `STATUS_LABELS` and `STATUS_COLOURS` | Both constants verified on `Project` model |
| DASH-01g | Alpine.js `x-show` filter; URL hash updated for bookmarkability | Alpine.js already bundled; `x-data`/`x-show` pattern verified in `schedule.blade.php` |
| DASH-01h | Install programme task-completion widget (complete/total * 100); gracefully absent when no active programme | `InstallProgramme::STATUS_ACTIVE`, `InstallTask::STATUS_COMPLETE`, `Project::activeInstallProgramme()` all verified |
</phase_requirements>

---

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| Laravel | ^12.0 | MVC framework, controller, service injection | Project standard [VERIFIED: composer.json] |
| Alpine.js | bundled via Vite | Client-side filtering/reactivity | Already used in schedule.blade.php, projects/show.blade.php [VERIFIED: codebase grep] |
| PHPUnit | ^11.5.3 | Unit tests for `ProjectHealthService` | Project standard [VERIFIED: composer.json] |
| Mockery | ^1.6 | Test doubles for service isolation | Used in `InstallTaskGeneratorServiceTest` [VERIFIED: codebase] |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| Carbon | (bundled with Laravel) | Date comparison for overdue calculation | `now()->diffInDays($stageStart)` |
| `<x-dashboard.*>` components | existing | Consistent UI primitives | All dashboard UI should use existing components |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Alpine.js x-show filter | Livewire / page reload | Alpine is already present and sufficient; Livewire is not in the stack |
| Flat `$healthMap` array | Computed property on Project model | Service stays isolated and unit-testable without Eloquent coupling |

**Installation:** No new packages required. All dependencies are already present.

---

## Architecture Patterns

### Recommended Project Structure
```
app/
├── Http/Controllers/
│   └── DashboardController.php       # thin — delegates to service, returns view
├── Services/
│   └── ProjectHealthService.php      # assess(Project): ProjectHealth
└── DTO/
    └── ProjectHealth.php             # value object: status, reason, overdue (bool)

resources/views/
├── dashboard.blade.php               # rewritten to use health grid
└── components/dashboard/
    ├── health-badge.blade.php        # new: green/amber/red badge
    └── filter-chip.blade.php        # new (optional): status strip chip
```

### Pattern 1: Controller — thin, single query

`DashboardController::index()` mirrors the pattern in `ProjectController::index()`.

```php
// Source: ProjectController.php pattern [VERIFIED: codebase]
public function index(): View
{
    $projects = Project::with(['owner', 'ramsDocuments', 'siteSurveys', 'activeInstallProgramme.tasks'])
        ->whereNotIn('status', [Project::STATUS_ARCHIVED])
        ->orderByDesc('updated_at')
        ->get();

    $healthService = app(ProjectHealthService::class);
    $healthMap = $projects->keyBy('id')->map(fn ($p) => $healthService->assess($p));

    $statusCounts = $projects->groupBy('status')->map->count();

    return view('dashboard', compact('projects', 'healthMap', 'statusCounts'));
}
```

### Pattern 2: ProjectHealth value object (DTO)

Project already uses model-level constants and helper methods. The `ProjectHealth` DTO follows the same data-holding pattern as the request/response objects in the codebase.

```php
// New file: app/DTO/ProjectHealth.php [ASSUMED structure, consistent with codebase style]
readonly class ProjectHealth
{
    public function __construct(
        public string $status,   // 'green' | 'amber' | 'red'
        public string $reason,   // human-readable explanation
        public bool   $overdue,  // true when stage elapsed > 14 days
    ) {}
}
```

### Pattern 3: Health derivation (priority order — first match wins)

```php
// Source: REQUIREMENTS.md DASH-01d [VERIFIED: REQUIREMENTS.md]
// Priority: RED first, then AMBER, then GREEN

// RED triggers:
// 1. Any ramsDocument with status = 'failed'
// 2. Project in 'engineering' with no RAMS at approved or beyond
// 3. Project in 'survey_pending' with no submitted SiteSurvey AND survey_started_at > 14 days ago

// AMBER triggers:
// 1. Project has been in current stage > 7 days
// 2. Any RAMS document is 'awaiting_review'
// 3. Project in 'engineering' with RAMS in 'uploaded' or 'awaiting_review'

// GREEN: default when no red/amber condition matched
```

The check for "RAMS at approved or beyond" maps to these statuses (from `RamsDocument` constants):
- `STATUS_APPROVED` (`'approved'`)
- `STATUS_APPROVED_FOR_GENERATION` (`'approved_for_generation'`) — legacy alias
- `STATUS_GENERATING` (`'generating'`)
- `STATUS_COMPLETED` (`'completed'`)

"RAMS in uploaded/awaiting_review" (blocked in pipeline) maps to:
- `STATUS_UPLOADED` (`'uploaded'`)
- `STATUS_AWAITING_REVIEW` (`'awaiting_review'`)

### Pattern 4: Milestone timestamp → stage start mapping

`ProjectService::milestoneColumn()` already defines this map. `ProjectHealthService` should duplicate or reference the same logic:

```php
// Source: app/Core/Modules/Projects/ProjectService.php:255-269 [VERIFIED: codebase]
$stageStartColumn = match ($project->status) {
    Project::STATUS_SURVEY_PENDING  => 'survey_started_at',
    Project::STATUS_ENGINEERING     => 'engineering_started_at',
    Project::STATUS_INSTALLING      => 'installation_started_at',
    Project::STATUS_COMMISSIONING   => 'commissioning_started_at',
    Project::STATUS_HANDOVER        => 'handover_started_at',
    Project::STATUS_COMPLETED       => 'completed_at',
    default                         => null,
};
```

`quote_imported` has no start timestamp — a project in `quote_imported` stage cannot be "overdue" by the timestamp rule (null guard needed).

### Pattern 5: Alpine.js filter with URL hash

The existing `schedule.blade.php` Alpine pattern uses `x-data` at a wrapper div level. The filter pattern for the dashboard follows the same approach:

```html
{{-- Source: schedule.blade.php pattern [VERIFIED: codebase] --}}
<div x-data="{ activeFilter: '' }" x-init="
    const hash = window.location.hash.replace('#','');
    if (hash) activeFilter = hash;
    $watch('activeFilter', v => { window.location.hash = v; });
">
    {{-- filter chips --}}
    @foreach(Project::STATUS_LABELS as $key => $label)
    <button @click="activeFilter = activeFilter === '{{ $key }}' ? '' : '{{ $key }}'"
            :class="activeFilter === '{{ $key }}' ? 'chip-active' : 'chip'"
    >{{ $label }} ({{ $statusCounts[$key] ?? 0 }})</button>
    @endforeach

    {{-- project rows --}}
    @foreach($projects as $project)
    <div x-show="activeFilter === '' || activeFilter === '{{ $project->status }}'">
        {{-- project card --}}
    </div>
    @endforeach
</div>
```

### Pattern 6: Install programme task completion widget

The `activeInstallProgramme` relationship is already defined on `Project` [VERIFIED: `app/Models/Project.php:183-188`]. It returns a single `InstallProgramme` with `status = 'active'`.

Task completion counting:

```php
// In view or passed from controller [ASSUMED — consistent with model data]
$programme = $project->activeInstallProgramme;
if ($programme) {
    $total    = $programme->tasks->count();
    $complete = $programme->tasks->where('status', InstallTask::STATUS_COMPLETE)->count();
    $pct      = $total > 0 ? round($complete / $total * 100) : 0;
}
```

To avoid N+1, eager-load `activeInstallProgramme.tasks` in the controller query.

### Anti-Patterns to Avoid

- **Lazy-loading inside view loop:** The `$project->ramsDocuments` call inside a foreach will N+1 if not eager-loaded. Always use `with([...])` in the controller.
- **Putting health logic in the Blade view:** Health derivation must live in `ProjectHealthService` to be unit-testable (DASH-01c success criterion).
- **Re-querying inside `assess()`:** `ProjectHealthService::assess()` must use the already-loaded relations — never call `$project->ramsDocuments()->get()` inside the service method.
- **`STATUS_COMPLETED` documents counting as "no approved RAMS":** The red rule for engineering checks for no RAMS "at approved or beyond". `STATUS_COMPLETED` is beyond approved — it must be included in the "approved or beyond" set.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Status colour mapping | Custom colour array | `Project::STATUS_COLOURS` | Already defined on the model [VERIFIED] |
| Status label display | Custom label map | `Project::STATUS_LABELS` | Already defined on the model [VERIFIED] |
| Date diff for overdue | Manual timestamp subtraction | `Carbon::now()->diffInDays($project->survey_started_at)` | Carbon is already the datetime library; columns are cast |
| Client-side filter animation | Custom CSS transitions | Alpine.js `x-show` | Already bundled; existing views use it |
| Health badge styling | New CSS system | Extend existing `dash-status-badge` component pattern | `status-badge.blade.php` already defines the dot + pill pattern |

---

## Common Pitfalls

### Pitfall 1: N+1 on health derivation
**What goes wrong:** Accessing `$project->ramsDocuments` inside the health grid loop triggers one query per project.
**Why it happens:** Relations are lazy-loaded by default if not in `with([...])`.
**How to avoid:** Controller query must include `with(['owner', 'ramsDocuments', 'siteSurveys', 'activeInstallProgramme.tasks'])`.
**Warning signs:** Laravel Debugbar showing > 10 queries on the dashboard page.

### Pitfall 2: Soft-deleted RAMS documents appearing in health check
**What goes wrong:** A project shows "no approved RAMS" even though one exists but was soft-deleted.
**Why it happens:** `ramsDocuments()` relationship includes soft-deleted records unless scoped.
**How to avoid:** In `ProjectHealthService`, filter `$project->ramsDocuments` to exclude soft-deleted: `$project->ramsDocuments->whereNull('deleted_at')` (in-memory filter on already-loaded collection). Or add `->whereNull('deleted_at')` as a constraint on the relationship.
**Warning signs:** A project with a completed RAMS shows red.

### Pitfall 3: `quote_imported` stage has no milestone timestamp
**What goes wrong:** `ProjectHealthService` reads `$project->survey_started_at` expecting a datetime but gets `null` for `quote_imported` projects.
**Why it happens:** The `quote_imported` status maps to `null` in the `milestoneColumn()` map — there is no `quote_imported_at` column.
**How to avoid:** Guard: `if ($stageStart === null) return false;` before the `diffInDays` comparison.
**Warning signs:** `Carbon::diffInDays(null)` throws a TypeError.

### Pitfall 4: Stale `activeInstallProgramme` for projects not in installing/commissioning
**What goes wrong:** A project that was in `installing` had a programme activated, then was moved back to `engineering`. The programme record still has `status = active`.
**Why it happens:** Programme status is not automatically updated when the project transitions backward.
**How to avoid:** In the DASH-01h widget, guard on `$project->status` IN ['installing', 'commissioning'] before showing the widget — even if an active programme exists.
**Warning signs:** Widget shown for projects in engineering stage.

### Pitfall 5: Route name collision or middleware gap
**What goes wrong:** The new `DashboardController` route is registered outside the `auth` middleware group.
**Why it happens:** Copy-paste error when moving from the closure (which was inside the `auth` group).
**How to avoid:** Register `Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');` inside the existing `Route::middleware('auth')->group(...)` block at line 66 of `web.php`.
**Warning signs:** `/dashboard` is accessible without login.

---

## Code Examples

### Eager-load query (controller)
```php
// Source: DASH-01b requirement + ProjectController pattern [VERIFIED: codebase]
$projects = Project::with([
    'owner',
    'ramsDocuments',
    'siteSurveys',
    'activeInstallProgramme.tasks',
])
->whereNotIn('status', [Project::STATUS_ARCHIVED])
->orderByDesc('updated_at')
->get();
```

### Status summary counts (controller)
```php
// Source: ProjectController::index() statusCounts pattern [VERIFIED: codebase]
$statusCounts = $projects->groupBy('status')->map->count();
// Note: this operates on the already-loaded collection — no second DB query.
```

### Health derivation skeleton (service)
```php
// Source: DASH-01d requirements [VERIFIED: REQUIREMENTS.md]
public function assess(Project $project): ProjectHealth
{
    $rams      = $project->ramsDocuments->whereNull('deleted_at');
    $surveys   = $project->siteSurveys;
    $stageStart = $this->stageStartTimestamp($project);
    $overdue    = $stageStart && now()->diffInDays($stageStart, false) > 14;

    // ── RED: first-match-wins ─────────────────────────────────────────
    if ($rams->contains('status', RamsDocument::STATUS_FAILED)) {
        return new ProjectHealth('red', 'RAMS document failed', $overdue);
    }
    if ($project->status === Project::STATUS_ENGINEERING
        && ! $rams->whereIn('status', $this->approvedOrBeyond())->count()) {
        return new ProjectHealth('red', 'No approved RAMS in engineering', $overdue);
    }
    if ($project->status === Project::STATUS_SURVEY_PENDING
        && ! $surveys->filter->isSubmitted()->count()
        && $stageStart && now()->diffInDays($stageStart, false) > 14) {
        return new ProjectHealth('red', 'Survey overdue — no submission', true);
    }

    // ── AMBER ─────────────────────────────────────────────────────────
    if ($stageStart && now()->diffInDays($stageStart, false) > 7) {
        return new ProjectHealth('amber', 'Stage duration > 7 days', $overdue);
    }
    if ($rams->contains('status', RamsDocument::STATUS_AWAITING_REVIEW)) {
        return new ProjectHealth('amber', 'RAMS awaiting review', $overdue);
    }
    if ($project->status === Project::STATUS_ENGINEERING
        && $rams->whereIn('status', [
            RamsDocument::STATUS_UPLOADED,
            RamsDocument::STATUS_AWAITING_REVIEW,
        ])->count()) {
        return new ProjectHealth('amber', 'RAMS blocked in pipeline', $overdue);
    }

    return new ProjectHealth('green', 'On track', $overdue);
}

private function approvedOrBeyond(): array
{
    return [
        RamsDocument::STATUS_APPROVED,
        RamsDocument::STATUS_APPROVED_FOR_GENERATION,
        RamsDocument::STATUS_GENERATING,
        RamsDocument::STATUS_COMPLETED,
    ];
}
```

### Alpine.js filter pattern (Blade)
```html
{{-- Source: schedule.blade.php + DASH-01g requirement [VERIFIED: codebase] --}}
<div x-data="{
    filter: '',
    init() {
        const hash = window.location.hash.replace('#','');
        if (hash) this.filter = hash;
        this.$watch('filter', v => { window.location.hash = v || ' '; });
    }
}">
    {{-- Status strip chips --}}
    @foreach(\App\Models\Project::STATUS_LABELS as $key => $label)
        @if($key !== 'archived')
        <button
            @click="filter = (filter === '{{ $key }}') ? '' : '{{ $key }}'"
            :class="filter === '{{ $key }}' ? 'chip chip--active' : 'chip'"
            style="border-color: {{ \App\Models\Project::STATUS_COLOURS[$key] }};">
            {{ $label }}
            <span>({{ $statusCounts[$key] ?? 0 }})</span>
        </button>
        @endif
    @endforeach

    {{-- Project rows --}}
    @foreach($projects as $project)
    <div x-show="filter === '' || filter === '{{ $project->status }}'">
        {{-- render card --}}
    </div>
    @endforeach
</div>
```

---

## Runtime State Inventory

> Omitted — this is a greenfield feature addition, not a rename/refactor/migration phase.

---

## Environment Availability

> Step 2.6: SKIPPED — no external dependencies beyond the existing Laravel stack (PHP, MySQL, Node/Vite). All tools are already confirmed present by the running application.

---

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit ^11.5.3 |
| Config file | `phpunit.xml` |
| Quick run command | `php artisan test tests/Unit/ProjectHealthServiceTest.php` |
| Full suite command | `php artisan test` |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| DASH-01c | `assess()` returns `ProjectHealth` value object | unit | `php artisan test tests/Unit/ProjectHealthServiceTest.php` | ❌ Wave 0 |
| DASH-01d (red 1) | Project with failed RAMS → red | unit | `php artisan test tests/Unit/ProjectHealthServiceTest.php::test_red_when_rams_failed` | ❌ Wave 0 |
| DASH-01d (red 2) | Engineering project with no approved RAMS → red | unit | `php artisan test tests/Unit/ProjectHealthServiceTest.php::test_red_when_engineering_no_approved_rams` | ❌ Wave 0 |
| DASH-01d (amber) | Project in stage > 7 days → amber | unit | `php artisan test tests/Unit/ProjectHealthServiceTest.php::test_amber_when_stage_overdue_7_days` | ❌ Wave 0 |
| DASH-01d (green) | Normal project → green | unit | `php artisan test tests/Unit/ProjectHealthServiceTest.php::test_green_when_all_clear` | ❌ Wave 0 |
| DASH-01a | `/dashboard` returns 200 via `DashboardController` | feature | `php artisan test tests/Feature/DashboardControllerTest.php` | ❌ Wave 0 |

### Sampling Rate
- **Per task commit:** `php artisan test tests/Unit/ProjectHealthServiceTest.php`
- **Per wave merge:** `php artisan test`
- **Phase gate:** Full suite green before `/gsd-verify-work`

### Wave 0 Gaps
- [ ] `tests/Unit/ProjectHealthServiceTest.php` — covers DASH-01c, DASH-01d (all branches)
- [ ] `tests/Feature/DashboardControllerTest.php` — covers DASH-01a (route + response)
- [ ] `app/DTO/ProjectHealth.php` — value object (must exist before test can import it)
- [ ] `app/Services/ProjectHealthService.php` — service class (must exist before test)

---

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes | Existing `auth` middleware — route must remain inside the middleware group |
| V3 Session Management | no | No session changes |
| V4 Access Control | no | Dashboard is visible to all authenticated users (no role restriction) |
| V5 Input Validation | no | No user input — read-only dashboard |
| V6 Cryptography | no | No crypto operations |

### Known Threat Patterns

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Unauthenticated dashboard access | Spoofing | `auth` middleware applied to the `DashboardController` route |
| Data leakage via health reasons | Information disclosure | `reason` string is operational (not a secret); safe to display to any authenticated user |

---

## Open Questions

1. **Should `quote_imported` projects appear in the health grid?**
   - What we know: DASH-01b says "all non-archived" — `quote_imported` is not archived.
   - What's unclear: A `quote_imported` project has no started-at timestamp, so the overdue rule cannot fire. Health will always be green for such projects. This is probably correct behaviour.
   - Recommendation: Include them; health will always be green (no blocking condition can match).

2. **Does `activeInstallProgramme.tasks` need `withCount` optimisation?**
   - What we know: `Project::activeInstallProgramme` is a `hasOne` — one programme per project max. Tasks per programme are typically < 100 items for an AV project.
   - What's unclear: Real-world row count at scale.
   - Recommendation: Use eager-load for now (`with(['activeInstallProgramme.tasks'])`). If profiling shows it's slow, add `withCount(['tasks', 'tasks as complete_tasks_count' => fn($q) => $q->where('status', 'complete')])` instead.

3. **Should the stat cards (Active Projects, RAMS Generated, etc.) remain on the new dashboard?**
   - What we know: They are present in the current `dashboard.blade.php` and passed from the route closure.
   - What's unclear: Requirements don't explicitly keep or remove them.
   - Recommendation: Retain them; the `DashboardController` should pass these stat values as before, derived from the already-loaded `$projects` collection where possible (to avoid extra queries).

---

## Sources

### Primary (HIGH confidence)
- `app/Models/Project.php` — status constants, STATUS_LABELS, STATUS_COLOURS, milestone timestamp columns, all relationships (verified in session)
- `app/Models/RamsDocument.php` — all pipeline status constants verified in session
- `app/Models/InstallProgramme.php` + `app/Models/InstallTask.php` — status constants, `STATUS_ACTIVE`, `STATUS_COMPLETE` verified in session
- `app/Core/Modules/Projects/ProjectService.php:255-269` — milestone column map verified in session
- `routes/web.php:69-79` — current dashboard route closure verified in session
- `resources/views/install-programmes/schedule.blade.php` — Alpine.js `x-data`/`x-show` pattern verified in session
- `.planning/REQUIREMENTS.md` — DASH-01 through DASH-01h verified in session

### Secondary (MEDIUM confidence)
- `resources/views/components/dashboard/status-badge.blade.php` — existing health badge component pattern; new health badge should follow same pill + dot structure
- `tests/Unit/InstallTaskGeneratorServiceTest.php` — confirms `Mockery` is used for service unit tests in this codebase; `ProjectHealthService` tests should follow the same structure

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — all libraries already present and verified in codebase
- Architecture: HIGH — patterns derived from verified existing code (ProjectController, schedule.blade.php, ProjectService)
- Pitfalls: HIGH — derived from direct inspection of model definitions and relationship chains
- Health derivation rules: HIGH — copied verbatim from REQUIREMENTS.md DASH-01d

**Research date:** 2026-04-14
**Valid until:** 2026-05-14 (stable codebase, no external API dependencies)
