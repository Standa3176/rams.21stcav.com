---
phase: 14-mobile-field-view
plan: "04"
subsystem: http-layer
tags: [laravel, controllers, routes, ajax, ownership-guard, mobile-field-view, inst-03, inst-04g]

# Dependency graph
requires:
  - phase: 14-mobile-field-view
    provides: 14-01 Wave 0 feature tests (FieldPageTest, InstallTaskStatusUpdateTest, InstallTaskPhotoUploadTest, InstallTaskNotesTest, TimeEntryTest, FieldViewResponsivenessTest)
  - phase: 14-mobile-field-view
    provides: 14-02 schema + models (InstallTaskPhoto, TimeEntry, InstallTask::photos, status audit columns)
  - phase: 14-mobile-field-view
    provides: 14-03 service layer (TaskPhotoService, TimeEntryService, HeicImageConverter, ClockInBlockedException)
provides:
  - InstallProgrammeController::field() action — mobile view (INST-03a/b)
  - TaskStatusController::update() + updateNotes() — PATCH endpoints with counters + notes blur-save
  - TaskPhotoController::store() + update() + destroy() + show() — photo lifecycle with HEIC-safe validation + private stream
  - TimeEntryController::start() + stop() — clock in/out with ClockInBlockedException → 422 translation
  - 9 routes under auth middleware (GET/PATCH×3/POST×3/DELETE/GET)
  - Minimal install-programmes/field.blade.php placeholder (Plan 05 replaces)
  - HeicImageConverter lazy-imagick health check (Rule 2 fix so JPEG/PNG/WebP don't require imagick)
  - HeicImageConverter copy-based passthrough (Rule 2 fix so test fixtures survive across runs)
affects: [14-05, 15-time-tracking, 16-commissioning]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Thin controllers → service delegation (CLAUDE.md architecture rule): every new controller has one public method per endpoint, validation + ownership guard + delegate + JSON response"
    - "Canonical ownership guard: project owner OR admin OR assigned engineer (task-level for InstallTask mutations, project-level for TimeEntry)"
    - "ClockInBlockedException → 422 translation with engineer-friendly copy (UI-SPEC wording), internal ID-leaking message logged only"
    - "Manual Validator::make + response()->json 422 for form POSTs (photo upload) — avoids the 302 redirect fallback when Accept header is absent"
    - "Lazy requireImagick() health check (moved from constructor to writeAsJpeg HEIC branch) — D-11 fail-loud preserved for HEIC path, JPEG/PNG/WebP passthrough works without imagick"
    - "copy() passthrough instead of \$file->move() — preserves test-mode source fixtures, equivalent in production (PHP tidies tmp at request end)"

key-files:
  created:
    - rams.21stcav.com/app/Http/Controllers/TaskStatusController.php
    - rams.21stcav.com/app/Http/Controllers/TaskPhotoController.php
    - rams.21stcav.com/app/Http/Controllers/TimeEntryController.php
    - rams.21stcav.com/resources/views/install-programmes/field.blade.php
    - rams.21stcav.com/.planning/phases/14-mobile-field-view/deferred-items.md
  modified:
    - rams.21stcav.com/app/Http/Controllers/InstallProgrammeController.php
    - rams.21stcav.com/app/Services/HeicImageConverter.php
    - rams.21stcav.com/routes/web.php
    - rams.21stcav.com/tests/Unit/Services/HeicImageConverterTest.php

key-decisions:
  - "TaskPhotoController uses manual Validator::make + 422 JSON response (not \$request->validate()) so a form POST without Accept:json still receives the JSON error contract the plan specifies. Throws via ValidationException would still have been subject to the exception handler's Accept-header redirect behaviour."
  - "Field view placeholder (30-line Blade) ships with Plan 04 rather than deferring to Plan 05 — the FieldPageTest assertSee('task title') tests require the view to render tasks grouped by room. Plan 05 replaces this entirely with the mobile-first UI-SPEC markup."
  - "Empty string notes: validated as ['present', 'nullable', 'string', 'max:5000'] and persisted as empty string (not null). Laravel's ConvertEmptyStringsToNull middleware would otherwise coerce \"\" → null and fail the \`string\` rule; explicit nullable lets it through, then \`?? ''\` persists an empty string so the test assertion \`assertSame('', \$task->fresh()->notes)\` passes."
  - "Rule 2 auto-fix: HeicImageConverter health check moved from eager constructor to lazy requireImagick() called inside the HEIC branch of writeAsJpeg. Plan 03's constructor-throw blocked ALL photo uploads on boxes without imagick (incl. JPEG/PNG/WebP passthrough), which conflicted with Plan 04's expectation that 'most tests green; test_heic_converts_to_jpeg may skip'. D-11 fail-loud invariant remains intact for the HEIC path — imagick missing + HEIC input still raises RuntimeException."
  - "Rule 2 auto-fix: HeicImageConverter passthrough switched from \$file->move() to copy(). Symfony UploadedFile with test=true renames (moves) the source, which consumed the committed tests/Fixtures/sample.jpg and made subsequent tests fail with 'file does not exist'. copy() preserves the source; production is equivalent — PHP clears the uploaded tmp file at request end regardless."
  - "ClockInBlockedException message is NOT returned to the client. The exception carries `(project #N, user #M)` for log correlation; the HTTP layer logs that as 'internal_message' and returns a hardcoded engineer-friendly copy per 14-UI-SPEC.md line ~205. This satisfies the plan's acceptance criterion 'grep -c \"#%d\" ... returns 0 (no internal-ID format string leaked to client)'."
  - "TaskStatusController::authoriseTaskMutation allows project owner OR admin OR (task.assigned_to === auth()->id()). InstallProgrammeController::field() has a slightly different gate — non-owner non-admin users must have at least one assigned task on the *active programme* to reach the page at all. Both match the 14-CONTEXT.md rules."

patterns-established:
  - "Every new controller accepts the canonical ownership guard pattern — `\$task->programme->project->user_id` for task-scoped endpoints, `\$project->user_id` for project-scoped endpoints. Both OR'd with `auth()->user()->isAdmin()` and an assigned-to check when relevant."
  - "Typed domain exceptions (ClockInBlockedException) → HTTP translation in the thin controller layer. Log the original exception message; return a UI-friendly copy. Mirrors the AIGenerationException pattern in the main RAMS pipeline."

requirements-completed:
  - INST-03a
  - INST-03b
  - INST-03c
  - INST-03d
  - INST-03e
  - INST-03f
  - INST-03g
  - INST-04g

# Metrics
duration: ~30 min (incl. vendor install + fixture restore)
completed: 2026-04-20
---

# Phase 14 Plan 04: Wave 3 HTTP Layer Summary

**Four controller artefacts (one extension + three new) + nine new routes that make the URL contract real — every Phase 14 Feature test from Plan 01 now turns GREEN. Plan 05 ships the Blade UI that consumes these endpoints.**

## Performance

- **Duration:** ~30 minutes (including fresh vendor install, fixture restore, and two Rule 2 auto-fixes on Plan 03's HeicImageConverter)
- **Started:** 2026-04-20T09:34:06Z
- **Completed:** 2026-04-20T10:01:44Z
- **Tasks:** 3 of 3 completed (all committed atomically with `--no-verify` per sequential wave policy)
- **Files created:** 5 (3 controllers + placeholder Blade view + deferred-items.md)
- **Files modified:** 4 (InstallProgrammeController, HeicImageConverter, routes/web.php, HeicImageConverterTest)

## Accomplishments

### Task 1 — Field view + status + notes (commit `820cc8c`)
- Extended `InstallProgrammeController` with a `field()` action. Loads the active programme with `tasks.assignedUser` + `tasks.photos` eager-loads, applies the engineer-scope filter (D-02), groups tasks by denormalised `room_name`, computes programme + per-room counters, and resolves the user's open `TimeEntry` for this project. 403 for strangers (non-owner, non-admin, not assigned to any task on the active programme).
- Created `TaskStatusController` with two endpoints: `update()` (PATCH status) and `updateNotes()` (PATCH notes). Status validation uses `Rule::in(...)` over the five `InstallTask::STATUS_*` constants, with `required_if:status,blocked` + `required_if:status,skipped` guards for the reason. The response payload mirrors RESEARCH.md Example 5 — `{ id, status, blocked_reason, counters: { room, programme } }`.
- Notes validation uses `['present', 'nullable', 'string', 'max:5000']` — `present` keeps the field required; `nullable` works around Laravel's ConvertEmptyStringsToNull middleware; `?? ''` re-coerces null back to empty string on persist so the empty-string-clears-notes contract round-trips cleanly.
- Registered 3 routes under auth middleware: `install-programmes.field`, `install-tasks.status`, `install-tasks.notes`.
- Shipped a 60-line `resources/views/install-programmes/field.blade.php` placeholder — renders project name, clock-in badge, counters, room sections, task titles + statuses. Sufficient for the FieldPageTest `assertSee('My assigned task')` / `assertDontSee('Someone else task')` scope-filter tests to pass. Plan 14-05 will replace this entirely with the mobile-first UI-SPEC markup (sticky bar, tap-to-advance, photo strip, testid hooks).
- Task 1 tests: **FieldPageTest 7/7 GREEN**, **InstallTaskStatusUpdateTest 8/8 GREEN**, **InstallTaskNotesTest 4/4 GREEN**.

### Task 2 — Photo lifecycle + lazy imagick health check (commit `5b102fa`)
- Created `TaskPhotoController` with `store`, `update`, `destroy`, `show`. All four routes enforce the task-level ownership guard (owner OR admin OR assigned engineer).
- `store()` uses manual `Validator::make` + JSON 422 response on failure — bypasses Laravel's default validation redirect so the endpoint always speaks JSON regardless of the client's Accept header. Tests use plain `->post()` (no Accept:json), so defensive JSON is required to satisfy the `assertStatus(422)` contract. Mobile fetch() clients send Accept:json and get the same JSON payload either way.
- `mimetypes` rule covers `image/jpeg,image/png,image/webp,image/heic,image/heif,image/heic-sequence,image/heif-sequence,application/octet-stream` (RESEARCH Pitfalls 2 + 3). A secondary extension + content-sniff check defends against `application/octet-stream` being abused to smuggle non-images.
- `show()` serves via `response()->file()` with the stored `mime_type` header — never `Storage::url()` per RESEARCH Pitfall 5 (local disk has no public symlink). `Content-Disposition: inline; filename=...` for browser-native display; `Cache-Control: private, max-age=3600`.
- `destroy()` returns HTTP 204 no-content after delegating to `TaskPhotoService::delete()`.
- Registered 4 routes: `install-task-photos.store` (POST, throttle:60,1 per T-14-10), `install-task-photos.update` (PATCH), `install-task-photos.destroy` (DELETE), `install-task-photos.show` (GET).
- **Rule 2 auto-fix #1:** moved the `imagick` extension check from `HeicImageConverter::__construct()` to a lazy `requireImagick()` called only inside the HEIC branch of `writeAsJpeg()`. Plan 03's eager check blocked ALL photo uploads on boxes without imagick — even JPEG passthrough — which conflicts with Plan 04's expectation that JPEG/PNG/WebP uploads succeed. D-11 "fail loudly if imagick missing and HEIC is uploaded" is preserved; the HEIC path still throws a RuntimeException with the original remediation message. `HeicImageConverterTest::test_throws_when_imagick_missing` updated to assert construction succeeds + `writeAsJpeg` with HEIC input throws — the invariant under test is the semantic "HEIC cannot be converted without imagick" rather than the accidental "constructor always needs imagick".
- **Rule 2 auto-fix #2:** replaced `$file->move()` with `copy()` in the passthrough branch. Symfony's UploadedFile with test=true renames (moves) the source file, which consumed the committed `tests/Fixtures/sample.jpg` on the first test run and left subsequent tests with "file does not exist" errors. `copy()` preserves the source. Production is equivalent — PHP clears the tmp upload file at request end regardless of whether we move_uploaded_file'd it ourselves.
- Task 2 tests: **InstallTaskPhotoUploadTest 7 GREEN / 1 skipped** (the skip is `test_heic_converts_to_jpeg`, gated on `extension_loaded('imagick')` — correct on the dev box without imagick).

### Task 3 — Time entries + ClockInBlockedException translation (commit `76743c9`)
- Created `TimeEntryController::start()` and `stop()`. Both use DI-injected `TimeEntryService`.
- `start()` catches `ClockInBlockedException` (guard violation) → logs the exception's internal message (`alreadyOpen(#project, #user)`) as `internal_message` + returns the engineer-friendly copy from 14-UI-SPEC.md (`"You're already clocked into another session on this project. Clock out first."`) with HTTP 422. The client never sees the internal project/user IDs — a privacy + threat-register requirement (T-14-12 scope).
- `stop()` catches `RuntimeException` from the no-open-entry path → returns the service message directly (safe generic copy — no identifiers).
- Ownership guard: project owner OR admin OR engineer-with-assigned-task-on-active-programme. Same shape as `field()` action, but using `$project->activeInstallProgramme` (no `with(...)` needed — just an `InstallTask::exists()` query scoped to the programme).
- Registered 2 routes: `time-entries.start` and `time-entries.stop`, both POST, both under `throttle:30,1` (T-14-10).
- Task 3 tests: **TimeEntryTest 6/6 GREEN** — start happy path, start-then-stop, double-clock-in-rejected, stop-without-open-entry-422, unrelated-user-403, same-user-multiple-projects-allowed.

## Task Commits

Each task committed atomically with `--no-verify` (sequential executor; orchestrator runs the full hook suite after wave merge):

1. **Task 1** `820cc8c` — feat(14-04): add field() action + TaskStatusController + 3 routes
2. **Task 2** `5b102fa` — feat(14-04): add TaskPhotoController + 4 photo routes + HEIC lazy health check
3. **Task 3** `76743c9` — feat(14-04): add TimeEntryController + 2 time-entry routes + 422 translation

## Files Created / Modified

Created:
- `rams.21stcav.com/app/Http/Controllers/TaskStatusController.php` — 150 lines. Two endpoints (status + notes), ownership guard, counters helper.
- `rams.21stcav.com/app/Http/Controllers/TaskPhotoController.php` — 200 lines. Four endpoints (store + update + destroy + show), manual validator for JSON 422, ownership guard, BinaryFileResponse serve.
- `rams.21stcav.com/app/Http/Controllers/TimeEntryController.php` — 130 lines. Two endpoints (start + stop), ClockInBlockedException catch → engineer-friendly 422, RuntimeException catch → service-message 422, ownership guard scoped to active programme.
- `rams.21stcav.com/resources/views/install-programmes/field.blade.php` — 60-line placeholder. Extended by Plan 14-05.
- `rams.21stcav.com/.planning/phases/14-mobile-field-view/deferred-items.md` — tracks 3 pre-existing out-of-scope test failures for orchestrator visibility.

Modified:
- `rams.21stcav.com/app/Http/Controllers/InstallProgrammeController.php` — appended `field()` action (92 new lines). No existing methods touched.
- `rams.21stcav.com/app/Services/HeicImageConverter.php` — two Rule 2 auto-fixes (lazy requireImagick + copy-passthrough). Behaviour preserved for production; test-mode and no-imagick-dev-box now both work.
- `rams.21stcav.com/routes/web.php` — appended 9 routes inside the existing `auth` middleware group after the install-programmes.schedule route. No existing routes reordered.
- `rams.21stcav.com/tests/Unit/Services/HeicImageConverterTest.php` — `test_throws_when_imagick_missing` updated to assert construction succeeds + HEIC input throws (new lazy semantics).

## Decisions Made

See frontmatter `key-decisions` for the full list.

Headlines:
- Ship a minimal Blade view now; Plan 05 owns the real UI.
- Manual Validator → JSON 422 so form POST without Accept:json still honours the plan's JSON error contract.
- Two Rule 2 auto-fixes on HeicImageConverter (lazy health check + copy passthrough) — both preserve production semantics, both unblock Plan 04 tests.
- Never return ClockInBlockedException's original message (contains project + user IDs).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Correctness] HeicImageConverter constructor was too eager**

- **Found during:** Task 2 first test run — all non-HEIC photo upload tests failed because TaskPhotoService's DI chain instantiated HeicImageConverter, which threw RuntimeException in its constructor on the no-imagick dev box, making JPEG/PNG/WebP uploads impossible.
- **Issue:** Plan 03's constructor-throw conflicts with Plan 04's acceptance criterion "most tests green; test_heic_converts_to_jpeg may skip". The test design assumes non-HEIC uploads succeed without imagick, which requires deferring the health check.
- **Fix:** Moved the `extension_loaded('imagick')` check from `__construct()` to a private `requireImagick()` called only inside the HEIC branch of `writeAsJpeg()`. Added a PHPDoc comment explaining the lazy-check rationale. Updated `HeicImageConverterTest::test_throws_when_imagick_missing` to assert the lazy semantics (construction succeeds; HEIC input still throws).
- **Files modified:** `app/Services/HeicImageConverter.php`, `tests/Unit/Services/HeicImageConverterTest.php`
- **Commit:** `5b102fa`

**2. [Rule 2 - Correctness] HeicImageConverter passthrough moved the source file**

- **Found during:** Task 2 second test run (after Rule 2 fix #1) — first photo upload test passed, subsequent tests failed with "file does not exist" because Symfony UploadedFile(test=true)->move() renamed the committed sample.jpg to the destination.
- **Issue:** Plan 03's `$file->move($destDir, basename($destinationAbsPath))` destroys the source. In tests this consumed the committed fixture after the first test run. The fix has to preserve the source without breaking production semantics.
- **Fix:** Replaced `$file->move(...)` with `copy($file->getRealPath(), $destinationAbsPath)` with a RuntimeException on failure. Added a comment noting the production equivalence (PHP clears the tmp upload file at request end regardless).
- **Files modified:** `app/Services/HeicImageConverter.php`
- **Commit:** `5b102fa`

### Scope Boundary Notes

- **Vendor install required:** worktree had no `vendor/` directory; an initial junction-to-main-project workaround caused `Illuminate\Foundation\Application::inferBasePath()` to resolve to the main project root (because junctions preserve the ClassLoader's registered path as the target, not the link). That made the testing Kernel load the main project's `routes/web.php`, which does NOT have Phase 14 routes — so every test returned 404. Fix: removed the junction and ran `composer install --no-interaction --prefer-dist` to materialise a real vendor directory inside the worktree. Subsequent tests inferred the correct base path.
- **Fixture restoration:** `tests/Fixtures/sample.jpg` was tracked in HEAD but missing from the working copy of this worktree. Restored via `git show HEAD:rams.21stcav.com/tests/Fixtures/sample.jpg > tests/Fixtures/sample.jpg`. Same fix will be required by the orchestrator merge if the file is not present in the target branch.
- **TmpDebugTest.php:** a debug scaffold I created to troubleshoot the route-not-found issue; removed before committing Task 1.
- **phpunit.xml:** an attempted `APP_BASE_PATH=.` override was reverted once the real fix (fresh vendor install) was found. No net change.

### Authentication Gates

None — all work was server-side PHP. No external service, no OAuth, no API keys.

## Issues Encountered

- **Vite manifest absent:** the worktree has no `public/build/manifest.json`. The six Auth view tests (login/register/reset-password/etc render tests) consequently fail because they render the Breeze guest layout which uses `@vite(...)`. The authenticated `app.blade.php` used by the new field view does NOT use `@vite` and all Phase 14 view tests pass. Logged in `deferred-items.md` for the orchestrator to resolve with `npm install && npm run build` before the final phase merge, or to gate those tests on manifest presence.
- **QueueRecoverCommandTest pre-existing failure:** unrelated to Phase 14, logged in `deferred-items.md`.
- **Windows junction / inferBasePath interaction:** documented in the "Scope Boundary Notes" section so future worktree executors do not step on the same rake.

## User Setup Required

None for this plan's code. For the phase to run cleanly in production:
- `php -m | grep -i imagick` — must list the extension
- `php -r "print_r((new Imagick())->queryFormats('HEI*'));"` — must return `['HEIC', 'HEIF']` or equivalent (indicates the libheif delegate is linked)
- `php.ini` — `upload_max_filesize = 25M` and `post_max_size = 32M` to let the `max:20480` validation rule see 20 MB uploads (RESEARCH Pitfall 4)

For developer environments:
- `composer install` and `npm install && npm run build` to materialise `vendor/` and `public/build/manifest.json`

## Next Plan Readiness

Plan 14-05 (Blade + Alpine + Tailwind mobile UI) can now:
- Replace `resources/views/install-programmes/field.blade.php` with the full UI-SPEC markup. The controller already provides `$project`, `$programme`, `$rooms`, `$counters`, `$openEntry`, `$isOwnerOrAdmin`, `$scope`.
- Wire `fetch('/install-tasks/{id}/status', { method: 'PATCH', body: JSON.stringify({ status }) })` with the `X-CSRF-TOKEN` meta header — the endpoint already returns the `{ id, status, counters }` payload Plan 05 needs to update the room + programme progress counters.
- Wire `fetch('/install-tasks/{id}/notes', { method: 'PATCH' })` for blur-saved notes (INST-03f).
- Wire `fetch('/install-tasks/{id}/photos', { method: 'POST', body: FormData })` — the endpoint already handles HEIC/JPEG/PNG + returns `{ id, filename, url }` for the thumbnail strip.
- Wire clock-in/out buttons to `fetch('/projects/{id}/time-entries/start' | '/stop')` — the engineer-friendly 422 copy is already baked in.
- Render `data-testid="task-row"` on each task row so `FieldViewResponsivenessTest::test_view_contains_required_ui_spec_markers` flips GREEN.

Wave 0 tests that turn GREEN after this plan's commits merge:
- `FieldPageTest` (7/7)
- `InstallTaskStatusUpdateTest` (8/8)
- `InstallTaskNotesTest` (4/4)
- `InstallTaskPhotoUploadTest` (7/7 + 1 imagick-skip — correct D-11)
- `TimeEntryTest` (6/6)
- `HeicImageConverterTest` (1/1 + 2 imagick-skip — lazy semantics asserted)
- Existing schema tests from Plan 02: `InstallTaskPhotosSchemaTest` (3/3), `TimeEntriesSchemaTest` (5/5)

Wave 0 test that stays RED (Plan 05 scope):
- `FieldViewResponsivenessTest::test_view_contains_required_ui_spec_markers` — requires the UI-SPEC `data-testid="task-row"` markup.

## Known Stubs

- `resources/views/install-programmes/field.blade.php` is an intentional placeholder. It renders enough structure to satisfy the FieldPageTest HTTP contract (200 + task-title visibility) but is NOT the UI-SPEC mobile layout. Plan 14-05 will replace it entirely. This stub is tracked in `14-VALIDATION.md` row 14-05-T1/T2 — the orchestrator will see it go away when Plan 05 ships.

## Threat Flags

None. Every endpoint in this plan honours the ownership guard (T-14-03 / T-14-03a mitigations), every input is validated (T-14-13 / T-14-14), photo upload is rate-limited + size-capped (T-14-10), MIME type is sniffed server-side post-conversion (T-14-07 via TaskPhotoService, Plan 03), CSRF protection is inherited from the `web` middleware group (T-14-11), and the private photo serve path is ownership-guarded (T-14-12). No new trust boundaries introduced beyond what the Plan 04 threat_model already captured.

## Self-Check: PASSED

**Files created (verified with `ls`):**
- FOUND: `rams.21stcav.com/app/Http/Controllers/TaskStatusController.php`
- FOUND: `rams.21stcav.com/app/Http/Controllers/TaskPhotoController.php`
- FOUND: `rams.21stcav.com/app/Http/Controllers/TimeEntryController.php`
- FOUND: `rams.21stcav.com/resources/views/install-programmes/field.blade.php`
- FOUND: `rams.21stcav.com/.planning/phases/14-mobile-field-view/deferred-items.md`

**Files modified (verified with `git diff`):**
- FOUND: `rams.21stcav.com/app/Http/Controllers/InstallProgrammeController.php` — `field()` action appended
- FOUND: `rams.21stcav.com/app/Services/HeicImageConverter.php` — lazy requireImagick + copy passthrough
- FOUND: `rams.21stcav.com/routes/web.php` — 9 new routes under auth middleware
- FOUND: `rams.21stcav.com/tests/Unit/Services/HeicImageConverterTest.php` — updated for lazy semantics

**Commits (verified with `git log --oneline`):**
- FOUND: `820cc8c feat(14-04): add field() action + TaskStatusController + 3 routes`
- FOUND: `5b102fa feat(14-04): add TaskPhotoController + 4 photo routes + HEIC lazy health check`
- FOUND: `76743c9 feat(14-04): add TimeEntryController + 2 time-entry routes + 422 translation`

**Routes registered (verified with `php artisan route:list`):**
- FOUND: 9 routes matching `install-programmes.field|install-tasks.status|install-tasks.notes|install-task-photos|time-entries`

**Phase 14 Feature test outcomes (verified with `php artisan test --filter=...`):**
- FieldPageTest: **7 passed** (11 assertions)
- InstallTaskStatusUpdateTest: **8 passed** (24 assertions)
- InstallTaskNotesTest: **4 passed** (6 assertions)
- InstallTaskPhotoUploadTest: **7 passed, 1 skipped** (16 assertions — the skip is the imagick-gated `test_heic_converts_to_jpeg`, correct per D-11 on boxes without imagick)
- TimeEntryTest: **6 passed** (12 assertions)
- FieldViewResponsivenessTest: **2 passed, 1 failed** (expected — the failing test asserts `data-testid="task-row"`, which Plan 14-05 delivers)

**Schema tests (inherited from Plan 02 merge):**
- InstallTaskPhotosSchemaTest: **3 passed**
- TimeEntriesSchemaTest: **5 passed**

**Service tests (inherited from Plan 03, lazy semantics re-asserted):**
- HeicImageConverterTest: **1 passed, 2 skipped** (imagick-gated happy-path + passthrough)

**Full-suite regression (verified with `php artisan test`):** 665 passed, 8 failed, 3 skipped. The 8 failures are enumerated in `deferred-items.md` — 6 pre-existing Vite manifest + 1 pre-existing queue command + 1 expected-RED Plan 05 scope. No regressions introduced by Plan 04.

---
*Phase: 14-mobile-field-view*
*Completed: 2026-04-20*
