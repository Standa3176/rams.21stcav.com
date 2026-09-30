---
phase: quick
plan: 260930-cl9
subsystem: navigation-project-landing
tags: [cockpit, project-landing, navigation, feature-flag, fallback, scope-d01b, scope-d03]
requires:
  - "Phase 45 — ProjectCockpitController, projects.cockpit route, config/cockpit.php flag"
  - "SCOPE.md D-01b — the cockpit becomes the project page"
  - "SCOPE.md D-03 — nothing linked to the cockpit, so no feedback about it was real feedback"
provides:
  - "Clicking a project (project list or dashboard) opens the cockpit when COCKPIT_ENABLED is true"
  - "A flag-off fallback to the nine-tab page, so a click is never a 404"
  - "An admin-only 'Classic' top-menu item that reaches the nine-tab page, context-aware"
  - "App\\Support\\Cockpit\\ProjectLanding — the single place that decides where a project opens"
affects:
  - resources/views/projects/index.blade.php
  - resources/views/dashboard.blade.php
  - resources/views/layouts/navigation.blade.php
key-files:
  created:
    - app/Support/Cockpit/ProjectLanding.php
    - tests/Feature/Projects/ProjectLandingPageTest.php
    - .planning/quick/260930-cl9-cockpit-becomes-the-project-landing/PLAN.md
  modified:
    - resources/views/projects/index.blade.php
    - resources/views/dashboard.blade.php
    - resources/views/layouts/navigation.blade.php
decisions:
  - "D-01: re-point the ENTRY POINTS, do not re-point ProjectController@show. The nine-tab page keeps /projects/{id}, which the admin escape hatch and 30+ '← Back to project' anchors depend on"
  - "D-02: the destination is resolved at render time from the flag, not hard-coded to projects.cockpit — with COCKPIT_ENABLED off a bare cockpit link would 404 the most-clicked link in the app"
  - "D-03: the admin menu item is context-aware — the project in context if there is one, otherwise the project list as the picker. Never disabled, never a dead link"
  - "D-04: context comes from the request's own {project} route parameter, not a session 'current project' — no such concept exists here and inventing one would write on every page render"
  - "D-05: entry points only. 'Back to project' anchors on sub-screens still go to the nine-tab page; moving them is a later, per-screen decision (D-01b step 3)"
metrics:
  duration: ~50 min
  completed: 2026-09-30
---

# Quick Task 260930-cl9: the cockpit becomes the project landing page — Summary

Clicking a project — from the project list or the dashboard — now opens the
**cockpit**. The nine-tab project page is untouched, still lives at
`/projects/{id}`, and is now reachable from an **admin-only "Classic" top-menu
item**. With `COCKPIT_ENABLED` off, a project click lands on the nine-tab page
exactly as it does today, so the switch cannot produce a 404.

## The mechanism, and why this one

Re-pointing the **entry points** was chosen over making `ProjectController@show`
render the cockpit, on the tie-breaker the task named: **the old page must keep a
stable URL**, because the admin escape hatch added by the same task points at it.

The nine-tab page is also the real front door today — `projects/show.blade.php` is
2,293 lines and links 46 routes, and `projects.show` is referenced from 30+ "← Back
to project" anchors across `rams/`, `site-survey/`, `worksheets/`, `om-manual/`,
`project-packages/`, `install-programmes/`, `admin/`, `dashboard` and
`projects/cockpit.blade.php` itself. Not one of them changed. `show.blade.php` was
not opened for writing.

Changed entry points, four links in two files:

| File | Links |
|---|---|
| `resources/views/projects/index.blade.php` | the row name (`proj-name-link`) and the row **View** button |
| `resources/views/dashboard.blade.php` | the health-row name (`dash-health-row__link`) and the health-row **View** button |

Both files call the same helper, so the name and the button can never disagree.

## The flag-off fallback

`App\Support\Cockpit\ProjectLanding::url()` resolves the destination at render
time:

```php
return self::cockpitIsTheFrontDoor()
    ? route('projects.cockpit', $project)
    : route('projects.show', $project);
```

`ProjectCockpitController` 404s whenever `config('cockpit.enabled')` is false
(`config/cockpit.php:44`, default **false** and deliberately so). A bare
`route('projects.cockpit')` in the row would therefore have made the most-clicked
link in the app a 404 on any tree where the flag has not been flipped — which is
the same failure `config/cockpit.php:19-38` already warns about in its own words.

`config()` is read rather than `env()`: once `config:cache` has run on live,
`env()` returns null and the fallback would silently invert.

**The assertion that covers both states** (the rendered href AND a GET of it, in
each state — `tests/Feature/Projects/ProjectLandingPageTest.php`):

