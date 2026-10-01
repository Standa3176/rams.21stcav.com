---
phase: 47-cockpit-links-and-returns
plan: 01
subsystem: cockpit
tags: [laravel, blade, engineer-link, worksheets, site-surveys, read-only-fence]

requires: []
provides:
  - "CockpitLinkPresenter::linkFor() — the current engineer link and its state for site_survey/worksheet, read-only"
  - "link-card.blade.php — the Overview-tab card: link text, state sentence, and (worksheet only) the revoke form"
  - "The worksheet revoke reachable from the cockpit, posting to the existing worksheets.revoke-token route"
affects:
  - "resources/views/projects/cockpit.blade.php"
  - "app/Http/Controllers/ProjectCockpitController.php"

tech-stack:
  added: []
  patterns:
    - "Read-only presenter dispatching on resolved model TYPE (instanceof), never on $moduleKey — mirrors VisitEvidence::for()'s own dispatch"
    - "'Render nothing' early-return Blade component pattern (@if ($link === null) @php return; @endphp @endif), same house pattern used elsewhere on this page"

key-files:
  created:
    - app/Support/Cockpit/CockpitLinkPresenter.php
    - resources/views/components/cockpit/link-card.blade.php
  modified:
    - app/Http/Controllers/ProjectCockpitController.php
    - resources/views/components/cockpit/panel.blade.php
    - resources/views/projects/cockpit.blade.php
    - tests/Feature/Cockpit/CockpitReadOnlyFenceTest.php
    - tests/Feature/Cockpit/CockpitLinkCardTest.php
    - tests/Feature/Cockpit/CockpitCreateVisitTest.php
    - tests/Feature/Cockpit/CockpitReturnedTabEndToEndTest.php

key-decisions:
  - "resources/views/projects/cockpit.blade.php added to files_modified beyond the plan's frontmatter list — the :link prop has to be threaded through this file to reach panel.blade.php; this is plumbing the plan's own action step describes ('pass it to the view') and not a scope expansion."
  - "Two pre-existing tests predating this plan (CockpitCreateVisitTest::test_no_access_token_appears_in_any_form_field, CockpitReturnedTabEndToEndTest::test_a_reconstructed_visit_offers_nothing_and_its_archive_still_opens) encoded the OLD rule that the engineer link is never rendered on the cockpit at all. That rule is exactly what this plan's D-01 overturns. Both narrowed to their real, still-true security claims (token never inside a <form>; no VISIT act control) rather than deleted. See Deviations below."
  - "Worksheet::isAccessTokenExpired() does not exist on the real model — the actual method is isTokenExpired(). Used the real name; the plan's <interfaces> section names a method that doesn't exist on this codebase."

requirements-completed: [LNK-01, LNK-02, LNK-03, LNK-04]

duration: ~90min
completed: 2026-10-01
---

# Phase 47 Plan 01: The engineer link, visible and copyable on every ordinary view Summary

**The cockpit now shows the current site-survey/worksheet engineer link as plain, selectable text on the Overview tab whenever one exists, states its state in words, and lets a worksheet's link be revoked and reissued — not only on the narrow collision-refusal flash that shipped hours earlier as quick task 260930-qcy.**

## What was already shipped vs. what this plan built

Quick task 260930-qcy (same day, ahead of this plan) already shipped:
- The collision-refusal flash on `doc-form.blade.php` (`session('cockpit_existing_link')`) — renders the link ONLY when a creation attempt just collided with an existing live survey.
- The site survey's "Start a fresh survey" supersede form.
- `intent=regenerate-document` on `CockpitDocumentRequest::INTENTS`.
- The fence's judged-form count already at 5 (not 4) from the supersede form.

This plan built the part that fix did not reach: an **always-visible link card on the Overview tab**, independent of any collision, for both site_survey and worksheet, plus the worksheet's working revoke control. Nothing from 260930-qcy was duplicated or rebuilt.

## Performance

- **Duration:** ~90 min
- **Tasks:** 3 completed (CockpitLinkPresenter; the link card wired in; the fence moved + revoke walked through HTTP)
- **Files modified:** 8 (2 created, 6 modified)

## Accomplishments

