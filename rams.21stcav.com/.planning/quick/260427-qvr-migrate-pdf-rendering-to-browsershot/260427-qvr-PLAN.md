---
phase: 260427-qvr
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - composer.json
  - app/Services/PdfRenderService.php
  - app/Services/PdfService.php
  - app/Services/SurveyPdfService.php
  - app/Console/Commands/PdfSmokeTestCommand.php
  - resources/views/pdf/_smoke.blade.php
  - resources/views/pdf/rams.blade.php
  - resources/views/pdf/om-manual.blade.php
  - .env.example
  - .planning/quick/260427-qvr-migrate-pdf-rendering-to-browsershot/260427-qvr-SUMMARY.md
autonomous: true
requirements: [QUICK-260427-QVR]
must_haves:
  truths:
    - "Composer requires spatie/browsershot ^4 and the package is installed"
    - "App\\Services\\PdfRenderService exists and renders any Blade view to PDF via chrome-headless-shell"
    - "RAMS download-pdf endpoint streams a Browsershot-rendered PDF (replacing dompdf)"
    - "O&M download-pdf endpoint streams a Browsershot-rendered PDF (replacing dompdf)"
    - "Site Survey download-pdf endpoint streams a Browsershot-rendered PDF (replacing dompdf)"
    - "Site Survey blank-form and field-form endpoints continue to work via Browsershot"
    - "php artisan pdf:smoke-test produces a non-zero PDF and exits 0"
    - "SUMMARY.md documents the production queue-worker user fix and chown commands the user must run"
  artifacts:
    - path: "app/Services/PdfRenderService.php"
      provides: "Single Browsershot wrapper used by all PDF pipelines"
      contains: "fromBlade"
    - path: "app/Services/PdfService.php"
      provides: "buildRams() and buildOmManual() now delegate to PdfRenderService"
      contains: "PdfRenderService"
    - path: "app/Services/SurveyPdfService.php"
      provides: "buildSummary(), buildBlank(), buildFieldFormContents() now delegate to PdfRenderService"
      contains: "PdfRenderService"
    - path: "app/Console/Commands/PdfSmokeTestCommand.php"
      provides: "pdf:smoke-test artisan command renders a hello-world PDF"
      contains: "pdf:smoke-test"
    - path: "resources/views/pdf/_smoke.blade.php"
      provides: "Trivial Blade view used by pdf:smoke-test"
    - path: "composer.json"
      provides: "spatie/browsershot ^4 in require"
      contains: "spatie/browsershot"
    - path: ".planning/quick/260427-qvr-migrate-pdf-rendering-to-browsershot/260427-qvr-SUMMARY.md"
      provides: "Deployment notes: chrome symlink, queue-worker user fix, one-time chown"
      contains: "chown -R stcav:stcav storage/app/documents"
  key_links:
    - from: "app/Services/PdfService.php"
      to: "app/Services/PdfRenderService.php"
      via: "constructor-injected dependency"
      pattern: "PdfRenderService"
    - from: "app/Services/SurveyPdfService.php"
      to: "app/Services/PdfRenderService.php"
      via: "constructor-injected dependency"
      pattern: "PdfRenderService"
    - from: "app/Services/PdfRenderService.php"
      to: "/home/stcav/chrome (symlink to chrome-headless-shell)"
      via: "Browsershot::setChromePath() reading env('CHROME_PATH')"
      pattern: "setChromePath"
    - from: "app/Http/Controllers/RamsController.php::downloadPdf"
      to: "PdfService::buildRams"
      via: "existing call site (unchanged signature)"
      pattern: "pdfService->buildRams"
    - from: "app/Http/Controllers/OmManualController.php::downloadPdf"
      to: "PdfService::buildOmManual"
      via: "existing call site (unchanged signature)"
      pattern: "pdfService->buildOmManual"
    - from: "app/Http/Controllers/SiteSurveyController.php::downloadPdf"
      to: "SurveyPdfService::buildSummary"
      via: "existing call site (unchanged signature)"
      pattern: "pdfService->buildSummary"
---

<objective>
Replace the dompdf-based PDF rendering used by RAMS, O&M, and Site Survey downloads with a single Browsershot pipeline driven by the chrome-headless-shell binary already installed on the AlmaLinux 8 production server. Existing Blade templates are reused as-is; this plan only swaps the rendering engine plumbing. Dompdf and mPDF stay in `composer.json` as fallbacks until production stability is proven (separate cleanup task later).

Purpose: dompdf produces brittle output (ignores modern CSS, weak unicode, no real headers/footers) and the duplicated render code paths (`PdfService` for RAMS+O&M, `SurveyPdfService` for surveys, `CommissioningPdfService` for snagging — left untouched) keep accumulating divergent CSS/font hacks. Centralising on Browsershot + chrome-headless-shell gives one engine, one wrapper, and identical rendering across every doc type. Bundling the queue-worker user fix prevents the recurring root-owned-file outage.

