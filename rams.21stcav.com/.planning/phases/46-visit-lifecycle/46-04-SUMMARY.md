---
phase: 46-visit-lifecycle
plan: 04
subsystem: cockpit-writes
tags: [write-surface, quick-actions, read-only-fence, csrf, no-javascript, vl-11, d-04, d-05, d-06]
requires:
  - "visits lifecycle columns (Plan 46-01)"
  - "CockpitModulePresenter / CockpitPanelPresenter (Phase 45)"
  - "SiteSurveyController::createFromProject + SurveyService::createFromProject"
  - "WorksheetController::generateFromProject + BuildWorksheetJob"
provides:
  - "POST projects/{project}/cockpit/visits (projects.cockpit.visits.store)"
  - "App\\Http\\Controllers\\ProjectCockpitActionController — the cockpit's ONE write controller"
  - "App\\Support\\Visits\\VisitLinkIssuer (VISIT_MODULES, issue, typesFor, moduleKeys, liveSurveyFor)"
  - "x-cockpit.quick-actions — the panel's Quick actions area (D-15)"
  - "ProjectActivityLog::ACTION_VISIT_CREATED"
  - "ProjectCockpitController::ACTIONS — the panel's third piece of URL state"
  - "a partially retired read-only fence: 2 markup strings, 19 affordances, 9 handler attributes, 7 tables"
affects:
  - "Plan 46-05 (adds its four return-actions to the SAME action controller; <textarea and Add note already lifted for it)"
  - "Plan 46-06 / 46-07 (visit-row controls; the four-control cap is already asserted)"
  - "Phase 47 / 48 (Assign parts, Close snag, Mark as sent are now banned by name)"
tech-stack:
  added: []
  patterns:
    - "A write surface that retires its fence IN THE SAME COMMIT SEQUENCE, per entry, with a reason"
    - "Disclosure by query string, not by JavaScript — &action=create-visit, closed by an anchor back"
    - "A second controller for writes, so the read controller still proves rendering writes nothing"
    - "Mirror a live controller's collaborator sequence rather than refactoring it (minimal diff posture)"
key-files:
  created:
    - app/Http/Controllers/ProjectCockpitActionController.php
    - app/Support/Visits/VisitLinkIssuer.php
    - resources/views/components/cockpit/quick-actions.blade.php
    - tests/Feature/Cockpit/CockpitCreateVisitTest.php
  modified:
    - routes/web.php
    - app/Models/ProjectActivityLog.php
    - app/Http/Controllers/ProjectCockpitController.php
    - resources/views/components/cockpit/panel.blade.php
    - resources/views/projects/cockpit.blade.php
    - resources/css/cockpit.css
    - tests/Feature/Cockpit/CockpitReadOnlyFenceTest.php
    - tests/Feature/Cockpit/CockpitPageTest.php
    - tests/Feature/Cockpit/CockpitPanelTest.php
decisions:
  - "Any authenticated staff user may create a visit — the app's shared-workspace convention, recorded in the controller docblock"
  - "ALL NINE banned handler attributes STAY: Phase 46 considered retiring the Alpine ban and declined"
  - "issue() takes the actor and returns a non-nullable string — a failure throws and rolls the transaction back"
  - "A survey visit ADOPTS a live survey; a backfilled visit already wrapping it is REUSED, never duplicated"
  - "A worksheet visit never adopts — first fix and install are different trips with different sign-offs"
  - "Quick actions render on the Overview tab only — one place to act, not three"
metrics:
  duration: ~95 min
  tasks: 3
  commits: 5
  completed: 2026-09-20
---

# Phase 46 Plan 04: Quick Actions and the Partial Fence Retirement — Summary

**One-liner:** The cockpit became writable — a PM creates a visit from inside its module drawer and
gets back one engineer link produced by the generator that already existed — and the read-only fence
was retired *per entry, by name, with a reason*, banning four fewer markup strings and three more
affordance strings than it did before.

## What was built

### Task 1 — the write controller and the link issuer (`62148963`, RED at `d8ce14c8`)

`POST /projects/{project}/cockpit/visits` → `ProjectCockpitActionController::storeVisit()`, registered
inside the existing `auth` group so the `web` group's session CSRF applies. It carries the same two
gates the read controller does, in the same order: `abort_unless(config('cockpit.enabled'), 404)` then
`abort_unless(auth()->check(), 403)`.

