---
phase: 45-visit-model-read-only-cockpit
plan: 12
subsystem: cockpit-presentation
tags: [blade, read-only, no-javascript, d-13, d-14, document-library, activity-feed, sketch-004]
requires:
  - "The panel shell and its Overview tab (Plan 45-11)"
  - "CockpitModulePresenter's module keys (Plan 45-10)"
  - "ProjectActivityLog — append-only, present before this phase"
provides:
  - "CockpitPanelPresenter — per-module file list, per-module notes, project-wide activity feed"
  - "The Files tab: the project's document library for the open module (D-13)"
  - "The Notes tab: the module's own notes plus logged note_added entries"
  - "Recent activity on the Overview tab, newest first, with initials, actor, phrase and time (D-14)"
  - "Two row components: cockpit.file-row and cockpit.activity-row"
affects:
  - "Plan 45-13 — owns CockpitSpineTest (still 15 red) and the fate of attention.blade.php"
  - "Plan 45-14 — the human check against the design image"
  - "Phase 48 — upload, issue-to-client and download all still unbuilt, by design"
tech-stack:
  added: []
  patterns:
    - "route resolution guarded by Route::has(), so a renamed route degrades to a link-less row"
    - "a const map read by tests as data, so 'every mapped route still exists' is provable"
    - "measured-not-assumed: three plan-time assumptions were corrected by running code"
key-files:
  created:
    - app/Support/Cockpit/CockpitPanelPresenter.php
    - resources/views/components/cockpit/file-row.blade.php
    - resources/views/components/cockpit/activity-row.blade.php
    - tests/Unit/Cockpit/CockpitPanelPresenterTest.php
    - tests/Feature/Cockpit/CockpitPanelTest.php
    - .planning/phases/45-visit-model-read-only-cockpit/45-12-SUMMARY.md
  modified:
    - resources/views/components/cockpit/panel.blade.php
    - resources/views/projects/cockpit.blade.php
    - app/Http/Controllers/ProjectCockpitController.php
    - resources/css/cockpit.css
    - tests/Feature/Cockpit/CockpitPageTest.php
decisions:
  - "MEASURED CORRECTION: site surveys and drawings DO have GET show routes (site-surveys.show, projects.drawings.show) — all six document types link"
  - "The activity feed is project-wide and activity() takes no module parameter, so filtering is inexpressible rather than merely absent"
  - "A document with no resolvable route is LISTED without a link — never dropped, never pointed at #"
  - "Project::notes is not a notes fallback; a module with no notes says so"
  - "cable_schedules has no notes column — that column belongs to cable_schedule_items"
  - "The note row is cav-pnote: cav-note was already taken by the page footnote"
  - "Blade prop named `produced`, not `produced_at` — an underscored prop arrives stringified"
metrics:
  duration: ~70 min
  completed: 2026-09-20
  tasks: 2
  commits: 4
---

# Phase 45 Plan 12: The panel's document library, notes and activity feed — Summary

The side panel's two empty tabs are filled from records the app already holds. **Files is the
project's document library for the open module** — D-13, the thing the user singled out: "under
docs a user can see all created project docs in one place (ie click and view etc)". Notes lists
the module's own notes. Recent activity renders `ProjectActivityLog` on the Overview tab. Nothing
writes, nothing offers to write, and no JavaScript of any kind was added.

## Tasks

| # | Task | RED | GREEN |
|---|------|-----|-------|
| 1 | `CockpitPanelPresenter` — files, notes, activity | `31727f38` (24 failed) | `232ad1f2` (24 passed) |
| 2 | The Files and Notes tabs and the activity feed | `5c8e7b4e` (15 failed, 4 passed) | `cace15ad` (134 passed) |

No REFACTOR commit — nothing needed cleanup after GREEN, and a no-op refactor commit would be
noise in the TDD gate sequence.

## What the Files tab lists

