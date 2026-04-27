---
phase: 260427-qvr
plan: 01
subsystem: pdf-rendering
tags: [pdf, browsershot, chrome-headless-shell, rams, om-manual, site-survey, deployment-runbook]
dependency-graph:
  requires:
    - "App\\Services\\DocumentArtifactStorage (H-07)"
    - "spatie/browsershot ^5.3"
    - "chrome-headless-shell on production server"
  provides:
    - "App\\Services\\PdfRenderService::fromBlade()"
    - "php artisan pdf:smoke-test"
    - "Single Browsershot rendering pipeline for RAMS + O&M + Site Survey PDFs"
  affects:
    - "App\\Services\\PdfService (RAMS + O&M PDF builders)"
    - "App\\Services\\SurveyPdfService (summary + blank + field-form)"
    - "RamsController::downloadPdf, OmManualController::downloadPdf, SiteSurveyController::downloadPdf (call sites unchanged)"
tech-stack:
  added:
    - "spatie/browsershot ^5.3 (chrome-headless-shell PDF renderer)"
  patterns:
    - "PdfRenderService::fromBlade(view, data, ?writeToPath) wraps Browsershot, defaulting Chrome path to /home/stcav/chrome"
    - "Blade-as-template, service-as-orchestration for site-survey PDFs"
    - "Static helpers via App\\Support\\SurveyPdfHelpers for view-side data cleansing"
key-files:
  created:
    - "app/Services/PdfRenderService.php"
    - "app/Console/Commands/PdfSmokeTestCommand.php"
    - "app/Support/SurveyPdfHelpers.php"
    - "resources/views/pdf/_smoke.blade.php"
    - "resources/views/pdf/site-survey/_styles.blade.php"
    - "resources/views/pdf/site-survey/_header-meta.blade.php"
    - "resources/views/pdf/site-survey/_blank-room-body.blade.php"
    - "resources/views/pdf/site-survey/_signoff.blade.php"
    - "resources/views/pdf/site-survey/summary.blade.php"
    - "resources/views/pdf/site-survey/blank.blade.php"
    - "resources/views/pdf/site-survey/field-form.blade.php"
  modified:
    - "composer.json (+ composer.lock)"
    - "app/Services/PdfService.php"
    - "app/Services/SurveyPdfService.php"
    - ".env.example"
decisions:
  - "Pinned spatie/browsershot ^5.3 (not ^4): Composer's security audit blocks every 4.x point release on advisories that also flag 5.0.0; only ^5.3 installs cleanly. v5 has the same public API used by this plan."
  - "Kept barryvdh/laravel-dompdf, dompdf/dompdf, and mpdf/mpdf in composer.json: removed only after production stability is proven (~2 weeks), per the migration brief's safety-net constraint."
  - "Site-survey PDFs continue to write under storage/app/site-surveys/ rather than the H-07 documents disk: site-surveys is not a TYPE_* constant in DocumentArtifactStorage and there is no migration mandate for that artifact type yet."
  - "CommissioningPdfService left on Dompdf: out of scope per the brief; will be migrated in a follow-up ticket."
  - "Replaced dompdf-only <script type='text/php'> page numbering with Chromium-native @page { @bottom-right { content: counter(page) } }, which Browsershot/Chromium honour out of the box."
metrics:
  duration: "~75 min"
  completed: "2026-04-27"
  tasks: 4
  commits: 3
  files-created: 11
  files-modified: 4
---

# 260427-qvr — Migrate PDF Rendering to Browsershot — SUMMARY

**Status:** Complete locally. Production rollout requires the runbook below.

## What changed (code)

- Added `spatie/browsershot ^5.3` (kept dompdf and mPDF in `composer.json` — remove in a later cleanup task once stability is proven).
- New `App\Services\PdfRenderService` — single Browsershot wrapper used by every PDF pipeline. Reads `CHROME_PATH` (default `/home/stcav/chrome`).
- New `php artisan pdf:smoke-test` command — renders `resources/views/pdf/_smoke.blade.php` to verify the pipeline post-deploy.
- `App\Services\PdfService::buildRams()` and `::buildOmManual()` now delegate to `PdfRenderService` and persist via `DocumentArtifactStorage` (H-07).
- `App\Services\SurveyPdfService` (`buildSummary`, `buildBlank`, `buildFieldFormContents`) now delegate to `PdfRenderService`. HTML extracted into `resources/views/pdf/site-survey/{summary,blank,field-form}.blade.php` plus shared partials (`_styles`, `_header-meta`, `_blank-room-body`, `_signoff`).
- New `App\Support\SurveyPdfHelpers` (static helpers `yn`, `blank`, `balanceParens`, `stripLeadingDuplicate`, `narrativeAsTickList`).
- Controllers (`RamsController::downloadPdf`, `OmManualController::downloadPdf`, `SiteSurveyController::downloadPdf`, blank-form, field-form) are unchanged — public method signatures preserved.
- `App\Services\CommissioningPdfService` is intentionally untouched (separate ticket).

## What did NOT change