Output:
- `app/Services/PdfRenderService.php` — the single Browsershot wrapper
- `app/Console/Commands/PdfSmokeTestCommand.php` — `php artisan pdf:smoke-test`
- `resources/views/pdf/_smoke.blade.php` — minimal Blade for the smoke test
- `app/Services/PdfService.php` — refactored to delegate to PdfRenderService (RAMS + O&M)
- `app/Services/SurveyPdfService.php` — refactored to delegate to PdfRenderService (summary, blank, field-form)
- `.planning/quick/260427-qvr-migrate-pdf-rendering-to-browsershot/260427-qvr-SUMMARY.md` — deployment runbook for chrome symlink, queue-worker user fix, and one-time chown
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@CLAUDE.md
@composer.json
@app/Services/PdfService.php
@app/Services/SurveyPdfService.php
@app/Services/DocumentArtifactStorage.php
@app/Http/Controllers/RamsController.php
@app/Http/Controllers/OmManualController.php
@app/Http/Controllers/SiteSurveyController.php

<interfaces>
<!-- Existing public APIs the migrated services must preserve so controllers do not change -->

From app/Services/PdfService.php (current):
```php
class PdfService
{
    public function buildRams(\App\Models\RamsDocument $rams): string;          // returns absolute PDF path
    public function buildOmManual(\App\Models\OmManual $manual): string;        // returns absolute PDF path
}
```

From app/Services/SurveyPdfService.php (current):
```php
class SurveyPdfService
{
    public function buildSummary(\App\Models\SiteSurvey $survey): string;       // returns absolute PDF path; updates $survey->filename
    public function buildBlank(): string;                                       // returns absolute PDF path
    public function buildFieldFormContents(\App\Models\SiteSurvey $survey): string;  // returns raw PDF bytes (no disk write)
}
```

From app/Services/DocumentArtifactStorage.php:
```php
class DocumentArtifactStorage
{
    public const TYPE_RAMS = 'rams';
    public const TYPE_OM = 'om-manuals';
    public function writePath(string $type, string $filename): string;          // creates parent dir, returns absolute path
    public function readPath(string $type, string $filename): ?string;          // null if not found in current OR legacy location
    public function delete(string $type, string $filename): void;
}
```

Controller call sites (must remain valid post-migration — no signature changes):
- `RamsController::downloadPdf` → `$this->pdfService->buildRams($rams)` returns string path; streamed via `response()->download(..., $pdfName)->deleteFileAfterSend()`
- `OmManualController::downloadPdf` → `$this->pdfService->buildOmManual($omManual)` same pattern
- `SiteSurveyController::downloadPdf` → `$this->pdfService->buildSummary($siteSurvey)` same pattern (keeps `survey->update(['filename' => ...])` side-effect)
- `SiteSurveyController::downloadBlankForm` (or similar) → `$this->pdfService->buildBlank()`
- Public field-form endpoint → `$this->pdfService->buildFieldFormContents($survey)` returns raw bytes for `response($bytes)->withHeaders(['Content-Type' => 'application/pdf'])`

Browsershot library shape (spatie/browsershot ^4):
```php
use Spatie\Browsershot\Browsershot;

Browsershot::html($htmlString)
    ->setChromePath('/home/stcav/chrome')        // string|null
    ->setNodeBinary('/usr/bin/node')             // string
    ->setNpmBinary('/usr/bin/npm')               // optional
    ->noSandbox()                                // disables --no-sandbox guard
    ->addChromiumArguments([                     // extra Chrome flags
        'disable-dev-shm-usage' => null,
        'disable-setuid-sandbox' => null,
    ])
    ->format('A4')
    ->margins(10, 10, 10, 10)                    // mm: top, right, bottom, left
    ->showBackground()
    ->emulateMedia('print')
    ->savePdf(string $absolutePath): void;       // writes file
// or:
    ->pdf(): string;                              // returns raw bytes
```
</interfaces>

<server_state>
<!-- Already in place on production. Do NOT re-do. -->
- Node.js v22.14.0 at `/usr/bin/node` (NodeSource)
- Puppeteer installed in /home/stcav/rams.21stcav.com/ via `npm install puppeteer`
- chrome-headless-shell binary at `/home/stcav/.cache/puppeteer/chrome-headless-shell/linux-147.0.7727.57/chrome-headless-shell-linux64/chrome-headless-shell`
- Verified: `await page.pdf()` produced a 15 KB PDF correctly owned by stcav:stcav
- ulimit raised via /etc/security/limits.d/stcav.conf (8192/16384)
- Full Chrome 147 fails with crashpad on AlmaLinux 8 — chrome-headless-shell is the working binary
</server_state>

