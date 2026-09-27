---
phase: quick
plan: 260927-lr7
subsystem: navigation-authorization
tags: [navigation, authorization, labour-resources, shared-workspace, route-rename, phase-44-followup]
requires:
  - "Phase 44 Plan 02 — the LabourResource model, controller, index + form views and six routes"
  - "Phase 44 Plan 04 (LR-04 / D-04) — the client-surface privacy boundary, unchanged by this task"
provides:
  - "A top-level 'Labour' nav item, visible to every authenticated user"
  - "Labour resources CRUD reachable by non-admins — the prerequisite for the survey wizard's engineer step having anyone to offer"
  - "/labour-resources (was /admin/labour-resources) with route names labour-resources.* (was admin.labour-resources.*)"
affects:
  - routes/web.php
  - app/Http/Controllers/Admin/LabourResourceController.php
  - resources/views/layouts/navigation.blade.php
  - resources/views/admin/labour-resources/index.blade.php
  - resources/views/admin/labour-resources/form.blade.php
  - tests/Feature/Admin/LabourResourceControllerTest.php
key-files:
  created:
    - .planning/quick/260927-lr7-labour-resources-top-level-menu/PLAN.md
  modified:
    - routes/web.php
    - app/Http/Controllers/Admin/LabourResourceController.php
    - resources/views/layouts/navigation.blade.php
    - resources/views/admin/labour-resources/index.blade.php
    - resources/views/admin/labour-resources/form.blade.php
    - tests/Feature/Admin/LabourResourceControllerTest.php
decisions:
  - "D-01: nav item is TOP LEVEL, not in the Admin dropdown — that dropdown only renders for admins, so putting it there would have shipped the same dead entry under a new name"
  - "D-02: URL moved to /labour-resources and route names dropped the admin. prefix; safe precisely because the page was unreachable and holds no data, so no bookmark or live link exists to break"
  - "D-03: controller namespace and view directory keep saying 'admin' — file organisation only, invisible to a user, out of scope; recorded in the class docblock so it reads as deliberate"
  - "D-04: the Phase 44 'non-admin gets 403' assertion was INVERTED, not deleted, and joined by guest + admin states — the widening is the payload of this task and a one-state test would not have measured it"
  - "D-05: log labels changed from 'Admin: ...' / admin_id to actor_id — an audit line claiming an admin acted would now be false"
metrics:
  duration: ~40 min
  completed: 2026-09-27
---

# Quick Task 260927-lr7: Labour Resources top-level menu, open to non-admins — Summary

Labour Resources had existed since Phase 44 and nothing in the UI had ever linked
to it; the routes were also admin-gated. It now has a top-level nav item and any
authenticated user can add, edit and deactivate resources — the unblock for the
survey wizard showing real engineers instead of "Unassigned".

## What was wrong

Phase 44 Plan 02 delivered a complete feature — model, six routes, index screen,
create/edit form, deactivate toggle, seven tests — registered inside
`Route::middleware('admin')`, and added **zero** links to it. Grep confirmed
`navigation.blade.php` mentioned labour zero times, in the dropdown or anywhere
else. The result was a feature reachable only by an admin typing a URL from
memory, so live carried zero labour resources, so the wizard's engineer step had
nobody to offer.

## The finding that decided the placement

`.tnav-primary` looks like a row of eight links. It is not: every link in it
except **Projects** sits inside an `isAdmin` conditional — Dashboard, RAMS,
Surveys, O&M, Cables, Import, and the whole `.tnav-admin` dropdown. A non-admin
sees one link.

So "move it out of the Admin dropdown" was moot (it was never in it — confirmed
by grep, not assumed), and putting it there would have been useless: the dropdown
does not render for the very users this task exists to serve. The item went
top-level, immediately after Projects, outside every `isAdmin` conditional.

## What changed

**Routes** — the six routes moved out of the `admin` group into the surrounding
`auth` group, re-pathed to `/labour-resources`, renamed to `labour-resources.*`.
`route:list -v` confirms the middleware stack is now `web, auth` on all six, with
no `admin` and no destroy route.

**Callers** — enumerated by grep *before* renaming: `routes/web.php` (6), the
controller's two `redirect()->route()` calls, `index.blade.php` (3),
`form.blade.php` (4), and the test file. Zero references to
`admin.labour-resources` remain in `app/`, `resources/`, `tests/` or `routes/`.
The cockpit's engineer empty-state (`components/cockpit/doc-form.blade.php:554`,
"Add people under Labour resources.") is plain prose with no `route()` call, so
the survey wizard needed no edit — which is just as well, it was out of scope.

**Controller** — had **no gate of its own**: no constructor middleware, no
`abort_unless`, nothing to remove. The route group was and remains the single
place authorization is expressed, and the docblock now says so, because a gate in
two places is a gate nobody can reason about. The docblock's "admin-only" claim
and the "Admin: labour resource created" / `admin_id` log labels were both untrue
after the widening and were corrected (`actor_id`).

**Nav** — `tnav-link` markup copied from the neighbouring items: `route()` href,
`request()->routeIs('labour-resources.*')` active state, an inline `tnav-icon`
SVG (two-person glyph), explicit `title`. No Tailwind utilities used — the
`tnav-*` classes are defined in navigation.blade.php's own inline style block.

## The widening, and how it was measured

Three states rendered and asserted, because a test that only proved the
non-admin could get in would pass just as happily against a page with no gate:

