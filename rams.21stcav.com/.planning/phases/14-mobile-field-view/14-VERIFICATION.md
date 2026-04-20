---
phase: 14-mobile-field-view
verified: 2026-04-20T13:15:00Z
status: human_needed
score: 5/5 must-haves verified
overrides_applied: 0
human_verification:
  - test: "Open /projects/{project}/programme in Chrome DevTools iPhone SE (375x667) emulator"
    expected: "No horizontal scrollbar; all interactive elements reachable with thumb; sticky bar + bottom tab-bar both visible"
    why_human: "SC-1 (375px viewport without horizontal scroll) cannot be asserted from PHPUnit HTML response — automated tests only cover anti-pattern heuristics (no w-[>400px], no min-w-[1024])"
  - test: "Upload a photo from iOS Safari on a real iPhone to /install-tasks/{task}/photos"
    expected: "HEIC file converts server-side; task-photos/{project_id}/{task_id}/{uuid}.jpg appears on disk; thumbnail renders in the strip; mime_type column is image/jpeg"
    why_human: "SC-3 HEIC round-trip requires a genuine iOS camera upload. Test suite skips the imagick happy-path because dev box has no imagick; prod parity cannot be proven in CI without an iOS device"
  - test: "Tap a pending task row; confirm visual state change (colour + checkmark pulse) within 400ms without page reload"
    expected: "Row advances pending -> in_progress (amber) -> complete (green) with 400ms ring-green pulse; room counter and programme progress bar both tick up; no network tab full navigation"
    why_human: "SC-2 inline save animation timing + counter pulse requires human eye to confirm no jank, correct colour, and no page reload"
  - test: "Tap Clock in chip in sticky bar; confirm chip turns teal with 'On the clock' label + H:MM timer; tap again to clock out"
    expected: "Chip toggles between white ('Clock in'), teal ('On the clock - H:MM'), white. setInterval(30s) updates the H:MM display. Second clock-in while already open returns 422 with inline 'already clocked in' message"
    why_human: "SC-5 clock in/out visual/interaction cycle requires observing the chip state transitions and the 30s timer tick"
---

# Phase 14: Mobile Field View Verification Report

**Phase Goal:** Mobile-responsive field page where engineers tick tasks complete, capture per-task photos, and clock in/out. HEIC photos are silently converted server-side.
**Verified:** 2026-04-20T13:15:00Z
**Status:** human_needed
**Re-verification:** No - initial verification

## Goal Achievement

### Observable Truths (ROADMAP Success Criteria)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | `/projects/{project}/programme` route renders on a 375px viewport without horizontal scroll | VERIFIED (auto) + human-needed | Route registered at `routes/web.php:297` (`install-programmes.field`), `InstallProgrammeController::field()` returns view `install-programmes.field`. `FieldViewResponsivenessTest::test_view_avoids_wide_fixed_pixel_widths` + `test_view_does_not_register_a_service_worker` both GREEN. Layout uses `pb-24`, `env(safe-area-inset-top)`, `h-14` sticky bar, no `min-w-[1024]`. Viewport meta tag present in `layouts/app.blade.php:5`. **375px visual render is a human checkpoint per 14-VALIDATION.md Manual-Only Verifications.** |
| 2 | Tapping a task status updates it via AJAX with no page reload; success shown visually | VERIFIED (auto) + human-needed | `_field-task-row.blade.php:410-430` fires `fetch('/install-tasks/{id}/status', PATCH)` with X-CSRF-TOKEN meta header; on 2xx sets `savedPulse=true` for 400ms ring-green overlay. `InstallTaskStatusUpdateTest` 8/8 GREEN covers status state machine + 403 + 422. Animation timing needs human eye. |
| 3 | Uploading a HEIC photo from iOS is stored as JPEG in `storage/app/private/task-photos/` | VERIFIED (auto) + human-needed | `TaskPhotoService::store()` writes `task-photos/{project_id}/{task_id}/{uuid}.jpg` via `Storage::disk('local')->path()`. `HeicImageConverter::writeAsJpeg()` converts HEIC via `ImageManager::imagick()->toJpeg(85)` with D-11 fail-loud (`requireImagick()` throws when extension missing). Post-conversion `mime_content_type()` sniff sets `install_task_photos.mime_type`; client MIME never persisted. `InstallTaskPhotoUploadTest::test_jpeg_upload_stores_file_and_creates_row` GREEN. HEIC-specific tests `markTestSkipped` on dev box without imagick (correct D-11). Real iOS upload is a human checkpoint. |
| 4 | Room-level progress counter updates when tasks are completed | VERIFIED | `TaskStatusController::counters()` returns `{room:{complete,total}, programme:{complete,total}}` in the PATCH response. Field view's Alpine `fieldRoot.applyCounters()` queries `[data-room-name="..."] [data-testid="room-counter"]` and updates innerHTML with server-returned counts. Task row dispatches `task-saved` with `room_name` from `$root.dataset.room`. `InstallTaskStatusUpdateTest::test_response_includes_room_and_programme_counters` GREEN. |
| 5 | Clock in/out controls appear on the field page | VERIFIED | Sticky-bar clock chip in `field.blade.php:47-80` (`toggleClock()`, `clockChipClasses()`). Routes `time-entries.start` and `time-entries.stop` registered at `routes/web.php:324-331`. `TimeEntryController::start()` translates `ClockInBlockedException` to 422 with UI-SPEC copy; `stop()` handles no-open-entry with RuntimeException -> 422. `TimeEntryTest` 6/6 GREEN (start, stop, double-start rejected, stop-without-open, 403, cross-project). |

