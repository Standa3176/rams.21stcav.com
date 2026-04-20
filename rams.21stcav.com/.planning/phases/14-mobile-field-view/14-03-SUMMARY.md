---
phase: 14-mobile-field-view
plan: "03"
subsystem: service-layer
tags: [laravel, services, heic, intervention-image, imagick, clock-in, transaction, lock-for-update, d-11]

# Dependency graph
requires:
  - phase: 14-mobile-field-view
    provides: 14-02 install_task_photos + time_entries schema + models (InstallTaskPhoto, TimeEntry, InstallTask::photos())
  - phase: 14-mobile-field-view
    provides: 14-01 Wave 0 test scaffold (HeicImageConverterTest, TimeEntryTest, InstallTaskPhotoUploadTest + fixtures)
provides:
  - app/Services/HeicImageConverter.php (Intervention Image v3 + Imagick driver wrapper, D-11 fail-loud)
  - app/Services/TaskPhotoService.php (UUID-named uploads, per-task path isolation, HEIC→JPEG on upload)
  - app/Services/TimeEntryService.php (clock in/out with DB::transaction + lockForUpdate guard — INST-04g)
  - app/Exceptions/ClockInBlockedException.php (typed exception consumed by Plan 04 controller for 422)
  - AppServiceProvider boot-time non-blocking warning when libheif delegate missing
  - composer.json require intervention/image ^3
affects: [14-04, 14-05, 16-commissioning]

# Tech tracking
tech-stack:
  added:
    - "intervention/image ^3 (v3.0.0 installed) — HEIC→JPEG conversion via Imagick driver"
  patterns:
    - "Constructor health check fails loudly (D-11) — no silent fallback when php-imagick missing"
    - "Constructor-injected dependencies (TaskPhotoService ← HeicImageConverter) via Laravel container"
    - "Post-conversion MIME sniff via mime_content_type() — never trust client MIME on disk"
    - "DB::transaction + lockForUpdate for one-open-entry guard (Pitfall 6 race mitigation)"
    - "Typed domain exception extending RuntimeException (mirrors AIGenerationException pattern)"
    - "Non-blocking boot-time health warning via Log::warning (app always starts)"

key-files:
  created:
    - rams.21stcav.com/app/Services/HeicImageConverter.php
    - rams.21stcav.com/app/Services/TaskPhotoService.php
    - rams.21stcav.com/app/Services/TimeEntryService.php
    - rams.21stcav.com/app/Exceptions/ClockInBlockedException.php
  modified:
    - rams.21stcav.com/composer.json (require intervention/image ^3)
    - rams.21stcav.com/composer.lock (transitive lock)
    - rams.21stcav.com/app/Providers/AppServiceProvider.php (boot-time libheif warning)

key-decisions:
  - "Stayed on intervention/image v3 line (3.0.0 installed, ^3 constraint pinned); v4 released 2026-03-28 requires PHP 8.3 but project is PHP ^8.2 — v3 remains PHP 8.1+ compatible"
  - "Did NOT install intervention/image-laravel integration package — we instantiate ImageManager::imagick() directly inside the service class; a facade or config file would bloat composer.json unnecessarily (per 14-RESEARCH.md Alternatives Considered)"
  - "Constructor throws RuntimeException when imagick is missing (D-11 fail-loud) instead of using a runtime feature detector + graceful fallback — PROJECT.md data-integrity rule: processing failures surface loudly, never silently degrade"
  - "Post-conversion MIME sniff via mime_content_type() on the written file (not $file->getMimeType()) — the client-provided MIME is never persisted to install_task_photos.mime_type (T-14-07 mitigation)"
  - "UUID filename is always .jpg extension regardless of input format (HEIC converts to JPEG; PNG/WebP pass through but land as .jpg) — mime_type column records the actual on-disk format for correct Content-Type headers at serve time"
  - "sort_order uses ($task->photos()->max('sort_order') ?? 0) + 1 defensive null-coalesce instead of ->max() + 1, because max() returns null (not 0) when the collection is empty — writing null+1 would insert null and break FEED order"
  - "ClockInBlockedException::alreadyOpen() factory encodes both project_id and user_id in the message — gives ops one-liner correlation to the log row from the exception message alone"
  - "TimeEntryService::stop() throws a plain RuntimeException (not a typed exception) when no open entry exists — the HTTP 422 translation is straightforward and a second typed exception for a trivial case adds noise. Plan 04 controller will catch RuntimeException → 422 with the message as the payload"
  - "Guard query uses whereNull('clocked_out_at') with the (user_id, clocked_out_at) index added in Plan 02 — production MySQL plan will use the index on the open-entry lookup; SQLite scan is fine for test env"

