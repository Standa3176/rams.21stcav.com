---
phase: 08-enterprise-dashboard
reviewed: 2026-04-19T00:00:00Z
depth: standard
files_reviewed: 8
files_reviewed_list:
  - app/DTO/ProjectHealth.php
  - app/Services/ProjectHealthService.php
  - app/Http/Controllers/DashboardController.php
  - routes/web.php
  - tests/Unit/ProjectHealthServiceTest.php
  - tests/Feature/DashboardControllerTest.php
  - resources/views/components/dashboard/health-badge.blade.php
  - resources/views/dashboard.blade.php
findings:
  critical: 0
  warning: 3
  info: 6
  total: 9
status: issues_found
---

# Phase 08: Code Review Report

**Reviewed:** 2026-04-19
**Depth:** standard
**Files Reviewed:** 8
**Status:** issues_found

## Summary

Phase 08 delivers a clean, well-tested enterprise dashboard. The `ProjectHealth` DTO + `ProjectHealthService` split is idiomatic, the controller is appropriately thin, eager-loading is in place, and both test suites cover the branches called out in the plan. Full suite reports 583 passing.

No Critical findings. All findings are Warnings (correctness / maintainability) or Info (style / nice-to-haves):

- **Warnings** concentrate on (1) a fragile reliance on the current sign semantics of `Carbon::diffInDays(..., false)`, (2) a duplicated `stageStart`/`diffInDays` guard in the "survey overdue" branch, and (3) an unscoped `Project::count()` on the stat card that bypasses soft deletes only by luck.
- **Info** items cover duplicate work between `health-badge` and `status-badge`, a small N+1 risk on the install-programme tasks, an Alpine `filter` init quirk that writes a single-space hash, and some minor dead/duplicated code.

Security: no XSS vectors reach CSS or HTML attributes — `$colour` is sourced from a hardcoded map (with hex fallback), and user-controlled strings (`$health->reason`, `$project->name`, `$project->client_name`) are routed through `{{ }}` escaping. Auth middleware is wired correctly and covered by a test.

## Warnings

### WR-01: `diffInDays(..., false)` sign direction is non-obvious and will silently break on a Carbon major bump

**File:** `app/Services/ProjectHealthService.php:41-42`, `:58`, `:64`

**Issue:** The overdue / amber thresholds are expressed as:

```php
$overdue = $stageStart !== null
    && Carbon::now()->diffInDays($stageStart, false) < -14;
```

With `nesbot/carbon` 3.11.3 (confirmed in `composer.lock`), `$now->diffInDays($past, false)` returns a **negative** value, so `< -14` means "more than 14 days ago" — the tests confirm this holds today. However:

1. This is exactly the sign flip that broke between Carbon 2 and Carbon 3. The project's `composer.json` pins `"nesbot/carbon": "^2.71.0 || ^3.0.0"` transitively via Laravel, so a future composer update that drops Carbon 3 would invert the comparison and every stage would instantly look "overdue" (or none would). There is no test that would catch that regression — the unit tests all run under Carbon 3 too.
2. A maintainer skimming the code has to mentally derive "negative means past" from `diffInDays(target, false)`; the readable form is `$stageStart->diffInDays(Carbon::now())` or simply `$stageStart->lt(Carbon::now()->subDays(14))`.

**Fix:** Flip the expression so the semantics are self-documenting and version-stable:

```php
// overdue when stage started more than 14 days ago
$overdue = $stageStart !== null
    && $stageStart->lt(Carbon::now()->subDays(14));
// ...
if ($stageStart !== null && $stageStart->lt(Carbon::now()->subDays(7))) {
    return new ProjectHealth('amber', 'Stage duration > 7 days', $overdue);
}
```

This also removes the `, false` flag entirely and reads left-to-right. Apply the same change at line 58.

### WR-02: Duplicate age check in "Survey overdue" branch — can be expressed in terms of `$overdue`

**File:** `app/Services/ProjectHealthService.php:55-60`

