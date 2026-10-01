---
phase: 47-cockpit-links-and-returns
plan: 03
subsystem: cockpit
tags: [laravel, blade, visit-evidence, read-only-fence, returned-tab]

requires:
  - "CockpitEvidencePresenter / VisitEvidence / VisitPhotoZipBuilder (Phase 46.1, zero-caller since 46.2-03)"
  - "ProjectCockpitController::resolveTab() single-fallback pattern (45-11)"
provides:
  - "ProjectCockpitController::TABS === ['overview','files','notes','returned'], conditional on evidenceFor()"
  - "resources/views/components/cockpit/returned-tab.blade.php — the calm-order render, re-surfaced"
  - "CockpitEvidencePresenter re-injected into ProjectCockpitController (no longer a zero-caller service)"
affects:
  - "resources/views/components/cockpit/panel.blade.php"
  - "resources/views/projects/cockpit.blade.php"
  - "tests/Feature/Cockpit/CockpitDocumentFormTest.php"
  - "tests/Feature/Cockpit/CockpitRamsWizardTest.php"
  - "tests/Feature/Cockpit/CockpitTabPreservationTest.php"
  - "tests/Feature/Cockpit/CockpitVisitActionsTest.php"

tech-stack:
  added: []
  patterns:
    - "Single fallback point for stale URL state: resolveTab($request, $offersReturned) takes the offer as a parameter rather than panel.blade.php carrying a second coercion"
    - "Controller derives $offersReturned FROM the same map ($panelEvidence !== []) it builds for the body, so the tab strip and the body can never disagree"
    - "Quote-bounded substring check (route.'\"') instead of a bare substring check, where one route's URI is a path-prefix of another's"

key-files:
  created:
    - resources/views/components/cockpit/returned-tab.blade.php
  modified:
    - app/Http/Controllers/ProjectCockpitController.php
    - resources/views/components/cockpit/panel.blade.php
    - resources/views/projects/cockpit.blade.php
    - tests/Feature/Cockpit/CockpitReadOnlyFenceTest.php
    - tests/Feature/Cockpit/CockpitPageTest.php
    - tests/Feature/Cockpit/CockpitReturnedTabTest.php
    - tests/Feature/Cockpit/CockpitDocumentFormTest.php
    - tests/Feature/Cockpit/CockpitRamsWizardTest.php
    - tests/Feature/Cockpit/CockpitTabPreservationTest.php
    - tests/Feature/Cockpit/CockpitVisitActionsTest.php

key-decisions:
  - "resources/views/projects/cockpit.blade.php added beyond the plan's frontmatter file list — :evidence and :offers-returned have to be threaded through this file to reach panel.blade.php, on the identical precedent 47-01 recorded for :link. Omitting this produced a real bug (see Deviations): the controller's $offersReturned/$panelEvidence never reached the Blade, so the tab strip silently stayed at three while the body rendered an empty 'returned' branch under the wrong tab's name."
  - "Four sibling test files outside this plan's frontmatter list required one-line fixes because they hardcoded DEFERRED_AFFORDANCES' total (21) or the 'returned' tab's absence — both facts this plan's own lift and D-04 directly change. See Deviations."
  - "Newest-first visit ordering inside returned-tab.blade.php: the plan's own parenthetical calls this 'mirroring the Visits card's own order', but Project::visits() orders ascending by scheduled_date (oldest first) — the two do NOT actually match. Followed the plan's explicit instruction ('newest first') rather than the inaccurate parenthetical, since the instruction is unambiguous and the parenthetical is descriptive gloss, not itself the rule."
  - "Gallery renders as a contact-sheet grid of <img> thumbnails inside <a target=_blank> anchors (reusing the pre-existing .cav-returned__sheet/.cav-returned__thumb CSS from Phase 46.1, never deleted), rather than a bare text link per photo. The CSS block's own comment ('THE CONTACT SHEET IS ONE UNIFORM GRID PER BUCKET') and the 46.2-03 retirement ledger's description of the deleted tests ('the contact sheet lazy loads and opens in a new tab') both point at this shape; the plan's own text ('a plain <a> per photo') is satisfied by the anchor, and the <img> inside it reuses the SAME authorised photo route rather than a second thumbnailer."

