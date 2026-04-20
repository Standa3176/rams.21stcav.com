---
phase: 14-mobile-field-view
plan: "01"
subsystem: test-infrastructure
tags: [wave-0, test-scaffold, fixtures, factories, nyquist, heic, time-tracking]
requires:
  - Phase 12 install_programmes + install_tasks schema (shipped)
  - Phase 13 task assignment (shipped)
provides:
  - tests/Fixtures/sample.heic (genuine HEIC binary for conversion tests)
  - tests/Fixtures/sample.jpg (JPEG passthrough fixture)
  - InstallProgrammeFactory / InstallTaskFactory (usable by all 14-0x plans)
  - InstallTaskPhotoFactory / TimeEntryFactory (placeholders for future models)
  - 9 Phase 14 red tests (6 Feature + 3 Unit)
  - 14-VALIDATION.md wave_0_complete + nyquist_compliant flags set true
affects:
  - Phase 14 plans 02, 03, 04, 05 (each must green their scoped tests)
  - Phase 16 (HeicImageConverter reused for commissioning evidence photos)
tech-stack:
  added: []
  patterns:
    - "Wave 0 red-first scaffolding — test files exist + fail meaningfully before implementation"
    - "Factory references future models (InstallTaskPhoto, TimeEntry) by FQCN"
    - "Imagick-gated tests use markTestSkipped guards (D-11 fail-loudly honoured elsewhere)"
    - "SQLite schema tests guard with Schema::hasTable + PRAGMA foreign_keys=ON"
key-files:
  created:
    - tests/Fixtures/sample.heic
    - tests/Fixtures/sample.jpg
    - tests/Fixtures/README.md
    - database/factories/InstallProgrammeFactory.php
    - database/factories/InstallTaskFactory.php
    - database/factories/InstallTaskPhotoFactory.php
    - database/factories/TimeEntryFactory.php
    - tests/Feature/FieldView/FieldPageTest.php
    - tests/Feature/FieldView/FieldViewResponsivenessTest.php
    - tests/Feature/InstallTasks/InstallTaskStatusUpdateTest.php
    - tests/Feature/InstallTasks/InstallTaskPhotoUploadTest.php
    - tests/Feature/InstallTasks/InstallTaskNotesTest.php
    - tests/Feature/TimeEntries/TimeEntryTest.php
    - tests/Unit/Services/HeicImageConverterTest.php
    - tests/Unit/Migrations/InstallTaskPhotosSchemaTest.php
    - tests/Unit/Migrations/TimeEntriesSchemaTest.php
  modified:
    - .planning/phases/14-mobile-field-view/14-VALIDATION.md
decisions:
  - "Rename private `setup()` helper to `scaffold()` across 4 test files — PHP method names are case-insensitive so private setup() clashes with parent TestCase::setUp()"
  - "Nokia HEIF public sample (`autumn_1440x960.heic`) chosen over iPhone capture — reproducible across dev boxes, no EXIF scrubbing required, known-good ftypmif1/heic brand"
  - "Generate sample.jpg via PHP GD (not magick CLI) — avoids ImageMagick install requirement on dev machines; result is a genuine FFD8FF JPEG"
  - "Guard `test_install_task_id_is_foreign_key` with explicit Schema::hasTable + `PRAGMA foreign_keys = ON` — prevents vacuous pass when table is missing and ensures SQLite actually enforces the FK once the migration ships"
metrics:
  started: "2026-04-20T09:45:00Z"
  completed: "2026-04-20T10:15:00Z"
  duration: "~30 minutes"
  tasks: 3
  commits: 3
  files_created: 16
  files_modified: 1
---

# Phase 14 Plan 01: Wave 0 Test Scaffold Summary

Red-first test scaffold for Phase 14 Mobile Field View — committed 9 failing tests, 4 factories, and 2 real binary fixtures so implementation plans 14-02 through 14-05 have concrete automated verify commands to green.

## What Shipped

**Fixtures (Task 1):**
- `tests/Fixtures/sample.heic` — 287 KB Nokia HEIF public reference (ftypmif1/heic brand, no EXIF)
- `tests/Fixtures/sample.jpg` — 2.4 KB GD-generated JPEG (FFD8FF magic)
- `tests/Fixtures/README.md` — documents source, size, magic-byte verification, and EXIF-scrub instructions for future replacements

**Factories (Task 2):**
- `InstallProgrammeFactory` with `draft()` state
- `InstallTaskFactory` with `inProgress()` / `complete()` states
- `InstallTaskPhotoFactory` (references `App\Models\InstallTaskPhoto` — created by plan 14-02)
- `TimeEntryFactory` with `closed()` state (references `App\Models\TimeEntry` — created by plan 14-02)

