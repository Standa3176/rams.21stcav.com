---
phase: quick
plan: 260930-cl9
type: feature
severity: front-door-switch
subsystem: navigation-project-landing
autonomous: true
---

# Quick Task 260930-cl9: the cockpit becomes the project landing page, and the nine-tab page becomes an admin-only escape hatch

## Objective

The user, verbatim:

> "add the 9 tab page for admin only to the top menu so we can get to it if needed
> while we complete the cockpit version"

> "clicking on a project from proj dash opens the cockpit"

and, when told the cockpit is incomplete:

> **"switch regardless"**

This is `SCOPE.md` D-01b (the cockpit becomes the project page) plus D-03 (nobody
can reach the cockpit today — measured 2026-09-30: no Blade outside
`components/cockpit/*` links to `projects.cockpit`, so it is reachable only by
typing the URL, and until that is fixed no feedback about it is real feedback).

## The mechanism, and why this one

Two shapes were available:

- **(A)** re-point the entry-point links at `projects.cockpit`, leaving
  `ProjectController@show` alone;
- **(B)** make `ProjectController@show` render the cockpit and give the nine-tab
  page a new URL of its own.

**(A) wins on the tie-breaker the task itself names: keep the old page at a stable
URL.** Under (B) the nine-tab page would have to move, and the admin menu item
added by this same task points straight at it. (A) also leaves `projects.show`
— which `projects/show.blade.php` reaches through 46 routes and which 30+ "← Back
to project" anchors across `rams/`, `site-survey/`, `worksheets/`, `om-manual/`,
`project-packages/` and `install-programmes/` link to — completely untouched.

So: the **entry points** change, the **destinations** do not.

## The flag is the trap, and its own docs say so

`ProjectCockpitController` gates on `config('cockpit.enabled')`
(`config/cockpit.php:44`, default **false**), and `config/cockpit.php:19-38`
spells out a five-step deploy order and warns that a gate which starts wrong
"teaches everyone to ignore it".

**A project click must therefore never be a bare `route('projects.cockpit')`.**
With the flag off that is a 404 on the most-clicked link in the app. One helper,
`App\Support\Cockpit\ProjectLanding::url()`, resolves the destination at render
time:

- flag **ON** → the cockpit
- flag **OFF** → the nine-tab page, i.e. exactly today's behaviour

Both states are rendered AND the rendered href is then followed and asserted 200.

The same file warns the cockpit shows an **empty spine** until
`visits:backfill --apply` has run. That cannot be checked from this repo, so it
goes in the deploy note as a pre-flight — this task makes that page the first
thing everyone sees.

## What the admin menu item does with no project in context

The nine-tab page **cannot render without a project**, so the item cannot be a
fixed href. It is resolved from the current request's own `{project}` route
parameter:

- **project in context** (any `/projects/{project}/...` page, including the
  cockpit — which is the case the user actually asked about) → that project's
  nine-tab page;
- **no project in context** → the **project list**, which is already the project
  picker.

Never disabled, never a dead link. Read from the route parameter rather than a
session "current project": no such concept exists in this app
(`ProjectContextResolver` resolves package data, not a selection) and inventing
one would put a write on every page render.

## Tasks

1. Add `App\Support\Cockpit\ProjectLanding` — the one place that decides where a
   project opens, with the flag-off fallback.
2. Re-point the project list rows (name + View) at it.
3. Re-point the dashboard health rows (name + View) at it.
4. Add the admin-only `Classic` top-menu item to
   `resources/views/layouts/navigation.blade.php`, following the `tnav-link` /
   `tnav-icon` / `request()->routeIs(...)` idiom of its neighbours.
5. Render every state and assert the count.

## Explicitly NOT in this task

The cockpit is incomplete and the user knows. **Not built here:** engineer-link
display / copy / revoke, visit management surfacing (accept, send back, note,
snag), returned-link review. That is `SCOPE.md` D-02 and it is the next task.
Nothing is deleted; `projects/show.blade.php` (2,293 lines) is not edited at all
— only how it is reached.

## Gates

`resources/views/layouts/app.blade.php` is one of three sha256-pinned files;
`navigation.blade.php` is **not** pinned and is the file to edit. A pin mismatch
is a STOP, never a hash to refresh.

One suite per `gate-46.ps1` invocation, foreground, redirected to a file:
`tests/Feature/Cockpit` (392), `tests/Unit/Cockpit` (114),
`tests/Feature/Worksheets` (263), `tests/Feature/Documents` (18),
`-Filter Project`, the three pins, and the D-06 baseline
(`>= 159 passed AND 0 failed`, never equality against 161).
