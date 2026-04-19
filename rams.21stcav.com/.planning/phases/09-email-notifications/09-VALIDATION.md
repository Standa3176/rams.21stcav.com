---
phase: 09
slug: email-notifications
status: planned
nyquist_compliant: true
wave_0_complete: false
created: 2026-04-19
populated: 2026-04-19
revised: 2026-04-19
---

# Phase 09 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit ^11.5.3 |
| **Config file** | `phpunit.xml` (`MAIL_MAILER=array` for `Mail::fake()` assertions) |
| **Quick run command** | `vendor/bin/phpunit --testsuite=Feature --filter=Notification` |
| **Full suite command** | `vendor/bin/phpunit` |
| **Estimated runtime** | ~30 seconds (existing suite baseline) |

---

## Sampling Rate

- **After every task commit:** Run `vendor/bin/phpunit --testsuite=Feature --filter=<NewTestClass>` (the test for the just-completed task)
- **After every plan wave:** Run `vendor/bin/phpunit --testsuite=Feature` (all feature tests — catches cross-wave regressions in `PublicSurveyControllerTest` etc.)
- **Before `/gsd-verify-work`:** `vendor/bin/phpunit` (full suite green) + `MAIL_MAILER=log php artisan tinker` smoke check (manually trigger one mailable, tail `storage/logs/laravel.log`)
- **Max feedback latency:** 30 seconds (full suite)

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 09-01-T1 | 09-01 | 1 | NOTF-01c, NOTF-03c, NOTF-04b, NOTF-04c | T-09-01, T-09-05 | Adds idempotency columns (8 + review_needed) + cable error_message | migration smoke | `php artisan migrate --pretend \| grep -q completion_email_sent_at` | `database/migrations/2026_04_19_*_add_email_sent_columns_for_phase_09.php` | ⬜ pending |
| 09-01-T2 | 09-01 | 1 | NOTF-05d | T-09-04 | Adds notifications.bcc config + .env.example placeholders | config presence | `php artisan tinker --execute="echo array_key_exists('notifications', config('rams'));"` returns truthy | `config/rams.php`, `.env.example` | ⬜ pending |
| 09-01-T3 | 09-01 | 1 | NOTF-01c, NOTF-01d, NOTF-03c, NOTF-04b, NOTF-04c | T-09-05 | Model `$fillable` + `$casts` for the 9 new email-timestamp columns + CableSchedule.error_message + **HasFactory trait additions on RamsDocument & CableSchedule** (moved here from 09-02b per checker B-01 — avoids Wave 1 file collision; without fillable wiring, mass-assignment silently drops the field and idempotency design fails — BLOCKER per checker I-01) | model smoke (chain) | `php artisan tinker --execute="echo (new App\Models\RamsDocument)->isFillable(\'completion_email_sent_at\') && (new App\Models\RamsDocument)->isFillable(\'review_needed_email_sent_at\') && (new App\Models\OmManual)->isFillable(\'completion_email_sent_at\') && (new App\Models\Worksheet)->isFillable(\'completion_email_sent_at\') && (new App\Models\CableSchedule)->isFillable(\'completion_email_sent_at\') && (new App\Models\CableSchedule)->isFillable(\'error_message\') ? \'ALL_OK\' : \'MISSING\';"` returns ALL_OK; AND `grep -q "use HasFactory" app/Models/RamsDocument.php` AND `grep -q "use HasFactory" app/Models/CableSchedule.php` both exit 0 | `app/Models/RamsDocument.php` (+HasFactory), `app/Models/OmManual.php`, `app/Models/Worksheet.php`, `app/Models/CableSchedule.php` (+HasFactory) | ⬜ pending |
| 09-02-T1 | 09-02 | 1 | NOTF-05g | Supply chain (accept) | Installs symfony/postmark-mailer + http-client | composer | `composer show symfony/postmark-mailer` exits 0 | `composer.lock` | ⬜ pending |
| 09-02-T2 | 09-02 | 1 | NOTF-05a, NOTF-05b | T-09-02, T-09-06 | Centralised recipient resolver (locks `role='admin'` + `Project::owner`) | unit | `vendor/bin/phpunit tests/Unit/Services/NotificationRecipientResolverTest.php` | `app/Services/NotificationRecipientResolver.php`, `tests/Unit/Services/NotificationRecipientResolverTest.php` | ⬜ pending |
| 09-02b-T1 | 09-02b | 2 | NOTF-01a, NOTF-01b, NOTF-02a, NOTF-04a | n/a (test infra) | 4 model factories ONLY (per checker B-01: model-file edits including HasFactory trait additions moved to 09-01 Task 3 to avoid Wave 1 file collision with 09-01 on RamsDocument.php + CableSchedule.php; 09-02b is now Wave 2 with `depends_on: ["09-01"]`) | factory smoke | `php artisan tinker --execute="echo App\Models\RamsDocument::factory()->make()->project_ref ? \'A\' : \'\'; echo App\Models\OmManual::factory()->make()->project_ref ? \'B\' : \'\'; echo App\Models\Worksheet::factory()->make()->project_ref ? \'C\' : \'\'; echo App\Models\CableSchedule::factory()->make()->source_filename ? \'D\' : \'\';" \| grep -q ABCD` | `database/factories/{Rams,Om,Worksheet,Cable}*Factory.php` | ⬜ pending |
| 09-03-T1 | 09-03 | 2 | NOTF-01a, NOTF-01b, NOTF-01f, NOTF-05e, NOTF-05f | T-09-02, T-09-05 | 4 typed *ReadyMail classes (ShouldQueue + DocumentArtifactStorage attachments) | autoload smoke | `php -r "class_exists('App\\Mail\\RamsReadyMail') ?: exit(1);"` | `app/Mail/{Rams,OmManual,Worksheet,CableSchedule}ReadyMail.php` | ⬜ pending |
| 09-03-T2 | 09-03 | 2 | NOTF-01a, NOTF-01b, NOTF-01f | T-09-01 | 4 Blade templates rendering project ref / name / dashboard link AND mirroring the canonical outer wrapper from rams-document.blade.php (per checker I-07) | view render + wrapper grep | `for f in rams-ready om-manual-ready worksheet-ready cable-schedule-ready; do grep -q '<!DOCTYPE html>' "resources/views/emails/$f.blade.php" \|\| exit 1; done` | `resources/views/emails/{rams,om-manual,worksheet,cable-schedule}-ready.blade.php` | ⬜ pending |
| 09-04-T1 | 09-04 | 2 | NOTF-03, NOTF-03a, NOTF-03b, NOTF-05e, NOTF-05f | T-09-02 | RamsReviewNeededMail + Blade with route('rams.review') link AND canonical wrapper (per checker I-07) | autoload smoke + grep | `grep -q "route('rams.review'" resources/views/emails/rams-review-needed.blade.php && grep -q '<!DOCTYPE html>' resources/views/emails/rams-review-needed.blade.php` | `app/Mail/RamsReviewNeededMail.php`, `resources/views/emails/rams-review-needed.blade.php` | ⬜ pending |
| 09-04-T2 | 09-04 | 2 | NOTF-04, NOTF-04a, NOTF-04c, NOTF-05e, NOTF-05f | T-09-01, T-09-02, T-09-04 | DocumentGenerationFailedMail polymorphic via primitives + Blade with canonical wrapper (per checker I-07) | autoload smoke + grep | `grep -q "string  \\$documentType" app/Mail/DocumentGenerationFailedMail.php && grep -q '<!DOCTYPE html>' resources/views/emails/document-generation-failed.blade.php` | `app/Mail/DocumentGenerationFailedMail.php`, `resources/views/emails/document-generation-failed.blade.php` | ⬜ pending |
| 09-04-T3 | 09-04 | 2 | NOTF-02, NOTF-02a, NOTF-05a | T-09-02, T-09-07 | Refactor SurveyService to use resolver (NOTF-02a) + fix latent `is_admin` accessor bug in SiteSurveyController::authorizeSurvey() (per checker B-02 — pre-existing reference at SiteSurveyController.php:449 would otherwise cause the codebase-wide grep to fail forever); codebase-wide negative greps now pass cleanly | feature regression + codebase grep | `vendor/bin/phpunit tests/Feature/PublicSurveyControllerTest.php && ! grep -rE "is_admin" app/ --include=\'*.php\' && ! grep -rE "Project::with\(\'user\'\)" app/ --include=\'*.php\' && grep -q "isAdmin()" app/Http/Controllers/SiteSurveyController.php && grep -q "function isAdmin" app/Models/User.php` | `app/Core/Modules/Survey/SurveyService.php`, `app/Http/Controllers/SiteSurveyController.php` | ⬜ pending |
| 09-05-T1 | 09-05 | 3 | NOTF-01a, NOTF-01b, NOTF-01d, NOTF-01e, NOTF-03a, NOTF-03c, NOTF-04a, NOTF-04b, NOTF-04c, NOTF-05d, NOTF-05h | T-09-02, T-09-03, T-09-05 | Wire all 5 jobs with idempotent send (incl. NOTF-03c review-needed) + try/catch + BCC at call site; verify command is now binary `php -l` lint (per checker I-03 — removes false-positive grep noise); anchored status-greps (per checker I-05) confirm STATUS_* references are inside the email-dispatch block, not random unrelated mentions | parse (deterministic) | `for f in app/Jobs/BuildRamsDocumentJob.php app/Jobs/BuildOmManualJob.php app/Jobs/BuildWorksheetJob.php app/Jobs/BuildCableScheduleJob.php app/Jobs/ExtractRamsDraftJob.php; do php -l "$f" \|\| exit 1; done` | `app/Jobs/Build{Rams,OmManual,Worksheet,CableSchedule}Job.php`, `app/Jobs/ExtractRamsDraftJob.php` | ⬜ pending |
| 09-05-T2 | 09-05 | 3 | NOTF-01a, NOTF-01b, NOTF-01f, NOTF-02, NOTF-02a, NOTF-03a, NOTF-03b, NOTF-03c, NOTF-04a, NOTF-04c | T-09-02, T-09-05 | 7 trigger feature tests + extend PublicSurveyControllerTest; review-needed test covers NOTF-03c idempotency lock; OCR-test guidance (per checker I-08) — prefer extracting `dispatchReviewNeededEmail()` helper rather than running real PDF/OCR pipeline in CI | feature (incl. full feature suite green — moved here from T1 per I-03) | `vendor/bin/phpunit --testsuite=Feature --filter=Notifications && vendor/bin/phpunit --testsuite=Feature` | `tests/Feature/Notifications/{Rams,OmManual,Worksheet,CableSchedule}CompletionNotificationTest.php`, `tests/Feature/Notifications/RamsReviewNeededNotificationTest.php`, `tests/Feature/Notifications/DocumentGenerationFailedNotificationTest.php`, `tests/Feature/PublicSurveyControllerTest.php` | ⬜ pending |
| 09-05-T3 | 09-05 | 3 | NOTF-01d, NOTF-03c, NOTF-04b, NOTF-05d | T-09-03, T-09-05 | Idempotency (incl. NOTF-03c review-needed) + BCC behavior locked under retry / config | feature | `vendor/bin/phpunit --testsuite=Feature --filter='Idempotency\|Bcc'` | `tests/Feature/Notifications/IdempotencyTest.php`, `tests/Feature/Notifications/BccTest.php` | ⬜ pending |
| 09-06-T1 | 09-06 | 4 | NOTF-05g | T-09-04, T-09-01 (DNS spoofing) | Production cutover runbook (DNS + Postmark + .env) | doc presence | `grep -q "POSTMARK_API_KEY" .planning/phases/09-email-notifications/POSTMARK-OPS-CHECKLIST.md` | `.planning/phases/09-email-notifications/POSTMARK-OPS-CHECKLIST.md` | ⬜ pending |
| 09-06-T2 | 09-06 | 4 | NOTF-05g | T-09-04 | User decides: cutover-complete / -deferred / -blocked / -noop | checkpoint:human-verify | n/a (gated on user signal) | n/a | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Unit/Services/NotificationRecipientResolverTest.php` — unit tests for `resolveProjectRecipient()` (owner present → owner; owner null → first admin; no admin → null) — created in plan 09-02 Task 2
- [ ] `database/factories/RamsDocumentFactory.php` — minimal factory for RamsDocument feature tests; added by plan 09-02b Task 1 (Wave 2 per B-01 — requires HasFactory trait added to RamsDocument by 09-01 Task 3 in Wave 1)
- [ ] `database/factories/OmManualFactory.php` — created by plan 09-02b Task 1
- [ ] `database/factories/WorksheetFactory.php` — created by plan 09-02b Task 1
- [ ] `database/factories/CableScheduleFactory.php` — created by plan 09-02b Task 1 (Wave 2 per B-01 — requires HasFactory trait added to CableSchedule by 09-01 Task 3 in Wave 1; uses `source_filename`, not `filename`)
- [ ] `tests/Feature/Notifications/RamsCompletionNotificationTest.php` — `Mail::fake()` + dispatch `BuildRamsDocumentJob` end-state and assert `RamsReadyMail` sent to owner with attachment — created in plan 09-05 Task 2
- [ ] `tests/Feature/Notifications/OmManualCompletionNotificationTest.php` — same shape for O&M — created in plan 09-05 Task 2
- [ ] `tests/Feature/Notifications/WorksheetCompletionNotificationTest.php` — same shape for Worksheet — created in plan 09-05 Task 2
- [ ] `tests/Feature/Notifications/CableScheduleCompletionNotificationTest.php` — same shape for Cable (uses source_filename + dual MIME) — created in plan 09-05 Task 2
- [ ] `tests/Feature/Notifications/RamsReviewNeededNotificationTest.php` — assert `RamsReviewNeededMail` fires when `ExtractRamsDraftJob` flips status to `awaiting_review` AND assert `review_needed_email_sent_at` is set (NOTF-03c idempotency) — created in plan 09-05 Task 2
- [ ] `tests/Feature/Notifications/DocumentGenerationFailedNotificationTest.php` — assert `DocumentGenerationFailedMail` fires from `Build*Job::failed()` to all admins; truncates error_message; cable falls back to `$exception->getMessage()` — created in plan 09-05 Task 2
- [ ] `tests/Feature/Notifications/IdempotencyTest.php` — second dispatch (e.g., job retry) does NOT re-send when `completion_email_sent_at` / `review_needed_email_sent_at` / `failed_email_sent_at` is set — created in plan 09-05 Task 3
- [ ] `tests/Feature/Notifications/BccTest.php` — when `RAMS_NOTIFICATION_BCC` is set, mail header includes Bcc; when empty, no Bcc header — created in plan 09-05 Task 3
- [ ] Existing `tests/Feature/PublicSurveyControllerTest.php` — extend to assert `SurveySubmittedMail` recipient resolution still works after `SurveyService` is refactored to use `NotificationRecipientResolver` (regression guard for NOTF-02a) — extended in plan 09-05 Task 2

*PHPUnit framework already installed; no Wave 0 framework install required.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Postmark transport actually sends to inbox | NOTF-05g | Requires production `POSTMARK_API_KEY` + DNS setup; CI cannot verify deliverability | Set `MAIL_MAILER=postmark` + valid `POSTMARK_API_KEY` in staging `.env`; trigger one document generation; confirm receipt in `rams@21stcav.com` inbox (see plan 09-06 checklist §4) |
| DKIM / SPF / DMARC pass at the recipient | NOTF-05g | DNS work, not code | After DNS records are deployed, send a test email to `check-auth@verifier.port25.com`; expect `pass` for all three (see plan 09-06 checklist §4) |
| `MAIL_MAILER=log` rendered output is readable in dev | NOTF-05f | Visual check of the rendered email body | `MAIL_MAILER=log php artisan tinker` → `Mail::to('test@example.com')->send(new App\Mail\RamsReadyMail(RamsDocument::first()));` → `tail storage/logs/laravel.log` |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or are explicit `checkpoint:human-verify` (09-06-T2)
- [x] Sampling continuity: every wave terminates in a green-suite check (no 3 consecutive tasks without automated verify)
- [x] Wave 0 covers all MISSING references (10 test files / extensions + 4 factory files enumerated above)
- [x] No watch-mode flags (every command is one-shot)
- [x] Feedback latency < 30s (full feature suite is ~5–15s; full project suite ~30s)
- [x] `nyquist_compliant: true` set in frontmatter
- [x] **Revision 1 (2026-04-19)** — Addressed checker issues I-01 (model fillable BLOCKER), I-02 (factories), I-03 (binary lint verify), I-04 (NOTF-03c review-needed idempotency column + guard), I-05 (anchored status greps), I-06 (codebase-wide negative greps), I-07 (Blade wrapper consistency), I-08 (OCR test guidance), I-09 (migration filename convention)
- [x] **Revision 2 (2026-04-19)** — Addressed checker issues B-01 (Wave 1 file collision on RamsDocument.php + CableSchedule.php: moved HasFactory trait additions into 09-01 Task 3; bumped 09-02b to Wave 2 with `depends_on: ["09-01"]`), B-02 (codebase-wide `is_admin` grep would fail forever due to latent bug at SiteSurveyController.php:449: expanded 09-04 Task 3 scope to fix it using the existing `User::isAdmin()` method), W-02 (09-01 Task 3 round-trip smoke replaced with direct `Model::create()` to avoid factory dependency now that 09-02b is Wave 2), W-03 (malformed `\|` alternation in 09-05 Task 2 grep replaced with single anchored `grep -qE "Mail::assertSent\(.*SurveySubmittedMail"`), I-01 (failure-colour header grep documented as optional acceptance row)

**Approval:** ready for execution
</content>