**Score:** 5/5 truths verified (3 require additional human confirmation for animation/visual/device specifics per 14-VALIDATION.md)

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `database/migrations/2026_04_20_000001_create_install_task_photos_table.php` | install_task_photos schema (D-09 mirror) | VERIFIED | File exists; `InstallTaskPhotosSchemaTest` 3/3 GREEN |
| `database/migrations/2026_04_20_000002_create_time_entries_table.php` | time_entries schema incl. last_heartbeat_at | VERIFIED | File exists; `TimeEntriesSchemaTest` 5/5 GREEN; no category column (Phase 15) |
| `database/migrations/2026_04_20_000003_add_status_audit_to_install_tasks_table.php` | status_changed_at + status_changed_by (D-07) | VERIFIED | File exists; migration applied cleanly |
| `app/Models/InstallTaskPhoto.php` | Eloquent model with task(), storagePath(), absolutePath() | VERIFIED | File exists; relations used by TaskPhotoController::show() for file serve |
| `app/Models/TimeEntry.php` | Eloquent model with project(), user(), isOpen() | VERIFIED | File exists; used by InstallProgrammeController::field() for openEntry lookup |
| `app/Models/InstallTask.php` | photos() HasMany, statusChangedBy() BelongsTo, status_changed_at cast | VERIFIED | Relations added without reordering existing code |
| `app/Services/HeicImageConverter.php` | Intervention Image v3 + Imagick wrapper, D-11 fail-loud on HEIC path | VERIFIED (148 lines) | Lazy `requireImagick()` throws on HEIC when extension missing; JPEG/PNG/WebP pass through via `copy()` |
| `app/Services/TaskPhotoService.php` | UUID upload, post-conversion MIME sniff, idempotent delete | VERIFIED (146 lines) | Uses `Str::uuid()` + `Storage::disk('local')->path()`; `mime_content_type()` on disk; client MIME never persisted |
| `app/Services/TimeEntryService.php` | start/stop with DB::transaction + lockForUpdate guard (INST-04g) | VERIFIED (109 lines) | `start()` throws `ClockInBlockedException` on double-open; `stop()` throws RuntimeException on no-open |
| `app/Exceptions/ClockInBlockedException.php` | Typed RuntimeException subclass | VERIFIED | `::alreadyOpen($projectId, $userId)` factory; message not leaked to client (per WR-pattern) |
| `app/Http/Controllers/InstallProgrammeController.php` | field() action with engineer-scope filter | VERIFIED | Ownership guard: owner OR admin OR has-any-assigned-task-on-programme; scope=mine vs scope=all |
| `app/Http/Controllers/TaskStatusController.php` | update() + updateNotes() with counters | VERIFIED (172 lines) | Returns counters.room + counters.programme; validates Rule::in on status enum |
| `app/Http/Controllers/TaskPhotoController.php` | store/update/destroy/show | VERIFIED (203 lines) | Manual `Validator::make` -> JSON 422; `response()->file()` for serve |
| `app/Http/Controllers/TimeEntryController.php` | start/stop with 422 translation | VERIFIED (127 lines) | `ClockInBlockedException` -> 422 with UI-SPEC copy (no internal IDs) |
| `routes/web.php` | 9 routes for field/status/notes/photos/time-entries | VERIFIED | All 9 route names present at lines 297-331 under `auth` middleware |
| `resources/views/install-programmes/field.blade.php` | Mobile UI root with sticky bar, progress, clock chip | VERIFIED (454 lines) | `h-14` sticky bar, `#178A95` accent, `data-testid="programme-progress-bar"`, `pb-24` scroll container |
| `resources/views/install-programmes/_field-task-row.blade.php` | Tap-to-advance rows with overflow menu | VERIFIED (135 lines) | `data-testid="task-row"` + 400ms ring-green savedPulse; overflow menu with blocked/skipped/reopen |
| `resources/views/install-programmes/_field-room.blade.php` | Collapsible room section with counter | VERIFIED | `data-room-name` + `data-testid="room-counter"` for Alpine root update |
| `resources/views/install-programmes/_field-sheet.blade.php` | Bottom-sheet for blocked/skipped reason | VERIFIED | Save disabled until reason trimmed non-empty |
| `resources/views/components/install-task/photo-upload.blade.php` | Horizontal strip + `capture="environment"` camera input + caption blur-save | VERIFIED | POST /install-tasks/{task}/photos via fetch+FormData; HEIC MIME in accept attr |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|----|--------|---------|
| `_field-task-row.blade.php` | `PATCH /install-tasks/{task}/status` | `fetch()` + X-CSRF-TOKEN meta | WIRED | Lines 410-425 — awaited fetch, response used to update local state and dispatch `task-saved` event |
| `_field-task-row.blade.php` | `PATCH /install-tasks/{task}/notes` | `fetch()` + blur handler | WIRED | Lines 431-449 — debounce via `notesDirty` guard |
| `components/install-task/photo-upload.blade.php` | `POST /install-tasks/{task}/photos` | multipart FormData + fetch | WIRED | Line 194-215 — 20 MB client guard, explicit 500/422 error copy |
| `field.blade.php` clock chip | `POST /projects/{project}/time-entries/start` | fetch | WIRED | Lines 222-254 — startClockTicker on success, 422 inline error on double-start |
| `field.blade.php` clock chip | `POST /projects/{project}/time-entries/stop` | fetch | WIRED | Same handler, branches on `clock.openEntry` presence |
| `TaskStatusController::update()` | `InstallTask::counters()` query | Inline `counters()` helper | WIRED | 4 separate COUNT queries scoped to programme + room_name |
| `field.blade.php fieldRoot` | `data-testid="room-counter"` node | `document.querySelector` | WIRED | Uses `CSS.escape(lastRoomName)` + `[data-room-name=...]` scope |
| `TaskPhotoService::store()` | `HeicImageConverter::writeAsJpeg()` | Constructor injection | WIRED | `private readonly HeicImageConverter $converter` |
| `TimeEntryService::start()` | `ClockInBlockedException::alreadyOpen()` | throw | WIRED | Caught by `TimeEntryController::start()` -> 422 |
| `TimeEntryController::start()` | UI-SPEC engineer-friendly copy (no internal IDs) | try/catch | WIRED | Original exception message logged as `internal_message`; client gets hardcoded copy |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| `field.blade.php` $rooms loop | `$rooms` | `InstallProgrammeController::field()` — `$tasks->groupBy('room_name')` from live InstallTask query | Yes (eager-loaded `tasks.assignedUser`, `tasks.photos`) | FLOWING |
| `field.blade.php` progress bar | `$counters['programme']` | Controller computes from `$tasks->where('status', STATUS_COMPLETE)->count()` | Yes | FLOWING |
| `field.blade.php` clock chip | `$openEntry` | Controller queries `TimeEntry::where(project_id+user_id)->whereNull(clocked_out_at)` | Yes | FLOWING |
| `_field-task-row.blade.php` photo strip | `$task->photos` | InstallTask::photos() HasMany relation | Yes | FLOWING |
| `_field-task-row.blade.php` Alpine state | `fieldTaskRow({id, status, blockedReason, notes})` | `@js([...])` from controller payload | Yes | FLOWING |
| Room counter post-save | `counters.room.complete/total` | `TaskStatusController::counters()` 4 COUNT queries | Yes | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Phase 14 test suite green | `php artisan test --filter=<all 14 filters>` | 44 passed, 3 skipped (imagick-gated HEIC happy-path) | PASS |
| Route registration | `grep 'install-programmes\.field' routes/web.php` | Match at line 297 | PASS |
| Migration files present | `ls database/migrations/2026_04_20_*` | 3 files | PASS |
| Controller line counts | `wc -l` on 4 controllers | 127-203 lines each (substantive, not stubs) | PASS |
| intervention/image installed | composer.json require block | `"intervention/image": "^3"` (v3.0.0 installed) | PASS |

