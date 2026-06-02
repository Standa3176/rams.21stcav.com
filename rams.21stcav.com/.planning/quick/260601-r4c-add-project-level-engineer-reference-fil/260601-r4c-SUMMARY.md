---
quick_id: 260601-r4c
plan: 01
type: summary
requirements: [r4c-01]
key_files:
  created:
    - database/migrations/2026_06_01_120000_create_project_reference_files_table.php
    - app/Models/ProjectReferenceFile.php
    - app/Services/ProjectReferenceFileService.php
    - app/Http/Controllers/ProjectReferenceFileController.php
    - config/reference_files.php
    - resources/views/projects/_engineer-reference-files-card.blade.php
    - resources/views/partials/_engineer-reference-drawer.blade.php
    - tests/Feature/ProjectReferenceFiles/SchemaTest.php
    - tests/Unit/Services/ProjectReferenceFileServiceTest.php
    - tests/Feature/ProjectReferenceFiles/AdminUploadTest.php
    - tests/Feature/ProjectReferenceFiles/AdminDeleteTest.php
    - tests/Feature/ProjectReferenceFiles/PublicWorksheetDownloadTest.php
    - tests/Feature/ProjectReferenceFiles/PublicSurveyDownloadTest.php
    - tests/Feature/ProjectReferenceFiles/EndToEndTest.php
  modified:
    - app/Models/Project.php
    - app/Services/DocumentArtifactStorage.php
    - app/Http/Controllers/PublicWorksheetController.php
    - app/Http/Controllers/PublicSurveyController.php
    - app/Http/Controllers/SurveyController.php
    - routes/web.php
    - resources/views/projects/show.blade.php
    - resources/views/worksheets/public-show.blade.php
    - resources/views/surveys/show.blade.php
metrics:
  duration_minutes: 60
  tasks: 6
  files_created: 14
  files_modified: 9
  tests_added: 41
  assertions_added: 106
---

# Quick Task 260601-r4c: Engineer Reference Files Summary

**One-liner:** Project-level upload channel (PDF/CAD/Office/CSV ≤20 MB) with H-07 storage, cross-tenant 403-guarded public download endpoints for both worksheet and survey tokens, and a self-contained drawer partial that renders inline PDFs / image gallery / type-chip download buttons in the engineer's on-site view.

## What Landed