**Issue:** The survey-overdue RED branch recomputes the `-14 days` check that `$overdue` already captures:

```php
if ($project->status === Project::STATUS_SURVEY_PENDING
    && $surveys->filter(fn (SiteSurvey $s) => $s->isSubmitted())->isEmpty()
    && $stageStart !== null
    && Carbon::now()->diffInDays($stageStart, false) < -14) {
    return new ProjectHealth('Survey overdue — no submission', true);
}
```

Two issues:

1. The `$stageStart !== null` + `diffInDays(...) < -14` pair is literally the definition of `$overdue` computed two lines earlier (line 41-42). The correct relationship is: when status is `survey_pending`, `$stageStart` is `survey_started_at`, so `$overdue` already answers "has the stage been active > 14 days".
2. Because the logic is duplicated, if WR-01 is applied to line 42 but not here, they could drift.

**Fix:** Reuse `$overdue` directly:

```php
if ($project->status === Project::STATUS_SURVEY_PENDING
    && $overdue
    && $surveys->filter(fn (SiteSurvey $s) => $s->isSubmitted())->isEmpty()) {
    return new ProjectHealth('red', 'Survey overdue — no submission', true);
}
```

(The existing test `test_red_when_survey_overdue_no_submission` still passes because `$overdue` is `true` for a 20-day-old `survey_started_at`.)

### WR-03: `statAllProjects` includes archived + soft-deleted projects inconsistently

**File:** `app/Http/Controllers/DashboardController.php:70`

**Issue:**

```php
$statAllProjects = Project::count();
```

`Project` uses `SoftDeletes` (confirmed in `app/Models/Project.php:13`), so this already excludes soft-deleted rows. But the blade template does not render this value — the stat card at `resources/views/dashboard.blade.php:26-36` shows `$statActiveProjects` instead, and `$statAllProjects` appears nowhere in the view. That makes it dead code today, but the bigger risk is that its semantics are ambiguous: "all projects" mixes archived + non-archived, whereas the filter strip and stat grid conceptually exclude archived projects.

If a future view uses `$statAllProjects`, a reader would reasonably expect it to match "the projects I could navigate to" (non-archived), not "the full row count". Two projects with the same status, one archived, would disagree.

**Fix:** Either remove the unused variable, or scope it to make intent explicit:

```php
// Remove if truly unused:
// $statAllProjects = Project::count();   // unused by the current view
// ...and drop from compact().

// Or scope it:
$statAllProjects = Project::whereNotIn('status', [Project::STATUS_ARCHIVED])->count();
```

Given `$projects` is already the authoritative active-projects collection, `$projects->count()` (already assigned to `$statActiveProjects`) is almost certainly what any future caller wants.

## Info

### IN-01: `health-badge` relies on a CSS class whose `<style>` block lives only in `status-badge`

**File:** `resources/views/components/dashboard/health-badge.blade.php:7-8`

**Issue:** The component docblock says "Reuses the `.dash-status-badge` and `.dash-status-badge__dot` classes defined in components/dashboard/status-badge.blade.php — we only override the colour inline so we never duplicate the style block." That works today because every dashboard row renders a `status-badge` before a `health-badge`, so the `<style>` block is present on the page. But:

- A future view that renders only `<x-dashboard.health-badge>` (e.g. a project-detail sidebar) will produce unstyled pills with no shape, padding, or font-weight.
- Inline `<style>` inside a component scoped to a sibling component is brittle coupling that won't show up in any test.

**Fix:** Either (a) move the shared `.dash-status-badge*` CSS into `resources/css/app.css` so both components can rely on it unconditionally, or (b) duplicate the style block into `health-badge` as well (Blade will emit it twice but browsers tolerate that). Option (a) is cleaner.

### IN-02: Install-programme tasks pagination/sizing — N+1 risk on large installs

**File:** `app/Http/Controllers/DashboardController.php:48-53`, `resources/views/dashboard.blade.php:135-142`

