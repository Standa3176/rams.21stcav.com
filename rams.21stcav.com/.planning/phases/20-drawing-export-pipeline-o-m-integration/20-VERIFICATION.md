---
phase: 20-drawing-export-pipeline-o-m-integration
verified: 2026-05-03T14:30:00Z
status: human_needed
score: 11/11 must-haves verified
overrides_applied: 0
human_verification:
  - test: "End-to-end bound PDF download from a real project's drawings index"
    expected: "Browser receives a multi-page PDF (cover + register + per-drawing pages); each drawing's title block shows the AV-XXX sheet number"
    why_human: "Browsershot/Chromium render quality, font rendering on real Chrome vs chrome-headless-shell, and visual cover-sheet layout cannot be verified by grep — needs eyeballed PDF output"
  - test: "End-to-end ZIP bundle download"
    expected: "Browser receives a ZIP containing bound-{id}-v{N}-{ulid}.pdf + per-drawing PDF/SVG/PNG + drawing-register.csv; opens in Windows Explorer / 7-Zip without warnings"
    why_human: "Real ZIP integrity, MIME handling, and download-prompt behaviour vary by browser; tests assert entry shape but not real-browser UX"
  - test: "Regen-needed amber badge surfaces after a drawing edit"
    expected: "After clicking Download Bound PDF, then editing any rack/schematic via Phase 18 editor, the drawings index page shows an amber 'Regen needed — drawing changed' pill next to the bound-PDF button"
    why_human: "MOD-10 staleness UX is a visual signal whose timing/colour/copy needs operator confirmation"
  - test: "Bound PDF completion email arrives with attachment"
    expected: "After the async job completes, the project recipient (per NotificationRecipientResolver) receives a 'Project drawings ready' email with the bound PDF attached"
    why_human: "Mail driver is 'log' in dev — actual SMTP delivery + attachment integrity needs prod-style smtp test"
  - test: "Failure isolation visible in cover sheet"
    expected: "If one drawing's generated_svg is corrupted/empty, the bound PDF still completes; the cover sheet's drawing register highlights that row in red and prefixes the title with '[render failed]'"
    why_human: "Visual styling of the failed-row banner + register highlight is a UX detail not asserted by unit tests"
  - test: "pdf:smoke-test --drawings on production with chrome-headless-shell"
    expected: "Command exits 0 with non-zero byte sizes for both schematic and rack outputs when run against the pinned chrome-headless-shell version"
    why_human: "CRIT-04 hardening only matters at production deploy time against the actual prod Chrome binary; dev test uses full Chrome"
---

# Phase 20: Drawing Export Pipeline + O&M Integration — Verification Report

**Phase Goal:** Ship the user-visible bound PDF + sheet numbering + ZIP bundle trio (Plan 20-01) and production-harden the v1.3 drawings pipeline (Plan 20-02). Complete v1.3 milestone.