| Surface | What |
|---------|------|
| **Schema** | `project_reference_files` table (1 migration). FK cascade on project_id, nullOnDelete on uploaded_by_user_id, `stored_path` widened to 500 chars for ulid + per-project nesting. **DEPLOY: needs `php artisan migrate` on prod.** |
| **Model** | `App\Models\ProjectReferenceFile` (belongsTo Project, belongsTo User via uploaded_by_user_id) + `Project::referenceFiles()` hasMany relation. |
| **Storage** | `DocumentArtifactStorage::TYPE_REFERENCE = 'reference-files'` constant added; `types()` array extended. NO LEGACY_ROOTS entry (post-H-07 feature). |
| **Service** | `App\Services\ProjectReferenceFileService` — finfo-based MIME sniff (NEVER `getClientMimeType`), extension allowlist + explicit deny-list (svg/html/js/php/exe/...), 20 MB cap, octet-stream→dwg/dxf gate, zip→xlsx/docx gate, filename sanitiser (strips `..`, `/`, `\`, null bytes, control chars; truncates to 100 chars; idempotent). `streamResponse` + `dispositionFor` shared across admin + 2 public controllers. |
| **Config** | `config/reference_files.php` — allowlist/denylist/max-size centralised; tests override via `config()`. |
| **Admin** | `ProjectReferenceFileController` (store/show/destroy) on 3 authed routes inside the `auth` middleware group, throttled 30/60/30. `scopeBindings()` on show + destroy → cross-project file_id returns 404 before the controller. Shared-workspace auth model (any authed user, per 260525-pyu/s8b). |
| **Admin UI** | `projects/_engineer-reference-files-card.blade.php` partial, included from `projects/show.blade.php` directly ABOVE the Danger Zone block. File-type chip + click-to-download + delete form-button with the existing `data-confirm` pattern. |
| **Public download** | `PublicWorksheetController::downloadReferenceFile` + `PublicSurveyController::downloadReferenceFile`. Both controllers' FIRST check (before any storage I/O) is `abort_unless($file->project_id === $worksheet/$survey->project_id, 403)` — the load-bearing security guard (T-r4c-01 / T-r4c-02). 2 public routes throttled 60/min. |
| **Public UI** | Shared partial `partials/_engineer-reference-drawer.blade.php`. PDFs render inline in expandable `<details>` with `<iframe ... #view=FitH>` + Download button; images as a thumbnail gallery; CAD/Office/CSV as tap-to-download button rows. Self-contained inline styling (brand teal `#178A95`) so it renders identically on both pages. Hides itself when zero files. Inserted ABOVE Site Logistics on both `worksheets/public-show.blade.php` (line ~583) and `surveys/show.blade.php` (line ~117). |
| **Eager-load** | `PublicWorksheetController::show` + `SurveyController::show` extended to load `project.referenceFiles` so the drawer doesn't N+1. |

## Test Counts (per task)

| Task | Test class | Tests | Assertions |
|------|------------|-------|------------|
| 1 | SchemaTest | 3 | 6 |
| 2 | ProjectReferenceFileServiceTest (+1 in Task 6) | 14 | 30 |
| 3 | AdminUploadTest + AdminDeleteTest | 11 | 38 |
| 4 | PublicWorksheetDownloadTest + PublicSurveyDownloadTest | 12 | 18 |
| 6 | EndToEndTest | 1 | 14 |
| **Total** | **7 classes** | **41** | **106** |

All 41 tests pass via `php vendor/bin/phpunit --filter "ProjectReferenceFile"`.

## RamsRenderRegression Canary

**GREEN — 3/3 / 9 assertions.** Verified both before-Task-1 (baseline) and after-Task-6 (post-implementation). D-LOCK byte-equivalence preserved across all four document generators (manual form / quote import / survey-derived). This feature never touches the RAMS/O&M/Worksheet/Cable services by construction.

## Full Suite Regression Check

Baseline (before this plan, captured pre-Task-1): **1334 pass, 12 fail, 8 skip / 5172 assertions / 280s**. The 12 pre-existing failures are unrelated to this work — example: `Tests\Feature\Worksheet\PublicWorksheetSignoffTest::resubmit appends a second signoff` fails on a mock URL mismatch (`http://rams.21stcav.com.test` vs token-suffixed expected URL — environment / route signing artefact, pre-dates 260601-r4c).

**Post-Task-6 full-suite result: 1375 pass, 12 fail, 8 skip / 5278 assertions / 335s.**

Net change: **+41 passed (exactly my new tests), +106 assertions (exactly my new assertions), 0 new failures.** The 12 failures are the same pre-existing ones (e.g. `Tests\Feature\Worksheet\PublicWorksheetSignoffTest::resubmit appends a second signoff` — pre-dates 260601-r4c).

## Deviations from Plan

### Rule 1 — Bug auto-fixed

**1. Disposition logic was MIME-based; switched to extension-based**

- **Found during:** Task 4 (the DWG happy-path attachment test failed).
- **Issue:** The naive `image/*` MIME prefix triggered `inline` Content-Disposition for DWG files, because Windows `libmagic` sniffs the AC1027 magic header as `image/vnd.dwg`. Browsers can't actually render DWG inline, so `inline` was both wrong and useless.
- **Fix:** `dispositionFor()` now gates on the file's EXTENSION (pdf/png/jpg/jpeg/webp → inline; everything else → attachment) instead of MIME family. The user-visible extension is the canonical signal for inline-vs-attachment.
- **Files modified:** `app/Services/ProjectReferenceFileService.php`
- **Commit:** `f0a8a1d`

### Rule 2 — Missing critical functionality auto-added

**2. application/zip MIME accepted only when extension is xlsx/docx**

- **Found during:** Task 6 end-to-end test (xlsx upload silently failed).
- **Issue:** Real-world XLSX/DOCX files are ZIP containers. `finfo` on the bytes lands at bare `application/zip` when the internal OOXML markers don't trigger the more specific MIME detection (depends on libmagic version + the file's internal layout). The original allowlist would reject legit Office uploads in this case.
- **Fix:** Mirrors the existing octet-stream→dwg/dxf gate exactly. Added `application/zip` to `config/reference_files.php`'s allowed_mimes; service-side gate rejects zip MIME unless the extension is in `{xlsx, docx}`. Plain `.zip` uploads still bounce on the extension allowlist (zip extension is not allowed at all).
- **Files modified:** `config/reference_files.php`, `app/Services/ProjectReferenceFileService.php`
- **Tests:** new unit test `test_zip_sniffed_bytes_accepted_when_extension_is_xlsx` (Task 6 commit).
- **Commit:** `0131393`

No Rule 3 (blocking issue) or Rule 4 (architectural decision) deviations.

## Authentication Gates

None encountered. All tasks executed autonomously.

## Threat Surface Coverage (matches plan's threat_model)