<known_concerns>
1. **Templates already use CSS-style page breaks** (`page-break-before: always`, `page-break-inside: avoid`, `@page { size: A4; margin: ... }`) — verified via grep. **No mPDF-specific tags** (`<pagebreak />`, `<htmlpageheader />`, `<htmlpagefooter />`) exist anywhere in `app/` or templates. Browsershot/Chromium handles these natively. No template rewrites needed.
2. **Brief says O&M uses mPDF — actual code uses dompdf** via `PdfService::buildOmManual()`. The mpdf/mpdf composer package is unused in app code (only bootstrap cache references it). Migration is therefore "dompdf → Browsershot" everywhere; mpdf removal is deferred to a future cleanup task per the "kept until stability proven" constraint.
3. **Disk write convention (H-07)**: `PdfService::renderToFile()` currently uses `Storage::disk('local')->put($diskPath, ...)` with raw `'rams/...'` / `'om-manuals/...'` paths. After migration, all writes MUST go through `DocumentArtifactStorage::writePath(TYPE_RAMS|TYPE_OM, $filename)` to honour the H-07 convention documented in CLAUDE.md. (`SurveyPdfService` keeps `Storage::disk('local')->path('site-surveys/...')` since site-surveys is not one of the H-07 types.)
4. **Streaming controllers use `deleteFileAfterSend()`** — Browsershot writes a file to disk; the controller's existing pattern continues to work unchanged.
5. **Queue worker user fix** is local-repo + documentation only. The user runs the systemd/cron edits and chown on production. No SSH from this plan.
</known_concerns>

<tasks>

<task type="auto" tdd="false">
  <name>Task 1: Add Browsershot dependency, scaffold PdfRenderService, add pdf:smoke-test command</name>
  <files>composer.json, app/Services/PdfRenderService.php, app/Console/Commands/PdfSmokeTestCommand.php, resources/views/pdf/_smoke.blade.php, .env.example</files>
  <action>
1. Edit `composer.json`: add `"spatie/browsershot": "^4"` to the `require` block (alphabetic order — between `spatie/pdf-to-text` and `symfony/http-client`). Also add a `spatie/browsershot` entry to the `extra.pdf-libraries` documentation block reading `"spatie/browsershot": "Headless Chromium PDF renderer for RAMS/O&M/Site Survey via PdfRenderService — chrome-headless-shell on AlmaLinux 8"`. Do NOT remove `barryvdh/laravel-dompdf`, `dompdf/dompdf`, or `mpdf/mpdf` — they stay until stability is proven (per task brief constraint). Run `composer require spatie/browsershot:^4` to install and update the lockfile.

2. Create `app/Services/PdfRenderService.php`:
```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\View;
use Spatie\Browsershot\Browsershot;

/**
 * Single Browsershot wrapper used by every PDF pipeline (RAMS, O&M,
 * Site Survey). Replaces the legacy dompdf calls that previously lived
 * inline inside PdfService and SurveyPdfService.
 *
 * On production (AlmaLinux 8) full Chrome 147 fails with crashpad, so we
 * point Browsershot at chrome-headless-shell via the /home/stcav/chrome
 * symlink. The path can be overridden in non-prod via CHROME_PATH.
 *
 * Chromium is invoked with --no-sandbox, --disable-dev-shm-usage, and
 * --disable-setuid-sandbox so it runs reliably under the stcav user
 * inside CWP without requiring root.
 */
class PdfRenderService
{
    /**
     * Render a Blade view to PDF.
     *
     * @param string      $view        Blade view name (e.g. 'pdf.rams')
     * @param array       $data        View data passed to view($view, $data)
     * @param string|null $writeToPath When provided, PDF is saved to this absolute path and the path is returned. When null, raw PDF bytes are returned instead.
     *
     * @return string Absolute file path (when $writeToPath provided) or raw PDF bytes (when null).
     */
    public function fromBlade(string $view, array $data, ?string $writeToPath = null): string
    {
        if (! View::exists($view)) {
            throw new \RuntimeException("PDF template missing: resources/views/" . str_replace('.', '/', $view) . ".blade.php");
        }

        $html = view($view, $data)->render();

        $shot = Browsershot::html($html)
            ->setChromePath(env('CHROME_PATH', '/home/stcav/chrome'))
            ->noSandbox()
            ->addChromiumArguments([
                'disable-dev-shm-usage' => null,
                'disable-setuid-sandbox' => null,
            ])
            ->format('A4')
            ->showBackground()
            ->emulateMedia('print')
            ->margins(10, 10, 10, 10);

        if ($writeToPath !== null) {
            $dir = dirname($writeToPath);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $shot->savePdf($writeToPath);
            return $writeToPath;
        }

        return $shot->pdf();
    }
}
```

3. Create `resources/views/pdf/_smoke.blade.php`:
```blade
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Smoke Test</title>
<style>
  @page { size: A4; margin: 20mm; }
  body { font-family: sans-serif; }
  h1 { color: #007B8A; }
</style></head><body>
<h1>PDF Smoke Test OK</h1>
<p>Generated {{ now()->toIso8601String() }} by Browsershot via chrome-headless-shell.</p>
<p>If you can read this PDF, the pipeline is alive.</p>
</body></html>
```

