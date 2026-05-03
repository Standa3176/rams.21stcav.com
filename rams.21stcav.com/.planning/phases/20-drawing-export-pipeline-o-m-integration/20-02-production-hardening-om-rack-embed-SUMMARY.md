---
phase: 20-drawing-export-pipeline-o-m-integration
plan: 02
subsystem: drawings
tags: [drawings, hardening, om-rack-embed, license-audit, queue, font-face, browsershot]
requires:
  - 20-01 (bound-cover.blade.php created here; BuildBoundPdfJob already calls $this->onQueue('drawings'))
  - 17-03 (OmManualDocxService Drawings section loop — already kind-agnostic)
  - 18-03 (DrawingExportRendererService::bladeViewFor rack arm lit up)
  - 17-03 (PdfSmokeTestCommand --drawings flag scaffolding)
provides:
  - drawings:audit-licenses Artisan command (MOD-01)
  - drawings queue connection in config/queue.php (CRIT-03)
  - CHROME_HEADLESS_SHELL_VERSION pin in .env.example (CRIT-04)
  - @font-face Liberation Sans + DejaVu Sans declarations in 3 drawing Blade views (CRIT-04)
  - docs/runbooks/drawings-queue-runbook.md (operator deploy procedure)
  - public/fonts/ directory in git (binaries deploy via runbook)
  - 7 new tests (4 Task 1 + 3 Task 2)
affects:
  - app/Console/Commands/PdfSmokeTestCommand.php (extends --drawings to render BOTH schematic + rack)
  - resources/views/pdf/drawings/schematic.blade.php (+30 lines @font-face)
  - resources/views/pdf/drawings/rack.blade.php (+30 lines @font-face)
  - resources/views/pdf/drawings/bound-cover.blade.php (+30 lines @font-face — resolves Plan 20-01 TODO comment)
tech-stack:
  added:
    - none (pure hardening — no new composer/npm deps)
  patterns:
    - Pre-existing-allowlist pattern in AuditDrawingLicensesCommand: separate "block new GPL" from "litigate existing infrastructure" — mpdf/dompdf/smalot/tcpdf/nette stay allowlisted with reason notes
    - Stub-via-subclass pattern for AuditDrawingLicensesCommand tests (override protected runComposerLicenses + runNpmLockGrep) so no real composer process runs in tests
    - PhpWord image XML detection via <v:imagedata> (NOT <w:drawing>) — PhpWord 1.4 uses the legacy VML path
    - Pair-helper pattern for PdfSmokeTestCommand --drawings: renderSchematicSmoke + renderRackSmoke each return bool; final exit = AND of both — fail-loud if either path breaks
key-files:
  created:
    - app/Console/Commands/AuditDrawingLicensesCommand.php
    - docs/runbooks/drawings-queue-runbook.md
    - public/fonts/.gitkeep
    - tests/Feature/Drawings/OmManualEmbedsRackTest.php
    - tests/Feature/Console/PdfSmokeTestRackTest.php
    - tests/Feature/Console/AuditDrawingLicensesTest.php
  modified:
    - app/Console/Commands/PdfSmokeTestCommand.php
    - config/queue.php
    - .env.example
    - resources/views/pdf/drawings/schematic.blade.php
    - resources/views/pdf/drawings/rack.blade.php
    - resources/views/pdf/drawings/bound-cover.blade.php
decisions:
  - O&M rack-embed regression-locked, NOT changed: the OmManualDocxService loop at lines 154-213 was already kind-agnostic (Phase 17 P03 + Phase 18 P03). Plan 20-02 Task 1 added a regression test asserting both kindLabels + 2 <v:imagedata> entries appear in the resulting DOCX so a future v1.3.x change cannot silently regress to schematic-only embedding.
  - PdfRenderService NOT modified for disable-dev-shm-usage — verified via grep that the flag IS already present in BOTH fromBlade AND fromBladeAsPng (CRIT-03 hardening shipped Phase 17 P03; Plan 20-01 did NOT remove it during BoundPdfBuilderService work). Single Browsershot hardening surface per Warning 8.
  - License-audit allowlist for pre-existing GPL/LGPL deps (mpdf, dompdf, smalot, tcpdf, nette/schema, nette/utils) — purpose of audit is to BLOCK NEW GPL/AGPL deps, not retroactively litigate the predating Browsershot-migration PDF stack (Plan 20-01 SUMMARY explicitly acknowledged these as out of scope).
  - Drawings queue defined as a SEPARATE connection (not a queue NAME on the existing database connection): explicit separation lets `queue:work --queue=drawings` target by name in supervisor configs AND lets retry_after diverge to 600s (default 90s would re-fire bound PDFs while first attempt still running).
  - public/fonts/ shipped via .gitkeep (NO binaries in git). woff2 binaries are 100KB+ each; deploy runbook step copies them from /home/stcav/fonts/ to public/fonts/ on first deploy. Graceful degradation: if missing, font-family fallback chain keeps PDFs valid.
  - PhpWord image detection via <v:imagedata> NOT <w:drawing>: PhpWord 1.4 emits images using the legacy VML path (verified in vendor/phpoffice/phpword/src/PhpWord/Writer/Word2007/Element/Image.php — uses w:pict + v:shape + v:imagedata). Initial test assumption of <w:drawing> was wrong — auto-fixed during RED.