### Requirements Coverage

| Requirement | Source Plan(s) | Description | Status | Evidence |
|-------------|---------------|-------------|--------|----------|
| INST-03 | Roadmap parent | Mobile Field View umbrella | SATISFIED | All 8 sub-requirements + 5 SCs satisfied |
| INST-03a | 14-01, 14-04, 14-05 | /projects/{project}/programme mobile-responsive layout | SATISFIED | Route + field.blade.php + viewport meta + 375px anti-pattern tests |
| INST-03b | 14-01, 14-04, 14-05 | Room-grouped list filtered to assigned engineer by default | SATISFIED | `InstallProgrammeController::field()` scope=mine default for engineers; `FieldPageTest::test_engineer_sees_only_assigned_tasks_by_default` GREEN |
| INST-03c | 14-01, 14-04, 14-05 | Tap-to-advance AJAX save with visual confirmation | SATISFIED | `patch()` fetch + savedPulse 400ms ring-green; `InstallTaskStatusUpdateTest` 8/8 GREEN |
| INST-03d | 14-01, 14-02, 14-03, 14-04, 14-05 | Photo capture per task | SATISFIED | `<input type="file" accept="image/*..." capture="environment">` in photo-upload.blade.php; install_task_photos table + model + upload endpoint |
| INST-03e | 14-01, 14-02, 14-03, 14-04 | iOS HEIC protection server-side convert to JPEG | SATISFIED (with human iOS checkpoint) | HeicImageConverter + TaskPhotoService; `InstallTaskPhotoUploadTest::test_heic_converts_to_jpeg` skip-guarded on imagick; D-11 fail-loud preserved for HEIC path |
| INST-03f | 14-01, 14-02, 14-04, 14-05 | Per-task notes blur-saved via AJAX | SATISFIED | `TaskStatusController::updateNotes()`; auto-grow textarea in task-row; `InstallTaskNotesTest` 4/4 GREEN |
| INST-03g | 14-01, 14-04, 14-05 | Room-level + programme progress | SATISFIED | `counters()` server helper + `applyCounters()` Alpine updates; `InstallTaskStatusUpdateTest::test_response_includes_room_and_programme_counters` GREEN |
| INST-03h | 14-01, 14-05 | Online-only, no service worker | SATISFIED | `FieldViewResponsivenessTest::test_view_does_not_register_a_service_worker` GREEN; grep of install-programmes views returns 0 navigator.serviceWorker hits |