For the open module, every document the project holds, newest first, each row carrying its **name**,
the **date it was produced** (`d M Y`, the design's "14 Aug 2026"), its **status** in plain English,
and a **View** link. The link copy is "View" and only "View" — "Download" is Phase 48's word and the
fence bans it by name.

| Module | Relation | Name falls back to | View link |
|---|---|---|---|
| Site survey | `siteSurveys` | "Site survey" | `site-surveys.show` |
| First fix and install | `worksheets` | "Worksheet" | `worksheets.show` |
| RAMS | `ramsDocuments` | "RAMS document" | `rams.review` |
| Drawings | `drawings` | "Drawing" | `projects.drawings.show` |
| O&M manual | `omManuals` | "O&M manual" | `om-manuals.edit` |
| Cable schedule | `cableSchedules` | "Cable schedule" | `cable-schedules.edit` |
| Programme and commissioning · Programming · Snagging | — | — | no document relation exists |

**Every listed document links.** That is a correction to a plan-time assumption, not a liberty — see
the deviation below. The link-less path is still built, still correct, and still exercised by a test
that empties the route collection and asserts the row survives with `route === null`.

A status containing "fail" or "error" reads **"Could not be produced"** — the wording Plan 45-07
settled, and the Copywriting Contract rule that the word "Error" never reaches a PM.

Empty states are one plain sentence each, and they say different things on purpose:

- `?module=programming&tab=files` → **"Programming holds no documents."** (permanent: no relation exists)
- `?module=rams&tab=files` with nothing produced → **"No RAMS documents have been produced yet."**
- `?module=rams&tab=notes` → **"RAMS has no notes recorded."**
- Overview with no log rows → **"Nothing has been recorded against this project yet."**

## The activity feed is project-wide, and it is a decision

`ProjectActivityLog` has **no module column**. Filtering it would mean guessing a module from
`metadata` keys, and every entry carrying no such key would then vanish — a feed that looks complete
and is wrong, which is worse than one that is obviously broad. So the feed is project-wide, and
`activity(Project $project, int $limit = 6)` **takes no module parameter at all**: the filtering is
not merely absent, it is inexpressible.

Two tests pin it, at two levels:

- `CockpitPanelPresenterTest::test_the_feed_takes_no_module_parameter_so_it_cannot_be_filtered()` —
  reflects the signature and asserts the parameter list is exactly `['project', 'limit']`.
- `CockpitPanelTest::test_the_same_feed_renders_under_every_module()` — **the rendered proof**: it
  iterates `CockpitModulePresenter::moduleMap()`'s own nine keys and asserts one `cav-act` row
  carrying the same entry under every `?module=`.

Each row shows an initials avatar (`aria-hidden`, because the actor's name is printed beside it —
`User` has no avatar column and initials are what the design draws anyway), the actor from the
model's own `actor_name` accessor (so a deleted user reads "System", not blank), the phrase, and
`14 Aug 2026, 16:11`. An entry with no description falls back to a humanised action, and an
**unknown** action falls back to its own value humanised — never to a generic "updated", which would
quietly mislabel every action a later phase adds.

## Deviations from Plan

### 1. [Rule 1 — the plan's stated fact was wrong] Site surveys and drawings DO have GET show routes

- **Found during:** Task 1, while verifying the route map with `artisan route:list --json`.
- **The plan and the orchestrator both stated** that `drawings` and site surveys have no GET show
  route, and instructed that those documents be listed **without a link**.
- **Measured:** both routes exist, both are `GET|HEAD`, and both sit behind `['web', 'auth']`:

  ```
  site-surveys.show        GET|HEAD  site-surveys/{site_survey}                 ['web','auth']
  projects.drawings.show   GET|HEAD  projects/{project}/drawings/{drawing}      ['web','auth']
  ```

  The plan's premise was true only of the *names* it checked (`drawings.show`, and a bare survey
  show). `site-surveys.show` is registered by `Route::resource('site-surveys', ...)->only([... 'show' ...])`
  at `routes/web.php:500`, and the drawings show route is the `{drawing}` wildcard at
  `routes/web.php` (the comment at `:618-623` names it explicitly).
- **What was done, and why:** both are linked. The instruction's rationale was "do not point at a
  route that 404s" — these do not 404, and the truth this plan is measured against is *"a PM can see
  every document the project already holds for a module, in one place, **and open it**"*. Listing a
  survey link-lessly when a live, authorised GET route exists would have shipped a knowingly worse
  page. Each target enforces its own authorisation; the cockpit adds no new read path and exposes no
  id the project page does not already expose (T-45-12-02).
- **The link-less path was NOT dropped.** `route` is still nullable, every `route()` call is still
  guarded by `Route::has()`, and
  `test_a_document_whose_route_is_gone_is_still_listed_without_a_link()` empties the route collection
  and asserts the row survives, unlinked and named. So a future rename degrades instead of throwing
  (T-45-12-03), and `test_every_mapped_view_route_name_exists()` turns such a rename red.