**Verified:** 2026-05-03T14:30:00Z
**Status:** human_needed
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | User can click 'Download Bound PDF' on drawings index and receive multi-page PDF (cover + register + per-drawing pages) — DRAW-21 | VERIFIED | `routes/web.php:357` `projects.drawings.bound-pdf` GET route → `ProjectDrawingController::downloadBoundPdf` (line 505); `BoundPdfBuilderService::build()` concatenates cover via `PdfRenderService::fromBlade('pdf.drawings.bound-cover')` + per-drawing PDFs via FPDI; route registered (verified via `php artisan route:list`); 3/3 BoundPdfDownload feature tests green |
| 2 | Every drawing's title block renders auto-derived AV-XXX sheet number (AV-201..299 schematics, AV-301..399 racks) — DRAW-23 | VERIFIED | Migration `2026_05_03_000001_add_sheet_number_to_project_drawings_table.php` adds nullable string(20); `ProjectDrawing.php:71` includes `sheet_number` in `$fillable`; `SheetNumberAllocator.php:54` allocates AV-{base+count+1}; `DrawingService.php:39` injects allocator and calls `allocate()` on `createForProject`; `_title-block.blade.php:79-85` consumes `$drawing->sheet_number`; 5/5 SheetNumberAllocator unit tests green |
| 3 | User can click 'Download ZIP' and receive ZIP with bound PDF + per-drawing PDF/SVG/PNG + register CSV — DRAW-28 | VERIFIED | `routes/web.php:363` `projects.drawings.bundle` GET route → `ProjectDrawingController::downloadBundle` (line 579); uses `ZipArchive` + `response()->streamDownload`; 3/3 ZipBundleDownload tests green including `zip_entry_names_have_no_path_traversal` (CRIT-04 mitigation via 4× `basename()` calls at lines 616/626/636/646) |
| 4 | Per-drawing render failure during bound-PDF assembly leaves bound PDF complete with `[render failed]` placeholder | VERIFIED | `BoundPdfBuilderService.php:87-101` try/catch around `renderer->renderPdf()`; failed drawing logged + appended to `$failedDrawings`; register row gets `[render failed] ` prefix at line 100; `BoundPdfBuilderServiceTest::test_failed_drawing_is_skipped_but_register_still_lists_it` locks contract |
| 5 | Index page surfaces amber 'Regen needed — drawing changed' badge when any drawing.updated_at > bound PDF generated_at — MOD-10 | VERIFIED | `index.blade.php:57` renders `<span class="bg-amber-100 text-amber-800">Regen needed — drawing changed</span>`; controller sets `$boundPdfStaleBadge` based on mtime comparison; `BoundPdfDownloadTest::test_regen_needed_badge_renders_when_drawing_updated_after_bound_pdf` covers the path |
| 6 | O&M Manual Drawings section embeds BOTH schematic AND rack PNGs — DRAW-26 extension | VERIFIED | `OmManualDocxService.php:155-201` loop is kind-agnostic (no kind filter beyond status=READY); `kindLabel()` invoked at line 201 prints "System Schematic" or "Rack Elevation"; `OmManualEmbedsRackTest` 2/2 green asserting both `<v:imagedata>` entries + both kindLabels in DOCX |
| 7 | Operator can run `php artisan pdf:smoke-test --drawings` and see BOTH schematic + rack PDFs rendered with non-zero bytes — CRIT-04 | VERIFIED | `PdfSmokeTestCommand.php:69-86` `renderDrawingSmoke` calls both `renderSchematicSmoke` AND `renderRackSmoke`; returns FAILURE if either fails; final summary line `schematic=<ok|FAIL> rack=<ok|FAIL>`; `PdfSmokeTestRackTest` 2/2 green |
| 8 | Operator can run `php artisan drawings:audit-licenses` and see PASS/FAIL on GPL/AGPL deps — MOD-01 | VERIFIED | `AuditDrawingLicensesCommand.php` registered as `drawings:audit-licenses` (verified via `php artisan list`); `violatesPolicy()` regex flags A?GPL with negative lookbehind on L; `--strict` flag adds LGPL; `AuditDrawingLicensesTest` 3/3 green (clean state, simulated GPL fails, LGPL fails only with --strict) |
| 9 | `queue:work --queue=drawings` processes BoundPdfJob without contention — CRIT-03 | VERIFIED | `config/queue.php:65-72` defines dedicated `drawings` connection with `retry_after=600`; `BuildBoundPdfJob::__construct` calls `$this->onQueue('drawings')` (line 71); `WithoutOverlapping('bound-pdf-{projectId}')` middleware at line 82; `docs/runbooks/drawings-queue-runbook.md` (218 lines) documents worker invocation |
| 10 | `.env.example` documents `CHROME_HEADLESS_SHELL_VERSION` pin — CRIT-04 | VERIFIED | `.env.example:128` `CHROME_HEADLESS_SHELL_VERSION=147.0.7727.57` adjacent to `CHROME_PATH` at line 113 |
| 11 | Schematic + rack + bound-cover Blade views @font-face declare Liberation Sans + DejaVu Sans fallbacks — CRIT-04 | VERIFIED | `grep -c "font-face"` returns 5 (schematic), 5 (rack), 4 (bound-cover); `grep -c "Liberation Sans\|DejaVu Sans"` returns 5+ in schematic; `PdfRenderService` retains `disable-dev-shm-usage` flag (4 matches via grep — regression guard intact) |

