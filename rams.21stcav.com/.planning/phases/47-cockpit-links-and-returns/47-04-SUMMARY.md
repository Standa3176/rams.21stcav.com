---
phase: 47-cockpit-links-and-returns
plan: 04
subsystem: cockpit
tags: [laravel, blade, visit-lifecycle, read-only-fence, returned-tab]

requires:
  - phase: 47-03
    provides: "The Returned tab (ProjectCockpitController::TABS including 'returned', returned-tab.blade.php, CockpitEvidencePresenter re-injected)"
provides:
  - "ProjectCockpitController::ACTIONS === ['generate', 'send-back', 'note', 'snag']"
  - "resolveActionVisitId(), rebuilt — casts ?visit= for the three visit-scoped disclosures, no lookup"
  - "returned-tab.blade.php renders <x-cockpit.visit-row controls=\"true\" tab=\"returned\"> beneath each visit's evidence"
  - "Accept, Send back, Add note, Raise a snag reachable again from the cockpit, scoped to the Returned tab only"
affects:
  - "tests/Feature/Cockpit/CockpitDocumentFormTest.php"
  - "tests/Feature/Cockpit/CockpitRamsWizardTest.php"

tech-stack:
  added: []
  patterns:
    - "A GET disclosure's visit id is a cast, never a lookup — the POST's own ownership check is the real guard (T-46-06-01), re-proved through the new URL shape rather than re-implemented"
    - "Cap and zero-controls proofs judged per-visit-subtree through real HTTP, not page-wide and not via the isolated component render"

key-files:
  created: []
  modified:
    - app/Http/Controllers/ProjectCockpitController.php
    - resources/views/components/cockpit/panel.blade.php
    - resources/views/components/cockpit/returned-tab.blade.php
    - resources/views/projects/cockpit.blade.php
    - tests/Feature/Cockpit/CockpitVisitActionsTest.php
    - tests/Feature/Cockpit/CockpitReadOnlyFenceTest.php
    - tests/Feature/Cockpit/CockpitDocumentFormTest.php
    - tests/Feature/Cockpit/CockpitRamsWizardTest.php

key-decisions:
  - "visit-row.blade.php genuinely already implemented every gate this plan needed (controls prop, tab-aware disclosure URLs with 'tab'=>'returned' hardcoded since 46.1-05, the four-control cap, the reconstructed exclusion) — confirmed by reading it before writing anything, and zero lines of visit-row.blade.php were touched."
  - "returned-tab.blade.php renders the FULL <x-cockpit.visit-row> component beneath each visit's evidence, not a hand-extracted action-area-only partial. This duplicates the review-state sentence/title/chips the card above already prints (wave 2's own docblock named this exact tension as the reason it deferred the decision to this plan). Chosen because: (a) the plan's interface section is explicit that visit-row needs no internal change, and extracting just the action area would require either a second component or editing visit-row's internal gates, both forbidden by the scope fence; (b) the duplication is cosmetic (both read the same Visit::state()/isBackfilled() and can never disagree about WHETHER a control renders, only how many times the same fact prints); (c) RV-08 ('is the tab calm?') is explicitly Plan 47-05's call, not this plan's to pre-empt by redesigning the row."
  - "resolveActionVisitId() casts (int) on ?visit= unconditionally once $action is a visit-scoped string, with no DB lookup — exactly as the plan's interface section specified, rebuilt rather than reinvented."

requirements-completed: [VL-05, VL-06, VL-07, VL-08, VL-11, RV-05]

duration: ~2.5h
completed: 2026-10-01
---

# Phase 47 Plan 04: Accept / Send back / Add note / Raise a snag — back beneath the Returned tab's evidence Summary

**Four visit-management controls Phase 46.2 D-02 unsurfaced are reachable again from the cockpit — wired beneath the Returned tab's evidence only, never on Overview, proven through real HTTP never to exceed VL-11's four-control cap and proven to offer zero on a reconstructed visit while its evidence still renders.**

## What this plan built