- **Commit:** `232ad1f2`

### 2. [Rule 1 — Bug] `cable_schedules` has no `notes` column

- **Found during:** Task 1 GREEN — `SQLSTATE[HY000]: no such column: notes`.
- The plan's notes sources named a module note field where one exists. The `notes` column in
  `2026_03_09_000002_create_cable_schedules_table.php` belongs to the **second** table that migration
  creates, `cable_schedule_items` — a per-cable remark, not a note about the document.
- **Fix:** the cable schedule has no own note field; its Notes tab shows logged notes only.
  `test_the_cable_schedule_has_no_own_note_field()` asserts the column's absence, so the day one is
  added the test says so.
- **Commit:** `232ad1f2`

### 3. [Rule 1 — Bug] Timestamps arrive as strings from several models

- **Found during:** Task 2 GREEN — `Call to a member function format() on string`, a 500 that blanked
  the whole page.
- Several document models declare their own `$casts` arrays that do not list `created_at`.
- **Fix:** `CockpitPanelPresenter::asDate()` normalises to Carbon (or null), so the one date format
  the design specifies lives in one place and no Blade has to guess at the type it was handed. An
  unparseable value renders "Date not recorded" rather than a 500 on a read-only page.
- **Commit:** `cace15ad`

### 4. [Rule 3 — Blocking] A Blade prop named `produced_at` arrives stringified

- Blade camel-cases prop names when binding, so the underscored prop did not arrive as the Carbon
  instance passed to it. The prop is `produced`. Recorded in the component's docblock as measured,
  so the next agent does not reintroduce it.
- **Commit:** `cace15ad`

### 5. [Rule 3 — Blocking] `cav-note` was already taken

- `cockpit.css:961` styles the page **footnote** (the block carrying "Open full project") as
  `.cav-note`. Reusing it for the panel's note rows would have put a border and padding on that
  footnote by accident and made the next edit to either reach both — and it broke a count assertion
  immediately. The panel's note row is **`cav-pnote`**. This is the `cav-schip`/`cav-chip` precedent
  from 45-11, applied again.
- **Commit:** `cace15ad`

### 6. [Rule 1 — a test asserting a stub this plan replaces] `CockpitPageTest` re-pointed, not deleted

`test_the_files_and_notes_tabs_say_plainly_that_they_arrive_next()` asserted 45-11's stub sentence,
which this plan's own SUMMARY named as 45-12's to resolve. It is now
`test_the_files_and_notes_tabs_render_their_own_bodies()`: it keeps the coverage it actually carried
(both tabs render a body; neither draws the Overview ring) and additionally asserts the stub sentence
is **absent**, so it cannot creep back. Nothing was weakened and no assertion was dropped.

### 7. [Scoped exclusion, named] `attention.blade.php` uses `{!! !!}`

T-45-12-01 requires no unescaped output across `resources/views/components/cockpit/*`. Every view
this plan touches complies. `attention.blade.php` does not: it predates this plan, and 45-11 left it
**unrendered** (the health summary moved into the Overall status KPI card) with its deletion assigned
to 45-13. It is excluded **by name** in a one-entry list, with a second test
(`test_the_excluded_legacy_component_is_rendered_by_nothing()`) asserting no view renders it — so the
exclusion is only valid while the component is unreachable, and removing it is a deliberate edit
rather than a quietly widened assertion.

## Not a deviation — the failing spine test

`CockpitSpineTest` is still 15 red. Every one asserts the accordion 45-11 deleted. **Nothing was
weakened, re-pointed or deleted to make them pass** — 45-13 owns that reconciliation, and the
D-02/D-04 disclosures they protect are still required.

## Verification

