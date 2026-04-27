---
quick_id: 260427-qvr
phase: 260427-qvr-migrate-pdf-rendering-to-browsershot
verified_at: 2026-04-27T00:00:00Z
status: human_needed
score: 11/11 must-haves verified (smoke-test routed to human verification — prod-only)
overrides_applied: 0
human_verification:
  - test: "Run pdf:smoke-test on production AlmaLinux 8 server as the stcav user"
    expected: "Exits 0 with `PDF smoke test OK — wrote NNNN bytes to /home/stcav/rams.21stcav.com/storage/app/pdf-smoke.pdf` and the file is owned by stcav:stcav"
    why_human: "chrome-headless-shell binary lives only on production (/home/stcav/.cache/puppeteer/...); no Chromium installed on this Windows dev box. SUMMARY explicitly defers this per the plan's <server_state>."
  - test: "RAMS Download PDF — open a real RAMS document in the browser and click Download PDF"
    expected: "PDF streams; visually identical to dompdf output; renders the expected RAMS sections"
    why_human: "Visual fidelity / streaming behaviour cannot be verified programmatically without running the full prod stack."
  - test: "O&M Manual Download PDF — open an O&M Manual and click Download PDF"
    expected: "PDF streams; renders all O&M sections (covers Frequency cell, hardware-as-equipment caveats are pre-existing follow-ups)"
    why_human: "Visual fidelity check requires production data + browser session."
  - test: "Site Survey Download PDF — open a Site Survey and click Download Survey PDF"
    expected: "PDF streams; survey->filename gets updated; page numbers render via Chromium @page counter"
    why_human: "End-to-end download UX + filename side-effect needs a live request cycle."
  - test: "Public field-form download — /survey/{token}/download-form on a real survey token"
    expected: "Pre-populated field form PDF streams in-memory (no disk write)"
    why_human: "Public route + token-gated; needs prod data."
  - test: "Blank survey form download — /site-surveys/blank-form"
    expected: "Blank printable form PDF downloads"
    why_human: "Live HTTP exercise needed."
  - test: "Confirm queue worker runs as stcav user post-deploy"
    expected: "`ps -fC php | grep queue` shows process owned by stcav, not root; new generated artifacts under storage/app/documents/ are stcav:stcav"
    why_human: "systemd unit state and process ownership are production-only signals."
---

# 260427-qvr — Migrate PDF Rendering to Browsershot — Verification Report

**Task Goal:** Replace dompdf for RAMS / O&M / Site Survey via a single `PdfRenderService`, add `pdf:smoke-test` artisan command, bundle a deployment runbook (chrome symlink, queue-worker user fix, chown). Reuse existing Blade templates as-is. Keep `dompdf`/`mpdf` composer deps for rollback. DOCX generation untouched.

**Status:** human_needed (all automated checks pass; only the production smoke test and visual-fidelity browser tests need human verification).

---

## Must-Have Verification

