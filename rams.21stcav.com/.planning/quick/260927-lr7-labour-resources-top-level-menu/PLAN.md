---
phase: quick
plan: 260927-lr7
type: defect
severity: feature-unreachable-plus-authorization-widening
subsystem: navigation-authorization
autonomous: true
---

# Quick Task 260927-lr7: Labour Resources has existed since Phase 44 and nothing has ever linked to it

## Objective

The user's instruction, verbatim:

> "add Labour Resources as a new top menu so any non admin can add to it"

Phase 44 built the whole feature — model, factory, six routes, an index screen,
a create/edit form, a deactivate toggle — and then registered it inside
`Route::middleware('admin')` while adding **zero** links to it anywhere in the
UI. `resources/views/layouts/navigation.blade.php` mentioned labour zero times.

So the page was reachable only by an admin who typed the URL from memory. Nobody
did. **Live carries zero labour resources**, which is why the survey wizard's
engineer step offers nothing but "Unassigned" — a dead nav entry is
indistinguishable from a missing feature.

Two things to fix, and the second one is the dangerous one:

1. Put Labour Resources in the top nav so it can be found at all.
2. Open it to non-admin authenticated users so the people who actually know the
   engineers can add them.

## The trap: the primary nav is almost entirely admin-gated

Reading `navigation.blade.php` casually suggests a row of eight links. It is not.
Everything in `.tnav-primary` except **Projects** sits inside `@if ($isAdmin)` —
Dashboard, RAMS, Surveys, O&M, Cables, Import, and the entire `.tnav-admin`
dropdown. A non-admin currently sees exactly one link: Projects.

That settles the placement question on its own. "Move it out of the Admin
dropdown" is moot (it was never in it, confirmed by grep, not assumed) and
putting it there would be pointless: **the dropdown only renders for admins**.
The item has to be top level and outside every `isAdmin` conditional, sitting
directly after Projects.

## This is an authorization widening, and it must be deliberate

`LabourResource` carries `name`, `email` and `phone`. After this change any
logged-in user can add, edit and deactivate engineers **including their contact
details**. That is what was asked for and it matches this app's shared-workspace
posture (the same posture already asserted for the field-ops cluster in
`tests/Feature/Authorization/SharedWorkspaceFieldOpsAccessTest.php`), but it is
not a change to make on a single green assertion.

**LR-04 still binds and is NOT affected.** A *client* is never given an
engineer's phone or email. This is a staff surface behind `auth`; the
client-facing rule lives in
`tests/Feature/Security/LabourResourceClientSurfacePrivacyTest.php`. That file is
NOT to be edited and this page is NOT to be added to its `CLIENT_FACING_PATHS` —
it is not client-facing. The scan covers pages a client can open (public survey
and worksheet token routes, generated documents). A staff page behind
authentication is categorically different, and the existing exclusion of
`admin/labour-resources/index.blade.php` from that list stays correct.

## Decision: the URL moves, the internals do not

The routes were `/admin/labour-resources` with names `admin.labour-resources.*`.
A non-admin page under `/admin/` reads as forbidden, and a user who clicks a nav
item and lands on an `/admin/` URL reasonably concludes they are somewhere they
should not be — which defeats the point of removing the onboarding friction.

- **Path → `/labour-resources`.** Safe to move precisely because the page has
  been unreachable and holds no data: there are no bookmarks or live links to
  break.
- **Route names → `labour-resources.*`.** Leaving `admin.` on the names of a
  surface any user can write to is the drift that would eventually mislead a
  maintainer into re-gating it. Every caller was enumerated by grep *before*
  the rename: `routes/web.php` (6), the controller's two redirects,
  `index.blade.php` (3), `form.blade.php` (4), and the test file. The cockpit's
  engineer empty-state (`components/cockpit/doc-form.blade.php:554`, "Add people
  under Labour resources.") is plain prose with no `route()` call, so the survey
  wizard needed no edit — which is just as well, it was out of scope.
- **The controller namespace (`App\Http\Controllers\Admin`) and the view
  directory (`resources/views/admin/labour-resources/`) stay put.** These are
  file organisation, invisible to a user, and moving them is churn on a task
  scoped to the menu entry and the authorization. Documented in the class
  docblock so the mismatch is deliberate rather than rot.

## Tasks

1. **Move the six routes** out of `Route::middleware('admin')` into the
   surrounding `auth` group; re-path to `/labour-resources`; rename to
   `labour-resources.*`. No destroy route — D-02 (deactivate, never delete)
   still binds.
2. **Check the controller for its own gate.** A route change alone is not
   enough: a constructor `middleware('admin')` or an `abort_unless` would
   silently keep non-admins out.
3. **Update every caller** found in step 0's grep.
4. **Add the top-level nav item** after Projects, outside every `isAdmin`
   conditional, following the existing `tnav-link` / `tnav-icon` /
   `request()->routeIs(...)` active-state markup.
5. **Invert the Phase 44 authorization test and assert all three states.**
   Phase 44 asserted "non-admin gets 403" — that assertion is now wrong and must
   be replaced, not deleted. Non-admin must *persist a row*, not merely receive a
   302 (a rejected request is also a 302). Guest must be bounced AND nothing
   written. Admin must be unregressed. Plus: assert the nav link actually renders,
   for admin and non-admin, on a page a non-admin can load (`projects.index`).
6. **Widen the D-02 tripwire** to assert the OLD route names resolve to nothing,
   so a resurrected copy of the Phase 44 block cannot smuggle a destroy route
   back in under a prefix the test no longer looks at.

## Out of scope

Not touched: the labour-resources screens themselves (no redesign), any destroy
path, the cockpit, the RAMS pipeline, the survey wizard. Two things the user
raised separately are separate tasks: the Admin dropdown behaviour, and the RAMS
`for_review` status.

## Gates

`resources/views/layouts/app.blade.php` is one of three sha256-pinned files.
`navigation.blade.php` is **not** pinned and is the file to edit. A pin mismatch
is a STOP, never a hash to refresh.

Suites, one per invocation, via `gate-46.ps1`: cockpit (489/0), Worksheets (183),
Security (LR-04 lives there), Documents (18), the D-06 baseline
(`>= 159 passed AND 0 failed`, never equality against 161), plus Authorization
and Admin as the blast radius of a nav + route-name change. Then the three pins.