```php
public function test_the_rendered_destination_is_reachable_in_both_flag_states(): void
{
    $expected = [
        true  => url('/projects/'.$project->id.'/cockpit'),
        false => url('/projects/'.$project->id),
    ];

    foreach ([true, false] as $enabled) {
        config(['cockpit.enabled' => $enabled]);
        $html  = $this->render($admin, route('projects.index'), ...);
        $hrefs = $this->rowHrefs($html, $project);
        $this->assertSame([$expected[$enabled]], $hrefs, 'Wrong destination with the flag '.var_export($enabled, true));
        $this->actingAs($admin)->get($hrefs[0])->assertOk();   // never a 404
    }
}
```

## The admin-only menu item

`Classic`, in `.tnav-primary` immediately after **Projects**, inside
`@if ($isAdmin)`, using the neighbours' exact `tnav-link` / `tnav-icon` /
`request()->routeIs(...)` idiom and an inline SVG. No Tailwind utilities, no new
class names, no hex — the `tnav-*` classes already exist in
`navigation.blade.php`'s own inline style block.

**With no project in context it points at the project list**, which is already the
picker. The nine-tab page cannot render without a project, so a fixed href was not
an option; the destination is read from the current request's own `{project}`
route parameter, handling both a bound `Project` and a bare id, and tolerating
`request()->route()` being null — a `RouteNotFoundException` in the layout would
take down every page.

| Where you are | Where `Classic` goes |
|---|---|
| a project's cockpit | that project's nine-tab page |
| the nine-tab page | itself (and it renders `active`) |
| a project's asset list | that project's nine-tab page |
| the project list / dashboard / anywhere with no project | the project list |
| any page, as a non-admin | **the item is not rendered at all** |

## The nine-tab page still works

`route('projects.show', $project)` still resolves to **`/projects/{id}`** — asserted
literally, in both flag states — and still renders the `ws-tab` workspace strip,
for an admin and for a non-admin. Nothing was deleted, nothing redirected.

## States rendered: 47

Measured, not claimed. 47 real HTTP renders across 13 of the 14 tests in
`tests/Feature/Projects/ProjectLandingPageTest.php`. 24 of them are the full cross
product in one test, whose own assertions are:

```php
$this->assertSame(8, $count, 'flag(2) x role(2) x project-shape(2) = 8 rendered landings.');
$this->assertCount(8, array_unique($landings), 'All eight landings must be DISTINCT states, not eight repeats.');
$this->assertSame(24, $renders, '8 landings x (list render + destination GET + nav render) = 24 renders.');
```

Axes covered: `COCKPIT_ENABLED` on/off · admin/non-admin · a project with
delivery data (two `Visit` rows) / an empty project (asserted to have zero) ·
the menu item with and without a project in context · the destination followed to
a 200 in every case.

`Tests: 14 passed (121 assertions)` — 6.72s.

## Two defects the state matrix caught before commit

- **[Rule 1 — bug] `@php` written inside a Blade `{{-- --}}` comment compiled and
  swallowed the whole nav.** The first version of the new nav comment said
  "resolved in the `@php` block above". Blade compiled that directive from inside
  the comment and **every nav item below the Projects link disappeared** —
  Labour, Classic, RAMS, Surveys, O&M, Cables, Import and the entire Admin
  dropdown. Caught by dumping the rendered nav, not by reading the Blade. The
  comment now spells the word out and says why, in the file.
- **[Rule 1 — bug, in the test] a document-wide href search read the shared
  edit-action-bar's `url()->previous()` anchor as a third project-row link.** That
  anchor renders on every page with `class="btn btn-outline btn-sm"`, so once a
  test had GET the cockpit it echoed the cockpit URL back on the next page and
  produced a false red in the flag-off state. Found by dumping the three matches
  with offsets. The row search is now bounded to the table body (or, on the
  dashboard, to the first health row onwards) **and** asserts it found exactly two
  links, so a third fails loudly instead of being averaged away.

## Gates measured

One suite per `gate-46.ps1` invocation, foreground, redirected to a file, ANSI
stripped. Every run printed a real `Duration:` line and its per-test list — none
was a killed stall reporting a false exit 0.