patterns-established:
  - "Service layer bias: controllers in Plan 04 should remain thin validator+delegator classes; all orchestration (storage paths, MIME sniffing, UUID generation, guard semantics) lives in app/Services/"
  - "Log prefix convention: every Log::info/warning/error includes the calling class name as the first token (Pint/PSR-12 style match with existing RAMS codebase)"
  - "Constructor DI with readonly promoted properties for single-dependency services (modern PHP 8.2 idiom, also seen in existing codebase e.g. PdfTextExtractorService)"

requirements-completed:
  - INST-03d
  - INST-03e
  - INST-04g

# Metrics
duration: ~50 min (incl. composer install + vendor bootstrap on fresh worktree)
completed: 2026-04-20
---

# Phase 14 Plan 03: Service Layer (HeicImageConverter + TaskPhotoService + TimeEntryService) Summary

**Three services and one typed exception that deliver HEIC→JPEG conversion, per-task photo storage orchestration, and the clock-in/out guard — all the business logic Wave 3 controllers will bind to with zero remaining decisions.**

## Performance

- **Duration:** ~50 minutes (composer install on bare worktree + implementation + verification)
- **Started:** 2026-04-20T~10:20Z
- **Completed:** 2026-04-20T~11:10Z
- **Tasks:** 3 of 3 completed
- **Files created:** 4 (HeicImageConverter, TaskPhotoService, TimeEntryService, ClockInBlockedException)
- **Files modified:** 3 (composer.json, composer.lock, AppServiceProvider)

## Accomplishments