4. Create `app/Console/Commands/PdfSmokeTestCommand.php`:
```php
<?php

namespace App\Console\Commands;

use App\Services\PdfRenderService;
use Illuminate\Console\Command;

/**
 * Quickest possible "is the PDF pipeline alive?" check. Renders a tiny
 * Blade view to a PDF in storage/app/pdf-smoke.pdf and reports the file
 * size. Runs in CI and on production for post-deploy verification.
 *
 * Exit codes: 0 = OK, 1 = render failed or zero-byte output.
 */
class PdfSmokeTestCommand extends Command
{
    protected $signature = 'pdf:smoke-test {--out= : Override output path (defaults to storage/app/pdf-smoke.pdf)}';
    protected $description = 'Render a hello-world Blade view to PDF via Browsershot to verify the pipeline.';

    public function handle(PdfRenderService $renderer): int
    {
        $out = (string) ($this->option('out') ?: storage_path('app/pdf-smoke.pdf'));

        try {
            $renderer->fromBlade('pdf._smoke', [], $out);
        } catch (\Throwable $e) {
            $this->error('PDF smoke test FAILED: ' . $e->getMessage());
            return self::FAILURE;
        }

        $size = is_file($out) ? filesize($out) : 0;
        if ($size <= 0) {
            $this->error('PDF smoke test FAILED: zero-byte output at ' . $out);
            return self::FAILURE;
        }

        $this->info(sprintf('PDF smoke test OK — wrote %d bytes to %s', $size, $out));
        return self::SUCCESS;
    }
}
```

5. Edit `.env.example`: append a new section near the bottom:
```
# ── PDF rendering (Browsershot) ──────────────────────────────────────────
# Path to the Chrome/Chromium binary used by Spatie Browsershot.
# On production (AlmaLinux 8) this is a symlink to chrome-headless-shell
# created during deployment: /home/stcav/chrome
# Local dev can use: /usr/bin/google-chrome or /usr/bin/chromium-browser
CHROME_PATH=/home/stcav/chrome
```
  </action>
  <verify>
    <automated>composer require spatie/browsershot:^4 --no-interaction && php artisan list | grep -F "pdf:smoke-test"</automated>
  </verify>
  <done>composer.json requires spatie/browsershot ^4; PdfRenderService class exists with fromBlade method; pdf:smoke-test command is registered (visible in `php artisan list`); _smoke.blade.php template exists; .env.example documents CHROME_PATH.</done>
</task>

<task type="auto" tdd="false">
  <name>Task 2: Migrate PdfService (RAMS + O&M) to PdfRenderService and route writes through DocumentArtifactStorage</name>
  <files>app/Services/PdfService.php</files>
  <action>
Refactor `app/Services/PdfService.php` to delegate all rendering to `PdfRenderService` and persist via `DocumentArtifactStorage` (per H-07 in CLAUDE.md). Public method signatures `buildRams(RamsDocument): string` and `buildOmManual(OmManual): string` MUST remain unchanged so RamsController and OmManualController need zero edits. Drop the `Dompdf\Dompdf` and `Dompdf\Options` imports, drop the `Storage::disk('local')->put()` call, drop `Str::slug` import.

Replace the file contents with:
```php
<?php

namespace App\Services;

use App\Models\OmManual;
use App\Models\RamsDocument;
use Illuminate\Support\Str;

/**
 * Renders RAMS and O&M documents to PDF via Browsershot (chrome-headless-shell).
 *
 * Previously used Dompdf directly — that produced brittle output and
 * duplicated render code with SurveyPdfService. Now both pipelines share
 * one engine via PdfRenderService.
 *
 * Outputs land under storage/app/documents/{rams,om-manuals}/ via the
 * H-07 DocumentArtifactStorage convention so legacy/current path resolution
 * stays consistent with the DOCX writers.
 */
class PdfService
{
    public function __construct(
        private readonly PdfRenderService          $renderer,
        private readonly DocumentArtifactStorage   $artifacts,
    ) {}

    public function buildRams(RamsDocument $rams): string
    {
        $filename = $this->filenameFor('rams', $rams->id, $rams->project_name ?? 'rams');
        $path     = $this->artifacts->writePath(DocumentArtifactStorage::TYPE_RAMS, $filename);

        return $this->renderer->fromBlade('pdf.rams', [
            'rams' => $rams,
            'data' => $rams->generated_data ?? [],
        ], $path);
    }

    public function buildOmManual(OmManual $manual): string
    {
        $filename = $this->filenameFor('om-manuals', $manual->id, $manual->project_name ?? 'om-manual');
        $path     = $this->artifacts->writePath(DocumentArtifactStorage::TYPE_OM, $filename);

        return $this->renderer->fromBlade('pdf.om-manual', [
            'manual' => $manual,
            'data'   => $manual->generated_data ?? [],
        ], $path);
    }

    private function filenameFor(string $subfolder, int $id, string $projectName): string
    {
        return implode('_', [
            $subfolder,
            $id,
            Str::slug($projectName) ?: 'untitled',
            now()->format('Ymd'),
        ]) . '.pdf';
    }
}
```