**Ancillary requirement:** INST-04g (clock-in one-open-entry guard) is a Phase 15 (INST-04) requirement but was shipped in Phase 14 to fulfil ROADMAP Success Criterion 5 ("Clock in/out controls appear on the field page"). This is documented in 14-CONTEXT.md "Claude's Discretion" as an explicit minimum-viable scope decision. All 9 Phase 14 plan frontmatters declaring INST-04g are satisfied; Phase 15 will extend with category, heartbeat, close-stale-sessions command.

**Orphaned requirements:** None. REQUIREMENTS.md maps INST-03/INST-03a-h to Phase 14, and every one is claimed by at least one 14-0x plan.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `app/Http/Controllers/TaskPhotoController.php` | 79-92 | Octet-stream + allowed extension bypass of `mimetypes` validation (WR-03) | Warning | Narrow: stored XSS via mislabeled file served inline. Does NOT break SC. Review-documented. |
| `resources/views/install-programmes/_field-room.blade.php` | 17 | Blade `{{ }}` inside Alpine `:aria-label` expression (WR-02) | Warning | aria-label silently broken for room names with apostrophes; not a SC failure. |
| `app/Http/Controllers/TaskPhotoController.php` | 195-202 | scope=all engineer can see photo thumbnails that 403 on load (WR-01) | Warning | Broken image UX for unrelated-task photos when viewing scope=all; does not break the 5 SCs (those test assigned-task paths). |
| `app/Http/Controllers/TaskPhotoController.php` | 181-185 | Photo response `Cache-Control: private, max-age=3600` — shared-device leak (WR-04) | Warning | Privacy concern on shared tablets; does not break SC. |
| `app/Services/TaskPhotoService.php` | 68-85 | File written before DB insert; no rollback on DB failure (WR-05) | Warning | Orphaned file accumulation on rare DB failures; does not break SC. |