metrics:
  duration: "13 min"
  tasks: 3
  files_created: 6
  files_modified: 6
  tests_added: 7
  test_assertions_added: 25
  commits: 3
  completed: 2026-05-03
---

# Phase 20 Plan 02: Production Hardening + O&M Rack Embed Summary

**One-liner:** Production-hardens the v1.3 drawings pipeline via 5 deliverables — O&M rack-embed regression test (locks Phase 17 P03 + Phase 18 P03 behaviour), pdf:smoke-test --drawings rack arm extension (CRIT-04), drawings:audit-licenses command + 3 stub-driven tests (MOD-01), dedicated drawings queue connection in config/queue.php + 218-line deploy runbook (CRIT-03), and @font-face Liberation Sans + DejaVu Sans declarations in all 3 drawing Blade views (CRIT-04). Phase 20 ships safe; v1.3 milestone ready for /gsd-complete-milestone.

## What This Plan Did NOT Change (Verified Findings)

Per the plan's interfaces block + critical_context, two pieces of infrastructure were verified-already-correct rather than rewritten:

**1. OmManualDocxService Drawings loop (lines 154-213) is already kind-agnostic.**
The loop filters `where('status', STATUS_READY)->whereNull('superseded_by_id')->orderBy('kind')` — schematics + racks BOTH come through, and `DrawingExportRendererService::bladeViewFor()` returns the right Blade view for each kind (Phase 18 P03 lit up the rack arm). Plan 20-02 added a regression test that mocks `ensurePngForHandover` for both kinds, builds the DOCX, opens it as a ZIP, and asserts BOTH "System Schematic" AND "Rack Elevation" headings + exactly 2 `<v:imagedata>` entries. The skip-on-failure path (existing try/catch + null guard at lines 182-198) was also locked — second test mocks `ensurePngForHandover` to return null for the rack and asserts only 1 image is embedded.

**2. PdfRenderService::fromBlade + fromBladeAsPng both still have `disable-dev-shm-usage` Chromium argument.**
`grep -c "disable-dev-shm-usage" app/Services/PdfRenderService.php` returns 4 (2 in fromBlade at line 83, 2 in fromBladeAsPng at line 167 — array key + closing comma counted). CRIT-03 hardening shipped Phase 17 P03; Plan 20-01's BoundPdfBuilderService work did NOT accidentally remove it. PdfRenderService is the single Browsershot hardening surface per Warning 8 architectural decision — any future hardening lands in one place.

## End-to-End Hardening Path

1. **Operator deploys new chrome-headless-shell version:**
   - Bumps `CHROME_HEADLESS_SHELL_VERSION` in `.env.example` + live `.env` (147.0.7727.57 today).
   - Downloads new binary into `/home/stcav/chrome-headless-shell-<version>/`.
   - Smoke-tests via `CHROME_PATH=/home/.../chrome php artisan pdf:smoke-test --drawings`.
   - Expects exit 0 with output mentioning BOTH "schematic" AND "rack" with non-zero byte sizes.
   - Atomic `ln -sfn ... /home/stcav/chrome` only after smoke passes.
   - Restarts drawings queue worker.

2. **Operator runs deploy preflight:**
   - `php artisan drawings:audit-licenses` — must exit 0 (flags GPL/AGPL composer + npm deps; pre-existing infrastructure deps allowlisted with reason notes; --strict mode adds LGPL but is informational).
   - License audit blocks NEW GPL/AGPL deps from landing without explicit operator override.

