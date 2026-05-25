---
phase: 260525-s8b
plan: 01
subsystem: authorization / field-ops + RAMS-upload
tags: [authorization, shared-workspace, commissioning, install-programme, time-entry, rams-upload]
requires:
  - 260525-pyu (documents + projects shared-workspace relaxation)
provides:
  - field-ops + RAMS-upload surfaces open to any authenticated user
  - schedule/field task listings un-scoped to ALL tasks
affects:
  - app/Http/Controllers/QuoteUploadController.php
  - app/Http/Controllers/CommissioningController.php
  - app/Http/Controllers/CommissioningItemController.php
  - app/Http/Controllers/CommissioningResyncController.php
  - app/Http/Controllers/CommissioningSignoffController.php
  - app/Http/Controllers/InstallProgrammeController.php
  - app/Http/Controllers/TaskAssignmentController.php
  - app/Http/Controllers/TaskPhotoController.php
  - app/Http/Controllers/TaskStatusController.php
  - app/Http/Controllers/TimeEntryController.php
  - app/Services/TimeEntryService.php
tech-stack:
  added: []
  patterns:
    - "abort_unless(auth()->check(), 403) // Shared workspace: any authenticated user has full access."
key-files:
  created:
    - tests/Feature/Authorization/SharedWorkspaceFieldOpsAccessTest.php
  modified:
    - app/Http/Controllers/QuoteUploadController.php
    - app/Http/Controllers/CommissioningController.php
    - app/Http/Controllers/CommissioningItemController.php
    - app/Http/Controllers/CommissioningResyncController.php
    - app/Http/Controllers/CommissioningSignoffController.php
    - app/Http/Controllers/InstallProgrammeController.php
    - app/Http/Controllers/TaskAssignmentController.php
    - app/Http/Controllers/TaskPhotoController.php
    - app/Http/Controllers/TaskStatusController.php
    - app/Http/Controllers/TimeEntryController.php
    - app/Services/TimeEntryService.php
    - tests/Feature/Commissioning/OwnershipGuardTest.php
    - tests/Feature/Commissioning/ItemPhotoUploadTest.php
    - tests/Feature/FieldView/FieldPageTest.php
    - tests/Feature/InstallTasks/InstallTaskStatusUpdateTest.php
    - tests/Feature/InstallTasks/InstallTaskNotesTest.php
    - tests/Feature/InstallTasks/InstallTaskPhotoUploadTest.php
    - tests/Feature/TimeEntries/TimeEntryTest.php
    - tests/Feature/TimeEntries/TimeEntryEditTest.php
    - tests/Unit/Services/TimeEntryServiceTest.php
decisions:
  - "TimeEntryService::recordHeartbeat kept STRICT owner-only (liveness/integrity guard, not access control)."
  - "TimeEntryService::editEntry relaxed; accountability preserved by append-only TimeEntryAudit row (edited_by_user_id)."
metrics:
  duration: ~35min
  tasks: 3
  files: 21
  completed: 2026-05-25
---

# Phase 260525-s8b Plan 01: Relax Authorization on RAMS Upload + Field-Ops Cluster Summary

Completed the shared-workspace authorization relaxation begun by 260525-pyu, covering the two surfaces the post-pyu audit found: (1) the RAMS upload flow's private `authoriseRamsAccess` guard, and (2) the entire field-operations cluster (Commissioning, Install Programme, Task assignment/photo/status, Time Entry). Every owner-/owner-OR-admin-/owner-OR-admin-OR-assigned-engineer ACCESS gate now reduces to `abort_unless(auth()->check(), 403)`, and the install-programme schedule/field task listings show ALL tasks to every authenticated user. The strict owner-only heartbeat liveness guard and all integrity/validation/throttle guards are preserved verbatim. This is an authorization-only change — no rendering, storage, route, migration, validation, throttle, or AI behaviour was touched.

## Tasks