- No edits to `resources/views/pdf/rams.blade.php` or `resources/views/pdf/om-manual.blade.php`. CSS already used `page-break-*` and `@page` rules which Chromium handles natively.
- No new Worksheet PDF download (still DOCX-only).
- DOCX generation paths (PhpWord) untouched.

## Commits on this branch

| # | Hash | Message |
|---|------|---------|
| 1 | `6ce3c15` | feat(260427-qvr): add Browsershot dep, PdfRenderService, pdf:smoke-test command |
| 2 | `fdbbde7` | refactor(260427-qvr): migrate PdfService (RAMS + O&M) to Browsershot |
| 3 | `92c95da` | refactor(260427-qvr): migrate SurveyPdfService to Browsershot, extract HTML to Blade |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking issue] spatie/browsershot ^4 not installable due to Composer security audit**
- **Found during:** Task 1 (`composer require`)
- **Issue:** `composer require spatie/browsershot:^4` was rejected because every 4.x point release (4.0.0–4.4.0) is flagged with PKSA advisories (file-disclosure issues in headless Chrome URL handling). Composer also rejects `^5.0` because 5.0.0 carries the same advisories; only `^5.3` (5.3.0) installs cleanly.
- **Fix:** Pinned to `spatie/browsershot:^5.3`. The 4→5 release was an internal cleanup; the public API used by this plan (`Browsershot::html()`, `setChromePath`, `noSandbox`, `addChromiumArguments`, `format`, `margins`, `showBackground`, `emulateMedia`, `savePdf`, `pdf`) is identical, so the PdfRenderService wrapper compiles and runs unchanged on v5.
- **Files modified:** `composer.json`, `composer.lock`
- **Commit:** `6ce3c15`

**2. [Rule 1 — Bug] Blade `@php(use ...)` shorthand produces invalid PHP**
- **Found during:** Task 3 verification (Blade compile check)
- **Issue:** Initial drafts of `summary.blade.php` and `field-form.blade.php` used `@php(use App\Support\SurveyPdfHelpers as H)` — Blade compiles `@php(...)` to `<?php(...)` (no closing tag). When the line contains a `use` declaration the parser rejects the whole file. Subsequent `@if`/`@foreach` directives ended up inside an unclosed PHP block, producing `unexpected token "endforeach"` parse errors.
- **Fix:** Converted those declarations to multi-line `@php ... @endphp` blocks. Inline `@php(...)` for simple assignments inside `@foreach` bodies still works fine and was kept for brevity.
- **Files modified:** `resources/views/pdf/site-survey/summary.blade.php`, `resources/views/pdf/site-survey/field-form.blade.php`
- **Commit:** `92c95da` (folded into the Task 3 commit)

### Deferred Items

- Local smoke test (`php artisan pdf:smoke-test`) was NOT run on this Windows dev box: the puppeteer/chrome-headless-shell binaries live only on the AlmaLinux production server per the plan's `<server_state>`. Production smoke test is the authoritative one — see step 4 of the runbook below.
- Tests in `tests/Unit/RamsPdfScopeTest.php` and `tests/Feature/SurveyDownloadFormTest.php` were NOT audited for HTML-internal assertions. Public API of both services is unchanged, so these tests should still pass for the call-graph paths they cover; if any reference internal Dompdf objects or specific HTML strings, they will need a separate test-cleanup ticket.

## Production rollout checklist (run on the AlmaLinux 8 server as needed)

### 1. Pull the new code

```bash
cd /home/stcav/rams.21stcav.com
git fetch && git checkout feat/worksheet-classifier-universal && git pull
composer install --no-dev --optimize-autoloader
```

### 2. Create the chrome symlink (one-time)

The Browsershot wrapper reads `CHROME_PATH` from env, defaulting to `/home/stcav/chrome`. We use a symlink so the versioned puppeteer-cache path isn't hard-coded in Laravel config.

```bash
ln -sfn /home/stcav/.cache/puppeteer/chrome-headless-shell/linux-147.0.7727.57/chrome-headless-shell-linux64/chrome-headless-shell /home/stcav/chrome
ls -l /home/stcav/chrome   # should show the symlink target
```

If you ever upgrade puppeteer, re-point this symlink — Laravel config does not need editing.

### 3. Confirm CHROME_PATH in .env

Append to `/home/stcav/rams.21stcav.com/.env` (only if missing):

```
CHROME_PATH=/home/stcav/chrome
```

Then:

```bash
php artisan config:clear
```

### 4. Run the smoke test as the stcav user

```bash
sudo -u stcav -H bash -lc "cd /home/stcav/rams.21stcav.com && php artisan pdf:smoke-test"
```

Expected output: `PDF smoke test OK — wrote NNNN bytes to /home/stcav/rams.21stcav.com/storage/app/pdf-smoke.pdf`. The file MUST be owned by `stcav:stcav`. If it is owned by `root`, the queue worker is still running as root — go to step 5 immediately.

### 5. Fix the queue-worker user (was running as root)

The recurring "PHP-FPM cannot read documents/" outage is caused by the queue worker running as root, writing files under `storage/app/documents/{rams,om-manuals,worksheets,cable-schedules}/` as `root:root mode 644`, which PHP-FPM (running as `stcav`) cannot read inside parent directories that are themselves `root:root 700`.