| Check | Result |
|---|---|
| `artisan test tests/Unit/Cockpit/CockpitPanelPresenterTest.php` | **`Tests: 24 passed (75 assertions)`** |
| `artisan test tests/Feature/Cockpit/CockpitPanelTest.php` | **`Tests: 20 passed (382 assertions)`** |
| Page + fence + flag-off + panel + unit suites | **`Tests: 134 passed (1164 assertions)`** |
| `artisan test tests/Feature/Cockpit tests/Unit/Cockpit` | `15 failed, 134 passed (1222 assertions)` — all 15 in `CockpitSpineTest`, owned by 45-13 |
| **D-06 baseline** (the 12-path enumerated command, character-identical) | **`Tests: 2 skipped, 159 passed (396 assertions)`** — `>= 159 passed AND 0 failed` **PASS** |
| `artisan route:list --json` — every mapped view route | all six present, `GET|HEAD`, `['web','auth']` — also asserted by two tests |
| `php -l` over every compiled view after `view:clear` | **16 checked, 0 failures** — no `@php` short-form or glued-`@if` trap |
| `Select-String resources/views/components/cockpit/*.blade.php -Pattern '#[0-9A-Fa-f]{6}'` | **no match — PASS** |
| Tailwind colour/spacing utilities in the new markup | **none** — only `cav-*` classes and `--cav-*` tokens |
| `git diff --name-only 4abd2b24 -- layouts/app.blade.php app.css tailwind.config.js` | **empty — PASS** |
| `Get-FileHash -Algorithm SHA256` on the three protected files | **all three match `45-BASELINE.md` exactly** |

```
9ED63C4C754F33832E12BB07A2C515AAC82AB656C5C9A1D35AED0174A7FF0557  resources/views/layouts/app.blade.php
EDAD1982303B9FABF86A4B791BBF16436104251277CA79DBAEFB5603C8BE2133  resources/css/app.css
73BB8AD6B7CDF4DC0B11C51661E3DBC7A274CABBCC226D1FF41B5E50938E74BB  tailwind.config.js
```

No migration was run. No package was installed. `migrate:fresh --env=testing` was never invoked.
`state.advance-plan` and `state.update-progress` were never invoked.

## Read-only, proven rather than asserted

`test_the_filled_panel_carries_no_control_no_handler_and_no_banned_copy()` runs over two modules ×
three tabs on a project carrying documents, a survey note and a logged note, and asserts the absence
of `<button`, `<form`, `<input`, `<select`, `<textarea`, `<script`, `x-data`, `x-show`, `x-init`,
`x-if`, `x-text`, `x-on:`, `@click`, `wire:`, `onclick`, and of the copy "Download", "Create visit",
"Add note", "Upload files", "Quick actions", "Add document" and "Open register".

The design's **Quick actions** tiles and the **"…"** overflow on each activity row are not rendered,
not even disabled — a disabled control is still an offer. The design's **"View all"** on the feed is
omitted because no project activity page exists at a GET route, and creating one is a new surface
outside this phase; `test_no_view_all_affordance_is_rendered()` pins that.

Hostile input: a `<script>alert(1)</script>` filename and a `<script>alert(2)</script>` note
description render 200 across all three tabs and are never reflected raw (asserted on the **raw**
response body, not the entity-decoded subtree); a 5,000-character filename still renders its row.

## TDD Gate Compliance

Two tasks, each RED → GREEN as separate commits. No test passed unexpectedly during a RED phase:
Task 1's RED was 24/24 failing on the missing class, and Task 2's four green tests at RED were the
static ones (the route map and the unescaped-output scan), which do not exercise the unwritten tabs.

| Task | RED (`test(...)`) | GREEN (`feat(...)`) |
|---|---|---|
| 1 | `31727f38` — 24 failed | `232ad1f2` — 24 passed |
| 2 | `5c8e7b4e` — 15 failed, 4 passed | `cace15ad` — 134 passed |

## Known Stubs

None. Both tabs and the feed render from real records; every value the design asked for that has no
source is omitted and asserted absent, which is the opposite of a stub.

## Requirements

`VIS-04`, `VIS-06` and `VIS-08` were deliberately **not** marked complete, on the precedent 45-09,
45-10 and 45-11 set: the panel is now filled, but 45-13 still owns the spine reconciliation and
45-14 is the human check against the design image. Marking them here would claim a page that is not
finished.

## Threat Flags

None. The only new outbound surface is the set of "View" links, and every one of them points at a
pre-existing GET route behind the `auth` middleware that enforces its own authorisation.

## Self-Check: PASSED

All five created code and test files exist on disk, and all four commits (`31727f38`,
`232ad1f2`, `5c8e7b4e`, `cace15ad`) are present in `git log --all`. No tracked file was deleted by
any commit in this plan. `.planning/STATE.md` is clean — neither `state.advance-plan` nor
`state.update-progress` was run.