**Tests (Task 3) — 9 files, 47 test cases, 42 failing red, 3 imagick-skipped, 2 passing:**

| Test File | Requirement | Status |
|-----------|-------------|--------|
| FieldView/FieldPageTest | INST-03a, INST-03b (ownership + engineer scope) | 7 red |
| FieldView/FieldViewResponsivenessTest | INST-03a/h (mobile-first, no SW) | 2 red, 1 pass |
| InstallTasks/InstallTaskStatusUpdateTest | INST-03c/g, D-05..D-07 | 8 red |
| InstallTasks/InstallTaskPhotoUploadTest | INST-03d/e, D-11/D-12 | 7 red, 1 imagick-skipped |
| InstallTasks/InstallTaskNotesTest | INST-03f | 4 red |
| TimeEntries/TimeEntryTest | INST-04g | 6 red |
| Services/HeicImageConverterTest | INST-03e, D-11 | 1 red, 2 imagick-skipped |
| Migrations/InstallTaskPhotosSchemaTest | D-09 | 2 red, 1 pass (vacuous — table absent) |
| Migrations/TimeEntriesSchemaTest | INST-04g (partial) + `last_heartbeat_at` day-one | 4 red, 1 pass (`does_not_have_category_yet` vacuously correct) |

**VALIDATION.md updates:**
- Frontmatter: `wave_0_complete: true`, `nyquist_compliant: true`
- Per-Task Verification Map: File Exists column flipped `⬜`/`⬜ W0` → `✅ W0` for 14 of 15 rows (14-05-T3 stays `⬜` — manual checkpoint, no test file)
- Sign-Off: all 6 bullets ticked
- Approval: `Wave 0 scaffold complete`

## Verification Evidence

```
php artisan test --filter="FieldPage|FieldView|InstallTaskStatusUpdate|InstallTaskPhotoUpload|InstallTaskNotes|TimeEntry|HeicImageConverter|InstallTaskPhotosSchema|TimeEntriesSchema"

  Tests:    42 failed, 3 skipped, 2 passed (42 assertions)
  Duration: 5.60s
```

Failures are meaningful (per plan acceptance criterion):
- 404 responses for missing routes (`/projects/{id}/programme`, `/install-tasks/{id}/status`, `/projects/{id}/time-entries/start`, etc.)
- `Class "App\Models\InstallTaskPhoto" not found` / `Class "App\Models\TimeEntry" not found` — expected until plan 14-02 ships the models
- `Class "App\Services\HeicImageConverter" not found` — expected until plan 14-03 ships the converter
- Missing table errors on `install_task_photos` / `time_entries` — expected until plan 14-02 ships the migrations

No test fatal-halted the suite. The only `markTestSkipped` calls are the three imagick-gated tests (HeicImageConverterTest happy-path + passthrough, InstallTaskPhotoUploadTest `test_heic_converts_to_jpeg`) — gated on `extension_loaded('imagick')` per D-11, returning to the queue only when the Imagick extension is available on the test runner.

Two passes are vacuously-correct negations that will remain correct once implementation ships (`test_view_avoids_wide_fixed_pixel_widths` — 404 body contains no forbidden widths; `test_does_not_have_category_column_yet` — nonexistent table has no category column).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Renamed private `setup()` helper to `scaffold()` across 4 test files**

- **Found during:** Task 3 verification run
- **Issue:** The plan specified `private function setup(array $taskAttrs = []): array` as a test-level helper. PHP method names are case-insensitive, so a private `setup()` clashes with the parent `Illuminate\Foundation\Testing\TestCase::setUp()` (protected) — this raises `Fatal error: Access level to Tests\...\setup() must be protected (as in class Illuminate\Foundation\Testing\TestCase) or weaker` and halts the entire PHPUnit run, preventing the "tests are red, not fatal" acceptance criterion from being satisfied.
- **Fix:** Renamed `setup(...)` → `scaffold(...)` in InstallTaskStatusUpdateTest, InstallTaskPhotoUploadTest, InstallTaskNotesTest. InstallTaskPhotoUploadTest's `setupWithPhoto()` call to `$this->setup()` was updated to `$this->scaffold()`.
- **Files modified:** 3 test files (renamed private helper + all call sites)
- **Commit:** 2286bf3

**2. [Rule 2 - Correctness] Tightened `InstallTaskPhotosSchemaTest::test_install_task_id_is_foreign_key`**