**Issue:** `->with(['activeInstallProgramme.tasks'])` eager-loads **all** tasks for every project's active programme, then the blade counts them in PHP. For a dashboard with 50 projects averaging ~30 tasks each, that's 1,500 rows hydrated as full Eloquent models just to compute two counts (`total`, `done`).

This is not a correctness bug — it's within scope to defer. But if install programmes grow in size (tasks per project), the dashboard page weight will grow linearly and you'll end up rebuilding this as a derived query. Worth a follow-up ticket.

**Fix (follow-up):** Consider either:

1. Add `withCount(['activeInstallProgramme.tasks as total_tasks', 'activeInstallProgramme.tasks as done_tasks' => fn($q) => $q->where('status', InstallTask::STATUS_COMPLETE)])` — two cheap aggregates, no hydration.
2. Or denormalise a `completion_pct` column onto `install_programmes` that's updated when a task transitions.

### IN-03: Alpine init writes `#` (hash with a single space) when filter is empty

**File:** `resources/views/dashboard.blade.php:87`

**Issue:**

```js
this.$watch('filter', v => { window.location.hash = v || ' '; });
```

Setting `window.location.hash = ' '` writes `#%20` to the URL when the user clears the filter. The intent was presumably to avoid leaving the previous hash visible, but `' '` is not a no-op — it produces an ugly `#%20` in the browser address bar and clutters history.

**Fix:** Clear the hash via `history.replaceState` (which doesn't add a history entry and leaves a clean URL):

```js
this.$watch('filter', v => {
    if (v) {
        window.location.hash = v;
    } else {
        history.replaceState(null, '', window.location.pathname + window.location.search);
    }
});
```

### IN-04: Redundant `$project->status = $status` in test helper

**File:** `tests/Unit/ProjectHealthServiceTest.php:204-210`

**Issue:** `makeProject()` does `new Project(array_merge(['status' => $status], $attributes))` — which already sets `status` via mass assignment — then loops `$attributes` setting each column, then re-sets `$project->status = $status` on line 210. The third assignment is a no-op unless `$attributes` contains a `status` key that would override the constructor value (it never does in the current tests). Harmless but confusing.

**Fix:** Drop line 210, or collapse the helper to:

```php
$project = new Project();
$project->status = $status;
foreach ($attributes as $key => $value) {
    $project->{$key} = $value;
}
```

### IN-05: Legacy RAMS status `STATUS_APPROVED_FOR_GENERATION` is included in `approvedOrBeyond()`

**File:** `app/Services/ProjectHealthService.php:116-117`

**Issue:** The service treats both `STATUS_APPROVED` and `STATUS_APPROVED_FOR_GENERATION` (marked "legacy alias" in `RamsDocument.php:19`) as "approved or beyond". That's correct for today, but the legacy alias is flagged for removal elsewhere — when it's dropped, this array will reference a missing constant.

**Fix:** Add a comment pointing at the deprecation, so whoever removes the constant also prunes this list:

```php
return [
    RamsDocument::STATUS_APPROVED,
    RamsDocument::STATUS_APPROVED_FOR_GENERATION, // TODO: remove together with RamsDocument::STATUS_APPROVED_FOR_GENERATION
    RamsDocument::STATUS_GENERATING,
    RamsDocument::STATUS_COMPLETED,
];
```

### IN-06: `$recentProjects = $projects->take(6)` is unused

**File:** `app/Http/Controllers/DashboardController.php:84`, `:95`

**Issue:** `$recentProjects` is assigned and passed to the view, but `dashboard.blade.php` (the Wave 2 rewrite) no longer references it — the health grid renders the full `$projects` collection with client-side filtering instead. The comment on line 82 acknowledges the variable is kept "for compatibility with the current blade", but the current blade doesn't need it.

**Fix:** Remove the assignment and drop `'recentProjects'` from `compact()`. If any other view still references it, grep-and-update.

---

_Reviewed: 2026-04-19_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