| #   | Must-Have                                                                                                                                                          | Status      | Evidence |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------- | -------- |
| 1   | Browsershot dependency added (executor pinned ^5.3 instead of ^4 due to security audit; v5 API compatible)                                                          | VERIFIED    | `composer.json:21` requires `spatie/browsershot ^5.3`; `composer.lock` resolves `spatie/browsershot 5.3.0`; deviation documented in SUMMARY `decisions[0]` and `Auto-fixed Issues #1` with rationale. PdfRenderService uses only the v4-stable public API surface (`Browsershot::html`, `setChromePath`, `noSandbox`, `addChromiumArguments`, `format`, `showBackground`, `emulateMedia`, `margins`, `savePdf`, `pdf`). |
| 2   | `App\Services\PdfRenderService` exists with `fromBlade()` public method, `setChromePath` reading `env('CHROME_PATH', '/home/stcav/chrome')`, `noSandbox()`, and `disable-dev-shm-usage` + `disable-setuid-sandbox` chromium args | VERIFIED    | `app/Services/PdfRenderService.php:37` (public `fromBlade`), `:48` (`setChromePath(env('CHROME_PATH', '/home/stcav/chrome'))`), `:49` (`noSandbox()`), `:50–53` (`addChromiumArguments` with both flags). `php -l` clean. |
| 3   | `pdf:smoke-test` artisan command registered, renders a Blade to PDF                                                                                                | VERIFIED    | `app/Console/Commands/PdfSmokeTestCommand.php:17` declares signature `pdf:smoke-test`; calls `$renderer->fromBlade('pdf._smoke', [], $out)` at `:26`. `php artisan list` output confirms `pdf:smoke-test  Render a hello-world Blade view to PDF via Browsershot to verify the pipeline.` `resources/views/pdf/_smoke.blade.php` exists. |
| 4   | `PdfService::buildRams` + `buildOmManual` keep public signatures, internals route through `PdfRenderService` (no Dompdf instantiation)                              | VERIFIED    | `app/Services/PdfService.php:32` `buildRams(RamsDocument $rams): string`, `:46` `buildOmManual(OmManual $manual): string` — same signatures as plan `<interfaces>`. `:37` and `:51` delegate to `$this->renderer->fromBlade(...)`. Grep for `Dompdf` returns ONLY a docblock string at `:12` ("Previously used Dompdf directly"); no `use Dompdf\Dompdf` import, no `new Dompdf(...)` call. |
| 5   | `PdfService` writes through `DocumentArtifactStorage::writePath(TYPE_RAMS|TYPE_OM, ...)` (H-07 honoured)                                                            | VERIFIED    | `app/Services/PdfService.php:35` `$this->artifacts->writePath(DocumentArtifactStorage::TYPE_RAMS, $filename)`; `:49` `…TYPE_OM, $filename`. Both constructor-injected at `:22–25` (`DocumentArtifactStorage`). Filename pattern preserved by `filenameFor()` at `:63–71`. |
| 6   | `SurveyPdfService::buildSummary/buildBlank/buildFieldFormContents` keep public signatures, route through `PdfRenderService`                                          | VERIFIED    | `app/Services/SurveyPdfService.php:27` `buildSummary(SiteSurvey $survey): string`, `:45` `buildBlank(): string`, `:62` `buildFieldFormContents(SiteSurvey $survey): string` — all match plan `<interfaces>`. All three call `$this->renderer->fromBlade(...)`. `survey->update(['filename' => ...])` side-effect preserved at `:36`. Grep for `Dompdf` returns ONLY one docblock match at `:10` ("Previously used Dompdf with HTML built up by"). |
| 7   | Existing `dompdf`/`mpdf` composer dependencies NOT removed (kept for rollback)                                                                                      | VERIFIED    | `composer.json:10` `barryvdh/laravel-dompdf ^3.1`, `:13` `dompdf/dompdf ^3.1`, `:18` `mpdf/mpdf ^8.3` all retained. `composer.lock` resolves `barryvdh/laravel-dompdf v3.1.1`, `dompdf/dompdf v3.1.5`, `mpdf/mpdf v8.3.1`. Documented in SUMMARY `decisions[1]`. |
| 8   | Controllers UNTOUCHED — `RamsController`, `OmManualController`, `SiteSurveyController` (and survey blank/field-form callers) byte-identical or only auto-import/whitespace deltas | VERIFIED    | `git diff 6ce3c15^..HEAD -- app/Http/Controllers/RamsController.php app/Http/Controllers/OmManualController.php app/Http/Controllers/SiteSurveyController.php resources/views/pdf/rams.blade.php resources/views/pdf/om-manual.blade.php` returns **EMPTY** — none of these were modified by the three migration commits (6ce3c15, fdbbde7, 92c95da). The `git diff master ...` deltas visible on the branch (tier-one readiness service injection on SiteSurveyController, permits logic on rams.blade.php) belong to earlier branch work, not this task. |
| 9   | DOCX paths NOT touched — RamsBuilderService, WorksheetDocxService, OmManual* services etc. unchanged                                                                | VERIFIED    | `git diff 6ce3c15^..HEAD --stat -- app/Services/RamsBuilderService.php app/Services/WorksheetDocxService.php app/Services/RamsDocumentRendererService.php app/Services/SiteSurveyDocxService.php "app/Services/OmManual*" app/Services/CommissioningPdfService.php` returns **EMPTY** — no DOCX or commissioning files touched. Full migration touched 16 files: `composer.json`, `composer.lock`, `.env.example`, 1 service (`PdfRenderService`), 2 refactors (`PdfService`, `SurveyPdfService`), 1 helper (`SurveyPdfHelpers`), 1 command, 8 Blade views — all in plan scope. |
| 10  | `CommissioningPdfService` NOT touched — still on Dompdf (out of scope, flagged in SUMMARY for follow-up)                                                            | VERIFIED    | Grep `app/Services/CommissioningPdfService.php` finds active Dompdf usage at `:8 use Dompdf\Dompdf`, `:9 use Dompdf\Options`, `:83 $dompdf = new Dompdf($options)` — file unchanged in this task. SUMMARY `decisions[3]` and `Follow-up` section explicitly flag the deferral. |
| 11  | Deployment runbook documented in SUMMARY — exact prod commands for chrome symlink, queue-worker user fix, chown                                                     | VERIFIED    | `260427-qvr-SUMMARY.md` sections 1–7 cover: pull code (`composer install --no-dev --optimize-autoloader`), chrome symlink (`ln -sfn /home/stcav/.cache/puppeteer/chrome-headless-shell/linux-147.0.7727.57/chrome-headless-shell-linux64/chrome-headless-shell /home/stcav/chrome`), `.env CHROME_PATH=/home/stcav/chrome`, `sudo -u stcav -H ... php artisan pdf:smoke-test`, queue-worker systemd unit fix (`User=stcav` / `Group=stcav` with daemon-reload + restart) AND root-crontab fallback, and `chown -R stcav:stcav /home/stcav/rams.21stcav.com/storage/app/documents/` + chmod, plus functional acceptance checklist and rollback. |