**Score:** 11/11 truths verified

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `database/migrations/2026_05_03_000001_add_sheet_number_to_project_drawings_table.php` | `project_drawings.sheet_number` nullable string(20) after `version` | VERIFIED | Exists 1656 bytes; up() adds `string('sheet_number', 20)->nullable()->after('version')`; down() drops cleanly |
| `app/Services/Drawings/SheetNumberAllocator.php` | Pure allocator returning AV-XXX per (project, kind) | VERIFIED | Exists 2789 bytes; `allocate(int $projectId, string $kind): string` with BLOCK_BASES map; throws on KIND_FLOOR_PLAN per "v2.0" message |
| `app/Services/Drawings/BoundPdfBuilderService.php` | Concatenates cover + per-drawing PDFs with failure isolation | VERIFIED | Exists 9881 bytes; `build()` returns `['path', 'register', 'failed_drawings', 'generated_at', 'version']`; FPDI concat at `concat()`; per-drawing failure isolated via try/catch (lines 87-101) |
| `app/Jobs/BuildBoundPdfJob.php` | Async assembly; tries=2; timeout=300; failed() admin alert | VERIFIED | Exists 8656 bytes; constructor `public readonly int $projectId` (NOT $drawingId); `$this->onQueue('drawings')`; `WithoutOverlapping` middleware; `failed()` sends `DocumentGenerationFailedMail` |
| `app/Mail/BoundPdfReadyMail.php` | Bound-PDF completion notification | VERIFIED | Exists 1908 bytes; mirrors DrawingReadyMail shape |
| `resources/views/pdf/drawings/bound-cover.blade.php` | Cover sheet + register table | VERIFIED | Exists 8369 bytes; A4 portrait layout; @font-face declarations present |
| `resources/views/projects/drawings/index.blade.php` | Bound PDF + ZIP buttons + sheet column + regen badge | VERIFIED | Lines 57 (badge), 63 (Bound PDF button), 67 (ZIP button), 88+136 (Sheet column for both kinds) |
| `app/Console/Commands/AuditDrawingLicensesCommand.php` | License audit command | VERIFIED | Exists 9277 bytes; signature `drawings:audit-licenses {--strict}`; registered (visible in `artisan list`) |
| `config/queue.php` | drawings queue connection | VERIFIED | Lines 65-72 define `drawings` connection with retry_after=600 |
| `docs/runbooks/drawings-queue-runbook.md` | Deploy runbook | VERIFIED | Exists 8053 bytes / 218 lines (>30 line acceptance) |
| `resources/views/pdf/drawings/schematic.blade.php` | @font-face declarations | VERIFIED | 5 font-face matches; Liberation Sans + DejaVu Sans declared |
| `resources/views/pdf/drawings/rack.blade.php` | @font-face declarations | VERIFIED | 5 font-face matches |
| `resources/views/pdf/drawings/_title-block.blade.php` | Sheet number row | VERIFIED | Lines 79-85 render `$drawing->sheet_number` |
| `app/Services/OmManualDocxService.php` | Drawings loop embeds rack PNGs | VERIFIED | Lines 155-201 kind-agnostic; `ensurePngForHandover` at 183; `kindLabel()` at 201 |
| `.env.example` | CHROME_HEADLESS_SHELL_VERSION pin | VERIFIED | Line 128 declares `CHROME_HEADLESS_SHELL_VERSION=147.0.7727.57` |
| `public/fonts/.gitkeep` | Fonts directory placeholder | VERIFIED | Exists 0 bytes |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|----|--------|---------|
| `ProjectDrawingController::regenerateBoundPdf` | `BuildBoundPdfJob` | `dispatch()` | WIRED | Line 554 `BuildBoundPdfJob::dispatch((int) $project->id)` |
| `ProjectDrawingController::downloadBoundPdf` | `BuildBoundPdfJob` (or inline) | `dispatch()` for >3 drawings | WIRED | Line 530 `BuildBoundPdfJob::dispatch((int) $project->id)` async branch; inline branch invokes `BoundPdfBuilderService::build()` for ≤3 drawings |
| `BuildBoundPdfJob::handle` | `BoundPdfBuilderService::build` | service-class injection | WIRED | Line 91 `handle(BoundPdfBuilderService $builder)` → line 109 `$builder->build($project)` |
| `BoundPdfBuilderService::build` | `DrawingExportRendererService::renderPdf` | per-drawing loop with try/catch | WIRED | Line 91 `$this->renderer->renderPdf($drawing)` inside try/catch (87-101) |
| `ProjectDrawingController::downloadBundle` | `ZipArchive::addFile` | `response()->streamDownload` | WIRED | Lines 616/626/636/646 `$zip->addFile($path, basename($path))` for bound PDF + per-drawing PDF/SVG/PNG; addFromString for register CSV |
| `DrawingService::createForProject` | `SheetNumberAllocator::allocate` | constructor injection + set-once | WIRED | Line 39 `private readonly SheetNumberAllocator $sheetAllocator`; called inside `createForProject` |
| `BuildBoundPdfJob` queue assignment | `config/queue.connections.drawings` | `$this->onQueue('drawings')` | WIRED | Line 71 in constructor; matches `config/queue.php` line 65-72 connection definition |
| `OmManualDocxService` drawings loop | `DrawingExportRendererService::ensurePngForHandover` | service injection (kind-agnostic) | WIRED | Line 183 `$renderer->ensurePngForHandover($drawing)` inside loop iterating over schematic + rack drawings |
| `PdfSmokeTestCommand --drawings` | `DrawingExportRendererService::renderPdf` for KIND_RACK | `renderRackSmoke()` | WIRED | Lines 174-247 `renderRackSmoke` queries `ProjectDrawing::where('kind', KIND_RACK)`; falls back to in-memory rack fixture |
| `PdfRenderService::fromBlade` | Browsershot `--disable-dev-shm-usage` flag | static addChromiumArguments | WIRED | `grep -c` returns 4 matches in PdfRenderService (regression guard intact — Plan 20-01 did NOT remove the flag) |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| `index.blade.php` Project Documents block | `$drawings`, `$boundPdfStaleBadge` | `ProjectDrawingController::index()` queries `Project::drawings()` + `BoundPdfBuilderService::latestBoundPdfPath()` | Yes — DB query for drawings, filesystem glob for bound PDFs | FLOWING |
| `bound-cover.blade.php` register table | `$register`, `$failed_drawings`, `$project` | `BoundPdfBuilderService::build()` builds register from real drawings query (lines 73-78) | Yes | FLOWING |
| `_title-block.blade.php` sheet row | `$drawing->sheet_number` | Persisted to DB by `SheetNumberAllocator::allocate()` on draft create via `DrawingService::createForProject` | Yes — column added by migration; allocator wired | FLOWING |
| Bound PDF artifact bytes | FPDI Output | `BoundPdfBuilderService::concat()` writes via `Fpdi::Output()` to `DocumentArtifactStorage::TYPE_DRAWING` path | Yes — FPDI loadable (`class_exists(setasign\\Fpdi\\Fpdi)` returns true) | FLOWING |
| ZIP bundle entries | per-drawing PDF/SVG/PNG paths | `DrawingExportRendererService::renderPdf/renderSvg/renderPng` per drawing | Yes — Phase 17/18 renderers untouched and exercised | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Drawings test suite green | `php artisan test --filter=Drawings` | 69 passed, 1 D2 binary skip, 261 assertions | PASS |
| Audit + Smoke console tests green | `php artisan test --filter="AuditDrawingLicenses\|PdfSmokeTest"` | 5 passed, 14 assertions (3 audit + 2 smoke) | PASS |
| Routes registered | `php artisan route:list \| grep drawings.bound-pdf\|drawings.bundle` | 3 routes shown (GET bound-pdf, POST bound-pdf.build, GET bundle.zip) | PASS |
| Audit command registered | `php artisan list \| grep drawings:audit-licenses` | Match found with description | PASS |
| FPDI loadable | `php -r "require 'vendor/autoload.php'; echo class_exists('setasign\\\\Fpdi\\\\Fpdi') ? 'OK' : 'MISSING';"` | "FPDI_OK" output | PASS |
| Smoke test command registered | `php artisan list \| grep pdf:smoke-test` | Match found | PASS |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| DRAW-21 | 20-01 | User can generate single bound multi-page PDF per project (cover + register + per-section sheets) | SATISFIED | BoundPdfBuilderService + 3 routes + 5 unit tests + 4 feature tests |
| DRAW-23 | 20-01 | Sheet numbering configurable per project (default AV-101/201/301 per kind) | SATISFIED | SheetNumberAllocator + migration + 5 unit tests; AV-201/AV-301 blocks active (AV-101 floor-plan block deferred to v2.0) |
| DRAW-28 | 20-01 | User can download ZIP bundle of all drawings for a project | SATISFIED | downloadBundle action + ZipArchive composition + 3 feature tests including path-traversal mitigation |