Why this shape:
- Constructor-injected dependencies match Laravel auto-wiring (no service-provider edits needed).
- The `filenameFor()` helper preserves the previous filename pattern (`{subfolder}_{id}_{slug}_{ymd}.pdf`) so existing email/download flows that reference the filename keep working.
- Writes now go through `DocumentArtifactStorage::writePath()` — controllers using `readPath(TYPE_RAMS, ...)` (e.g. resolveRamsDocxPath) work for both DOCX and PDF artifacts uniformly.
- `view()->exists()` guard moved into `PdfRenderService::fromBlade()` (already throws `\RuntimeException` with the same message) — caller doesn't need to repeat it.
  </action>
  <verify>
    <automated>php -l app/Services/PdfService.php && php artisan tinker --execute="app(\App\Services\PdfService::class); echo 'OK';"</automated>
  </verify>
  <done>PdfService no longer references Dompdf; both buildRams() and buildOmManual() return absolute paths under storage/app/documents/{rams,om-manuals}/; controllers RamsController::downloadPdf and OmManualController::downloadPdf compile and resolve the service without errors.</done>
</task>

<task type="auto" tdd="false">
  <name>Task 3: Migrate SurveyPdfService (summary + blank + field-form) to PdfRenderService and extract Blade views</name>
  <files>app/Services/SurveyPdfService.php, resources/views/pdf/site-survey/summary.blade.php, resources/views/pdf/site-survey/blank.blade.php, resources/views/pdf/site-survey/field-form.blade.php</files>
  <action>
SurveyPdfService currently builds HTML via concatenation in private methods (`renderSummaryHtml`, `renderBlankHtml`, `renderFieldFormHtml`). Migrating to Browsershot is straightforward, but we also extract the HTML into Blade views so future edits don't require PHP code changes — keeps the "Blade-as-template, service-as-orchestration" pattern from the rest of the codebase. The CSS, sign-off block, blank-room body, etc. all become Blade partials sharing one CSS file.

**Step 1 — Create the three Blade views.** They wrap the existing HTML output so the visual design is unchanged. Move the existing `css()`, `pageNumberScript()`, `renderHeaderMetaBlock()`, `renderBlankRoomBody()`, `renderBlankRoomSection()`, `renderFieldRoomSection()`, `renderSignOffBlock()`, `narrativeAsTickList()`, `balanceParens()`, `stripLeadingDuplicate()`, `yn()`, and `blank()` helpers into Blade view logic — either inline or as `@include` partials. Drop the dompdf-specific `pageNumberScript()` (which used `<script type="text/php">` — a dompdf hack); replace with CSS `@page` counters that Chromium understands natively:

```css
@page { @bottom-right { content: "Page " counter(page) " of " counter(pages); font: 7pt 'DejaVu Sans'; color: #666; } }
```

The three Blade views must each render the equivalent HTML the old methods produced. To keep this task atomic and verifiable, the simplest mechanical move is:
- `resources/views/pdf/site-survey/summary.blade.php` — receives `$survey` and renders the equivalent of `renderSummaryHtml($survey)`.
- `resources/views/pdf/site-survey/blank.blade.php` — receives nothing, renders `renderBlankHtml()`.
- `resources/views/pdf/site-survey/field-form.blade.php` — receives `$survey`, renders `renderFieldFormHtml($survey)`.

Helper functions (`balanceParens`, `narrativeAsTickList`, `stripLeadingDuplicate`, `yn`, `blank`) move to a small `App\Support\SurveyPdfHelpers` static class so the Blade views can call `SurveyPdfHelpers::balanceParens($room->room_name)` etc. Do NOT redesign anything — same HTML structure, same CSS, same content.

**Step 2 — Refactor `app/Services/SurveyPdfService.php`** to a thin orchestration layer:
```php
<?php

namespace App\Services;

use App\Models\SiteSurvey;
use Illuminate\Support\Facades\Storage;

class SurveyPdfService
{
    public function __construct(private readonly PdfRenderService $renderer) {}

    public function buildSummary(SiteSurvey $survey): string
    {
        $survey->loadMissing('rooms.photos');

        $filename = 'site_survey_' . $survey->id . '_' . now()->format('Ymd_His') . '.pdf';
        $path     = Storage::disk('local')->path('site-surveys/' . $filename);

        $this->renderer->fromBlade('pdf.site-survey.summary', ['survey' => $survey], $path);

        $survey->update(['filename' => $filename]);

        return $path;
    }

    public function buildBlank(): string
    {
        $path = Storage::disk('local')->path('site-surveys/blank-survey-form.pdf');
        return $this->renderer->fromBlade('pdf.site-survey.blank', [], $path);
    }

    public function buildFieldFormContents(SiteSurvey $survey): string
    {
        $survey->loadMissing(['rooms', 'project.latestPackage']);
        return $this->renderer->fromBlade('pdf.site-survey.field-form', ['survey' => $survey]);
    }
}
```