| Threat | Disposition | Implemented |
|--------|-------------|-------------|
| T-r4c-01 worksheet cross-tenant | mitigate | `abort_unless($file->project_id === $worksheet->project_id, 403)` BEFORE storage I/O; tested by `PublicWorksheetDownloadTest::test_cross_tenant_guard_returns_403`. |
| T-r4c-02 survey cross-tenant | mitigate | Same shape against `$survey->project_id`; tested by `PublicSurveyDownloadTest::test_cross_tenant_guard_returns_403`. |
| T-r4c-03 mime spoofing | mitigate | finfo `getMimeType()` only; getClientMimeType never called (grep verified). |
| T-r4c-04 filename traversal / null byte | mitigate | sanitiseFilename idempotent + tested with `../../etc/passwd.pdf` and `evil\x00.pdf`. |
| T-r4c-05 DoS | mitigate | 20 MB cap (configurable); throttle 30/60/30 per minute on upload/download/delete. |
| T-r4c-06 cross-project file_id smuggling on admin DELETE | mitigate | `->scopeBindings()` on show + destroy routes; tested by `AdminDeleteTest::test_cross_project_file_id_returns_404`. |
| T-r4c-07 repudiation | mitigate | `uploaded_by_user_id` + `uploaded_at` columns; FK nullOnDelete preserves audit trail. |
| T-r4c-08 DWG/DXF octet-stream sniff | mitigate | Service-side gate: octet-stream MIME only accepted when ext ∈ {dwg, dxf}; tested. |
| T-r4c-09 PDF JS in iframe | accept (per plan) | Browser PDF viewers sandbox PDF JS from parent frame; engineer-only links. |
| T-r4c-10 no virus scan | accept (per plan) | Engineer-facing only; Phase-2 enhancement. |
| T-r4c-SC supply chain | mitigate | Zero new composer/npm deps. Used only Laravel native (Str::ulid, finfo). |

## Deploy Runbook

1. **Migration is REQUIRED on production.** New table: `project_reference_files`. Run:
   ```bash
   sudo -u stcav php artisan migrate --force
   ```
   (Standard RAMS prod deploy pattern per `rams-prod-hosting` memory.)
2. No new composer/npm packages. No config changes required (config/reference_files.php is ships-with-the-code).
3. No queue worker changes — no async jobs in this feature.
4. Storage: writes land under `storage/app/documents/reference-files/{project_id}/` (auto-created by `DocumentArtifactStorage::writePath`). The standard `documents` disk root must be writable by the `stcav` user — already established by H-07.
5. Authorization is the shared-workspace model (260525-pyu/s8b) — any authed user can upload/delete; no admin gate.

## Commits

| # | Hash | Message |
|---|------|---------|
| 1 | `3e8ab36` | feat(260601-r4c): add project_reference_files schema + model + TYPE_REFERENCE |
| 2 | `d271837` | feat(260601-r4c): ProjectReferenceFileService — finfo mime + sanitiser + H-07 |
| 3 | `580f012` | feat(260601-r4c): admin controller + routes + projects/show reference files card |
| 4 | `f0a8a1d` | feat(260601-r4c): public download endpoints + cross-tenant 403 guard |
| 5 | `c3f17c2` | feat(260601-r4c): engineer reference drawer on worksheet + survey public links |
| 6 | `0131393` | test(260601-r4c): EndToEndTest + accept application/zip for xlsx/docx |

## Open Follow-Ups (Phase 2 / Out of Scope)

- Virus scanning on upload (T-r4c-10 accepted — flag for engineer-link-exposed-to-non-staff Phase 2).
- Image thumbnail resize service (currently serves the full original image; fine for typical site photo sizes but a 12 MP photo will load slowly on mobile cellular).
- DWG inline viewer (currently downloads only — viewing requires AutoCAD on engineer's device).
- Phase-2 server-side virus scan integration (clamav) for any externally-uploaded reference files.

## Known Stubs

None. Every UI surface is wired to real data through the full vertical stack (form → controller → service → storage → DB → public read).

## Self-Check

- [x] Migration file exists: `database/migrations/2026_06_01_120000_create_project_reference_files_table.php` — FOUND
- [x] Model file exists: `app/Models/ProjectReferenceFile.php` — FOUND
- [x] Service file exists: `app/Services/ProjectReferenceFileService.php` — FOUND
- [x] Admin controller exists: `app/Http/Controllers/ProjectReferenceFileController.php` — FOUND
- [x] Config file exists: `config/reference_files.php` — FOUND
- [x] Admin card partial exists: `resources/views/projects/_engineer-reference-files-card.blade.php` — FOUND
- [x] Public drawer partial exists: `resources/views/partials/_engineer-reference-drawer.blade.php` — FOUND
- [x] All 6 commits resolve in `git log` — FOUND (`3e8ab36`, `d271837`, `580f012`, `f0a8a1d`, `c3f17c2`, `0131393`)
- [x] Routes registered: `php artisan route:list --name=reference-files` returns 3; `--name=files.serve` returns 2 — VERIFIED
- [x] All 41 plan tests GREEN
- [x] RamsRenderRegression canary STILL GREEN 3/3

## Self-Check: PASSED