**Note on DRAW-23 partial completeness:** Plan 20-01 implements AV-201..299 (schematics) and AV-301..399 (racks). The AV-101 floor-plan block is intentionally absent per CONTEXT.md decision (floor plans deferred to v2.0 backlog 999.1). REQUIREMENTS.md text mentions "default AV-101, AV-201, AV-301" but the v2.0-deferred floor-plan kind correctly throws InvalidArgumentException with a "v2.0" message. This is consistent with the v1.3 scope reduction documented in REQUIREMENTS.md lines 47-54.

**No orphaned requirements:** REQUIREMENTS.md table maps DRAW-21, DRAW-23, DRAW-28 to Phase 20; all three appear in plan 20-01 frontmatter. Plan 20-02 declares `requirements: []` (pure hardening — correctly maps no requirements).

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| (none in scope) | — | No TODO/FIXME/PLACEHOLDER strings or hollow returns found in any of the 28 reviewed files | Info | Clean — Phase 20 ships substantive implementations not stubs |

REVIEW.md identified 4 warnings + 6 info findings. None are blockers (per REVIEW.md `findings.critical: 0`). Notable warnings (not gating ship):
- WR-01: `regenerateBoundPdf` uses `view` not `update` authorization — functionally OK today; flagged for future role refinement
- WR-02: FPDI parse failures inside `concat()` not isolated per-source-PDF — currently render-half is isolated but parse-half aborts whole bound PDF
- WR-03: `downloadBoundPdf` triggers synchronous Browsershot for empty projects — minor DoS surface
- WR-04: `BuildBoundPdfJob::handle()` always rebuilds even when artifact is fresher than every drawing — wasted CPU