| Task | Name | Commit |
|------|------|--------|
| 1 | Relax all field-ops + RAMS-upload ownership gates (preserve heartbeat owner-only + integrity guards) | `1abd6e4` |
| 2 | Flip the field-ops 403/assigned-only test suites to the shared-access model | `c1cfcd0` |
| 3 | Add shared-workspace field-ops regression test + fix commissioning photo ownership test | `29c3fa3` |

## What changed

### Production (11 files, Task 1)
- **QuoteUploadController::authoriseRamsAccess** — body replaced with `abort_unless(auth()->check(), 403)`; docblock updated. (The pyu-missed surface.)
- **CommissioningController::show** — inline owner/assigned gate removed; `$isOwnerOrAdmin = true` retained for the view contract (owner-chrome) since everyone is effectively an owner in a shared workspace.
- **CommissioningItemController::authoriseEdit / CommissioningResyncController::authorise / CommissioningSignoffController::authorise** — bodies collapsed to the authenticated-user check; unused `loadMissing` + ownership/engineer probes dropped.
- **InstallProgrammeController** — generate/review/activate/destroyTask inline gates relaxed; schedule() and field() gates relaxed with `$isOwnerOrAdmin = true`, which makes the existing task-filter ternary yield ALL tasks (schedule) and default everyone to scope=all (field). The `?scope=mine` toggle still works. The per-user open-session `TimeEntry` lookup in field() was left UNCHANGED (per-user clock-in state, not a listing).
- **TaskAssignmentController** — assign/assignRoom/assignAll gates relaxed.
- **TaskPhotoController / TaskStatusController::authoriseTaskMutation** — bodies collapsed to the authenticated-user check; TaskStatusController class docblock updated to the shared-workspace contract.
- **TimeEntryController::authoriseProjectAccess** — body collapsed; now-orphaned `use App\Models\InstallTask;` import removed (Pint-clean).
- **TimeEntryService::editEntry** — owner/admin throw block deleted; `$editor` retained for the audit row. **recordHeartbeat** — preserved verbatim with an explicit "PRESERVED" comment.

### Tests (Task 2 + 3)
- 8 suites flipped from asserting 403/assigned-only to asserting shared access (non-owner non-assigned user succeeds); FieldPage default scope now asserts ALL tasks visible; TimeEntry edit asserts the audit row records the editing stranger.
- New `SharedWorkspaceFieldOpsAccessTest` (7 cases): checklist view, field page + all-tasks listing, task status patch, clock-in + retro-edit (with audit-row editor assertion), RAMS upload checkReady/processing 403→200 repro, **heartbeat owner-only negative control (still 403)**, and **guest→login negative control**.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] ItemPhotoUploadTest::test_upload_requires_ownership broken by the authoriseEdit relaxation**
- **Found during:** Task 3 full-suite run.
- **Issue:** `tests/Feature/Commissioning/ItemPhotoUploadTest.php` asserts a stranger gets 403 on commissioning item photo upload. That endpoint (`CommissioningItemController::storePhoto`) is guarded by `authoriseEdit`, which Task 1 relaxed — so the test went red. The plan's Task 2 file list enumerated the 403/assigned-only suites but omitted this sibling file.
- **Fix:** Flipped `test_upload_requires_ownership` → `test_any_authenticated_user_can_upload_photo` (asserts 201 + `evidence_photo_path` persisted), mirroring the file's own owner-success test (real `tests/Fixtures/sample.jpg` fixture + `Storage::fake('local')` so the content-sniff guard passes). Consistent with the shared-access model applied to every other field-ops surface.
- **Files modified:** tests/Feature/Commissioning/ItemPhotoUploadTest.php
- **Commit:** `29c3fa3`

**2. [Rule 1 - Bug] Stale class-level docblock on TaskStatusController**
- **Found during:** Task 1 grep sanity sweep.
- **Issue:** The class docblock still described the removed owner/assigned-engineer model.
- **Fix:** Rewrote it to the shared-workspace contract (doc-only, within a file already being touched).
- **Commit:** `1abd6e4`