**No POST points at `ProjectCockpitController`.** Its docblock forbids it, and the forbidding is the
point: a reader can still tell from that class alone that rendering the cockpit writes nothing.

`VisitLinkIssuer::VISIT_MODULES` maps exactly two module keys — `site_survey => [site_survey]` and
`worksheet => [first_fix, install]` — because **they are the only two modules with an engineer link**.
`issue()` calls the same service `SiteSurveyController::createFromProject()` calls, or runs the same
four-collaborator sequence `WorksheetController::generateFromProject()` runs (create → auto-flip →
`ensureRunning()` → `BuildWorksheetJob::dispatch`). Neither live controller was refactored; both
method names are written into the issuer's docblock so a future change to either is findable by grep.

Behaviour pinned by test:

| Behaviour | How it is proved |
|---|---|
| Stored `status` stays `planned`; `sent` is DERIVED | `assertSame(STATUS_PLANNED)` + `assertSame(STATE_SENT, $visit->state())` |
| `is_backfilled = false`, `created_by_user_id` set | asserted on the created row |
| Issuing the link sets `sent_at` and nothing else | `accepted_at` asserted null |
| A second survey visit ADOPTS the live survey | one `site_surveys` row, byte-identical `access_token` |
| A backfilled visit already wrapping that survey is REUSED | one `visits` row; the `(source_type, source_id)` unique index never throws |
| One `visit_created` activity row per create | `ACTION_VISIT_CREATED` count = 1, description names the type |
| Row-count discipline | `install_records`, `install_programmes`, `snags`, `site_surveys` all unmoved on a worksheet create |
| Failure leaves nothing half-created | whole create wrapped in `DB::transaction` + re-render with an error |

**Server-side validation (T-46-04-02/03):** `module` is `Rule::in` the issuer's own module keys;
`visit_type` is `Rule::in` the types **the submitted module allows**, so an install type on the survey
module fails validation rather than producing a visit pointing at the wrong paperwork; `scheduled_date`
is `nullable|date`; `rooms.*` is `string|max:200`; `labour_resource_ids.*` is
`integer|exists:labour_resources,id`. **No id in the payload addresses a record** — `{project}` is
route-model-bound and every lookup is scoped to it, proved by a test that posts a foreign `project_id`
and asserts the visit landed on the bound project.

**Tokens (T-46-04-04):** the issuer never touches `access_token`. Tokens come only from
`SiteSurvey::boot()` / `Worksheet::boot()` direct property assignment; the S-02/S-03 `$fillable`
omissions are untouched; a test asserts the worksheet's token appears nowhere in the rendered region.

### Task 2 — Quick actions (`8a761776`)

At the bottom of the panel's **Overview** body, where sketch 004 draws it (D-15). Files and Notes tabs
carry none — one place to act, not three.

| Modules | Control | Why |
|---|---|---|
| Site survey, First fix and install | **one** — `Create visit` | the only two with an engineer link |
| RAMS, O&M manual, Cable schedule | **one** — `Generate document` | D-04, the existing generator, `Route::has()` guarded |
| Programme, Drawings, Programming, Snagging | **none** | not an empty heading, not a disabled button |

**No JavaScript.** The form is disclosed by `&action=create-visit` on the module's own URL and closed
by an anchor back: bookmarkable, back-button-correct, works with JavaScript off. `?action=` is resolved
by MEMBERSHIP against `ProjectCockpitController::ACTIONS` (the same pattern as `?module=` and `?tab=`),
so an unknown value discloses nothing and is never echoed — asserted against the RAW body with a
`<script>` payload and a 4,000-character payload.

The form offers a date, rooms-in-scope checkboxes (D-05 — captured and rendered on the engineer link by
46-05; **the generators stay project-wide, VL-12 NOT DELIVERED**), who-is-going checkboxes of **ACTIVE**
labour resources **by name only** (LR-04 — no email or phone reaches the form, asserted), and for the
worksheet module two radios. **No `<select` anywhere**, so the fence still bans it by name. Errors
re-render through `{{ }}`; no `{!! !!}` was added.

**The stretched-link trap was respected:** nothing in `.cav-qa` takes its own `position`, and the block
lives inside the PANEL, never inside a module row. Every class is `cav-`-prefixed and every colour is a
`--cav-*` token — `CockpitPageTest::test_cockpit_components_carry_no_raw_hex_colour()` still passes.
All 254 compiled views lint clean under `php -l` after `artisan view:clear`.

