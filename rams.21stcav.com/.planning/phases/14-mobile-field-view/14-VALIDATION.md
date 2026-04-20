---
phase: 14
slug: mobile-field-view
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-04-19
---

# Phase 14 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.5 |
| **Config file** | `phpunit.xml` |
| **Quick run command** | `php artisan test --filter=Phase14` |
| **Full suite command** | `php artisan test` |
| **Estimated runtime** | ~45 seconds (quick) / ~120 seconds (full) |

---

## Sampling Rate

- **After every task commit:** Run `php artisan test --filter=Phase14`
- **After every plan wave:** Run `php artisan test`
- **Before `/gsd-verify-work`:** Full suite must be green
- **Max feedback latency:** 60 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 14-01-T1 | 14-01 | 0 | Wave 0 fixtures | T-14-00a, T-14-00c | HEIC/JPEG fixtures are genuine binaries; no EXIF/credentials | Unit (shell) | `php -r "echo strpos(file_get_contents('tests/Fixtures/sample.heic'),'ftyp')!==false?'y':'n';"` | ⬜ | ⬜ pending |
| 14-01-T2 | 14-01 | 0 | Wave 0 factories | T-14-00b | Factory defns isolated via RefreshDatabase | Unit | `ls database/factories/InstallProgrammeFactory.php database/factories/InstallTaskFactory.php database/factories/InstallTaskPhotoFactory.php database/factories/TimeEntryFactory.php` | ⬜ | ⬜ pending |
| 14-01-T3 | 14-01 | 0 | All Wave 0 tests exist (red) | — | tests exist + fail meaningfully | Feature+Unit | `php artisan test --filter="FieldPage\|FieldView\|InstallTaskStatusUpdate\|InstallTaskPhotoUpload\|InstallTaskNotes\|TimeEntry\|HeicImageConverter\|InstallTaskPhotosSchema\|TimeEntriesSchema"` | ⬜ | ⬜ pending |
| 14-02-T1 | 14-02 | 1 | INST-03d, D-09 | T-14-06 | install_task_photos schema shape (mime, filename path, caption len) | Migration/Unit | `php artisan test --filter=InstallTaskPhotosSchemaTest` | ⬜ W0 | ⬜ pending |
| 14-02-T2 | 14-02 | 1 | INST-04g (discretion) | T-14-08 | time_entries schema with last_heartbeat_at nullable; open-entry uniqueness | Migration/Unit | `php artisan test --filter=TimeEntriesSchemaTest` | ⬜ W0 | ⬜ pending |
| 14-02-T3 | 14-02 | 1 | D-07 audit | T-14-04 | status_changed_at/by columns + InstallTask cast | Migration/Unit | `php artisan migrate && php artisan tinker --execute="echo Schema::hasColumn('install_tasks','status_changed_at')?'y':'n';"` | ⬜ W0 | ⬜ pending |
| 14-03-T1 | 14-03 | 2 | INST-03e, D-11 | T-14-01 | HeicImageConverter fails loudly when imagick missing | Unit | `php artisan test --filter=HeicImageConverterTest` | ⬜ W0 | ⬜ pending |
| 14-03-T2 | 14-03 | 2 | INST-03d/e, D-11 | T-14-01, T-14-02 | InstallTaskPhotoService upload + HEIC→JPEG convert | Feature/Unit | `php artisan test --filter=InstallTaskPhotoUploadTest` | ⬜ W0 | ⬜ pending |
| 14-03-T3 | 14-03 | 2 | INST-04g (discretion) | T-14-08 | TimeEntryService one-open-entry guard (lockForUpdate) | Feature/Unit | `php artisan test --filter=TimeEntryTest::test_double_clock_in_rejected` | ⬜ W0 | ⬜ pending |
| 14-04-T1 | 14-04 | 3 | INST-03a/b/c/f/g | T-14-03, T-14-04 | field() page + status/notes AJAX with engineer-scope filter | Feature | `php artisan test --filter="FieldPageTest\|InstallTaskStatusUpdateTest\|InstallTaskNotesTest"` | ⬜ W0 | ⬜ pending |
| 14-04-T2 | 14-04 | 3 | INST-03d/e, D-12 | T-14-01, T-14-02, T-14-05 | TaskPhotoController store/update/destroy/show with ownership guard | Feature | `php artisan test --filter=InstallTaskPhotoUploadTest` | ⬜ W0 | ⬜ pending |
| 14-04-T3 | 14-04 | 3 | INST-04g | T-14-08 | TimeEntryController start/stop; ClockInBlockedException → engineer-friendly 422 copy (no internal IDs) | Feature | `php artisan test --filter=TimeEntryTest` | ⬜ W0 | ⬜ pending |
| 14-05-T1 | 14-05 | 4 | INST-03a/h, D-01..D-06 | — | field.blade mobile-first, no service worker registration | Feature | `php artisan test --filter=FieldViewResponsivenessTest` | ⬜ W0 | ⬜ pending |
| 14-05-T2 | 14-05 | 4 | INST-03c/d/f, D-07..D-12 | — | Alpine bindings + status chips + photo upload + inline save | Feature | `php artisan test --filter=FieldViewResponsivenessTest::test_view_contains_required_ui_spec_markers` | ⬜ W0 | ⬜ pending |
| 14-05-T3 | 14-05 | 4 | INST-03a, SC-1 | — | 375px viewport + iOS real-device smoke (manual checkpoint) | Manual | Human checkpoint — see Manual-Only Verifications below | ⬜ | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

