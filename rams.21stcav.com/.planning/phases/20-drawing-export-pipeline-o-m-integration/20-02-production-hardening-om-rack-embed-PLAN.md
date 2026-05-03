---
phase: 20-drawing-export-pipeline-o-m-integration
plan: 02
type: execute
wave: 2
depends_on: [20-01]
files_modified:
  - app/Services/OmManualDocxService.php
  - app/Console/Commands/PdfSmokeTestCommand.php
  - app/Console/Commands/AuditDrawingLicensesCommand.php
  - app/Services/PdfRenderService.php
  - resources/views/pdf/drawings/schematic.blade.php
  - resources/views/pdf/drawings/rack.blade.php
  - resources/views/pdf/drawings/bound-cover.blade.php
  - public/fonts/.gitkeep
  - config/queue.php
  - .env.example
  - docs/runbooks/drawings-queue-runbook.md
  - tests/Feature/Drawings/OmManualEmbedsRackTest.php
  - tests/Unit/Console/PdfSmokeTestRackTest.php
  - tests/Unit/Console/AuditDrawingLicensesTest.php
autonomous: true
requirements: []

must_haves:
  truths:
    - "User who opens an O&M Manual sees a Drawings section with BOTH schematic AND rack PNGs embedded — one drawing per page (DRAW-26 extension; was schematic-only after Phase 17)"
    - "Operator can run `php artisan pdf:smoke-test --drawings` and see BOTH a schematic PDF AND a rack PDF rendered to disk with non-zero bytes (CRIT-04 hardening)"
    - "Operator can run `php artisan drawings:audit-licenses` and see PASS/FAIL on whether composer or npm dependencies introduce GPL/AGPL licenses (MOD-01)"
    - "Operator running `queue:work --queue=drawings` processes BoundPdfJob without contention from RAMS / O&M / Worksheet / Cable / Schematic jobs (CRIT-03)"
    - ".env.example documents the chrome-headless-shell version pin alongside CHROME_PATH (CRIT-04)"
    - "Schematic + rack + bound-cover Blade views @font-face declare Liberation Sans / DejaVu Sans fallbacks; PDFs render with consistent fonts on dev (full Chrome) and prod (chrome-headless-shell) — no missing-glyph boxes (CRIT-04)"
  artifacts:
    - path: "app/Console/Commands/AuditDrawingLicensesCommand.php"
      provides: "License audit command — fails on GPL/AGPL composer/npm deps"
      contains: "drawings:audit-licenses"
    - path: "config/queue.php"
      provides: "drawings queue connection definition"
      contains: "drawings"
    - path: "docs/runbooks/drawings-queue-runbook.md"
      provides: "Deploy runbook entry for the dedicated drawings queue worker"
    - path: "resources/views/pdf/drawings/schematic.blade.php"
      provides: "@font-face declarations for Liberation Sans + DejaVu Sans (CRIT-04)"
      contains: "font-face"
    - path: "app/Services/OmManualDocxService.php"
      provides: "Drawings section embedding loop now includes rack PNGs alongside schematic PNGs"
    - path: ".env.example"
      provides: "CHROME_HEADLESS_SHELL_VERSION env var pin documentation"
      contains: "CHROME_HEADLESS_SHELL_VERSION"
  key_links:
    - from: "OmManualDocxService::handle drawings loop"
      to: "DrawingExportRendererService::ensurePngForHandover"
      via: "kind-agnostic loop (already correct in Phase 17 — Task verifies + adds rack-aware acceptance)"
      pattern: "ensurePngForHandover"
    - from: "PdfSmokeTestCommand --drawings"
      to: "DrawingExportRendererService::renderPdf for KIND_RACK"
      via: "extended fallback fixture for rack"
      pattern: "KIND_RACK"
    - from: "BuildBoundPdfJob::queue"
      to: "config queue.connections.drawings.queue"
      via: "queue name 'drawings'"
      pattern: "queue.*drawings"
    - from: "PdfRenderService::fromBlade Browsershot construction"
      to: "addChromiumArguments disable-dev-shm-usage"
      via: "VERIFIED already present (line 83) — no code change needed; Task documents finding"
      pattern: "disable-dev-shm-usage"
---

<objective>
Production-harden the v1.3 drawings pipeline so it ships safely. Five hardening items per CONTEXT.md production-hardening-non-negotiables block:

