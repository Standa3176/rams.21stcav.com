---
phase: 260525-pyu
plan: 01
subsystem: authorization
tags: [authorization, shared-workspace, policies, rbac]
requires: []
provides:
  - "Shared-workspace authorization: any authenticated user has full access to all in-scope documents/projects"
  - "SharedWorkspaceAccessTest regression suite (6 cases incl. the exact prod-bug repro)"
affects:
  - app/Policies/*
  - app/Http/Controllers/{Rams,OmManual,Worksheet,CableSchedule,SiteSurvey,Project,QuoteImport,ProjectPackageReview,DocumentEdit}Controller.php
tech-stack:
  added: []
  patterns:
    - "abort_unless(auth()->check(), 403) + // Shared workspace comment (matches existing D-15/D-19 lineage)"
    - "Policies return true (auth middleware guarantees a logged-in user at every call site)"
key-files:
  created:
    - tests/Feature/Authorization/SharedWorkspaceAccessTest.php
  modified:
    - app/Policies/RamsDocumentPolicy.php
    - app/Policies/OmManualPolicy.php
    - app/Policies/ProjectPolicy.php
    - app/Policies/ProjectDrawingPolicy.php
    - app/Http/Controllers/RamsController.php
    - app/Http/Controllers/OmManualController.php
    - app/Http/Controllers/WorksheetController.php
    - app/Http/Controllers/CableScheduleController.php
    - app/Http/Controllers/SiteSurveyController.php
    - app/Http/Controllers/ProjectController.php
    - app/Http/Controllers/QuoteImportController.php
    - app/Http/Controllers/ProjectPackageReviewController.php
    - app/Http/Controllers/DocumentEditController.php
decisions:
  - "Authorization-only: zero changes to rendering/storage/routes/migrations/AI. RamsRenderRegression byte-equivalence canary stays GREEN."
  - "Project::scopeForUser left in place (now unused after un-scoping its only call sites) — not deleted, may be referenced in future."
  - "Field-operations cluster (Commissioning/InstallProgramme/Task*/TimeEntry) deliberately NOT relaxed — owner-OR-admin-OR-assigned-engineer model preserved."
metrics:
  duration: ~42m
  tasks: 5
  files: 14 (13 modified + 1 created)
  completed: 2026-05-25
---

# Quick Task 260525-pyu: Relax Authorization to Shared Workspace — Summary

Relaxed authorization so ANY authenticated user (incl. `role=user`, non-owner) has full access to every in-scope Projects function and document — the intended shared workspace for the 3-person company. Fixes the production bug where non-admin staff (zack, alison) got HTTP 403 downloading RAMS PDF/DOCX owned by user 1 (sonny). Genuinely administrative endpoints (restore, forceDestroy, admin panel) stay admin-only; guests are still redirected to login. Authorization-only — document rendering output is byte-identical.

## What Changed

### Task 1 — Policies relaxed (commit `e82c725`)
All 12 methods across `RamsDocumentPolicy`, `OmManualPolicy`, `ProjectPolicy`, `ProjectDrawingPolicy` (`view`/`update`/`delete`) now `return true`. These are only reached behind `auth` middleware, so `return true` == "any authenticated user". Signatures/imports untouched; docblocks updated to reflect shared access. AppServiceProvider untouched.

### Task 2 — Inline ownership gates neutralised (commit `4dae426`)
Every in-scope owner-OR-admin / owner-only inline gate replaced with `abort_unless(auth()->check(), 403); // Shared workspace`. Private helpers (`authorizeProject`, `authorizeSurvey`, `authorizePackage` ×2) relaxed once each to cover all call sites. `DocumentEditController::authorizeDocument` dropped the `document_forbidden` 403 branch (and the now-unused `$isAdmin`); the 401-unauthenticated and 404-document-not-found branches are preserved (`ownerIdFor()` kept solely for the 404 path). QuoteImport reassignment lookup dropped its `->where('user_id', auth()->id())` so any authed user can reassign a package to any project. All admin-only `isAdmin()` gates preserved verbatim.