- **Found during:** Task 3 post-run analysis (tests reported 3 false-positive passes)
- **Issue:** The plan's test used `expectException(QueryException::class)` and attempted an insert with a bogus FK, assuming this would only throw once the table + FK exist. But when the `install_task_photos` table is absent (Wave 0 red state), the insert still throws a `QueryException` ("no such table") — which matches the `expectException` and makes the test vacuously pass for the wrong reason, hiding missing FK enforcement later.
- **Fix:** Added an explicit `Schema::hasTable` assertion before the insert, and set `PRAGMA foreign_keys = ON` so SQLite actually enforces the FK constraint once the table + FK ship in plan 14-02.
- **Files modified:** tests/Unit/Migrations/InstallTaskPhotosSchemaTest.php
- **Commit:** 2286bf3

### Scope Boundary Notes

- **Worktree base mismatch handled pre-execution:** worktree was created from commit `6f23f370` (older H-01 branch) instead of the expected feature branch HEAD `c9a72032`. Recovered via `git reset --hard HEAD` after a `--soft` reset to the expected base realigned working tree with `c9a72032`. No planning artifacts were lost.
- **Composer install required:** worktree did not include a `vendor/` directory. Ran `composer install` once to enable PHPUnit execution; no dependency upgrades, `composer.lock` untouched.
- **.env:** copied from `.env.example` and `php artisan key:generate --force` run to satisfy `APP_KEY` requirement. `.env` is gitignored — not committed.

### Authentication Gates

None — test scaffold only, no external service calls.

## Known Stubs

None in shipped code. All 9 test files are intentionally red-stubbed (referencing future routes / models / services / migrations that plans 14-02 through 14-05 will ship). These are not stubs in the "empty data flowing to UI" sense — they are Wave 0 Nyquist red-tests with tracked implementation plans.

## Threat Flags

None. Plan's threat register (T-14-00a/b/c) covered test-scaffold threats only (fixture binaries, factory DB isolation, README disclosure) and all mitigations are honoured: HEIC/JPEG fixtures verified as genuine via magic-byte checks, factories isolated via `RefreshDatabase`, README documents source with no credentials or customer data.

## Self-Check: PASSED

**Files created (verified with `ls`):**
- FOUND: tests/Fixtures/sample.heic (293,608 bytes)
- FOUND: tests/Fixtures/sample.jpg (2,466 bytes)
- FOUND: tests/Fixtures/README.md
- FOUND: database/factories/InstallProgrammeFactory.php
- FOUND: database/factories/InstallTaskFactory.php
- FOUND: database/factories/InstallTaskPhotoFactory.php
- FOUND: database/factories/TimeEntryFactory.php
- FOUND: tests/Feature/FieldView/FieldPageTest.php
- FOUND: tests/Feature/FieldView/FieldViewResponsivenessTest.php
- FOUND: tests/Feature/InstallTasks/InstallTaskStatusUpdateTest.php
- FOUND: tests/Feature/InstallTasks/InstallTaskPhotoUploadTest.php
- FOUND: tests/Feature/InstallTasks/InstallTaskNotesTest.php
- FOUND: tests/Feature/TimeEntries/TimeEntryTest.php
- FOUND: tests/Unit/Services/HeicImageConverterTest.php
- FOUND: tests/Unit/Migrations/InstallTaskPhotosSchemaTest.php
- FOUND: tests/Unit/Migrations/TimeEntriesSchemaTest.php

**Commits (verified with `git log`):**
- FOUND: 93a4dd0 test(14-01): add HEIC + JPEG test fixtures for Wave 0
- FOUND: c3e1d10 test(14-01): add 4 Phase 14 factories for Wave 0 scaffold
- FOUND: 2286bf3 test(14-01): add 9 Wave 0 test files + flip VALIDATION flags

**VALIDATION.md flags (verified with `grep`):**
- FOUND: `wave_0_complete: true` (1 match in frontmatter)
- FOUND: `nyquist_compliant: true` (1 match in frontmatter + 1 reference in sign-off bullet; frontmatter value correct)
- FOUND: `Approval: Wave 0 scaffold complete` (line 105)
- FOUND: 6 of 6 Sign-Off bullets ticked (`- [x]`)
- FOUND: 15 Phase 14 rows in Per-Task Verification Map (>=13 required)
- FOUND: 0 "TBD" placeholders

**Test suite runs red (verified with `php artisan test`):**
- `Tests:    42 failed, 3 skipped, 2 passed (42 assertions)` — tests ARE running and reporting failures, not fatal-halting