3. **Operator runs supervisor for the dedicated drawings worker:**
   - `php artisan queue:work --queue=drawings --max-jobs=10 --memory=512 --timeout=600 --tries=2`.
   - Targets the new `drawings` connection in config/queue.php (`retry_after=600` vs default 90).
   - `numprocs=1` enforced at supervisor level — concurrency=1 supplements the existing `WithoutOverlapping('bound-pdf-{projectId}')` middleware on BuildBoundPdfJob.
   - Bound PDFs no longer starve RAMS notification emails / O&M handover builds / worksheets / cable schedules / schematic builds when concurrent.

4. **End-user receives O&M Manual:**
   - Drawings section embeds BOTH schematics AND racks (one per page, regression-locked).
   - PDF text renders with consistent fonts on dev (full Chrome) AND prod (chrome-headless-shell) — @font-face for Liberation Sans + DejaVu Sans backed by `public/fonts/*.woff2` deployed via runbook step.

## drawings:audit-licenses Command Behavior

```
$ php artisan drawings:audit-licenses
License audit OK — no GPL/AGPL offenders across 142 composer + 229 npm deps.
$ echo $?
0

$ php artisan drawings:audit-licenses --strict
License audit FAILED — N offender(s) found:
+-----------------+----------------+----------------+
| OFFENDER:source | package        | license        |
+-----------------+----------------+----------------+
| composer        | dompdf/dompdf  | LGPL-2.1...    |
+-----------------+----------------+----------------+
$ echo $?
1
```

Strict mode is informational — currently fails because of the pre-existing LGPL stack (dompdf/dompdf, smalot/pdfparser, tecnickcom/tcpdf — all LGPL via the allowlist), documented in the runbook for future migration to Browsershot-only.

**Test pattern:** Subclass binds via `$this->app->bind(AuditDrawingLicensesCommand::class, StubAuditDrawingLicensesCommand::class)` BEFORE `Artisan::call('drawings:audit-licenses')`. Stub overrides `runComposerLicenses` + `runNpmLockGrep` (declared `protected` for this exact reason) with stub payloads. No real composer process runs in tests.

## Drawings Queue Connection (config/queue.php)

```php
'drawings' => [
    'driver' => 'database',
    'connection' => env('DB_QUEUE_CONNECTION'),
    'table' => env('DB_QUEUE_TABLE', 'jobs'),
    'queue' => env('DB_QUEUE_DRAWINGS', 'drawings'),
    'retry_after' => (int) env('DB_QUEUE_DRAWINGS_RETRY_AFTER', 600),
    'after_commit' => false,
],
```

`retry_after=600` (10 min) vs the default 90s because bound PDFs over 5+ drawings can take 90+ seconds (FPDI page-by-page concat over multiple Browsershot renders). Default 90s would re-fire while the first attempt is still running. BuildBoundPdfJob already targets this via `$this->onQueue('drawings')` in its constructor (Plan 20-01 deviation 3 — typed `public string $queue` triggers a PHP fatal vs the untyped Queueable trait).

## Deploy Runbook Outline (`docs/runbooks/drawings-queue-runbook.md` — 218 lines)

1. **Why a separate drawings queue?** — CRIT-03 reference; bound PDFs starving the default queue.
2. **Worker process** — exact `queue:work` invocation + supervisor config snippet (numprocs=1, --memory=512, --max-jobs=10, --timeout=600, --tries=2) + restart procedure.
3. **Chrome upgrade procedure** — pin bump → download into versioned directory → smoke-test BEFORE symlink → atomic `ln -sfn` → re-smoke-test → restart worker → keep prior version 24h.
4. **License audit gate** — `drawings:audit-licenses` deploy preflight; --strict mode documented as informational.
5. **Fonts setup** — copy 3 woff2 binaries from `/home/stcav/fonts/` to `public/fonts/` on first deploy; `font-display: block` + fallback chain ensures graceful degradation if binaries missing (PDFs still render — just with Arial/Helvetica instead of Liberation Sans).
6. **Smoke test gate** — `pdf:smoke-test --drawings` is the single source of truth.
7. **Where bound PDFs land** — `documents/drawings/bound-{projectId}-v{N}-{ulid}.pdf` via DocumentArtifactStorage TYPE_DRAWING (H-07 contract).

## @font-face Approach + Fallback Chain

All 3 drawing Blade views (`schematic.blade.php`, `rack.blade.php`, `bound-cover.blade.php`) now declare:

```css
@font-face {
    font-family: 'Liberation Sans';
    font-style: normal;
    font-weight: 400;
    font-display: block;
    src: url('/fonts/liberation-sans-regular.woff2') format('woff2');
}
/* + bold variant, + DejaVu Sans regular */
```