These remain as backlog improvements; they do not invalidate the phase goal.

### Human Verification Required

See `human_verification` block in frontmatter. Six items requiring eyeballed/end-to-end testing:

1. **Bound PDF download (real project)** — render quality, font fallback, layout
2. **ZIP bundle download (real browser)** — MIME, prompt, integrity
3. **Regen-needed badge** — visual surfacing after edit
4. **Bound PDF email delivery** — SMTP path with attachment
5. **Failure isolation visible in cover sheet** — register highlight + banner
6. **pdf:smoke-test --drawings on production chrome-headless-shell** — CRIT-04 prod validation

### Gaps Summary

No structural gaps. All 11 must-have truths verified, all 16 required artifacts present and substantive, all 10 key links wired, all behavioral spot-checks pass (74 tests total — 69 Drawings + 5 Console). Three requirements (DRAW-21, DRAW-23, DRAW-28) satisfied. The phase achieves its stated goal: "Ship the user-visible bound PDF + sheet numbering + ZIP bundle trio (Plan 20-01) and production-harden the v1.3 drawings pipeline (Plan 20-02). Complete v1.3 milestone."

Status is `human_needed` rather than `passed` because the v1.3 milestone-completion gate requires operator-confirmed end-to-end UX validation (browser download flows, real Chrome rendering, SMTP delivery) that cannot be programmatically asserted from grep + filesystem checks. The automated verification confidence is high; human verification confirms the deliverable lands as intended for production.

---

_Verified: 2026-05-03T14:30:00Z_
_Verifier: Claude (gsd-verifier)_