### Task 3 — the fence, retired per entry (`e2dd7197`)

**`FORBIDDEN_MARKUP` — 6 → 2.**

| Entry | Disposition | Reason recorded at the entry |
|---|---|---|
| `<form` | **LIFTED** (46-04, VL-01) | a write is a form POST — the mechanism chosen over JavaScript |
| `<input` | **LIFTED** (46-04, VL-01) | CSRF hidden field, date, room and resource checkboxes, radios |
| `<button` | **LIFTED** (46-04, VL-01) | the submit control |
| `<textarea` | **LIFTED** (46-05, VL-06/07) | office note + send-back reason — lifted here, named, so the fence is not edited twice |
| `<select` | **STAYS BANNED** | nothing needs one; a select is an interaction the design does not draw |
| `<script` | **STAYS BANNED** | the cockpit ships no JavaScript of its own — a ruling, not an accident |

**`BANNED_HANDLER_ATTRIBUTES` — all NINE STAY.** The constant's docblock was rewritten to record that
Phase 46 **CONSIDERED** retiring the Alpine ban at the exact moment it could have (the first write) and
**declined**, so the next phase inherits a decision rather than an omission. Every write is a real form
POST and every piece of state is server-rendered from the query string, which is what keeps bookmarkable
panel state, a working back button and a page that survives JavaScript being off in a plant room.

**`DEFERRED_AFFORDANCES` — 18 → 19**, count assertion moved in the same commit with its
"never deleted to make a change fit" comment intact and extended.

- **LIFTED:** `Create visit` (shipped by this plan), `Add note` (shipped by 46-05, lifted here and named
  for the same reason as `<textarea`).