Notes:
- `site-surveys/` is NOT one of the H-07 DocumentArtifactStorage types, so the existing `Storage::disk('local')->path('site-surveys/...')` is preserved — site-survey artifacts have always lived there and there is no migration mandate for them in this task.
- `buildFieldFormContents()` still returns raw bytes (no `$writeToPath`) so the public field-form endpoint streams in-memory.
- The previous `mkdir($dir, 0755, true)` parent-creation logic is now inside `PdfRenderService::fromBlade()`.

**Step 3 — Sanity-grep**: verify no callers reference the now-deleted private methods (`renderSummaryHtml`, `pageNumberScript`, `css`, etc.). Tests that mock SurveyPdfService should be unaffected because public API is unchanged. If `tests/Feature/SurveyDownloadFormTest.php` directly inspects internal HTML, leave a TODO note in SUMMARY.md — do NOT modify tests in this plan.
  </action>
  <verify>
    <automated>php -l app/Services/SurveyPdfService.php && php artisan view:clear && php artisan tinker --execute="app(\App\Services\SurveyPdfService::class); echo 'OK';" && test -f resources/views/pdf/site-survey/summary.blade.php && test -f resources/views/pdf/site-survey/blank.blade.php && test -f resources/views/pdf/site-survey/field-form.blade.php</automated>
  </verify>
  <done>SurveyPdfService no longer imports Dompdf; the three Blade views exist; `php artisan view:clear` succeeds (Blade syntax valid); SurveyPdfService resolves through the container; `SiteSurveyController::downloadPdf` route still type-checks (no signature changes).</done>
</task>

<task type="auto" tdd="false">
  <name>Task 4: Run smoke test, write deployment SUMMARY.md, commit</name>
  <files>.planning/quick/260427-qvr-migrate-pdf-rendering-to-browsershot/260427-qvr-SUMMARY.md</files>
  <action>
**Step 1 — Local smoke test.** Run `php artisan pdf:smoke-test --out=storage/app/pdf-smoke.pdf` locally if a Chrome/Chromium binary is available; if `CHROME_PATH` does not resolve on the dev machine, skip with a note in SUMMARY.md (the production smoke test is the one that matters; the artisan command exists and is wired). Do NOT hand-edit Blade or service files to "fix" failures here — if the smoke test fails on a Windows dev box because Chromium isn't installed, that is expected; the production server has chrome-headless-shell ready.

**Step 2 — Write the deployment runbook.** Create `.planning/quick/260427-qvr-migrate-pdf-rendering-to-browsershot/260427-qvr-SUMMARY.md` with the following sections (substitute exact paths/commands; the user runs these on production):