| State | Asserted |
|---|---|
| non-admin | index / create / edit all 200; `store` **persists the row**; `update` persists; `toggle-active` flips to inactive and the row survives |
| guest | all six routes redirect to `login`; the row it tried to create is absent and the row it tried to rename is unchanged |
| admin | index still 200 |
| nav | the `labour-resources.index` href renders on `projects.index` for **both** admin and non-admin |

`store` asserts persistence rather than the redirect on purpose — a rejected
request is also a 302, so a redirect assertion alone proves nothing.

The D-02 tripwire was widened: it now also asserts
`admin.labour-resources.destroy` and `admin.labour-resources.index` resolve to
nothing, so a resurrected copy of the Phase 44 block cannot smuggle a destroy
route, or the old admin-gated paths, back in under a prefix the test no longer
looks at. It additionally asserts all six new names exist, since the nav links to
one of them.

## What LR-04 still forbids

Nothing here relaxes it. A `LabourResource` carries `email` and `phone`, and a
**client** is still never shown either. This is a staff surface behind `auth`;
the client-facing rule lives in
`tests/Feature/Security/LabourResourceClientSurfacePrivacyTest.php`, which was
**not edited** and stays green (20 passed). This page was **not** added to that
test's `CLIENT_FACING_PATHS` — the list covers pages a client can open (public
survey and worksheet token routes, generated documents), and the existing
exclusion of the labour index view remains correct. Both the index view comment
and the controller docblock now say this explicitly, so the next reader does not
"fix" it.

## Gates measured

One suite per invocation via `gate-46.ps1`, foreground, redirected to a file,
ANSI stripped. Every run printed a real `Duration:` line and its per-test list —
none was a killed stall.

| Suite | Result |
|---|---|
| Labour resources (the changed file) | `Tests: 13 passed (58 assertions)` — 9.01s |
| Security (LR-04 lives here) | `Tests: 20 passed (100 assertions)` — 11.57s |
| Cockpit (default gate) | `Tests: 489 passed (8884 assertions)` — 174.71s |
| Worksheets | `Tests: 183 passed (1687 assertions)` — 41.49s |
| Documents | `Tests: 18 passed (142 assertions)` — 5.78s |
| D-06 baseline | `Tests: 2 skipped, 159 passed (396 assertions)` — 48.40s; the `>= 159 passed AND 0 failed` gate is met, and the 2 skips are the known ext-imagick self-skips |
| Authorization (blast radius) | `Tests: 13 passed (31 assertions)` — 69.35s |
| Admin (blast radius of the rename) | `Tests: 38 passed (198 assertions)` — 14.60s |

Cockpit was 489/0 entering and 489/0 leaving.

**Pins** — all three byte-identical to `4abd2b24`, diffed before and after the
edits:

```
layouts/app.blade.php  9ED63C4C754F33832E12BB07A2C515AAC82AB656C5C9A1D35AED0174A7FF0557
resources/css/app.css  EDAD1982303B9FABF86A4B791BBF16436104251277CA79DBAEFB5603C8BE2133
tailwind.config.js     73BB8AD6B7CDF4DC0B11C51661E3DBC7A274CABBCC226D1FF41B5E50938E74BB
```

`navigation.blade.php` — the file that was edited — is not pinned.
`app.blade.php` was never opened for writing.

**`npm run build` is not needed.** Only Blade and PHP changed; no CSS, no JS, no
Tailwind utility classes. The `tnav-*` classes the new item uses are defined in
navigation.blade.php's own inline style block.

## Deviations from plan

- **[Rule 2 — correctness] Log labels corrected.** The three write paths logged
  `Admin: labour resource created` with an `admin_id` key; they now log
  `Labour resource created` with `actor_id`. Not cosmetic: after the widening
  those lines would assert to an auditor that an administrator performed an
  action a standard user performed.
- **[Rule 1 — bug, caught in flight] A blanket prefix substitution also rewrote
  the controller's three `view('admin.labour-resources.*')` names.** Caught by
  grepping the file immediately after the substitution, and reverted — the view
  directory deliberately did not move. Had it shipped it would have been a
  `View not found` 500 on all three screens; the feature tests would have caught
  it, but only after the commit.

## Out of scope, untouched

The labour-resources screens themselves (no redesign), any destroy path, the
cockpit, the RAMS pipeline, the survey wizard. Two things raised alongside this
are separate tasks: the Admin dropdown behaviour, and the RAMS `for_review`
status.

## Follow-up worth knowing

The user-visible symptom that motivated this task — the survey wizard's engineer
step showing "Unassigned" — is **not fixed by code**; it is fixed by somebody now
adding engineers through the new menu. Nothing was seeded. The next session
should expect the page to be empty on first visit and that to be correct.

## Self-Check: PASSED

- `.planning/quick/260927-lr7-labour-resources-top-level-menu/PLAN.md` — FOUND
- `.planning/quick/260927-lr7-labour-resources-top-level-menu/260927-lr7-SUMMARY.md` — FOUND
- Commit `99964728` (feat) — FOUND
- `route:list --name=labour-resources` — 6 routes, `web, auth` only, no destroy
- No `admin.labour-resources` references remain in `app/`, `resources/`, `tests/`, `routes/`
- `tests/Feature/Security/LabourResourceClientSurfacePrivacyTest.php` — not in the commit's file list, green