| Suite | Result |
|---|---|
| `tests/Feature/Cockpit` | `Tests: 392 passed (7856 assertions)` — 166.29s |
| `tests/Unit/Cockpit` | `Tests: 114 passed (1203 assertions)` — 14.01s |
| cockpit total | **506 / 0**, unchanged entering and leaving |
| `tests/Feature/Worksheets` | `Tests: 263 passed (2317 assertions)` — 47.72s |
| `tests/Feature/Documents` | `Tests: 18 passed (142 assertions)` — 5.78s |
| `-Filter Project` | `Tests: 1 skipped, 435 passed (1578 assertions)` — 67.19s |
| D-06 baseline | `Tests: 2 skipped, 159 passed (396 assertions)` — 31.07s → `>= 159 passed AND 0 failed` met; the 2 skips are the known ext-imagick self-skips |
| new file alone | `Tests: 14 passed (121 assertions)` — 6.72s |

The new test file lives in `tests/Feature/Projects/`, so the cockpit gate stays at
exactly **506 / 0** — the same number entering and leaving — and the growth lands
in `-Filter Project` instead.

**Pins — all three byte-identical to `4abd2b24`:**

```
layouts/app.blade.php  9ED63C4C754F33832E12BB07A2C515AAC82AB656C5C9A1D35AED0174A7FF0557
resources/css/app.css  EDAD1982303B9FABF86A4B791BBF16436104251277CA79DBAEFB5603C8BE2133
tailwind.config.js     73BB8AD6B7CDF4DC0B11C51661E3DBC7A274CABBCC226D1FF41B5E50938E74BB
```

`app.blade.php` was never opened for writing. `navigation.blade.php` — the file
that was edited — is not pinned.

## Deploy note

**Verified against this task's diff, not inherited.**

- **Migration: NO.** `git diff --name-only` touches zero files under
  `database/migrations`, no model, no schema. The diff is three Blades, one new
  support class and one new test file.
- **`npm run build`: NO.** No file in any Vite entry changed — `resources/css/app.css`,
  `tailwind.config.js`, `vite.config.js` and `package.json` are all untouched, and
  the change introduces **no new class name at all** (the nav item reuses
  `tnav-link` / `tnav-icon`, which are defined in `navigation.blade.php`'s own
  inline `<style>`). Blade compiles at runtime. Run `php artisan view:clear` after
  deploy as usual; that is not a build.
- **⚠️ `COCKPIT_ENABLED` must be confirmed `true` on live BEFORE this is relied
  on.** The live value is not in this repo. If it is false or absent, **every
  project click falls back to the nine-tab page** — safe, but the switch the user
  asked for has not happened and it will look like the change did nothing. Confirm
  with `php artisan tinker --execute="var_dump(config('cockpit.enabled'));"` on the
  VPS, and remember `config:clear` after any `.env` edit.
- **⚠️ Pre-flight: `php artisan visits:backfill` (dry run, read the counts) then
  `--apply`.** `config/cockpit.php:19-38` warns the cockpit renders an **empty
  spine** until the `visits` table is populated. That was tolerable while the
  cockpit was URL-only; it is not tolerable now that it is the first page everyone
  sees. Verify a non-zero visit count for live projects before flipping the flag.
- Deploy as `stcav`, not root. No `server/*.mjs` equivalent here and no queue
  worker touched, so no service restart is needed beyond the usual PHP-FPM reload
  if opcache is warm.

## Out of scope, and deliberately not built

Per the task and `SCOPE.md` D-02, **none** of the following was built, surfaced or
asserted here:

- engineer-link **display / copy / revoke** in the cockpit;
- **visit management** surfacing — accept, send back, note, snag;
- **returned/completed-link review** (Phase 46.1 built `VisitEvidence`,
  `CockpitEvidencePresenter` and `VisitPhotoZipBuilder`; 46.2 D-02 unsurfaced it;
  its routes are still registered).

That is the next task. `projects/show.blade.php`, `rams.review`,
`site-surveys.edit` and `om-manuals.edit-devices` were not touched — the
sole-capture-point rule (C-1 / C-4) is unchanged, and retirement stays a later,
separate, per-screen decision.

## Follow-up worth knowing

The "Back to project" anchors on every sub-screen still land on the nine-tab page.
That is D-01b step 3 territory — surfaces move one at a time, each on its own
evidence — and changing them here would have widened a front-door switch into a
repo-wide link rewrite.

## Self-Check: PASSED

- `app/Support/Cockpit/ProjectLanding.php` — FOUND
- `tests/Feature/Projects/ProjectLandingPageTest.php` — FOUND
- `.planning/quick/260930-cl9-cockpit-becomes-the-project-landing/PLAN.md` — FOUND
- `.planning/quick/260930-cl9-cockpit-becomes-the-project-landing/260930-cl9-SUMMARY.md` — FOUND
- `resources/views/projects/show.blade.php` — NOT in the diff (unchanged, 2,293 lines)
- Three sha256 pins — MATCH `4abd2b24`
- Commit `c65064a7` (feat, 7 files, 1007 insertions) — FOUND