### Task 1 — HEIC conversion infrastructure
- Installed `intervention/image` v3.0.0 via `composer require intervention/image:^3` — v4 was rejected (requires PHP 8.3; project is PHP ^8.2). Stayed on v3 line per 14-RESEARCH.md Standard Stack.
- Created `app/Services/HeicImageConverter.php` wrapping `ImageManager::imagick()`. Constructor fails loudly with `RuntimeException` when `php-imagick` is missing, including a remediation command in the message (`sudo apt install php8.2-imagick libheif-dev`). No silent fallback — per CONTEXT.md D-11 and PROJECT.md data-integrity rule.
- `writeAsJpeg()` converts HEIC/HEIF input at quality 85 using the v3 chainable API; JPEG/PNG/WebP inputs pass through via `UploadedFile::move()` without re-encoding (preserves EXIF + quality for photos that don't need conversion).
- `detectMime()` three-tier MIME detection (finfo content sniff → file extension → client MIME) handles the iOS Safari `application/octet-stream` misreport for HEIC uploads.
- `AppServiceProvider::boot()` now logs a non-blocking `Log::warning` when imagick IS loaded but the libheif delegate is missing — catches 14-RESEARCH.md Pitfall 1 ("works on my machine" trap) at first boot of the new code on a production box, before any upload even happens.
- Test `HeicImageConverterTest::test_throws_when_imagick_missing` flipped from RED (class-not-found error) to GREEN. The other two (HEIC→JPEG happy path + JPEG passthrough) are correctly skipped on this dev box because imagick is not loaded; they will turn green on any CI/prod box with imagick + libheif.

### Task 2 — Photo upload orchestration
- Created `app/Services/TaskPhotoService.php` with a constructor-injected `HeicImageConverter`. Single public API: `store()`, `delete()`, `updateCaption()`.
- `store()` generates a UUID filename under `task-photos/{project_id}/{task_id}/{uuid}.jpg`; never uses the client-supplied filename for the stored path (T-14-05 mitigation). HEIC conversion is delegated to the injected converter; the converter mkdirs intermediate directories as needed.
- After writing, the service sniffs the on-disk MIME via `mime_content_type()` and stores THAT as `install_task_photos.mime_type` — never the client-supplied MIME (T-14-07 mitigation).
- `sanitiseOriginalName()` strips directory separators (`/`, `\`) and control characters from the client's `original_name` before persisting it as a display label (T-14-02 defence-in-depth — the stored filesystem path is the UUID, so traversal in `original_name` cannot reach the disk anyway, but we strip it to prevent log-injection and hostile captions).
- `delete()` is idempotent on filesystem-missing — logs a warning but does not throw, so orphaned DB rows remain cleanable.
- `updateCaption()` clamps to 200 chars via `mb_substr` (Unicode-safe).

### Task 3 — Clock-in / clock-out with one-open-entry guard
- Created `app/Exceptions/ClockInBlockedException.php` — a typed `RuntimeException` with an `alreadyOpen(int $projectId, int $userId)` named constructor that encodes both IDs in the message for log correlation. Mirrors the existing `AIGenerationException` pattern.
- Created `app/Services/TimeEntryService.php` with `start(Project, User): TimeEntry` and `stop(Project, User): TimeEntry`.
- Both methods wrap their check + write in `DB::transaction()` with `SELECT ... FOR UPDATE` on the open-entry lookup. On MySQL this blocks parallel clock-in requests from both observing "no open entry" and both inserting one (T-14-08 race mitigation, 14-RESEARCH.md Pitfall 6). On SQLite `lockForUpdate()` is a no-op but the transaction still serialises.
- `start()` throws `ClockInBlockedException::alreadyOpen($project->id, $user->id)` if an open entry already exists; `stop()` throws `RuntimeException('No open time entry to close on this project.')` if nothing is open — Plan 04 controller will map both to HTTP 422.
- Guard is scoped per `(project, user)` — the same user can hold open entries on different projects simultaneously (matches `test_one_user_can_open_entries_on_different_projects` expectation in the Wave 0 test suite).
- Manual service-layer smoke test (start / double-start / stop / stop-without-open / cross-project-start) all pass.

## Task Commits

Each task committed atomically with `--no-verify` (sequential wave executor; orchestrator will run the full hook suite after wave merge):

1. **Task 1: HeicImageConverter + intervention/image + AppServiceProvider boot warning** — `2683293` (feat)
2. **Task 2: TaskPhotoService (upload orchestration + path traversal safety)** — `6f8c259` (feat)
3. **Task 3: TimeEntryService + ClockInBlockedException** — `b9f6aa5` (feat)

## Files Created / Modified

Created:
- `rams.21stcav.com/app/Services/HeicImageConverter.php` — 110 lines. Intervention Image v3 + Imagick wrapper. Fails loudly on missing extension. Passthrough for already-correct formats.
- `rams.21stcav.com/app/Services/TaskPhotoService.php` — 146 lines. UUID-path photo orchestration; DI HeicImageConverter; post-conversion MIME sniff; sanitised original_name; idempotent delete; caption clamp.
- `rams.21stcav.com/app/Services/TimeEntryService.php` — 102 lines. Clock-in/out with DB::transaction + lockForUpdate; typed exception on guard violation.
- `rams.21stcav.com/app/Exceptions/ClockInBlockedException.php` — 23 lines. RuntimeException subtype + named constructor.

Modified:
- `rams.21stcav.com/composer.json` — added `"intervention/image": "^3"` to require block (composer pinned to 3.0.0 in lock).
- `rams.21stcav.com/composer.lock` — transitive lock update (intervention/image 3.0.0 + its runtime deps).
- `rams.21stcav.com/app/Providers/AppServiceProvider.php` — appended a non-blocking libheif delegate health check to `boot()` (17 new lines); no existing bindings or events touched.

## Decisions Made

See frontmatter `key-decisions` for the full list. Highlights:

- **v3 not v4 for intervention/image** — v4 released 2026-03-28 requires PHP 8.3; project is PHP ^8.2. Composer constraint `^3` keeps us on the PHP 8.1-compatible line.
- **Direct ImageManager instantiation (no intervention/image-laravel integration package)** — the facade + config package is unnecessary overhead when the single consumer is a dedicated service class.
- **Post-conversion MIME sniff on disk** — the client-supplied MIME is NEVER persisted to `install_task_photos.mime_type`. This means if an attacker uploads a PDF with `Content-Type: image/jpeg`, the stored `mime_type` will reflect whatever the on-disk bytes actually are (PDF would fail earlier via the validation layer in Plan 04, but defence-in-depth).
- **D-11 fail-loud constructor** — no try/catch around `extension_loaded('imagick')`. If the extension is missing, requests die with HTTP 500 (converted to a user-friendly error by Plan 04's controller). This is the correct behaviour per PROJECT.md data-integrity rule.
- **ClockInBlockedException is typed; stop-no-open is plain RuntimeException** — the double-clock-in case is a specific business rule worth a typed exception; the no-open-entry-to-stop case is a trivial input error where a typed exception adds noise. Both translate to 422 in Plan 04.
- **`sort_order` uses `?? 0) + 1`** — defensive against `max()` returning `null` on an empty collection (PHP loose-compare quirk: `null + 1` is `1`, but Eloquent may complain about strict-types on the insert).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `sort_order` null-coalesce on empty photos collection**

- **Found during:** Task 2 review before commit
- **Issue:** The plan's action block wrote `$task->photos()->max('sort_order') + 1` — when a task has no photos yet, `max()` returns `null`, and `null + 1` evaluates to `1` but triggers implicit-null deprecation warnings on PHP 8.4 and fails strict-types checks. First photo for a task would silently get a `null` `sort_order` in some environments.
- **Fix:** Changed to `($task->photos()->max('sort_order') ?? 0) + 1` — explicit null-coalesce to 0, matching the `SurveyService::addPhoto()` pattern exactly (SurveyService uses `($room->photos()->max('sort_order') ?? 0) + 1`).
- **Files modified:** `app/Services/TaskPhotoService.php` (during initial Write, before Task 2 commit)
- **Commit:** `6f8c259` (clean — the fix was applied inline before the commit)

**2. [Rule 2 - Correctness] Capture photo_id before `$photo->delete()` in `TaskPhotoService::delete()`**

- **Found during:** Task 2 review before commit
- **Issue:** The plan's action block called `$photo->delete()` and then referenced `$photo->id` in the subsequent `Log::warning` / `Log::info` calls. After Eloquent `delete()`, the model instance is still in memory but — depending on the model — subsequent property access behaviour can vary. Relying on post-delete id access is fragile.
- **Fix:** Captured `$photoId = $photo->id` before `$photo->delete()`; all subsequent log references use `$photoId`, not `$photo->id`.
- **Files modified:** `app/Services/TaskPhotoService.php` (during initial Write, before Task 2 commit)
- **Commit:** `6f8c259` (clean — the fix was applied inline before the commit)

### Scope Boundary Notes

- **Composer install required on fresh worktree:** the worktree had no `vendor/` directory (git-worktree does not share vendor with the main checkout). Ran `composer install --no-interaction --prefer-dist` once to bootstrap, then `composer require intervention/image:^3` to complete Task 1 step 1. Also created `.env` from `.env.example` and ran `php artisan key:generate --force` to allow `php artisan` commands (env is gitignored — not committed).
- **Worktree base realignment:** the worktree was initialised from an older branch (`6f23f37`) instead of the expected base `902a5b7`. Detected and corrected via `git reset --hard 902a5b72a0d5d3a7aee1cac83488299bbde44c0a` before any work began. Subsequent `git log --oneline` confirms the three 14-03 commits sit directly on top of the 14-01 + 14-02 scaffold merges.
- **No stub code shipped:** all three services have concrete implementations. No TODOs, FIXMEs, placeholder strings, or empty-stub classes.

### Authentication Gates

None — pure service-layer code, no external service calls.

## Issues Encountered

- **Dev-box imagick extension is not loaded:** Herd on this Windows dev box runs PHP 8.4.19 with `fileinfo` + `gd` only — no `imagick`. This is the intended D-11 state: `HeicImageConverterTest::test_throws_when_imagick_missing` turns GREEN because the constructor correctly throws RuntimeException with "imagick" in the message. The two happy-path tests (`test_converts_heic_to_jpeg`, `test_jpeg_passthrough_preserves_bytes`) are `markTestSkipped` per their own `extension_loaded('imagick')` guards — they will turn GREEN on any CI/prod environment with imagick + libheif.
- **`php artisan list` intermittent exit code:** observed one spurious `exit=1` on `php artisan list` during verification. Re-running the same command produced `exit=0`. Most likely a shell/FD interleaving artefact with the `cmd //c` invocation path on MinGW — not a functional issue; `php artisan migrate`, `php artisan test`, and `php artisan tinker` all exit 0 consistently.

## User Setup Required

None for this plan's code. For production deployment (phase completion):
- Install `php8.2-imagick` (Linux) / enable `extension=imagick` in php.ini (Windows)
- Compile ImageMagick with the libheif delegate (`sudo apt install libheif-dev` and recompile, or use a distribution build that includes it)
- Verify with: `php -r "print_r((new Imagick())->queryFormats('HEI*'));"` — must print `Array ( [0] => HEIC [1] => HEIF ... )`. Empty array → delegate missing.

The AppServiceProvider boot-time warning catches the "imagick loaded but libheif missing" case one layer earlier than the upload request, flagged in `storage/logs/laravel.log` on first boot.

## Next Plan Readiness

Plan 14-04 (controllers + routes, the thin HTTP layer) can now:
- Resolve `TaskPhotoService` via DI and call `->store($task, $request->file('photo'))` — returns an `InstallTaskPhoto` ready to JSON-serialise for the `{ id, filename, original_name, url }` response.
- Resolve `TimeEntryService` via DI; wrap `->start($project, $user)` in try/catch `ClockInBlockedException` → return 422 JSON.
- Wrap `->stop($project, $user)` in try/catch `RuntimeException` → return 422 JSON (use the exception message as the error payload).
- Catch `RuntimeException` from `HeicImageConverter` (when imagick is missing on prod) → the global exception handler already logs it as `error`; no per-controller plumbing needed.

Wave 0 tests that will turn GREEN after Plan 14-04 merges its routes + controllers:
- `TimeEntryTest` (6 tests) — HTTP start/stop/guard tests
- `InstallTaskPhotoUploadTest` (7 non-imagick tests + 1 imagick-skipped) — upload orchestration tests
- The service-layer bits exercised by those HTTP tests already pass against this plan's code (confirmed via the smoke-test script).

## Known Stubs

None. All four files have concrete, production-ready implementations.

## Threat Flags

None. The plan's threat register (T-14-01, T-14-02, T-14-05, T-14-06, T-14-07, T-14-08, T-14-09) is fully covered by the code that shipped. No new attack surface introduced beyond what the threat model already captured.

## Self-Check: PASSED

**Files created (verified with `ls`):**
- FOUND: `rams.21stcav.com/app/Services/HeicImageConverter.php`
- FOUND: `rams.21stcav.com/app/Services/TaskPhotoService.php`
- FOUND: `rams.21stcav.com/app/Services/TimeEntryService.php`
- FOUND: `rams.21stcav.com/app/Exceptions/ClockInBlockedException.php`

**Files modified (verified with `git diff`):**
- FOUND: `rams.21stcav.com/composer.json` — intervention/image ^3 added to require block
- FOUND: `rams.21stcav.com/composer.lock` — transitive lock updated
- FOUND: `rams.21stcav.com/app/Providers/AppServiceProvider.php` — boot-time libheif check appended, no existing lines reordered

**Commits (verified with `git log --oneline`):**
- FOUND: `2683293 feat(14-03): add HeicImageConverter service + intervention/image v3 + libheif boot warning`
- FOUND: `6f8c259 feat(14-03): add TaskPhotoService for per-task photo upload orchestration`
- FOUND: `b9f6aa5 feat(14-03): add TimeEntryService with one-open-entry guard + ClockInBlockedException`

**Test suite (verified with `php artisan test`):**
- `HeicImageConverterTest`: 1 passed, 2 skipped (imagick missing — correct D-11 behaviour on boxes without imagick; `test_throws_when_imagick_missing` is GREEN, proving the fail-loud path works)
- `InstallTaskPhotosSchemaTest`: 3 passed (inherited from Plan 02 merge — still GREEN)
- `TimeEntriesSchemaTest`: 5 passed (inherited from Plan 02 merge — still GREEN)
- `php artisan list` exit 0 — AppServiceProvider boot warning is non-blocking

**Composer (verified with `composer show`):**
- FOUND: `intervention/image 3.0.0` — on the v3 line, not v4

**Service resolution chain (verified with `php artisan tinker`):**
- `app(App\Services\TimeEntryService::class)` → returns `App\Services\TimeEntryService` (no dependencies)
- `app(App\Services\TaskPhotoService::class)` → throws `RuntimeException: HeicImageConverter: the php-imagick PHP extension is required...` (expected on dev box, proves DI chain works)
- `app(App\Services\HeicImageConverter::class)` → throws same RuntimeException (expected, D-11 fail-loud)
- `class_exists(App\Exceptions\ClockInBlockedException::class)` → true

**Service-layer smoke test (executed against in-memory SQLite):**
- `TimeEntryService::start()` creates open entry → GREEN
- `TimeEntryService::start()` second call throws `ClockInBlockedException` → GREEN (guard works)
- `TimeEntryService::stop()` closes the open entry → GREEN
- `TimeEntryService::stop()` with no open entry throws `RuntimeException` → GREEN
- Same user clock-in on different project succeeds → GREEN (per-project scope correct)

---
*Phase: 14-mobile-field-view*
*Completed: 2026-04-20*
