---
phase: 44-labour-resources
plan: 04
subsystem: testing
tags: [phpunit, laravel, source-guard, http-feature-test, privacy]

# Dependency graph
requires:
  - phase: 44-labour-resources
    plan: 01
    provides: "labour_resources table + LabourResource model (toClientSafeArray(), factory)"
  - phase: 44-labour-resources
    plan: 02
    provides: "Admin CRUD (explicitly excluded from the client-facing scan)"
  - phase: 44-labour-resources
    plan: 03
    provides: "PM-facing LabourResourceSelect component (not wired into any client-facing surface)"
provides:
  - "tests/Feature/Security/LabourResourceClientSurfacePrivacyTest.php: the load-bearing D-04 proof"
  - "Static source-guard over 20 enumerated client-facing controllers/views, proven non-vacuous"
  - "Live HTTP-render proof against survey.show and public-worksheet.show with a seeded, distinctive email/phone, both active and inactive resource states"
affects: [45-visits, 46-prepare-panel]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Non-vacuous static source-guard: allow-list of real files scanned for a literal marker, plus a meta-test that appends the marker (via a fully-qualified class reference, not a lowerCamelCase variable, since that literal substring is what the scanner checks) to a copy of real file contents in memory and asserts the scan logic would have caught it — mirrors MissingRiskRefGateSourceGuardTest's technique"
    - "Self-contained fixture duplication over shared traits for a security-proof test file — makeSurveyWithRoom()/makeWorksheetWithContact() were copied verbatim from their originating test files rather than extracted, per the plan's explicit instruction, so this file's story is complete without cross-file navigation"

key-files:
  created:
    - tests/Feature/Security/LabourResourceClientSurfacePrivacyTest.php
  modified: []

key-decisions:
  - "The non-vacuity meta-test simulates the regression via `\\App\\Models\\LabourResource::find(1)->email` rather than a `$labourResource` variable — a first draft using the lowerCamelCase variable name failed the meta-test itself, because the literal marker the scanner checks for is the PascalCase string 'LabourResource', which a variable named `$labourResource` does not contain. This is exactly the kind of thing the meta-test exists to catch, and it caught it during this plan's own execution."
  - "Both HTTP-render tests are duplicated for is_active=true and is_active=false per the plan's explicit requirement ('this is not conditioned on is_active') rather than parameterized with a data provider, matching this codebase's convention of explicit named test methods over PHPUnit data providers (no @dataProvider usage found elsewhere in tests/Feature/Security)."
  - "The client-facing path list was re-derived by live grep/find at execution time (not hand-copied from the plan's interfaces note) and matched exactly, including two files not explicitly named in the plan's prose but caught by 'every file under `resources/views/pdf/om-manual/`' — create.blade2703.php, an apparent backup file with a .php (not .blade.php) extension. It was included because the plan's action says 'every file under' that directory, and a stray backup file rendered by no route is still the safest thing to scan rather than silently narrow the list."

patterns-established:
  - "Duplicate cheap assertions across test files for a single-file-review story: toClientSafeArray()'s key-shape is re-asserted here (in addition to Plan 44-01's unit test) so a future reviewer of the D-04 boundary finds the complete contract in one file, per the plan's explicit rationale."

requirements-completed: [LR-04, LR-05]

# Metrics
duration: 40min
completed: 2026-09-19
---

# Phase 44 Plan 04: D-04 Privacy Boundary Proof Summary

**`LabourResourceClientSurfacePrivacyTest` proves — with a non-vacuous static source-guard over 20 real client-facing files and live HTTP GETs against the actual `survey.show`/`public-worksheet.show` token routes with a seeded, distinctive email/phone — that no client-facing surface in the codebase can render a labour resource's contact details, closing Phase 44.**

## Performance

- **Duration:** ~40 min
- **Started:** 2026-09-19 (approx, after 44-03)
- **Completed:** 2026-09-19
- **Tasks:** 2/2 completed
- **Files modified:** 1 created, 0 modified