### Task 3 — Listings/dropdowns un-scoped (commit `9fffc8a`)
RAMS/O&M/Worksheet/CableSchedule/SiteSurvey `index` now list ALL records for every authenticated user (no `where('user_id', …)`); O&M-create + QuoteImport project dropdowns and O&M/SiteSurvey project lookups list all projects. Admin-only `show_deleted` / `onlyTrashed` branches preserved. `ProjectController::index` was already un-scoped (D-15) — left as-is.

### Task 4 — Tests flipped + regression suite added (commit `6b9d0b6`)
8 owner-403 tests flipped to assert shared access (Drawings bound-pdf/zip/rack-editor/flip-rack, DocumentEdits change-set/parse/hardening); O&M index and edit/download tests flipped to shared behaviour; ActualHours visibility test flipped (plus the coupled `ProjectController::show` gate relaxed to `auth()->check()`). New `SharedWorkspaceAccessTest` (6 cases, RefreshDatabase, H-07 `Storage::fake('documents')` + `DocumentArtifactStorage::writePath` for the downloadable artifact):
1. non-admin non-owner downloads RAMS DOCX (200) + PDF (not 403) — **exact prod-bug repro**
2. non-admin non-owner views + deletes a RAMS
3. non-admin non-owner reaches O&M edit/download, Worksheet show, CableSchedule edit, SiteSurvey show
4. listings show all records to any authenticated user
5. admin-only `projects.restore` / `projects.force-destroy` still 403 for `role=user`
6. guest → redirect to login

### Task 5 — Regression gate
Full suite: **1311 passed, 12 failed, 8 skipped (5094 assertions)**. All 12 failures are PRE-EXISTING (verified red on HEAD with changes stashed) and unrelated to authorization — see "Pre-existing failures" below. Critical guards GREEN: **RamsRenderRegression byte-equivalence 3/3** (manual-form / quote-import / survey-derived fixtures byte-identical), Phase22_1InvariantGuard SC1-6, DeadPathRemovalGuard, ScopeConsolidationGuard, ReviewedDataStructuralDiff, V13 surfaces. Grep sanity: zero owner-`user_id` authorization gates or owner-scoped listing filters remain in the nine in-scope controllers; all `isAdmin()` admin gates intact.

## Test Results (honest counts)

- **Full suite:** 1311 passed / 12 failed / 8 skipped — 5094 assertions (241.9s).
- **Task 4 filtered set (74 tests):** all new/flipped shared-access tests pass; the only failures in this set are the 6 pre-existing `projects.show`-view failures.
- **SharedWorkspaceAccessTest:** 6/6 green, including the production-bug repro and the preserved admin-only 403s + guest-login redirect.
- **RamsRenderRegression:** 3/3 byte-equivalence green — authorization change did NOT alter rendering output.

### Pre-existing failures (NOT caused by this change — all verified red on HEAD)