requirements-completed: [RV-01, RV-02, RV-03, RV-04, RV-06, RV-07]

duration: ~3h
completed: 2026-10-01
---

# Phase 47 Plan 03: The Returned tab — re-surfaced, conditional, calm Summary

**`ProjectCockpitController::TABS` grows to four, conditionally, re-injecting `CockpitEvidencePresenter` as a real caller for the first time since Phase 46.2 unsurfaced it — the tab renders the calm reference order (state → hand-off ZIP link → rooms → photo contact sheet → serials → sign-off), proven to read live, proven never to leak RV-03's three banned columns against realistically seeded values, and proven to still render a reconstructed visit's evidence with no review-state sentence and no control.**

## What this plan built

- `app/Http/Controllers/ProjectCockpitController.php`: `TABS` is now `['overview', 'files', 'notes', 'returned']`. `CockpitEvidencePresenter` is re-injected into the constructor, exactly where its docblock said it had sat before 46.2-03 removed it alongside its only caller. A new private `evidenceFor(Project, ?array $openModule): array` is the ONE call site — it builds a map keyed by visit id, skipping any visit for which the presenter answers null. `$offersReturned` is simply `$panelEvidence !== []`, so the tab's offer and its body are derived from the SAME map rather than two independent checks that could disagree. `resolveTab()` now takes `$offersReturned` as a second parameter and is the single point that coerces a stale `?tab=returned` bookmark on a module that doesn't offer it back to `TABS[0]` (Overview) — `panel.blade.php` carries no second coercion.
- `resources/views/components/cockpit/returned-tab.blade.php` (new): renders the presenter's payload, one block per qualifying visit, newest first. Per visit: (1) the review-state sentence — the SAME copy `visit-row.blade.php` renders for "Accepted by / Sent back / Awaiting the engineer", omitted for a reconstructed visit; (2) `source_missing` or `has_anything: false` each render ONE sentence and stop; otherwise (3) the hand-off ZIP link, rendered only when `photo_count > 0`; (4) per-room cards (survey-sourced) — name, notes, access notes, "N of M questions answered", each answered question with the engineer's own words for an `other` answer; (5) the photo contact sheet, grouped by `VisitEvidence::BUCKETS` order, each thumbnail a plain `<a target="_blank">` around an `<img>` that reuses the SAME authorised inline-photo route; (6) the serials list — room, device, serial, confirmed, captured date, never a thumbnail; (7) the sign-off block — client name, signed date, comments, the signature image. No visit-management control anywhere (Plan 47-04's job). Reuses the pre-existing `.cav-returned__*` CSS block Phase 46.1 wrote and 46.2-03 never deleted — this plan ships zero new CSS.
- `resources/views/components/cockpit/panel.blade.php`: `$tabs` grows a conditional `'returned' => 'Returned'` entry when `$offersReturned` is true; a new `@elseif ($tab === 'returned')` branch renders `<x-cockpit.returned-tab>`, passing the module's own `$visits` collection and the controller's `$evidence` map straight through.
- `resources/views/projects/cockpit.blade.php`: threads `:evidence="$panelEvidence"` and `:offers-returned="$offersReturned"` to `<x-cockpit.panel>` — see Deviations.
- `tests/Feature/Cockpit/CockpitReadOnlyFenceTest.php`: `'Download'` removed from `DEFERRED_AFFORDANCES` (21 → 20), cited to this plan and RV-04, with the lift proven to bite (not merely absent-by-removal) via `populatedProject()`'s real returned photo. The POST/GET unsurfacing test is renamed and split: the five visit-disclosure POSTs are still proven absent from every judged region; the two evidence GETs (`photos-zip`, `photo`) are now proven PRESENT on the Returned tab and ABSENT from Overview, for the same visit. A new test proves the GET row-count invariance for the new caller path — opening `?tab=returned` and following its ZIP link moves none of the 13 `WRITE_SURFACE_TABLES` rows.
- `tests/Feature/Cockpit/CockpitPageTest.php`: the `ProjectCockpitController::TABS` count pin moves 3 → 4; a new test proves a module WITH a returnable visit draws all four tab labels including `Returned`, while the pre-existing fixture (whose visits carry no `source_type`, the `VisitFactory` default) keeps its three-anchor assertion — both halves of the conditional, each proven on its own fixture.
- `tests/Feature/Cockpit/CockpitReturnedTabTest.php`: rebuilt, not restored from history. 19 tests prove: the constant is `['overview','files','notes','returned']`; the tab is absent for a module with no visits, with only a sourceless visit, or (unchanged) for RAMS/O&M; the richest fixture this file can build (worksheet photo + serial + sign-off) DOES draw the fourth tab; a stale `?tab=returned` bookmark on a non-offering module falls back to Overview and is never echoed; opening the tab on an offering module shows its real body; the tab strip stays anchors-only with no `aria-expanded`; opening every tab on every module moves no row across 11 tables; evidence reads live (edit a room note between two calls, see the second call change); RV-03's three columns are seeded with realistic values and proven absent from a REAL Returned-tab render (not merely a tab that fell back to Overview, which is the vacuity the old, retired version of this exact test was exposed to the moment TABS grew); the calm order is exercised for both a worksheet-sourced visit (after/label buckets, serial, sign-off) and a survey-sourced one (before bucket, room notes, answered/unanswered questions); `has_anything: false` and `source_missing: true` each render their one sentence and nothing else; a reconstructed visit's evidence renders with no review-state sentence and no control; and the new copy strings collide with none of the remaining 20 `DEFERRED_AFFORDANCES` entries or either `FORBIDDEN_MARKUP` entry.

## Measured results (all foreground, redirected, one suite per invocation)

- `gate-46.ps1 -Filter CockpitReturnedTabTest` — **19 passed, 0 failed, 334 assertions.**
- `gate-46.ps1 -Path tests/Feature/Cockpit` — **441 passed, 0 failed, 8708 assertions** (was 430/0/8180 after Plan 47-01; +11 tests, +528 assertions — the new `CockpitPageTest` fixture, the row-count-invariance test, and the rebuilt `CockpitReturnedTabTest`'s growth from 19 retired/replaced tests to 19 rebuilt ones with a materially larger body).
- `gate-46.ps1 -Path tests/Unit/Cockpit` — **114 passed, 0 failed, 1203 assertions** — unedited by this plan, re-run for confirmation; `CockpitEvidencePresenter`/`VisitEvidence` are untouched.
- `gate-46.ps1 -Baseline` — **159 passed, 2 skipped (pre-existing, missing ext-imagick), 0 failed** — meets `>= 159 passed AND 0 failed`.
- `gate-46.ps1 -Hashes` — all three protected files (`layouts/app.blade.php`, `resources/css/app.css`, `tailwind.config.js`) confirmed **byte-identical** to the pinned `4abd2b24` hashes.

## Fence counts, with the control each lift ships

| Count | Before (post-47-01) | After | Control |
|---|---|---|---|
| `DEFERRED_AFFORDANCES` | 21 | **20** | `'Download'` lifted — the Returned tab's hand-off link (`photos-zip`), proven to render in `populatedProject()`'s judged region, not merely absent by removal |
| `FORBIDDEN_MARKUP` | 2 | 2 | unchanged — no new form control type; the hand-off is an anchor |
| `BANNED_HANDLER_ATTRIBUTES` | 9 | 9 | unchanged — no JavaScript added |
| `WRITE_SURFACE_TABLES` | 13 | 13 | unchanged — no new table read; re-proved GET-inert for the ZIP follow specifically |
| `ProjectCockpitController::TABS` | 3 | **4** | `'returned'` appended, conditional on `evidenceFor()`'s own map being non-empty |

The lift and the count moves are earned the same way 46.1-04 and 46-04 earned theirs: `populatedProject()` carries a real `WorksheetPhoto` row behind a real visit, so the judged region genuinely renders "Download all photos (ZIP)" — the lift is paid for by evidence, not by assertion.

## How each lift was proven earned

- **`'Download'` (21 → 20):** `CockpitReadOnlyFenceTest::test_none_of_the_deferred_affordances_appears()` now also asserts that at least one of `everyRegion()`'s judged regions contains the literal string "Download all photos (ZIP)" — the same non-vacuity proof 46.1-04 took the first time this entry was lifted.
- **`TABS` (3 → 4):** `CockpitPageTest::test_a_module_with_a_returnable_visit_offers_a_fourth_returned_tab()` builds a fresh worksheet visit with a real photo and asserts the strip draws all four labels including `Returned`; the sibling `test_the_panel_renders_its_header_tab_strip_and_close_link()` keeps asserting three anchors on its own fixture (no resolvable source) — both states rendered and asserted separately, neither inferred from the other.

## How the four banned columns were proven to stay unrendered

`device_label_photos.captured_by` (`'ip:203.0.113.9|actor:deadbeef'`), `worksheet_signoffs.ip_address` (`'198.51.100.77'`) and `worksheet_signoffs.user_agent` (a realistic iPhone UA string) are seeded via `CockpitReturnedTabTest::worksheetVisit()`/`labelPhoto()`/`signoff()`. `test_no_capture_address_or_client_agent_is_ever_rendered()` first asserts via `assertDatabaseHas()` that the seeded values are genuinely in the database, then renders EVERY tab (`ProjectCockpitController::TABS`, now including `returned`) and asserts the concatenated raw output never contains any of eight secret-shaped strings — and, newly, asserts the Returned tab actually rendered real evidence (`'Download all photos (ZIP)'` present) so the secrets check cannot pass merely because the tab fell back to Overview. The fourth column from this phase's own standing concern — `pre_install_confirmations.room_complete.{room}.completed_by` — belongs to Plan 47-02 (already shipped, `F-46.7-04-01`) and `CockpitEvidencePresenter`/`VisitEvidence` never read `pre_install_confirmations` at all, so there was no fourth leak surface for this plan to introduce.

## How the ZIP was proven to write nothing

`CockpitReadOnlyFenceTest::test_opening_the_returned_tab_and_following_its_handoff_link_writes_nothing()`: snapshots all 13 `WRITE_SURFACE_TABLES` row counts, renders the Returned tab, asserts the hand-off link is actually present (non-vacuity — a real follow, not a built URL), performs a real `GET` on it, then re-counts every table and asserts every count is unchanged. This is in addition to (not a replacement for) `CockpitPageTest`'s existing `assertSame(3, $gets)` route-table proof that no new route was registered, and `CockpitReadOnlyFenceTest::test_opening_a_panel_writes_nothing()`, which already iterates `TABS` (now 4 entries) and so picked up a bare Returned-tab render's inertness automatically.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — blocking issue] `resources/views/projects/cockpit.blade.php` had to thread two new props through to reach `panel.blade.php`**

- **Found during:** first test run of `CockpitReturnedTabTest` (10 of 19 failing).
- **Issue:** the plan's frontmatter file list did not include `cockpit.blade.php`. Without editing it, `panel.blade.php`'s `evidence`/`offersReturned` props silently kept their `[]`/`false` defaults regardless of what the controller computed — the controller's OWN `$offersReturned` (used by `resolveTab()`) was correctly `true`, so `$tab` correctly resolved to `'returned'`, but the Blade-side `$offersReturned` (a SEPARATE, unwired prop) stayed `false`, so the tab strip never grew a fourth anchor while the `@elseif ($tab === 'returned')` body branch rendered anyway — an empty "Nothing has come back from site yet." under a three-anchor strip with no active tab marked. Exactly the kind of two-places-that-can-disagree bug the plan's own single-fallback design was meant to prevent, arriving through a different gap.
- **Fix:** added `:evidence="$panelEvidence"` and `:offers-returned="$offersReturned"` to the existing `<x-cockpit.panel>` call in `cockpit.blade.php`, on the identical precedent Plan 47-01 recorded for `:link`.
- **Files modified:** `resources/views/projects/cockpit.blade.php`.
- **Commit:** `5ab6f81f`.

**2. [Rule 1 — bug, real method vs. plan's named one] `Visit::source()` uses `withTrashed()`, so a plain soft `delete()` does not produce `source_missing: true`**

- **Found during:** writing `CockpitReturnedTabTest::test_a_visit_whose_source_was_force_deleted_says_so()`.
- **Issue:** my first draft called `$worksheet->delete()` (a soft delete) expecting `source_missing` to read true. `Visit::source()`'s own docblock states it deliberately uses `withTrashed()`, so a soft-deleted source still resolves; only a genuine `forceDelete()` produces `source === null`. `CockpitEvidencePresenterTest::test_a_force_deleted_source_still_resolves_and_reports_itself_missing()` already established this with `forceDelete()`.
- **Fix:** changed the test fixture to `forceDelete()`.
- **Files modified:** `tests/Feature/Cockpit/CockpitReturnedTabTest.php` (within the same file this plan already modifies — not a new deviation file).

**3. [Rule 1 — bug, substring collision] The unsurfaced-write-routes check could false-positive once the ZIP/photo GETs started legitimately rendering**

- **Found during:** the first full `tests/Feature/Cockpit` run after Task 3 (5 failures).
- **Issue:** `route('projects.cockpit.visits.store', $project)` resolves to a URI with NO trailing segment (`cockpit/visits`, a plain REST "store" POST to the collection). A bare `assertStringNotContainsString($url, $region)` check therefore also matched the Returned tab's OWN legitimate `cockpit/visits/4/photos.zip` href, because the store URL is a literal string-prefix of it — a check meant to prove a write route is unlinked was tripped by a read route it happens to share a path prefix with.
- **Fix:** changed the check to assert absence of `$url.'"'` (the URL immediately followed by the closing quote an `href`/`action` attribute would carry), which disambiguates "this exact route" from "a route nested under the same path". Removed a now-incorrect "belt and braces" segment check that asserted a `/visits/store` path shape that does not match the real route at all.
- **Files modified:** `tests/Feature/Cockpit/CockpitReadOnlyFenceTest.php` (within this plan's own file list).

**4. [Rule 1 — bug, guard-test trap] A prose comment literally contained the unescaped-output token and tripped `CockpitPanelTest::test_no_cockpit_view_uses_unescaped_output()`**

- **Found during:** the same full-suite run.
- **Issue:** `returned-tab.blade.php`'s own docblock said "Nothing here uses `{!! !!}`" — and that test greps every cockpit view's raw file contents for the literal `{!!` substring, with no exception for a comment. Exactly the trap this plan's own standing constraints warned about, caught by the guard it warned about.
- **Fix:** reworded the sentence to "Nothing here uses the unescaped-output directive" — same claim, no literal token.
- **Files modified:** `resources/views/components/cockpit/returned-tab.blade.php` (within this plan's own file list).

**5. [Rule 1 — bug, pinned counts in sibling files outside this plan's frontmatter] Four other test files hardcoded facts this plan's own change overturns**

- **Found during:** the same full-suite run (3 of the 5 failures).
- **Issue:** `CockpitDocumentFormTest::test_the_closed_control_copy_collides_with_no_fence_entry()` and `::test_the_wizard_copy_collides_with_no_fence_entry()` both pinned `assertCount(21, $deferred)`; `CockpitRamsWizardTest::test_the_rams_copy_collides_with_no_fence_entry()` pinned `assertCount(23, $banned)` (21 deferred + 2 forbidden-markup). All three went red the moment `'Download'` left `DEFERRED_AFFORDANCES`. Separately, `CockpitTabPreservationTest::test_every_real_tab_is_carried_and_only_a_real_tab_is()` asserted `'returned'` must be REJECTED as a tab value — true under 46.2 D-02, false now that D-04 restores it (that test's own first loop, iterating `TABS` directly, already covers the now-legal value correctly).
- **Fix:** moved both `21`s to `20`; moved `23` to `22`; removed `'returned'` from the rejection list (keeping `'RETURNED'` and `'returned"'`, which are genuinely still illegal). `CockpitVisitActionsTest.php` carried a stale docblock reference to the fence test this plan renamed — corrected the name, no assertion changed.
- **Files modified:** `tests/Feature/Cockpit/CockpitDocumentFormTest.php`, `tests/Feature/Cockpit/CockpitRamsWizardTest.php`, `tests/Feature/Cockpit/CockpitTabPreservationTest.php`, `tests/Feature/Cockpit/CockpitVisitActionsTest.php` — all four OUTSIDE this plan's frontmatter `files_modified` list.
- **Commit:** `bf3cc692` (separate commit, named as a deviation).

## Task Commits

1. **Task 1: TABS grows to four, conditionally, and the evidence is wired back in** — `5ab6f81f` (includes the `cockpit.blade.php` plumbing fix, Deviation 1)
2. **Task 2: The calm order — hand-off link, rooms, gallery, sign-off, no controls** — `e026b945`
3. **Task 3: Lift 'Download' by name, move the pinned tab counts, and the ZIP's row-count proof** — `0a49b315`
4. **Deviation fix: sibling pinned fence counts** — `bf3cc692`

_No plan metadata commit made per this plan's constraints (`.planning/STATE.md` is owned by the orchestrator; `.planning/ROADMAP.md` is owned by Plan 47-05)._

## Not delivered / scope notes

Nothing in the plan's task list was skipped. Scope was held exactly as written: no visit-management control renders on this tab (Plan 47-04's job); `VisitEvidence`, `CockpitEvidencePresenter` and `VisitPhotoZipBuilder` were read, never edited; no migration was written or needed; `cockpit.css` was NOT touched (the pre-existing `.cav-returned__*` block, never deleted by 46.2-03, covers every class this plan's new Blade uses) — so no `npm run build` is required for this plan's own changes. RV-08 ("is the tab calm?") is NOT answered here — that is Plan 47-05's blocking checkpoint, and this plan's job was only to build the calm order by structure, which it did.

## Self-Check: PASSED

- `app/Http/Controllers/ProjectCockpitController.php` — FOUND, modified as described.
- `resources/views/components/cockpit/returned-tab.blade.php` — FOUND, created as described.
- `resources/views/components/cockpit/panel.blade.php` — FOUND, modified as described.
- `resources/views/projects/cockpit.blade.php` — FOUND, modified as described (Deviation 1).
- `tests/Feature/Cockpit/CockpitReadOnlyFenceTest.php`, `CockpitPageTest.php`, `CockpitReturnedTabTest.php` — FOUND, modified as described.
- `tests/Feature/Cockpit/CockpitDocumentFormTest.php`, `CockpitRamsWizardTest.php`, `CockpitTabPreservationTest.php`, `CockpitVisitActionsTest.php` — FOUND, modified as described (Deviation 5).
- Commit `5ab6f81f` — FOUND in `git log --oneline`.
- Commit `e026b945` — FOUND in `git log --oneline`.
- Commit `0a49b315` — FOUND in `git log --oneline`.
- Commit `bf3cc692` — FOUND in `git log --oneline`.
