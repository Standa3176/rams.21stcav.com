---
phase: 20-drawing-export-pipeline-o-m-integration
plan: 01
subsystem: drawings
tags: [drawings, bound-pdf, sheet-numbering, zip-bundle, fpdi, browsershot]
requires:
  - 17-01 (project_drawings table + ProjectDrawing model + DocumentArtifactStorage::TYPE_DRAWING)
  - 17-02 (SchematicGeneratorService — emits generated_svg consumed by per-drawing PDF render)
  - 17-03 (DrawingExportRendererService — renderPdf/renderSvg/renderPng + handover PNG cache)
  - 18-01 (KIND_RACK column + rack scaffolding in DrawingService)
  - 18-03 (RackElevationRenderService — emits rack SVG; ProjectPolicy registered)
provides:
  - SheetNumberAllocator (DRAW-23)
  - BoundPdfBuilderService (DRAW-21)
  - BuildBoundPdfJob (async bound PDF assembly)
  - BoundPdfReadyMail (completion notification)
  - 3 new routes: projects.drawings.bound-pdf, .bound-pdf.build, .bundle
  - bound-cover Blade view + sheet column in title-block + index UI block
affects:
  - app/Models/ProjectDrawing.php (sheet_number added to $fillable)
  - app/Services/Drawings/DrawingService.php (auto-allocates sheet_number on createForProject)
  - app/Http/Controllers/ProjectDrawingController.php (downloadBoundPdf + regenerateBoundPdf + downloadBundle + index() boundPdfStaleBadge calc)
  - resources/views/projects/drawings/index.blade.php (Project Documents block + sheet column + regen-needed badge)
  - resources/views/pdf/drawings/_title-block.blade.php (Sheet row consuming $drawing->sheet_number)
  - composer.json + composer.lock (setasign/fpdi:^2.6 + setasign/fpdf:^1.8.6)
  - routes/web.php (3 new bound-PDF + ZIP routes)
tech-stack:
  added:
    - setasign/fpdi:^2.6 (MIT) — PDF page-by-page concatenation primitive
    - setasign/fpdf:^1.8.6 (no usage restriction / public-domain-style) — FPDF rendering backend FPDI uses
  patterns:
    - On-disk version scan (drawings/bound-{projectId}-v*-*.pdf glob) for next-version allocation — zero migration footprint vs a DB column
    - WithoutOverlapping middleware keyed by 'bound-pdf-{projectId}' with releaseAfter(60) for double-click idempotency
    - Per-drawing render failure isolation via try/catch in BoundPdfBuilderService::build (logged + skipped + register row prefixed '[render failed]')
    - CASE-based portable SQL ordering ('CASE kind WHEN schematic THEN 1 WHEN rack THEN 2') replaces MySQL-only FIELD() — works on SQLite test DB AND MySQL production
    - Inline-build-vs-async threshold at ≤3 drawings (sync within HTTP response); >3 drawings dispatches BuildBoundPdfJob + flash message
key-files:
  created:
    - app/Services/Drawings/SheetNumberAllocator.php
    - app/Services/Drawings/BoundPdfBuilderService.php
    - app/Jobs/BuildBoundPdfJob.php
    - app/Mail/BoundPdfReadyMail.php
    - resources/views/emails/bound-pdf-ready.blade.php
    - resources/views/pdf/drawings/bound-cover.blade.php
    - database/migrations/2026_05_03_000001_add_sheet_number_to_project_drawings_table.php
    - tests/Unit/Services/Drawings/SheetNumberAllocatorTest.php
    - tests/Unit/Services/Drawings/BoundPdfBuilderServiceTest.php
    - tests/Feature/Drawings/BoundPdfDownloadTest.php
    - tests/Feature/Drawings/ZipBundleDownloadTest.php
  modified:
    - app/Models/ProjectDrawing.php
    - app/Services/Drawings/DrawingService.php
    - app/Http/Controllers/ProjectDrawingController.php
    - resources/views/projects/drawings/index.blade.php
    - resources/views/pdf/drawings/_title-block.blade.php
    - routes/web.php
    - composer.json
    - composer.lock