1. **OmManualDocxService extension** — Phase 17 Plan 03 shipped the Drawings section but only embedded schematic PNGs (per CONTEXT.md narrative). Inspection of `OmManualDocxService.php` lines 154-213 actually shows the loop is ALREADY kind-agnostic (filters by status=READY only, ordered by kind), and `DrawingExportRendererService::bladeViewFor` was lit up for racks in Phase 18 Plan 03 — so racks already embed end-to-end. This Task LOCKS that behaviour with a regression test so a future v1.3.x change cannot silently regress to schematic-only.

2. **`pdf:smoke-test --drawings` rack extension** — Phase 17 Plan 03 added the flag but only renders schematics. Extend the fallback path to render BOTH a schematic fixture AND a rack fixture; report both outcomes; exit failure if either render returns zero bytes (CRIT-04).

3. **License audit command** — `drawings:audit-licenses` runs `composer licenses --format=json` + greps `package-lock.json` for the GPL/AGPL family; fails if any new drawing-related dep brings them in (MOD-01).

4. **Dedicated `drawings` queue + deploy runbook** — add `drawings` connection to `config/queue.php`; document worker process in `docs/runbooks/drawings-queue-runbook.md` with the `queue:work --queue=drawings --max-jobs=10 --memory=512` invocation. CRIT-03 mitigation. The `--disable-dev-shm-usage` Chromium flag is **already present** in PdfRenderService (verified at lines 83 + 167) so Task does NOT re-add it — instead documents that PdfRenderService is the single hardening surface (consistent with Warning 8 + Phase 17 Plan 03 architectural decision).