- `app/Http/Controllers/ProjectCockpitController.php`: `ACTIONS` grows `['generate']` → `['generate', 'send-back', 'note', 'snag']`. `resolveActionVisitId()` is rebuilt exactly as it was before 46.2-03 retired it — a plain `(int)` cast of `?visit=` when `$action` names a visit-scoped disclosure, no database lookup. `$actionVisitId` is resolved in `show()` and passed to the view. `create-visit` is NOT among `ACTIONS` and the method explicitly returns `null` for `generate` — D-03 names four acts on an existing visit, never creating one.
- `resources/views/components/cockpit/panel.blade.php`: new `actionVisitId` prop (default `null`), threaded into the `returned` tab's `<x-cockpit.returned-tab>` call alongside `$action`.
- `resources/views/projects/cockpit.blade.php`: threads `:action-visit-id="$actionVisitId"` into `<x-cockpit.panel>`, on the identical precedent 47-01/47-03 used for `:link`/`:evidence`.
- `resources/views/components/cockpit/returned-tab.blade.php`: each visit's card now renders `<x-cockpit.visit-row :visit="$visit" :project="$project" :module="$module" :action="$action" :action-visit-id="$actionVisitId" controls="true" tab="returned" />` beneath its evidence block, for EVERY visit in `$returnedVisits` — including a `source_missing` or `has_anything: false` one — because `visit-row`'s own eight gates, not this file, decide whether anything in its action area draws. Zero lines inside `visit-row.blade.php` were touched.
- `tests/Feature/Cockpit/CockpitVisitActionsTest.php`: the ACTIONS/`?action=` test is rewritten to prove the new four-entry list and `create-visit`'s continued exclusion, scoped to Overview (where it still discloses nothing, whatever the action). Five new tests prove, through real HTTP on the Returned tab: the four-control cap holds per-visit-subtree over every `Visit::TYPES` × six lifecycle states × both `is_backfilled` states; the cap is judged per-row not per-page (two visits on one page, one offering four controls and one offering one, counted separately); the SAME visit offers four controls on Returned and zero on Overview; a reconstructed visit with a real signed worksheet behind it offers zero controls on the Returned tab while its title/evidence still renders; cross-project `?visit=` scoping is refused by the POST when reached through this tab's own disclosure URL shape.
- `tests/Feature/Cockpit/CockpitReadOnlyFenceTest.php`: `DEFERRED_AFFORDANCES` moves 20 → 19 — `'Add note'` is lifted a second time (it returned when 46.2-03 unsurfaced the control; this plan puts it back). `'Create visit'` is the one entry that stays. The unsurfaced-routes test is renamed and narrowed: only `.store` (Create visit) is still proven linked nowhere; a new section proves `accept`/`send-back`/`note`/`snag` ARE linked on the Returned tab (fresh, state-correct fixtures per act) and absent from Overview.

## Measured results (all foreground, redirected, one suite per invocation)

- `gate-46.ps1 -Filter CockpitVisitActionsTest` — **39 passed, 0 failed, 1762 assertions.**
- `gate-46.ps1 -Filter CockpitReadOnlyFenceTest` — **14 passed, 0 failed, 1755 assertions.**
- `gate-46.ps1 -Path tests/Feature/Cockpit` — **446 passed, 0 failed, 9064 assertions** (was 441/0/8708 after Plan 47-03; +5 net tests from the two suites above, +356 assertions).
- `gate-46.ps1 -Path tests/Unit/Cockpit` — **114 passed, 0 failed, 1203 assertions** — unedited by this plan, re-run for confirmation.
- `gate-46.ps1 -Baseline` — **159 passed, 2 skipped (pre-existing, missing ext-imagick), 0 failed** — meets `>= 159 passed AND 0 failed`.
- `gate-46.ps1 -Hashes` — all three protected files (`layouts/app.blade.php`, `resources/css/app.css`, `tailwind.config.js`) confirmed **byte-identical** to the pinned `4abd2b24` hashes.

## Pinned counts moved, by name

| Count | Before | After | Control each lift ships |
|---|---|---|---|
| `DEFERRED_AFFORDANCES` | 20 | **19** | `'Add note'` lifted — proven to render on a noteable (RETURNED/SENT_BACK/ACCEPTED) visit's Returned tab, and proven absent on a reconstructed visit's |
| `ProjectCockpitController::ACTIONS` | `['generate']` (1) | **4** (`generate, send-back, note, snag`) | the three visit-scoped disclosures `visit-row.blade.php` already knew how to build URLs for |
| `FORBIDDEN_MARKUP` | 2 | 2 | unchanged — no new form-control type |
| `BANNED_HANDLER_ATTRIBUTES` | 9 | 9 | unchanged — no JavaScript |
| `WRITE_SURFACE_TABLES` | 13 | 13 | unchanged — this plan reads nothing new; the four acts' writes were already named by Phase 46 |
| Sibling: `CockpitDocumentFormTest` `assertCount($deferred)` ×2 | 20 | **19** | moved by name — pre-existing hidden coupling (same class wave 2/47-03 hit for `'Download'`) |
| Sibling: `CockpitRamsWizardTest` `assertCount($banned)` | 22 | **21** | moved by name — `19 deferred + 2 forbidden-markup` |

`CockpitPageTest`'s `assertSame(6, $writes)` / `assertSame(3, $gets)` were **not touched** — confirmed by the full-suite green run above; no route was registered by this plan (see below).

## How each proof was done