| Test | Area | Cause |
|------|------|-------|
| ActualHoursWidget: owner/admin/empty-state/by-category/excludes-open (5) | `projects.show` view | Widget text ("Actual Hours", "No time tracked yet") not rendering — pre-existing broken Blade section |
| OmManualProjectLinkage: project_show_page_always_renders_om_section | `projects.show` view | "O&M Manuals" section text not rendering — same pre-existing view issue |
| SolutionType: types_returns_all_four | admin/SolutionType | Pre-existing on HEAD |
| PublicWorksheetSignoff: sign_persists / resubmit_appends (2) | public token flow (OUT OF SCOPE) | Pre-existing on HEAD |
| WorksheetTaggedParser: qtvend variant / deduplicates (2) | worksheet-classifier WIP (this branch's in-progress work) | Pre-existing on HEAD |
| WorkerMonitor: unhealthy_queue_runs_restart_and_drain_plan (1) | WorkerMonitor | Flaky test-ordering; PASSES in isolation both with my changes and on HEAD |

## Deviations from Plan

### [Rule 3 - Test mechanics] ActualHours flipped test rewritten to assert authorization parity
The plan directed flipping `test_non_owner_non_admin_does_not_see_widget` to assert the stranger SEES the "Actual Hours" widget. But that widget text does NOT render for ANY user (owner or admin) on HEAD — the underlying `projects.show` widget section is pre-existing-broken (verified: owner + admin widget tests are red on HEAD). Asserting the broken widget text would be a guaranteed fail. Instead the flipped test (`test_any_authenticated_user_sees_actual_hours_widget`) proves the AUTHORIZATION relaxation directly: the stranger gets the SAME outcome as the owner (200, not 403). The coupled `ProjectController::show $canSeeActualHours = auth()->check()` edit was made exactly as the plan specified. Rationale: authorization-only scope forbids touching the `projects.show` rendering to "fix" the pre-existing widget bug.

### [Rule 3 - Test mechanics] SharedWorkspaceAccessTest used getStatusCode() not status()
`TestResponse::status()` is undefined for `BinaryFileResponse` (download endpoints); switched the non-403 assertions to `->getStatusCode()`. The headline DOCX download asserts a hard `assertOk()`. PDF download asserts "not 403" rather than 200 because PDF rendering depends on a headless-browser binary that may be absent in CI (absence surfaces as a redirect-back-with-error, never a 403).

### [Scope decision] Pint not auto-fixed across controllers/tests
The plan's verification runs `vendor\bin\pint --test app/Http/Controllers` (and `tests/Feature/Authorization`) expecting clean. The repo's `app/Http/Controllers`, `app/Policies` neighbours, and `tests/` were NEVER Pint-clean on HEAD (verified: the unmodified HEAD `RamsController.php` and `DocumentArtifactStorageTest.php` both fail Pint with the same fixer sets — `line_ending`, `ordered_imports`, `binary_operator_spaces`, etc., driven by the repo's CRLF convention vs Pint's LF default). Running Pint auto-fix would have rewritten ~1000 lines of unrelated formatting across files I touched plus diverged my files' line endings from every neighbouring file. Per the scope boundary ("only auto-fix issues directly caused by the current task"), I did NOT run the reformatter. Instead each edited file was normalised to the repo's CRLF convention and `php -l` verified clean, keeping per-task diffs surgical (Task 2: 39+/82-, Task 3: 22+/22-). The **policies (Task 1) ARE fully Pint-clean** (they were clean before and after). My edits follow each file's existing style.

### [Plan-directed, additional in-scope tests flipped under Task 5 guidance]
Two in-scope tests beyond the plan's explicit Task-4 list asserted old owner-isolation behaviour and were flipped per Task 5's "same flip pattern" instruction:
- `OmManualProjectLinkageTest::test_om_index_only_shows_current_users_manuals` → `..._shows_all_manuals_to_any_authenticated_user` (O&M index now lists all).

## Flags for the User

1. **OUT-OF-SCOPE field-operations cluster NOT relaxed.** Commissioning, Install Programme, Task assignment/photo/status, and Time Entry still use the owner-OR-admin-OR-assigned-engineer model. They are install/commissioning/time-tracking concerns, not "Projects documents", so they were deliberately left unchanged. **If you want the shared-workspace intent to extend there too, that is a quick follow-up — confirm and it can be done.**

2. **`Project::scopeForUser()` is now unused.** After un-scoping its only call sites (O&M-create + QuoteImport dropdowns), the scope method on the `Project` model has zero remaining callers. Per the plan it was LEFT IN PLACE (not deleted) in case it is referenced elsewhere later. You may delete it in a future cleanup if desired.

3. **Pre-existing `projects.show` view bug (separate from this task).** The "Actual Hours" widget and "O&M Manuals" section do not render on the project show page (6 tests red on HEAD, before this change). This is unrelated to authorization and was not touched (rendering is out of scope here). Worth a separate quick-fix.

## Self-Check: PASSED

- Created file exists: `tests/Feature/Authorization/SharedWorkspaceAccessTest.php` — FOUND.
- Commits exist: `e82c725`, `4dae426`, `9fffc8a`, `6b9d0b6` — all FOUND in `git log`.
- SharedWorkspaceAccessTest: 6/6 green (incl. prod-bug repro).
- RamsRenderRegression byte-equivalence: 3/3 green.
- All 12 full-suite failures verified pre-existing on HEAD (out of scope).