*File Exists legend: ⬜ = test file not yet in repo (Wave 0 will create). ⬜ W0 = test file will be created by plan 14-01 Task 3 (Wave 0) and must be red until its owning implementation plan ships. ✅ = test file exists on disk. Flip to ✅ once plan 14-01 Task 3 completes.*

---

## Wave 0 Requirements

- [ ] `tests/Feature/FieldView/FieldPageTest.php` — GET /projects/{project}/programme ownership + admin
- [ ] `tests/Feature/FieldView/FieldViewResponsivenessTest.php` — mobile-first heuristic + no-service-worker check (INST-03h)
- [ ] `tests/Feature/InstallTasks/InstallTaskStatusUpdateTest.php` — status transition matrix
- [ ] `tests/Feature/InstallTasks/InstallTaskPhotoUploadTest.php` — HEIC → JPEG conversion with fixture
- [ ] `tests/Feature/InstallTasks/InstallTaskNotesTest.php` — notes blur-save
- [ ] `tests/Feature/TimeEntries/TimeEntryTest.php` — start/stop + one-open-entry guard
- [ ] `tests/Unit/Services/HeicImageConverterTest.php` — happy path + Imagick-missing error path
- [ ] `tests/Unit/Migrations/InstallTaskPhotosSchemaTest.php` — install_task_photos column shape assertion
- [ ] `tests/Unit/Migrations/TimeEntriesSchemaTest.php` — time_entries column shape (incl. last_heartbeat_at nullable)
- [ ] `tests/Fixtures/sample.heic` — tiny (~50–300 KB) real HEIC test fixture (EXIF-scrubbed)
- [ ] `tests/Fixtures/sample.jpg` — small real JPEG passthrough fixture
- [ ] `tests/Fixtures/README.md` — documents fixture source + EXIF-scrubbing note
- [ ] `InstallProgrammeFactory` — test setup factory (project + generated_by + status)
- [ ] `InstallTaskFactory` — test setup factory (programme + status states)
- [ ] `InstallTaskPhotoFactory` — test setup factory (references future InstallTaskPhoto model)
- [ ] `TimeEntryFactory` — test setup factory (references future TimeEntry model)

*Wave 0 validates tests exist and fail meaningfully before implementation lands.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| 375px viewport renders without horizontal scroll | SC-1 / INST-03a | Dusk overkill for a single visual check | Open `/projects/{id}/programme` in Chrome DevTools iPhone SE emulator (375×667); confirm no horizontal scrollbar; confirm all interactive elements reachable with thumb |
| HEIC from a real iOS device | INST-03e | Cannot simulate full iOS camera pipeline in CI | Upload a photo from iOS Safari on an iPhone; confirm JPEG file appears on disk and thumbnail renders |
| Inline save visual (checkmark pulse, colour change) | INST-03c / D-08 | Animation timing requires human eye | Tap a task status; confirm colour change + checkmark pulse without page reload |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 60s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending

*Note: `wave_0_complete` and `nyquist_compliant` flip to `true` when plan 14-01 Task 3 completes and the sign-off items above are ticked. See 14-01-PLAN.md Task 3 acceptance criteria for the grep-verifiable flip.*