### Pint (pre-existing, out of scope — NOT changed)
`vendor/bin/pint --test` reports failures across ~45 controllers, including files this task never touched (Admin\*, Auth\*, CableScheduleController, etc.). Verified by stashing the diff and running Pint on clean `HEAD`: the same files fail with the same fixers (dominant: `line_ending` CRLF→LF on Windows, plus `binary_operator_spaces`). This is a pervasive pre-existing repo style state, not introduced by this task. Comparing fixer sets before/after my edits, my changes only REDUCED Pint findings (deleted `!`/operator expressions). Running `pint` (auto-fix) would rewrite line endings + spacing across files outside the 11-file scope, violating the scope boundary, so it was deliberately NOT run. `php -l` on all 11 production files + the new test: clean.

## Test Result

`php artisan test` (Herd PHP 8.4): **1318 passed, 12 failed, 8 skipped (5135 assertions)**.

- The **12 failures are all PRE-EXISTING** and unrelated to this task. Verified by checking out the pre-work commit `d869fb0` and re-running the same suites — they fail identically at baseline (the suite had 12 reds before this task, matching the documented baseline from before 260525-pyu). The 12: QuoteParserServiceTest (2 — RAMS quote parsing), DocumentArtifactStorageTest "types returns all four" (1 — storage enum), OmManualProjectLinkageTest (1 — O&M section render), ActualHoursWidgetTest (5 — dashboard widget), QueueRecoverCommandTest (1 — sync-queue driver), PublicWorksheetSignoffTest (2 — public token routes; explicitly out of scope).
- **No new failure is mine.** During Task 3 one transient new red appeared (`ItemPhotoUploadTest::test_upload_requires_ownership`) caused directly by the `authoriseEdit` relaxation; it was fixed in-task (Deviation #1) and the count returned to exactly 12 pre-existing.
- 8 skips are env-driven (ext-imagick absent for HEIC conversion, D2/PhpSpreadsheet binaries absent in dev).

## Flags for the user

- **`TimeEntryService::recordHeartbeat` was deliberately LEFT strict owner-only.** A peer cannot keep another user's open clock-session alive — this is a liveness/integrity anti-abuse guard (T-15-02-01), not an access-control gate. If you want even this relaxed it is a one-line follow-up, but doing so would let any user keep any session alive indefinitely, which is almost certainly not desired. The negative control `SharedWorkspaceFieldOpsAccessTest::test_heartbeat_still_owner_only` and the untouched `TimeEntryHeartbeatTest::test_heartbeat_returns_403_for_non_owner` both prove it still 403s.
- **`TimeEntryService::editEntry` WAS relaxed** to any authenticated user. Accountability is preserved by the append-only `TimeEntryAudit` row, which records who edited what, when (`edited_by_user_id`).
- **With 260525-pyu (documents/projects) + this task (field-ops + RAMS upload), the entire authenticated app surface is now a shared workspace**, except (all intentionally preserved): the admin route group / `Admin\*` controllers, restore/force-delete, soft-deleted/trashed listings, the heartbeat liveness guard, public token routes (survey/worksheet `{token}`), the DashboardController admin redirect, and the HazardTemplate personal/global split.
- **Deploy:** production via `git push live` then `git pull` + `php artisan optimize:clear` (orchestrator handles deploy). Authorization-only change — no migrations, no asset rebuild required.

## Self-Check: PASSED
- All 3 commits present: `1abd6e4`, `c1cfcd0`, `29c3fa3` (verified in `git log`).
- New file `tests/Feature/Authorization/SharedWorkspaceFieldOpsAccessTest.php` exists and lints clean.
- All 11 production files lint clean (`php -l`).
- New regression test: 7/7 cases pass (incl. heartbeat owner-only + guest negative controls).