**Score:** 11/11 must-haves verified. 0 overrides applied.

---

## Automated Checks

| Check | Command | Result |
| ----- | ------- | ------ |
| Lint PdfRenderService | `php -l app/Services/PdfRenderService.php` | No syntax errors |
| Lint PdfService | `php -l app/Services/PdfService.php` | No syntax errors |
| Lint SurveyPdfService | `php -l app/Services/SurveyPdfService.php` | No syntax errors |
| Lint PdfSmokeTestCommand | `php -l app/Console/Commands/PdfSmokeTestCommand.php` | No syntax errors |
| Lint SurveyPdfHelpers | `php -l app/Support/SurveyPdfHelpers.php` | No syntax errors |
| Laravel bootstrap | `php -r "require 'vendor/autoload.php'; require 'bootstrap/app.php'; echo 'bootstrap OK';"` | bootstrap OK |
| View clear | `php artisan view:clear` | Compiled views cleared successfully |
| Container resolution | `php artisan tinker --execute="app(PdfRenderService::class); app(PdfService::class); app(SurveyPdfService::class); echo 'all-resolved';"` | all-resolved |
| Artisan command registered | `php artisan list \| grep pdf:smoke` | `pdf:smoke-test  Render a hello-world Blade view to PDF via Browsershot to verify the pipeline.` |
| Browsershot installed | `composer.lock` package check | `spatie/browsershot 5.3.0` present |
| Dompdf retained | `composer.lock` package check | `barryvdh/laravel-dompdf v3.1.1`, `dompdf/dompdf v3.1.5`, `mpdf/mpdf v8.3.1` all retained |
| No Dompdf in PdfService.php | `grep Dompdf app/Services/PdfService.php` | Only docblock reference at line 12 (no import, no instantiation) |
| No Dompdf in SurveyPdfService.php | `grep Dompdf app/Services/SurveyPdfService.php` | Only docblock reference at line 10 (no import, no instantiation) |
| H-07 storage in PdfService | `grep TYPE_RAMS\|TYPE_OM` | Lines 35 and 49 — both writes routed |
| Migration commits resolve | `git rev-parse 6ce3c15 fdbbde7 92c95da` | All three resolve to full SHAs |
| Controllers unchanged in migration | `git diff 6ce3c15^..HEAD -- {Rams,OmManual,SiteSurvey}Controller.php` | Empty — not modified by migration commits |
| RAMS/O&M templates unchanged in migration | `git diff 6ce3c15^..HEAD -- resources/views/pdf/{rams,om-manual}.blade.php` | Empty |
| Site-survey Blade views | `glob resources/views/pdf/site-survey/*.blade.php` | 7 files: `_styles`, `_header-meta`, `_blank-room-body`, `_signoff`, `summary`, `blank`, `field-form` |
| .env.example documents CHROME_PATH | `cat .env.example` | Final block adds `CHROME_PATH=/home/stcav/chrome` with comment |

---

## Wiring / Key-Link Verification