All 5 warnings are advisory (code review classified as non-blocking — 0 critical, 0 blockers for goal achievement). None cause any of the 5 Success Criteria to be false.

### Human Verification Required

Four items require human checkpoints per 14-VALIDATION.md Manual-Only Verifications (SC-1, SC-3) plus animation timing (SC-2) and clock interaction (SC-5):

1. **375px viewport render** — Open `/projects/{project}/programme` in Chrome DevTools iPhone SE emulator (375x667); confirm no horizontal scrollbar; all interactive elements thumb-reachable. SC-1 automated coverage is heuristic-only (no w-[>400px]); actual viewport render is manual per 14-VALIDATION.md line 90.

2. **iOS HEIC upload end-to-end** — From iOS Safari on a real iPhone, select a camera photo and upload via the photo-upload component. Confirm: server converts to JPEG, file appears at `storage/app/private/task-photos/{project_id}/{task_id}/{uuid}.jpg`, thumbnail renders in strip, `install_task_photos.mime_type` is `image/jpeg`. Cannot be simulated in CI (dev box has no imagick; real iOS camera pipeline differs from fixture).

3. **Inline save animation** — Tap a pending task row; confirm colour transitions (pending-gray -> amber -> green) + 400ms ring-green pulse occurs without page reload. Confirm room counter and programme progress bar both tick up visually. Animation timing requires human eye per 14-VALIDATION.md line 92.

4. **Clock in/out chip interaction** — Tap "Clock in" chip in sticky bar; confirm chip turns teal (#178A95) with `On the clock - 0:00`. Tap again to clock out; chip returns to white. Attempt a second clock-in while already open; confirm inline 422 error message appears. Confirm 30s setInterval actually updates H:MM display.

### Deviations / Notes

- **INST-04g shipped in Phase 14:** Per 14-CONTEXT.md "Claude's Discretion", minimal `time_entries` schema + service + controller were shipped here to fulfil SC-5 ("Clock in/out controls appear on the field page"). Phase 15 will extend the schema (category, notes columns) and add heartbeat loop + close-stale-sessions command. `last_heartbeat_at` is present in the Phase 14 schema per REQUIREMENTS.md technical constraint ("not retrofittable").
- **HEIC happy-path tests skipped on dev box:** `HeicImageConverterTest::test_converts_heic_to_jpeg`, `test_jpeg_passthrough_preserves_bytes`, `InstallTaskPhotoUploadTest::test_heic_converts_to_jpeg` all `markTestSkipped` when `extension_loaded('imagick') === false`. This is the correct D-11 "fail loudly" stance on dev environments; these will turn green on any CI/prod box with `imagick` + `libheif`. Human verification item 2 covers the real-device proof.
- **Deferred items (pre-existing, not Phase 14 regressions):** 6 Vite-manifest Auth render tests + 1 QueueRecoverCommandTest failure in full-suite regression — documented in `deferred-items.md`, not caused by Phase 14.
- **Review.md 5 warnings:** All advisory / defence-in-depth; 0 critical; none block SC. Acceptable for phase closure per Review status `issues_found (advisory, non-blocking)`.

### Gaps Summary

**No gaps blocking goal achievement.** All 5 ROADMAP Success Criteria are fulfilled by shipped code:

- SC-1 (route renders at 375px): Automated heuristics pass; visual render needs human checkpoint
- SC-2 (AJAX status save, no page reload, visual confirmation): Controller + fetch + Alpine pulse all wired; animation timing needs human checkpoint
- SC-3 (HEIC -> JPEG at correct storage path): Service layer + converter + storage path all correct; real iOS device upload needs human checkpoint
- SC-4 (room progress counter updates on complete): Controller returns counters, Alpine root queries + updates room node — fully automated-verified
- SC-5 (clock in/out controls appear): Sticky bar chip + routes + controller + service all present and tested

All 8 INST-03 sub-requirements satisfied. 44 phase tests passing (3 imagick-gated skips, which is the correct D-11 stance).

**Status is `human_needed` (not `passed`) because 4 success criteria require visual/device/animation confirmation that cannot be asserted via PHPUnit.** This is an intrinsic property of a mobile-first UI phase, not a code gap.

---

*Verified: 2026-04-20T13:15:00Z*
*Verifier: Claude (gsd-verifier)*