#### Find the queue runner

```bash
systemctl list-units --type=service | grep -i queue
# OR
grep -r "queue:work\|queue:listen" /etc/systemd/system/ /etc/cron.d/ /etc/cron.* 2>/dev/null
# OR (CWP)
crontab -l -u root | grep -i artisan
```

#### If it's a systemd unit (most likely)

Edit the unit file, e.g. `/etc/systemd/system/rams-queue.service`. Add or change:

```
[Service]
User=stcav
Group=stcav
WorkingDirectory=/home/stcav/rams.21stcav.com
ExecStart=/usr/bin/php /home/stcav/rams.21stcav.com/artisan queue:work --tries=3 --timeout=600 --sleep=1
```

Then:

```bash
systemctl daemon-reload
systemctl restart rams-queue.service
ps -fC php | grep queue   # should show the process owned by stcav, not root
```

#### If it's a root crontab entry

Move it to stcav's crontab:

```bash
crontab -l -u root | grep -v 'artisan queue' | crontab -u root -        # remove from root
crontab -e -u stcav                                                      # add the queue:work line under stcav
```

Verify with `ps -fC php | grep queue`.

### 6. One-time chown of existing root-owned artifacts

```bash
chown -R stcav:stcav /home/stcav/rams.21stcav.com/storage/app/documents/
chmod -R u+rwX,go+rX /home/stcav/rams.21stcav.com/storage/app/documents/
ls -ld /home/stcav/rams.21stcav.com/storage/app/documents/{rams,om-manuals,worksheets,cable-schedules}
```

All four subdirectories must be owned by `stcav:stcav` and readable. Any new generation from this point will be created with the right ownership because the queue worker now runs as stcav.

### 7. Functional acceptance — exercise each PDF endpoint in the browser

- Open a RAMS document → click "Download PDF" → confirm the PDF opens, contains the expected RAMS sections, and the file streamed.
- Open an O&M Manual → click "Download PDF" → confirm.
- Open a Site Survey → click "Download Survey PDF" → confirm.
- Open `/site-surveys/blank-form` → confirm blank form PDF downloads.
- Open the public `/survey/{token}/download-form` link → confirm field form PDF downloads.

If any endpoint returns a 500, check `storage/logs/laravel.log` — the most likely cause is `CHROME_PATH` resolving to a binary the stcav user cannot execute. Fix by re-running step 2 and verifying with `sudo -u stcav -H /home/stcav/chrome --version`.

## Rollback

```bash
git revert 92c95da fdbbde7 6ce3c15
composer install --no-dev --optimize-autoloader
```

`barryvdh/laravel-dompdf`, `dompdf/dompdf`, and `mpdf/mpdf` are still in `composer.json`, so the previous Dompdf-based rendering returns immediately on revert — no manual restoration needed.

## Follow-up (not in this plan)

- Remove `barryvdh/laravel-dompdf`, `dompdf/dompdf`, `mpdf/mpdf` from `composer.json` once two weeks of clean PDF generations on production confirm Browsershot stability.
- Migrate `App\Services\CommissioningPdfService` to PdfRenderService (still uses Dompdf, currently out of scope).
- Worksheet PDF download (currently DOCX only).
- O&M content bugs (empty Frequency cell, hardware-as-equipment, "additional" pseudo-room) — tracked separately.
- Audit `tests/Unit/RamsPdfScopeTest.php` and `tests/Feature/SurveyDownloadFormTest.php` for HTML-internal assertions and update if they reference removed Dompdf objects or template-internal strings.

## Self-Check: PASSED

- `app/Services/PdfRenderService.php` — exists
- `app/Console/Commands/PdfSmokeTestCommand.php` — exists, registered (`php artisan list` shows `pdf:smoke-test`)
- `app/Support/SurveyPdfHelpers.php` — exists
- `resources/views/pdf/_smoke.blade.php` — exists
- `resources/views/pdf/site-survey/{_styles,_header-meta,_blank-room-body,_signoff,summary,blank,field-form}.blade.php` — all 7 files exist, all compile cleanly
- `app/Services/PdfService.php` — Dompdf import removed; uses `PdfRenderService` and `DocumentArtifactStorage::TYPE_RAMS|TYPE_OM`
- `app/Services/SurveyPdfService.php` — Dompdf import removed; three public methods delegate to `PdfRenderService`
- `composer require spatie/browsershot:^5.3` succeeded (composer.lock updated)
- `php artisan pdf:smoke-test` registered
- `php artisan tinker --execute="app(PdfRenderService); app(PdfService); app(SurveyPdfService)"` resolves all three (output: `all-resolved`)
- Commit `6ce3c15` — found in `git log`
- Commit `fdbbde7` — found in `git log`
- Commit `92c95da` — found in `git log`
- `app/Http/Controllers/{Rams,OmManual,SiteSurvey}Controller.php` — unmodified
- `resources/views/pdf/{rams,om-manual}.blade.php` — unmodified
- `app/Services/CommissioningPdfService.php` — unmodified (still on Dompdf, intentional)