- **The four-control cap, through HTTP, on the path this plan wires:** `CockpitVisitActionsTest::test_the_returned_tabs_four_control_cap_holds_through_http_per_visit()` sweeps every `Visit::TYPES` entry × `['planned', 'sent', 'returned', 'sentBack', 'accepted', 'default']` × both `is_backfilled` states, forcing a resolvable source onto every fixture so the Returned tab's own presence rule never silently excludes a row the sweep means to judge. Controls are counted inside `returnedTabRows()`'s per-row subtree (never page-wide) and asserted `<= 4`; a backfilled fixture is additionally asserted to be exactly `0`. 1,320+ rows judged (`$seen > 20` floor; actual count far higher given the full type × state × backfilled matrix).
- **The cap judged per row, not per page (the explicit non-vacuity trap named in the plan):** `test_the_four_control_cap_is_judged_per_row_not_per_page()` puts a RETURNED visit (4 controls: Accept, Send back, Add note, Raise a snag) and an ACCEPTED visit (1 control: Add note) on the SAME Returned tab, and asserts each row's own subtree count separately — `4` and `1` — rather than trusting a page-wide total that would read `5` and never trip a per-row ceiling of four.
- **Never on Overview, proved against the identical visit:** `test_the_same_visit_offers_four_controls_on_returned_and_zero_on_overview()` renders ONE RETURNED visit on both tabs and asserts all four control strings present on Returned and absent on Overview for that same row — not two different fixtures.
- **Never disabled:** every row judged by the cap sweep and the per-row test additionally asserts `assertStringNotContainsString('disabled', $row)`.
- **A reconstructed visit offers zero, re-proved on the page it was written for:** `test_a_reconstructed_visit_offers_zero_controls_on_the_returned_tab()` builds a `backfilledFromWorksheet()` visit with a real `WorksheetSignoff` behind it (so `Visit::state()` genuinely reads `STATE_RETURNED`, exactly as the 24 live rows do), opens the Returned tab through real HTTP, and asserts zero `cav-visit__control` elements in its subtree while the visit's own title still renders in the surrounding region — the row still happened and still reviews, it simply offers nothing.
- **Cross-project scoping, through this tab's own URLs:** `test_cross_project_visit_id_on_the_disclosure_url_is_refused_by_the_post()` opens project A's cockpit with `?action=send-back&visit={project B's visit id}` (asserts 200, since `resolveActionVisitId()` performs no lookup), then POSTs the send-back for real against that foreign id and asserts the existing `ProjectCockpitActionController::guard()` ownership check still 404s it — proving the pre-existing guard (T-46-06-01, unchanged) holds when reached exactly as a PM would reach it, not only through the bare route.
- **The lift bites (`'Add note'`):** `CockpitReadOnlyFenceTest`'s new "the four visit acts are linked on Returned, nowhere else" section builds one fresh, correctly-stated fixture per act and asserts the literal disclosure/POST URL present on the Returned tab and absent on Overview — `note` among them, proving the lift is paid for by a real render, not by removal alone.

## Was `visit-row.blade.php` already built for this?

Yes, entirely. Read before writing anything (per the plan's own instruction). It already computed `$canAccept`/`$canSendBack`/`$canNote`/`$canSnag`, all four disclosure URLs with `'tab' => 'returned'` hardcoded since Plan 46.1-05 (written for a tab that did not exist yet at the time), the `$sendBackOpen`/`$noteOpen`/`$snagOpen` comparison-never-lookup gates, and wrapped the whole action area in `@if ($controls && (...))` with `controls` defaulting `false`. This plan's entire code change to reach that mechanism was: grow `ACTIONS`, rebuild `resolveActionVisitId()`, and pass `controls="true" tab="returned"` from `returned-tab.blade.php`. **Zero lines of `visit-row.blade.php` were edited.**

## Route registration

**None.** `CockpitPageTest`'s `assertSame(3, $gets)` is unchanged by this plan (confirmed in the full green suite run above) and the seven visit/evidence routes this plan links to were already registered before this plan started — the plan surfaces links to `projects.cockpit.visits.accept`/`.send-back`/`.notes`/`.snags`, none of them new.

## `cockpit.css`

**Not touched.** No new CSS was needed — `visit-row.blade.php`'s existing `.cav-visit__actions`/`.cav-visit__control` rules (shipped in Phase 46) are reused unchanged. No `npm run build` is required for this plan's changes.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — bug, wrong URL shape in a new test] `send-back`/`note`/`snag`'s link is the cockpit's own `?action=` disclosure URL, not the POST route directly**