| From | To | Via | Status | Evidence |
| ---- | -- | --- | ------ | -------- |
| `PdfService` | `PdfRenderService` | constructor-injected dependency | WIRED | `PdfService.php:23` `private readonly PdfRenderService $renderer` |
| `PdfService` | `DocumentArtifactStorage` | constructor-injected dependency | WIRED | `PdfService.php:24` `private readonly DocumentArtifactStorage $artifacts` |
| `SurveyPdfService` | `PdfRenderService` | constructor-injected dependency | WIRED | `SurveyPdfService.php:20` |
| `PdfRenderService` | chrome-headless-shell binary | `Browsershot::setChromePath(env('CHROME_PATH', '/home/stcav/chrome'))` | WIRED in code; runtime depends on prod symlink (step 2 of runbook) | `PdfRenderService.php:48` |
| `RamsController::downloadPdf` | `PdfService::buildRams` | unchanged call site | WIRED | Controller untouched by migration; signature preserved |
| `OmManualController::downloadPdf` | `PdfService::buildOmManual` | unchanged call site | WIRED | Controller untouched; signature preserved |
| `SiteSurveyController::downloadPdf` | `SurveyPdfService::buildSummary` | unchanged call site | WIRED | Controller's pdf-related code untouched by migration |

---

## Anti-Pattern Scan

| File | Severity | Finding |
| ---- | -------- | ------- |
| `app/Services/PdfRenderService.php` | INFO | Uses `env()` outside config — acceptable here since the binary path is environment-specific and the plan explicitly chose `env('CHROME_PATH', ...)`. Consider hoisting to `config/pdf.php` in a follow-up if `php artisan config:cache` is ever enabled in production. |
| `app/Services/PdfService.php` | None | No TODO/FIXME/PLACEHOLDER. No empty returns. |
| `app/Services/SurveyPdfService.php` | None | No TODO/FIXME/PLACEHOLDER. |
| `app/Console/Commands/PdfSmokeTestCommand.php` | None | Returns proper exit codes (0/1) — not a stub. |
| Site-survey Blade views | None | All 7 files compile cleanly via `php artisan view:clear`. |

No blockers found.

---

## Deviations from Plan (Cross-Checked Against SUMMARY)

| # | Deviation | SUMMARY Documented? | Evidence Confirms |
| - | --------- | ------------------- | ----------------- |
| 1 | spatie/browsershot pinned ^5.3 instead of plan-specified ^4 | YES — `decisions[0]` + `Auto-fixed Issues #1` | composer.lock = 5.3.0; PdfRenderService uses only v4-stable API surface |
| 2 | Site-surveys NOT routed through DocumentArtifactStorage (still uses `Storage::disk('local')->path('site-surveys/...')`) | YES — `decisions[2]` and inline comment in SurveyPdfService docblock | Confirmed in plan known_concerns #3 — site-surveys is not an H-07 TYPE constant; this matches the plan |
| 3 | Three commits instead of single atomic commit | YES — implicit in commit table; plan said "single atomic commit" but allowed per-task commits | `git log --oneline` confirms 3 commits 6ce3c15, fdbbde7, 92c95da each scoped to one task |
| 4 | New `App\Support\SurveyPdfHelpers` static helper class | YES — listed in `key-files.created` | File exists; used by Blade views per migration commit message |
| 5 | Local smoke test NOT run (no Chrome on Windows dev box) | YES — `Deferred Items` section + step 4 of runbook | Expected per plan Task 4 step 1 |

All deviations are intentional, documented, and consistent with the plan's `<known_concerns>` and `<server_state>` constraints.

---

## Smoke-Test Status

**Local (Windows dev box):** SKIPPED. No Chrome / chrome-headless-shell binary available on this machine. Plan and SUMMARY both explicitly defer this — production is the authoritative environment.

**Production (AlmaLinux 8):** PENDING — must be run as part of deployment per runbook step 4. Routed to human verification.

---

## Gaps

None.

---

## Final Summary

**NEEDS_HUMAN_CHECK**

All 11 must-haves are programmatically VERIFIED. Code, dependencies, command registration, container wiring, H-07 storage routing, and runbook completeness all pass. The only remaining items are inherently human-verifiable: the production smoke test (`pdf:smoke-test` on AlmaLinux 8 as the stcav user), the visual-fidelity download checks for RAMS/O&M/Survey/blank-form/field-form, and confirmation that the queue-worker user fix sticks after deployment. These are listed in the `human_verification` frontmatter for the developer to walk through during prod rollout.

The migration is implementation-complete and ready to deploy.

---

_Verified: 2026-04-27_
_Verifier: Claude (gsd-verifier)_