`font-display: block` makes Browsershot wait for the font load. If the woff2 URL 404s on a fresh server, Chromium silently moves to the next font in the existing CSS family chain (`Arial → Helvetica → 'Liberation Sans' → 'DejaVu Sans' → sans-serif`) — graceful degradation, PDF still valid, smoke test still exits 0. Production deploy adds the binaries via the runbook's "Fonts setup" step (NOT git — they're 100KB+ each).

## pdf:smoke-test --drawings Extension Shape

Refactored `PdfSmokeTestCommand::renderDrawingSmoke()`:

```
schemOk = renderSchematicSmoke(renderer, schemOut)   # existing logic
rackOk  = renderRackSmoke(renderer, rackOut)          # NEW — mirrors schematic shape
return (schemOk && rackOk) ? SUCCESS : FAILURE
```

Each helper:
1. Tries a real ready drawing (kind-filtered, status=READY, non-superseded, latest by id) — true e2e check via `DrawingExportRendererService::renderPdf`.
2. Falls back to in-memory ProjectDrawing fixture with placeholder generated_svg (rack fixture is a tall narrow rect with 4 U-rail tick marks — just enough structure to exercise the rack Blade view + Browsershot font fallback chain).
3. Returns false on exception OR zero-byte output (with `$this->error(...)`).
4. Returns true on success (with `$this->info(...)`).

Schematic out path = `--out` value (default `storage/app/pdf-smoke-drawing.pdf`). Rack out is derived as `<base>-rack.pdf` via new `deriveRackOutPath` helper — preserves --out semantics while giving the rack render its own destination so a single `--out` flag drives both.

## Tests Added