decisions:
  - D-A (bound PDF assembly) HONORED VERBATIM — per-drawing PDF render via existing PdfRenderService::fromBlade-via-DrawingExportRendererService::renderPdf; concat via FPDI; cover sheet rendered to a temp PDF first then glued
  - D-B (sheet numbering) HONORED VERBATIM — auto-derived ONCE on DrawingService::createForProject via SheetNumberAllocator; never re-derived; manual override deferred to v1.3.x
  - D-C (ZIP bundle) HONORED VERBATIM — on-demand server-side build via PHP's ZipArchive + response()->streamDownload; no staleness risk
  - D-D (drawings queue) — partial: queue assignment via constructor onQueue('drawings') call (not the deferred public string property — PHP fatal due to Queueable trait conflict); dedicated worker runbook lands in Plan 20-02
  - Storage: bound PDFs land at documents/drawings/bound-{projectId}-v{version}-{ulid}.pdf via DocumentArtifactStorage::TYPE_DRAWING (per CONTEXT.md)
  - Failure semantics: per-drawing render failures logged + skipped (NOT abort); register row prefixed '[render failed]' (per CONTEXT.md MOD-10)
  - Regen-needed badge: amber pill rendered when latest bound PDF mtime < max drawings.updated_at (per CONTEXT.md MOD-10)
  - License audit: composer licenses confirms setasign/fpdi MIT, setasign/fpdf 'no usage restriction' — no GPL/AGPL introduced (T-20-08 / MOD-01)
metrics:
  duration: "35 min"
  tasks: 3
  files_created: 11
  files_modified: 8
  tests_added: 12 (5 SheetNumberAllocator + 5 BoundPdfBuilder + 4 BoundPdfDownload + 3 ZipBundleDownload — 17 tests counting the regenerate-bound-pdf-dispatches-job test added as Task-3 supplement)
  test_assertions_added: 67 (49 unit + 26 ZIP feature minus shared = 67 net)
  commits: 3
  completed: 2026-05-03
---

# Phase 20 Plan 01: Bound PDF + Sheet Numbering + ZIP Bundle Summary

**One-liner:** Ships DRAW-21 (bound multi-page project PDF via FPDI page-concat), DRAW-23 (auto-derived AVIXA-style AV-201/AV-301 sheet numbers persisted on draft create), and DRAW-28 (on-demand ZIP bundle with per-drawing PDF/SVG/PNG + drawing-register CSV) — the user-visible v1.3 deliverable trio.

## Decisions Honored from CONTEXT.md