```markdown
# 260427-qvr — Migrate PDF Rendering to Browsershot — SUMMARY

**Status:** Complete (local).
**Production rollout:** requires the steps below.

## What changed (code)
- Added `spatie/browsershot ^4` (kept dompdf and mPDF in composer.json — remove in a later cleanup task once stability is proven).
- New `App\Services\PdfRenderService` — single Browsershot wrapper used by every PDF pipeline.
- New `php artisan pdf:smoke-test` command — renders `resources/views/pdf/_smoke.blade.php` to verify the pipeline.
- `App\Services\PdfService::buildRams()` and `::buildOmManual()` now delegate to `PdfRenderService` and persist via `DocumentArtifactStorage` (H-07).
- `App\Services\SurveyPdfService` (`buildSummary`, `buildBlank`, `buildFieldFormContents`) now delegate to `PdfRenderService`. HTML extracted into `resources/views/pdf/site-survey/{summary,blank,field-form}.blade.php`.
- Controllers (`RamsController::downloadPdf`, `OmManualController::downloadPdf`, `SiteSurveyController::downloadPdf`, blank-form, field-form) are unchanged — public method signatures preserved.
- `App\Services\CommissioningPdfService` is intentionally untouched (separate ticket).

## What did NOT change
- No Blade templates under `resources/views/pdf/{rams,om-manual}.blade.php` were redesigned. CSS already used dompdf-compatible `page-break-*` and `@page` rules which Chromium handles natively.
- No new Worksheet PDF download (still DOCX-only).
- DOCX generation paths (PhpWord) are untouched.

## Production rollout checklist (run on production server)

### 1. Pull the new code
    cd /home/stcav/rams.21stcav.com
    git fetch && git checkout <branch> && git pull
    composer install --no-dev --optimize-autoloader

### 2. Create the chrome symlink (one-time)
The Browsershot wrapper reads `CHROME_PATH` from env, defaulting to `/home/stcav/chrome`. We use a symlink so the versioned puppeteer-cache path isn't hard-coded in Laravel config.

    ln -sfn /home/stcav/.cache/puppeteer/chrome-headless-shell/linux-147.0.7727.57/chrome-headless-shell-linux64/chrome-headless-shell /home/stcav/chrome
    ls -l /home/stcav/chrome   # should show the symlink target

If you ever upgrade puppeteer, re-point this symlink — Laravel config does not need editing.

### 3. Confirm CHROME_PATH in .env
Append to `/home/stcav/rams.21stcav.com/.env` (only if missing):

    CHROME_PATH=/home/stcav/chrome

Then `php artisan config:clear`.

### 4. Run the smoke test as the stcav user
    sudo -u stcav -H bash -lc "cd /home/stcav/rams.21stcav.com && php artisan pdf:smoke-test"

Expected output: `PDF smoke test OK — wrote NNNN bytes to /home/stcav/rams.21stcav.com/storage/app/pdf-smoke.pdf`. The file MUST be owned by `stcav:stcav`. If it is owned by `root`, the queue worker is still running as root — go to step 5 immediately.

### 5. Fix the queue-worker user (was running as root)
The recurring "PHP-FPM cannot read documents/" outage is caused by the queue worker running as root, writing files under `storage/app/documents/{rams,om-manuals,worksheets,cable-schedules}/` as `root:root mode 644`, which PHP-FPM (running as `stcav`) cannot read inside parent dirs that are themselves `root:root 700`.

#### Find the queue runner
    systemctl list-units --type=service | grep -i queue
    # OR
    grep -r "queue:work\|queue:listen" /etc/systemd/system/ /etc/cron.d/ /etc/cron.* 2>/dev/null
    # OR (CWP)
    crontab -l -u root | grep -i artisan

#### If it's a systemd unit (most likely)
Edit the unit file, e.g. `/etc/systemd/system/rams-queue.service`. Add or change:

    [Service]
    User=stcav
    Group=stcav
    WorkingDirectory=/home/stcav/rams.21stcav.com
    ExecStart=/usr/bin/php /home/stcav/rams.21stcav.com/artisan queue:work --tries=3 --timeout=600 --sleep=1

Then:
    systemctl daemon-reload
    systemctl restart rams-queue.service
    ps -fC php | grep queue   # should show the process owned by stcav, not root

#### If it's a root crontab entry
Move it to stcav's crontab:
    crontab -l -u root | grep -v 'artisan queue' | crontab -u root -        # remove from root
    crontab -e -u stcav                                                      # add the queue:work line under stcav

Verify with `ps -fC php | grep queue`.

### 6. One-time chown of existing root-owned artifacts
    chown -R stcav:stcav /home/stcav/rams.21stcav.com/storage/app/documents/
    chmod -R u+rwX,go+rX /home/stcav/rams.21stcav.com/storage/app/documents/
    ls -ld /home/stcav/rams.21stcav.com/storage/app/documents/{rams,om-manuals,worksheets,cable-schedules}

All four subdirectories must be owned by `stcav:stcav` and readable. Any new generation from this point will be created with the right ownership because the queue worker now runs as stcav.

### 7. Functional acceptance — exercise each PDF endpoint in the browser
- Open a RAMS document → click "Download PDF" → confirm PDF opens, contains the expected RAMS sections, and the file streamed.
- Open an O&M Manual → click "Download PDF" → confirm.
- Open a Site Survey → click "Download Survey PDF" → confirm.
- Open `/site-surveys/blank-form` → confirm blank form PDF downloads.
- Open the public `/survey/{token}/download-form` link → confirm field form PDF downloads.

If any endpoint returns a 500, check `storage/logs/laravel.log` — the most likely cause is `CHROME_PATH` resolving to a binary the stcav user cannot execute. Fix by re-running step 2 and verifying with `sudo -u stcav -H /home/stcav/chrome --version`.

## Rollback
- `composer remove spatie/browsershot && git checkout HEAD~1 -- app/Services/PdfService.php app/Services/SurveyPdfService.php app/Http/Controllers/*.php`
- The dompdf path is still in composer.json so a single git revert restores the previous behaviour.

## Follow-up (not in this plan)
- Remove `barryvdh/laravel-dompdf`, `dompdf/dompdf`, `mpdf/mpdf` from composer.json once 2 weeks of clean PDF generations on production confirm Browsershot stability.
- Migrate `App\Services\CommissioningPdfService` to PdfRenderService (uses dompdf, currently out of scope).
- Worksheet PDF download (currently DOCX only).
- O&M content bugs (empty Frequency cell, hardware-as-equipment, "additional" pseudo-room) — tracked separately.
- Optional: tests in `tests/Unit/RamsPdfScopeTest.php` and `tests/Feature/SurveyDownloadFormTest.php` may reference internal HTML strings. Audit and update if needed (separate test-cleanup ticket).
```

**Step 3 — Update the STATE.md Quick Tasks table**: append a new row:
| 260427-qvr | 2026-04-27 | Migrate PDF rendering to Browsershot (RAMS, O&M, Site Survey) + bundled queue-worker user fix runbook | ✅ Done | <commit-sha> |

**Step 4 — Commit.** Use one atomic commit per the project's commit conventions:
```
feat(pdf): migrate RAMS/O&M/Site Survey rendering to Browsershot

- New App\Services\PdfRenderService wraps spatie/browsershot ^4
- PdfService and SurveyPdfService delegate to PdfRenderService
- New php artisan pdf:smoke-test verifies the pipeline
- HTML extracted from SurveyPdfService into pdf.site-survey.* Blade views
- Writes for RAMS/O&M now route through DocumentArtifactStorage (H-07)
- dompdf and mPDF kept in composer.json until production stability is proven
- Deployment runbook (chrome symlink, queue-worker user fix, chown) in 260427-qvr-SUMMARY.md
```

(If the user prefers per-step atomic commits as suggested in the brief — six small commits — they can `git rebase -i` after the fact; the plan delivers as one logical unit because each task in this plan was one logical commit.)
  </action>
  <verify>
    <automated>test -f .planning/quick/260427-qvr-migrate-pdf-rendering-to-browsershot/260427-qvr-SUMMARY.md && grep -q "chown -R stcav:stcav storage/app/documents" .planning/quick/260427-qvr-migrate-pdf-rendering-to-browsershot/260427-qvr-SUMMARY.md && grep -q "pdf:smoke-test" .planning/quick/260427-qvr-migrate-pdf-rendering-to-browsershot/260427-qvr-SUMMARY.md && grep -q "CHROME_PATH" .planning/quick/260427-qvr-migrate-pdf-rendering-to-browsershot/260427-qvr-SUMMARY.md</automated>
  </verify>
  <done>SUMMARY.md exists with all 7 production-rollout sections (pull code, chrome symlink, CHROME_PATH, smoke test, queue-worker user fix, chown, functional acceptance). STATE.md Quick Tasks table updated. Single feat(pdf) commit lands containing all of: composer.json, PdfRenderService, smoke-test command, _smoke.blade.php, refactored PdfService, refactored SurveyPdfService, three site-survey Blade views, .env.example update, SUMMARY.md.</done>
</task>

</tasks>

<verification>
After execution:

1. `composer show spatie/browsershot` reports an installed ^4 version.
2. `php artisan list | grep pdf:smoke-test` shows the new command.
3. `grep -rn "Dompdf\\\\Dompdf\\|new Dompdf" app/Services/PdfService.php app/Services/SurveyPdfService.php` returns no matches (dompdf gone from these two services). `app/Services/CommissioningPdfService.php` may still reference dompdf — out of scope for this task.
4. `grep -n "DocumentArtifactStorage::TYPE_RAMS\\|DocumentArtifactStorage::TYPE_OM" app/Services/PdfService.php` shows both write paths route through the H-07 store.
5. `php artisan view:clear && php -r "require 'vendor/autoload.php'; require 'bootstrap/app.php';"` does not error (Blade compiles).
6. `php artisan tinker --execute="app(\App\Services\PdfRenderService::class); app(\App\Services\PdfService::class); app(\App\Services\SurveyPdfService::class); echo 'all-resolved';"` outputs `all-resolved`.
7. SUMMARY.md contains the production runbook with the six command blocks (chrome symlink, .env append, smoke test, queue-worker systemd edit, chown, functional acceptance).
8. No edits made to: `app/Http/Controllers/RamsController.php`, `app/Http/Controllers/OmManualController.php`, `app/Http/Controllers/SiteSurveyController.php`, `resources/views/pdf/rams.blade.php`, `resources/views/pdf/om-manual.blade.php` (templates and controllers preserved as-is per task brief).
</verification>

<success_criteria>
- spatie/browsershot ^4 in composer.json + lockfile.
- `App\Services\PdfRenderService` exists with public `fromBlade(view, data, ?writeToPath): string` method.
- `app/Services/PdfService.php` no longer references Dompdf; both buildRams/buildOmManual delegate to PdfRenderService and persist via DocumentArtifactStorage.
- `app/Services/SurveyPdfService.php` no longer references Dompdf; the three public methods delegate to PdfRenderService; HTML extracted into three Blade views under `resources/views/pdf/site-survey/`.
- `php artisan pdf:smoke-test` is registered and visible in `php artisan list`.
- `.env.example` documents `CHROME_PATH=/home/stcav/chrome`.
- SUMMARY.md present and contains the 7 production-rollout sections.
- DOCX generation paths untouched (no edits in `app/Services/RamsBuilderService.php`, `app/Services/WordDocumentService.php`, `app/Services/RamsDocumentRendererService.php`, `app/Services/SiteSurveyDocxService.php`, `app/Services/OmManual*` services, etc.).
- Controllers RamsController, OmManualController, SiteSurveyController are NOT modified.
- A single atomic commit lands the work; `git status` clean afterwards.
</success_criteria>

<output>
After completion, create `.planning/quick/260427-qvr-migrate-pdf-rendering-to-browsershot/260427-qvr-SUMMARY.md` (Task 4 produces this file directly with the production runbook content).
</output>