| File | Type | Count | Coverage |
|------|------|-------|----------|
| tests/Feature/Drawings/OmManualEmbedsRackTest.php | feature | 2 | O&M Drawings section regression — both kindLabels + 2 <v:imagedata> for ready schematic + ready rack; only 1 <v:imagedata> when rack render returns null |
| tests/Feature/Console/PdfSmokeTestRackTest.php | feature | 2 | Smoke test extension — output mentions both "schematic" AND "rack"; exit 1 when rack render returns zero bytes |
| tests/Feature/Console/AuditDrawingLicensesTest.php | feature | 3 | Audit command — clean state passes (with allowlist), simulated GPL fails, simulated LGPL passes by default but fails with --strict |
| **TOTAL** | — | **7** | 25 net new assertions; 72 total drawings/console tests pass (1 D2 binary skip; +7 from Wave 1's 65 baseline) |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] PhpWord image XML detection — `<w:drawing>` was wrong**
- **Found during:** Task 1 RED → GREEN. Initial test asserted `substr_count($xml, '<w:drawing>') === 2` based on the plan's `<behavior>` text. Test failed with 0 matches.
- **Issue:** PhpWord 1.4 emits images using the legacy VML writer path (`<w:pict><v:shape><v:imagedata.../></v:shape></w:pict>`), NOT the modern DrawingML `<w:drawing>` block. Verified in `vendor/phpoffice/phpword/src/PhpWord/Writer/Word2007/Element/Image.php` line 79 (`startElement('w:pict')`) and line 86 (`startElement('v:imagedata')`).
- **Fix:** Updated both test assertions to count `<v:imagedata` instead of `<w:drawing>`. The kindLabel heading assertions (which were passing — confirming the loop IS iterating both drawings) remained unchanged.
- **Files modified:** tests/Feature/Drawings/OmManualEmbedsRackTest.php
- **Commit:** c065b17 (Task 1)

**2. [Rule 2 - Missing critical functionality] License audit needs pre-existing dep allowlist**
- **Found during:** Task 2 GREEN — the `audit_passes_on_current_clean_composer_state` test failed because the live `composer licenses` output flagged 4 pre-existing GPL/dual-licensed packages: mpdf/mpdf (GPL-2.0), nette/schema + nette/utils (BSD/GPL dual), and the LGPL family (dompdf, smalot, tcpdf — only flagged in --strict mode).
- **Issue:** Plan acceptance criteria require "exits 0 against the current clean composer state". The audit's purpose per CONTEXT.md MOD-01 is to BLOCK NEW GPL/AGPL deps from landing — not to retroactively litigate the pre-existing PDF stack (mpdf/dompdf/etc. predate Browsershot migration). Plan 20-01 SUMMARY explicitly acknowledged these as "out of Plan 20-01 scope".
- **Fix:** Added a `$preExistingAllowlist` array to AuditDrawingLicensesCommand documenting each pre-Phase-20 GPL/LGPL dep with a reason note (mpdf=PDF rendering predating Browsershot; dompdf=in-progress migration via 260427-qvr; nette=composer/composer transitive dev-time; etc.). Future cleanup tasks can remove entries as they swap deps. Test stubs (e.g. `evil/evil-pkg` with GPL-3.0-only) are NOT allowlisted — only documented existing infrastructure.
- **Files modified:** app/Console/Commands/AuditDrawingLicensesCommand.php
- **Commit:** 79c68d2 (Task 2)

### Side Effects (Not Auto-Fixed — Out of Scope)

**Many pre-existing modifications in working tree.** The repo had ~30+ unrelated modified files at executor start (controllers, services, Blade views) from prior sessions. Plan 20-02 staged each task's files individually (per task_commit_protocol) — `git add <specific-files>` not `git add -A`. The unrelated modifications were not touched and not staged in any of the 3 plan commits.

## Authentication Gates

None. All operations are CLI-side or Blade view edits — no auth flow changes.

## Threat Flags

None — the plan's `<threat_model>` (T-20-09 through T-20-14) covers every new surface this plan introduced. The license-audit command is operator-only with no untrusted input. The drawings queue connection accepts the same ProjectId-only payload as BuildBoundPdfJob (Plan 20-01 already mitigated). The @font-face URLs are server-relative + statically declared (no user input).

## Verification Status

| Check | Status |
|-------|--------|
| OmManualEmbedsRackTest 2/2 green | ✓ |
| PdfSmokeTestRackTest 2/2 green | ✓ |
| AuditDrawingLicensesTest 3/3 green | ✓ |
| `php artisan drawings:audit-licenses` exits 0 against live composer state | ✓ (142 composer + 229 npm deps, no offenders) |
| `php artisan list \| grep drawings:audit-licenses` returns match | ✓ |
| `php artisan tinker config('queue.connections.drawings.queue')` returns "drawings" | ✓ |
| `php artisan tinker config('queue.connections.drawings.retry_after')` returns 600 | ✓ |
| `grep CHROME_HEADLESS_SHELL_VERSION .env.example` returns match | ✓ |
| `wc -l docs/runbooks/drawings-queue-runbook.md` > 30 | ✓ (218 lines) |
| `grep -l "font-face" resources/views/pdf/drawings/{schematic,rack,bound-cover}.blade.php` returns 3 paths | ✓ |
| `grep -c "Liberation Sans" resources/views/pdf/drawings/schematic.blade.php` >= 2 | ✓ (4) |
| `grep -c "DejaVu Sans" resources/views/pdf/drawings/schematic.blade.php` >= 1 | ✓ (3) |
| `grep -c "disable-dev-shm-usage" app/Services/PdfRenderService.php` >= 2 | ✓ (4 — regression guard intact, Plan 20-01 did NOT remove the flag) |
| `test -f public/fonts/.gitkeep` | ✓ |
| `php artisan view:cache` succeeds (Blade syntax valid post @font-face additions) | ✓ |
| Phase 17 + 18 + 20-01 regression: 72 drawings/console tests pass (1 D2 skip on dev) | ✓ (+7 from Wave 1's 65 baseline) |

## Self-Check: PASSED

All claimed files exist:
- ✓ `app/Console/Commands/AuditDrawingLicensesCommand.php`
- ✓ `app/Console/Commands/PdfSmokeTestCommand.php` (modified)
- ✓ `config/queue.php` (modified)
- ✓ `.env.example` (modified)
- ✓ `docs/runbooks/drawings-queue-runbook.md`
- ✓ `public/fonts/.gitkeep`
- ✓ `resources/views/pdf/drawings/schematic.blade.php` (modified)
- ✓ `resources/views/pdf/drawings/rack.blade.php` (modified)
- ✓ `resources/views/pdf/drawings/bound-cover.blade.php` (modified)
- ✓ `tests/Feature/Drawings/OmManualEmbedsRackTest.php`
- ✓ `tests/Feature/Console/PdfSmokeTestRackTest.php`
- ✓ `tests/Feature/Console/AuditDrawingLicensesTest.php`

All claimed commits exist:
- ✓ `c065b17` — feat(20-02): O&M rack-embed regression test + smoke-test --drawings rack arm
- ✓ `79c68d2` — feat(20-02): drawings:audit-licenses + drawings queue + chrome pin + runbook
- ✓ `e882f40` — feat(20-02): @font-face declarations in 3 drawing Blade views + fonts dir