- **Found during:** first run of `CockpitReadOnlyFenceTest`'s new "the four visit acts are linked" section.
- **Issue:** the test initially built the expected URL via `route('projects.cockpit.visits.send-back', ...)` for all four acts. Only `accept` renders as a `<form action="...">` pointing straight at its POST route — `send-back`/`note`/`snag` render as `<a href="...">` pointing at the cockpit's OWN `?action=send-back&visit={id}&tab=returned` disclosure URL (`visit-row.blade.php`'s `$sendBackUrl`/`$noteUrl`/`$snagUrl`); the POST route itself only appears once that form is already disclosed, a different scenario this plan does not add a test for (the forms themselves are unedited and already tested by `CockpitVisitActionsTest`'s pre-existing route-half assertions).
- **Fix:** built the three disclosure URLs via `route('projects.cockpit', [...'action' => 'send-back'/'note'/'snag', 'visit' => $id, 'tab' => 'returned'])`, matching what `visit-row.blade.php` actually renders.
- **Files modified:** `tests/Feature/Cockpit/CockpitReadOnlyFenceTest.php` (within this plan's own file list).

**2. [Rule 1 — bug, test-helper coupling] `cockpitRegion()` asserts a hardcoded project name; the four new per-act fixtures did not carry it**

- **Found during:** the same first run.
- **Issue:** `cockpitRegion()` (used by `render()`) hard-asserts `self::PROJECT_NAME` ("Fence Test Job") is present in the extracted region as a non-vacuity bracket. The four fresh `Project::factory()->create(['status' => ...])` fixtures built for the per-act proof did not set `name`, so the bracket check failed before the real assertion was ever reached.
- **Fix:** added `'name' => self::PROJECT_NAME` to each of the four fixtures.
- **Files modified:** `tests/Feature/Cockpit/CockpitReadOnlyFenceTest.php` (within this plan's own file list).

**3. [Rule 1 — bug, pinned counts in sibling files outside this plan's frontmatter] Two other test files hardcoded `DEFERRED_AFFORDANCES`'s total**

- **Found during:** the full `tests/Feature/Cockpit` run after Tasks 1–3.
- **Issue:** `CockpitDocumentFormTest::test_the_closed_control_copy_collides_with_no_fence_entry()` and `::test_the_wizard_copy_collides_with_no_fence_entry()` both pinned `assertCount(20, $deferred)`; `CockpitRamsWizardTest::test_the_rams_copy_collides_with_no_fence_entry()` pinned `assertCount(22, $banned)` (20 deferred + 2 forbidden-markup). All three went red the moment `'Add note'` left `DEFERRED_AFFORDANCES` — the identical class of hidden coupling Plan 47-03 documented and fixed for the `'Download'` lift.
- **Fix:** moved both `20`s to `19`; moved `22` to `21`, citing this plan in each message.
- **Files modified:** `tests/Feature/Cockpit/CockpitDocumentFormTest.php`, `tests/Feature/Cockpit/CockpitRamsWizardTest.php` — both OUTSIDE this plan's frontmatter `files_modified` list.
- **Commit:** `522331fe` (separate commit, named as a deviation).

## Task Commits

1. **Task 1 + Task 2: wire controls into the Returned tab, grow ACTIONS, and prove the cap/zero-controls/never-on-Overview/cross-project rules through HTTP** — `86bcecc6`
2. **Task 3: lift 'Add note' by name and prove the four acts link on Returned** — `b73c855b`
3. **Deviation fix: sibling pinned fence counts** — `522331fe`

_No plan metadata commit made per this plan's constraints (`.planning/STATE.md` is owned by the orchestrator; `.planning/ROADMAP.md` is owned by Plan 47-05)._

## Not delivered / scope notes

Nothing in the plan's task list was skipped. `Create visit` was NOT shipped and stays out of `ACTIONS` — confirmed by name in both the controller's docblock and the new tests. No change was made to `ProjectCockpitActionController`, `VisitLinkIssuer`, or any Phase 46 model/service — all four acts' writes were already live and tested; this plan adds links, nothing else. No gate inside `visit-row.blade.php` was edited, and none looked wrong once reachable through this path — nothing to report under the scope fence's "report, don't silently edit" clause. RV-08 ("is the tab calm?") is NOT answered here — that is Plan 47-05's blocking checkpoint.

## Self-Check: PASSED

- `app/Http/Controllers/ProjectCockpitController.php` — FOUND, modified as described.
- `resources/views/components/cockpit/panel.blade.php` — FOUND, modified as described.
- `resources/views/components/cockpit/returned-tab.blade.php` — FOUND, modified as described.
- `resources/views/projects/cockpit.blade.php` — FOUND, modified as described.
- `tests/Feature/Cockpit/CockpitVisitActionsTest.php` — FOUND, modified as described.
- `tests/Feature/Cockpit/CockpitReadOnlyFenceTest.php` — FOUND, modified as described.
- `tests/Feature/Cockpit/CockpitDocumentFormTest.php`, `CockpitRamsWizardTest.php` — FOUND, modified as described (Deviation 3).
- Commit `86bcecc6` — FOUND in `git log --oneline`.
- Commit `b73c855b` — FOUND in `git log --oneline`.
- Commit `522331fe` — FOUND in `git log --oneline`.