## Accomplishments
- Re-derived the client-facing surface list live at execution time (`grep -n "survey/{token}\|worksheet/{token}" routes/web.php`, `grep -n "engineer-report" routes/web.php`, `find resources/views/public-survey|pdf/om-manual|pdf/site-survey -type f`) rather than trusting the plan's prose copy, confirming it still matched exactly
- Static scan (`test_no_client_facing_file_references_labour_resource`) checks all 20 enumerated files for the literal `LabourResource` substring — zero hits today, as expected, since nothing consumes the model outside admin/PM surfaces
- `test_every_enumerated_path_exists` guards the allow-list itself against silent drift (a typo or a renamed file would narrow coverage without failing loudly otherwise)
- Non-vacuity meta-test (`test_guard_would_fail_if_a_client_facing_view_referenced_labour_resource`) proves the scan logic would actually catch a regression — and, during this plan's own execution, an initial draft using a `$labourResource` variable in the simulated regression genuinely failed this meta-test (the marker is the PascalCase class name, not a lowerCamelCase variable), which is exactly the failure mode the meta-test exists to surface; fixed by using a fully-qualified class reference instead
- Four HTTP-render tests GET the real `survey.show` and `public-worksheet.show` routes (built via `makeSurveyWithRoom()`/`makeWorksheetWithContact()`, copied verbatim from `SurveyDownloadFormTest`/`PublicWorksheetHeaderContactTest` per the plan's self-contained-file instruction) against a seeded `LabourResource` carrying `engineer-privacy-check@example.test` / `07700 900999` — both active and inactive states assert the response body contains neither string
- `toClientSafeArray()`'s `['id', 'name']` key-shape contract is re-asserted here as the single-file, end-to-end story of D-04

## Task Commits

Each task was committed as a single test file (no separate RED/GREEN split — see TDD Gate Compliance below):

1. **Task 1 + Task 2: `LabourResourceClientSurfacePrivacyTest` (source-guard + HTTP render proof)** - `04e7dc15` (test)

**Plan metadata:** (this commit follows this summary)

## Files Created/Modified
- `tests/Feature/Security/LabourResourceClientSurfacePrivacyTest.php` - 8 tests: static source-guard, allow-list existence check, non-vacuity meta-test, 4 HTTP-render assertions (survey/worksheet × active/inactive), and a `toClientSafeArray()` contract re-assertion

## Decisions Made
- **Meta-test uses a fully-qualified class reference, not a variable name:** see key-decisions above — this was discovered as a genuine bug in the first draft, caught by the meta-test itself doing its job.
- **Explicit duplicate test methods for active/inactive states**, not a data provider — matches existing convention in `tests/Feature/Security/` and keeps failure messages unambiguous about which state broke.
- **`create.blade2703.php` included in the scan** — an apparent stray backup file under `resources/views/pdf/om-manual/`, included because the plan's action specifies "every file under" that directory literally.
- **Two pre-existing backup/scratch files in `resources/views/pdf/` were deliberately NOT added to the scan**: `pdf/rams.blade - keep boarder.php` and `pdf/rams.blade-keep-borders.php` (both discovered via `find resources/views/pdf -maxdepth 1 -type f` during re-derivation). These are not in the plan's enumerated list (`rams.blade.php`, `rams-v2.blade.php`, `om-manual.blade.php`, `commissioning-snagging.blade.php`, `mini-om.blade.php` only) and are not referenced by any route — they read as manual backups a developer left in place. Flagged here rather than silently added or silently ignored; if these files are ever wired to a route, Plan 44-04's scan will need extending.

## Deviations from Plan

None affecting scope or production code. One implementation-detail deviation, self-corrected during Task 1's own non-vacuity check (see key-decisions): the simulated regression in the meta-test initially used a `$labourResource` variable, which does not contain the literal `LabourResource` marker string; corrected to reference the fully-qualified class name directly. No production code changed — this plan is test-only as required.

## TDD Gate Compliance

Both tasks are marked `tdd="true"` but were implemented together as a single test file in one commit, since Task 2 extends the same file Task 1 creates and the plan's own verification step re-runs the whole file after each task. No standalone `test(...)`-then-`feat(...)` RED/GREEN pair exists because no production code exists for this plan to make pass — the entire deliverable is the test file itself, run once it is complete, with all 8 assertions passing on the first full run (after the meta-test caught and let us fix the variable-vs-class-reference bug described above, itself proof the guard's own logic works in-process before final commit).

## Known Stubs

None. No UI, no data-wiring — a pure test file.

## Threat Flags

None. This plan implements the mitigation for T-44-09 and T-44-10 from its own `<threat_model>`; no new surface was introduced.

## Issues Encountered

One self-caught issue during development, not left in the codebase: the non-vacuity meta-test's first draft used a lowerCamelCase variable name in the simulated regression string, which does not contain the PascalCase marker the scanner checks for, causing the meta-test to correctly fail. Fixed by using a fully-qualified class reference (`\App\Models\LabourResource::find(1)->email`) instead — documented above as it demonstrates the meta-test doing exactly its job.

Separately: a full `php artisan test --filter=Rams` run showed `1 failed, 874 passed` on first execution (a `Browsershot`/Windows-env-related failure inside `vendor/spatie/browsershot`, unrelated to this plan's test-only change — no file this plan touches is on that call path). A second, immediate re-run of the same filter returned `875 passed, 0 failed`, matching the 44-01 baseline exactly, confirming the first result was an environmental flake, not a regression introduced here.

## Test Results

- **Before (baseline, read from real output):**
  - `php artisan test --filter=LabourResource` — 17 passed / 0 failed
  - `php artisan test --filter=Worksheet` — 247 passed / 0 failed
  - `php artisan test --filter=Rams` — 875 passed / 0 failed
- **After (this plan's changes applied):**
  - `php artisan test --filter=LabourResourceClientSurfacePrivacyTest` — 8 passed / 0 failed (new)
  - `php artisan test --filter=LabourResource` — 25 passed / 0 failed (17 baseline + 8 new)
  - `php artisan test --filter=Worksheet` — 249 passed / 0 failed (247 baseline + 2 of this plan's new worksheet-named test methods matching the substring filter; no existing Worksheet test regressed)
  - `php artisan test --filter=Rams` — 875 passed / 0 failed on re-run (first run showed 1 flaky Browsershot/Windows-env failure unrelated to this change; re-run matched baseline exactly)

## Next Steps

Phase 44 is now closed. All four plans (schema, admin CRUD, PM selector, privacy proof) are complete. Phase 45 (`visits` table) is the first real consumer of `LabourResource`; Phase 46 (prepare panel) is the first UI surface beyond admin. Any future consumer of this model on a client-facing surface must add itself to `LabourResourceClientSurfacePrivacyTest::CLIENT_FACING_PATHS` and keep passing `toClientSafeArray()`, not the raw model, to any client-visible template.

## Self-Check: PASSED

- `tests/Feature/Security/LabourResourceClientSurfacePrivacyTest.php` — FOUND on disk
- Commit `04e7dc15` — FOUND in `git log`