- `app/Support/Cockpit/CockpitLinkPresenter.php` — `linkFor(Project, string): ?array` resolves the current link for `site_survey` (via `VisitLinkIssuer::liveSurveyFor()`, the same adoption rule a new visit uses) and `worksheet` (most-recent-created, mirroring `CockpitPanelPresenter::files()`'s own convention). Returns `null` for rams/om (derived from `VisitLinkIssuer::moduleKeys()`, never a hand-written list) and for a module with no document yet. State derivation dispatches on resolved model TYPE, never on `$moduleKey`. Proven to write nothing over `visits`, `site_surveys`, `worksheets`, `worksheet_signoffs`, `project_activity_logs`.
- `resources/views/components/cockpit/link-card.blade.php` — renders on the Overview tab, above the Visits card: the URL as escaped `<a>` text, the state sentence, and — worksheet only — a form posting to the already-registered `worksheets.revoke-token` (no new route) with the submit "Revoke and reissue". Site survey renders one sentence stating no revoke exists, never a disabled control. Renders nothing for rams/om or a module with no document yet.
- Wired through `ProjectCockpitController::show()` (constructor-injected `CockpitLinkPresenter`, `$panelLink` derived read-only, passed via `panel.blade.php`'s new `:link` prop) and `resources/views/projects/cockpit.blade.php`.
- `CockpitReadOnlyFenceTest`'s judged-form count moved **5 → 6**, by name, citing the worksheet revoke form. `FORBIDDEN_MARKUP` (2), `BANNED_HANDLER_ATTRIBUTES` (9) and `WRITE_SURFACE_TABLES` (13) are unchanged and re-proved green in the same run.
- `CockpitLinkCardTest` (new, 16 tests / 253 assertions): presenter unit coverage, rendered-card coverage for all four states (survey-with-link, worksheet-with-link-and-revoke, no-document, rams/om), the fence-collision check for the new copy, a DOM-scoped no-`<select>`/`<script>`/handler-attribute check, and the revoke walked through real HTTP (old token gone, new different token shown, `worksheets` row count unchanged — one row updated, not a second created).

## Measured results (all foreground, redirected, one suite per invocation)

- `gate-46.ps1 -Filter CockpitLinkCardTest` — **16 passed, 0 failed, 253 assertions.**
- `gate-46.ps1 -Path tests/Feature/Cockpit` — **430 passed, 0 failed, 8180 assertions.**
- `gate-46.ps1 -Baseline` — **159 passed, 2 skipped (pre-existing, missing ext-imagick), 0 failed** — meets `>= 159 passed AND 0 failed`.
- `gate-46.ps1 -Hashes` — all three protected files (`layouts/app.blade.php`, `resources/css/app.css`, `tailwind.config.js`) confirmed **byte-identical** to the pinned `4abd2b24` hashes.
- `gate-46.ps1 -Filter CockpitPageTest` — **45 passed, 0 failed** — `assertSame(6, $writes)` / `assertSame(3, $gets)` unchanged; no new route added.

## Fence counts, with the control each lift ships

| Count | Before | After | Control |
|---|---|---|---|
| Judged document-form count | 5 | **6** | The worksheet's revoke form (`link-card.blade.php`, posts to existing `worksheets.revoke-token`) |
| `FORBIDDEN_MARKUP` | 2 | 2 | unchanged — no new form control type |
| `DEFERRED_AFFORDANCES` | 21 | 21 | unchanged — new copy checked as a substring against all 21 keys, asserted for real in `CockpitLinkCardTest::test_the_new_copy_collides_with_no_fence_entry()` |
| `BANNED_HANDLER_ATTRIBUTES` | 9 | 9 | unchanged — disclosure is the existing `?module=` GET, no directive |
| `WRITE_SURFACE_TABLES` | 13 | 13 | unchanged — revoke writes to `worksheets`, already named since Plan 46-04 |

The judged-form count's rise is earned: `CockpitLinkCardTest` and `CockpitReadOnlyFenceTest`'s own fixture (`populatedProject()`, which already carries a real `Worksheet` row) both exercise a project where the worksheet module genuinely holds a document, so `CockpitLinkPresenter::linkFor()` resolves `can_revoke => true` and the revoke form actually renders in the region the fence judges — not a count raised for a form that never appears.

## The fourth truth — proven non-vacuously

`test_opening_site_survey_with_a_live_survey_shows_the_link_above_visits` renders a project with a real, live `SiteSurvey` and asserts BOTH halves: the sentence "There is no way to revoke a survey link. Superseding this survey below starts a fresh one instead." is present, AND no `<form>` posting a revoke exists for that module (`test_opening_worksheet_with_a_worksheet_shows_the_link_and_a_working_revoke_form` proves the inverse — the worksheet module DOES render the control). Both states — link-with-revoke, link-without-revoke — and the no-document and rams/om null states are each rendered and asserted separately; none is inferred from another.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — stale assumption contradicted by this plan's own explicit objective] Narrowed two pre-existing tests that assumed the engineer link was never rendered anywhere on the cockpit**

- **Found during:** Task 2, running the full `tests/Feature/Cockpit` suite.
- **Issue:** `CockpitCreateVisitTest::test_no_access_token_appears_in_any_form_field` (T-46-04-04) asserted the raw access token never appears ANYWHERE in the `.cav-cockpit` region. `CockpitReturnedTabEndToEndTest::test_a_reconstructed_visit_offers_nothing_and_its_archive_still_opens` asserted exactly zero `<button` elements ever render for a reconstructed visit's module. Both predate this plan and encoded "the link is never shown at all" — which is precisely the rule 47-01's D-01 exists to overturn ("the engineer link must be VISIBLE and COPYABLE... not only on the narrow collision-refusal path").
- **Fix:** Narrowed each assertion to the real, still-true security/behaviour claim it was actually protecting: the token is now checked ONLY inside `<form>` elements (T-46-04-04's real concern — "never travels through a form" — is unchanged, since the revoke form is keyed by the worksheet's id via default route-model binding, never by its token); the button count moved 0 → 1, named as the revoke control (a document-link affordance, not a VISIT act), with the four banned visit-act strings (`Accept`, `Send back`, `Add note`, `Raise a snag`) still asserted absent.
- **Files modified:** `tests/Feature/Cockpit/CockpitCreateVisitTest.php`, `tests/Feature/Cockpit/CockpitReturnedTabEndToEndTest.php`.
- **Commit:** `b1dc1afa`.

**2. [Rule 1 — plan's `<interfaces>` named a method that doesn't exist] `Worksheet::isAccessTokenExpired()` is actually `isTokenExpired()`**

- **Found during:** Task 1, writing `CockpitLinkPresenter`.
- **Issue:** The plan's `<interfaces>` block documents `Worksheet::isAccessTokenExpired(): bool`. The real model has no such method; the equivalent is `isTokenExpired()`, which reads `access_token_expires_at`.
- **Fix:** Used the real method name. No behavioural difference — same column, same semantics.
- **Files modified:** `app/Support/Cockpit/CockpitLinkPresenter.php`.
- **Commit:** `8d4d8a36`.

## Task Commits

1. **Task 1: CockpitLinkPresenter — the link and its state, read-only** — `8d4d8a36`
2. **Task 2: The link card — visible text, state, and the worksheet's revoke** — `b1dc1afa`
3. **Task 3: The fence, moved by name, and the revoke walked through HTTP** — `9d8020eb`

_No plan metadata commit made per this plan's constraints (STATE.md/ROADMAP.md are owned by the orchestrator / plan 47-05)._

## Files Created/Modified

- `app/Support/Cockpit/CockpitLinkPresenter.php` (new) — `linkFor()`, pure read, mints no token.
- `resources/views/components/cockpit/link-card.blade.php` (new) — the Overview-tab card.
- `app/Http/Controllers/ProjectCockpitController.php` — constructor-injects `CockpitLinkPresenter`; derives `$panelLink`.
- `resources/views/components/cockpit/panel.blade.php` — new `link` prop (default `null`); renders `<x-cockpit.link-card>` above the Visits card on Overview.
- `resources/views/projects/cockpit.blade.php` — threads `:link="$panelLink"` through to `<x-cockpit.panel>`.
- `tests/Feature/Cockpit/CockpitLinkCardTest.php` (new) — 16 tests across all three tasks.
- `tests/Feature/Cockpit/CockpitReadOnlyFenceTest.php` — judged-form count 5 → 6, by name; historical commentary block extended.
- `tests/Feature/Cockpit/CockpitCreateVisitTest.php`, `tests/Feature/Cockpit/CockpitReturnedTabEndToEndTest.php` — narrowed per Deviation 1 above.

## Self-Check: PASSED

- `app/Support/Cockpit/CockpitLinkPresenter.php` — FOUND.
- `resources/views/components/cockpit/link-card.blade.php` — FOUND.
- Commit `8d4d8a36` — FOUND in `git log --oneline`.
- Commit `b1dc1afa` — FOUND in `git log --oneline`.
- Commit `9d8020eb` — FOUND in `git log --oneline`.