| Decision | Status | Implementation |
|----------|--------|----------------|
| **D-A — bound PDF assembly: hybrid (per-drawing PDF + FPDI concat)** | HONORED VERBATIM | `BoundPdfBuilderService::build` calls `DrawingExportRendererService::renderPdf` per drawing, renders cover via `PdfRenderService::fromBlade('pdf.drawings.bound-cover', ...)` to a temp PDF, then concats with `setasign\Fpdi\Fpdi::importPage` page-by-page preserving each source page's orientation + size. |
| **D-B — sheet numbering: auto-derive once on draft create** | HONORED VERBATIM | `DrawingService::createForProject` calls `SheetNumberAllocator::allocate($projectId, $kind)` after the row is created. Set-once via `if ($drawing->sheet_number === null)` guard. Floor plans throw `InvalidArgumentException` (v2.0). Manual override deferred. |
| **D-C — ZIP bundle: on-demand server-side, ZipArchive + streamDownload** | HONORED VERBATIM | `ProjectDrawingController::downloadBundle` builds the ZIP synchronously to a tempfile (avoids ZipArchive's quirk where streaming-while-building can yield empty entries) and streams via `response()->streamDownload`. Contents: bound PDF (built inline if missing/stale) + per-ready-drawing PDF/SVG/PNG + `drawing-register.csv`. |
| **D-D — drawings queue isolation: dedicated 'drawings' queue** | PARTIAL — queue assigned in this plan; worker runbook in 20-02 | `BuildBoundPdfJob::__construct()` calls `$this->onQueue('drawings')` (the originally-intended `public string $queue` property triggered a PHP "incompatible composition" fatal because `Queueable` already declares `$queue` without a type). Dedicated worker process + `config/queue.php` connection lands in Plan 20-02 alongside the rest of the production hardening. |

## End-to-End Bound-PDF Path

1. **Draft create:** `ProjectDrawingController::createSchematic|createRack` → `DrawingService::createForProject` → `SheetNumberAllocator::allocate(projectId, kind)` → row gets `sheet_number = 'AV-201'` (or AV-202 if a current AV-201 already exists).
2. **Generate per-drawing artifacts:** `BuildSchematicJob` (or rack synchronous render) writes `generated_svg` and flips `status=ready`. Existing Phase 17/18 path — UNCHANGED.
3. **User clicks "Download Bound PDF" on the drawings index:**
   - `GET projects/{project}/drawings/bound-pdf` → `downloadBoundPdf`
   - Locates latest bound PDF on disk via `BoundPdfBuilderService::latestBoundPdfPath` (glob `drawings/bound-{projectId}-v*-*.pdf`).
   - If fresh (mtime ≥ max drawings.updated_at) → stream existing.
   - Else if ≤3 drawings → `BoundPdfBuilderService::build()` inline + stream.
   - Else → `BuildBoundPdfJob::dispatch($projectId)` + flash redirect.
4. **`BoundPdfBuilderService::build($project)`:**
   - Loads non-superseded schematic + rack drawings, ordered schematics-first then chronological (CASE-based portable SQL).
   - For each drawing: try renderer.renderPdf — on exception, log + add to `failed_drawings` + register row gets `[render failed]` prefix.
   - Renders cover via `PdfRenderService::fromBlade('pdf.drawings.bound-cover', [project, register, failed_drawings, generated_at])` to a temp PDF.
   - Concats cover + every successful per-drawing PDF via FPDI's `setSourceFile`/`importPage` loop.
   - Writes to `documents/drawings/bound-{projectId}-v{N}-{ulid}.pdf` (version = max existing on disk + 1).
5. **Async path:** `BuildBoundPdfJob::handle` calls the same `BoundPdfBuilderService::build` then dispatches `BoundPdfReadyMail` to the project recipient (via `NotificationRecipientResolver`). `failed()` hook sends `DocumentGenerationFailedMail` to admins with `documentType='Bound project drawings PDF'`.

## Sheet-Number Allocation Algorithm

```
next-number = block-base + (count of non-superseded drawings of this kind in
                            this project that already have a sheet_number) + 1

block-base[KIND_SCHEMATIC] = 200  → first schematic = AV-201
block-base[KIND_RACK]      = 300  → first rack      = AV-301
KIND_FLOOR_PLAN throws InvalidArgumentException (v2.0)
```

**Edge cases covered by tests:**
- First schematic → AV-201 (Test 1)
- Second schematic → AV-202 (Test 2)
- Schematic count does NOT consume rack numbers (Test 3 — first rack still AV-301)
- Superseded drawings filtered out: regenerated AV-201 keeps AV-201, next allocate → AV-202 (Test 4)
- Floor plans throw with "v2.0" in message (Test 5)

## ZIP Composition + Entry-Name Sanitisation

Built synchronously into a tempfile via `ZipArchive::CREATE | ZipArchive::OVERWRITE`:
1. Bound PDF (built inline if missing or stale)
2. Per-ready-drawing PDF + SVG + PNG (via `DrawingExportRendererService` — failures isolated per-drawing per try/catch)
3. `drawing-register.csv` written via `addFromString` (Sheet, Title, Kind, Revision, Status, Date, Filename columns)

**T-20-02 mitigation:** EVERY `addFile($realPath, $name)` call passes `basename($realPath)` as the second arg. A hostile filename like `../../etc/passwd` would write its CONTENTS as `passwd` in the ZIP, NOT escape the ZIP root. Verified by `ZipBundleDownloadTest::test_zip_entry_names_have_no_path_traversal` which iterates every entry and asserts no `..`, no leading `/`, no leading `\`.

**Filename:** `{projectRef or projectId}-drawings-{Y-m-d}.zip` with non-alphanumerics replaced by `_`.

## Failure Semantics for Bound PDF (MOD-10)

- **Per-drawing render failure:** Logged at `warning` level, drawing skipped from PDF concat, register row prefixed `[render failed]`, drawing added to `failed_drawings` array. Whole bound PDF still completes.
- **Bound-cover render failure:** Currently propagates — would fail the whole bound PDF build. Mitigation: covers Browsershot startup which Plan 20-02 hardens further.
- **Whole-job failure (FPDI exception, disk full):** Job retries once (tries=2), then `failed()` hook sends `DocumentGenerationFailedMail` to admins.
- **Cover sheet visibility:** Failed-drawing rows render with `bg-red-50` highlighting and a banner "{N} drawing(s) failed to render — see register rows highlighted below."

## Regen-Needed Badge (MOD-10)

`ProjectDrawingController::index` computes `$boundPdfStaleBadge`:
- `null` → no bound PDF on disk yet (no badge)
- `false` → fresh (mtime ≥ max drawings.updated_at)
- `true` → stale (drawings touched since generation)

Index view renders an amber pill `<span class="bg-amber-100 text-amber-800">Regen needed — drawing changed</span>` when `$boundPdfStaleBadge === true`. Verified by `BoundPdfDownloadTest::test_regen_needed_badge_renders_when_drawing_updated_after_bound_pdf`.

## License Audit (T-20-08 / MOD-01)

```
$ composer licenses | grep -i setasign
setasign/fpdi   v2.6.6   MIT
setasign/fpdf   1.8.6    no usage restriction (permissive / public-domain-style)
```

Both verified GPL/AGPL-free. Pre-existing GPL/LGPL deps (mpdf/mpdf, dompdf/dompdf, smalot/pdfparser, tecnickcom/tcpdf) were already in the project before this plan and are out of Plan 20-01 scope.

## Tests Added

| File | Type | Count | Coverage |
|------|------|-------|----------|
| tests/Unit/Services/Drawings/SheetNumberAllocatorTest.php | unit | 5 | DRAW-23 algorithm — first/second schematic, rack-block independence, superseded-row exclusion, floor-plan throw |
| tests/Unit/Services/Drawings/BoundPdfBuilderServiceTest.php | unit | 5 | DRAW-21 builder — 3-page concat, failure isolation, floor-plan exclusion, kind-group ordering, filename pattern (drawings/bound-{id}-v{N}-{ulid}.pdf) |
| tests/Feature/Drawings/BoundPdfDownloadTest.php | feature | 4 | DRAW-21 routes — owner happy path (200 + Content-Type:application/pdf + body starts with %PDF), non-owner 403, regen-needed badge surfaces after drawing touch, regenerate POST dispatches BuildBoundPdfJob |
| tests/Feature/Drawings/ZipBundleDownloadTest.php | feature | 3 | DRAW-28 routes — owner ZIP contains bound + per-drawing PDF/SVG/PNG + register.csv (regex-asserted entry names), T-20-02 path-traversal mitigation, non-owner 403 |
| **TOTAL** | — | **17** | 35 plan-tests pass; 65 total drawings-suite tests pass (no Phase 17/18 regressions) |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] FPDF 1.8 fatal under PHP 8 (`get_magic_quotes_runtime` removed)**
- **Found during:** Task 1 verification — `php -r "new setasign\Fpdi\Fpdi();"` threw `Call to undefined function get_magic_quotes_runtime()`.
- **Issue:** FPDF 1.8.0 (the version the plan asked for) calls a PHP 5.x function removed in PHP 8.0. The fix branch is FPDF 1.8.6.
- **Fix:** Bumped composer constraint from `setasign/fpdf:^1.8` to `setasign/fpdf:^1.8.6`. FPDI 2.6.x supports both transparently.
- **Files modified:** composer.json, composer.lock
- **Commit:** feefb41

**2. [Rule 1 - Bug] SQLite has no `FIELD()` function — bound PDF builder query failed in tests**
- **Found during:** Task 2 — running `BoundPdfBuilderServiceTest` produced `SQLSTATE[HY000]: General error: 1 no such function: FIELD`.
- **Issue:** The plan's `orderByRaw("FIELD(kind, 'schematic', 'rack')")` works on MySQL (production) but SQLite (used in `:memory:` test DB) has no `FIELD()` function. The kind-grouping ordering would only ever be exercised in production, with no test coverage.
- **Fix:** Replaced with portable CASE-based ordering: `orderByRaw("CASE kind WHEN 'schematic' THEN 1 WHEN 'rack' THEN 2 ELSE 99 END")`. Identical semantics; works on every SQL engine.
- **Files modified:** app/Services/Drawings/BoundPdfBuilderService.php (and matched in ProjectDrawingController::downloadBundle)
- **Commit:** 428690d

**3. [Rule 1 - Bug] BuildBoundPdfJob `public string $queue` property conflicted with Queueable trait**
- **Found during:** Task 3 verification — running BoundPdfDownloadTest produced `App\Jobs\BuildBoundPdfJob and Illuminate\Bus\Queueable define the same property ($queue) in the composition`. The Queueable trait declares `$queue` as untyped, so a typed redeclaration triggers a PHP fatal at autoload time.
- **Issue:** The plan called for `public string $queue = 'drawings';`. While Laravel does accept the property pattern for some traits, Queueable specifically declares `$queue` as `protected $queue;` — typed redeclaration is a PHP fatal.
- **Fix:** Moved queue assignment from a class property to a constructor call: `$this->onQueue('drawings')` inside `__construct()`. Same forward-compat with Plan 20-02's dedicated worker process; just uses the trait's documented runtime API instead of a static property.
- **Files modified:** app/Jobs/BuildBoundPdfJob.php
- **Commit:** 184beec

**4. [Rule 3 - Blocking] Routes registered AFTER {drawing} wildcard show route**
- **Found during:** Task 3 — initial route placement ended up below `Route::get('projects/{project}/drawings/{drawing}')` which would catch `bound-pdf` as an attempted model bind, throwing 404 before reaching the new routes.
- **Issue:** Implicit route model binding on `{drawing}` would consume the `bound-pdf` segment before declaration order could dispatch.
- **Fix:** Restructured to register the 3 new literal-segment routes (`bound-pdf`, `bound-pdf/build`, `bundle.zip`) BEFORE the `{drawing}` show route. Mirrors the existing pattern from Phase 18 Plan 03 (`edit`, `rack-canvas` routes registered before show).
- **Files modified:** routes/web.php
- **Commit:** 184beec

### Side Effects (Not Auto-Fixed — Out of Scope)

**Composer downgraded `symfony/http-client` 8.0.8 → 7.4.8 + `symfony/postmark-mailer` 8.0.4 → 7.4.4** when installing FPDF. The composer solver chose the most-compatible versions. No test regressions in the drawings suite. If a future plan needs symfony 8.x specifically (e.g. for an HTTP client feature), it will need to bump explicitly. Logged here for future reference; not blocking Phase 20.

## Authentication Gates

None. All operations are owner-or-admin via existing `ProjectPolicy::view` (registered in AppServiceProvider since Phase 18 Plan 03).

## Threat Flags

None — the plan's `<threat_model>` covers every new surface this plan introduced. The 3 new routes (downloadBoundPdf, regenerateBoundPdf, downloadBundle) are all authorised against `ProjectPolicy::view`. ZIP entry-name traversal mitigated by `basename()` on every `addFile` call. Bound PDF filename includes a 26-char ULID — collision-proof.

## Verification Status

| Check | Status |
|-------|--------|
| Migration applied (`php artisan migrate --pretend` shows the new column add) | ✓ |
| 5 SheetNumberAllocator tests green | ✓ |
| 5 BoundPdfBuilderService tests green | ✓ |
| 4 BoundPdfDownload tests green | ✓ |
| 3 ZipBundleDownload tests green | ✓ |
| `composer licenses` shows setasign/fpdi=MIT, setasign/fpdf=permissive (no GPL/AGPL) | ✓ |
| `php artisan view:cache` succeeds (bound-cover.blade.php + index.blade.php compile clean) | ✓ |
| `php artisan route:list` shows 14 drawings routes (11 P17+18 + 3 new) | ✓ |
| `grep -n "basename(" ProjectDrawingController.php` returns 7 matches incl. all addFile sites | ✓ |
| Phase 17/18 regression check: 46 feature tests + 19 unit tests + 1 D2 skip — all green | ✓ |
| FPDI loads cleanly via `php -r "new setasign\Fpdi\Fpdi();"` | ✓ |

## Self-Check: PASSED

All claimed files exist:
- ✓ `app/Services/Drawings/SheetNumberAllocator.php`
- ✓ `app/Services/Drawings/BoundPdfBuilderService.php`
- ✓ `app/Jobs/BuildBoundPdfJob.php`
- ✓ `app/Mail/BoundPdfReadyMail.php`
- ✓ `resources/views/emails/bound-pdf-ready.blade.php`
- ✓ `resources/views/pdf/drawings/bound-cover.blade.php`
- ✓ `database/migrations/2026_05_03_000001_add_sheet_number_to_project_drawings_table.php`
- ✓ `tests/Unit/Services/Drawings/SheetNumberAllocatorTest.php`
- ✓ `tests/Unit/Services/Drawings/BoundPdfBuilderServiceTest.php`
- ✓ `tests/Feature/Drawings/BoundPdfDownloadTest.php`
- ✓ `tests/Feature/Drawings/ZipBundleDownloadTest.php`

All claimed commits exist:
- ✓ `feefb41` — feat(20-01): sheet-number column + AVIXA allocator + FPDI/FPDF deps
- ✓ `428690d` — feat(20-01): bound PDF builder + cover Blade + job + ready mail
- ✓ `184beec` — feat(20-01): bound PDF + ZIP routes + controller + index UI + 7 feature tests