- **ADDED:** `Assign parts` → Phase 47, `Close snag` → Phase 47, `Mark as sent` → Phase 48.
- **STILL BANNED, each still meaning something:** `Upload files`, `Download`, `Add document`,
  `Issue to client`, `Send a RAMS to the client`, `Upload a drawing`, `Open register`, `Export CSV`,
  `Add anyway` (all Phase 48 — D-04, this phase does not grow a second half); `Add a snag` and
  `Book a visit` (Phase 47 — Phase 46's copy is `Raise a snag`); `Book another survey` and
  `Prepare a visit` (superseded sketch-002 wording for what this plan ships as `Create visit`);
  `Edit details` (Phase 49); `Re-import from QuoteWerks` and `Close this project` (Phase 50).

**`WRITE_SURFACE_TABLES` — 5 → 7:** `snags` and `project_activity_logs`, because they are now written by
POSTs, which makes it more important that a GET leaves them alone.

**Both `cockpitRegion()` brackets stay exactly as they were. Both GET row-count invariance tests stay
exactly as they were.** Two tests were added:

- `test_a_write_is_a_post_and_a_get_is_still_inert()` — issues the create POST, asserts `visits`,
  `worksheets` and `project_activity_logs` each moved by one and the other four did not move, then
  re-GETs every module, every tab and the disclosed form and asserts nothing moved further.
- `test_every_form_in_the_region_carries_a_csrf_token()` — every `<form>` in the region is
  `method="POST"` and carries **exactly one** `_token` input, matched structurally in the DOM (so a
  neighbouring form's token cannot satisfy it), with a `>= 5` floor so it can never pass vacuously.

The markup and affordance tests were **widened** to judge every open panel and the disclosed form, not
just the bare page: Phase 45's affordances were all absent so the bare page sufficed, but Phase 46 puts
a form inside the panel, and a bare-page-only assertion would have gone on passing while anything at
all was added to a drawer.

## The three-way breakage ritual — what went red

Run in the order below, each reverted immediately after.

**1. XPath pointed at a class that does not exist** (`' cav-cockpit '` → `' cav-cockpit-NOT-A-CLASS '`).
**10 of 11 tests went red**, every one of them on the vacuity guard:
`The cav-cockpit root element was not found — the fence would pass vacuously.`
(The eleventh, `test_the_fence_enumerates_the_whole_deferred_set`, reads the constants and never
extracts a region — correct.)

**2. Extraction truncated to 2,000 characters.** The **BOTTOM bracket** fired:
`The extracted region stops before the end of the page shell — it would leave the module rows
unexamined.` 10 of 11 red. The top bracket alone would have passed — which is exactly why both ends
exist.

**3. `<button onclick="alert(1)">Upload files</button>` injected into `quick-actions.blade.php`.**
**THE ASYMMETRY, which is the proof the retirement was surgical:**

| Test | Result | Why |
|---|---|---|
| `test_rows_are_static_and_nothing_is_wired_to_a_handler` | **RED** | `onclick` — all nine handler attributes still banned |
| `test_none_of_the_deferred_affordances_appears` | **RED** | `Upload files` is still Phase 48 |
| `test_the_cockpit_region_contains_no_form_control_and_no_script` | **GREEN** | `<button` was LIFTED BY NAME and is now legitimate |

`Tests: 2 failed, 9 passed` — the fence caught the script and the deferred copy while permitting the
markup this plan legitimately ships. All three breakages reverted;
`grep -c onclick quick-actions.blade.php` = 0 and the working tree carries no tracked modification.

## Verification

```
tests/Feature/Cockpit + tests/Unit/Cockpit:  Tests:    200 passed (3565 assertions)
CockpitCreateVisitTest:                      Tests:    31 passed (466 assertions)
CockpitReadOnlyFenceTest:                    Tests:    11 passed (1823 assertions)
tests/Unit/Models:                           Tests:    121 passed (376 assertions)
tests/Feature/Projects:                      Tests:    88 passed (241 assertions)
D-06 baseline (gate-46.ps1 -Baseline):       Tests:    2 skipped, 159 passed (396 assertions)
```

Baseline gate: **159 passed ≥ 159, 0 failed, 0 errors.** The two skips are the pre-existing
`ext-imagick` self-skips named in `45-BASELINE.md`. **Never compared against 161.**

`artisan route:list --name=projects.cockpit` — the GET plus the one new POST, and nothing else:

```
GET|HEAD  projects/{project}/cockpit         projects.cockpit             › ProjectCockpitController@show
POST      projects/{project}/cockpit/visits  projects.cockpit.visits.store › ProjectCockpitActionController@storeVisit
```

Protected-file hashes (`gate-46.ps1 -Hashes`) — all three **match** `45-BASELINE.md` exactly:

| File | SHA256 |
|---|---|
| `resources/views/layouts/app.blade.php` | `9ED63C4C…0557` ✅ |
| `resources/css/app.css` | `EDAD1982…2133` ✅ |
| `tailwind.config.js` | `73BB8AD6…74BB` ✅ |

## Deviations from Plan

**1. [Rule 3 — Blocking] Three Phase 45 tests outside `files_modified` carried inline COPIES of the fence**
- **Found during:** Task 2, the moment the panel rendered a form.
- **Issue:** `CockpitPageTest::test_the_panel_carries_no_handler_attribute_no_control_and_no_deferred_write_copy()`
  and `CockpitPanelTest::test_the_filled_panel_carries_no_control_no_handler_and_no_banned_copy()` each
  duplicate the six-string markup list and the affordance list inline. 45-13 "promoted" those lists into
  the fence but did not delete the duplicates. The plan's `files_modified` names only the canonical fence,
  yet its own `<verification>` requires `CockpitPageTest` and `CockpitPanelTest` green.
- **Fix:** Both local lists retired **in step with the canonical one**, by name, with a comment at each
  pointing at `CockpitReadOnlyFenceTest` — `<select` and `<script` kept, all nine handler attributes kept,
  `Upload files` / `Add document` / `Download` / `Issue to client` / `Open register` kept.
- **Commit:** `8a761776`

**2. [Rule 3 — Blocking] `test_no_write_route_exists_for_the_cockpit()` was a Phase 45 assertion this plan makes false**
- It asserted that EVERY cockpit route is GET-only and that exactly one exists. Rewritten (never deleted)
  as `test_the_cockpit_read_route_is_still_get_only_and_every_write_is_a_post_elsewhere()`, which asserts
  something **stronger**: the read route is still the only GET and is still served by
  `ProjectCockpitController`, and every other cockpit route is a plain `POST` served by
  `ProjectCockpitActionController`. That is the plan's `route:list` verification, executable.
- **Commit:** `8a761776`

**3. [Rule 3 — Blocking] `ProjectCockpitController` needed two READ derivations, not just `?action=`**
- The plan authorised only the `?action=` membership resolution there. The form also needs its two option
  lists (survey room names, active labour resources). Deriving them in Blade would have put a query in a
  template against this page's own "the controller wires, the presenter derives" rule, so both were added
  as private read-only helpers beside `resolveAction()`. **They add no write** — the fence's GET row-count
  tests, now covering seven tables including `project_activity_logs`, still pass over every module, every
  tab and the disclosed form.
- **Commit:** `8a761776`

**4. [Rule 1 — Interface] `issue()` is `issue(Visit $visit, User $user): string`, not `issue(Visit $visit): ?string`**
- The generators both require the acting user (`SurveyService::createFromProject($project, $user)`,
  `Worksheet.user_id`), and `auth()` inside a support class would have hidden a dependency the class
  genuinely has. The return was made non-nullable because a null would be a failure a caller could ignore
  and then persist a visit with no link — the exact half-created state the plan forbids. A failure throws,
  the transaction rolls back, and the PM sees an error.
- **Commit:** `62148963`

**5. [Documented, not fixed] The survey path writes a SECOND activity row, from code this plan must not touch**
- `SurveyService::createFromProject()` logs its own `survey_created` row. So a *survey* create produces two
  `project_activity_logs` rows: that one, plus this plan's `visit_created`. The plan's "exactly one
  `ProjectActivityLog` row per create" is asserted as **exactly one `visit_created` row**, which is the part
  this plan owns. Touching the service to suppress its own logging would violate the minimal-diff posture
  against a live delivery path, and losing `survey_created` would remove a feed entry the project page has
  shown for months. `test_a_write_is_a_post_and_a_get_is_still_inert()` therefore exercises the worksheet
  path, where the delta is exactly one.

**6. [Scope] `Quick actions` is used as the block's heading**
- The plan's Task 3 does not add `Quick actions` to `DEFERRED_AFFORDANCES`, and sketch 004 names the block
  that (D-15). It was banned only in the two inline duplicates retired under Deviation 1.

## Requirements

- **VL-01** — complete (create from the drawer, type fixed by the module, date/rooms/people captured,
  `is_backfilled = false`).
- **VL-02** — complete (exactly one link, from the existing generator, no new public route, no new
  document generator).
- **VL-09** — complete (a module's document generated from inside its drawer; sending, confirming,
  uploading and downloading all still absent).
- **VL-03** — **not** marked complete: it spans 46-04 + 46-05 + 46-08. This plan's half (no token becomes
  mass-assignable; no token is rendered) is done and asserted.
- **VL-10** — **not** marked complete: it spans 46-04 + 46-06 + 46-07. `visit_created` is the first entry.
- **VL-11** — **not** marked complete: the one-control-per-module cap is delivered and asserted here; the
  visit-row four-control cap is 46-06 and the human check is 46-08.
- **VL-12** — remains the recorded GAP. `rooms_in_scope` is captured; the generators stay project-wide.

## Known Stubs

None. Every control rendered performs a real write against a real generator, and the four modules with
nothing to offer render nothing at all rather than a placeholder.

## Threat Flags

| Flag | File | Description |
|------|------|-------------|
| threat_flag: new-write-endpoint | `routes/web.php` | `POST projects/{project}/cockpit/visits` — the cockpit's first write. In the `web` + `auth` group (session CSRF), flag-gated, validated, project-scoped, transactional. Enumerated in the plan's register as T-46-04-01/02/03/05/06; no surface outside that register was added. |

## Self-Check: PASSED

- `app/Http/Controllers/ProjectCockpitActionController.php` — FOUND
- `app/Support/Visits/VisitLinkIssuer.php` — FOUND
- `resources/views/components/cockpit/quick-actions.blade.php` — FOUND
- `tests/Feature/Cockpit/CockpitCreateVisitTest.php` — FOUND
- `ProjectActivityLog::ACTION_VISIT_CREATED` — FOUND
- `route('projects.cockpit.visits.store')` registered — FOUND (`route:list`, 2 routes)
- Commit `d8ce14c8` (RED) — FOUND
- Commit `62148963` (Task 1) — FOUND
- Commit `8a761776` (Task 2) — FOUND
- Commit `e2dd7197` (Task 3, the fence) — FOUND
- Commit `77cbfbb6` (cleanup) — FOUND