5. **`@font-face` + chrome version pin** — declare @font-face for Liberation Sans + DejaVu Sans in the three drawing Blade views; pin chrome-headless-shell version in `.env.example`. CRIT-04 mitigation. Fonts use a `public/fonts/` directory (currently absent — Task creates `.gitkeep`); the actual font binaries land via deploy runbook (don't commit binaries to git).

This plan does NOT carry DRAW-XX requirement IDs (those all land in 20-01). It is pure hardening + integration completion. Per critical_constraints in planning_context: license audit is OPTIONAL for v1.3 ship if `composer licenses` is fragile in CI — Task ships the command but does NOT gate ship on it.

Output: queue config + runbook + license command + smoke-test extension + font-face Blade declarations + .env.example pin + 3 hardening tests.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/STATE.md
@.planning/phases/20-drawing-export-pipeline-o-m-integration/20-CONTEXT.md
@.planning/phases/20-drawing-export-pipeline-o-m-integration/20-01-bound-pdf-sheet-numbering-zip-PLAN.md
@.planning/research/PITFALLS.md
@.planning/quick/260427-qvr-migrate-pdf-rendering-to-browsershot/260427-qvr-SUMMARY.md
@CLAUDE.md

# Phase 17 + 18 + 20-01 surfaces this plan touches:
@app/Services/OmManualDocxService.php
@app/Services/PdfRenderService.php
@app/Console/Commands/PdfSmokeTestCommand.php
@app/Models/ProjectDrawing.php
@config/queue.php
@.env.example
@resources/views/pdf/drawings/schematic.blade.php
@resources/views/pdf/drawings/rack.blade.php
@composer.json

<interfaces>
Verified contracts the executor needs:

- OmManualDocxService.php (lines 166-213): the Drawings loop already filters by status=READY + non-superseded and loops kind-agnostically. DrawingExportRendererService::ensurePngForHandover supports both KIND_SCHEMATIC and KIND_RACK already (Phase 18 Plan 03 lit up the rack arm of bladeViewFor). So racks already embed. Task is to LOCK this with a regression test, not to change the loop.

- PdfRenderService.php (lines 80-89 + 163-170): addChromiumArguments(['disable-dev-shm-usage' => null, 'disable-setuid-sandbox' => null]) is ALREADY present in BOTH fromBlade AND fromBladeAsPng. CRIT-03 flag is already shipped — no code change needed. Plan documents this and adds a runbook check.

- PdfSmokeTestCommand.php (lines 58-135): the --drawings flag currently only renders schematics. Task extends with an analogous rack fallback path — try a real ready rack first, fall back to in-memory rack fixture rendering pdf.drawings.rack. Reports both outcomes; non-zero on either failure.

- resources/views/pdf/drawings/schematic.blade.php (line 65) and rack.blade.php (line 61): both views currently set font-family stack to Arial, Helvetica, Liberation Sans, DejaVu Sans, sans-serif !important inside SVG text. Task adds @font-face declarations above this rule pointing to /fonts/*.woff2 — production deploy ships the actual font binaries to public/fonts/ (out-of-git, runbook step).

- .env.example (line containing CHROME_PATH=/home/stcav/chrome): Task appends CHROME_HEADLESS_SHELL_VERSION=147.0.7727.57 with a comment block explaining the runbook-driven bump.

</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: O&M rack-embed regression test + pdf:smoke-test --drawings rack extension</name>
  <files>
    app/Console/Commands/PdfSmokeTestCommand.php,
    tests/Feature/Drawings/OmManualEmbedsRackTest.php,
    tests/Unit/Console/PdfSmokeTestRackTest.php
  </files>
  <read_first>
    @app/Services/OmManualDocxService.php (lines 154-213 — Drawings section loop; verify it is already kind-agnostic)
    @app/Console/Commands/PdfSmokeTestCommand.php (lines 58-135 — --drawings flag handler; Task extends with rack fallback)
    @app/Services/Drawings/DrawingExportRendererService.php (renderPdf + bladeViewFor — confirm both kinds resolve)
    @app/Models/ProjectDrawing.php (KIND_RACK constant + factory shape)
    @tests/Feature/Drawings/ (existing test patterns from Phase 17/18 — Storage::fake('documents'), Project + ProjectDrawing factories)
  </read_first>
  <behavior>
    Smoke test extension behavior: When `php artisan pdf:smoke-test --drawings` runs, the command should:
      1. Attempt schematic render (existing path — unchanged)
      2. Attempt rack render — find latest ready rack OR fall back to in-memory rack fixture (with placeholder generated_svg)
      3. Report BOTH outcomes (lines like "Schematic smoke: OK ({size} bytes)", "Rack smoke: OK ({size} bytes)")
      4. Exit FAILURE if either renders zero bytes; exit SUCCESS only if both succeeded.

    O&M rack embed behavior (regression-locked): An OmManual whose project has 1 ready schematic + 1 ready rack should produce a DOCX where the Drawings section's underlying PhpWord sections call addImage TWICE — once for schematic, once for rack — interleaved with addPageBreak.

    Test 1 (OmManualEmbedsRackTest::test_om_drawings_section_embeds_both_schematic_and_rack):
      - Create Project, OmManual
      - Create 1 ready schematic with sheet_number AV-201, generated_svg = stub SVG, and a stub PNG fixture present at the handover-png cache path
      - Create 1 ready rack with sheet_number AV-301, generated_svg = stub SVG, and a stub PNG fixture at the handover cache path
      - Mock DrawingExportRendererService::ensurePngForHandover to return paths to fixture PNGs (avoid Browsershot)
      - Call OmManualDocxService::build($manual)
      - Open the resulting DOCX as a ZIP; read word/document.xml as a string
      - Assert the document.xml contains TWO drawing blocks AND both kindLabel strings ("System Schematic" AND "Rack Elevation")

    Test 2 (OmManualEmbedsRackTest::test_om_drawings_section_skips_failed_renders):
      - Mock ensurePngForHandover to return null for the rack (simulate render failure)
      - Assert document.xml contains ONE drawing block (schematic only); rack is skipped per existing Phase 17 Plan 03 try/catch (the `if (! $pngPath || ! is_file($pngPath)) continue;` line)

    Test 3 (PdfSmokeTestRackTest::test_drawings_smoke_renders_rack_in_addition_to_schematic):
      - Use Artisan::call('pdf:smoke-test', ['--drawings' => true, '--out' => $tmpPath])
      - Mock PdfRenderService partial to write stub PDF bytes to both schematic + rack out paths
      - Assert command exit code = 0 AND output (Artisan::output()) contains BOTH "Schematic" AND "Rack"

    Test 4 (test_drawings_smoke_fails_when_rack_render_returns_zero_bytes):
      - Mock the rack render to write a 0-byte file
      - Assert command exit code = 1 (FAILURE) AND output mentions failure for the rack
  </behavior>
  <action>
    1. Modify `app/Console/Commands/PdfSmokeTestCommand.php`:
       Refactor `renderDrawingSmoke()` so it now runs TWO sub-renders sequentially (schematic, then rack), tracks both outcomes, and returns FAILURE if either failed.

       Extract the existing schematic logic into a new private method `renderSchematicSmoke(PdfRenderService $renderer, string $out): bool` (returns true on success).

       Add an analogous private method `renderRackSmoke(PdfRenderService $renderer, string $out): bool` that:
       - Looks for an existing real rack (kind=KIND_RACK, status=READY, whereNull(superseded_by_id), latest('id'))
       - If found: calls `app(DrawingExportRendererService::class)->renderPdf($real)` and copies the result to $out
       - If not found: builds an in-memory ProjectDrawing fixture with kind=KIND_RACK, status=READY, version=1, and a minimal placeholder generated_svg (rack-shaped: a tall narrow rect with U-rail markers, ~10 lines of SVG); calls `$renderer->fromBlade('pdf.drawings.rack', ['drawing' => $fixture], $out)`
       - Returns false on exception OR zero-byte output (with $this->error as before)
       - Returns true on success (with $this->info as before)

       Refactor `renderDrawingSmoke()` to:
       - Compute schematic out path (existing default storage_path('app/pdf-smoke-drawing.pdf'))
       - Compute rack out path (storage_path('app/pdf-smoke-drawing-rack.pdf'))
       - Call both helpers; return SUCCESS only if both true.
       - Print a final summary line: "Drawings smoke: schematic={ok|FAIL} rack={ok|FAIL}".

    2. Test file `tests/Feature/Drawings/OmManualEmbedsRackTest.php`:
       Use RefreshDatabase + Storage::fake('documents'). Create Project + OmManual via factories. Create ProjectDrawing rows directly via ::create() with status=READY + sheet_number set. Bind a Mockery partial of DrawingExportRendererService into the container that returns paths to fixture PNG files (write a 1x1 PNG via `imagepng(imagecreate(1,1), $tmpPath)` in setUp). After OmManualDocxService::build($manual) returns, locate the DOCX via $manual->filename + DocumentArtifactStorage::readPath; open with PHP's ZipArchive; readFile word/document.xml; assert against XML contents.

    3. Test file `tests/Unit/Console/PdfSmokeTestRackTest.php`:
       Mock PdfRenderService partial via Mockery. For Test 3, mock both fromBlade calls to write a stub `%PDF-1.4` byte to the requested path. For Test 4, mock the rack call to write empty string. Use `Artisan::call(...)` + `Artisan::output()`. Assert exit code via the return value of Artisan::call.
  </action>
  <verify>
    <automated>php artisan test --filter="OmManualEmbedsRackTest|PdfSmokeTestRackTest"</automated>
  </verify>
  <acceptance_criteria>
    - `grep -n "renderRackSmoke\|renderSchematicSmoke" app/Console/Commands/PdfSmokeTestCommand.php` returns 2+ matches (the two private methods)
    - `grep -n "KIND_RACK" app/Console/Commands/PdfSmokeTestCommand.php` returns at least 1 match (rack fixture path)
    - `php artisan pdf:smoke-test --drawings` (with no real drawings in DB) prints lines containing both "schematic" AND "rack"
    - `php artisan test --filter=OmManualEmbedsRackTest` reports 2 tests / all green
    - `php artisan test --filter=PdfSmokeTestRackTest` reports 2 tests / all green
    - O&M test asserts both "System Schematic" AND "Rack Elevation" appear in the resulting document.xml
  </acceptance_criteria>
  <done>O&M Manual rack-embedding behaviour locked by regression test (cannot regress in future v1.3.x patches); pdf:smoke-test --drawings now exercises both schematic + rack render paths and fails loudly if either breaks.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: License audit command + drawings queue config + .env.example pin + deploy runbook</name>
  <files>
    app/Console/Commands/AuditDrawingLicensesCommand.php,
    config/queue.php,
    .env.example,
    docs/runbooks/drawings-queue-runbook.md,
    tests/Unit/Console/AuditDrawingLicensesTest.php
  </files>
  <read_first>
    @composer.json (current require block — what licenses to audit)
    @config/queue.php (existing connections — Task adds a 'drawings' connection)
    @.env.example (CHROME_PATH section — Task appends CHROME_HEADLESS_SHELL_VERSION near it)
    @.planning/quick/260427-qvr-migrate-pdf-rendering-to-browsershot/260427-qvr-SUMMARY.md (Browsershot deployment runbook precedent — Task mirrors structure)
    @app/Console/Commands/PdfSmokeTestCommand.php (existing artisan command shape — Task mirrors signature/handle structure)
  </read_first>
  <behavior>
    AuditDrawingLicensesCommand:
      - Signature: `drawings:audit-licenses {--strict : Treat LGPL as failure too}`
      - Runs `composer licenses --format=json` via Symfony Process; parses output; flags any package whose license string matches a GPL/AGPL regex. With --strict, also flags LGPL.
      - Greps package-lock.json (if exists) for license strings containing GPL/AGPL — npm equivalent.
      - Exit code: 0 = clean, 1 = at least one offender (prints offenders as a $this->table).
      - Output is human-readable (not JSON) — operator-facing.
      - Helpers `runComposerLicenses()` and `runNpmLockGrep()` declared `protected` so test subclasses can override with stub data.

    config/queue.php drawings connection:
      - Add a `drawings` connection block (NOT just a queue name on the existing default connection — an explicit separate connection so deploy can monitor the worker by name). Driver = database; queue = env('DB_QUEUE_DRAWINGS', 'drawings'); retry_after = 600 (longer than default 90 because bound PDF builds can take 90s+); after_commit = false.
      - Document the choice in a `// Phase 20 — drawings queue (CRIT-03)` block comment above the new connection.

    .env.example pin:
      - Append (after the existing CHROME_PATH section) a multi-line block declaring CHROME_HEADLESS_SHELL_VERSION=147.0.7727.57 with comment that bumps require running pdf:smoke-test --drawings first; reference the runbook.

    drawings-queue-runbook.md:
      - One-page runbook in docs/runbooks/. Sections: Why a separate queue (CRIT-03 reference), Deploy steps (the queue:work invocation), supervisor config snippet (mirror the existing default queue's), Chrome version bump procedure (CRIT-04), license audit step (drawings:audit-licenses run during deploy), Where fonts live (public/fonts/ — runbook adds copy step for Liberation Sans + DejaVu Sans woff2 binaries).

    Test 1 (AuditDrawingLicensesTest::test_audit_passes_on_current_clean_composer_state):
      - Run Artisan::call('drawings:audit-licenses')
      - Assert exit code 0
      - Assert no output line contains "GPL" or "AGPL" as an offender

    Test 2 (test_audit_fails_when_a_GPL_dep_is_simulated):
      - Subclass the command to override runComposerLicenses() with stub data containing one package with license "GPL-3.0"; bind subclass into the container
      - Assert exit code 1 AND output mentions the simulated package name + "GPL-3.0"

    Test 3 (test_audit_lgpl_only_fails_with_strict_flag):
      - Override stub to return a package with license "LGPL-3.0"
      - Without --strict: exit 0; with --strict: exit 1
  </behavior>
  <action>
    1. Create `app/Console/Commands/AuditDrawingLicensesCommand.php` extending `Illuminate\Console\Command`:
       - Signature `drawings:audit-licenses {--strict}`
       - Description "Audit composer + npm dependencies for GPL/AGPL/LGPL licenses (Phase 20 MOD-01)."
       - handle(): collect offenders from runComposerLicenses() and runNpmLockGrep(); report via $this->info on clean OR $this->error + $this->table on failure
       - protected runComposerLicenses(): array — runs `composer licenses --format=json --no-ansi` via Symfony\Component\Process\Process with 60s timeout; parses dependencies block; returns map of package => license string (joined with `|` if multiple licenses listed)
       - protected runNpmLockGrep(): array — reads base_path('package-lock.json') if exists; parses JSON packages map; returns map of package => license
       - protected violatesPolicy(string $license, bool $strict): bool — uses regex `~(?:^|[/+\s])(?:A?GPL)\b~i` for default; ALSO `~\bLGPL\b~i` when strict
       - Helpers declared `protected` so test subclasses can override stubs

    2. Modify `config/queue.php`. Inside the connections array (alphabetically near 'database'), add a new `drawings` connection block: driver=database, connection=env('DB_QUEUE_CONNECTION'), table=env('DB_QUEUE_TABLE','jobs'), queue=env('DB_QUEUE_DRAWINGS','drawings'), retry_after=600 via env override DB_QUEUE_DRAWINGS_RETRY_AFTER, after_commit=false. Add a leading comment block explaining CRIT-03.

    3. Modify `.env.example`. Locate the existing `CHROME_PATH=` line. Append, BELOW that line, a "Phase 20 (CRIT-04)" comment block followed by `CHROME_HEADLESS_SHELL_VERSION=147.0.7727.57`. Then a second commented-out block for DB_QUEUE_DRAWINGS + DB_QUEUE_DRAWINGS_RETRY_AFTER overrides.

    4. Create `docs/runbooks/drawings-queue-runbook.md`. ~30-50 lines. Sections (use markdown ## headings):
       - "Why a separate drawings queue?" — CRIT-03 reference; bound PDF builds can take 90s+ and OOM-kill default queue workers. Isolation = bound PDFs cannot starve RAMS notification emails.
       - "Worker process" — exact `php artisan queue:work --queue=drawings --max-jobs=10 --memory=512 --timeout=600 --tries=2` command; supervisor config snippet (copy from existing default queue runbook); restart procedure.
       - "Chrome upgrade procedure" — bump CHROME_HEADLESS_SHELL_VERSION in env, deploy, run `php artisan pdf:smoke-test --drawings`, verify output, repoint /home/stcav/chrome symlink, run smoke-test once more.
       - "License audit gate" — `php artisan drawings:audit-licenses` should be added to deploy preflight; failure = pause deploy + investigate.
       - "Fonts setup" — Liberation Sans + DejaVu Sans woff2 binaries should live at `public/fonts/`. Don't commit binaries to git. Deploy step: copy from `/home/stcav/fonts/` to `{project_root}/public/fonts/` after first deploy on a new server. Confirm via `ls public/fonts/` shows liberation-sans-regular.woff2 + dejavu-sans-regular.woff2.

    5. Create test file `tests/Unit/Console/AuditDrawingLicensesTest.php`. Use a local subclass `StubAuditDrawingLicensesCommand` that overrides runComposerLicenses + runNpmLockGrep. In each test, $this->app->bind(AuditDrawingLicensesCommand::class, StubAuditDrawingLicensesCommand::class) BEFORE Artisan::call. Three tests as in the behavior block.
  </action>
  <verify>
    <automated>php artisan list 2>&1 | grep -q "drawings:audit-licenses" && php artisan tinker --execute="echo config('queue.connections.drawings.queue');" 2>&1 | grep -q drawings && php artisan test --filter=AuditDrawingLicensesTest</automated>
  </verify>
  <acceptance_criteria>
    - `php artisan list | grep drawings:audit-licenses` returns a match
    - `php artisan drawings:audit-licenses` exits 0 against the current clean composer state (post 20-01's setasign/fpdi MIT install)
    - `grep -n "drawings" config/queue.php` returns at least 1 match in the connections block (the new connection key)
    - `grep -n "CHROME_HEADLESS_SHELL_VERSION" .env.example` returns a match
    - `test -f docs/runbooks/drawings-queue-runbook.md` succeeds AND `wc -l docs/runbooks/drawings-queue-runbook.md` returns > 30
    - `php artisan test --filter=AuditDrawingLicensesTest` reports 3 tests / all green
    - `php artisan tinker --execute="echo config('queue.connections.drawings.queue');"` outputs `drawings`
  </acceptance_criteria>
  <done>License audit command shipped + tested; drawings queue connection registered in config; .env.example documents Chrome version pin alongside CHROME_PATH; deploy runbook covers worker process, Chrome upgrade procedure, license audit gate, and font copy step. Operators have a single page describing what's different about deploying drawings.</done>
</task>

<task type="auto">
  <name>Task 3: @font-face declarations in drawing Blade views + public/fonts placeholder + verify --disable-dev-shm-usage already present</name>
  <files>
    resources/views/pdf/drawings/schematic.blade.php,
    resources/views/pdf/drawings/rack.blade.php,
    resources/views/pdf/drawings/bound-cover.blade.php,
    public/fonts/.gitkeep
  </files>
  <read_first>
    @resources/views/pdf/drawings/schematic.blade.php (current font-family override at line 65 — Task adds @font-face above it)
    @resources/views/pdf/drawings/rack.blade.php (current font-family at line 61 — same change)
    @app/Services/PdfRenderService.php (lines 80-89 + 163-170 — verify --disable-dev-shm-usage IS already present; Task documents finding, no functional change to the service)
  </read_first>
  <behavior>
    Each of the three Blade views (schematic, rack, bound-cover) declares @font-face for Liberation Sans (regular + bold) and DejaVu Sans (regular) inside the existing &lt;style&gt; block, BEFORE the existing font-family rule that overrides text/tspan. The src URLs reference `/fonts/liberation-sans-regular.woff2`, `/fonts/liberation-sans-bold.woff2`, `/fonts/dejavu-sans-regular.woff2`. Use font-display: block so Browsershot waits for the font before snapshot.

    public/fonts/.gitkeep added so the directory exists in git; binaries committed via deploy runbook (NOT git — they are 100KB+ each).

    Verification step ONLY (no code change) for PdfRenderService: confirm via grep that `disable-dev-shm-usage` already appears in BOTH fromBlade and fromBladeAsPng.

    For bound-cover.blade.php (created in 20-01 Task 2), the Task adds the same @font-face block to its existing &lt;style&gt; section. This is a forward-compatible edit — bound-cover.blade.php must exist before Task 3 runs (Wave 2 dependency on 20-01 ensures this).

    No new test file. Verification is grep-based + manual smoke render:
      - Grep: assert all three views contain @font-face declarations for both Liberation Sans + DejaVu Sans
      - Grep: assert PdfRenderService still contains 'disable-dev-shm-usage' (regression guard — confirms 20-01 didn't accidentally remove it during BoundPdfBuilderService work)
      - Manual rendering test: run `php artisan pdf:smoke-test --drawings` after the change; if no fonts present in public/fonts/ yet, the @font-face URLs 404 silently and Browsershot falls back to the next font in the family stack (Arial, Helvetica, sans-serif) — output is still a valid PDF, no breakage. Run yields the same byte size as before the @font-face change ± 2% (no regression).
  </behavior>
  <action>
    1. Modify `resources/views/pdf/drawings/schematic.blade.php`. Inside the existing &lt;style&gt; block, BEFORE the rule that sets `svg, svg * { font-family: Arial, ... }` (line near 65 — re-read to confirm exact line), insert three @font-face declarations:
       - Liberation Sans regular (font-weight 400) -> /fonts/liberation-sans-regular.woff2 format('woff2'), font-display: block
       - Liberation Sans bold (font-weight 700) -> /fonts/liberation-sans-bold.woff2 format('woff2'), font-display: block
       - DejaVu Sans regular (font-weight 400) -> /fonts/dejavu-sans-regular.woff2 format('woff2'), font-display: block
       Add a CSS comment line above explaining: "Phase 20 (CRIT-04) — explicit @font-face so chrome-headless-shell finds the font. Production drops the woff2 binaries into public/fonts/ via the deploy runbook; if missing, the existing font-family fallback chain (Arial → Helvetica → Liberation Sans → DejaVu Sans → sans-serif) keeps PDFs valid — no breakage."

    2. Apply the same insertion to `resources/views/pdf/drawings/rack.blade.php` (insert before the analogous font-family override at line 61).

    3. Apply the same insertion to `resources/views/pdf/drawings/bound-cover.blade.php` (created by 20-01 Task 2 — insert at the top of its &lt;style&gt; block).

    4. Create `public/fonts/.gitkeep` (empty file) so the directory exists in git. The actual woff2 binaries are NOT committed (deploy runbook adds them).

    5. Verification (no code change to PdfRenderService — just confirm via grep that `disable-dev-shm-usage` is still present in BOTH methods; document this finding in the Task SUMMARY).

    6. Run `php artisan pdf:smoke-test --drawings` after the changes; capture output to confirm exit code 0 + non-zero PDF byte sizes (regression check that @font-face additions didn't break rendering).
  </action>
  <verify>
    <automated>grep -l "font-face" resources/views/pdf/drawings/schematic.blade.php resources/views/pdf/drawings/rack.blade.php resources/views/pdf/drawings/bound-cover.blade.php && grep -c "disable-dev-shm-usage" app/Services/PdfRenderService.php && test -f public/fonts/.gitkeep && php artisan pdf:smoke-test --drawings</automated>
  </verify>
  <acceptance_criteria>
    - `grep -l "font-face" resources/views/pdf/drawings/schematic.blade.php` succeeds (file matches)
    - `grep -l "font-face" resources/views/pdf/drawings/rack.blade.php` succeeds
    - `grep -l "font-face" resources/views/pdf/drawings/bound-cover.blade.php` succeeds
    - `grep -c "Liberation Sans" resources/views/pdf/drawings/schematic.blade.php` returns >= 2 (regular + bold)
    - `grep -c "DejaVu Sans" resources/views/pdf/drawings/schematic.blade.php` returns >= 1
    - `grep -c "disable-dev-shm-usage" app/Services/PdfRenderService.php` returns >= 2 (one in fromBlade, one in fromBladeAsPng — regression guard)
    - `test -f public/fonts/.gitkeep` succeeds
    - `php artisan pdf:smoke-test --drawings` exits 0 with non-zero PDF byte sizes for both schematic + rack outputs
  </acceptance_criteria>
  <done>Three drawing Blade views declare @font-face fallbacks; public/fonts/ directory exists in git via .gitkeep; deploy runbook (Task 2) instructs operators to drop the actual woff2 binaries into the directory; PdfRenderService confirmed unchanged with disable-dev-shm-usage flag still present (single Browsershot hardening surface, per Warning 8 architectural decision).</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| operator → artisan command (drawings:audit-licenses) | local-shell-only; no untrusted input flows in |
| operator → artisan command (pdf:smoke-test --drawings) | local-shell-only; no untrusted input |
| Blade view → Chromium @font-face URL fetch | font URL is server-relative (/fonts/*) and statically declared in template; no user input flows into URL |
| queue:work --queue=drawings → BuildBoundPdfJob | queue payload is project_id (integer); job re-fetches Project model from DB; no untrusted input |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-20-09 | Tampering | drawings:audit-licenses output trust | accept | Operator-only command; output is read by humans not parsed by other systems; if a malicious composer dep changes its license string, the regex catches GPL/AGPL strings — false negatives possible if a dep deliberately misreports, but that's outside platform threat model |
| T-20-10 | Denial of service | dedicated drawings queue worker memory | mitigate | --memory=512 flag in runbook caps worker RSS; --max-jobs=10 cycles the worker before fork-leakage compounds; CRIT-03 hardening complete via the existing PdfRenderService flag (verified) + isolation from default queue |
| T-20-11 | Information disclosure | runbook contents | accept | Runbook in docs/runbooks/ is committed to git; repo is private; no secrets included (only command names + paths) |
| T-20-12 | Denial of service | @font-face missing-binary fallback | mitigate | font-display: block makes Browsershot wait for font; if font binary 404s, Chromium silently moves to next font in the CSS family stack (Arial → Helvetica → Liberation Sans → DejaVu Sans → sans-serif); existing fallback chain documented in schematic.blade.php line 65 keeps PDFs valid |
| T-20-13 | Tampering | Chrome version pin | mitigate | .env.example documents the pinned version; runbook makes bumps explicit; smoke-test gate prevents silent prod-vs-dev divergence (CRIT-04 mitigation) |
| T-20-14 | Information disclosure | OmManual rack-embed exposes drawing PNG to recipients | accept | Same as schematic embed (already shipped Phase 17); recipient is the project's own owner/admin via NotificationRecipientResolver — same trust level as the existing Drawings section embed |
</threat_model>

<verification>
After all 3 tasks land:

1. **O&M rack embed regression-locked**: `php artisan test --filter=OmManualEmbedsRackTest` reports 2 tests green.
2. **Smoke test extension working**: `php artisan pdf:smoke-test --drawings` produces output mentioning both "schematic" and "rack" with non-zero byte sizes for each, exit code 0.
3. **License audit clean**: `php artisan drawings:audit-licenses` exits 0 against current clean composer state (post setasign/fpdi MIT install in 20-01).
4. **License audit detects offenders**: `php artisan test --filter=AuditDrawingLicensesTest` reports 3 tests green (clean / GPL-fails / LGPL-fails-with-strict).
5. **Drawings queue defined**: `php artisan tinker --execute="echo config('queue.connections.drawings.queue');"` outputs `drawings`.
6. **.env.example documents pin**: `grep CHROME_HEADLESS_SHELL_VERSION .env.example` returns the version line.
7. **Runbook exists + non-trivial**: `wc -l docs/runbooks/drawings-queue-runbook.md` > 30.
8. **@font-face shipped in all three views**: `grep -l "font-face" resources/views/pdf/drawings/{schematic,rack,bound-cover}.blade.php` returns 3 file paths.
9. **Browsershot hardening flag intact**: `grep -c "disable-dev-shm-usage" app/Services/PdfRenderService.php` returns >= 2 (fromBlade + fromBladeAsPng both still hardened — regression guard against accidental 20-01 removal).
10. **Phase 17 + 18 + 20-01 functionality intact**: `php artisan test --testsuite=Feature --filter=Drawings` reports all Drawings tests green (no regressions).
</verification>

<success_criteria>
Production hardening complete. O&M Manual rack-embed locked by regression test. pdf:smoke-test --drawings covers both schematic + rack render paths. drawings:audit-licenses command available and tested with 3 stub-driven tests. Dedicated drawings queue connection defined in config/queue.php with deploy runbook documenting the worker process. .env.example pins chrome-headless-shell version. Three drawing Blade views declare @font-face fallbacks for Liberation Sans + DejaVu Sans. PdfRenderService confirmed to still have --disable-dev-shm-usage Chromium flag in BOTH methods (regression guard against 20-01 work). Phase 20 ships safe; v1.3 milestone ready for /gsd-complete-milestone.
</success_criteria>

<output>
After completion, create `.planning/phases/20-drawing-export-pipeline-o-m-integration/20-02-SUMMARY.md` covering: confirmation that O&M rack embed already worked from Phase 18 + Plan 17-03 (regression test added not behavior change); pdf:smoke-test extension shape; drawings:audit-licenses command behavior + stub-test approach; queue config addition + runbook outline; @font-face approach + fallback chain documentation; verification that --disable-dev-shm-usage was already present (no PdfRenderService change); commit shas.
</output>
